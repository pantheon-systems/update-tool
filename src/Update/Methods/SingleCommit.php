<?php

namespace UpdateTool\Update\Methods;

use Consolidation\Config\ConfigInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use UpdateTool\Git\Remote;
use UpdateTool\Git\WorkingCopy;
use UpdateTool\Util\ExecWithRedactionTrait;
use UpdateTool\Util\TmpDir;
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

        // $this->updatedProject retains its working contents, and takes over
        // the .git directory of $this->originalProject.
        $this->updatedProject->take($this->originalProject);

        return $this->updatedProject;
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
        // Restore the original project to avoid dirtying our cache
        // TODO: Maybe we should just remove it
        $this->originalProject->take($this->updatedProject);

        // Remove the updated project local working copy, as it is no
        // longer usable
        $this->updatedProject->remove();
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
