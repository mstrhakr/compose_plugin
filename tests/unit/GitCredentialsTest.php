<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use CredentialVault;
use GitCredentials;
use PluginTests\TestCase;
use RuntimeException;

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitCredentials.php';

/**
 * The short-lived files that hand a git stack's HTTPS token to git.
 */
final class GitCredentialsTest extends TestCase
{
    private const TOKEN = 'secret-token-123';

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(COMPOSE_CREDENTIAL_VAULT_FILE);
        @unlink(COMPOSE_CREDENTIAL_KEY_FILE);
        foreach (glob(COMPOSE_GIT_CREDENTIAL_DIR . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function testFileHoldsTheRepositoryAndOnlyRootCanReadIt(): void
    {
        $file = GitCredentials::writeFile($this->gitCredential('github.com'), 'https://github.com/owner/repo.git');

        $this->assertSame("https://bot:" . self::TOKEN . "@github.com/owner/repo.git\n", file_get_contents($file));
        $this->assertSame(0600, fileperms($file) & 0777);
        $this->assertSame(0700, fileperms(COMPOSE_GIT_CREDENTIAL_DIR) & 0777);
        GitCredentials::remove($file);
        $this->assertFileDoesNotExist($file);
    }

    public function testACredentialFolderThatIsASymlinkIsRefused(): void
    {
        // The folder is in /var/tmp, where anyone can create things first. (A folder another
        // user owns is refused too, but a test that does not run as root cannot make one.)
        $directory = rtrim(COMPOSE_GIT_CREDENTIAL_DIR, '/');
        $target = sys_get_temp_dir() . '/compose_git_credentials_elsewhere';
        @mkdir($target, 0755);
        $moved = $directory . '.real';
        @rename($directory, $moved);
        symlink($target, $directory);
        try {
            GitCredentials::writeRunFile('secret');
            $this->fail('A credential was written through a symlinked folder.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('is not a plain folder', $error->getMessage());
        } finally {
            unlink($directory);
            @rename($moved, $directory);
        }
        $this->assertSame([], glob($target . '/*') ?: []);
        $this->assertSame(0755, fileperms($target) & 0777);
        rmdir($target);
    }

    public function testGitGetsTheTokenForTheStacksRepositoryOnly(): void
    {
        $file = GitCredentials::writeFile($this->gitCredential('github.com'), 'https://github.com/owner/repo.git');

        $this->assertStringContainsString('password=' . self::TOKEN, $this->credentialFill($file, 'owner/repo.git'));
        // Another repository on the same host gets nothing.
        $this->assertStringNotContainsString(self::TOKEN, $this->credentialFill($file, 'owner/other.git'));
        GitCredentials::remove($file);
    }

    public function testUserNameAndTokenWithSpecialCharactersArePassedExactly(): void
    {
        $id = (new CredentialVault())->saveCredential([
            'name' => 'Odd',
            'provider' => 'git',
            'registry' => 'git.example.com:3000',
            'username' => 'me@example.com',
            'secret' => 'p@ss:w/rd%',
        ])['id'];
        $file = GitCredentials::writeFile($id, 'https://git.example.com:3000/team/stacks.git');

        $answer = $this->credentialFill($file, 'team/stacks.git', 'git.example.com:3000');

        $this->assertStringContainsString("username=me@example.com\n", $answer);
        $this->assertStringContainsString("password=p@ss:w/rd%\n", $answer);
        GitCredentials::remove($file);
    }

    public function testCredentialForAnotherHostIsRefused(): void
    {
        $id = $this->gitCredential('gitlab.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is for gitlab.com, but the repository is on github.com');
        GitCredentials::writeFile($id, 'https://github.com/owner/repo.git');
    }

    public function testRegistryCredentialIsRefused(): void
    {
        $id = (new CredentialVault())->saveCredential([
            'name' => 'Docker Hub',
            'provider' => 'docker',
            'registry' => 'docker.io',
            'username' => 'bot',
            'secret' => self::TOKEN,
        ])['id'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a git repository credential');
        GitCredentials::writeFile($id, 'https://github.com/owner/repo.git');
    }

    public function testMissingCredentialIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        GitCredentials::writeFile(str_repeat('0', 32), 'https://github.com/owner/repo.git');
    }

    public function testLocalRepositoryPathIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('https://');
        GitCredentials::writeFile($this->gitCredential('github.com'), '/mnt/user/repos/stacks.git');
    }

    public function testRemoveTouchesOnlyFilesInTheCredentialFolder(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'not-a-credential');
        GitCredentials::remove($outside);
        $this->assertFileExists($outside);
        unlink($outside);
    }

    public function testFileLeftByAKilledRunIsRemovedByTheNextOne(): void
    {
        $id = $this->gitCredential('github.com');
        $old = GitCredentials::writeFile($id, 'https://github.com/owner/repo.git');
        touch($old, time() - 7200);
        $recent = GitCredentials::writeFile($id, 'https://github.com/owner/repo.git');
        touch($recent, time() - 60);

        $new = GitCredentials::writeFile($id, 'https://github.com/owner/repo.git');

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
        GitCredentials::remove($recent);
        GitCredentials::remove($new);
    }

    private function gitCredential(string $host): string
    {
        return (new CredentialVault())->saveCredential([
            'name' => 'Git ' . $host,
            'provider' => 'git',
            'registry' => $host,
            'username' => 'bot',
            'secret' => self::TOKEN,
        ])['id'];
    }

    /**
     * Ask git, the way it asks before a fetch, for the credential of one repository.
     *
     * Run directly rather than through GitCommand, which gives git no input: the
     * request goes to git on stdin. The settings are the ones a stack's run gets.
     */
    private function credentialFill(string $file, string $path, string $host = 'github.com'): string
    {
        $command = ['git', '-c', 'credential.helper='];
        foreach (GitCredentials::settingsFor($file) as $setting) {
            $command[] = '-c';
            $command[] = $setting;
        }
        $command[] = 'credential';
        $command[] = 'fill';
        $environment = [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'HOME' => sys_get_temp_dir(),
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ASKPASS' => '/bin/false',
        ];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        $this->assertIsResource($process);
        fwrite($pipes[0], "protocol=https\nhost=$host\npath=$path\n\n");
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return $output;
    }

    public function testARedirectIsNamedWhenTheTokenWasNotOfferedForTheNewAddress(): void
    {
        $stderr = "warning: redirecting to https://gitlab.example.com/group/project.git/\n"
            . "fatal: Authentication failed for 'https://gitlab.example.com/group/project.git/'\n";

        $hint = \GitClone::httpsFailureHint($stderr, true);

        $this->assertNotNull($hint);
        $this->assertStringContainsString('redirected to https://gitlab.example.com/group/project.git/', $hint);
        // Without a credential a redirect is followed and needs no hint.
        $this->assertNull(\GitClone::httpsFailureHint($stderr, false));
    }

    public function testAPrivateRepositoryWithoutACredentialSaysItNeedsOne(): void
    {
        $stderr = "fatal: could not read Username for 'https://github.com': terminal prompts disabled\n";

        $this->assertStringContainsString('needs a credential', (string) \GitClone::httpsFailureHint($stderr, false));
        $this->assertNull(\GitClone::httpsFailureHint("fatal: repository 'https://github.com/x/y.git/' not found\n", false));
    }
}
