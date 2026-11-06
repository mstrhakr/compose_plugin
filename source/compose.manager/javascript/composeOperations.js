function composePollOperation(options) {
    var startedAt = Date.now();

    function check() {
        if (!options.isCurrent()) return;
        var completed = false;
        $.ajax({
            url: options.endpoint,
            method: 'POST',
            timeout: 30000,
            data: {
                action: options.operationId ? 'getUiOperation' : 'checkStackLock',
                script: options.project,
                operationId: options.operationId
            }
        }).done(function(response) {
            if (!options.isCurrent()) {
                completed = true;
                return;
            }
            var parsed;
            try {
                parsed = typeof response === 'string' ? JSON.parse(response) : response;
            } catch (error) {
                options.onError('Completion check failed', { error: String(error) });
                return;
            }
            var receipt = options.operationId && parsed && parsed.operation;
            var receiptComplete = receipt && receipt.state === 'completed' && Number.isInteger(receipt.exitCode);
            var lockComplete = !options.operationId && parsed && parsed.locked === false;
            if (parsed && parsed.result === 'success' && (receiptComplete || lockComplete)) {
                completed = true;
                options.onComplete(receiptComplete ? receipt.exitCode : 0);
            } else if (!parsed || parsed.result !== 'success' ||
                (options.operationId ? !receipt || !['queued', 'running'].includes(receipt.state) : typeof parsed.locked !== 'boolean')) {
                options.onError('Completion check failed', parsed);
            }
        }).fail(function(xhr, status, error) {
            if (options.isCurrent()) {
                options.onError('Completion check request failed', { status: status, error: error });
            }
        }).always(function() {
            if (completed || !options.isCurrent()) return;
            var elapsed = (Date.now() - startedAt) / 1000;
            if (elapsed < 1800) {
                setTimeout(check, elapsed < 60 ? 2000 : elapsed < 300 ? 5000 : 15000);
            } else {
                options.onTimeout();
            }
        });
    }

    setTimeout(check, 2000);
}
