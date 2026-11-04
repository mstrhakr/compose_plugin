<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';

class ContainerInfoTest extends TestCase
{
    // ===========================================
    // fromDockerInspect Tests
    // ===========================================

    public function testFromDockerInspectPascalCaseKeys(): void
    {
        $raw = [
            'Name' => 'my-container',
            'ID' => 'abc123def4567890',
            'Service' => 'web',
            'Image' => 'nginx:latest',
            'State' => 'running',
            'Icon' => 'https://example.com/icon.png',
            'Shell' => '/bin/sh',
            'WebUI' => 'http://localhost:8080',
            'Ports' => ['8080->80/tcp'],
            'Networks' => [['name' => 'bridge']],
            'Volumes' => [['source' => '/data']],
            'Created' => '2024-01-01T00:00:00Z',
            'StartedAt' => '2024-01-01T00:01:00Z',
        ];

        $info = \ContainerInfo::fromDockerInspect($raw);

        $this->assertSame('my-container', $info->name);
    $this->assertSame('abc123def4567890', $info->id);
        $this->assertSame('web', $info->service);
        $this->assertSame('nginx:latest', $info->image);
        $this->assertSame('running', $info->state);
        $this->assertTrue($info->isRunning);
        $this->assertSame('https://example.com/icon.png', $info->icon);
        $this->assertSame('/bin/sh', $info->shell);
        $this->assertSame('http://localhost:8080', $info->webUI);
        $this->assertSame(['8080->80/tcp'], $info->ports);
        $this->assertSame([['name' => 'bridge']], $info->networks);
        $this->assertSame([['source' => '/data']], $info->volumes);
        $this->assertSame('2024-01-01T00:00:00Z', $info->created);
        $this->assertSame('2024-01-01T00:01:00Z', $info->startedAt);
    }

    public function testFromDockerInspectCamelCaseKeys(): void
    {
        $raw = [
            'name' => 'my-container',
            'id' => 'ffeeddccbbaa9988',
            'service' => 'api',
            'image' => 'node:18',
            'state' => 'exited',
        ];

        $info = \ContainerInfo::fromDockerInspect($raw);

        $this->assertSame('my-container', $info->name);
        $this->assertSame('ffeeddccbbaa9988', $info->id);
        $this->assertSame('api', $info->service);
        $this->assertSame('node:18', $info->image);
        $this->assertSame('exited', $info->state);
        $this->assertFalse($info->isRunning);
    }

    public function testFromDockerInspectUpdateStatusNormalization(): void
    {
        // PascalCase update fields
        $raw = [
            'Name' => 'test',
            'UpdateStatus' => 'update-available',
            'LocalSha' => 'abc123',
            'RemoteSha' => 'def456',
        ];

        $info = \ContainerInfo::fromDockerInspect($raw);

        $this->assertSame('update-available', $info->updateStatus);
        $this->assertTrue($info->hasUpdate);
        $this->assertSame('abc123', $info->localSha);
        $this->assertSame('def456', $info->remoteSha);
    }

    public function testFromDockerInspectDerivesPinnedFromSha256(): void
    {
        $raw = [
            'Name' => 'pinned-container',
            'Image' => 'nginx@sha256:abcdef1234567890abcdef1234567890',
        ];

        $info = \ContainerInfo::fromDockerInspect($raw);

        $this->assertTrue($info->isPinned);
        $this->assertSame('abcdef1234567890abcdef1234567890', $info->pinnedDigest);
    }

    public function testFromDockerInspectNotPinnedWithoutSha256(): void
    {
        $raw = [
            'Name' => 'normal-container',
            'Image' => 'nginx:latest',
        ];

        $info = \ContainerInfo::fromDockerInspect($raw);

        $this->assertFalse($info->isPinned);
        $this->assertNull($info->pinnedDigest);
    }

    public function testFromDockerInspectDefaultShell(): void
    {
        $raw = ['Name' => 'test'];
        $info = \ContainerInfo::fromDockerInspect($raw);
        $this->assertSame('/bin/bash', $info->shell);
    }

    public function testFromDockerInspectDefaultUpdateStatus(): void
    {
        $raw = ['Name' => 'test'];
        $info = \ContainerInfo::fromDockerInspect($raw);
        $this->assertSame('unknown', $info->updateStatus);
        $this->assertFalse($info->hasUpdate);
    }

    public function testFromDockerInspectHasUpdateExplicitlyFalse(): void
    {
        $raw = [
            'Name' => 'test',
            'hasUpdate' => false,
            'updateStatus' => 'update-available',
        ];

        $info = \ContainerInfo::fromDockerInspect($raw);

        // Explicit hasUpdate should win over derived
        $this->assertFalse($info->hasUpdate);
    }

    // ===========================================
    // fromUpdateResponse Tests
    // ===========================================

    public function testFromUpdateResponseBasicFields(): void
    {
        $raw = [
            'container' => 'web-container',
            'id' => '0011223344556677',
            'image' => 'nginx:alpine',
            'hasUpdate' => true,
            'status' => 'update-available',
            'localSha' => 'aaa111',
            'remoteSha' => 'bbb222',
        ];

        $info = \ContainerInfo::fromUpdateResponse($raw);

        $this->assertSame('web-container', $info->name);
    $this->assertSame('0011223344556677', $info->id);
        $this->assertSame('nginx:alpine', $info->image);
        $this->assertTrue($info->hasUpdate);
        $this->assertSame('update-available', $info->updateStatus);
        $this->assertSame('aaa111', $info->localSha);
        $this->assertSame('bbb222', $info->remoteSha);
    }

    public function testFromUpdateResponseFallsBackToStatusField(): void
    {
        $raw = [
            'container' => 'test',
            'status' => 'up-to-date',
        ];

        $info = \ContainerInfo::fromUpdateResponse($raw);

        $this->assertSame('up-to-date', $info->updateStatus);
        $this->assertFalse($info->hasUpdate);
    }

    // ===========================================
    // mergeUpdateStatus Tests
    // ===========================================

    public function testMergeUpdateStatus(): void
    {
        $base = \ContainerInfo::fromDockerInspect([
            'Name' => 'test',
            'Image' => 'nginx:latest',
        ]);

        $update = \ContainerInfo::fromUpdateResponse([
            'container' => 'test',
            'hasUpdate' => true,
            'status' => 'update-available',
            'localSha' => 'aaa',
            'remoteSha' => 'bbb',
        ]);

        $base->mergeUpdateStatus($update);

        $this->assertTrue($base->hasUpdate);
        $this->assertSame('update-available', $base->updateStatus);
        $this->assertSame('aaa', $base->localSha);
        $this->assertSame('bbb', $base->remoteSha);
    }

    public function testMergeUpdateStatusDoesNotOverwriteWithEmpty(): void
    {
        $base = \ContainerInfo::fromDockerInspect([
            'Name' => 'test',
            'updateStatus' => 'update-available',
            'localSha' => 'existing-sha',
        ]);

        $update = \ContainerInfo::fromUpdateResponse([
            'container' => 'test',
            'status' => 'unknown',
            'localSha' => '',
        ]);

        $base->mergeUpdateStatus($update);

        // Should keep existing values since update has unknown/empty
        $this->assertSame('update-available', $base->updateStatus);
        $this->assertSame('existing-sha', $base->localSha);
    }

    public function testMergeUpdateStatusClearsAnOutdatedBadgeAndDigests(): void
    {
        $base = \ContainerInfo::fromDockerInspect([
            'Name' => 'test',
            'Image' => 'postgres:18',
            'updateStatus' => 'update-available',
            'localSha' => 'old-local',
            'remoteSha' => 'old-remote',
        ]);
        $base->mergeUpdateStatus(\ContainerInfo::fromUpdateResponse([
            'container' => 'test',
            'image' => 'docker.io/library/postgres:18',
            'hasUpdate' => false,
            'status' => 'up-to-date',
        ]));

        $this->assertFalse($base->hasUpdate);
        $this->assertSame('up-to-date', $base->updateStatus);
        $this->assertSame('', $base->localSha);
        $this->assertSame('', $base->remoteSha);
    }

    public function testMergeUpdateStatusDoesNotReuseAnotherTagsResult(): void
    {
        $base = \ContainerInfo::fromDockerInspect(['Name' => 'test', 'Image' => 'postgres:18']);
        $base->mergeUpdateStatus(\ContainerInfo::fromUpdateResponse([
            'container' => 'test',
            'image' => 'postgres:16',
            'hasUpdate' => true,
            'status' => 'update-available',
            'localSha' => 'old-local',
            'remoteSha' => 'old-remote',
        ]));

        $this->assertFalse($base->hasUpdate);
        $this->assertSame('unknown', $base->updateStatus);
        $this->assertSame('', $base->localSha);
    }

    public function testMergeUpdateStatusDoesNotReuseAnotherReplicasResult(): void
    {
        $base = \ContainerInfo::fromDockerInspect(['Name' => 'stack-web-1', 'Image' => 'nginx:latest']);
        $base->mergeUpdateStatus(\ContainerInfo::fromUpdateResponse([
            'container' => 'stack-web-2',
            'image' => 'nginx:latest',
            'status' => 'up-to-date',
        ]));

        $this->assertSame('unknown', $base->updateStatus);
    }

    // ===========================================
    // toArray Tests
    // ===========================================

    public function testToArrayProducesConsistentCamelCase(): void
    {
        $raw = [
            'Name' => 'test-container',
            'Service' => 'web',
            'Image' => 'nginx:latest',
            'State' => 'running',
            'UpdateStatus' => 'up-to-date',
            'LocalSha' => 'sha1',
            'RemoteSha' => 'sha2',
        ];

        $info = \ContainerInfo::fromDockerInspect($raw);
        $arr = $info->toArray();

        // All keys should be camelCase
        $this->assertArrayHasKey('name', $arr);
        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayHasKey('service', $arr);
        $this->assertArrayHasKey('image', $arr);
        $this->assertArrayHasKey('state', $arr);
        $this->assertArrayHasKey('isRunning', $arr);
        $this->assertArrayHasKey('hasUpdate', $arr);
        $this->assertArrayHasKey('updateStatus', $arr);
        $this->assertArrayHasKey('localSha', $arr);
        $this->assertArrayHasKey('remoteSha', $arr);
        $this->assertArrayHasKey('isPinned', $arr);
        $this->assertArrayHasKey('pinnedDigest', $arr);
        $this->assertArrayHasKey('icon', $arr);
        $this->assertArrayHasKey('shell', $arr);
        $this->assertArrayHasKey('webUI', $arr);
        $this->assertArrayHasKey('ports', $arr);
        $this->assertArrayHasKey('networks', $arr);
        $this->assertArrayHasKey('volumes', $arr);
        $this->assertArrayHasKey('created', $arr);
        $this->assertArrayHasKey('startedAt', $arr);

        // No PascalCase keys
        $this->assertArrayNotHasKey('Name', $arr);
        $this->assertArrayNotHasKey('Service', $arr);
        $this->assertArrayNotHasKey('UpdateStatus', $arr);
    }

    public function testToArrayValues(): void
    {
        $info = \ContainerInfo::fromDockerInspect([
            'Name' => 'web',
            'ID' => '1234567890abcdef',
            'State' => 'running',
            'Image' => 'nginx:latest',
        ]);

        $arr = $info->toArray();

        $this->assertSame('web', $arr['name']);
    $this->assertSame('1234567890abcdef', $arr['id']);
        $this->assertSame('running', $arr['state']);
        $this->assertTrue($arr['isRunning']);
        $this->assertSame('nginx:latest', $arr['image']);
    }

    // ===========================================
    // toUpdateArray Tests
    // ===========================================

    public function testToUpdateArrayContainsOnlyUpdateFields(): void
    {
        $info = \ContainerInfo::fromDockerInspect([
            'Name' => 'test',
            'Image' => 'nginx@sha256:abcdef1234567890abcdef1234567890',
            'State' => 'running',
            'Icon' => 'icon.png',
        ]);

        $arr = $info->toUpdateArray();

        $expected = ['name', 'oneOff', 'service', 'image', 'icon', 'hasUpdate', 'updateStatus', 'localSha', 'remoteSha',
            'localImageSha', 'updateKind', 'updateReadiness', 'isPinned', 'pinnedDigest'];
        $this->assertSame($expected, array_keys($arr));
        $this->assertSame('abcdef1234567890abcdef1234567890', $arr['pinnedDigest']);
        $this->assertSame('icon.png', $arr['icon']);

        // Should NOT contain non-update fields
        $this->assertArrayNotHasKey('state', $arr);
        $this->assertArrayNotHasKey('ports', $arr);
    }

    public function testOneOffIdentitySurvivesNormalizationAndBlocksSharedOrSavedUpdates(): void
    {
        $pending = [
            'name' => 'job', 'service' => 'app', 'image' => 'app:latest', 'state' => 'exited',
            'hasUpdate' => true, 'updateStatus' => 'update-available', 'updateReadiness' => 'ready-to-apply',
            'localSha' => 'old', 'remoteSha' => 'new', 'localImageSha' => 'new',
        ];
        foreach ([
            ['Labels' => 'com.docker.compose.oneoff=True'],
            ['Labels' => ['com.docker.compose.oneoff' => 'True']],
            ['oneOff' => true],
        ] as $identity) {
            $job = \ContainerInfo::fromDockerInspect($pending + $identity);
            $this->assertTrue($job->oneOff);
            $this->assertSame('exited', $job->state);
            $this->assertFalse($job->hasUpdate);
            $this->assertSame('unknown', $job->updateStatus);
            $this->assertSame('', $job->remoteSha);
            $job->mergeUpdateStatus(\ContainerInfo::fromUpdateResponse($pending));
            $this->assertFalse($job->hasUpdate);
            $this->assertSame('unknown', $job->updateReadiness);
            $this->assertTrue(\ContainerInfo::fromDockerInspect($job->toMetadataArray())->oneOff);
            $this->assertTrue(\ContainerInfo::fromUpdateResponse($job->toUpdateArray())->oneOff);
            $this->assertTrue(\ContainerInfo::fromDockerPs($pending + $identity)->oneOff);
        }
        $regular = \ContainerInfo::fromDockerInspect($pending + ['Labels' => 'com.docker.compose.oneoff=False']);
        $this->assertFalse($regular->oneOff);
        $this->assertTrue($regular->hasUpdate);
    }

    public function testReadinessSurvivesFactoriesSerializationAndStatusMerging(): void
    {
        $raw = [
            'name' => 'app', 'service' => 'app', 'image' => 'app:latest',
            'status' => 'update-available', 'hasUpdate' => true,
            'localSha' => 'old', 'remoteSha' => 'base', 'localImageSha' => 'built',
            'updateKind' => 'build-base', 'updateReadiness' => 'ready-to-apply',
        ];
        $update = \ContainerInfo::fromUpdateResponse($raw);
        $roundtrip = \ContainerInfo::fromDockerInspect($update->toArray());
        $this->assertSame($update->toUpdateArray(), $roundtrip->toUpdateArray());
        $container = \ContainerInfo::fromDockerInspect(['name' => 'app', 'image' => 'app:latest']);
        $container->mergeUpdateStatus($update);
        $this->assertSame('ready-to-apply', $container->updateReadiness);
        $this->assertSame('build-base', $container->updateKind);
        $this->assertSame('built', $container->localImageSha);
        $other = \ContainerInfo::fromDockerInspect(['name' => 'other', 'image' => 'app:latest']);
        $other->mergeUpdateStatus($update);
        $this->assertSame('unknown', $other->updateReadiness);
        $container->mergeUpdateStatus(\ContainerInfo::fromUpdateResponse([
            'name' => 'app', 'image' => 'app:latest', 'status' => 'unknown',
        ]));
        $this->assertSame('unknown', $container->updateReadiness);
        $this->assertSame('', $container->localImageSha);
        $this->assertSame('update-available', $container->updateStatus, 'Legacy pending-update history remains preserved.');
    }

    // ===========================================
    // Edge Cases
    // ===========================================

    public function testFromDockerInspectEmptyArray(): void
    {
        $info = \ContainerInfo::fromDockerInspect([]);

        $this->assertSame('', $info->name);
        $this->assertSame('', $info->service);
        $this->assertSame('', $info->image);
        $this->assertSame('', $info->state);
        $this->assertFalse($info->isRunning);
        $this->assertFalse($info->hasUpdate);
        $this->assertSame('unknown', $info->updateStatus);
    }

    public function testStateLowercaseNormalization(): void
    {
        $info = \ContainerInfo::fromDockerInspect([
            'Name' => 'test',
            'State' => 'Running',
        ]);

        $this->assertSame('running', $info->state);
        $this->assertTrue($info->isRunning);
    }
}
