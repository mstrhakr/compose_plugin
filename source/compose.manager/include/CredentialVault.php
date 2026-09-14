<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';

final class CredentialVault
{
    private const KEY_BYTES = 32;
    private const CIPHER = 'aes-256-gcm';

    /** @return array<int, array<string, string>> */
    public function listCredentials(): array
    {
        return $this->withLock(LOCK_SH, function (): array {
            return array_map(static function (array $credential): array {
                unset($credential['secret']);
                return $credential;
            }, $this->readVault());
        });
    }

    /** @param array<string, string> $input */
    public function saveCredential(array $input): array
    {
        return $this->withLock(LOCK_EX, function () use ($input): array {
            $credentials = $this->readVault();
            $id = trim($input['id'] ?? '');
            $existingIndex = null;
            foreach ($credentials as $index => $credential) {
                if (($credential['id'] ?? '') === $id && $id !== '') {
                    $existingIndex = $index;
                    break;
                }
            }

            $existing = $existingIndex !== null ? $credentials[$existingIndex] : [];
            $secret = trim($input['secret'] ?? '');
            if ($secret === '') {
                $secret = (string) ($existing['secret'] ?? '');
            }

            $name = trim($input['name'] ?? (string) ($existing['name'] ?? ''));
            $registry = self::normalizeRegistry($input['registry'] ?? (string) ($existing['registry'] ?? ''));
            $username = trim($input['username'] ?? (string) ($existing['username'] ?? ''));
            $provider = strtolower(trim($input['provider'] ?? (string) ($existing['provider'] ?? 'generic')));
            // Auth provenance is set at creation time (manual entry vs. OAuth device flow)
            // and must not be overridable by a client-supplied field on update.
            $authMethod = strtolower((string) ($existing['authMethod'] ?? trim($input['authMethod'] ?? 'manual')));
            if ($name === '' || $registry === '' || $username === '' || $secret === '') {
                throw new InvalidArgumentException('Name, registry, username, and token are required.');
            }
            if (!in_array($provider, ['github', 'docker', 'gitlab', 'quay', 'aws', 'azure', 'gcr', 'generic'], true)) {
                throw new InvalidArgumentException('Unsupported credential provider.');
            }
            if (!in_array($authMethod, ['manual', 'oauth_device'], true)) {
                throw new InvalidArgumentException('Unsupported credential authentication method.');
            }

            $now = gmdate('c');
            $credential = [
                // Only an id that matched an existing record is honored; otherwise the
                // vault always generates the id so clients cannot choose their own.
                'id' => $existingIndex !== null ? $id : bin2hex(random_bytes(16)),
                'name' => $name,
                'provider' => $provider,
                'authMethod' => $authMethod,
                'registry' => $registry,
                'username' => $username,
                'secret' => $secret,
                'createdAt' => (string) ($existing['createdAt'] ?? $now),
                'updatedAt' => $now,
            ];


            if ($existingIndex === null) {
                $credentials[] = $credential;
            } else {
                $credentials[$existingIndex] = $credential;
            }
            $this->writeVault($credentials);

            unset($credential['secret']);
            return $credential;
        });
    }

    public function deleteCredential(string $id): bool
    {
        return $this->withLock(LOCK_EX, function () use ($id): bool {
            $credentials = $this->readVault();
            $filtered = array_values(array_filter($credentials, static fn(array $credential): bool => ($credential['id'] ?? '') !== $id));
            if (count($filtered) === count($credentials)) {
                return false;
            }
            $this->writeVault($filtered);
            return true;
        });
    }

    public function hasCredential(string $id): bool
    {
        if ($id === '') {
            return false;
        }
        return $this->withLock(LOCK_SH, function () use ($id): bool {
            foreach ($this->readVault() as $credential) {
                if (($credential['id'] ?? '') === $id) {
                    return true;
                }
            }
            return false;
        });
    }

    public function materializeDockerConfig(string $id): string
    {
        $credential = $this->withLock(LOCK_SH, fn(): array => $this->findCredential($id));
        $baseDir = rtrim(COMPOSE_DOCKER_CONFIG_DIR, '/');
        if (!is_dir($baseDir) && !mkdir($baseDir, 0700, true) && !is_dir($baseDir)) {
            throw new RuntimeException('Unable to create Docker credential directory.');
        }
        chmod($baseDir, 0700);
        self::sweepStaleDockerConfigs($baseDir);

        $directory = $baseDir . '/' . bin2hex(random_bytes(16));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create temporary Docker config.');
        }

        $auth = base64_encode($credential['username'] . ':' . $credential['secret']);
        // Merge onto the operator's existing Docker CLI config so credHelpers,
        // other registries' auths, and proxy settings still apply during the run.
        $config = self::loadExistingDockerConfig();
        if (!isset($config['auths']) || !is_array($config['auths'])) {
            $config['auths'] = [];
        }
        $config['auths'][$credential['registry']] = ['auth' => $auth];

        $json = json_encode($config, JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($directory . '/config.json', $json, LOCK_EX) === false) {
            @rmdir($directory);
            throw new RuntimeException('Unable to write temporary Docker config.');
        }
        chmod($directory . '/config.json', 0600);
        return $directory;
    }

    /** @return array<string, mixed> */
    private static function loadExistingDockerConfig(): array
    {
        $configDir = getenv('DOCKER_CONFIG');
        $existingPath = $configDir !== false && $configDir !== ''
            ? rtrim($configDir, '/') . '/config.json'
            : rtrim((string) (getenv('HOME') ?: '/root'), '/') . '/.docker/config.json';
        if (!is_file($existingPath)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($existingPath), true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function removeDockerConfig(string $directory): void
    {
        $baseDir = realpath(COMPOSE_DOCKER_CONFIG_DIR);
        $target = realpath($directory);
        if ($baseDir === false || $target === false || dirname($target) !== $baseDir) {
            return;
        }
        @unlink($target . '/config.json');
        @rmdir($target);
    }

    /**
     * Remove orphaned Docker config directories left behind by crashed or killed
     * compose operations that never reached their cleanup trap.
     */
    private static function sweepStaleDockerConfigs(string $baseDir, int $maxAgeSeconds = 3600): void
    {
        foreach (glob($baseDir . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $mtime = @filemtime($directory);
            if ($mtime !== false && (time() - $mtime) > $maxAgeSeconds) {
                self::removeDockerConfig($directory);
            }
        }
    }

    private static function normalizeRegistry(string $registry): string
    {
        $registry = strtolower(trim($registry));
        $registry = preg_replace('#^https?://#', '', $registry) ?? '';
        $registry = rtrim($registry, '/');
        if ($registry === 'docker.io' || $registry === 'registry-1.docker.io') {
            return 'https://index.docker.io/v1/';
        }
        if ($registry === '' || preg_match('/[\s?#]/', $registry)) {
            return '';
        }
        return $registry;
    }

    /** @return array<string, string> */
    private function findCredential(string $id): array
    {
        foreach ($this->readVault() as $credential) {
            if (($credential['id'] ?? '') === $id) {
                return $credential;
            }
        }
        throw new RuntimeException('Selected credential no longer exists.');
    }

    /** @return array<int, array<string, string>> */
    private function readVault(): array
    {
        if (!is_file(COMPOSE_CREDENTIAL_VAULT_FILE)) {
            return [];
        }
        $payload = json_decode((string) file_get_contents(COMPOSE_CREDENTIAL_VAULT_FILE), true);
        if (!is_array($payload) || !isset($payload['ciphertext'])) {
            throw new RuntimeException('Credential vault is invalid.');
        }
        $ciphertext = base64_decode((string) $payload['ciphertext'], true);
        if ($ciphertext === false) {
            throw new RuntimeException('Credential vault is invalid.');
        }

        if (($payload['algorithm'] ?? '') === self::CIPHER) {
            $iv = base64_decode((string) ($payload['iv'] ?? ''), true);
            $tag = base64_decode((string) ($payload['tag'] ?? ''), true);
            if ($iv === false || $tag === false || !function_exists('openssl_decrypt')) {
                throw new RuntimeException('Credential vault is invalid.');
            }
            $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->loadKey(), OPENSSL_RAW_DATA, $iv, $tag);
        } else {
            $nonce = base64_decode((string) ($payload['nonce'] ?? ''), true);
            if ($nonce === false || !function_exists('sodium_crypto_secretbox_open')) {
                throw new RuntimeException('Legacy credential vault requires the PHP sodium extension.');
            }
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->loadKey());
        }
        if (!is_string($plaintext)) {
            throw new RuntimeException('Credential vault could not be decrypted.');
        }
        $credentials = json_decode($plaintext, true);
        return is_array($credentials) ? array_values($credentials) : [];
    }

    /** @param array<int, array<string, string>> $credentials */
    private function writeVault(array $credentials): void
    {
        $directory = dirname(COMPOSE_CREDENTIAL_VAULT_FILE);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create credential storage directory.');
        }
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('Credential encryption requires the PHP OpenSSL extension.');
        }
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false) {
            throw new RuntimeException('Credential encryption is unavailable.');
        }
        $iv = random_bytes($ivLength);
        $plaintext = json_encode(array_values($credentials), JSON_UNESCAPED_SLASHES);
        if ($plaintext === false) {
            throw new RuntimeException('Unable to encode credential vault.');
        }
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->loadKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt credential vault.');
        }
        $payload = json_encode([
            'version' => 2,
            'algorithm' => self::CIPHER,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('Unable to encode credential vault.');
        }
        self::atomicWrite(COMPOSE_CREDENTIAL_VAULT_FILE, $payload, 0600, 'Unable to save credential vault.');
    }

    private function loadKey(): string
    {
        if (is_file(COMPOSE_CREDENTIAL_KEY_FILE)) {
            $key = base64_decode(trim((string) file_get_contents(COMPOSE_CREDENTIAL_KEY_FILE)), true);
            if ($key !== false && strlen($key) === self::KEY_BYTES) {
                return $key;
            }
            throw new RuntimeException('Credential encryption key is invalid.');
        }
        $directory = dirname(COMPOSE_CREDENTIAL_KEY_FILE);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create credential storage directory.');
        }
        $key = random_bytes(self::KEY_BYTES);
        self::atomicWrite(COMPOSE_CREDENTIAL_KEY_FILE, base64_encode($key), 0600, 'Unable to save credential encryption key.');
        return $key;
    }

    /**
     * Write via a same-directory temp file + rename so a crash or power loss
     * mid-write cannot corrupt the existing vault/key file.
     */
    private static function atomicWrite(string $path, string $contents, int $mode, string $errorMessage): void
    {
        $tmpPath = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($tmpPath, $contents, LOCK_EX) === false) {
            @unlink($tmpPath);
            throw new RuntimeException($errorMessage);
        }
        chmod($tmpPath, $mode);
        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new RuntimeException($errorMessage);
        }
    }

    /**
     * Execute a callback while holding a lock on the credential vault lockfile.
     *
     * @template T
     * @param int $lockType LOCK_SH or LOCK_EX
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(int $lockType, callable $callback)
    {
        $lockDir = dirname(COMPOSE_CREDENTIAL_VAULT_FILE);
        if (!is_dir($lockDir) && !mkdir($lockDir, 0700, true) && !is_dir($lockDir)) {
            throw new RuntimeException('Unable to create credential storage directory.');
        }
        $lockFile = $lockDir . '/credentials.lock';
        $handle = fopen($lockFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open credential lock.');
        }
        if (!flock($handle, $lockType)) {
            fclose($handle);
            throw new RuntimeException('Unable to acquire credential lock.');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}