const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const ts = require('typescript');
const { expect } = require('@playwright/test');
const source = fs.readFileSync(`${__dirname}/../tests/helpers/composeE2eHelpers.ts`, 'utf8');
const output = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS },
}).outputText;
const context = {
    exports: {}, require(name) {
        return name === '@playwright/test' ? { expect } : require(name);
    },
};
vm.createContext(context);
vm.runInContext(output, context);

function harness(states) {
    const calls = [];
    const page = {
        evaluate: async () => 'synthetic-csrf',
        request: {
            async post(endpoint, { form }) {
                calls.push(form);
                assert.equal(endpoint, '/plugins/compose.manager/include/Exec.php');
                assert.equal(form.script, 'pw-host-owned');
                assert.equal(form.action, 'getUiOperation');
                assert.equal(form.operationId, 'receipt-owned');
                const operation = states.shift();
                return { ok: () => true, text: async () => JSON.stringify({ result: 'success', operation }) };
            },
        },
    };
    const dispatch = {
        response: { ok: () => true }, body: 'synthetic dispatch',
        json: { operations: { 'pw-host-owned': 'receipt-owned' } },
    };
    return { page, calls, dispatch };
}

(async () => {
    const { waitForOperation, waitForStackContainersRemoved } = context.exports;
    const queued = harness([
        { state: 'queued' }, { state: 'running' }, { state: 'completed', exitCode: 0 },
    ]);
    await waitForOperation(queued.page, 'pw-host-owned', queued.dispatch);
    assert.equal(queued.calls.length, 3, 'Queued does not count as completion');
    const failed = harness([{ state: 'completed', exitCode: 7 }]);
    await assert.rejects(() => waitForOperation(failed.page, 'pw-host-owned', failed.dispatch), /Tracked command failed/);
    const expectedFailure = harness([{ state: 'completed', exitCode: 7 }]);
    await waitForOperation(expectedFailure.page, 'pw-host-owned', expectedFailure.dispatch, 'nonzero');
    const missing = harness([]);
    missing.dispatch.json.operations = {};
    await assert.rejects(() => waitForOperation(missing.page, 'pw-host-owned', missing.dispatch), /Missing receipt/);
    assert.equal(missing.calls.length, 0, 'Missing dispatch evidence never falls back to lock polling');
    let snapshots = 0;
    const removalPage = {
        evaluate: async () => 'synthetic-csrf',
        request: { async post() {
            snapshots++;
            return { ok: () => true, text: async () => JSON.stringify({
                result: 'success', ...(snapshots > 1 ? { containers: [] } : {}),
            }) };
        } },
    };
    await assert.rejects(() => waitForStackContainersRemoved(removalPage, 'pw-host-owned'), /containers array/);
    await waitForStackContainersRemoved(removalPage, 'pw-host-owned');
    assert.equal(snapshots, 2, 'Malformed container snapshots do not establish removal');
    console.log('Operation helpers: queued/running, exact identity, exit codes and missing-receipt guards passed.');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
