<?php

namespace Updatinate;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class WhoamiTest extends TestCase implements CommandTesterInterface
{
    use CommandTesterTrait;

    /** @var Fixtures */
    protected $fixtures;

    public function fixtures()
    {
        if (!$this->fixtures) {
            $commandClasses = [
                \Updatinate\Cli\PhpCommands::class,
                \Updatinate\Cli\TestUtilCommands::class,
                \Hubph\Cli\HubphCommands::class,
            ];

            $this->fixtures = new Fixtures();
            $this->setupCommandTester('TestFixtureApp', '1.0.1', $commandClasses, $this->fixtures()->configurationFile());
        }
        return $this->fixtures;
    }

    public function setUp()
    {
        $this->fixtures();
    }

    public function tearDown()
    {
    }

    /**
     * Sanity-check: who are we authenticated as
     */
    public function testWhoami()
    {
        $output = $this->executeExpectOK(['whoami']);
        $this->assertContains('Authenticated as pantheon-ci-bot', $output);
    }
}
