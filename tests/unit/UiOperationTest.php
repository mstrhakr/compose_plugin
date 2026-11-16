<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/UiOperation.php';

class UiOperationTest extends TestCase
{
    public function testReceiptsAreScopedToDispatchAndStackAndPreserveExitStatus(): void
    {
        $_POST['trackOperation'] = '1';
        $operations = [];
        $first = \composeTrackUiCommand(['sh', '-c', 'exit 7'], 'receipt-test', $operations);
        $firstId = $operations['receipt-test'];
        $second = \composeTrackUiCommand(['sh', '-c', 'exit 0'], 'receipt-test', $operations);
        $secondId = $operations['receipt-test'];
        $firstFile = \composeUiOperationFile('receipt-test', $firstId);
        $secondFile = \composeUiOperationFile('receipt-test', $secondId);
        try {
            $this->assertNotSame($firstId, $secondId);
            $this->assertSame(['state' => 'queued'], json_decode((string) file_get_contents($secondFile), true));
            exec(implode(' ', array_map('escapeshellarg', $first)), $output, $code);
            $this->assertSame(7, $code);
            $this->assertSame(['state' => 'completed', 'exitCode' => 7], json_decode((string) file_get_contents($firstFile), true));
            $this->assertSame(['state' => 'queued'], json_decode((string) file_get_contents($secondFile), true));
            exec(implode(' ', array_map('escapeshellarg', $second)), $output, $code);
            $this->assertSame(0, $code);
            $this->assertSame(['state' => 'completed', 'exitCode' => 0], json_decode((string) file_get_contents($secondFile), true));
            $this->assertNotSame($firstFile, \composeUiOperationFile('another-stack', $firstId));
        } finally {
            @unlink($firstFile);
            @unlink($secondFile);
            unset($_POST['trackOperation']);
        }
    }

    public function testCommandSeesItsRunningReceiptBeforeItExecutes(): void
    {
        $_POST['trackOperation'] = '1';
        $operations = [];
        $command = \composeTrackUiCommand(['sh', '-c', 'grep -q \'"state":"running"\' "$COMPOSE_UI_OPERATION_FILE"'], 'running-test', $operations);
        $file = \composeUiOperationFile('running-test', $operations['running-test']);
        try {
            exec(implode(' ', array_map('escapeshellarg', $command)), $output, $code);
            $this->assertSame(0, $code);
            $this->assertSame(['state' => 'completed', 'exitCode' => 0], json_decode((string) file_get_contents($file), true));
        } finally {
            @unlink($file);
            unset($_POST['trackOperation']);
        }
    }

    public function testOldClientsKeepTheirOriginalCommand(): void
    {
        unset($_POST['trackOperation']);
        $operations = [];
        $this->assertSame(['echo', 'old-client'], \composeTrackUiCommand(['echo', 'old-client'], 'stack', $operations));
        $this->assertSame([], $operations);
    }

    public function testInvalidOperationIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \composeUiOperationFile('stack', '../outside');
    }

    public function testDispatchFailureOnlyCompletesUnstartedReceipts(): void
    {
        $id = \composeCreateUiOperation('failed-dispatch');
        $file = \composeUiOperationFile('failed-dispatch', $id);
        try {
            \composeFailQueuedUiOperation($file);
            $this->assertSame(['state' => 'completed', 'exitCode' => 1], json_decode((string) file_get_contents($file), true));
            file_put_contents($file, '{"state":"running"}');
            \composeFailQueuedUiOperation($file);
            $this->assertSame(['state' => 'running'], json_decode((string) file_get_contents($file), true));
        } finally {
            unlink($file);
        }
    }

    public function testHousekeepingIsThrottledAndKeepsActiveOutput(): void
    {
        $directory = $this->createTempDir();
        $completed = $directory . '/' . basename(\composeUiOperationFile('completed', str_repeat('1', 32)));
        $active = $directory . '/' . basename(\composeUiOperationFile('active', str_repeat('2', 32)));
        $old = $directory . '/' . basename(\composeUiOperationFile('old', str_repeat('3', 32)));
        $queued = $directory . '/' . basename(\composeUiOperationFile('queued', str_repeat('4', 32)));
        foreach ([$completed, $active, $old, $queued] as $file) {
            $state = $file === $active ? 'running' : ($file === $queued ? 'queued' : 'completed');
            file_put_contents($file, json_encode(['state' => $state, 'exitCode' => 0]));
            file_put_contents($file . '.log', 'log');
            file_put_contents($file . '.viewer.json', '{"pid":123}');
            file_put_contents($file . '.log.connected', 'connected');
            file_put_contents($file . '.detach-requested', 'requested');
            file_put_contents($file . '.detach.lock', '');
            $this->assertTrue(posix_mkfifo($file . '.input', 0600));
            touch($file, time() - ($file === $completed ? 4000 : 90000));
        }
        \composeExpireUiOperations($directory);
        $this->assertFileExists($completed);
        $this->assertFileDoesNotExist($completed . '.log');
        $this->assertFileDoesNotExist($completed . '.viewer.json');
        $this->assertFileDoesNotExist($completed . '.log.connected');
        $this->assertFileDoesNotExist($completed . '.input');
        $this->assertFileDoesNotExist($completed . '.detach-requested');
        $this->assertFileDoesNotExist($completed . '.detach.lock');
        $this->assertFileDoesNotExist($old);
        $this->assertFileDoesNotExist($old . '.log');
        $this->assertFileExists($active);
        $this->assertFileExists($active . '.log');
        $this->assertFileExists($active . '.input');
        $this->assertFileExists($active . '.viewer.json');
        $this->assertFileExists($queued);
        $this->assertFileExists($queued . '.log');
        file_put_contents($old, '{"state":"completed","exitCode":0}');
        touch($old, time() - 90000);
        \composeExpireUiOperations($directory);
        $this->assertFileExists($old, 'Repeated receipt creation must not rescan the directory within the housekeeping interval.');
    }

    public function testHousekeepingIgnoresAuxiliaryAndUnrelatedJsonFiles(): void
    {
        $directory = $this->createTempDir();
        $receipt = basename(\composeUiOperationFile('viewer', str_repeat('a', 32)));
        foreach ([$receipt . '.viewer.json', 'unrelated.json', str_repeat('b', 64) . '-invalid.json'] as $name) {
            file_put_contents($directory . '/' . $name, '{"pid":123}');
            touch($directory . '/' . $name, time() - 90000);
        }
        \composeExpireUiOperations($directory);
        foreach ([$receipt . '.viewer.json', 'unrelated.json', str_repeat('b', 64) . '-invalid.json'] as $name) {
            $this->assertSame('{"pid":123}', file_get_contents($directory . '/' . $name));
        }
    }

    public function testHousekeepingToleratesReceiptDisappearanceAfterEnumeration(): void
    {
        $directory = $this->createTempDir();
        $file = $directory . '/' . basename(\composeUiOperationFile('disappeared', str_repeat('5', 32)));
        file_put_contents($file, '{"state":"completed","exitCode":0}');
        // glob sees the real file; subsequent opens/stat resolve to its removed location.
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping($file, $file . '.missing');
        try {
            \composeExpireUiOperations($directory);
            $this->assertNotSame('', file_get_contents($directory . '/.housekeeping'));
        } finally {
            \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping($file, $file);
        }
    }

    public function testCleanupDoesNotHidePersistentRemovalFailure(): void
    {
        $directory = $this->createTempDir();
        $target = $directory . '/output';
        mkdir($target);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to expire terminal output.');
        \composeExpireUiOperationFile($target, 'Unable to expire terminal output.');
    }

    public function testCleanupAcceptsAlreadyMissingFiles(): void
    {
        $target = $this->createTempDir() . '/removed';
        \composeExpireUiOperationFile($target, 'Unable to expire terminal output.');
        $this->assertFileDoesNotExist($target);
    }
}
