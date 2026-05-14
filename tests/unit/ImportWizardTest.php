<?php

/**
 * Unit Tests for Import Wizard backend functions (REAL SOURCE)
 *
 * Tests new functions added for the 5-stage import wizard:
 * - guessHealthcheck()
 * - nanosToComposeDuration()
 * - parsePortMapping()
 * - detectPortConflicts()
 * - dockerServicesToComposeYml() with wizardConfig
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';

class ImportWizardTest extends TestCase
{
    // =========================================================
    // nanosToComposeDuration()
    // =========================================================

    public function testNanosToComposeDurationZero(): void
    {
        $this->assertEquals('0s', nanosToComposeDuration(0));
    }

    public function testNanosToComposeDurationSeconds(): void
    {
        $this->assertEquals('30s', nanosToComposeDuration(30_000_000_000));
    }

    public function testNanosToComposeDurationExactMinutes(): void
    {
        $this->assertEquals('2m', nanosToComposeDuration(120_000_000_000));
    }

    public function testNanosToComposeDurationMinutesAndSeconds(): void
    {
        $this->assertEquals('1m30s', nanosToComposeDuration(90_000_000_000));
    }

    public function testNanosToComposeDurationOneSecond(): void
    {
        $this->assertEquals('1s', nanosToComposeDuration(1_000_000_000));
    }

    // =========================================================
    // parsePortMapping()
    // =========================================================

    public function testParsePortMappingHostAndContainer(): void
    {
        $result = parsePortMapping('8080:80/tcp');
        $this->assertEquals([
            'hostIp' => '',
            'hostPort' => '8080',
            'containerPort' => '80',
            'protocol' => 'tcp',
        ], $result);
    }

    public function testParsePortMappingWithHostIp(): void
    {
        $result = parsePortMapping('192.168.1.1:8080:80/tcp');
        $this->assertEquals([
            'hostIp' => '192.168.1.1',
            'hostPort' => '8080',
            'containerPort' => '80',
            'protocol' => 'tcp',
        ], $result);
    }

    public function testParsePortMappingExposeOnly(): void
    {
        $result = parsePortMapping('80/tcp');
        $this->assertEquals([
            'hostIp' => '',
            'hostPort' => '',
            'containerPort' => '80',
            'protocol' => 'tcp',
        ], $result);
    }

    public function testParsePortMappingUdpProtocol(): void
    {
        $result = parsePortMapping('53:53/udp');
        $this->assertEquals([
            'hostIp' => '',
            'hostPort' => '53',
            'containerPort' => '53',
            'protocol' => 'udp',
        ], $result);
    }

    public function testParsePortMappingNoProtocol(): void
    {
        $result = parsePortMapping('8080:80');
        $this->assertEquals([
            'hostIp' => '',
            'hostPort' => '8080',
            'containerPort' => '80',
            'protocol' => 'tcp',
        ], $result);
    }

    public function testParsePortMappingIpv6Loopback(): void
    {
        $result = parsePortMapping('[::1]:8080:80/tcp');
        $this->assertEquals([
            'hostIp' => '::1',
            'hostPort' => '8080',
            'containerPort' => '80',
            'protocol' => 'tcp',
        ], $result);
    }

    public function testParsePortMappingIpv6Unspecified(): void
    {
        $result = parsePortMapping('[::]:8080:80/tcp');
        $this->assertEquals([
            'hostIp' => '::',
            'hostPort' => '8080',
            'containerPort' => '80',
            'protocol' => 'tcp',
        ], $result);
    }

    // =========================================================
    // guessHealthcheck()
    // =========================================================

    public function testGuessHealthcheckFromExistingInspect(): void
    {
        $existing = [
            'Test' => ['CMD-SHELL', 'curl -f http://localhost/ || exit 1'],
            'Interval' => 30_000_000_000,
            'Timeout' => 10_000_000_000,
            'Retries' => 3,
            'StartPeriod' => 5_000_000_000,
        ];
        $result = guessHealthcheck('nginx:latest', [], $existing);
        $this->assertNotNull($result);
        $this->assertEquals(['CMD-SHELL', 'curl -f http://localhost/ || exit 1'], $result['test']);
        $this->assertEquals('30s', $result['interval']);
        $this->assertEquals('10s', $result['timeout']);
        $this->assertEquals(3, $result['retries']);
        $this->assertEquals('5s', $result['start_period']);
    }

    public function testGuessHealthcheckFromKnownPort80(): void
    {
        $result = guessHealthcheck('someimage:latest', ['80/tcp' => new \stdClass()]);
        $this->assertNotNull($result);
        $this->assertEquals(['CMD-SHELL', 'curl -f http://localhost:80/ || exit 1'], $result['test']);
        $this->assertEquals('30s', $result['interval']);
        $this->assertEquals('10s', $result['timeout']);
        $this->assertEquals(3, $result['retries']);
    }

    public function testGuessHealthcheckFromKnownPort3306(): void
    {
        $result = guessHealthcheck('custom-db:v1', ['3306/tcp' => new \stdClass()]);
        $this->assertNotNull($result);
        $this->assertStringContainsString('mysqladmin', $result['test'][1]);
    }

    public function testGuessHealthcheckFromKnownPort6379(): void
    {
        $result = guessHealthcheck('my-redis:7', ['6379/tcp' => new \stdClass()]);
        $this->assertNotNull($result);
        $this->assertStringContainsString('redis-cli', $result['test'][1]);
    }

    public function testGuessHealthcheckFromImageNameMysql(): void
    {
        $result = guessHealthcheck('mysql:8.0', []);
        $this->assertNotNull($result);
        $this->assertStringContainsString('mysqladmin', $result['test'][1]);
    }

    public function testGuessHealthcheckFromImageNameLinuxserverMariadb(): void
    {
        $result = guessHealthcheck('linuxserver/mariadb:10', []);
        $this->assertNotNull($result);
        $this->assertStringContainsString('mysqladmin', $result['test'][1]);
    }

    public function testGuessHealthcheckFromImageNamePostgres(): void
    {
        $result = guessHealthcheck('postgres:15', []);
        $this->assertNotNull($result);
        $this->assertStringContainsString('pg_isready', $result['test'][1]);
    }

    public function testGuessHealthcheckFromImageNameRedis(): void
    {
        $result = guessHealthcheck('redis:7-alpine', []);
        $this->assertNotNull($result);
        $this->assertStringContainsString('redis-cli', $result['test'][1]);
    }

    public function testGuessHealthcheckFromImageNameNginx(): void
    {
        $result = guessHealthcheck('nginx:latest', []);
        $this->assertNotNull($result);
        $this->assertStringContainsString('curl', $result['test'][1]);
    }

    public function testGuessHealthcheckReturnsNullForUnknown(): void
    {
        $result = guessHealthcheck('mycustomapp:v3', []);
        $this->assertNull($result);
    }

    public function testGuessHealthcheckPortTakesPriorityOverImageName(): void
    {
        // Image says "nginx" (curl localhost/) but exposed port is 5432 (pg_isready)
        $result = guessHealthcheck('nginx:latest', ['5432/tcp' => new \stdClass()]);
        $this->assertNotNull($result);
        $this->assertStringContainsString('pg_isready', $result['test'][1]);
    }

    public function testGuessHealthcheckExistingTakesPriorityOverAll(): void
    {
        $existing = [
            'Test' => ['CMD', '/usr/bin/custom-check'],
            'Interval' => 60_000_000_000,
            'Timeout' => 5_000_000_000,
            'Retries' => 5,
        ];
        // Has known port AND image match, but existing should win
        $result = guessHealthcheck('mysql:8', ['3306/tcp' => new \stdClass()], $existing);
        $this->assertNotNull($result);
        $this->assertEquals(['CMD', '/usr/bin/custom-check'], $result['test']);
        $this->assertEquals('1m', $result['interval']);
    }

    // =========================================================
    // detectPortConflicts()
    // =========================================================

    public function testDetectPortConflictsNoConflicts(): void
    {
        $services = [
            'web' => ['ports' => ['8080:80/tcp']],
            'api' => ['ports' => ['3000:3000/tcp']],
        ];
        $conflicts = detectPortConflicts($services);
        $this->assertEmpty($conflicts);
    }

    public function testDetectPortConflictsDuplicateHostPort(): void
    {
        $services = [
            'nginx' => ['ports' => ['8080:80/tcp']],
            'app' => ['ports' => ['8080:3000/tcp']],
        ];
        $conflicts = detectPortConflicts($services);
        $this->assertCount(1, $conflicts);
        $this->assertEquals('8080', $conflicts[0]['hostPort']);
        $this->assertEquals('tcp', $conflicts[0]['protocol']);
        $this->assertContains('nginx', $conflicts[0]['services']);
        $this->assertContains('app', $conflicts[0]['services']);
    }

    public function testDetectPortConflictsDifferentHostIpsNoConflict(): void
    {
        $services = [
            'svc1' => ['ports' => ['192.168.1.1:8080:80/tcp']],
            'svc2' => ['ports' => ['192.168.1.2:8080:80/tcp']],
        ];
        $conflicts = detectPortConflicts($services);
        $this->assertEmpty($conflicts);
    }

    public function testDetectPortConflictsExposeOnlyIgnored(): void
    {
        $services = [
            'svc1' => ['ports' => ['80/tcp']],
            'svc2' => ['ports' => ['80/tcp']],
        ];
        $conflicts = detectPortConflicts($services);
        $this->assertEmpty($conflicts);
    }

    public function testDetectPortConflictsNoPorts(): void
    {
        $services = [
            'svc1' => ['image' => 'nginx'],
            'svc2' => ['image' => 'redis'],
        ];
        $conflicts = detectPortConflicts($services);
        $this->assertEmpty($conflicts);
    }

    public function testDetectPortConflictsDifferentProtocolNoConflict(): void
    {
        $services = [
            'svc1' => ['ports' => ['53:53/tcp']],
            'svc2' => ['ports' => ['53:53/udp']],
        ];
        $conflicts = detectPortConflicts($services);
        $this->assertEmpty($conflicts);
    }

    public function testDetectPortConflictsPortRange(): void
    {
        $services = [
            'svc1' => ['ports' => ['8000-8005:8000-8005/tcp']],
            'svc2' => ['ports' => ['8000-8005:9000-9005/tcp']],
        ];
        $conflicts = detectPortConflicts($services);
        $this->assertCount(1, $conflicts);
        $this->assertEquals('8000-8005', $conflicts[0]['hostPort']);
        $this->assertEquals('tcp', $conflicts[0]['protocol']);
        $this->assertContains('svc1', $conflicts[0]['services']);
        $this->assertContains('svc2', $conflicts[0]['services']);
    }

    // =========================================================
    // dockerServicesToComposeYml() with wizardConfig
    // =========================================================

    public function testComposeYmlWithContainerNames(): void
    {
        $services = [
            'web' => ['image' => 'nginx:latest'],
            'db' => ['image' => 'mysql:8'],
        ];
        $config = [
            'containerNames' => ['web' => 'my-web', 'db' => 'my-database'],
            'networkConfig' => ['stackNetwork' => ['enabled' => false], 'externalNetworks' => [], 'perService' => []],
            'healthchecks' => [],
            'dependencies' => [],
        ];
        $yaml = dockerServicesToComposeYml($services, $config);
        $this->assertStringContainsString('container_name: my-web', $yaml);
        $this->assertStringContainsString('container_name: my-database', $yaml);
    }

    public function testComposeYmlWithStackNetwork(): void
    {
        $services = [
            'web' => ['image' => 'nginx:latest'],
        ];
        $config = [
            'containerNames' => [],
            'networkConfig' => [
                'stackNetwork' => ['enabled' => true, 'name' => 'mystack_net'],
                'externalNetworks' => [],
                'perService' => [
                    'web' => ['networkMode' => 'default', 'attachStackNet' => true, 'externalNets' => []],
                ],
            ],
            'healthchecks' => [],
            'dependencies' => [],
        ];
        $yaml = dockerServicesToComposeYml($services, $config);
        $this->assertStringContainsString('networks:', $yaml);
        $this->assertStringContainsString('mystack_net', $yaml);
        $this->assertStringContainsString('driver: bridge', $yaml);
    }

    public function testComposeYmlWithHostNetworkMode(): void
    {
        $services = [
            'web' => ['image' => 'nginx:latest', 'networks' => ['old_net']],
        ];
        $config = [
            'containerNames' => [],
            'networkConfig' => [
                'stackNetwork' => ['enabled' => true, 'name' => 'mystack_net'],
                'externalNetworks' => [],
                'perService' => [
                    'web' => ['networkMode' => 'host', 'attachStackNet' => false, 'externalNets' => []],
                ],
            ],
            'healthchecks' => [],
            'dependencies' => [],
        ];
        $yaml = dockerServicesToComposeYml($services, $config);
        $this->assertStringContainsString('network_mode: host', $yaml);
        // Should NOT contain networks for this service
        $this->assertStringNotContainsString('old_net', $yaml);
    }

    public function testComposeYmlWithHealthcheck(): void
    {
        $services = [
            'web' => ['image' => 'nginx:latest'],
        ];
        $config = [
            'containerNames' => [],
            'networkConfig' => ['stackNetwork' => ['enabled' => false], 'externalNetworks' => [], 'perService' => []],
            'healthchecks' => [
                'web' => [
                    'test' => ['CMD-SHELL', 'curl -f http://localhost/ || exit 1'],
                    'interval' => '30s',
                    'timeout' => '10s',
                    'retries' => 3,
                    'start_period' => '10s',
                ],
            ],
            'dependencies' => [],
        ];
        $yaml = dockerServicesToComposeYml($services, $config);
        $this->assertStringContainsString('healthcheck:', $yaml);
        $this->assertStringContainsString('test:', $yaml);
        $this->assertStringContainsString('CMD-SHELL', $yaml);
        $this->assertStringContainsString('interval: 30s', $yaml);
        $this->assertStringContainsString('retries: 3', $yaml);
        // Verify retries is NOT quoted (should be bare integer, not "3")
        $this->assertStringNotContainsString('retries: "3"', $yaml);
    }

    public function testComposeYmlWithRemovedHealthcheck(): void
    {
        $services = [
            'web' => ['image' => 'nginx:latest', 'healthcheck' => ['test' => ['CMD', 'true']]],
        ];
        $config = [
            'containerNames' => [],
            'networkConfig' => ['stackNetwork' => ['enabled' => false], 'externalNetworks' => [], 'perService' => []],
            'healthchecks' => ['web' => null],
            'dependencies' => [],
        ];
        $yaml = dockerServicesToComposeYml($services, $config);
        $this->assertStringNotContainsString('healthcheck:', $yaml);
    }

    public function testComposeYmlWithDependsOn(): void
    {
        $services = [
            'web' => ['image' => 'nginx:latest'],
            'db' => ['image' => 'mysql:8'],
        ];
        $config = [
            'containerNames' => [],
            'networkConfig' => ['stackNetwork' => ['enabled' => false], 'externalNetworks' => [], 'perService' => []],
            'healthchecks' => [],
            'dependencies' => [
                'web' => [
                    ['service' => 'db', 'condition' => 'service_healthy'],
                ],
            ],
        ];
        $yaml = dockerServicesToComposeYml($services, $config);
        $this->assertStringContainsString('depends_on:', $yaml);
        $this->assertStringContainsString('db:', $yaml);
        $this->assertStringContainsString('condition: service_healthy', $yaml);
    }

    public function testComposeYmlWithExternalNetworks(): void
    {
        $services = [
            'web' => ['image' => 'nginx:latest'],
        ];
        $config = [
            'containerNames' => [],
            'networkConfig' => [
                'stackNetwork' => ['enabled' => false],
                'externalNetworks' => ['proxy_net'],
                'perService' => [
                    'web' => ['networkMode' => 'default', 'attachStackNet' => false, 'externalNets' => ['proxy_net']],
                ],
            ],
            'healthchecks' => [],
            'dependencies' => [],
        ];
        $yaml = dockerServicesToComposeYml($services, $config);
        $this->assertStringContainsString('proxy_net', $yaml);
        $this->assertStringContainsString('external: true', $yaml);
    }

    public function testComposeYmlLegacyPathNoWizardConfig(): void
    {
        $services = [
            'web' => [
                'image' => 'nginx:latest',
                'ports' => ['80:80/tcp'],
                '__guessed_healthcheck' => ['test' => ['CMD', 'true']],
                '__exposed_ports' => ['80/tcp' => new \stdClass()],
                'icon' => '/path/to/icon.png',
            ],
        ];
        $yaml = dockerServicesToComposeYml($services);
        $this->assertStringContainsString('nginx:latest', $yaml);
        // Internal keys should not appear
        $this->assertStringNotContainsString('__guessed_healthcheck', $yaml);
        $this->assertStringNotContainsString('__exposed_ports', $yaml);
        $this->assertStringNotContainsString('icon:', $yaml);
    }

    public function testComposeYmlFullWizardConfig(): void
    {
        $services = [
            'app' => ['image' => 'myapp:latest', 'ports' => ['8080:80/tcp']],
            'db' => ['image' => 'postgres:15', 'ports' => ['5432:5432/tcp']],
        ];
        $config = [
            'containerNames' => ['app' => 'my-app', 'db' => 'my-postgres'],
            'networkConfig' => [
                'stackNetwork' => ['enabled' => true, 'name' => 'app_net'],
                'externalNetworks' => [],
                'perService' => [
                    'app' => ['networkMode' => 'default', 'attachStackNet' => true, 'externalNets' => []],
                    'db' => ['networkMode' => 'default', 'attachStackNet' => true, 'externalNets' => []],
                ],
            ],
            'healthchecks' => [
                'app' => ['test' => ['CMD-SHELL', 'curl -f http://localhost:80/'], 'interval' => '15s', 'timeout' => '5s', 'retries' => 3, 'start_period' => '10s'],
                'db' => ['test' => ['CMD-SHELL', 'pg_isready -U postgres'], 'interval' => '30s', 'timeout' => '10s', 'retries' => 5, 'start_period' => '20s'],
            ],
            'dependencies' => [
                'app' => [['service' => 'db', 'condition' => 'service_healthy']],
            ],
        ];
        $yaml = dockerServicesToComposeYml($services, $config);

        // Container names
        $this->assertStringContainsString('container_name: my-app', $yaml);
        $this->assertStringContainsString('container_name: my-postgres', $yaml);

        // Network
        $this->assertStringContainsString('app_net', $yaml);
        $this->assertStringContainsString('driver: bridge', $yaml);

        // App healthcheck
        $this->assertStringContainsString('curl -f http://localhost:80/', $yaml);

        // DB healthcheck
        $this->assertStringContainsString('pg_isready -U postgres', $yaml);

        // Dependency
        $this->assertStringContainsString('depends_on:', $yaml);
        $this->assertStringContainsString('condition: service_healthy', $yaml);
    }
}
