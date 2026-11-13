<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use ComposeManager\Tests\Support\FakeDocker;
use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/ContainerActions.php';
require_once __DIR__ . '/support/FakeDocker.php';

final class ContainerActionsTest extends TestCase
{
    private FakeDocker $docker;
    private string|false $oldDirectory;
    private string|false $oldPending;
    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docker = new FakeDocker();
        $this->id = str_repeat('a', 64);
        $this->oldDirectory = getenv('COMPOSE_LOCK_DIR');
        $this->oldPending = is_file(PENDING_RECHECK_FILE) ? file_get_contents(PENDING_RECHECK_FILE) : false;
        putenv('COMPOSE_LOCK_DIR=' . $this->createTempDir());
        file_put_contents(PENDING_RECHECK_FILE, '{}');
        $this->identity('myapp');
    }

    private function identity(string $project): void
    {
        $json = json_encode([['Id' => $this->id, 'Config' => ['Labels' => ['com.docker.compose.project' => $project]]]]);
        foreach (['app', $this->id] as $name) file_put_contents($this->docker->dir . '/containers/' . $name . '.json', $json);
    }

    protected function tearDown(): void
    {
        putenv($this->oldDirectory === false ? 'COMPOSE_LOCK_DIR' : 'COMPOSE_LOCK_DIR=' . $this->oldDirectory);
        if ($this->oldPending === false) @unlink(PENDING_RECHECK_FILE);
        else file_put_contents(PENDING_RECHECK_FILE, $this->oldPending);
        $this->docker->cleanUp();
        parent::tearDown();
    }

    public function testManagedActionUsesInspectedIdAndRenewsOnlyExistingPending(): void
    {
        \PendingRechecks::mark(['myapp']);
        $before = \PendingRechecks::token('myapp');
        \ContainerActions::run('app', 'restart');
        $this->assertSame([
            'inspect --type container -- app',
            'inspect --type container -- ' . $this->id,
            'restart -- ' . $this->id,
        ], $this->docker->calls());
        $this->assertNotSame($before, \PendingRechecks::token('myapp'));
        $this->assertSame(['myapp'], \PendingRechecks::acknowledge(['myapp'], ['myapp' => $before]));
        file_put_contents(PENDING_RECHECK_FILE, '{}');
        \ContainerActions::run('app', 'stop');
        $this->assertSame([], \PendingRechecks::timestamps());
    }

    public function testBusyProjectDoesNotExecuteDockerMutation(): void
    {
        $lock = \StackMutationGuard::tryExclusive('myapp');
        try {
            try {
                \ContainerActions::run('app', 'stop');
                $this->fail('Busy stack action was accepted.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('operation is in progress', $error->getMessage());
            }
            $this->assertSame(['inspect --type container -- app'], $this->docker->calls());
            $this->assertSame('', \StackMutationGuard::epoch('myapp'));
        } finally {
            fclose($lock);
        }
    }

    public function testDockerFailureIsVisibleAndOperationLockIsReleased(): void
    {
        $this->docker->fail('stop', 'error: daemon refused stop');
        try {
            \ContainerActions::run('app', 'stop');
            $this->fail('Docker failure was hidden.');
        } catch (\RuntimeException $error) {
            $this->assertSame('error: daemon refused stop', $error->getMessage());
        }
        $lock = \StackMutationGuard::tryExclusive('myapp');
        $this->assertIsResource($lock);
        fclose($lock);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', \StackMutationGuard::epoch('myapp'));
    }

    public function testNativeContainerNeedsNoComposeOwner(): void
    {
        $this->identity('');
        \ContainerActions::run('app', 'start');
        $this->assertSame('start -- ' . $this->id, $this->docker->calls()[2]);
        $this->assertSame([], \PendingRechecks::timestamps());
        $this->assertSame([], glob(\StackMutationGuard::directory() . '/*.epoch'));
    }

    public function testReassignedIdentityIsRejectedBeforeAction(): void
    {
        file_put_contents($this->docker->dir . '/containers/' . $this->id . '.json',
            json_encode([['Id' => $this->id, 'Config' => ['Labels' => ['com.docker.compose.project' => 'other']]]]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Container identity changed.');
        \ContainerActions::run('app', 'restart');
    }

    public function testInvalidInspectionIsRejected(): void
    {
        file_put_contents($this->docker->dir . '/containers/app.json', '{}');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid container identity');
        \ContainerActions::run('app', 'restart');
    }
}
