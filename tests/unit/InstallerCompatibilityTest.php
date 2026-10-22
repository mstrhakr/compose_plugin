<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class InstallerCompatibilityTest extends TestCase
{
    public function testLegacyOriginIsCapturedBeforePackageReplacement(): void
    {
        $plugin = simplexml_load_file(dirname(__DIR__, 2) . '/compose.manager.plg', \SimpleXMLElement::class, LIBXML_NOENT);
        $this->assertNotFalse($plugin);
        $preInstall = (string) $plugin->FILE[0]->INLINE;
        $this->assertStringContainsString('/php/util.php', $preInstall);
        $this->assertStringContainsString('legacy-origin', $preInstall);
        $this->assertStringContainsString('! -f /usr/local/emhttp/plugins/compose.manager/include/ProjectIdentity.php', $preInstall);
        $installer = file_get_contents(dirname(__DIR__, 2) . '/install.sh');
        $this->assertLessThan(strpos($installer, 'if plugin remove'), strpos($installer, '/legacy-origin'));
    }

    public static function legacyInstallCases(): array
    {
        return [
            'original runtime intact' => ['original', '', '', true],
            'active original manifest only' => ['', 'dcflachs', '', true],
            'original removed before installation' => ['', '', 'dcflachs', true],
            'active Plus ignores stale removed original' => ['', 'mstrhakr', 'dcflachs', false],
            'active Plus ignores old-style PHP leftovers' => ['original', 'mstrhakr', 'dcflachs', false],
            'Plus runtime ignores removed original' => ['plus', '', 'dcflachs', false],
            'fresh installation' => ['', '', '', false],
        ];
    }

    #[DataProvider('legacyInstallCases')]
    public function testLegacyDetectionLeavesProjectDataUntouched(string $runtimeKind, string $activeAuthor, string $removedAuthor, bool $expected): void
    {
        $plugin = simplexml_load_file(dirname(__DIR__, 2) . '/compose.manager.plg', \SimpleXMLElement::class, LIBXML_NOENT);
        $this->assertNotFalse($plugin);
        $preInstall = (string) $plugin->FILE[0]->INLINE;
        $capture = substr($preInstall, 0, strpos($preInstall, '# Remove all but current'));
        $root = sys_get_temp_dir() . '/cm183-installer-' . bin2hex(random_bytes(6));
        $runtime = $root . '/runtime';
        $persistent = $root . '/persistent';
        mkdir($runtime . '/php', 0755, true);
        mkdir($persistent . '/projects/legacy-stack', 0755, true);
        if ($runtimeKind === 'original') {
            file_put_contents($runtime . '/php/util.php', '<?php');
        } elseif ($runtimeKind === 'plus') {
            mkdir($runtime . '/include');
            file_put_contents($runtime . '/include/ProjectIdentity.php', '<?php');
        }
        foreach (['active' => $activeAuthor, 'removed' => $removedAuthor] as $kind => $author) {
            if ($author !== '') {
                file_put_contents($root . '/' . $kind . '.plg', '<!DOCTYPE PLUGIN [<!ENTITY author "' . $author . '">]><PLUGIN name="compose.manager" author="&author;"/>');
            }
        }
        $projectFile = $persistent . '/projects/legacy-stack/docker-compose.yml';
        file_put_contents($projectFile, "services: {}\n");
        try {
            $script = str_replace(
                ['/boot/config/plugins/compose.manager.plg', '/boot/config/plugins-removed/compose.manager.plg', '/usr/local/emhttp/plugins/compose.manager', '/boot/config/plugins/compose.manager'],
                [$root . '/active.plg', $root . '/removed.plg', $runtime, $persistent],
                $capture
            );
            $output = [];
            $status = 0;
            exec('bash -c ' . escapeshellarg($script), $output, $status);
            $this->assertSame(0, $status, implode("\n", $output));
            $this->assertSame($expected, is_file($persistent . '/legacy-origin'));
            if ($expected) {
                $this->assertSame("dcflachs\n", file_get_contents($persistent . '/legacy-origin'));
            }
            $this->assertSame("services: {}\n", file_get_contents($projectFile));
            $this->assertStringNotContainsString('docker ', $script);
        } finally {
            @unlink($runtime . '/php/util.php');
            @unlink($runtime . '/include/ProjectIdentity.php');
            @unlink($root . '/active.plg');
            @unlink($root . '/removed.plg');
            @unlink($projectFile);
            @unlink($persistent . '/legacy-origin');
            @rmdir($runtime . '/php');
            @rmdir($runtime . '/include');
            @rmdir($runtime);
            @rmdir($persistent . '/projects/legacy-stack');
            @rmdir($persistent . '/projects');
            @rmdir($persistent);
            @rmdir($root);
        }
    }

    public function testMinimumUnraidVersionRequiresPhp8(): void
    {
        $plugin = simplexml_load_file(dirname(__DIR__, 2) . '/compose.manager.plg', \SimpleXMLElement::class, LIBXML_NOENT);
        $this->assertNotFalse($plugin);
        $minimum = (string) $plugin['min'];
        $this->assertSame('6.12.0', $minimum);

        foreach (['6.9.0', '6.10.3', '6.11.5'] as $version) {
            $this->assertTrue(version_compare($version, $minimum, '<'), "$version must be rejected");
        }
        foreach (['6.12.0', '6.12.14', '7.0.0', '7.2.3'] as $version) {
            $this->assertTrue(version_compare($version, $minimum, '>='), "$version must meet the minimum");
        }
    }

    public function testAceFallbackIsRetainedWithoutLegacyPatchHooks(): void
    {
        $plugin = simplexml_load_file(dirname(__DIR__, 2) . '/compose.manager.plg', \SimpleXMLElement::class, LIBXML_NOENT);
        $this->assertNotFalse($plugin);
        $aceFiles = 0;
        foreach ($plugin->FILE as $file) {
            $name = (string) $file['Name'];
            if (str_contains($name, 'ace-')) {
                $this->assertSame('6.99.99', (string) $file['Max']);
                $aceFiles++;
            }
            $this->assertStringNotContainsString('patch-util', $name);
            $this->assertStringNotContainsString('install-patch.sh', (string) $file->INLINE);
        }
        $this->assertSame(3, $aceFiles);
    }
}