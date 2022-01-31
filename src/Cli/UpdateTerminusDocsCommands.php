<?php

namespace UpdateTool\Cli;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Common\ConfigAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Hubph\HubphAPI;
use Hubph\Git\WorkingCopy;
use Hubph\Git\Remote;

/**
 * Commands used to interact with Terminus releases.
 */
class UpdateTerminusDocsCommands extends \Robo\Tasks implements ConfigAwareInterface, LoggerAwareInterface
{
    use ConfigAwareTrait;
    use LoggerAwareTrait;

    /**
     * Update terminus commands and releases in documentation repo.
     *
     * @command terminus:update-docs
     */
    public function terminusUpdateDocs($options = [
        'as' => 'default',
        'update-commands' => true,
        'update-releases' => true,
        'terminus-release' => 'latest',
        'github-repo' => 'pantheon-systems/documentation',
        'terminus-repo' => 'pantheon-systems/terminus',
        'base-branch' => 'main',
        'branch-name-prefix' => 'docs-update-terminus-',
        'commit-message' => 'Update terminus information.',
        'pr-body' => '',
        'pr-title' => '[UpdateTool - Terminus Information] Update commands and releases to version %version.',
        'dry-run' => false,
    ])
    {
        $api = $this->api($options['as']);
        $updateCommands = $options['update-commands'];
        $updateReleases = $options['update-releases'];
        if (!$updateCommands && !$updateReleases) {
            throw new \Exception("Both --no-update-commands and --no-update-releases specified; nothing to do.");
        }

        $terminusRepo = $options['terminus-repo'];
        $terminusRelease = $options['terminus-release'];

        $terminusDir = sys_get_temp_dir() . '/update-tool/terminus/';
        if (!is_dir($terminusDir)) {
            mkdir($terminusDir, 0755, true);
        }

        if ($terminusRelease === 'latest') {
            $terminusRelease = $this->getLatestRelease($api, $terminusRepo);
        }
        $downloadUrl = 'https://github.com/pantheon-systems/terminus/releases/download/' . $terminusRelease . '/terminus.phar';

        file_put_contents($terminusDir . 'terminus.phar', file_get_contents($downloadUrl));
        if (!file_exists($terminusDir . 'terminus.phar')) {
            throw new \Exception(sprintf('Failed to download terminus.phar from release %s.', $terminusRelease));
        }
        chmod($terminusDir . 'terminus.phar', 0755);

        $githubRepo = $options['github-repo'];
        $baseBranch = $options['base-branch'];

        // Finish early if nothing to do.
        $prTitle = $options['pr-title'];
        $preamble = $this->preamble($prTitle);
        $realPrTitle = $this->getFinalPrTitle($prTitle, $terminusRelease);
        $prs = $api->matchingPRs($githubRepo, $preamble);
        if (in_array($realPrTitle, $prs->titles())) {
            $this->logger->notice("There is an existing pull request for this Terminus version; nothing else to do.");
            return;
        }

        $branchNamePrefix = $options['branch-name-prefix'];
        $branchName = $branchNamePrefix . date('YmdHi');

        $url = 'git@github.com:' . $githubRepo . '.git';
        $remote = new Remote($url);
        $dir = sys_get_temp_dir() . '/update-tool/' . $remote->project();
        $workingCopy = WorkingCopy::cloneBranch($url, $dir, $baseBranch, $api);
        $workingCopy->setLogger($this->logger);
        $workingCopy->createBranch($branchName, $baseBranch, true);

        if ($updateCommands) {
            $this->logger->info('Updating Terminus commands...');
            exec(sprintf("cd %s && %s/terminus.phar list --format=json > source/data/commands.json", $dir, $terminusDir), $output, $exitCode);
            if ($exitCode) {
                throw new \Exception(sprintf('Failed to list terminus commands. Exit code: %s. Output: %s.', $exitCode, implode("\n", $output)));
            }

            $commands = json_decode(file_get_contents($dir . '/source/data/commands.json'), true);

            // Remove _complete command that makes the build break.
            foreach ($commands['commands'] as $key => $command) {
                if ($command['name'] === '_complete') {
                    unset($commands['commands'][$key]);
                    break;
                }
            }
            $commands['commands'] = array_values($commands['commands']);

            $commandsJson = json_encode($commands, JSON_PRETTY_PRINT);

            // Adjust output.
            $commandsJson = str_replace(
                [
                    '<site_env>',
                    'drush_command',
                    'wp_command',
                ],
                [
                    '<site>.<env>',
                    'command',
                    'command',
                ],
                $commandsJson
            );

            file_put_contents($dir . '/source/data/commands.json', $commandsJson);
        }
        if ($updateReleases) {
            $this->logger->info('Updating Terminus releases...');
            $releases = $this->getAllReleases($api, $terminusRepo);
            // Cleanup releases download count.
            foreach ($releases as $key => &$release) {
                $tag_name = $release['tag_name'];
                if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $tag_name)) {
                    unset($releases[$key]);
                    continue;
                }
                if (isset($release['assets'][0]['download_count'])) {
                    $release['assets'][0]['download_count'] = 0;
                }
            }
            // Reset the keys after deleting some releases.
            $releases = array_values($releases);
            $releasesJson = json_encode($releases, JSON_PRETTY_PRINT);
            file_put_contents($dir . '/source/data/terminusReleases.json', $releasesJson);
        }

        if (!$workingCopy->status()) {
            $this->logger->info('Nothing to update.');
            return;
        }

        $this->logger->info('Committing changes...');
        $commitMessage = $options['commit-message'];
        $workingCopy->add("$dir/source/data");
        $workingCopy->commit($commitMessage);

        $dryRun = $options['dry-run'];
        if (!$dryRun) {
            $prBody = $options['pr-body'];
            $workingCopy->push('origin', $branchName);
            $pr = $workingCopy->pr($prTitle, $prBody, $baseBranch, $branchName);

            $comment = sprintf('Superseeded by #%s.', $pr['number']);
            $api->prClose($workingCopy->org(), $workingCopy->project(), $prs, $comment);
        }
    }

    /**
     * Get preamble string for PR title.
     */
    protected function preamble($prTitle) {
        if ($position = strpos($prTitle, '%version')) {
            return substr($prTitle, 0, $position);
        }
    }

    /**
     * Get final PR title based on version.
     */
    protected function getFinalPrTitle($prTitle, $version) {
        return str_replace('%version', $version, $prTitle);
    }

    /**
     * Get all releases for a given repo.
     */
    protected function getAllReleases($api, $repo)
    {
        [$username, $repository] = explode('/', $repo);
        $releases = $api->gitHubAPI()->repo()->releases()->all($username, $repository);
        return $releases;
    }

    /**
     * Get the latest release for a given repo.
     */
    protected function getLatestRelease($api, $repo)
    {
        [$username, $repository] = explode('/', $repo);
        $release = $api->gitHubAPI()->repo()->releases()->latest($username, $repository);
        return $release['tag_name'];
    }

    /**
     * Get the API object.
     */
    protected function api($as = 'default')
    {
        $api = new HubphAPI($this->getConfig());
        $api->setAs($as);

        return $api;
    }
}
