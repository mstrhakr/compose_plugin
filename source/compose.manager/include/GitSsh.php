<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/ProcessRunner.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/CredentialVault.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitCredentials.php';

/**
 * ssh for git stacks: a deploy key per stack, and the repository host's pinned keys.
 *
 * The private key is kept in the credential vault (a "git-ssh" entry) and
 * written to a root-only file under /var/tmp for each git run, like an HTTPS
 * token (see GitCredentials). The host's keys are pinned in git.json when the
 * stack is set up, and every connection is checked against them only.
 *
 * ssh runs with no config file (-F /dev/null), only the stack's key, no
 * prompts, and strict host key checking against the pinned keys, so nothing
 * in root's ~/.ssh is used and a changed host key stops the run.
 */
final class GitSsh
{
    private const TIMEOUT_SECONDS = 30;

    /**
     * Make a new ed25519 key pair.
     *
     * @return array{private: string, public: string}
     * @throws RuntimeException if ssh-keygen fails
     */
    public static function generateKey(string $comment): array
    {
        $file = GitCredentials::writeRunFile('');
        // ssh-keygen refuses to write over an existing file, so it gets a name next to the placeholder.
        $keyFile = $file . '-key';
        try {
            $result = self::run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $comment, '-f', $keyFile]);
            if (!$result->succeeded() || !is_file($keyFile) || !is_file($keyFile . '.pub')) {
                throw new RuntimeException('Could not make an ssh key: ' . $result->errorSummary());
            }
            return [
                'private' => (string) file_get_contents($keyFile),
                'public' => trim((string) file_get_contents($keyFile . '.pub')),
            ];
        } finally {
            @unlink($keyFile);
            @unlink($keyFile . '.pub');
            GitCredentials::remove($file);
        }
    }

    /**
     * The public key of a deploy key kept in the vault, to paste into the repository's settings.
     *
     * @throws RuntimeException if the credential is missing or not a deploy key
     */
    public static function publicKey(string $credentialId): string
    {
        $keyFile = self::writeKeyFile($credentialId);
        try {
            $result = self::run(['ssh-keygen', '-y', '-f', $keyFile]);
            if (!$result->succeeded()) {
                throw new RuntimeException('Could not read the deploy key: ' . $result->errorSummary());
            }
            return trim($result->stdout);
        } finally {
            GitCredentials::remove($keyFile);
        }
    }

    /**
     * Ask a host for its keys, as known_hosts lines.
     *
     * @throws RuntimeException if the host does not answer
     */
    public static function scanHostKeys(string $host, int $port): string
    {
        $result = self::run(['ssh-keyscan', '-T', '10', '-p', (string) $port, '-t', 'ed25519,ecdsa,rsa', $host]);
        $lines = [];
        foreach (explode("\n", $result->stdout) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $lines[] = $line;
            }
        }
        sort($lines);
        if ($lines === []) {
            // ssh-keyscan reports the server's banner as "#" lines on stderr; any other line is the error.
            $errors = array_values(array_filter(
                array_map('trim', explode("\n", $result->stderr)),
                static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')
            ));
            $reason = $errors === [] ? 'no key came back' : $errors[count($errors) - 1];
            throw new RuntimeException(
                "Could not get the ssh host keys of $host (port $port): $reason. "
                . 'Check the address, and that this server can reach the host.'
            );
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * The SHA256 fingerprint of each pinned key, for a person to compare with the ones the host publishes.
     *
     * @return string[] Lines such as "SHA256:abc... (ED25519)"
     */
    public static function fingerprints(string $knownHosts): array
    {
        $file = GitCredentials::writeRunFile($knownHosts);
        try {
            $result = self::run(['ssh-keygen', '-l', '-E', 'sha256', '-f', $file]);
            $fingerprints = [];
            foreach (explode("\n", trim($result->stdout)) as $line) {
                // "256 SHA256:abc... host (ED25519)"
                if (preg_match('/^\d+ (SHA256:\S+) .* (\([A-Z0-9-]+\))$/', trim($line), $match) === 1) {
                    $fingerprints[] = $match[1] . ' ' . $match[2];
                }
            }
            // Keys the person cannot compare must not be pinned: say so instead of showing nothing.
            if ($fingerprints === []) {
                throw new RuntimeException('Could not read the fingerprints of the ssh host keys, so nothing was pinned: ' . $result->errorSummary());
            }
            return $fingerprints;
        } finally {
            GitCredentials::remove($file);
        }
    }

    /**
     * Write a stack's deploy key and pinned host keys for one git run.
     *
     * @return array{key: string, knownHosts: string} The files; remove both with GitCredentials::remove()
     * @throws RuntimeException if the key is missing or not a deploy key
     */
    public static function writeRunFiles(string $credentialId, string $knownHosts): array
    {
        $keyFile = self::writeKeyFile($credentialId);
        try {
            $knownHostsFile = GitCredentials::writeRunFile($knownHosts);
        } catch (Throwable $error) {
            GitCredentials::remove($keyFile);
            throw $error;
        }
        return ['key' => $keyFile, 'knownHosts' => $knownHostsFile];
    }

    /**
     * The environment that makes git's ssh use only these files (for GitCommand::run).
     *
     * GIT_SSH_COMMAND goes through a shell, so it only ever holds the plugin's own
     * file names, which are hex under a fixed folder.
     *
     * @param array{key: string, knownHosts: string} $files
     * @return array<string, string>
     */
    public static function environmentFor(array $files): array
    {
        return [
            'GIT_SSH_COMMAND' => implode(' ', [
                'ssh',
                '-F /dev/null',
                '-i ' . escapeshellarg($files['key']),
                '-o IdentitiesOnly=yes',
                '-o BatchMode=yes',
                '-o StrictHostKeyChecking=yes',
                '-o UserKnownHostsFile=' . escapeshellarg($files['knownHosts']),
                '-o GlobalKnownHostsFile=/dev/null',
                '-o ConnectTimeout=30',
            ]),
        ];
    }

    /**
     * The git settings for an ssh run: the ssh transport, which is otherwise not allowed.
     *
     * @return string[]
     */
    public static function settings(): array
    {
        return ['protocol.ssh.allow=always'];
    }

    private static function writeKeyFile(string $credentialId): string
    {
        $privateKey = (new CredentialVault())->useCredential(
            $credentialId,
            static function (array $credential): string {
                if (($credential['provider'] ?? '') !== 'git-ssh') {
                    throw new RuntimeException("The credential '{$credential['name']}' is not an ssh deploy key.");
                }
                return $credential['secret'];
            }
        );
        // ssh needs the key file to end with a newline; the vault trims it off.
        return GitCredentials::writeRunFile(rtrim($privateKey) . "\n");
    }

    /**
     * @param string[] $command
     */
    private static function run(array $command): ProcessResult
    {
        return ProcessRunner::run(
            $command,
            sys_get_temp_dir(),
            ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'HOME' => COMPOSE_GIT_HOME_DIR, 'LC_ALL' => 'C'],
            self::TIMEOUT_SECONDS
        );
    }
}
