<?php

namespace UpdateTool\Cli;

use Hubph\HubphAPI;
use Symfony\Component\Filesystem\Filesystem;

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

        // Log API authentication setup
        if (isset($this->logger)) {
            try {
                $token = $api->gitHubToken();
                $hasToken = $token ? 'yes' : 'no';
                $tokenLength = $token ? strlen($token) : 0;
                $this->logger->notice("API setup: using profile '{as}', token available={hasToken}, token length={tokenLength}", [
                    'as' => $as,
                    'hasToken' => $hasToken,
                    'tokenLength' => $tokenLength
                ]);
            } catch (\Exception $e) {
                $this->logger->error("Error getting GitHub token: {error}", ['error' => $e->getMessage()]);
            }
        }

        // Turn on PR request logging if the log path is set.
        $log_path = $this->getConfig()->get('log.path');
        if ($log_path) {
            $fs = new Filesystem();
            $fs->mkdir(dirname($log_path));
            $api->startLogging($log_path);
        }

        return $api;
    }
}
