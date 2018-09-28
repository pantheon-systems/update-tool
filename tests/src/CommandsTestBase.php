<?php

namespace Updatinate;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandsTestBase extends TestCase implements CommandTesterInterface
{
    use CommandTesterTrait;

    /** @var Fixtures */
    protected $fixtures;

    public function __construct()
    {
        $this->fixtures = new Fixtures();
    }

    public function assertCommand($expectedOutput, $expectedStatus, $variable_args)
    {
        // Create our argv array and run the command
        $argv = $this->argv(func_get_args());
        print "\n=========================\n";
        print implode(' ', $argv);
        print "\n=========================\n";
        list($actualOutput, $statusCode) = $this->execute($argv, $this->commandClasses, $this->fixtures->configurationFile());

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
