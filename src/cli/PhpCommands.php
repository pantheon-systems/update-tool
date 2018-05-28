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
     * Multiply two numbers together
     *
     * @command php:rpm
     */
    public function phpRpm()
    {
        $github_token_cache = $this->getConfig()->get('github.personal-auth-token.path');
        $this->say("The token cache path is $github_token_cache");
        if (file_exists($github_token_cache)) {
            $token = trim(file_get_contents($github_token_cache));
            putenv("GITHUB_TOKEN=$token");
        }

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
}
