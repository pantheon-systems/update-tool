<?php

namespace Updatinate\Update\Filters;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Update filters modify the destination after it is updated.
 */
interface UpdateFilterInterface
{
    /**
     * Do whatever action the filter is designed to do.
     */
    public function action($src, $dest, $parameters);
}
