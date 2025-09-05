<?php

namespace UpdateTool\Git;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use UpdateTool\Util\ExecWithRedactionTrait;

class WorkingCopy implements LoggerAwareInterface
{
    use ExecWithRedactionTrait;
    use LoggerAwareTrait;

    protected $remote;
    protected $fork;
    protected $dir;
    protected $api;

    const FORCE_MERGE_COMMIT = 0x01;

    /**
     * WorkingCopy constructor
     *
     * @param $url Remote origin for the GitHub repository
     * @param $dir Checkout location for the project
     */
    protected function __construct($url, $dir, $branch = false, $api = null)
    {
        $this->remote = new Remote($url);
        
        // Add basic logging to see what's happening with authentication
        if ($api) {
            error_log("WorkingCopy: About to call addAuthentication with URL: " . $url);
            $originalUrl = $this->remote->url();
        }
        
        $this->remote->addAuthentication($api);
        
        if ($api) {
            $finalUrl = $this->remote->url();
            $changed = ($originalUrl !== $finalUrl) ? 'yes' : 'no';
            error_log("WorkingCopy: Authentication complete. URL changed: " . $changed . ", Final URL: " . preg_replace('#://[^@]*@#', '://***:***@', $finalUrl));
        }
        
        $this->dir = $dir;
        $this->api = $api;

        $this->confirmCachedRepoHasCorrectRemote();
    }

    public function fromDir($dir, $api = null)
    {
        $this->remote = Remote::fromDir($dir);
        $this->remote->addAuthentication($api);
        $this->dir = $dir;
        $this->api = $api;
    }

    /**
     * addFork will set a secondary remote on this repository.
     * The purpose of having a fork remote is if the primary repository
     * is read-only. If a fork is set, then any branches pushed
     * will go to the fork; any pull request created will still be
     * set on the primary repository, but will refer to the branch on
     * the fork.
     */
    public function addFork($fork_url)
    {
        if (empty($fork_url)) {
            $this->fork = null;
            return $this;
        }
        $this->fork = new Remote($fork_url);
        $this->fork->addAuthentication($this->api);

        $this->addRemote($this->fork->url(), 'fork');

        return $this;
    }

    /**
     * createFork creates a new secondary repository copied from
     * the current repository, and sets it up as a fork per 'addFork'.
     */
    public function createFork($forked_project_name, $forked_org = null, $branch = '')
    {
        [$org, $project_name] = explode('/', $this->remote->projectWithOrg());
        $result = $this->api->gitHubAPI()->api('repo')->forks()->create(
            $org,
            $project_name,
            [
                'owner' => $this->api->whoami()['login'],
                'repo' => $forked_project_name,
                'org' => $forked_org,
            ]
        );

        // 'git_url' => 'git://github.com/org/project.git',
        // 'ssh_url' => 'git@github.com:org/project.git',

        $fork_url = $result['ssh_url'];
        $result = $this->addFork($fork_url);

        $this->push('fork', $branch);

        return $result;
    }

    public function deleteFork()
    {
        if (!$this->fork) {
            return;
        }

        $this->api->gitHubAPI()->api('repo')->remove($this->fork->org(), $this->fork->project());
    }

    /**
     * forkUrl returns the URL of the forked repository that should
     * be used for creating any pull requests.
     */
    public function forkUrl()
    {
        if (!$this->fork) {
            return null;
        }
        return $this->fork->url();
    }

    public function forkProjectWithOrg()
    {
        if (!$this->fork) {
            return null;
        }
        return $this->fork->projectWithOrg();
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
     * Clone the specified repository to the given URL at the indicated
     * directory. Only clone a single commit. Since we're only interested
     * in one commit, we'll just remove the cache if it is present.
     *
     * @param string $url
     * @param string $dir
     * @param string $branch
     * @param HubphAPI|null $api
     * @return WorkingCopy
     */
    public static function shallowClone($url, $dir, $branch, $depth = 1, $api = null)
    {
        $workingCopy = new self($url, $dir, $branch, $api);
        $workingCopy->freshClone($branch, $depth);
        return $workingCopy;
    }

    public static function unclonedReference($url, $dir, $branch, $api = null)
    {
        return new self($url, $dir, $branch, $api);
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
    public static function cloneBranch($url, $dir, $branch, $api, $depth = false)
    {
        $workingCopy = new self($url, $dir, $branch, $api);
        $workingCopy->cloneIfNecessary($branch, $depth);
        return $workingCopy;
    }

    /**
     * take tranforms this local working copy such that it RETAINS all of its
     * local files (no change to any unstaged modifications or files) and
     * TAKES OVER the repository from the provided working copy.
     *
     * The local repository that was formerly in place here is disposed.
     * Any branches or commits not already pushed to the remote repository
     * are lost. Only the working files remain. The remotes for this working
     * copy become the remotes from the provided repository.
     *
     * The other working copy is disposed: its files are all removed
     * from the filesystem.
     */
    public function take(WorkingCopy $rhs)
    {
        $fs = new Filesystem();

        $ourLocalGitRepo = $this->dir() . '/.git';
        $rhsLocalGitRepo = $rhs->dir() . '/.git';

        $fs->remove($ourLocalGitRepo);
        $fs->rename($rhsLocalGitRepo, $ourLocalGitRepo);

        $this->remote = $rhs->remote();
        $this->addFork($rhs->forkUrl());
    }

    /**
     * remove will delete all of the local working files managed by this
     * object, including the '.git' directory. This method should be called
     * if the local working copy is corrupted or otherwise becomes unusable.
     */
    public function remove()
    {
        $fs = new Filesystem();
        $fs->remove($this->dir());
    }

    public function remote($remote_name = '')
    {
        if (empty($remote_name) || ($remote_name == 'origin')) {
            return $this->remote;
        }
        return Remote::fromDir($this->dir, $remote_name);
    }

    public function url($remote_name = '')
    {
        return $this->remote($remote_name)->url();
    }

    public function dir()
    {
        return $this->dir;
    }

    public function org($remote_name = '')
    {
        return $this->remote($remote_name)->org();
    }

    public function project($remote_name = '')
    {
        return $this->remote($remote_name)->project();
    }

    public function projectWithOrg($remote_name = '')
    {
        return $this->remote($remote_name)->projectWithOrg();
    }

    /**
     * List modified files.
     */
    public function status()
    {
        return $this->git('status --porcelain');
    }

    /**
     * Fetch from the specified remote.
     */
    public function fetch($remote, $branch)
    {
        $this->git('fetch --no-tags {remote} {branch}', ['remote' => $remote, 'branch' => $branch]);
        return $this;
    }

    /**
     * Fetch from the specified remote.
     */
    public function fetchTags($remote = 'origin')
    {
        $this->fetch($remote, '--tags');
        return $this;
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
    public function push($remote = '', $branch = '', $force = false)
    {
        if (empty($remote)) {
            $remote = isset($this->fork) ? 'fork' : 'origin';
        }
        if (empty($branch)) {
            $branch = $this->branch();
        }
        $flag = $force ? '--force ' : '';
        $this->git('push {flag}{remote} {branch}', ['remote' => $remote, 'branch' => $branch, 'flag' => $flag]);
        return $this;
    }

    /**
     * Force-push the branch
     */
    public function forcePush($remote = '', $branch = '')
    {
        return $this->push($remote, $branch, true);
    }

    /**
     * Merge the specified branch into the current branch.
     */
    public function merge($branch, $modes = 0)
    {
        $flags = '';
        if ($modes & static::FORCE_MERGE_COMMIT) {
            $flags .= ' --no-ff';
        }

        $this->git('merge{flags} {branch}', ['branch' => $branch, 'flags' => $flags]);
        return $this;
    }

    public function cherryPick($sha)
    {
        $this->git('cherry-pick {sha}', ['sha' => $sha]);
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
     * switchBranch is a synonym for 'checkout'
     */
    public function switchBranch($branch)
    {
        $this->git('checkout {branch}', ['branch' => $branch]);
        return $this;
    }

    /**
     * Switch to the specified branch. Use 'createBranch' to create a new branch.
     */
    public function checkout($branch)
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
     * Stage everything
     */
    public function addAll()
    {
        $this->git('add -A --force .');
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
     * Commit the staged changes by a specified user at specified date.
     *
     * @param string $message
     * @param string $author
     * @param string $commit_date
     * @param bool $amend
     */
    public function commitBy($message, $author, $commit_date, $amend = false)
    {
        $flag = $amend ? '--amend ' : '';
        $this->git("commit {flag}-m '{message}' --author='{author}' --date='{date}'", ['message' => $message, 'author' => $author, 'date' => $commit_date, 'flag' => $flag]);
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
     * Add a tag
     */
    public function tag($tag, $ref = '')
    {
        $this->git("tag $tag $ref");
        return $this;
    }

    /**
     * Apply a patch
     */
    public function apply($file)
    {
        $this->git('apply < {file}', ['file' => $file]);
        return $this;
    }

    /**
     * Return the commit message for the sprecified ref
     */
    public function message($ref = 'HEAD')
    {
        return trim(implode("\n", $this->git('log --format=%B -n 1 {ref}', ['ref' => $ref])));
    }

    /**
     * Return the commit date for the sprecified ref
     */
    public function commitDate($ref = 'HEAD')
    {
        return trim(implode("\n", $this->git('log -1 --date=iso --pretty=format:"%cd" {ref}', ['ref' => $ref])));
    }

    public function branch($ref = 'HEAD')
    {
        return trim(implode("\n", $this->git('rev-parse --abbrev-ref {ref}', ['ref' => $ref])));
    }

    public function revParse($ref)
    {
        return trim(implode("\n", $this->git('rev-parse {ref}', ['ref' => $ref])));
    }

    /**
     * Show a diff of the current modified and uncommitted files.
     */
    public function diff()
    {
        return trim(implode("\n", $this->git('diff')));
    }

    /**
     * Show a diff between two references (e.g. tags)
     */
    public function diffRefs($from, $to)
    {
        return trim(implode("\n", $this->git('diff {from} {to}', ['from' => $from, 'to' => $to])));
    }

    /**
     * Create a pull request.
     *
     * @param string $message
     * @return $this
     */
    public function pr($message, $body = '', $base = 'master', $head = '', $forked_org = '')
    {
        if (empty($head)) {
            $head = $this->branch();
        }
        if (isset($this->fork)) {
            $forked_org = $this->fork->org();
            $head = "$forked_org:$head";
        }

        $this->logger->notice('Create pull request for {org_project} using {head} from {base}', ['org_project' => $this->projectWithOrg(), 'head' => $head, 'base' => $base]);

        $result = $this->api->prOpen($this->org(), $this->project(), $message, $body, $base, $head);

        return $result;
    }

    /**
     * Show a diff of the specified reference from the commit before it.
     */
    public function show($ref = "HEAD")
    {
        return implode("\n", $this->git("show $ref"));
    }

    /**
     * Add a remote (or change the URL to an existing remote)
     */
    public function addRemote($url, $remote)
    {
        return static::setRemoteUrl($url, $this->dir, $remote);
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

        if ($currentURL == $this->url()) {
            return;
        }
        // If the API exists, try to repair the URL if the existing URL is close
        // (e.g. someone switched authentication tokens)
        if ($this->api) {
            if (($emptyOk && empty($currentURL)) || ($this->api->addTokenAuthentication($currentURL) == $this->url())) {
                static::setRemoteUrl($this->url(), $this->dir);
                return;
            }
        }

        // TODO: This error message is a potential credentials leak
        throw new \Exception("Directory `{$this->dir}` exists and is a clone of `$currentURL` rather than `{$this->url()}`");
    }

    /**
     * Set the remote origin to the provided url
     * @param string $url
     * @param string $dir
     * @param string $remote
     */
    protected static function setRemoteUrl($url, $dir, $remote = 'origin')
    {
        if (is_dir($dir)) {
            $currentURL = exec("git -C {$dir} config --get remote.{$remote}.url");
            $gitCommand = empty($currentURL) ? 'add' : 'set-url';
            exec("git -C {$dir} remote {$gitCommand} {$remote} {$url}");
        }
        $remote = new Remote($url);

        return $remote;
    }

    /**
     * If the directory does not exist, then clone it.
     */
    public function cloneIfNecessary($branch = false, $depth = false)
    {
        // If the directory exists, we have already validated that it points
        // at the correct repository.
        if (!is_dir($this->dir)) {
            $this->freshClone($branch, $depth);
        }
        // Make sure that we are on 'master' (or the specified branch) and up-to-date.
        $branchTerm = $branch ?: 'master';
        exec("git -C '{$this->dir}' reset --hard 2>/dev/null", $output, $result);
        exec("git -C '{$this->dir}' checkout $branchTerm 2>/dev/null", $output, $result);
        exec("git -C '{$this->dir}' pull origin $branchTerm 2>/dev/null", $output, $result);
    }

    protected function freshClone($branch = false, $depth = false)
    {
        // Remove $this->dir if it exists, then make sure its parents exist.
        $fs = new Filesystem();
        if (is_dir($this->dir)) {
            $fs->remove($this->dir);
        }
        $fs->mkdir(dirname($this->dir));

        $branchTerm = $branch ? "--branch=$branch " : '';
        $depthTerm = $depth ? "--depth=$depth " : '';
        
        // Log the URL being used for cloning (without exposing token)
        $url = $this->url();
        $logUrl = preg_replace('#://[^@]*@#', '://***:***@', $url);
        if ($this->logger) {
            $this->logger->notice("Cloning repository from {url} to {dir}", ['url' => $logUrl, 'dir' => $this->dir]);
        }
        
        exec("git clone '{$url}' $branchTerm$depthTerm'{$this->dir}' 2>&1", $output, $result);

        // Fail if we could not clone.
        if ($result) {
            $project = $this->projectWithOrg();
            if ($this->logger) {
                $this->logger->error("Git clone failed with exit code {code}. Output: {output}", ['code' => $result, 'output' => implode("\n", $output)]);
            }
            throw new \Exception("Could not clone $project: git failed with exit code $result. Output: " . implode("\n", $output));
        }
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
     * Clean untracked files
     */
    public function clean($flags = '-df')
    {
        $this->git('clean {flags}', ['flags' => $flags]);
    }
}
