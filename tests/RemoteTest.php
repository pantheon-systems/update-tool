<?php

namespace UpdateTool;

use PHPUnit\Framework\TestCase;
use UpdateTool\Git\Remote;

class RemoteTest extends TestCase
{
    public function testProjectWithOrgFromHttpsUrl()
    {
        $remote = new Remote('https://github.com/pantheon-systems/update-tool.git');
        $this->assertEquals('pantheon-systems/update-tool', $remote->projectWithOrg());
    }

    public function testProjectWithOrgFromSshUrl()
    {
        $remote = new Remote('git@github.com:pantheon-systems/update-tool.git');
        $this->assertEquals('pantheon-systems/update-tool', $remote->projectWithOrg());
    }

    public function testProjectWithOrgFromAuthenticatedUrl()
    {
        $remote = new Remote('https://ghp_abc123:x-oauth-basic@github.com/pantheon-systems/update-tool.git');
        $this->assertEquals('pantheon-systems/update-tool', $remote->projectWithOrg());
    }

    public function testProjectWithOrgFromUrlWithoutGitSuffix()
    {
        $remote = new Remote('https://github.com/pantheon-systems/update-tool');
        $this->assertEquals('pantheon-systems/update-tool', $remote->projectWithOrg());
    }

    public function testOrg()
    {
        $remote = new Remote('git@github.com:pantheon-systems/update-tool.git');
        $this->assertEquals('pantheon-systems', $remote->org());
    }

    public function testProject()
    {
        $remote = new Remote('git@github.com:pantheon-systems/update-tool.git');
        $this->assertEquals('update-tool', $remote->project());
    }

    public function testHostFromSshUrl()
    {
        $remote = new Remote('git@github.com:pantheon-systems/update-tool.git');
        $this->assertEquals('github.com', $remote->host());
    }

    public function testHostFromHttpsUrl()
    {
        $remote = new Remote('https://token:x-oauth-basic@github.com/org/repo.git');
        $this->assertEquals('github.com', $remote->host());
    }

    public function testHostReturnsFalseForInvalidUrl()
    {
        $remote = new Remote('not-a-url');
        $this->assertFalse($remote->host());
    }

    public function testValid()
    {
        $remote = new Remote('git@github.com:org/repo.git');
        $this->assertTrue($remote->valid());
    }

    public function testValidReturnsFalseForEmptyRemote()
    {
        $remote = new Remote('');
        $this->assertFalse($remote->valid());
    }

    public function testUrl()
    {
        $url = 'git@github.com:pantheon-systems/update-tool.git';
        $remote = new Remote($url);
        $this->assertEquals($url, $remote->url());
    }

    public function testToString()
    {
        $remote = new Remote('https://token:x-oauth-basic@github.com/pantheon-systems/update-tool.git');
        $this->assertEquals('git@github.com:pantheon-systems/update-tool.git', (string) $remote);
    }

    public function testToStringFromSshUrl()
    {
        $remote = new Remote('git@github.com:pantheon-systems/update-tool.git');
        $this->assertEquals('git@github.com:pantheon-systems/update-tool.git', (string) $remote);
    }

    public function testProjectWithOrgFromUrlStatic()
    {
        $this->assertEquals(
            'org/project',
            Remote::projectWithOrgFromUrl('git@github.com:org/project.git')
        );
        $this->assertEquals(
            'org/project',
            Remote::projectWithOrgFromUrl('https://github.com/org/project.git')
        );
        $this->assertEquals(
            'org/project',
            Remote::projectWithOrgFromUrl('https://token@github.com/org/project')
        );
    }

    public function testAddAuthenticationWithApi()
    {
        $api = $this->createMock(\UpdateTool\Hubph\HubphAPI::class);
        $api->method('addTokenAuthentication')
            ->with('git@github.com:org/repo.git')
            ->willReturn('https://x-access-token:tok@github.com/org/repo.git');

        $remote = new Remote('git@github.com:org/repo.git');
        $remote->addAuthentication($api);
        $this->assertEquals('https://x-access-token:tok@github.com/org/repo.git', $remote->url());
    }

    public function testAddAuthenticationWithoutApiDoesNothing()
    {
        $remote = new Remote('git@github.com:org/repo.git');
        $remote->addAuthentication(null);
        $this->assertEquals('git@github.com:org/repo.git', $remote->url());
    }
}
