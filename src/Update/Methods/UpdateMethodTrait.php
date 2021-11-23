<?php

namespace UpdateTool\Update\Methods;

use Hubph\HubphAPI;
use UpdateTool\Update\Filters\FilterManager;

/**
 * Not sure why this is a trait; it should probably be a base class.
 */
trait UpdateMethodTrait
{
    /** @var HubphAPI */
    protected $api;

    /** @var FilterManger */
    protected $filters;

    public function setApi(HubphAPI $api)
    {
        $this->api = $api;
    }

    public function setFilters(FilterManager $filters)
    {
        $this->filters = $filters;
    }
}
