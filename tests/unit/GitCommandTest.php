<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use GitCommand;
use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitCommand.php';

/**
 * Runs real git against temporary repositories.
 */
final class GitCommandTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir() . '/compose_git_command_test_' . getmypid();
        $this->removeTree($this->workDir);
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        putenv('GIT_DIR');
        putenv('GIT_WORK_TREE');
        $this->removeTree($this->workDir);
        parent::tearDown();
    }

    public function testRunsGitAndCapturesOutput(): void
    {
        $result = GitCommand::run(['--version']);
        $this->assertTrue($result->succeeded());
        $this->assertStringStartsWith('git version', $result->stdout);
    }

    public function testFailureReportsTheLastErrorLine(): void
    {
        $result = GitCommand::run(['rev-parse', '--verify', 'no-such-ref'], $this->makeRepo());
        $this->assertFalse($result->succeeded());
        $this->assertStringContainsString('fatal', $result->errorSummary());
    }

    public function testStrayGitEnvironmentVariablesAreIgnored(): void
    {
        $repo = $this->makeRepo();
        putenv('GIT_DIR=' . $this->workDir . '/elsewhere');
        putenv('GIT_WORK_TREE=' . $this->workDir . '/elsewhere');

        $result = GitCommand::run(['rev-parse', '--show-toplevel'], $repo);

        $this->assertTrue($result->succeeded(), $result->stderr);
        $this->assertSame(realpath($repo), trim($result->stdout));
    }

    public function testRepositoryHooksNeverRun(): void
    {
        $repo = $this->makeRepo();
        $marker = $this->workDir . '/hook-ran';
        $hook = $repo . '/.git/hooks/post-checkout';
        file_put_contents($hook, "#!/bin/sh\ntouch '$marker'\n");
        chmod($hook, 0755);

        $result = GitCommand::run(['checkout', '--detach', 'HEAD'], $repo);

        $this->assertTrue($result->succeeded(), $result->stderr);
        $this->assertFileDoesNotExist($marker);
    }

    public function testHooksPathSetInTheRepositoryConfigIsOverridden(): void
    {
        $repo = $this->makeRepo();
        $marker = $this->workDir . '/hook-ran';
        mkdir($this->workDir . '/evil-hooks');
        file_put_contents($this->workDir . '/evil-hooks/post-checkout', "#!/bin/sh\ntouch '$marker'\n");
        chmod($this->workDir . '/evil-hooks/post-checkout', 0755);
        $this->git(['config', 'core.hooksPath', $this->workDir . '/evil-hooks'], $repo);

        GitCommand::run(['checkout', '--detach', 'HEAD'], $repo);

        $this->assertFileDoesNotExist($marker);
    }

    public function testExtTransportIsRefused(): void
    {
        $marker = $this->workDir . '/ext-ran';
        $result = GitCommand::run(['ls-remote', "ext::sh -c touch% $marker"]);

        $this->assertFalse($result->succeeded());
        $this->assertFileDoesNotExist($marker);
    }

    public function testPlainHttpTransportIsRefused(): void
    {
        $result = GitCommand::run(['ls-remote', 'http://127.0.0.1:9/repo.git'], null, 10);
        $this->assertFalse($result->succeeded());
        $this->assertStringContainsString('transport', $result->stderr);
    }

    /**
     * git allows ssh and git by default (unlike ext), so only the runner's
     * protocol.allow=never refuses them.
     *
     * @return array<string, array{string}>
     */
    public static function transportsGitAllowsByDefault(): array
    {
        return [
            'ssh' => ['ssh://127.0.0.1:9/repo.git'],
            'git' => ['git://127.0.0.1:9/repo.git'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transportsGitAllowsByDefault')]
    public function testTransportsGitAllowsByDefaultAreRefused(string $url): void
    {
        $result = GitCommand::run(['ls-remote', '--', $url], null, 10);
        $this->assertFalse($result->succeeded());
        $this->assertStringContainsString('not allowed', $result->stderr);
    }

    public function testLocalPathTransportIsAllowed(): void
    {
        $repo = $this->makeRepo();
        $result = GitCommand::run(['ls-remote', '--', $repo]);
        $this->assertTrue($result->succeeded(), $result->stderr);
        $this->assertStringContainsString('refs/heads/main', $result->stdout);
    }

    public function testArgumentsArePassedLiterallyWithoutAShell(): void
    {
        $repo = $this->makeRepo();
        $name = '-rf $(touch pwned); `id`';
        file_put_contents($repo . '/' . $name, 'x');

        $result = GitCommand::run(['add', '--', $name], $repo);

        $this->assertTrue($result->succeeded(), $result->stderr);
        $this->assertFileDoesNotExist($repo . '/pwned');
        $status = GitCommand::run(['status', '--porcelain', '-z'], $repo);
        $this->assertStringContainsString($name, $status->stdout);
    }

    public function testRunIsStoppedAfterTheTimeLimit(): void
    {
        $started = microtime(true);
        $result = GitCommand::run(['-c', 'alias.wait=!sleep 30', 'wait'], $this->makeRepo(), 1);

        $this->assertTrue($result->timedOut);
        $this->assertFalse($result->succeeded());
        $this->assertLessThan(15, microtime(true) - $started);
    }

    public function testCommandKilledBySomethingElseIsNotCalledATimeout(): void
    {
        $result = \ProcessRunner::run(['sh', '-c', 'kill -9 $$'], '/', ['PATH' => '/usr/bin:/bin'], 30);

        $this->assertSame(137, $result->exitCode);
        $this->assertFalse($result->timedOut);
    }

    public function testCommandThatClosesItsOutputEarlyIsStillReadToTheEnd(): void
    {
        $started = microtime(true);
        $result = \ProcessRunner::run(
            ['sh', '-c', 'exec 1>&-; sleep 1; echo finished >&2'],
            '/',
            ['PATH' => '/usr/bin:/bin'],
            30
        );

        $this->assertSame(0, $result->exitCode);
        $this->assertSame("finished\n", $result->stderr);
        $this->assertLessThan(10, microtime(true) - $started);
    }

    public function testRepositoryOwnedByAnotherUserIsUsedWhenItIsTheWorkingFolder(): void
    {
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('Needs root to change file owners.');
        }
        $repo = $this->makeRepo();
        $chown = \ProcessRunner::run(['chown', '-R', '65534', $repo], '/', ['PATH' => '/usr/bin:/bin'], 30);
        $this->assertTrue($chown->succeeded(), $chown->stderr);

        $this->assertTrue(GitCommand::run(['status', '--porcelain'], $repo)->succeeded());
        // Only the folder git works in is trusted, not every repository.
        $this->assertFalse(GitCommand::run(['-C', $repo, 'status', '--porcelain'])->succeeded());
    }

    // ----- helpers -----

    private function makeRepo(): string
    {
        $repo = $this->workDir . '/repo';
        if (!is_dir($repo . '/.git')) {
            mkdir($repo, 0755, true);
            $this->git(['init', '-q', '-b', 'main'], $repo);
            file_put_contents($repo . '/compose.yaml', "services: {}\n");
            $this->git(['add', 'compose.yaml'], $repo);
            $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.invalid', 'commit', '-q', '-m', 'first'], $repo);
        }
        return $repo;
    }

    /** @param string[] $args */
    private function git(array $args, string $dir): void
    {
        $result = GitCommand::run($args, $dir);
        $this->assertTrue($result->succeeded(), 'git ' . implode(' ', $args) . ': ' . $result->stderr);
    }

    private function removeTree(string $path): void
    {
        // readlink(), not is_link(): the test stream wrapper hides symlinks from is_link().
        if (@readlink($path) !== false || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
