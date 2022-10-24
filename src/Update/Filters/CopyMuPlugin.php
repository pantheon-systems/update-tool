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
class CopyMuPlugin implements UpdateFilterInterface, LoggerAwareInterface
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
        $repo = $parameters['muplugin']['repo'];
        $path = $parameters['muplugin']['path'];
        $dest = $dest ? $dest : $parameters['muplugin']['muplugin-dir'];
        $muplugin_dir = dirname($dest);
        $this->logger->notice('Git pulling {repo} into {path}', [
            'repo' => $repo,
            'path' => $path,
        ]);
        // Do a git clone of the pantheon-mu-plugin repo
        WorkingCopy::clone($repo, $path);

        // Move files from the working copy to the destination.
        $this->logger->notice('Copying files from {path} to {dest}', [
            'path' => $path,
            'dest' => $dest,
        ]);
        $fs = new Filesystem();
        $fs->mirror($path, $dest);

        $this->logger->notice('Cleaning up...');

        // Clean up the old mu-plugin working copy.
        $this->logger->notice('Removing {path}', ['path' => $path]);
        $fs->remove($path);

        // Clean up the old and unnecessary files in mu-plugins.
        $files_to_delete = [
            "$dest/.git",
            "$dest/README.md",
            "$dest/composer.json",
        ];
        foreach ($files_to_delete as $file) {
            $this->logger->notice('Removing {file}', ['file' => $file]);
            $fs->remove($file);
        }

        // Check if the /pantheon subdirectory exists. If it does, delete that, too.
        if ($fs->exists($muplugin_dir . '/pantheon')) {
            $this->logger->notice('Removing {file}', ['file' => $dest . '/pantheon']);
            $fs->remove($dest . '/pantheon');
        }
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
