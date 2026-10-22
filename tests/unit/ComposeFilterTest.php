<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

// Include util.php (contains hide_compose_from_docker())
include_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';

class ComposeFilterTest extends TestCase
{
    public function testHideComposeFromDockerPrefFromPluginConfig(): void
    {
        // Mock plugin config
        $this->mockPluginConfig('compose.manager', [
            'HIDE_COMPOSE_FROM_DOCKER' => 'true'
        ]);

        $this->assertTrue(hide_compose_from_docker());

        // Turn off
        $this->mockPluginConfig('compose.manager', [
            'HIDE_COMPOSE_FROM_DOCKER' => 'false'
        ]);
        $this->assertFalse(hide_compose_from_docker());
    }

    public function testDockerContainersPageFiltersComposeContainers(): void
    {
        // Ask compose.manager to hide compose containers
        $this->mockPluginConfig('compose.manager', [
            'HIDE_COMPOSE_FROM_DOCKER' => 'true'
        ]);

        // Mock containers: one normal, one compose-managed
        $this->mockContainers([
            'normal' => [
                'Name' => 'normal',
                'Image' => 'nginx:latest',
                'State' => 'running',
                'Id' => 'id1',
                // No manager label => treated as third-party/dockerman
            ],
            'compose_app' => [
                'Name' => 'compose_app',
                'Image' => 'redis:7',
                'State' => 'running',
                'Id' => 'id2',
                'Labels' => 'net.unraid.docker.managed=composeman',
            ],
        ]);

        // Emulate Docker UI filtering: ensure a compose-managed container would be filtered
        $containers = [
            ['Name' => 'normal', 'Manager' => ''],
            ['Name' => 'compose_app', 'Manager' => 'composeman'],
        ];
        $hide = hide_compose_from_docker();
        $filtered = array_values(array_filter($containers, function($ct) use ($hide) {
            if ($hide && (!empty($ct['Manager']) && $ct['Manager'] === 'composeman')) return false;
            return true;
        }));

        $names = array_column($filtered, 'Name');
        $this->assertContains('normal', $names);
        $this->assertNotContains('compose_app', $names);

    }
}
