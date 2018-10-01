<?php

namespace Updatinate\Update\Methods;

use Hubph\HubphAPI;

/**
 * SingleCommit is an update method that takes all of the changes from
 * the upstream repository and composes them into a single commit that
 * is added to the working copy being updated.
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
