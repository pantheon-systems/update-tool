<?php


namespace UpdateTool\Git;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use UpdateTool\Util\ExecWithRedactionTrait;

trait Git
{
    use ExecWithRedactionTrait;

    /**
     * Run a git function on the local working copy. Fail on error.
     *
     * @return string stdout
     */
    public function git($cmd, $replacements = [], $redacted = [])
    {
        return $this->execWithRedaction('git {dir}' . $cmd, ['dir' => "-C {$this->dir()} "] + $replacements, ['dir' => ''] + $redacted);
    }
}
