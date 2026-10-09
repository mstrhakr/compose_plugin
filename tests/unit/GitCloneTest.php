<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use GitClone;
use GitCloneNotOwnedException;
use GitCommand;
use GitStackSettings;
use PluginTests\TestCase;
use RuntimeException;

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitClone.php';

/**
 * Real git against a temporary "upstream" bare repository under the fake /mnt.
 *
 * Layout: $mnt/user/repos/upstream.git is the remote; $this->author is a
 * normal clone of it used to make and push commits; clones made by the plugin
 * go under $mnt/user/appdata/git.
 */
final class GitCloneTest extends TestCase
{
    private string $mnt;
    private string $upstream;
    private string $author;
    private string $clonesRoot;
    private string $stackDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mnt = COMPOSE_GIT_MNT_DIR;
        $this->removeTree($this->mnt);
        mkdir($this->mnt . '/user/appdata', 0755, true);
        mkdir($this->mnt . '/user/repos', 0755, true);
        $this->setArrayState('STARTED');
        file_put_contents(COMPOSE_MOUNTS_FILE, "rootfs {$this->mnt} rootfs rw 0 0\nshfs {$this->mnt}/user fuse.shfs rw 0 0\n");

        $this->upstream = $this->mnt . '/user/repos/upstream.git';
        $this->author = sys_get_temp_dir() . '/compose_git_clone_author';
        $this->clonesRoot = $this->mnt . '/user/appdata/git';
        $this->stackDir = sys_get_temp_dir() . '/compose_git_clone_stack';
        $this->removeTree($this->author);
        $this->removeTree($this->stackDir);
        mkdir($this->stackDir);

        $this->git(['init', '-q', '--bare', '-b', 'main', $this->upstream], null);
        $this->git(['clone', '-q', $this->upstream, $this->author], null);
        $this->git(['checkout', '-q', '-b', 'main'], $this->author);
        $this->writeAndPush([
            'whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami\n",
            'other/compose.yaml' => "services:\n  other:\n    image: busybox\n",
            'README.md' => "stacks\n",
        ], 'first');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->mnt);
        $this->removeTree($this->author);
        $this->removeTree($this->stackDir);
        @unlink(COMPOSE_UNRAID_VAR_INI);
        @unlink(COMPOSE_MOUNTS_FILE);
        parent::tearDown();
    }

    // ----- creating a clone -----

    public function testCreateChecksOutTheBranch(): void
    {
        $clone = $this->makeClone('whoami/compose.yaml');
        $commit = $clone->create();

        $dir = $clone->settings()->cloneDir;
        $this->assertSame($this->upstreamHead(), $commit);
        $this->assertFileExists($dir . '/whoami/compose.yaml');
        $this->assertFileExists($dir . '/other/compose.yaml');
        $this->assertSame($clone->settings()->cloneId . "\n", file_get_contents($dir . '/.git/' . GitClone::MARKER_FILE));
        $clone->assertOwned();
    }

    public function testRepositoryAndCloneOwnedByAnotherUserStillWork(): void
    {
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('Needs root to change file owners.');
        }
        // A repository kept by a container, and New Permissions run over the clones.
        $head = $this->upstreamHead();
        $this->chownTree($this->upstream, 65534);
        $clone = $this->makeClone('whoami/compose.yaml');
        $this->assertSame($head, $clone->create());
        $this->chownTree($clone->settings()->cloneDir, 65534);

        $clone->assertOwned();
        $this->assertSame($head, $clone->fetch());
        $this->assertSame([], $clone->locallyChangedFiles());
    }

    public function testCreateRefusesAnExistingFolderAndLeavesItAlone(): void
    {
        $clone = $this->makeClone('whoami/compose.yaml');
        $dir = $clone->settings()->cloneDir;
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/precious.txt', 'keep me');

        try {
            $clone->create();
            $this->fail('Cloned into an existing folder');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('already exists', $error->getMessage());
        }
        $this->assertSame('keep me', file_get_contents($dir . '/precious.txt'));
        $this->assertSame(['.', '..', 'precious.txt'], scandir($dir));
    }

    public function testCreateRefusesAnExistingEmptyFolder(): void
    {
        $clone = $this->makeClone('whoami/compose.yaml');
        mkdir($clone->settings()->cloneDir, 0755, true);
        $this->expectException(RuntimeException::class);
        $clone->create();
    }

    public function testMissingBranchLeavesNothingBehind(): void
    {
        $clone = $this->makeClone('whoami/compose.yaml', 'mian');
        try {
            $clone->create();
            $this->fail('Cloned a branch that does not exist');
        } catch (RuntimeException) {
            $this->assertFalse($clone->exists());
            $this->assertDirectoryExists($this->clonesRoot);
        }
    }

    public function testMissingRepositoryLeavesNothingBehind(): void
    {
        $settings = GitStackSettings::createNew($this->mnt . '/user/repos/typo.git', 'main', 'whoami/compose.yaml', $this->clonesRoot, 'whoami');
        $clone = new GitClone($settings);
        try {
            $clone->create();
            $this->fail('Cloned a repository that does not exist');
        } catch (RuntimeException) {
            $this->assertFalse($clone->exists());
        }
    }

    public function testEmptyRepositoryIsRefused(): void
    {
        $empty = $this->mnt . '/user/repos/empty.git';
        $this->git(['init', '-q', '--bare', '-b', 'main', $empty], null);
        $clone = new GitClone(GitStackSettings::createNew($empty, 'main', 'compose.yaml', $this->clonesRoot, 'x'));

        $this->expectException(RuntimeException::class);
        $clone->create();
    }

    public function testNothingIsClonedWhileTheArrayIsStopped(): void
    {
        $clone = $this->makeClone('whoami/compose.yaml');
        $this->setArrayState('STOPPED');
        try {
            $clone->create();
            $this->fail('Cloned on a stopped array');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist($this->clonesRoot);
        }
    }

    public function testSubmodulesAreNotFetched(): void
    {
        $sub = $this->mnt . '/user/repos/sub.git';
        $this->git(['init', '-q', '--bare', '-b', 'main', $sub], null);
        $this->git(
            ['-c', 'protocol.file.allow=always', 'submodule', 'add', '-q', $this->upstream, 'whoami/vendored'],
            $this->author
        );
        $this->commitAndPush('add submodule');

        $clone = $this->makeClone('whoami/compose.yaml');
        $clone->create();

        $this->assertSame(['.', '..'], scandir($clone->settings()->cloneDir . '/whoami/vendored'));
    }

    // ----- ownership -----

    public function testFolderWithoutMarkerIsNotOwned(): void
    {
        $clone = $this->createdClone();
        unlink($clone->settings()->cloneDir . '/.git/' . GitClone::MARKER_FILE);
        $this->expectException(GitCloneNotOwnedException::class);
        $clone->assertOwned();
    }

    public function testFolderMarkedForAnotherStackIsNotOwned(): void
    {
        $clone = $this->createdClone();
        file_put_contents($clone->settings()->cloneDir . '/.git/' . GitClone::MARKER_FILE, "ffffffffffffffff\n");
        $this->expectException(GitCloneNotOwnedException::class);
        $clone->assertOwned();
    }

    public function testSymlinkInPlaceOfTheCloneIsNotOwned(): void
    {
        $clone = $this->createdClone();
        $dir = $clone->settings()->cloneDir;
        rename($dir, $dir . '-real');
        symlink($dir . '-real', $dir);
        $this->expectException(GitCloneNotOwnedException::class);
        $clone->assertOwned();
    }

    public function testCloneOfADifferentRepositoryIsNotOwned(): void
    {
        $clone = $this->createdClone();
        $this->git(['remote', 'set-url', 'origin', $this->mnt . '/user/repos/other.git'], $clone->settings()->cloneDir);
        $this->expectException(GitCloneNotOwnedException::class);
        $this->expectExceptionMessage('different repository');
        $clone->assertOwned();
    }

    public function testGitFilePointingElsewhereIsNotOwned(): void
    {
        $clone = $this->createdClone();
        $dir = $clone->settings()->cloneDir;
        rename($dir . '/.git', $this->mnt . '/user/appdata/moved-git');
        file_put_contents($dir . '/.git', 'gitdir: ' . $this->mnt . "/user/appdata/moved-git\n");
        $this->expectException(GitCloneNotOwnedException::class);
        $clone->assertOwned();
    }

    public function testMissingCloneIsReportedAsMissing(): void
    {
        $clone = $this->makeClone('whoami/compose.yaml');
        $this->expectException(GitCloneNotOwnedException::class);
        $this->expectExceptionMessage('missing');
        $clone->assertOwned();
    }

    // ----- fetching and checking out -----

    public function testFetchThenCheckOutMovesToTheNewCommit(): void
    {
        $clone = $this->createdClone();
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v2\n"], 'bump');

        $newCommit = $clone->fetch();
        $this->assertSame($this->upstreamHead(), $newCommit);
        $clone->checkOut($newCommit);

        $this->assertSame($newCommit, $clone->checkedOutCommit());
        $this->assertStringContainsString('v2', (string) file_get_contents($clone->settings()->cloneDir . '/whoami/compose.yaml'));
    }

    public function testRemoteBranchCommitDoesNotChangeTheClone(): void
    {
        $clone = $this->createdClone();
        $before = $clone->checkedOutCommit();
        $this->writeAndPush(['whoami/new.txt' => "x\n"], 'more');

        $this->assertSame($this->upstreamHead(), $clone->remoteBranchCommit());
        $this->assertSame($before, $clone->checkedOutCommit());
    }

    public function testForcePushUpstreamIsJustANewTarget(): void
    {
        $clone = $this->createdClone();
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.invalid', 'commit', '-q', '--amend', '-m', 'rewritten'], $this->author);
        $this->git(['push', '-q', '--force', 'origin', 'main'], $this->author);

        $commit = $clone->fetch();
        $clone->checkOut($commit);
        $this->assertSame($this->upstreamHead(), $clone->checkedOutCommit());
    }

    public function testBranchDeletedUpstreamFailsAndLeavesFilesAlone(): void
    {
        $clone = $this->createdClone();
        $before = $clone->checkedOutCommit();
        $this->git(['push', '-q', 'origin', 'HEAD:refs/heads/keep'], $this->author);
        $this->git(['--git-dir=' . $this->upstream, 'symbolic-ref', 'HEAD', 'refs/heads/keep'], null);
        $this->git(['push', '-q', 'origin', '--delete', 'main'], $this->author);

        try {
            $clone->fetch();
            $this->fail('Fetched a deleted branch');
        } catch (RuntimeException) {
            $this->assertSame($before, $clone->checkedOutCommit());
            $this->assertFileExists($clone->settings()->cloneDir . '/whoami/compose.yaml');
        }
    }

    public function testUntrackedFilesSurviveACheckout(): void
    {
        $clone = $this->createdClone();
        $data = $clone->settings()->cloneDir . '/whoami/data/app.db';
        mkdir(dirname($data));
        file_put_contents($data, 'container data');
        $this->writeAndPush(['whoami/compose.yaml' => "services: {}\n"], 'change');

        $clone->checkOut($clone->fetch());

        $this->assertSame('container data', file_get_contents($data));
        $this->assertSame([], $clone->locallyChangedFiles());
    }

    public function testCheckoutThatWouldOverwriteAnUntrackedFileChangesNothing(): void
    {
        $clone = $this->createdClone();
        $before = $clone->checkedOutCommit();
        $local = $clone->settings()->cloneDir . '/whoami/config.yml';
        file_put_contents($local, 'my local config');
        $this->writeAndPush(['whoami/config.yml' => "from upstream\n"], 'add config');

        try {
            $clone->checkOut($clone->fetch());
            $this->fail('Overwrote an untracked file');
        } catch (RuntimeException $error) {
            // git refuses, and the message names the file.
            $this->assertStringContainsString('whoami/config.yml', $error->getMessage());
            $this->assertStringContainsString('Move them out of the way', $error->getMessage());
        }
        $this->assertSame('my local config', file_get_contents($local));
        $this->assertSame($before, $clone->checkedOutCommit());
    }

    public function testCheckoutThatWouldPutAFileWhereAnUntrackedFolderIsChangesNothing(): void
    {
        $clone = $this->createdClone();
        $before = $clone->checkedOutCommit();
        $folder = $clone->settings()->cloneDir . '/whoami/data';
        mkdir($folder);
        file_put_contents($folder . '/app.db', 'container data');
        $this->writeAndPush(['whoami/data' => "now a file upstream\n"], 'file where data folder is');

        try {
            $clone->checkOut($clone->fetch());
            $this->fail('Replaced an untracked folder');
        } catch (RuntimeException) {
            $this->assertSame('container data', file_get_contents($folder . '/app.db'));
            $this->assertSame($before, $clone->checkedOutCommit());
        }
    }

    public function testCheckoutThatNeedsAFolderWhereAnUntrackedFileIsChangesNothing(): void
    {
        $clone = $this->createdClone();
        $before = $clone->checkedOutCommit();
        $file = $clone->settings()->cloneDir . '/whoami/conf';
        file_put_contents($file, 'local conf file');
        $this->writeAndPush(['whoami/conf/app.yml' => "upstream\n"], 'folder where conf file is');

        try {
            $clone->checkOut($clone->fetch());
            $this->fail('Replaced an untracked file with a folder');
        } catch (RuntimeException) {
            $this->assertSame('local conf file', file_get_contents($file));
            $this->assertSame($before, $clone->checkedOutCommit());
        }
    }

    public function testCheckoutThatWouldOverwriteAnIgnoredFileChangesNothing(): void
    {
        $this->writeAndPush(['.gitignore' => "whoami/data/\n"], 'ignore data');
        $clone = $this->createdClone();
        $before = $clone->checkedOutCommit();
        $data = $clone->settings()->cloneDir . '/whoami/data/settings.ini';
        mkdir(dirname($data));
        file_put_contents($data, 'live data written by the container');
        // The repository starts tracking a file under the ignored folder.
        mkdir($this->author . '/whoami/data');
        file_put_contents($this->author . '/whoami/data/settings.ini', "template\n");
        $this->git(['add', '-f', '--', 'whoami/data/settings.ini'], $this->author);
        $this->commitAndPush('track a template under data');

        try {
            $clone->checkOut($clone->fetch());
            $this->fail('Overwrote an ignored file');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('whoami/data/settings.ini', $error->getMessage());
            $this->assertStringContainsString('Move them out of the way', $error->getMessage());
        }
        $this->assertSame('live data written by the container', file_get_contents($data));
        $this->assertSame($before, $clone->checkedOutCommit());
    }

    public function testCheckoutThatDropsTheIgnoreRuleAndTracksTheFileChangesNothing(): void
    {
        $this->writeAndPush(['.gitignore' => "whoami/data/\n"], 'ignore data');
        $clone = $this->createdClone();
        $before = $clone->checkedOutCommit();
        $data = $clone->settings()->cloneDir . '/whoami/data/settings.ini';
        mkdir(dirname($data));
        file_put_contents($data, 'live data written by the container');
        $this->writeAndPush(['.gitignore' => "", 'whoami/data/settings.ini' => "template\n"], 'stop ignoring data');

        try {
            $clone->checkOut($clone->fetch());
            $this->fail('Overwrote a file that was ignored before the checkout');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('whoami/data/settings.ini', $error->getMessage());
        }
        $this->assertSame('live data written by the container', file_get_contents($data));
        $this->assertSame($before, $clone->checkedOutCommit());
    }

    public function testCheckoutThatNeedsAFolderWhereAnIgnoredFileIsChangesNothing(): void
    {
        $this->writeAndPush(['.gitignore' => "whoami/cache\n"], 'ignore cache');
        $clone = $this->createdClone();
        $before = $clone->checkedOutCommit();
        $file = $clone->settings()->cloneDir . '/whoami/cache';
        file_put_contents($file, 'ignored cache file');
        mkdir($this->author . '/whoami/cache');
        file_put_contents($this->author . '/whoami/cache/x', "upstream\n");
        $this->git(['add', '-f', '--', 'whoami/cache/x'], $this->author);
        $this->commitAndPush('cache is a folder');

        try {
            $clone->checkOut($clone->fetch());
            $this->fail('Replaced an ignored file with a folder');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('whoami/cache', $error->getMessage());
        }
        $this->assertSame('ignored cache file', file_get_contents($file));
        $this->assertSame($before, $clone->checkedOutCommit());
    }

    public function testCheckoutThatTurnsAFolderHoldingIgnoredFilesIntoAFileChangesNothing(): void
    {
        $this->writeAndPush(['.gitignore' => "*.log\n", 'whoami/logs/README' => "logs go here\n"], 'logs folder');
        $clone = $this->createdClone();
        $before = $clone->checkedOutCommit();
        $log = $clone->settings()->cloneDir . '/whoami/logs/app.log';
        file_put_contents($log, 'ignored log');
        $this->git(['rm', '-q', '-r', 'whoami/logs'], $this->author);
        $this->writeAndPush(['whoami/logs' => "now a file\n"], 'logs is a file');

        try {
            $clone->checkOut($clone->fetch());
            $this->fail('Removed a folder holding an ignored file');
        } catch (RuntimeException $error) {
            // git's message for this case lists the folder.
            $this->assertStringContainsString('whoami/logs', $error->getMessage());
            $this->assertStringContainsString('Move them out of the way', $error->getMessage());
        }
        $this->assertSame('ignored log', file_get_contents($log));
        $this->assertSame($before, $clone->checkedOutCommit());
    }

    public function testTrackedFileBecomingASymlinkIsNotMistakenForAnUntrackedFile(): void
    {
        $clone = $this->createdClone();
        $this->git(['rm', '-q', 'whoami/compose.yaml'], $this->author);
        @mkdir($this->author . '/whoami');
        symlink('../other/compose.yaml', $this->author . '/whoami/compose.yaml');
        $this->git(['add', 'whoami/compose.yaml'], $this->author);
        $this->commitAndPush('compose file is now a symlink');

        $clone->checkOut($clone->fetch());

        $this->assertNotFalse(readlink($clone->settings()->cloneDir . '/whoami/compose.yaml'));
    }

    public function testTrackedFileReplacedByAFolderIsNotMistakenForAnUntrackedFile(): void
    {
        $this->writeAndPush(['whoami/conf' => "a file\n"], 'conf is a file');
        $clone = $this->createdClone();
        $this->git(['rm', '-q', 'whoami/conf'], $this->author);
        $this->writeAndPush(['whoami/conf/app.yml' => "now a folder\n"], 'conf is a folder');

        $clone->checkOut($clone->fetch());

        $this->assertFileExists($clone->settings()->cloneDir . '/whoami/conf/app.yml');
    }

    public function testTrackedFolderReplacedByAFileIsNotMistakenForAnUntrackedFile(): void
    {
        $this->writeAndPush(['whoami/conf/app.yml' => "in a folder\n"], 'conf is a folder');
        $clone = $this->createdClone();
        $this->git(['rm', '-q', '-r', 'whoami/conf'], $this->author);
        $this->writeAndPush(['whoami/conf' => "now a file\n"], 'conf is a file');

        $clone->checkOut($clone->fetch());

        $this->assertSame("now a file\n", file_get_contents($clone->settings()->cloneDir . '/whoami/conf'));
    }

    public function testUntrackedSymlinkWhereTheCommitNeedsAFolderChangesNothing(): void
    {
        $clone = $this->createdClone();
        $before = $clone->checkedOutCommit();
        $elsewhere = $this->mnt . '/user/appdata/elsewhere';
        mkdir($elsewhere);
        symlink($elsewhere, $clone->settings()->cloneDir . '/whoami/conf');
        $this->writeAndPush(['whoami/conf/app.yml' => "upstream\n"], 'add conf folder');

        try {
            $clone->checkOut($clone->fetch());
            $this->fail('Checked out through an untracked symlink');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('whoami/conf', $error->getMessage());
        }
        $this->assertFileDoesNotExist($elsewhere . '/app.yml');
        $this->assertSame($before, $clone->checkedOutCommit());
    }

    public function testUntrackedFileInAnotherStackFolderIsAlsoProtected(): void
    {
        $clone = $this->createdClone();
        $local = $clone->settings()->cloneDir . '/other/notes.txt';
        file_put_contents($local, 'mine');
        $this->writeAndPush(['other/notes.txt' => "theirs\n"], 'other stack adds notes');

        try {
            $clone->checkOut($clone->fetch());
            $this->fail('Overwrote an untracked file outside the stack folder');
        } catch (RuntimeException) {
            $this->assertSame('mine', file_get_contents($local));
        }
    }

    public function testCheckoutIsRefusedWithLocalChanges(): void
    {
        $clone = $this->createdClone();
        file_put_contents($clone->settings()->cloneDir . '/whoami/compose.yaml', "edited\n");
        $this->writeAndPush(['whoami/compose.yaml' => "upstream\n"], 'upstream edit');
        $commit = $clone->fetch();

        try {
            $clone->checkOut($commit);
            $this->fail('Checked out over local changes');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('local changes', $error->getMessage());
        }
        $this->assertSame("edited\n", file_get_contents($clone->settings()->cloneDir . '/whoami/compose.yaml'));
    }

    public function testStaleIndexLockIsReportedAndLeftInPlace(): void
    {
        $clone = $this->createdClone();
        $lock = $clone->settings()->cloneDir . '/.git/index.lock';
        file_put_contents($lock, '');
        $this->writeAndPush(['whoami/compose.yaml' => "services: {}\n"], 'change');

        try {
            $clone->checkOut($clone->fetch());
            $this->fail('Ignored a stale index.lock');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('index.lock', $error->getMessage());
        }
        $this->assertFileExists($lock);
    }

    // ----- local changes -----

    public function testLocalEditIsListedAndUntrackedFilesAreNot(): void
    {
        $clone = $this->createdClone();
        $dir = $clone->settings()->cloneDir;
        file_put_contents($dir . '/whoami/compose.yaml', "edited\n");
        file_put_contents($dir . '/whoami/untracked.txt', "x\n");

        $this->assertSame(['whoami/compose.yaml'], $clone->locallyChangedFiles());
    }

    public function testOddFileNamesAreListedExactly(): void
    {
        $names = ['whoami/-rf now.yaml', "whoami/new\nline.txt", 'whoami/caf' . "\u{e9}" . '.txt', 'whoami/*.yaml'];
        $files = [];
        foreach ($names as $name) {
            $files[$name] = "original\n";
        }
        $this->writeAndPush($files, 'odd names');
        $clone = $this->createdClone();
        foreach ($names as $name) {
            file_put_contents($clone->settings()->cloneDir . '/' . $name, "edited\n");
        }

        $changed = $clone->locallyChangedFiles();
        sort($changed);
        sort($names);
        $this->assertSame($names, $changed);
    }

    public function testLocalChangesAreSavedAsAPatchThenDiscarded(): void
    {
        $clone = $this->createdClone();
        $dir = $clone->settings()->cloneDir;
        $deployed = $clone->checkedOutCommit();
        file_put_contents($dir . '/whoami/compose.yaml', "hotfix\n");
        file_put_contents($dir . '/whoami/untracked.txt', "keep\n");

        $patch = $clone->saveChangesAsPatch($deployed, $this->stackDir);
        $clone->discardLocalChanges();

        $this->assertNotNull($patch);
        $this->assertStringContainsString('+hotfix', (string) file_get_contents($patch));
        $this->assertSame([], $clone->locallyChangedFiles());
        $this->assertSame("keep\n", file_get_contents($dir . '/whoami/untracked.txt'));

        // The patch puts the change back.
        $this->git(['apply', $patch], $dir);
        $this->assertSame("hotfix\n", file_get_contents($dir . '/whoami/compose.yaml'));
    }

    public function testDiscardRefusesWhenAFolderOfDataReplacedATrackedFile(): void
    {
        $this->writeAndPush(['whoami/logs' => "placeholder\n"], 'placeholder');
        $clone = $this->createdClone();
        $dir = $clone->settings()->cloneDir;
        unlink($dir . '/whoami/logs');
        mkdir($dir . '/whoami/logs');
        file_put_contents($dir . '/whoami/logs/app.log', "data\n");

        try {
            $clone->discardLocalChanges();
            $this->fail('Discarded over an untracked folder of data');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('whoami/logs', $error->getMessage());
        }
        $this->assertSame("data\n", file_get_contents($dir . '/whoami/logs/app.log'));
    }

    public function testDiscardRefusesWhenAFileOfDataReplacedATrackedFolder(): void
    {
        $this->writeAndPush(['whoami/logs/app.log' => "placeholder\n"], 'placeholder');
        $clone = $this->createdClone();
        $dir = $clone->settings()->cloneDir;
        unlink($dir . '/whoami/logs/app.log');
        rmdir($dir . '/whoami/logs');
        file_put_contents($dir . '/whoami/logs', "data\n");

        try {
            $clone->discardLocalChanges();
            $this->fail('Discarded over an untracked file of data');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('whoami/logs', $error->getMessage());
        }
        $this->assertSame("data\n", file_get_contents($dir . '/whoami/logs'));
    }

    public function testEditToAFileRemovedFromTheIndexIsSavedInThePatch(): void
    {
        $clone = $this->createdClone();
        $dir = $clone->settings()->cloneDir;
        $deployed = $clone->checkedOutCommit();
        $this->git(['rm', '-q', '--cached', 'whoami/compose.yaml'], $dir);
        file_put_contents($dir . '/whoami/compose.yaml', "edited\n");

        // Unstaging tracks the file again, so the edit is an ordinary local change.
        $clone->unstageAll();
        $patch = $clone->saveChangesAsPatch($deployed, $this->stackDir);
        $clone->discardLocalChanges();

        $this->assertStringContainsString('+edited', (string) file_get_contents((string) $patch));
        $this->git(['apply', (string) $patch], $dir);
        $this->assertSame("edited\n", file_get_contents($dir . '/whoami/compose.yaml'));
    }

    public function testStagedNewFileSurvivesADiscard(): void
    {
        $clone = $this->createdClone();
        $dir = $clone->settings()->cloneDir;
        $deployed = $clone->checkedOutCommit();
        mkdir($dir . '/whoami/data');
        file_put_contents($dir . '/whoami/data/app.db', 'container data');
        file_put_contents($dir . '/whoami/compose.yaml', "hotfix\n");
        // "git add -A" after a hotfix also stages the container's data.
        $this->git(['add', '-A'], $dir);

        $clone->unstageAll();
        $patch = $clone->saveChangesAsPatch($deployed, $this->stackDir);
        $clone->discardLocalChanges();

        $this->assertSame('container data', file_get_contents($dir . '/whoami/data/app.db'));
        $this->assertSame([], $clone->locallyChangedFiles());
        // The data is not in the patch: it stayed where it was.
        $this->assertStringNotContainsString('app.db', (string) file_get_contents((string) $patch));
        $this->assertStringContainsString('+hotfix', (string) file_get_contents((string) $patch));
    }

    public function testCommitMadeByHandInTheCloneIsInThePatch(): void
    {
        $clone = $this->createdClone();
        $dir = $clone->settings()->cloneDir;
        $deployed = $clone->checkedOutCommit();
        file_put_contents($dir . '/whoami/compose.yaml', "committed by hand\n");
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.invalid', 'commit', '-q', '-am', 'by hand'], $dir);

        $patch = $clone->saveChangesAsPatch($deployed, $this->stackDir);

        $this->assertNotNull($patch);
        $this->assertStringContainsString('+committed by hand', (string) file_get_contents($patch));
    }

    public function testNothingToSaveGivesNoPatch(): void
    {
        $clone = $this->createdClone();
        $this->assertNull($clone->saveChangesAsPatch($clone->checkedOutCommit(), $this->stackDir));
        $this->assertDirectoryDoesNotExist($this->stackDir . '/git-changes');
    }

    public function testTwoPatchesInTheSameSecondDoNotOverwriteEachOther(): void
    {
        $clone = $this->createdClone();
        $deployed = $clone->checkedOutCommit();
        file_put_contents($clone->settings()->cloneDir . '/whoami/compose.yaml', "one\n");
        $first = $clone->saveChangesAsPatch($deployed, $this->stackDir);
        $second = $clone->saveChangesAsPatch($deployed, $this->stackDir);

        $this->assertNotSame($first, $second);
        $this->assertFileExists((string) $first);
        $this->assertFileExists((string) $second);
    }

    // ----- what changed -----

    public function testChangeInAnotherStackFolderDoesNotCount(): void
    {
        $clone = $this->createdClone();
        $before = $clone->checkedOutCommit();
        $this->writeAndPush(['other/compose.yaml' => "services: {}\n", 'README.md' => "changed\n"], 'other stack');

        $this->assertFalse($clone->stackFilesChanged($before, $clone->fetch()));
    }

    public function testChangeInTheStackFolderCounts(): void
    {
        $clone = $this->createdClone();
        $before = $clone->checkedOutCommit();
        $this->writeAndPush(['whoami/extra.env' => "A=1\n"], 'this stack');

        $this->assertTrue($clone->stackFilesChanged($before, $clone->fetch()));
    }

    public function testComposeFileRemovedUpstreamIsDetected(): void
    {
        $clone = $this->createdClone();
        $this->git(['rm', '-q', 'whoami/compose.yaml'], $this->author);
        $this->commitAndPush('remove whoami');

        $this->assertFalse($clone->composeFileExistsAt($clone->fetch()));
        $this->assertTrue($clone->composeFileExistsAt($clone->checkedOutCommit()));
    }

    public function testWindowsLineEndingsFromGitattributesDoNotCountAsLocalChanges(): void
    {
        $this->writeAndPush([
            '.gitattributes' => "* text=auto eol=crlf\n",
            'whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami\n",
        ], 'crlf');
        $clone = $this->createdClone();

        $this->assertSame([], $clone->locallyChangedFiles());
        $this->assertStringContainsString("\r\n", (string) file_get_contents($clone->settings()->cloneDir . '/whoami/compose.yaml'));
    }

    // ----- helpers -----

    private function makeClone(string $composePath, string $branch = 'main'): GitClone
    {
        return new GitClone(GitStackSettings::createNew($this->upstream, $branch, $composePath, $this->clonesRoot, 'whoami'));
    }

    private function createdClone(): GitClone
    {
        $clone = $this->makeClone('whoami/compose.yaml');
        $clone->create();
        return $clone;
    }

    /** @param array<string, string> $files */
    private function writeAndPush(array $files, string $message): void
    {
        foreach ($files as $path => $content) {
            $full = $this->author . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0755, true);
            }
            file_put_contents($full, $content);
            $this->git(['add', '--', $path], $this->author);
        }
        $this->commitAndPush($message);
    }

    private function commitAndPush(string $message): void
    {
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.invalid', 'commit', '-q', '-m', $message], $this->author);
        $this->git(['push', '-q', 'origin', 'main'], $this->author);
    }

    private function upstreamHead(): string
    {
        return trim(GitCommand::run(['--git-dir=' . $this->upstream, 'rev-parse', 'refs/heads/main'])->stdout);
    }

    /** @param string[] $args */
    private function git(array $args, ?string $dir): void
    {
        $result = GitCommand::run($args, $dir);
        $this->assertTrue($result->succeeded(), 'git ' . implode(' ', $args) . ': ' . $result->stderr);
    }

    private function setArrayState(string $state): void
    {
        file_put_contents(COMPOSE_UNRAID_VAR_INI, "mdState=\"$state\"\n");
    }

    private function chownTree(string $path, int $uid): void
    {
        $result = \ProcessRunner::run(['chown', '-R', (string) $uid, $path], '/', ['PATH' => '/usr/bin:/bin'], 30);
        $this->assertTrue($result->succeeded(), $result->stderr);
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
