<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/CredentialVault.php';

final class GitHubDeviceAuth
{
    private string $clientId;
    private CredentialVault $vault;
    /** @var callable */
    private $request;

    public function __construct(string $clientId, ?CredentialVault $vault = null, ?callable $request = null)
    {
        $this->clientId = trim($clientId);
        $this->vault = $vault ?? new CredentialVault();
        $this->request = $request ?? [$this, 'curlRequest'];
    }

    /** @return array<string, mixed> */
    public function start(): array
    {
        if ($this->clientId === '') {
            throw new RuntimeException('GitHub sign-in is not configured.');
        }
        $response = ($this->request)('POST', 'https://github.com/login/device/code', [
            'client_id' => $this->clientId,
            'scope' => 'read:packages',
        ], []);
        foreach (['device_code', 'user_code', 'verification_uri', 'expires_in'] as $field) {
            if (empty($response[$field])) {
                throw new RuntimeException('GitHub returned an invalid device authorization response.');
            }
        }
        $state = bin2hex(random_bytes(24));
        $interval = max(5, (int) ($response['interval'] ?? 5));
        $this->writeState($state, [
            'deviceCode' => (string) $response['device_code'],
            'expiresAt' => time() + (int) $response['expires_in'],
            'interval' => $interval,
            'nextPollAt' => 0,
        ]);
        return [
            'state' => $state,
            'userCode' => (string) $response['user_code'],
            'verificationUri' => (string) $response['verification_uri'],
            'expiresIn' => (int) $response['expires_in'],
            'interval' => $interval,
        ];
    }

    /** @return array<string, mixed> */
    public function poll(string $state): array
    {
        $session = $this->readState($state);
        if ((int) $session['expiresAt'] <= time()) {
            $this->deleteState($state);
            return ['status' => 'expired'];
        }
        if ((int) $session['nextPollAt'] > time()) {
            return ['status' => 'pending', 'interval' => (int) $session['interval']];
        }
        $session['nextPollAt'] = time() + (int) $session['interval'];
        $this->writeState($state, $session);

        $response = ($this->request)('POST', 'https://github.com/login/oauth/access_token', [
            'client_id' => $this->clientId,
            'device_code' => (string) $session['deviceCode'],
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        ], []);
        $error = (string) ($response['error'] ?? '');
        if ($error === 'authorization_pending') {
            return ['status' => 'pending', 'interval' => (int) $session['interval']];
        }
        if ($error === 'slow_down') {
            $session['interval'] = (int) $session['interval'] + 5;
            $this->writeState($state, $session);
            return ['status' => 'pending', 'interval' => (int) $session['interval']];
        }
        if ($error !== '') {
            $this->deleteState($state);
            return ['status' => $error === 'access_denied' ? 'denied' : 'expired'];
        }
        $token = trim((string) ($response['access_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('GitHub did not return an access token.');
        }
        $user = ($this->request)('GET', 'https://api.github.com/user', [], [
            'Authorization: Bearer ' . $token,
            'X-GitHub-Api-Version: 2022-11-28',
        ]);
        $username = trim((string) ($user['login'] ?? ''));
        if ($username === '') {
            throw new RuntimeException('Unable to read the authorized GitHub account.');
        }
        $credential = $this->vault->saveCredential([
            'name' => 'GitHub - ' . $username,
            'provider' => 'github',
            'authMethod' => 'oauth_device',
            'registry' => 'ghcr.io',
            'username' => $username,
            'secret' => $token,
        ]);
        $this->deleteState($state);
        return ['status' => 'success', 'credential' => $credential];
    }

    /** @param array<string, string> $data @param string[] $headers @return array<string, mixed> */
    private function curlRequest(string $method, string $url, array $data, array $headers): array
    {
        $handle = curl_init($url);
        $requestHeaders = array_merge(['Accept: application/json', 'User-Agent: Compose-Manager'], $headers);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $requestHeaders,
        ]);
        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);
        if ($body === false || $curlError !== '' || $status < 200 || $status >= 300) {
            throw new RuntimeException('GitHub authentication request failed.');
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('GitHub returned an invalid response.');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $session */
    private function writeState(string $state, array $session): void
    {
        if (!is_dir(COMPOSE_GITHUB_DEVICE_DIR) && !mkdir(COMPOSE_GITHUB_DEVICE_DIR, 0700, true) && !is_dir(COMPOSE_GITHUB_DEVICE_DIR)) {
            throw new RuntimeException('Unable to create GitHub sign-in session.');
        }
        chmod(COMPOSE_GITHUB_DEVICE_DIR, 0700);
        file_put_contents(COMPOSE_GITHUB_DEVICE_DIR . '/' . $state . '.json', json_encode($session), LOCK_EX);
        chmod(COMPOSE_GITHUB_DEVICE_DIR . '/' . $state . '.json', 0600);
    }

    /** @return array<string, mixed> */
    private function readState(string $state): array
    {
        if (preg_match('/^[a-f0-9]{48}$/', $state) !== 1) {
            throw new InvalidArgumentException('Invalid GitHub sign-in session.');
        }
        $path = COMPOSE_GITHUB_DEVICE_DIR . '/' . $state . '.json';
        $session = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($session)) {
            throw new RuntimeException('GitHub sign-in session expired.');
        }
        return $session;
    }

    private function deleteState(string $state): void
    {
        if (preg_match('/^[a-f0-9]{48}$/', $state) === 1) {
            @unlink(COMPOSE_GITHUB_DEVICE_DIR . '/' . $state . '.json');
        }
    }
}