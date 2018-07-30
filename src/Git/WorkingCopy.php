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
    protected $api;

    /**
     * WorkingCopy constructor
     *
     * @param $url Remote origin for the GitHub repository
     * @param $dir Checkout location for the project
     */
    protected function __construct($url, $dir, $branch = false, $api = null)
    {
        if ($api) {
            $url = $api->addTokenAuthentication($url);
        }
        $this->url = $url;
        $this->dir = $dir;
        $this->api = $api;

        $this->confirmCachedRepoHasCorrectRemote();
    }

    /**
     * Clone the specified repository to the given URL at the indicated
     * directory. If the desired repository already exists there, then
     * we will re-use it rather than re-clone the repository.
     *
     * @param string $url
     * @param string $dir
     * @param HubphAPI|null $api
     * @return WorkingCopy
     */
    public static function clone($url, $dir, $api = null)
    {
        return static::cloneBranch($url, $dir, false, $api);
    }

    /**
     * Clone the specified branch of the specified repository to the given URL.
     *
     * @param string $url
     * @param string $dir
     * @param string $branch
     * @param HubphAPI|null $api
     * @return WorkingCopy
     */
    public static function cloneBranch($url, $dir, $branch, $api)
    {
        $workingCopy = new self($url, $dir, $branch, $api);
        $workingCopy->cloneIfNecessary($branch);
        return $workingCopy;
    }

    /**
     * Blow away the existing repository at the provided directory and
     * force-push the new empty repository to the destination URL.
     *
     * @param string $url
     * @param string $dir
     * @param HubphAPI|null $api
     * @return WorkingCopy
     */
    public static function forceReinitializeFixture($url, $dir, $fixture, $api)
    {
        $fs = new Filesystem();

        // Make extra-sure that no one accidentally calls the tests on a non-fixture repo
        if (strpos($url, 'fixture') === false) {
            throw new \Exception('WorkingCopy::forceReinitializeFixture requires url to contain the string "fixture" to avoid accidental deletion of non-fixture repositories.');
        }

        // TODO: check to see if the fixture repository has never been initialized

        if (false) {
            $auth_url = $api->addTokenAuthentication($url);

            static::copyFixtureOverReinitializedRepo($dir, $fixture);
            exec("git -C {$dir} init", $output, $status);
            exec("git -C {$dir} add -A", $output, $status);
            exec("git -C {$dir} commit -m 'Initial fixture data'", $output, $status);
            static::setRemoteOrigin($auth_url, $dir);
            exec("git -C {$dir} push --force origin master");
        }

        $workingCopy = static::clone($url, $dir, $api);

        // Find the first commit and re-initialize
        $topCommit = $workingCopy->git('rev-list HEAD');
        $topCommit = $topCommit[0];
        $firstCommit = $workingCopy->git('rev-list --max-parents=0 HEAD');
        $firstCommit = $firstCommit[0];
        $workingCopy->reset($firstCommit, true);

        // TODO: Not quite working yet; overwrites .git directory even
        // without 'delete' => true
        if (false) {
            // Check to see if the fixtures changed
            // n.b. if we add 'delete' => true then our .git directory
            // disappears, which breaks everything. Without it, we risk
            // retaining deleted assets.
            $fs->mirror($fixture, $dir, null, ['override' => true, 'delete' => true]);
            static::copyFixtureOverReinitializedRepo($dir, $fixture);
            $hasModifications = $workingCopy->status();

            if (!empty($hasModifications)) {
                $workingCopy->add('.');
                $workingCopy->amend();
            }
        }

        $workingCopy->push('origin', 'master', true);

        return $workingCopy;
    }

    protected static function copyFixtureOverReinitializedRepo($dir, $fixture)
    {
        $fs = new Filesystem();
        $fs->mirror($fixture, $dir, null, ['override' => true, 'delete' => true]);
    }

    public function org()
    {
        $projectAndOrg = $this->projectAndOrgFromUrl($this->url);
        $parts = explode('/', $projectAndOrg);
        return $parts[0];
    }

    public function project()
    {
        $projectAndOrg = $this->projectAndOrgFromUrl($this->url);
        $parts = explode('/', $projectAndOrg);
        return $parts[1];
    }

    public function projectWithOrg()
    {
        return $this->projectAndOrgFromUrl($this->url);
    }

    protected function projectAndOrgFromUrl($remote)
    {
        $remote = preg_replace('#^git@[^:]*:#', '', $remote);
        $remote = preg_replace('#^[^:]*://[^/]*/#', '', $remote);
        $remote = preg_replace('#\.git$#', '', $remote);

        return $remote;
    }

    /**
     * List modified files.
     */
    public function status()
    {
        return $this->git('status --porcelain');
    }

    /**
     * Pull from the specified remote.
     */
    public function pull($remote, $branch)
    {
        $this->git('pull {remote} {branch}', ['remote' => $remote, 'branch' => $branch]);
        return $this;
    }

    /**
     * Push the specified branch to the desired remote.
     */
    public function push($remote, $branch, $force = false)
    {
        $flag = $force ? '--force ' : '';
        $this->git('push {flag}{remote} {branch}', ['remote' => $remote, 'branch' => $branch, 'flag' => $flag]);
        return $this;
    }

    /**
     * Merge the specified branch into the current branch.
     */
    public function merge($branch)
    {
        $this->git('merge {branch}', ['branch' => $branch]);
        return $this;
    }

    /**
     * Reset to the specified reference.
     */
    public function reset($ref = '', $hard = false)
    {
        $flag = $hard ? '--hard ' : '';
        $this->git('reset {flag}{ref}', ['ref' => $ref, 'flag' => $flag]);
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

    /**
     * Create a new branch
     */
    public function createBranch($branch, $base = '', $force = false)
    {
        $flag = $force ? '-B' : '-b';
        $this->git('checkout {flag} {branch} {base}', ['branch' => $branch, 'base' => $base, 'flag' => $flag]);
        return $this;
    }

    /**
     * Stage the items at the specified path.
     */
    public function add($itemsToAdd)
    {
        $this->git('add ' . $itemsToAdd);
        return $this;
    }

    /**
     * Commit the staged changes.
     *
     * @param string $message
     * @param bool $amend
     */
    public function commit($message, $amend = false)
    {
        $flag = $amend ? '--amend ' : '';
        $this->git("commit {flag}-m '{message}'", ['message' => $message, 'flag' => $flag]);
        return $this;
    }

    /**
     * Ammend the top commit without altering the message.
     */
    public function amend()
    {
        return $this->commit($this->message(), true);
    }

    /**
     * Return the commit message for the sprecified ref
     */
    public function message($ref = 'HEAD')
    {
        return trim(implode("\n", $this->git('log --format=%B -n 1 {ref}', ['ref' => $ref])));
    }

    public function branch($ref = 'HEAD')
    {
        return trim(implode("\n", $this->git('rev-parse --abbrev-ref {ref}', ['ref' => $ref])));
    }

    /**
     * Show a diff of the current modified and uncommitted files.
     */
    public function diff()
    {
        return trim(implode("\n", $this->git('diff')));
    }

    /**
     * Create a pull request.
     *
     * @param string $message
     * @return $this
     */
    public function pr($message, $body = '', $base = 'master', $head = '')
    {
        if (empty($head)) {
            $head = $this->branch();
        }
        $this->api->prCreate($this->org(), $this->project(), $message, $body, $base, $head);
        return $this;
    }

    /**
     * Show a diff of the specified reference from the commit before it.
     */
    public function show($ref = "HEAD")
    {
        return implode("\n", $this->git("show $ref"));
    }

    /**
     * Run a git function on the local working copy. Fail on error.
     *
     * @return string stdout
     */
    public function git($cmd, $replacements = [], $redacted = [])
    {
        return $this->execWithRedaction('git {dir}' . $cmd, ['dir' => "-C {$this->dir} "] + $replacements, ['dir' => ''] + $redacted);
    }

    /**
     * If the directory exists, check its remote. Fail if there is
     * some project there that is not the requested project.
     */
    protected function confirmCachedRepoHasCorrectRemote($emptyOk = false)
    {
        if (!file_exists($this->dir)) {
            return;
        }
        // Check to see if the remote origin is already set to our exact url
        $currentURL = exec("git -C {$this->dir} config --get remote.origin.url", $output, $result);
        if ($currentURL == $this->url) {
            return;
        }
        // If the API exists, try to repair the URL if the existing URL is close
        // (e.g. someone switched authentication tokens)
        if ($this->api) {
            if (($emptyOk && empty($currentURL)) || ($this->api->addTokenAuthentication($currentURL) == $this->url)) {
                static::setRemoteOrigin($this->url, $this->dir);
                return;
            }
        }

        // TODO: This error message is a potential credentials leak
        throw new \Exception("Directory `{$this->dir}` exists and is a clone of `$currentURL` rather than `{$this->url}`");
    }

    /**
     * Set the remote origin to the provided url
     * @param string $url
     * @param string $dir
     */
    protected static function setRemoteOrigin($url, $dir, $remote = 'origin')
    {
        $currentURL = exec("git -C {$dir} config --get remote.{$remote}.url");
        $gitCommand = empty($currentURL) ? 'add' : 'set-url';
        exec("git -C {$dir} remote {$gitCommand} {$remote} {$url}");
    }

    /**
     * If the directory does not exist, then clone it.
     */
    public function cloneIfNecessary($branch = false)
    {
        // If the directory exists, we have already validated that it points
        // at the correct repository.
        if (is_dir($this->dir)) {
            // Make sure that we are on 'master' (or the specified branch) and up-to-date.
            $branchTerm = $branch ?: 'master';
            exec("git -C '{$this->dir}' reset --hard 2>/dev/null", $output, $result);
            exec("git -C '{$this->dir}' checkout $branchTerm 2>/dev/null", $output, $result);
            exec("git -C '{$this->dir}' pull origin $branchTerm 2>/dev/null", $output, $result);
            return;
        }
        // Create the parents of $this->dir
        $fs = new Filesystem();
        $fs->mkdir(dirname($this->dir));

        $branchTerm = $branch ? "--branch=$branch " : '';
        exec("git clone '{$this->url}' $branchTerm'{$this->dir}' 2>/dev/null", $output, $result);
    }
}
