<?php

declare(strict_types=1);

require_once __DIR__ . '/Defines.php';
require_once __DIR__ . '/StackMutationGuard.php';

class PendingRechecks
{
    /** @param mixed $stacks
     *  @return list<string>
     */
    public static function validateStacks($stacks): array
    {
        if (!is_array($stacks) || !$stacks || !array_is_list($stacks)) {
            throw new InvalidArgumentException('No valid stack list specified.');
        }
        foreach ($stacks as $name) {
            if (!is_string($name) || $name === '' || in_array($name, ['.', '..'], true)
                || basename($name) !== $name || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                throw new InvalidArgumentException('Invalid stack name.');
            }
        }
        return array_values(array_unique($stacks));
    }

    /** @return array<string|int, int> */
    public static function timestamps(): array
    {
        return array_map(static fn($entry): int => is_int($entry) ? $entry : $entry['timestamp'], self::access());
    }

    public static function token(string $stack, ?string $project = null): ?string
    {
        $snapshot = self::snapshot([$stack => $project ?? StackMutationGuard::projectForFolder($stack)]);
        if (!array_key_exists($stack, $snapshot['tokens'])) throw new RuntimeException('A stack operation is in progress.');
        return $snapshot['tokens'][$stack];
    }

    /** @param array<string|int, string> $projects Folder => canonical project
     *  @return array{epochs: array<string|int, string>, tokens: array<string|int, string|null>}
     */
    public static function snapshot(array $projects): array
    {
        return StackMutationGuard::inspectMany(array_values($projects),
            static function (array $current) use ($projects): array {
                $entries = self::access();
                $epochs = [];
                $tokens = [];
                foreach ($projects as $folder => $project) {
                    if (!array_key_exists($project, $current)) continue;
                    $epochs[$folder] = $current[$project];
                    $tokens[$folder] = isset($entries[$folder]) ? self::entryToken($entries[$folder], $current[$project]) : null;
                }
                return ['epochs' => $epochs, 'tokens' => $tokens];
            });
    }

    /** @param list<string> $stacks
     *  @return list<string|int>
     */
    public static function mark(array $stacks, bool $create = true): array
    {
        $stacks = self::validateStacks($stacks);
        $entries = self::access(static function (array $entries) use ($stacks, $create): array {
            foreach ($stacks as $stack) {
                if (!$create && !isset($entries[$stack])) continue;
                $entries[$stack] = ['timestamp' => time(), 'token' => bin2hex(random_bytes(16))];
            }
            return $entries;
        });
        return array_keys($entries);
    }

    /** @param list<string> $stacks
     *  @param mixed $tokens
     *  @return list<string|int>
     */
    public static function acknowledge(array $stacks, $tokens): array
    {
        $stacks = self::validateStacks($stacks);
        if (!is_array($tokens)) throw new InvalidArgumentException('Recheck tokens are required.');
        foreach ($stacks as $stack) {
            if (!array_key_exists($stack, $tokens)
                || ($tokens[$stack] !== null && (!is_string($tokens[$stack])
                    || preg_match('/^[a-f0-9]{64}$/D', $tokens[$stack]) !== 1))) {
                throw new InvalidArgumentException('Invalid or missing recheck token.');
            }
        }
        $projects = [];
        foreach ($stacks as $stack) $projects[$stack] = StackMutationGuard::projectForFolder($stack);
        return StackMutationGuard::inspectMany(array_values($projects),
            static function (array $epochs) use ($projects, $tokens): array {
                return array_keys(self::access(static function (array $entries) use ($projects, $tokens, $epochs): array {
                    foreach ($projects as $stack => $project) {
                        if (array_key_exists($project, $epochs) && isset($entries[$stack])
                            && self::entryToken($entries[$stack], $epochs[$project]) === $tokens[$stack]) {
                            unset($entries[$stack]);
                        }
                    }
                    return $entries;
                }));
            });
    }

    public static function remove(string $stack): bool
    {
        $removed = false;
        self::access(static function (array $entries) use ($stack, &$removed): array {
            $removed = array_key_exists($stack, $entries);
            unset($entries[$stack]);
            return $entries;
        });
        return $removed;
    }

    /** @param int|array{timestamp:int,token:string} $entry */
    private static function entryToken($entry, string $epoch = ''): string
    {
        return hash('sha256', (is_int($entry) ? 'legacy:' . $entry : $entry['token']) . ':' . $epoch);
    }

    /** @param callable(array):array|null $change
     *  @return array<string|int, int|array{timestamp:int,token:string}>
     */
    private static function access(?callable $change = null): array
    {
        $lock = @fopen(PENDING_RECHECK_FILE . '.lock', 'c+');
        if ($lock === false) throw new RuntimeException('Unable to open pending recheck initialization lock.');
        try {
            if (!flock($lock, $change === null ? LOCK_SH : LOCK_EX)) {
                throw new RuntimeException('Unable to lock pending recheck initialization.');
            }
            clearstatcache(true, PENDING_RECHECK_FILE);
            return self::accessLocked($change);
        } finally {
            fclose($lock);
        }
    }

    /** @param callable(array):array|null $change
     *  @return array<string|int, int|array{timestamp:int,token:string}>
     */
    private static function accessLocked(?callable $change): array
    {
        if (!file_exists(PENDING_RECHECK_FILE) && $change === null) return [];
        // All readers and writers lock this inode; never unlink or replace it.
        $created = false;
        if ($change !== null) {
            $handle = @fopen(PENDING_RECHECK_FILE, 'x+');
            $created = $handle !== false;
            if ($handle === false) $handle = @fopen(PENDING_RECHECK_FILE, 'r+');
        } else {
            $handle = @fopen(PENDING_RECHECK_FILE, 'r');
        }
        if ($handle === false) throw new RuntimeException('Unable to open pending rechecks.');
        try {
            if (!flock($handle, $change === null ? LOCK_SH : LOCK_EX)) {
                throw new RuntimeException('Unable to lock pending rechecks.');
            }
            $raw = stream_get_contents($handle);
            if ($raw === false) throw new RuntimeException('Unable to read pending rechecks.');
            // Only a new file may be empty; existing empty data is damaged evidence.
            if ($raw === '') {
                if (!$created) {
                    throw new RuntimeException('Invalid pending rechecks.');
                }
                $entries = [];
            } else {
                $entries = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($entries)) throw new RuntimeException('Invalid pending rechecks.');
            }
            foreach ($entries as $stack => $entry) {
                self::validateStacks([(string) $stack]);
                if (is_int($entry) && $entry > 0) continue;
                if (!is_array($entry) || !is_int($entry['timestamp'] ?? null) || $entry['timestamp'] < 1
                    || !is_string($entry['token'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $entry['token']) !== 1) {
                    throw new RuntimeException('Invalid pending recheck entry.');
                }
            }
            if ($change === null) return $entries;
            $entries = $change($entries);
            $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (!ftruncate($handle, 0) || !rewind($handle)
                || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('Unable to save pending rechecks.');
            }
            return $entries;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
