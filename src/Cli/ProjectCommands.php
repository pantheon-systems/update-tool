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

use Updatinate\Git\Remote;

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
        $remote_repo = $this->createRemote($remote);

        return new RowsOfFields($remote_repo->releases($options['major']));
    }

    /**
     * Show the latest available releases for the specified project.
     *
     * @command project:latest
     */
    public function projectLatest($remote, $options = ['as' => 'default', 'major' => '[0-9]+'])
    {
        $remote_repo = $this->createRemote($remote);

        return $remote_repo->latest($options['major']);
    }

    /**
     * Check to see if there is an update available on the upstream of the specified project.
     *
     * @command project:upstream:check
     */
    public function projectCheck($remote, $options = ['as' => 'default', 'major' => '[0-9]+'])
    {
        $upstream = $this->getConfig()->get("projects.$remote.upstream");

        $remote_repo = $this->createRemote($remote);
        $upstream_repo = $this->createRemote($upstream);

        $latest = $upstream_repo->latest($options['major']);

        if ($remote_repo->has($latest)) {
            $this->logger->notice("{remote} is at the most recent available version, {latest}", ['remote' => $remote, 'latest' => $latest]);
            return;
        }
        $this->logger->notice("{remote} has an available update: {latest}", ['remote' => $remote, 'latest' => $latest]);
    }

    /**
     * @command project:upstream:update
     */
    public function projectUpstreamUpdate($remote, $options = ['as' => 'default', 'major' => '[0-9]+'])
    {
        $upstream = $this->getConfig()->get("projects.$remote.upstream");

        $remote_repo = $this->createRemote($remote);
        $upstream_repo = $this->createRemote($upstream);

        $latest = $upstream_repo->latest($options['major']);

        if ($remote_repo->has($latest)) {
            $this->logger->notice("{remote} is at the most recent available version, {latest}", ['remote' => $remote, 'latest' => $latest]);
            return;
        }

        $upstream_label = ucfirst($upstream);
        $message = 'Update to ' . $upstream_label . ' ' . $latest;

        $api = $this->api($options['as']);

        $vids = new VersionIdentifiers();
        $vids->addVidsFromMessage($message);

        // Check to see if there are any open PRs that have already done this
        // work, or that are old and need to be closed.
        list($status, $existingPRList) = $api->prCheck($remote_repo->projectWithOrg(), $vids);
        if ($status) {
            $this->logger->notice("Pull request already exists for available update; nothing more to do.");
            return;
        }

        $this->logger->notice("Updating {remote} to {latest}", ['remote' => $remote, 'latest' => $latest]);

        $branch = "update-$latest";

        $project_url = $this->getConfig()->get("projects.$remote.repo");
        $project_dir = $this->getConfig()->get("projects.$remote.path");

        $upstream_url = $this->getConfig()->get("projects.$upstream.repo");
        $upstream_dir = $this->getConfig()->get("projects.$upstream.path");

        $project_working_copy = WorkingCopy::clone($project_url, $project_dir, $api);
        $project_working_copy
            ->setLogger($this->logger);

        // $current = $remote_repo->latest($options['major']);

        // Clone the upstream. Check out just $latest
        $upstream_working_copy = WorkingCopy::shallowClone($upstream_url, $upstream_dir, $latest, $api);

        // Make a patch from the upstream between $current and $latest

        // Apply 'shipit' PRs from the GitHub repository to the project working copy

        // Apply the patch from the upstream repo to the project working copy

/*

        // Commit, push, and make the PR
        $project_working_copy
            ->createBranch($branch, 'master', true)
            ->add('.')
            ->commit($message)
            ->push('origin', $branch)
            ->pr($message);

        // TODO: $existingPRList might be a string or an array of PR numbers. :P Fix.
        if (is_array($existingPRList)) {
            $api->prClose($project_working_copy->org(), $project_working_copy->project(), $existingPRList);
        }
*/
    }

    protected function createRemote($remote_name)
    {
        $remote_url = $this->getConfig()->get("projects.$remote_name.repo");

        return Remote::create($remote_url);
    }
}
