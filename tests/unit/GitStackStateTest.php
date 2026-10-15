<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use GitStackState;
use PluginTests\TestCase;
use RuntimeException;

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackState.php';

final class GitStackStateTest extends TestCase
{
    private string $stackDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stackDir = $this->createTempDir();
    }

    public function testNoFileMeansNothingDeployedYet(): void
    {
        $state = GitStackState::load($this->stackDir);
        $this->assertNull($state->deployedCommit);
        $this->assertNull($state->failedCommit);
    }

    public function testSaveThenLoad(): void
    {
        $deployed = str_repeat('a', 40);
        $failed = str_repeat('b', 40);
        (new GitStackState($deployed, $failed))->save($this->stackDir);

        $state = GitStackState::load($this->stackDir);
        $this->assertSame($deployed, $state->deployedCommit);
        $this->assertSame($failed, $state->failedCommit);
    }

    /** @return array<string, array{string}> */
    public static function brokenFiles(): array
    {
        return [
            'not json' => ['{'],
            'extra key' => ['{"version":1,"deployedCommit":null,"failedCommit":null,"paused":false}'],
            'other version' => ['{"version":2,"deployedCommit":null,"failedCommit":null}'],
            'short commit' => ['{"version":1,"deployedCommit":"abc123","failedCommit":null}'],
            'option as commit' => ['{"version":1,"deployedCommit":"--upload-pack=x","failedCommit":null}'],
            'number' => ['{"version":1,"deployedCommit":5,"failedCommit":null}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenFiles')]
    public function testBrokenFileIsReportedNotUsed(string $content): void
    {
        file_put_contents($this->stackDir . '/' . GitStackState::FILE_NAME, $content);
        $this->expectException(RuntimeException::class);
        GitStackState::load($this->stackDir);
    }
}
