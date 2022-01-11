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
     * Update WP-CLI version for COS
     *
     * @command wpcli:cos:update
     * @aliases wpcli:cos
     */
    public function wpcliCosUpdate($options = ['as' => 'default'])
    {
        $api = $this->api($options['as']);

        $url = $this->getConfig()->get('projects.cos-clis.repo');
        $fork = $this->getConfig()->get('projects.cos-clis.fork', '');
        $work_dir = $this->getConfig()->get('projects.cos-php.path');

        $cos_wp_cli = WorkingCopy::clone($url, $work_dir, $api);
        $cos_wp_cli
            ->addFork($fork)
            ->setLogger($this->logger);
        $this->logger->notice("Check out {project} to {path}.", ['project' => $cos_wp_cli->projectWithOrg(), 'path' => $work_dir]);
        $cos_wp_cli
            ->switchBranch('master')
            ->pull('origin', 'master');

        $versions_file_path = "$work_dir/wpcli/Dockerfile";
        $version_file_contents = file_get_contents($versions_file_path);
        $updated_version = '';

        $version = $this->getCosWpCliVersion($version_file_contents);
        $next_version = $this->nextVersionThatExists($version);
        if (!$next_version) {
            $urlTemplate = $this->getConfig()->get('wp-cli-gh.download-url');
            throw new \Exception("Could not find current WP-CLI $version on WP-CLI GH release page. Using url: $urlTemplate. Check configuration and network.");
        }

        if ($next_version != $version) {
            $this->say("$next_version is available.");
            $version_file_contents = preg_replace("#$version#", "$next_version", $version_file_contents);
            $updated_version = $next_version;
        } else {
            $this->say("$version is the most recent version on COS");
        }

        if (empty($updated_version)) {
            return;
        }

        $preamble = $this->preamble();
        $message = "{$preamble}WP-CLI version $updated_version";

        // Determine if there are any PRs already open that we should
        // close. There should be no more than one. If its contents are the
        // same, then we should abort rather than create the same PR again.
        // If the cnotents are different, then we'll make a new PR and close
        // this one.
        $prs = $api->matchingPRs($cos_wp_cli->projectWithOrg(), $preamble, '');
        if (in_array($message, $prs->titles())) {
            $this->logger->notice("There is an existing pull request for this update; nothing else to do.");
            return;
        }

        file_put_contents($versions_file_path, $version_file_contents);

        // Create a new pull request
        $branch = $this->branchPrefix() . $updated_version;
        $this->logger->notice('Using {branch}', ['branch' => $branch]);
        $cos_wp_cli
            ->createBranch($branch, 'master', true)
            ->add('wpcli/Dockerfile')
            ->commit($message)
            ->push()
            ->pr($message);

        // Once we create a new PR, we can close the existing PRs.
        $api->prClose($cos_wp_cli->org(), $cos_wp_cli->project(), $prs);
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

    /**
     * The preamble is placed at the beginning of commit messages.
     */
    protected function preamble()
    {
        return $this->getConfig()->get('messages.update-to', 'Update to ');
    }

    /**
     * The branch prefix is placed at the beginning of branch names.
     */
    protected function branchPrefix()
    {
        return $this->getConfig()->get('constants.branch-prefix', 'wp-cli-');
    }

    /**
     * Check the github repo at wp-cli/wp-cli and see if there is a .phar
     * file available for the specified wp-cli version.
     */
    protected function versionExists($version)
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
     * Keep incrementing the provided (patch) version; return the highest
     * version number that has an available download file.
     */
    protected function nextVersionThatExists($version)
    {
        // Return 'false' if the -current- version cannot be found on wp-cli/wp-cli.
        if (!$this->versionExists($version)) {
            return false;
        }

        // Check for latest tag on GH.
        $apiUrl = $this->getConfig()->get('wp-cli-gh.api-url');
        $latest_version = trim(exec("curl -sL '$apiUrl' | jq -r '.tag_name'"), 'v');

        $next_version = $version;
        // If our versions do not match, check that the latest version exists before continuing.
        if ($version !== $latest_version) {
            $next_version = $version;
            $try_version = $this->versionExists($latest_version);

            if (!empty($try_version)) {
                $next_version = $latest_version;
            }
        }

        return $next_version;
    }
}
