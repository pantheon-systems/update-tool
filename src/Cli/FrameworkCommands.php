<?php

namespace UpdateTool\Cli;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Robo\Common\ConfigAwareTrait;
use UpdateTool\Git\WorkingCopy;
use Hubph\VersionIdentifiers;
use Hubph\HubphAPI;

/**
 * Commands used to create pull requests to update the available command line tool version on the platform
 */
class FrameworkCommands extends \Robo\Tasks implements ConfigAwareInterface, LoggerAwareInterface
{
    use ConfigAwareTrait;
    use LoggerAwareTrait;
    use ApiTrait;

    /**
     * Update CLI version for COS
     *
     * @command cli:cos:update
     * @aliases cli:cos
     */
    public function cliCosUpdate($cli = 'wp')
    {
        $api = $this->api('default');

        $url = $this->getConfig()->get('projects.cos-clis.repo');
        $fork = $this->getConfig()->get('projects.cos-clis.fork', '');
        $work_dir = $this->getConfig()->get('projects.cos-php.path');

        $cos_cli = WorkingCopy::clone($url, $work_dir, $api);
        $cos_cli
            ->addFork($fork)
            ->setLogger($this->logger);
        $this->logger->notice("Check out {project} to {path}.", ['project' => $cos_cli->projectWithOrg(), 'path' => $work_dir]);
        $cos_cli
            ->switchBranch('master')
            ->pull('origin', 'master');


        if ('drush' === $cli) {
            $versions_file_path = "$work_dir/drush/Makefile";
            $file_to_add = 'drush/Makefile';
            $version_file_contents = file_get_contents($versions_file_path);
            $major_versions = $this->getCosDrushMajorVersions($version_file_contents);
            $versions = $this->getCosDrushVersions($version_file_contents);

            $next_versions = $this->nextDrushVersionThatExists($major_versions, $versions);
            if (!empty($next_versions)) {
                $version_file_contents = $this->updateDrushMakefile($next_versions, $versions, $version_file_contents);
                $updated_version = implode(' and ', $next_versions);
                $branch_slug = implode('-', $next_versions);
            } else {
                $this->say("Drush versions are up to date on COS");
            }
        } else {
            $versions_file_path = "$work_dir/wpcli/Dockerfile";
            $version_file_contents = file_get_contents($versions_file_path);
            $version = $this->getCosWpCliVersion($version_file_contents);
            
            $updated_version = '';
            $next_version = $this->nextWpVersionThatExists($version);
            if (!$next_version) {
                $urlTemplate = $this->getConfig()->get('wp-cli-gh.download-url');
                throw new \Exception("Could not find current WP-CLI $version on WP-CLI GH release page. Using url: $urlTemplate. Check configuration and network.");
            }

            $file_to_add = 'wpcli/Dockerfile';
            if ($next_version != $version) {
                $this->say("$next_version is available.");
                $version_file_contents = preg_replace("#$version#", "$next_version", $version_file_contents);
                $updated_version = $next_version;
                $branch_slug = $updated_version;
            } else {
                $this->say("$version is the most recent version on COS");
            }
        }

        if (empty($updated_version)) {
            return;
        }

        $preamble = $this->preamble($cli);
        $message = "{$preamble} $updated_version";

        // Determine if there are any PRs already open that we should
        // close. If its contents are the same, then we should abort rather than create the same PR again.
        // If the contents are different, then we'll make a new PR and close this one.
        $prs = $api->matchingPRs($cos_cli->projectWithOrg(), $preamble);
        if (in_array($message, $prs->titles())) {
            $this->logger->notice("There is an existing pull request for this update; nothing else to do.");
            return;
        }

        file_put_contents($versions_file_path, $version_file_contents);

        // Create a new pull request
        $branch = $this->branchPrefix($cli) . $branch_slug;
        $this->logger->notice('Using {branch}', ['branch' => $branch]);
        $cos_cli
            ->createBranch($branch, 'master', true)
            ->add($file_to_add)
            ->commit($message)
            ->push()
            ->pr($message);

        // Once we create a new PR, we can close the existing PRs.
        $api->prClose($cos_cli->org(), $cos_cli->project(), $prs);
    }

    protected function getCosWpCliVersion($version_file_contents)
    {
        $result = '';

        foreach (explode("\n", $version_file_contents) as $line) {
            if (preg_match('#^(ARG wpcli_version=)(.*)$#', $line, $matches)) {
                $version = $matches[2];

                $result = $version;
            }
        }

        return $result;
    }

    protected function getCosDrushMajorVersions($version_file_contents)
    {
        $result = '';

        foreach (explode("\n", $version_file_contents) as $line) {
            $regex = '#^(DRUSH_MAJOR_VERSIONS := )(.*)#';
            if (preg_match($regex, $line, $matches)) {
                $major_versions = $matches[2];

                $result = $major_versions;
            }
        }

        return $result;
    }

    protected function getCosDrushVersions($version_file_contents)
    {
        $result = '';
        $versions = [];

        foreach (explode("\n", $version_file_contents) as $line) {
            $regex = '/^(DRUSH\d{1,2}_VERSION := )(.*)/m';
            if (preg_match_all($regex, $line, $matches)) {
                $versions[] = $matches[2][0];

                $result = $versions;
            }
        }

        return $result;
    }

    /**
     * The preamble is placed at the beginning of commit messages.
     */
    protected function preamble($cli)
    {
        if ('drush' === $cli) {
            return $this->getConfig()->get('messages.update-to', 'Update to Drush version');
        } else {
            return $this->getConfig()->get('messages.update-to', 'Update to WP-CLI version');
        }
    }

    /**
     * The branch prefix is placed at the beginning of branch names.
     */
    protected function branchPrefix($cli)
    {
        if ('drush' === $cli) {
            return $this->getConfig()->get('constants.branch-prefix', 'drush-');
        } else {
            return $this->getConfig()->get('constants.branch-prefix', 'wp-cli-');
        }
    }

    /**
     * Check the github repo at wp-cli/wp-cli and see if there is a .phar
     * file available for the specified wp-cli version.
     */
    protected function wpVersionExists($version)
    {
        $urlTemplate = $this->getConfig()->get('wp-cli-gh.download-url');
        $url = str_replace('{version}', $version, $urlTemplate);

        // If the $url points to a local cache, use file_exists
        if ((strpos($url, 'file:///') !== false) || (strpos($url, '://') === false)) {
            return file_exists($url);
        }

        // For network urls, run `curl -I` to do just a HEAD request.
        // -s is "silent mode", and -L follows redirects.
        exec("curl -s -L -I " . escapeshellarg($url), $output, $status);
        $httpStatus = $this->findStatusInCurlOutput($output);
        return $httpStatus == 200;
    }

    /**
     * Check the github repo at drush-ops/drush and see if there is a .zip
     * file available for the specified drush version.
     */
    protected function drushVersionExists($versions)
    {
        $urlTemplate = $this->getConfig()->get('drush-gh.download-url');

        foreach ($versions as $version) {
            $url = str_replace('{version}', $version, $urlTemplate);

            // If the $url points to a local cache, use file_exists
            if ((strpos($url, 'file:///') !== false) || (strpos($url, '://') === false)) {
                return file_exists($url);
            }

            // For network urls, run `curl -I` to do just a HEAD request.
            // -s is "silent mode", and -L follows redirects.
            exec("curl -s -L -I " . escapeshellarg($url), $output, $status);
            $httpStatus = $this->findStatusInCurlOutput($output);
        }

        return $httpStatus == 200;
    }

    protected function findStatusInCurlOutput(array $output)
    {
        foreach ($output as $line) {
            if (preg_match('#HTTP/*[0-9]* +([0-9]+)#i', $line, $matches)) {
                if (!empty($matches[1]) && ($matches[1][0] != '3')) {
                    return $matches[1];
                }
            }
        }
        return false;
    }

    /**
     * Keep incrementing the provided version; return the highest
     * version number that has an available download file.
     */
    protected function nextWpVersionThatExists($current_version)
    {
        // Return 'false' if the -current- version cannot be found on wp-cli/wp-cli.
        if (!$this->wpVersionExists($current_version)) {
            return false;
        }

        // At this point the $version_that_exists is the version that is already in use.
        $version_that_exists = $current_version;
        // Check for latest tag on GH.
        $apiUrl = $this->getConfig()->get('wp-cli-gh.api-url');
        $latest_version = trim(exec("curl -sL '$apiUrl' | jq -r '.tag_name'"), 'v');

        // If our versions do not match, check that the latest version exists before continuing.
        // Greater than comparision to ensure that we don't get older versions. See https://github.com/pantheon-systems/cos-framework-clis/pull/76#issuecomment-1118650316
        if ($latest_version > $current_version) {
            $try_version = $this->wpVersionExists($latest_version);

            if (!empty($try_version)) {
                // If the latest version exists on GH, set it as the $version_that_exists.
                $version_that_exists = $latest_version;
            }
        }

        return $version_that_exists;
    }

    /**
     * Keep incrementing the provided version; return the highest
     * version number that has an available download file.
     */
    protected function nextDrushVersionThatExists($major_versions, $versions)
    {
        // Return 'false' if the -current- version cannot be found on drush-ops/drush.
        if (!$this->drushVersionExists($versions)) {
            return false;
        }

        // Transform major version string to array for processing.
        $major_versions = explode(' ', $major_versions);
        $latest_versions = $this->getDrushLatestVersions($major_versions);

        $updated_versions = array_diff($latest_versions, $versions);
        // Remove any versions where an update isn't needed.
        $updated_versions = array_diff($updated_versions, $major_versions);

        $next_version = $versions;
        // Check that the updated versions exist before continuing.
        if (!empty($updated_versions)) {
            $try_version = $this->drushVersionExists($updated_versions);

            if (!empty($try_version)) {
                $next_version = $updated_versions;
            }
        } else {
            $next_version = [];
        }

        return $next_version;
    }

    /**
     * Check the github repo at drush-ops/drush and get the latest version
     * for each major version Pantheon supports.
     */
    protected function getDrushLatestVersions($major_versions)
    {
        $apiUrl = $this->getConfig()->get('drush-gh.api-url');
        $latest_versions = [];
        foreach ($major_versions as $major_version) {
            // Get recent releases that match our supported major versions
            $latest_version = exec("curl -sL '$apiUrl' | jq 'first(.[].tag_name | select(test(\"^(?!.*(-)).*($major_version.).*\")))'");
            $lv = str_replace('"', '', $latest_version);
            if (str_starts_with($lv, $major_version . '.')) {
                $latest_versions[] = $lv;
            } else {
                $latest_versions[] = $major_version;
            }
        }

        // return array of latest versions
        return $latest_versions;
    }

    protected function updateDrushMakefile($next_versions, $versions, $version_file_contents)
    {
        foreach ($next_versions as $version) {
            $version_arr = explode('.', $version);
            $version_match = preg_grep("/^$version_arr[0].(\w+)/i", $versions);
            $version_match = array_values($version_match);
            $old_version = $version_match[0];
            $this->say("$version is available.");
            $version_file_contents = preg_replace("#$old_version#", "$version", $version_file_contents);
        }

        return $version_file_contents;
    }
}
