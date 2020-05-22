<?php

namespace Updatinate\Update\Filters;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;

/**
 * RemoveUnwanted is an update filter that will remove files
 * and directories from a list.
 */
class RemoveUnwanted implements UpdateFilterInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Unwanted files are exactly that: things we do not want in the target.
     */
    public function action($src, $dest, $parameters)
    {
        $parameters += ['unwanted' => []];

        $fs = new Filesystem();
        foreach ($parameters['unwanted'] as $item) {
            $this->logger->notice('Removing {item}', ['item' => $item]);
            $fs->remove("$dest/$item");
        }
    }
}
