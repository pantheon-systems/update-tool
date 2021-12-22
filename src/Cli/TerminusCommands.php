<?php

namespace UpdateTool\Cli;

use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\Filter\FilterOutputData;
use Consolidation\Filter\LogicalOpFactory;
use Consolidation\OutputFormatters\Options\FormatterOptions;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Common\ConfigAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Consolidation\AnnotatedCommand\CommandError;
use Hubph\HubphAPI;
use Hubph\VersionIdentifiers;
use Hubph\PullRequests;
use Hubph\Git\WorkingCopy;
use Hubph\Git\Remote;
use UpdateTool\Util\SupportLevel;
use UpdateTool\Util\ProjectUpdate;

class TerminusCommands extends \Robo\Tasks implements ConfigAwareInterface, LoggerAwareInterface
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
            throw new \Exception("Failed to download terminus.phar from release $terminusRelease");
        }
        chmod($terminusDir . 'terminus.phar', 0755);

        $githubRepo = $options['github-repo'];
        $baseBranch = $options['base-branch'];

        $branchNamePrefix = $options['branch-name-prefix'];
        $branchName = $branchNamePrefix . date('Ymd');

        $url = "git@github.com:$githubRepo.git";
        $remote = new Remote($url);
        $dir = sys_get_temp_dir() . '/update-tool/' . $remote->project();
        $workingCopy = WorkingCopy::cloneBranch($url, $dir, $baseBranch, $api);
        $workingCopy->createBranch($branchName, $baseBranch, true);

        if ($updateCommands) {
            exec("cd $dir && $terminusDir/terminus.phar list --format=json > source/data/commands.json", $output, $return);
            if ($return != 0) {
                throw new \Exception("Failed to list terminus commands.");
            }

            $commands = json_decode(file_get_contents($dir . '/source/data/commands.json'), true);
            $commandsJson = json_encode($commands, JSON_PRETTY_PRINT);

            // @todo Fix some stuff!
        /**
         * echo "Ajusting output..."
sed -i 's/site_env/site>\.<env/g' source/data/commands.json
sed -i 's/drush_command/command/g' source/data/commands.json
sed -i 's/wp_command/command/g' source/data/commands.json
         */

            file_put_contents($dir . '/source/data/commands.json', $commandsJson);
        }
        if ($updateReleases) {
            /**
             * 
echo "Update Terminus releases"
curl -H "Authorization: token $GITHUB_TOKEN" https://api.github.com/repos/pantheon-systems/terminus/releases > source/data/terminusReleases.json
head source/data/terminusReleases.json
             */
        }

        $dryRun = $options['dry-run'];
        if (!$dryRun) {
            $commitMessage = $options['commit-message'];
            $prBody = $options['pr-body'];
            $prTitle = $options['pr-title'];
            // @todo commit and create PR.
        }

    }

    protected function getLatestRelease($api, $repo)
    {
        [$username, $repository] = explode('/', $repo);
        $release = $api->gitHubAPI()->repo()->releases()->latest($username, $repository);
        return $release['tag_name'];
    }

    protected function api($as = 'default')
    {
        $api = new HubphAPI($this->getConfig());
        $api->setAs($as);

        return $api;
    }
}
