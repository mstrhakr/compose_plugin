<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitPathGuard.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitClone.php';

/**
 * What has been deployed for a git stack, stored as git_state.json in the stack folder.
 *
 * Written only when a deploy finishes, so the flash sees one small write per deploy.
 *
 * Changing the format later follows the same rules as git.json (see
 * GitStackSettings): a key added later defaults to what the plugin did before
 * it existed, an unknown key is refused, and FORMAT_VERSION changes only when
 * a key changes meaning.
 */
final class GitStackState
{
    public const FILE_NAME = 'git_state.json';
    public const FORMAT_VERSION = 1;

    /**
     * @param string|null $deployedCommit The last commit that deployed successfully
     * @param string|null $failedCommit The commit whose deploy failed, if the last deploy failed.
     *                                  Cleared by the next successful deploy.
     */
    public function __construct(
        public readonly ?string $deployedCommit = null,
        public readonly ?string $failedCommit = null
    ) {
        foreach ([$deployedCommit, $failedCommit] as $commit) {
            if ($commit !== null && !GitClone::isCommitId($commit)) {
                throw new InvalidArgumentException("Not a full commit id: $commit");
            }
        }
    }

    /**
     * @throws RuntimeException when git_state.json exists but is not valid
     */
    public static function load(string $stackDir): self
    {
        $file = rtrim($stackDir, '/') . '/' . self::FILE_NAME;
        if (!file_exists($file)) {
            return new self();
        }
        $content = @file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException("Could not read $file.");
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $data = json_decode($content, true);
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException("$file is not a valid git state file.");
        }
        $unknown = array_values(array_diff(array_keys($data), ['version', 'deployedCommit', 'failedCommit']));
        if ($unknown !== []) {
            throw new RuntimeException(
                "$file has entries this version of the plugin does not know: " . implode(', ', $unknown)
                . '. A newer version may have written them; update the plugin, or remove them by hand.'
            );
        }
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['deployedCommit', 'failedCommit', 'version'] || $data['version'] !== self::FORMAT_VERSION) {
            throw new RuntimeException("$file has unexpected contents.");
        }
        foreach (['deployedCommit', 'failedCommit'] as $key) {
            if ($data[$key] !== null && !is_string($data[$key])) {
                throw new RuntimeException("$file: '$key' must be a commit id or null.");
            }
        }

        try {
            return new self($data['deployedCommit'], $data['failedCommit']);
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException("$file: " . $error->getMessage(), 0, $error);
        }
    }

    /**
     * @throws RuntimeException if it cannot be written safely
     */
    public function save(string $stackDir): void
    {
        $stackDir = rtrim($stackDir, '/');
        if (str_starts_with($stackDir, rtrim(COMPOSE_GIT_MNT_DIR, '/') . '/')) {
            GitPathGuard::assertSafeToWrite($stackDir);
        }
        $json = json_encode([
            'version' => self::FORMAT_VERSION,
            'deployedCommit' => $this->deployedCommit,
            'failedCommit' => $this->failedCommit,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $file = $stackDir . '/' . self::FILE_NAME;
        $tmpFile = $file . '.tmp-' . bin2hex(random_bytes(4));
        // A short write (a full flash) counts as a failure, so a cut-off file never replaces the good one.
        if ($json === false || file_put_contents($tmpFile, $json . "\n") !== strlen($json . "\n")) {
            @unlink($tmpFile);
            throw new RuntimeException("Could not write $tmpFile.");
        }
        if (!rename($tmpFile, $file)) {
            @unlink($tmpFile);
            throw new RuntimeException("Could not replace $file.");
        }
    }
}
