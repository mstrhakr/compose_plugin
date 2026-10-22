<?php

/**
 * Git stack helper for compose.sh's gitdeploy command. Runs under the stack lock.
 *
 * Usage:
 *   git_stack.php prepare <stack-path> <project-name> [--commit <sha>] [--save-local-changes] [--profile <name>]...
 *                         [--automatic-from <deployed commit>|none]
 *       Fetch, check out and check the commit to deploy. Progress goes to stderr;
 *       on success the previously checked-out commit is printed on stdout. With
 *       --automatic-from (the scheduled check's deploy, and the deployed commit it
 *       decided from), first check that the automatic deploy still applies; exit
 *       status 75 (GitDeploy::EXIT_SKIPPED) when it does not.
 *   git_stack.php compose-args <stack-path>
 *       Print the stack's -f / --env-file arguments, each followed by a NUL byte.
 *   git_stack.php up-arguments <stack-path> <previous-commit>
 *       Print the extra arguments for "up" (--force-recreate or nothing), one per line.
 *   git_stack.php restore <stack-path> <commit>
 *       Check the clone out at <commit> again (after a failed pull).
 *   git_stack.php finish <stack-path> success|failed
 *       Record how the deploy ended.
 *
 * Exit status: 0 on success, 1 with a message on stderr otherwise.
 */

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitDeploy.php';

$say = static function (string $message): void {
    fwrite(STDERR, $message . "\n");
};

$args = array_slice($argv, 1);
$action = $args[0] ?? '';
$stackPath = $args[1] ?? '';
if ($stackPath === '' || !is_dir($stackPath)) {
    $say('git_stack.php: a stack folder is required.');
    exit(1);
}
$deploy = new GitDeploy($stackPath, $say);

try {
    switch ($action) {
        case 'prepare':
            $projectName = $args[2] ?? '';
            if ($projectName === '') {
                throw new RuntimeException('git_stack.php prepare: the project name is required.');
            }
            $commit = null;
            $saveLocalChanges = false;
            $profiles = [];
            $automatic = false;
            $deployedWhenDecided = null;
            for ($i = 3; $i < count($args); $i++) {
                if ($args[$i] === '--commit' && isset($args[$i + 1])) {
                    $commit = $args[++$i];
                } elseif ($args[$i] === '--save-local-changes') {
                    $saveLocalChanges = true;
                } elseif ($args[$i] === '--profile' && isset($args[$i + 1])) {
                    $profiles[] = $args[++$i];
                } elseif ($args[$i] === '--automatic-from' && isset($args[$i + 1])) {
                    $automatic = true;
                    $from = $args[++$i];
                    $deployedWhenDecided = $from === 'none' ? null : $from;
                } else {
                    throw new RuntimeException("git_stack.php prepare: unknown option {$args[$i]}");
                }
            }
            if ($automatic) {
                $deploy->assertAutomaticDeployStillApplies($projectName, $deployedWhenDecided);
            }
            echo $deploy->prepare($projectName, $profiles, $commit, $saveLocalChanges) . "\n";
            break;

        case 'compose-args':
            foreach ($deploy->composeArgs() as $arg) {
                echo $arg . "\0";
            }
            break;

        case 'compose-folder':
            echo $deploy->composeFolder() . "\n";
            break;

        case 'up-arguments':
            foreach ($deploy->upArguments($args[2] ?? '') as $arg) {
                echo $arg . "\n";
            }
            break;

        case 'restore':
            $deploy->restore($args[2] ?? '');
            break;

        case 'finish':
            $outcome = $args[2] ?? '';
            if ($outcome !== 'success' && $outcome !== 'failed') {
                throw new RuntimeException('git_stack.php finish: expected success or failed.');
            }
            $deploy->finish($outcome === 'success');
            break;

        default:
            throw new RuntimeException("git_stack.php: unknown action '$action'.");
    }
} catch (GitDeploySkipped $skipped) {
    $say($skipped->getMessage());
    exit(GitDeploy::EXIT_SKIPPED);
} catch (Throwable $error) {
    $say('✗ ' . $error->getMessage());
    exit(1);
}
exit(0);
