<?php

namespace Updatinate\Update\Filters;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;

/**
 * CopyTemplateAdditions is an update filter that will copy files
 * from a template directory into the project being updated.
 */
class CopyTemplateAdditions implements UpdateFilterInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function action($src, $dest, $parameters)
    {
        $parameters += [
            'templates' => [['source' => '.', 'target' => '.']],
            'template-base' => basename($parameters['meta']['name']),
        ];

        $base = "./templates/" . $parameters['template-base'];
        if (!is_dir($base)) {
            $base = dirname(__DIR__, 3) . "/$base";
        }

        foreach ($parameters['templates'] as $item) {
            $from = $item['source'];
            $to = $item['target'];
            $this->logger->notice('Copying template item {item}', ['item' => $from]);
            $this->copyFileOrDirectory($base . '/' . $from, $dest . '/' . $to);
        }
    }

    /**
     * Helpful wrapper to call either 'mirror' or 'copy' as needed.
     */
    protected function copyFileOrDirectory($src, $dest)
    {
        $fs = new Filesystem();

        $fs->mkdir(dirname($dest));
        if (is_dir($src)) {
            $fs->mirror($src, $dest);
        } else {
            $fs->copy($src, $dest, true);
        }
    }
}
