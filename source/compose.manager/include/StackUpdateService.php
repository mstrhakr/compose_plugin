<?php

declare(strict_types=1);

require_once __DIR__ . '/UpdateCheck.php';
require_once __DIR__ . '/ComposeCommandBuilder.php';

/**
 * Shared stack-level update workflow. Registry comparisons remain in ComposeUpdateCheck.
 */
class StackUpdateService
{
    /** @var list<string> */
    public array $superseded = [];

    /** @param array<string, mixed> $cfg */
    public static function resolveBuildOnUpdate(string $stackPath, array $cfg): bool
    {
        $buildFile = rtrim($stackPath, '/') . '/build_on_update';
        if (is_file($buildFile)) {
            $raw = trim((string) file_get_contents($buildFile));
            if ($raw !== '') {
                return $raw === 'true' || $raw === '1';
            }
        }
        return ($cfg['BUILD_ON_UPDATE_DEFAULT'] ?? 'false') === 'true';
    }

    /** @return array<string, mixed> */
    public static function resolveUpdateSpec(StackInfo $stack, ?string $stackPath = null): array
    {
        return ComposeCommandBuilder::buildForAction($stack, 'update', $stackPath, []);
    }

    /**
     * @param array<string, mixed> $cfg
     * @param array{recreate?: bool, automatic?: bool, includeStopped?: bool} $options
     * @return string[]
     */
    public static function buildUpdateCommand(StackInfo $stack, array $cfg, array $options = []): array
    {
        $spec = self::resolveUpdateSpec($stack);
        return ComposeCommandBuilder::buildUpdateCommand($spec, [
            'build' => self::resolveBuildOnUpdate($stack->path, $cfg),
            'recreate' => !empty($options['recreate']),
            'automatic' => !empty($options['automatic']),
            'includeStopped' => empty($options['automatic'])
                && ($options['includeStopped'] ?? (($cfg['UPDATE_INCLUDE_STOPPED_DEFAULT'] ?? 'false') === 'true')),
            'debug' => ($cfg['DEBUG_TO_LOG'] ?? 'false') === 'true',
        ]);
    }

    /** @param array<string, mixed> $cfg */
    public static function automaticUpdateInvocation(StackInfo $stack, array $cfg, int $parallelLimit): string
    {
        $command = self::buildUpdateCommand($stack, $cfg, ['automatic' => true]);
        $command[0] = dirname(__DIR__) . '/scripts/compose_autoupdate.sh';
        $shell = getenv('COMPOSE_MANAGER_SH') ?: 'bash';
        return 'COMPOSE_PARALLEL_LIMIT=' . escapeshellarg((string) $parallelLimit) . ' '
            . $shell . ' ' . implode(' ', array_map('escapeshellarg', $command));
    }

    /**
     * Inspect existing containers without changing their runtime state.
     * Presentation enrichment is supplied by the caller, not the image checker.
     *
     * @param StackInfo[] $stacks
     * @param callable(string, ContainerInfo): void|null $enrich
     * @return array<string, array{projectName: string, hasUpdate: bool, containers: array}>
     */
    public function checkStacks(array $stacks, ?callable $enrich = null, bool $replaceSavedStatus = false): array
    {
        $ownership = self::reserveChecks(array_map(static fn(StackInfo $stack): string => $stack->projectFolder, $stacks), $replaceSavedStatus);
        $this->superseded = [];
        $results = [];
        $checkers = [];
        $runtime = $stacks ? StackInfo::containersByProject(
            count($stacks) === 1 ? $stacks[0]->projectName : null, true
        ) : [];
        foreach ($stacks as $stack) {
            $rows = array_values(array_filter(
                $runtime[$stack->projectName] ?? [],
                static fn(array $row): bool => !ContainerInfo::isOneOff($row)
            ));
            $checks = [];
            if ($rows) {
                $credentialId = $stack->getCredentialId();
                $checker = $checkers[$credentialId ?? ''] ??= new ComposeUpdateCheck(null, $credentialId);
                $checks = $checker->checkContainers($rows, $stack->getBuildConfigs());
            }
            $updates = [];
            foreach ($rows as $row) {
                $container = array_change_key_case($row, CASE_LOWER);
                $name = trim((string) ($container['name'] ?? $container['names'] ?? ''));
                $image = trim((string) ($container['image'] ?? ''));
                if ($name === '' || $image === '') {
                    continue;
                }
                $check = $checks[$name] ?? [
                    'local' => '', 'remote' => '', 'status' => 'unknown', 'hasUpdate' => false,
                ];
                $update = ContainerInfo::fromUpdateResponse([
                    'container' => $name,
                    'service' => ContainerInfo::resolveService($container),
                    'image' => $check['image'] ?? $image,
                    'icon' => '',
                    'hasUpdate' => $check['hasUpdate'],
                    'status' => $check['status'],
                    'localSha' => $this->shortDigest((string) $check['local']),
                    'remoteSha' => $this->shortDigest((string) $check['remote']),
                    'localImageSha' => $this->shortDigest((string) ($check['localImage'] ?? '')),
                    'updateKind' => $check['updateKind'] ?? 'image',
                    'updateReadiness' => $check['updateReadiness'] ?? 'unknown',
                ]);
                if ($enrich !== null) {
                    $enrich($stack->projectFolder, $update);
                }
                $updates[] = $update->toUpdateArray();
            }
            $results[$stack->projectFolder] = [
                'projectName' => $stack->projectFolder,
                'containers' => $updates,
            ];
        }
        $accepted = $this->saveResults($results, $replaceSavedStatus, $ownership);
        $this->superseded = array_map('strval', array_keys(array_diff_key($results, $accepted)));
        return $accepted;
    }

    /** @param list<string> $projects
     *  @return array<string|int, string>
     */
    private static function reserveChecks(array $projects, bool $full): array
    {
        $ownership = [];
        self::accessSavedResults(static function (array $saved, array &$generations) use ($projects, $full, &$ownership): array {
            $names = array_fill_keys($projects, true);
            if ($full) $names += array_fill_keys(array_keys($saved), true);
            foreach ($names as $name => $_) {
                $ownership[$name] = $generations[$name] = bin2hex(random_bytes(16));
            }
            return $saved;
        }, $full);
        return $ownership;
    }

    private function shortDigest(string $digest): string
    {
        return str_starts_with($digest, 'sha256:') ? substr($digest, 7, 12) : $digest;
    }

    /** @param array<string|int, string> $ownership */
    private function saveResults(array $results, bool $replaceSavedStatus, array $ownership): array
    {
        $accepted = [];
        $saved = self::accessSavedResults(static function (array $saved, array &$generations) use ($results, $replaceSavedStatus, $ownership, &$accepted): array {
            $now = time();
            $next = $saved;
            if ($replaceSavedStatus) {
                foreach ($ownership as $name => $token) {
                    if (!array_key_exists($name, $results) && ($generations[$name] ?? null) === $token) {
                        unset($next[$name], $generations[$name]);
                    }
                }
            }
            foreach ($results as $name => $result) {
                if (!isset($ownership[$name]) || ($generations[$name] ?? null) !== $ownership[$name]) continue;
                $accepted[$name] = true;
                $previous = [];
                foreach ($saved[$name]['containers'] ?? [] as $container) {
                    $previous[$container['name']] = ContainerInfo::fromUpdateResponse($container);
                }
                $result['hasUpdate'] = false;
                foreach ($result['containers'] as &$container) {
                    $fresh = ContainerInfo::fromUpdateResponse($container);
                    $old = $previous[$fresh->name] ?? null;
                    if ($fresh->updateStatus === 'unknown' && !$fresh->isPinned && $old !== null
                        && $old->hasUpdate && $fresh->pinnedDigest === $old->pinnedDigest
                        && ContainerInfo::normalizeImageForUpdateCheck($fresh->image)
                            === ContainerInfo::normalizeImageForUpdateCheck($old->image)
                        && ($fresh->localSha === '' || $fresh->localSha === $old->localSha)) {
                        $old->mergeUpdateStatus($fresh);
                        $container = array_replace($old->toUpdateArray(), [
                            'service' => $fresh->service, 'image' => $fresh->image, 'icon' => $fresh->icon,
                        ]);
                    }
                    $result['hasUpdate'] = $result['hasUpdate'] || ($container['hasUpdate'] && !$container['isPinned']);
                }
                unset($container);
                $next[$name] = $result + ['lastChecked' => $now];
            }
            return $next;
        }, $replaceSavedStatus);
        return array_intersect_key($saved, $accepted);
    }

    public static function readSavedResults(): array
    {
        $saved = self::accessSavedResults();
        foreach ($saved as &$stack) {
            if (!isset($stack['containers'])) {
                continue;
            }
            $stack['hasUpdate'] = false;
            foreach ($stack['containers'] as &$container) {
                $info = ContainerInfo::fromUpdateResponse($container);
                if ($info->oneOff) {
                    $container = $info->toUpdateArray();
                } elseif ($info->isPinned) {
                    $container['hasUpdate'] = false;
                    $container['isPinned'] = true;
                    $container['pinnedDigest'] = $info->pinnedDigest;
                }
                $stack['hasUpdate'] = $stack['hasUpdate'] || ($info->hasUpdate && !$info->isPinned);
            }
            unset($container);
        }
        unset($stack);
        return $saved;
    }

    public static function clearSavedResults(): void
    {
        self::accessSavedResults(static function (array $saved, array &$generations): array {
            $generations = [];
            return [];
        }, true);
    }

    public static function removeSavedResult(string $stackName): bool
    {
        $removed = false;
        self::accessSavedResults(static function (array $saved, array &$generations) use ($stackName, &$removed): array {
            $removed = array_key_exists($stackName, $saved);
            unset($saved[$stackName], $generations[$stackName]);
            return $saved;
        });
        return $removed;
    }

    /** @param callable(array, array&): array|null $change */
    private static function accessSavedResults(?callable $change = null, bool $replace = false): array
    {
        if ($change === null && !is_file(COMPOSE_UPDATE_STATUS_FILE)) {
            return [];
        }
        // Never unlink this file: every reader and writer must lock the same inode.
        $handle = fopen(COMPOSE_UPDATE_STATUS_FILE, $change === null ? 'r' : 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open stack update status file.');
        }
        try {
            if (!flock($handle, $change === null ? LOCK_SH : LOCK_EX)) {
                throw new RuntimeException('Unable to lock stack update status file.');
            }
            $raw = stream_get_contents($handle);
            if ($raw === false) {
                throw new RuntimeException('Unable to read stack update status.');
            }
            try {
                $saved = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
            } catch (JsonException $e) {
                if (!$replace) {
                    throw $e;
                }
                composeLogger('Replacing corrupt saved stack update status: ' . $e->getMessage(), null, 'user', 'warning', 'update-check');
                $saved = [];
            }
            if (!is_array($saved)) {
                if (!$replace) {
                    throw new RuntimeException('Invalid saved stack update status.');
                }
                composeLogger('Replacing invalid saved stack update status.', null, 'user', 'warning', 'update-check');
                $saved = [];
            }
            $generations = [];
            if (($saved['version'] ?? null) === 1) {
                if (!is_array($saved['stacks'] ?? null) || !is_array($saved['generations'] ?? null)) {
                    throw new RuntimeException('Invalid saved update ownership.');
                }
                $generations = $saved['generations'];
                foreach ($generations as $token) {
                    if (!is_string($token) || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
                        throw new RuntimeException('Invalid saved update generation.');
                    }
                }
                $saved = $saved['stacks'];
            }
            if ($change === null) {
                return $saved;
            }
            $saved = $change($saved, $generations);
            $json = json_encode(['version' => 1, 'stacks' => $saved, 'generations' => $generations], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (!ftruncate($handle, 0) || !rewind($handle)
                || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('Unable to save stack update status.');
            }
            return $saved;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
