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
        $this->assertSame('ready-to-apply', $results['old-db']['updateReadiness']);
        $this->assertSame('current', $results['current-db']['updateReadiness']);
        $this->assertSame('unknown', $results['missing-db']['updateReadiness']);
        $this->assertFalse($results['current-db']['hasUpdate']);
        $this->assertSame('up-to-date', $results['current-db']['status']);
        $this->assertSame('unknown', $results['missing-db']['status']);
        $this->assertContains('Container image digest unavailable', $GLOBALS['composeLoggerMessages']);
    }

    public static function readinessCases(): array
    {
        return [
            'current' => ['a', 'a', 'a', 'current'],
            'current despite moved local tag' => ['a', 'b', 'a', 'current'],
            'downloaded' => ['a', 'b', 'b', 'ready-to-apply'],
            'remote newer' => ['a', 'a', 'b', 'download-required'],
            'local intermediate' => ['a', 'b', 'c', 'download-required'],
            'local missing' => ['a', null, 'b', 'download-required'],
            'remote unavailable' => ['a', 'b', null, 'unknown'],
            'container unavailable' => [null, 'b', 'b', 'unknown'],
            'full digest comparison' => ['sha256:123456789012a', 'sha256:123456789012b', 'sha256:123456789012c', 'download-required'],
        ];
    }

    public function testPinnedContainersNeverCheckOrOfferTheMutableTag(): void
    {
        $checker = new class extends \ComposeUpdateCheck {
            public function checkMany(array $images): array
            {
                throw new \LogicException('Pinned images must not query mutable tags.');
            }
        };
        \DockerClient::setMockResponse('/containers/pinned/json', ['Image' => 'sha256:old']);
        $result = $checker->checkContainers([[
            'Name' => 'pinned', 'Image' => 'alpine:latest@sha256:pinned',
        ]])['pinned'];
        $this->assertFalse($result['hasUpdate']);
        $this->assertSame('up-to-date', $result['status']);
        $this->assertSame('current', $result['updateReadiness']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('readinessCases')]
    public function testImageReadinessUsesThreeIndependentImageIds(?string $container, ?string $local, ?string $remote, string $expected): void
    {
        $this->assertSame($expected, \ComposeUpdateCheck::imageReadiness($container, $local, $remote));
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

    public static function dockerfileBaseCases(): array
    {
        return [
            'single stage with syntax directive' => [
                "# syntax=docker/dockerfile:1.7\nFROM lscr.io/linuxserver/code-server:latest\n\nARG TARGETARCH\nRUN echo \\\n  FROM nope\n",
                [], 'lscr.io/linuxserver/code-server:latest',
            ],
            'multi-stage follows final stage alias' => [
                "FROM golang:1.22 AS build\nRUN go build\nFROM alpine:3.20 AS base\nFROM base AS final\nCOPY --from=build /app /app\n",
                [], 'alpine:3.20',
            ],
            'target selects stage' => [
                "FROM node:20 AS dev\nFROM nginx:1.27 AS prod\n",
                ['target' => 'dev'], 'node:20',
            ],
            'global arg default and compose override' => [
                "ARG BASE=debian\nARG TAG=12\nFROM --platform=\$BUILDPLATFORM \${BASE}:\${TAG:-11}\n",
                ['args' => ['TAG' => 'bookworm']], 'debian:bookworm',
            ],
            'unresolved arg is unknown' => ["FROM \${MISSING}\n", [], null],
            'scratch' => ["FROM alpine AS a\nFROM scratch\nCOPY --from=a / /\n", [], 'scratch'],
            'heredoc body is ignored' => ["FROM alpine:3\nRUN <<EOF\nFROM ubuntu\nEOF\n", [], 'alpine:3'],
            'quoted dash heredoc is ignored' => ["FROM golang:1 AS b\nCOPY <<-\"END\" /x\n\tFROM ubuntu\n\tEND\nFROM alpine:3\n", [], 'alpine:3'],
            'here-string is not a heredoc' => ["FROM golang:1 AS b\nRUN read x <<<hello\nFROM alpine:3.20\n", [], 'alpine:3.20'],
            'shell shift is not a heredoc' => ["FROM golang:1 AS b\nRUN echo \$((1<<2))\nFROM alpine:3.20\n", [], 'alpine:3.20'],
            'blank line inside continuation' => ["FROM alpine:3.20\nRUN echo \\\n\n    FROM ubuntu\n", [], 'alpine:3.20'],
            'arg default uses earlier arg' => ["ARG TAG=3.20\nARG IMG=alpine:\${TAG}\nFROM \${IMG}\n", [], 'alpine:3.20'],
            'compose arg feeds chained default' => ["ARG TAG=3.20\nARG IMG=alpine:\${TAG}\nFROM \${IMG}\n", ['args' => ['TAG' => '3.19']], 'alpine:3.19'],
            'empty arg default' => ["ARG SUFFIX=\nFROM alpine\${SUFFIX}:3\n", [], 'alpine:3'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dockerfileBaseCases')]
    public function testDockerfileBaseImageResolution(string $dockerfile, array $build, ?string $expected): void
    {
        $context = $this->createTempDir();
        file_put_contents($context . '/Dockerfile', $dockerfile);
        $this->assertSame($expected, \ComposeUpdateCheck::resolveDockerfileBaseImage($build + ['context' => $context]));
        $this->assertSame($expected, \ComposeUpdateCheck::resolveDockerfileBaseImage($build + ['dockerfile_inline' => $dockerfile]));
    }

    public function testCustomDockerfilePathAndMissingContext(): void
    {
        $context = $this->createTempDir();
        mkdir($context . '/docker');
        file_put_contents($context . '/docker/app.Dockerfile', "FROM redis:8\n");
        $this->assertSame('redis:8', \ComposeUpdateCheck::resolveDockerfileBaseImage(['context' => $context, 'dockerfile' => 'docker/app.Dockerfile']));
        $this->assertNull(\ComposeUpdateCheck::resolveDockerfileBaseImage(['context' => 'https://github.com/org/repo.git']));
    }

    public function testBuildOnlyContainersAreCheckedAgainstTheirBaseImage(): void
    {
        $context = $this->createTempDir();
        file_put_contents($context . '/Dockerfile', "FROM lscr.io/linuxserver/code-server:latest\nRUN true\n");
        $checker = new class extends \ComposeUpdateCheck {
            public array $checkedImages = [];
            public array $fetched = [];

            public function checkMany(array $images): array
            {
                $this->checkedImages = $images;
                return [];
            }

            protected function fetchRemoteImageConfig(string $image): ?array
            {
                $this->fetched[] = $image;
                return ['digest' => 'sha256:base-new', 'diffIds' => ['sha256:l1', 'sha256:l2']];
            }
        };
        \DockerClient::setMockResponse('/containers/current/json', ['Image' => 'sha256:built-current']);
        \DockerClient::setMockResponse('/containers/stale/json', ['Image' => 'sha256:built-stale']);
        \DockerClient::setMockResponse('/images/' . rawurlencode('sha256:built-current') . '/json', ['RootFS' => ['Layers' => ['sha256:l1', 'sha256:l2', 'sha256:mine']]]);
        \DockerClient::setMockResponse('/images/' . rawurlencode('sha256:built-stale') . '/json', ['RootFS' => ['Layers' => ['sha256:l1', 'sha256:old', 'sha256:mine']]]);

        $build = ['build' => ['context' => $context, 'dockerfile' => 'Dockerfile'], 'image' => null];
        \DockerClient::setMockResponse('/images/' . rawurlencode('stack-worker') . '/json', ['Id' => 'sha256:built-stale']);
        \DockerClient::setMockResponse('/images/' . rawurlencode('lscr.io/linuxserver/code-server:latest') . '/json', ['Id' => 'sha256:base-new']);
        $results = $checker->checkContainers([
            ['Name' => 'current', 'Service' => 'app', 'Image' => 'stack-app'],
            ['Names' => 'stale', 'Labels' => 'com.docker.compose.service=worker,com.docker.compose.project=stack', 'Image' => 'stack-worker'],
        ], ['app' => $build, 'worker' => $build]);

        $this->assertSame([], $checker->checkedImages, 'Build-only images must not be looked up on a registry.');
        $this->assertSame(['lscr.io/linuxserver/code-server:latest'], $checker->fetched);
        $this->assertSame('up-to-date', $results['current']['status']);
        $this->assertFalse($results['current']['hasUpdate']);
        $this->assertSame('update-available', $results['stale']['status']);
        $this->assertTrue($results['stale']['hasUpdate']);
        $this->assertSame('sha256:base-new', $results['stale']['remote']);
        $this->assertSame('sha256:built-stale', $results['stale']['local']);
        $this->assertSame('rebuild-required', $results['stale']['updateReadiness']);
        $this->assertSame('build-base', $results['stale']['updateKind']);
        $this->assertSame('current', $results['current']['updateReadiness']);
        $this->assertNotSame('ready-to-apply', $results['stale']['updateReadiness'], 'A downloaded base alone is not a rebuilt application.');

        \DockerClient::setMockResponse('/images/' . rawurlencode('stack-worker') . '/json', ['Id' => 'sha256:rebuilt']);
        \DockerClient::setMockResponse('/images/' . rawurlencode('sha256:rebuilt') . '/json', [
            'RootFS' => ['Layers' => ['sha256:l1', 'sha256:l2', 'sha256:new-app']],
        ]);
        $rebuilt = $checker->checkContainers([
            ['Names' => 'stale', 'labels' => ['com.docker.compose.service' => 'worker'], 'Image' => 'stack-worker'],
        ], ['worker' => $build]);
        $this->assertSame('ready-to-apply', $rebuilt['stale']['updateReadiness']);
        $this->assertSame('sha256:rebuilt', $rebuilt['stale']['localImage']);
        $this->assertTrue($rebuilt['stale']['hasUpdate']);
    }

    public function testNamedBuildImageFallsBackToBaseOnlyWithoutARemoteDigest(): void
    {
        $context = $this->createTempDir();
        file_put_contents($context . '/Dockerfile', "FROM alpine:3.20\n");
        $checker = new class extends \ComposeUpdateCheck {
            public function checkMany(array $images): array
            {
                return ['ghcr.io/org/published:latest' => ['local' => '', 'remote' => 'sha256:published', 'status' => 'up-to-date', 'hasUpdate' => false]];
            }

            protected function fetchRemoteImageConfig(string $image): ?array
            {
                return ['digest' => 'sha256:alpine', 'diffIds' => ['sha256:alpine-layer']];
            }
        };
        \DockerClient::setMockResponse('/containers/published/json', ['Image' => 'sha256:published']);
        \DockerClient::setMockResponse('/containers/local-only/json', ['Image' => 'sha256:local-only']);
        \DockerClient::setMockResponse('/images/' . rawurlencode('sha256:local-only') . '/json', ['RootFS' => ['Layers' => ['sha256:other']]]);

        $build = ['context' => $context];
        $results = $checker->checkContainers([
            ['Name' => 'published', 'Service' => 'pub', 'Image' => 'ghcr.io/org/published:latest'],
            ['Name' => 'local-only', 'Service' => 'loc', 'Image' => 'myapp:dev'],
        ], [
            'pub' => ['build' => $build, 'image' => 'ghcr.io/org/published:latest'],
            'loc' => ['build' => $build, 'image' => 'myapp:dev'],
        ]);

        $this->assertSame('up-to-date', $results['published']['status']);
        $this->assertSame('sha256:published', $results['published']['remote']);
        $this->assertSame('update-available', $results['local-only']['status']);
        $this->assertSame('sha256:alpine', $results['local-only']['remote']);
    }
}
