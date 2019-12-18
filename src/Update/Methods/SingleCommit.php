<?php

namespace Updatinate\Update\Methods;

use Consolidation\Config\ConfigInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Updatinate\Git\Remote;
use Updatinate\Git\WorkingCopy;
use Updatinate\Util\ExecWithRedactionTrait;
use Updatinate\Util\TmpDir;
use VersionTool\VersionTool;

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

    protected $upstream_repo;
    protected $download_url;

    /**
     * @inheritdoc
     */
    public function configure(ConfigInterface $config, $project)
    {
        $upstream = $config->get("projects.$project.upstream.project");
        $this->upstream_url = $config->get("projects.$upstream.repo");
        $this->upstream_dir = $config->get("projects.$upstream.path");
        $this->download_url = $config->get("projects.$upstream.download.url");

        $this->upstream_repo = Remote::create($this->upstream_url, $this->api);
    }

    /**
     * @inheritdoc
     */
    public function findLatestVersion($major, $tag_prefix)
    {
        $this->latest = $this->upstream_repo->latest($major, $tag_prefix);

        return $this->latest;
    }

    /**
     * @inheritdoc
     */
    public function update(WorkingCopy $originalProject, array $parameters)
    {
        $this->originalProject = $originalProject;
        $this->updatedProject = $this->fetchUpstream($parameters);

        // Copy over the additional files we need on the platform over to
        // the updated project.
        $this->copyPlatformAdditions($this->originalProject->dir(), $this->updatedProject->dir(), $parameters);

        // Apply any patch files needed
        $this->applyPlatformPatches($this->updatedProject->dir(), $parameters);

        // $this->updatedProject retains its working contents, and takes over
        // the .git directory of $this->originalProject.
        $this->updatedProject->take($this->originalProject);

        return $this->updatedProject;
    }

    protected function fetchUpstream(array $parameters)
    {
        if (!empty($this->download_url)) {
            return $this->downloadUpstream($parameters);
        }
        return $this->cloneUpstream($parameters);
    }

    protected function downloadUpstream(array $parameters)
    {
        $latestTag = $parameters['latest-tag'];

        // Ensure $this->upstream_dir is empty, as we are going to untar
        // on top of it.
        $this->logger->notice('Removing upstream cache {dir}', ['dir' => $this->upstream_dir]);
        $fs = new Filesystem();
        $fs->remove($this->upstream_dir);

        $this->logger->notice('Downloading from url template {url}', ['url' => $this->download_url]);

        $download_url = str_replace('{version}', $latestTag, $this->download_url);
        $this->logger->notice('Downloading {url}', ['url' => $download_url]);

        $upstream_working_copy = WorkingCopy::unclonedReference($this->upstream_url, $this->upstream_dir, $latestTag, $this->api);
        $upstream_working_copy
            ->setLogger($this->logger);

        // Download the tarball
        $download_target = '/tmp/' . basename($download_url);
        $this->execWithRedaction('curl {url} --output {target}', ['url' => $download_url, 'target' => $download_target], ['target' => basename($download_target)]);

        // Uncompress the tarball
        $fs->mkdir($this->upstream_dir);
        $this->execWithRedaction('tar -xzvf {tarball} -C {working-copy} --strip-components=1', ['tarball' => $download_target, 'working-copy' => $this->upstream_dir], ['tarball' => basename($download_target)]);

        return $upstream_working_copy;
    }

    /**
     * @inheritdoc
     */
    protected function cloneUpstream(array $parameters)
    {
        $latestTag = $parameters['latest-tag'];

        // Clone the upstream. Check out just $latest
        $upstream_working_copy = WorkingCopy::shallowClone($this->upstream_url, $this->upstream_dir, $latestTag, 1, $this->api);
        $upstream_working_copy
            ->setLogger($this->logger);

        // Run 'composer install' if necessary
        $this->composerInstall($upstream_working_copy->dir());

        // Confirm that the local working copy of the upstream has checked out $latest
        $version_info = new VersionTool();
        $info = $version_info->info($upstream_working_copy->dir());
        if (!$info) {
            throw new \Exception("Could not identify the type of application at " . $upstream_working_copy->dir());
        }
        $upstream_version = $info->version();
        if ($upstream_version != $this->latest) {
            throw new \Exception("Update failed. We expected that the local working copy of the upstream project should be {$this->latest}, but instead it is $upstream_version.");
        }

        return $upstream_working_copy;
    }

    /**
     * @inheritdoc
     */
    public function complete(array $parameters)
    {
        // Restore the original project to avoid dirtying our cache
        // TODO: Maybe we should just remove it
        $this->originalProject->take($this->updatedProject);

        // Remove the updated project local working copy, as it is no
        // longer usable
        $this->updatedProject->remove();
    }

    /**
     * Run 'composer install' if there is a 'composer json' in the specified directory.
     */
    protected function composerInstall($dir)
    {
        if (!file_exists("$dir/composer.json")) {
            return;
        }

        $this->logger->notice("Running composer install");

        passthru("composer --working-dir=$dir -q install --prefer-dist --no-dev --optimize-autoloader");
    }

    /**
     * If the update parameters contain any patch files, then apply
     * them by running 'patch'
     */
    protected function applyPlatformPatches($dst, $parameters)
    {
        $parameters += ['platform-patches' => []];

        foreach ($parameters['platform-patches'] as $patch) {
            $this->logger->notice('Applying {patch}', ['patch' => $patch]);
            $this->applyPatch($dst, $patch);
        }
    }

    /**
     * Platform "additions" are files in the Pantheon repository that
     * do not exist in the upstream. These must all be listed in the
     * update parameters. Listed files are copied from the Pantheon
     * repository into the repository being updated, which starts off
     * as a pristine copy of the upstream.
     */
    protected function copyPlatformAdditions($src, $dest, $parameters)
    {
        $parameters += ['platform-additions' => []];

        foreach ($parameters['platform-additions'] as $item) {
            $this->logger->notice('Copying {item}', ['item' => $item]);
            $this->copyFileOrDirectory($src . '/' . $item, $dest . '/' . $item);
        }
    }

    /**
     * Run 'patch' to apply a patch to the project being updated.
     */
    protected function applyPatch($dst, $patch)
    {
        $patchContents = file_get_contents($patch);

        $tmpDir = TmpDir::create();
        $patchPath = "$tmpDir/" . basename($patch);
        file_put_contents($patchPath, $patchContents);

        $this->execWithRedaction('patch -Np1 --no-backup-if-mismatch --directory={dst} --input={patch}', ['patch' => $patchPath, 'dst' => $dst], ['patch' => basename($patchPath), 'dst' => basename($dst)]);
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
