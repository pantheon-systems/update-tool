<?php

namespace UpdateTool;

use Symfony\Component\Console\Output\BufferedOutput;

trait CommandTesterTrait
{
    /** @var string */
    protected $appName;

    /** @var string */
    protected $appVersion;

    /** @var string[] */
    protected $commandClasses;

    /** @var string|string[] */
    protected $configurationFile;

    /**
     * Prepare to test
     */
    public function setupCommandTester($appName, $appVersion, $commandClasses = null, $configurationFile = null)
    {
        // Define our invariants for our test
        $this->appName = $appName;
        $this->appVersion = $appVersion;
        $this->commandClasses = $commandClasses;
        $this->configurationFile = $configurationFile;
    }

    /**
     * Prepare our $argv array; put the app name in $argv[0] followed by
     * the command name and all command arguments and options.
     *
     * @param array $functionParameters should usually be func_get_args()
     * @param int $leadingParameterCount the number of function parameters
     *   that are NOT part of argv. Default is 2 (expected content and
     *   expected status code).
     */
    protected function argv($functionParameters, $leadingParameterCount = 2)
    {
        $argv = $functionParameters;
        $argv = array_slice($argv, $leadingParameterCount);

        return $argv;
    }

    /**
     * Simulated front controller
     */
    protected function execute($argv, $commandClasses = null, $configurationFile = false)
    {
        // Define a global output object to capture the test results
        $output = new BufferedOutput();
        array_unshift($argv, $this->appName);

        // We can only call `Runner::execute()` once; then we need to tear down.
        $runner = new \Robo\Runner($commandClasses ?: $this->commandClasses);
        $runner->setEnvConfigPrefix('TEST_OVERRIDE');

        if ($configurationFile || $this->configurationFile) {
            $runner->setConfigurationFilename($configurationFile ?: $this->configurationFile);
        }
        $statusCode = $runner->execute($argv, $this->appName, $this->appVersion, $output);

        // Destroy our container so that we can call $runner->execute() again for the next test.
        \Robo\Robo::unsetContainer();

        // Return the output and status code.
        return [trim($output->fetch()), $statusCode];
    }

    protected function executeExpectOK($argv, $commandClasses = null, $configurationFile = false)
    {
        list($output, $status) = $this->execute($argv, $commandClasses, $configurationFile);
        $this->assertEquals(0, $status, implode(' ', $argv) . "\n----\n$output\n");
        return $output;
    }

    protected function executeExpectError($argv, $commandClasses = null, $configurationFile = false)
    {
        list($output, $status) = $this->execute($argv, $commandClasses, $configurationFile);
        $this->assertNotEquals(0, $status);
        return $output;
    }
}
