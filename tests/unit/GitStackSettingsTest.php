<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use GitStackSettings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PluginTests\TestCase;
use RuntimeException;

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackSettings.php';

final class GitStackSettingsTest extends TestCase
{
    private string $mnt;
    private string $stackDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mnt = COMPOSE_GIT_MNT_DIR;
        $this->removeTree($this->mnt);
        mkdir($this->mnt . '/user/appdata', 0755, true);
        file_put_contents(COMPOSE_UNRAID_VAR_INI, "mdState=\"STARTED\"\n");
        file_put_contents(COMPOSE_MOUNTS_FILE, "rootfs {$this->mnt} rootfs rw 0 0\nshfs {$this->mnt}/user fuse.shfs rw 0 0\n");

        $this->stackDir = sys_get_temp_dir() . '/compose_git_settings_stack';
        $this->removeTree($this->stackDir);
        mkdir($this->stackDir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->mnt);
        $this->removeTree($this->stackDir);
        @unlink(COMPOSE_UNRAID_VAR_INI);
        @unlink(COMPOSE_MOUNTS_FILE);
        parent::tearDown();
    }

    // ----- repository address -----

    /** @return array<string, array{string}> */
    public static function goodUrls(): array
    {
        return [
            'github' => ['https://github.com/owner/repo.git'],
            'no .git suffix' => ['https://github.com/owner/repo'],
            'port' => ['https://git.example.com:3000/owner/repo.git'],
            'nested path' => ['https://gitlab.example.com/group/sub/repo.git'],
            'local bare repo' => [COMPOSE_GIT_MNT_DIR . '/user/appdata/repos/stacks.git'],
        ];
    }

    #[DataProvider('goodUrls')]
    public function testGoodRepositoryAddressesAreAccepted(string $url): void
    {
        GitStackSettings::validateUrl($url);
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string, string}> */
    public static function badUrls(): array
    {
        return [
            'empty' => ['', 'empty'],
            'plain http' => ['http://github.com/owner/repo.git', 'https://'],
            'upper case scheme, which git refuses' => ['HTTPS://github.com/owner/repo.git', 'https://'],
            'ssh' => ['ssh://git@github.com/owner/repo.git', 'https://'],
            'scp style' => ['git@github.com:owner/repo.git', 'https://'],
            'file url' => ['file:///mnt/user/repo.git', 'https://'],
            'ext transport' => ['ext::sh -c touch% /tmp/x', 'spaces'],
            'ext transport without spaces' => ['ext::sh', 'https://'],
            'leading dash' => ['--upload-pack=touch /tmp/x', 'spaces'],
            'leading dash no space' => ['-uhttps://x/y', 'https://'],
            'token in url' => ['https://user:ghp_secret@github.com/owner/repo.git', 'password'],
            'user in url' => ['https://user@github.com/owner/repo.git', 'user name'],
            'query' => ['https://github.com/owner/repo.git?x=1', '?'],
            'no path' => ['https://github.com', 'no repository path'],
            'bad host' => ['https://-evil.com/repo.git', 'host'],
            'space' => ['https://github.com/owner/my repo.git', 'spaces'],
            'newline' => ["https://github.com/owner/repo.git\n", 'spaces'],
            'relative path' => ['repos/stacks.git', 'https://'],
            'local outside mnt' => ['/boot/repos/stacks.git', 'must be under'],
            'local traversal' => [COMPOSE_GIT_MNT_DIR . '/user/../../etc', "'..'"],
        ];
    }

    #[DataProvider('badUrls')]
    public function testBadRepositoryAddressesAreRefused(string $url, string $messagePart): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($messagePart);
        GitStackSettings::validateUrl($url);
    }

    // ----- branch -----

    /** @return array<string, array{string}> */
    public static function goodBranches(): array
    {
        return [['main'], ['master'], ['release/2026.10'], ['feature-x_y']];
    }

    #[DataProvider('goodBranches')]
    public function testGoodBranchNamesAreAccepted(string $branch): void
    {
        GitStackSettings::validateBranch($branch);
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string}> */
    public static function badBranches(): array
    {
        return [
            'empty' => [''],
            'leading dash' => ['-main'],
            'option' => ['--upload-pack=x'],
            'double dot' => ['a..b'],
            'reflog syntax' => ['main@{1}'],
            'space' => ['my branch'],
            'tilde' => ['main~1'],
            'caret' => ['main^'],
            'colon' => ['a:b'],
            'trailing lock' => ['main.lock'],
            'trailing slash' => ['main/'],
            'HEAD' => ['HEAD'],
            'newline' => ["main\n"],
        ];
    }

    #[DataProvider('badBranches')]
    public function testBadBranchNamesAreRefused(string $branch): void
    {
        $this->expectException(InvalidArgumentException::class);
        GitStackSettings::validateBranch($branch);
    }

    // ----- compose path -----

    /** @return array<string, array{string}> */
    public static function goodComposePaths(): array
    {
        return [['compose.yaml'], ['docker-compose.yml'], ['whoami/compose.yaml'], ['stacks/media/Plex.YML'], ['a b/c.yaml']];
    }

    #[DataProvider('goodComposePaths')]
    public function testGoodComposePathsAreAccepted(string $path): void
    {
        GitStackSettings::validateComposePath($path);
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string}> */
    public static function badComposePaths(): array
    {
        return [
            'empty' => [''],
            'absolute' => ['/etc/compose.yaml'],
            'traversal' => ['../compose.yaml'],
            'inner traversal' => ['a/../../compose.yaml'],
            'dot part' => ['./compose.yaml'],
            'double slash' => ['a//compose.yaml'],
            'trailing slash' => ['a/'],
            'backslash' => ['a\\compose.yaml'],
            'inside .git' => ['.git/compose.yaml'],
            'inside .GIT' => ['.GIT/compose.yaml'],
            'not yaml' => ['compose.json'],
            'directory' => ['whoami'],
            'leading dash' => ['-compose.yaml'],
            'newline' => ["compose.yaml\n"],
        ];
    }

    #[DataProvider('badComposePaths')]
    public function testBadComposePathsAreRefused(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        GitStackSettings::validateComposePath($path);
    }

    // ----- clone folder -----

    public function testCloneFolderNameIsReadableAndUnique(): void
    {
        $this->assertSame('my-stack-0123abcd', GitStackSettings::cloneFolderName('My Stack', '0123abcd89ef4567'));
        $this->assertSame('stack-0123abcd', GitStackSettings::cloneFolderName('...', '0123abcd89ef4567'));
        $this->assertSame('caf-0123abcd', GitStackSettings::cloneFolderName("Caf\u{e9}", '0123abcd89ef4567'));
    }

    public function testTwoStacksWithNamesThatLookAlikeGetDifferentCloneFolders(): void
    {
        $root = $this->mnt . '/user/appdata/git';
        $first = GitStackSettings::createNew('https://github.com/o/r.git', 'main', 'compose.yaml', $root, 'My Stack');
        $second = GitStackSettings::createNew('https://github.com/o/r.git', 'main', 'compose.yaml', $root, 'my-stack');
        $this->assertNotSame($first->cloneDir, $second->cloneDir);
    }

    public function testCreateNewRefusesAClonesRootInRam(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GitStackSettings::createNew('https://github.com/o/r.git', 'main', 'compose.yaml', $this->mnt . '/usr/appdata/git', 'x');
    }

    // ----- load and save -----

    public function testSaveThenLoadGivesTheSameSettings(): void
    {
        $settings = $this->makeSettings();
        $settings->save($this->stackDir);
        $loaded = GitStackSettings::load($this->stackDir);

        $this->assertNotNull($loaded);
        $this->assertSame($settings->url, $loaded->url);
        $this->assertSame($settings->branch, $loaded->branch);
        $this->assertSame($settings->composePath, $loaded->composePath);
        $this->assertSame($settings->cloneId, $loaded->cloneId);
        $this->assertSame($settings->cloneDir, $loaded->cloneDir);
        $this->assertTrue($loaded->recreateOnFolderChange);
        $this->assertSame([], glob($this->stackDir . '/git.json.tmp-*') ?: []);
    }

    public function testRecreateOnFolderChangeIsOnByDefaultAndCanBeTurnedOff(): void
    {
        $settings = $this->makeSettings();
        $this->assertTrue($settings->recreateOnFolderChange);

        $settings->withRecreateOnFolderChange(false)->save($this->stackDir);
        $loaded = GitStackSettings::load($this->stackDir);

        $this->assertNotNull($loaded);
        $this->assertFalse($loaded->recreateOnFolderChange);
        $this->assertSame($settings->cloneDir, $loaded->cloneDir);
    }

    public function testStackWithoutGitJsonIsNotAGitStack(): void
    {
        $this->assertNull(GitStackSettings::load($this->stackDir));
    }

    public function testFileWithWindowsLineEndingsAndByteOrderMarkLoads(): void
    {
        $this->makeSettings()->save($this->stackDir);
        $file = $this->stackDir . '/git.json';
        file_put_contents($file, "\xEF\xBB\xBF" . str_replace("\n", "\r\n", (string) file_get_contents($file)));
        $this->assertNotNull(GitStackSettings::load($this->stackDir));
    }

    /** @return array<string, array{callable(array<string, mixed>): mixed}> */
    public static function brokenFiles(): array
    {
        return [
            'not json' => [static fn(array $data): string => '{not json'],
            'list instead of object' => [static fn(array $data): string => '[1, 2]'],
            'missing key' => [static function (array $data): string {
                unset($data['branch']);
                return (string) json_encode($data);
            }],
            'extra key' => [static function (array $data): string {
                $data['deployNow'] = true;
                return (string) json_encode($data);
            }],
            'newer format' => [static function (array $data): string {
                $data['version'] = 2;
                return (string) json_encode($data);
            }],
            'number instead of string' => [static function (array $data): string {
                $data['branch'] = 5;
                return (string) json_encode($data);
            }],
            'string instead of true or false' => [static function (array $data): string {
                $data['recreateOnFolderChange'] = 'yes';
                return (string) json_encode($data);
            }],
            'hand-edited bad branch' => [static function (array $data): string {
                $data['branch'] = '--upload-pack=touch /tmp/x';
                return (string) json_encode($data);
            }],
            'hand-edited compose path escaping the clone' => [static function (array $data): string {
                $data['composePath'] = '../../etc/compose.yaml';
                return (string) json_encode($data);
            }],
            'clone folder moved to the flash' => [static function (array $data): string {
                $data['cloneDir'] = '/boot/config/x-' . substr((string) $data['cloneId'], 0, 8);
                return (string) json_encode($data);
            }],
            'clone folder of another stack' => [static function (array $data): string {
                $data['cloneDir'] = dirname((string) $data['cloneDir']) . '/other-ffffffff';
                return (string) json_encode($data);
            }],
            'clone id not hex' => [static function (array $data): string {
                $data['cloneId'] = '../../../../etc';
                return (string) json_encode($data);
            }],
        ];
    }

    #[DataProvider('brokenFiles')]
    public function testBrokenSettingsFileIsReportedNotUsed(callable $breakIt): void
    {
        $this->makeSettings()->save($this->stackDir);
        $file = $this->stackDir . '/git.json';
        $data = json_decode((string) file_get_contents($file), true);
        file_put_contents($file, $breakIt($data));

        $this->expectException(RuntimeException::class);
        GitStackSettings::load($this->stackDir);
    }

    // ----- files written by released versions -----

    /** @return array<string, array{string}> */
    public static function releasedFormats(): array
    {
        $formats = [];
        foreach (glob(__DIR__ . '/fixtures/git-stack-settings/*.json') ?: [] as $fixture) {
            $formats[basename($fixture, '.json')] = [$fixture];
        }
        return $formats;
    }

    #[DataProvider('releasedFormats')]
    public function testFileWrittenByAReleasedVersionStillLoads(string $fixture): void
    {
        // Every git.json a released version wrote must keep loading (see the class comment).
        $content = str_replace('@MNT@', $this->mnt, (string) file_get_contents($fixture));
        file_put_contents($this->stackDir . '/git.json', $content);

        $settings = GitStackSettings::load($this->stackDir);

        $this->assertNotNull($settings);
        $this->assertSame('main', $settings->branch);
    }

    public function testFormatOneLoadsWithTheBehaviourItWasWrittenWith(): void
    {
        $content = str_replace('@MNT@', $this->mnt, (string) file_get_contents(__DIR__ . '/fixtures/git-stack-settings/format-1.json'));
        file_put_contents($this->stackDir . '/git.json', $content);

        $settings = GitStackSettings::load($this->stackDir);

        $this->assertSame('https://github.com/owner/repo.git', $settings->url);
        $this->assertSame('whoami/compose.yaml', $settings->composePath);
        $this->assertTrue($settings->recreateOnFolderChange);
    }

    public function testSettingFromANewerVersionIsRefusedByName(): void
    {
        $this->makeSettings()->save($this->stackDir);
        $file = $this->stackDir . '/git.json';
        $data = json_decode((string) file_get_contents($file), true);
        $data['autoDeploy'] = 'off';
        file_put_contents($file, (string) json_encode($data));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not know: autoDeploy');
        GitStackSettings::load($this->stackDir);
    }

    public function testJsonSyntaxErrorIsNamed(): void
    {
        $this->makeSettings()->save($this->stackDir);
        $file = $this->stackDir . '/git.json';
        // A trailing comma, the usual slip when editing by hand.
        file_put_contents($file, str_replace("\n}", ",\n}", (string) file_get_contents($file)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not valid JSON: Syntax error');
        GitStackSettings::load($this->stackDir);
    }

    public function testSaveIntoAProjectsFolderOnAStoppedArrayIsRefused(): void
    {
        $stackOnArray = $this->mnt . '/user/appdata/projects/mystack';
        mkdir($stackOnArray, 0755, true);
        file_put_contents(COMPOSE_UNRAID_VAR_INI, "mdState=\"STOPPED\"\n");

        try {
            $this->makeSettings()->save($stackOnArray);
            $this->fail('Saved while the array was stopped');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($stackOnArray . '/git.json');
        }
    }

    // ----- helpers -----

    private function makeSettings(): GitStackSettings
    {
        return GitStackSettings::createNew(
            'https://github.com/owner/repo.git',
            'main',
            'whoami/compose.yaml',
            $this->mnt . '/user/appdata/git',
            'whoami'
        );
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
