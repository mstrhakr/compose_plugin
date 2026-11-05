<?php

declare(strict_types=1);

require_once __DIR__ . '/Util.php';

class ContainerMetadataCache
{
    public static function read(): array
    {
        return self::access();
    }

    /** @param list<string> $projects
     *  @return array<string|int, string>
     */
    public static function begin(array $projects): array
    {
        $tokens = [];
        self::access(static function (array $cache, array &$owners) use ($projects, &$tokens): array {
            foreach ($projects as $project) $tokens[$project] = $owners[$project] = bin2hex(random_bytes(16));
            return $cache;
        });
        return $tokens;
    }

    /** @param array<string|int, string> $tokens
     *  @param array<string|int, array> $patches
     */
    public static function merge(array $patches, array $tokens): void
    {
        self::access(static function (array $cache, array &$owners) use ($patches, $tokens): array {
            foreach ($patches as $project => $services) {
                if (!isset($tokens[$project]) || ($owners[$project] ?? null) !== $tokens[$project]) continue;
                foreach ($services as $service => $patch) {
                    $cache[$project][$service] = ContainerInfo::fromDockerInspect(
                        array_replace($cache[$project][$service] ?? [], $patch)
                    )->toMetadataArray();
                }
            }
            return $cache;
        });
    }

    public static function remove(string $project): bool
    {
        $removed = false;
        self::access(static function (array $cache, array &$owners) use ($project, &$removed): array {
            $removed = array_key_exists($project, $cache);
            unset($cache[$project], $owners[$project]);
            return $cache;
        });
        return $removed;
    }

    public static function clearEvidence(): void
    {
        self::access(static function (array $cache, array &$owners): array {
            $owners = [];
            return $cache;
        }, true);
    }

    /** @param callable(array, array&):array|null $change */
    private static function access(?callable $change = null, bool $recover = false): array
    {
        if ($change === null && !is_file(COMPOSE_CONTAINER_CACHE_FILE)) return [];
        $created = false;
        if ($change !== null && !is_file(COMPOSE_CONTAINER_CACHE_FILE)) {
            $handle = @fopen(COMPOSE_CONTAINER_CACHE_FILE, 'x+');
            $created = $handle !== false;
        }
        if (!$created) $handle = fopen(COMPOSE_CONTAINER_CACHE_FILE, $change === null ? 'r' : 'c+');
        if ($handle === false) throw new RuntimeException('Unable to open container metadata.');
        try {
            if (!flock($handle, $change === null ? LOCK_SH : LOCK_EX)) throw new RuntimeException('Unable to lock container metadata.');
            $raw = stream_get_contents($handle);
            if ($raw === false) throw new RuntimeException('Unable to read container metadata.');
            try {
                $cache = $created ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $owners = [];
                if (!is_array($cache)) throw new RuntimeException('Invalid container metadata.');
                if (($cache['version'] ?? null) === 1) {
                    if (!is_array($cache['projects'] ?? null) || !is_array($cache['owners'] ?? null)) {
                        throw new RuntimeException('Invalid container metadata ownership.');
                    }
                    $owners = $cache['owners'];
                    $cache = $cache['projects'];
                    foreach ($owners as $token) {
                        if (!is_string($token) || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
                            throw new RuntimeException('Invalid container metadata generation.');
                        }
                    }
                }
                foreach ($cache as &$services) {
                    if (!is_array($services)) throw new RuntimeException('Invalid container metadata project.');
                    foreach ($services as &$container) {
                        if (!is_array($container)) throw new RuntimeException('Invalid container metadata service.');
                        $container = ContainerInfo::fromDockerInspect($container)->toMetadataArray();
                    }
                    unset($container);
                }
                unset($services);
            } catch (JsonException | RuntimeException $e) {
                if (!$recover) throw $e;
                composeLogger('Explicitly clearing corrupt container metadata: ' . $e->getMessage(), null, 'user', 'warning', 'metadata');
                $cache = $owners = [];
            }
            if ($change === null) return $cache;
            $cache = $change($cache, $owners);
            $json = json_encode(['version' => 1, 'projects' => $cache, 'owners' => $owners], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (!ftruncate($handle, 0) || !rewind($handle)
                || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('Unable to save container metadata.');
            }
            return $cache;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
