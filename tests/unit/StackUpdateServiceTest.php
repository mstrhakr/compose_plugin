<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/StackUpdateService.php';

class StackUpdateServiceTest extends TestCase
{
    public function testNamedBuildRegistryFailurePreservesPendingApplicationEvidence(): void
    {
        $checker = new class extends \ComposeUpdateCheck {
            public function checkMany(array $images): array
            {
                return [];
            }

            protected function fetchRemoteImageConfig(string $image): ?array
            {
                throw new \LogicException('Application registry failure must not fall back to a public base.');
            }
        };
        \DockerClient::setMockResponse('/containers/app/json', [
            'Image' => 'sha256:old', 'Config' => ['Image' => 'registry.example/app:latest'],
        ]);
        $checked = $checker->checkContainers([['Name' => 'app', 'Service' => 'app', 'Image' => 'old']], [
            'app' => ['build' => ['dockerfile_inline' => 'FROM alpine:3.20'], 'image' => 'registry.example/app:latest'],
        ])['app'];
        $save = new \ReflectionMethod(\StackUpdateService::class, 'saveResults');
        $previous = \ContainerInfo::fromUpdateResponse([
            'name' => 'app', 'image' => $checked['image'], 'localSha' => $checked['local'],
            'remoteSha' => 'sha256:new', 'hasUpdate' => true, 'updateStatus' => 'update-available',
        ])->toUpdateArray();
        $fresh = \ContainerInfo::fromUpdateResponse([
            'name' => 'app', 'image' => $checked['image'], 'localSha' => $checked['local'],
            'remoteSha' => $checked['remote'], 'hasUpdate' => $checked['hasUpdate'],
            'updateStatus' => $checked['status'],
        ])->toUpdateArray();
        try {
            foreach ([false, true] as $replace) {
                file_put_contents(COMPOSE_UPDATE_STATUS_FILE, json_encode(['stack' => ['containers' => [$previous]]]));
                $result = $save->invoke(new \StackUpdateService(), ['stack' => ['containers' => [$fresh]]], $replace);
                $this->assertTrue($result['stack']['hasUpdate']);
                $this->assertSame('sha256:new', $result['stack']['containers'][0]['remoteSha']);
                $this->assertSame('unknown', $result['stack']['containers'][0]['updateReadiness']);
            }
        } finally {
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
        }
    }

    public function testLegacyPinnedResultsDoNotEnableUpdateActionsBeforeTheNextCheck(): void
    {
        file_put_contents(COMPOSE_UPDATE_STATUS_FILE, json_encode(['stack' => [
            'hasUpdate' => true, 'containers' => [[
                'name' => 'app', 'image' => 'alpine:latest@sha256:pinned',
                'hasUpdate' => true, 'updateStatus' => 'update-available',
            ]],
        ]]));
        try {
            $saved = \StackUpdateService::readSavedResults();
            $this->assertFalse($saved['stack']['hasUpdate']);
            $this->assertFalse($saved['stack']['containers'][0]['hasUpdate']);
            $this->assertTrue($saved['stack']['containers'][0]['isPinned']);
        } finally {
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
        }
    }

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
