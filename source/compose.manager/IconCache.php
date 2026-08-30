<?php
/**
 * Serve a plugin-cached icon PNG to the browser.
 * ?src=<url-encoded-source> — fetch/hit cache then output bytes.
 * Falls back to 302 redirect for http(s) sources not yet cached.
 */
require_once __DIR__ . '/include/Defines.php';
require_once __DIR__ . '/include/Util.php';

$src = isset($_GET['src']) ? trim((string) $_GET['src']) : '';
if ($src === '') {
    http_response_code(400);
    exit;
}

$cached = compose_fetch_icon_to_cache($src);

if ($cached !== '' && file_exists($cached)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($cached);
    exit;
}

// Cache miss (conversion unsupported or fetch failed) — redirect to original
// Only follow through for http(s); anything else is a 404
if (
    filter_var($src, FILTER_VALIDATE_URL) !== false
    && (strpos($src, 'https://') === 0 || strpos($src, 'http://') === 0)
) {
    header('Location: ' . $src, true, 302);
    exit;
}

http_response_code(404);
