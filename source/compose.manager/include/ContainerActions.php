<?php

declare(strict_types=1);

require_once __DIR__ . '/DockerCommand.php';
require_once __DIR__ . '/StackMutationGuard.php';

final class ContainerActions
{
    /** @return array{id: string, project: string} */
    private static function inspect(string $container): array
    {
        $result = DockerCommand::run(['inspect', '--type', 'container', '--', $container], null, 5);
        if (!$result->succeeded()) throw new RuntimeException($result->errorSummary());
        $inspection = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        $id = $inspection[0]['Id'] ?? null;
        $project = $inspection[0]['Config']['Labels']['com.docker.compose.project'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id) || !is_string($project)) {
            throw new RuntimeException('Docker returned invalid container identity.');
        }
        return ['id' => $id, 'project' => $project];
    }

    public static function run(string $container, string $action): string
    {
        if ($container === '' || !in_array($action, ['start', 'stop', 'restart', 'pause', 'unpause'], true)) {
            throw new InvalidArgumentException('Invalid container or action.');
        }
        $identity = self::inspect($container);
        $work = static function () use ($identity, $action): string {
            if (self::inspect($identity['id']) !== $identity) {
                throw new RuntimeException('Container identity changed. Refresh and try again.');
            }
            $result = DockerCommand::run([$action, '--', $identity['id']], null, 20);
            if (!$result->succeeded()) throw new RuntimeException($result->errorSummary());
            return trim($result->stdout);
        };
        // Native containers have no Compose operation owner, but still use the
        // inspected ID rather than a name that could be reassigned.
        return $identity['project'] === ''
            ? $work() : StackMutationGuard::mutate($identity['project'], '', $work);
    }
}
