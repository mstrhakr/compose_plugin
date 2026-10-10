<?php

/**
 * Compose Util Functions for Compose Manager
 *
 * Contains utility functions used by ComposeUtil.php for compose command execution.
 * Separated from ComposeUtil.php to allow unit testing without triggering the switch statement.
 */

require_once("/usr/local/emhttp/plugins/compose.manager/include/Defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/Util.php");
require_once("/usr/local/emhttp/plugins/dynamix/include/Wrappers.php");

/**
 * Wait for ttyd UNIX socket to exist.
 *
 * @param string $socketName Base name of socket file (without path)
 * @param int $timeoutMs How long to wait in milliseconds (default 2000)
 * @param int $intervalMs Poll interval in milliseconds (default 100)
 * @return bool True if socket existed within timeout, false otherwise
 */
function waitForTtydSocket($socketName, $timeoutMs = 2000, $intervalMs = 100, $tmpDir = COMPOSE_TTYD_SOCKET_DIR)
{

    $socketPath = rtrim($tmpDir, '/') . "/$socketName.sock";
    $attempts = max(1, (int) ceil($timeoutMs / $intervalMs));
    for ($i = 0; $i < $attempts; $i++) {
        if (file_exists($socketPath)) {
            composeLogger("ttyd socket ready: $socketPath", ['socket' => $socketPath, 'attempt' => $i], 'user', 'debug', 'ttyd');
            return true;
        }
        usleep($intervalMs * 1000);
    }
    composeLogger("ttyd socket timeout: $socketPath", ['socket' => $socketPath, 'timeoutMs' => $timeoutMs], 'user', 'warning', 'ttyd');
    return false;
}

/**
 * Execute a compose command in a ttyd terminal, optionally capturing output to a log file.
 *
 * @param string $cmd The command to execute
 * @param bool $debug Whether to log debug messages
 * @param string $logFile Optional path to write a copy of the output
 * @param string $socketOverride Optional socket name to use instead of the global default
 */
function execComposeCommandInTTY($cmd, $debug, $logFile = '', $socketOverride = '')
{
    global $socket_name;
    $effectiveSocket = $socketOverride !== '' ? $socketOverride : $socket_name;
    $socketFile = rtrim(COMPOSE_TTYD_SOCKET_DIR, '/') . "/$effectiveSocket.sock";
    if (defined('COMPOSE_SKIP_TTYD_EXEC') && COMPOSE_SKIP_TTYD_EXEC) {
        composeLogger("Skipping ttyd execution in test mode: " . $cmd, ['command' => $cmd], 'user', 'debug', 'ttyd');
        return;
    }
    // Use pkill -f for more robust process matching instead of pgrep|awk pipeline
    exec("pkill -f " . escapeshellarg("$effectiveSocket.sock") . " 2>/dev/null");
    usleep(300000); // 300ms for process to exit
    @unlink($socketFile);
    $socketPath = escapeshellarg($socketFile);
    if ($logFile !== '') {
        // Preserve interactive TTY behavior by running the command under "script"
        // (PTY capture). A plain tee pipeline breaks terminal redraw/spinner output.
        $scriptCmd = "script -qefc " . escapeshellarg($cmd) . " " . escapeshellarg($logFile);
        $innerCmd = "bash -lc " . escapeshellarg($scriptCmd);
        $command = "ttyd -R -o -i $socketPath $innerCmd > /dev/null &";
    } else {
        $command = "ttyd -R -o -i $socketPath $cmd > /dev/null &";
    }
    exec($command);
    composeLogger("Executing command in ttyd: " . $cmd, ['command' => $cmd], 'user', 'debug', 'ttyd');

    // Wait for the socket to be created to avoid 502 on first open.
    waitForTtydSocket($effectiveSocket);
}

/**
 * Get the last command log file path for a given compose action.
 *
 * @param string $action Compose action (up, down, update, pull, stop, logs)
 * @param string $path Stack path
 * @return string Log file path or empty string when logs should not be saved.
 */
function getLastCmdLogFileForComposeAction($action, $path)
{
    if ($action === 'logs') {
        return '';
    }
    return rtrim($path, '/') . '/last_cmd.log';
}

/**
 * Suspend a live follow-logs compose session by process group so the stack keeps running
 * after the ttyd client is detached.
 *
 * @param string $path Stack path
 * @return bool True when a follow session was suspended, false otherwise
 */
function suspendComposeFollowProcess(string $path): bool
{
    $stackRoot = rtrim($path, '/');
    if ($stackRoot === '' || !is_dir($stackRoot)) {
        return false;
    }

    $realStackRoot = realpath($stackRoot);
    if ($realStackRoot === false) {
        return false;
    }

    global $compose_root;
    $realComposeRoot = realpath($compose_root ?? '');
    if ($realComposeRoot !== false && strncmp($realStackRoot, $realComposeRoot . '/', strlen($realComposeRoot) + 1) !== 0 && $realStackRoot !== $realComposeRoot) {
        return false;
    }

    $pidFile = $realStackRoot . '/.compose_follow.pid';
    if (!is_file($pidFile)) {
        return false;
    }

    $pid = trim((string) file_get_contents($pidFile));
    if ($pid === '' || !ctype_digit($pid)) {
        return false;
    }

    $pgid = trim((string) shell_exec('ps -o pgid= -p ' . escapeshellarg($pid) . ' 2>/dev/null | tr -d " "'));
    if ($pgid === '' || !ctype_digit($pgid)) {
        return false;
    }

    exec('kill -TSTP -- -' . escapeshellarg($pgid) . ' 2>/dev/null');
    composeLogger('Suspended live follow session for stack', ['path' => $realStackRoot, 'pid' => $pid, 'pgid' => $pgid], 'user', 'info', 'compose');
    return true;
}

/**
 * Append compose file discovery arguments to compose.sh command args.
 *
 * @param array<int, string> $composeCommand
 * @param array<string, mixed> $args
 */
function appendComposeFileArgs(array &$composeCommand, array $args): void
{
    if (!empty($args['useDefaultFileDiscovery'])) {
        $projectDirectory = $args['projectDirectory'] ?? '';
        if (is_string($projectDirectory) && $projectDirectory !== '') {
            $composeCommand[] = "-w" . $projectDirectory;
        }
        return;
    }

    $filePaths = $args['filePaths'] ?? [];
    if (!is_array($filePaths)) {
        return;
    }

    foreach ($filePaths as $filePath) {
        if (is_string($filePath) && $filePath !== '' && is_file($filePath)) {
            $composeCommand[] = "-f" . $filePath;
        }
    }
}

/**
 * Append env-file argument to compose.sh command args when valid.
 *
 * @param array<int, string> $composeCommand
 * @param array<string, mixed> $args
 */
function appendComposeEnvFileArg(array &$composeCommand, array $args): void
{
    $envFilePath = $args['envFilePath'] ?? null;
    if (is_string($envFilePath) && $envFilePath !== '' && is_file($envFilePath)) {
        $composeCommand[] = "-e" . $envFilePath;
    }
}

function resolveStackWaitSettings(string $stackPath, array $cfg): array
{
    $stackName = basename($stackPath);
    $stackBase = rtrim($stackPath, '/');
    $waitFile = $stackBase . '/wait_for_healthy';
    $timeoutFile = $stackBase . '/wait_timeout';

    $globalEnabled = (($cfg['WAIT_FOR_HEALTHY_DEFAULT'] ?? 'false') === 'true');
    $globalTimeout = (string) ($cfg['WAIT_FOR_HEALTHY_TIMEOUT_DEFAULT'] ?? '300');

    $stackEnabled = null;
    if (is_file($waitFile)) {
        $raw = trim((string) file_get_contents($waitFile));
        if ($raw !== '') {
            $stackEnabled = ($raw === 'true' || $raw === '1');
        }
    }

    $stackTimeout = null;
    if (is_file($timeoutFile)) {
        $raw = trim((string) file_get_contents($timeoutFile));
        if ($raw !== '') {
            $stackTimeout = $raw;
        }
    }

    $enabled = $stackEnabled !== null ? $stackEnabled : $globalEnabled;
    $timeout = $stackTimeout !== null && $stackTimeout !== '' ? $stackTimeout : $globalTimeout;

    return ['enabled' => $enabled, 'timeout' => $timeout, 'stackName' => $stackName];
}

/**
 * Resolve whether `update` should rebuild buildable services for a stack.
 *
 * Compose only builds automatically when an image is missing, so forcing
 * `--build` breaks stacks that publish an image alongside an unbuildable
 * `build:` section (see issue #149). Opt-in per stack, global default otherwise.
 *
 * @param array<string, mixed> $cfg
 */
function resolveStackBuildOnUpdate(string $stackPath, array $cfg): bool
{
    $buildFile = rtrim($stackPath, '/') . '/build_on_update';

    if (is_file($buildFile)) {
        $raw = trim((string) file_get_contents($buildFile));
        if ($raw !== '') {
            return ($raw === 'true' || $raw === '1');
        }
    }

    return (($cfg['BUILD_ON_UPDATE_DEFAULT'] ?? 'false') === 'true');
}

/**
 * Build and echo a compose command for a single stack.
 *
 * @param string $action The compose action (up, down, update, pull, stop, logs)
 * @param array<string, mixed> $options Command options: recreate, background, removeOrphans
 */
function echoComposeCommand($action, array $options = [])
{
    /**
     * Note: This function is called from an AJAX endpoint and must be careful to only echo the intended command or JSON response.
     * 
     * POST parameters:
     * 
     * path: the stack path (required)
     * profile: optional comma-separated list of profiles to enable
     * 
     * Security: The 'path' parameter is validated to ensure it is within allowed directories to prevent command injection or unauthorized file access.
     */
    global $plugin_root;
    global $sName;
    global $compose_root;
    $cfg = parse_plugin_cfg($sName);
    $debug = $cfg['DEBUG_TO_LOG'] == "true";
    $path = isset($_POST['path']) ? trim($_POST['path']) : "";
    $profile = isset($_POST['profile']) ? trim($_POST['profile']) : "";
    $recreate = !empty($options['recreate']);
    $background = !empty($options['background']);
    $removeOrphans = !empty($options['removeOrphans']);
    $followLogs = !empty($options['followLogs']);
    $waitForHealthy = false;
    $waitTimeout = (string) ($cfg['WAIT_FOR_HEALTHY_TIMEOUT_DEFAULT'] ?? '300');
    $buildOnUpdate = ($action === 'update') && resolveStackBuildOnUpdate($path, $cfg);
    if ($action === 'up') {
        $resolvedWait = resolveStackWaitSettings($path, $cfg);
        $waitForHealthy = !empty($resolvedWait['enabled']);
        $waitTimeout = (string) ($resolvedWait['timeout'] ?? $waitTimeout);
        $waitForHealthy = isset($_POST['waitForHealthy']) ? ((string) $_POST['waitForHealthy'] === '1' || strtolower((string) $_POST['waitForHealthy']) === 'true') : $waitForHealthy;
        if (isset($_POST['waitTimeout']) && trim((string) $_POST['waitTimeout']) !== '') {
            $waitTimeout = trim((string) $_POST['waitTimeout']);
        }
    }
    $unRaidVars = parse_ini_file("/var/local/emhttp/var.ini");
    if ($unRaidVars['mdState'] != "STARTED") {
        echo $plugin_root . "/scripts/arrayNotStarted.sh";
        composeLogger("Cannot perform action: array not started", ['action' => $action, 'path' => $path], 'user', 'debug', 'compose');
    } else {
        composeLogger("Preparing compose command", ['action' => $action, 'path' => $path, 'profile' => $profile, 'recreate' => $recreate, 'background' => $background, 'removeOrphans' => $removeOrphans], 'user', 'debug', 'compose');
        $composeCommand = array($plugin_root . "scripts/compose.sh");

        // Resolve stack identity via StackInfo
        try {
            $stackInfo = StackInfo::fromProject($compose_root, basename($path));
        } catch (\Throwable $e) {
            composeLogger("Cannot perform action: invalid stack", ['action' => $action, 'path' => $path, 'error' => $e->getMessage()], 'user', 'warning', 'compose');
            echo '';
            return;
        }

        // Fail closed for mutating actions only; logs remains read-only.
        if ($action !== 'logs' && !$stackInfo->hasResolvedIdentity()) {
            composeLogger(
                "Blocked '$action' for '{$stackInfo->projectFolder}': compose project identity is unresolved",
                ['action' => $action, 'path' => $path, 'identity' => $stackInfo->identity->toArray()],
                'user',
                'warning',
                'identity'
            );
            echo json_encode([
                'error' => 'identity',
                'project' => $stackInfo->projectFolder,
                'folderCandidate' => $stackInfo->identity->folderCandidate,
                'legacyCandidate' => $stackInfo->identity->legacyCandidate,
                'message' => $stackInfo->getIdentityBlockReason(),
            ]);
            return;
        }

        $args = $stackInfo->buildComposeArgs();

        $composeCommand[] = "-c" . $action;
        $composeCommand[] = "-p" . $args['projectName'];
        appendComposeFileArgs($composeCommand, $args);

        // Prune orphaned services from override before compose up
        if ($action === 'up') {
            $stackInfo->pruneOrphanOverrideServices();
        }

        if ($removeOrphans && ($action === 'up' || $action === 'down')) {
            $composeCommand[] = '--remove-orphans';
        }

        appendComposeEnvFileArg($composeCommand, $args);

        if (in_array($action, ['up', 'update', 'pull'], true)) {
            $credentialId = trim((string) ($stackInfo->getCredentialId() ?? ''));
            if ($credentialId !== '') {
                $composeCommand[] = '--credential-id';
                $composeCommand[] = $credentialId;
            }
        }

        // Support multiple profiles (comma-separated)
        if ($profile) {
            $profileList = array_map('trim', explode(',', $profile));
            foreach ($profileList as $p) {
                if ($p) {
                    $composeCommand[] = "-g" . $p;
                }
            }
        }

        // Pass stack path for timestamp saving
        $composeCommand[] = "-s$path";

        // Add recreate flag if requested
        if ($recreate) {
            $composeCommand[] = "--recreate";
        }

        if ($debug) {
            $composeCommand[] = "--debug";
        }

        if ($action === 'up' && $followLogs) {
            $composeCommand[] = "--follow-logs";
        }

        if ($buildOnUpdate) {
            $composeCommand[] = "--build";
        }

        if ($action === 'up' && $waitForHealthy) {
            if ($followLogs) {
                composeLogger("Blocked wait-for-healthy with follow logs enabled", ['action' => $action, 'path' => $path], 'user', 'warning', 'compose');
                echo json_encode(['error' => 'wait_conflict', 'message' => 'Follow stack logs and wait-for-healthy cannot be enabled at the same time.']);
                return;
            }
            $composeCommand[] = "--wait";
            $composeCommand[] = "--wait-timeout";
            $composeCommand[] = (string) $waitTimeout;
        }

        if ($background) {
            // Run fully in the background using compose_background.sh.
            // Output is captured to last_cmd.log; notification sent on completion.
            $bgScript = $plugin_root . "scripts/compose_background.sh";
            $bgCmd = escapeshellarg($bgScript);
            foreach ($composeCommand as $arg) {
                $bgCmd .= ' ' . escapeshellarg($arg);
            }
            $bgCmd .= ' > /dev/null 2>&1 &';
            exec($bgCmd);
            composeLogger("Background command: " . $bgCmd, ['command' => $bgCmd], 'user', 'debug', 'compose');
            // Signal to JS that this ran in background (no terminal window to open)
            echo json_encode(['background' => true]);
        } else {
            $logFile = getLastCmdLogFileForComposeAction($action, $path);
            $composeCommandEscaped = array_map(function ($item) {
                return escapeshellarg($item);
            }, $composeCommand);
            $composeCommandStr = join(" ", $composeCommandEscaped);
            // Use a per-stack socket for logs so viewing logs doesn't conflict
            // with action operations (up/update/pull) that share the default socket.
            $logsSocket = '';
            if ($action === 'logs') {
                $logsSocket = 'compose_logs_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', basename($path));
            }
            execComposeCommandInTTY($composeCommandStr, $debug, $logFile, $logsSocket);
            composeLogger("Executing command in ttyd: " . $composeCommandStr, ['command' => $composeCommandStr], 'user', 'debug', 'compose');
            if ($action === 'logs') {
                $composeCommand = "/plugins/compose.manager/include/ShowTtyd.php?socket=" . urlencode($logsSocket);
            } else {
                $composeCommand = "/plugins/compose.manager/include/ShowTtyd.php?done=1";
                if ($action === 'up' && $followLogs) {
                    $composeCommand .= '&path=' . urlencode($path);
                }
            }
            echo $composeCommand;
        }
        composeLogger("Final compose command: " . $composeCommand, ['command' => $composeCommand], 'user', 'debug', 'compose');
    }
}

/**
 * The compose profiles a git deploy runs with.
 *
 * The profiles asked for when there are any. Otherwise the profiles the stack
 * is running with, else its default profiles, as the web UI's Update does: a
 * deploy without profiles would leave the running profile services on the
 * old commit, and compose.sh would forget the stack's running profiles.
 *
 * @param string[] $requested Profiles named for this deploy, often none
 * @return string[]
 */
function gitDeployProfiles(StackInfo $stack, array $requested): array
{
    if ($requested !== []) {
        return array_values($requested);
    }
    $running = $stack->getRunningProfiles();
    if ($running !== []) {
        return array_values($running);
    }
    return array_values($stack->getDefaultProfiles());
}

/**
 * compose.sh's command line for deploying a git stack (compose.sh gitdeploy).
 * Used by both compose-git deploy and the web UI, so the two deploy alike.
 *
 * @param string|null $commit A full commit id to deploy, or null for the branch's latest
 * @param string[] $profiles Compose profiles to enable
 * @param string[] $waitArguments --wait and --wait-timeout, if the deploy waits for healthy containers
 * @return string[]
 */
function buildGitDeployCommand(StackInfo $stack, ?string $commit, bool $saveLocalChanges, array $profiles, array $waitArguments): array
{
    $command = [dirname(__DIR__) . '/scripts/compose.sh', '-cgitdeploy', '-p' . $stack->projectName, '-s' . $stack->path];
    $credentialId = trim((string) ($stack->getCredentialId() ?? ''));
    if ($credentialId !== '') {
        $command[] = '--credential-id';
        $command[] = $credentialId;
    }
    foreach ($profiles as $profile) {
        $command[] = '-g' . $profile;
    }
    if ($commit !== null) {
        $command[] = '--git-commit';
        $command[] = $commit;
    }
    if ($saveLocalChanges) {
        $command[] = '--save-local-changes';
    }
    foreach ($waitArguments as $argument) {
        $command[] = $argument;
    }
    return $command;
}

/**
 * The profiles a git deploy from the web UI runs with. When the person chose
 * them in the profile dialog (profileChosen=1), exactly those: an empty choice
 * means the default services only. Otherwise the ones the stack runs with, as
 * compose-git deploy does (gitDeployProfiles()).
 *
 * @param array<string, mixed> $post The request: profile, profileChosen
 * @return string[]
 */
function gitDeployProfilesFromRequest(StackInfo $stack, array $post): array
{
    if (($post['profileChosen'] ?? '') != '1') {
        return gitDeployProfiles($stack, []);
    }
    $names = array_map('trim', explode(',', (string) ($post['profile'] ?? '')));
    return array_values(array_filter($names, static fn(string $name): bool => $name !== ''));
}

/**
 * Deploy a git stack from the web UI: the stack menu's "Pull and Redeploy"
 * and "Deploy Commit...". Echoes what echoComposeCommand() does: a ttyd
 * viewer URL, {"background":true}, or a JSON error.
 *
 * POST parameters:
 *   path: the stack folder (required)
 *   commit: a full commit id to deploy (optional; the branch's latest otherwise)
 *   saveLocalChanges: '1' to save files changed in the clone as a patch, then discard them
 *   profile: optional comma-separated list of profiles to enable
 *   profileChosen: '1' when profile is the person's choice (see gitDeployProfilesFromRequest())
 *
 * @param array<string, mixed> $options background
 */
function echoGitDeployCommand(array $options = []): void
{
    global $plugin_root;
    global $sName;
    global $compose_root;
    $cfg = parse_plugin_cfg($sName);
    $debug = ($cfg['DEBUG_TO_LOG'] ?? '') == "true";
    $path = isset($_POST['path']) ? trim($_POST['path']) : '';
    $commit = isset($_POST['commit']) ? strtolower(trim((string) $_POST['commit'])) : '';
    $saveLocalChanges = isset($_POST['saveLocalChanges']) && $_POST['saveLocalChanges'] == '1';
    $background = !empty($options['background']);

    $unRaidVars = parse_ini_file("/var/local/emhttp/var.ini");
    if ($unRaidVars['mdState'] != "STARTED") {
        echo $plugin_root . "/scripts/arrayNotStarted.sh";
        return;
    }

    try {
        $stack = StackInfo::fromProject($compose_root, basename($path));
    } catch (\Throwable $e) {
        composeLogger("Cannot deploy: invalid stack", ['path' => $path, 'error' => $e->getMessage()], 'user', 'warning', 'compose');
        echo json_encode(['error' => 'git', 'message' => 'There is no such stack.']);
        return;
    }
    if (!$stack->isGitStack()) {
        echo json_encode(['error' => 'git', 'message' => "'{$stack->projectFolder}' is not a git stack."]);
        return;
    }
    if (!$stack->hasResolvedIdentity()) {
        echo json_encode([
            'error' => 'identity',
            'project' => $stack->projectFolder,
            'folderCandidate' => $stack->identity->folderCandidate,
            'legacyCandidate' => $stack->identity->legacyCandidate,
            'message' => $stack->getIdentityBlockReason(),
        ]);
        return;
    }
    // A full commit id only: the deploy refuses anything else too, but this
    // way the person sees why at once, not in a terminal window.
    require_once '/usr/local/emhttp/plugins/compose.manager/include/GitClone.php';
    if ($commit !== '' && !GitClone::isCommitId($commit)) {
        echo json_encode(['error' => 'git', 'message' => 'Enter the full commit id (40 characters), not a short one or a branch name.']);
        return;
    }

    $profiles = gitDeployProfilesFromRequest($stack, $_POST);

    // Waits for healthy containers as the stack's up does.
    $waitArguments = [];
    $waitSettings = resolveStackWaitSettings($stack->path, $cfg);
    if (!empty($waitSettings['enabled'])) {
        $waitArguments[] = '--wait';
        if (preg_match('/^[1-9][0-9]{0,5}$/', (string) $waitSettings['timeout']) === 1) {
            $waitArguments[] = '--wait-timeout';
            $waitArguments[] = (string) $waitSettings['timeout'];
        }
    }

    $composeCommand = buildGitDeployCommand($stack, $commit === '' ? null : $commit, $saveLocalChanges, $profiles, $waitArguments);
    if ($debug) {
        $composeCommand[] = '--debug';
    }

    if ($background) {
        // As echoComposeCommand(): output goes to last_cmd.log and a notification is sent at the end.
        $bgCmd = escapeshellarg($plugin_root . "scripts/compose_background.sh");
        foreach ($composeCommand as $arg) {
            $bgCmd .= ' ' . escapeshellarg($arg);
        }
        exec($bgCmd . ' > /dev/null 2>&1 &');
        composeLogger("Background command: " . $bgCmd, ['command' => $bgCmd], 'user', 'debug', 'compose');
        echo json_encode(['background' => true]);
        return;
    }

    $composeCommandString = implode(' ', array_map('escapeshellarg', $composeCommand));
    execComposeCommandInTTY($composeCommandString, $debug, getLastCmdLogFileForComposeAction('gitdeploy', $stack->path));
    echo "/plugins/compose.manager/include/ShowTtyd.php?done=1";
}

/**
 * Build and echo a compose command for multiple stacks.
 *
 * @param string $action The compose action (up, down, update)
 * @param array<string, mixed> $options Command options: paths, background, removeOrphans
 */
function echoComposeCommandMultiple($action, array $options = [])
{
    global $plugin_root;
    global $sName;
    global $compose_root;
    $cfg = parse_plugin_cfg($sName);
    $debug = $cfg['DEBUG_TO_LOG'] == "true";
    $unRaidVars = parse_ini_file("/var/local/emhttp/var.ini");
    $paths = $options['paths'] ?? [];
    $background = !empty($options['background']);
    $removeOrphans = !empty($options['removeOrphans']);

    if ($unRaidVars['mdState'] != "STARTED") {
        echo $plugin_root . "/scripts/arrayNotStarted.sh";
        composeLogger("Multi Compose operation aborted: Array not started", null, 'user', 'warning', 'compose-multi');
        return;
    }

    // Build a combined command that runs compose up/down for each stack sequentially
    $commands = array();
    $stackNames = array();
    $blockedStacks = array();

    foreach ($paths as $path) {
        composeLogger("Processing stack for multi-compose action: " . $path, ['path' => $path, 'action' => $action], 'user', 'debug', 'compose-multi');
        $composeCommand = array($plugin_root . "scripts/compose.sh");

        $project = basename($path);

        // Resolve stack identity via StackInfo
        try {
            $stackInfo = StackInfo::fromProject($compose_root, $project);
        } catch (\Throwable $e) {
            composeLogger("Skipping invalid stack during multi-compose action", ['action' => $action, 'path' => $path, 'error' => $e->getMessage()], 'user', 'warning', 'compose-multi');
            continue;
        }

        // Fail closed: never hand Docker Compose a project name we could not prove.
        if (!$stackInfo->hasResolvedIdentity()) {
            composeLogger(
                "Skipping '{$stackInfo->projectFolder}' during multi-compose action: compose project identity is unresolved",
                ['action' => $action, 'path' => $path, 'identity' => $stackInfo->identity->toArray()],
                'user',
                'warning',
                'identity'
            );
            $blockedStacks[] = $stackInfo->getName();
            continue;
        }

        $stackNames[] = $stackInfo->getName();
        $args = $stackInfo->buildComposeArgs();

        $composeCommand[] = "-c" . $action;
        $composeCommand[] = "-p" . $args['projectName'];
        appendComposeFileArgs($composeCommand, $args);

        // Prune orphaned services from override before compose up
        if ($action === 'up') {
            $stackInfo->pruneOrphanOverrideServices();
        }

        if ($removeOrphans && ($action === 'up' || $action === 'down')) {
            $composeCommand[] = '--remove-orphans';
        }

        appendComposeEnvFileArg($composeCommand, $args);

        if (in_array($action, ['up', 'update', 'pull'], true)) {
            $credentialId = trim((string) ($stackInfo->getCredentialId() ?? ''));
            if ($credentialId !== '') {
                $composeCommand[] = '--credential-id';
                $composeCommand[] = $credentialId;
            }
        }

        // Profile selection per action:
        //  - up:     use user-configured default profiles (running_profiles
        //            is stale/absent when the stack isn't running).
        //  - update: preserve the currently active profile set so the same
        //            services are recreated; fall back to defaults on first run.
        //  - down:   wildcard * ensures every profiled service is torn down,
        //            regardless of what was recorded or configured.
        if ($action === 'down') {
            $composeCommand[] = "-g*";
        } elseif ($action === 'update') {
            $profiles = $stackInfo->getRunningProfiles();
            if (empty($profiles)) {
                $profiles = $stackInfo->getDefaultProfiles();
            }
            foreach ($profiles as $p) {
                $composeCommand[] = "-g" . $p;
            }
        } else {
            // 'up' and any future actions
            foreach ($stackInfo->getDefaultProfiles() as $p) {
                $composeCommand[] = "-g" . $p;
            }
        }

        // Pass stack path for timestamp saving
        $composeCommand[] = "-s" . $path;

        if ($debug) {
            $composeCommand[] = "--debug";
        }

        $commands[] = $composeCommand;
    }

    if (empty($commands)) {
        composeLogger("Multi Compose operation aborted: no valid stacks resolved", ['action' => $action, 'blocked' => $blockedStacks], 'user', 'warning', 'compose-multi');
        if (!empty($blockedStacks)) {
            echo json_encode([
                'error' => 'identity',
                'stacks' => $blockedStacks,
                'message' => 'Compose project identity is unresolved for: ' . implode(', ', $blockedStacks),
            ]);
            return;
        }
        echo '';
        return;
    }

    // Human-readable action label for terminal headings
    $actionLabelByType = [
        'up' => 'Starting',
        'down' => 'Stopping',
        'update' => 'Updating',
    ];
    $actionLabel = $actionLabelByType[$action] ?? ucfirst($action);

    if ($background) {
        // Queue stacks sequentially in a single background wrapper script
        // so we don't slam the system with parallel compose operations.
        $bgScript = $plugin_root . "scripts/compose_background.sh";
        $tmpScript = "/tmp/compose_multi_bg_" . uniqid() . ".sh";
        $scriptContent = "#!/bin/bash\n";
        foreach ($commands as $cmd) {
            $line = escapeshellarg($bgScript);
            foreach ($cmd as $arg) {
                $line .= ' ' . escapeshellarg($arg);
            }
            $scriptContent .= "$line\n";
        }
        $scriptContent .= "rm -f " . escapeshellarg($tmpScript) . "\n";
        file_put_contents($tmpScript, $scriptContent);
        chmod($tmpScript, 0700);
        exec(escapeshellarg($tmpScript) . ' > /dev/null 2>&1 &');
        composeLogger("Background multi-stack queued: " . $tmpScript, ['script' => $tmpScript, 'stacks' => count($commands)], 'user', 'debug', 'compose-multi');
        echo json_encode(['background' => true]);
        return;
    }

    // Create a temporary script and execute it via ttyd.
    // This avoids nested shell-quote edge cases and continues after per-stack failures.
    $tmpScript = "/tmp/compose_multi_" . uniqid() . ".sh";
    $scriptContent = "#!/bin/bash\n";
    $scriptContent .= "# Multi-stack compose script (ttyd) - auto-generated\n\n";

    foreach ($blockedStacks as $blocked) {
        $blockedTitle = str_replace(['\\', '"'], ['\\\\', '\\"'], $blocked);
        $scriptContent .= "echo \"! Skipped " . $blockedTitle . ": compose project identity is unresolved\"\n";
    }

    foreach ($commands as $idx => $cmd) {
        $cmdStr = implode(" ", array_map('escapeshellarg', $cmd));
        $stackTitle = str_replace(['\\', '"'], ['\\\\', '\\"'], $stackNames[$idx]);

        $scriptContent .= "echo \"\"\n";
        $scriptContent .= "echo \"=== " . $actionLabel . ": " . $stackTitle . " ===\"\n";
        $scriptContent .= "echo \"\"\n";
        $scriptContent .= $cmdStr . "\n";
        $scriptContent .= "rc=$?\n";
        $scriptContent .= "if [ \$rc -ne 0 ]; then\n";
        $scriptContent .= "  echo \"X Stack " . $stackTitle . " failed to " . strtolower($actionLabel) . " (exit code: \$rc)\"\n";
        $scriptContent .= "fi\n";
        $scriptContent .= "echo \"\"\n";
    }

    $scriptContent .= "echo \"========================================\"\n";
    $scriptContent .= "echo \"=== All operations complete ===\"\n";
    $scriptContent .= "echo \"========================================\"\n";
    $scriptContent .= "rm -f " . escapeshellarg($tmpScript) . "\n";

    file_put_contents($tmpScript, $scriptContent);
    chmod($tmpScript, 0755);

    $ttydCommand = "bash " . escapeshellarg($tmpScript);
    execComposeCommandInTTY($ttydCommand, $debug);
    composeLogger("Multi-stack script created: " . $tmpScript, null, 'user', 'debug', 'compose-multi');
    echo "/plugins/compose.manager/include/ShowTtyd.php?done=1";
}
