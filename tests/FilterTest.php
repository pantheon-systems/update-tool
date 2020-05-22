<?php

namespace Updatinate;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Updatinate\Update\Filters\RsyncFromSource;
use Updatinate\Update\Filters\RenameProject;

class FilterTest extends TestCase implements CommandTesterInterface
{
    /** @var Fixtures */
    protected $fixtures;

    public function fixtures()
    {
        if (!$this->fixtures) {
            $this->fixtures = new Fixtures();
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

    protected function assertProjectName($expected, $path)
    {
        $composer_json_path = $path . '/composer.json';
        $this->assertFileExists($composer_json_path);
        $composer_json = json_decode(file_get_contents($composer_json_path), true);
        $this->assertTrue(array_key_exists('name', $composer_json));
        $project_name = $composer_json['name'];
        $this->assertTrue(is_string($project_name));

        $this->assertEquals($expected, $project_name);
    }

    /**
     * Test that the rsync filter copies files, but leaves .git directory behind
     */
    public function testRenameProject()
    {
        $logger = $this->fixtures()->getLogger();
        $source = $this->fixtures()->mktmpdir();
        $target = $this->fixtures()->getFrameworkFixture('generic');
        $parameters = [
            'meta' => [
                'name' => 'new-org/target-project',
            ],
        ];

        $sut = new RenameProject();
        $sut->setLogger($logger);

        $this->assertProjectName('org/generic', $target);

        $sut->action($source, $target, $parameters);

        $this->assertProjectName('new-org/target-project', $target);
    }

    /**
     * Test that the rsync filter copies files, but leaves .git directory behind
     */
    public function testRsyncFromSource()
    {
        $logger = $this->fixtures()->getLogger();
        $target = $this->fixtures()->mktmpdir();
        $source = $this->fixtures()->getFrameworkFixture('generic');
        $parameters = [];

        $sut = new RsyncFromSource();
        $sut->setLogger($logger);

        $this->assertFileExists("$source/.git");

        $sut->action($source, $target, $parameters);

        $this->assertFileNotExists("$target/.git");
        $this->assertFileExists("$target/index.php");
    }
}
