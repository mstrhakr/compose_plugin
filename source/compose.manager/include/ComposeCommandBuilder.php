<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';

/**
 * Compose command argument builder for stack-scoped operations.
 *
 * Centralizes action-aware argument resolution so callers (PHP endpoints,
 * event scripts, autoupdate wrappers) can consume a single contract.
 */
class ComposeCommandBuilder
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
     * The only command construction path for individual, force, bulk and automatic updates.
     *
     * @param array<string, mixed> $cfg
     * @param array{profiles?: string[], recreate?: bool, automatic?: bool} $options
     * @return string[]
     */
    public static function buildUpdateCommand(StackInfo $stack, array $cfg, array $options = []): array
    {
        $spec = self::buildForAction($stack, 'update');
        $command = [dirname(__DIR__) . '/scripts/compose.sh', '-cupdate', '-p' . $spec['projectName']];
        if ($spec['useDefaultFileDiscovery']) {
            $command[] = '-w' . $spec['projectDirectory'];
        } else {
            foreach ($spec['composeFiles'] as $file) {
                $command[] = '-f' . $file;
            }
        }
        if (!empty($spec['envFilePath'])) {
            $command[] = '-e' . $spec['envFilePath'];
        }
        if ($spec['credentialId'] !== '') {
            $command[] = '--credential-id';
            $command[] = $spec['credentialId'];
        }
        foreach ($options['profiles'] ?? $spec['profiles'] as $profile) {
            $command[] = '-g' . $profile;
        }
        $command[] = '-s' . $spec['stackPath'];
        if (self::resolveBuildOnUpdate($stack->path, $cfg)) {
            $command[] = '--build';
        }
        if (!empty($options['recreate'])) {
            $command[] = '--recreate';
        }
        if (!empty($options['automatic'])) {
            $command[] = '--automatic-update';
        }
        if (($cfg['DEBUG_TO_LOG'] ?? 'false') === 'true') {
            $command[] = '--debug';
        }
        return $command;
    }

    /**
     * Both cron and Run Now use the same notification adapter and update command.
     *
     * @param array<string, mixed> $cfg
     */
    public static function automaticUpdateInvocation(StackInfo $stack, array $cfg, int $parallelLimit): string
    {
        $command = self::buildUpdateCommand($stack, $cfg, ['automatic' => true]);
        $command[0] = dirname(__DIR__) . '/scripts/compose_autoupdate.sh';
        $shell = getenv('COMPOSE_MANAGER_SH') ?: 'bash';
        return 'COMPOSE_PARALLEL_LIMIT=' . escapeshellarg((string) $parallelLimit) . ' '
            . $shell . ' ' . implode(' ', array_map('escapeshellarg', $command));
    }

    /**
     * Build resolved compose runtime arguments for a specific stack/action.
     *
     * @param StackInfo $stackInfo
     * @param string $action Supported: up, down, update, pull, stop, logs
     * @param string|null $stackPath Optional explicit stack path to include in output
        * @return array<string, mixed>
     */
    public static function buildForAction(StackInfo $stackInfo, string $action, ?string $stackPath = null): array
    {
        $action = strtolower(trim($action));
        self::assertSupportedAction($action);
        self::assertResolvedIdentity($stackInfo, $action);

        $args = $stackInfo->buildComposeArgs();
        $credentialId = in_array($action, ['up', 'update', 'pull'], true)
            ? trim((string) ($stackInfo->getCredentialId() ?? ''))
            : '';

        return [
            'action' => $action,
            'projectName' => $args['projectName'],
            'composeFiles' => $args['filePaths'],
            'envFilePath' => $args['envFilePath'] ?? null,
            'projectDirectory' => $args['projectDirectory'],
            'useDefaultFileDiscovery' => $args['useDefaultFileDiscovery'],
            'profiles' => self::resolveProfilesForAction($stackInfo, $action),
            'stackPath' => $stackPath ?? $stackInfo->path,
            'credentialId' => $credentialId,
        ];
    }

    /**
     * Resolve and build compose runtime arguments from compose root/project.
     *
     * @param string $composeRoot
     * @param string $project
     * @param string $action
     * @param string|null $stackPath
        * @return array<string, mixed>
     */
    public static function fromProject(string $composeRoot, string $project, string $action, ?string $stackPath = null): array
    {
        $stackInfo = StackInfo::fromProject($composeRoot, $project);
        return self::buildForAction($stackInfo, $action, $stackPath);
    }

    /**
     * @param string $action
     * @return string[]
     */
    private static function resolveProfilesForAction(StackInfo $stackInfo, string $action): array
    {
        if ($action === 'down') {
            return ['*'];
        }

        if ($action === 'update') {
            $profiles = $stackInfo->getRunningProfiles();
            if (empty($profiles)) {
                $profiles = $stackInfo->getDefaultProfiles();
            }
            return array_values($profiles);
        }

        return array_values($stackInfo->getDefaultProfiles());
    }

    /**
     * @param string $action
     */
    private static function assertSupportedAction(string $action): void
    {
        $allowed = ['up', 'down', 'update', 'pull', 'stop', 'logs'];
        if (!in_array($action, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported compose action: ' . $action);
        }
    }

    /**
     * Refuse to build mutating arguments for a stack whose runtime project
     * identity could not be proven (see {@see ProjectIdentity}).
     */
    private static function assertResolvedIdentity(StackInfo $stackInfo, string $action): void
    {
        if ($action === 'logs' || $stackInfo->hasResolvedIdentity()) {
            return;
        }

        throw new \RuntimeException(
            "Compose project identity for '{$stackInfo->projectFolder}' is unresolved: "
            . (string) $stackInfo->getIdentityBlockReason()
        );
    }
}
