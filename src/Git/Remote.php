<?php

namespace UpdateTool\Git;

use Composer\Semver\Semver;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use UpdateTool\Util\ExecWithRedactionTrait;

class Remote implements LoggerAwareInterface
{
    use ExecWithRedactionTrait;
    use LoggerAwareTrait;

    protected $remote;

    public function __construct($remote)
    {
        $this->remote = $remote;
    }

    public static function create($remote, $api = null)
    {
        $remote = new self($remote);
        $remote->addAuthentication($api);
        return $remote;
    }

    public static function fromDir($dir, $remote = 'origin')
    {
        $currentURL = exec("git -C {$dir} config --get remote.{$remote}.url");
        return new self($currentURL);
    }

    public function addAuthentication($api = null)
    {
        if ($api) {
            $this->remote = $api->addTokenAuthentication($this->remote);
        }
    }

    public function projectWithOrg()
    {
        return static::projectWithOrgFromUrl($this->remote);
    }

    // https://{$token}:x-oauth-basic@github.com/{$projectWithOrg}.git";
    // git@github.com:{$projectWithOrg}.git
    public static function projectWithOrgFromUrl($remote)
    {
        $remote = preg_replace('#^git@[^:]*:#', '', $remote);
        $remote = preg_replace('#^[^:]*://[^/]*/#', '', $remote);
        $remote = preg_replace('#\.git$#', '', $remote);

        return $remote;
    }

    public function url()
    {
        return $this->remote;
    }

    public function org()
    {
        $projectWithOrg = $this->projectWithOrg();
        $parts = explode('/', $projectWithOrg);
        return $parts[0];
    }

    public function project()
    {
        $projectWithOrg = $this->projectWithOrg();
        $parts = explode('/', $projectWithOrg);
        return $parts[1];
    }

    public function host()
    {
        if (preg_match('#@([^:/]+)[:/]', $this->remote, $matches)) {
            return $matches[1];
        }
        return false;
    }

    /**
     * Return an associative array of tag names -> sha
     *
     * @return array
     */
    public function tags($constraint_arg, $stable = true, $tag_prefix = '')
    {
        $filter = $this->appearsToBeSemver($constraint_arg) ? '' : $constraint_arg;
        $version_constraints = $this->appearsToBeSemver($constraint_arg) ? $constraint_arg : '*';
        $trailing = $stable ? '[0-9.]*' : '.*';
        $tags = $this->git('ls-remote --tags --refs {remote}', ['remote' => $this->remote]);
        $regex = "#([^ ]+)[ \t]+refs/tags/$tag_prefix($filter$trailing\$)#";
        $result = [];
        foreach ($tags as $tagLine) {
            if (preg_match($regex, $tagLine, $matches)) {
                $sha = $matches[1];
                $tag = $matches[2];
                if ($this->satisfies($tag, $version_constraints)) {
                    $result[$tag] = ['ref' => $sha];
                }
            }
        }
        // Sort result by keys using natural order
        uksort($result, "strnatcmp");
        return $result;
    }

    /**
     * Returns an array of the last X commit hashes.
     *
     * @return string
     */
    public function commits($branch = null)
    {
        // If branch wasn't manually specified, retrieve the default branch from the repo.
        if (empty($branch)) {
            $branch = $this->git('remote show {remote}', ['remote' => $this->remote]);
            $branch = trim($branch[3]);
            // The response always begins with "HEAD branch: ", trim.
            $branch = substr($branch, 13);
        }


        $commit_hash = $this->git('ls-remote {remote} {branch}', ['remote' => $this->remote, 'branch' => $branch]);

        if (!empty($commit_hash)) {
            return $commit_hash;
        } else {
            return null;
        }
    }

    /**
     * Delete a tag from the remote
     */
    public function delete($tag)
    {
        $this->git('push --delete {remote} {tag}', ['remote' => $this->remote, 'tag' => $tag]);
    }

    protected function satisfies($tag, $version_constraints)
    {
        // If we are using a regex rather than semver, then pass anything.
        if ($version_constraints == '*') {
            return true;
        }

        try {
            return Semver::satisfies($tag, $version_constraints);
        } catch (\Exception $e) {
            return false;
        }
    }

    // @deprecated: we should just use semver everywhere, not regex.
    // The downside to this theory is that WordPress doesn't use semver.
    protected function appearsToBeSemver($arg)
    {
        return ($arg[0] == '^') || ($arg[0] == '~');
    }

    public function releases($majorVersion /*= '[0-9]+'*/, $stable, $tag_prefix)
    {
        return $this->tags("$majorVersion\.", $stable, $tag_prefix);
    }

    /**
     * Return the latest release in the specified major version series
     *
     * TODO: allow for beta or RC builds (by request via a second parameter)
     */
    public function latest($majorVersion /*= '[0-9]+'*/, $stable, $tag_prefix)
    {
        $tags = $this->releases($majorVersion, $stable, $tag_prefix);
        $tags = array_keys($tags);
        return array_pop($tags);
    }

    /**
     * Return 'true' if the provided tag exists on this remote.
     */
    public function has($tag_to_check, $majorVersion = '[0-9]+', $stable = false, $tag_prefix = '')
    {
        $tags = $this->releases($majorVersion, $stable, $tag_prefix);
        return array_key_exists($tag_to_check, $tags);
    }

    /**
     * Return a sanitized version of this remote (sans authentication string)
     */
    public function __toString()
    {
        $host = $this->host();
        $projectWithOrg = $this->projectWithOrg();

        return "git@{$host}:{$projectWithOrg}.git";
    }

    /**
     * Run a git function on the local working copy. Fail on error.
     *
     * @return string stdout
     */
    public function git($cmd, $replacements = [], $redacted = [])
    {
        return $this->execWithRedaction('git ' . $cmd, $replacements, $redacted);
    }

    /**
     * Return the commit message for the sprecified ref
     */
    public function message($ref = 'HEAD', $dir = '')
    {
        return trim(implode("\n", $this->git('{dir} log --format=%B -n 1 {ref}', ['dir' => !empty($dir) ? "-C $dir" : $dir, 'ref' => $ref])));
    }
}
