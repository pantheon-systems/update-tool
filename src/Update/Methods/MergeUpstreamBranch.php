<?php

namespace Updatinate\Update\Methods;

use Consolidation\Config\ConfigInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Updatinate\Git\WorkingCopy;

/**
 * MergeUpstreamBranch is an update method that takes all of the changes from
 * the upstream repository and merges them into the working copy of the
 * project being updated.
 */
class MergeUpstreamBranch implements UpdateMethodInterface, LoggerAwareInterface
{
    use UpdateMethodTrait;
    use LoggerAwareTrait;

    /**
     * @inheritdoc
     */
    public function configure(ConfigInterface $config, $project)
    {
    }

    /**
     * @inheritdoc
     */
    public function findLatestVersion($major, $tag_prefix, $update_parameters)
    {
    }

    /**
     * @inheritdoc
     */
    public function update(WorkingCopy $project, array $parameters)
    {
        return $project;
    }

    /**
     * @inheritdoc
     */
    public function postCommit(WorkingCopy $updatedProject, array $parameters)
    {
    }

    /**
     * @inheritdoc
     */
    public function complete(array $parameters)
    {
    }
}
