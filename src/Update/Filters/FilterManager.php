<?php

namespace Updatinate\Update\Filters;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Manage a collection of filters.
 */
class FilterManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

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
     * Run the action on each of our filters in turn.
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
            $filter->action($src, $dest, $parameters);
        }
    }
}
