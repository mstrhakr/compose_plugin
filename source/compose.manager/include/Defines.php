<?php
require_once("/usr/local/emhttp/plugins/dynamix/include/Wrappers.php");

function locate_compose_root($name)
{
    $cfg = parse_plugin_cfg($name);
    return $cfg['PROJECTS_FOLDER'] ?? "/boot/config/plugins/compose.manager/projects";
}

$plugin_root = "/usr/local/emhttp/plugins/compose.manager/";
$socket_name = "compose_manager_action";
$sName = "compose.manager";
$docker_label_managed = "net.unraid.docker.managed";
$docker_label_icon = "net.unraid.docker.icon";
$docker_label_webui = "net.unraid.docker.webui";
$docker_label_shell = "net.unraid.docker.shell";
$docker_label_managed_name = "composeman";
$compose_root = locate_compose_root($sName);

// Centralised file-path constants — avoid scattering identical literals
// Each constant is guarded with defined() so the test bootstrap can pre-define them
// to writable temp paths (CI environments lack /boot and /var/lib/docker).
defined('COMPOSE_UPDATE_STATUS_FILE') || define('COMPOSE_UPDATE_STATUS_FILE', '/boot/config/plugins/compose.manager/update-status.json');
defined('COMPOSE_STACK_ORDER_FILE')   || define('COMPOSE_STACK_ORDER_FILE',   '/boot/config/plugins/compose.manager/stack-order.json');
defined('UNRAID_UPDATE_STATUS_FILE')  || define('UNRAID_UPDATE_STATUS_FILE',  '/var/lib/docker/unraid-update-status.json');
defined('PENDING_RECHECK_FILE')       || define('PENDING_RECHECK_FILE',       '/boot/config/plugins/compose.manager/pending-recheck.json');
defined('COMPOSE_TTYD_SOCKET_DIR')    || define('COMPOSE_TTYD_SOCKET_DIR',    '/var/tmp');
defined('COMPOSE_ICON_NORMALIZE_DIR') || define('COMPOSE_ICON_NORMALIZE_DIR', '/var/tmp/compose-icon-normalize');
defined('COMPOSE_ICON_CACHE_DIR')     || define('COMPOSE_ICON_CACHE_DIR',     '/boot/config/plugins/compose.manager/icon-cache');

/**
 * Reserved filename at the compose root level used by the plugin installer
 * to track migration state. Must be skipped when enumerating project folders.
 */
define('COMPOSE_ROOT_VERSION_FILE', 'version');

/**
 * Compose file names in priority order per the Docker Compose spec.
 * @see https://docs.docker.com/compose/intro/compose-application-model/#the-compose-file
 */
define('COMPOSE_FILE_NAMES', [
    'compose.yaml',
    'compose.yml',
    'docker-compose.yaml',
    'docker-compose.yml',
]);
