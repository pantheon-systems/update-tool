<?php

namespace Updatinate\Cli;

use Composer\Semver\Semver;
use Consolidation\Config\Util\Interpolator;
use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Hubph\HubphAPI;
use Hubph\VersionIdentifiers;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Common\ConfigAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Updatinate\Git\Remote;
use Updatinate\Git\WorkingCopy;
use Updatinate\Update\Filters\FilterManager;
use Updatinate\Util\ReleaseNode;
use VersionTool\VersionTool;

/**
 * Commands used to manipulate projects directly with git
 */
class ProjectCommands extends \Robo\Tasks implements ConfigAwareInterface, LoggerAwareInterface
{
    use ConfigAwareTrait;
    use LoggerAwareTrait;
    use ApiTrait;

    /**
     * Show the list of available versions already released for the specified project.
     *
     * @command project:releases
     */
    public function projectReleases($remote, $options = ['as' => 'default', 'major' => '[0-9]+', 'format' => 'yaml'])
    {
        $api = $this->api($options['as']);
        $remote_repo = $this->createRemote($remote, $api);
        $update_parameters = $this->getConfig()->get("projects.$remote.upstream.update-parameters", []);

        return new RowsOfFields($remote_repo->releases($options['major'], empty($update_parameters['allow-pre-release']), ''));
    }

    /**
     * Show a list of available projects.
     *
     * @command project:list
     *
     * @return array
     */
    public function projectList($options = ['format' => 'list'])
    {
        $projects = $this->getConfig()->get("projects", []);
        if (empty($projects)) {
            throw new \Exception("No projects defined.");
        }
        return array_keys($projects);
    }

    /**
     * Show the metadata for the requested project.
     *
     * @command project:info
     * @param string $remote
     *
     * @return array
     */
    public function projectInfo($remote, $options = ['format' => 'yaml'])
    {
        $info = $this->getConfig()->get("projects.$remote", []);
        if (empty($info)) {
            throw new \Exception("Project $remote not found.");
        }
        return $info;
    }

    /**
     * Show the latest available releases for the specified project.
     *
     * @command project:latest
     * @table-style compact
     * @list-delimiter :
     * @field-labels
     *   drush-version: Drush version
     *
     * @return \Consolidation\OutputFormatters\StructuredData\PropertyList
     */
    public function projectLatest($remote, $options = ['as' => 'default', 'major' => '[0-9]+'])
    {
        $api = $this->api($options['as']);
        $remote_repo = $this->createRemote($remote, $api);
        $update_parameters = $this->getConfig()->get("projects.$remote.upstream.update-parameters", []);

        return $remote_repo->latest($options['major'], empty($update_parameters['allow-pre-release']), '');
    }

    /**
     * @command project:release-node
     * @table-style compact
     * @list-delimiter :
     * @field-labels
     *   url: Release URL
     *
     * @return \Consolidation\OutputFormatters\StructuredData\PropertyList
     */
    public function releaseNode($remote, $version = '', $options = ['as' => 'default', 'major' => '[0-9]', 'allow-pre-release' => false])
    {
        $api = $this->api($options['as']);
        $releaseNode = new ReleaseNode($api);
        list($failure_message, $release_node) = $releaseNode->get($this->getConfig(), $remote, $options['major'], $version, !$options['allow-pre-release']);

        if (!empty($failure_message)) {
            throw new \Exception($failure_message);
        }

        return new PropertyList(['url' => $release_node]);
    }

    /**
     * @command project:derivative:update
     */
    public function projectDerivativeUpdate($remote, $options = ['as' => 'default', 'push' => true, 'check' => false])
    {
        $api = $this->api($options['as']);

        $upstream = $this->getConfig()->get("projects.$remote.source.project");
        if (empty($upstream)) {
            throw new \Exception('Derivative project cannot be updated; it is missing a source.');
        }

        $remote_repo = $this->createRemote($remote, $api);
        $upstream_repo = $this->createRemote($upstream, $api);

        $update_parameters = $this->getConfig()->get("projects.$remote.source.update-parameters", []);
        $update_parameters['meta']['name'] = $remote_repo->projectWithOrg();

        $version_pattern = $this->getConfig()->get("projects.$remote.source.version-pattern", '#.#.#');
        $versionPatternRegex = $this->versionPatternRegex($version_pattern);



        $source_releases = $upstream_repo->tags($versionPatternRegex, empty($update_parameters['allow-pre-release']), '');
        $existing_releases = $remote_repo->tags($versionPatternRegex, empty($update_parameters['allow-pre-release']), '');

        $this->logger->notice("Finding missing derived tags for {project}", ['project' => $remote_repo->projectWithOrg()]);

        // Find versions in the source that have not been created in the target.
        $versions_to_process = [];
        $previous_version = 'master'; // @todo: what's our best default?
        foreach ($source_releases as $source_version => $info) {
            if (array_key_exists($source_version, $existing_releases)) {
                $this->logger->notice("{version} already exists", ['version' => $source_version]);
            } else {
                $this->logger->notice("{version} needs to be created", ['version' => $source_version]);
                $versions_to_process[$source_version] = $previous_version;
            }
            $previous_version = $source_version;
        }

        if (empty($versions_to_process)) {
            $this->logger->notice("Everything is up-to-date.");
            return;
        }

        // If we are running in check-only mode, exit now, before we do anything.
        if ($options['check']) {
            return;
        }

        $project_url = $this->getConfig()->get("projects.$remote.repo");
        $project_dir = $this->getConfig()->get("projects.$remote.path");
        $project_fork = $this->getConfig()->get("projects.$remote.fork");
        $main_branch = $this->getConfig()->get("projects.$remote.main-branch", 'master');

        $upstream_url = $this->getConfig()->get("projects.$upstream.repo");
        $upstream_dir = $this->getConfig()->get("projects.$upstream.path");

        $this->logger->notice("Cloning repositories for {remote} and {upstream}", ['remote' => $remote, 'upstream' => $upstream]);

        $project_working_copy = WorkingCopy::cloneBranch($project_url, $project_dir, $main_branch, $api);

        $upstream_working_copy = WorkingCopy::shallowClone($upstream_url, $upstream_dir, $main_branch, 1, $api);

        $project_working_copy->fetchTags();
        $upstream_working_copy->fetchTags();

        // Create the filters
        $filter_manager = $this->getFilters($this->getConfig()->get("projects.$remote.source.update-filters"));

        foreach ($versions_to_process as $version => $previous_version) {
            $project_working_copy->switchBranch($previous_version);
            $upstream_working_copy->switchBranch($version);

            $this->logger->notice("Processing files from {upstream} {version} over {target} {previous}", ['upstream' => $upstream_repo->projectWithOrg(), 'version' => $version, 'target' => $remote_repo->projectWithOrg(), 'previous' => $previous_version]);

            // Apply the filters
            $filter_manager->apply($upstream_working_copy->dir(), $project_working_copy->dir(), $update_parameters);

            // Commit and tag
            $comment = $upstream_working_copy->message();
            $commit_date = $upstream_working_copy->commitDate();
            $project_working_copy->addAll();
            $project_working_copy->commitBy($comment, 'Pantheon Automation <bot@getpantheon.com>', $commit_date);
            $project_working_copy->tag($version);

            if (!empty($options['push'])) {
                $this->logger->notice("Push tag {version} to {target}", ['version' => $version, 'target' => $remote_repo->projectWithOrg()]);
                $project_working_copy->push('origin', $version);
            }
        }
    }

    /**
     * Check to see if there is an update available on the upstream of the specified project.
     *
     * @command project:upstream:check
     */
    public function projectCheck($remote, $options = ['as' => 'default'])
    {
        $this->projectUpstreamUpdate($remote, $options + ['check' => true]);
    }

    /**
     * @command project:upstream:update
     */
    public function projectUpstreamUpdate($remote, $options = ['as' => 'default', 'pr' => true, 'check' => false])
    {
        $api = $this->api($options['as']);
        $make_pr = !empty($options['pr']);

        // Get references to the remote repo and the upstream repo
        $upstream = $this->getConfig()->get("projects.$remote.upstream.project");
        if (empty($upstream)) {
            throw new \Exception('Project cannot be updated; it is missing an upstream.');
        }

        $remote_repo = $this->createRemote($remote, $api);
        $update_parameters = $this->getConfig()->get("projects.$remote.upstream.update-parameters", []);
        $update_parameters['meta']['name'] = $remote_repo->projectWithOrg();

        // Determine the major version of the upstream repo
        $tag_prefix = $this->getConfig()->get("projects.$remote.upstream.tag-prefix", '');
        $major = $this->getConfig()->get("projects.$remote.upstream.major", '[0-9]+');
        // We haven't cloned the repo yet, so look at the remote tags to
        // determine our version.
        $current = $remote_repo->latest($major, empty($update_parameters['allow-pre-release']), '');
        $this->logger->notice("Considering updates for {project}", ['project' => $remote_repo->projectWithOrg()]);

        // Create the filters
        $filter_manager = $this->getFilters($this->getConfig()->get("projects.$remote.upstream.update-filters"));

        // Find an update method and create an updater
        $update_method = $this->getConfig()->get("projects.$remote.upstream.update-method");
        $updater = $this->getUpdater($update_method, $api);
        if (empty($updater)) {
            throw new \Exception('Project cannot be updated; it is missing an update method.');
        }
        $updater->setApi($api);
        $updater->setLogger($this->logger);
        $updater->setFilters($filter_manager);

        // Allow the updator to configure itself prior to the update.
        $updater->configure($this->getConfig(), $remote);

        $this->logger->notice("Check latest version for {upstream}.", ['upstream' => $upstream]);

        // Determine the latest version in the same major series in the upstream
        $latest = $updater->findLatestVersion($major, $tag_prefix, $update_parameters);

        // Convert $latest to a version number matching $version_pattern,
        // and put the actual tag name in $latestTag.
        $version_pattern = $this->getConfig()->get("projects.$remote.upstream.version-pattern", '#.#.#');
        if (!empty($update_parameters['allow-pre-release'])) {
            $version_pattern .= '(-[a-z]+#|)';
        }
        list($latest, $latestTag) = $this->versionAndTagFromLatest($latest, $tag_prefix, $version_pattern);
        $update_parameters['latest-tag'] = $latestTag;

        // Exit with no action and no error if already up-to-date
        if ($remote_repo->has($latest)) {
            $this->logger->notice("{remote} is at the most recent available version, {latest}", ['remote' => $remote, 'latest' => $latest]);
            return;
        }

        $this->logger->notice("{remote} has an available update: {latest}.", ['remote' => $remote, 'latest' => $latest]);

        // If we are running in check-only mode, exit now, before we do anything.
        if ($options['check']) {
            return;
        }

        // Create a commit message.
        $upstream_label = ucfirst($upstream);
        $message = $this->getConfig()->get("projects.$remote.upstream.update-message", 'Update to ' . $upstream_label . ' ' . $latest . '.');
        $message = str_replace('{version}', $latest, $message);

        // If we can find a release node, then add the "more information" blerb.
        $releaseNode = new ReleaseNode($api);
        list($failure_message, $release_url) = $releaseNode->get($this->getConfig(), $remote, $major, $latest, empty($update_parameters['allow-pre-release']));
        if (!empty($release_url)) {
            $message .= " For more information, see $release_url";
        }

        $this->logger->notice("Update message: {msg}", ['msg' => $message]);

        // Check to see if there is already a matching PR
        $prs = $api->matchingPRs($remote_repo->projectWithOrg(), $message, '');
        $existingPRList = $prs->prNumbers();
        if (!empty($existingPRList)) {
            $this->logger->notice("Pull request already exists for available update {latest}; nothing more to do.", ['latest' => $latest]);
            return;
        }

        $this->logger->notice("Updating {remote} from {current} to {latest}", ['remote' => $remote, 'current' => $current, 'latest' => $latest]);

        $branch = "update-$latest";

        $project_url = $this->getConfig()->get("projects.$remote.repo");
        $project_dir = $this->getConfig()->get("projects.$remote.path");
        $project_fork = $this->getConfig()->get("projects.$remote.fork");
        $main_branch = $this->getConfig()->get("projects.$remote.main-branch", 'master');

        $upstream_url = $this->getConfig()->get("projects.$upstream.repo");
        $upstream_dir = $this->getConfig()->get("projects.$upstream.path");

        $this->logger->notice("Cloning repositories for {remote} and {upstream}", ['remote' => $remote, 'upstream' => $upstream]);

        $project_working_copy = WorkingCopy::cloneBranch($project_url, $project_dir, $main_branch, $api);
        $project_working_copy
            ->addFork($project_fork)
            ->setLogger($this->logger);

        $fork_url = $project_working_copy->forkUrl();
        if ($fork_url) {
            $this->logger->notice("Pull requests will be made in forked repository {fork}", ['fork' => $fork_url]);
        }

        // Check to see if the version we want to update to exists on the main
        // branch. The lifecycle is new release -> PR -> merge PR back to main
        // branch. Then we end up here, to tag the new release.
        $version_info = new VersionTool();
        $info = $version_info->info($project_working_copy->dir());
        if (!$info) {
            throw new \Exception("Could not figure out version from " . $project_working_copy->dir() . "; maybe project failed to clone / download.");
        }
        $main_branch_version = $info->version();
        if ($main_branch_version != $current) {
            $this->logger->notice("The version on the {main} branch is {version}, but the latest tag is {current}.", ['version' => $main_branch_version, 'current' => $current, 'main' => $main_branch]);
            $tag_branch = $this->getConfig()->get("projects.$remote.tag-branch", '');
            if (empty($tag_branch)) {
                return;
            }
            if ($main_branch_version != $latest) {
                $this->logger->notice("The latest version is {latest}, which is different than {current}, so I don't know what to do. Aborting.", ['latest' => $latest, 'current' => $current]);
            }
            $existing_commit_message = $project_working_copy->message($tag_branch);
            if (strpos($existing_commit_message, $message) === FALSE) {
                throw new \Exception("The commit message at the top of the {main} branch does not match the commit message we expect.\n\nExpected: $message\n\nActual: $existing_commit_message");
            }
            // Tag it up.
            $project_working_copy
                ->tag($latest, $tag_branch)
                ->push('origin', $latest);

            $this->logger->notice("Tagged version {latest}.", ['latest' => $latest]);

            return;
        }

        // Do the actual update
        $this->logger->notice("Updating via update method {method} using {class}.", ['method' => $update_method, 'class' => get_class($updater)]);

        $updated_project = $updater->update($project_working_copy, $update_parameters);

        // Confirm that the updated version of the code is now equal to $latest
        $info = $version_info->info($updated_project->dir());
        if (!$info) {
            throw new \Exception("Could not figure out version from " . $updated_project->dir() . "; maybe project failed to update correctly.");
        }
        $updated_version = $info->version();
        if ($updated_version != $latest) {
            throw new \Exception("Update failed. We expected that the updated version of the project should be '$latest', but instead it is '$updated_version'. " . $updated_project->dir());
        }

        // Give folks instructions on what to do with this update.
        $replacements = [
            'branch' => $branch,
            'main-branch' => $main_branch,
            'commit-message' => $message,
            'project' => $remote,
            'upstream' => $upstream_label,
            'project-url' => $project_url,
            'upstream-url' => $upstream_url,
            'original-version' => $current,
            'update-version' => $latest,
        ];
        $instructions = $this->getConfig()->get("projects.$remote.pr.instructions");
        $interpolator = new Interpolator();
        $body = $interpolator->mustInterpolate($replacements, $instructions);

        // Commit onto the branch
        $updated_project
            ->createBranch($branch, $main_branch, true)
            ->add('.')
            ->commit($message);

        // Give the updater a chance to do something after the commit.
        $updater->postCommit($updated_project, $update_parameters);

        // If we aren't going to make a PR, then exit dirty.
        if (!$make_pr) {
            $this->logger->notice("Updated project can be found at {dir}.", ['dir' => $updated_project->dir()]);
            return;
        }

        // Push and make the PR. Close similar PRs.
        $updated_project
            ->forcePush()
            ->pr($message, $body, $main_branch);

        // These PRs may be closed now, as they are replaced by the new PR.
        $api->prClose($project_working_copy->org(), $project_working_copy->project(), $prs);

        // Once the PR has been submitted, bring our cached project
        // back to the main branch.
        $updated_project->checkout($main_branch);

        $updater->complete($update_parameters);
    }

    protected function getFilters($filters)
    {
        $filter_manager = new FilterManager();
        $filter_manager->setLogger($this->logger);
        $filter_manager->getFilters($filters);

        return $filter_manager;
    }

    protected function versionAndTagFromLatest($latest, $tag_prefix, $version_pattern)
    {
        $latestTag = "$tag_prefix$latest";
        $versionPatternRegex = $this->versionPatternRegex($version_pattern);
        if (preg_match("#$versionPatternRegex#", $latest, $matches)) {
            $latest = $matches[0];
        }

        return [$latest, $latestTag];
    }

    protected function versionPatternRegex($version_pattern)
    {
        if ($this->appearsToBeSemver($version_pattern)) {
            return $version_pattern;
        }
        $versionPatternRegex = str_replace('.', '\\.', $version_pattern);
        $versionPatternRegex = str_replace('#', '[0-9]+', $versionPatternRegex);

        return $versionPatternRegex;
    }

    // @deprecated: we should just use semver everywhere, not regex
    protected function appearsToBeSemver($arg)
    {
        return ($arg[0] == '^') || ($arg[0] == '~');
    }

    protected function getUpdater($update_method, $api)
    {
        $update_class = "\\Updatinate\\Update\\Methods\\$update_method";
        if (!class_exists($update_class)) {
            return false;
        }
        return new $update_class($api);
    }

    protected function createRemote($remote_name, $api)
    {
        $remote_url = $this->getConfig()->get("projects.$remote_name.repo");
        return Remote::create($remote_url, $api);
    }
}
