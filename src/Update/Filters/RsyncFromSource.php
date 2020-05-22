<?php

namespace Updatinate\Update\Filters;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;

/**
 * RsyncFromSource is an update filter that will copy files
 * from the upstream repository into the target repository.
 * By default, we copy everything from the source except the
 * '.git' directory (which wouldn't make sense), but certain
 * directories may be excluded via the 'exclusions' option
 * if desired.
 *
 * See also 'CopyPlatformAdditions'.
 */
class RsyncFromSource implements UpdateFilterInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Create the destination by rsyncing from the source.
     */
    public function action($src, $dest, $parameters)
    {
        $parameters += ['rsync' => []];
        $parameters['rsync'] += ['options' => '-ravz --delete', 'exclusions' => []];
        $options = $parameters['rsync']['options'];
        $exclusions = $parameters['rsync']['exclusions'];
        if (!in_array('.git', $exclusions)) {
            $exclusions[] = '.git';
        }
        $options .= ' ' . implode(
            ' ',
            array_map(
                function ($item) {
                    return "--exclude=$item";
                },
                $exclusions
            )
        );

        exec("rsync $options $src/ $dest >/dev/null 2>&1");
    }
}
