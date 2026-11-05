<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/TerminalSession.php';

class TerminalSessionTest extends TestCase
{
    /** @var resource|null */
    private $process;
    private string $id;
    private string $file;
    private string $directory;
    /** @var string[] */
    private array $operationFiles = [];
    /** @var resource[] */
    private array $pipes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = $this->createTempDir();
        $_POST['trackOperation'] = '1';
        $operations = [];
        \composeTrackUiCommand([], 'terminal-session', $operations);
        $this->id = $operations['terminal-session'];
        $this->file = \composeUiOperationFile('terminal-session', $this->id);
    }

    /** @param string[] $operations */
    private function launch(string $command, array $operations = [], string $log = ''): void
    {
        $script = dirname(__DIR__, 2) . '/source/compose.manager/scripts/compose_terminal_session.sh';
        $this->process = proc_open(['setsid', 'bash', $script, $this->file, $command, $log, ...$operations], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $this->pipes);
        $this->assertIsResource($this->process);
        fclose($this->pipes[0]);
        $this->waitFor(fn() => \composeReadTerminalSession($this->id)['state'] !== 'queued');
    }

    private function waitFor(callable $condition): void
    {
        for ($i = 0; $i < 300; $i++) {
            if ($condition()) {
                return;
            }
            usleep(10000);
        }
        $this->fail('Timed out waiting for isolated terminal process.');
    }

    public function testClosingViewerDoesNotKillCommandAndPreservesOutputAndFailure(): void
    {
        $this->launch('echo started; sleep 1; echo finished; exit 7', [], $this->directory . '/last_cmd.log');
        $session = \composeReadTerminalSession($this->id);
        $viewer = proc_open(['tail', '--pid=' . $session['pid'], '-f', $this->directory . '/last_cmd.log'], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($viewer);
        proc_terminate($viewer);
        foreach ($pipes as $pipe) fclose($pipe);
        proc_close($viewer);
        $this->assertSame('running', \composeReadTerminalSession($this->id)['state']);
        $this->waitFor(fn() => \composeReadTerminalSession($this->id)['state'] === 'completed');
        $this->assertSame(7, \composeReadTerminalSession($this->id)['exitCode']);
        $this->assertSame("started\nfinished\n", file_get_contents($this->directory . '/last_cmd.log'));
        $this->assertSame("started\nfinished\n", file_get_contents($this->file . '.log'));
    }

    public function testStopCancelsRunningAndQueuedBulkReceiptsWithoutRunningNextCommand(): void
    {
        $operations = [];
        $first = \composeTrackUiCommand(['sh', '-c', 'sleep 30 & echo $! > ' . escapeshellarg($this->directory . '/child') . '; wait'], 'terminal-first', $operations);
        $second = \composeTrackUiCommand(['touch', $this->directory . '/should-not-exist'], 'terminal-second', $operations);
        foreach ($operations as $project => $id) {
            $this->operationFiles[] = \composeUiOperationFile($project, $id);
        }
        $this->launch(implode(' ', array_map('escapeshellarg', $first)) . '; ' . implode(' ', array_map('escapeshellarg', $second)), $this->operationFiles);
        $this->waitFor(fn() => is_file($this->directory . '/child'));
        $child = trim((string) file_get_contents($this->directory . '/child'));
        \composeStopTerminalSession($this->id);
        $this->waitFor(fn() => \composeReadTerminalSession($this->id)['state'] === 'completed');
        $this->assertSame(143, \composeReadTerminalSession($this->id)['exitCode']);
        foreach ($this->operationFiles as $file) {
            $this->assertSame(['state' => 'completed', 'exitCode' => 143], json_decode((string) file_get_contents($file), true));
        }
        $this->assertFileDoesNotExist($this->directory . '/should-not-exist');
        $stat = @file_get_contents("/proc/$child/stat");
        $this->assertTrue($stat === false || str_contains($stat, ') Z '), 'Nested command must no longer be running.');
        \composeStopTerminalSession($this->id); // Completed stops are idempotent.
    }

    public function testStopRefusesReusedOrAlteredProcessIdentity(): void
    {
        $this->launch('sleep 30');
        $original = (string) file_get_contents($this->file);
        $session = json_decode($original, true);
        $session['started'] = '0';
        file_put_contents($this->file, json_encode($session));
        try {
            \composeStopTerminalSession($this->id);
            $this->fail('Changed process identity must not be signalled.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('identity changed', $error->getMessage());
        } finally {
            file_put_contents($this->file, $original);
        }
        $this->assertSame('running', \composeReadTerminalSession($this->id)['state']);
    }

    public function testStopLeavesUnrelatedProcessesRunning(): void
    {
        $unrelated = proc_open(['sleep', '30'], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($unrelated);
        try {
            $this->launch('sleep 30');
            \composeStopTerminalSession($this->id);
            $this->waitFor(fn() => \composeReadTerminalSession($this->id)['state'] === 'completed');
            $this->assertTrue(proc_get_status($unrelated)['running']);
        } finally {
            proc_terminate($unrelated);
            foreach ($pipes as $pipe) fclose($pipe);
            proc_close($unrelated);
        }
    }

    public function testLoggedCancellationWaitsForDescendantBeforeCompletingReceipts(): void
    {
        $script = $this->directory . '/delayed-stop.sh';
        file_put_contents($script, "#!/bin/bash\ntrap '' TERM\necho ready > " .
            escapeshellarg($this->directory . '/ready') . "\nsleep 1\necho done > " .
            escapeshellarg($this->directory . '/cleaned') . "\n");
        $operations = [];
        $command = \composeTrackUiCommand(['bash', $script], 'delayed-stop', $operations);
        $operation = \composeUiOperationFile('delayed-stop', $operations['delayed-stop']);
        $this->operationFiles[] = $operation;
        $this->launch(implode(' ', array_map('escapeshellarg', $command)), [$operation], $this->directory . '/last_cmd.log');
        $this->waitFor(fn() => is_file($this->directory . '/ready'));
        \composeStopTerminalSession($this->id);
        usleep(100000);
        $this->assertSame('running', \composeReadTerminalSession($this->id)['state']);
        $this->assertSame('running', json_decode((string) file_get_contents($operation), true)['state']);
        $this->waitFor(fn() => \composeReadTerminalSession($this->id)['state'] === 'completed');
        $this->assertFileExists($this->directory . '/cleaned');
        $this->assertSame(143, \composeReadTerminalSession($this->id)['exitCode']);
        $this->assertSame(143, json_decode((string) file_get_contents($operation), true)['exitCode']);
    }

    public function testStopRefusesQueuedSession(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not started yet');
        \composeStopTerminalSession($this->id);
    }

    public function testLiveSpoolIsBoundedWhileFullStackLogIsPreserved(): void
    {
        $log = $this->directory . '/last_cmd.log';
        $this->launch('head -c 3145728 /dev/zero; printf final-output', [], $log);
        $this->waitFor(fn() => \composeReadTerminalSession($this->id)['state'] === 'completed');
        $this->assertSame(0, \composeReadTerminalSession($this->id)['exitCode']);
        $this->assertLessThanOrEqual(1048576, filesize($this->file . '.log'));
        $this->assertSame(3145740, filesize($log));
        $this->assertStringEndsWith('final-output', (string) file_get_contents($this->file . '.log'));
        $this->assertStringContainsString('Earlier output omitted', (string) file_get_contents($this->file . '.log'));
    }

    public function testOutputFailureCannotReportSuccessfulSession(): void
    {
        $this->launch('printf output', [], $this->directory);
        $this->waitFor(fn() => \composeReadTerminalSession($this->id)['state'] === 'completed');
        $this->assertNotSame(0, \composeReadTerminalSession($this->id)['exitCode']);
        $this->assertStringContainsString('Unable to open terminal output files', stream_get_contents($this->pipes[2]));
    }

    public function testAcceptedCommandKeepsTrackingWhenViewerCannotStart(): void
    {
        $this->assertStartupResponse(false);
    }

    public function testFailedLauncherCompletesQueuedOperationWithoutExecutingCommand(): void
    {
        $this->assertStartupResponse(true);
    }

    private function assertStartupResponse(bool $failLauncher): void
    {
        $bin = $this->directory . '/bin';
        mkdir($bin);
        file_put_contents($bin . '/ttyd', "#!/bin/sh\nexit 1\n");
        chmod($bin . '/ttyd', 0700);
        if ($failLauncher) {
            file_put_contents($bin . '/setsid', "#!/bin/sh\nexit 1\n");
            chmod($bin . '/setsid', 0700);
        }
        $before = glob(dirname($this->file) . '/*.json');
        $code = 'define("COMPOSE_SKIP_TTYD_EXEC", false); require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
            . 'function waitForTtydSocket($socket) { return false; }'
            . 'require ' . var_export(dirname(__DIR__, 2) . '/source/compose.manager/include/TerminalSession.php', true) . ';'
            . '$_POST["trackOperation"] = "1"; $operations = [];'
            . '$command = composeTrackUiCommand(["sh", "-c", ' . var_export('sleep 0.1; touch ' . escapeshellarg($this->directory . '/executed'), true) . '], "startup-test", $operations);'
            . 'composeEchoTerminalSession(implode(" ", array_map("escapeshellarg", $command)), $operations, false);';
        $process = proc_open([PHP_BINARY, '-r', $code], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, null, array_merge(getenv(), ['PATH' => $bin . ':' . getenv('PATH')]));
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $response = json_decode(stream_get_contents($pipes[1]), true);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $stderr);
        $newFiles = array_diff(glob(dirname($this->file) . '/*.json'), $before);
        foreach ($newFiles as $file) $this->operationFiles[] = $file;
        $this->assertIsArray($response);
        $operation = \composeUiOperationFile('startup-test', $response['operations']['startup-test']);
        $this->waitFor(fn() => (json_decode((string) file_get_contents($operation), true)['state'] ?? '') === 'completed');
        if ($failLauncher) {
            $this->assertSame('terminal', $response['error']);
            $this->assertSame(1, json_decode((string) file_get_contents($operation), true)['exitCode']);
            $this->assertFileDoesNotExist($this->directory . '/executed');
        } else {
            $this->assertTrue($response['background']);
            $this->assertTrue($response['warning']);
            $this->assertSame(0, json_decode((string) file_get_contents($operation), true)['exitCode']);
            $this->assertFileExists($this->directory . '/executed');
        }
    }

    public function testSessionIdentifiersCannotEscapeReceiptDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \composeStopTerminalSession('../other-command');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            if (\composeReadTerminalSession($this->id)['state'] === 'running') {
                \composeStopTerminalSession($this->id);
            }
            fclose($this->pipes[1]);
            fclose($this->pipes[2]);
            proc_close($this->process);
        }
        foreach (array_merge([$this->file], $this->operationFiles) as $file) {
            @unlink($file);
            @unlink($file . '.log');
            @unlink($file . '.stderr');
        }
        unset($_POST['trackOperation']);
        parent::tearDown();
    }
}
