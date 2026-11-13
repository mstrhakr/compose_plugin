<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/SharedStackSources.php';

final class SharedStackSourcesTest extends TestCase
{
    public function testMapsFilesRatherThanDirectoriesIncludingMissingEnvAndSymlinkAliases(): void
    {
        $root = $this->createTempDir();
        $a = \StackInfo::createNew($root, 'a');
        $b = \StackInfo::createNew($root, 'b');
        file_put_contents($a->path . '/other.yaml', "services:\n  app:\n    image: busybox\n");
        symlink($a->path, $b->path . '/source-link');
        file_put_contents($b->path . '/indirect', $b->path . '/source-link/other.yaml');
        file_put_contents($b->path . '/indirect_mode', 'file');
        \StackInfo::clearCache();
        $a = \StackInfo::fromProject($root, 'a');
        $result = \SharedStackSources::inspect($root, $a);
        $shared = array_column($result['shared'], 'stacks', 'path');
        $this->assertArrayNotHasKey($a->composeFilePath, $shared);
        $this->assertSame(['b'], $shared[$a->path . '/.env']);
        $this->assertFalse($result['dismissed']);
        $this->assertSame([], $result['incomplete']);
        file_put_contents($b->path . '/indirect', $b->path . '/source-link/compose.yaml');
        \StackInfo::clearCache();
        $changed = \SharedStackSources::inspect($root, $a, $a->composeFilePath);
        $this->assertSame(['b'], $changed['shared'][0]['stacks']);
        $this->assertNotSame($result['confirmation'], $changed['confirmation']);
        unlink($b->path . '/source-link');
    }

    public function testDefaultDiscoveryAndHardLinksAreRecognizedWithoutGeneratedIconInputs(): void
    {
        $root = $this->createTempDir();
        $a = \StackInfo::createNew($root, 'a');
        $b = \StackInfo::createNew($root, 'b');
        unlink($b->composeFilePath);
        link($a->composeFilePath, $b->composeFilePath);
        file_put_contents($b->path . '/use_default_compose_files', 'true');
        \StackInfo::clearCache();
        $result = \SharedStackSources::inspect($root, $a, $a->composeFilePath);
        $this->assertSame(['b'], $result['shared'][0]['stacks']);
        $this->assertSame([], $result['incomplete']);
        $this->assertNotContains(COMPOSE_ICON_NORMALIZE_DIR . '/' . $a->projectName . '.yaml', $a->getSourceFilePaths());
    }

    public function testDanglingEnvAliasesAreReportedBeforeTargetExists(): void
    {
        $root = $this->createTempDir();
        $a = \StackInfo::createNew($root, 'a');
        $b = \StackInfo::createNew($root, 'b');
        symlink('../missing.env', $a->path . '/.env');
        symlink($a->path . '/.env', $b->path . '/.env');
        \StackInfo::clearCache();
        $result = \SharedStackSources::inspect($root, \StackInfo::fromProject($root, 'a'), $a->path . '/.env');
        $this->assertSame([['path' => $a->path . '/.env', 'stacks' => ['b']]], $result['shared']);
        $this->assertSame([], $result['incomplete']);
    }
}
