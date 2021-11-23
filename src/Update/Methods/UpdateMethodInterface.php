<?php

namespace UpdateTool\Update\Methods;

use UpdateTool\Git\WorkingCopy;
use UpdateTool\Update\Filters\FilterManager;
use Consolidation\Config\ConfigInterface;

/**
 * UpdateMethodInterface defines an interface for updating projects from
 * their upstream repositories using git.
 */
interface UpdateMethodInterface
{
    /**
     * Copy any information needed from configuration.
     */
    public function configure(ConfigInterface $config, $project);

    /**
     * Determine the most recent version of the given project that is available
     */
    public function findLatestVersion($major, $tag_prefix, $update_parameters);

    /**
     * Update the project's working copy. Return $project if it was updated
     * in place; return a new WorkingCopy object if a new project was created
     * for the purpose of doing the update.
     */
    public function update(WorkingCopy $project, array $parameters);

    /**
     * Modify the updated project after the update commit is made.
     */
    public function postCommit(WorkingCopy $updatedProject, array $parameters);

    /**
     * Do any cleanup tasks required after an update function completes.
     */
    public function complete(array $parameters);

    /**
     * Set the filters to be applied after the update
     *
     * @param FilterManger $filters
     */
    public function setFilters(FilterManager $filters);
}
