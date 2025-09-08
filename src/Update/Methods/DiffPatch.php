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
    protected $latest;

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
        
        // For tag1-drupal, map patch versions to base version for diff
        $upstream_base_version = $parameters['meta']['current-version'];
        if (strpos($this->upstream_url, 'tag1consulting/drupal-partner-mirror-test') !== false) {
            // Convert 7.103.5 -> 7.103 for upstream comparison
            $version_parts = explode('.', $parameters['meta']['current-version']);
            if (count($version_parts) >= 3) {
                $upstream_base_version = $version_parts[0] . '.' . $version_parts[1];
                $this->logger->notice("Mapping current version {current} to upstream base version {base}", 
                    ['current' => $parameters['meta']['current-version'], 'base' => $upstream_base_version]);
            }
        }
        
        $this->updatedProject->fetch('origin', 'refs/tags/' . $upstream_base_version . ':refs/tags/' . $upstream_base_version);

        // Create the diff file with exclusions
        $diffExcludes = $parameters['diff-excludes'] ?? [];
        $diffContents = $this->generateFilteredDiff($upstream_base_version, $this->latest, $diffExcludes);
        
        // Log diff info
        $diffSize = strlen($diffContents);
        $diffLines = substr_count($diffContents, "\n");
        $this->logger->notice("Diff generated: {size} bytes, {lines} lines", ['size' => $diffSize, 'lines' => $diffLines]);

        // Apply the diff as a patch
        $tmpfname = tempnam(sys_get_temp_dir(), "diff-patch-" . $parameters['meta']['current-version'] . '-' . $this->latest . '.tmp');
        file_put_contents($tmpfname, $diffContents . "\n");
        $this->logger->notice('patch -d {file} --no-backup-if-mismatch --ignore-whitespace -Nutp1 < {tmpfile}', ['file' => $this->originalProject->dir(), 'tmpfile' => $tmpfname]);
        passthru('patch -d ' .  $this->originalProject->dir() . ' --no-backup-if-mismatch --ignore-whitespace -Nutp1 < ' . $tmpfname, $exitCode);
        if ($exitCode !== 0) {
            throw new \Exception("Failed to apply diff as patch. Diff size: $diffSize bytes, Lines: $diffLines");
        }
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
        // Strip -dev suffix for comparison to handle test versions
        $normalized_upstream = preg_replace('/-dev$/', '', $upstream_version);
        $normalized_latest = preg_replace('/-dev$/', '', $this->latest);
        if ($normalized_upstream != $normalized_latest) {
            throw new \Exception("Update failed. We expected that the local working copy of the upstream project should be {$this->latest}, but instead it is $upstream_version.");
        }

        return $upstream_working_copy;
    }

    /**
     * Generate a filtered diff that excludes specified files/directories
     */
    protected function generateFilteredDiff($fromRef, $toRef, $excludes)
    {
        if (empty($excludes)) {
            return $this->updatedProject->diffRefs($fromRef, $toRef);
        }

        $old_dir = getcwd();
        chdir($this->updatedProject->dir());
        
        // Build git diff command with exclusions
        $excludeArgs = '';
        foreach ($excludes as $exclude) {
            $excludeArgs .= " ':!{$exclude}'";
        }
        
        $diffCommand = "git diff {$fromRef} {$toRef} -- .{$excludeArgs}";
        $this->logger->notice('Executing {command}', ['command' => $diffCommand]);
        
        ob_start();
        passthru($diffCommand, $exitCode);
        $diffContents = ob_get_clean();
        
        chdir($old_dir);
        
        if ($exitCode !== 0) {
            throw new \Exception("Failed to generate filtered diff. Command: {$diffCommand}");
        }
        
        return $diffContents;
    }
}
