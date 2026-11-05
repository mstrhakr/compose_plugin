<?php

declare(strict_types=1);

require_once __DIR__ . '/Defines.php';

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

    public static function token(string $stack): ?string
    {
        $entries = self::access();
        return isset($entries[$stack]) ? self::entryToken($entries[$stack]) : null;
    }

    /** @param list<string> $stacks
     *  @return list<string|int>
     */
    public static function mark(array $stacks): array
    {
        $stacks = self::validateStacks($stacks);
        $entries = self::access(static function (array $entries) use ($stacks): array {
            foreach ($stacks as $stack) {
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
        return array_keys(self::access(static function (array $entries) use ($stacks, $tokens): array {
            foreach ($stacks as $stack) {
                if (isset($entries[$stack]) && self::entryToken($entries[$stack]) === $tokens[$stack]) {
                    unset($entries[$stack]);
                }
            }
            return $entries;
        }));
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
    private static function entryToken($entry): string
    {
        return hash('sha256', is_int($entry) ? 'legacy:' . $entry : $entry['token']);
    }

    /** @param callable(array):array|null $change
     *  @return array<string|int, int|array{timestamp:int,token:string}>
     */
    private static function access(?callable $change = null): array
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
