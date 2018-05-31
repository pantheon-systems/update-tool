<?php

namespace Updatinate\Cli;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Robo\Common\ConfigAwareTrait;
use Updatinate\Git\WorkingCopy;

class PhpCommands extends \Robo\Tasks implements ConfigAwareInterface, LoggerAwareInterface
{
    use ConfigAwareTrait;
    use LoggerAwareTrait;

    /**
     * Given a set of available php RPMs, as specified in the rpmbuild-php
     * repository, create a PR in the php cookbook to deploy those rpms.
     *
     * @command php:cookbook:update
     */
    public function phpCookbookUpdate()
    {
        $token = $this->getGitHubToken();

        $rpmbuild_php_url = $this->getConfig()->get('projects.rpmbuild-php.repo');
        $rpmbuild_php_dir = $this->getConfig()->get('projects.rpmbuild-php.path');

        $php_cookbook_url = $this->getConfig()->get('projects.php-cookbook.repo');
        $php_cookbook_dir = $this->getConfig()->get('projects.php-cookbook.path');

        $rpmbuild_php = new WorkingCopy($rpmbuild_php_url, $rpmbuild_php_dir);
        $rpmbuild_php
            ->setLogger($this->logger);

        // Look at the most recent commit on the current branch.
        $output = $rpmbuild_php->show('HEAD');

        // Look for changes to both the php_version and rpm_datecode in the same file.
        $version_updates = [];
        $version = false;
        foreach ($output as $line) {
            if (preg_match('#^diff #', $line)) {
                $version = false;
            }
            elseif (preg_match('#\+%define php_version *([0-9.]*)#', $line, $matches)) {
                $version = $matches[1];
            }
            elseif (preg_match('#\+%define rpm_datecode *([0-9]*)#', $line, $matches)) {
                $rpm_datecode = $matches[1];
                $version_updates[$version] = "{$version}-{$rpm_datecode}";
            }
        }

        // If there were no updates to php versions, then we are done.
        if (empty($version_updates)) {
            $this->say("Nothing was updated.");
            return;
        }

        $php_cookbook = new WorkingCopy($php_cookbook_url, $php_cookbook_dir);
        $php_cookbook
            ->setLogger($this->logger);

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

        // Create a pull request with the update.
        $branch = 'php-' . implode('-', array_keys($version_updates));
        $all_updated_versions = implode(' ', array_keys($version_updates));
        $message = "Update to PHP $all_updated_versions";
        $php_cookbook
            ->createBranch($branch)
            ->add('libraries/php.rb')
            ->commit($message)
            ->push('origin', $branch)
            ->pr($message);
    }

    /**
     * Determine if there are any php version updates available, and if so,
     * create a PR in the rpmbuild-php repository.
     *
     * @command php:rpm:update
     * @aliases php:update
     */
    public function phpRpmUpdate()
    {
        $token = $this->getGitHubToken();

        $url = $this->getConfig()->get('projects.rpmbuild-php.repo');
        $work_dir = $this->getConfig()->get('projects.rpmbuild-php.path');

        // Ensure that a local working copy of the project has
        // been checked out and is available.
        $rpmbuild_php = new WorkingCopy($url, $work_dir);
        $rpmbuild_php
            ->setLogger($this->logger);
        $rpmbuild_php
            ->switchBranch('master')
            ->pull('origin', 'master');

        $datecode = date("Ymd");

        exec("grep '^%define php_version ' $work_dir/php*/php.spec | sed -e 's#[^ ]* *php_version *##'", $versions, $status);

        $updated_versions = [];
        foreach ($versions as $version) {

          $next_version = $this->next_version_that_exists($version);

          if ($next_version != $version) {
            $this->say("$next_version is available, but we are still on version $version");

            // TODO: we need to determine if there is already an open pull request that contains $next_version
            $this->update_spec($next_version, $datecode, $work_dir);
            $updated_versions[] = $next_version;
          }
          else {
            $this->say("$version is the most recent version");
          }
        }

        if (!empty($updated_versions)) {
            $all_updated_versions = $this->pretty_implode(', ', ' and ', $updated_versions);
            $branch = 'php-' . implode('-', $updated_versions);
            $message = "Update to PHP $all_updated_versions";
            $rpmbuild_php
                ->createBranch($branch)
                ->add('php-*')
                ->commit($message)
                ->push('origin', $branch)
                ->pr($message);
        }
    }

    protected function next_version($version)
    {
        $parts = explode('.', $version);
        $parts[count($parts) - 1]++;

        return implode('.', $parts);
    }

    protected function version_exists($version)
    {
        $url = "http://php.net/distributions/php-$version.tar.gz";

        exec("curl -s -I $url", $output, $status);
        return (strpos($output[0], '200 OK') !== false);
    }

    protected function next_version_that_exists($version)
    {
        $next_version = $version;
        $try_version = $this->next_version($version);
        while ($this->version_exists($try_version)) {
                    $next_version = $try_version;
                    $try_version = $this->next_version($next_version);
        }
        return $next_version;
    }

    protected function update_spec($version, $datecode, $dir)
    {
        $parts = explode('.', $version);
        $major_minor = $parts[0] . '.' . $parts[1];
        $path = "{$dir}/php-{$major_minor}/php.spec";
        $spec = file_get_contents($path);
        $spec = preg_replace('#(%define php_version *).*#', '${1}' . $version, $spec);
        $spec = preg_replace('#(%define rpm_datecode *).*#', '${1}' . $datecode, $spec);
        file_put_contents($path, $spec);
    }

    protected function pretty_implode($sep, $last, $items)
    {
        if (count($items) < 2) {
            return implode($sep, $items);
        }
        $last_item = array_pop($items);
        return implode($sep, $items) . $last . $last_item;
    }

    /**
     * Look up the GitHub token set either via environment variable or in the
     * auth-token cache directory.
     */
    protected function getGitHubToken()
    {
        $github_token_cache = $this->getConfig()->get('github.personal-auth-token.path');
        if (file_exists($github_token_cache)) {
            $token = trim(file_get_contents($github_token_cache));
            putenv("GITHUB_TOKEN=$token");
        }
        else {
            $token = getenv('GITHUB_TOKEN');
        }

        return $token;
    }
}
