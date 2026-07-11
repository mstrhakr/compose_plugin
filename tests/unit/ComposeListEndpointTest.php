<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\Mocks\FunctionMocks;
use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';

class ComposeListEndpointTest extends TestCase
{
    private string $testComposeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testComposeRoot = sys_get_temp_dir() . '/compose_list_test_' . getmypid();
        if (!is_dir($this->testComposeRoot)) {
            mkdir($this->testComposeRoot, 0755, true);
        }

        global $compose_root, $plugin_root, $sName;
        $compose_root = $this->testComposeRoot;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager';
        $sName = 'compose.manager';

        FunctionMocks::setPluginConfig('compose.manager', [
            'PROJECTS_FOLDER' => $this->testComposeRoot,
        ]);

        if (class_exists('StackInfo')) {
            \StackInfo::clearCache();
        }
        $_GET = [];
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testComposeRoot)) {
            $this->recursiveDelete($this->testComposeRoot);
        }
        if (class_exists('StackInfo')) {
            \StackInfo::clearCache();
        }
        $_GET = [];
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        foreach ((array)scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function createStack(string $name, ?string $iconUrl = null): void
    {
        $path = $this->testComposeRoot . '/' . $name;
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        file_put_contents($path . '/compose.yaml', "services:\n  app:\n    image: alpine:latest\n");
        if ($iconUrl !== null) {
            file_put_contents($path . '/icon_url', $iconUrl);
        }
    }

    private function executeComposeList(array $query = []): string
    {
        global $compose_root, $plugin_root, $sName;
        $compose_root = $this->testComposeRoot;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager';
        $sName = 'compose.manager';

        if (class_exists('StackInfo')) {
            \StackInfo::clearCache();
        }
        $_GET = $query;

        ob_start();
        include '/usr/local/emhttp/plugins/compose.manager/include/ComposeList.php';
        return (string)ob_get_clean();
    }

    public function testDefaultModeRendersRealHtmlForStack(): void
    {
        $this->createStack('alpha', 'http://example.com/icon.png');
        mkdir($this->testComposeRoot . '/not-a-stack', 0755, true);
        file_put_contents($this->testComposeRoot . '/not-a-stack/readme.txt', 'x');

        $output = $this->executeComposeList();

        $this->assertStringContainsString("class='compose-sortable'", $output);
        $this->assertStringContainsString("data-project='alpha'", $output);
        $this->assertStringContainsString("class='col-update compose-updatecolumn'", $output);
        $this->assertStringContainsString("onerror=\"this.src='/plugins/dynamix.docker.manager/images/question.png';\"", $output);
        $this->assertStringNotContainsString("data-project='not-a-stack'", $output);
    }

    public function testDefaultModeShowsEmptyStateWhenNoStacks(): void
    {
        $output = $this->executeComposeList();
        $this->assertStringContainsString('No Docker Compose stacks found', $output);
    }
}
