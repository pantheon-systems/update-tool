<?php

namespace UpdateTool\Cli;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Robo\Common\ConfigAwareTrait;
use UpdateTool\Git\WorkingCopy;
use UpdateTool\Git\Remote;
use Hubph\VersionIdentifiers;
use Hubph\HubphAPI;

/**
 * Commands used to create pull requests to update the available command line tool version on the platform
 */
class PluginCommands extends \Robo\Tasks implements ConfigAwareInterface, LoggerAwareInterface
{
    use ConfigAwareTrait;
    use LoggerAwareTrait;
    use ApiTrait;

    /**
     * @command plugin:update
     */
    public function pluginUpdate($remote, $options = ['as' => 'default', 'push' => true, 'check' => false])
    {
        $api = $this->api($options['as']);

        $url = $this->getConfig()->get("plugins.$remote.repo");
        if (empty($url)) {
            throw new \Exception('Plugin cannot be updated; it is missing a source.');
        }

        $work_dir = $this->getConfig()->get("plugins.$remote.path");
        $plugin_working_copy = WorkingCopy::clone($url, $work_dir, $api);
        $plugin_working_copy
            ->addFork('')
            ->setLogger($this->logger);
        $this->logger->notice("Check out {project} to {path}.", ['project' => $plugin_working_copy->projectWithOrg(), 'path' => $work_dir]);
        $plugin_working_copy
        ->switchBranch("plugins.$remote.main-branch")
        ->pull('origin', "plugins.$remote.main-branch");

        // Get plugin file to parse.
        $file = $this->getConfig()->get("plugins.$remote.file-to-check");
        $query_string = $this->getConfig()->get("plugins.$remote.query-string");
        $versions_file_path = "$work_dir/$file";
        $version_file_contents = file_get_contents($versions_file_path);

        // Check for updates.
        $api_url = $this->getConfig()->get("plugins.$remote.version-api.url", '') ;
        $latest_versions = $this->findLatestVersion($api_url);

        // Get current version, compare to updated version, and update file if needed.
        $version_diff = $this->getCurrentVersionUpdateFile($version_file_contents, $query_string, $latest_versions);



    }

    /**
     * Get Latest Versions
     */
    public function findLatestVersion($api_url)
    {
        $availableVersions = file_get_contents($api_url);
        if (empty($availableVersions)) {
            throw new \Exception('Could not contact the version-check API endpoint.');
        }
        $versionData = json_decode($availableVersions, true);
        if (!isset($versionData['offers'][0])) {
            throw new \Exception('No offers returned from the version-check API endpoint.');
        }
        $major_version = explode('.', $versionData['offers'][0]['version']);
        //$latest_versions[] = $major_version[0] . '.' . $major_version[1];
        $latest_versions[] = '6.0';
        $latest_versions[] = $versionData['offers'][3]['version'];

        return $latest_versions;
    }


    protected function getCurrentVersionUpdateFile($version_file_contents, $query_string, $latest_versions)
    {
        $current_versions = [];

        foreach (explode("\n", $version_file_contents) as $line) {
            if (preg_match('#('. $query_string .')(.*)$#', $line, $matches)) {
                $version = str_replace( ' --force`', '', $matches[2] );

                $current_versions[] = $version;
            }
        }

        $compare_arrays = ($latest_versions === $current_versions);
        // If our comparison is false, we need to update the file.
        if(empty($compare_arrays)){
            // figure out which version is different and update accordingly
        }



        return $current_versions;
    }

    /**
     * The branch prefix is placed at the beginning of branch names.
     */
    protected function branchPrefix($cli)
    {
        return $this->getConfig()->get('constants.branch-prefix', 'wp-cli-');
    }

    protected function createRemote($remote_name, $api)
    {
        $remote_url = $this->getConfig()->get("projects.$remote_name.repo");
        return Remote::create($remote_url, $api);
    }
}
