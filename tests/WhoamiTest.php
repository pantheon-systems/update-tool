<?php

namespace UpdateTool;

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
                \UpdateTool\Cli\PhpCommands::class,
                \UpdateTool\Hubph\Cli\HubphCommands::class,
            ];

            $this->fixtures = new Fixtures();
            $this->setupCommandTester('TestFixtureApp', '1.0.1', $commandClasses, $this->fixtures()->configurationFile());
        }
        return $this->fixtures;
    }

    public function setUp(): void
    {
        $this->fixtures();
    }

    public function tearDown(): void
    {
    }

    /**
     * Sanity-check: who are we authenticated as
     */
    public function testWhoami()
    {
        // Assert only that auth succeeded and produced a login, not a specific
        // user (the CI token owner can change).
        $output = $this->executeExpectOK(['whoami']);
        $this->assertMatchesRegularExpression('/Authenticated as \S+/', $output);
    }
}
