<?php

namespace UpdateTool\Hubph\Internal;

use UpdateTool\Hubph\EventLoggerInterface;

class EventLogger implements EventLoggerInterface
{
    protected $filename;

    public function __construct($filename)
    {
        $this->filename = $filename;
    }

    public function start()
    {
        if (file_exists($this->filename)) {
            @unlink($this->filename);
        }
    }

    public function stop()
    {
    }

    public function log($event_name, $args, $params, $response)
    {
        $this->writeHeader();
        $entry = $this->getEntry($event_name, $args, $params, $response);
        file_put_contents($this->filename, $entry, FILE_APPEND);
    }

    protected function writeHeader()
    {
        if (!file_exists($this->filename)) {
            file_put_contents($this->filename, $this->getHeader());
        }
    }

    protected function getHeader()
    {
        return <<<EOT
# Event log
# ---------

EOT;
    }

    protected function getEntry($event_name, $args, $params, $response)
    {
        $args_string = json_encode($args);
        $params_string = json_encode($params);
        $response_string = json_encode($response);

        return <<<EOT
name    : $event_name
args    : $args_string
params  : $params_string
response: $response_string
EOT;
    }
}
