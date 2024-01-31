<?php

namespace UpdateTool\Cli;

use Composer\Semver\Semver;
use Composer\Semver\Comparator;
use Consolidation\Config\Util\Interpolator;
use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Hubph\HubphAPI;
use Hubph\VersionIdentifiers;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Common\ConfigAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use UpdateTool\Git\Remote;
use UpdateTool\Git\WorkingCopy;
use UpdateTool\Update\Filters\FilterManager;
use UpdateTool\Util\ReleaseNode;
use VersionTool\VersionTool;
use UpdateTool\Util\SupportLevel;
use UpdateTool\Util\ProjectUpdate;
use UpdateTool\Exceptions\NotRecentReleaseException;

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
     * @command project:derivative:pull
     */
    public function projectDerivativePull($remote, $options = ['as' => 'default', 'push' => true, 'check' => false])
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

        // Get main-branch from config, default to master.
        $main_branch = $this->getConfig()->get("projects.$remote.main-branch", 'master');
        $previous_version = $main_branch;
        // Find versions in the source that have not been created in the target.
        $versions_to_process = $this->compareTagsSets($source_releases, $existing_releases, $previous_version);

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

        $upstream_url = $this->getConfig()->get("projects.$upstream.repo");
        $upstream_branch = $this->getConfig()->get("projects.$remote.source.branch", $main_branch);
        $upstream_dir = $this->getConfig()->get("projects.$upstream.path");

        $this->logger->notice("Cloning repository for {remote} and fetching needed branch and tags", ['remote' => $remote]);

        $project_working_copy = WorkingCopy::cloneBranch($project_url, $project_dir, $main_branch, $api);
        $project_working_copy->addRemote($upstream_url, 'upstream');
        $project_working_copy->fetch($upstream_branch, 'upstream');
        $project_working_copy->fetchTags('upstream');

        foreach ($versions_to_process as $version => $previous_version) {
            $project_working_copy->switchBranch($version);

            if (!empty($options['push'])) {
                $this->logger->notice("Push tag {version} to {target}", ['version' => $version, 'target' => $remote_repo->projectWithOrg()]);
                $project_working_copy->push('origin', $version);
            }
        }

        // Add commits to main-branch from corresponding branch in upstream.
        $project_working_copy->switchBranch($main_branch);
        $project_working_copy->pull('upstream', $upstream_branch);
        if (!empty($options['push'])) {
            $this->logger->notice("Push branch {version} to {target}", ['version' => $main_branch, 'target' => $remote_repo->projectWithOrg()]);
            $project_working_copy->push('origin', $main_branch);
        }
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

        // Get main-branch from config, default to master.
        $previous_version = $this->getConfig()->get("projects.$remote.main-branch") ?? 'master';
        // Find versions in the source that have not been created in the target.
        $versions_to_process = $this->compareTagsSets($source_releases, $existing_releases, $previous_version);

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

        $latest_version = '0.0';
        foreach ($versions_to_process as $version => $previous_version) {
            if (Comparator::greaterThan($version, $latest_version)) {
                $latest_version = $version;
            }
            $project_working_copy->switchBranch($previous_version);
            $new_branch_name = $version . '-dev';
            $project_working_copy->createBranch($new_branch_name);
            $upstream_working_copy->switchBranch($version);

            $this->logger->notice("Processing files from {upstream} {version} over {target} {previous}", ['upstream' => $upstream_repo->projectWithOrg(), 'version' => $version, 'target' => $remote_repo->projectWithOrg(), 'previous' => $previous_version]);

            $this->applyFiltersAndCommit($filter_manager, $upstream_working_copy, $project_working_copy, $update_parameters);

            // Tag the new release.
            $project_working_copy->tag($version);

            if (!empty($options['push'])) {
                $this->logger->notice("Push tag {version} to {target}", ['version' => $version, 'target' => $remote_repo->projectWithOrg()]);
                $project_working_copy->push('origin', $version);
            }
        }

        // Add commits to main-branch from latest processed tag.
        $project_working_copy->switchBranch($main_branch);
        $upstream_working_copy->switchBranch($latest_version);
        $this->logger->notice("Processing files from {upstream} {version} over {target} {previous}", ['upstream' => $upstream_repo->projectWithOrg(), 'version' => $latest_version, 'target' => $remote_repo->projectWithOrg(), 'previous' => $previous_version]);
        $this->applyFiltersAndCommit($filter_manager, $upstream_working_copy, $project_working_copy, $update_parameters);
        if (!empty($options['push'])) {
            $this->logger->notice("Push branch {version} to {target}", ['version' => $main_branch, 'target' => $remote_repo->projectWithOrg()]);
            $project_working_copy->push('origin', $main_branch);
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
     * Compare two given tags sets to find the missing items.
     */
    protected function compareTagsSets($source_releases, $existing_releases, $previous_version = '')
    {
        $versions_to_process = [];
        foreach ($source_releases as $source_version => $info) {
            if (array_key_exists($source_version, $existing_releases)) {
                $this->logger->notice("{version} already exists", ['version' => $source_version]);
            } else {
                $this->logger->notice("{version} needs to be created", ['version' => $source_version]);
                $versions_to_process[$source_version] = $previous_version;
            }
            $previous_version = $source_version;
        }
        return $versions_to_process;
    }

    /**
     * @command project:upstream:push-tags
     */
    public function projectUpstreamPushTags($remote, $options = ['as' => 'default', 'check' => false, 'dry-run' => false])
    {
        $api = $this->api($options['as']);

        // Get references to the remote repo and the upstream repo
        $upstream = $this->getConfig()->get("projects.$remote.upstream.project");
        if (empty($upstream)) {
            throw new \Exception('Project cannot be updated; it is missing an upstream.');
        }

        $remote_repo = $this->createRemote($remote, $api);
        $update_parameters = $this->getConfig()->get("projects.$remote.upstream.update-parameters", []);
        $update_parameters['meta']['name'] = $remote_repo->projectWithOrg();
        $update_parameters['force-db-drop'] = true;
        $major = $this->getConfig()->get("projects.$remote.upstream.major", '[0-9]+');

        $upstream_repo_url = $this->getConfig()->get("projects.$remote.upstream.repo");
        $upstream_repo = Remote::create($upstream_repo_url, $api);

        $version_pattern = $this->getConfig()->get("projects.$remote.upstream.tags-version-pattern", '#.#.#');
        $versionPatternRegex = $this->versionPatternRegex($version_pattern);

        $source_releases = $upstream_repo->tags($versionPatternRegex, empty($update_parameters['allow-pre-release']), '');
        $existing_releases = $remote_repo->tags($versionPatternRegex, empty($update_parameters['allow-pre-release']), '');

        $this->logger->notice("Finding missing tags for {project}", ['project' => $remote_repo->projectWithOrg()]);

        // Find versions in the source that have not been created in the target.
        $versions_to_process = $this->compareTagsSets($source_releases, $existing_releases);

        $source_tags = array_keys($source_releases);
        $latest_tag_in_source = end($source_tags);

        if (empty($versions_to_process)) {
            $this->logger->notice("Everything is up-to-date.");
            return;
        }

        // If we are running in check-only mode, exit now, before we do anything.
        if ($options['check']) {
            return;
        }

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

        $project_url = $this->getConfig()->get("projects.$remote.repo");
        $project_dir = $this->getConfig()->get("projects.$remote.path");
        $project_fork = $this->getConfig()->get("projects.$remote.fork");
        $main_branch = $this->getConfig()->get("projects.$remote.main-branch", 'master');
        $upstream_url = $this->getConfig()->get("projects.$upstream.repo");
        $upstream_dir = $this->getConfig()->get("projects.$upstream.path");

        $this->logger->notice("Cloning repository for {remote}", ['remote' => $remote]);
        $project_working_copy = WorkingCopy::cloneBranch($project_url, $project_dir, $main_branch, $api);
        $project_working_copy
            ->addFork($project_fork)
            ->setLogger($this->logger);
        $fork_url = $project_working_copy->forkUrl();
        if ($fork_url) {
            $this->logger->notice("Pull requests will be made in forked repository {fork}", ['fork' => $fork_url]);
        }
        $project_working_copy->fetchTags();

        $last_successful_update = null;
        foreach ($versions_to_process as $version => $previous) {
            if ($version === $latest_tag_in_source) {
                $this->logger->notice("Skipping {version} because it is the latest tag in the source and should be handled by another job.", ['version' => $version]);
                continue;
            }

            // Make sure our working directory is cleaned up. An "untracked working tree files" error will fail checkout silently.
            $project_working_copy->clean();
            // Checkout previous tag.
            $project_working_copy->checkout($previous);

            // This will force to use $version instead of the latest version (currently only for WpCliUpdate method).
            $update_parameters['meta']['new-version'] = $version;
            $updater->findLatestVersion($major, '', $update_parameters);

            // Create a commit message.
            $upstream_label = ucfirst($upstream);
            $message = $this->getConfig()->get("projects.$remote.upstream.update-message", 'Update to ' . $upstream_label . ' ' . $version . '.');
            $message = str_replace('{version}', $version, $message);

            // If we can find a release node, then add the "more information" blerb.
            $releaseNode = new ReleaseNode($api);
            try {
                list($failure_message, $release_url) = $releaseNode->get($this->getConfig(), $remote, $major, $version, empty($update_parameters['allow-pre-release']));
                if (!empty($release_url)) {
                    $message .= " For more information, see $release_url";
                }
            } catch (NotRecentReleaseException $e) {
                $this->logger->notice("{version} is not a recent release so no release node link found.", ['version' => $version]);
            }

            $this->logger->notice("Update message: {msg}", ['msg' => $message]);

            $branch = "update-$version";
            $project_working_copy->createBranch($branch);

            // Check to see if the version we want to update from is the one we have checked out.
            $version_info = new VersionTool();
            $info = $version_info->info($project_working_copy->dir());
            if (!$info) {
                throw new \Exception("Could not figure out version from " . $project_working_copy->dir() . "; maybe project failed to clone / download.");
            }

            $current_version = $info->version();
            if ($current_version !== $previous) {
                if ($current_version === $last_successful_update) {
                    // Ok to use last successful as backup here.
                    $previous = $last_successful_update;
                } else {
                    throw new \Exception("Version " . $current_version . " does not match expected " . $previous);
                }
            }
            $this->logger->notice("Updating {remote} from {previous} to {version}", ['remote' => $remote, 'previous' => $previous, 'version' => $version]);

            $update_parameters['meta']['current-version'] = $previous;

            // Do the actual update
            $this->logger->notice("Updating via update method {method} using {class}.", ['method' => $update_method, 'class' => get_class($updater)]);

            try {
                $updated_project = $updater->update($project_working_copy, $update_parameters);
            } catch (\Exception $e) {
                // Wordpress 5.0.5 is always failing because the zip files does not exist so we should be able to continue.
                $this->logger->error("Error updating {remote} to version {version}", ['version' => $version, 'remote' => $remote]);
                continue;
            }

            // Confirm that the updated version of the code is now equal to $latest
            $info = $version_info->info($updated_project->dir());
            if (!$info) {
                throw new \Exception("Could not figure out version from " . $updated_project->dir() . "; maybe project failed to update correctly.");
            }
            $updated_version = $info->version();
            if ($updated_version !== $version) {
                throw new \Exception("Update failed. We expected that the updated version of the project should be '$version', but instead it is '$updated_version'. " . $updated_project->dir());
            }

            // Commit onto the branch
            $updated_project
                ->add('.')
                ->commit($message);

            // Give the updater a chance to do something after the commit.
            $updater->postCommit($updated_project, $update_parameters);

            // Tag and push new version.
            $project_working_copy->tag($version);

            if (!$options['dry-run']) {
                $this->logger->notice("Pushing tag {version} to {remote}", ['version' => $version, 'remote' => $remote]);
                $project_working_copy->push('origin', $version);
            }
            $last_successful_update = $version;
        }
    }

    /**
     * @command project:upstream:update
     */
    public function projectUpstreamUpdate($remote, $options = ['as' => 'default', 'pr' => true, 'check' => false, 'allow-mismatch' => false])
    {
        $api = $this->api($options['as']);
        $make_pr = !empty($options['pr']);
        $allow_msg_mismatch = !empty($options['allow-mismatch']);

        // Get references to the remote repo and the upstream repo
        $upstream = $this->getConfig()->get("projects.$remote.upstream.project");
        if (empty($upstream)) {
            throw new \Exception('Project cannot be updated; it is missing an upstream.');
        }

        $remote_repo = $this->createRemote($remote, $api);
        $upstream_repo = $this->createRemote($upstream, $api);
        $update_parameters = $this->getConfig()->get("projects.$remote.upstream.update-parameters", []);
        $update_parameters['meta']['name'] = $remote_repo->projectWithOrg();

        // Determine the major version of the upstream repo
        $tag_prefix = $this->getConfig()->get("projects.$remote.upstream.tag-prefix", '');
        $major = $this->getConfig()->get("projects.$remote.upstream.major", '[0-9]+');
        // We haven't cloned the repo yet, so look at the remote tags to
        // determine our version.
        $current = $remote_repo->latest($major, empty($update_parameters['allow-pre-release']), '');
        $update_parameters['meta']['current-version'] = $current;
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

        $main_branch = $this->getConfig()->get("projects.$remote.main-branch", 'master');

        $update_parameters['meta']['commit-update'] = false;
        $source_commits = $upstream_repo->commits();
        $existing_commits = $remote_repo->commits();

        // Exit with no action and no error if already up-to-date
        if ($source_commits == $existing_commits) {
            $this->logger->notice("{remote} is at the most recent available version, {latest}", ['remote' => $remote, 'latest' => $latest]);
            return;
        }

        // Source commits are not equal to existing commits, compare the releases.
        if ($current === $latest || is_null($latest)) {
            // Set $latest to the last commit hash in the $soucre_commits string from git if $current and $latest are the same version.
            if (is_array($source_commits)) {
                $latest_commit = end($source_commits);
            } else {
                $latest_commit = $source_commits;
            }
            // Strip out everything after the first string of characters representing the git hash.
            $latest_commit = preg_replace('/^([a-z0-9]+).*/', '$1', $latest_commit);
            $update_parameters['meta']['commit-update'] = true;
            $update_parameters['meta']['latest-commit'] = $latest_commit;
        }

        if (!$update_parameters['meta']['commit-update']) {
            $this->logger->notice("{remote} {current} has an available update: {latest}", ['remote' => $remote, 'current' => $current, 'latest' => $latest]);
        } else {
            $this->logger->notice("{remote} {current} has an available update: {latest}", ['remote' => $remote, 'current' => $current, 'latest' => $latest_commit]);
        }

        // If we are running in check-only mode, exit now, before we do anything.
        if ($options['check']) {
            return;
        }

        // Create a commit message.
        $upstream_label = ucfirst($upstream);
        $message = $this->getConfig()->get("projects.$remote.upstream.update-message", 'Update to ' . $upstream_label . ' ' . $latest . '.');
        $message = str_replace('{version}', $latest, $message);
        $preamble = $this->getConfig()->get("projects.$remote.upstream.update-preamble", '');

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

        // Now search for PRs based on preamble if it was set.
        if ($preamble) {
            $prs = $api->matchingPRs($remote_repo->projectWithOrg(), $preamble, '');
        }

        $this->logger->notice("Updating {remote} from {current} to {latest}", ['remote' => $remote, 'current' => $current, 'latest' => $latest]);

        $branch = "update-$latest";

        $project_url = $this->getConfig()->get("projects.$remote.repo");
        $project_dir = $this->getConfig()->get("projects.$remote.path");
        $project_fork = $this->getConfig()->get("projects.$remote.fork");

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
                $this->logger->notice("This project is not configured to automatically tag; exiting.");
                return;
            }
            if ($main_branch_version != $latest) {
                $this->logger->notice("The latest version is {latest}, which is different than {current}, so I don't know what to do. Aborting.", ['latest' => $latest, 'current' => $current]);
            }
            $project_working_copy->fetch('origin', $tag_branch);
            $project_working_copy->switchBranch($tag_branch);
            $existing_commit_message = $project_working_copy->message($tag_branch);
            if (!$allow_msg_mismatch && strpos($existing_commit_message, $message) === false) {
                throw new \Exception("The commit message at the top of the {main} branch does not match the commit message we expect.\n\nExpected: $message\n\nActual: $existing_commit_message");
            }

            // Tag it up.
            $project_working_copy
                ->tag($latest, $tag_branch)
                ->push('origin', $latest);

            $this->logger->notice("Tagged version {latest}.", ['latest' => $latest]);
            $project_working_copy->switchBranch($main_branch);

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
        $pr = $updated_project
            ->forcePush()
            ->pr($message, $body, $main_branch);

        $comment = sprintf('Superseeded by #%s.', $pr['number']);

        // These PRs may be closed now, as they are replaced by the new PR.
        $api->prClose($project_working_copy->org(), $project_working_copy->project(), $prs, $comment);

        // Once the PR has been submitted, bring our cached project
        // back to the main branch.
        $updated_project->checkout($main_branch);

        $updater->complete($update_parameters);
    }

    /**
     * @command project:update-info
     * @param $project The github project to update info for.
     *
     * @return Consolidation\OutputFormatters\StructuredData\RowsOfFields
     */
    public function projectUpdateInfo($project, $options = [
        'as' => 'default',
        'codeowners' => '',
        'support-level-badge' => '',
        'branch-name' => 'project-update-info',
        'commit-message' => 'Update project information.',
        'pr-title' => 'Update project information.',
        'pr-body' => '',
        'base-branch' => 'master',
    ])
    {
        $api = $this->api($options['as']);
        $projectUpdate = new ProjectUpdate($this->logger);
        $projectUpdate->updateProjectInfo($api, $project, $options['base-branch'], $options['branch-name'], $options['commit-message'], $options['pr-title'], $options['pr-body'], $options['support-level-badge'], $options['codeowners']);
    }

    /**
     * @command project:update-from-base
     * @param $project The github project to update from base.
     */
    public function projectUpdateFromBase($project, $options = ['as' => 'default', 'push' => true, 'check' => false])
    {
        $api = $this->api($options['as']);

        $upstream = $this->getConfig()->get("projects.$project.upstream.repo");
        if (empty($upstream)) {
            throw new \Exception('Project cannot be updated; it is missing a source.');
        }
        $upstream_branch = $this->getConfig()->get("projects.$project.upstream.branch", 'master');

        $project_repo = $this->getConfig()->get("projects.$project.repo");
        $project_dir = $this->getConfig()->get("projects.$project.path");
        $base_branch = $this->getConfig()->get("projects.$project.base-branch");
        $main_branch = $this->getConfig()->get("projects.$project.main-branch");
        $tracking_file = $this->getConfig()->get("projects.$project.tracking-file");
        $commit_preamble = $this->getConfig()->get("projects.$project.commit-preamble");
        $pr_title_preamble = $this->getConfig()->get("projects.$project.pr-title-preamble");

        $project_working_copy = WorkingCopy::cloneBranch($project_repo, $project_dir, $main_branch, $api);
        $project_working_copy->setLogger($this->logger);
        $project_working_copy->switchBranch($base_branch);
        $project_working_copy->addRemote($upstream, 'upstream');
        $project_working_copy->pull('upstream', $upstream_branch);
        // Update base-branch in the process.
        $project_working_copy->push();

        $last_commit = null;
        $project_working_copy->switchBranch($main_branch);
        if (file_exists($project_dir . '/' . $tracking_file)) {
            $last_commit = trim(file_get_contents($project_dir . '/' . $tracking_file));
        }
        $project_working_copy->switchBranch($base_branch);

        $base_last_commit = $project_working_copy->revParse($base_branch);
        if ($base_last_commit === $last_commit) {
            $this->logger->notice("No changes since last update.");
            return;
        }

        $pr_title = sprintf('%s %s', $pr_title_preamble, substr($base_last_commit, 0, 7));

        $prs = $api->matchingPRs($project_working_copy->projectWithOrg(), $pr_title_preamble);
        if (in_array($pr_title, $prs->titles())) {
            $this->logger->notice("There is an existing pull request for this update; nothing else to do.");
            return;
        }

        // Pantheonize this repo.
        copy(__DIR__ . '/../../templates/composer-scaffold/pantheonize.sh', $project_dir . '/pantheonize.sh');
        chmod($project_dir . '/pantheonize.sh', 0755);
        $patch_path = realpath(__DIR__ . '/../../templates/composer-scaffold/pantheonize.patch');
        exec("cd $project_dir && PATCH_FILE=$patch_path ./pantheonize.sh && rm pantheonize.sh");
        $project_working_copy->addAll();

        // Create a temporary patch.
        exec("cd $project_dir && git diff --staged $main_branch > /tmp/$project-result.patch");
        $project_working_copy->reset($base_branch, true);

        if (trim(file_get_contents("/tmp/$project-result.patch")) === '') {
            $this->logger->notice("No changes since last update.");
            return;
        }

        // Apply the patch.
        $project_working_copy->switchBranch($main_branch);
        $date = date('Y-m-d');
        $new_branch_name = sprintf('update-%s', $date);
        $project_working_copy->createBranch($new_branch_name);
        $project_working_copy->apply("/tmp/$project-result.patch");

        // Update the hash file.
        file_put_contents($project_dir . '/' . $tracking_file, $base_last_commit);

        // Commit the changes.
        $project_working_copy->addAll();
        $commit_message = sprintf('%s. (%s, commit: %s)', $commit_preamble, $date, substr($base_last_commit, 0, 7));
        $project_working_copy->commit($commit_message);

        // Push the changes.
        $project_working_copy->push('origin', $new_branch_name);
        $project_working_copy->pr($pr_title, '', $main_branch, $new_branch_name);

        // Once we create a new PR, we can close the existing PRs.
        $api->prClose($project_working_copy->org(), $project_working_copy->project(), $prs);
    }

    /**
     * Apply specified filters to the working copy and commit the changes.
     */
    protected function applyFiltersAndCommit($filter_manager, $upstream_working_copy, $project_working_copy, $update_parameters)
    {
        // Apply the filters.
        $filter_manager->apply($upstream_working_copy->dir(), $project_working_copy->dir(), $update_parameters);

        // Commit changes.
        $comment = $upstream_working_copy->message();
        $commit_date = $upstream_working_copy->commitDate();
        $project_working_copy->addAll();
        $project_working_copy->commitBy($comment, 'Pantheon Automation <bot@getpantheon.com>', $commit_date);
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
        $update_class = "\\UpdateTool\\Update\\Methods\\$update_method";
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
