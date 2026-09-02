<?php
require_once("/usr/local/emhttp/plugins/compose.manager/include/Defines.php");

// Allow callers to override the socket name via query parameter
// (used by per-container console/logs). Sanitise to alphanumeric + _ and -.
$active_socket = $socket_name;
if (!empty($_GET['socket'])) {
    $active_socket = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['socket']);
}
$showDone = !empty($_GET['done']);
$stackPath = isset($_GET['path']) ? trim($_GET['path']) : '';

$url = "/logterminal/$active_socket/";

$version = parse_ini_file("/etc/unraid-version");
if (version_compare($version['version'], "6.10.0", "<")) {
    $url = "/dockerterminal/$active_socket/";
}

// Read active Dynamix theme — iframe pages don't inherit parent PHP vars.
$docroot ??= ($_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp');
require_once "$docroot/webGui/include/Wrappers.php";
if (!function_exists('autov')) {
    $helperCandidates = [
        "$docroot/webGui/include/Helpers.php",
        "$docroot/plugins/dynamix/include/Helpers.php",
    ];
    foreach ($helperCandidates as $helperFile) {
        if (is_file($helperFile)) {
            require_once $helperFile;
            break;
        }
    }
}

if (!function_exists('autov')) {
    // Fallback for environments where Helpers.php is unavailable.
    function autov($file, $ret = false)
    {
        $newFile = $file;
        if ($ret) {
            return $newFile;
        }
        echo $newFile;
    }
}
extract(parse_plugin_cfg('dynamix', true));
$themeFile = 'gray';
if (!empty($display['theme'])) {
    $themeName = strtok($display['theme'], '-');
    $themePath = "$docroot/webGui/styles/themes/{$themeName}.css";
    if (is_file($themePath)) {
        $themeFile = $themeName;
    }
}
$themeSheetJson = json_encode("themes/{$themeFile}.css");
?>
<!DOCTYPE html>
<html class="Theme--<?= htmlspecialchars($themeFile, ENT_QUOTES, 'UTF-8') ?>" style="height:100%;margin:0;padding:0">

<head>
    <link rel="stylesheet" href="<?php autov('/webGui/styles/default-base.css'); ?>">
    <link rel="stylesheet" href="<?php autov('/webGui/styles/default-dynamix.css'); ?>">
    <link rel="stylesheet" href="<?php autov('/webGui/styles/default-color-palette.css'); ?>">
    <link rel="stylesheet" href="<?php autov('/webGui/styles/themes/' . htmlspecialchars($themeFile, ENT_QUOTES, 'UTF-8') . '.css'); ?>">
    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            height: 100%;
            overflow: hidden;
            background: var(--background-color);
            box-sizing: border-box
        }

        body {
            display: flex;
            flex-direction: column
        }

        #ttyd-frame {
            flex: 1;
            border: none;
            width: 100%;
            display: block
        }

        p.centered {
            text-align: center;
            padding: 10px 0;
            margin: 0;
            background: transparent;
            flex-shrink: 0
        }
    </style>
</head>

<body>
    <iframe id="ttyd-frame" src="<?= $url ?>"></iframe>
    <?php if ($showDone): ?>
        <p class="centered ui-dialog-buttonpane">
            <span class="ui-dialog-buttonset">
                <button type="button" id="done-btn">Done</button>
            </span>
        </p>
    <?php endif; ?>
    <script>
        // Suppress "Leave Site?" prompt from ttyd's beforeunload handler.
        // Must run immediately (before ttyd registers its handler), no layout impact.
        function suppressBeforeUnload(win) {
            try {
                Object.defineProperty(win, 'onbeforeunload', {
                    get: function() {
                        return null;
                    },
                    set: function() {
                        /* swallow ttyd's assignment */ },
                    configurable: true
                });
                var origAdd = win.addEventListener.bind(win);
                win.addEventListener = function(type, fn, opts) {
                    if (type === 'beforeunload') return;
                    return origAdd(type, fn, opts);
                };
            } catch (e) {}
        }

        suppressBeforeUnload(window);

        function setupFrame(frame) {
            try {
                suppressBeforeUnload(frame.contentWindow);
                var fdoc = frame.contentDocument || frame.contentWindow.document;

                // Add Theme-- class so .Theme--<name>:root variable blocks apply
                fdoc.documentElement.classList.add('Theme--' + <?= json_encode($themeFile) ?>);

                // Inject theme CSS into ttyd iframe so vars are defined there too
                var sheets = ['default-base.css', 'default-dynamix.css', 'default-color-palette.css', <?= $themeSheetJson ?>];
                sheets.forEach(function(sheet) {
                    var link = fdoc.createElement('link');
                    link.rel = 'stylesheet';
                    link.href = '/webGui/styles/' + sheet;
                    fdoc.head.appendChild(link);
                });

                // Scrollbar styling for ttyd iframe
                var s = fdoc.createElement('style');
                s.textContent = '::-webkit-scrollbar{width:8px;height:8px}::-webkit-scrollbar-track{background:var(--dynamix-box-inner-div-border-color);border-radius:4px}::-webkit-scrollbar-thumb{background:var(--dynamix-box-text-color);border-radius:4px}::-webkit-scrollbar-thumb:hover{background:var(--dynamix-sb-title-text-color)}';
                fdoc.head.appendChild(s);

                // Recalculate terminal dimensions after CSS settles
                setTimeout(function() {
                    frame.contentWindow.dispatchEvent(new Event('resize'));
                }, 200);
            } catch (e) {}
        }

        // Defer all DOM/layout work until stylesheets have loaded to avoid
        // "Layout forced before page fully loaded" / flash of unstyled content.
        window.addEventListener('load', function() {
            <?php if ($showDone): ?>
                document.getElementById('done-btn').addEventListener('click', function() {
                    try {
                        var stackPath = <?= json_encode($stackPath) ?>;
                        if (stackPath) {
                            var xhr = new XMLHttpRequest();
                            xhr.open('POST', '/plugins/compose.manager/include/ComposeUtil.php', true);
                            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                            xhr.send('action=composeDetachFollow&path=' + encodeURIComponent(stackPath));
                        }
                    } catch (e) {}
                    try {
                        top.Shadowbox.close();
                    } catch (e) {}
                    try {
                        window.close();
                    } catch (e) {}
                });
            <?php endif; ?>

            // Hide Shadowbox info bar in parent — prevents it from pushing content
            // past the wrapper's border-radius and showing a mismatched strip.
            try {
                var sbInfo = top.document.getElementById('sb-info');
                if (sbInfo) sbInfo.style.display = 'none';
            } catch (e) {}

            // window.load waits for iframes, so the frame is already loaded —
            // apply theme directly.
            var frame = document.getElementById('ttyd-frame');
            setupFrame(frame);

            // Re-attach for any subsequent navigations within the iframe.
            frame.addEventListener('load', function() {
                setupFrame(frame);
            });
        });
    </script>
</body>

</html>