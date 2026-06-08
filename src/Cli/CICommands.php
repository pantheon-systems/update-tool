<?php

namespace UpdateTool\Cli;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Robo\Common\ConfigAwareTrait;
use UpdateTool\Git\WorkingCopy;
use UpdateTool\Util\CIUtilsTrait;
use UpdateTool\Hubph\VersionIdentifiers;
use UpdateTool\Hubph\HubphAPI;

/**
 * Commands used to create pull requests to update available php versions on the platform
 */
class CICommands extends \Robo\Tasks implements ConfigAwareInterface, LoggerAwareInterface
{
    use ConfigAwareTrait;
    use LoggerAwareTrait;
    use CIUtilsTrait;

    /**
     * Cancel the current build.
     *
     * @command ci:build:cancel
     */
    public function cancelCurrentBuild()
    {
        $this->cancelCurrentCIBuild();
    }
}
