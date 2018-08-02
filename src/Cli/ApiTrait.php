<?php

namespace Updatinate\Cli;

use Hubph\HubphAPI;

trait ApiTrait
{
    /**
     * Return a Hubph API object authenticated per the credentials
     * indicated by the active configuration, as selected by the
     * "as" parameter.
     */
    protected function api($as = 'default')
    {
        $api = new HubphAPI($this->getConfig());
        $api->setAs($as);

        return $api;
    }
}
