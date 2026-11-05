const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeDockerFilter.js`, 'utf8');

function harness(initialTable = true) {
    let hasTable = initialTable;
    let observer;
    const handlers = {};
    const timers = new Map();
    const requests = [];
    const errors = [];
    const rows = ['app', 'stopped', 'native', 'App'].map(name => ({ name, classes: new Set(['other-plugin']) }));
    const table = {};
    const context = {
        document: { body: {}, getElementById: () => hasTable ? table : null },
        console: { error: (...args) => errors.push(args) },
        MutationObserver: function(callback) {
            observer = callback;
            this.observe = () => {};
        },
        setTimeout: fn => { const key = {}; timers.set(key, fn); return key; },
        clearTimeout: key => timers.delete(key),
        $: selector => {
            if (typeof selector === 'function') { selector(); return; }
            if (selector === context.document) {
                return { on: (names, callback) => {
                    names.split(' ').forEach(name => { handlers[name] = callback; });
                } };
            }
            if (selector === '#docker_list tr.sortable') {
                return { each: callback => rows.forEach(row => callback.call(row)) };
            }
            if (rows.includes(selector)) {
                return {
                    find: () => ({ first: () => ({ text: () => selector.name }) }),
                    toggleClass: (name, enabled) => enabled ? selector.classes.add(name) : selector.classes.delete(name),
                };
            }
            return { closest: () => ({ length: selector.inTable ? 1 : 0 }) };
        },
    };
    context.$.post = (url, payload) => {
        assert.equal(payload.action, 'getComposeContainerNames');
        const request = {};
        requests.push(request);
        return {
            done(fn) { request.done = fn; return this; },
            fail(fn) { request.fail = fn; return this; },
            always(fn) { request.always = fn; return this; },
        };
    };
    vm.runInNewContext(source, context);
    const flush = () => {
        for (const [key, fn] of [...timers]) { timers.delete(key); fn(); }
    };
    const finish = response => {
        const request = requests.at(-1);
        request.done(response);
        request.always();
    };
    return { rows, requests, errors, handlers, flush, finish,
        observe: records => observer(records),
        setTable: value => { hasTable = value; } };
}

for (const initialTable of [true, false]) {
    const h = harness(initialTable);
    if (!initialTable) {
        assert.equal(h.requests.length, 0);
        h.setTable(true);
        h.observe([{ target: {}, addedNodes: [{ nodeType: 1, id: 'docker_list' }] }]);
        h.flush();
    }
    assert.equal(h.requests.length, 1, 'Does not require inline Compose DOM');
    h.finish(JSON.stringify({ result: 'success', names: ['app', 'stopped'] }));
    assert.deepEqual(h.rows.map(row => row.classes.has('compose-manager-hidden')), [true, true, false, false]);
    assert.ok(h.rows.every(row => row.classes.has('other-plugin')), 'Other visibility rules remain untouched');
    h.handlers['compose-stack-removed']();
    h.flush();
    h.finish({ result: 'success', names: [] });
    assert.ok(h.rows.every(row => !row.classes.has('compose-manager-hidden')), 'Removed stacks become visible again');
    for (let batch = 0; batch < 20; batch++) {
        h.observe([{ target: { inTable: true }, addedNodes: [] }]);
        h.flush();
    }
    assert.equal(h.requests.length, 2, 'Text/row replacement updates use cached membership');
    h.rows.push({ name: 'new-container', classes: new Set() });
    h.observe([{ target: { inTable: true }, addedNodes: [] }]);
    h.flush();
    h.flush();
    assert.equal(h.requests.length, 3, 'Changed container names invalidate membership');
    h.handlers['compose-list-loaded']();
    h.flush();
    assert.equal(h.requests.length, 3, 'Coalesces changes while a request is pending');
    h.finish({ result: 'success', names: ['app'] });
    h.flush();
    assert.equal(h.requests.length, 4, 'Refreshes again after changes during a request');
    h.finish({ result: 'error', message: 'Docker unavailable' });
    assert.equal(h.errors.length, 1);
    assert.ok(h.rows[0].classes.has('compose-manager-hidden'), 'A failure does not apply an empty snapshot');
    h.handlers['compose-list-loaded']();
    h.flush();
    h.finish('{');
    assert.equal(h.errors.length, 2, 'Malformed JSON is reported');
    h.handlers['compose-list-loaded']();
    h.flush();
    h.requests.at(-1).fail({}, 'error', 'network');
    h.requests.at(-1).always();
    assert.equal(h.errors.length, 3, 'Transport failures are reported');
}

for (const responseBeforeDebounce of [true, false]) {
    const h = harness();
    h.finish({ result: 'success', names: ['app'] });
    h.flush();
    assert.equal(h.requests.length, 1, 'Initial baseline must not create a redundant membership request');
    h.handlers['compose-list-loaded']();
    h.flush();
    const newRow = { name: 'new-managed', classes: new Set() };
    h.rows.push(newRow);
    h.observe([{ target: { inTable: true }, addedNodes: [] }]);
    if (!responseBeforeDebounce) h.flush();
    h.finish({ result: 'success', names: ['app'] });
    h.flush();
    assert.equal(h.requests.length, 3, 'Row changes during membership fetch require exactly one follow-up regardless of debounce ordering');
    h.finish({ result: 'success', names: ['app', 'new-managed'] });
    h.flush();
    assert.ok(newRow.classes.has('compose-manager-hidden'));
    assert.equal(h.requests.length, 3, 'Settled membership must not create a request loop');
}

const settings = fs.readFileSync(`${__dirname}/../../source/compose.manager/compose.manager.settings.page`, 'utf8');
const toggle = settings.match(/^        function toggleComposeOnTop\([^]*?\n        \}/m);
assert.ok(toggle);
{
    let header = false;
    const top = { checked: true, saved: undefined };
    const context = {
        $: selector => {
            assert.ok(['#SHOW_COMPOSE_IN_HEADER_MENU', '#SHOW_COMPOSE_ON_TOP',
                '#docker-page-integration-wrapper'].includes(selector),
            'Header layout toggle must not access the independent hide settings');
            return {
                is: () => selector === '#SHOW_COMPOSE_IN_HEADER_MENU' ? header : top.checked,
                data(key, value) { if (arguments.length === 2) top.saved = value; return top.saved; },
                prop(key, value) { top.checked = value; return this; },
                change() { return this; },
                css() {},
                removeData() { top.saved = undefined; },
            };
        },
    };
    vm.createContext(context);
    vm.runInContext(toggle[0], context);
    for (header of [false, true, false]) context.toggleComposeOnTop();
    assert.equal(top.checked, true);
}
assert.ok(!/style=/.test(settings.match(/<dl[^>]*id="docker-page-hide-wrapper"[^>]*>/)[0]));
const page = fs.readFileSync(`${__dirname}/../../source/compose.manager/compose.manager.docker-filter.page`, 'utf8');
assert.ok(!/^Title=/m.test(page), 'Filter must not introduce another Docker tab');
assert.ok(!/SHOW_COMPOSE_IN_HEADER_MENU/.test(page), 'Filter bootstrap must work in either layout');
assert.match(page, /HIDE_COMPOSE_FROM_DOCKER/);
assert.match(page, /autov\('\/plugins\/compose.manager\/javascript\/composeDockerFilter.js'\)/);
console.log('Docker filtering and independent header settings regressions passed.');
