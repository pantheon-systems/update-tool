<?php

namespace UpdateTool\Update\Filters;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use UpdateTool\Util\ExecWithRedactionTrait;
use UpdateTool\Util\TmpDir;

/**
 * RenameProject changes the name of the project in its composer.json file
 * to match the target repository project and org.
 */
class RenameProject implements UpdateFilterInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;
    use ExecWithRedactionTrait;

    /**
     * If the update parameters contain any patch files, then apply
     * them by running 'patch'
     */
    public function action($src, $dest, $parameters)
    {
        $parameters += ['project-name' => $parameters['meta']['name']];

        $composer_json = json_decode(file_get_contents("$dest/composer.json"), true);
        $composer_json['name'] = $parameters['project-name'];
        file_put_contents("$dest/composer.json", json_encode($composer_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
