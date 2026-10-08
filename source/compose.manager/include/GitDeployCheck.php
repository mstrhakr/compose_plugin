<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitPathGuard.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/DockerCommand.php';

/**
 * The checks a git stack must pass before a deploy touches any container.
 *
 * The compose file is never parsed here: `docker compose config` reads it with
 * the stack's real files, env file and profiles, and these checks work on the
 * JSON it prints. What they catch, each with the reason:
 *  - compose errors, and variables that are not set (a blank value can create
 *    state that outlives fixing it);
 *  - keys listed in the repository's .env.example but missing from the .env;
 *  - external networks and volumes that do not exist (networks only when the
 *    "Create Missing External Networks" setting is off);
 *  - bind-mount sources under /mnt that are not on a live mount (Docker would
 *    create them in RAM) or whose share (such as /mnt/user/appdata) does not
 *    exist, and sources outside /mnt whose top folder (such as /tmp) does not
 *    exist, which catches a typo of /mnt itself. Missing folders below those
 *    are left for Docker to create, as are paths inside the clone;
 *  - config and secret files, and local build folders, that do not exist;
 *  - container names and published ports already taken by another container,
 *    and a port published by two services of the stack.
 * When Docker cannot answer a question (the daemon is not responding, a
 * command times out), that is a problem too: "up" must not be the one to find
 * out, after it has changed some of the containers.
 */
final class GitDeployCheck
{
    /** @var string[] */
    private array $foldersDockerWillCreate = [];

    /** @var array<string, string> Drivers of external networks, asked once per check. */
    private array $externalNetworkDrivers = [];

    /**
     * @param string $projectName The stack's compose project name
     * @param string[] $composeArgs The stack's -f, --env-file and --profile arguments
     * @param string $cloneDir The stack's clone folder
     * @param string $composeDir The folder of the compose file inside the clone
     * @param string|null $envFile The .env the stack uses, if any
     * @param bool $missingNetworksWillBeCreated True when the "Create Missing External Networks"
     *                                           setting is on, so a missing external network is not a problem
     */
    public function __construct(
        private readonly string $projectName,
        private readonly array $composeArgs,
        private readonly string $cloneDir,
        private readonly string $composeDir,
        private readonly ?string $envFile,
        private readonly bool $missingNetworksWillBeCreated = false
    ) {
    }

    /**
     * Run every check.
     *
     * @return string[] One message per problem found; empty when the stack can be deployed
     */
    public function run(): array
    {
        $this->foldersDockerWillCreate = [];
        $config = DockerCommand::run(
            array_merge(['compose'], $this->composeArgs, ['-p', $this->projectName, 'config', '--format', 'json']),
            $this->composeDir
        );
        if (!$config->succeeded()) {
            return ['The compose file is not valid: ' . trim($config->stderr !== '' ? $config->stderr : $config->errorSummary())];
        }

        $problems = $this->unsetVariables($config->stderr);

        $resolved = json_decode($config->stdout, true);
        if (!is_array($resolved)) {
            $problems[] = 'docker compose config did not print valid JSON.';
            return $problems;
        }
        // An emptied compose file is almost always a mistake, and "up" would fail on it anyway.
        if (!is_array($resolved['services'] ?? null) || $resolved['services'] === []) {
            $problems[] = 'The compose file defines no services (with the profiles in use).';
            return $problems;
        }

        return array_merge(
            $problems,
            $this->missingEnvKeys(),
            $this->missingExternalResources($resolved),
            $this->missingPaths($resolved),
            $this->takenContainerNames($resolved),
            $this->takenPorts($resolved)
        );
    }

    /**
     * Keys in a .env-style file, in the order they appear.
     *
     * Understands blank lines, # comments, "export KEY=value", quoted values,
     * a bare "KEY" line, and Windows line endings.
     *
     * @return string[]
     */
    public static function readEnvKeys(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $keys = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*(?:=|$)/', $line, $match) === 1) {
                $keys[] = $match[1];
            }
        }
        return array_values(array_unique($keys));
    }

    /**
     * @return string[]
     */
    private function unsetVariables(string $stderr): array
    {
        preg_match_all('/The \\\\?"([A-Za-z_][A-Za-z0-9_]*)\\\\?" variable is not set/', $stderr, $matches);
        $names = array_values(array_unique($matches[1]));
        if ($names === []) {
            return [];
        }
        return ['These variables are used by the compose file but not set: ' . implode(', ', $names)
            . '. Add them to the .env file (an empty value must be written as NAME=).'];
    }

    /**
     * @return string[]
     */
    private function missingEnvKeys(): array
    {
        $example = $this->composeDir . '/.env.example';
        if (!is_file($example)) {
            return [];
        }
        $wanted = self::readEnvKeys((string) file_get_contents($example));
        $have = ($this->envFile !== null && is_file($this->envFile))
            ? self::readEnvKeys((string) file_get_contents($this->envFile))
            : [];
        $missing = array_values(array_diff($wanted, $have));
        if ($missing === []) {
            return [];
        }
        return ['The repository\'s .env.example lists keys missing from the stack\'s .env: '
            . implode(', ', $missing) . '.'];
    }

    /**
     * @param array<string, mixed> $resolved
     * @return string[]
     */
    private function missingExternalResources(array $resolved): array
    {
        $problems = [];
        foreach (['networks' => 'network', 'volumes' => 'volume'] as $section => $kind) {
            foreach ((array) ($resolved[$section] ?? []) as $key => $definition) {
                if (!is_array($definition) || ($definition['external'] ?? false) !== true) {
                    continue;
                }
                // compose.sh creates missing external networks right before "up" when the setting is on.
                if ($kind === 'network' && $this->missingNetworksWillBeCreated) {
                    continue;
                }
                $name = (string) ($definition['name'] ?? $key);
                if (!DockerCommand::run([$kind, 'inspect', '--', $name], null, 30)->succeeded()) {
                    $problems[] = "The external $kind '$name' does not exist. Create it before deploying.";
                }
            }
        }
        return $problems;
    }

    /**
     * @param array<string, mixed> $resolved
     * @return string[]
     */
    private function missingPaths(array $resolved): array
    {
        $problems = [];
        $cloneReal = realpath($this->cloneDir) ?: $this->cloneDir;

        foreach ((array) ($resolved['services'] ?? []) as $serviceName => $service) {
            if (!is_array($service)) {
                continue;
            }
            foreach ((array) ($service['volumes'] ?? []) as $volume) {
                if (!is_array($volume) || ($volume['type'] ?? '') !== 'bind') {
                    continue;
                }
                $source = (string) ($volume['source'] ?? '');
                $problem = $this->checkBindSource($source, $cloneReal);
                if ($problem !== null) {
                    $problems[] = "Service '$serviceName': $problem";
                }
            }

            $build = $service['build'] ?? null;
            if (is_array($build) && isset($build['context']) && str_starts_with((string) $build['context'], '/')) {
                if (!is_dir((string) $build['context'])) {
                    $problems[] = "Service '$serviceName': the build folder {$build['context']} does not exist.";
                }
            }
        }

        foreach (['configs' => 'config', 'secrets' => 'secret'] as $section => $kind) {
            foreach ((array) ($resolved[$section] ?? []) as $key => $definition) {
                if (is_array($definition) && isset($definition['file']) && !file_exists((string) $definition['file'])) {
                    $problems[] = "The $kind '$key' file {$definition['file']} does not exist.";
                }
            }
        }

        return $problems;
    }

    private function checkBindSource(string $source, string $cloneReal): ?string
    {
        if ($source === '' || !str_starts_with($source, '/')) {
            return null;
        }

        // Inside the clone (a relative path such as ./data): Docker may create it, which is harmless there.
        if ($source === $cloneReal || str_starts_with($source, $cloneReal . '/')) {
            return null;
        }

        $mntDir = rtrim(COMPOSE_GIT_MNT_DIR, '/');
        if (!str_starts_with($source, $mntDir . '/')) {
            if (file_exists($source)) {
                return null;
            }
            // Outside /mnt a missing top folder is almost always a typo of /mnt
            // itself (/mtn/user/...), which would build a tree in RAM. A missing
            // folder below an existing one, such as /tmp/transcode after a
            // reboot, is left for Docker to create.
            $topFolder = '/' . explode('/', substr($source, 1))[0];
            if (!is_dir($topFolder)) {
                return "the bind mount source $source does not exist, and neither does $topFolder. "
                    . 'Check the path for a typo, or create the folder first.';
            }
            $this->foldersDockerWillCreate[] = $source;
            return null;
        }

        if (GitPathGuard::findMountFor($source) === null) {
            return "the bind mount $source is not on a mounted disk, pool or share. "
                . 'Docker would create it in RAM. Check the path for a typo.';
        }
        if (file_exists($source)) {
            return null;
        }

        // A new stack's folders, such as appdata/newapp/config, are left for Docker to
        // create. The share (such as /mnt/user/appdata) must already exist: a missing
        // one is a typo, or would quietly become a new share.
        $share = GitPathGuard::shareFolder($source);
        if ($share === null || !is_dir($share)) {
            return "the bind mount source $source does not exist, and neither does " . ($share ?? 'its share') . '. '
                . 'Check the path for a typo, or create the folder first.';
        }
        $this->foldersDockerWillCreate[] = $source;
        return null;
    }

    /**
     * Bind-mount folders that do not exist yet and that Docker will create, found by the last run().
     *
     * @return string[]
     */
    public function foldersDockerWillCreate(): array
    {
        return $this->foldersDockerWillCreate;
    }

    /**
     * @param array<string, mixed> $resolved
     * @return string[]
     */
    private function takenContainerNames(array $resolved): array
    {
        $problems = [];
        foreach ((array) ($resolved['services'] ?? []) as $service) {
            if (!is_array($service) || !isset($service['container_name'])) {
                continue;
            }
            $name = (string) $service['container_name'];
            $inspect = DockerCommand::run(['container', 'inspect', '--', $name], null, 30);
            if (!$inspect->succeeded()) {
                if (self::failedOnlyOnMissingContainers($inspect)) {
                    // Nothing has that name.
                    continue;
                }
                $problems[] = "Could not check whether the container name '$name' is free: " . self::failureText($inspect);
                continue;
            }
            $containers = json_decode($inspect->stdout, true);
            if (!is_array($containers)) {
                $problems[] = "Could not check whether the container name '$name' is free: docker inspect did not print valid JSON.";
                continue;
            }
            // docker inspect also matches the start of a container id, so a short
            // hex-looking name such as "cafe" can find an unrelated container.
            $foundName = ltrim((string) ($containers[0]['Name'] ?? ''), '/');
            if ($foundName !== $name) {
                continue;
            }
            $owner = $containers[0]['Config']['Labels']['com.docker.compose.project'] ?? null;
            if ($owner !== $this->projectName) {
                $problems[] = "A container named '$name' already exists and does not belong to this stack"
                    . ($owner !== null ? " (it belongs to '$owner')" : '') . '.';
            }
        }
        return $problems;
    }

    /**
     * @param array<string, mixed> $resolved
     * @return string[]
     */
    private function takenPorts(array $resolved): array
    {
        $wanted = [];
        foreach ((array) ($resolved['services'] ?? []) as $serviceName => $service) {
            if (!is_array($service)) {
                continue;
            }
            // Docker publishes no ports for a container on macvlan or ipvlan
            // networks only (such as Unraid's br0), so they cannot clash.
            if (($service['ports'] ?? []) !== [] && $this->isOnlyOnMacvlanOrIpvlan($service, $resolved)) {
                continue;
            }
            foreach ((array) ($service['ports'] ?? []) as $port) {
                if (!is_array($port) || !isset($port['published'])) {
                    continue;
                }
                // A published range ("8000-8010") lets Docker pick any free
                // port in it, so only single ports are checked.
                $published = (string) $port['published'];
                if (preg_match('/^\d+$/', $published) !== 1) {
                    continue;
                }
                $wanted[] = [
                    'service' => (string) $serviceName,
                    'ip' => (string) ($port['host_ip'] ?? ''),
                    'port' => (int) $published,
                    'protocol' => strtolower((string) ($port['protocol'] ?? 'tcp')),
                ];
            }
        }
        if ($wanted === []) {
            return [];
        }

        // Two services of this stack asking for the same port clash with each
        // other, not with a running container: "up" would start one of them
        // and fail on the other.
        $problems = [];
        foreach ($wanted as $i => $want) {
            foreach (array_slice($wanted, $i + 1) as $other) {
                if ($other['port'] !== $want['port'] || $other['protocol'] !== $want['protocol']) {
                    continue;
                }
                if (!self::addressesOverlap($want['ip'], $other['ip'])) {
                    continue;
                }
                $problems[] = $other['service'] === $want['service']
                    ? "Service '{$want['service']}' publishes port {$want['port']}/{$want['protocol']} twice."
                    : "Services '{$want['service']}' and '{$other['service']}' both publish port {$want['port']}/{$want['protocol']}.";
            }
        }

        try {
            $heldByOthers = $this->portsHeldByOtherContainers();
        } catch (RuntimeException $error) {
            $problems[] = 'Could not check whether the published ports are free: ' . $error->getMessage();
            return $problems;
        }

        foreach ($wanted as $want) {
            foreach ($heldByOthers as $held) {
                if ($held['port'] !== $want['port'] || $held['protocol'] !== $want['protocol']) {
                    continue;
                }
                if (!self::addressesOverlap($want['ip'], $held['ip'])) {
                    continue;
                }
                $problems[] = "Service '{$want['service']}': port {$want['port']}/{$want['protocol']} is already in use by {$held['owner']}.";
                break;
            }
        }
        return array_values(array_unique($problems));
    }

    /**
     * Ports published by running containers of other stacks, or by plain containers.
     *
     * @return list<array{ip: string, port: int, protocol: string, owner: string}>
     * @throws RuntimeException when Docker could not say
     */
    private function portsHeldByOtherContainers(): array
    {
        $result = [];
        $ids = DockerCommand::run(['ps', '-q'], null, 30);
        if (!$ids->succeeded()) {
            throw new RuntimeException('docker ps failed: ' . self::failureText($ids));
        }
        $idList = array_values(array_filter(explode("\n", trim($ids->stdout))));
        if ($idList === []) {
            return $result;
        }
        $inspect = DockerCommand::run(array_merge(['container', 'inspect', '--'], $idList), null, 60);
        // A container removed between the two commands makes inspect fail,
        // but it still prints the others, and the removed one holds no port.
        if (!$inspect->succeeded() && !self::failedOnlyOnMissingContainers($inspect)) {
            throw new RuntimeException('docker inspect failed: ' . self::failureText($inspect));
        }
        $containers = json_decode($inspect->stdout, true);
        if (!is_array($containers)) {
            throw new RuntimeException('docker inspect did not print valid JSON.');
        }

        foreach ($containers as $container) {
            if (!is_array($container)) {
                continue;
            }
            $project = $container['Config']['Labels']['com.docker.compose.project'] ?? null;
            $name = ltrim((string) ($container['Name'] ?? ''), '/');
            foreach ((array) ($container['NetworkSettings']['Ports'] ?? []) as $containerPort => $bindings) {
                $protocol = str_contains((string) $containerPort, '/') ? explode('/', (string) $containerPort)[1] : 'tcp';
                foreach ((array) $bindings as $binding) {
                    if (!is_array($binding) || !isset($binding['HostPort'])) {
                        continue;
                    }
                    if ($project !== $this->projectName) {
                        $result[] = [
                            'ip' => (string) ($binding['HostIp'] ?? ''),
                            'port' => (int) $binding['HostPort'],
                            'protocol' => $protocol,
                            'owner' => "container '$name'",
                        ];
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Whether every network a service joins has the macvlan or ipvlan driver.
     *
     * A service with no networks listed joins the project's default network
     * (a bridge). The driver of an external network is asked from Docker.
     *
     * @param array<string, mixed> $service
     * @param array<string, mixed> $resolved
     */
    private function isOnlyOnMacvlanOrIpvlan(array $service, array $resolved): bool
    {
        if (isset($service['network_mode'])) {
            return false;
        }
        $networkKeys = array_keys((array) ($service['networks'] ?? []));
        if ($networkKeys === []) {
            return false;
        }
        foreach ($networkKeys as $key) {
            $definition = (array) ($resolved['networks'][$key] ?? []);
            $driver = (string) ($definition['driver'] ?? '');
            if (($definition['external'] ?? false) === true) {
                $driver = $this->externalNetworkDriver((string) ($definition['name'] ?? $key));
            }
            if ($driver !== 'macvlan' && $driver !== 'ipvlan') {
                return false;
            }
        }
        return true;
    }

    private function externalNetworkDriver(string $name): string
    {
        if (!isset($this->externalNetworkDrivers[$name])) {
            $inspect = DockerCommand::run(['network', 'inspect', '--format', '{{.Driver}}', '--', $name], null, 30);
            $this->externalNetworkDrivers[$name] = $inspect->succeeded() ? trim($inspect->stdout) : '';
        }
        return $this->externalNetworkDrivers[$name];
    }

    /**
     * Whether a failed "docker container inspect" failed only because a
     * container it was asked about does not exist. Any other failure (the
     * daemon not answering, a timeout) means the question was not answered.
     */
    private static function failedOnlyOnMissingContainers(ProcessResult $inspect): bool
    {
        if ($inspect->timedOut) {
            return false;
        }
        $lines = array_filter(array_map('trim', explode("\n", $inspect->stderr)), static fn(string $line): bool => $line !== '');
        if ($lines === []) {
            return false;
        }
        foreach ($lines as $line) {
            // "Error response from daemon: No such container: x" (docker container inspect),
            // "error: no such object: x" (docker inspect).
            if (stripos($line, 'no such container') === false && stripos($line, 'no such object') === false) {
                return false;
            }
        }
        return true;
    }

    private static function failureText(ProcessResult $result): string
    {
        return trim($result->stderr) !== '' ? trim($result->stderr) : $result->errorSummary();
    }

    private static function addressesOverlap(string $a, string $b): bool
    {
        $any = ['', '0.0.0.0', '::'];
        return in_array($a, $any, true) || in_array($b, $any, true) || $a === $b;
    }
}
