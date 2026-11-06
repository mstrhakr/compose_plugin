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
}
