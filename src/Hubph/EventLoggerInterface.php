<?php

namespace UpdateTool\Hubph;

interface EventLoggerInterface
{
    public function start();
    public function stop();
    public function log($event_name, $args, $params, $response);
}
