<?php

namespace UpdateTool\Update\Filters;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use UpdateTool\Git\WorkingCopy;

/**
 * CopyMuPlugin is an update filter that will pull the
 * latest pantheon-mu-plugin code and drop it into the
 * mu-plugins directory in WordPress.
 *
 * See also 'Copy'
 */
class CopyMuPlugin implements UpdateFilterInterface, LoggerAwareInterface {
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
        $repo = $parameters['muplugin']['repo'];
        $path = $parameters['muplugin']['path'];
        $dest = $dest ? $dest : $parameters['muplugin']['muplugin-dir'];
        $this->logger->notice('Git pulling {repo} into {path}', [
            'repo' => $repo,
            'path' => $path,
        ]);
        // Do a git clone of the pantheon-mu-plugin repo
        WorkingCopy::clone( $repo, $path );

    }

    /**
     * Helpful wrapper to call either 'mirror' or 'copy' as needed.
     */
    protected function copyFileOrDirectory($src, $dest)
    {
        $fs = new Filesystem();

        $fs->mkdir(dirname($dest));
        if (is_dir($src)) {
            $fs->mirror($src, $dest);
        } else {
            $fs->copy($src, $dest, true);
        }
    }
}
