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
}