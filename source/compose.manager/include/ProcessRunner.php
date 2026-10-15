<?php

declare(strict_types=1);

/**
 * The result of running one command.
 */
final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut
    ) {
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut;
    }

    /**
     * A one-line description of a failure, for messages and logs.
     */
    public function errorSummary(): string
    {
        if ($this->timedOut) {
            return 'The command did not finish in time and was stopped.';
        }
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $this->stderr)),
            static fn(string $line): bool => $line !== ''
        ));
        if ($lines === []) {
            return "The command exited with code $this->exitCode.";
        }
        // git and docker put the useful line after "fatal:" or "error:"; hints may follow it.
        foreach (array_reverse($lines) as $line) {
            if (str_starts_with($line, 'fatal:') || str_starts_with($line, 'error:')) {
                return $line;
            }
        }
        return $lines[count($lines) - 1];
    }
}

/**
 * Runs a command without a shell, with no input, a clean environment and a time limit.
 */
final class ProcessRunner
{
    /**
     * @param string[] $command The program and its arguments, passed as-is (no shell)
     * @param array<string, string> $environment The complete environment for the command
     */
    public static function run(array $command, string $workingDirectory, array $environment, int $timeoutSeconds): ProcessResult
    {
        // GNU timeout stops the command and every helper it started, since it
        // signals the whole process group.
        $fullCommand = array_merge(['timeout', '--kill-after=10', (string) $timeoutSeconds], $command);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $startedAt = microtime(true);
        $process = proc_open($fullCommand, $descriptors, $pipes, $workingDirectory, $environment);
        if (!is_resource($process)) {
            return new ProcessResult(127, '', 'Could not start ' . $command[0] . '.', false);
        }

        // The command gets no input: its stdin is closed straight away.
        fclose($pipes[0]);

        // Read both pipes as output arrives, so a full pipe can never stall the command.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        while (true) {
            // A pipe at end-of-file always counts as readable, so it is left
            // out of the wait; otherwise the loop would spin while the command
            // runs on with that output closed.
            $read = [];
            foreach ([1, 2] as $index) {
                if (!feof($pipes[$index])) {
                    $read[] = $pipes[$index];
                }
            }
            if ($read === []) {
                // Usually the command is exiting: check again shortly. A command
                // that closes both outputs and keeps running is checked every 20 ms.
                usleep(20000);
            } else {
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 1);
            }
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $exitCode = (int) $status['exitcode'];
        if ($status['signaled']) {
            // Killed by a signal (timeout passes a child's signal on): report it
            // the way a shell does, as 128 plus the signal number.
            $exitCode = 128 + (int) $status['termsig'];
        }
        // timeout exits 124 when it stopped the command, or 137 when it had to
        // kill it. 137 also means the command was killed by something else (such
        // as the out-of-memory killer), so the time spent decides.
        $ranOutOfTime = microtime(true) - $startedAt >= $timeoutSeconds;
        $timedOut = ($exitCode === 124 || $exitCode === 137) && $ranOutOfTime;

        return new ProcessResult($exitCode, $stdout, $stderr, $timedOut);
    }
}
