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
 * Diff is an update method that makes a diff between the new version
 * of the upstream and the current version of the upstream, and then
 * applies the diff as a patch to the target project.
 */
class DiffPatch implements UpdateMethodInterface, LoggerAwareInterface
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
    public function findLatestVersion($major, $tag_prefix, $update_parameters)
    {
        $this->latest = $this->upstream_repo->latest($major, empty($update_parameters['allow-pre-release']), $tag_prefix);

        return $this->latest;
    }

    /**
     * @inheritdoc
     */
    public function update(WorkingCopy $originalProject, array $parameters)
    {
        $this->originalProject = $originalProject;
        $this->updatedProject = $this->fetchUpstream($parameters);

        // Fetch the sources for the 'latest' tag
        $this->updatedProject->fetch('origin', 'refs/tags/' . $this->latest);
        $this->updatedProject->fetch('origin', 'refs/tags/' . $parameters['meta']['current-version'] . ':refs/tags/' . $parameters['meta']['current-version']);

        // Create the diff file
        $diffContents = $this->updatedProject->diffRefs($parameters['meta']['current-version'], $this->latest);

        // Apply the diff as a patch
        $tmpfname = tempnam(sys_get_temp_dir(), "diff-patch-" . $parameters['meta']['current-version'] . '-' . $this->latest . '.tmp');
        file_put_contents($tmpfname, $diffContents);
        $this->logger->notice('patch -d {file} --no-backup-if-mismatch -Nutp1 < {tmpfile}', ['file' => $this->originalProject->dir(), 'tmpfile' => $tmpfname]);
        passthru('patch -d ' .  $this->originalProject->dir() . ' --no-backup-if-mismatch -Nutp1 < ' . $tmpfname);
        unlink($tmpfname);

        // Apply configured filters.
        $this->filters->apply($this->originalProject->dir(), $this->originalProject->dir(), $parameters);

        return $this->originalProject;
    }

    /**
     * @inheritdoc
     */
    public function postCommit(WorkingCopy $updatedProject, array $parameters)
    {
        $this->filters->postCommit($updatedProject, $parameters);
    }

    /**
     * @inheritdoc
     */
    public function complete(array $parameters)
    {
    }

    protected function fetchUpstream(array $parameters)
    {
        $upstream_working_copy = $this->cloneUpstream($parameters);

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
}
