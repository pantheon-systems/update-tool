<?php

namespace Updatinate;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class FixturesTest extends TestCase
{
    /** @var Fixtures */
    protected $fixtures;

    public function __construct()
    {
        $this->fixtures = new Fixtures();
    }

    /**
     * Make sure that at least some of our assumptions are correct (test our testbed).
     */
    public function testFixtureAssumptions()
    {
        // Ensure that our 'next' function works.
        $this->assertEquals(Fixtures::PHP_53_CURRENT, '5.3.29');
        $this->assertEquals($this->fixtures->next(Fixtures::PHP_53_CURRENT), '5.3.30');

        // Ensure that our php.net fixture builder drops files in the right location
        $this->fixtures->setupPhpDotNetFixture([ Fixtures::PHP_55_CURRENT, Fixtures::PHP_56_CURRENT ]);
        $this->assertFileExists($this->fixtures->phpDotNetDir() . '/distributions/php-5.5.38.tar.gz');
        $this->assertFileExists($this->fixtures->phpDotNetDir() . '/distributions/php-5.6.37.tar.gz');

        // Ensure that our php.net fixture builder does not leave anything behind when called again
        $this->fixtures->setupPhpDotNetFixture([ Fixtures::PHP_71_CURRENT, Fixtures::PHP_72_CURRENT ]);
        $this->assertFileExists($this->fixtures->phpDotNetDir() . '/distributions/php-7.1.20.tar.gz');
        $this->assertFileExists($this->fixtures->phpDotNetDir() . '/distributions/php-7.2.8.tar.gz');
        $this->assertFileNotExists($this->fixtures->phpDotNetDir() . '/distributions/php-5.5.38.tar.gz');
        $this->assertFileNotExists($this->fixtures->phpDotNetDir() . '/distributions/php-5.6.37.tar.gz');
    }

}
