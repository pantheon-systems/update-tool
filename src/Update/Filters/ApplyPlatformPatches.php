<?php

namespace Updatinate\Update\Filters;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Updatinate\Util\ExecWithRedactionTrait;
use Updatinate\Util\TmpDir;

/**
 * ApplyPlatformPatches is an update filter that will apply patches
 * onto the branch being updated.
 */
class ApplyPlatformPatches implements UpdateFilterInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;
    use ExecWithRedactionTrait;

    /**
     * If the update parameters contain any patch files, then apply
     * them by running 'patch'
     */
    public function action($src, $dest, $parameters)
    {
        $parameters += ['platform-patches' => []];

        foreach ($parameters['platform-patches'] as $description => $patch_url) {
            $this->logger->notice('Applying {description}: {patch}', ['description' => $description, 'patch' => $patch_url]);
            $this->applyPatch($dest, $patch_url);
        }
    }

    /**
     * Run 'patch' to apply a patch to the project being updated.
     */
    protected function applyPatch($dest, $patch)
    {
        $patchContents = file_get_contents($patch);

        $tmpDir = TmpDir::create();
        $patchPath = "$tmpDir/" . basename($patch);
        file_put_contents($patchPath, $patchContents);

        $this->execWithRedaction('patch -Np1 --no-backup-if-mismatch --directory={dst} --input={patch}', ['patch' => $patchPath, 'dst' => $dest], ['patch' => basename($patchPath), 'dst' => basename($dest)]);
    }
}
