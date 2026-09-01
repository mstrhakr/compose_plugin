<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\Mocks\FunctionMocks;
use PluginTests\TestCase;

class IconTest extends TestCase
{
    private string $testComposeRoot;
    private string $projectName = 'icon-test-stack';
    private string $projectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testComposeRoot = sys_get_temp_dir() . '/compose_icon_test_' . getmypid();
        $this->projectPath = $this->testComposeRoot . '/' . $this->projectName;

        if (!is_dir($this->projectPath)) {
            mkdir($this->projectPath, 0755, true);
        }

        FunctionMocks::setPluginConfig('compose.manager', [
            'PROJECTS_FOLDER' => $this->testComposeRoot,
        ]);

        $_GET = [];
        $_SERVER['DOCUMENT_ROOT'] = '/usr/local/emhttp';
        if (function_exists('header_remove')) {
            header_remove();
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testComposeRoot)) {
            $this->recursiveDelete($this->testComposeRoot);
        }
        $_GET = [];
        if (function_exists('header_remove')) {
            header_remove();
        }
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

    private function runEndpoint(string $project): array
    {
        $_GET = ['project' => $project];
        $_SERVER['DOCUMENT_ROOT'] = '/usr/local/emhttp';

        if (function_exists('header_remove')) {
            header_remove();
        }

        ob_start();
        include '/usr/local/emhttp/plugins/compose.manager/include/Icon.php';
        $output = (string)ob_get_clean();

        $headers = function_exists('xdebug_get_headers') ? xdebug_get_headers() : headers_list();

        return [
            'output' => $output,
            'headers' => $headers,
        ];
    }

    private function hasHeader(array $headers, string $needle): bool
    {
        foreach ($headers as $header) {
            if (stripos((string)$header, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    public function testEndpointServesPngIconBytes(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 64);
        file_put_contents($this->projectPath . '/icon.png', $png);

        $result = $this->runEndpoint($this->projectName);

        $this->assertSame($png, $result['output']);
        $this->assertTrue($this->hasHeader($result['headers'], 'Content-Type: image/png'));
    }

    public function testEndpointServesJpgMimeType(): void
    {
        $jpg = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 64);
        file_put_contents($this->projectPath . '/icon.jpg', $jpg);

        $result = $this->runEndpoint($this->projectName);

        $this->assertSame($jpg, $result['output']);
        $this->assertTrue($this->hasHeader($result['headers'], 'Content-Type: image/jpeg'));
    }

    public function testEndpointPrefersIconPngOverOtherIconFiles(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . str_repeat("\x11", 32);
        $jpg = "\xFF\xD8\xFF\xE0" . str_repeat("\x22", 32);

        file_put_contents($this->projectPath . '/icon.jpg', $jpg);
        file_put_contents($this->projectPath . '/icon.png', $png);

        $result = $this->runEndpoint($this->projectName);

        $this->assertSame($png, $result['output']);
    }

    public function testEndpointSupportsBareIconFilename(): void
    {
        $bytes = "GIF89a" . str_repeat("\x01", 32);
        file_put_contents($this->projectPath . '/icon', $bytes);

        $result = $this->runEndpoint($this->projectName);

        $this->assertSame($bytes, $result['output']);
        $this->assertTrue($this->hasHeader($result['headers'], 'Content-Type: image/png'));
    }
}
