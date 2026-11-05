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
}
