<?php

namespace Updatinate;

use Symfony\Component\Console\Output\BufferedOutput;

class ProjectCommandsTest extends CommandsTestBase
{
    use CommandTesterTrait;

    /** @var Fixtures */
    protected $fixtures;

    public function fixtures()
    {
        return $this->fixtures;
    }

    /**
     * Prepare to test our commandfile
     */
    public function setUp()
    {
        $commandClasses = [
            \Updatinate\Cli\ProjectCommands::class,
            \Updatinate\Cli\TestUtilCommands::class,
            \Hubph\Cli\HubphCommands::class,
        ];

        $this->fixtures = new Fixtures();
        $this->setupCommandTester('TestFixtureApp', '1.0.1', $commandClasses, $this->fixtures->configurationFile());

        @unlink($this->fixtures()->activityLogPath());
    }

    public function tearDown()
    {
        $this->fixtures()->cleanup();
    }

    /**
     * Test to see if we can update drops-8 from 8.5.6 to 8.6.0 using a snapshot.
     */
    public function testDrops8Update()
    {
        // Closes any leftover PRs in the fixture repository.
        $this->fixtures()->closeAllOpenPullRequests('drops-8');

        // Create a fork
//        $drops8_repo = $this->fixtures()->forkTestRepo('drops-8');

        // Verify the latest releast in our drops-8 and drupal fixtures.
        $output = $this->executeExpectOK(['project:latest', 'drops-8']);
        $this->assertEquals('8.5.6', $output);
        $output = $this->executeExpectOK(['project:latest', 'drupal']);
        $this->assertEquals('8.6.0', $output);

        // Check to see if an update is expected in our fixture. (It always is.)
        $output = $this->executeExpectOK(['project:upstream:check', 'drops-8']);
        $this->assertContains('drops-8 8.5.6 has an available update: 8.6.0', $output);

        // TODO: Is there any reasonable test we can do on project:releases?

        // Check to see if we can compose a release node url for our fixtures
        $output = $this->executeExpectOK(['project:release-node', 'drops-8', '--format=string']);
        $this->assertEquals('https://www.drupal.org/project/drupal/releases/8.6.0', $output);
        $output = $this->executeExpectOK(['project:release-node', 'drupal', '--format=string']);
        $this->assertEquals('https://www.drupal.org/project/drupal/releases/8.6.0', $output);

        // Try to create an upstream update PR for our drops-8 fixture
        $output = $this->executeExpectOK(['project:upstream:update', 'drops-8']);
        $this->assertContains('Updating drops-8 from 8.5.6 to 8.6.0', $output);

        // Ensure that the PR that was created is logged
        $this->assertFileExists($this->fixtures()->activityLogPath());

        // Make sure github API has enough time to realize the PR has been created
        sleep(5);

        // Try to make another update; confirm that nothing is done
        $output = $this->executeExpectOK(['project:upstream:update', 'drops-8']);
        $this->assertContains('[notice] Pull request already exists for available update 8.6.0; nothing more to do.', $output);
    }

    /**
     * Test to see if we can update Pantheon's WordPress from 4.9.8 to 5.0.1
     * using a snapshot.
     */
    public function testWordPressUpdate()
    {
        // Closes any leftover PRs in the fixture repository.
        $this->fixtures()->closeAllOpenPullRequests('wp');

        // Create a fork
//        $wp_repo = $this->fixtures()->forkTestRepo('wp');

        // Verify the latest releast in our drops-8 and drupal fixtures.
        $output = $this->executeExpectOK(['project:latest', 'wp']);
        $this->assertEquals('4.9.8', $output);

        // Check to see if an update is expected in our fixture. (It always is.)
        $output = $this->executeExpectOK(['project:upstream:check', 'wp']);
        $this->assertContains('wp 4.9.8 has an available update: 5.0.1', $output);

        // Check to see if we can compose a release node url for our fixtures
        $output = $this->executeExpectOK(['project:release-node', 'wp', '--format=string']);
        $this->assertEquals('https://wordpress.org/news/2018/12/wordpress-5-0-1-security-release/', $output);

        // Try to create an upstream update PR for our drops-8 fixture
        $output = $this->executeExpectOK(['project:upstream:update', 'wp']);
        $this->assertContains('Updating wp from 4.9.8 to 5.0.1', $output);

        // Ensure that the PR that was created is logged
        $this->assertFileExists($this->fixtures()->activityLogPath());

        // Make sure github API has enough time to realize the PR has been created
        sleep(5);

        // Try to make another update; confirm that nothing is done
        $output = $this->executeExpectOK(['project:upstream:update', 'wp']);
        $this->assertContains('[notice] Pull request already exists for available update 5.0.1; nothing more to do.', $output);
    }
}
