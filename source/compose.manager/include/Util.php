<?php

require_once("/usr/local/emhttp/plugins/compose.manager/include/Defines.php");
require_once("/usr/local/emhttp/plugins/dynamix/include/Wrappers.php");

/**
 * Utility functions for Compose Manager
 *
 * This file contains shared utility functions used across the Compose Manager plugin.
 * Functions include logging, string sanitization, path handling, and stack operation locking.
 *
 * These functions are designed to be reusable and testable. They are used by both the
 * main plugin code and the AJAX action handlers.
 */

if (!function_exists('composeLogger')) {
    function composeLogger($message, $data = null, $type = 'user', $level = 'info', $category = '')
    {
        $message = (string) $message;
        if ($type == '' || $type == null) {
            $type = 'user';
        }
        switch ($level) {
            case 'debug':
                $logLevel = "$type.debug";
                $displayLevel = '[DEBUG]';
                break;
            case 'error':
            case 'err':
                $logLevel = "$type.err";
                $displayLevel = '[ERROR]';
                break;
            case 'warning':
            case 'warn':
                $logLevel = "$type.warning";
                $displayLevel = '[WARN]';
                break;
            case 'info':
            default:
                $logLevel = "$type.info";
                $displayLevel = '[INFO]';
        }
        $cfg = @parse_ini_file("/boot/config/plugins/compose.manager/compose.manager.cfg", true, INI_SCANNER_RAW);
        $debugMode = ((($cfg['DEBUG_TO_LOG'] ?? 'false') == 'true'));
        // Skip debug messages if debug logging is disabled in plugin settings
        if (!$debugMode && $level == 'debug') {
            return;
        }

        $category = trim((string) $category);
        if ($category !== '') {
            $category = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $category) ?? '';
            $category = trim($category, '-');
        }
        if ($debugMode) {
            $messageParts = ["[$logLevel]"];
        } else {
            $messageParts = [$displayLevel];
        }
        if ($category !== '') {
            $messageParts[] = '[' . $category . ']';
        }
        $messageParts[] = $message;

        if ($data !== null && $data !== '' && $data !== 'null') {
            if (is_array($data) || is_object($data)) {
                $data = json_encode($data);
            }
            $messageParts[] = ' - Data: ' . (string) $data;
        }

        $formattedMessage = implode(' ', $messageParts);
        $logCmd = "logger -t 'compose.manager' -p '$logLevel' " . escapeshellarg($formattedMessage);
        exec($logCmd);
    }
}

function sanitizeLogText(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!function_exists('getElement')) {
    /**
     * Convert an element name to a safe HTML ID.
     * Replaces dots with dashes and removes spaces.
     */
    function getElement($element)
    {
        $return = str_replace('.', '-', $element);
        $return = str_replace(' ', '', $return);
        return $return;
    }
}

function getComposeStackOrderKey(string $composeRoot): string
{
    $normalized = realpath($composeRoot);
    if ($normalized === false || $normalized === null) {
        $normalized = rtrim($composeRoot, '/');
    }
    return (string) $normalized;
}

function getComposeStackOrderMap(): array
{
    if (!is_file(COMPOSE_STACK_ORDER_FILE)) {
        return [];
    }

    $json = @file_get_contents(COMPOSE_STACK_ORDER_FILE);
    if ($json === false || $json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function getComposeStackOrder(string $composeRoot): array
{
    $map = getComposeStackOrderMap();
    $key = getComposeStackOrderKey($composeRoot);
    $order = $map[$key] ?? [];
    return is_array($order) ? array_values(array_filter($order, 'is_string')) : [];
}

function saveComposeStackOrder(string $composeRoot, array $projects): bool
{
    $map = getComposeStackOrderMap();
    $map[getComposeStackOrderKey($composeRoot)] = array_values($projects);

    $dir = dirname(COMPOSE_STACK_ORDER_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return @file_put_contents(COMPOSE_STACK_ORDER_FILE, $json . "\n", LOCK_EX) !== false;
}

/**
 * Find the first compose file in a directory using Docker Compose spec priority.
 *
 * @param string $dir The directory to check
 * @return string|false Full path to compose file, or false if none found
 */
function findComposeFile($dir)
{
    foreach (COMPOSE_FILE_NAMES as $name) {
        if (is_file("$dir/$name")) {
            return "$dir/$name";
        }
    }
    return false;
}

/**
 * Check whether a stack directory has a compose file (any of the supported names).
 *
 * @param string $dir The directory to check
 * @return bool
 */
function hasComposeFile($dir)
{
    return findComposeFile($dir) !== false;
}

/**
 * Resolve the config file path used by auto-update.
 *
 * Prefers a persistent path under /boot/config when available and writable,
 * with environment override support for tests.
 *
 * @return string
 */
function getAutoUpdateConfigFilePath(): string
{
    global $plugin_root;

    $override = getenv('COMPOSE_MANAGER_AUTOUPDATE_FILE');
    if ($override !== false && $override !== '') {
        return $override;
    }

    $persistentPath = '/boot/config/plugins/compose.manager/autoupdate.json';
    $persistentDir  = dirname($persistentPath);

    // Prefer a persistent, writable location under /boot/config when possible.
    if ((is_dir('/boot/config') || is_dir($persistentDir) || @mkdir($persistentDir, 0755, true)) && is_dir($persistentDir)) {
        if (is_writable($persistentDir)) {
            // If the file already exists but is not writable, fall back to plugin_root.
            if (!file_exists($persistentPath) || is_writable($persistentPath)) {
                return $persistentPath;
            }
        }
    }

    return rtrim($plugin_root ?? '', '/') . '/autoupdate.json';
}

/**
 * Validate that a path is allowed for auto-update operations.
 * Must be under compose_root, /mnt/, or /boot/config/.
 *
 * @param string $path The path to validate
 * @return bool
 */
function isAllowedAutoUpdatePath($path): bool
{
    global $compose_root;

    $realPath = realpath($path);
    if ($realPath === false) {
        return false;
    }

    $realComposeRoot = realpath($compose_root);
    if ($realComposeRoot !== false) {
        $realComposeRoot = rtrim($realComposeRoot, DIRECTORY_SEPARATOR);
        if ($realPath === $realComposeRoot || strpos($realPath, $realComposeRoot . DIRECTORY_SEPARATOR) === 0) {
            return true;
        }
    }

    if ($realPath === '/mnt' || strpos($realPath, '/mnt/') === 0) {
        return true;
    }
    if ($realPath === '/boot/config' || strpos($realPath, '/boot/config/') === 0) {
        return true;
    }

    return false;
}

/**
 * Pure string checks for path validation.
 */
class Path
{
    public static function hasNewline(string $path): bool
    {
        return strpos($path, "\n") !== false;
    }

    public static function hasSeparator(string $path): bool
    {
        return strpos($path, '/') !== false || strpos($path, DIRECTORY_SEPARATOR) !== false;
    }

    public static function hasWindowsStylePath(string $path): bool
    {
        return DIRECTORY_SEPARATOR === '/' && strpos($path, '\\') !== false;
    }

    public static function hasTraversal(string $path): bool
    {
        return strpos($path, '/..') !== false || strpos($path, '../') !== false;
    }

    public static function isAbsolutePath(string $path): bool
    {
        return preg_match('#^([A-Za-z]:|/|\\\\)#', $path) === 1;
    }
}

/**
 * Generic shared string helper utilities.
 */
class Strings
{
    public static function stripQuotes(string $value): string
    {
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}

/**
 * Validate a WebUI URL, allowing [IP] and [PORT]/[PORT:xxxx] placeholders.
 */
function isValidWebuiUrl(string $url): bool
{
    if ($url === '') {
        return false;
    }
    // Replace placeholders with dummy values so the URL can be validated structurally
    $normalized = preg_replace(
        ['/\[IP\]/i', '/\[PORT:\d+\]/i', '/\[PORT\]/i'],
        ['localhost', '8080', '8080'],
        $url
    );
    if ($normalized === null) {
        return false;
    }
    return (bool) filter_var($normalized, FILTER_VALIDATE_URL)
        && (strpos($normalized, 'http://') === 0 || strpos($normalized, 'https://') === 0);
}

/**
 * Ports that are reliably not associated with HTTP-based WebUI endpoints.
 *
 * This blocklist is used as a conservative exclusion step during WebUI detection to
 * avoid treating well-known infrastructure and database ports as browser-accessible
 * interfaces. A blocklist is preferred here because many applications expose WebUIs on
 * arbitrary high ports, so an allowlist of "known good" ports would create too many
 * false negatives.
 *
 * Extend this list only for ports that are consistently reserved for non-WebUI
 * protocols across common deployments. If a port is sometimes used by application
 * dashboards or other HTTP services, leave it out so detection can continue to inspect
 * it normally.
 */
const NON_WEBUI_PORTS = [
    22,    // SSH
    25,    // SMTP
    53,    // DNS
    67,    // DHCP
    68,    // DHCP
    69,    // TFTP
    123,   // NTP
    143,   // IMAP
    161,   // SNMP
    389,   // LDAP
    465,   // SMTPS
    514,   // Syslog
    587,   // SMTP submission
    636,   // LDAPS
    993,   // IMAPS
    995,   // POP3S
    1194,  // OpenVPN
    1883,  // MQTT
    3306,  // MySQL
    5432,  // PostgreSQL
    5672,  // AMQP/RabbitMQ
    6379,  // Redis
    6881,  // BitTorrent
    11211, // Memcached
    27017, // MongoDB
    51820, // WireGuard
];

/** Ports that imply HTTPS rather than HTTP. */
const HTTPS_PORTS = [443, 8443];

/**
 * Detect a likely WebUI URL from a stack's compose file and override labels.
 *
 * Uses a blocklist approach: any TCP port NOT in NON_WEBUI_PORTS is a candidate.
 * First candidate wins; use the first service's first eligible port.
 *
 * @param string $composeRoot Compose projects root directory
 * @param string $project     Stack folder name
 * @return array{url: string, source: string}|null Detected URL template + source description, or null
 */
function detectWebuiUrl(string $composeRoot, string $project): ?array
{
    if (!function_exists('yaml_parse')) {
        return null;
    }

    try {
        $stackInfo = \StackInfo::fromProject($composeRoot, $project);
    } catch (\Throwable $e) {
        return null;
    }

    // 1. Check override labels for explicit net.unraid.docker.webui
    $overridePath = $stackInfo->getOverridePath();
    if ($overridePath && is_file($overridePath)) {
        $overrideContent = @file_get_contents($overridePath);
        if ($overrideContent !== false) {
            $override = @yaml_parse($overrideContent);
            if (is_array($override) && !empty($override['services'])) {
                foreach ($override['services'] as $svcName => $svc) {
                    $webui = $svc['labels']['net.unraid.docker.webui'] ?? null;
                    if (is_string($webui) && $webui !== '' && isValidWebuiUrl($webui)) {
                        return ['url' => $webui, 'source' => "label on service '$svcName'"];
                    }
                }
            }
        }
    }

    // 2. Parse main compose file for port mappings
    $composePath = $stackInfo->composeFilePath;
    if (!$composePath || !is_file($composePath)) {
        return null;
    }
    $content = @file_get_contents($composePath);
    if ($content === false) {
        return null;
    }
    $parsed = @yaml_parse($content);
    if (!is_array($parsed) || empty($parsed['services'])) {
        return null;
    }

    // Collect all services with eligible port mappings (not in blocklist)
    $candidates = [];
    foreach ($parsed['services'] as $svcName => $svc) {
        if (empty($svc['ports']) || !is_array($svc['ports'])) {
            continue;
        }
        foreach ($svc['ports'] as $portEntry) {
            $proto = 'tcp';
            if (is_string($portEntry)) {
                if (preg_match('/\/udp$/i', $portEntry)) {
                    continue; // skip UDP-only ports
                }
                $clean = preg_replace('/\/\w+$/', '', $portEntry);
                $parts = explode(':', $clean);
                $containerPort = (int) end($parts);
            } elseif (is_array($portEntry) && isset($portEntry['target'])) {
                $proto = $portEntry['protocol'] ?? 'tcp';
                if ($proto !== 'tcp') {
                    continue;
                }
                $containerPort = (int) $portEntry['target'];
            } else {
                continue;
            }
            if ($containerPort > 0 && !in_array($containerPort, NON_WEBUI_PORTS, true)) {
                $candidates[] = ['service' => $svcName, 'port' => $containerPort];
            }
        }
    }

    if (empty($candidates)) {
        return null;
    }

    // 3. First eligible candidate wins
    $c = $candidates[0];
    $scheme = in_array($c['port'], HTTPS_PORTS, true) ? 'https' : 'http';
    return [
        'url' => "{$scheme}://[IP]:[PORT:{$c['port']}]/",
        'source' => "port {$c['port']} on service '{$c['service']}'"
    ];
}
/**
 * Import helper for Docker Manager containers.
 */
function getDockerManagerImportCandidates(): array
{
    require_once "/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php";

    $dockerClient = new DockerClient();
    $containers = $dockerClient->getDockerContainers();
    $candidates = [];

    foreach ($containers as $ct) {
        if (!is_array($ct)) {
            continue;
        }
        $manager = $ct['Manager'] ?? '';
        if ($manager !== 'dockerman') {
            continue;
        }
        $candidates[] = [
            'Id' => $ct['Id'] ?? '',
            'Name' => $ct['Name'] ?? '',
            'Image' => $ct['Image'] ?? '',
            'Status' => $ct['Status'] ?? '',
            'Running' => $ct['Running'] ?? false,
            'Icon' => $ct['Icon'] ?? '',
            'Url' => $ct['Url'] ?? '',
            'TSUrl' => $ct['TSUrl'] ?? '',
            'Labels' => $ct['Labels'] ?? [],
        ];
    }

    return $candidates;
}

function getDockerManagerContainerInfo(string $id): array
{
    require_once "/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php";

    $dockerClient = new DockerClient();
    $info = $dockerClient->getContainerDetails($id);
    if (!is_array($info)) {
        return [];
    }
    return $info;
}

/**
 * Known port → healthcheck command map.
 * Keys are "port/proto", values are healthcheck test commands.
 */
function getKnownPortHealthchecks(): array
{
    return [
        '80/tcp'    => 'curl -f http://localhost:80/ || exit 1',
        '443/tcp'   => 'curl -fk https://localhost:443/ || exit 1',
        '8080/tcp'  => 'curl -f http://localhost:8080/ || exit 1',
        '8443/tcp'  => 'curl -fk https://localhost:8443/ || exit 1',
        '3000/tcp'  => 'curl -f http://localhost:3000/ || exit 1',
        '9090/tcp'  => 'curl -f http://localhost:9090/ || exit 1',
        '3306/tcp'  => 'mysqladmin ping -h localhost || exit 1',
        '5432/tcp'  => 'pg_isready -U postgres || exit 1',
        '6379/tcp'  => 'redis-cli ping || exit 1',
        '27017/tcp' => 'mongosh --eval "db.runCommand(\"ping\")" --quiet || exit 1',
        '9200/tcp'  => 'curl -f http://localhost:9200/_cluster/health || exit 1',
        '5672/tcp'  => 'rabbitmq-diagnostics -q ping || exit 1',
        '15672/tcp' => 'curl -f http://localhost:15672/ || exit 1',
        '1883/tcp'  => 'mosquitto_sub -t "\$SYS/#" -C 1 -W 3 || exit 1',
        '8086/tcp'  => 'curl -f http://localhost:8086/ping || exit 1',
        '9000/tcp'  => 'curl -f http://localhost:9000/ || exit 1',
    ];
}

/**
 * Known image name patterns → healthcheck commands (checked when port alone is ambiguous).
 * Pattern is matched case-insensitively against the image name (without tag).
 */
function getImageHealthcheckPatterns(): array
{
    return [
        'mysql'       => ['test' => ['CMD-SHELL', 'mysqladmin ping -h localhost || exit 1']],
        'mariadb'     => ['test' => ['CMD-SHELL', 'mysqladmin ping -h localhost || exit 1']],
        'postgres'    => ['test' => ['CMD-SHELL', 'pg_isready -U postgres || exit 1']],
        'redis'       => ['test' => ['CMD-SHELL', 'redis-cli ping || exit 1']],
        'mongo'       => ['test' => ['CMD-SHELL', 'mongosh --eval "db.runCommand(\"ping\")" --quiet || exit 1']],
        'nginx'       => ['test' => ['CMD-SHELL', 'curl -f http://localhost/ || exit 1']],
        'httpd'       => ['test' => ['CMD-SHELL', 'curl -f http://localhost/ || exit 1']],
        'apache'      => ['test' => ['CMD-SHELL', 'curl -f http://localhost/ || exit 1']],
        'traefik'     => ['test' => ['CMD-SHELL', 'traefik healthcheck --ping || exit 1']],
        'rabbitmq'    => ['test' => ['CMD-SHELL', 'rabbitmq-diagnostics -q ping || exit 1']],
        'mosquitto'   => ['test' => ['CMD-SHELL', 'mosquitto_sub -t "\\$SYS/#" -C 1 -W 3 || exit 1']],
        'influxdb'    => ['test' => ['CMD-SHELL', 'curl -f http://localhost:8086/ping || exit 1']],
        'elasticsearch' => ['test' => ['CMD-SHELL', 'curl -f http://localhost:9200/_cluster/health || exit 1']],
        'memcached'   => ['test' => ['CMD-SHELL', 'echo stats | nc localhost 11211 || exit 1']],
    ];
}

/**
 * Guess a healthcheck for a service based on its image name and exposed ports.
 *
 * @param string $image Full image string (e.g. "nginx:latest", "linuxserver/mariadb:10")
 * @param array $exposedPorts Exposed ports from Config.ExposedPorts (e.g. {"80/tcp": {}, "443/tcp": {}})
 * @param array|null $existingHealthcheck Existing healthcheck from Config.Healthcheck (docker inspect)
 * @return array|null Compose-format healthcheck array, or null if no guess possible
 */
function guessHealthcheck(string $image, array $exposedPorts, ?array $existingHealthcheck = null): ?array
{
    // If the container already has a healthcheck defined in its image, convert it to compose format
    if (!empty($existingHealthcheck) && !empty($existingHealthcheck['Test'])) {
        $test = $existingHealthcheck['Test'];
        $hc = ['test' => $test];
        if (!empty($existingHealthcheck['Interval'])) {
            // Docker stores intervals in nanoseconds; convert to compose duration string
            $ns = $existingHealthcheck['Interval'];
            $hc['interval'] = nanosToComposeDuration($ns);
        }
        if (!empty($existingHealthcheck['Timeout'])) {
            $hc['timeout'] = nanosToComposeDuration($existingHealthcheck['Timeout']);
        }
        if (isset($existingHealthcheck['Retries'])) {
            $hc['retries'] = (int)$existingHealthcheck['Retries'];
        }
        if (!empty($existingHealthcheck['StartPeriod'])) {
            $hc['start_period'] = nanosToComposeDuration($existingHealthcheck['StartPeriod']);
        }
        return $hc;
    }

    $defaults = ['interval' => '30s', 'timeout' => '10s', 'retries' => 3, 'start_period' => '10s'];

    // Try to match by exposed port first (most specific)
    $portMap = getKnownPortHealthchecks();
    foreach ($exposedPorts as $portProto => $_) {
        if (isset($portMap[$portProto])) {
            return array_merge($defaults, [
                'test' => ['CMD-SHELL', $portMap[$portProto]],
            ]);
        }
    }

    // Fall back to image name pattern matching
    $imageName = strtolower($image);
    // Strip tag
    if (($pos = strpos($imageName, ':')) !== false) {
        $imageName = substr($imageName, 0, $pos);
    }
    // Strip registry prefix (e.g. "linuxserver/mariadb" → check against "mariadb")
    $parts = explode('/', $imageName);
    $shortName = end($parts);

    $patterns = getImageHealthcheckPatterns();
    foreach ($patterns as $pattern => $hc) {
        if (str_contains($shortName, $pattern)) {
            return array_merge($defaults, $hc);
        }
    }

    return null;
}

/**
 * Convert Docker nanoseconds to compose duration string (e.g. "30s", "1m30s").
 */
function nanosToComposeDuration(int $ns): string
{
    $seconds = (int)($ns / 1_000_000_000);
    if ($seconds <= 0) {
        return '0s';
    }
    if ($seconds >= 60 && $seconds % 60 === 0) {
        return ($seconds / 60) . 'm';
    }
    if ($seconds >= 60) {
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return "{$m}m{$s}s";
    }
    return "{$seconds}s";
}

/**
 * Parse a port mapping string into structured data.
 *
 * @param string $portStr Port string like "8080:80/tcp", "192.168.1.1:8080:80/tcp", "80/tcp"
 * @return array{hostIp: string, hostPort: string, containerPort: string, protocol: string}
 */
function parsePortMapping(string $portStr): array
{
    $protocol = 'tcp';
    if (($slashPos = strrpos($portStr, '/')) !== false) {
        $protocol = substr($portStr, $slashPos + 1);
        $portStr = substr($portStr, 0, $slashPos);
    }

    // Handle bracketed IPv6 host IP: [::1]:8080:80
    if (str_starts_with($portStr, '[')) {
        $bracketEnd = strpos($portStr, ']');
        if ($bracketEnd !== false) {
            $hostIp = substr($portStr, 1, $bracketEnd - 1);
            $rest = substr($portStr, $bracketEnd + 1); // e.g. ":8080:80"
            $rest = ltrim($rest, ':');
            $parts = explode(':', $rest);
            if (count($parts) === 2) {
                return ['hostIp' => $hostIp, 'hostPort' => $parts[0], 'containerPort' => $parts[1], 'protocol' => $protocol];
            }
            if (count($parts) === 1 && $parts[0] !== '') {
                return ['hostIp' => $hostIp, 'hostPort' => '', 'containerPort' => $parts[0], 'protocol' => $protocol];
            }
        }
    }

    $parts = explode(':', $portStr);
    $count = count($parts);

    if ($count === 3) {
        return ['hostIp' => $parts[0], 'hostPort' => $parts[1], 'containerPort' => $parts[2], 'protocol' => $protocol];
    }
    if ($count === 2) {
        return ['hostIp' => '', 'hostPort' => $parts[0], 'containerPort' => $parts[1], 'protocol' => $protocol];
    }
    // Expose-only (no host binding)
    return ['hostIp' => '', 'hostPort' => '', 'containerPort' => $parts[0], 'protocol' => $protocol];
}

/**
 * Detect host port conflicts across a set of services.
 *
 * @param array $services Associative array of serviceName => service definition (with 'ports' key)
 * @return array Array of conflicts: [{hostPort, protocol, hostIp, services: [name1, name2, ...]}]
 */
function detectPortConflicts(array $services): array
{
    // Maps grouping key => ['ip' => ..., 'port' => ..., 'proto' => ..., 'services' => [...]]
    $portMap = [];

    foreach ($services as $serviceName => $service) {
        if (empty($service['ports']) || !is_array($service['ports'])) {
            continue;
        }
        foreach ($service['ports'] as $portStr) {
            $parsed = parsePortMapping($portStr);
            if ($parsed['hostPort'] === '') {
                continue; // expose-only, no host binding
            }
            // Normalize: treat empty and 0.0.0.0 as equivalent
            $ip = ($parsed['hostIp'] === '' || $parsed['hostIp'] === '0.0.0.0') ? '0.0.0.0' : $parsed['hostIp'];
            $key = "{$ip}:{$parsed['hostPort']}/{$parsed['protocol']}";
            if (!isset($portMap[$key])) {
                $portMap[$key] = [
                    'hostIp' => $ip,
                    'hostPort' => $parsed['hostPort'],
                    'protocol' => $parsed['protocol'],
                    'services' => [],
                ];
            }
            $portMap[$key]['services'][] = $serviceName;
        }
    }

    $conflicts = [];
    foreach ($portMap as $entry) {
        if (count($entry['services']) > 1) {
            $conflicts[] = [
                'hostIp' => $entry['hostIp'],
                'hostPort' => $entry['hostPort'],
                'protocol' => $entry['protocol'],
                'services' => array_values(array_unique($entry['services'])),
            ];
        }
    }
    return $conflicts;
}

/**
 * Get available Docker networks from the host.
 *
 * @return array Array of ['name' => string, 'driver' => string]
 */
function getDockerNetworks(): array
{
    $networks = [];
    exec("docker network ls --format '{{.Name}}\t{{.Driver}}' 2>/dev/null", $outputLines, $exitCode);
    if ($exitCode !== 0) {
        composeLogger('Failed to list Docker networks (exit code ' . $exitCode . ')', null, 'daemon', 'warning');
        return [];
    }
    foreach ($outputLines as $line) {
        $parts = explode("\t", $line);
        if (count($parts) === 2) {
            $networks[] = ['name' => $parts[0], 'driver' => $parts[1]];
        }
    }
    return $networks;
}

function dockerContainerToComposeService(array $info): array
{
    $service = [];
    $originalName = ltrim(trim($info['Name'] ?? $info['Id'] ?? ''), '/');
    $serviceName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $originalName);
    if ($serviceName === '') {
        $serviceName = 'container_' . substr($info['Id'] ?? 'unknown', 0, 12);
    }

    // Store container_name explicitly so each container gets a deterministic name
    $service['container_name'] = $serviceName;

    $config = $info['Config'] ?? [];
    $hostConfig = $info['HostConfig'] ?? [];

    $service['image'] = $config['Image'] ?? '';

    // Do not include explicit command/entrypoint by default in generated compose
    // because Compose can use defaults from the image; this avoids embedding Unraid startup semantics.

    if (!empty($config['Env']) && is_array($config['Env'])) {
        $env = [];
        $blacklist = ['TERM', 'LANG', 'HOME', 'PATH', 'HOST_OS', 'HOST_HOSTNAME', 'HOST_CONTAINERNAME'];
        foreach ($config['Env'] as $entry) {
            if (!str_contains($entry, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $entry, 2);
            if (in_array($key, $blacklist, true)) {
                continue;
            }
            $env[$key] = $value;
        }
        if (!empty($env)) {
            // Replace values with env variable expansion in compose file, values go in .env file.
            $service['environment'] = array_map(function ($key) {
                return sprintf('%s=${%s}', $key, $key);
            }, array_keys($env));
            // We store the resolved env set as a reserved field to generate .env file later.
            $service['__resolved_env'] = $env;
        }
    }

    if (!empty($hostConfig['RestartPolicy']['Name'])) {
        $policy = $hostConfig['RestartPolicy']['Name'];
        if ($policy !== 'no') {
            $service['restart'] = $policy;
        }
    }

    $ports = [];
    if (!empty($hostConfig['PortBindings']) && is_array($hostConfig['PortBindings'])) {
        foreach ($hostConfig['PortBindings'] as $port => $bindings) {
            [$privatePort, $proto] = array_pad(explode('/', $port), 2, 'tcp');
            foreach ((array)$bindings as $bind) {
                if (!is_array($bind)) continue;
                $hostIp = $bind['HostIp'] ?? '';
                $hostPort = $bind['HostPort'] ?? '';
                if ($hostIp !== '' && $hostIp !== '0.0.0.0') {
                    // Bracket IPv6 addresses for valid Compose port syntax
                    $formattedIp = str_contains($hostIp, ':') ? "[{$hostIp}]" : $hostIp;
                    $ports[] = "{$formattedIp}:{$hostPort}:{$privatePort}/{$proto}";
                } elseif ($hostPort !== '') {
                    $ports[] = "{$hostPort}:{$privatePort}/{$proto}";
                } else {
                    $ports[] = "{$privatePort}/{$proto}";
                }
            }
        }
    }
    if (!empty($ports)) {
        $service['ports'] = $ports;
    }

    $volumes = [];
    if (!empty($info['Mounts']) && is_array($info['Mounts'])) {
        foreach ($info['Mounts'] as $mount) {
            $source = $mount['Source'] ?? '';
            $target = $mount['Destination'] ?? '';
            if ($source !== '' && $target !== '') {
                $mode = '';
                if (array_key_exists('RW', $mount) && $mount['RW'] === false) {
                    $mode = ':ro';
                }
                $volumes[] = "{$source}:{$target}{$mode}";
            }
        }
    }
    if (!empty($volumes)) {
        $service['volumes'] = $volumes;
    }

    $labels = [];
    if (!empty($config['Labels']) && is_array($config['Labels'])) {
        foreach ($config['Labels'] as $key => $val) {
            // Skip internal Compose Manager markers and helper labels
            if (in_array($key, ['net.unraid.docker.managed', 'net.unraid.docker.icon', 'net.unraid.docker.webui'], true)) {
                continue;
            }

            // Skip uncaught LinuxServer/OCI metadata that is not needed in compose services
            if ($key === 'build_version' || $key === 'maintainer') {
                continue;
            }
            $excludePrefixes = [
                'org.opencontainers.image.',
                'org.label-schema.',
                'com.docker.compose.',
            ];
            $skip = false;
            foreach ($excludePrefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            $labels[$key] = $val;
        }
    }
    if (!empty($labels)) {
        $service['labels'] = $labels;
    }

    if (!empty($hostConfig['NetworkMode'])) {
        $networkMode = $hostConfig['NetworkMode'];
        if ($networkMode !== 'default') {
            if (in_array($networkMode, ['host', 'bridge', 'none'])) {
                $service['network_mode'] = $networkMode;
            } elseif (str_starts_with($networkMode, 'container:')) {
                $service['network_mode'] = $networkMode;
            }
        }
    }

    // Extract named networks from NetworkSettings (including static IPs)
    $networks = $info['NetworkSettings']['Networks'] ?? [];
    if (is_array($networks) && !isset($service['network_mode'])) {
        $namedNetworks = [];
        $networkIPs = [];
        foreach ($networks as $netName => $netInfo) {
            if (!in_array($netName, ['bridge', 'host', 'none'], true)) {
                $namedNetworks[] = $netName;
                // Preserve static IP from IPAMConfig (user-assigned, not DHCP)
                $staticIP = $netInfo['IPAMConfig']['IPv4Address'] ?? '';
                if ($staticIP !== '') {
                    $networkIPs[$netName] = $staticIP;
                }
            }
        }
        if (!empty($namedNetworks)) {
            $service['networks'] = $namedNetworks;
        }
        if (!empty($networkIPs)) {
            $service['__network_ips'] = $networkIPs;
        }
    }

    // Extract exposed ports (for healthcheck guessing)
    $exposedPorts = $config['ExposedPorts'] ?? [];
    if (is_array($exposedPorts)) {
        $service['__exposed_ports'] = $exposedPorts;
    }

    // Extract existing healthcheck from image, or guess one
    $existingHC = $config['Healthcheck'] ?? null;
    $guessed = guessHealthcheck($service['image'], $exposedPorts, $existingHC);
    if ($existingHC && !empty($existingHC['Test'])) {
        // Use the image-defined healthcheck (already converted by guessHealthcheck)
        if ($guessed) {
            $service['healthcheck'] = $guessed;
            $service['__healthcheck_source'] = 'image';
        }
    } elseif ($guessed) {
        // Store as a guess that the user can accept or reject
        $service['__guessed_healthcheck'] = $guessed;
        $service['__healthcheck_source'] = 'auto';
    }

    return ['name' => $serviceName, 'originalName' => $originalName, 'service' => $service];
}

/**
 * Fetch, convert, and deduplicate Docker Manager containers into compose services.
 *
 * Shared by generateImportData and finalizeImportCompose to avoid duplicating
 * the container-fetch → convert → label-extract → dedup loop.
 *
 * @param string[] $containerIds Docker container IDs to process
 * @return array{services: array, inspectData: array} services keyed by deduped name,
 *         inspectData keyed by same name → raw docker inspect array
 */
function buildImportServicesFromIds(array $containerIds): array
{
    $services = [];
    $inspectData = [];
    foreach ($containerIds as $id) {
        $id = trim($id);
        if ($id === '') {
            continue;
        }
        $info = getDockerManagerContainerInfo($id);
        if (empty($info)) {
            continue;
        }
        $converted = dockerContainerToComposeService($info);
        $name = $converted['name'];
        $service = $converted['service'];

        $serviceIcon = $info['Config']['Labels']['net.unraid.docker.icon'] ?? '';
        $serviceWebui = $info['Config']['Labels']['net.unraid.docker.webui'] ?? '';
        if ($serviceIcon) {
            $service['icon'] = $serviceIcon;
        }
        if ($serviceWebui) {
            $service['webui'] = $serviceWebui;
        }

        // Deduplicate service keys
        $baseName = $name;
        $append = 1;
        while (isset($services[$name])) {
            $name = $baseName . '_' . $append;
            $append++;
        }
        $services[$name] = $service;
        $inspectData[$name] = $info;
    }
    return ['services' => $services, 'inspectData' => $inspectData];
}

/**
 * Quote a YAML scalar value if it contains special characters.
 *
 * Preserves native int/float types so that fields like healthcheck.retries
 * are emitted as bare numbers (3) rather than quoted strings ("3").
 */
function yamlQuoteValue($value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    // Preserve actual numeric types as bare YAML scalars
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    $str = (string)$value;
    if ($str === '') {
        return '""';
    }
    // Quote if value contains YAML-special characters or could be misinterpreted
    if (preg_match('/[:\#{}\[\],&*?|>!\'"%@`]/', $str)
        || preg_match('/^[\s-]/', $str)
        || preg_match('/\s$/', $str)
        || in_array(strtolower($str), ['true', 'false', 'null', 'yes', 'no', 'on', 'off'], true)
        || is_numeric($str)
    ) {
        return '"' . str_replace(['\\', '"', "\n", "\t"], ['\\\\', '\\"', '\\n', '\\t'], $str) . '"';
    }
    return $str;
}

function dockerServicesToComposeYml(array $services, array $wizardConfig = []): string
{
    $yaml = "services:\n";
    $allNetworks = [];
    $externalNetworks = []; // networks declared as external: true
    $stackNetworks = [];    // networks created by the stack (driver: bridge)

    $containerNames = $wizardConfig['containerNames'] ?? [];
    $netCfg = $wizardConfig['networkConfig'] ?? [];
    $healthchecks = $wizardConfig['healthchecks'] ?? [];
    $dependencies = $wizardConfig['dependencies'] ?? [];

    $stackNetEnabled = !empty($netCfg['stackNetwork']['enabled']);
    $stackNetName = $netCfg['stackNetwork']['name'] ?? '';
    $externalNetList = $netCfg['externalNetworks'] ?? [];
    $perServiceNet = $netCfg['perService'] ?? [];

    // If wizard config provides a stack network, track it
    if ($stackNetEnabled && $stackNetName !== '') {
        $stackNetworks[$stackNetName] = true;
    }
    // Track external networks from wizard
    foreach ($externalNetList as $extNet) {
        if (is_string($extNet) && $extNet !== '') {
            $externalNetworks[$extNet] = true;
        }
    }

    // Internal fields to skip when emitting YAML
    $internalKeys = [
        '__resolved_env', '__exposed_ports', '__guessed_healthcheck',
        '__healthcheck_source', '__network_ips', 'icon', 'webui',
    ];

    foreach ($services as $name => $service) {
        $yaml .= "  " . $name . ":\n";

        // Apply container name override from wizard
        if (isset($containerNames[$name]) && $containerNames[$name] !== '') {
            $service['container_name'] = $containerNames[$name];
        }

        // Apply network configuration from wizard (if provided)
        if (!empty($wizardConfig) && !empty($perServiceNet)) {
            $svcNetCfg = $perServiceNet[$name] ?? [];
            $svcNetMode = $svcNetCfg['networkMode'] ?? null;

            if ($svcNetMode && $svcNetMode !== 'default') {
                // Explicit network_mode overrides any networks setting
                $service['network_mode'] = $svcNetMode;
                unset($service['networks']);
            } else {
                // Remove any pre-existing network_mode if wizard says "default"
                unset($service['network_mode']);

                $serviceNetworks = [];
                $svcIPv4 = $svcNetCfg['ipv4Addresses'] ?? [];
                // Attach to stack network if enabled
                if ($stackNetEnabled && $stackNetName !== '' && !empty($svcNetCfg['attachStackNet'])) {
                    $serviceNetworks[] = $stackNetName;
                }
                // Attach to selected external networks
                $svcExternalNets = $svcNetCfg['externalNets'] ?? [];
                foreach ($svcExternalNets as $extNet) {
                    if (is_string($extNet) && $extNet !== '' && !in_array($extNet, $serviceNetworks, true)) {
                        $serviceNetworks[] = $extNet;
                        $externalNetworks[$extNet] = true;
                    }
                }
                if (!empty($serviceNetworks)) {
                    // Use mapping format if any network has a static IP
                    $hasIPs = false;
                    foreach ($serviceNetworks as $netName) {
                        if (!empty($svcIPv4[$netName])) {
                            $hasIPs = true;
                            break;
                        }
                    }
                    if ($hasIPs) {
                        $netMap = [];
                        foreach ($serviceNetworks as $netName) {
                            $ip = $svcIPv4[$netName] ?? '';
                            $netMap[$netName] = $ip !== '' ? ['ipv4_address' => $ip] : null;
                        }
                        $service['networks'] = $netMap;
                    } else {
                        $service['networks'] = $serviceNetworks;
                    }
                } else {
                    unset($service['networks']);
                }
            }
        }

        // Apply healthcheck from wizard
        if (!empty($wizardConfig) && array_key_exists($name, $healthchecks)) {
            $hc = $healthchecks[$name];
            if ($hc === null || $hc === false) {
                // User explicitly removed the healthcheck
                unset($service['healthcheck']);
                unset($service['__guessed_healthcheck']);
            } else {
                $service['healthcheck'] = $hc;
                unset($service['__guessed_healthcheck']);
            }
        } elseif (empty($wizardConfig)) {
            // No wizard config (legacy path) — don't auto-apply guessed healthchecks
            unset($service['__guessed_healthcheck']);
        }

        // Apply depends_on from wizard
        if (!empty($dependencies[$name]) && is_array($dependencies[$name])) {
            $dependsOn = [];
            foreach ($dependencies[$name] as $dep) {
                $depService = $dep['service'] ?? '';
                $depCondition = $dep['condition'] ?? 'service_started';
                if ($depService !== '' && isset($services[$depService])) {
                    $dependsOn[$depService] = ['condition' => $depCondition];
                }
            }
            if (!empty($dependsOn)) {
                $service['depends_on'] = $dependsOn;
            }
        }

        // Collect all referenced networks for the top-level block
        if (!empty($service['networks']) && is_array($service['networks'])) {
            if (array_is_list($service['networks'])) {
                foreach ($service['networks'] as $networkName) {
                    if (!in_array($networkName, $allNetworks, true)) {
                        $allNetworks[] = $networkName;
                    }
                }
            } else {
                // Mapping format (network => config or null)
                foreach (array_keys($service['networks']) as $networkName) {
                    if (!in_array($networkName, $allNetworks, true)) {
                        $allNetworks[] = $networkName;
                    }
                }
            }
        }

        // Emit service fields
        foreach ($service as $key => $value) {
            if (in_array($key, $internalKeys, true)) {
                continue;
            }
            if ($key === 'depends_on' && is_array($value) && !array_is_list($value)) {
                // depends_on with conditions: nested map format
                $yaml .= "    depends_on:\n";
                foreach ($value as $depName => $depConfig) {
                    $yaml .= "      " . $depName . ":\n";
                    if (is_array($depConfig)) {
                        foreach ($depConfig as $dk => $dv) {
                            $yaml .= "        " . $dk . ": " . yamlQuoteValue($dv) . "\n";
                        }
                    }
                }
                continue;
            }
            if ($key === 'healthcheck' && is_array($value)) {
                $yaml .= "    healthcheck:\n";
                foreach ($value as $hcKey => $hcVal) {
                    // Skip private metadata keys (e.g. __originalTestType)
                    if (is_string($hcKey) && str_starts_with($hcKey, '__')) {
                        continue;
                    }
                    if ($hcKey === 'test' && is_array($hcVal)) {
                        $yaml .= "      test:\n";
                        foreach ($hcVal as $testItem) {
                            $yaml .= "        - " . yamlQuoteValue($testItem) . "\n";
                        }
                    } elseif (is_string($hcVal) || is_numeric($hcVal)) {
                        $yaml .= "      $hcKey: " . yamlQuoteValue($hcVal) . "\n";
                    }
                }
                continue;
            }
            if ($key === 'networks' && is_array($value) && !array_is_list($value)) {
                // Networks mapping format with per-network config (e.g. ipv4_address)
                $yaml .= "    networks:\n";
                foreach ($value as $netName => $netConfig) {
                    if (is_array($netConfig) && !empty($netConfig)) {
                        $yaml .= "      " . $netName . ":\n";
                        foreach ($netConfig as $nk => $nv) {
                            $yaml .= "        " . $nk . ": " . yamlQuoteValue($nv) . "\n";
                        }
                    } else {
                        // No config for this network — emit as simple entry
                        $yaml .= "      " . $netName . ":\n";
                    }
                }
                continue;
            }
            if (is_string($value) || is_numeric($value) || is_bool($value)) {
                $yaml .= "    $key: " . yamlQuoteValue($value) . "\n";
            } elseif (is_array($value)) {
                $yaml .= "    $key:\n";
                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        $yaml .= "      - " . yamlQuoteValue($item) . "\n";
                    }
                } else {
                    foreach ($value as $subKey => $subVal) {
                        $yaml .= "      " . $subKey . ": " . yamlQuoteValue($subVal) . "\n";
                    }
                }
            }
        }
    }

    // Emit top-level networks block
    $hasNetworks = !empty($allNetworks) || !empty($stackNetworks) || !empty($externalNetworks);
    if ($hasNetworks) {
        $yaml .= "\nnetworks:\n";
        // Stack-created networks (bridge driver)
        foreach ($stackNetworks as $netName => $_) {
            $yaml .= "  " . $netName . ":\n";
            $yaml .= "    driver: bridge\n";
        }
        // External networks
        foreach ($allNetworks as $networkName) {
            if (isset($stackNetworks[$networkName])) {
                continue; // Already emitted as stack network
            }
            $yaml .= "  " . $networkName . ":\n";
            $yaml .= "    external: true\n";
        }
        // Any external networks from wizard not yet emitted
        foreach ($externalNetworks as $netName => $_) {
            if (isset($stackNetworks[$netName]) || in_array($netName, $allNetworks, true)) {
                continue;
            }
            $yaml .= "  " . $netName . ":\n";
            $yaml .= "    external: true\n";
        }
    }

    return $yaml;
}

function dockerResolveEnvAndCompose(array &$services): string
{
    $globalEnv = [];

    // Track environment values by canonical variable to avoid duplicates for same values across multiple services
    $valueKeyMap = [];

    foreach ($services as $serviceName => &$service) {
        $serviceEnvItems = [];

        if (!empty($service['__resolved_env']) && is_array($service['__resolved_env'])) {
            foreach ($service['__resolved_env'] as $k => $v) {
                if ($k === '') {
                    continue;
                }

                // If single value reused exactly, reuse same variable name (dedupe name and value)
                if (isset($valueKeyMap[$k][$v])) {
                    $resolvedKey = $valueKeyMap[$k][$v];
                } else {
                    if (!array_key_exists($k, $globalEnv)) {
                        $globalEnv[$k] = $v;
                        $resolvedKey = $k;
                    } elseif ($globalEnv[$k] === $v) {
                        $resolvedKey = $k;
                    } else {
                        $suffix = 1;
                        $resolvedKey = $k . '_' . $suffix;
                        while (array_key_exists($resolvedKey, $globalEnv)) {
                            if ($globalEnv[$resolvedKey] === $v) {
                                break;
                            }
                            $suffix++;
                            $resolvedKey = $k . '_' . $suffix;
                        }
                        if (!array_key_exists($resolvedKey, $globalEnv)) {
                            $globalEnv[$resolvedKey] = $v;
                        }
                    }
                    $valueKeyMap[$k][$v] = $resolvedKey;
                }

                $serviceEnvItems[] = sprintf('%s=${%s}', $k, $resolvedKey);
            }
        }

        if (!empty($serviceEnvItems)) {
            $service['environment'] = $serviceEnvItems;
        }

        // Cleanup internal field before rendering
        unset($service['__resolved_env']);
    }
    unset($service);

    // Build per-service lookup: envKey → [serviceName, ...]
    $keyServices = [];
    foreach ($services as $svcName => $svc) {
        if (!empty($svc['environment']) && is_array($svc['environment'])) {
            foreach ($svc['environment'] as $entry) {
                if (preg_match('/^[^=]+=\$\{(.+)\}$/', $entry, $m)) {
                    $keyServices[$m[1]][] = $svcName;
                }
            }
        }
    }

    $lines = [];
    $lastServices = null;
    foreach ($globalEnv as $k => $v) {
        // Add service-group comment when the owning service(s) change
        $owners = $keyServices[$k] ?? [];
        sort($owners);
        if ($owners !== $lastServices) {
            if (!empty($lines)) {
                $lines[] = '';
            }
            $lines[] = '# ' . implode(', ', $owners);
            $lastServices = $owners;
        }
        // Quote values that contain special characters to prevent .env parsing issues
        if (preg_match('/[\s#$"\\\n]/', $v) || $v === '') {
            $escaped = str_replace(['\\', '"', '$', "\n"], ['\\\\', '\\"', '\\$', '\\n'], $v);
            $lines[] = "$k=\"$escaped\"";
        } else {
            $lines[] = "$k=$v";
        }
    }
    return implode("\n", $lines) . (empty($lines) ? '' : "\n");
}

function dockerAddOverrideIcons(array $importedServices): string
{
    $yaml = "services:\n";
    foreach ($importedServices as $serviceName => $service) {
        $labels = ['net.unraid.docker.managed' => 'composeman'];
        $icon = $service['icon'] ?? null;
        $webui = $service['webui'] ?? null;
        if ($icon) {
            $labels['net.unraid.docker.icon'] = $icon;
        }
        if ($webui) {
            $labels['net.unraid.docker.webui'] = $webui;
        }

        $yaml .= "  " . $serviceName . ":\n";
        $yaml .= "    labels:\n";
        foreach ($labels as $key => $val) {
            $yaml .= "      " . $key . ": " . yamlQuoteValue($val) . "\n";
        }
    }
    return $yaml;
}

function pruneOverrideContentServices(string $overrideContent, array $validServices): array
{
    $validMap = [];
    foreach ($validServices as $service) {
        if (is_string($service) && $service !== '') {
            $validMap[$service] = true;
        }
    }

    if (empty($validMap) || $overrideContent === '') {
        return ['content' => $overrideContent, 'removed' => [], 'changed' => false];
    }

    $lineEnding = (strpos($overrideContent, "\r\n") !== false) ? "\r\n" : "\n";
    $normalized = str_replace(["\r\n", "\r"], "\n", $overrideContent);
    $hadTrailingNewline = substr($normalized, -1) === "\n";
    $lines = explode("\n", $normalized);

    $servicesStart = null;
    $servicesEnd = count($lines);

    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        if ($servicesStart === null) {
            if (preg_match('/^services\s*:/', $line)) {
                $servicesStart = $i;
            }
            continue;
        }

        // Detect the end of the services section by finding the next top-level YAML key:
        // - a non-indented, non-comment line that looks like "key:" (optionally followed by a comment)
        // - excluding the "services:" key itself
        if (preg_match('/^[^\s#][^:]*:\s*(?:#.*)?$/', $line) && !preg_match('/^services\s*:/', $line)) {
            $servicesEnd = $i;
            break;
        }
    }

    if ($servicesStart === null || $servicesStart + 1 >= $servicesEnd) {
        return ['content' => $overrideContent, 'removed' => [], 'changed' => false];
    }

    $serviceRanges = [];
    $currentServiceName = null;
    $currentServiceStart = null;

    for ($i = $servicesStart + 1; $i < $servicesEnd; $i++) {
        $line = $lines[$i];
        // Match a single service definition line under `services:` with:
        // - exactly two spaces of indentation
        // - an optional quote character (single or double) around the service name (captured in group 1)
        // - a service name that cannot start with or contain quotes, colon, hash, or whitespace
        // - a trailing colon after the service name, optional whitespace, and an optional inline `# comment`
        if (preg_match('/^ {2}(["\']?)([^"\':#\s][^"\':#]*)\1\s*:\s*(?:#.*)?$/', $line, $matches)) {
            if ($currentServiceName !== null) {
                $serviceRanges[] = [
                    'name' => $currentServiceName,
                    'start' => $currentServiceStart,
                    'end' => $i - 1
                ];
            }
            $currentServiceName = trim($matches[2]);
            $currentServiceStart = $i;
        }
    }

    if ($currentServiceName !== null) {
        $serviceRanges[] = [
            'name' => $currentServiceName,
            'start' => $currentServiceStart,
            'end' => $servicesEnd - 1
        ];
    }

    if (empty($serviceRanges)) {
        return ['content' => $overrideContent, 'removed' => [], 'changed' => false];
    }

    $removedRanges = [];
    $removedServices = [];
    foreach ($serviceRanges as $range) {
        if (!isset($validMap[$range['name']])) {
            $removedRanges[] = $range;
            $removedServices[] = $range['name'];
        }
    }

    if (empty($removedRanges)) {
        return ['content' => $overrideContent, 'removed' => [], 'changed' => false];
    }

    // Safety check: if ALL services in the override would be removed, don't prune.
    // This likely indicates a rename scenario rather than genuine orphans.
    // Wiping to "services: {}" would destroy user data (icons, webui labels, etc).
    if (count($removedRanges) === count($serviceRanges)) {
        return ['content' => $overrideContent, 'removed' => [], 'changed' => false];
    }

    // Only prune specific orphaned services while preserving others
    $removeByLine = [];
    foreach ($removedRanges as $range) {
        for ($lineIndex = $range['start']; $lineIndex <= $range['end']; $lineIndex++) {
            $removeByLine[$lineIndex] = true;
        }
    }

    $newLines = [];
    foreach ($lines as $lineIndex => $line) {
        if (!isset($removeByLine[$lineIndex])) {
            $newLines[] = $line;
        }
    }

    $newContent = implode("\n", $newLines);
    if ($hadTrailingNewline && substr($newContent, -1) !== "\n") {
        $newContent .= "\n";
    }

    if ($lineEnding === "\r\n") {
        $newContent = str_replace("\n", "\r\n", $newContent);
    }

    return ['content' => $newContent, 'removed' => $removedServices, 'changed' => true];
}

/**
 * Data class representing the override file information for a stack, including paths and usage flags.
 * This class encapsulates the logic for determining which override file to use (project vs indirect),
 * handles legacy override migration, and provides utility methods for pruning and migrating override content.
 * 
 * @property string $computedName The computed override filename based on the compose file (e.g. compose.override.yaml)
 * @property string|null $projectOverride The path to the project override file
 * @property string|null $indirectOverride The path to the indirect override file (if applicable)
 * @property bool $useIndirect Whether the indirect override should be used instead of the project override
 * @property bool $mismatchIndirectLegacy Whether there is a legacy-named indirect override that doesn't match the computed name
 * @property string|null $composeFilePath The resolved path to the main compose file for the stack
 * @method static OverrideInfo fromStackInfo(StackInfo $stackInfo) Create an OverrideInfo from a StackInfo instance (preferred)
 * @method static OverrideInfo fromStack(string $composeRoot, string $stack) Create an OverrideInfo by resolving paths from scratch (deprecated)
 * @method string|null getOverridePath() Get the path to the override file that should be used (indirect if present, else project)
 * @method array{changed: bool, removed: string[]} pruneOrphanServices(array $validServices) Prune orphaned services from the override file based on a list of valid service names
 * @method array{migrated: bool, migrations: array<array{oldName: string, newName: string}>} migrateRenamedServices(string $oldComposeContent, string $newComposeContent) Migrate override entries when services are renamed by comparing old and new compose content
 * 
 */
class OverrideInfo
{
    /**
     * @var string Computed override filename (e.g. compose.override.yaml)
     */
    public string $computedName = '';
    /**
     * @var string|null Path to project override file
     */
    public ?string $projectOverride = null;
    /**
     * @var string|null Path to indirect override file
     */
    public ?string $indirectOverride = null;
    /**
     * @var bool True if indirect override should be used
     */
    public bool $useIndirect = false;
    /**
     * @var bool True if indirect contains legacy-named override but not correctly-named one
     */
    public bool $mismatchIndirectLegacy = false;
    /**
     * @var string|null Resolved path to the main compose file
     */
    public ?string $composeFilePath = null;

    private function __construct() {}

    /**
     * Create an OverrideInfo from a StackInfo instance.
     *
     * Primary factory — uses the pre-resolved identity fields from StackInfo
     * (path, composeSource, composeFilePath, isIndirect) so that no duplicate
     * filesystem resolution is needed.
     *
     * @param StackInfo $stackInfo The owning stack (must have identity fields populated)
     * @return OverrideInfo
     */
    public static function fromStackInfo(StackInfo $stackInfo): self
    {
        $indirectPath = $stackInfo->isIndirect ? $stackInfo->composeSource : null;
        return self::resolveOverride($stackInfo->path, $indirectPath, $stackInfo->composeFilePath);
    }

    /**
     * Create an OverrideInfo by resolving paths from scratch.
     *
     * @deprecated Use StackInfo::fromProject() which provides OverrideInfo automatically
     *             via fromStackInfo(), avoiding duplicate filesystem resolution.
     *
     * @param string $composeRoot
     * @param string $stack
     * @return OverrideInfo
     */
    public static function fromStack(string $composeRoot, string $stack): self
    {
        $projectPath = rtrim($composeRoot, '/') . '/' . $stack;
        $indirectPath = is_file("$projectPath/indirect")
            ? trim(file_get_contents("$projectPath/indirect"))
            : null;
        if ($indirectPath === '') {
            $indirectPath = null;
        }

        $composeSource = $indirectPath ?? $projectPath;
        $foundCompose = findComposeFile($composeSource);
        $composeFilePath = $foundCompose !== false ? $foundCompose : null;

        return self::resolveOverride($projectPath, $indirectPath, $composeFilePath);
    }

    /**
     * Core override resolution logic shared by both factories.
     *
     * Computes the override filename from the compose file, resolves project
     * and indirect override paths while preserving legacy filenames as-is,
     * and auto-creates a project override template if needed.
     *
     * @param string      $projectPath     Full path to the stack directory
     * @param string|null $indirectPath     Indirect target directory, or null if not indirect
     * @param string|null $composeFilePath  Resolved main compose file path, or null if none
     * @return OverrideInfo
     */
    private static function resolveOverride(string $projectPath, ?string $indirectPath, ?string $composeFilePath): self
    {
        $info = new self();
        $info->composeFilePath = $composeFilePath;

        $composeBaseName = $composeFilePath !== null ? basename($composeFilePath) : COMPOSE_FILE_NAMES[0];
        $info->computedName = preg_replace('/(\.[^.]+)$/', '.override$1', $composeBaseName);

        $computedProjectOverride = $projectPath . '/' . $info->computedName;
        $computedIndirectOverride = $indirectPath !== null ? ($indirectPath . '/' . $info->computedName) : null;

        $legacyProject = $projectPath . '/docker-compose.override.yml';
        $legacyIndirect = $indirectPath !== null ? ($indirectPath . '/docker-compose.override.yml') : null;

        if (is_file($computedProjectOverride)) {
            $info->projectOverride = $computedProjectOverride;
        } elseif (is_file($legacyProject)) {
            $info->projectOverride = $legacyProject;
        } else {
            $info->projectOverride = $computedProjectOverride;
        }

        if ($computedIndirectOverride !== null && is_file($computedIndirectOverride)) {
            $info->indirectOverride = $computedIndirectOverride;
        } elseif ($legacyIndirect !== null && is_file($legacyIndirect)) {
            $info->indirectOverride = $legacyIndirect;
        } else {
            $info->indirectOverride = $computedIndirectOverride;
        }

        $info->useIndirect = ($info->indirectOverride && is_file($info->indirectOverride));
        $info->mismatchIndirectLegacy = false;

        if (!is_file($info->projectOverride) && !$info->useIndirect) {
            $overrideContent = "# Override file for UI labels (icon, webui, shell)\n";
            $overrideContent .= "# This file is managed by Compose Manager\n";
            $overrideContent .= "services: {}\n";
            file_put_contents($info->projectOverride, $overrideContent);
            composeLogger("Created missing project override template at $info->projectOverride", null, 'user', 'info', 'override');
        }

        return $info;
    }

    /**
     * Get the override file to use (indirect if present, else project override)
     * @return string|null
     */
    public function getOverridePath(): ?string
    {
        return $this->useIndirect ? $this->indirectOverride : $this->projectOverride;
    }

    /**
     * Prune orphaned services from the override file.
     *
     * Removes any services in the override that are not present in the
     * provided list of valid service names. The override file is rewritten
     * in place.
     *
     * @param string[] $validServices Service names that are defined in the main compose file
     * @return array{changed: bool, removed: string[]}
     */
    public function pruneOrphanServices(array $validServices): array
    {
        $overridePath = $this->getOverridePath();
        if ($overridePath === null || $overridePath === '' || !is_file($overridePath)) {
            return ['changed' => false, 'removed' => []];
        }

        if (empty($validServices)) {
            return ['changed' => false, 'removed' => []];
        }

        $overrideContent = file_get_contents($overridePath);
        if ($overrideContent === false || $overrideContent === '') {
            return ['changed' => false, 'removed' => []];
        }

        $result = pruneOverrideContentServices($overrideContent, $validServices);
        if (!($result['changed'] ?? false)) {
            return ['changed' => false, 'removed' => []];
        }

        file_put_contents($overridePath, $result['content']);

        $removedServices = $result['removed'] ?? [];
        if (!empty($removedServices)) {
            composeLogger(
                "Pruned orphaned override services from " . basename($overridePath) . ": " . implode(', ', $removedServices),
                null,
                'user',
                'info',
                'override'
            );
        }

        return ['changed' => true, 'removed' => $removedServices];
    }

    /**
     * Migrate override entries when services are renamed.
     *
     * Compares old and new compose content to detect service renames and
     * automatically updates the override file to match.
     *
     * @param string $oldComposeContent The old compose file content
     * @param string $newComposeContent The new compose file content
     * @return array{migrated: bool, migrations: array<array{from: string, to: string}>}
     */
    public function migrateOnRename(string $oldComposeContent, string $newComposeContent): array
    {
        $result = ['migrated' => false, 'migrations' => []];

        $overridePath = $this->getOverridePath();
        if ($overridePath === null || !is_file($overridePath)) {
            return $result;
        }

        $overrideContent = file_get_contents($overridePath);
        if ($overrideContent === false || trim($overrideContent) === '') {
            return $result;
        }

        // Parse services from old and new compose content using simple YAML parsing
        $oldServices = self::parseServicesFromYaml($oldComposeContent);
        $newServices = self::parseServicesFromYaml($newComposeContent);
        $overrideServices = self::parseServicesFromYaml($overrideContent);

        if (empty($oldServices) || empty($newServices) || empty($overrideServices)) {
            return $result;
        }

        // Find removed services (in old but not in new)
        $removedServices = array_diff(array_keys($oldServices), array_keys($newServices));
        // Find added services (in new but not in old)
        $addedServices = array_diff(array_keys($newServices), array_keys($oldServices));

        if (empty($removedServices) || empty($addedServices)) {
            return $result;
        }

        // Build rename map: match by image, or if same count assume positional rename
        $renameMap = [];

        // First try to match by image
        foreach ($removedServices as $oldName) {
            $oldImage = $oldServices[$oldName]['image'] ?? '';
            if ($oldImage === '') {
                continue;
            }

            foreach ($addedServices as $newName) {
                if (isset($renameMap[$oldName]) || in_array($newName, $renameMap)) {
                    continue;
                }
                $newImage = $newServices[$newName]['image'] ?? '';
                if ($oldImage === $newImage) {
                    $renameMap[$oldName] = $newName;
                    break;
                }
            }
        }

        // If counts match and we couldn't match by image, assume 1:1 positional rename
        if (empty($renameMap) && count($removedServices) === count($addedServices)) {
            $removedList = array_values($removedServices);
            $addedList = array_values($addedServices);
            for ($i = 0; $i < count($removedList); $i++) {
                // Only map if the old name exists in override
                if (isset($overrideServices[$removedList[$i]])) {
                    $renameMap[$removedList[$i]] = $addedList[$i];
                }
            }
        }

        if (empty($renameMap)) {
            return $result;
        }

        // Apply renames to override content
        $newOverrideContent = $overrideContent;
        foreach ($renameMap as $oldName => $newName) {
            // Only migrate if old name exists in override and new name doesn't
            if (!isset($overrideServices[$oldName]) || isset($overrideServices[$newName])) {
                continue;
            }

            // Replace service name in override (careful YAML replacement)
            // Match "  oldname:" at start of line (2 space indent under services:)
            $pattern = '/^(  )' . preg_quote($oldName, '/') . '(\s*:)/m';
            $replacement = '$1' . $newName . '$2';
            $newOverrideContent = preg_replace($pattern, $replacement, $newOverrideContent, 1, $count);

            if ($count > 0) {
                $result['migrations'][] = ['from' => $oldName, 'to' => $newName];
            }
        }

        if (!empty($result['migrations'])) {
            file_put_contents($overridePath, $newOverrideContent);
            $result['migrated'] = true;

            $migrationLog = array_map(fn($m) => "{$m['from']} -> {$m['to']}", $result['migrations']);
            composeLogger(
                "Migrated renamed services: " . implode(', ', $migrationLog),
                null,
                'user',
                'info',
                'override'
            );
        }

        return $result;
    }

    /**
     * Parse service definitions from YAML content.
     *
     * Simple parser that extracts service names and their image values.
     * Public static method for use in tests and other contexts.
     *
     * @param string $yamlContent The YAML content to parse
     * @return array<string, array{image?: string}> Service name => service data
     */
    public static function parseServicesFromYaml(string $yamlContent): array
    {
        $services = [];
        $lines = explode("\n", $yamlContent);
        $inServices = false;
        $currentService = null;

        foreach ($lines as $line) {
            // Skip empty lines and comments
            if (trim($line) === '' || preg_match('/^\s*#/', $line)) {
                continue;
            }

            // Check for services: section
            if (preg_match('/^services\s*:/', $line)) {
                $inServices = true;
                continue;
            }

            // Check for end of services section (another top-level key)
            if ($inServices && preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*\s*:/', $line) && !preg_match('/^services\s*:/', $line)) {
                $inServices = false;
                continue;
            }

            if (!$inServices) {
                continue;
            }

            // Match service name (2 spaces indent)
            if (preg_match('/^  (["\']?)([a-zA-Z0-9_-]+)\1\s*:/', $line, $matches)) {
                $currentService = $matches[2];
                $services[$currentService] = [];
                continue;
            }

            // Match image under service (4+ spaces indent)
            if ($currentService && preg_match('/^    image\s*:\s*["\']?([^"\'#\n]+)["\']?/', $line, $matches)) {
                $services[$currentService]['image'] = trim($matches[1]);
            }
        }

        return $services;
    }
}

/**
 * Normalized container information.
 *
 * Provides a single canonical shape for container data regardless of source
 * (docker inspect response, update-check response, or cached status).
 * Eliminates PascalCase/camelCase drift and container-name aliasing issues.
 * 
 * @property string $name Canonical container name (from Name/Service/container)
 * @property string $id Docker container ID
 * @property string $service Compose service name
 * @property string $image Full image reference (e.g. library/nginx:latest)
 * @property string $state Container state (running/exited/paused/restarting)
 * @property bool $isRunning Whether the container is currently running
 * @property bool $hasUpdate Whether an image update is available
 * @property string $updateStatus Update status text (unknown/up-to-date/update-available)
 * @property string $localSha Local image SHA (truncated)
 * @property string $remoteSha Remote image SHA (truncated)
 * @property bool $isPinned Whether the image is pinned to a specific digest
 * @property string|null $pinnedDigest Pinned digest if isPinned is true
 * @property string $icon Icon URL from Docker label
 * @property string $shell Shell path from Docker label
 * @property string $webUI Resolved WebUI URL
 * @property array $ports Port mappings (e.g. ["192.168.1.50:8080->80/tcp"])
 * @property array $networks Network info [{name, ip, driver}]
 * @property array $volumes Volume mounts [{source, destination, type}]
 * @property string $created ISO datetime when container was created
 * @property string $startedAt ISO datetime when container was started
 * @method static ContainerInfo fromDockerInspect(array $raw) Create a ContainerInfo from a docker inspect + compose ps result array
 * @method static ContainerInfo fromUpdateResponse(array $raw) Create a ContainerInfo from an update-check response element
 * 
 */
class ContainerInfo
{
    /** @var string Canonical container name (from Name/Service/container) */
    public string $name = '';
    /** @var string Docker container ID */
    public string $id = '';
    /** @var string Compose service name */
    public string $service = '';
    /** @var string Full image reference (e.g. library/nginx:latest) */
    public string $image = '';
    /** @var string Container state (running/exited/paused/restarting) */
    public string $state = '';
    /** @var bool Whether the container is currently running */
    public bool $isRunning = false;
    /** @var bool Whether an image update is available */
    public bool $hasUpdate = false;
    /** @var string Update status text (unknown/up-to-date/update-available) */
    public string $updateStatus = 'unknown';
    /** @var string Local image SHA (truncated) */
    public string $localSha = '';
    /** @var string Remote image SHA (truncated) */
    public string $remoteSha = '';
    /** @var bool Whether the image is pinned to a specific digest */
    public bool $isPinned = false;
    /** @var string|null Pinned digest if isPinned is true */
    public ?string $pinnedDigest = null;
    /** @var string Icon URL from Docker label */
    public string $icon = '';
    /** @var string Shell path from Docker label */
    public string $shell = '/bin/bash';
    /** @var string Resolved WebUI URL */
    public string $webUI = '';
    /** @var array Port mappings (e.g. ["192.168.1.1:8080->80/tcp"]) */
    public array $ports = [];
    /** @var array Network info [{name, ip, driver}] */
    public array $networks = [];
    /** @var array Volume mounts [{source, destination, type}] */
    public array $volumes = [];
    /** @var string ISO datetime when container was created */
    public string $created = '';
    /** @var string ISO datetime when container was started */
    public string $startedAt = '';

    private function __construct() {}

    /**
     * Normalize Docker image name for update checking.
     *
     * Strips docker.io/ prefix and @sha256 digest suffix, then applies
     * DockerUtil::ensureImageTag() to match Unraid's update-status keys.
     */
    public static function normalizeImageForUpdateCheck(string $image): string
    {
        if (strpos($image, 'docker.io/') === 0) {
            $image = substr($image, 10);
        }
        if (($digestPos = strpos($image, '@sha256:')) !== false) {
            $image = substr($image, 0, $digestPos);
        }
        return DockerUtil::ensureImageTag($image);
    }

    /**
     * Create a ContainerInfo from a fully-assembled docker inspect + compose ps result.
     *
     * This is the shape built by the getStackContainers action in exec.php.
     * Accepts either PascalCase or camelCase keys and normalizes them.
     *
     * @param array $raw Associative array with container data
     * @return ContainerInfo
     */
    public static function fromDockerInspect(array $raw): self
    {
        $info = new self();
        $info->name = $raw['Name'] ?? $raw['name'] ?? '';
        $info->id = $raw['ID'] ?? $raw['Id'] ?? $raw['id'] ?? '';
        $info->service = $raw['Service'] ?? $raw['service'] ?? '';
        $info->image = $raw['Image'] ?? $raw['image'] ?? '';
        $info->state = strtolower($raw['State'] ?? $raw['state'] ?? '');
        $info->isRunning = ($info->state === 'running');
        $info->icon = $raw['Icon'] ?? $raw['icon'] ?? '';
        $info->shell = $raw['Shell'] ?? $raw['shell'] ?? '/bin/bash';
        $info->webUI = $raw['WebUI'] ?? $raw['webUI'] ?? $raw['webui'] ?? '';
        $info->ports = $raw['Ports'] ?? $raw['ports'] ?? [];
        $info->networks = $raw['Networks'] ?? $raw['networks'] ?? [];
        $info->volumes = $raw['Volumes'] ?? $raw['volumes'] ?? [];
        $info->created = $raw['Created'] ?? $raw['created'] ?? '';
        $info->startedAt = $raw['StartedAt'] ?? $raw['startedAt'] ?? '';

        // Normalize update status (accept PascalCase or camelCase)
        $info->updateStatus = $raw['updateStatus'] ?? $raw['UpdateStatus'] ?? $raw['status'] ?? 'unknown';
        $info->localSha = $raw['localSha'] ?? $raw['LocalSha'] ?? '';
        $info->remoteSha = $raw['remoteSha'] ?? $raw['RemoteSha'] ?? '';
        $info->hasUpdate = $raw['hasUpdate']
            ?? ($info->updateStatus === 'update-available');

        // Derive pinned status from @sha256: in image reference
        $info->derivePinned();

        return $info;
    }

    /**
     * Create a ContainerInfo from an update-check response element.
     *
     * This is the per-container shape returned by checkStackUpdates (keys:
     * container, image, hasUpdate, status, localSha, remoteSha).
     *
     * @param array $raw Associative array from update check
     * @return ContainerInfo
     */
    public static function fromUpdateResponse(array $raw): self
    {
        $info = new self();
        $info->name = $raw['container'] ?? $raw['name'] ?? $raw['Name'] ?? '';
        $info->id = $raw['ID'] ?? $raw['Id'] ?? $raw['id'] ?? '';
        $info->service = $raw['service'] ?? $raw['Service'] ?? '';
        $info->image = $raw['image'] ?? $raw['Image'] ?? '';
        $info->hasUpdate = $raw['hasUpdate'] ?? false;
        $info->updateStatus = $raw['status'] ?? $raw['updateStatus'] ?? 'unknown';
        $info->localSha = $raw['localSha'] ?? '';
        $info->remoteSha = $raw['remoteSha'] ?? '';

        $info->derivePinned();

        return $info;
    }

    /**
     * Merge update-check fields from another ContainerInfo without
     * overwriting identity or runtime state fields.
     *
     * @param ContainerInfo $update The newer update data to merge in
     */
    public function mergeUpdateStatus(ContainerInfo $update): void
    {
        if ($update->hasUpdate) {
            $this->hasUpdate = true;
        }
        if ($update->updateStatus !== '' && $update->updateStatus !== 'unknown') {
            $this->updateStatus = $update->updateStatus;
        }
        if ($update->localSha !== '') {
            $this->localSha = $update->localSha;
        }
        if ($update->remoteSha !== '') {
            $this->remoteSha = $update->remoteSha;
        }
        if ($update->isPinned) {
            $this->isPinned = $update->isPinned;
            $this->pinnedDigest = $update->pinnedDigest;
        }
    }

    /**
     * Serialize to a consistently camelCase associative array for JSON responses.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'id' => $this->id,
            'service' => $this->service,
            'image' => $this->image,
            'state' => $this->state,
            'isRunning' => $this->isRunning,
            'hasUpdate' => $this->hasUpdate,
            'updateStatus' => $this->updateStatus,
            'localSha' => $this->localSha,
            'remoteSha' => $this->remoteSha,
            'isPinned' => $this->isPinned,
            'pinnedDigest' => $this->pinnedDigest,
            'icon' => $this->icon,
            'shell' => $this->shell,
            'webUI' => $this->webUI,
            'ports' => $this->ports,
            'networks' => $this->networks,
            'volumes' => $this->volumes,
            'created' => $this->created,
            'startedAt' => $this->startedAt,
        ];
    }

    /**
     * Serialize only the update-related fields (for update-check responses).
     *
     * @return array
     */
    public function toUpdateArray(): array
    {
        return [
            'name' => $this->name,
            'image' => $this->image,
            'hasUpdate' => $this->hasUpdate,
            'updateStatus' => $this->updateStatus,
            'localSha' => $this->localSha,
            'remoteSha' => $this->remoteSha,
            'isPinned' => $this->isPinned,
            'pinnedDigest' => $this->pinnedDigest,
        ];
    }

    /**
     * Create a ContainerInfo from a `docker ps --format '{{json .}}'` row.
     *
     * This is a lightweight factory that populates only the fields available
     * from a docker ps row (no inspect, no ports/networks/volumes detail).
     *
     * @param array $raw Decoded JSON row from docker ps
     * @return ContainerInfo
     */
    public static function fromDockerPs(array $raw): self
    {
        $info = new self();
        $info->name = ltrim(trim($raw['Names'] ?? $raw['Name'] ?? $raw['name'] ?? ''), '/');
        $info->id = $raw['ID'] ?? $raw['Id'] ?? $raw['id'] ?? '';
        $info->image = $raw['Image'] ?? $raw['image'] ?? '';
        $info->state = strtolower($raw['State'] ?? $raw['state'] ?? '');
        $info->isRunning = ($info->state === 'running');
        $info->derivePinned();
        return $info;
    }

    /**
     * Derive isPinned and pinnedDigest from the image reference.
     */
    private function derivePinned(): void
    {
        if ($this->image !== '' && strpos($this->image, '@sha256:') !== false) {
            $this->isPinned = true;
            $parts = explode('@sha256:', $this->image, 2);
            $this->pinnedDigest = $parts[1] ?? null;
        }
    }
}

/**
 * Centralized stack identity and metadata.
 *
 * Resolves and caches the canonical identity for a compose stack: directory
 * name (project), sanitized Docker project name, compose file path, indirect
 * target, override info, and provides lazy access to metadata files (name,
 * description, envpath, icon_url, webui_url, etc.).
 *
 * Construction is intentionally eager for identity fields and override
 * resolution (preserving current side-effect behavior). Metadata files are
 * loaded lazily on first access.
 * 
 * @property string $projectFolder Compose project folder basename (also project string)
 * @property string $displayName Display name (from ./name)
 * @property string $path Full path to the stack directory ($composeRoot/$project)
 * @property string|null $indirectPath Indirect path or null if not indirect
 * @property string $composeSource Resolved compose source directory, direct or indirect
 * @property string|null $composeFilePath Full path to the main compose file, direct or indirect
 * @property bool $isIndirect Whether this stack uses an indirect compose path
 * @property OverrideInfo $overrideInfo Resolved override info (eager)
 * @method static StackInfo fromProject(string $composeRoot, string $project) Create a StackInfo for a project directory under the compose root, with caching
 * @method static void clearCache(?string $key = null) Clear the static instance cache (all or specific key) - primarily for testing
 * @method string|null getMetadata(string $field) Get the value of a metadata file (e.g. name, description, envpath) with lazy loading and caching
 * 
 */
class StackInfo
{
    /**
     * Marker for the services cache semantics.
     *
     * `all_profiles_v1` means the cached `services` file was generated with
     * `docker compose --profile '*' config --services`.
     */
    private const SERVICES_CACHE_MODE = 'all_profiles_v1';

    /** @var string Compose project folder basename (actual directory name on disk) */
    public string $projectFolder;
    /** @var string Sanitized Docker Compose project name (always lowercase, valid for -p flag) */
    public string $projectName;
    /** @var string Display name (from ./name) */
    public string $displayName;
    /** @var string Full path to the stack directory ($composeRoot/$project) */
    public string $path;
    /** @var string|null Indirect path or null if not indirect */
    public ?string $indirectPath;
    /** @var string Resolved compose source directory, direct or indirect */
    public string $composeSource;
    /** @var string Full path to the main compose file, direct or indirect */
    public ?string $composeFilePath;
    /** @var bool Whether this stack uses an indirect compose path */
    public bool $isIndirect;
    /** @var string|null Path from a renamed indirect.invalid file, if present (needs user fix) */
    public ?string $invalidIndirectPath;
    /** @var OverrideInfo Resolved override info (eager) */
    public OverrideInfo $overrideInfo;

    /** @var string Compose root directory */
    private string $composeRoot;

    /** @var array Lazy-loaded metadata cache (field => value|null, unset = not loaded) */
    private array $metadataCache = [];

    /** @var array<string, StackInfo> Static instance cache keyed by composeRoot/project */
    private static array $instances = [];

    /** @var array[]|null Per-instance lazy cache of docker compose ps rows (null = not yet fetched) */
    private ?array $cachedContainerList = null;

    /** @var array|null Cached aggregate container state counts */
    private ?array $cachedContainerCounts = null;

    /**
     * Pre-populate the container list cache for this stack.
     *
     * Used by allFromRoot() to inject batch-fetched container data so that
     * getContainerList() returns immediately without a per-stack docker call.
     *
     * @param array[] $containers Raw rows (docker ps JSON format)
     */
    public function setContainerList(array $containers): void
    {
        $this->cachedContainerList = $containers;
        $this->cachedContainerCounts = null; // invalidate derived cache
    }

    /**
     * @param string $composeRoot Compose root directory
     * @param string $projectFolder Directory basename of the stack
     */
    private function __construct(string $composeRoot, string $projectFolder)
    {
        // Set the things we know right away: compose root and project folder.
        $this->composeRoot = rtrim($composeRoot, '/');
        $this->projectFolder = $projectFolder;
        $this->setPath();

        if (!is_dir($this->path)) {
            throw new \RuntimeException("Project path is not a directory: $this->path");
        }

        // projectFolder is the directory name as-is; projectName is the sanitized
        // version used for the Docker Compose -p flag. We never rename the folder.
        $this->projectName = self::sanitizeProjectString($this->projectFolder);

        // Resolve display name from metadata (or default to folder name).
        $this->displayName = $this->getDisplayName();

        // Resolve indirect path and compose source (indirect if present, else direct)
        $this->isIndirect = $this->isIndirect();
        $this->indirectPath = $this->readMetadata('indirect');
        $this->invalidIndirectPath = $this->readInvalidIndirect();
        if ($this->invalidIndirectPath === null && !$this->isIndirect && $this->indirectPath !== null && $this->indirectPath !== '') {
            // Invalid indirect path still present in ./indirect (non-destructive handling).
            $this->invalidIndirectPath = $this->indirectPath;
        }
        $this->composeSource = $this->isIndirect ? $this->indirectPath : $this->path;

        // Resolve compose file
        $this->composeFilePath = self::getComposeFilePath($this->composeSource);
        if ($this->composeFilePath === null) {
            if ($this->invalidIndirectPath !== null) {
                // Stack has a broken indirect reference — allow degraded construction
                // so the user can fix it in the Settings editor.
                composeLogger("Stack $this->projectFolder has an invalid indirect path; loading in degraded mode", null, 'user', 'warning', 'stack');
                $this->overrideInfo = OverrideInfo::fromStackInfo($this);
                return;
            }
            throw new \RuntimeException("Not a valid compose stack: no compose file found at $this->composeSource");
        }

        // Eagerly resolve override info using pre-resolved identity (no duplicate I/O)
        $this->overrideInfo = OverrideInfo::fromStackInfo($this);
    }

    /**
     * Create a StackInfo for a project directory under the compose root.
     *
     * Returns a cached instance if one already exists for this composeRoot/project
     * combination, avoiding redundant filesystem and override resolution work.
     *
     * @param string $composeRoot The compose projects root directory
     * @param string $project Directory basename of the stack
     * @return StackInfo
     */
    public static function fromProject(string $composeRoot, string $project): self
    {
        $key = rtrim($composeRoot, '/') . '/' . $project;
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($composeRoot, $project);
        }
        return self::$instances[$key];
    }

    /**
     * Clear the static instance cache.
     *
     * Primarily useful in tests to ensure a clean state between test cases.
     *
     * @param string|null $key Optional specific key (composeRoot/project) to clear; null clears all.
     */
    public static function clearCache(?string $key = null): void
    {
        if ($key !== null) {
            unset(self::$instances[$key]);
        } else {
            self::$instances = [];
        }
    }

    /**
     * Find the compose file in a directory using Docker Compose spec priority.
     *
     * Checks for compose.yaml, compose.yml, docker-compose.yaml, docker-compose.yml
     * in that order and returns the first one found.
     *
     * @return string|null Full path to the compose file if found, or null if none found
     */
    private static function getComposeFilePath($path): string|null
    {
        $composeFilePath = null;
        foreach (COMPOSE_FILE_NAMES as $name) {
            if (is_file("$path/$name")) {
                $composeFilePath = "$path/$name";
                break;
            }
        }
        return $composeFilePath;
    }


    /**
     * Determine if the stack uses an indirect compose path by checking for a valid 'indirect' file.
     * Validate file path to prevent security issues (e.g. directory traversal, invalid paths) and ensure it points to an existing directory.
     * 
     * @return bool True if the stack is indirect, false otherwise
     */
    private function isIndirect(): bool
    {
        $indirectPath = $this->readMetadata('indirect');
        if ($indirectPath !== null) {
            if (
                $indirectPath === ''
                || Path::hasNewline($indirectPath)
                || !Path::hasSeparator($indirectPath)
                || Path::hasWindowsStylePath($indirectPath)
                || Path::hasTraversal($indirectPath)
            ) {
                // Path is structurally invalid — ignore it and keep stack local without mutating files.
                composeLogger("Ignoring structurally invalid indirect path at $this->path/indirect: " . sanitizeLogText($indirectPath), null, 'user', 'warning', 'stack');
                return false;
            }
            if (!is_dir($indirectPath)) {
                // Directory doesn't exist — may be a temporarily unmounted share (NFS, etc.).
                // Ignore it and keep stack local without mutating files.
                composeLogger("Ignoring unavailable indirect path (may be temporarily unavailable): " . sanitizeLogText($indirectPath), null, 'user', 'warning', 'stack');
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Read the indirect.invalid file if present.
     *
     * Legacy compatibility: older versions wrote this file when an indirect
     * path was invalid. Newer versions keep ./indirect unchanged and use
     * non-destructive warning behavior, but still read indirect.invalid.
     *
     * @return string|null The path stored in indirect.invalid, or null if not present
     */
    private function readInvalidIndirect(): ?string
    {
        $invalidFile = "$this->path/indirect.invalid";
        if (is_file($invalidFile)) {
            $content = @file_get_contents($invalidFile);
            return $content !== false ? trim($content) : null;
        }
        return null;
    }

    /**
     * Canonical sanitizer for Display Name to Docker Compose project name.
     *
     * Docker Compose project names must match: [a-z0-9][a-z0-9_-]*
     *
     * @param string $rawProjectString Directory basename of the stack
     * @return string Canonical compose project name
     */
    public static function sanitizeProjectString(string $rawProjectString): string
    {

        // Trim and lowercase the input to start normalization.
        $sanitizedProjectString = strtolower(trim($rawProjectString));

        // Replace unsupported characters with underscore.
        $sanitizedProjectString = preg_replace('/[^a-z0-9_-]/', '_', $sanitizedProjectString) ?? '';

        // Collapse multiple underscores into one.
        $sanitizedProjectString = preg_replace('/_+/', '_', $sanitizedProjectString);

        // Collapse multiple dashes into one.
        $sanitizedProjectString = preg_replace('/-+/', '-', $sanitizedProjectString);

        // Remove leading or trailing underscores or dashes.
        $sanitizedProjectString = trim($sanitizedProjectString, '_-');

        // If the result is empty, default to 'compose' to ensure a valid project name.
        if ($sanitizedProjectString === '') {
            composeLogger("Sanitized project string is empty after processing; defaulting to 'compose'", ['input' => $rawProjectString], 'user', 'warning', 'stack');
            return 'compose';
        }

        return $sanitizedProjectString;
    }

    // ---------------------------------------------------------------
    // Lazy metadata getters — read from file on first access, cache
    // ---------------------------------------------------------------

    /**
     * Get the display name (from `name` file, falls back to project folder).
     * @return string
     */
    public function getDisplayName(): string
    {
        $displayName = $this->readMetadata('name') ?? null;
        if ($displayName === null || $displayName === '') {
            // If no display name is set, initialize it from the project folder name.
            $displayName = $this->projectFolder;
            $this->writeMetadata('name', $displayName);
            composeLogger("Initialized missing display name from project folder: '$displayName'", ['project' => $this->projectFolder, 'displayName' => $displayName], 'user', 'warning', 'stack');
        }
        $this->displayName = $displayName;
        return $this->displayName;
    }

    /**
     * Alias for getDisplayName() — backward compatibility.
     * @return string
     */
    public function getName(): string
    {
        return $this->displayName;
    }

    /**
     * Get the stack description.
     * @return string
     */
    public function getDescription(): string
    {
        return $this->readMetadata('description') ?? '';
    }

    /**
     * Get the custom env file path (from `envpath` file).
     * @return string|null
     */
    public function getEnvFilePath(): ?string
    {
        $val = $this->readMetadata('envpath');
        return ($val !== null && $val !== '') ? $val : null;
    }

    /**
     * Resolve the effective env file path for this stack.
     *
     * Resolution order:
     * 1) `envpath` metadata (explicit stack setting) when it points to a
     *    readable file
     * 2) Local `.env` in compose source (direct or indirect)
     *
     * If `envpath` exists but is empty, it is treated as unset.
     * If `envpath` exists but points to a missing file, it is treated as
     * invalid and local `.env` is used as fallback when available.
     *
     * @return string|null
     */
    public function getEffectiveEnvFilePath(): ?string
    {
        $rawEnvPath = $this->readMetadata('envpath');
        if ($rawEnvPath !== null) {
            $rawEnvPath = trim($rawEnvPath);
            if ($rawEnvPath !== '') {
                if (is_file($rawEnvPath)) {
                    return $rawEnvPath;
                }
                composeLogger("Ignoring invalid envpath for stack $this->projectFolder: file not found", ['envpath' => $rawEnvPath], 'user', 'warning', 'stack');
            }
        }

        $defaultEnvPath = $this->composeSource . '/.env';
        return is_file($defaultEnvPath) ? $defaultEnvPath : null;
    }

    /**
     * Parse COMPOSE_FILE values from the configured env file.
     *
     * This is used to support projects that declare additional compose
     * files via COMPOSE_FILE in the env file loaded with the stack.
     *
     * @return string[]
     */
    private function getAdditionalComposeFilesFromEnv(): array
    {
        $envFilePath = $this->getEffectiveEnvFilePath();
        if ($envFilePath === null) {
            return [];
        }
        if (!is_file($envFilePath)) {
            return [];
        }

        $content = @file_get_contents($envFilePath);
        if ($content === false) {
            return [];
        }

        $files = [];
        $envDir = dirname($envFilePath);
        foreach (preg_split('/\R/', $content) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key !== 'COMPOSE_FILE') {
                continue;
            }
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            foreach (self::splitComposeFileValue($value) as $entry) {
                $entry = Strings::stripQuotes(trim($entry));
                if ($entry === '') {
                    continue;
                }
                if (Path::isAbsolutePath($entry)) {
                    $files[] = $entry;
                } else {
                    $files[] = $envDir . '/' . $entry;
                }
            }
            break;
        }

        return array_values(array_unique($files));
    }

    /**
     * Normalize compose file paths for deduplication.
     *
     * @return string
     */
    private function normalizeComposeFilePath(string $path): string
    {
        // Normalize equivalent paths to avoid duplicate compose file entries
        // when the same file is referenced via slightly different paths.
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        return rtrim($path, '/\\');
    }

    /**
     * Split a COMPOSE_FILE env value into individual file paths.
     *
     * This preserves quoted segments so values like
     * `"compose.debug.yaml":compose.extra.yaml` are parsed correctly.
     *
     * @param string $value
     * @return string[]
     */
    private static function splitComposeFileValue(string $value): array
    {
        $entries = [];
        $current = '';
        $quote = null;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($quote === null && ($char === '"' || $char === "'")) {
                $quote = $char;
                $current .= $char;
                continue;
            }
            if ($quote !== null && $char === $quote) {
                $quote = null;
                $current .= $char;
                continue;
            }
            if ($quote === null && $char === PATH_SEPARATOR) {
                $entries[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }

        $entries[] = $current;
        return $entries;
    }

    private function getComposeFilePaths(): array
    {
        $paths = [];
        $mainComposeFile = $this->composeFilePath ?? ($this->composeSource . '/' . COMPOSE_FILE_NAMES[0]);
        $paths[] = $mainComposeFile;

        $overridePath = $this->getOverridePath();
        if ($overridePath !== null) {
            $paths[] = $overridePath;
        }

        foreach ($this->getAdditionalComposeFilesFromEnv() as $extraFile) {
            $paths[] = $extraFile;
        }

        $normalized = [];
        $unique = [];
        foreach ($paths as $path) {
            $key = $this->normalizeComposeFilePath($path);
            if ($key !== '' && !isset($unique[$key])) {
                $unique[$key] = true;
                $normalized[] = $path;
            }
        }

        return $normalized;
    }

    /**
     * Build the `-f` flags for all compose file paths used by this stack.
     *
     * @return string
     */
    private function buildComposeFileFlags(): string
    {
        $flags = [];
        foreach ($this->getComposeFilePaths() as $filePath) {
            if (is_file($filePath)) {
                $flags[] = '-f ' . escapeshellarg($filePath);
            }
        }
        return implode(' ', $flags);
    }

    /**
     * Get the env-file argument for the current stack, if configured.
     *
     * @return string
     */
    private function buildEnvFileFlag(): string
    {
        $envPath = $this->getEffectiveEnvFilePath();
        if ($envPath !== null && is_file($envPath)) {
            return '--env-file ' . escapeshellarg($envPath);
        }
        return '';
    }

    /**
     * Get the icon URL (from `icon_url` file), validated.
     * @return string|null
     */
    public function getIconUrl(): ?string
    {
        $url = $this->readMetadata('icon_url');
        if ($url === null) {
            return null;
        }
        // Accept http(s) URLs
        if (
            filter_var($url, FILTER_VALIDATE_URL)
            && (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0)
        ) {
            return $url;
        }
        // Accept image data URLs used by custom inline SVG/PNG icons
        if (preg_match('/^data:image\/[a-z0-9.+-]+(?:;[a-z0-9.+-]+=[^;,]+)*(?:;base64)?,.+$/i', $url) === 1) {
            return $url;
        }
        // Accept local server paths under allowed prefixes
        if (
            strpos($url, '/') === 0
            && strpos($url, '..') === false
            && (strpos($url, '/mnt/') === 0 || strpos($url, '/boot/config/plugins/compose.manager/projects/') === 0)
        ) {
            return $url;
        }
        return null;
    }

    /**
     * Get the stack-level WebUI URL (from `webui_url` file), validated.
     * @return string|null
     */
    public function getWebUIUrl(): ?string
    {
        $url = $this->readMetadata('webui_url');
        if ($url !== null && isValidWebuiUrl($url)) {
            return $url;
        }
        return null;
    }

    /**
     * Get default profiles (from `default_profile` file), comma-split.
     * @return string[]
     */
    public function getDefaultProfiles(): array
    {
        $raw = $this->readMetadata('default_profile');
        if ($raw === null || $raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Get the profiles that were active when the stack was last started/updated.
     *
     * Reads the `running_profiles` metadata file written by compose.sh on
     * successful `up` or `update` operations.
     *
     * @return string[]
     */
    public function getRunningProfiles(): array
    {
        $raw = $this->readMetadata('running_profiles');
        if ($raw === null || $raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Get autostart flag (from `autostart` file).
     * @return bool
     */
    public function getAutostart(): bool
    {
        $val = $this->readMetadata('autostart');
        return ($val !== null && strpos($val, 'true') !== false);
    }

    /**
     * Get the started_at timestamp (from `started_at` file).
     * @return string|null
     */
    public function getStartedAt(): ?string
    {
        $val = $this->readMetadata('started_at');
        return ($val !== null && $val !== '') ? $val : null;
    }

    /**
     * Get available profiles (from `profiles` JSON metadata cache).
     *
     * Returns cached profiles if the metadata file is newer than the compose
     * file.  When the cache is missing or stale, delegates to
     * `docker compose config --profiles` and writes the result back.
     *
     * @return string[]
     */
    public function getProfiles(): array
    {
        $raw = $this->readMetadata('profiles');
        if ($raw !== null && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !$this->isProfilesCacheStale()) {
                return $decoded;
            }
        }

        return $this->extractProfilesFromCompose();
    }

    /**
     * Check whether the cached profiles metadata file is stale.
     *
     * Compares filemtime of the profiles metadata file against compose inputs
     * that can affect profile resolution (compose, override, env-file).
     * Returns true when any input is newer than the cache or when the cache
     * file does not exist.
     *
     * @return bool
     */
    private function isProfilesCacheStale(): bool
    {
        $profilesFile = $this->path . '/profiles';
        if (!is_file($profilesFile)) {
            return true;
        }

        $cacheMtime = filemtime($profilesFile);

        if ($this->composeFilePath === null || !is_file($this->composeFilePath)) {
            return false;
        }

        if (filemtime($this->composeFilePath) > $cacheMtime) {
            return true;
        }

        $overridePath = $this->getOverridePath();
        if ($overridePath !== null && is_file($overridePath) && filemtime($overridePath) > $cacheMtime) {
            return true;
        }

        $envFilePath = $this->getEffectiveEnvFilePath();
        if ($envFilePath !== null && is_file($envFilePath) && filemtime($envFilePath) > $cacheMtime) {
            return true;
        }

        return false;
    }

    /**
     * Extract available profiles via `docker compose config --profiles`.
     *
     * Mirrors the approach used by {@see getDefinedServices()}.  On success
     * the result is written back to the profiles metadata file so subsequent
     * reads hit the fast-path cache.
     *
     * @return string[]
     */
    private function extractProfilesFromCompose(): array
    {
        if ($this->composeFilePath === null || !is_file($this->composeFilePath)) {
            return [];
        }

        $cmd = "docker compose " . $this->buildComposeFileFlags();
        $envFlag = $this->buildEnvFileFlag();
        if ($envFlag !== '') {
            $cmd .= " " . $envFlag;
        }
        $cmd .= " config --profiles 2>/dev/null";

        $output = shell_exec($cmd);
        if (!is_string($output) || trim($output) === '') {
            return [];
        }

        $profiles = array_values(array_filter(
            array_map('trim', explode("\n", trim($output))),
            fn(string $p): bool => $p !== ''
        ));

        // Write-through: persist so future reads hit the cache.
        $this->writeMetadata('profiles', json_encode($profiles));

        return $profiles;
    }

    // ---------------------------------------------------------------
    // Derived helpers
    // ---------------------------------------------------------------

    /**
     * Get the OverrideInfo for this stack.
     * @return OverrideInfo
     */
    public function getOverrideInfo(): OverrideInfo
    {
        return $this->overrideInfo;
    }

    /**
     * Get the effective override file path (delegates to OverrideInfo).
     * @return string|null
     */
    public function getOverridePath(): ?string
    {
        return $this->overrideInfo->getOverridePath();
    }

    /**
     * Get the list of services defined in the main compose file.
     *
     * Returns cached services from the `services` metadata file when the
     * compose (and override) files have not changed since the cache was
     * written.  On a cache miss delegates to `docker compose config
     * --services` and persists the result for future calls.
     *
     * @return string[] List of service names
     */
    public function getDefinedServices(): array
    {
        if ($this->composeFilePath === null || !is_file($this->composeFilePath)) {
            return [];
        }

        // Persistent file cache – use when fresh
        $raw = $this->readMetadata('services');
        if ($raw !== null && $raw !== '' && !$this->isServicesCacheStale()) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $this->extractServicesFromCompose();
    }

    /**
     * Check whether the cached `services` metadata file is stale.
     *
     * Compares filemtime of the cache file against the compose file (and
     * override file when present).  Returns true when either source file
     * is newer than the cache or when the cache file does not exist.
     *
     * @return bool
     */
    private function isServicesCacheStale(): bool
    {
        $cacheFile = $this->path . '/services';
        if (!is_file($cacheFile)) {
            return true;
        }

        // Invalidate legacy cache entries created before all-profile service
        // extraction was introduced.
        $modeRaw = $this->readMetadata('services_cache_mode');
        if (!is_string($modeRaw) || trim($modeRaw) !== self::SERVICES_CACHE_MODE) {
            return true;
        }

        $cacheMtime = filemtime($cacheFile);

        if ($this->composeFilePath !== null && is_file($this->composeFilePath)) {
            if (filemtime($this->composeFilePath) > $cacheMtime) {
                return true;
            }
        }

        $overridePath = $this->getOverridePath();
        if ($overridePath !== null && is_file($overridePath)) {
            if (filemtime($overridePath) > $cacheMtime) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run `docker compose config --services` and persist the result.
     *
     * On success the service list is written to the `services` metadata
     * file so subsequent calls hit the fast-path file cache.
     *
     * @return string[]
     */
    private function extractServicesFromCompose(): array
    {
        if ($this->composeFilePath === null || !is_file($this->composeFilePath)) {
            return [];
        }

        $cmd = "docker compose " . $this->buildComposeFileFlags();
        $envFlag = $this->buildEnvFileFlag();
        if ($envFlag !== '') {
            $cmd .= " " . $envFlag;
        }
        // Include all profile-scoped services so stack totals reflect the
        // full compose definition, not only default-profile services.
        $cmd .= " --profile '*' config --services 2>/dev/null";

        $output = shell_exec($cmd);
        if (!is_string($output) || trim($output) === '') {
            return [];
        }

        $services = array_values(array_filter(
            array_map('trim', explode("\n", trim($output))),
            fn(string $s): bool => $s !== ''
        ));

        // Write-through: persist so future reads hit the cache.
        $this->writeMetadata('services', json_encode($services));
        $this->writeMetadata('services_cache_mode', self::SERVICES_CACHE_MODE);

        return $services;
    }

    /**
     * Prune orphaned services from the override file.
     *
     * Resolves valid services from the main compose file (without override),
     * then delegates to OverrideInfo::pruneOrphanServices().
     *
     * @return array{changed: bool, removed: string[]}
     */
    public function pruneOrphanOverrideServices(): array
    {
        $validServices = $this->getMainFileServices();
        if (empty($validServices)) {
            return ['changed' => false, 'removed' => []];
        }
        return $this->overrideInfo->pruneOrphanServices($validServices);
    }

    /**
     * Get the list of services defined in the main compose file only (without override).
     *
     * Used internally by pruneOrphanOverrideServices() to determine which services
     * are valid. Excludes the override file so orphaned override services are not
     * counted as valid, but enables all profiles so profile-tagged services remain
     * valid targets for Unraid label metadata stored in the override file.
     *
     * External callers should typically use getDefinedServices() which includes
     * the override file for a complete picture.
     *
     * @return string[] List of service names
     */
    private function getMainFileServices(): array
    {
        if ($this->composeFilePath === null || !is_file($this->composeFilePath)) {
            return [];
        }

        $cmd = "docker compose " . $this->buildComposeFileFlags();
        $envFlag = $this->buildEnvFileFlag();
        if ($envFlag !== '') {
            $cmd .= " " . $envFlag;
        }
        $cmd .= " --profile '*' config --services 2>/dev/null";

        $output = shell_exec($cmd);
        if (!is_string($output) || trim($output) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", trim($output))), function ($service) {
            return $service !== '';
        }));
    }

    /**
     * Build the common compose CLI arguments for this stack.
     *
     * Returns the project name, file flags, env-file flag, and additional
     * metadata needed by callers.
     *
     * @return array{
     *   projectName: string,
     *   files: string,
     *   envFile: string,
     *   envFilePath?: string,
     *   filePaths: string[]
     * }
     */
    public function buildComposeArgs(): array
    {
        $files = $this->buildComposeFileFlags();
        $envFile = $this->buildEnvFileFlag();
        $envPath = $this->getEffectiveEnvFilePath();

        $result = [
            'projectName' => $this->projectName,
            'files' => $files,
            'envFile' => $envFile,
        ];
        if ($envPath !== null) {
            $result['envFilePath'] = $envPath;
        }
        $result['filePaths'] = $this->getComposeFilePaths();
        $result['fileList'] = $this->buildComposeFileList();

        return $result;
    }

    /**
     * Build a PATH_SEPARATOR-delimited list of compose file paths for shell environment use.
     *
     * @return string
     */
    public function buildComposeFileList(): string
    {
        $filePaths = $this->getComposeFilePaths();
        $filePaths = array_filter($filePaths, static fn(string $value): bool => $value !== '');
        $filePaths = array_map('trim', $filePaths);

        return implode(PATH_SEPARATOR, $filePaths);
    }

    /**
     * Check if any service in the stack has a build configuration.
     *
     * Returns a cached result from the `has_build` metadata file when the
     * compose (and override) files have not changed since the cache was
     * written.  On a cache miss the method falls back to
     * `docker compose config` and persists the result for future calls.
     *
     * @return bool True if any service has a build configuration
     */
    public function hasBuildConfig(): bool
    {
        // In-memory cache (same request)
        if (array_key_exists('has_build', $this->metadataCache)) {
            return (bool) $this->metadataCache['has_build'];
        }

        // Persistent file cache – use when fresh
        $raw = $this->readMetadata('has_build');
        if ($raw !== null && $raw !== '' && !$this->isHasBuildCacheStale()) {
            $val = ($raw === '1');
            $this->metadataCache['has_build'] = $val;
            return $val;
        }

        return $this->extractHasBuildFromCompose();
    }

    /**
     * Check whether the cached `has_build` metadata file is stale.
     *
     * Compares filemtime of the cache file against the compose file (and
     * override file when present).  Returns true when either source file
     * is newer than the cache or when the cache file does not exist.
     *
     * @return bool
     */
    private function isHasBuildCacheStale(): bool
    {
        $cacheFile = $this->path . '/has_build';
        if (!is_file($cacheFile)) {
            return true;
        }
        $cacheMtime = filemtime($cacheFile);

        if ($this->composeFilePath !== null && is_file($this->composeFilePath)) {
            if (filemtime($this->composeFilePath) > $cacheMtime) {
                return true;
            }
        }

        $overridePath = $this->getOverridePath();
        if ($overridePath !== null && is_file($overridePath)) {
            if (filemtime($overridePath) > $cacheMtime) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run `docker compose config` and check for build directives.
     *
     * On success the result is written to the `has_build` metadata file so
     * subsequent calls hit the fast-path file cache.
     *
     * @return bool
     */
    private function extractHasBuildFromCompose(): bool
    {
        if ($this->composeFilePath === null || !is_file($this->composeFilePath)) {
            $this->metadataCache['has_build'] = false;
            return false;
        }

        $cmd = "docker compose " . $this->buildComposeFileFlags();
        $envFlag = $this->buildEnvFileFlag();
        if ($envFlag !== '') {
            $cmd .= " " . $envFlag;
        }
        $cmd .= " config 2>/dev/null";

        $output = shell_exec($cmd);
        if (!is_string($output) || trim($output) === '') {
            $this->metadataCache['has_build'] = false;
            return false;
        }

        $hasBuild = preg_match('/^\s+build:/m', $output) === 1;

        // Persist so future reads hit the cache
        $this->writeMetadata('has_build', $hasBuild ? '1' : '0');
        $this->metadataCache['has_build'] = $hasBuild;
        return $hasBuild;
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------
    /**
     * Read a metadata file from the stack directory (lazy, cached).
     *
     * @param string $filename Metadata filename (e.g. 'name', 'envpath')
     * @param bool $forceRefresh If true, bypass cache and re-read from disk
     * @return string|null Trimmed file contents, or null if file doesn't exist
     */
    private function readMetadata(string $filename, bool $forceRefresh = false): ?string
    {
        if (!$forceRefresh && array_key_exists($filename, $this->metadataCache)) {
            return $this->metadataCache[$filename];
        }

        $filePath = $this->path . '/' . $filename;
        if (is_file($filePath)) {
            $content = @file_get_contents($filePath);
            $this->metadataCache[$filename] = ($content !== false) ? trim($content) : null;
        } else {
            $this->metadataCache[$filename] = null;
        }

        return $this->metadataCache[$filename];
    }

    /** Write a metadata file to the stack directory and update cache.
     *
     * @param string $filename Metadata filename (e.g. 'name', 'envpath')
     * @param string $content Content to write to the file
     * @return bool True on success, false on failure
     */
    private function writeMetadata(string $filename, string $content): bool
    {
        $content = trim($content);
        $result = @file_put_contents($this->path . '/' . $filename, $content) !== false;
        if ($result) {
            $this->metadataCache[$filename] = $content;
        }
        return $result;
    }

    /** 
     * Set the path to $composeRoot/$project.
     * 
     * @return void
     */
    private function setPath(): void
    {
        $this->path = $this->composeRoot . '/' . $this->projectFolder;
    }

    // ---------------------------------------------------------------
    // Static factory: create a new stack on disk
    // ---------------------------------------------------------------

    /**
     * Create a new stack directory with all required files.
     *
     * Handles folder naming (via sanitizeFolderName + collision avoidance),
     * indirect wiring, default compose file creation, metadata files (name,
     * description), and override initialization.
     *
     * Input validation (e.g. allowed indirect path roots) is the caller's
     * responsibility — this method only deals with the filesystem structure.
     *
     * @param string $composeRoot  The compose projects root directory
     * @param string $stackName    Human-readable stack name
     * @param string $description  Optional description text
     * @param string $indirectPath Optional indirect compose source directory
     *
     * @return self The newly created (and cached) StackInfo instance
     *
     * @throws \RuntimeException If the stack directory cannot be created
     */
    public static function createNew(
        string $composeRoot,
        string $stackName,
        string $description = '',
        string $indirectPath = ''
    ): self {
        $composeRoot = rtrim($composeRoot, '/');

        // Validate stack name is not empty
        if (trim($stackName) === '') {
            composeLogger("Attempted to create a new stack with an empty name, which is not allowed.", null, 'user', 'error', 'stack');
            throw new \RuntimeException("Stack name cannot be empty");
        }
        $projectName = $stackName;

        // Set the project name to the folder-and-project sanitized version of the display name.
        $project = self::sanitizeProjectString($stackName);
        if ($project === '') {
            composeLogger("Sanitized stack name is empty, cannot create stack directory.", ['stackName' => $stackName], 'user', 'error', 'stack');
            throw new \RuntimeException("Stack name produced an empty folder name after sanitization.");
        }

        // Set the path to composeRoot/project (even if indirect, we want the folder there for metadata and override)
        $path = $composeRoot . '/' . $project;

        // Verify the resolved path stays within composeRoot (defense-in-depth)
        $realComposeRoot = realpath($composeRoot);
        if ($realComposeRoot === false) {
            composeLogger("Failed to resolve real path for compose root.", ['composeRoot' => $composeRoot], 'user', 'error', 'stack');
            throw new \RuntimeException("Invalid compose root directory.");
        }

        // For new folders, check that the parent resolves correctly
        $resolvedParent = realpath(dirname($path));
        if ($resolvedParent === false || strpos($resolvedParent, $realComposeRoot) !== 0) {
            composeLogger("Invalid stack name: path would escape compose root.", ['stackName' => $stackName, 'resolvedParent' => $resolvedParent, 'realComposeRoot' => $realComposeRoot], 'user', 'error', 'stack');
            throw new \RuntimeException("Invalid stack name: path would escape compose root.");
        }

        try {
            // Ensure the project folder is available, handling collisions by appending suffixes if needed (e.g. "my-stack-001", "my-stack-002", etc.)
            $path = self::getAvailablePath($composeRoot, $project);
        } catch (\RuntimeException $e) {
            composeLogger("Failed to get available path for stack.", ['stackName' => $stackName, 'error' => $e->getMessage()], 'user', 'error', 'stack');
            throw new \RuntimeException("Failed to create stack: " . $e->getMessage());
        }

        // Create the directory
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            composeLogger("Failed to create stack directory.", ['path' => $path], 'user', 'error', 'stack');
            throw new \RuntimeException("Failed to create stack directory: $path");
        }

        // Create indirect file to store path to indirect project directory
        if ($indirectPath !== '') {
            file_put_contents("$path/indirect", $indirectPath);
        }

        // Write metadata
        file_put_contents("$path/name", $projectName);
        if ($description !== '') {
            file_put_contents("$path/description", $description);
        }

        // Create default compose file at the appropriate location (indirect target or stack dir)
        $composeTarget = ($indirectPath !== '') ? $indirectPath : $path;
        self::writeDefaultComposeFile($composeTarget);

        // Build + cache the instance (resolves override, etc.)
        return self::fromProject($composeRoot, basename($path));
    }

    /**
     * Write a blank compose file to the given path if one doesn't already exist.
     * 
     * @param string $dir The directory to check for an existing compose file and write to if missing
     * @return string The path to the compose file (existing or newly created)
     * @throws \RuntimeException If the compose file cannot be created
     */
    private static function writeDefaultComposeFile(string $dir): string
    {
        // Check for any existing compose file before creating a new one
        $existing = self::getComposeFilePath($dir);
        if ($existing !== null) {
            return $existing;
        }

        $filePath = "$dir/" . COMPOSE_FILE_NAMES[0];
        if (file_put_contents($filePath, "services:\n") === false) {
            throw new \RuntimeException("Failed to create default compose file: $filePath");
        }
        return $filePath;
    }

    /**
     * Get an available project folder path under the compose root, handling name collisions by appending numeric suffixes.
     * 
     * @param string $composeRoot
     * @param string $baseName
     * @return string An available project name (folder name) that doesn't collide with existing stacks
     * @throws \RuntimeException If an available name cannot be found after many attempts
     */
    private static function getAvailablePath(string $composeRoot, string $baseName): string
    {
        $candidate = $composeRoot . '/' . $baseName;
        $project = $baseName;

        // Handle name collision by appending a suffix (if the folder already exists)
        if (is_dir($candidate)) {
            $maxAttempts = 100;
            $attempts = 0;
            do {
                if ($attempts < 1) {
                    composeLogger("Name collision detected for preferred folder, '$candidate', attempting to find an available name.", ['candidate' => $candidate], 'user', 'info', 'stack');
                } else {
                    composeLogger("Name collision detected for suffixed name, '$candidate'.", ['candidate' => $candidate], 'user', 'info', 'stack');
                }
                $attempts++;
                $candidate = $composeRoot . '/' . $project . '-' . sprintf('%03d', $attempts);
                composeLogger("Checking candidate stack name: '$candidate'", ['candidate' => $candidate], 'user', 'debug', 'stack');
                if ($attempts > $maxAttempts) {
                    throw new \RuntimeException("Unable to find a unique folder name for stack '$baseName' after $maxAttempts attempts");
                }
            } while (is_dir($candidate));
        }
        return $candidate;
    }

    /**
     * List project folder basenames under a compose root, skipping reserved entries.
     *
     * Excludes the standard '.'/'..', plus the plugin-managed {@see COMPOSE_ROOT_VERSION_FILE}
     * that lives at the compose root level (written by compose.manager.plg on install/upgrade).
     * Only directory entries are returned, so plain files are always ignored.
     *
     * Use this everywhere project folders need to be enumerated to guarantee consistent
     * behaviour across allFromRoot(), backup, and any future callers.
     *
     * @param string $composeRoot Compose projects root directory
     * @return string[] Directory basenames (unsorted, filesystem order)
     */
    public static function listProjectFolders(string $composeRoot): array
    {
        $root = rtrim($composeRoot, '/');
        $result = [];
        foreach (@scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === COMPOSE_ROOT_VERSION_FILE) {
                continue;
            }
            if (is_dir("$root/$entry")) {
                $result[] = $entry;
            }
        }

        $savedOrder = getComposeStackOrder($composeRoot);
        if ($savedOrder) {
            $positions = array_flip($savedOrder);
            usort($result, function ($left, $right) use ($positions) {
                $leftPos = $positions[$left] ?? PHP_INT_MAX;
                $rightPos = $positions[$right] ?? PHP_INT_MAX;
                if ($leftPos === $rightPos) {
                    return strnatcasecmp($left, $right);
                }
                return $leftPos <=> $rightPos;
            });
        }

        return $result;
    }

    /**
     * Return all valid StackInfo instances under a compose root.
     *
     * Scans the root directory and returns one StackInfo per valid stack,
     * silently skipping folders with no compose file or invalid structure.
     *
     * @param string $composeRoot Compose projects root directory
     * @param bool   $skipDocker  If true, skip the batch docker ps preload
     *                            (returns stacks with empty container lists
     *                            for fast skeleton rendering).
     * @return self[]
     */
    public static function allFromRoot(string $composeRoot, bool $skipDocker = false): array
    {
        $stacks = [];
        foreach (self::listProjectFolders($composeRoot) as $project) {
            try {
                $stacks[] = self::fromProject($composeRoot, $project);
            } catch (\Throwable $e) {
                // skip non-stack directories (no compose file, invalid structure, etc.)
                composeLogger("Skipped project '$project': " . $e->getMessage(), null, 'user', 'debug', 'allFromRoot');
            }
        }

        if ($skipDocker) {
            // Set empty container lists so getContainerList() won't trigger
            // per-stack docker calls.
            foreach ($stacks as $stack) {
                $stack->setContainerList([]);
            }
            return $stacks;
        }

        // Batch-preload container data with a single docker ps call to avoid
        // O(n) docker invocations when callers iterate getContainerList().
        $containersByProject = [];
        $psOutput = shell_exec("docker ps -a --filter label=com.docker.compose.project --format json 2>/dev/null");
        if ($psOutput) {
            foreach (explode("\n", trim($psOutput)) as $line) {
                if ($line === '') {
                    continue;
                }
                $ct = @json_decode($line, true);
                if (!$ct) {
                    continue;
                }
                // Extract project name from Labels string
                $labels = $ct['Labels'] ?? '';
                if (preg_match('/com\.docker\.compose\.project=([^,]+)/', $labels, $m)) {
                    $containersByProject[$m[1]][] = $ct;
                }
            }
        }
        foreach ($stacks as $stack) {
            $key = $stack->projectName;
            $stack->setContainerList($containersByProject[$key] ?? []);
        }

        return $stacks;
    }

    /**
     * Get the raw docker compose ps rows for this stack.
     *
     * Runs `docker compose ps --all --format json` scoped to this stack using
     * the resolved compose file, env file, and project name. Results are cached
     * on the instance so repeated calls are free.
     *
     * @return array[] Raw rows from `docker compose ps --all --format json`
     */
    public function getContainerList(): array
    {
        if ($this->cachedContainerList !== null) {
            return $this->cachedContainerList;
        }
        $this->cachedContainerList = [];
        $args = $this->buildComposeArgs();
        $cmd = "docker compose {$args['files']} {$args['envFile']} -p "
            . escapeshellarg($args['projectName'])
            . " ps --all --format json 2>/dev/null";
        $output = shell_exec($cmd);
        if (!$output) {
            return $this->cachedContainerList;
        }
        foreach (explode("\n", trim($output)) as $line) {
            if ($line === '') {
                continue;
            }
            $container = @json_decode($line, true);
            if ($container) {
                $this->cachedContainerList[] = $container;
            }
        }
        return $this->cachedContainerList;
    }

    /**
     * Get the containers for this stack as ContainerInfo objects.
     *
     * Uses the same cached docker ps data as getContainerList().
     *
     * @return ContainerInfo[]
     */
    public function getContainers(): array
    {
        return array_map(
            fn($raw) => ContainerInfo::fromDockerPs($raw),
            $this->getContainerList()
        );
    }

    /**
     * Get aggregate container state counts for this stack.
     *
     * Counts are based on actually created containers (from docker ps),
     * not on the services defined in the compose file. This means
     * services in inactive profiles are excluded from the total.
     *
     * @return array{running: int, stopped: int, paused: int, restarting: int, total: int}
     */
    public function getContainerCounts(): array
    {
        if ($this->cachedContainerCounts !== null) {
            return $this->cachedContainerCounts;
        }

        $counts = ['running' => 0, 'stopped' => 0, 'paused' => 0, 'restarting' => 0, 'total' => 0];

        foreach ($this->getContainerList() as $ct) {
            $counts['total']++;
            $state = $ct['State'] ?? '';
            if ($state === 'running') {
                $counts['running']++;
            } elseif ($state === 'paused') {
                $counts['paused']++;
            } elseif ($state === 'restarting') {
                $counts['restarting']++;
            } else {
                // Any created but non-running/non-paused/non-restarting
                // container state is treated as stopped.
                $counts['stopped']++;
            }
        }

        $this->cachedContainerCounts = $counts;
        return $counts;
    }

    /**
     * Derive the display state of this stack from its container counts.
     *
     * Returns everything needed to render the status icon, label, and
     * colour class in both the stack list and the dashboard tile.
     *
     * @return array{state: string, label: string, shape: string, color: string, running: int, total: int}
     */
    public function getStackState(): array
    {
        $counts = $this->getContainerCounts();
        $running = $counts['running'];
        $total   = $counts['total'];

        if ($running > 0 && $running < $total) {
            $state = 'partial';
        } elseif ($running > 0) {
            $state = 'started';
        } elseif ($counts['paused'] > 0 && $total > 0) {
            $state = 'paused';
        } else {
            $state = 'stopped';
        }

        $label = $state;
        if ($state === 'partial') {
            $label = "partial ($running/$total)";
        }

        switch ($state) {
            case 'started':
                $shape = 'play';
                $color = 'green-text';
                break;
            case 'partial':
                $shape = 'exclamation-circle';
                $color = 'orange-text';
                break;
            case 'paused':
                $shape = 'pause';
                $color = 'orange-text';
                break;
            default: // stopped
                $shape = 'square';
                $color = 'grey-text';
                break;
        }

        return [
            'state'   => $state,
            'label'   => $label,
            'shape'   => $shape,
            'color'   => $color,
            'running' => $running,
            'total'   => $total,
        ];
    }

    /**
     * Find a StackInfo by its compose source path.
     *
     * Searches all projects to find one whose composeSource matches the given path.
     * Useful for auto-update feature where config stores compose source paths.
     *
     * @param string $composeRoot The compose projects root directory
     * @param string $composePath The compose source path to search for
     * @return self|null The matching StackInfo, or null if not found
     */
    public static function fromComposePath(string $composeRoot, string $composePath): ?self
    {
        // Static cache: [composeRoot => [composeSourcePath => StackInfo]]
        static $cacheByRoot = [];

        $composeRoot = rtrim($composeRoot, '/');
        $normalizedPath = rtrim($composePath, '/');

        if (isset($cacheByRoot[$composeRoot])) {
            return $cacheByRoot[$composeRoot][$normalizedPath] ?? null;
        }

        $cacheByRoot[$composeRoot] = [];

        foreach (self::allFromRoot($composeRoot) as $stackInfo) {
            $sourcePath = rtrim($stackInfo->composeSource, '/');
            if ($sourcePath !== '') {
                $cacheByRoot[$composeRoot][$sourcePath] = $stackInfo;
            }
        }

        return $cacheByRoot[$composeRoot][$normalizedPath] ?? null;
    }
}

/**
 * Stack operation locking functions
 * Prevents concurrent operations on the same stack
 */

// Lock directory override for testing - set via $GLOBALS['compose_lock_dir']

/**
 * Get the lock directory path
 * @return string
 */
function getLockDir(): string
{
    return $GLOBALS['compose_lock_dir'] ?? "/var/run/compose.manager";
}

/**
 * Acquire a lock for a stack operation
 * @param string $stackName The stack name/folder
 * @param int $timeout Maximum seconds to wait for lock (default 30)
 * @return resource|false File handle if lock acquired, false otherwise
 */
function acquireStackLock($stackName, $timeout = 30)
{
    $lockDir = getLockDir();
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0755, true);
    }

    $lockFile = "$lockDir/" . StackInfo::sanitizeProjectString($stackName) . ".lock";
    $fp = @fopen($lockFile, 'w');

    if (!$fp) {
        return false;
    }

    $waited = 0;
    while (!flock($fp, LOCK_EX | LOCK_NB)) {
        if ($waited >= $timeout) {
            fclose($fp);
            return false;
        }
        sleep(1);
        $waited++;
    }

    // Write lock info for debugging
    fwrite($fp, json_encode([
        'pid' => getmypid(),
        'time' => date('c'),
        'stack' => $stackName
    ]));
    fflush($fp);

    return $fp;
}

/**
 * Release a stack lock
 * @param resource $fp File handle from acquireStackLock
 */
function releaseStackLock($fp)
{
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Check if a stack is currently locked
 * @param string $stackName The stack name/folder
 * @return array|false Lock info if locked, false if not locked
 */
function isStackLocked($stackName)
{
    $lockDir = getLockDir();
    $lockFile = "$lockDir/" . StackInfo::sanitizeProjectString($stackName) . ".lock";

    if (!is_file($lockFile)) {
        return false;
    }

    $fp = @fopen($lockFile, 'r');
    if (!$fp) {
        return false;
    }

    // Try to get a non-blocking lock
    if (flock($fp, LOCK_EX | LOCK_NB)) {
        // Got the lock, so it wasn't locked
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    // Couldn't get lock, read the lock info
    $content = file_get_contents($lockFile);
    fclose($fp);

    $info = @json_decode($content, true);
    return $info ?: ['locked' => true];
}

/**
 * Get the last operation result for a stack
 * @param string $stackPath Full path to the stack directory
 * @return array|null Result info or null if not found
 */
function getStackLastResult($stackPath)
{
    $resultFile = "$stackPath/last_result.json";
    if (is_file($resultFile)) {
        $content = @file_get_contents($resultFile);
        if ($content) {
            return @json_decode($content, true);
        }
    }
    return null;
}

/**
 * Determine whether Compose-managed containers should be hidden from the Docker tab
 * Uses parse_plugin_cfg('compose.manager') when available (testable), or falls back to
 * parsing /boot/config/plugins/compose.manager/compose.manager.cfg
 *
 * @return bool
 */
function hide_compose_from_docker(): bool
{
    $cfg = [];
    if (function_exists('parse_plugin_cfg')) {
        $cfg = parse_plugin_cfg('compose.manager');
    } else {
        $cfg = @parse_ini_file('/boot/config/plugins/compose.manager/compose.manager.cfg') ?: [];
    }
    return (isset($cfg['HIDE_COMPOSE_FROM_DOCKER']) && $cfg['HIDE_COMPOSE_FROM_DOCKER'] === 'true');
}
