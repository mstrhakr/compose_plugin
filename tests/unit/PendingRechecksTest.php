<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/PendingRechecks.php';

class PendingRechecksTest extends TestCase
{
    private string|false $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = is_file(PENDING_RECHECK_FILE) ? file_get_contents(PENDING_RECHECK_FILE) : false;
        @unlink(PENDING_RECHECK_FILE);
    }

    protected function tearDown(): void
    {
        if ($this->original === false) @unlink(PENDING_RECHECK_FILE);
        else file_put_contents(PENDING_RECHECK_FILE, $this->original);
        parent::tearDown();
    }

    public function testLegacyEntriesAndRenewalsRequireExactAcknowledgement(): void
    {
        file_put_contents(PENDING_RECHECK_FILE, '{"constructor":12,"app":14}');
        $token = \PendingRechecks::token('constructor');
        $this->assertSame(['constructor' => 12, 'app' => 14], \PendingRechecks::timestamps());
        $this->assertSame(['app'], \PendingRechecks::acknowledge(['constructor'], ['constructor' => $token]));
        \PendingRechecks::mark(['constructor']);
        $first = \PendingRechecks::token('constructor');
        \PendingRechecks::mark(['constructor']);
        $second = \PendingRechecks::token('constructor');
        $this->assertNotSame($first, $second, 'Marks renewed within one second must have distinct identities.');
        \PendingRechecks::acknowledge(['constructor'], ['constructor' => $first]);
        $this->assertSame($second, \PendingRechecks::token('constructor'));
        \PendingRechecks::acknowledge(['constructor'], ['constructor' => null]);
        $this->assertSame($second, \PendingRechecks::token('constructor'), 'A check begun before any mark cannot consume a new one.');
        \PendingRechecks::acknowledge(['constructor'], ['constructor' => $second]);
        $this->assertNull(\PendingRechecks::token('constructor'));
        $this->assertTrue(\PendingRechecks::remove('app'));
        $this->assertFalse(\PendingRechecks::remove('app'));
    }

    public function testCorruptionIsPreservedByEveryStoreOperation(): void
    {
        foreach (['', 'broken', '"scalar"', 'null', '{"app":"bad"}', '{"app":{"timestamp":1,"token":"bad"}}'] as $raw) {
            foreach (['read', 'mark', 'acknowledge', 'remove'] as $operation) {
                file_put_contents(PENDING_RECHECK_FILE, $raw);
                try {
                    match ($operation) {
                        'read' => \PendingRechecks::timestamps(),
                        'mark' => \PendingRechecks::mark(['app']),
                        'acknowledge' => \PendingRechecks::acknowledge(['app'], ['app' => null]),
                        'remove' => \PendingRechecks::remove('app'),
                    };
                    $this->fail("Accepted corrupt data during $operation");
                } catch (\RuntimeException | \JsonException $error) {
                    $this->assertNotEmpty($error->getMessage());
                }
                $this->assertSame($raw, file_get_contents(PENDING_RECHECK_FILE));
            }
        }
    }

    public function testInvalidListsAndTokensDoNotMutateStorage(): void
    {
        \PendingRechecks::mark(['app', 'constructor', '0']);
        $before = file_get_contents(PENDING_RECHECK_FILE);
        foreach ([null, [], [['app']], [12], [''], ['../app'], ['app' => 'app']] as $stacks) {
            try {
                \PendingRechecks::validateStacks($stacks);
                $this->fail('Invalid list accepted');
            } catch (\InvalidArgumentException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
        foreach ([null, [], ['app' => 'bad'], ['app' => []]] as $tokens) {
            try {
                \PendingRechecks::acknowledge(['app'], $tokens);
                $this->fail('Invalid token accepted');
            } catch (\InvalidArgumentException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
        $this->assertSame($before, file_get_contents(PENDING_RECHECK_FILE));
    }

    public function testReadersAndEveryWriterWaitForSameTransactionLock(): void
    {
        foreach (['timestamps()', 'mark(["new"])', 'acknowledge(["app"], ["app"=>null])', 'remove("app")'] as $operation) {
            file_put_contents(PENDING_RECHECK_FILE, '{"app":1}');
            $handle = fopen(PENDING_RECHECK_FILE, 'r+');
            $this->assertTrue(flock($handle, LOCK_EX));
            $code = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
                . 'require "/usr/local/emhttp/plugins/compose.manager/include/PendingRechecks.php";'
                . 'echo "waiting\n"; flush(); $result=PendingRechecks::' . $operation . '; echo json_encode($result);';
            $process = proc_open([PHP_BINARY, '-r', $code], [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes);
            fclose($pipes[0]);
            try {
                stream_set_timeout($pipes[1], 5);
                $this->assertSame("waiting\n", fgets($pipes[1]));
                stream_set_blocking($pipes[1], false);
                usleep(100000);
                $this->assertSame('', stream_get_contents($pipes[1]), "$operation bypassed transaction lock");
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, '{"app":2,"other":3}');
                fflush($handle);
                flock($handle, LOCK_UN);
                stream_set_blocking($pipes[1], true);
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $errors);
                $this->assertNotSame('', $output);
                $this->assertSame(3, \PendingRechecks::timestamps()['other'], 'Writer must preserve entries committed before it acquires lock.');
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }
}
