<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PHPUnit\Framework\TestCase;

class InstallerCompatibilityTest extends TestCase
{
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