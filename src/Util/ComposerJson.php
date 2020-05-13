<?php
namespace Updatinate\Util;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

/**
 * ComposerJson is a convenience class for manipulating composer.json files.
 */
class ComposerJson extends JsonFile
{
    public function addAllowedPackage($package)
    {
        $this->data['extra']['drupal-scaffold']['allowed-packages'][] = 'pantheon-systems/drupal-integrations';
    }
}
