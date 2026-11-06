<?php

declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/StackMutationGuard.php';

final class StaleSourceRevision extends RuntimeException {}

final class StackSourceEditor
{
    /** @return array{content: string, revision: string, exists: bool} */
    public static function read(string $path): array
    {
        clearstatcache(true, $path);
        if (is_dir($path)) throw new RuntimeException('Target path is a directory.');
        $exists = file_exists($path);
        $content = $exists ? @file_get_contents($path) : '';
        if ($content === false) throw new RuntimeException('Unable to read ' . $path . '.');
        return ['content' => $content, 'revision' => self::revision($content, $exists), 'exists' => $exists];
    }

    public static function revision(string $content, bool $exists): string
    {
        return hash('sha256', ($exists ? 'file:' : 'missing:') . $content);
    }

    public static function assertRevision(string $path, mixed $expected): void
    {
        if (!is_string($expected) || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) {
            throw new InvalidArgumentException('A valid loaded file revision is required. Reopen the editor.');
        }
        if (!hash_equals(self::read($path)['revision'], $expected)) {
            throw new StaleSourceRevision('This file changed since it was loaded. Your edits have been retained. Copy them before reloading, then review and merge the current file.');
        }
    }

    public static function sourcePathKey(string $path): string
    {
        clearstatcache(true);
        $real = realpath($path);
        if ($real !== false) return $real;
        if (!str_starts_with($path, '/')) {
            $cwd = getcwd();
            if ($cwd === false) throw new RuntimeException('Unable to resolve source working directory.');
            $path = $cwd . '/' . $path;
        }
        $remaining = explode('/', $path);
        $resolved = [];
        $links = 0;
        while ($remaining !== []) {
            $part = array_shift($remaining);
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                array_pop($resolved);
                continue;
            }
            $link = @readlink('/' . implode('/', [...$resolved, $part]));
            if ($link !== false) {
                if (++$links > 40) throw new RuntimeException('Unable to resolve source path: too many symbolic links.');
                if (str_starts_with($link, '/')) $resolved = [];
                $remaining = [...explode('/', $link), ...$remaining];
            } else {
                $resolved[] = $part;
            }
        }
        return '/' . implode('/', $resolved);
    }

    /** @template T
     *  @param list<string> $paths
     *  @param callable(): T $work
     *  @return T
     */
    public static function withFileLocks(array $paths, callable $work): mixed
    {
        $keys = [];
        foreach ($paths as $path) {
            $keys[] = hash('sha256', 'path:' . self::sourcePathKey($path));
            $stat = @stat($path);
            if ($stat !== false) $keys[] = hash('sha256', 'inode:' . $stat['dev'] . ':' . $stat['ino']);
        }
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        $directory = StackMutationGuard::directory() . '/source-edits';
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create source edit lock directory.');
        }
        $handles = [];
        try {
            foreach ($keys as $key) {
                $handle = @fopen($directory . '/' . $key . '.lock', 'c+');
                if ($handle === false) throw new RuntimeException('Unable to open source edit lock.');
                $handles[] = $handle;
                if (!flock($handle, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another editor is saving this file. Try again after it finishes.');
            }
            return $work();
        } finally {
            foreach ($handles as $handle) fclose($handle);
        }
    }

    public static function write(string $path, string $contents): void
    {
        if (@file_put_contents($path, $contents) !== strlen($contents)) {
            throw new RuntimeException('Unable to save ' . $path . '.');
        }
    }

    public static function remove(string $path): void
    {
        if ((file_exists($path) || @readlink($path) !== false) && !@unlink($path)) {
            throw new RuntimeException('Unable to remove ' . $path . '.');
        }
    }

    /** @param string[] $paths
     *  @return array<string, string|false|array{link: string, contents: string|false}>
     */
    public static function snapshot(array $paths): array
    {
        $original = [];
        foreach ($paths as $file) {
            if (file_exists($file) && !is_file($file)) throw new RuntimeException('The save target is not a file: ' . $file);
            $original[$file] = is_file($file) ? @file_get_contents($file) : false;
            if (is_file($file) && $original[$file] === false) throw new RuntimeException('Unable to read ' . $file . '.');
            $link = @readlink($file);
            if ($link !== false) {
                $original[$file] = ['link' => $link, 'contents' => $original[$file]];
            }
        }
        return $original;
    }

    /** @param array<string, string|false|array{link: string, contents: string|false}> $original */
    public static function restore(array $original, RuntimeException $error): never
    {
        $failures = [];
        foreach ($original as $file => $bytes) {
            try {
                if (is_array($bytes)) {
                    if (@readlink($file) !== $bytes['link']) {
                        self::remove($file);
                        if (!@symlink($bytes['link'], $file)) throw new RuntimeException('Unable to restore source symlink ' . $file . '.');
                    }
                    if ($bytes['contents'] === false) continue;
                    $bytes = $bytes['contents'];
                }
                if ($bytes !== false && is_file($file) && @file_get_contents($file) === $bytes) continue;
                if ($bytes === false) self::remove($file);
                else self::write($file, $bytes);
            } catch (RuntimeException $restoreError) {
                $failures[] = $restoreError->getMessage();
            }
        }
        if ($failures !== []) {
            throw new RuntimeException($error->getMessage() . ' Restoring the previous source failed: ' . implode(' ', $failures), 0, $error);
        }
        throw $error;
    }

    public static function saveCompose(StackInfo $stack, string $path, string $contents, bool $migrate): void
    {
        $paths = [$path];
        $override = $migrate ? $stack->overrideInfo->getProjectOverridePath() : null;
        if ($override !== null && $override !== $path) $paths[] = $override;
        $original = self::snapshot($paths);
        $oldContent = $original[$path];
        if (is_array($oldContent)) $oldContent = $oldContent['contents'];
        try {
            self::write($path, $contents);
            if ($migrate && $oldContent !== false) {
                $stack->overrideInfo->migrateOnRename($oldContent, $contents);
            }
        } catch (RuntimeException $error) {
            self::restore($original, $error);
        }
    }
}
