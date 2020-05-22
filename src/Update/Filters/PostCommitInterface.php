<?php

namespace Updatinate\Update\Filters;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Updatinate\Git\WorkingCopy;

/**
 * Update filters modify the destination after it is updated.
 */
interface PostCommitInterface
{
    /**
     * Alter our update project after the update commit is made.
     */
    public function postCommit(WorkingCopy $updatedProject, array $parameters);
}
