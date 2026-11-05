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
console.log('Runtime redraw and busy context-menu regressions passed.');
