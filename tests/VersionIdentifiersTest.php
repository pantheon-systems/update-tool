<?php

namespace UpdateTool;

use PHPUnit\Framework\TestCase;
use UpdateTool\Hubph\VersionIdentifiers;

class VersionIdentifiersTest extends TestCase
{
    public function testIsEmptyOnNew()
    {
        $vids = new VersionIdentifiers();
        $this->assertTrue($vids->isEmpty());
    }

    public function testAddMakesNotEmpty()
    {
        $vids = new VersionIdentifiers();
        $vids->add('WordPress ', '6.5.0');
        $this->assertFalse($vids->isEmpty());
    }

    public function testAll()
    {
        $vids = new VersionIdentifiers();
        $vids->add('WordPress ', '6.5.0');
        $vids->add('Drupal ', '10.3.1');

        $this->assertEquals(['WordPress 6.5.0', 'Drupal 10.3.1'], $vids->all());
    }

    public function testIds()
    {
        $vids = new VersionIdentifiers();
        $vids->add('WordPress ', '6.5.0');
        $vids->add('Drupal ', '10.3.1');

        $this->assertEquals(['WordPress ', 'Drupal '], $vids->ids());
    }

    public function testToStringWithSingleItem()
    {
        $vids = new VersionIdentifiers();
        $vids->add('WordPress ', '6.5.0');

        $this->assertEquals('WordPress 6.5.0', (string) $vids);
    }

    public function testToStringWithMultipleItems()
    {
        $vids = new VersionIdentifiers();
        $vids->add('WordPress ', '6.5.0');
        $vids->add('Drupal ', '10.3.1');

        $this->assertEquals('WordPress 6.5.0, and Drupal 10.3.1', (string) $vids);
    }

    public function testToStringWhenEmpty()
    {
        $vids = new VersionIdentifiers();
        $this->assertEquals('', (string) $vids);
    }

    public function testPreamble()
    {
        $vids = new VersionIdentifiers();
        $this->assertEquals('Update to ', $vids->getPreamble());

        $vids->setPreamble('Upgrade to ');
        $this->assertEquals('Upgrade to ', $vids->getPreamble());
    }

    public function testVidPattern()
    {
        $vids = new VersionIdentifiers();
        $this->assertEquals(VersionIdentifiers::DEFAULT_VID, $vids->getVidPattern());

        $vids->setVidPattern('WordPress ');
        $this->assertEquals('WordPress ', $vids->getVidPattern());
    }

    public function testVvalPattern()
    {
        $vids = new VersionIdentifiers();
        $this->assertEquals(VersionIdentifiers::DEFAULT_VVAL, $vids->getVvalPattern());

        $vids->setVvalPattern('#.#');
        $this->assertEquals('#.#', $vids->getVvalPattern());
    }

    public function testAddVidsFromMessage()
    {
        $vids = new VersionIdentifiers();
        $vids->addVidsFromMessage('Update to WordPress 6.5.3');

        $all = $vids->all();
        $this->assertCount(1, $all);
        $this->assertStringContainsString('6.5.3', $all[0]);
    }

    public function testAddVidsFromMessageWithMultipleVersions()
    {
        $vids = new VersionIdentifiers();
        $vids->addVidsFromMessage('Update to WordPress 6.5.3 and Drupal 10.3.1');

        $all = $vids->all();
        $this->assertCount(2, $all);
    }

    public function testAddVidsFromMessageThrowsOnNoMatch()
    {
        $vids = new VersionIdentifiers();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not contain a semver release identifier');
        $vids->addVidsFromMessage('No version here');
    }

    public function testAllExist()
    {
        $vids = new VersionIdentifiers();
        $vids->add('WordPress ', '6.5.3');

        $titles = ['Update to WordPress 6.5.3', 'Other PR'];
        $this->assertTrue($vids->allExist($titles));
    }

    public function testAllExistReturnsFalseWhenMissing()
    {
        $vids = new VersionIdentifiers();
        $vids->add('WordPress ', '6.5.3');

        $titles = ['Update to Drupal 10.3.1'];
        $this->assertFalse($vids->allExist($titles));
    }

    public function testAllExistReturnsFalseWhenEmpty()
    {
        $vids = new VersionIdentifiers();
        $this->assertFalse($vids->allExist(['anything']));
    }

    public function testAllExistWithMultipleVids()
    {
        $vids = new VersionIdentifiers();
        $vids->add('WordPress ', '6.5.3');
        $vids->add('Drupal ', '10.3.1');

        $titles = ['Update to WordPress 6.5.3', 'Update to Drupal 10.3.1'];
        $this->assertTrue($vids->allExist($titles));
    }

    public function testAllExistFailsIfOnlyPartialMatch()
    {
        $vids = new VersionIdentifiers();
        $vids->add('WordPress ', '6.5.3');
        $vids->add('Drupal ', '10.3.1');

        $titles = ['Update to WordPress 6.5.3'];
        $this->assertFalse($vids->allExist($titles));
    }

    public function testPatternMatchesSemver()
    {
        $vids = new VersionIdentifiers();
        $pattern = $vids->pattern();

        $this->assertMatchesRegularExpression("#{$pattern}#", 'WordPress 6.5.3');
        $this->assertMatchesRegularExpression("#{$pattern}#", 'Drupal 10.3.1');
        $this->assertMatchesRegularExpression("#{$pattern}#", 'myproject-1.2.3');
    }

    public function testPatternMatchesBetaVersions()
    {
        $vids = new VersionIdentifiers();
        $pattern = $vids->pattern();

        $this->assertMatchesRegularExpression("#{$pattern}#", 'WordPress 6.5.3-beta1');
        $this->assertMatchesRegularExpression("#{$pattern}#", 'Drupal 10.3.1-RC2');
    }
}
