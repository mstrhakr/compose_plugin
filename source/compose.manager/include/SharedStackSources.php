<?php

declare(strict_types=1);

require_once __DIR__ . '/StackSourceEditor.php';

final class SharedStackSources
{
    private static function key(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) return $real;
        $parent = realpath(dirname($path));
        return ($parent === false ? dirname($path) : $parent) . '/' . basename($path);
    }

    public static function dismissed(StackInfo $stack): bool
    {
        $file = $stack->path . '/shared_source_warning_dismissed';
        if (!file_exists($file)) return false;
        $value = @file_get_contents($file);
        if ($value === false) throw new RuntimeException('Unable to read the shared-source warning preference.');
        return trim($value) === 'true';
    }

    /** @param string|list<string>|null $target
     *  @return array{shared: list<array{path: string, stacks: list<string>}>, dismissed: bool, incomplete: list<string>, confirmation: string}
     */
    public static function inspect(string $root, StackInfo $stack, string|array|null $target = null): array
    {
        $paths = $target === null ? $stack->getSourceFilePaths() : (is_array($target) ? $target : [$target]);
        $keys = [];
        $inodes = [];
        foreach ($paths as $path) {
            $keys[self::key($path)] = $path;
            $stat = @stat($path);
            if ($stat !== false) $inodes[$stat['dev'] . ':' . $stat['ino']] = $path;
        }
        $matches = [];
        $incomplete = [];
        foreach (StackInfo::listProjectFolders($root) as $folder) {
            if ($folder === $stack->projectFolder) continue;
            try {
                $other = StackInfo::fromProject($root, $folder);
                foreach ($other->getSourceFilePaths() as $path) {
                    $stat = @stat($path);
                    $match = $keys[self::key($path)] ?? ($stat !== false ? ($inodes[$stat['dev'] . ':' . $stat['ino']] ?? null) : null);
                    if ($match !== null) $matches[$match][$folder] = true;
                }
            } catch (RuntimeException $error) {
                $incomplete[] = $folder;
                composeLogger('Unable to inspect shared stack sources: ' . $error->getMessage(), ['stack' => $folder], 'user', 'warning', 'editor');
            }
        }
        ksort($matches);
        $shared = [];
        foreach ($matches as $path => $folders) {
            $names = array_keys($folders);
            sort($names, SORT_STRING);
            $shared[] = ['path' => $path, 'stacks' => $names];
        }
        return [
            'shared' => $shared, 'dismissed' => self::dismissed($stack), 'incomplete' => $incomplete,
            'confirmation' => hash('sha256', json_encode([$stack->projectName, $target, $shared, $incomplete], JSON_THROW_ON_ERROR)),
        ];
    }
}
