<?php

declare(strict_types=1);

require_once __DIR__ . '/UpdateCheck.php';
require_once __DIR__ . '/ComposeCommandBuilder.php';

/**
 * Shared stack-level update workflow. Registry comparisons remain in ComposeUpdateCheck.
 */
class StackUpdateService
{
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

    /**
     * @param string[]|null $profiles Explicit empty selection must not fall back.
     * @return array<string, mixed>
     */
    public static function buildUpdateArguments(StackInfo $stack, ?string $stackPath = null, ?array $profiles = null): array
    {
        if ($profiles === null) {
            $profiles = $stack->getRunningProfiles();
            if (empty($profiles)) {
                $profiles = $stack->getDefaultProfiles();
            }
        }
        return ComposeCommandBuilder::buildForAction($stack, 'update', $stackPath, array_values($profiles));
    }

    /**
     * @param array<string, mixed> $cfg
     * @param array{profiles?: string[], recreate?: bool, automatic?: bool, includeStopped?: bool} $options
     * @return string[]
     */
    public static function buildUpdateCommand(StackInfo $stack, array $cfg, array $options = []): array
    {
        $spec = self::buildUpdateArguments($stack, null, $options['profiles'] ?? null);
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
        $results = [];
        foreach ($stacks as $stack) {
            $rows = $stack->getContainerList();
            $checks = [];
            if ($rows) {
                $checker = new ComposeUpdateCheck(null, $stack->getCredentialId());
                $checks = $checker->checkContainers($rows, $stack->getBuildConfigs());
            }
            $updates = [];
            $hasUpdate = false;
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
                    'service' => self::resolveContainerService($container),
                    'image' => $image,
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
                $hasUpdate = $hasUpdate || $check['hasUpdate'];
            }
            $results[$stack->projectFolder] = [
                'projectName' => $stack->projectFolder,
                'hasUpdate' => $hasUpdate,
                'containers' => $updates,
            ];
        }
        $this->saveResults($results, $replaceSavedStatus);
        return $results;
    }

    public static function resolveContainerService(array $container): string
    {
        $service = trim((string) ($container['Service'] ?? $container['service'] ?? ''));
        if ($service !== '') {
            return $service;
        }
        $labels = $container['Labels'] ?? $container['labels'] ?? '';
        if (is_array($labels)) {
            return trim((string) ($labels['com.docker.compose.service'] ?? ''));
        }
        if (is_string($labels)
            && preg_match('/(?:^|,)\s*com\.docker\.compose\.service=([^,]+)/', $labels, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    private function shortDigest(string $digest): string
    {
        return str_starts_with($digest, 'sha256:') ? substr($digest, 7, 12) : $digest;
    }

    private function saveResults(array $results, bool $replaceSavedStatus): void
    {
        $handle = fopen(COMPOSE_UPDATE_STATUS_FILE, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open stack update status file.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock stack update status file.');
            }
            $raw = stream_get_contents($handle);
            if ($raw === false) {
                throw new RuntimeException('Unable to read stack update status.');
            }
            $saved = !$replaceSavedStatus && $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
            if (!is_array($saved)) {
                throw new RuntimeException('Invalid saved stack update status.');
            }
            $now = time();
            foreach ($results as $name => $result) {
                $saved[$name] = $result + ['lastChecked' => $now];
            }
            $json = json_encode($saved, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (!ftruncate($handle, 0) || !rewind($handle)
                || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('Unable to save stack update status.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
