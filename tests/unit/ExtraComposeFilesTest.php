<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/ComposeCommandBuilder.php';

class ExtraComposeFilesTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        \StackInfo::clearCache();
        $this->tempRoot = $this->createTempDir();
    }

    protected function tearDown(): void
    {
        \StackInfo::clearCache();
        parent::tearDown();
    }

    /**
     * @param string[] $extraLines
     */
    private function makeStack(string $stack, array $extraLines = []): string
    {
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents($stackDir . '/compose.yaml', "services:\n  web:\n    image: nginx\n");
        if (!empty($extraLines)) {
            file_put_contents($stackDir . '/extra_compose_files', implode("\n", $extraLines) . "\n");
        }
        return $stackDir;
    }

    public function testExtraAbsoluteFileIsAppended(): void
    {
        $stackDir = $this->makeStack('extra-abs');
        $extraFile = $stackDir . '/compose.gpu.yaml';
        file_put_contents($extraFile, "services:\n  web:\n    runtime: nvidia\n");
        file_put_contents($stackDir . '/extra_compose_files', $extraFile . "\n");

        $spec = \ComposeCommandBuilder::fromProject($this->tempRoot, 'extra-abs', 'up');

        // The app-managed override is optional and only included when its file exists.
        // With no override file, extras follow the main compose file.
        $this->assertSame(
            [$stackDir . '/compose.yaml', $extraFile],
            $spec['composeFiles']
        );
    }

    public function testExtraRelativePathResolvesAgainstComposeSource(): void
    {
        $stackDir = $this->makeStack('extra-rel', ['compose.gpu.yaml']);
        file_put_contents($stackDir . '/compose.gpu.yaml', "services:\n");

        $spec = \ComposeCommandBuilder::fromProject($this->tempRoot, 'extra-rel', 'up');

        $this->assertContains($stackDir . '/compose.gpu.yaml', $spec['composeFiles']);
    }

    public function testMissingExtraFileIsSkipped(): void
    {
        $stackDir = $this->makeStack('extra-missing', ['/nonexistent/extra.yaml']);

        $spec = \ComposeCommandBuilder::fromProject($this->tempRoot, 'extra-missing', 'up');

        $this->assertNotContains('/nonexistent/extra.yaml', $spec['composeFiles']);
        $this->assertContains($stackDir . '/compose.yaml', $spec['composeFiles']);
    }

    public function testCommentsAndBlankLinesAreIgnored(): void
    {
        $stackDir = $this->makeStack('extra-comments');
        file_put_contents($stackDir . '/compose.gpu.yaml', "services:\n");
        file_put_contents(
            $stackDir . '/extra_compose_files',
            "# gpu override\n\ncompose.gpu.yaml\n"
        );

        $spec = \ComposeCommandBuilder::fromProject($this->tempRoot, 'extra-comments', 'up');

        $this->assertContains($stackDir . '/compose.gpu.yaml', $spec['composeFiles']);
        $this->assertNotContains($stackDir . '/# gpu override', $spec['composeFiles']);
    }

    public function testDuplicateOfMainComposeFileIsDeduplicated(): void
    {
        $stackDir = $this->makeStack('extra-dup', ['compose.yaml']);

        $spec = \ComposeCommandBuilder::fromProject($this->tempRoot, 'extra-dup', 'up');

        $this->assertSame(
            [$stackDir . '/compose.yaml'],
            array_values(array_filter($spec['composeFiles'], static fn(string $p): bool => basename($p) === 'compose.yaml'))
        );
    }

    public function testExtraFilesDisableDefaultFileDiscovery(): void
    {
        $stackDir = $this->makeStack('extra-discovery', ['compose.gpu.yaml']);
        file_put_contents($stackDir . '/compose.gpu.yaml', "services:\n");
        file_put_contents($stackDir . '/use_default_compose_files', 'true');

        $spec = \ComposeCommandBuilder::fromProject($this->tempRoot, 'extra-discovery', 'up');

        $this->assertFalse($spec['useDefaultFileDiscovery']);
        $this->assertContains($stackDir . '/compose.gpu.yaml', $spec['composeFiles']);
    }

    public function testEditableComposeFilesListsAllStackFilesInOrder(): void
    {
        $stackDir = $this->makeStack('editable-files', ['compose.gpu.yaml']);
        file_put_contents($stackDir . '/compose.override.yaml', "services:\n");
        file_put_contents($stackDir . '/compose.gpu.yaml', "services:\n");

        $info = \StackInfo::fromProject($this->tempRoot, 'editable-files');
        $editable = $info->getEditableComposeFiles();

        $this->assertSame(
            [
                $stackDir . '/compose.yaml',
                $stackDir . '/compose.override.yaml',
                $stackDir . '/compose.gpu.yaml',
            ],
            $editable
        );
    }

    public function testExtraFileAppendsAfterOverride(): void
    {
        $stackDir = $this->makeStack('extra-order', ['compose.gpu.yaml']);
        file_put_contents($stackDir . '/compose.override.yaml', "services:\n");
        file_put_contents($stackDir . '/compose.gpu.yaml', "services:\n");

        $spec = \ComposeCommandBuilder::fromProject($this->tempRoot, 'extra-order', 'up');

        $this->assertSame(
            [
                $stackDir . '/compose.yaml',
                $stackDir . '/compose.override.yaml',
                $stackDir . '/compose.gpu.yaml',
            ],
            $spec['composeFiles']
        );
    }
}
