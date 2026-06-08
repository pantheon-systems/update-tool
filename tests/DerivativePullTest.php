<?php

namespace UpdateTool;

class DerivativePullTest extends CommandsTestBase
{
    use CommandTesterTrait;

    /** @var Fixtures */
    protected $fixtures;

    // The source fixture and its known tag/branch state.
    const SOURCE_PROJECT = 'wp';
    const SOURCE_BRANCH = 'default';
    const SOURCE_TAG = '4.9.8';

    // The derivative fixture project key in test-configuration.yml.
    const DERIVATIVE_PROJECT = 'wordpress-network-fixture';

    public function fixtures()
    {
        return $this->fixtures;
    }

    public function setUp()
    {
        $commandClasses = [
            \UpdateTool\Cli\ProjectCommands::class,
            \Hubph\Cli\HubphCommands::class,
        ];

        $this->fixtures = new Fixtures();
        $this->setupCommandTester('TestFixtureApp', '1.0.1', $commandClasses, $this->fixtures->configurationFile());
    }

    public function tearDown()
    {
        $this->fixtures()->deleteDerivativeFixture(self::DERIVATIVE_PROJECT);
        $this->fixtures()->cleanup();
    }

    /**
     * When the derivative already has all tags from the source, derivative:pull
     * should still sync the main branch — it must not exit early.
     */
    public function testDerivativePullSyncsBranchWithNoNewTags()
    {
        // Create the fixture with the same tag the source has, so there are no
        // new tags to process.
        $this->fixtures()->createDerivativeFixture(
            self::DERIVATIVE_PROJECT,
            self::SOURCE_PROJECT,
            self::SOURCE_BRANCH,
            [self::SOURCE_TAG]
        );

        // Give GitHub a moment to settle.
        sleep(3);

        $configFile = $this->fixtures()->seededConfigurationFile(self::DERIVATIVE_PROJECT);
        $output = $this->executeExpectOK(['project:derivative:pull', self::DERIVATIVE_PROJECT], null, $configFile);

        $this->assertContains('No new version tags to sync.', $output);
        $this->assertContains('Push branch master to', $output);
        $this->assertNotContains('Everything is up-to-date.', $output);
    }

    /**
     * When the derivative is missing tags that exist in the source, derivative:pull
     * should push the missing tags AND still sync the main branch afterward.
     */
    public function testDerivativePullSyncsTagsAndBranch()
    {
        // Create the fixture with no tags so the source tag is "new" to it.
        $this->fixtures()->createDerivativeFixture(
            self::DERIVATIVE_PROJECT,
            self::SOURCE_PROJECT,
            self::SOURCE_BRANCH,
            []
        );

        // Give GitHub a moment to settle.
        sleep(3);

        $configFile = $this->fixtures()->seededConfigurationFile(self::DERIVATIVE_PROJECT);
        $output = $this->executeExpectOK(['project:derivative:pull', self::DERIVATIVE_PROJECT], null, $configFile);

        $this->assertContains('Push tag ' . self::SOURCE_TAG . ' to', $output);
        $this->assertContains('Push branch master to', $output);
        $this->assertNotContains('Everything is up-to-date.', $output);
    }
}
