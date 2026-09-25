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
        $this->assertSame('oauth_device', $result['credential']['authMethod']);
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

    public function testRenewingAnExistingCredentialPreservesIdAndName(): void
    {
        $responses = [
            ['device_code' => 'device-secret-1', 'user_code' => 'ABCD-EFGH', 'verification_uri' => 'https://github.com/login/device', 'expires_in' => 900, 'interval' => 5],
            ['access_token' => 'first-token', 'token_type' => 'bearer', 'scope' => 'read:packages'],
            ['login' => 'octocat'],
        ];
        $auth = new GitHubDeviceAuth('client-id', null, static function () use (&$responses): array {
            return array_shift($responses);
        });
        $original = $auth->poll($auth->start()['state'])['credential'];
        $vault = new \CredentialVault();
        $vault->saveCredential(array_merge($original, ['id' => $original['id'], 'name' => 'My Renamed Credential', 'secret' => 'first-token']));

        $responses = [
            ['device_code' => 'device-secret-2', 'user_code' => 'IJKL-MNOP', 'verification_uri' => 'https://github.com/login/device', 'expires_in' => 900, 'interval' => 5],
            ['access_token' => 'renewed-token', 'token_type' => 'bearer', 'scope' => 'read:packages'],
            ['login' => 'octocat'],
        ];
        $device = $auth->start($original['id']);
        $result = $auth->poll($device['state']);

        $this->assertSame('success', $result['status']);
        $this->assertSame($original['id'], $result['credential']['id']);
        $this->assertSame('My Renamed Credential', $result['credential']['name']);
        $this->assertCount(1, $vault->listCredentials());
    }

    public function testCannotRenewCredentialFromAnotherProvider(): void
    {
        $vault = new \CredentialVault();
        $credential = $vault->saveCredential([
            'name' => 'Docker Hub', 'provider' => 'docker', 'registry' => 'docker.io',
            'username' => 'user', 'secret' => 'token',
        ]);
        $auth = new GitHubDeviceAuth('client-id', $vault, static function (): array {
            throw new \RuntimeException('GitHub must not be contacted.');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only GitHub OAuth credentials can be renewed');
        $auth->start($credential['id']);
    }
}