<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/UpdateCheck.php';

class UpdateCheckTest extends TestCase
{
    public function testContainersUsingTheSameTagAreComparedByTheirActualImageIds(): void
    {
        $checker = new class extends \ComposeUpdateCheck {
            public array $checkedImages = [];

            public function checkMany(array $images): array
            {
                $this->checkedImages = $images;
                return ['library/postgres:18' => [
                    'local' => 'sha256:current',
                    'remote' => 'sha256:current',
                    'status' => 'up-to-date',
                    'hasUpdate' => false,
                ]];
            }
        };
        \DockerClient::setMockResponse('/containers/old-db/json', ['Image' => 'sha256:old']);
        \DockerClient::setMockResponse('/containers/current-db/json', ['Image' => 'sha256:current']);

        $results = $checker->checkContainers([
            ['Name' => 'old-db', 'Image' => 'postgres:18'],
            ['Name' => 'current-db', 'Image' => 'docker.io/library/postgres:18'],
            ['Name' => 'missing-db', 'Image' => 'postgres:18'],
        ]);

        $this->assertSame(['postgres:18', 'docker.io/library/postgres:18', 'postgres:18'], $checker->checkedImages);
        $this->assertTrue($results['old-db']['hasUpdate']);
        $this->assertSame('sha256:old', $results['old-db']['local']);
        $this->assertSame('update-available', $results['old-db']['status']);
        $this->assertFalse($results['current-db']['hasUpdate']);
        $this->assertSame('up-to-date', $results['current-db']['status']);
        $this->assertSame('unknown', $results['missing-db']['status']);
        $this->assertContains('Container image digest unavailable', $GLOBALS['composeLoggerMessages']);
    }

    public function testManifestJsonDoesNotIncludeHttpHeaders(): void
    {
        $file = $this->createTempDir() . '/manifest.json';
        $manifest = ['config' => ['digest' => 'sha256:config']];
        file_put_contents($file, json_encode($manifest));
        $checker = new \ComposeUpdateCheck();
        $getJson = new \ReflectionMethod($checker, 'httpGetJson');

        $this->assertSame($manifest, $getJson->invoke($checker, 'file://' . $file, [], null));
    }

    public function testDifferentConfigDigestsHaveAnUpdate(): void
    {
        $checker = new \ComposeUpdateCheck();
        $summarize = new \ReflectionMethod($checker, 'summarize');
        $result = $summarize->invoke($checker, 'sha256:old', 'sha256:new');

        $this->assertTrue($result['result']['hasUpdate']);
        $this->assertSame('update-available', $result['result']['status']);
    }

    public function testSameConfigDigestDoesNotHaveAnUpdate(): void
    {
        $checker = new \ComposeUpdateCheck();
        $summarize = new \ReflectionMethod($checker, 'summarize');
        $result = $summarize->invoke($checker, 'sha256:same', 'sha256:same');

        $this->assertFalse($result['result']['hasUpdate']);
        $this->assertSame('up-to-date', $result['result']['status']);
    }

    public function testMissingDigestIsUnknownRatherThanUpToDate(): void
    {
        $checker = new \ComposeUpdateCheck();
        $summarize = new \ReflectionMethod($checker, 'summarize');
        foreach ([[null, 'sha256:remote'], ['sha256:local', null]] as [$local, $remote]) {
            $result = $summarize->invoke($checker, $local, $remote);
            $this->assertSame('unknown', $result['result']['status']);
            $this->assertSame('undef', $result['statusRaw']);
        }
    }
}
