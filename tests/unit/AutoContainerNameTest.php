<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/ComposeCommandBuilder.php';

/**
 * Automatic container names: the opt-in `auto_container_name` file makes the
 * stack add a generated container_name layer for services that have none.
 *
 * A stand-in docker on PATH answers `compose ... config --format json` with
 * the services of the test (what compose prints after merging every file).
 */
final class AutoContainerNameTest extends TestCase
{
    private string $root;
    private string $bin;
    private string $originalPath;

    protected function setUp(): void
    {
        parent::setUp();
        \StackInfo::clearCache();
        $this->root = $this->createTempDir();
        $this->bin = $this->createTempDir();
        $this->originalPath = (string) getenv('PATH');

        file_put_contents($this->bin . '/docker', "#!/bin/bash\ncat \"\$(dirname \"\$0\")/config.json\"\n");
        chmod($this->bin . '/docker', 0755);
        putenv('PATH=' . $this->bin . ':' . $this->originalPath);
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->originalPath);
        \StackInfo::clearCache();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $services What `docker compose config` reports
     */
    private function makeStack(string $name, array $services, bool $optIn = true): string
    {
        $stackDir = $this->root . '/' . $name;
        mkdir($stackDir);
        file_put_contents($stackDir . '/name', $name);
        file_put_contents($stackDir . '/compose.yaml', "services:\n  web:\n    image: nginx\n");
        if ($optIn) {
            file_put_contents($stackDir . '/auto_container_name', 'true');
        }
        file_put_contents($this->bin . '/config.json', json_encode(['services' => $services]));
        return $stackDir;
    }

    public function testStackNameMustBeAValidContainerName(): void
    {
        $this->assertNull(\StackInfo::autoContainerNameProblem('app'));
        $this->assertNull(\StackInfo::autoContainerNameProblem('My_App.2-b'));
        $this->assertNotNull(\StackInfo::autoContainerNameProblem(''));
        $this->assertNotNull(\StackInfo::autoContainerNameProblem('My App'));
        $this->assertNotNull(\StackInfo::autoContainerNameProblem('-app'));
        $this->assertStringContainsString("'My App'", (string) \StackInfo::autoContainerNameProblem('My App'));
    }

    public function testServiceNamedLikeTheStackGetsTheStackNameAndOthersAreSuffixed(): void
    {
        $names = \StackInfo::buildAutoContainerNames('app', ['app' => [], 'db' => [], 'cache' => []]);
        $this->assertSame(['app' => 'app', 'db' => 'app-db', 'cache' => 'app-cache'], $names);
    }

    public function testContainerNameSetByTheUserIsKept(): void
    {
        $names = \StackInfo::buildAutoContainerNames('app', [
            'db' => ['container_name' => 'mine-db'],
            'cache' => [],
        ]);
        $this->assertSame(['cache' => 'app-cache'], $names);
    }

    public function testServicesWithMoreThanOneReplicaAreLeftAlone(): void
    {
        $names = \StackInfo::buildAutoContainerNames('app', [
            'a' => ['deploy' => ['replicas' => 2]],
            'b' => ['scale' => 3],
            'c' => ['deploy' => ['replicas' => 1]],
        ]);
        $this->assertSame(['c' => 'app-c'], $names);
    }

    public function testOptInAddsTheGeneratedFileAsTheLastLayer(): void
    {
        $stackDir = $this->makeStack('app', ['app' => [], 'db' => [], 'cache' => ['container_name' => 'mine-cache']]);

        $spec = \ComposeCommandBuilder::fromProject($this->root, 'app', 'up');

        $files = $spec['composeFiles'];
        $this->assertSame($stackDir . '/compose.yaml', $files[0]);
        $generated = end($files);
        $this->assertStringEndsWith('/app.container-names.yaml', $generated);
        $content = (string) file_get_contents($generated);
        $this->assertStringContainsString("\"app\":\n    container_name: \"app\"", $content);
        $this->assertStringContainsString("\"db\":\n    container_name: \"app-db\"", $content);
        $this->assertStringNotContainsString('cache', $content);
        @unlink($generated);
    }

    public function testNothingIsAddedWithoutOptIn(): void
    {
        $stackDir = $this->makeStack('plain', ['web' => []], false);

        $spec = \ComposeCommandBuilder::fromProject($this->root, 'plain', 'up');

        $this->assertSame([$stackDir . '/compose.yaml'], $spec['composeFiles']);
    }

    public function testInvalidStackNameIsRefusedForUpAndNotRewritten(): void
    {
        $stackDir = $this->makeStack('badname', ['web' => []]);
        file_put_contents($stackDir . '/name', 'Bad Name');

        try {
            \ComposeCommandBuilder::fromProject($this->root, 'badname', 'up');
            $this->fail('up should be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("'Bad Name' is not a valid container name", $e->getMessage());
        }

        // Stopping must still work, and no names are generated.
        $spec = \ComposeCommandBuilder::fromProject($this->root, 'badname', 'down');
        $this->assertSame([$stackDir . '/compose.yaml'], $spec['composeFiles']);
    }

    public function testNoFileIsKeptWhenEveryServiceHasAName(): void
    {
        $this->makeStack('named', ['web' => ['container_name' => 'web']]);

        $spec = \ComposeCommandBuilder::fromProject($this->root, 'named', 'up');

        $this->assertCount(1, $spec['composeFiles']);
    }
}
