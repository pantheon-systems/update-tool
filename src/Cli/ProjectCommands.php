<?php

namespace Updatinate\Cli;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Robo\Common\ConfigAwareTrait;
use Updatinate\Git\WorkingCopy;
use Hubph\VersionIdentifiers;
use Hubph\HubphAPI;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Updatinate\Util\ReleaseNode;
use Consolidation\Config\Util\Interpolator;

use Updatinate\Git\Remote;
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
     * Show the list of available releases for the specified project.
     *
     * @command project:releases
     */
    public function projectReleases($remote, $options = ['as' => 'default', 'major' => '[0-9]+', 'format' => 'yaml'])
    {
        $api = $this->api($options['as']);
        $remote_repo = $this->createRemote($remote, $api);

        return new RowsOfFields($remote_repo->releases($options['major']));
    }

    /**
     * Show the latest available releases for the specified project.
     *
     * @command project:latest
     */
    public function projectLatest($remote, $options = ['as' => 'default', 'major' => '[0-9]+'])
    {
        $api = $this->api($options['as']);
        $remote_repo = $this->createRemote($remote, $api);

        return $remote_repo->latest($options['major']);
    }

    /**
     * Check to see if there is an update available on the upstream of the specified project.
     *
     * @command project:upstream:check
     */
    public function projectCheck($remote, $options = ['as' => 'default'])
    {
        $api = $this->api($options['as']);
        $upstream = $this->getConfig()->get("projects.$remote.upstream.project");
        $tag_prefix = $this->getConfig()->get("projects.$remote.upstream.tag-prefix", '');
        $major = $this->getConfig()->get("projects.$remote.upstream.major", '[0-9]+');

        $remote_repo = $this->createRemote($remote, $api);
        $upstream_repo = $this->createRemote($upstream, $api);

        $latest = $upstream_repo->latest($major, $tag_prefix);

        if ($remote_repo->has($latest)) {
            $this->logger->notice("{remote} is at the most recent available version, {latest}", ['remote' => $remote, 'latest' => $latest]);
            return;
        }
        $this->logger->notice("{remote} has an available update: {latest}", ['remote' => $remote, 'latest' => $latest]);
    }

    /**
     * @command project:release-node
     */
    public function releaseNode($remote, $version = '', $options = ['as' => 'default', 'major' => '[0-9]'])
    {
        $api = $this->api($options['as']);
        $releaseNode = new ReleaseNode($api);
        list($failure_message, $release_node) = $releaseNode->get($this->getConfig(), $remote, $options['major'], $version);

        if (!empty($failure_message)) {
            throw new \Exception($failure_message);
        }

        return $release_node;
    }

    /**
     * @command project:upstream:update
     */
    public function projectUpstreamUpdate($remote, $options = ['as' => 'default'])
    {
        $api = $this->api($options['as']);

        $releaseNode = new ReleaseNode($api);

        // Get references to the remote repo and the upstream repo
        $upstream = $this->getConfig()->get("projects.$remote.upstream.project");
        if (empty($upstream)) {
            throw new \Exception('Project cannot be updated; it is missing an upstream.');
        }

        $main_branch = $this->getConfig()->get("projects.$remote.main-branch", 'master');

        $remote_repo = $this->createRemote($remote, $api);

        // Determine the major version of the upstream repo
        $version_pattern = $this->getConfig()->get("projects.$remote.upstream.version-pattern", '#.#.#');
        $tag_prefix = $this->getConfig()->get("projects.$remote.upstream.tag-prefix", '');
        $major = $this->getConfig()->get("projects.$remote.upstream.major", '[0-9]+');
        $current = $remote_repo->latest($major);
        $major = preg_replace('#\..*#', '', $major);

        // Find an update method and create an updater
        $update_method = $this->getConfig()->get("projects.$remote.upstream.update-method");
        $update_parameters = $this->getConfig()->get("projects.$remote.upstream.update-parameters", []);
        $updater = $this->getUpdater($update_method, $api);
        if (empty($updater)) {
            throw new \Exception('Project cannot be updated; it is missing an update method.');
        }
        $updater->setApi($api);
        $updater->setLogger($this->logger);

        // Allow the updator to configure itself prior to the update.
        $updater->configure($this->getConfig(), $remote);

        $this->logger->notice("Check latest version for {upstream}.", ['upstream' => $upstream]);

        // Determine the latest version in the same major series in the upstream
        // TODO: existing script allows 'latest' to be taken from beta / RC / nightly builds.
        $latest = $updater->findLatestVersion($major, $tag_prefix);

        // Convert $latest to a version number matching $version_pattern,
        // and put the actual tag name in $latestTag.
        list($latest, $latestTag) = $this->versionAndTagFromLatest($latest, $tag_prefix, $version_pattern);
        $update_parameters['latest-tag'] = $latestTag;

        // Exit with no action and no error if already up-to-date
        if ($remote_repo->has($latest)) {
            $this->logger->notice("{remote} is at the most recent available version, {latest}", ['remote' => $remote, 'latest' => $latest]);
            return;
        }

        $this->logger->notice("Latest version of {upstream} is {latest}.", ['upstream' => $upstream, 'latest' => $latest]);

        // Create a commit message.
        $upstream_label = ucfirst($upstream);
        $message = $this->getConfig()->get("projects.$remote.upstream.update-message", 'Update to ' . $upstream_label . ' ' . $latest . '.');
        $message = str_replace('{version}', $latest, $message);

        // If we can find a release node, then add the "more information" blerb.
        list($failure_message, $releaseNode) = $releaseNode->get($this->getConfig(), $remote, $major);
        if (strstr($releaseNode, $latest) !== false) {
            $message .= " For more information, see $releaseNode";
        }

        $this->logger->notice("Update message: {msg}", ['msg' => $message]);

        $vids = new VersionIdentifiers();
        $vids->setVvalPattern($version_pattern);
        $vids->addVidsFromMessage($message);

        // Check to see if there are any open PRs that have already done this
        // work, or that are old and need to be closed.
        list($status, $prs) = $api->prCheck($remote_repo->projectWithOrg(), $vids);
        $existingPRList = $prs->prNumbers();
        if ($status) {
            $this->logger->notice("Pull request already exists for available update {latest}; nothing more to do.", ['latest' => $latest]);
            return;
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

        // TODO: Existing drops-8 script pre-tags scaffolding files if possible
        // We should probably do this in a separate command.

        // TODO: Apply 'shipit' PRs from the GitHub repository to the project working copy

        // TODO: Some update methods (e.g. WpCliUpdate) do not need the
        // upstream working copy. We are needlessly cloning that repo in
        // those instances at the moment.
        $updated_project = $updater->update($project_working_copy, $update_parameters);

        // Confirm that the updated version of the code is now equal to $latest
        $version_info = new VersionTool();
        $info = $version_info->info($updated_project->dir());
        $updated_version = $info->version();
        if ($updated_version != $latest) {
            throw new \Exception("Update failed. We expected that the updated version of the project should be $latest, but instead it is $updated_version.");
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

        // Commit, push, and make the PR
        $updated_project
            ->createBranch($branch, $main_branch, true)
            ->add('.')
            ->commit($message)
            ->forcePush()
            ->pr($message, $body, $main_branch);

        // These PRs may be closed now, as they are replaced by the new PR.
        $api->prClose($project_working_copy->org(), $project_working_copy->project(), $prs);

        // Once the PR has been submitted, bring our cached project
        // back to the main branch.
        $updated_project->checkout($main_branch);

        // TODO: existing script once again checks to see if it should
        // pre-tag the scaffolding files here, if it didn't do it above, for
        // instances where the PR is for a pre-release (as beta / RC pull
        // requests will never be released).
        // We should probably do this in a separate command.

        $updater->complete($update_parameters);
    }

    protected function versionAndTagFromLatest($latest, $tag_prefix, $version_pattern)
    {
        $latestTag = "$tag_prefix$latest";
        $versionPatternRegex = str_replace('.', '\\.', $version_pattern);
        $versionPatternRegex = str_replace('#', '[0-9]+', $versionPatternRegex);
        if (preg_match("#$versionPatternRegex#", $latest, $matches)) {
            $latest = $matches[0];
        }

        return [$latest, $latestTag];
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
