<?php

require_once __DIR__ . '/Defines.php';
require_once __DIR__ . '/Util.php';
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
 *   - Only entries for the passed images are read/written in the shared
 *     unraid-update-status.json file. Entries for non-compose images are
 *     untouched (no interference with the native Docker tab's state).
 *
 * Also see the parallel upstream fix for the native Docker tab in the
 * dynamix.docker.manager DockerUpdate class. This plugin-local
 * implementation exists so the Compose Manager UI shows correct results
 * without waiting for that upstream change to merge and ship.
 */
class ComposeUpdateCheck
{
    /** @var string Path to the shared Unraid update-status JSON file. */
    private string $statusFile;

    public function __construct(?string $statusFile = null)
    {
        $this->statusFile = $statusFile ?? UNRAID_UPDATE_STATUS_FILE;
    }

    /**
     * Check a single image and persist its entry.
     *
     * @param string $image Image reference (e.g. "redis:8", "ghcr.io/foo/bar:latest").
     * @return array{local: string, remote: string, status: string, hasUpdate: bool}
     */
    public function check(string $image): array
    {
        $results = $this->checkMany([$image]);
        $key = ContainerInfo::normalizeImageForUpdateCheck($image);
        return $results[$key] ?? [
            'local' => '', 'remote' => '', 'status' => 'unknown', 'hasUpdate' => false,
        ];
    }

    /**
     * Check a batch of images. Reads and writes the shared status file
     * exactly once around the batch, and only touches entries for the
     * provided images. Duplicate images in $images are checked only once.
     *
     * @param string[] $images
     * @return array<string, array{local: string, remote: string, status: string, hasUpdate: bool}>
     *   Keys are normalized image names.
     */
    public function checkMany(array $images): array
    {
        $status = $this->loadStatus();
        $results = [];
        $dirty = false;

        foreach ($images as $rawImage) {
            $image = ContainerInfo::normalizeImageForUpdateCheck((string) $rawImage);
            if ($image === '' || isset($results[$image])) {
                continue;
            }

            $local  = $this->inspectLocalConfigDigest($image);
            $remote = $this->fetchRemoteConfigDigest($image);
            $summary = $this->summarize($local, $remote);

            $status[$image] = [
                'local'  => (string) ($local ?? ''),
                'remote' => (string) ($remote ?? ''),
                'status' => $summary['statusRaw'],
            ];
            $dirty = true;
            $results[$image] = $summary['result'];
        }

        if ($dirty) {
            $this->saveStatus($status);
        }
        return $results;
    }

    /**
     * Read the currently persisted status entry for an image (if any).
     * Does not perform any network or docker calls.
     *
     * @return array{local: string, remote: string, status: string}|null
     */
    public function readStatus(string $image): ?array
    {
        $image = ContainerInfo::normalizeImageForUpdateCheck($image);
        $status = $this->loadStatus();
        $entry = $status[$image] ?? null;
        if (!is_array($entry)) return null;
        return [
            'local'  => (string) ($entry['local']  ?? ''),
            'remote' => (string) ($entry['remote'] ?? ''),
            'status' => (string) ($entry['status'] ?? 'undef'),
        ];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function summarize(?string $local, ?string $remote): array
    {
        if (!$local || !$remote) {
            return [
                'statusRaw' => 'undef',
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
            'statusRaw' => $upToDate ? 'true' : 'false',
            'result' => [
                'local'     => $local,
                'remote'    => $remote,
                'status'    => $upToDate ? 'up-to-date' : 'update-available',
                'hasUpdate' => !$upToDate,
            ],
        ];
    }

    private function loadStatus(): array
    {
        if (class_exists('DockerUtil') && method_exists('DockerUtil', 'loadJSON')) {
            $loaded = DockerUtil::loadJSON($this->statusFile);
            return is_array($loaded) ? $loaded : [];
        }
        if (is_file($this->statusFile)) {
            $decoded = json_decode((string) @file_get_contents($this->statusFile), true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function saveStatus(array $status): void
    {
        if (class_exists('DockerUtil') && method_exists('DockerUtil', 'saveJSON')) {
            DockerUtil::saveJSON($this->statusFile, $status);
            return;
        }
        @file_put_contents($this->statusFile, json_encode($status, JSON_PRETTY_PRINT));
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
     * If any step fails (transport, parse, missing config.digest, etc.)
     * falls back to the legacy Docker-Content-Digest HEAD behavior so
     * detection can never be worse than upstream's pre-fix code.
     */
    private function fetchRemoteConfigDigest(string $image): ?string
    {
        $client = new DockerClient();
        $auth   = $client->getRegistryAuth($image);
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
            return $this->fetchDigestHead($manifestURL, $header, $basicUserPwd);
        }

        $mediaType = (string) ($manifest['mediaType'] ?? '');
        $isIndex = in_array($mediaType, [
            'application/vnd.docker.distribution.manifest.list.v2+json',
            'application/vnd.oci.image.index.v1+json',
        ], true) || !empty($manifest['manifests']);

        if ($isIndex && is_array($manifest['manifests'] ?? null)) {
            $platformDigest = $this->pickPlatformManifestDigest($manifest['manifests']);
            if (!$platformDigest) {
                return $this->fetchDigestHead($manifestURL, $header, $basicUserPwd);
            }
            $platformURL = sprintf('%s%s%s/manifests/%s',
                $auth['apiUrl'], $auth['repository'], $auth['imageName'], $platformDigest
            );
            $platformManifest = $this->httpGetJson($platformURL, $header, $basicUserPwd);
            if ($platformManifest === null) {
                return $this->fetchDigestHead($manifestURL, $header, $basicUserPwd);
            }
            $configDigest = (string) ($platformManifest['config']['digest'] ?? '');
            return $configDigest !== ''
                ? $configDigest
                : $this->fetchDigestHead($manifestURL, $header, $basicUserPwd);
        }

        // Single-arch manifest.
        $configDigest = (string) ($manifest['config']['digest'] ?? '');
        return $configDigest !== ''
            ? $configDigest
            : $this->fetchDigestHead($manifestURL, $header, $basicUserPwd);
    }

    private function httpGetJson(string $url, array $header, ?string $basicUserPwd): ?array
    {
        $ch = getCurlHandle($url, 'GET');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
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

    private function fetchDigestHead(string $url, array $header, ?string $basicUserPwd): ?string
    {
        $ch = getCurlHandle($url, 'HEAD');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        if ($basicUserPwd !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $basicUserPwd);
        }
        $reply = curl_exec($ch);
        if (curl_errno($ch) !== 0) return null;
        if (preg_match('@Docker-Content-Digest:\s*(.*)@i', (string) $reply, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Pick the manifest entry matching the running host's platform.
     * Preferences (in order):
     *   1. Exact match on os=linux + architecture=<host arch>.
     *   2. linux/amd64 as a safe fallback (Unraid is amd64 today; future-
     *      proofed for ARM Unraid via the arch map).
     *   3. First non-attestation entry with a digest.
     *
     * Attestation manifests (architecture=="unknown", added by buildx for
     * SBOM/provenance) are always skipped so they can't be selected as the
     * "image" to compare.
     */
    private function pickPlatformManifestDigest(array $manifests): ?string
    {
        $archMap = [
            'x86_64'  => 'amd64',
            'amd64'   => 'amd64',
            'aarch64' => 'arm64',
            'arm64'   => 'arm64',
            'armv7l'  => 'arm',
            'armv6l'  => 'arm',
        ];
        $wantArch = $archMap[php_uname('m')] ?? 'amd64';
        $wantOs   = 'linux';

        foreach ($manifests as $entry) {
            $p = $entry['platform'] ?? [];
            if (($p['architecture'] ?? '') === 'unknown') continue;
            if (($p['os'] ?? '') === $wantOs && ($p['architecture'] ?? '') === $wantArch) {
                if (!empty($entry['digest'])) return (string) $entry['digest'];
            }
        }
        foreach ($manifests as $entry) {
            $p = $entry['platform'] ?? [];
            if (($p['architecture'] ?? '') === 'unknown') continue;
            if (($p['os'] ?? '') === 'linux' && ($p['architecture'] ?? '') === 'amd64') {
                if (!empty($entry['digest'])) return (string) $entry['digest'];
            }
        }
        foreach ($manifests as $entry) {
            $p = $entry['platform'] ?? [];
            if (($p['architecture'] ?? '') === 'unknown') continue;
            if (!empty($entry['digest'])) return (string) $entry['digest'];
        }
        return null;
    }
}
