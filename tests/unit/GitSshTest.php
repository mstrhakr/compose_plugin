<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use CredentialVault;
use GitCredentials;
use GitSsh;
use PluginTests\TestCase;
use RuntimeException;

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitSsh.php';

/**
 * Deploy keys and pinned host keys for git stacks reached over ssh.
 *
 * A real ssh connection is covered on Unraid (an end-to-end check against a
 * Forgejo server); these cover the keys and the files a run gets.
 */
final class GitSshTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (trim((string) shell_exec('command -v ssh-keygen')) === '') {
            $this->markTestSkipped('ssh-keygen is not installed.');
        }
        @unlink(COMPOSE_CREDENTIAL_VAULT_FILE);
        @unlink(COMPOSE_CREDENTIAL_KEY_FILE);
        foreach (glob(COMPOSE_GIT_CREDENTIAL_DIR . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function testNewKeyIsEd25519AndLeavesNoFileBehind(): void
    {
        $key = GitSsh::generateKey('compose-manager whoami');

        $this->assertStringContainsString('BEGIN OPENSSH PRIVATE KEY', $key['private']);
        $this->assertMatchesRegularExpression('#^ssh-ed25519 [A-Za-z0-9+/=]+ compose-manager whoami$#', $key['public']);
        $this->assertSame([], glob(COMPOSE_GIT_CREDENTIAL_DIR . '/*') ?: []);
    }

    public function testPublicKeyIsReadBackFromTheVault(): void
    {
        $key = GitSsh::generateKey('compose-manager whoami');
        $id = $this->saveDeployKey($key['private']);

        $this->assertSame($this->keyPart($key['public']), $this->keyPart(GitSsh::publicKey($id)));
        $this->assertSame([], glob(COMPOSE_GIT_CREDENTIAL_DIR . '/*') ?: []);
    }

    public function testHttpsTokenIsNotUsedAsADeployKey(): void
    {
        $id = (new CredentialVault())->saveCredential([
            'name' => 'Token', 'provider' => 'git', 'registry' => 'github.com', 'username' => 'bot', 'secret' => 'token',
        ])['id'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not an ssh deploy key');
        GitSsh::publicKey($id);
    }

    public function testHostThatGivesNoKeysIsRefusedWithAReason(): void
    {
        if (trim((string) shell_exec('command -v ssh-keyscan')) === '') {
            $this->markTestSkipped('ssh-keyscan is not installed.');
        }

        // Nothing listens on port 9.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Could not get the ssh host keys of 127\.0\.0\.1 \(port 9\): .+\. Check the address/');
        GitSsh::scanHostKeys('127.0.0.1', 9);
    }

    public function testFingerprintsOfPinnedKeys(): void
    {
        $key = GitSsh::generateKey('host key');
        $knownHosts = '[git.example.com]:2222 ' . $this->keyPart($key['public']) . "\n";
        $publicFile = GitCredentials::writeRunFile($key['public'] . "\n");
        $expected = explode(' ', trim((string) shell_exec('ssh-keygen -l -E sha256 -f ' . escapeshellarg($publicFile))))[1];
        GitCredentials::remove($publicFile);

        $this->assertSame([$expected . ' (ED25519)'], GitSsh::fingerprints($knownHosts));
    }

    public function testRunFilesAreRootOnlyAndTheKeyEndsWithANewline(): void
    {
        $key = GitSsh::generateKey('compose-manager whoami');
        $id = $this->saveDeployKey($key['private']);
        $knownHosts = 'github.com ' . $this->keyPart($key['public']) . "\n";

        $files = GitSsh::writeRunFiles($id, $knownHosts);

        $this->assertSame(rtrim($key['private']) . "\n", file_get_contents($files['key']));
        $this->assertSame($knownHosts, file_get_contents($files['knownHosts']));
        $this->assertSame(0600, fileperms($files['key']) & 0777);
        $this->assertSame(0600, fileperms($files['knownHosts']) & 0777);
        GitCredentials::remove($files['key']);
        GitCredentials::remove($files['knownHosts']);
        $this->assertSame([], glob(COMPOSE_GIT_CREDENTIAL_DIR . '/*') ?: []);
    }

    public function testSshUsesOnlyTheStacksKeyAndPinnedHostKeys(): void
    {
        $command = GitSsh::environmentFor(['key' => '/var/tmp/x/key', 'knownHosts' => '/var/tmp/x/hosts'])['GIT_SSH_COMMAND'];

        $this->assertStringStartsWith('ssh -F /dev/null ', $command);
        $this->assertStringContainsString("-i '/var/tmp/x/key'", $command);
        $this->assertStringContainsString('-o IdentitiesOnly=yes', $command);
        $this->assertStringContainsString('-o BatchMode=yes', $command);
        $this->assertStringContainsString('-o StrictHostKeyChecking=yes', $command);
        $this->assertStringContainsString("-o UserKnownHostsFile='/var/tmp/x/hosts'", $command);
        $this->assertStringContainsString('-o GlobalKnownHostsFile=/dev/null', $command);
        $this->assertSame(['protocol.ssh.allow=always'], GitSsh::settings());
    }

    private function saveDeployKey(string $privateKey): string
    {
        return (new CredentialVault())->saveCredential([
            'name' => 'whoami deploy key', 'provider' => 'git-ssh', 'registry' => 'github.com',
            'username' => 'git', 'secret' => $privateKey,
        ])['id'];
    }

    /**
     * "type base64" without the comment.
     */
    private function keyPart(string $publicKey): string
    {
        return implode(' ', array_slice(explode(' ', trim($publicKey)), 0, 2));
    }
}
