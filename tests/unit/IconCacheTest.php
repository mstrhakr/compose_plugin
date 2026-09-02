<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';

class IconCacheTest extends TestCase
{
    // Minimal 1×1 red PNG (raw bytes, hard-coded to avoid GD dependency in this helper)
    private const TINY_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI6QAAAABJRU5ErkJggg==';

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = COMPOSE_ICON_CACHE_DIR;
        // Clean slate for each test
        if (is_dir($this->cacheDir)) {
            $this->removeDir($this->cacheDir);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            $this->removeDir($this->cacheDir);
        }
        parent::tearDown();
    }

    public function testSyncDockerManagerIconsReusesSingleDockerPsInvocation(): void
    {
        $stubDir = sys_get_temp_dir() . '/compose-docker-stub-' . bin2hex(random_bytes(6));
        $counterFile = $stubDir . '/docker-call-count';
        $pngPath = $stubDir . '/docker-sync.png';

        mkdir($stubDir, 0755, true);
        mkdir(COMPOSE_DM_ICON_RAM_DIR, 0755, true);

        file_put_contents($stubDir . '/docker', <<<'SH'
#!/bin/sh
count_file="${COMPOSE_DOCKER_COUNT_FILE:-/tmp/compose-docker-call-count}"
count=0
if [ -f "$count_file" ]; then
    count=$(cat "$count_file" 2>/dev/null || echo 0)
fi
count=$((count + 1))
printf '%s' "$count" > "$count_file"
printf '%s\n' 'ct-a	https://example.com/a.png'
printf '%s\n' 'ct-b	https://example.com/b.png'
SH
        );
        chmod($stubDir . '/docker', 0755);
        file_put_contents($pngPath, base64_decode(self::TINY_PNG_BASE64));

        putenv('COMPOSE_DOCKER_COUNT_FILE=' . $counterFile);
        $oldPath = getenv('PATH');
        putenv('PATH=' . $stubDir . ':' . ($oldPath !== false ? $oldPath : ''));

        compose_sync_docker_manager_icons_for_source('https://example.com/a.png', $pngPath);
        compose_sync_docker_manager_icons_for_source('https://example.com/b.png', $pngPath);

        $this->assertSame('1', trim((string) file_get_contents($counterFile)));

        putenv('PATH=' . ($oldPath !== false ? $oldPath : ''));
        putenv('COMPOSE_DOCKER_COUNT_FILE');
        @unlink($counterFile);
        @unlink($pngPath);
        @unlink($stubDir . '/docker');
        @rmdir($stubDir);
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ── compose_fetch_icon_to_cache ────────────────────────────────────────

    public function testFetchEmptySourceReturnsEmpty(): void
    {
        $this->assertSame('', compose_fetch_icon_to_cache(''));
        $this->assertSame('', compose_fetch_icon_to_cache('   '));
    }

    public function testFetchDataUriPngWritesCacheFile(): void
    {
        $source = 'data:image/png;base64,' . self::TINY_PNG_BASE64;
        $result = compose_fetch_icon_to_cache($source);

        $this->assertNotSame('', $result, 'Expected a cache path, got empty string');
        $this->assertFileExists($result);
        $this->assertSame(compose_get_icon_cache_path($source), $result);
        // Verify the written bytes are a valid PNG
        $this->assertStringStartsWith("\x89PNG", (string) file_get_contents($result));
    }

    public function testFetchCacheHitReturnsSamePathWithoutReFetch(): void
    {
        $source = 'data:image/png;base64,' . self::TINY_PNG_BASE64;
        $first  = compose_fetch_icon_to_cache($source);
        $mtime1 = filemtime($first);

        // Small sleep to ensure mtime would differ if the file were rewritten
        clearstatcache(true, $first);
        $second = compose_fetch_icon_to_cache($source);

        $this->assertSame($first, $second);
        $this->assertSame($mtime1, filemtime($first));
    }

    public function testFetchForceRefreshOverwritesExistingFile(): void
    {
        $source = 'data:image/png;base64,' . self::TINY_PNG_BASE64;
        $first  = compose_fetch_icon_to_cache($source);

        // Write garbage so we can detect overwrite
        file_put_contents($first, 'garbage');

        $second = compose_fetch_icon_to_cache($source, true);

        $this->assertSame($first, $second);
        $this->assertStringStartsWith("\x89PNG", (string) file_get_contents($second));
    }

    public function testFetchSvgDataUriReturnsEmptyWithoutResvg(): void
    {
        if (is_executable(COMPOSE_RESVG_BIN)) {
            $this->markTestSkipped('resvg available — SVG is converted, not rejected');
        }
        $source = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->assertSame('', compose_fetch_icon_to_cache($source));
    }

    public function testFetchSvgDataUriSucceedsWithResvg(): void
    {
        if (!is_executable(COMPOSE_RESVG_BIN)) {
            $this->markTestSkipped('resvg not available');
        }
        $svg    = '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8">'
                . '<rect width="8" height="8" fill="blue"/></svg>';
        $source = 'data:image/svg+xml;base64,' . base64_encode($svg);
        $result = compose_fetch_icon_to_cache($source, true);
        $this->assertNotSame('', $result);
        $this->assertFileExists($result);
        $this->assertStringStartsWith("\x89PNG", (string) file_get_contents($result));
    }

    public function testFetchLocalFileOutsideAllowedPathReturnsEmpty(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'icon_test_');
        file_put_contents($tmp, base64_decode(self::TINY_PNG_BASE64));

        // /tmp is not under /mnt or /boot/config/plugins/compose.manager
        $this->assertSame('', compose_fetch_icon_to_cache($tmp));

        unlink($tmp);
    }

    public function testFetchLocalPathTraversalReturnsEmpty(): void
    {
        $this->assertSame('', compose_fetch_icon_to_cache('/mnt/../etc/passwd'));
    }

    public function testFetchUnsupportedSchemeReturnsEmpty(): void
    {
        $this->assertSame('', compose_fetch_icon_to_cache('ftp://example.com/icon.png'));
        $this->assertSame('', compose_fetch_icon_to_cache('javascript:alert(1)'));
    }

    // ── compose_icon_is_safe_host ──────────────────────────────────────────

    public function testSafeHostRejectsLoopback(): void
    {
        $this->assertFalse(compose_icon_is_safe_host('127.0.0.1'));
        $this->assertFalse(compose_icon_is_safe_host('localhost'));
    }

    public function testSafeHostRejectsPrivateRanges(): void
    {
        $this->assertFalse(compose_icon_is_safe_host('192.168.1.1'));
        $this->assertFalse(compose_icon_is_safe_host('10.0.0.1'));
        $this->assertFalse(compose_icon_is_safe_host('172.16.0.1'));
    }

    // ── compose_icon_ext_to_mime ───────────────────────────────────────────

    public function testExtToMimeKnownTypes(): void
    {
        $this->assertSame('image/png',     compose_icon_ext_to_mime('png'));
        $this->assertSame('image/jpeg',    compose_icon_ext_to_mime('jpg'));
        $this->assertSame('image/jpeg',    compose_icon_ext_to_mime('jpeg'));
        $this->assertSame('image/gif',     compose_icon_ext_to_mime('gif'));
        $this->assertSame('image/webp',    compose_icon_ext_to_mime('webp'));
        $this->assertSame('image/x-icon', compose_icon_ext_to_mime('ico'));
        $this->assertSame('image/svg+xml', compose_icon_ext_to_mime('svg'));
        $this->assertSame('',              compose_icon_ext_to_mime('xyz'));
    }

    // ── compose_seed_docker_manager_icon ──────────────────────────────────

    public function testSeedSkipsOnEmptyCachePath(): void
    {
        // Should not throw; just a no-op
        compose_seed_docker_manager_icon('', 'my-container');
        $this->assertTrue(true);
    }

    public function testSeedSkipsInvalidContainerName(): void
    {
        // Build a valid cache PNG so the function reaches the name-validation guard
        $source = 'data:image/png;base64,' . self::TINY_PNG_BASE64;
        $cached = compose_fetch_icon_to_cache($source);

        compose_seed_docker_manager_icon($cached, '../etc/passwd');
        compose_seed_docker_manager_icon($cached, '');
        // No files were written outside expected path — just assert no exception
        $this->assertTrue(true);
    }

    // ── compose_icon_to_png_bytes ──────────────────────────────────────────

    public function testPngBytesPassThroughRawPngWhenGdAbsent(): void
    {
        if (function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is available — raw pass-through path not exercised');
        }

        $raw = base64_decode(self::TINY_PNG_BASE64);
        $out = compose_icon_to_png_bytes($raw, 'image/png');
        $this->assertSame($raw, $out);
    }

    public function testPngBytesReturnsNullForSvg(): void
    {
        if (is_executable(COMPOSE_RESVG_BIN)) {
            $this->markTestSkipped('resvg is available — null-return path not exercised');
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"/>';
        $this->assertNull(compose_icon_to_png_bytes($svg, 'image/svg+xml'));
    }

    public function testPngBytesConvertsSvgWhenResvgAvailable(): void
    {
        if (!is_executable(COMPOSE_RESVG_BIN)) {
            $this->markTestSkipped('resvg binary not available');
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16">'
             . '<rect width="16" height="16" fill="red"/></svg>';
        $out = compose_icon_to_png_bytes($svg, 'image/svg+xml');
        $this->assertNotNull($out);
        $this->assertStringStartsWith("\x89PNG", (string) $out);
    }

    public function testPngBytesReturnsNullForGarbage(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD not available');
        }
        $this->assertNull(compose_icon_to_png_bytes('not-an-image', 'image/png'));
    }
}
