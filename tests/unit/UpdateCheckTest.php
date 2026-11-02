<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/UpdateCheck.php';

class UpdateCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(COMPOSE_CREDENTIAL_VAULT_FILE);
        @unlink(COMPOSE_CREDENTIAL_KEY_FILE);
    }

    public function testAttachedCredentialsAreScopedToTheirRegistryAndChecker(): void
    {
        $vault = new \CredentialVault();
        $first = $vault->saveCredential([
            'name' => 'First', 'provider' => 'github', 'registry' => 'ghcr.io',
            'username' => 'first', 'secret' => 'first-test-token',
        ]);
        $second = $vault->saveCredential([
            'name' => 'Second', 'provider' => 'github', 'registry' => 'ghcr.io',
            'username' => 'second', 'secret' => 'second-test-token',
        ]);
        $hub = $vault->saveCredential([
            'name' => 'Hub', 'provider' => 'docker', 'registry' => 'docker.io',
            'username' => 'hub', 'secret' => 'hub-test-token',
        ]);
        $resolve = new \ReflectionMethod(\ComposeUpdateCheck::class, 'resolveRegistryAuth');
        foreach ([$first, $second, $first] as $credential) {
            $auth = $resolve->invoke(new \ComposeUpdateCheck(null, $credential['id']), 'ghcr.io/org/private:latest');
            $this->assertSame(strtolower($credential['name']), $auth['username']);
            $this->assertSame(strtolower($credential['name']) . '-test-token', $auth['password']);
        }
        $checker = new \ComposeUpdateCheck(null, $first['id']);
        $this->assertSame(
            $resolve->invoke(new \ComposeUpdateCheck(), 'quay.io/org/public:latest'),
            $resolve->invoke($checker, 'quay.io/org/public:latest')
        );
        foreach (['postgres:18', 'docker.io/library/postgres:18'] as $image) {
            $auth = $resolve->invoke(new \ComposeUpdateCheck(null, $hub['id']), $image);
            $this->assertSame('hub', $auth['username']);
            $this->assertSame('hub-test-token', $auth['password']);
        }
        $this->assertStringNotContainsString('test-token', implode("\n", $GLOBALS['composeLoggerMessages'] ?? []));
    }

    public static function registryAuthCases(): array
    {
        return [
            'basic' => ['basic', false],
            'bearer' => ['bearer', false],
            'rejected basic' => ['basic', true],
            'rejected bearer' => ['bearer', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('registryAuthCases')]
    public function testPrivateRegistryHandshakeUsesAttachedCredential(string $mode, bool $reject): void
    {
        $directory = $this->createTempDir();
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($socket, $error);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->assertIsString($address);
        $router = $directory . '/registry.php';
        file_put_contents($router, '<?php
            $authorization = $_SERVER["HTTP_AUTHORIZATION"] ?? "";
            $basic = "Basic " . base64_encode("registry-user:fixture-token");
            $mode = ' . var_export($mode, true) . ';
            $reject = ' . var_export($reject, true) . ';
            if ($mode === "bearer" && str_starts_with($_SERVER["REQUEST_URI"], "/token")) {
                if ($reject || $authorization !== $basic) { http_response_code(401); exit; }
                echo json_encode(["token" => "fixture-bearer"]);
                exit;
            }
            if ($reject || $authorization !== ($mode === "basic" ? $basic : "Bearer fixture-bearer")) {
                http_response_code(401);
                header($mode === "basic" ? \'WWW-Authenticate: Basic realm="fixture"\'
                    : \'WWW-Authenticate: Bearer realm="http://' . $address . '/token",service="fixture",scope="repository:org/private:pull"\');
                exit;
            }
            echo json_encode(["config" => ["digest" => "sha256:new"]]);
        ');
        $process = proc_open([PHP_BINARY, '-S', $address, $router], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                if ($connection !== false) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($ready, 'Fixture registry did not start.');
            $saved = (new \CredentialVault())->saveCredential([
                'name' => 'Private registry', 'provider' => 'github', 'registry' => 'ghcr.io',
                'username' => 'registry-user', 'secret' => 'fixture-token',
            ]);
            $checker = new class($directory . '/status.json', $saved['id']) extends \ComposeUpdateCheck {
                public string $endpoint;
                protected function resolveRegistryAuth(string $image): array
                {
                    $auth = parent::resolveRegistryAuth($image);
                    $auth['apiUrl'] = $this->endpoint . '/v2/';
                    return $auth;
                }
            };
            $checker->endpoint = 'http://' . $address;
            \DockerClient::setMockResponse('/images/' . rawurlencode('ghcr.io/org/private:latest') . '/json', ['Id' => 'sha256:new']);
            \DockerClient::setMockResponse('/containers/private-app/json', ['Image' => 'sha256:old']);
            $results = $checker->checkContainers([['Name' => 'private-app', 'Image' => 'ghcr.io/org/private:latest']]);
            $this->assertSame($reject ? 'unknown' : 'update-available', $results['private-app']['status']);
            $this->assertSame(!$reject, $results['private-app']['hasUpdate']);
            $this->assertSame($reject ? '' : 'sha256:new', $results['private-app']['remote']);
            $cached = json_decode((string) file_get_contents($directory . '/status.json'), true);
            $this->assertSame($reject ? '' : 'sha256:new', $cached['ghcr.io/org/private:latest']['configDigest']);
            $this->assertStringNotContainsString('fixture-token', json_encode($cached));
            $this->assertStringNotContainsString('fixture-token', implode("\n", $GLOBALS['composeLoggerMessages'] ?? []));
        } finally {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    public function testMissingAttachedCredentialFailsExplicitlyWithoutAnonymousFallback(): void
    {
        $checker = new \ComposeUpdateCheck($this->createTempDir() . '/status.json', 'missing');
        $result = $checker->check('ghcr.io/org/private:latest');
        $this->assertSame('unknown', $result['status']);
        $this->assertContains('Unable to load attached registry credential for update check', $GLOBALS['composeLoggerMessages']);
    }

    public function testGitCredentialCannotBeUsedAsARegistryLogin(): void
    {
        $credential = (new \CredentialVault())->saveCredential([
            'name' => 'Repository only', 'provider' => 'git', 'registry' => 'ghcr.io',
            'username' => 'user', 'secret' => 'repository-only-token',
        ]);
        $checker = new \ComposeUpdateCheck($this->createTempDir() . '/status.json', $credential['id']);
        $result = $checker->check('ghcr.io/org/private:latest');
        $this->assertSame('unknown', $result['status']);
        $this->assertContains('Unable to load attached registry credential for update check', $GLOBALS['composeLoggerMessages']);
        $this->assertStringNotContainsString('repository-only-token', implode("\n", $GLOBALS['composeLoggerMessages']));
    }

    public function testContainersUsingTheSameTagAreComparedByTheirActualImageIds(): void
    {
        $checker = new class extends \ComposeUpdateCheck {
            public array $checkedImages = [];

            public function checkMany(array $images): array
            {
                $this->checkedImages = $images;
                return ['library/postgres:18' => [
                    'local' => 'sha256:current',
                    'remote' => 'sha256:current',
                    'status' => 'up-to-date',
                    'hasUpdate' => false,
                ]];
            }
        };
        \DockerClient::setMockResponse('/containers/old-db/json', ['Image' => 'sha256:old']);
        \DockerClient::setMockResponse('/containers/current-db/json', ['Image' => 'sha256:current']);

        $results = $checker->checkContainers([
            ['Name' => 'old-db', 'Image' => 'postgres:18'],
            ['Name' => 'current-db', 'Image' => 'docker.io/library/postgres:18'],
            ['Name' => 'missing-db', 'Image' => 'postgres:18'],
        ]);

        $this->assertSame(['postgres:18', 'docker.io/library/postgres:18', 'postgres:18'], $checker->checkedImages);
        $this->assertTrue($results['old-db']['hasUpdate']);
        $this->assertSame('sha256:old', $results['old-db']['local']);
        $this->assertSame('update-available', $results['old-db']['status']);
        $this->assertFalse($results['current-db']['hasUpdate']);
        $this->assertSame('up-to-date', $results['current-db']['status']);
        $this->assertSame('unknown', $results['missing-db']['status']);
        $this->assertContains('Container image digest unavailable', $GLOBALS['composeLoggerMessages']);
    }

    public function testManifestJsonDoesNotIncludeHttpHeaders(): void
    {
        $file = $this->createTempDir() . '/manifest.json';
        $manifest = ['config' => ['digest' => 'sha256:config']];
        file_put_contents($file, json_encode($manifest));
        $checker = new \ComposeUpdateCheck();
        $getJson = new \ReflectionMethod($checker, 'httpGetJson');

        $this->assertSame($manifest, $getJson->invoke($checker, 'file://' . $file, [], null));
    }

    public function testDifferentConfigDigestsHaveAnUpdate(): void
    {
        $checker = new \ComposeUpdateCheck();
        $summarize = new \ReflectionMethod($checker, 'summarize');
        $result = $summarize->invoke($checker, 'sha256:old', 'sha256:new');

        $this->assertTrue($result['result']['hasUpdate']);
        $this->assertSame('update-available', $result['result']['status']);
    }

    public function testSameConfigDigestDoesNotHaveAnUpdate(): void
    {
        $checker = new \ComposeUpdateCheck();
        $summarize = new \ReflectionMethod($checker, 'summarize');
        $result = $summarize->invoke($checker, 'sha256:same', 'sha256:same');

        $this->assertFalse($result['result']['hasUpdate']);
        $this->assertSame('up-to-date', $result['result']['status']);
    }

    public function testMissingDigestIsUnknownRatherThanUpToDate(): void
    {
        $checker = new \ComposeUpdateCheck();
        $summarize = new \ReflectionMethod($checker, 'summarize');
        foreach ([[null, 'sha256:remote'], ['sha256:local', null]] as [$local, $remote]) {
            $result = $summarize->invoke($checker, $local, $remote);
            $this->assertSame('unknown', $result['result']['status']);
            $this->assertSame('undef', $result['statusRaw']);
        }
    }
}
