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
 * Commands used to create pull requests to update available php versions on the platform
 */
class PhpCommands extends \Robo\Tasks implements ConfigAwareInterface, LoggerAwareInterface
{
    use ConfigAwareTrait;
    use LoggerAwareTrait;
    use ApiTrait;

    /**
     * Determine whether the given PHP version has reached EOL.
     * We judge this based on the presence of an 'eol.txt' file
     * in the php.spec directory.
     */
    protected function phpIsEOL($work_dir, $version)
    {
        $version_parts = explode('.', $version);
        $majorMinorVersion = implode('.', [$version_parts[0], $version_parts[1]]);
        $this->logger->notice("Check for $work_dir/php-$majorMinorVersion/eol.txt");
        return file_exists("$work_dir/php-$majorMinorVersion/eol.txt");
    }

    /**
     * Update PHP versions for COS
     *
     * @command php:cos:update
     * @aliases php:cos
     */
    public function phpCosUpdate($options = ['as' => 'default'])
    {
        $api = $this->api($options['as']);

        $url = $this->getConfig()->get('projects.cos-php.repo');
        $fork = $this->getConfig()->get('projects.cos-php.fork', '');
        $work_dir = $this->getConfig()->get('projects.cos-php.path');

        $cos_php = WorkingCopy::clone($url, $work_dir, $api);
        $cos_php
            ->addFork($fork)
            ->setLogger($this->logger);
        $this->logger->notice("Check out {project} to {path}.", ['project' => $cos_php->projectWithOrg(), 'path' => $work_dir]);
        $cos_php
            ->switchBranch('master')
            ->pull('origin', 'master');

        $versions_file_path = "$work_dir/PHP_VERSIONS";
        $version_file_contents = file_get_contents($versions_file_path);
        $updated_versions = [];

        $versions = $this->getCosPhpVersions($version_file_contents);

        foreach ($versions as $version => $prefix) {
            $next_version = $this->nextVersionThatExists($version);
            if (!$next_version) {
                $urlTemplate = $this->getConfig()->get('php-net.download-url');
                throw new \Exception("Could not find current php $version on php downloads server. Using url: $urlTemplate. Check configuration and network.");
            }

            if ($next_version != $version) {
                $this->say("$next_version is available.");
                $version_file_contents = preg_replace("#$prefix$version#", "$prefix$next_version", $version_file_contents);
                $updated_versions[] = $next_version;
            } else {
                $this->say("$version is the most recent version on COS");
            }
        }

        if (empty($updated_versions)) {
            return;
        }

        $all_updated_versions = $this->prettyImplode(', ', ' and ', array_map(function ($v) {
            return "php-$v";
        }, $updated_versions));
        $preamble = $this->preamble();
        $message = "{$preamble}{$all_updated_versions}";

        // Determine if there are any PRs already open that we should
        // close. There should be no more than one. If its contents are the
        // same, then we should abort rather than create the same PR again.
        // If the cnotents are different, then we'll make a new PR and close
        // this one.
        $prs = $api->matchingPRs($cos_php->projectWithOrg(), $preamble, '');
        if (in_array($message, $prs->titles())) {
            $this->logger->notice("There is an existing pull request for this update; nothing else to do.");
            return;
        }

        file_put_contents($versions_file_path, $version_file_contents);

        // Create a new pull request
        $branch = $this->branchPrefix() . implode('-', $updated_versions);
        $this->logger->notice('Using {branch}', ['branch' => $branch]);
        $cos_php
            ->createBranch($branch, 'master', true)
            ->add('PHP_VERSIONS')
            ->commit($message)
            ->push()
            ->pr($message);

        // Once we create a new PR, we can close the existing PRs.
        $api->prClose($cos_php->org(), $cos_php->project(), $prs);
    }

    protected function getCosPhpVersions($version_file_contents)
    {
        $result = [];

        foreach (explode("\n", $version_file_contents) as $line) {
            if (preg_match('#^(declare -r PHP[^=]*=)(.*)$#', $line, $matches)) {
                $version = $matches[2];
                $prefix = $matches[1];

                $result[$version] = $prefix;
            }
        }

        return $result;
    }

    /**
     * Determine if there are any php version updates available, and if so,
     * create a PR in the rpmbuild-php repository.
     *
     * @command php:rpm:update
     * @aliases php:update
     */
    public function phpRpmUpdate($options = ['as' => 'default', 'auto-merge' => true])
    {
        $api = $this->api($options['as']);

        $url = $this->getConfig()->get('projects.rpmbuild-php.repo');
        $fork = $this->getConfig()->get('projects.rpmbuild-php.fork', '');
        $work_dir = $this->getConfig()->get('projects.rpmbuild-php.path');

        // Ensure that a local working copy of the project has
        // been checked out and is available.
        $rpmbuild_php = WorkingCopy::clone($url, $work_dir, $api);
        $rpmbuild_php
            ->addFork($fork)
            ->setLogger($this->logger);
        $this->logger->notice("Check out {project} to {path}.", ['project' => $rpmbuild_php->projectWithOrg(), 'path' => $work_dir]);
        $rpmbuild_php
            ->switchBranch('master')
            ->pull('origin', 'master');

        $datecode = date("Ymd");

        // TODO: convert this snippet of bash from the old script to php.
        // Purpose: finds the current php_version lines from all php.spec files.
        exec("grep '^%define php_version ' $work_dir/php*/php.spec | sed -e 's#[^ ]* *php_version *##'", $versions, $status);

        $updated_versions = [];
        foreach ($versions as $version) {
            // Don't check for updates if the PHP version has reached end-of-life
            if ($this->phpIsEOL($work_dir, $version)) {
                $this->logger->notice("$version is EOL; skipping check.");
                continue;
            }
            $next_version = $this->nextVersionThatExists($version);
            if (!$next_version) {
                $urlTemplate = $this->getConfig()->get('php-net.download-url');
                throw new \Exception("Could not find current php $version on php downloads server. Using url: $urlTemplate. Check configuration and network.");
            }

            if ($next_version != $version) {
                $this->say("$next_version is available, but we are still on version $version on heirloom");

                $this->updateSpec($next_version, $datecode, $work_dir);
                $updated_versions[] = $next_version;
            } else {
                $this->say("$version is the most recent version on heirloom");
            }
        }

        if (empty($updated_versions)) {
            $this->logger->notice("Nothing was updated.");
            return;
        }

        $all_updated_versions = $this->prettyImplode(', ', ' and ', array_map(function ($v) {
            return "php-$v";
        }, $updated_versions));
        $preamble = $this->preamble();
        $message = "{$preamble}{$all_updated_versions}";
        $this->logger->notice("Commit message {message}", ['message' => $message]);

        // Create a set of version ids from the commit message
        $vids = new VersionIdentifiers();
        $vids->setVidPattern('php-#.#.');
        $vids->setVvalPattern('#');
        $vids->setPreamble($preamble);
        $vids->addVidsFromMessage($message);

        // Check to see if there are any open PRs that have already done this
        // work, or that are old and need to be closed.
        list($status, $prs) = $api->prCheck($rpmbuild_php->projectWithOrg(), $vids);

        if ($status) {
            if (!$options['auto-merge']) {
                $this->logger->notice("There is an existing pull request for this update; nothing else to do.");
                return;
            }
            $message = "Automatically merging PR";
            if (getenv('CIRCLE_BUILD_URL')) {
                $message .= " from " . getenv('CIRCLE_BUILD_URL');
            }
            $allMerged = $api->prMerge($rpmbuild_php->org(), $rpmbuild_php->project(), $prs, $message);
            if (!$allMerged) {
                $this->logger->warning("There is an existing pull request for this update, but its tests have not all passed yet. Waiting.");
            } else {
                $this->logger->notice("There was an existing pull request for this update with passing tests; merged it.");
            }
            return;
        }

        // Create a new pull request
        $branch = $this->branchPrefix() . implode('-', $updated_versions);
        $this->logger->notice('Using {branch}', ['branch' => $branch]);
        $rpmbuild_php
            ->createBranch($branch, 'master', true)
            ->add('php-*')
            ->commit($message)
            ->push()
            ->pr($message);

        // These PRs may be closed now, as they are replaced by the new PR.
        $api->prClose($rpmbuild_php->org(), $rpmbuild_php->project(), $prs);
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
        return $this->getConfig()->get('constants.branch-prefix', 'php-');
    }

    /**
     * Increment the patch number of a semver version string.
     */
    protected function nextVersion($version)
    {
        $parts = explode('.', $version);
        $parts[count($parts) - 1]++;

        return implode('.', $parts);
    }

    /**
     * Check the ftp server at php.net and see if there is a .tar.gz
     * file available for the specified php version.
     */
    protected function versionExists($version)
    {
        $urlTemplate = $this->getConfig()->get('php-net.download-url');
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
        // Return 'false' if the -current- version cannot be found on the ftp server.
        if (!$this->versionExists($version)) {
            return false;
        }
        $next_version = $version;
        $try_version = $this->nextVersion($version);
        while ($this->versionExists($try_version)) {
            $next_version = $try_version;
            $try_version = $this->nextVersion($next_version);
        }
        return $next_version;
    }

    /**
     * Reach into the spec file for the rpmbuild project for php
     * and inject the provided version and datecode.
     */
    protected function updateSpec($version, $datecode, $dir)
    {
        $parts = explode('.', $version);
        $major_minor = $parts[0] . '.' . $parts[1];
        $path = "{$dir}/php-{$major_minor}/php.spec";
        $spec = file_get_contents($path);
        $spec = preg_replace('#(%define php_version *).*#', '${1}' . $version, $spec);
        $spec = preg_replace('#(%define rpm_datecode *).*#', '${1}' . $datecode, $spec);
        file_put_contents($path, $spec);
    }

    /**
     * Like implode, but allows us to put an "and" before the last item.
     */
    protected function prettyImplode($sep, $last, $items)
    {
        if (count($items) < 2) {
            return implode($sep, $items);
        }
        $last_item = array_pop($items);
        return implode($sep, $items) . $last . $last_item;
    }
}
