<?php

namespace UpdateTool\Update\Filters;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;

/**
 * CopyPlatformAdditions is an update filter that will copy files
 * from the source branch onto the branch being updated.
 *
 * See also 'RsyncFromSource'
 */
class CopyPlatformAdditions implements UpdateFilterInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Platform "additions" are files in the Pantheon repository that
     * do not exist in the upstream. These must all be listed in the
     * update parameters. Listed files are copied from the Pantheon
     * repository into the repository being updated, which starts off
     * as a pristine copy of the upstream.
     */
    public function action($src, $dest, $parameters)
    {
        $parameters += ['platform-additions' => []];
        $this->logger->notice('doing platform additions');
        foreach ($parameters['platform-additions'] as $item) {
            $this->logger->notice('Copying {item}', ['item' => $item]);
            $this->copyFileOrDirectory($src . '/' . $item, $dest . '/' . $item);
        }
    }

    /**
     * Helpful wrapper to call either 'mirror' or 'copy' as needed.
     */
    protected function copyFileOrDirectory($src, $dest)
    {
        $fs = new Filesystem();
        $this->logger->notice('copying {src} to {dest}', ['src' => $src, 'dest' => $dest]);
        $fs->mkdir(dirname($dest));
        if (is_dir($src)) {
            $fs->mirror($src, $dest);
        } else {
            $fs->copy($src, $dest, true);
        }
    }
}
