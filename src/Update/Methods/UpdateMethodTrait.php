<?php

namespace Updatinate\Update\Methods;

use Hubph\HubphAPI;

/**
 * Not sure why this is a trait; it should probably be a base class.
 */
trait UpdateMethodTrait
{
    /** @var HubphAPI */
    protected $api;

    public function setApi(HubphAPI $api)
    {
        $this->api = $api;
    }
}
