<?php

namespace Updatinate;

use Symfony\Component\Filesystem\Filesystem;
use Hubph\HubphAPI;
use Updatinate\Git\WorkingCopy;
use Updatinate\Git\Remote;

class Fixtures
{
    protected $testDir;
    protected $tmpDirs = [];
    protected $prevEnvs = [];
    protected $config;

    // Our test fixtures' idea of what the current php releases are, frozen in time.
    const PHP_53_CURRENT = '5.3.29';
    const PHP_55_CURRENT = '5.5.38';
    const PHP_56_CURRENT = '5.6.37';
    const PHP_70_CURRENT = '7.0.31';
    const PHP_71_CURRENT = '7.1.20';
    const PHP_72_CURRENT = '7.2.8';

    /**
     * Fixtures constructor
     */
    public function __construct()
    {
        $testDir = false;
        $this->seed = date("Y") - 2017 . date("mdHi");
        $this->prevEnvs = $this->setupEnv($this->seed);
    }

    /**
     * Clean up any temporary directories that may have been created
     */
    public function cleanup()
    {
        $this->putEnvs($this->prevEnvs);
        return;
        $fs = new Filesystem();
        foreach ($this->tmpDirs as $tmpDir) {
            $fs->remove($tmpDir);
        }
        $this->tmpDirs = [];
    }

    public function seed()
    {
        return $this->seed;
    }

    public function configurationFile()
    {
        return $this->homeDir() . '/test-configuration.yml';
    }

    public function getConfig()
    {
        if (!$this->config) {
            $this->config = \Robo\Robo::createConfiguration((array)$this->configurationFile());
        }
        return $this->config;
    }

    public function api($as = 'default')
    {
        $api = new HubphAPI($this->getConfig());
        $api->setAs($as);

        return $api;
    }

    public function phpRpmWorkingCopy()
    {
        $rpmbuild_php_url = $this->getConfig()->get('projects.rpmbuild-php.repo');
        $rpmbuild_php_dir = $this->getConfig()->get('projects.rpmbuild-php.path');
        return WorkingCopy::clone($rpmbuild_php_url, $rpmbuild_php_dir, $this->api());
    }

    public function phpCookbookWorkingCopy()
    {
        $php_cookbook_url = $this->getConfig()->get('projects.php-cookbook.repo');
        $php_cookbook_dir = $this->getConfig()->get('projects.php-cookbook.path');

        return WorkingCopy::clone($php_cookbook_url, $php_cookbook_dir, $this->api());
    }

    public function forceReinitializeProjectFixtures($remote_name, $as = 'default')
    {
        $api = $this->api($as);

        $remote_url = $this->getConfig()->get("projects.$remote_name.repo");
        $remote_repo = Remote::create($remote_url, $api);

        $allPRs = $api->allPRs($remote_repo->org() . '/' . $remote_repo->project());
        $api->prClose($remote_repo->org(), $remote_repo->project(), $allPRs);
    }

    public function forceReinitializePhpFixtures($as = 'default')
    {
        $api = $this->api($as);

        $rpmbuild_php_url = $this->getConfig()->get('projects.rpmbuild-php.repo');
        $rpmbuild_php_dir = $this->getConfig()->get('projects.rpmbuild-php.path');

        $rpmbuild_php_fixture = $this->rpmbuildPhpFixture();

        $this->forceReinitialize($rpmbuild_php_url, $rpmbuild_php_dir, $rpmbuild_php_fixture, $api);

        $php_cookbook_url = $this->getConfig()->get('projects.php-cookbook.repo');
        $php_cookbook_dir = $this->getConfig()->get('projects.php-cookbook.path');

        $php_cookbook_fixture = $this->phpCookbookFixture();

        $this->forceReinitialize($php_cookbook_url, $php_cookbook_dir, $php_cookbook_fixture, $api);
    }

    protected function forceReinitialize($url, $dir, $fixture, $api)
    {
        $workingCopy = WorkingCopy::forceReinitializeFixture($url, $dir, $fixture, $api);

        $allPRs = $api->allPRs($workingCopy->org() . '/' . $workingCopy->project());
        $api->prClose($workingCopy->org(), $workingCopy->project(), $allPRs);

        return $workingCopy;
    }

    public function activityLogPath()
    {
        return $this->getConfig()->get('log.path');
    }

    /**
     * Create a new temporary directory.
     *
     * @param string|bool $basedir Where to store the temporary directory
     * @return type
     */
    public function mktmpdir($basedir = false)
    {
        $tempfile = tempnam($basedir ?: $this->testDir ?: sys_get_temp_dir(),'updatinate-tests');
        unlink($tempfile);
        mkdir($tempfile);
        $this->tmpDirs[] = $tempfile;
        return $tempfile;
    }

    /**
     * Set up environment variables for testing
     */
    protected function setupEnv($seed)
    {
        $envs = [
            'TESTHOME' => $this->homeDir(),
            'TESTDIR' => $this->testDir(),

            // These override messages.update-to and constants.branch-prefix,
            // respectively, and allow us to inject unique values to differentiate
            // our current test run from past test runs.
            // @see PhpCommands::preamble() and PhpCommands::branchPrefix().
            'TEST_OVERRIDE_MESSAGES_UPDATE_TO' => "[$seed] Update to ",
            'TEST_OVERRIDE_CONSTANTS_BRANCH_PREFIX' => "php-$seed-",
        ];
        return $this->putEnvs($envs);
    }

    protected function putEnvs($envs)
    {
        $prevEnvs = [];
        foreach ($envs as $var => $val) {
            $prevEnvs[$var] = getenv($var);
            putenv("{$var}={$val}");
        }
        return $prevEnvs;
    }

    protected function fixturesDir()
    {
        return dirname(__DIR__) . '/fixtures';
    }

    protected function rpmbuildPhpFixture()
    {
        return $this->fixturesDir() . '/rpmbuild-php';
    }

    protected function phpCookbookFixture()
    {
        return $this->fixturesDir() . '/php-cookbook';
    }

    protected function homeDir()
    {
        return $this->fixturesDir() . '/home';
    }

    protected function testDir()
    {
        if (!$this->testDir) {
            $this->testDir = $this->mktmpdir();
        }
        return $this->testDir;
    }

    public function phpDotNetDir()
    {
        return $this->testDir() . '/php.net';
    }

    public function setupPhpDotNetFixture($availablePhpVersions)
    {
        $fs = new Filesystem();
        $baseDir = $this->phpDotNetDir() . '/distributions';
        $fs->remove($baseDir);
        $fs->mkdir($baseDir);
        foreach ($availablePhpVersions as $phpVersion) {
            $phpDownloadFixture = "{$baseDir}/php-{$phpVersion}.tar.gz";
            file_put_contents($phpDownloadFixture, '');
        }
    }

    /**
     * Calculate the next version after the provided version
     */
    public function next($version)
    {
        $parts = explode('.', $version);
        $parts[count($parts) - 1]++;
        return implode('.', $parts);
    }
}
