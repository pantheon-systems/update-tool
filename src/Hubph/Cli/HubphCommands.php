<?php

namespace UpdateTool\Hubph\Cli;

use Robo\Contract\ConfigAwareInterface;
use Robo\Common\ConfigAwareTrait;
use Robo\Tasks;
use UpdateTool\Hubph\HubphAPI;

class HubphCommands extends Tasks implements ConfigAwareInterface
{
    use ConfigAwareTrait;

    /**
     * Show the user we are authenticated as.
     *
     * @command whoami
     */
    public function whoami($options = ['as' => 'default'])
    {
        $api = new HubphAPI($this->getConfig());
        $api->setAs($options['as']);
        $authenticated = $api->whoami();
        $this->io()->text('Authenticated as ' . $authenticated['login']);
    }
}
