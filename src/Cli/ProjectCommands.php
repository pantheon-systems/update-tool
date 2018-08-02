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
        $remote = Remote::create($remote);

        return new RowsOfFields($remote->releases($options['major']));
    }

    /**
     * Show the latest available releases for the specified project.
     *
     * @command project:latest
     */
    public function projectLatest($remote, $options = ['as' => 'default', 'major' => '[0-9]+'])
    {
        $remote = Remote::create($remote);

        return $remote->latest($options['major']);
    }
}
