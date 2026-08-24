<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';

class StackInfoTest extends TestCase
{
    private string $tempRoot;
    private string $stackOrderFile;

    protected function setUp(): void
    {
        parent::setUp();
        \StackInfo::clearCache();
        $this->tempRoot = $this->createTempDir();
        $this->stackOrderFile = COMPOSE_STACK_ORDER_FILE;
        if (is_file($this->stackOrderFile)) {
            @unlink($this->stackOrderFile);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->stackOrderFile)) {
            @unlink($this->stackOrderFile);
        }

        parent::tearDown();
    }

    // ===========================================
    // Factory / Identity Tests
    // ===========================================

    public function testFromProjectCreatesInstance(): void
    {
        $stack = 'mystack';
        mkdir($this->tempRoot . '/' . $stack);
        file_put_contents($this->tempRoot . '/' . $stack . '/compose.yaml', "services:\n");
        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $this->assertInstanceOf(\StackInfo::class, $info);
    }

    public function testProjectIdentityFields(): void
    {
        $stack = 'my-stack';
        mkdir($this->tempRoot . '/' . $stack);
        file_put_contents($this->tempRoot . '/' . $stack . '/compose.yaml', "services:\n");
        file_put_contents($this->tempRoot . '/' . $stack . '/name', 'My Stack');
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('my-stack', $info->projectFolder);
        $this->assertSame('my-stack', $info->projectName);
        $this->assertSame('My Stack', $info->displayName);
        $this->assertSame($this->tempRoot . '/my-stack', $info->path);
        $this->assertFalse($info->isIndirect);
    }

    public function testSanitizedNameUsesCanonicalProjectRules(): void
    {
        $stack = 'My Stack (v2)';
        mkdir($this->tempRoot . '/' . $stack);
        file_put_contents($this->tempRoot . '/' . $stack . '/compose.yaml', "services:\n");
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

    // projectFolder preserves the raw directory name; projectName is the sanitized version
    // (lowercased, special chars replaced with underscore, consecutive underscores collapsed).
    $this->assertSame('My Stack (v2)', $info->projectFolder);
    $this->assertSame('my_stack_v2', $info->projectName);
    }

    public function testProjectNameAlwaysLowercaseEvenWhenFolderUnrenamed(): void
    {
        // Simulate a folder with uppercase that already has the lowercase version existing
        // (so rename can't happen). projectName should still be lowercase.
        $stack = 'Audible_Plex_Downloader';
        $lowered = 'audible_plex_downloader';

        // Create both directories so rename is blocked
        mkdir($this->tempRoot . '/' . $stack);
        mkdir($this->tempRoot . '/' . $lowered);
        file_put_contents($this->tempRoot . '/' . $stack . '/compose.yaml', "services:\n");
        file_put_contents($this->tempRoot . '/' . $lowered . '/compose.yaml', "services:\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        // Folder stays uppercase since rename was blocked
        $this->assertSame($stack, $info->projectFolder);
        // But projectName must always be the sanitized lowercase version
        $this->assertSame($lowered, $info->projectName);
    }

    public function testIndirectStackResolution(): void
    {
        $stack = 'indirect-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        $indirectTarget = $this->tempRoot . '/actual_source';
        mkdir($stackDir);
        mkdir($indirectTarget);
        file_put_contents($stackDir . '/indirect', $indirectTarget);
        file_put_contents($indirectTarget . '/compose.yaml', "services:\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertTrue($info->isIndirect);
        $this->assertSame($indirectTarget, $info->composeSource);
    }

    public function testNonIndirectStackSource(): void
    {
        $stack = 'direct-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertFalse($info->isIndirect);
        $this->assertSame($stackDir, $info->composeSource);
    }

    public function testStructurallyInvalidIndirectPreserved(): void
    {
        $stack = 'bad-indirect';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        // Structurally invalid path (has directory traversal)
        file_put_contents("$stackDir/indirect", '/mnt/user/../etc/passwd');
        file_put_contents("$stackDir/compose.yaml", "services:\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertFalse($info->isIndirect);
        // Structurally invalid path is silently ignored — not stored as invalidIndirectPath
        $this->assertFileExists("$stackDir/indirect");
        $this->assertNull($info->invalidIndirectPath);
    }

    public function testMissingDirIndirectPreserved(): void
    {
        $stack = 'missing-dir-indirect';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        // Valid-looking path but directory doesn't exist
        file_put_contents("$stackDir/indirect", '/mnt/user/nonexistent_share');
        file_put_contents("$stackDir/compose.yaml", "services:\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertTrue($info->isIndirect);
        $this->assertSame('folder', $info->indirectMode);
        $this->assertSame('/mnt/user/nonexistent_share', $info->composeSource);
        $this->assertNull($info->composeFilePath);
        $this->assertFileExists("$stackDir/indirect");
        $this->assertSame('/mnt/user/nonexistent_share', $info->invalidIndirectPath);
    }

    public function testInvalidIndirectLoadsDegradedWithNoLocalCompose(): void
    {
        $stack = 'degraded-indirect';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        // Path to nonexistent directory, no local compose file
        file_put_contents("$stackDir/indirect", '/mnt/user/gone');

        // Should NOT throw — constructs in degraded mode
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertTrue($info->isIndirect);
        $this->assertSame('folder', $info->indirectMode);
        $this->assertNull($info->composeFilePath);
        $this->assertSame('/mnt/user/gone', $info->invalidIndirectPath);
        $this->assertInstanceOf(\OverrideInfo::class, $info->overrideInfo);
    }

    public function testMissingIndirectFilePreservedInFileMode(): void
    {
        $stack = 'missing-file-indirect';
        $stackDir = $this->tempRoot . '/' . $stack;
        $missingFile = $this->tempRoot . '/external/missing.compose.yaml';
        mkdir($stackDir);
        file_put_contents("$stackDir/indirect", $missingFile);
        file_put_contents("$stackDir/indirect_mode", 'file');

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertTrue($info->isIndirect);
        $this->assertSame('file', $info->indirectMode);
        $this->assertSame(dirname($missingFile), $info->composeSource);
        $this->assertNull($info->composeFilePath);
        $this->assertSame($missingFile, $info->invalidIndirectPath);
    }

    public function testValidIndirectHasNoInvalidPath(): void
    {
        $stack = 'valid-indirect';
        $stackDir = $this->tempRoot . '/' . $stack;
        $indirectTarget = $this->tempRoot . '/valid_source';
        mkdir($stackDir);
        mkdir($indirectTarget);
        file_put_contents("$stackDir/indirect", $indirectTarget);
        file_put_contents("$indirectTarget/compose.yaml", "services:\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertTrue($info->isIndirect);
        $this->assertNull($info->invalidIndirectPath);
        $this->assertFileDoesNotExist("$stackDir/indirect.invalid");
    }

    public function testHiddenDirectoryIndirectNotFlaggedAsTraversal(): void
    {
        $stack = 'hidden-dir-indirect';
        $stackDir = $this->tempRoot . '/' . $stack;
        $hiddenTarget = $this->tempRoot . '/.appdata/compose';
        mkdir($stackDir);
        mkdir($hiddenTarget, 0755, true);
        file_put_contents("$stackDir/indirect", $hiddenTarget);
        file_put_contents("$hiddenTarget/compose.yaml", "services:\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertTrue($info->isIndirect);
        $this->assertSame($hiddenTarget, $info->composeSource);
        $this->assertFileDoesNotExist("$stackDir/indirect.invalid");
    }

    public function testAllFromRootSkipsInvalidWithoutCrashing(): void
    {
        // Create a valid stack
        $validDir = $this->tempRoot . '/valid-stack';
        mkdir($validDir);
        file_put_contents("$validDir/compose.yaml", "services:\n");

        // Create an invalid directory (no compose file, no indirect)
        $invalidDir = $this->tempRoot . '/not-a-stack';
        mkdir($invalidDir);

        // Place the plugin-managed version file at the root (as compose.manager.plg does)
        file_put_contents($this->tempRoot . '/version', "1\n");

        $stacks = \StackInfo::allFromRoot($this->tempRoot);

        $this->assertCount(1, $stacks);
        $this->assertSame('valid-stack', $stacks[0]->projectFolder);
    }

    // ===========================================
    // listProjectFolders() Tests
    // ===========================================

    public function testListProjectFoldersReturnsOnlyDirectories(): void
    {
        mkdir($this->tempRoot . '/stack-a');
        mkdir($this->tempRoot . '/stack-b');
        file_put_contents($this->tempRoot . '/some-file.txt', 'data');

        $folders = \StackInfo::listProjectFolders($this->tempRoot);

        $this->assertContains('stack-a', $folders);
        $this->assertContains('stack-b', $folders);
        $this->assertNotContains('some-file.txt', $folders);
    }

    public function testListProjectFoldersSkipsVersionFile(): void
    {
        mkdir($this->tempRoot . '/my-stack');
        file_put_contents($this->tempRoot . '/version', "1\n");

        $folders = \StackInfo::listProjectFolders($this->tempRoot);

        $this->assertContains('my-stack', $folders);
        $this->assertNotContains('version', $folders);
    }

    public function testListProjectFoldersExcludesDotEntries(): void
    {
        mkdir($this->tempRoot . '/stack-c');

        $folders = \StackInfo::listProjectFolders($this->tempRoot);

        $this->assertNotContains('.', $folders);
        $this->assertNotContains('..', $folders);
    }

    public function testListProjectFoldersEmptyRootReturnsEmptyArray(): void
    {
        $folders = \StackInfo::listProjectFolders($this->tempRoot);
        $this->assertSame([], $folders);
    }

    public function testListProjectFoldersNonexistentRootReturnsEmptyArray(): void
    {
        $folders = \StackInfo::listProjectFolders('/nonexistent/path/that/does/not/exist');
        $this->assertSame([], $folders);
    }

    public function testListProjectFoldersHonorsSavedStackOrder(): void
    {
        mkdir($this->tempRoot . '/stack-a');
        mkdir($this->tempRoot . '/stack-b');
        mkdir($this->tempRoot . '/stack-c');

        $this->assertTrue(saveComposeStackOrder($this->tempRoot, ['stack-c', 'stack-a', 'stack-b']));

        $folders = \StackInfo::listProjectFolders($this->tempRoot);

        $this->assertSame(['stack-c', 'stack-a', 'stack-b'], $folders);
    }

    // =========================================== 
    // Compose File Resolution Tests
    // ===========================================

    public function testComposeFilePathNullWhenNoFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no compose file found/');
        $stack = 'no-compose';
        mkdir($this->tempRoot . '/' . $stack);
        \StackInfo::fromProject($this->tempRoot, $stack);
    }

    public function testComposeFilePathResolvedWithComposeYaml(): void
    {
        $stack = 'has-compose';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  web:\n    image: nginx\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame("$stackDir/compose.yaml", $info->composeFilePath);
    }

    public function testComposeFilePathResolvedWithDockerComposeYml(): void
    {
        $stack = 'legacy-compose';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame("$stackDir/docker-compose.yml", $info->composeFilePath);
    }

    public function testComposeFilePathResolvedWithComposeYml(): void
    {
        $stack = 'compose-yml';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yml", "services:\n  web:\n    image: nginx\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame("$stackDir/compose.yml", $info->composeFilePath);
    }

    public function testComposeFilePathResolvedWithDockerComposeYaml(): void
    {
        $stack = 'docker-compose-yaml';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/docker-compose.yaml", "services:\n  web:\n    image: nginx\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame("$stackDir/docker-compose.yaml", $info->composeFilePath);
    }

    public function testComposeFilePriorityPrefersComposeYamlOverLegacyNames(): void
    {
        $stack = 'priority-compose-yaml';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  preferred:\n    image: nginx\n");
        file_put_contents("$stackDir/docker-compose.yml", "services:\n  fallback:\n    image: redis\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame("$stackDir/compose.yaml", $info->composeFilePath);
    }

    public function testComposeFilePathResolvedViaIndirect(): void
    {
        $stack = 'indirect-compose';
        $stackDir = $this->tempRoot . '/' . $stack;
        $indirectTarget = $this->tempRoot . '/external_source';
        mkdir($stackDir);
        mkdir($indirectTarget);
        file_put_contents("$stackDir/indirect", $indirectTarget);
        file_put_contents("$indirectTarget/compose.yaml", "services:\n  app:\n    image: redis\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame("$indirectTarget/compose.yaml", $info->composeFilePath);
    }

    // ===========================================
    // Override Info Tests
    // ===========================================

    public function testOverrideInfoIsResolved(): void
    {
        $stack = 'override-stack';
        mkdir($this->tempRoot . '/' . $stack);
        file_put_contents($this->tempRoot . '/' . $stack . '/compose.yaml', "services:\n");
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertInstanceOf(\OverrideInfo::class, $info->overrideInfo);
        $this->assertInstanceOf(\OverrideInfo::class, $info->getOverrideInfo());
    }

    public function testGetOverridePathDelegates(): void
    {
        $stack = 'with-override';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/compose.override.yaml", '# override');

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame("$stackDir/compose.override.yaml", $info->getOverridePath());
    }

    public function testPreferredOverridePathUsesIndirectFolder(): void
    {
        $stack = 'preferred-folder';
        $stackDir = $this->tempRoot . '/' . $stack;
        $indirectDir = $this->tempRoot . '/indirect-folder-target';
        mkdir($stackDir);
        mkdir($indirectDir);
        file_put_contents($stackDir . '/indirect', $indirectDir);
        file_put_contents($indirectDir . '/compose.yaml', "services:\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame($indirectDir . '/compose.override.yaml', $info->getPreferredOverridePath());
    }

    public function testPreferredOverridePathUsesIndirectFile(): void
    {
        $stack = 'preferred-file';
        $stackDir = $this->tempRoot . '/' . $stack;
        $indirectFile = $this->tempRoot . '/indirect-file-target/custom.compose.yaml';
        mkdir($stackDir);
        mkdir(dirname($indirectFile), 0755, true);
        file_put_contents($stackDir . '/indirect', $indirectFile);
        file_put_contents($indirectFile, "services:\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame(dirname($indirectFile) . '/custom.compose.override.yaml', $info->getPreferredOverridePath());
    }

    // ===========================================
    // Lazy Metadata Getter Tests
    // ===========================================

    public function testGetNameFromFile(): void
    {
        $stack = 'named-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/name", "My Display Name");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('My Display Name', $info->getName());
    }

    public function testGetNameFallsBackToProject(): void
    {
        $stack = 'unnamed-stack';
        mkdir($this->tempRoot . '/' . $stack);
        file_put_contents($this->tempRoot . '/' . $stack . '/compose.yaml', "services:\n");
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('unnamed-stack', $info->getName());
    }

    public function testGetDescription(): void
    {
        $stack = 'desc-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/description", "A test stack\nwith multiple lines");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame("A test stack\nwith multiple lines", $info->getDescription());
    }

    public function testGetDescriptionEmptyWhenNoFile(): void
    {
        $stack = 'no-desc';
        mkdir($this->tempRoot . '/' . $stack);
        file_put_contents($this->tempRoot . '/' . $stack . '/compose.yaml', "services:\n");
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('', $info->getDescription());
    }

    public function testGetEnvFilePath(): void
    {
        $stack = 'env-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/envpath", "/path/to/.env");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('/path/to/.env', $info->getEnvFilePath());
    }

    public function testGetEnvFilePathNullWhenNoFile(): void
    {
        $stack = 'no-env';
        mkdir($this->tempRoot . '/' . $stack);
        file_put_contents($this->tempRoot . '/' . $stack . '/compose.yaml', "services:\n");
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertNull($info->getEnvFilePath());
    }

    public function testGetEffectiveEnvFilePathFallsBackWhenEnvpathEmpty(): void
    {
        $stack = 'empty-envpath-fallback';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/.env", "KEY=value\n");
        file_put_contents("$stackDir/envpath", "   \n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame("$stackDir/.env", $info->getEffectiveEnvFilePath());
    }

    public function testGetEffectiveEnvFilePathFallsBackWhenEnvpathInvalid(): void
    {
        $stack = 'invalid-envpath-fallback';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/.env", "KEY=value\n");
        file_put_contents("$stackDir/envpath", "/path/that/does/not/exist/.env\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame("$stackDir/.env", $info->getEffectiveEnvFilePath());
    }

    public function testBuildComposeArgsUsesLocalEnvWhenEnvpathInvalid(): void
    {
        $stack = 'invalid-envpath-build-args';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/.env", "KEY=value\n");
        file_put_contents("$stackDir/envpath", "/path/that/does/not/exist/.env\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $args = $info->buildComposeArgs();

        $this->assertStringContainsString('--env-file', $args['envFile']);
        $this->assertStringContainsString($stackDir . '/.env', $args['envFile']);
        $this->assertSame($stackDir . '/.env', $args['envFilePath']);
    }

    public function testGetIconUrlValid(): void
    {
        $stack = 'icon-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/icon_url", "https://example.com/icon.png");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('https://example.com/icon.png', $info->getIconUrl());
    }

    public function testGetIconUrlNullForInvalidUrl(): void
    {
        $stack = 'bad-icon';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/icon_url", "not-a-url");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertNull($info->getIconUrl());
    }

    public function testGetIconUrlNullForFtpScheme(): void
    {
        $stack = 'ftp-icon';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/icon_url", "ftp://example.com/icon.png");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertNull($info->getIconUrl());
    }

    public function testGetIconUrlAcceptsExactSvgDataUrl(): void
    {
        $stack = 'data-icon';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        $iconDataUrl = "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='20 10 90 90'><text y='1em' font-size='90'>%F0%9F%94%A7</text></svg>";
        file_put_contents("$stackDir/icon_url", $iconDataUrl);

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame($iconDataUrl, $info->getIconUrl());
    }

    public function testGetWebUIUrl(): void
    {
        $stack = 'webui-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/webui_url", "http://192.168.1.1:8080");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('http://192.168.1.1:8080', $info->getWebUIUrl());
    }

    public function testGetWebUIUrlNullWhenInvalid(): void
    {
        $stack = 'bad-webui';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/webui_url", "javascript:alert(1)");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertNull($info->getWebUIUrl());
    }

    public function testGetWebUIUrlWithPlaceholders(): void
    {
        $stack = 'placeholder-webui';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/webui_url", "http://[IP]:[PORT:8080]/");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('http://[IP]:[PORT:8080]/', $info->getWebUIUrl());
    }

    public function testGetWebUIUrlWithBarePortPlaceholder(): void
    {
        $stack = 'bare-port-webui';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/webui_url", "http://[IP]:[PORT]/");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('http://[IP]:[PORT]/', $info->getWebUIUrl());
    }

    public function testGetDefaultProfiles(): void
    {
        $stack = 'profiles-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/default_profile", "dev, test , production");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame(['dev', 'test', 'production'], $info->getDefaultProfiles());
    }

    public function testGetDefaultProfilesEmptyWhenNoFile(): void
    {
        $stack = 'no-profiles';
        mkdir($this->tempRoot . '/' . $stack);
        file_put_contents($this->tempRoot . '/' . $stack . '/compose.yaml', "services:\n");
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame([], $info->getDefaultProfiles());
    }

    public function testGetRunningProfiles(): void
    {
        $stack = 'running-profiles-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/running_profiles", "debug,monitoring");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame(['debug', 'monitoring'], $info->getRunningProfiles());
    }

    public function testGetRunningProfilesEmptyWhenNoFile(): void
    {
        $stack = 'no-running-profiles';
        mkdir($this->tempRoot . '/' . $stack);
        file_put_contents($this->tempRoot . '/' . $stack . '/compose.yaml', "services:\n");
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame([], $info->getRunningProfiles());
    }

    public function testGetAutostartTrue(): void
    {
        $stack = 'autostart-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/autostart", "true");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertTrue($info->getAutostart());
    }

    public function testGetAutostartFalseWhenNoFile(): void
    {
        $stack = 'no-autostart';
        mkdir($this->tempRoot . '/' . $stack);
        file_put_contents($this->tempRoot . '/' . $stack . '/compose.yaml', "services:\n");
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertFalse($info->getAutostart());
    }

    public function testGetAutostartFalseWhenNotTrue(): void
    {
        $stack = 'false-autostart';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/autostart", "false");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertFalse($info->getAutostart());
    }

    public function testGetStartedAt(): void
    {
        $stack = 'started-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/started_at", "2024-06-15T10:30:00Z");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('2024-06-15T10:30:00Z', $info->getStartedAt());
    }

    public function testGetStartedAtNullWhenNoFile(): void
    {
        $stack = 'not-started';
        mkdir($this->tempRoot . '/' . $stack);
        file_put_contents($this->tempRoot . '/' . $stack . '/compose.yaml', "services:\n");
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertNull($info->getStartedAt());
    }

    public function testGetProfiles(): void
    {
        $stack = 'json-profiles';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/profiles", json_encode(['dev', 'staging', 'prod']));

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame(['dev', 'staging', 'prod'], $info->getProfiles());
    }

    public function testGetProfilesEmptyWhenNoFile(): void
    {
        $stack = 'no-json-profiles';
        mkdir($this->tempRoot . '/' . $stack);
        file_put_contents($this->tempRoot . '/' . $stack . '/compose.yaml', "services:\n");
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame([], $info->getProfiles());
    }

    public function testGetProfilesEmptyForInvalidJson(): void
    {
        $stack = 'bad-json';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/profiles", "not-valid-json{");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame([], $info->getProfiles());
    }

    // ===========================================
    // Metadata Caching Tests
    // ===========================================

    public function testMetadataIsCachedOnSecondAccess(): void
    {
        $stack = 'cached-name';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n");
        file_put_contents("$stackDir/name", "Original Name");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        // First read
        $this->assertSame('Original Name', $info->getName());

        // Mutate the file — should still return cached value
        file_put_contents("$stackDir/name", "Changed Name");
        $this->assertSame('Original Name', $info->getName());
    }

    public function testFromProjectReturnsCachedInstance(): void
    {
        $stack = 'cached-instance';
        mkdir($this->tempRoot . '/' . $stack);
        file_put_contents($this->tempRoot . '/' . $stack . '/compose.yaml', "services:\n");

        $first = \StackInfo::fromProject($this->tempRoot, $stack);
        $second = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame($first, $second);
    }

    public function testClearCacheForcesFreshInstance(): void
    {
        $stack = 'clear-cache';
        mkdir($this->tempRoot . '/' . $stack);
        file_put_contents($this->tempRoot . '/' . $stack . '/compose.yaml', "services:\n");

        $first = \StackInfo::fromProject($this->tempRoot, $stack);
        \StackInfo::clearCache();
        $second = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertNotSame($first, $second);
    }

    // ===========================================
    // buildComposeArgs Tests
    // ===========================================

    public function testBuildComposeArgsBasic(): void
    {
        $stack = 'args-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  web:\n    image: nginx\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $args = $info->buildComposeArgs();

        $this->assertArrayHasKey('projectName', $args);
        $this->assertArrayHasKey('files', $args);
        $this->assertArrayHasKey('envFile', $args);
        $this->assertSame($info->projectFolder, $args['projectName']);
        $this->assertStringContainsString('compose.yaml', $args['files']);
    }

    public function testBuildComposeArgsWithEnvFile(): void
    {
        $stack = 'env-args';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  web:\n    image: nginx\n");
        $envPath = $stackDir . '/.env';
        file_put_contents($envPath, "KEY=value");
        file_put_contents("$stackDir/envpath", $envPath);

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $args = $info->buildComposeArgs();

        $this->assertStringContainsString('--env-file', $args['envFile']);
    }

    public function testBuildComposeArgsWithComposeFileInEnv(): void
    {
        $stack = 'env-compose-file';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/compose.debug.yaml", "services:\n  web:\n    image: nginx:alpine\n");
        $envPath = $stackDir . '/.env';
        file_put_contents($envPath, "COMPOSE_FILE=compose.debug.yaml");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $args = $info->buildComposeArgs();

        $this->assertSame(3, count($args['filePaths']));
        $this->assertStringContainsString('compose.debug.yaml', $args['files']);
        $this->assertContains($stackDir . '/compose.debug.yaml', $args['filePaths']);
    }

    public function testBuildComposeArgsWithQuotedComposeFileInEnv(): void
    {
        $stack = 'env-compose-file-quoted';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/compose.debug.yaml", "services:\n  web:\n    image: nginx:alpine\n");
        file_put_contents("$stackDir/compose.extra.yaml", "services:\n  web:\n    image: nginx:alpine\n");
        $envPath = $stackDir . '/.env';
        // Use PATH_SEPARATOR (what splitComposeFileValue() actually splits on,
        // matching Docker Compose's own OS-dependent COMPOSE_FILE separator)
        // instead of a hardcoded ':' so this passes on Windows dev machines too.
        file_put_contents($envPath, 'COMPOSE_FILE="compose.debug.yaml"' . PATH_SEPARATOR . 'compose.extra.yaml');

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $args = $info->buildComposeArgs();

        $this->assertSame(4, count($args['filePaths']));
        $this->assertStringContainsString('compose.debug.yaml', $args['files']);
        $this->assertStringContainsString('compose.extra.yaml', $args['files']);
        $this->assertContains($stackDir . '/compose.debug.yaml', $args['filePaths']);
        $this->assertContains($stackDir . '/compose.extra.yaml', $args['filePaths']);
        $this->assertContains($stackDir . '/compose.override.yaml', $args['filePaths']);
        $this->assertSame(
            implode(PATH_SEPARATOR, [
                $stackDir . '/compose.yaml',
                $stackDir . '/compose.override.yaml',
                $stackDir . '/compose.debug.yaml',
                $stackDir . '/compose.extra.yaml',
            ]),
            $args['fileList']
        );
    }

    public function testBuildComposeArgsWithOverride(): void
    {
        $stack = 'override-args';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/compose.override.yaml", "services:\n  web:\n    ports:\n      - '80:80'\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $args = $info->buildComposeArgs();

        // Should use two compose files: main + override
        $this->assertSame(2, count($args['filePaths']));
        $this->assertStringContainsString('compose.override.yaml', $args['files']);
    }

    public function testBuildComposeArgsDefaultDiscoveryOmitsExplicitEnvWhenItMatchesLocalEnv(): void
    {
        $stack = 'default-discovery-local-env';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/.env", "KEY=value\n");
        file_put_contents("$stackDir/envpath", $stackDir . '/./.env');
        file_put_contents("$stackDir/use_default_compose_files", 'true');

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $args = $info->buildComposeArgs();

        $this->assertFalse($info->useDefaultComposeFileDiscovery(), 'Explicit envpath should disable default discovery.');
        $this->assertStringContainsString('--env-file', $args['envFile']);

        // envFile/envFilePath are resolved via realpath(), which normalizes to
        // native path separators (backslashes on Windows); normalize both sides
        // before comparing so this isn't sensitive to the dev OS.
        $normalize = static fn(string $path): string => str_replace('\\', '/', $path);
        $expectedEnvPath = $normalize($stackDir . '/.env');
        $this->assertStringContainsString($expectedEnvPath, $normalize($args['envFile']));
        $this->assertSame($expectedEnvPath, $normalize($args['envFilePath']));
    }

    public function testBuildComposeArgsIndirectFileModeDisablesDefaultDiscovery(): void
    {
        $stack = 'indirect-file-args';
        $stackDir = $this->tempRoot . '/' . $stack;
        $externalDir = $this->tempRoot . '/external-file-stack';
        $externalFile = $externalDir . '/custom.compose.yaml';
        mkdir($stackDir);
        mkdir($externalDir, 0755, true);
        file_put_contents($externalFile, "services:\n  app:\n    image: redis\n");
        file_put_contents("$stackDir/indirect", $externalFile);
        file_put_contents("$stackDir/indirect_mode", 'file');
        file_put_contents("$stackDir/use_default_compose_files", 'true');

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $args = $info->buildComposeArgs();

        $this->assertFalse($info->useDefaultComposeFileDiscovery());
        $this->assertStringContainsString('-f', $args['files']);
        $this->assertStringContainsString($externalFile, $args['files']);
        $this->assertStringNotContainsString('--project-directory', $args['files']);
    }

    // ===========================================
    // createNew() Tests
    // ===========================================

    public function testCreateNewBasicStack(): void
    {
        $stack = \StackInfo::createNew($this->tempRoot, 'My Stack');

        $this->assertInstanceOf(\StackInfo::class, $stack);
        $this->assertDirectoryExists($stack->path);
        $this->assertSame('My Stack', $stack->getName());
        $this->assertNotNull($stack->composeFilePath);
        $this->assertFileExists($stack->composeFilePath);
        $this->assertSame("services:\n", file_get_contents($stack->composeFilePath));
        $this->assertFalse($stack->isIndirect);
    }

    public function testCreateNewSanitizesFolderName(): void
    {
        $stack = \StackInfo::createNew($this->tempRoot, 'My "Stack" (v2)');

        // sanitizeProjectString lowercases and replaces unsupported chars
        $this->assertSame('my_stack_v2', $stack->projectFolder);
        $this->assertSame('My "Stack" (v2)', $stack->getName());
    }

    public function testCreateNewWithDescription(): void
    {
        $stack = \StackInfo::createNew($this->tempRoot, 'Described', 'A test stack');

        $this->assertSame('A test stack', $stack->getDescription());
        $this->assertFileExists($stack->path . '/description');
    }

    public function testCreateNewWithoutDescription(): void
    {
        $stack = \StackInfo::createNew($this->tempRoot, 'NoDesc');

        $this->assertSame('', $stack->getDescription());
        $this->assertFileDoesNotExist($stack->path . '/description');
    }

    public function testCreateNewWithIndirectPath(): void
    {
        $indirectDir = $this->tempRoot . '/external';
        mkdir($indirectDir, 0755, true);

        $stack = \StackInfo::createNew($this->tempRoot, 'Indirect Stack', '', $indirectDir);

        $this->assertTrue($stack->isIndirect);
        $this->assertSame($indirectDir, $stack->composeSource);
        $this->assertFileExists($stack->path . '/indirect');
        $this->assertSame($indirectDir, trim(file_get_contents($stack->path . '/indirect')));
        // Should have created compose.yaml at indirect target
        $this->assertFileExists($indirectDir . '/compose.yaml');
        // New stacks also create an app-managed project override template.
        $this->assertFileExists($stack->path . '/compose.override.yaml');
    }

    public function testCreateNewIndirectExistingComposeFile(): void
    {
        $indirectDir = $this->tempRoot . '/existing';
        mkdir($indirectDir, 0755, true);
        file_put_contents("$indirectDir/compose.yaml", "services:\n  web:\n    image: nginx\n");

        $stack = \StackInfo::createNew($this->tempRoot, 'Existing Indirect', '', $indirectDir);

        $this->assertTrue($stack->isIndirect);
        // Should NOT overwrite existing compose file
        $this->assertSame("services:\n  web:\n    image: nginx\n", file_get_contents("$indirectDir/compose.yaml"));
    }

    public function testCreateNewWithIndirectComposeFileWritesFileModeWithoutCreatingDefaultCompose(): void
    {
        $indirectDir = $this->tempRoot . '/file-mode';
        $indirectFile = $indirectDir . '/custom.compose.yaml';
        mkdir($indirectDir, 0755, true);
        file_put_contents($indirectFile, "services:\n  web:\n    image: nginx\n");

        $stack = \StackInfo::createNew($this->tempRoot, 'Indirect File Stack', '', $indirectFile);

        $this->assertTrue($stack->isIndirect);
        $this->assertSame('file', $stack->indirectMode);
        $this->assertSame(dirname($indirectFile), $stack->composeSource);
        $this->assertSame($indirectFile, $stack->composeFilePath);
        $this->assertSame('file', trim(file_get_contents($stack->path . '/indirect_mode')));
        $this->assertFileDoesNotExist($indirectDir . '/compose.yaml');
    }

    public function testCreateNewHandlesFolderCollision(): void
    {
        // Pre-create the folder that sanitizeProjectString would produce
        $sanitized = 'collide';
        $existingDir = $this->tempRoot . '/' . $sanitized;
        mkdir($existingDir, 0755, true);

        $stack = \StackInfo::createNew($this->tempRoot, 'Collide');

        // Should have created a different folder (with collision suffix)
        $this->assertNotSame($sanitized, $stack->projectFolder);
        $this->assertStringStartsWith($sanitized, $stack->projectFolder);
        $this->assertDirectoryExists($stack->path);
    }

    public function testCreateNewInitializesOverride(): void
    {
        $stack = \StackInfo::createNew($this->tempRoot, 'Override Init');

        $this->assertInstanceOf(\OverrideInfo::class, $stack->overrideInfo);
        // New stacks should always include a project override template.
        $overridePath = $stack->getOverridePath();
        $this->assertNotNull($overridePath);
        $this->assertFileExists($overridePath);
        $this->assertStringContainsString('services: {}', (string) file_get_contents($overridePath));
    }

    public function testCreateNewIsCached(): void
    {
        $stack = \StackInfo::createNew($this->tempRoot, 'Cached New');

        // Fetching via fromProject should return the same cached instance
        $fetched = \StackInfo::fromProject($this->tempRoot, $stack->projectFolder);
        $this->assertSame($stack, $fetched);
    }

    public function testCreateNewWritesNameFile(): void
    {
        $stack = \StackInfo::createNew($this->tempRoot, 'Display Name');

        $this->assertFileExists($stack->path . '/name');
        $this->assertSame('Display Name', file_get_contents($stack->path . '/name'));
    }

    public function testCreateNewThrowsOnEmptyName(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stack name cannot be empty');
        \StackInfo::createNew($this->tempRoot, '');
    }

    public function testCreateNewThrowsOnWhitespaceName(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stack name cannot be empty');
        \StackInfo::createNew($this->tempRoot, '   ');
    }

    public function testCreateNewCollisionSuffixDoesNotCompound(): void
    {
        // Pre-create the base folder (already lowercase to match sanitization)
        $baseName = 'compoundtest';
        mkdir($this->tempRoot . '/' . $baseName, 0755, true);

        $stack = \StackInfo::createNew($this->tempRoot, $baseName);

        // The project name should be baseName + one collision suffix, not compounded
        $suffix = substr($stack->projectFolder, strlen($baseName));
        // The suffix should be a dash followed by digits (from collision avoidance)
        $this->assertMatchesRegularExpression('/^-\d+$/', $suffix);
    }

    // ===========================================
    // Container Counts & Stack State Tests
    // ===========================================

    /**
     * Helper: create a StackInfo and inject mock container data.
     *
     * @param array[] $containers Raw docker ps rows (each must have at least 'State')
     */
    private function createStackWithContainers(array $containers): \StackInfo
    {
        $stack = 'state-test';
        mkdir($this->tempRoot . '/' . $stack);
        file_put_contents($this->tempRoot . '/' . $stack . '/compose.yaml', "services:\n");
        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $info->setContainerList($containers);
        return $info;
    }

    public function testGetContainerCountsEmpty(): void
    {
        $info = $this->createStackWithContainers([]);
        $counts = $info->getContainerCounts();

        $this->assertSame(0, $counts['running']);
        $this->assertSame(0, $counts['stopped']);
        $this->assertSame(0, $counts['paused']);
        $this->assertSame(0, $counts['restarting']);
        $this->assertSame(0, $counts['total']);
    }

    public function testGetContainerCountsAllRunning(): void
    {
        $info = $this->createStackWithContainers([
            ['State' => 'running'],
            ['State' => 'running'],
            ['State' => 'running'],
        ]);
        $counts = $info->getContainerCounts();

        $this->assertSame(3, $counts['running']);
        $this->assertSame(0, $counts['stopped']);
        $this->assertSame(3, $counts['total']);
    }

    public function testGetContainerCountsMixedStates(): void
    {
        $info = $this->createStackWithContainers([
            ['State' => 'running'],
            ['State' => 'exited'],
            ['State' => 'paused'],
            ['State' => 'restarting'],
        ]);
        $counts = $info->getContainerCounts();

        $this->assertSame(1, $counts['running']);
        $this->assertSame(1, $counts['stopped']);
        $this->assertSame(1, $counts['paused']);
        $this->assertSame(1, $counts['restarting']);
        $this->assertSame(4, $counts['total']);
    }

    public function testGetContainerCountsTreatsUnknownNonRunningAsStopped(): void
    {
        $info = $this->createStackWithContainers([
            ['State' => 'created'],
            ['State' => 'dead'],
            ['State' => 'removing'],
        ]);
        $counts = $info->getContainerCounts();

        $this->assertSame(0, $counts['running']);
        $this->assertSame(3, $counts['stopped']);
        $this->assertSame(0, $counts['paused']);
        $this->assertSame(0, $counts['restarting']);
        $this->assertSame(3, $counts['total']);
    }

    public function testGetStackStateStartedWhenAllRunning(): void
    {
        $info = $this->createStackWithContainers([
            ['State' => 'running'],
            ['State' => 'running'],
        ]);
        $state = $info->getStackState();

        $this->assertSame('started', $state['state']);
        $this->assertSame('started', $state['label']);
        $this->assertSame('play', $state['shape']);
        $this->assertSame('green-text', $state['color']);
        $this->assertSame(2, $state['running']);
        $this->assertSame(2, $state['total']);
    }

    public function testGetStackStatePartialWhenSomeRunning(): void
    {
        $info = $this->createStackWithContainers([
            ['State' => 'running'],
            ['State' => 'exited'],
            ['State' => 'exited'],
        ]);
        $state = $info->getStackState();

        $this->assertSame('partial', $state['state']);
        $this->assertSame('partial (1/3)', $state['label']);
        $this->assertSame('exclamation-circle', $state['shape']);
        $this->assertSame('orange-text', $state['color']);
        $this->assertSame(1, $state['running']);
        $this->assertSame(3, $state['total']);
    }

    public function testGetStackStateStoppedWhenNoneRunning(): void
    {
        $info = $this->createStackWithContainers([
            ['State' => 'exited'],
            ['State' => 'exited'],
        ]);
        $state = $info->getStackState();

        $this->assertSame('stopped', $state['state']);
        $this->assertSame('stopped', $state['label']);
        $this->assertSame('square', $state['shape']);
        $this->assertSame('grey-text', $state['color']);
        $this->assertSame(0, $state['running']);
        $this->assertSame(2, $state['total']);
    }

    public function testGetStackStateStoppedWhenEmpty(): void
    {
        $info = $this->createStackWithContainers([]);
        $state = $info->getStackState();

        $this->assertSame('stopped', $state['state']);
        $this->assertSame(0, $state['running']);
        $this->assertSame(0, $state['total']);
    }

    public function testGetStackStatePausedWhenAllPausedNoneRunning(): void
    {
        $info = $this->createStackWithContainers([
            ['State' => 'paused'],
            ['State' => 'paused'],
        ]);
        $state = $info->getStackState();

        $this->assertSame('paused', $state['state']);
        $this->assertSame('paused', $state['label']);
        $this->assertSame('pause', $state['shape']);
        $this->assertSame('orange-text', $state['color']);
    }

    public function testGetStackStatePartialTrumpsRestarting(): void
    {
        // If some containers are running and one is restarting, it's partial
        $info = $this->createStackWithContainers([
            ['State' => 'running'],
            ['State' => 'restarting'],
        ]);
        $state = $info->getStackState();

        $this->assertSame('partial', $state['state']);
        $this->assertSame('partial (1/2)', $state['label']);
    }

    public function testGetStackStateCountsOnlyCreatedContainers(): void
    {
        // This is the core bug fix: total should be created containers (3),
        // not services defined in compose file (which could be higher with profiles).
        $info = $this->createStackWithContainers([
            ['State' => 'running'],
            ['State' => 'running'],
            ['State' => 'exited'],
        ]);
        $state = $info->getStackState();

        $this->assertSame(3, $state['total']);
        $this->assertSame(2, $state['running']);
        $this->assertSame('partial (2/3)', $state['label']);
    }
    /**
     * Bug #95: When importing an indirect project that already has docker-compose.yml,
     * compose manager should NOT create a new compose.yaml and should resolve the
     * override name based on the existing file.
     */
    public function testCreateNewIndirectExistingDockerComposeYml(): void
    {
        $indirectDir = $this->tempRoot . '/falco';
        mkdir($indirectDir, 0755, true);
        file_put_contents("$indirectDir/docker-compose.yml", "services:\n  falco:\n    image: falco\n");

        $stack = \StackInfo::createNew($this->tempRoot, 'Falco', '', $indirectDir);

        $this->assertTrue($stack->isIndirect);
        // Should NOT create compose.yaml when docker-compose.yml already exists
        $this->assertFileDoesNotExist("$indirectDir/compose.yaml",
            "compose.yaml should not be created when docker-compose.yml already exists");
        // Should NOT overwrite existing compose file
        $this->assertSame("services:\n  falco:\n    image: falco\n",
            file_get_contents("$indirectDir/docker-compose.yml"));
        // The resolved compose file should be the existing docker-compose.yml
        $this->assertSame("$indirectDir/docker-compose.yml", $stack->composeFilePath);
        // The override should be named to match docker-compose.yml, not compose.yaml
        $this->assertSame('docker-compose.override.yml', $stack->overrideInfo->computedName);
    }

    /**
     * Bug #95 variant: Same issue with docker-compose.yaml
     */
    public function testCreateNewIndirectExistingDockerComposeYaml(): void
    {
        $indirectDir = $this->tempRoot . '/test-yaml';
        mkdir($indirectDir, 0755, true);
        file_put_contents("$indirectDir/docker-compose.yaml", "services:\n  app:\n    image: app\n");

        $stack = \StackInfo::createNew($this->tempRoot, 'TestYaml', '', $indirectDir);

        $this->assertTrue($stack->isIndirect);
        $this->assertFileDoesNotExist("$indirectDir/compose.yaml");
        $this->assertSame("$indirectDir/docker-compose.yaml", $stack->composeFilePath);
        $this->assertSame('docker-compose.override.yaml', $stack->overrideInfo->computedName);
    }

    /**
     * Bug #95 variant: Same issue with compose.yml
     */
    public function testCreateNewIndirectExistingComposeYml(): void
    {
        $indirectDir = $this->tempRoot . '/test-yml';
        mkdir($indirectDir, 0755, true);
        file_put_contents("$indirectDir/compose.yml", "services:\n  svc:\n    image: svc\n");

        $stack = \StackInfo::createNew($this->tempRoot, 'TestYml', '', $indirectDir);

        $this->assertTrue($stack->isIndirect);
        $this->assertFileDoesNotExist("$indirectDir/compose.yaml");
        $this->assertSame("$indirectDir/compose.yml", $stack->composeFilePath);
        $this->assertSame('compose.override.yml', $stack->overrideInfo->computedName);
    }
}
