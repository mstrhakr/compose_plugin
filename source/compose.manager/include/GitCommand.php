<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/ProcessRunner.php';

/**
 * Runs git for git-backed stacks, locked down.
 *
 * The plugin runs as root, and the repositories it works with come from
 * outside, so every run:
 *  - ignores the system and global git config (no helpers, aliases or hooks set
 *    up for other uses of git on the server),
 *  - never asks for input (no password prompt that could hang a deploy),
 *  - runs no repository hooks and no filesystem monitor,
 *  - allows only the https and local-path transports (and ssh for a stack
 *    with a deploy key, through its own run settings: see GitSsh),
 *  - starts from an empty environment, so stray GIT_DIR or GIT_WORK_TREE
 *    variables cannot point it at a different repository,
 *  - is stopped after a time limit, gets no input, and runs without a shell
 *    (see ProcessRunner).
 *
 * Arguments are passed straight to git with no shell in between. Callers must
 * still put user-supplied values after "--" or validate them first, so a value
 * starting with "-" can never be read as an option.
 */
final class GitCommand
{
    public const DEFAULT_TIMEOUT_SECONDS = 120;

    /**
     * Settings passed with -c on every run.
     *
     * @var string[]
     */
    private const FORCED_CONFIG = [
        'core.hooksPath=/dev/null',
        'core.fsmonitor=false',
        'credential.helper=',
        'protocol.allow=never',
        'protocol.https.allow=always',
        'protocol.file.allow=always',
        'submodule.recurse=false',
        'advice.detachedHead=false',
        // Give up on a connection that stalls (under 1 KB/s for 60 seconds).
        'http.lowSpeedLimit=1000',
        'http.lowSpeedTime=60',
    ];

    /**
     * Run git with the given arguments.
     *
     * @param string[] $args Arguments after "git", for example ['fetch', 'origin', '--', $ref]
     * @param string|null $workingDirectory Folder to run in (the clone), or null for a neutral folder
     * @param array<string, string> $extraEnvironment Extra environment variables for this run
     * @param string[] $trustedRepositories Repositories git may use even when another user owns them
     * @param string[] $extraConfig Settings for this run only, passed with -c after the forced ones, such as
     *                              a credential helper for one stack. Built by the plugin, never from user
     *                              input, and never holding a secret: anyone on the server can read a
     *                              command line.
     */
    public static function run(
        array $args,
        ?string $workingDirectory = null,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        array $extraEnvironment = [],
        array $trustedRepositories = [],
        array $extraConfig = []
    ): ProcessResult {
        $homeDirectory = COMPOSE_GIT_HOME_DIR;
        $homeProblem = self::homeDirectoryProblem($homeDirectory);
        if ($homeProblem !== null) {
            // 128, git's own code for "could not run": some callers read exit code 1 as an
            // answer (merge-base --is-ancestor says "no" with 1).
            return new ProcessResult(128, '', $homeProblem, false);
        }

        $command = ['git'];
        foreach (self::FORCED_CONFIG as $setting) {
            $command[] = '-c';
            $command[] = $setting;
        }
        foreach ($extraConfig as $setting) {
            $command[] = '-c';
            $command[] = $setting;
        }
        foreach ($args as $arg) {
            $command[] = $arg;
        }

        $environment = [
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME' => $homeDirectory,
            'XDG_CONFIG_HOME' => $homeDirectory,
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => self::trustFile($homeDirectory, $workingDirectory, $trustedRepositories),
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ASKPASS' => '/bin/false',
            // Paths are always literal names, never patterns such as "*" or ":(top)".
            'GIT_LITERAL_PATHSPECS' => '1',
            // Plain English messages, so errors read the same everywhere.
            'LC_ALL' => 'C',
        ];
        foreach ($extraEnvironment as $name => $value) {
            $environment[$name] = $value;
        }

        return ProcessRunner::run($command, $workingDirectory ?? $homeDirectory, $environment, $timeoutSeconds);
    }

    /**
     * The global config file for a run: /dev/null, or a file that only lists
     * the repositories git may use although another user owns them.
     *
     * git refuses such a repository ("dubious ownership"). A clone can end up
     * owned by nobody:users after Unraid's New Permissions tool, and a
     * repository on this server is often owned by the container that keeps it.
     * Only the folder git is asked to work in and the stack's own repository
     * are listed. It has to be the global config: git 2.47 ignores
     * safe.directory given with -c when it reads from a local repository.
     * One small file is kept per distinct list, in the home folder under
     * /var/tmp, which is in RAM and emptied at reboot.
     *
     * @param string[] $trustedRepositories
     */
    private static function trustFile(string $homeDirectory, ?string $workingDirectory, array $trustedRepositories): string
    {
        $paths = [];
        foreach (array_merge($workingDirectory !== null ? [$workingDirectory] : [], $trustedRepositories) as $path) {
            $paths[] = $path;
            $real = realpath($path);
            if ($real !== false) {
                $paths[] = $real;
            }
        }
        $paths = array_values(array_unique($paths));
        if ($paths === []) {
            return '/dev/null';
        }

        $content = "[safe]\n";
        foreach ($paths as $path) {
            // Quoted, with \ and " escaped, as git's config format needs.
            $content .= "\tdirectory = \"" . addcslashes($path, "\\\"") . "\"\n";
        }
        $file = $homeDirectory . '/trust-' . hash('sha256', $content) . '.gitconfig';
        if (!is_file($file)) {
            $tmpFile = $file . '.tmp-' . bin2hex(random_bytes(4));
            // A short write (a full /var/tmp) must not leave a cut-off file under the
            // content's name: it is never rewritten, and git would refuse to read it.
            if (file_put_contents($tmpFile, $content) !== strlen($content) || !rename($tmpFile, $file)) {
                @unlink($tmpFile);
                return '/dev/null';
            }
        }
        return $file;
    }

    /**
     * Make the folder git runs with as HOME, so no personal config is read, and
     * say what is wrong with it, if anything.
     *
     * It is in /var/tmp, where anyone can create things, and the global config
     * git reads is kept in it: a folder someone else made first (or a symlink
     * there) would let its owner give git a config of their own. So only a plain
     * folder owned by whoever runs this (root on Unraid) is used.
     */
    private static function homeDirectoryProblem(string $home): ?string
    {
        if (!file_exists($home) && @readlink($home) === false && !@mkdir($home, 0700, true) && !is_dir($home)) {
            return "Could not create $home for git.";
        }
        clearstatcache(true, $home);
        if (@readlink($home) !== false || !is_dir($home)) {
            return "$home is not a plain folder, so git was not run. Remove it.";
        }
        if (@fileowner($home) !== (function_exists('posix_geteuid') ? posix_geteuid() : 0)) {
            return "$home belongs to another user, so git was not run. Remove it.";
        }
        if (!chmod($home, 0700)) {
            return "Could not make $home private, so git was not run.";
        }
        return null;
    }
}
