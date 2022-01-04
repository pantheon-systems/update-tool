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
        'pr-title' => '[UpdateTool - Terminus Information] Update commands and releases.',
        'dry-run' => false,
    ])
    {
        $api = $this->api($options['as']);
        $updateCommands = $options['update-commands'];
        $updateReleases = $options['update-releases'];
        if (!$updateCommands && !$updateReleases) {
            throw new \Exception("Either commands or releases must be updated.");
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

        $branchNamePrefix = $options['branch-name-prefix'];
        $branchName = $branchNamePrefix . date('Ymd');

        $url = 'git@github.com:' . $githubRepo . '.git';
        $remote = new Remote($url);
        $dir = sys_get_temp_dir() . '/update-tool/' . $remote->project();
        $workingCopy = WorkingCopy::cloneBranch($url, $dir, $baseBranch, $api);
        $workingCopy->setLogger($this->logger);
        $workingCopy->createBranch($branchName, $baseBranch, true);

        if ($updateCommands) {
            $this->logger->info('Updating Terminus commands...');
            exec(sprintf("cd %s && %s/terminus.phar list --format=json > source/data/commands.json", $dir, $terminusDir), $output, $return);
            if ($return != 0) {
                throw new \Exception(sprintf('Failed to list terminus commands. Output: %s', implode("\n", $output)));
            }

            $commands = json_decode(file_get_contents($dir . '/source/data/commands.json'), true);
            $commandsJson = json_encode($commands, JSON_PRETTY_PRINT);

            // Adjust output.
            $commandsJson = str_replace(
                [
                    'site_env',
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
            foreach ($releases as &$release) {
                if (isset($release['assets'][0]['download_count'])) {
                    $release['assets'][0]['download_count'] = 0;
                }
            }
            $releasesJson = json_encode($releases, JSON_PRETTY_PRINT);
            file_put_contents($dir . '/source/data/terminusReleases.json', $releasesJson);
        }

        if (!$workingCopy->status()) {
            $this->logger->info('Nothing to do...');
            return;
        }

        $this->logger->info('Committing changes...');
        $commitMessage = $options['commit-message'];
        $workingCopy->add("$dir/source/data");
        $workingCopy->commit($commitMessage);

        $dryRun = $options['dry-run'];
        if (!$dryRun) {
            $prTitle = $options['pr-title'];
            $prBody = $options['pr-body'];
            $workingCopy->push('origin', $branchName);
            $workingCopy->pr($prTitle, $prBody, $baseBranch, $branchName);
        }
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
