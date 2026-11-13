<?php

declare(strict_types=1);

require_once __DIR__ . '/Defines.php';

/**
 * Short critical sections only: Docker and registry work must run outside these locks.
 */
class StackMutationGuard
{
    public static function directory(): string
    {
        return getenv('COMPOSE_LOCK_DIR') ?: COMPOSE_LOCK_DIR;
    }

    /** @template T
     *  @param callable(string): T $work
     *  @return T|null null when a mutation owns the project lock
     */
    public static function inspect(string $project, callable $work): mixed
    {
        return self::inspectMany([$project], static function (array $epochs) use ($project, $work): mixed {
            return array_key_exists($project, $epochs) ? $work($epochs[$project]) : null;
        });
    }

    /** @template T
     *  @param list<string> $projects
     *  @param callable(array<string, string>): T $work
     *  @return T
     */
    public static function inspectMany(array $projects, callable $work): mixed
    {
        $projects = array_values(array_unique($projects));
        sort($projects, SORT_STRING);
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create stack operation lock directory.');
        }
        $handles = [];
        $epochs = [];
        try {
            foreach ($projects as $project) {
                self::validateProject($project);
                $handle = @fopen($directory . '/' . $project . '.lock', 'c+');
                if ($handle === false) throw new RuntimeException('Unable to open stack operation lock.');
                if (!flock($handle, LOCK_SH | LOCK_NB)) {
                    fclose($handle);
                    continue;
                }
                $handles[] = $handle;
                $epochs[$project] = self::epoch($project);
            }
            return $work($epochs);
        } finally {
            foreach ($handles as $handle) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    public static function epoch(string $project): string
    {
        self::validateProject($project);
        $path = self::directory() . '/' . $project . '.epoch';
        if (!file_exists($path)) return '';
        $epoch = @file_get_contents($path);
        if (!is_string($epoch) || preg_match('/^[a-f0-9]{32}$/D', $epoch) !== 1) {
            throw new RuntimeException('Invalid stack mutation epoch.');
        }
        return $epoch;
    }

    /** @return resource|null */
    public static function tryExclusive(string $project)
    {
        self::validateProject($project);
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create stack operation lock directory.');
        }
        $handle = @fopen($directory . '/' . $project . '.lock', 'c+');
        if ($handle === false) throw new RuntimeException('Unable to open stack operation lock.');
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @template T
     *  @param callable(): T $work
     *  @return T
     */
    public static function mutate(string $project, string $folder, callable $work): mixed
    {
        $handle = self::tryExclusive($project);
        if ($handle === null) throw new RuntimeException('Another operation is in progress. Try again when it has finished.');
        try {
            return self::duringMutation($project, $folder, $work);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** The caller must own the exclusive operation lock.
     *  @template T
     *  @param callable(): T $work
     *  @return T
     */
    public static function duringMutation(string $project, string $folder, callable $work): mixed
    {
        self::mutationBoundary($project, $folder, false);
        try {
            return $work();
        } finally {
            self::mutationBoundary($project, $folder, false);
        }
    }

    /** Called only while the mutation owns compose.sh's exclusive operation lock. */
    public static function mutationBoundary(string $project, string $folder, bool $createPending = true): void
    {
        self::validateProject($project);
        $epoch = bin2hex(random_bytes(16));
        if (file_put_contents(self::directory() . '/' . $project . '.epoch', $epoch) !== strlen($epoch)) {
            throw new RuntimeException('Unable to save stack mutation epoch.');
        }
        require_once __DIR__ . '/PendingRechecks.php';
        $folders = $folder !== '' ? [$folder] : self::foldersForProject($project);
        PendingRechecks::mark($folders, $createPending);
    }

    /** @return list<string> */
    private static function foldersForProject(string $project): array
    {
        global $compose_root;
        if (!isset($compose_root)) $compose_root = locate_compose_root('compose.manager');
        $folders = [];
        if (isset($compose_root) && is_dir($compose_root)) {
            require_once __DIR__ . '/Util.php';
            foreach (StackInfo::allFromRoot($compose_root, true) as $stack) {
                if ($stack->projectName === $project) $folders[] = $stack->projectFolder;
            }
        }
        return $folders ?: [$project];
    }

    public static function projectForFolder(string $folder): string
    {
        global $compose_root;
        if (isset($compose_root) && is_dir(rtrim($compose_root, '/') . '/' . $folder)) {
            require_once __DIR__ . '/Util.php';
            return StackInfo::fromProject($compose_root, $folder)->projectName;
        }
        // Deleted stacks can still have pending evidence; their valid project names
        // remain lockable without reading Docker or recreating a stack.
        require_once __DIR__ . '/ProjectNameSanitizer.php';
        return compose_manager_sanitize_project_name($folder);
    }

    private static function validateProject(string $project): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $project) !== 1) {
            throw new InvalidArgumentException('Invalid stack operation project name.');
        }
    }
}
