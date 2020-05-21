<?php

namespace Updatinate\Update\Filters;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * CopyPlatformAdditions is an update filter that will copy files
 * from the source branch onto the branch being updated.
 */
class RemoveDrupalOrgPackagingInfo implements UpdateFilterInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * The drupal.org packaging scripts add version and package date
     * information into the .info.yml files. We take these out so that
     * we will have a smaller diff on each release.
     */
    public function action($src, $dest, $parameters)
    {
        $base = "$dest/core";
        $files = Finder::create()
          ->files()
          ->name('*.info.yml')
          ->exclude('includes')
          ->exclude('libs')
          ->in($base)
        ;

        foreach ($files as $file) {
            $this->removePackagingInfo($base . '/' . $file->getRelativePathname());
        }
    }

    protected function removePackagingInfo($path)
    {
        $contents = file_get_contents($path);

        $altered = preg_replace('%# version:%ms', 'version:', $contents);
        $altered = preg_replace('%\n*# Information added by.*%ms', '', $altered);
        $altered .= "\n";

        if ($altered != $contents) {
            file_put_contents($path, $altered);
        }
    }
}
