<?php

namespace UpdateTool\Update\Filters;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use UpdateTool\Util\ExecWithRedactionTrait;
use UpdateTool\Util\TmpDir;

/**
 * OptimizeAutoloader is an update filter that ensures that the autoloader
 * is optimized.
 */
class OptimizeAutoloader implements UpdateFilterInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;
    use ExecWithRedactionTrait;

    /**
     * Run composer install to optimize the autoloader.
     */
    public function action($src, $dest, $parameters)
    {
        $this->logger->notice("Running composer install");

        passthru("composer --working-dir=$dest -q --no-interaction install --prefer-dist --no-dev --optimize-autoloader");
    }
}
