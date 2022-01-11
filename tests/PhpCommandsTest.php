<?php

namespace UpdateTool;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class PhpCommandsTest extends TestCase implements CommandTesterInterface
{
    use CommandTesterTrait;

    /** @var Fixtures */
    protected $fixtures;

    public function fixtures()
    {
        if (!$this->fixtures) {
            $commandClasses = [
                \UpdateTool\Cli\PhpCommands::class,
                \UpdateTool\Cli\TestUtilCommands::class,
                \Hubph\Cli\HubphCommands::class,
            ];

            $this->fixtures = new Fixtures();
            $this->setupCommandTester('TestFixtureApp', '1.0.1', $commandClasses, $this->fixtures()->configurationFile());
        }
        return $this->fixtures;
    }

    /**
     * Prepare to test our commandfile
     */
    public function setUp()
    {
        $this->fixtures();
    }

    public function tearDown()
    {
        $this->fixtures()->cleanup();
    }

    public function assertCommand($expectedOutput, $expectedStatus, $variable_args)
    {
        // Create our argv array and run the command
        $argv = $this->argv(func_get_args());
        print "\n=========================\n";
        print implode(' ', $argv);
        print "\n=========================\n";
        list($actualOutput, $statusCode) = $this->execute($argv, $this->commandClasses, $this->fixtures()->configurationFile());

        // Confirm that our output and status code match expectations
        if (empty($expectedOutput)) {
            $this->assertEquals('', $actualOutput);
        } else {
            foreach ((array)$expectedOutput as $expected) {
                $this->assertContains($expected, $actualOutput);
            }
        }
        $this->assertEquals($expectedStatus, $statusCode);
    }

}
