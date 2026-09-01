<?php
/**
 * Serve a plugin-cached icon PNG to the browser.
 * ?src=<url-encoded-source> — always answered from the plugin icon cache.
 * ?ct=<container>           — also repair that container's Docker Manager icon.
 * ?refresh=1                — force a re-fetch before serving.
 */
require_once __DIR__ . '/include/Defines.php';
require_once __DIR__ . '/include/Util.php';

$src = isset($_GET['src']) ? trim((string) $_GET['src']) : '';
if ($src === '') {
    http_response_code(400);
    exit;
}

$containerName = isset($_GET['ct']) ? trim((string) $_GET['ct']) : '';
if ($containerName !== '' && preg_match('#^[a-zA-Z0-9][a-zA-Z0-9._-]*$#', $containerName) !== 1) {
    $containerName = '';
}

$forceRefresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';
$cachePath    = compose_get_icon_cache_path($src);

// Populate only when the cache is missing, stale, or holds non-PNG bytes; a
// failed refresh keeps serving the last known-good PNG.
if ($forceRefresh || compose_icon_cache_is_stale($src, $cachePath)) {
    if (compose_fetch_icon_to_cache($src, true) === '' && compose_file_is_png($cachePath)) {
        composeLogger('Icon refresh failed; serving cached copy', ['source' => $src], 'system', 'debug', 'icon-cache');
    }
}

if (!compose_file_is_png($cachePath)) {
    header('Location: /plugins/compose.manager/images/question.png', true, 302);
    exit;
}

if ($containerName !== '') {
    compose_seed_docker_manager_icon($cachePath, $containerName);
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string) filesize($cachePath));
readfile($cachePath);
