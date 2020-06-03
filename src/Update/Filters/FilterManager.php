<?php

namespace Updatinate\Update\Filters;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Updatinate\Git\WorkingCopy;

/**
 * Manage a collection of filters.
 */
class FilterManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $filters = [];

    /**
     * Instantiate requested filters.
     *
     * @param string[] $filter_classnames
     */
    public function getFilters($filter_classnames)
    {
        foreach ((array)$filter_classnames as $filter_class) {
            $filter_fqcn = "\\Updatinate\\Update\\Filters\\$filter_class";
            $filter = new $filter_fqcn();
            if ($filter instanceof LoggerAwareInterface) {
                $filter->setLogger($this->logger);
            }
            $this->filters[] = $filter;
        }
    }

    /**
     * Run the filter action on each of our filters in turn.
     *
     * @param string $src
     *   Path to source of site being updated
     * @param string $dest
     *   Path to updated copy of site
     * @param string[] $parameters
     *   Map of named parameters
     */
    public function apply($src, $dest, $parameters)
    {
        foreach ($this->filters as $filter) {
            if ($filter instanceof UpdateFilterInterface) {
                $this->logger->notice('Apply filter {filter}', ['filter' => get_class($filter)]);
                $filter->action($src, $dest, $parameters);
                $this->logger->notice('Filter applied.');
            }
        }
    }

    /**
     * Call the post commit method on each filters.
     *
     * @param WorkingCopy $updatedProject
     *   The updated project, after the update commit has been made.
     * @param string[] $parameters
     *   Map of named parameters
     */
    public function postCommit(WorkingCopy $updatedProject, array $parameters)
    {
        foreach ($this->filters as $filter) {
            if ($filter instanceof PostCommitInterface) {
                $this->logger->notice('Post-commit action for {filter}', ['filter' => get_class($filter)]);
                $filter->postCommit($updatedProject, $parameters);
                $this->logger->notice('Post-commit action complete.');
            }
        }
    }
}
