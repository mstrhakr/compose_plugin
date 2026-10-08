<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';

/**
 * Checks that keep git-backed stacks from writing anywhere they should not.
 *
 * On Unraid, /mnt itself lives in RAM. Disks, pools and the user shares are
 * mounted below it only while the array is started. A path that is not on one
 * of those mounts, such as a typo like /mnt/usr/appdata, or /mnt/user/... while
 * the array is stopped, is a plain directory in RAM: anything written there is
 * lost at reboot, and a stray file left in /mnt/user can stop the user shares
 * from mounting at the next array start.
 *
 * Every directory the git code creates or changes goes through
 * assertSafeToWrite() immediately before the write.
 */
final class GitPathGuard
{
    /**
     * Whether the Unraid array is started, read from emhttp's var.ini.
     *
     * A missing or unreadable file counts as "not started", so the check fails
     * closed.
     */
    public static function isArrayStarted(): bool
    {
        $varIni = @parse_ini_file(COMPOSE_UNRAID_VAR_INI);
        if (!is_array($varIni)) {
            return false;
        }
        return ($varIni['mdState'] ?? '') === 'STARTED';
    }

    /** Filesystem types that keep their contents in RAM. */
    private const RAM_FILESYSTEMS = ['tmpfs', 'ramfs', 'rootfs'];

    /**
     * Mount points currently listed in the mounts file, as absolute paths.
     *
     * RAM filesystems (tmpfs, ramfs) are left out: Unassigned Devices, for
     * example, puts a small tmpfs over /mnt/disks, and a folder left behind
     * there for an unplugged disk must not count as being on a disk.
     *
     * @return string[]
     */
    public static function listMountPoints(): array
    {
        $content = @file_get_contents(COMPOSE_MOUNTS_FILE);
        if ($content === false) {
            return [];
        }

        $mountPoints = [];
        foreach (explode("\n", $content) as $line) {
            $fields = explode(' ', trim($line));
            if (count($fields) < 3) {
                continue;
            }
            if (in_array($fields[2], self::RAM_FILESYSTEMS, true)) {
                continue;
            }
            // The kernel writes spaces, tabs, newlines and backslashes in mount
            // points as octal escapes, for example "\040" for a space.
            $mountPoints[] = preg_replace_callback(
                '/\\\\([0-7]{3})/',
                static fn(array $match): string => chr((int) octdec($match[1])),
                $fields[1]
            );
        }
        return $mountPoints;
    }

    /**
     * The mount under /mnt that holds a path, or null when the path is not on one.
     *
     * Picks the longest listed mount point that contains the path. /mnt itself
     * and / never count: on Unraid both are RAM.
     */
    public static function findMountFor(string $path): ?string
    {
        $mntDir = rtrim(COMPOSE_GIT_MNT_DIR, '/');
        $best = null;
        foreach (self::listMountPoints() as $mountPoint) {
            $mountPoint = rtrim($mountPoint, '/');
            if (!str_starts_with($mountPoint, $mntDir . '/')) {
                continue;
            }
            if ($path !== $mountPoint && !str_starts_with($path, $mountPoint . '/')) {
                continue;
            }
            if ($best === null || strlen($mountPoint) > strlen($best)) {
                $best = $mountPoint;
            }
        }
        return $best;
    }

    /**
     * Check the shape of an absolute path without touching the filesystem.
     *
     * @throws InvalidArgumentException naming the problem
     */
    public static function assertCleanAbsolutePath(string $path, string $what): void
    {
        if ($path === '') {
            throw new InvalidArgumentException("$what is empty.");
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            throw new InvalidArgumentException("$what contains a control character.");
        }
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException("$what must be an absolute path: $path");
        }
        if (str_contains($path, '\\')) {
            throw new InvalidArgumentException("$what must not contain a backslash: $path");
        }
        foreach (explode('/', substr($path, 1)) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new InvalidArgumentException("$what must not contain empty, '.' or '..' parts: $path");
            }
        }
    }

    /**
     * Check that a path is a usable place to keep git clones.
     *
     * The path must sit on a mount below /mnt (a user share, a disk, a pool or
     * an Unassigned Devices mount), and its share folder (see shareFolder())
     * must already exist. Deeper directories may be created later. This keeps
     * a typo from creating a new share, or a directory in RAM.
     *
     * Does not check the array state; assertSafeToWrite() does that at the
     * moment of writing.
     *
     * @throws InvalidArgumentException naming the problem
     */
    public static function assertValidClonesRoot(string $path): void
    {
        self::assertCleanAbsolutePath($path, 'The git clones folder');
        $path = self::followExclusiveShare($path);

        $mount = self::findMountFor($path);
        if ($mount === null) {
            throw new InvalidArgumentException(
                "The git clones folder $path is not on a disk, pool or share under "
                . COMPOSE_GIT_MNT_DIR . ". Anything written there would be kept in RAM and lost at reboot. "
                . "Check the path for a typo, and that the array is started."
            );
        }
        $share = self::shareFolder($path);
        if ($share === null) {
            throw new InvalidArgumentException(
                "The git clones folder must be a folder inside $mount, not $mount itself."
            );
        }
        if (self::isSymlink($share)) {
            throw new InvalidArgumentException("$share is a symlink, so it was not used. Check the path for a typo.");
        }
        if (!is_dir($share)) {
            throw new InvalidArgumentException(
                "$share does not exist. Create it first (for a user share, add the share in Unraid), "
                . "or check the path for a typo."
            );
        }

        self::assertExistingPartStaysInside($path, $mount);
    }

    /**
     * The share folder of a path below /mnt: its first two folders, such as
     * /mnt/user/appdata, /mnt/cache/appdata or /mnt/disk1/appdata. Null for a
     * path that is not at least that deep.
     *
     * This is measured from /mnt, not from the mount that holds the path: on a
     * ZFS pool every share is a dataset with its own mount, so the mount for
     * /mnt/cache/appdata/compose.manager is /mnt/cache/appdata itself. Call
     * after followExclusiveShare().
     */
    public static function shareFolder(string $path): ?string
    {
        $mntDir = rtrim(COMPOSE_GIT_MNT_DIR, '/');
        if (!str_starts_with($path, $mntDir . '/')) {
            return null;
        }
        $parts = explode('/', substr($path, strlen($mntDir) + 1));
        if (count($parts) < 2) {
            return null;
        }
        return $mntDir . '/' . $parts[0] . '/' . $parts[1];
    }

    /**
     * Throw unless it is safe to create or change something at this path right now.
     *
     * Checks, in order: the array is started, the path is on a live mount below
     * /mnt, and the part of the path that exists does not lead out of that mount
     * through a symlink. Call this immediately before each write, not once at
     * the start of a long operation: the array can stop in between.
     *
     * @throws RuntimeException naming the problem
     */
    public static function assertSafeToWrite(string $path): void
    {
        try {
            self::assertCleanAbsolutePath($path, 'The path');
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException($error->getMessage(), 0, $error);
        }

        if (!self::isArrayStarted()) {
            throw new RuntimeException("The array is not started, so nothing was written to $path.");
        }

        $path = self::followExclusiveShare($path);
        $mount = self::findMountFor($path);
        if ($mount === null) {
            throw new RuntimeException(
                "$path is not on a mounted disk, pool or share, so nothing was written there."
            );
        }

        try {
            self::assertExistingPartStaysInside($path, $mount);
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException($error->getMessage(), 0, $error);
        }
    }

    /**
     * Create a directory and any missing parents, checking each level first.
     *
     * Unlike mkdir($path, 0755, true), this never creates the share folder or
     * anything above it: those must exist (see assertValidClonesRoot).
     *
     * @throws RuntimeException if a level cannot be created safely
     */
    public static function createDirectory(string $path, int $mode = 0755): void
    {
        self::assertSafeToWrite($path);
        $path = self::followExclusiveShare($path);

        $current = self::shareFolder($path);
        if ($current === null || !is_dir($current) || self::isSymlink($current)) {
            throw new RuntimeException(($current ?? $path) . " does not exist, so $path was not created.");
        }

        $levelsToCreate = [];
        if ($path !== $current) {
            $levelsToCreate = explode('/', substr($path, strlen($current) + 1));
        }
        foreach ($levelsToCreate as $part) {
            $current .= '/' . $part;
            if (self::isSymlink($current)) {
                throw new RuntimeException("$current is a symlink, so $path was not created.");
            }
            if (is_dir($current)) {
                continue;
            }
            if (file_exists($current)) {
                throw new RuntimeException("$current exists and is not a folder, so $path was not created.");
            }
            self::assertSafeToWrite($current);
            if (!@mkdir($current, $mode) && !is_dir($current)) {
                throw new RuntimeException("Could not create $current.");
            }
        }
    }

    /**
     * The same path on the pool, when its share is an exclusive share.
     *
     * Since Unraid 6.12, a share that lives on one pool only can be "exclusive":
     * /mnt/user/<share> is then a symlink to /mnt/<pool>/<share>, made by
     * Unraid. Only that exact shape is followed, and only to a live mount: any
     * other symlink at the share level is left alone, so the checks still
     * refuse it.
     */
    public static function followExclusiveShare(string $path): string
    {
        $userDir = rtrim(COMPOSE_GIT_MNT_DIR, '/') . '/user';
        if (!str_starts_with($path, $userDir . '/')) {
            return $path;
        }
        $parts = explode('/', substr($path, strlen($userDir) + 1), 2);
        $share = $parts[0];
        $target = @readlink($userDir . '/' . $share);
        if ($target === false) {
            return $path;
        }

        $pattern = '#^' . preg_quote(rtrim(COMPOSE_GIT_MNT_DIR, '/'), '#') . '/([^/]+)/' . preg_quote($share, '#') . '$#';
        if (preg_match($pattern, $target, $match) !== 1 || $match[1] === 'user' || $match[1] === 'user0') {
            return $path;
        }
        $poolMount = rtrim(COMPOSE_GIT_MNT_DIR, '/') . '/' . $match[1];
        if (self::findMountFor($poolMount) !== $poolMount) {
            return $path;
        }
        return $target . (isset($parts[1]) ? '/' . $parts[1] : '');
    }

    /**
     * Throw if the deepest existing part of a path resolves outside its mount.
     *
     * @throws InvalidArgumentException
     */
    private static function assertExistingPartStaysInside(string $path, string $mount): void
    {
        $existing = $path;
        while (!file_exists($existing) && !self::isSymlink($existing)) {
            $existing = dirname($existing);
        }

        $resolved = realpath($existing);
        $resolvedMount = realpath($mount);
        if ($resolved === false || $resolvedMount === false) {
            throw new InvalidArgumentException("Could not resolve $existing.");
        }
        if ($resolved !== $resolvedMount && !str_starts_with($resolved, $resolvedMount . '/')) {
            throw new InvalidArgumentException(
                "$existing leads outside $mount (it resolves to $resolved), so it was not used."
            );
        }
    }

    /**
     * Whether a path is a symlink (dangling or not).
     *
     * Uses readlink() rather than is_link(): PHP caches is_link() answers
     * during a run, and a stream wrapper registered for file:// (the test
     * framework has one) can change them. readlink() is never cached and
     * always asks the filesystem.
     */
    private static function isSymlink(string $path): bool
    {
        return @readlink($path) !== false;
    }
}
