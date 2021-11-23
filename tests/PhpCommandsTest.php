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
        // reinitialize / force-push rpmbuild-php-fixture and php-cookbook-fixture repositories
        $this->fixtures()->forceReinitializePhpFixtures();
    }

    public function tearDown()
    {
        $this->fixtures()->cleanup();
    }

    public function currentPhpVersions()
    {
        return [
            Fixtures::PHP_53_CURRENT,
            Fixtures::PHP_55_CURRENT,
            Fixtures::PHP_56_CURRENT,
            Fixtures::PHP_70_CURRENT,
            Fixtures::PHP_71_CURRENT,
            Fixtures::PHP_72_CURRENT
        ];
    }

    /**
     * Test our php update commands.
     */
    public function testPhpCommands()
    {
        $phpRpmWorkingCopy = $this->fixtures()->phpRpmWorkingCopy();
        $seed = $this->fixtures()->seed();

        // Step 1: No updates available. No pull requests opened.
        $available_php_versions = $this->currentPhpVersions();
        $this->fixtures()->setupPhpDotNetFixture($available_php_versions);
        $output = $this->executeExpectOK(['php:rpm:update', '--no-auto-merge']);
        $this->assertContains('5.3.29 is the most recent version', $output);
        $this->assertContains('5.5.38 is the most recent version', $output);
        $this->assertContains('5.6.37 is the most recent version', $output);
        $this->assertContains('7.0.31 is the most recent version', $output);
        $this->assertContains('7.1.20 is the most recent version', $output);
        $this->assertContains('7.2.8 is the most recent version', $output);
        $output = $this->executeExpectOK(['pr:list', 'pantheon-fixtures/rpmbuild-php-fixture', '--field=title']);
        $this->assertEquals('', $output);
        $message = $phpRpmWorkingCopy->message();
        $this->assertEquals('Initial fixture data', $message);

        // Step 2: Simulate a PHP 7.2 available update. Confirm that one pull request opened.
        $available_php_versions = array_merge($available_php_versions, [ $this->fixtures()->next(Fixtures::PHP_72_CURRENT) ]);
        $this->fixtures()->setupPhpDotNetFixture($available_php_versions);
        $output = $this->executeExpectOK(['php:rpm:update', '--no-auto-merge']);
        $this->assertContains("[notice] Executing git push origin php-$seed-7.2.9", $output);
        $output = $this->executeExpectOK(['pr:list', 'pantheon-fixtures/rpmbuild-php-fixture', '--field=title']);
        $expectedTitle = "[$seed] Update to php-7.2.9";
        $this->assertEquals($expectedTitle, $output);
        $message = $phpRpmWorkingCopy->message();
        $this->assertEquals($expectedTitle, $message);
        $diff = preg_replace('#  +#', ' ', $phpRpmWorkingCopy->show());
        $this->assertContains('+%define php_version 7.2.9', $diff);
        $this->assertContains('+++ b/php-7.2/php.spec', $diff);
        $this->assertNotContains('+++ b/php-7.1/php.spec', $diff);
        $this->assertNotContains('+++ b/php-7.0/php.spec', $diff);
        $this->assertNotContains('+++ b/php-5.6/php.spec', $diff);
        $expectedDiff = '+%define php_version 7.2.9';
        $diff = preg_replace('#  +#', ' ', $phpRpmWorkingCopy->show());
        $this->assertContains($expectedDiff, $diff);

        // Make sure github API has enough time to realize the PR has been created
        sleep(5);

        // Step 3: No change to available PHP versions. No action taken (PR stays open)
        $this->fixtures()->setupPhpDotNetFixture($available_php_versions);
        $output = $this->executeExpectOK(['php:rpm:update', '--no-auto-merge']);
        $this->assertContains('[notice] There is an existing pull request for this update; nothing else to do.', $output);
        $output = $this->executeExpectOK(['pr:list', 'pantheon-fixtures/rpmbuild-php-fixture', '--field=title']);
        $expectedTitle = "[$seed] Update to php-7.2.9";
        $this->assertEquals($expectedTitle, $output);
        $message = $phpRpmWorkingCopy->message();
        $this->assertEquals('Initial fixture data', $message);

        // Step 4: Now there are updates available for both 7.1 and 7.2.
        // A new PR is opened with the 7.1 and 7.2 change.
        // The old 7.2 PR is closed.
        $available_php_versions = array_merge($available_php_versions, [ $this->fixtures()->next(Fixtures::PHP_71_CURRENT) ]);
        $this->fixtures()->setupPhpDotNetFixture($available_php_versions);
        $output = $this->executeExpectOK(['php:rpm:update', '--no-auto-merge']);
        $this->assertContains("[notice] Executing git push origin php-$seed-7.1.21-7.2.9", $output);
        $output = $this->executeExpectOK(['pr:list', 'pantheon-fixtures/rpmbuild-php-fixture', '--field=title']);
        $expectedTitle = "[$seed] Update to php-7.1.21 and php-7.2.9";
        $this->assertEquals($expectedTitle, $output);
        $message = $phpRpmWorkingCopy->message();
        $this->assertEquals($expectedTitle, $message);
        $diff = preg_replace('#  +#', ' ', $phpRpmWorkingCopy->show());
        $this->assertContains('+%define php_version 7.2.9', $diff);
        $this->assertContains('+%define php_version 7.1.21', $diff);
        $this->assertContains('+++ b/php-7.2/php.spec', $diff);
        $this->assertContains('+++ b/php-7.1/php.spec', $diff);
        $this->assertNotContains('+++ b/php-7.0/php.spec', $diff);
        $this->assertNotContains('+++ b/php-5.6/php.spec', $diff);

        // Step 5: Finally we'll merge our PR. No tests to do here any more though.
        $phpRpmWorkingCopy
            ->switchBranch('master')
            ->merge("php-$seed-7.1.21-7.2.9")
            ->push('origin', 'master');
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
