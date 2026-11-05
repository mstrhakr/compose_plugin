(function () {
    'use strict';
    var session = window.composeTerminalSession;
    if (!session) return;
    var background = document.getElementById('terminal-background');
    var stop = document.getElementById('terminal-stop');
    var done = document.getElementById('done-btn');
    var status = document.getElementById('terminal-status');
    var completed = false;
    var stopping = false;
    var disposed = false;
    var timer;
    var stopEffects = {
        up: 'This Up runs detached (-d). Cancelling interrupts preparation or startup; it does not run Stop or Down. Containers may already have been created or started and may remain running.',
        down: 'This interrupts Down. Containers and networks already removed will not be restored; remaining resources may still exist.',
        stop: 'This interrupts stopping the stack. Containers already stopped stay stopped; others may still be running.',
        pull: 'This interrupts image downloads. Images already downloaded remain; this action does not recreate or stop containers.',
        update: 'This interrupts the update. Images may already have been pulled or built, and containers may already have been stopped or recreated. The stack may be partially updated.',
        gitdeploy: 'This interrupts the Git deployment. Repository files may already have changed, and containers may already have been stopped or recreated. The stack may be partially deployed.'
    };
    var stopWarning = 'Stop this command?\n\n' +
        (stopEffects[window.composeTerminalCommand] ||
            'Cancelling interrupts the current operation. Depending on the command, containers may be stopped or left partially changed.') +
        (window.composeTerminalBulk ? '\n\nThis applies to the whole batch: the current action is interrupted and remaining queued actions are cancelled. Earlier actions are not undone.' : '') +
        '\n\nThe exact execution phase is not tracked. Changes already made will not be undone. Check the stack state afterward.';

    function closeViewer() {
        window.top.Shadowbox.close();
    }

    async function request(action) {
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 10000);
        try {
            var response = await fetch('/plugins/compose.manager/include/Exec.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: action, session: session }),
                signal: controller.signal
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            var result = await response.json();
            if (result.result !== 'success') throw new Error(result.message || 'Invalid terminal response.');
            return result.session;
        } finally {
            clearTimeout(timeout);
        }
    }

    function render(receipt) {
        if (completed) return;
        if (!receipt || !['queued', 'running', 'completed'].includes(receipt.state) ||
            receipt.state === 'completed' && !Number.isInteger(receipt.exitCode)) {
            throw new Error('Invalid terminal session receipt.');
        }
        completed = receipt.state === 'completed';
        background.hidden = completed;
        stop.hidden = completed;
        done.hidden = !completed;
        stop.disabled = stopping || receipt.state !== 'running';
        status.textContent = completed
            ? (receipt.exitCode === 0 ? 'Command completed.' : 'Command ended with exit code ' + receipt.exitCode + '. Review the output.')
            : (stopping ? 'Stopping command...' : 'Closing this viewer will leave the command running.');
    }

    async function poll() {
        try {
            render(await request('getTerminalSession'));
        } catch (error) {
            if (!completed) {
                status.textContent = 'Unable to check command status: ' + error.message + ' Retrying...';
                stop.disabled = true;
            }
        }
        if (!completed && !disposed) timer = setTimeout(poll, 1000);
    }

    background.addEventListener('click', closeViewer);
    done.addEventListener('click', closeViewer);
    stop.addEventListener('click', async function () {
        if (completed || stopping || !window.confirm(stopWarning)) return;
        stopping = true;
        stop.disabled = true;
        status.textContent = 'Stopping command...';
        try {
            render(await request('stopTerminalSession'));
        } catch (error) {
            if (!completed) {
                stopping = false;
                status.textContent = 'Unable to stop command: ' + error.message;
            }
        }
    });
    window.addEventListener('unload', function () {
        disposed = true;
        clearTimeout(timer);
    });
    poll();
}());
