<?php

/**
 * compose-git: create, deploy and look after git-backed stacks from the command line.
 *
 * Run it as scripts/compose-git (a small wrapper around this file). Stacks are
 * named by their exact folder in the projects folder. The exit status is the
 * result, so scripts and git hooks can use it:
 *   0  success (for check: the deployed commit is the branch's latest)
 *   1  failed; the message says what changed, if anything
 *   2  the command line was not understood
 *   3  check only: the branch has a newer commit that changes the stack's folder
 *   4  check only: the branch has a newer commit, but it changes nothing in the stack's folder
 */

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackManager.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitScheduledCheck.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/CronManager.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/Helpers.php';

const COMPOSE_GIT_EXIT_OK = 0;
const COMPOSE_GIT_EXIT_FAILED = 1;
const COMPOSE_GIT_EXIT_USAGE = 2;
const COMPOSE_GIT_EXIT_BEHIND = 3;
const COMPOSE_GIT_EXIT_NEWER_ELSEWHERE = 4;

/** The command line itself was not understood (as opposed to a value that was refused). */
final class ComposeGitUsageError extends InvalidArgumentException
{
}

const COMPOSE_GIT_USAGE = <<<'TEXT'
Usage:
  compose-git add <name> --url <url> --path <compose file> [--branch <branch>] [--clones-root <folder>] [--description <text>] [--credential <name>]
  compose-git convert <stack> --url <url> --path <compose file> [--branch <branch>] [--clones-root <folder>] [--credential <name>]
  compose-git credential <stack> <name>|--none
  compose-git auto-deploy <stack> off|notify|deploy
  compose-git recreate-on-folder-change <stack> on|off
  compose-git resume <stack>
  compose-git deploy-key <stack>
  compose-git trust-host <stack>
  compose-git check <stack>|--all
  compose-git deploy <stack> [--commit <full commit id>] [--save-local-changes] [--wait|--no-wait] [--wait-timeout <seconds>] [--profile <name>]...
  compose-git deploy <stack> --dry-run [--commit <full commit id>] [--profile <name>]...
  compose-git reclone <stack>
  compose-git status [<stack>|--all] [--json]
  compose-git scheduled-check

<url> is an https address (no user name or password in it), an ssh address (ssh://git@host/path
or git@host:path), or the path of a repository under /mnt.
<compose file> is the compose file's path inside the repository, such as stacks/whoami/compose.yaml.
--branch defaults to main. <stack> is the stack's exact folder name.
--credential names a git credential (an HTTPS token) from the plugin's Credentials tab, for a
private repository; credential changes or removes it on an existing stack.
An ssh stack gets a deploy key when it is added, one per repository: a second stack of the same
repository uses the first one's. deploy-key shows it again, to add to the repository as a
read-only deploy key. Its host's keys are pinned when it is added;
trust-host pins them again after the server's keys change.
deploy waits for healthy containers when the stack's wait-for-healthy setting says so;
--wait and --no-wait override it.
auto-deploy sets what the scheduled check does when a new commit changes the stack: nothing
(off, the default), send a notification (notify), or deploy it (deploy). After a failed deploy
the scheduled check leaves the stack alone until a deploy works; resume clears the failure.
recreate-on-folder-change sets whether a deploy that changes the stack's folder in the
repository recreates every container of the stack (on, the default).
scheduled-check runs the scheduled check now (cron runs it every 15 minutes while any stack
asks for it).

Exit status: 0 success, 1 failed, 2 usage error; for check, 3 a newer commit changes the stack's
folder, 4 a newer commit changes only files outside it. See 'compose-git check --help'.

Run 'compose-git <command> --help' for what a command does and what each option means.
TEXT;

/**
 * The detailed help for one command, or null if there is no such command.
 */
function compose_git_command_help(string $command): ?string
{
    return match ($command) {
        'add' => <<<'TEXT'
Usage: compose-git add <name> --url <url> --path <compose file> [options]

Make a new git stack from a repository. The repository is cloned onto the array, and a new
stack folder is made that points at the compose file in the clone. Nothing is deployed yet:
run 'compose-git deploy <stack>' next. If the clone fails, or the branch has no compose file
at --path, nothing is created.

<name> is the stack's name. Its folder name is the name with unsafe characters replaced, and
is printed at the end. No stack folder by that name may exist yet; to put an existing stack
under git, use 'compose-git convert'.

Options:
  --url <url>             The repository: an https:// address with no user name or
                          password in it, an ssh address (ssh://git@host[:port]/path or
                          git@host:path), or the path of a repository on this server under
                          /mnt. Required. An ssh stack gets the repository's deploy key (made
                          for the first stack of the repository), and the host's ssh keys
                          are pinned.
  --path <compose file>   The compose file's path inside the repository, such as
                          stacks/whoami/compose.yaml. Required.
  --branch <branch>       The branch to follow. Default: main.
  --clones-root <folder>  The folder the clone goes in, as <stack>-<id>. It must be on a
                          share, disk or pool, and the share must exist.
                          Default: /mnt/user/appdata/compose.manager/git.
  --description <text>    The description shown for the stack on the Compose page.
  --credential <name>     A git credential (an HTTPS token) from the Credentials tab, by
                          its name or id, for a private repository.

Example:
  compose-git add whoami --url https://github.com/you/stacks.git --path whoami/compose.yaml
TEXT,
        'convert' => <<<'TEXT'
Usage: compose-git convert <stack> --url <url> --path <compose file> [options]

Put an existing stack under git. Copy its compose file into the repository and push it first.
The repository is cloned, and the stack is pointed at the compose file in the clone.

The stack keeps its name, project name, .env, override file and settings, so it keeps its
volumes and networks. Its old compose file, and its old indirect settings if it had any, are
moved into a pre-git-<date> folder in the stack folder: that is the way back. Running
containers are left alone until the next deploy. Folders next to the old compose file are
listed as a reminder, because a relative bind mount such as ./data now points inside the
clone.

If the clone fails, or the branch has no compose file at --path, nothing is changed. It
refuses to start while another operation on the stack is running.

Options:
  --url <url>             The repository: an https:// address with no user name or
                          password in it, an ssh address (ssh://git@host[:port]/path or
                          git@host:path), or the path of a repository on this server under
                          /mnt. Required. An ssh stack gets the repository's deploy key (made
                          for the first stack of the repository), and the host's ssh keys
                          are pinned.
  --path <compose file>   The compose file's path inside the repository. Required.
  --branch <branch>       The branch to follow. Default: main.
  --clones-root <folder>  The folder the clone goes in, as <stack>-<id>. It must be on a
                          share, disk or pool, and the share must exist.
                          Default: /mnt/user/appdata/compose.manager/git.
  --credential <name>     A git credential (an HTTPS token) from the Credentials tab, by
                          its name or id, for a private repository.

Then deploy it: compose-git deploy <stack>
TEXT,
        'credential' => <<<'TEXT'
Usage: compose-git credential <stack> <name>
       compose-git credential <stack> --none

Change the git credential a stack uses to reach its repository, or remove it. <name> is a
git credential (an HTTPS token) from the plugin's Credentials tab, by its name or id. The
repository is reached with the new setting first, and nothing is saved unless that works.
No container is touched.

An ssh stack always has a deploy key. <name> can only be another deploy key of the same
repository: one that another stack of the repository uses, or the one made for the
repository. That moves stacks that each got a key of their own (before a repository's
stacks shared one) onto one key; the old key then stays on the Credentials tab.

Options:
  --none   Reach the repository without a credential, as for a public one.
TEXT,
        'deploy-key' => <<<'TEXT'
Usage: compose-git deploy-key <stack>

Print the public half of an ssh stack's deploy key, to add to the repository as a read-only
deploy key (on GitHub: the repository's Settings, Deploy keys). The key was made when the
first stack of the repository was added, is used by every ssh stack of that repository, and is
kept in the plugin's credential vault. Changes nothing.
TEXT,
        'trust-host' => <<<'TEXT'
Usage: compose-git trust-host <stack>

Pin an ssh stack's repository server keys again, after the server was rebuilt or its keys
were changed on purpose. Until then every fetch fails, because a changed host key is also
what an attacker in between would look like. The pinned and the offered fingerprints are
printed: compare the new ones with the ones your git host publishes before you deploy. The
new keys are saved only after the repository has been reached with them. No container is
touched.
TEXT,
        'check' => <<<'TEXT'
Usage: compose-git check <stack>
       compose-git check --all

Ask the repository for the newest commit on the stack's branch, and compare it with the
deployed commit. When there is a newer one, it is fetched into the clone to see what it
changes. No container and no checked-out file is changed.

The stack's folder is the folder holding its compose file in the repository (the whole
repository when the compose file is at the top). A newer commit that changes nothing in that
folder is reported apart (exit 4): in a repository that holds several stacks, it is usually a
commit to another stack. But a stack can use files from outside its folder: a bind mount of
../shared, a build context of .., an env_file, extends or include in another folder. A change
to those is not seen as a change to the stack, so deploy it anyway when the stack uses them.

Options:
  --all   Check every git stack, one line each.

Exit status:
  0  up to date
  1  the stack or the repository could not be read (or another operation on the stack is
     running)
  3  a newer commit changes the stack's folder, or its changes cannot be identified (nothing
     is deployed yet, or the deployed commit is no longer in the clone); deploy it with
     'compose-git deploy <stack>'
  4  a newer commit is on the branch, but it changes only files outside the stack's folder
With --all: 3 if any stack's folder changed, even when another could not be checked;
otherwise 1 if any could not be checked; otherwise 4 if any has a newer commit outside its
folder. A script that should deploy whenever the branch moves treats 4 like 3.
TEXT,
        'deploy' => <<<'TEXT'
Usage: compose-git deploy <stack> [options]

Deploy the newest commit on the stack's branch, or the commit given with --commit. In order:
fetch the commit, check it out in the clone, check it, pull and build the images, then run
docker compose up. The checks stop the deploy when the compose file is not valid, a variable
it uses is not set, a name in the repository's .env.example is missing from the .env, or an
external network or volume, bind folder, config or secret file, or build folder is missing,
or a container name or port is already taken.

If anything before up fails, the previous commit is put back and no container is changed. If
up itself fails, the commit is recorded as failed ('compose-git status' shows it) and nothing
is rolled back: fix the cause, usually in the repository, and deploy again.

When the commit changes anything in the stack's folder in the repository, every container in
the stack is recreated, so changed config files take effect. To turn that off, run
'compose-git recreate-on-folder-change <stack> off'.

If another operation on the stack is running, such as a Start from the web UI, it waits up
to 30 seconds (COMPOSE_LOCK_TIMEOUT) for it to finish, then fails without changing anything.

Options:
  --commit <id>             Deploy this commit instead of the newest, for example to go back
                            to an older version. Give the full id (40 characters, or 64 in a
                            SHA-256 repository). A later deploy without --commit returns to
                            the branch's newest commit.
  --save-local-changes      If tracked files in the clone were changed, or a commit was made
                            in it, save the changes as a patch in the stack folder's
                            git-changes folder, discard them, then deploy. Without it, the
                            deploy stops and lists the changed files. Files that are not part
                            of the repository are never touched either way.
  --wait                    Wait for the containers to be running and healthy, and count the
                            deploy as failed if they are not. Default: the stack's
                            wait-for-healthy setting.
  --no-wait                 Do not wait, whatever the stack's setting says.
  --wait-timeout <seconds>  How long to wait for healthy containers. Implies --wait.
  --profile <name>          Start the services in this compose profile too. Repeat it for
                            more than one profile. Default: the profiles the stack is running
                            with, else its default profiles, as the web UI's Update uses.
  --dry-run                 Run the checks without deploying: fetch, check out the commit, run
                            every check above, show how the compose configuration changes
                            (variables as written, not their values), and check the commit
                            from before out again. What the checks find is said as a deploy
                            says it, with values filled in (a missing folder's path, say).
                            Nothing is pulled, built or deployed, but while the checks run
                            the clone holds the tried commit, so a container reading files
                            from the clone sees them for that time.
                            Exit status 0 when every check passed, 1 when one did not or the
                            dry run could not be done. Takes only --commit and --profile.

Examples:
  compose-git deploy myapp
  compose-git deploy myapp --commit 4f1c2b0e9d8a7c6b5a4f3e2d1c0b9a8f7e6d5c4b
  compose-git deploy myapp --dry-run
TEXT,
        'reclone' => <<<'TEXT'
Usage: compose-git reclone <stack>

Replace the stack's clone with a fresh one at the deployed commit, for when the clone is in
a bad state. No container is touched.

The old clone is moved aside to <clone>.replaced-<date>, never deleted, and any files in it
that are not part of the repository (data a container wrote there, say) are listed. Copy
back what you need, then delete the old clone yourself. If the new clone fails, the old one
is moved back.

It refuses to start while another operation on the stack is running.
TEXT,
        'status' => <<<'TEXT'
Usage: compose-git status [<stack> | --all] [--json]

Show what is known about git stacks on this server, without asking the repository: the
repository and branch, the compose file's path in it, the clone folder, the deployed commit,
a failed commit if the last deploy failed during up, the commit checked out in the clone,
and the tracked files changed in the clone. Changes nothing.

Options:
  --all    Show every git stack. This is what happens when no stack is named, too. A stack
           whose git settings cannot be read is shown with the reason.
  --json   Print the same as JSON, for scripts.
TEXT,
        'auto-deploy' => <<<'TEXT'
Usage: compose-git auto-deploy <stack> off|notify|deploy

Choose what the scheduled check (every 15 minutes) does when a new commit on the stack's
branch changes the stack, that is, anything in the stack's folder in the repository:
  off      nothing is checked (the default)
  notify   send an Unraid notification, once per commit
  deploy   deploy the commit, as 'compose-git deploy' does with no options
Each commit is tried once. A stack that is not running (stopped, or never deployed) is not
deployed until it runs again, and one taken back to an older commit is brought forward to
the newest. The plugin's cron line is added while any stack's setting is not off.
TEXT,
        'recreate-on-folder-change' => <<<'TEXT'
Usage: compose-git recreate-on-folder-change <stack> on|off

Choose what a deploy does when the new commit changes anything in the stack's folder in the
repository:
  on    recreate every container of the stack, so a changed config file that a container
        mounts takes effect (the default)
  off   leave it to docker compose up, which recreates only the containers whose compose
        definition changed
Off also skips the recreate after local changes were discarded (--save-local-changes), and
after the branch was rewritten. A change to a shared folder elsewhere in the repository never
recreates anything. The setting
is saved in the stack's git.json as "recreateOnFolderChange". No container is changed now;
the setting applies from the next deploy.
TEXT,
        'resume' => <<<'TEXT'
Usage: compose-git resume <stack>

Clear a stack's failed deploy, so the scheduled check deploys it again. After a deploy fails
during up, automatic deploys of the stack are paused until a deploy works; after a scheduled
deploy fails before up (an image pull, say), that commit is not tried again. Use this once
you have fixed the cause another way. The deployed commit and the containers are not changed.
TEXT,
        'scheduled-check' => <<<'TEXT'
Usage: compose-git scheduled-check

Run the scheduled check now, for every git stack whose auto-deploy setting is not off. Cron
runs it every 15 minutes while any stack asks for it. Does nothing while the array is
stopped, or while another check is still running.
TEXT,
        default => null,
    };
}

/**
 * Split arguments into positional values and --options. Options that take a
 * value are named in $withValue; --profile may repeat.
 *
 * @param string[] $args
 * @param string[] $withValue
 * @param string[] $flags
 * @return array{0: string[], 1: array<string, string|true|string[]>}
 */
function compose_git_parse(array $args, array $withValue, array $flags): array
{
    $positional = [];
    $options = [];
    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        if (!str_starts_with($arg, '--')) {
            $positional[] = $arg;
            continue;
        }
        $name = substr($arg, 2);
        if (in_array($name, $flags, true)) {
            $options[$name] = true;
        } elseif (in_array($name, $withValue, true)) {
            if (!isset($args[$i + 1])) {
                throw new ComposeGitUsageError("--$name needs a value.");
            }
            $value = $args[++$i];
            if ($name === 'profile') {
                $options['profile'] = array_merge((array) ($options['profile'] ?? []), [$value]);
            } else {
                $options[$name] = $value;
            }
        } else {
            throw new ComposeGitUsageError("Unknown option --$name.");
        }
    }
    return [$positional, $options];
}

/**
 * @param array<string, string|true|string[]> $options
 */
function compose_git_required(array $options, string $name): string
{
    $value = $options[$name] ?? null;
    if (!is_string($value) || $value === '') {
        throw new ComposeGitUsageError("--$name is required.");
    }
    return $value;
}

/**
 * @param string[] $positional
 */
function compose_git_one_stack(array $positional): string
{
    if (count($positional) !== 1) {
        throw new ComposeGitUsageError('Name exactly one stack.');
    }
    return $positional[0];
}

/**
 * compose.sh's --wait arguments for a deploy.
 *
 * Waits for healthy containers as the web UI's up does: the stack's own
 * setting, else the plugin default (both from resolveStackWaitSettings()),
 * with --wait, --no-wait and --wait-timeout overriding them.
 *
 * @param array{enabled: bool, timeout: string, stackName?: string} $waitSettings
 * @param array<string, string|true|string[]> $options
 * @return string[]
 */
function compose_git_wait_arguments(array $waitSettings, array $options): array
{
    if (isset($options['wait']) && isset($options['no-wait'])) {
        throw new ComposeGitUsageError('Use --wait or --no-wait, not both.');
    }
    $wait = $waitSettings['enabled'];
    $timeout = $waitSettings['timeout'];
    if (isset($options['wait']) || isset($options['wait-timeout'])) {
        $wait = true;
    }
    if (isset($options['no-wait'])) {
        $wait = false;
    }
    if (isset($options['wait-timeout'])) {
        $timeout = (string) $options['wait-timeout'];
        if (preg_match('/^[1-9][0-9]{0,5}$/', $timeout) !== 1) {
            throw new ComposeGitUsageError('--wait-timeout must be a number of seconds.');
        }
    }
    // As the web UI's and the scheduled deploy wait: a timeout read from the settings
    // files is passed on only when it is a plain number.
    return gitDeployWaitArguments(['enabled' => $wait, 'timeout' => $timeout]);
}

/**
 * The environment compose.sh gets for a git deploy: what DockerCommand gives the deploy's
 * checks, plus the documented lock settings. Nothing else of the caller's shell is passed on.
 *
 * @param array<string, string> $current The caller's environment
 * @return array<string, string>
 */
function compose_git_deploy_environment(array $current): array
{
    $environment = [
        'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'HOME' => $current['HOME'] ?? '/root',
        'LC_ALL' => 'C',
    ];
    // TERM only changes how Compose draws its progress.
    foreach (['DOCKER_CONFIG', 'COMPOSE_LOCK_TIMEOUT', 'COMPOSE_LOCK_DIR', 'TERM'] as $name) {
        if (isset($current[$name]) && $current[$name] !== '') {
            $environment[$name] = $current[$name];
        }
    }
    return $environment;
}

/**
 * The folder compose.sh runs in for a git deploy: the compose file's folder, where the
 * deploy's checks run too.
 */
function compose_git_deploy_directory(?string $composeFilePath): string
{
    $composeFile = $composeFilePath === null ? false : realpath($composeFilePath);
    if ($composeFile === false) {
        // The compose file is checked again by the deploy, which stops before up if it is missing.
        return '/';
    }
    return dirname($composeFile);
}

/**
 * Deploy through compose.sh, so the deploy takes the same lock, credentials
 * and logging as every other stack action. Its output goes straight to ours.
 *
 * @param array<string, string|true|string[]> $options
 * @param resource $errors
 */
function compose_git_deploy(GitStackManager $manager, string $folder, array $options, $errors): int
{
    $stack = $manager->gitStack($folder);
    if (!$stack->hasResolvedIdentity()) {
        fwrite($errors, $stack->getIdentityBlockReason() . "\n");
        return COMPOSE_GIT_EXIT_FAILED;
    }

    $waitSettings = resolveStackWaitSettings($stack->path, parse_plugin_cfg('compose.manager'));
    $command = buildGitDeployCommand(
        $stack,
        isset($options['commit']) ? (string) $options['commit'] : null,
        isset($options['save-local-changes']),
        gitDeployProfiles($stack, array_map('strval', (array) ($options['profile'] ?? []))),
        compose_git_wait_arguments($waitSettings, $options)
    );

    // compose.sh runs with the same environment and folder as the deploy's checks, so a
    // variable exported in the caller's shell (or the folder it was run from, ${PWD})
    // cannot make up deploy something other than what was checked.
    $process = proc_open(
        $command,
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
        $pipes,
        compose_git_deploy_directory($stack->composeFilePath),
        compose_git_deploy_environment(getenv())
    );
    if (!is_resource($process)) {
        fwrite($errors, "Could not start compose.sh.\n");
        return COMPOSE_GIT_EXIT_FAILED;
    }
    $exitCode = proc_close($process);
    return $exitCode === 0 ? COMPOSE_GIT_EXIT_OK : COMPOSE_GIT_EXIT_FAILED;
}

/**
 * compose-git deploy --dry-run: try the deploy without deploying, and print what it found.
 *
 * @param array<string, string|true|string[]> $options
 * @return int 0 when every check passed, 1 when one did not
 */
function compose_git_dry_run(GitStackManager $manager, string $folder, array $options): int
{
    foreach (['save-local-changes', 'wait', 'no-wait', 'wait-timeout'] as $option) {
        if (isset($options[$option])) {
            throw new ComposeGitUsageError("--dry-run takes only --commit and --profile, not --$option: a dry run saves nothing and starts no container.");
        }
    }
    $stack = $manager->gitStack($folder);
    $result = $manager->dryRun(
        $folder,
        isset($options['commit']) ? (string) $options['commit'] : null,
        gitDeployProfiles($stack, array_map('strval', (array) ($options['profile'] ?? [])))
    );
    $previous = substr($result['previousCommit'], 0, 12);
    $target = substr($result['targetCommit'], 0, 12);

    if ($result['previousCommit'] === $result['targetCommit']) {
        echo "Dry run of $folder: $target, the commit checked out now.\n";
    } else {
        echo "Dry run of $folder: from $previous (checked out now) to $target.\n";
    }
    // After a failed up the clone holds the commit that failed, not the one that last worked.
    if ($result['deployedCommit'] === null) {
        echo "Nothing has been deployed yet.\n";
    } elseif ($result['deployedCommit'] !== $result['previousCommit']) {
        echo 'The last commit deployed successfully is ' . substr($result['deployedCommit'], 0, 12) . ".\n";
    }
    if ($result['problems'] === []) {
        echo "Every check passed. A dry run does not pull or build the images, so a missing image or tag is\n"
            . "found only by the deploy.\n";
        if ($result['recreatesEveryContainer']) {
            echo "Up would recreate every container of the stack (see 'compose-git recreate-on-folder-change --help').\n";
        }
    } else {
        echo "The deploy would stop:\n- " . implode("\n- ", $result['problems']) . "\n";
    }

    echo "\n";
    if ($result['configDiff'] === null) {
        echo "How the compose configuration changes is not known. {$result['configProblem']}\n";
    } elseif ($result['configDiff'] === '') {
        echo "The compose configuration does not change.\n";
    } else {
        echo "How the compose configuration changes (variables as written, not their values):\n" . $result['configDiff'];
    }
    echo "\nNothing was deployed, and the clone is back at $previous.\n";

    return $result['problems'] === [] ? COMPOSE_GIT_EXIT_OK : COMPOSE_GIT_EXIT_FAILED;
}

/**
 * A status row for a git stack whose settings or state could not be read,
 * with the same keys as GitStackManager::status() and the reason as the problem.
 *
 * @return array<string, mixed>
 */
function compose_git_unreadable_status(string $folder, string $problem): array
{
    return [
        'stack' => $folder,
        'url' => null,
        'branch' => null,
        'composePath' => null,
        'cloneDir' => null,
        'recreateOnFolderChange' => null,
        'deployedCommit' => null,
        'failedCommit' => null,
        'checkedOutCommit' => null,
        'localChanges' => [],
        'commitMadeByHand' => false,
        'failedAutomaticCommit' => null,
        'problem' => $problem,
    ];
}

/**
 * @param array<string, mixed> $status
 */
function compose_git_print_status(array $status): void
{
    $short = static fn(?string $commit): string => $commit === null ? '-' : substr($commit, 0, 12);
    echo $status['stack'] . "\n";
    if ($status['url'] === null) {
        // The stack's git settings could not be read (see compose_git_unreadable_status()).
        echo '  problem:      ' . $status['problem'] . "\n";
        return;
    }
    echo '  repository:   ' . $status['url'] . ' (' . $status['branch'] . ")\n";
    echo '  compose file: ' . $status['composePath'] . "\n";
    echo '  clone:        ' . $status['cloneDir'] . "\n";
    if ($status['credential'] !== null) {
        echo '  credential:   ' . $status['credential'] . "\n";
    }
    echo '  automatic:    ' . $status['autoDeploy'] . "\n";
    echo '  recreate:     ' . ($status['recreateOnFolderChange'] ? 'on' : 'off') . " (every container, when a deploy changes the stack's folder)\n";
    echo '  deployed:     ' . $short($status['deployedCommit']) . "\n";
    if ($status['failedCommit'] !== null) {
        echo '  failed:       ' . $short($status['failedCommit']) . " (fix it and deploy again)\n";
    }
    if ($status['failedAutomaticCommit'] !== null) {
        echo '  failed:       ' . $short($status['failedAutomaticCommit'])
            . " (the automatic deploy, before up; compose-git resume tries it again)\n";
    }
    echo '  checked out:  ' . $short($status['checkedOutCommit']) . "\n";
    if ($status['localChanges'] !== []) {
        echo '  local changes: ' . implode(', ', $status['localChanges']) . "\n";
    }
    if ($status['commitMadeByHand']) {
        echo "  made by hand: the checked-out commit was committed in the clone (deploy --save-local-changes saves it)\n";
    }
    if ($status['problem'] !== null) {
        echo '  problem:      ' . $status['problem'] . "\n";
    }
}

/**
 * @param string[] $argv
 * @param resource|null $errors Where progress and errors go (standard error by default)
 */
function compose_git_main(array $argv, string $composeRoot, $errors = null): int
{
    $errors ??= STDERR;
    $say = static function (string $message) use ($errors): void {
        fwrite($errors, $message . "\n");
    };
    $manager = new GitStackManager(rtrim($composeRoot, '/'), $say);
    $command = $argv[1] ?? '';
    $args = array_slice($argv, 2);

    // 'compose-git <command> --help' (or -h) prints that command's help, whatever else is given.
    $commandHelp = compose_git_command_help($command);
    if ($commandHelp !== null && (in_array('--help', $args, true) || in_array('-h', $args, true))) {
        echo $commandHelp . "\n";
        return COMPOSE_GIT_EXIT_OK;
    }

    try {
        switch ($command) {
            case 'add':
                [$positional, $options] = compose_git_parse($args, ['url', 'path', 'branch', 'clones-root', 'description', 'credential'], []);
                $folder = $manager->add(
                    compose_git_one_stack($positional),
                    compose_git_required($options, 'url'),
                    (string) ($options['branch'] ?? GitStackManager::DEFAULT_BRANCH),
                    compose_git_required($options, 'path'),
                    isset($options['clones-root']) ? (string) $options['clones-root'] : null,
                    (string) ($options['description'] ?? ''),
                    isset($options['credential']) ? $manager->findGitCredential((string) $options['credential']) : null
                );
                echo "Deploy it with: compose-git deploy $folder\n";
                return COMPOSE_GIT_EXIT_OK;

            case 'convert':
                [$positional, $options] = compose_git_parse($args, ['url', 'path', 'branch', 'clones-root', 'credential'], []);
                $folder = compose_git_one_stack($positional);
                $backup = $manager->convert(
                    $folder,
                    compose_git_required($options, 'url'),
                    (string) ($options['branch'] ?? GitStackManager::DEFAULT_BRANCH),
                    compose_git_required($options, 'path'),
                    isset($options['clones-root']) ? (string) $options['clones-root'] : null,
                    isset($options['credential']) ? $manager->findGitCredential((string) $options['credential']) : null
                );
                echo "The replaced files are in $backup.\nDeploy it with: compose-git deploy $folder\n";
                return COMPOSE_GIT_EXIT_OK;

            case 'credential':
                [$positional, $options] = compose_git_parse($args, [], ['none']);
                if (count($positional) === 2 && !isset($options['none'])) {
                    $manager->setCredential($positional[0], $manager->findCredentialFor($positional[0], $positional[1]));
                } elseif (count($positional) === 1 && isset($options['none'])) {
                    $manager->setCredential($positional[0], null);
                } else {
                    throw new ComposeGitUsageError('credential needs a stack and either a credential name or --none.');
                }
                return COMPOSE_GIT_EXIT_OK;

            case 'auto-deploy':
                [$positional] = compose_git_parse($args, [], []);
                if (count($positional) !== 2) {
                    throw new ComposeGitUsageError('auto-deploy needs a stack and one of: ' . implode(', ', GitStackSettings::AUTO_DEPLOY_CHOICES) . '.');
                }
                $manager->setAutoDeploy($positional[0], $positional[1]);
                // Installs the scheduled check's cron line while any stack asks for it, and removes it after.
                if (!composeCronManager()->rebuild()) {
                    fwrite($errors, "The setting was saved, but the plugin's cron file could not be updated.\n");
                    return COMPOSE_GIT_EXIT_FAILED;
                }
                return COMPOSE_GIT_EXIT_OK;

            case 'recreate-on-folder-change':
                [$positional] = compose_git_parse($args, [], []);
                if (count($positional) !== 2 || !in_array($positional[1], ['on', 'off'], true)) {
                    throw new ComposeGitUsageError('recreate-on-folder-change needs a stack and either on or off.');
                }
                $manager->setRecreateOnFolderChange($positional[0], $positional[1] === 'on');
                return COMPOSE_GIT_EXIT_OK;

            case 'resume':
                [$positional] = compose_git_parse($args, [], []);
                $manager->resume(compose_git_one_stack($positional));
                return COMPOSE_GIT_EXIT_OK;

            case 'deploy-key':
                [$positional] = compose_git_parse($args, [], []);
                echo $manager->deployKey(compose_git_one_stack($positional)) . "\n";
                return COMPOSE_GIT_EXIT_OK;

            case 'trust-host':
                [$positional] = compose_git_parse($args, [], []);
                $manager->trustHost(compose_git_one_stack($positional));
                return COMPOSE_GIT_EXIT_OK;

            case 'check':
                [$positional, $options] = compose_git_parse($args, [], ['all']);
                $folders = isset($options['all']) ? $manager->listGitStacks() : [compose_git_one_stack($positional)];
                $anyChanged = false;
                $anyFailed = false;
                $anyElsewhere = false;
                foreach ($folders as $folder) {
                    try {
                        $result = $manager->check($folder);
                        $deployed = $result['deployedCommit'] === null ? 'nothing deployed yet' : substr($result['deployedCommit'], 0, 12) . ' deployed';
                        $newer = substr($result['remoteCommit'], 0, 12) . " available on {$result['branch']}";
                        $stackFolder = $result['stackFolder'] === '' ? 'the repository' : $result['stackFolder'] . '/';
                        if ($result['upToDate']) {
                            echo "$folder: up to date ($deployed)\n";
                        } elseif ($result['deployedCommit'] === null) {
                            echo "$folder: $newer ($deployed)\n";
                            $anyChanged = true;
                        } elseif ($result['deployedCommitMissing']) {
                            echo "$folder: $newer ($deployed, but no longer in the clone, so the changes "
                                . "cannot be identified)\n";
                            $anyChanged = true;
                        } elseif ($result['changesStack'] === true) {
                            echo "$folder: $newer, changing $stackFolder ($deployed)\n";
                            $anyChanged = true;
                        } else {
                            echo "$folder: $newer, but it changes nothing in $stackFolder ($deployed); "
                                . "deploy it anyway if the stack uses files outside that folder\n";
                            $anyElsewhere = true;
                        }
                    } catch (RuntimeException | InvalidArgumentException $error) {
                        echo "$folder: could not check: " . $error->getMessage() . "\n";
                        $anyFailed = true;
                    }
                }
                if ($anyChanged) {
                    return COMPOSE_GIT_EXIT_BEHIND;
                }
                if ($anyFailed) {
                    return COMPOSE_GIT_EXIT_FAILED;
                }
                return $anyElsewhere ? COMPOSE_GIT_EXIT_NEWER_ELSEWHERE : COMPOSE_GIT_EXIT_OK;

            case 'deploy':
                [$positional, $options] = compose_git_parse($args, ['commit', 'wait-timeout', 'profile'], ['save-local-changes', 'wait', 'no-wait', 'dry-run']);
                if (isset($options['dry-run'])) {
                    return compose_git_dry_run($manager, compose_git_one_stack($positional), $options);
                }
                return compose_git_deploy($manager, compose_git_one_stack($positional), $options, $errors);

            case 'reclone':
                [$positional] = compose_git_parse($args, [], []);
                $movedTo = $manager->reclone(compose_git_one_stack($positional));
                if ($movedTo !== null) {
                    echo "The old clone is in $movedTo. Copy back anything you need from it, then delete it yourself.\n";
                }
                return COMPOSE_GIT_EXIT_OK;

            case 'status':
                [$positional, $options] = compose_git_parse($args, [], ['all', 'json']);
                $listingAll = $positional === [] || isset($options['all']);
                $folders = $listingAll ? $manager->listGitStacks() : [compose_git_one_stack($positional)];
                $statuses = [];
                foreach ($folders as $folder) {
                    try {
                        $statuses[] = $manager->status($folder);
                    } catch (RuntimeException | InvalidArgumentException $error) {
                        if (!$listingAll) {
                            throw $error;
                        }
                        // One unreadable stack (a hand-edited git.json, say) must not hide the others.
                        $statuses[] = compose_git_unreadable_status($folder, $error->getMessage());
                    }
                }
                if (isset($options['json'])) {
                    echo json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                } else {
                    foreach ($statuses as $status) {
                        compose_git_print_status($status);
                    }
                    if ($statuses === []) {
                        echo "There are no git stacks.\n";
                    }
                }
                return COMPOSE_GIT_EXIT_OK;

            case 'scheduled-check':
                [$positional] = compose_git_parse($args, [], []);
                if ($positional !== []) {
                    throw new ComposeGitUsageError('scheduled-check checks every git stack whose auto-deploy is not off; name no stack.');
                }
                (new GitScheduledCheck($composeRoot, null, null, $say))->run();
                return COMPOSE_GIT_EXIT_OK;

            case 'help':
                // 'compose-git help <command>' is the same as 'compose-git <command> --help'.
                if (isset($args[0])) {
                    $helpFor = compose_git_command_help($args[0]);
                    if ($helpFor === null) {
                        throw new ComposeGitUsageError("Unknown command '{$args[0]}'.");
                    }
                    echo $helpFor . "\n";
                    return COMPOSE_GIT_EXIT_OK;
                }
                echo COMPOSE_GIT_USAGE . "\n";
                return COMPOSE_GIT_EXIT_OK;

            case '--help':
            case '-h':
                echo COMPOSE_GIT_USAGE . "\n";
                return COMPOSE_GIT_EXIT_OK;

            case '':
                echo COMPOSE_GIT_USAGE . "\n";
                return COMPOSE_GIT_EXIT_USAGE;

            default:
                throw new ComposeGitUsageError("Unknown command '$command'.");
        }
    } catch (ComposeGitUsageError $error) {
        // A mistake in a known command shows that command's help; anything else, the overview.
        fwrite($errors, $error->getMessage() . "\n\n" . ($commandHelp ?? COMPOSE_GIT_USAGE) . "\n");
        return COMPOSE_GIT_EXIT_USAGE;
    } catch (Throwable $error) {
        fwrite($errors, $error->getMessage() . "\n");
        return COMPOSE_GIT_EXIT_FAILED;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    exit(compose_git_main($argv, $compose_root));
}
