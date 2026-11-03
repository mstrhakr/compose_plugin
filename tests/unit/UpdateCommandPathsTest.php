<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/Helpers.php';

final class UpdateCommandPathsTest extends TestCase
{
    public function testIndividualForceAndBulkUseTheSameUpdateCommand(): void
    {
        global $compose_root, $plugin_root, $sName, $socket_name;
        $compose_root = $this->createTempDir();
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager/';
        $sName = 'compose.manager';
        $socket_name = 'update-paths-test';
        $this->mockPluginConfig($sName, [
            'DEBUG_TO_LOG' => 'false',
            'BUILD_ON_UPDATE_DEFAULT' => 'true',
        ]);
        \StackInfo::clearCache();
        $dir = $compose_root . '/update-paths';
        mkdir($dir);
        file_put_contents($dir . '/compose.yaml', "services:\n  web:\n    image: nginx\n");
        file_put_contents($dir . '/running_profiles', 'active');
        $varDir = $this->createTempDir();
        file_put_contents($varDir . '/var.ini', "mdState=STARTED\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', $varDir . '/var.ini');
        $stack = \StackInfo::fromProject($compose_root, 'update-paths');
        $cfg = ['BUILD_ON_UPDATE_DEFAULT' => 'true'];

        foreach (['individual', 'force', 'bulk'] as $entry) {
            $GLOBALS['composeLoggerMessages'] = [];
            $_POST = [
                'action' => $entry === 'bulk' ? 'composeUpdateMultiple' : 'composeUpPullBuild',
                'path' => $dir,
                'paths' => json_encode([$dir]),
                'recreate' => $entry === 'force' ? '1' : '0',
            ];
            ob_start();
            include '/usr/local/emhttp/plugins/compose.manager/include/ComposeUtil.php';
            ob_end_clean();
            $expected = \StackUpdateService::buildUpdateCommand($stack, $cfg, ['recreate' => $entry === 'force']);
            $quoted = implode(' ', array_map('escapeshellarg', $expected));
            $logs = implode("\n", $GLOBALS['composeLoggerMessages']);
            if ($entry === 'bulk') {
                $this->assertSame(1, preg_match('/Multi-stack script created: ([^\n]+)/', $logs, $matches));
                $logs = (string) file_get_contents($matches[1]);
                unlink($matches[1]);
            }
            $this->assertStringContainsString($quoted, $logs, $entry);
        }
        $_POST = [];
        \StackInfo::clearCache();
    }
}
