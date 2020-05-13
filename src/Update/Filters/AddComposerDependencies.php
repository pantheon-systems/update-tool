<?php

namespace Updatinate\Update\Filters;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Updatinate\Util\ExecWithRedactionTrait;

/**
 * ApplyPlatformPatches is an update filter that will apply patches
 * onto the branch being updated.
 */
class AddComposerDependencies implements UpdateFilterInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;
    use ExecWithRedactionTrait;

    /**
     * If the update parameters contain any patch files, then apply
     * them by running 'patch'
     */
    public function action($src, $dest, $parameters)
    {
        $parameters += ['composer-dependencies' => [], 'composer-dev-dependencies' => []];

        foreach ($parameters['composer-dependencies'] as $project => $constraint) {
            $this->logger->notice('Adding {project}', ['project' => $project]);
            $this->addDependency($dest, $project, $constraint, false);
        }
        foreach ($parameters['composer-dev-dependencies'] as $project => $constraint) {
            $this->logger->notice('Adding {project} as "require-dev"', ['project' => $project]);
            $this->addDependency($dest, $project, $constraint, true);
        }
    }

    /**
     * Add a dependency.
     */
    protected function addDependency($dest, $project, $version_constraint, $dev)
    {
        $this->execWithRedaction('composer -n --working-dir={dest} -q require {dev} {project}:{constraint}', ['dest' => $dest, 'dev' => $dev ? '--dev' : '', 'project' => $project, 'constraint' => $version_constraint], ['dest' => basename($dest)]);
    }
}
