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
    var frame = document.getElementById('ttyd-frame');
    var output = document.getElementById('terminal-output');
    var connection = document.getElementById('terminal-connection');
    var retry = document.getElementById('terminal-retry');
    var attempts = 0;
    var connecting = false;
    var viewerAttempted = false;
    var attached = false;
    var waitingUntil = 0;
    var outputTimer;
    var outputOffset = 0;
    var outputGeneration = 0;
    var rawOutput = '';
    var decoder = new TextDecoder();
    var controllers = new Set();
    var fallback = false;
    var outputEpoch = 0;
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
        dispose();
        window.top.Shadowbox.close();
    }

    async function request(action, extra) {
        var controller = new AbortController();
        controllers.add(controller);
        var timeout = setTimeout(function () { controller.abort(); }, action === 'connectTerminalViewer' ? 12000 : 10000);
        try {
            var token = window.top.csrf_token;
            if (typeof token !== 'string' || !token) throw new Error('The page CSRF token is unavailable. Reload the Compose page.');
            var response = await fetch('/plugins/compose.manager/include/Exec.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(Object.assign({ action: action, session: session, csrf_token: token }, extra)),
                signal: controller.signal
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            var result = await response.json();
            if (result.result !== 'success') throw new Error(result.message || 'Invalid terminal response.');
            return result;
        } finally {
            clearTimeout(timeout);
            controllers.delete(controller);
        }
    }

    function plainOutput(text) {
        text = text.replace(/\x1b\][^\x07]*?(?:\x07|\x1b\\)/g, '')
            .replace(/\x1b\[[0-?]*[ -/]*[@-~]/g, '')
            .replace(/\x1b(?:\[[0-?]*[ -/]*|\][^\x07]*)?$/g, '');
        var lines = [];
        var line = [];
        var cursor = 0;
        for (var ch of text) {
            if (ch === '\n') { lines.push(line.join('')); line = []; cursor = 0; }
            else if (ch === '\r') cursor = 0;
            else if (ch === '\b') cursor = Math.max(0, cursor - 1);
            else if (ch === '\t' || ch >= ' ' && ch !== '\x7f') {
                line[cursor] = ch;
                cursor++;
            }
        }
        return lines.concat(line.join('')).join('\n');
    }

    async function pollOutput() {
        if (disposed || !fallback) return;
        var epoch = outputEpoch;
        var more = false;
        var finished = false;
        try {
            var result = await request('getTerminalOutput', { offset: String(outputOffset), generation: String(outputGeneration) });
            if (disposed || !fallback || epoch !== outputEpoch) return;
            var chunk = result.output;
            if (!chunk || typeof chunk.data !== 'string' || !Number.isInteger(chunk.offset) ||
                !Number.isInteger(chunk.generation) || typeof chunk.reset !== 'boolean' || typeof chunk.remaining !== 'boolean') {
                throw new Error('Invalid command output response.');
            }
            if (chunk.reset) { rawOutput = ''; decoder = new TextDecoder(); }
            var bytes = Uint8Array.from(atob(chunk.data), function (ch) { return ch.charCodeAt(0); });
            rawOutput = (rawOutput + decoder.decode(bytes, { stream: true })).slice(-1048576);
            outputOffset = chunk.offset;
            outputGeneration = chunk.generation;
            var nearBottom = output.scrollHeight - output.scrollTop - output.clientHeight < 40;
            output.textContent = plainOutput(rawOutput);
            if (nearBottom) output.scrollTop = output.scrollHeight;
            more = chunk.remaining;
            render(result.session);
            finished = result.session.state === 'completed' && !more;
        } catch (error) {
            if (!disposed && fallback && epoch === outputEpoch) connection.textContent = 'Unable to read saved output: ' + error.message + ' Retrying...';
        }
        if (!disposed && fallback && epoch === outputEpoch && !finished) outputTimer = setTimeout(pollOutput, more ? 0 : 1000);
    }

    function showFallback(message) {
        frame.hidden = true;
        frame.removeAttribute('src');
        output.hidden = false;
        retry.hidden = false;
        connection.textContent = message + ' Showing saved output instead.';
        if (!fallback) {
            fallback = true;
            outputEpoch++;
            pollOutput();
        }
    }

    async function connectViewer() {
        if (disposed || connecting || !frame || attempts >= 3) return;
        connecting = true;
        attached = false;
        waitingUntil = 0;
        attempts++;
        retry.disabled = true;
        connection.textContent = 'Connecting to live terminal (attempt ' + attempts + ' of 3)...'
            + (fallback ? ' Saved output remains available.' : '');
        try {
            var replaceViewer = viewerAttempted;
            viewerAttempted = true;
            var result = await request('connectTerminalViewer', { retry: replaceViewer ? '1' : '0' });
            if (disposed) return;
            if (!result.viewer || result.viewer.ready !== true) throw new Error('The live viewer connection is not ready.');
            frame.src = window.composeTerminalUrl + '?attempt=' + attempts + '&time=' + Date.now();
            frame.hidden = false;
            output.hidden = true;
            fallback = false;
            outputEpoch++;
            clearTimeout(outputTimer);
            waitingUntil = Date.now() + 10000;
        } catch (error) {
            if (disposed) return;
            showFallback('Couldn\'t connect to the live terminal. ' + error.message);
        } finally {
            connecting = false;
            if (!disposed) retry.disabled = false;
        }
    }

    function render(receipt) {
        if (!receipt || !['queued', 'running', 'completed'].includes(receipt.state) ||
            receipt.state === 'completed' && !Number.isInteger(receipt.exitCode)) {
            throw new Error('Invalid terminal session receipt.');
        }
        if (completed && receipt.state !== 'completed') return;
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
            var result = await request('getTerminalSession');
            if (disposed) return;
            render(result.session);
            if (frame && !connecting) {
            if (waitingUntil && (result.session.viewerConnected === true ||
                completed && result.session.viewerViewed === true)) {
                attached = true;
                waitingUntil = 0;
                connection.textContent = 'Live terminal connected.';
                retry.hidden = true;
            } else if (waitingUntil && Date.now() >= waitingUntil ||
                attached && !completed && result.session.viewerConnected === false) {
                attached = false;
                waitingUntil = 0;
                showFallback('Couldn\'t connect to the live terminal.');
            }
            if (!attached && !waitingUntil && attempts < 3) connectViewer();
            }
        } catch (error) {
            if (!completed) {
                status.textContent = 'Unable to check command status: ' + error.message + ' Retrying...';
                stop.disabled = true;
            }
        }
        if (!disposed && (!completed || frame && (!attached && (connecting || waitingUntil || attempts < 3)))) timer = setTimeout(poll, 1000);
    }

    background.addEventListener('click', closeViewer);
    done.addEventListener('click', closeViewer);
    stop.addEventListener('click', async function () {
        if (completed || stopping || !window.confirm(stopWarning)) return;
        stopping = true;
        stop.disabled = true;
        status.textContent = 'Stopping command...';
        try {
            var result = await request('stopTerminalSession');
            if (!disposed) render(result.session);
        } catch (error) {
            if (!completed) {
                stopping = false;
                status.textContent = 'Unable to stop command: ' + error.message;
            }
        }
    });
    function dispose() {
        disposed = true;
        clearTimeout(timer);
        clearTimeout(outputTimer);
        controllers.forEach(function (controller) { controller.abort(); });
    }
    if (retry) retry.addEventListener('click', function () {
        if (connecting) return;
        attempts = 0;
        connectViewer();
        clearTimeout(timer);
        timer = setTimeout(poll, 1000);
    });
    window.addEventListener('unload', dispose);
    poll();
}());
