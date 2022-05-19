<?php

namespace UpdateTool\Cli;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Robo\Common\ConfigAwareTrait;
use UpdateTool\Git\WorkingCopy;
use UpdateTool\Git\Remote;

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
        $main_branch = $this->getConfig()->get("plugins.$remote.main-branch");

        $plugin_working_copy = WorkingCopy::clone($url, $work_dir, $api);
        $plugin_working_copy
            ->addFork('')
            ->setLogger($this->logger);
        $this->logger->notice("Check out {project} to {path}.", ['project' => $plugin_working_copy->projectWithOrg(), 'path' => $work_dir]);
        $plugin_working_copy
        ->switchBranch($main_branch)
        ->pull('origin', $main_branch);

        // Get plugin file to parse.
        $file_to_check = $this->getConfig()->get("plugins.$remote.file-to-check");
        $query_string = $this->getConfig()->get("plugins.$remote.query-string");
        $versions_file_path = "$work_dir/$file_to_check";
        $version_file_contents = file_get_contents($versions_file_path);

        // Check for updates.
        $api_url = $this->getConfig()->get("plugins.$remote.version-api.url", '') ;
        $latest_versions = $this->findLatestVersion($api_url);

        // Get current version, compare to updated version, and update file if needed.
        // Returns array containing the version to update and the updated version_file_contents.
        $version_data = $this->getCurrentVersionUpdateFile($version_file_contents, $query_string, $latest_versions);
        if (empty($version_data[0])) {
            return;
        }

        if (count($version_data[0]) >=2) {
            $version_updates = implode(' and ', $version_data[0]);
        } else {
            $version_updates = implode(' ', $version_data[0]);
        }

        $preamble = $this->preamble($remote);
        $message = "{$preamble} to {$version_updates}";
        // Determine if there are any PRs already open that we should
        // close. If its contents are the same, then we should abort rather than create the same PR again.
        // If the contents are different, then we'll make a new PR and close this one.
        $prs = $api->matchingPRs($plugin_working_copy->projectWithOrg(), $message);
        if (in_array($message, $prs->titles())) {
            $this->logger->notice("There is an existing pull request for this update; nothing else to do.");
            return;
        }
        
        file_put_contents($versions_file_path, $version_data[1]);

        // Create a new pull request
        $branch_slug = str_replace(' ', '-', $version_updates);
        $branch = $this->branchPrefix($remote) . $branch_slug;
        $this->logger->notice('Using {branch}', ['branch' => $branch]);
        $plugin_working_copy
            ->createBranch($branch, $main_branch, true)
            ->add($file_to_check)
            ->commit($message)
            ->push()
            ->pr($message, '', $main_branch);

        // Once we create a new PR, we can close the existing PRs.
        $api->prClose($plugin_working_copy->org(), $plugin_working_copy->project(), $prs);
    }

    /**
     * Get Latest Versions
     */
    public function findLatestVersion($api_url)
    {
        $latest = '"latest"';
        $input = 'keys[] as $k';
        $filter = '"\($k), \(.[$k])"';

        $cmd = "curl -s $api_url | jq '" . $input . " | " . $filter . " | select(index(" . $latest . "))'";
        exec($cmd, $minor, $status);
        $minor = str_replace('"', '', $minor[0]);
        // We need to remove the minor version to test for the WordPress minor version recommendation message.
        $minor_version = explode('.', explode(', ', $minor)[0]);
        $latest_versions[] = $minor_version[0] . '.' . $minor_version[1];
        
        // Check for previous major version using latest version.
        $last_major = '"' . strval($latest_versions[0] - 0.1) . '"';
        $cmd = "curl -s $api_url | jq '" . $input . " | " . $filter . " | select(index(" . $last_major . "))'";
        exec($cmd, $major, $status);

        // Filter for outdated value (we don't want the insecure verison).
        $previous_version = preg_grep("/outdated/", $major);
        if (!empty($previous_version)) {
            $major_version = explode(',', str_replace('"', '', reset($previous_version)));
            // Add previous major version to $latest_versions array.
            $latest_versions[] = $major_version[0];
        }

        return $latest_versions;
    }

    /**
     * Get Current Versions and Update File if needed
     */
    protected function getCurrentVersionUpdateFile($version_file_contents, $query_string, $latest_versions)
    {
        $current_versions = [];
        $versions_to_replace = [];

        foreach (explode("\n", $version_file_contents) as $line) {
            if (preg_match('#('. $query_string .')(.*)$#', $line, $matches)) {
                $version = str_replace(' --force`', '', $matches[2]);

                $current_versions[] = $version;
            }
        }

        $compare_arrays = ($latest_versions === $current_versions);
        // If our comparison is false, we need to update the file.
        if (empty($compare_arrays)) {
            $versions_to_replace = array_diff($latest_versions, $current_versions);

            if (count($versions_to_replace) >= 2) {
                foreach ($versions_to_replace as $key => $version) {
                    $version_file_contents = str_replace($current_versions[$key], $version, $version_file_contents);
                }
            } else {
                $array_key_to_replace = array_key_first($versions_to_replace);
                $version_file_contents = str_replace($current_versions[$array_key_to_replace], $versions_to_replace[$array_key_to_replace], $version_file_contents);
            }
        }

        return [$versions_to_replace,$version_file_contents];
    }

     /**
     * The preamble is placed at the beginning of commit messages.
     */
    protected function preamble($remote)
    {
        return $this->getConfig()->get("plugins.$remote.commit-preamble", 'Update version');
    }

    /**
     * The branch prefix is placed at the beginning of branch names.
     */
    protected function branchPrefix($remote)
    {
        return $this->getConfig()->get("plugins.$remote.branch-prefix", 'wplc-');
    }

    protected function createRemote($remote_name, $api)
    {
        $remote_url = $this->getConfig()->get("projects.$remote_name.repo");
        return Remote::create($remote_url, $api);
    }
}
