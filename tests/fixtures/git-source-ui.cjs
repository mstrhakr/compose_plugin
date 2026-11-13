const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeManagerMain.js`, 'utf8');

for (const name of ['saveGitAutoDeploy', 'saveGitRecreateOnFolderChange']) {
    for (const switched of [false, true]) {
        for (const outcome of ['success', 'error', 'failure']) {
            const events = [];
            let complete, fail;
            const context = {
                editorModal: { currentProject: 'original', isGitStack: true },
                caURL: 'exec',
                tryParseJson: JSON.parse,
                swal: options => events.push(['error', options.title]),
                $: selector => ({
                    text(value) { events.push(['text', selector, value]); return this; },
                    show() { events.push(['show', selector]); return this; },
                    hide() { events.push(['hide', selector]); return this; },
                    toggle(value) { events.push(['toggle', selector, value]); return this; },
                }),
            };
            context.$.post = (url, payload) => {
                events.push(['request', payload.action, payload.script]);
                return {
                    then(callback) {
                        complete = callback;
                        return { fail(callback) { fail = callback; } };
                    },
                };
            };
            vm.createContext(context);
            for (const functionName of [name, 'showGitSourceForStack']) {
                const match = source.match(new RegExp(`^function ${functionName}\\([^]*?\\n\\}`, 'm'));
                assert.ok(match, `Missing function ${functionName}`);
                vm.runInContext(match[0], context);
            }
            context[name](name === 'saveGitAutoDeploy' ? 'notify' : false);
            assert.deepEqual(events.find(event => event[0] === 'request'),
                ['request', name === 'saveGitAutoDeploy' ? 'setGitAutoDeploy' : 'setGitRecreateOnFolderChange', 'original']);
            if (switched) {
                context.editorModal.currentProject = 'non-git-stack';
                context.editorModal.isGitStack = false;
            }
            events.length = 0;
            if (outcome === 'failure') {
                fail();
            } else {
                complete(JSON.stringify({ result: outcome, message: 'Save refused' }));
            }
            const label = `${name}: ${outcome}, switched=${switched}`;
            if (switched) {
                assert.deepEqual(events, [], label);
                assert.equal(context.editorModal.isGitStack, false, label);
            } else if (outcome === 'success') {
                assert.ok(events.some(event => event[0] === 'text' && event[2] === 'Saved'), label);
                assert.ok(!events.some(event => event[0] === 'request'), label);
            } else {
                assert.ok(events.some(event => event[0] === 'error'), label);
                assert.ok(events.some(event => event[0] === 'request' && event[1] === 'getGitStackStatus'
                    && event[2] === 'original'), label);
            }
        }
    }
}

console.log('Git source save callback regressions passed (12 cases).');
