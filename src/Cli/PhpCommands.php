<?php

namespace Updatinate\Cli;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Robo\Common\ConfigAwareTrait;
use Updatinate\Git\WorkingCopy;
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
     * Given a set of available php RPMs, as specified in the rpmbuild-php
     * repository, create a PR in the php cookbook to deploy those rpms.
     *
     * @command php:cookbook:update
     */
    public function phpCookbookUpdate($options = ['as' => 'default'])
    {
        $api = $this->api($options['as']);

        $rpmbuild_php_url = $this->getConfig()->get('projects.rpmbuild-php.repo');
        $rpmbuild_php_dir = $this->getConfig()->get('projects.rpmbuild-php.path');

        $php_cookbook_url = $this->getConfig()->get('projects.php-cookbook.repo');
        $php_cookbook_dir = $this->getConfig()->get('projects.php-cookbook.path');

        $rpmbuild_php = WorkingCopy::clone($rpmbuild_php_url, $rpmbuild_php_dir, $api);
        $rpmbuild_php
            ->setLogger($this->logger);

        // Look at the most recent commit on the current branch.
        $output = $rpmbuild_php->git('show HEAD');

        // Look for changes to both the php_version and rpm_datecode in the same file.
        $version_updates = [];
        $version = false;
        foreach ($output as $line) {
            if (preg_match('#^diff #', $line)) {
                $version = false;
            } elseif (preg_match('#.%define php_version *([0-9.]*)#', $line, $matches)) {
                $version = $matches[1];
            } elseif ($version && preg_match('#\+%define rpm_datecode *([0-9]*)#', $line, $matches)) {
                $rpm_datecode = $matches[1];
                $version_updates[$version] = "{$version}-{$rpm_datecode}";
            }
        }

        // If there were no updates to php versions, then we are done.
        if (empty($version_updates)) {
            $this->logger->notice("Nothing was updated.");
            return;
        }

        $php_cookbook = WorkingCopy::clone($php_cookbook_url, $php_cookbook_dir, $api);
        $php_cookbook
            ->setLogger($this->logger);

        // Create a commit message with all of our modified versions
        $all_updated_versions = $this->prettyImplode(', ', ' and ', array_map(function ($v) {
            return "php-$v";
        }, array_keys($version_updates)));
        $preamble = $this->preamble();
        $message = "{$preamble}{$all_updated_versions}";
        $this->logger->notice("Commit message {message}", ['message' => $message]);

        // Create a set of version ids from the commit message
        $vids = new VersionIdentifiers();
        $vids->setVidPattern('php-#.#.');
        $vids->setVvalPattern('#');
        $vids->setPreamble($preamble);
        $vids->addVidsFromMessage($message);

        // Modify the php.rb source file in the php cookbook to select the php rpm that was built
        $php_library_src_path = "$php_cookbook_dir/libraries/php.rb";
        $contents = file_get_contents($php_library_src_path);

        foreach ($version_updates as $version_spec) {
            if (preg_match('#^[0-9]+\.[0-9]+#', $version_spec, $matches)) {
                $major_minor = $matches[0];
                $contents = preg_replace("#'$major_minor\.[0-9]+-[0-9]{8}'#", "'$version_spec'", $contents);
            }
        }

        file_put_contents($php_library_src_path, $contents);

        // Check to see if our modifications caused any changes
        $output = $php_cookbook->status();
        if (empty($output)) {
            $this->logger->notice("Nothing was updated.");
            return;
        }

        // Check to see if there are any open PRs that have already done this
        // work, or that are old and need to be closed.
        list($status, $existingPRList) = $api->prCheck($php_cookbook->projectWithOrg(), $vids);
        if ($status) {
            // TODO: $existingPRList might be a string or an array of PR numbers. :P Fix.
            $this->logger->notice($existingPRList);
            return;
        }

        // Create a pull request with the update.
        $branch = $this->branchPrefix() . implode('-', array_keys($version_updates));
        $this->logger->notice('Using {branch}', ['branch' => $branch]);

        // Commit, push, and make the PR
        $php_cookbook
            ->createBranch($branch, 'master', true)
            ->add('libraries/php.rb')
            ->commit($message)
            ->push('origin', $branch)
            ->pr($message);

        // TODO: $existingPRList might be a string or an array of PR numbers. :P Fix.
        if (is_array($existingPRList)) {
            $api->prClose($php_cookbook->org(), $php_cookbook->project(), $existingPRList);
        }
    }

    /**
     * Determine if there are any php version updates available, and if so,
     * create a PR in the rpmbuild-php repository.
     *
     * @command php:rpm:update
     * @aliases php:update
     */
    public function phpRpmUpdate($options = ['as' => 'default'])
    {
        $api = $this->api($options['as']);

        $url = $this->getConfig()->get('projects.rpmbuild-php.repo');
        $work_dir = $this->getConfig()->get('projects.rpmbuild-php.path');

        // Ensure that a local working copy of the project has
        // been checked out and is available.
        $rpmbuild_php = WorkingCopy::clone($url, $work_dir, $api);
        $rpmbuild_php
            ->setLogger($this->logger);
        $rpmbuild_php
            ->switchBranch('master')
            ->pull('origin', 'master');

        $datecode = date("Ymd");

        // TODO: convert this snippet of bash from the old script to php.
        // Purpose: finds the current php_version lines from all php.spec files.
        exec("grep '^%define php_version ' $work_dir/php*/php.spec | sed -e 's#[^ ]* *php_version *##'", $versions, $status);

        $updated_versions = [];
        foreach ($versions as $version) {
            $next_version = $this->nextVersionThatExists($version);

            if ($next_version != $version) {
                $this->say("$next_version is available, but we are still on version $version");

                // TODO: we need to determine if there is already an open pull request that contains $next_version
                $this->updateSpec($next_version, $datecode, $work_dir);
                $updated_versions[] = $next_version;
            } else {
                $this->say("$version is the most recent version");
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
        list($status, $existingPRList) = $api->prCheck($rpmbuild_php->projectWithOrg(), $vids);
        if ($status) {
            // TODO: $existingPRList might be a string or an array of PR numbers. :P Fix.
            $this->logger->notice($existingPRList);
            return;
        }

        $branch = $this->branchPrefix() . implode('-', $updated_versions);
        $this->logger->notice('Using {branch}', ['branch' => $branch]);
        $rpmbuild_php
            ->createBranch($branch, 'master', true)
            ->add('php-*')
            ->commit($message)
            ->push('origin', $branch)
            ->pr($message);

        // TODO: $existingPRList might be a string or an array of PR numbers. :P Fix.
        if (is_array($existingPRList)) {
            $api->prClose($rpmbuild_php->org(), $rpmbuild_php->project(), $existingPRList);
        }
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
        // -s is "silent mode".
        exec("curl -s -I $url", $output, $status);
        return (strpos($output[0], '200 OK') !== false);
    }

    /**
     * Keep incrementing the provided (patch) version; return the highest
     * version number that has an available download file.
     */
    protected function nextVersionThatExists($version)
    {
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
