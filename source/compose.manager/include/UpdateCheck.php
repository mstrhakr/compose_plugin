<?php

require_once __DIR__ . '/Defines.php';
require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/CredentialVault.php';
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';

/**
 * Compose Manager plugin-local update checker.
 *
 * Compares platform-specific image config digests instead of top-level
 * index/manifest-list digests. This eliminates the false-positive
 * "update ready" indicator that occurs for multi-arch tags when a
 * registry republishes the index over identical layer content (a routine
 * occurrence on Docker Hub for redis, postgres, alpine, and many others).
 *
 * Scope guarantees:
 *   - Callers pass an explicit set of images to check. This class never
 *     enumerates the full Docker image list.
 *   - Registry evidence is cached only for this checker's lifetime.
 *     StackUpdateService owns accepted, persistent stack update evidence.
 *     The native Docker tab's update cache is never read or written.
 *
 * Also see the parallel upstream fix for the native Docker tab in the
 * dynamix.docker.manager DockerUpdate class. This plugin-local
 * implementation exists so the Compose Manager UI shows correct results
 * without waiting for that upstream change to merge and ship.
 */
class ComposeUpdateCheck
{
    private ?string $credentialId;
    /** @var array<string, array|null> */
    private array $remoteManifests = [];
    /** @var array<string, array{digest: string, diffIds: string[]}|null> */
    private array $baseConfigs = [];

    public function __construct(?string $credentialId = null)
    {
        $this->credentialId = $credentialId;
    }

    /**
     * Check a batch of images without persisting image-level evidence.
     * Duplicate images in $images are checked only once.
     *
     * @param string[] $images
     * @param string|null $platform Target os/architecture[/variant], or the host platform
     * @return array<string, array{local: string, remote: string, status: string, hasUpdate: bool}>
     *   Keys are normalized image names.
     */
    public function checkMany(array $images, ?string $platform = null): array
    {
        $results = [];

        foreach ($images as $rawImage) {
            $image = ContainerInfo::normalizeImageForUpdateCheck((string) $rawImage);
            if ($image === '' || isset($results[$image])) {
                continue;
            }

            $local  = $this->inspectLocalConfigDigest($image);
            $remote = $this->fetchRemoteConfigDigest($image, $platform);
            if (!$local || !$remote) {
                composeLogger('Image update check incomplete', [
                    'image' => $image,
                    'platform' => $platform ?? self::hostPlatform(),
                    'localDigestAvailable' => (bool) $local,
                    'remoteDigestAvailable' => (bool) $remote,
                ], 'user', 'warning', 'update-check');
            }
            $summary = $this->summarize($local, $remote);

            $results[$image] = $summary['result'];
        }

        return $results;
    }

    /**
     * Compare each container's actual image with its tag's remote config.
     * A tag may already point at a newly pulled image while a container
     * still uses the previous image.
     *
     * Services in $buildConfigs (from StackInfo::getBuildConfigs) are built
     * locally. Build-only services have no registry tag, so they are checked
     * against their Dockerfile base image instead. Services that also name an
     * image retain unknown application evidence when registry checks fail.
     *
     * @param array<string, array{build: array<string, mixed>, image: ?string, platform?: string}> $buildConfigs Keyed by service name
     * @return array<string, array{local: string, remote: string, status: string, hasUpdate: bool, localImage: string, updateKind: string, updateReadiness: string, image?: string}>
     */
    public function checkContainers(array $containers, array $buildConfigs = []): array
    {
        $images = [];
        $client = new DockerClient();
        $inspections = [];
        foreach ($containers as &$container) {
            $name = trim((string) ($container['Name'] ?? $container['name'] ?? $container['Names'] ?? ''));
            if ($name === '') {
                continue;
            }
            $inspect = $client->getDockerJSON('/containers/' . rawurlencode($name) . '/json');
            $inspections[$name] = $inspect;
            $reference = trim((string) ($inspect['Config']['Image'] ?? ''));
            if ($reference !== '') {
                $container['Image'] = $reference;
            }
            $image = trim((string) ($container['Image'] ?? $container['image'] ?? ''));
            $build = $buildConfigs[ContainerInfo::resolveService($container)] ?? null;
            if ($image !== '' && strpos($image, '@sha256:') === false
                && ($build === null || $build['image'] !== null)) {
                $images[$build['platform'] ?? ''][] = $image;
            }
        }
        unset($container);
        $imageResults = [];
        foreach ($images as $platform => $references) {
            $imageResults[$platform] = $this->checkMany($references, $platform !== '' ? $platform : null);
        }
        $results = [];
        foreach ($containers as $container) {
            $name = trim((string) ($container['Name'] ?? $container['name'] ?? $container['Names'] ?? ''));
            $image = trim((string) ($container['Image'] ?? $container['image'] ?? ''));
            if ($name === '' || $image === '') {
                continue;
            }
            $build = $buildConfigs[ContainerInfo::resolveService($container)] ?? null;
            $imageResult = $imageResults[$build['platform'] ?? ''][ContainerInfo::normalizeImageForUpdateCheck($image)] ?? [];
            $inspect = $inspections[$name] ?? [];
            $local = !empty($inspect['Image']) ? (string) $inspect['Image'] : null;
            $remote = !empty($imageResult['remote']) ? (string) $imageResult['remote'] : null;
            if ($local === null) {
                composeLogger('Container image digest unavailable', ['container' => $name], 'user', 'warning', 'update-check');
            }
            if (strpos($image, '@sha256:') !== false) {
                $results[$name] = $this->summarize($local, $local)['result'] + [
                    'localImage' => (string) ($local ?? ''), 'updateKind' => 'image',
                    'updateReadiness' => $local === null ? 'unknown' : 'current',
                    'image' => $image,
                ];
                continue;
            }
            if ($build !== null && $build['image'] === null && $local !== null) {
                $results[$name] = $this->checkBuiltImage($name, $local, $image, $build['build'], $build['platform'] ?? null)
                    + ['image' => $image];
                continue;
            }
            $results[$name] = $this->summarize($local, $remote)['result'];
            $tagImage = (string) ($imageResult['local'] ?? '');
            $results[$name]['localImage'] = $tagImage;
            $results[$name]['updateKind'] = 'image';
            $results[$name]['updateReadiness'] = self::imageReadiness($local, $tagImage, $remote);
            $results[$name]['image'] = $image;
        }
        return $results;
    }

    /**
     * Resolve the external image the final build stage starts from.
     *
     * Follows stage aliases (FROM builder AS final) and expands ARG values
     * declared before the first FROM, overridden by compose build args.
     *
     * @param array<string, mixed> $build Resolved compose build section
     * @return string|null Image reference, "scratch", or null when it cannot be determined
     */
    public static function resolveDockerfileBaseImage(array $build): ?string
    {
        return self::resolveDockerfileBase($build)['image'] ?? null;
    }

    /**
     * @param array<string, mixed> $build
     * @return array{image: string, platform: string}|null
     */
    private static function resolveDockerfileBase(array $build, ?string $platform = null): ?array
    {
        $dockerfile = self::readDockerfile($build);
        if ($dockerfile === null) {
            return null;
        }

        $buildArgs = [];
        foreach ((is_array($build['args'] ?? null) ? $build['args'] : []) as $key => $value) {
            if ($value !== null) {
                $buildArgs[(string) $key] = (string) $value;
            }
        }

        $host = self::hostPlatform();
        $globalArgs = ['BUILDPLATFORM' => $host, 'TARGETPLATFORM' => $platform ?? $host];
        $stages = [];
        foreach (self::dockerfileInstructions($dockerfile) as [$instruction, $arguments]) {
            if ($instruction === 'ARG' && $stages === []) {
                foreach (preg_split('/\s+/', $arguments) as $declaration) {
                    $parts = explode('=', $declaration, 2);
                    if ($parts[0] === '') {
                        continue;
                    }
                    $default = null;
                    if (isset($parts[1])) {
                        $literal = trim($parts[1], '"\'');
                        $default = $literal === '' ? '' : self::expandDockerfileArgs($literal, $globalArgs);
                    }
                    $globalArgs[$parts[0]] = $buildArgs[$parts[0]] ?? $default ?? ($globalArgs[$parts[0]] ?? null);
                }
            } elseif ($instruction === 'FROM') {
                $stagePlatform = $platform ?? $host;
                if (preg_match('/(?:^|\s)--platform=(\S+)/', $arguments, $match)) {
                    $stagePlatform = self::expandDockerfileArgs($match[1], $globalArgs);
                }
                $tokens = array_values(array_filter(
                    preg_split('/\s+/', $arguments),
                    static fn(string $token): bool => $token !== '' && strpos($token, '--') !== 0
                ));
                if ($tokens === []) {
                    return null;
                }
                $stages[] = [
                    'from' => self::expandDockerfileArgs($tokens[0], $globalArgs),
                    'platform' => $stagePlatform,
                    'name' => (isset($tokens[2]) && strtoupper($tokens[1]) === 'AS') ? strtolower($tokens[2]) : null,
                ];
            }
        }
        if ($stages === []) {
            return null;
        }

        $target = strtolower(trim((string) ($build['target'] ?? '')));
        $index = count($stages) - 1;
        if ($target !== '') {
            $index = null;
            foreach ($stages as $i => $stage) {
                if ($stage['name'] === $target) {
                    $index = $i;
                }
            }
            if ($index === null) {
                return null;
            }
        }

        for ($hops = 0; $hops <= count($stages); $hops++) {
            $from = $stages[$index]['from'];
            if ($from === null) {
                return null;
            }
            $previous = null;
            for ($i = $index - 1; $i >= 0; $i--) {
                if ($stages[$i]['name'] === strtolower($from)) {
                    $previous = $i;
                    break;
                }
            }
            if ($previous === null) {
                $stagePlatform = $stages[$index]['platform'];
                if ($stagePlatform === null || !self::validPlatform($stagePlatform)) {
                    return null;
                }
                $contexts = $build['additional_contexts'] ?? [];
                if (is_array($contexts) && array_key_exists($from, $contexts)) {
                    $context = $contexts[$from];
                    if (!is_string($context) || !str_starts_with($context, 'docker-image://')) {
                        return null;
                    }
                    $from = substr($context, strlen('docker-image://'));
                    if ($from === '') {
                        return null;
                    }
                }
                return ['image' => strtolower($from) === 'scratch' ? 'scratch' : $from, 'platform' => $stagePlatform];
            }
            $index = $previous;
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * A locally built image is current when its layers still start with the
     * remote base image's layers (rootfs.diff_ids).
     *
     * @param array<string, mixed> $build
     * @return array{local: string, remote: string, status: string, hasUpdate: bool, localImage: string, updateKind: string, updateReadiness: string}
     */
    private function checkBuiltImage(string $container, string $imageId, string $image, array $build, ?string $platform = null): array
    {
        $unknown = [
            'local' => $imageId, 'remote' => '', 'status' => 'unknown', 'hasUpdate' => false,
            'localImage' => '', 'updateKind' => 'build-base', 'updateReadiness' => 'unknown',
        ];
        $resolved = self::resolveDockerfileBase($build, $platform);
        $base = $resolved['image'] ?? null;
        if ($base === null || $base === 'scratch') {
            composeLogger('Build base image unavailable for update check', [
                'container' => $container,
                'base' => $base,
            ], 'user', $base === null ? 'warning' : 'debug', 'update-check');
            return $unknown;
        }
        if (strpos($base, '@sha256:') !== false) {
            return array_replace($unknown, [
                'status' => 'up-to-date', 'updateReadiness' => 'current',
            ]);
        }

        $baseKey = ContainerInfo::normalizeImageForUpdateCheck($base);
        $platform = $resolved['platform'];
        $cacheKey = $baseKey . '|' . $platform;
        if (!array_key_exists($cacheKey, $this->baseConfigs)) {
            $this->baseConfigs[$cacheKey] = $this->fetchRemoteImageConfig($baseKey, $platform);
        }
        $remote = $this->baseConfigs[$cacheKey];
        $inspect = (new DockerClient())->getDockerJSON('/images/' . rawurlencode($imageId) . '/json');
        $layers = $inspect['RootFS']['Layers'] ?? null;
        if ($remote === null || !is_array($layers) || $layers === []) {
            composeLogger('Build base image update check incomplete', [
                'container' => $container,
                'base' => $baseKey,
                'remoteConfigAvailable' => $remote !== null,
                'localLayersAvailable' => is_array($layers) && $layers !== [],
            ], 'user', 'warning', 'update-check');
            return $unknown;
        }

        $current = array_slice(array_values($layers), 0, count($remote['diffIds'])) === $remote['diffIds'];
        $tagImage = $this->inspectLocalConfigDigest($image);
        $ready = false;
        if (!$current && $tagImage !== null && $tagImage !== $imageId) {
            $tagInspect = (new DockerClient())->getDockerJSON('/images/' . rawurlencode($tagImage) . '/json');
            $tagLayers = $tagInspect['RootFS']['Layers'] ?? null;
            $ready = is_array($tagLayers)
                && array_slice(array_values($tagLayers), 0, count($remote['diffIds'])) === $remote['diffIds'];
        }
        return [
            'local' => $imageId,
            'remote' => $remote['digest'],
            'status' => $current ? 'up-to-date' : 'update-available',
            'hasUpdate' => !$current,
            'localImage' => (string) ($tagImage ?? ''),
            'updateKind' => 'build-base',
            'updateReadiness' => $current ? 'current' : ($ready ? 'ready-to-apply' : 'rebuild-required'),
        ];
    }

    public static function imageReadiness(?string $containerImage, ?string $localImage, ?string $remoteImage): string
    {
        if (!$containerImage || !$remoteImage) {
            return 'unknown';
        }
        if ($containerImage === $remoteImage) {
            return 'current';
        }
        return $localImage === $remoteImage ? 'ready-to-apply' : 'download-required';
    }

    /** @param array<string, mixed> $build */
    private static function readDockerfile(array $build): ?string
    {
        if (is_string($build['dockerfile_inline'] ?? null)) {
            return $build['dockerfile_inline'];
        }
        $context = (string) ($build['context'] ?? '');
        if ($context === '' || !is_dir($context)) {
            return null;
        }
        $dockerfile = (string) ($build['dockerfile'] ?? '');
        if ($dockerfile === '') {
            $dockerfile = 'Dockerfile';
        }
        $path = $dockerfile[0] === '/' ? $dockerfile : rtrim($context, '/') . '/' . $dockerfile;
        $content = is_file($path) ? @file_get_contents($path) : false;
        return is_string($content) ? $content : null;
    }

    /**
     * Split a Dockerfile into [INSTRUCTION, arguments] pairs, joining line
     * continuations and skipping comments and heredoc bodies.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function dockerfileInstructions(string $dockerfile): array
    {
        $lines = preg_split('/\r?\n/', $dockerfile);
        $escape = '\\';
        foreach ($lines as $line) {
            if (!preg_match('/^\s*#\s*(\w+)\s*=\s*(\S+)\s*$/', $line, $m)) {
                break;
            }
            if (strtolower($m[1]) === 'escape') {
                $escape = $m[2];
            }
        }

        $instructions = [];
        $pending = '';
        $heredocs = [];
        foreach ($lines as $line) {
            if ($heredocs !== []) {
                $heredoc = $heredocs[0];
                if (($heredoc['strip'] ? ltrim($line, "\t") : $line) === $heredoc['word']) {
                    array_shift($heredocs);
                }
                continue;
            }
            if ($pending === '' && preg_match('/^\s*(#|$)/', $line)) {
                continue;
            }
            if ($pending !== '' && preg_match('/^\s*(#|$)/', $line)) {
                continue;
            }
            $trimmed = rtrim($line);
            if ($trimmed !== '' && substr($trimmed, -strlen($escape)) === $escape) {
                $pending .= substr($trimmed, 0, -strlen($escape)) . ' ';
                continue;
            }
            $full = trim($pending . $line);
            $pending = '';
            if (!preg_match('/^(\w+)\s*(.*)$/s', $full, $m)) {
                continue;
            }
            $instruction = strtoupper($m[1]);
            $instructions[] = [$instruction, trim($m[2])];
            // Like BuildKit, only a standalone <<WORD token in RUN/COPY/ADD starts a heredoc,
            // so here-strings (<<<) and shell arithmetic (1<<2) are not mistaken for one.
            if (in_array($instruction, ['RUN', 'COPY', 'ADD'], true)) {
                foreach (preg_split('/\s+/', $m[2]) as $token) {
                    if (preg_match('/^\d*<<(-?)(["\']?)([^<"\']+)\2$/', $token, $h)) {
                        $heredocs[] = ['strip' => $h[1] === '-', 'word' => $h[3]];
                    }
                }
            }
        }
        return $instructions;
    }

    /** @param array<string, ?string> $args */
    private static function expandDockerfileArgs(string $value, array $args): ?string
    {
        $expanded = preg_replace_callback(
            '/\$(?:\{(\w+)(?:(:?[-+])([^}]*))?\}|(\w+))/',
            static function (array $m) use ($args): string {
                $name = (string) ($m[1] ?? $m[4]);
                $current = $args[$name] ?? null;
                $operator = (string) $m[2];
                $word = (string) $m[3];
                $isSet = $operator !== '' && $operator[0] === ':' ? ($current !== null && $current !== '') : $current !== null;
                if (substr($operator, -1) === '-') {
                    return $isSet ? (string) $current : $word;
                }
                if (substr($operator, -1) === '+') {
                    return $isSet ? $word : '';
                }
                return (string) $current;
            },
            $value,
            -1,
            $count,
            PREG_UNMATCHED_AS_NULL
        );
        return is_string($expanded) && $expanded !== '' && strpos($expanded, '$') === false ? $expanded : null;
    }

    /**
     * Fetch the platform config digest and layer diff IDs for an image tag.
     *
     * @return array{digest: string, diffIds: string[]}|null
     */
    protected function fetchRemoteImageConfig(string $image, ?string $platform = null): ?array
    {
        $manifest = $this->fetchRemoteManifest($image, $platform);
        if ($manifest === null) {
            return null;
        }
        $config = $this->httpGetJson($manifest['blobUrl'], $manifest['header'], $manifest['basicUserPwd']);
        $diffIds = $config['rootfs']['diff_ids'] ?? null;
        if (!is_array($diffIds) || $diffIds === []) {
            return null;
        }
        return ['digest' => $manifest['configDigest'], 'diffIds' => array_values(array_map('strval', $diffIds))];
    }

    private function summarize(?string $local, ?string $remote): array
    {
        if (!$local || !$remote) {
            return [
                'result' => [
                    'local'     => (string) ($local  ?? ''),
                    'remote'    => (string) ($remote ?? ''),
                    'status'    => 'unknown',
                    'hasUpdate' => false,
                ],
            ];
        }
        $upToDate = ($local === $remote);
        return [
            'result' => [
                'local'     => $local,
                'remote'    => $remote,
                'status'    => $upToDate ? 'up-to-date' : 'update-available',
                'hasUpdate' => !$upToDate,
            ],
        ];
    }

    /**
     * Return the image's content-addressed config digest (Docker .Id).
     *
     * The .Id field is sha256 of the image config blob, which pins the
     * exact layer set via rootfs.diff_ids. It changes only when there is
     * real new content — unlike RepoDigests, which stores the pulled
     * index/manifest-list digest for multi-arch tags and thus changes
     * whenever the registry republishes the index over identical layers.
     */
    private function inspectLocalConfigDigest(string $image): ?string
    {
        $client  = new DockerClient();
        $inspect = $client->getDockerJSON('/images/' . rawurlencode($image) . '/json');
        if (is_array($inspect) && !empty($inspect['Id'])) {
            return (string) $inspect['Id'];
        }
        return null;
    }

    /**
     * Fetch the remote config digest for the image's tag.
     *
     * For multi-arch tags: resolves the platform manifest matching the
     * running host, then reads config.digest from that manifest. For
     * single-arch tags: reads config.digest directly from the manifest.
     *
     * If any step fails (transport, parse, missing config.digest, etc.),
     * return no digest. Manifest digests cannot be compared to Docker's
     * local config digest.
     */
    private function fetchRemoteConfigDigest(string $image, ?string $platform = null): ?string
    {
        return $this->fetchRemoteManifest($image, $platform)['configDigest'] ?? null;
    }

    /**
     * Resolve the platform manifest for an image tag.
     *
     * @return array{configDigest: string, blobUrl: string, header: string[], basicUserPwd: ?string}|null
     */
    private function fetchRemoteManifest(string $image, ?string $platform = null): ?array
    {
        $platform ??= self::hostPlatform();
        $key = $image . '|' . $platform;
        if (!array_key_exists($key, $this->remoteManifests)) {
            $this->remoteManifests[$key] = self::validPlatform($platform)
                ? $this->requestRemoteManifest($image, $platform) : null;
        }
        return $this->remoteManifests[$key];
    }

    /** @return array{configDigest: string, blobUrl: string, header: string[], basicUserPwd: ?string}|null */
    private function requestRemoteManifest(string $image, string $platform): ?array
    {
        try {
            $auth = $this->resolveRegistryAuth($image);
        } catch (RuntimeException $error) {
            composeLogger('Unable to load attached registry credential for update check', [
                'image' => $image,
                'platform' => $platform,
                'credentialId' => $this->credentialId,
            ], 'user', 'error', 'update-check');
            return null;
        }
        $manifestURL = sprintf('%s%s%s/manifests/%s',
            $auth['apiUrl'], $auth['repository'], $auth['imageName'], $auth['imageTag']
        );

        // Step 1: probe for the auth challenge (HEAD is sufficient and cheap).
        $probe = getCurlHandle($manifestURL, 'HEAD');
        $probeReply = curl_exec($probe);
        if (curl_errno($probe) !== 0) return null;

        // Step 2: build the Accept header covering docker + OCI, index + single-arch.
        $accept = 'application/vnd.docker.distribution.manifest.list.v2+json,'
                . 'application/vnd.oci.image.index.v1+json,'
                . 'application/vnd.docker.distribution.manifest.v2+json,'
                . 'application/vnd.oci.image.manifest.v1+json';
        $header = ['Accept: ' . $accept];
        $basicUserPwd = null;

        // Registry authentication: probe the manifest endpoint for auth challenges
        // (Bearer token or Basic auth). If Bearer is required, fetch a token scoped
        // to pull the specific repository. If Basic auth is required, save credentials
        // for subsequent requests. Either way, add auth to the Accept header so the
        // registry returns the actual manifest instead of a 401.
        // Bearer challenge → fetch a token scoped to repository:<repo>:pull.
        if (preg_match('@www-authenticate:\s*Bearer\s*(.*)@i', (string) $probeReply, $m)) {
            $args = [];
            foreach (explode(',', $m[1]) as $arg) {
                $kv = explode('=', $arg, 2);
                $args[trim($kv[0])] = trim($kv[1] ?? '', "\" \r\n");
            }
            if (empty($args['realm']) || empty($args['service']) || empty($args['scope'])) {
                return null;
            }
            $tokenUrl = $args['realm']
                . '?service=' . urlencode($args['service'])
                . '&scope='   . urlencode($args['scope']);
            $tokenCh = getCurlHandle($tokenUrl);
            curl_setopt($tokenCh, CURLOPT_HEADER, false);
            if (!empty($auth['password'])) {
                curl_setopt($tokenCh, CURLOPT_USERPWD, $auth['username'] . ':' . $auth['password']);
            }
            $tokenReply = curl_exec($tokenCh);
            if (curl_errno($tokenCh) !== 0) return null;
            $tokenJson = json_decode((string) $tokenReply, true);
            if (!is_array($tokenJson) || empty($tokenJson['token'])) return null;
            $header[] = 'Authorization: Bearer ' . $tokenJson['token'];
        }

        // Basic challenge → keep credentials for subsequent calls.
        if (preg_match('@www-authenticate:\s*Basic\s*@i', (string) $probeReply)) {
            if (empty($auth['password'])) return null;
            $basicUserPwd = $auth['username'] . ':' . $auth['password'];
        }

        // Step 3: GET the manifest body.
        $manifest = $this->httpGetJson($manifestURL, $header, $basicUserPwd);
        if ($manifest === null) {
            return null;
        }

        $mediaType = (string) ($manifest['mediaType'] ?? '');
        $isIndex = in_array($mediaType, [
            'application/vnd.docker.distribution.manifest.list.v2+json',
            'application/vnd.oci.image.index.v1+json',
        ], true) || !empty($manifest['manifests']);

        if ($isIndex && is_array($manifest['manifests'] ?? null)) {
            $platformDigest = $this->pickPlatformManifestDigest($manifest['manifests'], $platform);
            if (!$platformDigest) {
                return null;
            }
            $platformURL = sprintf('%s%s%s/manifests/%s',
                $auth['apiUrl'], $auth['repository'], $auth['imageName'], $platformDigest
            );
            $manifest = $this->httpGetJson($platformURL, $header, $basicUserPwd);
            if ($manifest === null) {
                return null;
            }
        }

        $configDigest = (string) ($manifest['config']['digest'] ?? '');
        if ($configDigest === '') {
            return null;
        }
        $blobUrl = sprintf('%s%s%s/blobs/%s', $auth['apiUrl'], $auth['repository'], $auth['imageName'], $configDigest);
        if (!$isIndex) {
            $config = $this->httpGetJson($blobUrl, $header, $basicUserPwd);
            if ($config === null || $this->pickPlatformManifestDigest([[
                'platform' => [
                    'os' => $config['os'] ?? '',
                    'architecture' => $config['architecture'] ?? '',
                    'variant' => $config['variant'] ?? '',
                ],
                'digest' => $configDigest,
            ]], $platform) === null) {
                return null;
            }
        }
        return [
            'configDigest' => $configDigest,
            'blobUrl' => $blobUrl,
            'header' => $header,
            'basicUserPwd' => $basicUserPwd,
        ];
    }

    /** @return array<string, string> */
    protected function resolveRegistryAuth(string $image): array
    {
        $auth = (new DockerClient())->getRegistryAuth($image);
        if ($this->credentialId === null || $this->credentialId === '') {
            return $auth;
        }
        return (new CredentialVault())->useCredential($this->credentialId, static function (array $credential) use ($auth): array {
            if (in_array($credential['provider'] ?? '', ['git', 'git-ssh'], true)) {
                throw new RuntimeException('The attached credential is not a registry login.');
            }
            $registry = strtolower($auth['registryName']);
            if (in_array($registry, ['', 'docker.io', 'registry-1.docker.io', 'index.docker.io'], true)) {
                $registry = 'https://index.docker.io/v1/';
            }
            if ($credential['registry'] === $registry) {
                $auth['username'] = $credential['username'];
                $auth['password'] = $credential['secret'];
            }
            return $auth;
        });
    }

    private function httpGetJson(string $url, array $header, ?string $basicUserPwd): ?array
    {
        $ch = getCurlHandle($url, 'GET');
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        // Registries redirect blob downloads to storage hosts; curl drops credentials on cross-host redirects.
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        // Only follow redirects to http(s), never file:// or other schemes.
        if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, 'http,https');
        } else {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if ($basicUserPwd !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $basicUserPwd);
        }
        $body = curl_exec($ch);
        if (curl_errno($ch) !== 0) return null;
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code >= 400) return null;
        $json = json_decode((string) $body, true);
        return is_array($json) ? $json : null;
    }

    private static function hostPlatform(): string
    {
        $archMap = [
            'x86_64'  => 'amd64',
            'amd64'   => 'amd64',
            'aarch64' => 'arm64',
            'arm64'   => 'arm64',
            'armv7l'  => 'arm/v7',
            'armv6l'  => 'arm/v6',
        ];
        return 'linux/' . ($archMap[php_uname('m')] ?? php_uname('m'));
    }

    private static function validPlatform(string $platform): bool
    {
        return preg_match('~^[a-z0-9_.-]+/[a-z0-9_.-]+(?:/[a-z0-9_.-]+)?$~D', $platform) === 1;
    }

    /** Select only the requested platform; never substitute another architecture. */
    private function pickPlatformManifestDigest(array $manifests, ?string $platform = null): ?string
    {
        $platform ??= self::hostPlatform();
        if (!self::validPlatform($platform)) {
            return null;
        }
        [$wantOs, $wantArch] = explode('/', $platform);
        $wantVariant = explode('/', $platform)[2] ?? '';
        foreach ($manifests as $entry) {
            $p = $entry['platform'] ?? [];
            if (($p['architecture'] ?? '') === 'unknown') continue;
            $variant = $p['variant'] ?? '';
            // OCI arm64/v8 and arm64 without a variant describe the same baseline.
            if ($wantArch === 'arm64' && in_array($variant, ['', 'v8'], true)
                && in_array($wantVariant, ['', 'v8'], true)) {
                $variant = $wantVariant;
            }
            if (($p['os'] ?? '') === $wantOs && ($p['architecture'] ?? '') === $wantArch
                && $variant === $wantVariant) {
                if (!empty($entry['digest'])) return (string) $entry['digest'];
            }
        }
        return null;
    }
}
