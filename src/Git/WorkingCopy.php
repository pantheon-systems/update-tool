<?php

namespace Updatinate\Git;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Updatinate\Util\ExecWithRedactionTrait;

class WorkingCopy implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    use ExecWithRedactionTrait;

    protected $url;
    protected $dir;

    /**
     * WorkingCopy constructor
     *
     * @param $url Remote origin for the GitHub repository
     * @param $dir Checkout location for the project
     */
    public function __construct($url, $dir, $branch = false)
    {
        $this->url = $url;
        $this->dir = $dir;

        $this->confirmNoConflict();
        $this->cloneIfNecessary($branch);
    }

    public function pull($remote, $branch)
    {
        $this->git('pull {remote} {branch}', ['remote' => $remote, 'branch' => $branch]);
        return $this;
    }

    public function push($remote, $branch)
    {
        $this->git('push {remote} {branch}', ['remote' => $remote, 'branch' => $branch]);
        return $this;
    }

    /**
     * Ensure we are on the correct branch. Update to the
     * latest HEAD from origin.
     */
    public function switchBranch($branch)
    {
        $this->git('checkout {branch}', ['branch' => $branch]);
        return $this;
    }

    public function createBranch($branch)
    {
        $this->git('checkout -b {branch}', ['branch' => $branch]);
        return $this;
    }

    public function add($itemsToAdd)
    {
        $this->git('add ' . $itemsToAdd);
        return $this;
    }

    public function commit($message)
    {
        $this->git("commit -m '{message}'", ['message' => $message]);
        return $this;
    }

    public function pr($message)
    {
        $replacements = ['message' => $message];
        $redacted = [];

        //$this->execWithRedaction("hub pull-request -m '{message}'", ['dir' => "-C {$this->dir} "] + $replacements, ['dir' => ''] + $redacted);

        $oldDir = getcwd();
        chdir($this->dir);
        exec("hub pull-request -m '$message'", $output, $status);
        chdir($oldDir);

        return $this;
    }

    public function show($ref)
    {
        return $this->git("show $ref");
    }

    /**
     * Run a git function on the local working copy. Fail on error.
     *
     * @return string stdout
     */
    protected function git($cmd, $replacements = [], $redacted = [])
    {
        return $this->execWithRedaction('git {dir}' . $cmd, ['dir' => "-C {$this->dir} "] + $replacements, ['dir' => ''] + $redacted);
    }

    /**
     * If the directory exists, check its remote. Fail if there is
     * some project there that is not the requested project.
     */
    protected function confirmNoConflict()
    {
        if (!file_exists($this->dir)) {
            return;
        }
        exec("git -C {$this->dir} config --get remote.origin.url", $output, $result);
        if ($output[0] == $this->url) {
            return;
        }
        throw new \Exception("Directory `{$this->dir}` exists and is not a clone of `{$this->url}`");
    }

    /**
     * If the directory does not exist, then clone it.
     */
    protected function cloneIfNecessary($branch = false)
    {
        // If the directory exists, we have already validated that it is okay
        if (is_dir($this->dir)) {
            return;
        }
        // Create the parents of $this->dir
        $fs = new Filesystem();
        $fs->mkdir(dirname($this->dir));

        $branchTerm = $branch ? "--branch=$branch " : '';
        exec("git clone '{$this->url}' $branchTerm'{$this->dir}'", $output, $result);
    }
}
