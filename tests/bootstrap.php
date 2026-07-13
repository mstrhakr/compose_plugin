<?php

/**
 * Compose Manager Plugin - Test Bootstrap
 *
 * Uses the plugin-tests framework to set up the test environment.
 * This is a minimal bootstrap that delegates to the framework.
 */

declare(strict_types=1);

// Stub the logger function before source files are loaded.
// On Linux this is a syslog command; on Windows it doesn't exist.
if (!function_exists('logger')) {
    function logger(string $message = ''): void
    {
        // no-op in test environment
    }
}

// Stub composeLogger before source files are loaded.
// It calls exec("logger ...") internally which doesn't exist on Windows.
if (!function_exists('composeLogger')) {
    function composeLogger($message, $data = null, $type = 'daemon', $level = 'info'): void
    {
        // no-op in test environment
    }
}

// Pre-define /boot and /var/lib/docker constants before Defines.php is loaded
// so they resolve to writable temp paths on CI where /boot does not exist.
$_bootConfigTemp = sys_get_temp_dir() . '/compose_manager_boot_config';
if (!is_dir($_bootConfigTemp)) {
    mkdir($_bootConfigTemp, 0755, true);
}
define('COMPOSE_UPDATE_STATUS_FILE', $_bootConfigTemp . '/update-status.json');
define('COMPOSE_STACK_ORDER_FILE',   $_bootConfigTemp . '/stack-order.json');
define('UNRAID_UPDATE_STATUS_FILE',  sys_get_temp_dir() . '/unraid-update-status.json');
define('PENDING_RECHECK_FILE',       $_bootConfigTemp . '/pending-recheck.json');
define('COMPOSE_TTYD_SOCKET_DIR',    sys_get_temp_dir());
define('COMPOSE_SKIP_TTYD_EXEC',     true);
unset($_bootConfigTemp);

// Load the plugin-tests framework
require_once __DIR__ . '/framework/src/php/bootstrap.php';

use PluginTests\PluginBootstrap;
use PluginTests\StreamWrapper\UnraidStreamWrapper;
use PluginTests\Mocks\FunctionMocks;

// Initialize the plugin test environment
// This auto-maps all .php files from source/compose.manager/include/ to Unraid paths
PluginBootstrap::init(
    'compose.manager',
    __DIR__ . '/../source/compose.manager/include',
    [
        'config' => [
            'PROJECTS_FOLDER' => sys_get_temp_dir() . '/compose_test_projects',
            'DEBUG_TO_LOG' => 'false',
        ],
        'subPath' => 'include',
    ]
);

// Map compose.manager writable support paths to a temp-backed plugin root.
// The framework auto-maps PHP source files under include/, but tests that
// create helper scripts or cron files under plugin_root need explicit file
// mappings in CI where /usr/local/emhttp/... does not exist on disk.
$pluginTempRoot = sys_get_temp_dir() . '/compose_manager_plugin_testfs';
$pluginTempScripts = $pluginTempRoot . '/scripts';
$pluginTempPhp = $pluginTempRoot . '/php';

foreach ([$pluginTempRoot, $pluginTempScripts, $pluginTempPhp] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

UnraidStreamWrapper::addMappings([
    '/usr/local/emhttp/plugins/compose.manager' => $pluginTempRoot,
    '/usr/local/emhttp/plugins/compose.manager/scripts' => $pluginTempScripts,
    '/usr/local/emhttp/plugins/compose.manager/php' => $pluginTempPhp,
    '/usr/local/emhttp/plugins/compose.manager/autoupdate.json' => $pluginTempRoot . '/autoupdate.json',
    '/usr/local/emhttp/plugins/compose.manager/compose_manager_autoupdate' => $pluginTempRoot . '/compose_manager_autoupdate',
    '/usr/local/emhttp/plugins/compose.manager/scripts/compose_autoupdate.sh' => $pluginTempScripts . '/compose_autoupdate.sh',
    '/usr/local/emhttp/plugins/compose.manager/php/sh_wrapper.php' => $pluginTempPhp . '/sh_wrapper.php',
]);

// Set up test compose projects directory
$testComposeRoot = sys_get_temp_dir() . '/compose_test_projects';
if (!is_dir($testComposeRoot)) {
    mkdir($testComposeRoot, 0755, true);
}

// Set compose_root global (used by plugin)
$GLOBALS['compose_root'] = $testComposeRoot;
