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

    public function testEncryptionKeyIsCreatedWithRestrictivePermissions(): void
    {
        $vault = new CredentialVault();
        $vault->saveCredential([
            'name' => 'Work GitHub',
            'provider' => 'github',
            'registry' => 'ghcr.io',
            'username' => 'octocat',
            'secret' => 'github-secret-token',
        ]);

        $this->assertSame(0600, fileperms(COMPOSE_CREDENTIAL_KEY_FILE) & 0777);
        $this->assertSame([], glob(COMPOSE_CREDENTIAL_KEY_FILE . '.tmp-*') ?: []);
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

    public function testTheKindOfACredentialCannotChangeBetweenRegistryAndGit(): void
    {
        // A stack using it would break at its next run: refused, and the credential left as it was.
        $vault = new CredentialVault();
        $registry = $vault->saveCredential(['name' => 'Hub', 'provider' => 'docker', 'registry' => 'docker.io', 'username' => 'u', 'secret' => 's']);
        $token = $vault->saveCredential(['name' => 'Forgejo', 'provider' => 'git', 'registry' => 'git.example.com', 'username' => 'u', 'secret' => 's']);
        $key = $vault->saveCredential(['name' => 'app deploy key', 'provider' => 'git-ssh', 'registry' => 'git.example.com', 'username' => 'git', 'secret' => 'k']);

        $changes = [
            [$registry, 'git', 'git.example.com'],
            [$token, 'github', 'ghcr.io'],
            [$token, 'git-ssh', 'git.example.com'],
            [$key, 'git', 'git.example.com'],
        ];
        foreach ($changes as [$credential, $provider, $host]) {
            try {
                $vault->saveCredential(['id' => $credential['id'], 'name' => $credential['name'], 'provider' => $provider, 'registry' => $host, 'username' => 'u']);
                $this->fail("{$credential['provider']} was changed to $provider");
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('cannot change between a registry login and a git credential', $error->getMessage());
            }
            $this->assertSame($credential['provider'], $vault->getCredentialSummary($credential['id'])['provider']);
        }
    }

    public function testARegistryCredentialCanStillChangeBetweenRegistryKinds(): void
    {
        $vault = new CredentialVault();
        $saved = $vault->saveCredential(['name' => 'Mine', 'provider' => 'generic', 'registry' => 'registry.example.com', 'username' => 'u', 'secret' => 's']);

        $updated = $vault->saveCredential(['id' => $saved['id'], 'name' => 'Mine', 'provider' => 'docker', 'registry' => 'docker.io', 'username' => 'u']);

        $this->assertSame('docker', $updated['provider']);
    }

    public function testSelectedCredentialOverridesDockerCredentialHelpers(): void
    {
        $dockerConfigDirectory = sys_get_temp_dir() . '/docker-config-test-' . bin2hex(random_bytes(8));
        mkdir($dockerConfigDirectory, 0700, true);
        file_put_contents($dockerConfigDirectory . '/config.json', json_encode([
            'credsStore' => 'secretservice',
            'credHelpers' => [
                'ghcr.io' => 'pass',
                'registry.example.com' => 'desktop',
            ],
            'auths' => [
                'registry.example.com' => ['auth' => base64_encode('other:token')],
            ],
            'proxies' => ['default' => ['httpProxy' => 'http://proxy.example.com']],
        ], JSON_UNESCAPED_SLASHES));

        $previousDockerConfig = getenv('DOCKER_CONFIG');
        putenv('DOCKER_CONFIG=' . $dockerConfigDirectory);
        try {
            $vault = new CredentialVault();
            $saved = $vault->saveCredential([
                'name' => 'GitHub', 'provider' => 'github', 'registry' => 'ghcr.io',
                'username' => 'selected-user', 'secret' => 'selected-token',
            ]);

            $directory = $vault->materializeDockerConfig($saved['id']);
            $config = json_decode((string) file_get_contents($directory . '/config.json'), true);
            $this->assertSame(base64_encode('selected-user:selected-token'), $config['auths']['ghcr.io']['auth']);
            $this->assertArrayNotHasKey('credsStore', $config);
            $this->assertArrayNotHasKey('ghcr.io', $config['credHelpers']);
            $this->assertSame('desktop', $config['credHelpers']['registry.example.com']);
            $this->assertSame('http://proxy.example.com', $config['proxies']['default']['httpProxy']);

            CredentialVault::removeDockerConfig($directory);
        } finally {
            $previousDockerConfig === false ? putenv('DOCKER_CONFIG') : putenv('DOCKER_CONFIG=' . $previousDockerConfig);
            @unlink($dockerConfigDirectory . '/config.json');
            @rmdir($dockerConfigDirectory);
        }
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

    public function testGitCredentialIsForOneHost(): void
    {
        $vault = new CredentialVault();
        $saved = $vault->saveCredential([
            'name' => 'Git stacks',
            'provider' => 'git',
            'registry' => 'https://GitHub.com/',
            'username' => 'bot',
            'secret' => 'token123',
        ]);
        $this->assertSame('git', $saved['provider']);
        $this->assertSame('github.com', $saved['registry']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('repository host only');
        $vault->saveCredential([
            'name' => 'Too much',
            'provider' => 'git',
            'registry' => 'github.com/owner/repo.git',
            'username' => 'bot',
            'secret' => 'token123',
        ]);
    }

    public function testDeployKeyIsKeptLikeAnyOtherSecret(): void
    {
        $vault = new CredentialVault();
        $privateKey = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\n-----END OPENSSH PRIVATE KEY-----";
        $saved = $vault->saveCredential([
            'name' => 'whoami deploy key',
            'provider' => 'git-ssh',
            'registry' => 'git.example.com:2222',
            'username' => 'git',
            'secret' => $privateKey,
        ]);

        $this->assertSame('git-ssh', $saved['provider']);
        $this->assertArrayNotHasKey('secret', $vault->listCredentials()[0]);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) file_get_contents(COMPOSE_CREDENTIAL_VAULT_FILE));
        $this->assertSame($privateKey, $vault->useCredential($saved['id'], static fn(array $credential): string => $credential['secret']));
    }

    public function testDeployKeyIsNeverWrittenOutAsADockerLogin(): void
    {
        $vault = new CredentialVault();
        $saved = $vault->saveCredential([
            'name' => 'whoami deploy key',
            'provider' => 'git-ssh',
            'registry' => 'github.com',
            'username' => 'git',
            'secret' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\n-----END OPENSSH PRIVATE KEY-----",
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is a git repository credential, not a registry login');
        $vault->materializeDockerConfig($saved['id']);
    }

    public function testUseCredentialHandsTheSecretToTheCallbackOnly(): void
    {
        $vault = new CredentialVault();
        $saved = $vault->saveCredential([
            'name' => 'Git stacks',
            'provider' => 'git',
            'registry' => 'github.com',
            'username' => 'bot',
            'secret' => 'token123',
        ]);

        $seen = $vault->useCredential($saved['id'], static fn(array $credential): string => $credential['secret']);

        $this->assertSame('token123', $seen);
    }

    public function testGitCredentialIsNeverWrittenOutAsADockerLogin(): void
    {
        $vault = new CredentialVault();
        $saved = $vault->saveCredential([
            'name' => 'Git stacks',
            'provider' => 'git',
            'registry' => 'github.com',
            'username' => 'bot',
            'secret' => 'token123',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is a git repository credential, not a registry login');
        $vault->materializeDockerConfig($saved['id']);
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