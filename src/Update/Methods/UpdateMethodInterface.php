<?php

namespace Updatinate\Update\Methods;

use Updatinate\Git\WorkingCopy;

/**
 * UpdateMethodInterface defines an interface for updating projects from
 * their upstream repositories using git.
 */
interface UpdateMethodInterface
{
    public function update(WorkingCopy $project, WorkingCopy $upstream, array $parameters);
    public function complete(WorkingCopy $project, WorkingCopy $upstream, array $parameters);
}
