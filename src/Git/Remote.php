<?php

namespace Updatinate\Git;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Updatinate\Util\ExecWithRedactionTrait;

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

    // https://{$token}:x-oauth-basic@github.com/{$projectWithOrg}.git";
    // git@github.com:{$projectWithOrg}.git

    public function projectWithOrg()
    {
        $remote = $this->remote;

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
    public function tags($filter = '', $stable = false)
    {
        $tags = $this->git('ls-remote --tags --refs {remote}', ['remote' => $this->remote]);
        $trailing = $stable ? '[0-9.]*' : '.*';
        $regex = "#([^ ]+)[ \t]+refs/tags/($filter$trailing\$)#";
        $result = [];
        foreach ($tags as $tagLine) {
            if (preg_match($regex, $tagLine, $matches)) {
                $sha = $matches[1];
                $tag = $matches[2];
                $result[$tag] = ['ref' => $sha];
            }
        }
        // Sort result by keys using natural order
        uksort($result, "strnatcmp");
        return $result;
    }

    public function releases($majorVersion = '[0-9]+')
    {
        return $this->tags("$majorVersion\.", true);
    }

    /**
     * Return the latest release in the specified major version series
     */
    public function latest($majorVersion = '[0-9]+')
    {
        $tags = $this->releases($majorVersion);
        $tags = array_keys($tags);
        return array_pop($tags);
    }

    /**
     * Return 'true' if the provided tag exists on this remote.
     */
    public function has($tag_to_check, $majorVersion = '[0-9]+')
    {
        $tags = $this->releases($majorVersion);

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
}
