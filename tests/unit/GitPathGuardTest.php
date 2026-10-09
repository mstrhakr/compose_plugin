<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use GitPathGuard;
use InvalidArgumentException;
use PluginTests\TestCase;
use RuntimeException;

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitPathGuard.php';

/**
 * The guard stands in for Unraid's /mnt with a temp folder (COMPOSE_GIT_MNT_DIR),
 * and fakes the array state and mount table with temp files.
 */
final class GitPathGuardTest extends TestCase
{
    private string $mnt;

    /** A folder outside the fake /mnt, for symlinks that try to escape it. */
    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mnt = COMPOSE_GIT_MNT_DIR;
        $this->outside = sys_get_temp_dir() . '/compose_git_outside';
        $this->removeTree($this->mnt);
        $this->removeTree($this->outside);
        mkdir($this->outside);
        mkdir($this->mnt . '/user/appdata', 0755, true);
        mkdir($this->mnt . '/disk1/appdata', 0755, true);
        mkdir($this->mnt . '/disks/usb stick/stuff', 0755, true);
        $this->setArrayState('STARTED');
        $this->setMounts(['user', 'disk1']);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->mnt);
        $this->removeTree($this->outside);
        @unlink(COMPOSE_UNRAID_VAR_INI);
        @unlink(COMPOSE_MOUNTS_FILE);
        parent::tearDown();
    }

    // ----- array state -----

    public function testArrayStartedIsReadFromVarIni(): void
    {
        $this->assertTrue(GitPathGuard::isArrayStarted());
    }

    public function testArrayStoppedOrStartingCountsAsNotStarted(): void
    {
        foreach (['STOPPED', 'STARTING', 'STOPPING', ''] as $state) {
            $this->setArrayState($state);
            $this->assertFalse(GitPathGuard::isArrayStarted(), "mdState=$state");
        }
    }

    public function testMissingVarIniCountsAsNotStarted(): void
    {
        unlink(COMPOSE_UNRAID_VAR_INI);
        $this->assertFalse(GitPathGuard::isArrayStarted());
    }

    // ----- mount table -----

    public function testMountPointsWithEscapedSpacesAreDecoded(): void
    {
        file_put_contents(COMPOSE_MOUNTS_FILE, "/dev/sdx1 {$this->mnt}/disks/usb\\040stick xfs rw 0 0\n");
        $this->assertSame([$this->mnt . '/disks/usb stick'], GitPathGuard::listMountPoints());
    }

    public function testFindMountPicksTheLongestMatchingMount(): void
    {
        file_put_contents(
            COMPOSE_MOUNTS_FILE,
            "rootfs / rootfs rw 0 0\n"
            . "rootfs {$this->mnt} rootfs rw 0 0\n"
            . "shfs {$this->mnt}/user fuse.shfs rw 0 0\n"
            . "/dev/sdx1 {$this->mnt}/user/appdata/inner xfs rw 0 0\n"
        );
        $this->assertSame($this->mnt . '/user/appdata/inner', GitPathGuard::findMountFor($this->mnt . '/user/appdata/inner/x'));
        $this->assertSame($this->mnt . '/user', GitPathGuard::findMountFor($this->mnt . '/user/appdata/x'));
    }

    public function testMntItselfAndRootNeverCountAsAMount(): void
    {
        file_put_contents(COMPOSE_MOUNTS_FILE, "rootfs / rootfs rw 0 0\nrootfs {$this->mnt} rootfs rw 0 0\n");
        $this->assertNull(GitPathGuard::findMountFor($this->mnt . '/user/appdata/git'));
    }

    public function testMountNameThatOnlySharesAPrefixDoesNotMatch(): void
    {
        // /mnt/user0 must not be taken as being on /mnt/user.
        $this->assertNull(GitPathGuard::findMountFor($this->mnt . '/user0/appdata'));
    }

    public function testRamMountInsideADiskMountIsRefused(): void
    {
        // A tmpfs mounted inside a pool (a RAM transcode folder, say) keeps its contents
        // in RAM, even though the pool is mounted below it.
        mkdir($this->mnt . '/cache/appdata/ram/git', 0755, true);
        file_put_contents(
            COMPOSE_MOUNTS_FILE,
            "rootfs / rootfs rw 0 0\n"
            . "rootfs {$this->mnt} rootfs rw 0 0\n"
            . "/dev/nvme0n1p1 {$this->mnt}/cache xfs rw 0 0\n"
            . "tmpfs {$this->mnt}/cache/appdata/ram tmpfs rw 0 0\n"
        );

        $this->assertNull(GitPathGuard::findMountFor($this->mnt . '/cache/appdata/ram/git'));
        $this->assertSame($this->mnt . '/cache', GitPathGuard::findMountFor($this->mnt . '/cache/appdata/git'));

        try {
            GitPathGuard::assertSafeToWrite($this->mnt . '/cache/appdata/ram/git');
            $this->fail('A folder on a tmpfs inside a pool was accepted for writing.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('not on a mounted disk', $error->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        GitPathGuard::assertValidClonesRoot($this->mnt . '/cache/appdata/ram/git');
    }

    // ----- clones root -----

    public function testUsualClonesRootsAreAccepted(): void
    {
        GitPathGuard::assertValidClonesRoot($this->mnt . '/user/appdata/compose.manager/git');
        GitPathGuard::assertValidClonesRoot($this->mnt . '/disk1/appdata/git');
        GitPathGuard::assertValidClonesRoot($this->mnt . '/user/appdata');
        $this->addToAssertionCount(3);
    }

    public function testUnassignedDevicesMountIsAccepted(): void
    {
        $this->setMounts(['user', 'disks/usb stick']);
        GitPathGuard::assertValidClonesRoot($this->mnt . '/disks/usb stick/stuff/git');
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string}> */
    public static function badRootShapes(): array
    {
        return [
            'empty' => [''],
            'relative' => ['user/appdata/git'],
            'dot dot' => ['/x/../etc'],
            'dot' => ['/x/./y'],
            'double slash' => ['/x//y'],
            'trailing slash' => ['/x/y/'],
            'newline' => ["/x/y\nz"],
            'nul' => ["/x/y\0z"],
            'backslash' => ['/x\\y'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badRootShapes')]
    public function testMalformedClonesRootIsRefused(string $root): void
    {
        $this->expectException(InvalidArgumentException::class);
        GitPathGuard::assertValidClonesRoot($root);
    }

    public function testRootFilesystemAndFlashAreRefused(): void
    {
        foreach (['/', '/boot/config/plugins/compose.manager/git', '/tmp/git', '/root/git'] as $root) {
            try {
                GitPathGuard::assertValidClonesRoot($root);
                $this->fail("Accepted $root");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMntItselfAndAMountItselfAreRefused(): void
    {
        foreach ([$this->mnt, $this->mnt . '/user', $this->mnt . '/disk1'] as $root) {
            try {
                GitPathGuard::assertValidClonesRoot($root);
                $this->fail("Accepted $root");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTypoInMountNameIsRefusedAsRam(): void
    {
        // /mnt/usr is not a mount: on Unraid this would be written to RAM.
        mkdir($this->mnt . '/usr/appdata', 0755, true);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RAM');
        GitPathGuard::assertValidClonesRoot($this->mnt . '/usr/appdata/git');
    }

    public function testUserShareNotMountedIsRefused(): void
    {
        // The Homelab 2026-09-22 state: /mnt/user exists as a plain directory, shfs is not mounted.
        $this->setMounts(['disk1']);
        $this->expectException(InvalidArgumentException::class);
        GitPathGuard::assertValidClonesRoot($this->mnt . '/user/appdata/git');
    }

    public function testMissingShareIsRefusedRatherThanCreated(): void
    {
        // A typo in the share name would otherwise create a new user share.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');
        GitPathGuard::assertValidClonesRoot($this->mnt . '/user/apdata/git');
    }

    public function testShareThatIsASymlinkIsRefused(): void
    {
        symlink($this->outside, $this->mnt . '/user/etc');
        $this->expectException(InvalidArgumentException::class);
        GitPathGuard::assertValidClonesRoot($this->mnt . '/user/etc/git');
    }

    public function testExclusiveShareOnAPoolIsAcceptedAndWrittenOnThePool(): void
    {
        // Unraid 6.12+: an exclusive share is a symlink /mnt/user/<share> -> /mnt/<pool>/<share>.
        mkdir($this->mnt . '/cache/media', 0755, true);
        symlink($this->mnt . '/cache/media', $this->mnt . '/user/media');
        $this->setMounts(['user', 'disk1', 'cache']);

        GitPathGuard::assertValidClonesRoot($this->mnt . '/user/media/compose.manager/git');
        GitPathGuard::createDirectory($this->mnt . '/user/media/compose.manager/git');

        $this->assertDirectoryExists($this->mnt . '/cache/media/compose.manager/git');
    }

    public function testShareThatIsAZfsDatasetIsAcceptedAndItsFoldersCreated(): void
    {
        // On a ZFS pool each share is a dataset, mounted on its own.
        mkdir($this->mnt . '/cache/appdata', 0755, true);
        $this->setMounts(['user', 'cache', 'cache/appdata']);

        GitPathGuard::assertValidClonesRoot($this->mnt . '/cache/appdata/compose.manager/git');
        GitPathGuard::createDirectory($this->mnt . '/cache/appdata/compose.manager/git');

        $this->assertDirectoryExists($this->mnt . '/cache/appdata/compose.manager/git');
    }

    public function testExclusiveShareThatIsAZfsDatasetIsAccepted(): void
    {
        // The default clones root, with appdata exclusive to a ZFS cache pool.
        $this->removeTree($this->mnt . '/user/appdata');
        mkdir($this->mnt . '/cache/appdata', 0755, true);
        symlink($this->mnt . '/cache/appdata', $this->mnt . '/user/appdata');
        $this->setMounts(['user', 'cache', 'cache/appdata']);

        GitPathGuard::assertValidClonesRoot($this->mnt . '/user/appdata/compose.manager/git');
        GitPathGuard::createDirectory($this->mnt . '/user/appdata/compose.manager/git');

        $this->assertDirectoryExists($this->mnt . '/cache/appdata/compose.manager/git');
    }

    public function testMissingShareOnAZfsPoolIsRefused(): void
    {
        $this->setMounts(['user', 'cache']);
        mkdir($this->mnt . '/cache');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');
        GitPathGuard::assertValidClonesRoot($this->mnt . '/cache/apdata/git');
    }

    public function testSymlinkedShareIsNamedAsASymlink(): void
    {
        symlink($this->outside, $this->mnt . '/user/etc');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is a symlink');
        GitPathGuard::assertValidClonesRoot($this->mnt . '/user/etc/git');
    }

    public function testExclusiveShareWhosePoolIsNotMountedIsRefused(): void
    {
        mkdir($this->mnt . '/cache/media', 0755, true);
        symlink($this->mnt . '/cache/media', $this->mnt . '/user/media');

        $this->expectException(InvalidArgumentException::class);
        GitPathGuard::assertValidClonesRoot($this->mnt . '/user/media/git');
    }

    public function testShareSymlinkToADifferentlyNamedFolderIsRefused(): void
    {
        mkdir($this->mnt . '/cache/other', 0755, true);
        symlink($this->mnt . '/cache/other', $this->mnt . '/user/media');
        $this->setMounts(['user', 'disk1', 'cache']);

        $this->expectException(InvalidArgumentException::class);
        GitPathGuard::assertValidClonesRoot($this->mnt . '/user/media/git');
    }

    public function testFolderLeftOnARamMountForAnUnpluggedDiskIsRefused(): void
    {
        // Unassigned Devices can put a tmpfs over /mnt/disks; the disk's own mount is gone.
        file_put_contents(
            COMPOSE_MOUNTS_FILE,
            "rootfs {$this->mnt} rootfs rw 0 0\ntmpfs {$this->mnt}/disks tmpfs rw 0 0\nshfs {$this->mnt}/user fuse.shfs rw 0 0\n"
        );
        $this->assertNull(GitPathGuard::findMountFor($this->mnt . '/disks/usb stick/stuff'));

        $this->expectException(InvalidArgumentException::class);
        GitPathGuard::assertValidClonesRoot($this->mnt . '/disks/usb stick/git');
    }

    public function testSymlinkDeeperInThePathLeadingOutsideIsRefused(): void
    {
        symlink($this->outside, $this->mnt . '/user/appdata/escape');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('leads outside');
        GitPathGuard::assertValidClonesRoot($this->mnt . '/user/appdata/escape/git');
    }

    public function testSymlinkThatStaysInsideTheMountIsAccepted(): void
    {
        mkdir($this->mnt . '/user/appdata/real');
        symlink($this->mnt . '/user/appdata/real', $this->mnt . '/user/appdata/link');
        GitPathGuard::assertValidClonesRoot($this->mnt . '/user/appdata/link/git');
        $this->addToAssertionCount(1);
    }

    // ----- writing -----

    public function testWriteIsRefusedWhileTheArrayIsStopped(): void
    {
        $this->setArrayState('STOPPED');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('array is not started');
        GitPathGuard::assertSafeToWrite($this->mnt . '/user/appdata/git');
    }

    public function testWriteIsRefusedWhenTheMountHasGone(): void
    {
        $this->setMounts(['disk1']);
        $this->expectException(RuntimeException::class);
        GitPathGuard::assertSafeToWrite($this->mnt . '/user/appdata/git');
    }

    public function testCreateDirectoryMakesOnlyTheMissingLevels(): void
    {
        $target = $this->mnt . '/user/appdata/compose.manager/git';
        GitPathGuard::createDirectory($target);
        $this->assertDirectoryExists($target);
    }

    public function testCreateDirectoryNeverCreatesTheShare(): void
    {
        try {
            GitPathGuard::createDirectory($this->mnt . '/user/newshare/git');
            $this->fail('Created a new share');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist($this->mnt . '/user/newshare');
        }
    }

    public function testCreateDirectoryCreatesNothingWhileTheArrayIsStopped(): void
    {
        $this->setArrayState('STOPPED');
        try {
            GitPathGuard::createDirectory($this->mnt . '/user/appdata/compose.manager/git');
            $this->fail('Created a folder on a stopped array');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist($this->mnt . '/user/appdata/compose.manager');
        }
    }

    public function testCreateDirectoryStopsAtAFileInTheWay(): void
    {
        file_put_contents($this->mnt . '/user/appdata/compose.manager', 'not a folder');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a folder');
        GitPathGuard::createDirectory($this->mnt . '/user/appdata/compose.manager/git');
    }

    public function testCreateDirectoryStopsAtASymlinkInTheWay(): void
    {
        mkdir($this->mnt . '/user/appdata/real');
        symlink($this->mnt . '/user/appdata/real', $this->mnt . '/user/appdata/link');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symlink');
        GitPathGuard::createDirectory($this->mnt . '/user/appdata/link/git');
    }

    // ----- helpers -----

    private function setArrayState(string $state): void
    {
        file_put_contents(COMPOSE_UNRAID_VAR_INI, "version=\"7.3.2\"\nmdState=\"$state\"\n");
    }

    /** @param string[] $names mounts below the fake /mnt */
    private function setMounts(array $names): void
    {
        $lines = ["rootfs / rootfs rw 0 0", "rootfs {$this->mnt} rootfs rw 0 0"];
        foreach ($names as $name) {
            $escaped = str_replace(' ', '\\040', $this->mnt . '/' . $name);
            $lines[] = "shfs $escaped fuse.shfs rw 0 0";
        }
        file_put_contents(COMPOSE_MOUNTS_FILE, implode("\n", $lines) . "\n");
    }

    private function removeTree(string $path): void
    {
        // readlink(), not is_link(): the test stream wrapper hides symlinks from is_link(),
        // and following a symlink here would delete whatever it points at.
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
