<?php

namespace Updatinate\Update\Methods;

use Symfony\Component\Filesystem\Filesystem;
use Updatinate\Git\WorkingCopy;

/**
 * MergeUpstreamBranch is an update method that takes all of the changes from
 * the upstream repository and merges them into the working copy of the
 * project being updated.
 */
class MergeUpstreamBranch extends UpdateMethodBase
{
    public function update(WorkingCopy $project, WorkingCopy $upstream)
    {

    }
}
