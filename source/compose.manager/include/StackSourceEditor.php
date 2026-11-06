<?php

declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/StackMutationGuard.php';

final class StackSourceEditor
{
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
