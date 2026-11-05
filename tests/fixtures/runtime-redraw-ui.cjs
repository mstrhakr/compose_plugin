const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeManagerMain.js`, 'utf8');
const context = {
    composeStackActionInProgress: {}, stackContainersCache: {}, stackUpdateStatus: {},
    context: {
        settings() {}, destroy() { destroyed++; },
        attach(selector, options) { menus.push(options); },
    },
    fixContextDropdownOverflow() {},
    refreshComposeContextMenu(id, options) { destroyed++; menus.push(options); },
    swal() { warnings++; },
};
let destroyed = 0, warnings = 0, mutations = 0;
const menus = [];
function element(data = {}, attributes = {}) {
    return {
        length: 1,
        data(key, value) {
            if (arguments.length === 2) { data[key] = value; return this; }
            return data[key];
        },
        attr(key, value) {
            if (arguments.length === 2) { attributes[key] = value; return this; }
            return attributes[key];
        },
    };
}
const icon = element({ stackid: '1', project: 'app', projectname: 'App', isup: '1' });
const row = element({ path: '/app', project: 'app' }, { 'data-containers': '["app-1"]' });
context.$ = selector => selector === '#stack-row-1' ? row : icon;
context.ComposeStop = () => mutations++;
vm.createContext(context);
for (const name of ['addComposeStackContext', 'addComposeContainerContext', 'containerAction']) {
    vm.runInContext(source.match(new RegExp(`^function ${name}\\([^]*?\\n\\}`, 'm'))[0], context);
}
context.addComposeStackContext('stack-1');
context.addComposeStackContext('stack-1');
assert.equal(destroyed, 1, 'Unchanged runtime does not destroy an open menu');
let menu = menus.at(-1);
const stop = menu.find(option => option.text === 'Compose Stop');
assert.equal(stop.disabled, false, 'Health starting alone does not block commands');
context.composeStackActionInProgress.app = {};
stop.action({ preventDefault() {} });
assert.equal(mutations, 0, 'An already-open menu cannot dispatch during a newer operation');
assert.equal(warnings, 1);
context.addComposeStackContext('stack-1');
menu = menus.at(-1);
assert.equal(menu.find(option => option.text === 'Compose Stop').disabled, true);
assert.equal(menu.find(option => option.text === 'Check for Updates').disabled, true);
assert.ok(!menu.find(option => option.text === 'View Logs').disabled);
assert.ok(!menu.find(option => option.text === 'View Last Cmd Log').disabled);
context.addComposeStackContext('stack-1');
assert.equal(destroyed, 2, 'Busy polls do not repeatedly rebind the menu');
delete context.composeStackActionInProgress.app;
context.addComposeStackContext('stack-1');
assert.equal(menus.at(-1).find(option => option.text === 'Compose Stop').disabled, false);
icon.off = () => icon;
icon.data('state', 'running');
context.composeStackActionInProgress.app = {};
context.addComposeContainerContext('container-1');
assert.equal(menus.at(-1).find(option => option.text === 'Stop').disabled, true);
assert.ok(!menus.at(-1).find(option => option.text === 'Logs').disabled);
delete context.composeStackActionInProgress.app;
context.addComposeContainerContext('container-1');
assert.equal(menus.at(-1).find(option => option.text === 'Stop').disabled, false);
icon.data('isup', '0');
row.data('profiles', ['web']);
context.addComposeStackContext('stack-1');
let selectedAction;
context.showProfileSelector = action => { selectedAction = action; };
menus.at(-1).find(option => option.text === 'Pull/Build & Up').action({ preventDefault() {} });
assert.equal(selectedAction, 'prepareUp', 'Stopped deployment selects profiles instead of invoking Update');

let writes = 0, health = 'starting', label = 'starting', iconClass = '';
function cell() {
    let html = '';
    return {
        length: 1, find() { return { length: 0 }; },
        html(value) {
            if (arguments.length) { html = value; writes++; return this; }
            return html;
        },
        data() {}, removeData() {},
        text(value) { if (arguments.length) label = value; return label; },
        attr(key, value) { if (arguments.length === 2) { iconClass = value; return this; } return iconClass; },
    };
}
const healthCell = cell(), statusIcon = cell(), state = cell(), other = cell();
const iconSpan = element();
const parent = {
    length: 1,
    find(selector) {
        return selector === 'td.col-health' ? healthCell : selector === '.state' ? state :
            selector === '.compose-status-icon' ? statusIcon :
            selector === 'span[data-stackid]' ? iconSpan : other;
    },
    data() { return this; }, attr() { return this; },
};
Object.assign(context, {
    $: selector => selector.startsWith('#compose_stacks ') ? parent : { length: 0 },
    stackStartedAtCache: {}, composeLogger() {}, reconcileStackRuntimeMetadata() {},
    buildStackInfoFromCache: () => ({ containers: [] }),
    deriveStackState: () => ({ label: 'starting', state: 'starting', runningCount: 1,
        totalCount: 1, shape: 'refresh fa-spin', colorClass: 'orange-text' }),
    updateStackUpdateUI() {},
    composeAggregateStackHealth: () => health,
    composeRenderHealthBadge: value => `<span>${value}</span>`,
    composeLoadlist() { throw new Error('Unexpected fallback'); },
});
vm.runInContext(source.match(/^function updateParentStackFromContainers\([^]*?\n\}/m)[0], context);
context.updateParentStackFromContainers('1', 'app');
const before = healthCell.html();
const originalHtml = healthCell.html;
let healthWrites = 0;
healthCell.html = function(...args) { if (args.length) healthWrites++; return originalHtml.apply(this, args); };
context.updateParentStackFromContainers('1', 'app');
assert.equal(healthWrites, 0, 'Unchanged health preserves the animated DOM');
assert.equal(healthCell.html(), before);
health = 'healthy';
context.updateParentStackFromContainers('1', 'app');
assert.equal(healthWrites, 1, 'Changed health still updates');

{
    const events = [];
    let visible = true, focused = 'Stop', position = { top: '240px', left: '50px' }, scroll = 18;
    const anchor = {
        length: 1, text: () => 'Stop',
        attr(name, value) { events.push([name, value]); return this; },
        on(name, callback) {
            const event = { preventDefault() { events.push('prevented'); },
                stopImmediatePropagation() { events.push('blocked'); } };
            callback(event);
            return this;
        },
        first() { return this; },
        filter(callback) { callback.call(this); return this; },
        trigger(name) { focused = 'Stop'; events.push(name); return this; },
    };
    const item = { addClass() { return this; }, find: () => anchor };
    const menu = {
        is: () => visible,
        css(key) {
            if (typeof key === 'string') return position[key];
            position = key;
            return this;
        },
        hasClass: () => true,
        scrollTop(value) { if (arguments.length) { scroll = value; return this; } return scroll; },
        find: () => anchor,
        children: () => ({ each(callback) { callback.call(item, 0); } }),
        toggleClass(name, value) { events.push([name, value]); return this; },
        show() { visible = true; return this; },
    };
    const adapter = {
        $: value => value === item ? item : value === anchor ? anchor : menu,
        context: { attach() {
            visible = false;
            focused = null;
            position = {};
            scroll = 0;
        } },
        fixContextDropdownOverflow() {},
    };
    vm.createContext(adapter);
    vm.runInContext(source.match(/^function refreshComposeContextMenu\([^]*?\n\}/m)[0], adapter);
    adapter.refreshComposeContextMenu('stack-1', [{ text: 'Stop', disabled: true }]);
    assert.equal(visible, true, 'Changed options preserve an open menu');
    assert.equal(position.top, '240px');
    assert.equal(position.left, '50px');
    assert.equal(scroll, 18);
    assert.equal(focused, 'Stop');
    assert.ok(events.includes('blocked'), 'Disabled options are enforced even if host ignores disabled');
    visible = false;
    adapter.refreshComposeContextMenu('stack-1', [{ text: 'Stop' }]);
    assert.equal(visible, false, 'Refreshing a closed menu never opens it');
}
console.log('Runtime redraw and busy context-menu regressions passed.');
