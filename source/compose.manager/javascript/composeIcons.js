/**
 * Shared icon helpers.
 * Loaded by both the Compose page and the Dashboard tile — the tile has no
 * access to composeManagerMain.js, so these must live in their own file.
 */

function composeIconFallback(img) {
    if (!img || img.dataset.composeFallbackApplied === 'true') {
        return;
    }
    img.dataset.composeFallbackApplied = 'true';
    img.onerror = null;
    img.src = '/plugins/compose.manager/images/question.png';
}

// Validate an icon source: http(s) URL, data URI, or local server path
function isValidIconSrc(src) {
    if (!src) return false;
    var s = src.trim();
    return s.indexOf('http://') === 0 || s.indexOf('https://') === 0 ||
        s.indexOf('data:image/') === 0 || s.indexOf('/') === 0;
}

function isCacheEligibleLocalIconPath(src) {
    if (!src) return false;
    var s = src.trim();
    return s.indexOf('/mnt/') === 0 || s.indexOf('/boot/config/plugins/compose.manager/') === 0;
}

/** Route cache-eligible icons through the local cache proxy; passthrough otherwise. */
function composeIconSrc(src, containerName) {
    if (!src || !isValidIconSrc(src)) {
        return '/plugins/compose.manager/images/question.png';
    }
    var s = src.trim();
    if (s.indexOf('/plugins/compose.manager/IconCache.php?') === 0) {
        return s;
    }

    var cacheable = s.indexOf('http://') === 0 || s.indexOf('https://') === 0 ||
        s.indexOf('data:image/') === 0 || isCacheEligibleLocalIconPath(s);
    if (cacheable) {
        var proxied = '/plugins/compose.manager/IconCache.php?src=' + encodeURIComponent(s);
        if (containerName && /^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(containerName)) {
            proxied += '&ct=' + encodeURIComponent(containerName);
        }
        return proxied;
    }

    return s;
}

if (typeof window !== 'undefined') {
    window.composeIconFallback = composeIconFallback;
    window.isValidIconSrc = isValidIconSrc;
    window.isCacheEligibleLocalIconPath = isCacheEligibleLocalIconPath;
    window.composeIconSrc = composeIconSrc;
}
