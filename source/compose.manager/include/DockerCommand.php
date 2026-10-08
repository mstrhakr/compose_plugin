<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/ProcessRunner.php';

/**
 * Runs the docker CLI without a shell, with a time limit.
 */
final class DockerCommand
{
    public const DEFAULT_TIMEOUT_SECONDS = 120;

    /**
     * @param string[] $args Arguments after "docker"
     */
    public static function run(array $args, ?string $workingDirectory = null, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): ProcessResult
    {
        $environment = [
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME' => getenv('HOME') ?: '/root',
            'LC_ALL' => 'C',
        ];
        // Keep the registry credential compose.sh selected for this run, if any.
        $dockerConfig = getenv('DOCKER_CONFIG');
        if (is_string($dockerConfig) && $dockerConfig !== '') {
            $environment['DOCKER_CONFIG'] = $dockerConfig;
        }

        return ProcessRunner::run(
            array_merge([COMPOSE_DOCKER_BIN], $args),
            $workingDirectory ?? '/',
            $environment,
            $timeoutSeconds
        );
    }
}
