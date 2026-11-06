<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/ContainerMetadataCache.php';

class ContainerMetadataCacheTest extends TestCase
{
    private string|false $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = is_file(COMPOSE_CONTAINER_CACHE_FILE) ? file_get_contents(COMPOSE_CONTAINER_CACHE_FILE) : false;
        @unlink(COMPOSE_CONTAINER_CACHE_FILE);
    }

    protected function tearDown(): void
    {
        if ($this->original === false) @unlink(COMPOSE_CONTAINER_CACHE_FILE);
        else file_put_contents(COMPOSE_CONTAINER_CACHE_FILE, $this->original);
        parent::tearDown();
    }

    public function testScopedPublicationMergesUnrelatedProjectsAndPreservesMetadata(): void
    {
        file_put_contents(COMPOSE_CONTAINER_CACHE_FILE, '{"app":{"web":{"service":"web","image":"old","icon":"icon.png","hasUpdate":true}}}');
        $app = \ContainerMetadataCache::begin(['app']);
        $other = \ContainerMetadataCache::begin(['other']);
        \ContainerMetadataCache::merge(['other' => ['db' => ['service' => 'db', 'image' => 'db:1']]], $other);
        \ContainerMetadataCache::merge(['app' => ['web' => ['image' => 'new']]], $app);
        $cache = \ContainerMetadataCache::read();
        $this->assertSame('new', $cache['app']['web']['image']);
        $this->assertSame('icon.png', $cache['app']['web']['icon']);
        $this->assertArrayNotHasKey('hasUpdate', $cache['app']['web']);
        $this->assertSame('db:1', $cache['other']['db']['image']);
    }

    public function testNewerCollectionDeletionAndRecreationRejectOldPublishers(): void
    {
        $old = \ContainerMetadataCache::begin(['app']);
        $new = \ContainerMetadataCache::begin(['app']);
        $patch = ['app' => ['web' => ['service' => 'web', 'image' => 'new']]];
        \ContainerMetadataCache::merge($patch, $new);
        \ContainerMetadataCache::merge(['app' => ['web' => ['image' => 'old']]], $old);
        $this->assertSame('new', \ContainerMetadataCache::read()['app']['web']['image']);
        $this->assertTrue(\ContainerMetadataCache::remove('app'));
        \ContainerMetadataCache::merge($patch, $new);
        $this->assertSame([], \ContainerMetadataCache::read());
        $recreated = \ContainerMetadataCache::begin(['app']);
        \ContainerMetadataCache::merge($patch, $recreated);
        \ContainerMetadataCache::merge(['app' => ['web' => ['image' => 'old']]], $new);
        $this->assertSame('new', \ContainerMetadataCache::read()['app']['web']['image']);
    }

    public function testExplicitClearPreservesPresentationButInvalidatesCollectors(): void
    {
        $token = \ContainerMetadataCache::begin(['app']);
        \ContainerMetadataCache::merge(['app' => ['web' => ['image' => 'kept']]], $token);
        \ContainerMetadataCache::clearEvidence();
        \ContainerMetadataCache::merge(['app' => ['web' => ['image' => 'stale']]], $token);
        $this->assertSame('kept', \ContainerMetadataCache::read()['app']['web']['image']);
    }

    public function testCorruptionIsPreservedUntilExplicitClear(): void
    {
        foreach (['', 'broken', 'null', '{"app":42}', '{"version":1,"projects":{},"owners":{"app":"bad"}}'] as $raw) {
            file_put_contents(COMPOSE_CONTAINER_CACHE_FILE, $raw);
            foreach (['read', 'begin', 'remove', 'merge'] as $operation) {
                try {
                    match ($operation) {
                        'read' => \ContainerMetadataCache::read(),
                        'begin' => \ContainerMetadataCache::begin(['app']),
                        'remove' => \ContainerMetadataCache::remove('app'),
                        'merge' => \ContainerMetadataCache::merge([], []),
                    };
                    $this->fail('Corrupt metadata accepted.');
                } catch (\RuntimeException | \JsonException $error) {
                    $this->assertNotEmpty($error->getMessage());
                }
                $this->assertSame($raw, file_get_contents(COMPOSE_CONTAINER_CACHE_FILE));
            }
            \ContainerMetadataCache::clearEvidence();
            $this->assertSame([], \ContainerMetadataCache::read());
        }
    }

    public function testEveryReaderAndWriterUsesTheTransactionLock(): void
    {
        foreach (['read()', 'begin(["new"])', 'merge([], [])', 'remove("app")', 'clearEvidence()'] as $operation) {
            file_put_contents(COMPOSE_CONTAINER_CACHE_FILE, '{"app":{}}');
            $handle = fopen(COMPOSE_CONTAINER_CACHE_FILE, 'r+');
            $this->assertTrue(flock($handle, LOCK_EX));
            $code = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
                . 'require "/usr/local/emhttp/plugins/compose.manager/include/ContainerMetadataCache.php";'
                . 'echo "waiting\n"; flush(); ContainerMetadataCache::' . $operation . '; echo "done";';
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
                fwrite($handle, '{"app":{},"other":{}}');
                fflush($handle);
                flock($handle, LOCK_UN);
                stream_set_blocking($pipes[1], true);
                $this->assertSame('done', stream_get_contents($pipes[1]));
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $errors);
                $this->assertArrayHasKey('other', \ContainerMetadataCache::read());
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }

        }
    }

    public function testReadersAndWritersWaitUntilFirstUseInitializationFinishes(): void
    {
        foreach (['read()', 'begin(["new"])'] as $operation) {
            $lock = fopen(COMPOSE_CONTAINER_CACHE_FILE . '.lock', 'c+');
            flock($lock, LOCK_EX);
            file_put_contents(COMPOSE_CONTAINER_CACHE_FILE, '');
            $code = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
                . 'require "/usr/local/emhttp/plugins/compose.manager/include/ContainerMetadataCache.php";'
                . 'echo "waiting\n"; flush(); ContainerMetadataCache::' . $operation . '; echo "done";';
            $process = proc_open([PHP_BINARY, '-r', $code], [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes);
            fclose($pipes[0]);
            try {
                stream_set_timeout($pipes[1], 5);
                $this->assertSame("waiting\n", fgets($pipes[1]));
                stream_set_blocking($pipes[1], false);
                usleep(100000);
                $this->assertSame('', stream_get_contents($pipes[1]));
                file_put_contents(COMPOSE_CONTAINER_CACHE_FILE, '{"initialized":{}}');
                flock($lock, LOCK_UN);
                stream_set_blocking($pipes[1], true);
                $this->assertSame('done', stream_get_contents($pipes[1]));
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $errors);
                $this->assertArrayHasKey('initialized', \ContainerMetadataCache::read());
            } finally {
                fclose($lock);
            }
        }
    }
}
