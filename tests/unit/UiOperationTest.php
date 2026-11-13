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
        $completed = $directory . '/completed.json';
        $active = $directory . '/active.json';
        $old = $directory . '/old.json';
        foreach ([$completed, $active, $old] as $file) {
            file_put_contents($file, $file === $active ? '{"state":"running"}' : '{"state":"completed","exitCode":0}');
            file_put_contents($file . '.log', 'log');
            file_put_contents($file . '.detach-requested', 'requested');
            file_put_contents($file . '.detach.lock', '');
            $this->assertTrue(posix_mkfifo($file . '.input', 0600));
            touch($file, time() - ($file === $old || $file === $active ? 90000 : 4000));
        }
        \composeExpireUiOperations($directory);
        $this->assertFileExists($completed);
        $this->assertFileDoesNotExist($completed . '.log');
        $this->assertFileDoesNotExist($completed . '.input');
        $this->assertFileDoesNotExist($completed . '.detach-requested');
        $this->assertFileDoesNotExist($completed . '.detach.lock');
        $this->assertFileDoesNotExist($old);
        $this->assertFileDoesNotExist($old . '.log');
        $this->assertFileExists($active);
        $this->assertFileExists($active . '.log');
        $this->assertFileExists($active . '.input');
        file_put_contents($old, '{"state":"completed","exitCode":0}');
        touch($old, time() - 90000);
        \composeExpireUiOperations($directory);
        $this->assertFileExists($old, 'Repeated receipt creation must not rescan the directory within the housekeeping interval.');
    }
}
