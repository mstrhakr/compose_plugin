<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use GitHubDeviceAuth;
use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitHubDeviceAuth.php';

final class GitHubDeviceAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(COMPOSE_CREDENTIAL_VAULT_FILE);
        @unlink(COMPOSE_CREDENTIAL_KEY_FILE);
        foreach (glob(COMPOSE_GITHUB_DEVICE_DIR . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function testDeviceFlowCreatesGitHubCredentialWithoutExposingToken(): void
    {
        $responses = [
            ['device_code' => 'device-secret', 'user_code' => 'ABCD-EFGH', 'verification_uri' => 'https://github.com/login/device', 'expires_in' => 900, 'interval' => 5],
            ['access_token' => 'github-access-token', 'token_type' => 'bearer', 'scope' => 'read:packages'],
            ['login' => 'octocat'],
        ];
        $request = static function () use (&$responses): array {
            return array_shift($responses);
        };
        $auth = new GitHubDeviceAuth('client-id', null, $request);

        $device = $auth->start();
        $result = $auth->poll($device['state']);

        $this->assertSame('success', $result['status']);
        $this->assertSame('GitHub - octocat', $result['credential']['name']);
        $this->assertArrayNotHasKey('secret', $result['credential']);
        $this->assertStringNotContainsString('github-access-token', (string) file_get_contents(COMPOSE_CREDENTIAL_VAULT_FILE));
    }

    public function testPendingAuthorizationCanBePolledAgain(): void
    {
        $responses = [
            ['device_code' => 'device-secret', 'user_code' => 'ABCD-EFGH', 'verification_uri' => 'https://github.com/login/device', 'expires_in' => 900, 'interval' => 5],
            ['error' => 'authorization_pending'],
        ];
        $auth = new GitHubDeviceAuth('client-id', null, static function () use (&$responses): array {
            return array_shift($responses);
        });
        $device = $auth->start();

        $this->assertSame('pending', $auth->poll($device['state'])['status']);
    }
}