// Initialize editor modal after DOM is fully loaded
$(function() {
    try {
        updateModalOffset();
        $(window).on('resize', updateModalOffset);
        initEditorModal();
    } catch (e) {
        composeLogger('Editor init error (non-fatal)', {
            error: e && e.toString()
        }, 'user', 'warn', 'editorInit');
    }
    // Attach Unraid folder/file browser to all path inputs on the page.
    // The picker popup needs manual positioning for modals and overlay stacks.
    if ($.fn.fileTreeAttach) {
        var $pathInputs = $('input[data-pickroot]');
        composeBindFileTreeInputs($pathInputs, {
            zIndex: 100010,
            minWidth: 320,
            addClass: true
        });
    }
});

// Reorder Compose section above Docker Containers if configured
// Runs in its own $(function) to avoid being blocked by editor init errors
$(function() {
    (function reorderComposeAboveDocker() {
        if (!showComposeOnTop) return;
        // In tabbed mode the sections live in separate tabs; reordering is not applicable
        if ($('.tabs').length) return;

        var $content = $('div.content');
        if (!$content.length) return;

        // Locate the two title divs by their text content
        // Use find() with a filter instead of children() to handle any nesting edge-cases
        var $dockerTitle = null;
        var $composeTitle = null;
        $content.children('div.title').each(function() {
            var txt = $(this).text().trim();
            if (!$dockerTitle && /Docker\s*Containers/i.test(txt)) $dockerTitle = $(this);
            if (!$composeTitle && /Compose/i.test(txt) && !/Docker/i.test(txt)) $composeTitle = $(this);
        });

        if (!$dockerTitle || !$composeTitle) {
            composeLogger('Reorder Compose above Docker skipped', {
                dockerTitle: !!$dockerTitle,
                composeTitle: !!$composeTitle
            }, 'user', 'warn', 'reorderComposeAboveDocker');
            return;
        }

        // Collect all nodes from the Compose title to the end of .content
        var composeNodes = [];
        var found = false;
        $content.contents().each(function() {
            if (this === $composeTitle[0]) found = true;
            if (found) composeNodes.push(this);
        });

        // Move them before the Docker title
        composeNodes.forEach(function(node) {
            $content[0].insertBefore(node, $dockerTitle[0]);
        });
    })();
});
