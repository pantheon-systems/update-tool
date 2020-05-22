<?php

namespace Updatinate\Update\Filters;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Updatinate\Util\ExecWithRedactionTrait;
use Updatinate\Util\TmpDir;
use Updatinate\Git\WorkingCopy;

/**
 * Cherry-pick the CircleCI tests as a separate commit.
 */
class ApplyCircleTests implements PostCommitInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;
    use ExecWithRedactionTrait;

    /**
     * Find the Circle tests and cherry-pick them
     */
    public function postCommit(WorkingCopy $updatedProject, array $parameters)
    {
        // Find the sha of the Circle tests
        $tests_sha = $updatedProject->revParse('circle-tests');
        $updatedProject->cherryPick($tests_sha);
    }
}
