<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/StackUpdateService.php';

final class StackMutationGuardTest extends TestCase
{
    private string $directory;
    private string $folder = 'Display Folder';
    private string $project = 'legacy___runtime--';
    private string|false $path;
    private string|false $lockDirectory;
    private mixed $root;
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = $this->createTempDir();
        $this->path = getenv('PATH');
        $this->lockDirectory = getenv('COMPOSE_LOCK_DIR');
        $this->root = $GLOBALS['compose_root'] ?? null;
        $GLOBALS['compose_root'] = $this->directory . '/projects';
        mkdir($GLOBALS['compose_root']);
        mkdir($GLOBALS['compose_root'] . '/' . $this->folder);
        file_put_contents($GLOBALS['compose_root'] . '/' . $this->folder . '/compose.yaml', "services:\n  app:\n    image: alpine\n");
        file_put_contents($GLOBALS['compose_root'] . '/' . $this->folder . '/project_name', $this->project);
        foreach ([COMPOSE_UPDATE_STATUS_FILE, PENDING_RECHECK_FILE] as $file) {
            $this->original[$file] = is_file($file) ? file_get_contents($file) : false;
            file_put_contents($file, '{}');
        }
        putenv('COMPOSE_LOCK_DIR=' . $this->directory . '/locks');
        mkdir(\StackMutationGuard::directory());
        mkdir($this->directory . '/bin');
        $row = json_encode([
            'Names' => 'app', 'Image' => 'alpine@sha256:pinned',
            'Labels' => 'com.docker.compose.project=' . $this->project . ',com.docker.compose.service=app',
        ]);
        file_put_contents($this->directory . '/bin/docker', "#!/bin/sh\n"
            . "case \"\$1\" in\nps) printf '%s\\n' " . escapeshellarg($row) . ";;\n"
            . "compose) printf '%s\\n' '{\"services\":{}}';;\nesac\n");
        chmod($this->directory . '/bin/docker', 0755);
        putenv('PATH=' . $this->directory . '/bin:' . $this->path);
        \DockerClient::setMockResponse('/containers/app/json', [
            'Image' => 'sha256:pinned', 'Config' => ['Image' => 'alpine@sha256:pinned'],
        ]);
        \StackInfo::clearCache();
    }

    protected function tearDown(): void
    {
        putenv($this->path === false ? 'PATH' : 'PATH=' . $this->path);
        putenv($this->lockDirectory === false ? 'COMPOSE_LOCK_DIR' : 'COMPOSE_LOCK_DIR=' . $this->lockDirectory);
        $GLOBALS['compose_root'] = $this->root;
        foreach ($this->original as $file => $raw) {
            if ($raw === false) @unlink($file);
            else file_put_contents($file, $raw);
        }
        \StackInfo::clearCache();
        parent::tearDown();
    }

    private function stack(): \StackInfo
    {
        return \StackInfo::fromProject($GLOBALS['compose_root'], $this->folder);
    }

    public function testPhpMutationInvalidatesChecksAtBothBoundariesAndReleasesOnFailure(): void
    {
        $this->stack();
        \PendingRechecks::mark([$this->folder]);
        $old = \PendingRechecks::token($this->folder);
        $during = null;
        try {
            \StackMutationGuard::mutate($this->project, $this->folder, function () use (&$during): void {
                $during = \StackMutationGuard::epoch($this->project);
                $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $during);
                $this->assertNull(\StackMutationGuard::inspect($this->project, static fn() => true));
                throw new \RuntimeException('Source mutation failed.');
            });
            $this->fail('The original failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Source mutation failed.', $error->getMessage());
        }
        $this->assertNotSame($during, \StackMutationGuard::epoch($this->project));
        $this->assertSame([$this->folder], \PendingRechecks::acknowledge([$this->folder], [$this->folder => $old]));
        $lock = \StackMutationGuard::tryExclusive($this->project);
        $this->assertIsResource($lock);
        fclose($lock);
    }

    /** @return resource */
    private function mutationLock()
    {
        $handle = fopen(\StackMutationGuard::directory() . '/' . $this->project . '.lock', 'c+');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        return $handle;
    }

    public function testBusyProjectDefersBeforeDockerWithoutLockingUnrelatedProjects(): void
    {
        $stack = $this->stack();
        // If runtime enumeration is reached, the check must fail rather than defer.
        file_put_contents($this->directory . '/bin/docker', "#!/bin/sh\nexit 91\n");
        $lock = $this->mutationLock();
        try {
            $service = new \StackUpdateService();
            $this->assertSame([], $service->checkStacks([$stack]));
            $this->assertSame([$this->folder], $service->superseded);
            $this->assertSame([], $service->recheckTokens);
            $this->assertSame('available', \StackMutationGuard::inspect('unrelated', static fn() => 'available'));
        } finally {
            fclose($lock);
        }
    }

    public function testMutationSpanningRuntimeRejectsEvidenceAndMetadataPublication(): void
    {
        foreach ([false, true] as $finishBeforePublication) {
            file_put_contents(COMPOSE_UPDATE_STATUS_FILE, '{}');
            $lock = null;
            $metadataCommitted = false;
            $service = new \StackUpdateService();
            try {
                $results = $service->checkStacks([$this->stack()], function () use (&$lock, $finishBeforePublication): void {
                    $lock = $this->mutationLock();
                    \StackMutationGuard::mutationBoundary($this->project, $this->folder);
                    if ($finishBeforePublication) {
                        \StackMutationGuard::mutationBoundary($this->project, $this->folder);
                        fclose($lock);
                        $lock = null;
                    }
                }, false, static function () use (&$metadataCommitted): void {
                    $metadataCommitted = true;
                });
                $this->assertSame([], $results);
                $this->assertFalse($metadataCommitted);
                $this->assertSame([$this->folder], $service->superseded);
                $this->assertSame([], \StackUpdateService::readSavedResults());
                $this->assertArrayHasKey($this->folder, \PendingRechecks::timestamps());
            } finally {
                if (is_resource($lock)) fclose($lock);
            }
        }
    }

    public function testPublishedTokenCannotAcknowledgeDuringOrAfterALaterMutation(): void
    {
        \PendingRechecks::mark([$this->folder]);
        $service = new \StackUpdateService();
        $metadataCommitted = false;
        $result = $service->checkStacks([$this->stack()], null, false, function () use (&$metadataCommitted): void {
            $probe = fopen(\StackMutationGuard::directory() . '/' . $this->project . '.lock', 'c+');
            $this->assertFalse(flock($probe, LOCK_EX | LOCK_NB), 'Metadata publication must retain the operation lock.');
            fclose($probe);
            $metadataCommitted = true;
        });
        $this->assertArrayHasKey($this->folder, $result);
        $this->assertTrue($metadataCommitted);
        $tokens = $service->recheckTokens;
        $lock = $this->mutationLock();
        try {
            // Acquiring the mutation lock alone must already prevent acknowledgement.
            $this->assertContains($this->folder, \PendingRechecks::acknowledge([$this->folder], $tokens));
            \StackMutationGuard::mutationBoundary($this->project, $this->folder);
            $begin = json_decode(file_get_contents(PENDING_RECHECK_FILE), true);
            $this->assertContains($this->folder, \PendingRechecks::acknowledge([$this->folder], $tokens));
            \StackMutationGuard::mutationBoundary($this->project, $this->folder);
            $this->assertNotSame($begin[$this->folder]['token'], json_decode(file_get_contents(PENDING_RECHECK_FILE), true)[$this->folder]['token']);
        } finally {
            fclose($lock);
        }
        $this->assertContains($this->folder, \PendingRechecks::acknowledge([$this->folder], $tokens));
        $fresh = new \StackUpdateService();
        $this->assertArrayHasKey($this->folder, $fresh->checkStacks([$this->stack()]));
        $this->assertNotSame($tokens, $fresh->recheckTokens);
        $this->assertSame([], \PendingRechecks::acknowledge([$this->folder], $fresh->recheckTokens));
        $this->assertFileDoesNotExist(\StackMutationGuard::directory() . '/' . $this->folder . '.lock');
    }

    public function testNewerBusyCheckStillInvalidatesAnOlderReservation(): void
    {
        $service = new \StackUpdateService();
        $result = $service->checkStacks([$this->stack()], function (): void {
            $lock = $this->mutationLock();
            try {
                // Even if no epoch could yet be written, the newer unsuccessful
                // check must prevent an older result from resurrecting evidence.
                $newer = new \StackUpdateService();
                $this->assertSame([], $newer->checkStacks([$this->stack()]));
            } finally {
                fclose($lock);
            }
        });
        $this->assertSame([], $result);
        $this->assertSame([], \StackUpdateService::readSavedResults());
    }

    public function testLegacyCliWithoutStackPathRenewsTheRealFolder(): void
    {
        $this->stack();
        $lock = $this->mutationLock();
        try {
            \StackMutationGuard::mutationBoundary($this->project, '');
        } finally {
            fclose($lock);
        }
        $this->assertSame([$this->folder], array_keys(\PendingRechecks::timestamps()));
    }

    public function testFullPublicationCommitsEveryAcceptedProjectTogetherAndConditionallyPrunes(): void
    {
        $root = $GLOBALS['compose_root'];
        foreach (['other', 'busy'] as $folder) {
            mkdir($root . '/' . $folder);
            file_put_contents($root . '/' . $folder . '/compose.yaml', "services:\n  app:\n    image: alpine\n");
            file_put_contents($root . '/' . $folder . '/project_name', $folder);
        }
        $stacks = [$this->stack(), \StackInfo::fromProject($root, 'other'), \StackInfo::fromProject($root, 'busy')];
        file_put_contents(COMPOSE_UPDATE_STATUS_FILE, json_encode([
            'busy' => ['containers' => [], 'lastChecked' => 17],
            'removed' => ['containers' => []],
        ]));
        $busy = fopen(\StackMutationGuard::directory() . '/busy.lock', 'c+');
        $this->assertTrue(flock($busy, LOCK_EX | LOCK_NB));
        $commits = [];
        $collected = 0;
        try {
            $service = new \StackUpdateService();
            $accepted = $service->checkStacks($stacks, function () use (&$collected): void {
                $collected++;
                // Collection must not retain even one project's operation lock.
                foreach ([$this->project, 'other'] as $project) {
                    $probe = fopen(\StackMutationGuard::directory() . '/' . $project . '.lock', 'c+');
                    $this->assertTrue(flock($probe, LOCK_EX | LOCK_NB));
                    fclose($probe);
                }
            }, true, function (array $accepted) use (&$commits): void {
                $saved = \StackUpdateService::readSavedResults();
                // The first metadata callback must already see every accepted
                // stack and the full-check prune, not a series of partial saves.
                $this->assertArrayHasKey($this->folder, $saved);
                $this->assertArrayHasKey('other', $saved);
                $this->assertArrayNotHasKey('removed', $saved);
                $this->assertSame(17, $saved['busy']['lastChecked']);
                foreach ([$this->project, 'other'] as $project) {
                    $probe = fopen(\StackMutationGuard::directory() . '/' . $project . '.lock', 'c+');
                    $this->assertFalse(flock($probe, LOCK_EX | LOCK_NB));
                    fclose($probe);
                }
                $commits[] = array_keys($accepted);
            });
            $this->assertSame(1, $collected);
            $this->assertSame([$this->folder, 'other'], array_keys($accepted));
            $this->assertSame([array_keys($accepted)], $commits);
            $this->assertSame(['busy'], $service->superseded);
            $this->assertArrayNotHasKey('busy', $service->recheckTokens);
        } finally {
            fclose($busy);
        }
    }

    public function testFullCheckPreservesFailedStackEvidenceAndPublishesHealthyStacks(): void
    {
        $root = $GLOBALS['compose_root'];
        mkdir($root . '/broken');
        file_put_contents($root . '/broken/compose.yaml', "services: {}\n");
        file_put_contents($root . '/broken/project_name', 'broken');
        $healthy = $this->stack();
        $broken = \StackInfo::fromProject($root, 'broken');
        $rows = [];
        foreach ([$this->project => 'app', 'broken' => 'broken-app'] as $project => $name) {
            $rows[] = json_encode(['Names' => $name, 'Image' => 'alpine@sha256:pinned',
                'Labels' => 'com.docker.compose.project=' . $project . ',com.docker.compose.service=app']);
        }
        file_put_contents($this->directory . '/bin/docker', "#!/bin/sh\ncase \"\$1\" in\n"
            . "ps) printf '%s\\n' " . implode(' ', array_map('escapeshellarg', $rows)) . ";;\n"
            . "compose) case \" \$* \" in *' -p broken '*) echo broken-config >&2; exit 7;; "
            . "*) printf '%s\\n' '{\"services\":{}}';; esac;;\nesac\n");
        foreach ([[$broken, $healthy], [$healthy, $broken]] as $stacks) {
            file_put_contents(COMPOSE_UPDATE_STATUS_FILE, json_encode([
                'broken' => ['containers' => [], 'lastChecked' => 17],
                'removed' => ['containers' => []],
            ]));
            \PendingRechecks::mark(['broken']);
            $pending = \PendingRechecks::token('broken');
            $service = new \StackUpdateService();
            $published = [];
            $results = $service->checkStacks($stacks, null, true, static function (array $accepted) use (&$published): void {
                $published = array_keys($accepted);
            });
            $this->assertSame([$this->folder], array_keys($results));
            $this->assertSame([$this->folder], $published);
            $this->assertSame(['broken'], array_keys($service->failures));
            $this->assertStringContainsString('broken-config', $service->failures['broken']);
            $this->assertSame([], $service->superseded);
            $this->assertArrayNotHasKey('broken', $service->recheckTokens);
            $saved = \StackUpdateService::readSavedResults();
            $this->assertSame(17, $saved['broken']['lastChecked']);
            $this->assertArrayNotHasKey('removed', $saved);
            $this->assertSame($pending, \PendingRechecks::token('broken'));
        }
        $allFailed = new \StackUpdateService();
        $this->assertSame([], $allFailed->checkStacks([$broken], null, true));
        $this->assertSame(['broken'], array_keys($allFailed->failures));
        $this->assertSame([], $allFailed->superseded);
        $this->assertSame([], $allFailed->recheckTokens);
        $this->assertSame(17, \StackUpdateService::readSavedResults()['broken']['lastChecked']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('broken-config');
        (new \StackUpdateService())->checkStacks([$broken]);
    }

    public function testCliLegacyMappingResolvesConfiguredRootWhenGlobalIsAbsent(): void
    {
        $this->stack();
        $bootstrap = $this->directory . '/cli-bootstrap.php';
        file_put_contents($bootstrap, '<?php require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
            . '\\PluginTests\\Mocks\\FunctionMocks::setPluginConfig("compose.manager", ["PROJECTS_FOLDER"=>'
            . var_export($GLOBALS['compose_root'], true) . ']); unset($GLOBALS["compose_root"]);');
        $script = dirname(__DIR__, 2) . '/source/compose.manager/scripts/mutation_boundary.php';
        $lock = $this->mutationLock();
        try {
            foreach ([$this->folder, ''] as $folder) {
                file_put_contents(PENDING_RECHECK_FILE, '{}');
                $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . $bootstrap, $script, $this->project, $folder, 'create'], [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes);
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $output . $errors);
                $this->assertSame([$this->folder], array_keys(\PendingRechecks::timestamps()));
            }
        } finally {
            fclose($lock);
        }
    }

    public function testBatchedPendingSnapshotAndAcknowledgementPreserveBusyAndRenewedEntries(): void
    {
        $root = $GLOBALS['compose_root'];
        $projects = [$this->folder => $this->project];
        foreach (['other', 'busy', 'renewed', 'unrelated'] as $folder) {
            mkdir($root . '/' . $folder);
            file_put_contents($root . '/' . $folder . '/compose.yaml', "services:\n  app:\n    image: alpine\n");
            file_put_contents($root . '/' . $folder . '/project_name', $folder);
            $projects[$folder] = $folder;
        }
        \PendingRechecks::mark(array_keys($projects));
        $snapshot = \PendingRechecks::snapshot($projects);
        $this->assertSame(array_keys($projects), array_keys($snapshot['tokens']));
        foreach ($projects as $folder => $project) {
            $this->assertSame(\PendingRechecks::token($folder, $project), $snapshot['tokens'][$folder]);
        }
        $busy = fopen(\StackMutationGuard::directory() . '/busy.lock', 'c+');
        $this->assertTrue(flock($busy, LOCK_EX | LOCK_NB));
        try {
            $during = \PendingRechecks::snapshot($projects);
            $this->assertArrayNotHasKey('busy', $during['epochs']);
            $this->assertArrayNotHasKey('busy', $during['tokens']);
            $this->assertArrayHasKey($this->folder, $during['tokens']);
            \PendingRechecks::mark(['renewed']);
            $before = json_decode(file_get_contents(PENDING_RECHECK_FILE), true);
            $remaining = \PendingRechecks::acknowledge(
                [$this->folder, 'other', 'busy', 'renewed'], $snapshot['tokens']
            );
            $this->assertSame(['busy', 'renewed', 'unrelated'], $remaining);
            $after = json_decode(file_get_contents(PENDING_RECHECK_FILE), true);
            foreach ($remaining as $folder) $this->assertSame($before[$folder], $after[$folder]);
        } finally {
            fclose($busy);
        }
        $this->assertSame(['renewed', 'unrelated'], \PendingRechecks::acknowledge(['busy'], $snapshot['tokens']));
    }
}
