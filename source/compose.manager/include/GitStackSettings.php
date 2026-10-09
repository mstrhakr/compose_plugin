<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitPathGuard.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitCommand.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/CredentialVault.php';

/**
 * The git settings of one git-backed stack, stored as git.json in the stack folder.
 *
 * Every value is checked when the settings are created and again every time
 * they are loaded: the file can be edited by hand, restored from a backup, or
 * left behind by another version, so nothing in it is trusted.
 *
 * Changing the format later. Every git.json any released version wrote must
 * keep loading, and must keep meaning what it meant:
 *  - The keys of format 1 (KEYS) are always required.
 *  - A key added later (OPTIONAL_KEYS) may be missing from a file. It then
 *    takes a default that does exactly what the plugin did before the key
 *    existed, or less, never more: a stack written by an older version must
 *    behave as it did. save() writes it only when it differs from that
 *    default, so a stack that never uses it still loads in an older version.
 *  - A key this code does not know is refused, naming the key: it was
 *    written by a newer version (after a downgrade), and ignoring a setting
 *    could make the stack do something its owner turned off.
 *  - FORMAT_VERSION changes only when an existing key changes meaning or is
 *    removed. load() then converts each older version explicitly, and a
 *    version newer than this code knows is refused rather than guessed at.
 *  - tests/unit/fixtures/git-stack-settings/ holds a file as each released
 *    version wrote it, and every one must keep loading (see the tests).
 */
final class GitStackSettings
{
    public const FILE_NAME = 'git.json';
    public const FORMAT_VERSION = 1;

    /** @var string[] The keys git.json must contain (format 1). */
    private const KEYS = ['version', 'url', 'branch', 'composePath', 'cloneId', 'cloneDir', 'recreateOnFolderChange'];

    /** @var string[] Keys added later, which a file may leave out. */
    private const OPTIONAL_KEYS = ['credentialId', 'sshKnownHosts'];

    /**
     * @param bool $recreateOnFolderChange Recreate the stack's containers when a deploy changes
     *     anything in the stack's folder, not only when the compose definition changes. A commit
     *     that only edits a bind-mounted config file then takes effect.
     * @param string|null $credentialId The credential vault entry used to reach the repository, or
     *     null for a repository that needs none (the default: format 1 had no credentials). For an
     *     ssh repository, the stack's deploy key.
     * @param string|null $sshKnownHosts The ssh repository host's keys, pinned when the stack was
     *     set up, in known_hosts format; null for an https or local repository.
     */
    private function __construct(
        public readonly string $url,
        public readonly string $branch,
        public readonly string $composePath,
        public readonly string $cloneId,
        public readonly string $cloneDir,
        public readonly bool $recreateOnFolderChange,
        public readonly ?string $credentialId = null,
        public readonly ?string $sshKnownHosts = null
    ) {
    }

    /**
     * Settings for a new git stack, with a new clone folder under the clones root.
     *
     * @throws InvalidArgumentException naming the first problem found
     */
    public static function createNew(
        string $url,
        string $branch,
        string $composePath,
        string $clonesRoot,
        string $stackFolderName,
        bool $recreateOnFolderChange = true
    ): self {
        GitPathGuard::assertValidClonesRoot($clonesRoot);
        $cloneId = bin2hex(random_bytes(8));
        $cloneDir = $clonesRoot . '/' . self::cloneFolderName($stackFolderName, $cloneId);
        return self::fromValues($url, $branch, $composePath, $cloneId, $cloneDir, $recreateOnFolderChange);
    }

    /**
     * The same settings with "recreate on any change in the stack's folder" turned on or off.
     */
    public function withRecreateOnFolderChange(bool $recreateOnFolderChange): self
    {
        return new self(
            $this->url,
            $this->branch,
            $this->composePath,
            $this->cloneId,
            $this->cloneDir,
            $recreateOnFolderChange,
            $this->credentialId,
            $this->sshKnownHosts
        );
    }

    /**
     * The same settings with another credential, or none.
     *
     * @throws InvalidArgumentException if the id is not a credential vault id
     */
    public function withCredentialId(?string $credentialId): self
    {
        return self::fromValues(
            $this->url,
            $this->branch,
            $this->composePath,
            $this->cloneId,
            $this->cloneDir,
            $this->recreateOnFolderChange,
            $credentialId,
            $this->sshKnownHosts
        );
    }

    /**
     * The same settings with other pinned ssh host keys.
     *
     * @throws InvalidArgumentException if they are not known_hosts lines, or the repository is not ssh
     */
    public function withSshKnownHosts(?string $sshKnownHosts): self
    {
        return self::fromValues(
            $this->url,
            $this->branch,
            $this->composePath,
            $this->cloneId,
            $this->cloneDir,
            $this->recreateOnFolderChange,
            $this->credentialId,
            $sshKnownHosts
        );
    }

    /**
     * Whether the repository is reached over ssh (with a deploy key).
     */
    public function isSsh(): bool
    {
        return self::isSshUrl($this->url);
    }

    /**
     * Load a stack's git settings.
     *
     * @return self|null null when the stack is not a git stack (no git.json)
     * @throws RuntimeException when git.json exists but is not valid
     */
    public static function load(string $stackDir): ?self
    {
        $file = rtrim($stackDir, '/') . '/' . self::FILE_NAME;
        if (!file_exists($file)) {
            return null;
        }
        $content = @file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException("Could not read $file.");
        }

        // A file saved by a Windows editor may start with a byte order mark.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $data = json_decode($content, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("$file is not valid JSON: " . json_last_error_msg() . '.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException("$file is not a valid git settings file (expected a JSON object).");
        }

        $unknown = array_values(array_diff(array_keys($data), self::KEYS, self::OPTIONAL_KEYS));
        if ($unknown !== []) {
            throw new RuntimeException(
                "$file has settings this version of the plugin does not know: " . implode(', ', $unknown)
                . '. A newer version may have written them; update the plugin, or remove them by hand.'
            );
        }
        $missing = array_values(array_diff(self::KEYS, array_keys($data)));
        if ($missing !== []) {
            throw new RuntimeException("$file is missing settings: " . implode(', ', $missing) . '.');
        }
        if ($data['version'] !== self::FORMAT_VERSION) {
            throw new RuntimeException(
                "$file was written by a different version of the plugin (format "
                . var_export($data['version'], true) . ', expected ' . self::FORMAT_VERSION . ').'
            );
        }
        foreach (['url', 'branch', 'composePath', 'cloneId', 'cloneDir'] as $key) {
            if (!is_string($data[$key])) {
                throw new RuntimeException("$file: '$key' must be a string.");
            }
        }
        if (!is_bool($data['recreateOnFolderChange'])) {
            throw new RuntimeException("$file: 'recreateOnFolderChange' must be true or false.");
        }
        $credentialId = $data['credentialId'] ?? null;
        if ($credentialId !== null && !is_string($credentialId)) {
            throw new RuntimeException("$file: 'credentialId' must be a string.");
        }
        $sshKnownHosts = $data['sshKnownHosts'] ?? null;
        if ($sshKnownHosts !== null && !is_string($sshKnownHosts)) {
            throw new RuntimeException("$file: 'sshKnownHosts' must be a string.");
        }

        try {
            return self::fromValues(
                $data['url'],
                $data['branch'],
                $data['composePath'],
                $data['cloneId'],
                $data['cloneDir'],
                $data['recreateOnFolderChange'],
                $credentialId,
                $sshKnownHosts
            );
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException("$file: " . $error->getMessage(), 0, $error);
        }
    }

    /**
     * Write git.json when it gives the stack a credential (a new stack, or a changed
     * credential): check the credential still exists and write, both under the lock
     * that deleting a credential takes. The repository was reached before this, which
     * can take a while, and the credential may have been deleted meanwhile; it was not
     * in use yet, so the delete was allowed. The lock is not held across the network.
     *
     * @throws RuntimeException if the credential is gone, or git.json cannot be written
     */
    public function saveCheckingCredential(string $stackDir): void
    {
        CredentialVault::withCredentialAssignmentLock(function () use ($stackDir): void {
            if ($this->credentialId !== null && !(new CredentialVault())->hasCredential($this->credentialId)) {
                throw new RuntimeException(
                    'The credential was deleted while the repository was being reached, so the stack settings were not saved.'
                );
            }
            $this->save($stackDir);
        });
    }

    /**
     * Write git.json to the stack folder, replacing it in one step.
     *
     * @throws RuntimeException if it cannot be written safely
     */
    public function save(string $stackDir): void
    {
        $stackDir = rtrim($stackDir, '/');
        if (str_starts_with($stackDir, rtrim(COMPOSE_GIT_MNT_DIR, '/') . '/')) {
            // A projects folder kept on the array: never write there while it is not mounted.
            GitPathGuard::assertSafeToWrite($stackDir);
        }
        if (!is_dir($stackDir)) {
            throw new RuntimeException("Stack folder $stackDir does not exist.");
        }

        $data = [
            'version' => self::FORMAT_VERSION,
            'url' => $this->url,
            'branch' => $this->branch,
            'composePath' => $this->composePath,
            'cloneId' => $this->cloneId,
            'cloneDir' => $this->cloneDir,
            'recreateOnFolderChange' => $this->recreateOnFolderChange,
        ];
        // Keys added later are written only when they differ from their default (see the class comment).
        if ($this->credentialId !== null) {
            $data['credentialId'] = $this->credentialId;
        }
        if ($this->sshKnownHosts !== null) {
            $data['sshKnownHosts'] = $this->sshKnownHosts;
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Could not encode the git settings.');
        }

        $file = $stackDir . '/' . self::FILE_NAME;
        $tmpFile = $file . '.tmp-' . bin2hex(random_bytes(4));
        // A short write (a full flash) counts as a failure, so a cut-off file never replaces the good one.
        $content = $json . "\n";
        if (file_put_contents($tmpFile, $content) !== strlen($content)) {
            @unlink($tmpFile);
            throw new RuntimeException("Could not write $tmpFile.");
        }
        if (!rename($tmpFile, $file)) {
            @unlink($tmpFile);
            throw new RuntimeException("Could not replace $file.");
        }
    }

    /**
     * Full path of the compose file inside the clone.
     */
    public function composeFileInClone(): string
    {
        return $this->cloneDir . '/' . $this->composePath;
    }

    /**
     * The clone folder name for a stack: a readable part from the stack folder
     * name, plus part of the clone id so two stacks can never share a folder.
     */
    public static function cloneFolderName(string $stackFolderName, string $cloneId): string
    {
        $readable = strtolower($stackFolderName);
        $readable = (string) preg_replace('/[^a-z0-9._-]+/', '-', $readable);
        $readable = trim($readable, '.-_');
        $readable = substr($readable, 0, 40);
        if ($readable === '') {
            $readable = 'stack';
        }
        return $readable . '-' . substr($cloneId, 0, 8);
    }

    /**
     * Check a repository address.
     *
     * Accepted: an https URL without a user name or password in it, an ssh
     * address (ssh://git@host[:port]/path or git@host:path, reached with the
     * stack's deploy key), or the absolute path of a repository under /mnt (for
     * a bare repository on this server that you push to).
     *
     * @throws InvalidArgumentException naming the problem
     */
    public static function validateUrl(string $url): void
    {
        if ($url === '') {
            throw new InvalidArgumentException('The repository address is empty.');
        }
        if (strlen($url) > 2048) {
            throw new InvalidArgumentException('The repository address is too long.');
        }
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new InvalidArgumentException('The repository address must not contain spaces or control characters.');
        }

        if (self::isSshUrl($url)) {
            // Each part is held to plain characters: the host and user reach ssh's command line,
            // where a value starting with "-" would be read as an option.
            if (self::sshAddress($url) === null) {
                throw new InvalidArgumentException(
                    "The ssh repository address is not valid. Use ssh://git@host[:port]/path or git@host:path: $url"
                );
            }
            return;
        }

        if (str_starts_with($url, '/')) {
            GitPathGuard::assertCleanAbsolutePath($url, 'The repository path');
            if (!str_starts_with($url, rtrim(COMPOSE_GIT_MNT_DIR, '/') . '/')) {
                throw new InvalidArgumentException(
                    'A repository on this server must be under ' . COMPOSE_GIT_MNT_DIR . ": $url"
                );
            }
            return;
        }

        // Lower case only: git matches the transport name exactly, and refuses "HTTPS".
        if (!str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException(
                "The repository address must start with https:// or ssh:// (or be git@host:path), or be a path under "
                . COMPOSE_GIT_MNT_DIR . " for a repository on this server: $url"
            );
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidArgumentException("The repository address is not a valid URL: $url");
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(
                'The repository address must not contain a user name, password or token '
                . '(it would be stored in plain text). For a private repository, use a git credential instead.'
            );
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException("The repository address must not contain ? or #: $url");
        }
        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $parts['host']) !== 1) {
            throw new InvalidArgumentException("The repository address has an invalid host name: {$parts['host']}");
        }
        $path = $parts['path'] ?? '';
        if ($path === '' || $path === '/') {
            throw new InvalidArgumentException("The repository address has no repository path: $url");
        }
    }

    /**
     * Whether an address is meant as ssh: ssh://..., or the user@host:path form.
     */
    public static function isSshUrl(string $url): bool
    {
        return str_starts_with(strtolower($url), 'ssh://')
            || preg_match('#^[^/:@]+@[^/:]+:#', $url) === 1;
    }

    /**
     * The parts of an ssh repository address, or null when it is not a valid one.
     *
     * The user, host and port go to ssh, so each is held to plain characters, and
     * none can start with "-". The path is what the server is asked for.
     *
     * @return array{user: string, host: string, port: int, path: string}|null
     */
    public static function sshAddress(string $url): ?array
    {
        $user = '[A-Za-z0-9_][A-Za-z0-9._-]*';
        $host = '(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?';
        $path = '[A-Za-z0-9._~+][A-Za-z0-9._~+/-]*';
        if (preg_match("#^ssh://($user)@($host)(?::([0-9]{1,5}))?/($path)$#i", $url, $match) === 1) {
            $port = $match[3] === '' ? 22 : (int) $match[3];
        } elseif (preg_match("#^($user)@($host):(/?$path)$#", $url, $match) === 1) {
            $port = 22;
            $match[4] = $match[3];
        } else {
            return null;
        }
        if ($port < 1 || $port > 65535 || str_contains($match[4], '..')) {
            return null;
        }
        return ['user' => $match[1], 'host' => strtolower($match[2]), 'port' => $port, 'path' => $match[4]];
    }

    /**
     * Check pinned ssh host keys: one or more known_hosts lines, "host type key".
     *
     * @throws InvalidArgumentException naming the problem
     */
    public static function validateKnownHosts(string $knownHosts): void
    {
        $lines = array_filter(explode("\n", trim($knownHosts)), static fn(string $line): bool => $line !== '');
        if ($lines === []) {
            throw new InvalidArgumentException('The pinned ssh host keys are empty.');
        }
        foreach ($lines as $line) {
            if (preg_match('#^[A-Za-z0-9.:\[\]_-]+ (?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+/]+={0,2}$#', $line) !== 1) {
                throw new InvalidArgumentException("A pinned ssh host key is not a known_hosts line: $line");
            }
        }
    }

    /**
     * Check a branch name, using git's own rules.
     *
     * @throws InvalidArgumentException naming the problem
     */
    public static function validateBranch(string $branch): void
    {
        if ($branch === '') {
            throw new InvalidArgumentException('The branch is empty.');
        }
        if (strlen($branch) > 255) {
            throw new InvalidArgumentException('The branch name is too long.');
        }
        if (str_starts_with($branch, '-')) {
            throw new InvalidArgumentException("The branch name must not start with '-': $branch");
        }
        if ($branch === 'HEAD') {
            throw new InvalidArgumentException("'HEAD' is not a branch name. Enter the branch to deploy, such as main.");
        }
        $result = GitCommand::run(['check-ref-format', 'refs/heads/' . $branch], null, 10);
        if (!$result->succeeded()) {
            throw new InvalidArgumentException("'$branch' is not a valid branch name.");
        }
    }

    /**
     * Check the path of the compose file inside the repository.
     *
     * It must be a relative path to a .yml or .yaml file that stays inside the
     * repository.
     *
     * @throws InvalidArgumentException naming the problem
     */
    public static function validateComposePath(string $composePath): void
    {
        if ($composePath === '') {
            throw new InvalidArgumentException('The compose file path is empty.');
        }
        if (strlen($composePath) > 1024) {
            throw new InvalidArgumentException('The compose file path is too long.');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $composePath) === 1) {
            throw new InvalidArgumentException('The compose file path contains a control character.');
        }
        if (str_starts_with($composePath, '/')) {
            throw new InvalidArgumentException(
                "The compose file path must be relative to the top of the repository, without a leading /: $composePath"
            );
        }
        if (str_contains($composePath, '\\')) {
            throw new InvalidArgumentException("Use / in the compose file path, not \\: $composePath");
        }
        if (str_starts_with($composePath, '-')) {
            throw new InvalidArgumentException("The compose file path must not start with '-': $composePath");
        }
        foreach (explode('/', $composePath) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new InvalidArgumentException("The compose file path must not contain empty, '.' or '..' parts: $composePath");
            }
            if (strtolower($part) === '.git') {
                throw new InvalidArgumentException("The compose file path must not be inside .git: $composePath");
            }
        }
        if (preg_match('/\.ya?ml$/i', $composePath) !== 1) {
            throw new InvalidArgumentException("The compose file path must end in .yml or .yaml: $composePath");
        }
    }

    /**
     * Check a credential vault id: null for none, or the 32 hex characters the vault gives every entry.
     *
     * @throws InvalidArgumentException naming the problem
     */
    public static function validateCredentialId(?string $credentialId): void
    {
        if ($credentialId !== null && preg_match('/^[0-9a-f]{32}$/', $credentialId) !== 1) {
            throw new InvalidArgumentException("The credential id is not valid: $credentialId");
        }
    }

    /**
     * Validate every value and build the settings.
     *
     * @throws InvalidArgumentException naming the first problem found
     */
    private static function fromValues(
        string $url,
        string $branch,
        string $composePath,
        string $cloneId,
        string $cloneDir,
        bool $recreateOnFolderChange,
        ?string $credentialId = null,
        ?string $sshKnownHosts = null
    ): self {
        self::validateUrl($url);
        self::validateBranch($branch);
        self::validateComposePath($composePath);

        if (preg_match('/^[0-9a-f]{16}$/', $cloneId) !== 1) {
            throw new InvalidArgumentException("The clone id is not valid: $cloneId");
        }
        GitPathGuard::assertCleanAbsolutePath($cloneDir, 'The clone folder');
        if (!str_starts_with($cloneDir, rtrim(COMPOSE_GIT_MNT_DIR, '/') . '/')) {
            throw new InvalidArgumentException('The clone folder must be under ' . COMPOSE_GIT_MNT_DIR . ": $cloneDir");
        }
        if (!str_ends_with(basename($cloneDir), '-' . substr($cloneId, 0, 8))) {
            throw new InvalidArgumentException("The clone folder $cloneDir does not belong to clone id $cloneId.");
        }

        self::validateCredentialId($credentialId);
        if ($credentialId !== null && str_starts_with($url, '/')) {
            throw new InvalidArgumentException('A repository on this server needs no credential.');
        }
        if ($sshKnownHosts !== null) {
            if (!self::isSshUrl($url)) {
                throw new InvalidArgumentException('Pinned ssh host keys are only for an ssh repository.');
            }
            self::validateKnownHosts($sshKnownHosts);
        }

        return new self($url, $branch, $composePath, $cloneId, $cloneDir, $recreateOnFolderChange, $credentialId, $sshKnownHosts);
    }
}
