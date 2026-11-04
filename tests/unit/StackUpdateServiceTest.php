<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/StackUpdateService.php';

class StackUpdateServiceTest extends TestCase
{
    public function testFreshResultsAndPersistenceSharePendingHistoryForSingleAndFullChecks(): void
    {
        $save = new \ReflectionMethod(\StackUpdateService::class, 'saveResults');
        $service = new \StackUpdateService();
        $old = \ContainerInfo::fromUpdateResponse([
            'name' => 'app-1', 'service' => 'app', 'image' => 'app:latest',
            'hasUpdate' => true, 'updateStatus' => 'update-available',
            'localSha' => 'old', 'remoteSha' => 'new', 'localImageSha' => 'new',
            'updateKind' => 'build-base', 'updateReadiness' => 'ready-to-apply',
        ])->toUpdateArray();
        try {
            foreach ([false, true] as $replace) {
                file_put_contents(COMPOSE_UPDATE_STATUS_FILE, json_encode([
                    'stack' => ['containers' => [$old]],
                    'removed' => ['containers' => []],
                ]));
                $fresh = \ContainerInfo::fromUpdateResponse([
                    'name' => 'app-1', 'service' => 'app', 'image' => 'app:latest',
                    'localSha' => 'old', 'icon' => '/fresh.png',
                ])->toUpdateArray();
                $results = $save->invoke($service, [
                    'stack' => ['projectName' => 'stack', 'hasUpdate' => false, 'containers' => [$fresh]],
                ], $replace);
                $saved = \StackUpdateService::readSavedResults();
                $this->assertSame($results['stack'], $saved['stack']);
                $this->assertSame(!$replace, isset($saved['removed']));
                $this->assertTrue($results['stack']['hasUpdate']);
                $container = $results['stack']['containers'][0];
                $this->assertSame('unknown', $container['updateReadiness']);
                $this->assertSame('', $container['localImageSha']);
                $this->assertSame('new', $container['remoteSha']);
                $this->assertSame('/fresh.png', $container['icon']);
            }
            foreach ([
                ['image' => 'app:other'],
                ['image' => 'app:latest@sha256:pinned'],
                ['name' => 'app-2'],
                ['localSha' => 'recreated'],
            ] as $changes) {
                file_put_contents(COMPOSE_UPDATE_STATUS_FILE, json_encode(['stack' => ['containers' => [$old]]]));
                $fresh = \ContainerInfo::fromUpdateResponse($changes + [
                    'name' => 'app-1', 'image' => 'app:latest', 'localSha' => 'old',
                ])->toUpdateArray();
                $result = $save->invoke($service, ['stack' => ['containers' => [$fresh]]], false);
                $this->assertFalse($result['stack']['hasUpdate'], 'Changed container identity must invalidate history.');
            }
        } finally {
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
        }
    }
}
