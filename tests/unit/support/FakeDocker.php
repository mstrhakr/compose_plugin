<?php

declare(strict_types=1);

namespace ComposeManager\Tests\Support;

/**
 * Sets up tests/unit/fixtures/fake-docker.sh at COMPOSE_DOCKER_BIN and writes
 * the answers it gives.
 */
final class FakeDocker
{
    public readonly string $dir;

    public function __construct()
    {
        $this->dir = dirname(COMPOSE_DOCKER_BIN);
        self::removeTree($this->dir);
        mkdir($this->dir . '/networks', 0755, true);
        mkdir($this->dir . '/volumes');
        mkdir($this->dir . '/containers');
        mkdir($this->dir . '/fail');
        copy(__DIR__ . '/../fixtures/fake-docker.sh', COMPOSE_DOCKER_BIN);
        chmod(COMPOSE_DOCKER_BIN, 0755);
    }

    public function cleanUp(): void
    {
        self::removeTree($this->dir);
    }

    /**
     * Make every `docker <command> ...` call fail with this stderr (exit 1),
     * after printing $stdout, if any. The command is the first argument
     * ("ps", "container").
     */
    public function fail(string $command, string $stderr, string $stdout = ''): void
    {
        file_put_contents($this->dir . '/fail/' . $command, $stderr . "\n");
        if ($stdout !== '') {
            file_put_contents($this->dir . '/fail/' . $command . '.stdout', $stdout);
        }
    }

    /**
     * What `docker compose config --format json` prints.
     *
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config, string $stderr = '', int $exitCode = 0): void
    {
        file_put_contents($this->dir . '/config.json', json_encode($config, JSON_UNESCAPED_SLASHES));
        file_put_contents($this->dir . '/config.stderr', $stderr);
        file_put_contents($this->dir . '/config.exit', (string) $exitCode);
    }

    /**
     * What `docker compose config --services` prints.
     *
     * @param string[] $services
     */
    public function setServices(array $services): void
    {
        file_put_contents($this->dir . '/services.txt', implode("\n", $services) . "\n");
    }

    /**
     * A network that exists, with its driver (empty for the default, bridge).
     */
    public function addNetwork(string $name, string $driver = ''): void
    {
        file_put_contents($this->dir . '/networks/' . $name, $driver === '' ? '' : $driver . "\n");
    }

    public function addVolume(string $name): void
    {
        touch($this->dir . '/volumes/' . $name);
    }

    /**
     * A container that exists (running or not), found by name.
     */
    public function addNamedContainer(string $name, ?string $project): void
    {
        $labels = $project === null ? [] : ['com.docker.compose.project' => $project];
        file_put_contents(
            $this->dir . '/containers/' . $name . '.json',
            json_encode([['Name' => '/' . $name, 'Config' => ['Labels' => $labels]]])
        );
    }

    /**
     * Running containers and the ports they publish.
     *
     * @param list<array{name: string, project: ?string, ports: array<string, list<array{HostIp: string, HostPort: string}>>}> $containers
     */
    public function setRunning(array $containers): void
    {
        $ids = [];
        $inspect = [];
        foreach ($containers as $i => $container) {
            $ids[] = 'id' . $i;
            $inspect[] = [
                'Name' => '/' . $container['name'],
                'Config' => ['Labels' => $container['project'] === null ? [] : ['com.docker.compose.project' => $container['project']]],
                'NetworkSettings' => ['Ports' => $container['ports']],
            ];
        }
        file_put_contents($this->dir . '/running.ids', implode("\n", $ids) . "\n");
        file_put_contents($this->dir . '/running.json', json_encode($inspect));
    }

    /**
     * @return string[] Every docker call made, as one line of arguments each
     */
    public function calls(): array
    {
        $log = @file_get_contents($this->dir . '/calls.log');
        return $log === false ? [] : array_values(array_filter(explode("\n", $log)));
    }

    public static function removeTree(string $path): void
    {
        // readlink(), not is_link(): the test stream wrapper hides symlinks from is_link().
        if (@readlink($path) !== false || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
