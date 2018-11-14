<?php

namespace Updatinate\Update\Methods;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Updatinate\Git\WorkingCopy;
use Updatinate\Util\TmpDir;
use Updatinate\Util\ExecWithRedactionTrait;

/**
 * SingleCommit is an update method that takes all of the changes from
 * the upstream repository and composes them into a single commit that
 * is added to the working copy being updated.
 */
class SingleCommit implements UpdateMethodInterface, LoggerAwareInterface
{
    use UpdateMethodTrait;
    use LoggerAwareTrait;
    use ExecWithRedactionTrait;

    public function update(WorkingCopy $originalProject, WorkingCopy $updatedProject, array $parameters)
    {
        // Copy over the additional files we need on the platform over to
        // the updated project.
        $this->copyPlatformAdditions($originalProject->dir(), $updatedProject->dir(), $parameters);

        // Apply any patch files needed
        $this->applyPlatformPatches($updatedProject->dir(), $parameters);

        // $updatedProject retains its working contents, and takes over
        // the .git directory of $originalProject.
        $updatedProject->take($originalProject);
        $originalProject->remove();

        return $updatedProject;
    }

    public function complete(WorkingCopy $originalProject, WorkingCopy $updatedProject, array $parameters)
    {
        // Restore the original project to avoid dirtying our cache
        // TODO: Maybe we should just remove it
        $originalProject->take($updatedProject);

        // Remove the updated project local working copy, as it is no
        // longer usable
        $updatedProject->remove();
    }

    protected function applyPlatformPatches($dst, $parameters)
    {
        $parameters += ['platform-patches' => []];

        foreach ($parameters['platform-patches'] as $patch) {
            $this->logger->notice('Applying {patch}', ['patch' => $patch]);
            $this->applyPatch($dst, $patch);
        }
    }

    protected function copyPlatformAdditions($src, $dest, $parameters)
    {
        $parameters += ['platform-additions' => []];

        foreach ($parameters['platform-additions'] as $item) {
            $this->logger->notice('Copying {item}', ['item' => $item]);
            $this->copyFileOrDirectory($src . '/' . $item, $dest . '/' . $item);
        }
    }

    protected function applyPatch($dst, $patch)
    {
        $patchContents = file_get_contents($patch);

        $tmpDir = TmpDir::create();
        $patchPath = "$tmpDir/" . basename($patch);
        file_put_contents($patchPath, $patchContents);

        $this->execWithRedaction('patch -Np1 --directory={dst} --input={patch}', ['patch' => $patchPath, 'dst' => $dst], ['patch' => basename($patchPath), 'dst' => basename($dst)]);
    }

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
