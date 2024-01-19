<?php

namespace UpdateTool;

use Consolidation\Config\Util\EnvConfig;
use Consolidation\Log\Logger;
use Hubph\HubphAPI;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;
use UpdateTool\Git\Remote;
use UpdateTool\Git\WorkingCopy;

class Fixtures
{
    protected $testDir;
    protected $phpDotNetDir;
    protected $forkedRepos = [];
    protected $tmpDirs = [];
    protected $prevEnvs = [];
    protected $config;
    protected $logOutput;
    protected $logger;

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

        // Delete all of our scratch forked repositories
        foreach ($this->forkedRepos as $fork) {
            $fork->deleteFork();
        }

        // Remove all of our temporary directories
        $fs = new Filesystem();
        foreach ($this->tmpDirs as $tmpDir) {
            $fs->remove($tmpDir);
        }
        $this->tmpDirs = [];
        $this->logger = null;
        $this->logOutput = null;
        $this->config = null;
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
            $this->config = new \Robo\Config\Config();
            $this->config->set('nonce', $this->seed());
            \Robo\Robo::loadConfiguration((array)$this->configurationFile(), $this->config);

            $envConfig = new EnvConfig('SUTCONFIG');
            $this->config->addContext('env', $envConfig);
        }
        return $this->config;
    }

    /**
     * Fetch everything that has been logged so far and clear the log buffer.
     */
    public function fetchLogOutput()
    {
        if (!$this->logOutput) {
            return '';
        }

        return $this->logOutput->fetch();
    }

    public function getLogger()
    {
        if (!$this->logger) {
            $this->logOutput = new BufferedOutput();
            $this->logger = new Logger($this->logOutput);
        }

        return $this->logger;
    }

    public function api($as = 'default')
    {
        $api = new HubphAPI($this->getConfig());
        $api->setAs($as);

        return $api;
    }

    public function closeAllOpenPullRequests($remote_name, $as = 'default')
    {
        $api = $this->api($as);

        $remote_url = $this->getConfig()->get("projects.$remote_name.repo");
        $remote_repo = Remote::create($remote_url, $api);

        $allPRs = $api->allPRs($remote_repo->org() . '/' . $remote_repo->project());
        $api->prClose($remote_repo->org(), $remote_repo->project(), $allPRs);
    }

    public function mergeAllOpenPullRequests($remote_name, $as = 'default')
    {
        $api = $this->api($as);

        $remote_url = $this->getConfig()->get("projects.$remote_name.repo");
        $remote_repo = Remote::create($remote_url, $api);

        $allPRs = $api->allPRs($remote_repo->org() . '/' . $remote_repo->project());
        $api->prMerge($remote_repo->org(), $remote_repo->project(), $allPRs, '');
    }

    public function forceReinitializeDrops8Fixture($as = 'default')
    {
        $fixture_repo = 'drops-8';
        $api = $this->api($as);

        $drops_8_url = $this->getConfig()->get("projects.$fixture_repo.repo");
        $dir = $this->mktmpdir();
        rmdir($dir);

        $workingCopy = WorkingCopy::clone($drops_8_url, $dir, $api);

        // Remove any extra tags not in the allowed list.
        $remote = $workingCopy->remote();
        $tags = $remote->tags('^8');
        $allowed_tags = ['8.5.1', '8.5.3', '8.5.4', '8.5.5', '8.5.6'];
        $unwanted_tags = array_diff(array_keys($tags), $allowed_tags);
        foreach ($unwanted_tags as $unwanted) {
            $remote->delete($unwanted);
        }

        // Reset the default branch back to the state of the default-fixture branch
        $workingCopy->createBranch('default', 'origin/default-fixture', true);
        $workingCopy->push('', 'default', true);
    }

    protected function forceReinitialize($url, $dir, $fixture, $api)
    {
        $workingCopy = static::forceReinitializeFixture($url, $dir, $fixture, $api);

        $allPRs = $api->allPRs($workingCopy->org() . '/' . $workingCopy->project());
        $api->prClose($workingCopy->org(), $workingCopy->project(), $allPRs);

        return $workingCopy;
    }

    public function forkTestRepo($remote_name, $as = 'default')
    {
        $api = $this->api($as);
        $url = $this->getConfig()->get("projects.$remote_name.repo");
        $path = $this->getConfig()->get("projects.$remote_name.path");
        $fork_url = $this->getConfig()->get("projects.$remote_name.fork");
        $main_branch = $this->getConfig()->get("projects.$remote_name.main-branch", 'master');

        $original = WorkingCopy::clone($url, $path, $api);
        if (!$fork_url) {
            return $original;
        }

        $forkProjectWithOrg = Remote::projectWithOrgFromUrl($fork_url);
        $forked_project_name = basename($forkProjectWithOrg);
        $forked_project_org = dirname($forkProjectWithOrg);

        $original->createFork($forked_project_name, $forked_project_org, $main_branch);

        // Remember that we forked this repo so that we can clean it up
        // when we're done.
        $this->forkedRepos[] = $original;

        return $original;
    }

    /**
     * Blow away the existing repository at the provided directory and
     * force-push the new empty repository to the destination URL.
     *
     * @param string $url
     * @param string $dir
     * @param HubphAPI|null $api
     * @return WorkingCopy
     */
    public static function forceReinitializeFixture($url, $dir, $fixture, $api)
    {
        $fs = new Filesystem();

        // Make extra-sure that no one accidentally calls the tests on a non-fixture repo
        if (strpos($url, 'fixture') === false) {
            throw new \Exception('WorkingCopy::forceReinitializeFixture requires url to contain the string "fixture" to avoid accidental deletion of non-fixture repositories. Provided fixture: ' . $url);
        }

        // TODO: check to see if the fixture repository has never been initialized

        if (false) {
            $auth_url = $api->addTokenAuthentication($url);

            static::copyFixtureOverReinitializedRepo($dir, $fixture);
            exec("git -C {$dir} init", $output, $status);
            exec("git -C {$dir} add -A", $output, $status);
            exec("git -C {$dir} commit -m 'Initial fixture data'", $output, $status);
            static::setRemoteUrl($auth_url, $dir);
            exec("git -C {$dir} push --force origin master");
        }

        $workingCopy = WorkingCopy::clone($url, $dir, $api);

        // Find the first commit and re-initialize
        $topCommit = $workingCopy->git('rev-list HEAD');
        $topCommit = $topCommit[0];
        $firstCommit = $workingCopy->git('rev-list --max-parents=0 HEAD');
        $firstCommit = $firstCommit[0];
        $workingCopy->reset($firstCommit, true);

        // TODO: Not quite working yet; overwrites .git directory even
        // without 'delete' => true
        if (false) {
            // Check to see if the fixtures changed
            // n.b. if we add 'delete' => true then our .git directory
            // disappears, which breaks everything. Without it, we risk
            // retaining deleted assets.
            $fs->mirror($fixture, $dir, null, ['override' => true, 'delete' => true]);
            static::copyFixtureOverReinitializedRepo($dir, $fixture);
            $hasModifications = $workingCopy->status();

            if (!empty($hasModifications)) {
                $workingCopy->add('.');
                $workingCopy->amend();
            }
        }

        $workingCopy->push('origin', 'master', true);

        return $workingCopy;
    }

    protected static function copyFixtureOverReinitializedRepo($dir, $fixture)
    {
        $fs = new Filesystem();
        $fs->mirror($fixture, $dir, null, ['override' => true, 'delete' => true]);
    }


    public function activityLogPath()
    {
        return $this->getConfig()->get('log.path');
    }

    public function getPath($project)
    {
        return $this->getConfig()->get("projects.$project.path");
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
            'FIXTURES' => $this->fixturesDir(),
            'PHPDOTNETFIXTURE' => $this->phpDotNetDir(),

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

    public function getFrameworkFixture($framework_name)
    {
        $fixtureTemplate = $this->getFixture("frameworks/$framework_name");
        $target = $this->mktmpdir();

        $fs = new Filesystem();
        $fs->mirror($fixtureTemplate, $target);

        // We do not want to store .git directories in our fixtures,
        // but we do want them for testing. Rather than `git init`,
        // just make a placeholder directory that we can test for.
        // Make a fake branch in case we want to track where this came from.
        mkdir("$target/.git");
        file_put_contents("$target/.git/HEAD", 'ref: refs/heads/' . $framework_name);

        return $target;
    }

    protected function fixturesDir()
    {
        return dirname(__DIR__) . '/fixtures';
    }

    protected function getFixture($name)
    {
        return $this->fixturesDir() . '/' . $name;
    }

    protected function phpCookbookFixture()
    {
        return $this->getFixture('/php-cookbook');
    }

    protected function homeDir()
    {
        return $this->getFixture('/home');
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
        if (!$this->phpDotNetDir) {
            $this->phpDotNetDir = $this->mktmpdir() . '/php.net';
        }
        return $this->phpDotNetDir;
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
