<?php

namespace UpdateTool;

use PHPUnit\Framework\TestCase;
use Consolidation\Config\Config;
use UpdateTool\Hubph\HubphAPI;

class HubphAPITest extends TestCase
{
    protected function createApiWithToken($token)
    {
        $config = new Config([
            'github' => [
                'default-user' => 'default',
                'personal-auth-token' => [
                    'default' => [
                        'path' => '/nonexistent/path',
                    ],
                ],
            ],
        ]);

        putenv("DEFAULT_TOKEN=$token");
        $api = new HubphAPI($config);
        return $api;
    }

    /** @var array<string,string|false> */
    private $envBackup = [];

    protected function setUp(): void
    {
        // Snapshot the env vars these tests mutate so tearDown can restore them.
        // Unsetting GITHUB_TOKEN unconditionally would strip the ambient CI token
        // from the process and 401 the integration tests that run afterwards.
        foreach (['GITHUB_TOKEN', 'DEFAULT_TOKEN', 'MYBOT_TOKEN'] as $k) {
            $this->envBackup[$k] = getenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            $v === false ? putenv($k) : putenv("$k=$v");
        }
    }

    public function testAddTokenAuthenticationToSshUrl()
    {
        putenv('GITHUB_TOKEN=test-token-123');
        $config = new Config([]);
        $api = new HubphAPI($config);

        $result = $api->addTokenAuthentication('git@github.com:org/repo.git');
        $this->assertEquals('https://x-access-token:test-token-123@github.com/org/repo.git', $result);
    }

    public function testAddTokenAuthenticationToHttpsUrl()
    {
        putenv('GITHUB_TOKEN=test-token-123');
        $config = new Config([]);
        $api = new HubphAPI($config);

        $result = $api->addTokenAuthentication('https://github.com/org/repo.git');
        $this->assertEquals('https://x-access-token:test-token-123@github.com/org/repo.git', $result);
    }

    public function testAddTokenAuthenticationSkipsNonGitHubUrls()
    {
        putenv('GITHUB_TOKEN=test-token-123');
        $config = new Config([]);
        $api = new HubphAPI($config);

        $result = $api->addTokenAuthentication('git@gitlab.com:org/repo.git');
        $this->assertEquals('git@gitlab.com:org/repo.git', $result);
    }

    public function testAddTokenAuthenticationReturnsUrlWhenNoToken()
    {
        putenv('GITHUB_TOKEN=');
        $config = new Config([
            'github' => [
                'default-user' => 'default',
                'personal-auth-token' => [
                    'default' => [
                        'path' => '/nonexistent/path',
                    ],
                ],
            ],
        ]);
        $api = new HubphAPI($config);

        $result = $api->addTokenAuthentication('git@github.com:org/repo.git');
        $this->assertEquals('git@github.com:org/repo.git', $result);
    }

    public function testSetAsResetsTokenAndClient()
    {
        putenv('GITHUB_TOKEN=token-1');
        $config = new Config([]);
        $api = new HubphAPI($config);

        $token1 = $api->gitHubToken();
        $this->assertEquals('token-1', $token1);

        putenv('GITHUB_TOKEN=token-2');
        $api->setAs('other-user');
        $token2 = $api->gitHubToken();
        $this->assertEquals('token-2', $token2);
    }

    public function testSetAsSameUserDoesNotReset()
    {
        putenv('GITHUB_TOKEN=token-1');
        $config = new Config([]);
        $api = new HubphAPI($config);

        $token1 = $api->gitHubToken();
        $this->assertEquals('token-1', $token1);

        putenv('GITHUB_TOKEN=token-2');
        $api->setAs('default');
        $token2 = $api->gitHubToken();
        // Token should NOT have been reset since 'default' is the same as current
        $this->assertEquals('token-1', $token2);
    }

    public function testGitHubTokenFromEnvVar()
    {
        putenv('GITHUB_TOKEN=env-token-abc');
        $config = new Config([]);
        $api = new HubphAPI($config);

        $this->assertEquals('env-token-abc', $api->gitHubToken());
    }

    public function testGitHubTokenFromNamedEnvVar()
    {
        putenv('GITHUB_TOKEN=');
        putenv('MYBOT_TOKEN=named-token-xyz');
        $config = new Config([
            'github' => [
                'default-user' => 'mybot',
                'personal-auth-token' => [
                    'mybot' => [
                        'path' => '/nonexistent/path',
                    ],
                ],
            ],
        ]);
        $api = new HubphAPI($config);

        $this->assertEquals('named-token-xyz', $api->gitHubToken());
        putenv('MYBOT_TOKEN');
    }

    public function testGitHubTokenFromFile()
    {
        putenv('GITHUB_TOKEN=');
        $tmpFile = tempnam(sys_get_temp_dir(), 'token');
        file_put_contents($tmpFile, "file-token-456\n");

        $config = new Config([
            'github' => [
                'default-user' => 'default',
                'personal-auth-token' => [
                    'default' => [
                        'path' => $tmpFile,
                    ],
                ],
            ],
        ]);
        $api = new HubphAPI($config);

        $this->assertEquals('file-token-456', $api->gitHubToken());
        unlink($tmpFile);
    }

    public function testStartAndStopLogging()
    {
        putenv('GITHUB_TOKEN=test-token');
        $config = new Config([]);
        $api = new HubphAPI($config);

        $tmpFile = tempnam(sys_get_temp_dir(), 'log');
        $api->startLogging($tmpFile);
        $api->stopLogging();

        // Should not throw; logging starts and stops cleanly
        $this->assertTrue(true);
        unlink($tmpFile);
    }

    public function testStopLoggingWhenNotStarted()
    {
        $config = new Config([]);
        $api = new HubphAPI($config);
        $api->stopLogging();

        // Should not throw
        $this->assertTrue(true);
    }
}
