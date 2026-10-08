<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitPathGuard.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitCommand.php';

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
 *  - A key added later may be missing from a file. It then takes a default
 *    that does exactly what the plugin did before the key existed, or less,
 *    never more: a stack written by an older version must behave as it did.
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

    /** @var string[] The keys git.json must contain, no more and no fewer. */
    private const KEYS = ['version', 'url', 'branch', 'composePath', 'cloneId', 'cloneDir', 'recreateOnFolderChange'];

    /**
     * @param bool $recreateOnFolderChange Recreate the stack's containers when a deploy changes
     *     anything in the stack's folder, not only when the compose definition changes. A commit
     *     that only edits a bind-mounted config file then takes effect.
     */
    private function __construct(
        public readonly string $url,
        public readonly string $branch,
        public readonly string $composePath,
        public readonly string $cloneId,
        public readonly string $cloneDir,
        public readonly bool $recreateOnFolderChange
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
        return new self($this->url, $this->branch, $this->composePath, $this->cloneId, $this->cloneDir, $recreateOnFolderChange);
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

        $unknown = array_values(array_diff(array_keys($data), self::KEYS));
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

        try {
            return self::fromValues(
                $data['url'],
                $data['branch'],
                $data['composePath'],
                $data['cloneId'],
                $data['cloneDir'],
                $data['recreateOnFolderChange']
            );
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException("$file: " . $error->getMessage(), 0, $error);
        }
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

        $json = json_encode([
            'version' => self::FORMAT_VERSION,
            'url' => $this->url,
            'branch' => $this->branch,
            'composePath' => $this->composePath,
            'cloneId' => $this->cloneId,
            'cloneDir' => $this->cloneDir,
            'recreateOnFolderChange' => $this->recreateOnFolderChange,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
     * Accepted: an https URL without a user name or password in it, or the
     * absolute path of a repository under /mnt (for a bare repository on this
     * server that you push to).
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
                "The repository address must start with https://, or be a path under " . COMPOSE_GIT_MNT_DIR
                . " for a repository on this server: $url"
            );
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidArgumentException("The repository address is not a valid URL: $url");
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(
                'The repository address must not contain a user name, password or token '
                . '(it would be stored in plain text). Private repositories are not supported yet.'
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
        bool $recreateOnFolderChange
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

        return new self($url, $branch, $composePath, $cloneId, $cloneDir, $recreateOnFolderChange);
    }
}
