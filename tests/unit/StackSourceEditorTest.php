<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/StackSourceEditor.php';

final class StackSourceEditorTest extends TestCase
{
    public function testFailedRenameMigrationRestoresBothFilesAndPreservesTheirInodes(): void
    {
        $root = $this->createTempDir();
        $stack = \StackInfo::createNew($root, 'rollback');
        $main = $stack->composeFilePath;
        $override = $stack->path . '/compose.override.yaml';
        $oldMain = file_get_contents($main);
        $oldOverride = "services:\n  old:\n    labels:\n      example: keep\n";
        file_put_contents($override, $oldOverride);
        $inodes = [fileinode($main), fileinode($override)];
        $migration = $this->createMock(\OverrideInfo::class);
        $migration->method('getProjectOverridePath')->willReturn($override);
        $migration->expects($this->once())->method('migrateOnRename')->willReturnCallback(static function () use ($override): never {
            file_put_contents($override, 'partial migration');
            throw new \RuntimeException('Migration write failed.');
        });
        $stack->overrideInfo = $migration;
        try {
            \StackSourceEditor::saveCompose($stack, $main, "services:\n  renamed:\n    image: alpine\n", true);
            $this->fail('Migration failure was hidden.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Migration write failed.', $error->getMessage());
        }
        $this->assertSame($oldMain, file_get_contents($main));
        $this->assertSame($oldOverride, file_get_contents($override));
        $this->assertSame($inodes, [fileinode($main), fileinode($override)]);
    }

    public function testSettingsSnapshotRestoresModifiedDeletedAndNewFiles(): void
    {
        $directory = $this->createTempDir();
        file_put_contents($directory . '/old', 'previous');
        file_put_contents($directory . '/removed', 'keep');
        $snapshot = \StackSourceEditor::snapshot([
            $directory . '/old', $directory . '/removed', $directory . '/new',
        ]);
        \StackSourceEditor::write($directory . '/old', 'changed');
        \StackSourceEditor::remove($directory . '/removed');
        \StackSourceEditor::write($directory . '/new', 'new file');
        try {
            \StackSourceEditor::restore($snapshot, new \RuntimeException('Settings save failed.'));
        } catch (\RuntimeException $error) {
            $this->assertSame('Settings save failed.', $error->getMessage());
        }
        $this->assertSame('previous', file_get_contents($directory . '/old'));
        $this->assertSame('keep', file_get_contents($directory . '/removed'));
        $this->assertFileDoesNotExist($directory . '/new');
    }

    public function testFailedRestorationIsExplicitAndRetainsOriginalFailure(): void
    {
        $directory = $this->createTempDir();
        $file = $directory . '/source';
        mkdir($file);
        file_put_contents($directory . '/other', 'changed');
        try {
            \StackSourceEditor::restore([$file => 'previous', $directory . '/other' => 'restored'], new \RuntimeException('Primary failure.'));
            $this->fail('Restoration failure was hidden.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Primary failure. Restoring the previous source failed:', $error->getMessage());
            $this->assertSame('Primary failure.', $error->getPrevious()->getMessage());
        }
        $this->assertSame('restored', file_get_contents($directory . '/other'));
    }

    public function testRestorationKeepsDeletedSourceSymlinksAndTheirExternalBytes(): void
    {
        $directory = $this->createTempDir();
        file_put_contents($directory . '/external', 'previous');
        symlink('external', $directory . '/source');
        $snapshot = \StackSourceEditor::snapshot([$directory . '/source']);
        \StackSourceEditor::remove($directory . '/source');
        file_put_contents($directory . '/external', 'changed');
        try {
            \StackSourceEditor::restore($snapshot, new \RuntimeException('Settings failed.'));
        } catch (\RuntimeException $error) {
            $this->assertSame('Settings failed.', $error->getMessage());
        }
        $this->assertSame('external', readlink($directory . '/source'));
        $this->assertSame('previous', file_get_contents($directory . '/external'));
    }

    public function testCompareAndSaveLockRefusesAnotherWriterThroughSymlinkOrHardLink(): void
    {
        $directory = $this->createTempDir();
        file_put_contents($directory . '/source', 'original');
        symlink('source', $directory . '/symlink');
        link($directory . '/source', $directory . '/hardlink');
        \StackSourceEditor::withFileLocks([$directory . '/source'], function () use ($directory): void {
            foreach (['source', 'symlink', 'hardlink'] as $file) {
                try {
                    \StackSourceEditor::withFileLocks([$directory . '/' . $file], static fn() => true);
                    $this->fail('A concurrent alias writer was allowed.');
                } catch (\RuntimeException $error) {
                    $this->assertStringContainsString('Another editor is saving', $error->getMessage());
                }
            }
        });
        $this->assertTrue(\StackSourceEditor::withFileLocks([$directory . '/source'], static fn() => true));
    }

    public function testDanglingSourceAliasesShareLocksAndRejectStaleMissingRevisions(): void
    {
        $directory = $this->createTempDir();
        mkdir($directory . '/a');
        mkdir($directory . '/b');
        symlink('../missing', $directory . '/a/.env');
        symlink($directory . '/a/.env', $directory . '/b/.env');
        symlink($directory . '/a', $directory . '/parent');
        $paths = [$directory . '/a/.env', $directory . '/b/.env', $directory . '/parent/.env'];
        $missing = \StackSourceEditor::read($paths[0])['revision'];
        foreach ($paths as $path) {
            $this->assertSame($directory . '/missing', \StackSourceEditor::sourcePathKey($path));
        }
        \StackSourceEditor::withFileLocks([$paths[0]], function () use ($paths): void {
            foreach (array_slice($paths, 1) as $path) {
                try {
                    \StackSourceEditor::withFileLocks([$path], static fn() => true);
                    $this->fail('Dangling alias bypassed source lock.');
                } catch (\RuntimeException $error) {
                    $this->assertStringContainsString('Another editor is saving', $error->getMessage());
                }
            }
            \StackSourceEditor::write($paths[0], 'first save');
        });
        $this->expectException(\StaleSourceRevision::class);
        \StackSourceEditor::withFileLocks([$paths[1]], static function () use ($paths, $missing): void {
            \StackSourceEditor::assertRevision($paths[1], $missing);
        });
    }

    public function testCyclicSourceLinksFailExplicitly(): void
    {
        $directory = $this->createTempDir();
        symlink('b', $directory . '/a');
        symlink('a', $directory . '/b');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too many symbolic links');
        \StackSourceEditor::sourcePathKey($directory . '/a');
    }
}
