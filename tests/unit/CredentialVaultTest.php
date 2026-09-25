<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use CredentialVault;
use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/CredentialVault.php';

final class CredentialVaultTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(COMPOSE_CREDENTIAL_VAULT_FILE);
        @unlink(COMPOSE_CREDENTIAL_KEY_FILE);
        if (is_dir(COMPOSE_DOCKER_CONFIG_DIR)) {
            foreach (glob(COMPOSE_DOCKER_CONFIG_DIR . '/*') ?: [] as $directory) {
                CredentialVault::removeDockerConfig($directory);
            }
            @rmdir(COMPOSE_DOCKER_CONFIG_DIR);
        }
    }

    public function testStoresEncryptedCredentialAndOmitsSecretFromList(): void
    {
        $vault = new CredentialVault();
        $saved = $vault->saveCredential([
            'name' => 'Work GitHub',
            'provider' => 'github',
            'registry' => 'https://ghcr.io/',
            'username' => 'octocat',
            'secret' => 'github-secret-token',
        ]);

        $this->assertSame('ghcr.io', $saved['registry']);
        $this->assertArrayNotHasKey('secret', $saved);
        $vaultPayload = (string) file_get_contents(COMPOSE_CREDENTIAL_VAULT_FILE);
        $this->assertStringNotContainsString('github-secret-token', $vaultPayload);
        $this->assertSame('aes-256-gcm', json_decode($vaultPayload, true)['algorithm']);
        $this->assertArrayNotHasKey('secret', $vault->listCredentials()[0]);
    }

    public function testMaterializesMinimalDockerConfig(): void
    {
        $vault = new CredentialVault();
        $saved = $vault->saveCredential([
            'name' => 'Docker Hub',
            'provider' => 'docker',
            'registry' => 'docker.io',
            'username' => 'user',
            'secret' => 'token',
        ]);

        $directory = $vault->materializeDockerConfig($saved['id']);
        $config = json_decode((string) file_get_contents($directory . '/config.json'), true);
        $this->assertSame(base64_encode('user:token'), $config['auths']['https://index.docker.io/v1/']['auth']);
        $this->assertCount(1, $config);

        CredentialVault::removeDockerConfig($directory);
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function testUpdateWithoutSecretPreservesExistingToken(): void
    {
        $vault = new CredentialVault();
        $saved = $vault->saveCredential([
            'name' => 'GitHub', 'provider' => 'github', 'registry' => 'ghcr.io',
            'username' => 'user', 'secret' => 'token',
        ]);
        $saved['name'] = 'Renamed GitHub';
        $updated = $vault->saveCredential($saved);

        $directory = $vault->materializeDockerConfig($updated['id']);
        $config = json_decode((string) file_get_contents($directory . '/config.json'), true);
        $this->assertSame(base64_encode('user:token'), $config['auths']['ghcr.io']['auth']);
    }

    public function testStaleSweepPreservesConfigUsedByComposeProcess(): void
    {
        $baseDir = COMPOSE_DOCKER_CONFIG_DIR;
        mkdir($baseDir, 0700, true);
        $activeDirectory = $baseDir . '/active';
        mkdir($activeDirectory, 0700);
        file_put_contents($activeDirectory . '/config.json', '{}');
        touch($activeDirectory, time() - 7200);
        $process = proc_open(
            [PHP_BINARY, '-r', 'usleep(5000000);'],
            [],
            $pipes,
            null,
            ['DOCKER_CONFIG' => $activeDirectory]
        );
        $this->assertIsResource($process);

        $vault = new CredentialVault();
        $saved = $vault->saveCredential([
            'name' => 'GitHub', 'provider' => 'github', 'registry' => 'ghcr.io',
            'username' => 'user', 'secret' => 'token',
        ]);
        $vault->materializeDockerConfig($saved['id']);

        $this->assertDirectoryExists($activeDirectory);
        proc_terminate($process);
        proc_close($process);
    }

    public function testSupportsAllStandardProviderPresets(): void
    {
        $vault = new CredentialVault();
        $providers = ['github', 'docker', 'gitlab', 'quay', 'aws', 'azure', 'gcr', 'generic'];
        foreach ($providers as $provider) {
            $saved = $vault->saveCredential([
                'name' => 'Provider ' . $provider,
                'provider' => $provider,
                'registry' => 'registry.example.com',
                'username' => 'user',
                'secret' => 'secret123',
            ]);
            $this->assertSame($provider, $saved['provider']);
        }
    }

    public function testRejectsUnsupportedProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported credential provider.');

        $vault = new CredentialVault();
        $vault->saveCredential([
            'name' => 'Invalid Provider',
            'provider' => 'unsupported_vendor',
            'registry' => 'registry.example.com',
            'username' => 'user',
            'secret' => 'secret123',
        ]);
    }

    public function testGetCredentialSummaryOmitsSecret(): void
    {
        $vault = new CredentialVault();
        $saved = $vault->saveCredential([
            'name' => 'Work GitHub',
            'provider' => 'github',
            'registry' => 'ghcr.io',
            'username' => 'octocat',
            'secret' => 'github-secret-token',
        ]);

        $summary = $vault->getCredentialSummary($saved['id']);
        $this->assertSame('Work GitHub', $summary['name']);
        $this->assertSame('ghcr.io', $summary['registry']);
        $this->assertArrayNotHasKey('secret', $summary);
    }
}