import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(__dirname, '../../../source/compose.manager/javascript');
const source = readFileSync(`${root}/composeManagerMain.js`, 'utf8');
const status = readFileSync(`${root}/composeUpdateStatus.js`, 'utf8');
const functions = [
  'createContainerInfo', 'composeImageUpdateKey', 'mergeContainerUpdateStatus',
  'createStackInfo', 'mergeStackUpdateStatus', 'mergeUpdateStatus',
  'composeCheckedContainers', 'composeContainerUpdateBadge', 'composeUpdateCheckBadge',
  'setComposeUpdateCheckState', 'updateStackUpdateUI', 'checkStackUpdates',
].map(name => source.match(new RegExp(`^function ${name}\\([^]*?\\n\\}`, 'm'))![0]).join('\n');

test.use({ storageState: { cookies: [], origins: [] } });

for (const kind of ['image', 'build-base']) {
  test(`${kind} evidence survives check, expansion, collapse and browser reload`, async ({ page }) => {
    const pending = {
      name: 'app', service: 'web', image: 'app:latest', state: 'running', localSha: 'original',
      hasUpdate: true, updateStatus: 'update-available', remoteSha: 'target', updateKind: kind,
      updateReadiness: kind === 'image' ? 'download-required' : 'rebuild-required',
    };
    const label = kind === 'image' ? 'Available' : 'Rebuild';
    let checked = false;
    let recreated = false;
    let releaseDetails: (() => void) | undefined;
    let blockDetails = false;
    await page.route('http://compose.test/**', async route => {
      if (route.request().url().endsWith('/exec')) {
        const action = new URLSearchParams(route.request().postData() || '').get('action');
        if (action === 'checkStackUpdates') {
          checked = true;
          await route.fulfill({ json: { result: 'success', updates: [pending] } });
        } else if (action === 'getStackContainers') {
          if (blockDetails) await new Promise<void>(resolve => { releaseDetails = resolve; });
          const container = recreated
            ? { ...pending, localSha: 'replacement', remoteSha: '', hasUpdate: false, updateStatus: 'unknown', updateReadiness: 'unknown' }
            : pending;
          await route.fulfill({ json: { result: 'success', containers: [container] } });
        } else {
          await route.fulfill({ json: { result: 'success' } });
        }
        return;
      }
      await route.fulfill({
        contentType: 'text/html',
        body: `<!doctype html><button id="check">Check</button><button id="expand">Expand</button><button id="collapse">Collapse</button>
          <table id="compose_stacks"><tr class="compose-sortable" data-project="test" id="stack-row-test"><td class="compose-updatecolumn"></td></tr></table>
          <table id="details-container-test" hidden><tbody></tbody></table>
          <script>
          // Transport/DOM adapter: execute production evidence and check functions without a live host.
          const $ = selector => {
            const nodes = typeof selector === 'string' ? [...document.querySelectorAll(selector)] : [selector];
            return {
              length: nodes.length, attr: key => nodes[0]?.getAttribute(key),
              find: selector => $(nodes[0]?.querySelector(selector)),
              html: value => nodes.forEach(node => { if (node) node.innerHTML = value; }),
              each: fn => nodes.forEach(node => fn.call(node)),
            };
          };
          $.post = (url, payload, callback) => {
            const request = fetch(url, {method:'POST', body:new URLSearchParams(payload)})
              .then(response => response.text()).then(text => { if (callback) callback(text); });
            return {fail: handler => request.catch(error => handler({}, 'error', error.message))};
          };
          var caURL = '/exec', stackUpdateStatus = {}, stackContainersCache = {},
            composeUpdateCheckState = {}, composeUpdateCheckRevision = {}, stackDetailsPrefetchCache = {},
            pendingUpdateCheckStacks = [];
          function composeEscapeAttr(value) { return value; }
          function composeLogger() {}
          function updateUpdateAllButton() {}
          function isValidIconSrc() { return true; }
          ${status}
          ${functions}
          stackUpdateStatus.test = {containers: ${JSON.stringify(checked ? [pending] : [])}};
          updateStackUpdateUI('test', stackUpdateStatus.test);
          document.querySelector('#check').onclick = () => checkStackUpdates('test');
          document.querySelector('#collapse').onclick = () => { document.querySelector('#details-container-test').hidden = true; };
          document.querySelector('#expand').onclick = async () => {
            const revision = composeUpdateCheckRevision.test || 0;
            const response = await fetch('/exec', {method:'POST', body:'action=getStackContainers'}).then(response => response.json());
            const containers = composeCheckedContainers(response, 'test', revision);
            stackContainersCache.test = containers;
            document.querySelector('#details-container-test tbody').innerHTML = containers.map(container =>
              '<tr data-container="app"><td class="ct-updatecolumn">' + composeContainerUpdateBadge(container, 'test', 'test') + '</td></tr>').join('');
            document.querySelector('#details-container-test').hidden = false;
          };
          </script>`,
      });
    });
    await page.goto('http://compose.test/');
    await page.locator('#check').click();
    await expect(page.locator('.compose-updatecolumn')).toContainText(label);
    for (let attempt = 0; attempt < 2; attempt++) {
      await page.locator('#expand').click();
      await expect(page.locator('.ct-updatecolumn')).toContainText(label);
      await page.locator('#collapse').click();
      await expect(page.locator('#details-container-test')).toBeHidden();
    }
    await page.reload();
    await expect(page.locator('.compose-updatecolumn')).toContainText(label);
    await page.locator('#expand').click();
    await expect(page.locator('.ct-updatecolumn')).toContainText(label);

    await page.locator('#collapse').click();
    blockDetails = true;
    recreated = true;
    await page.locator('#expand').click();
    await expect.poll(() => !!releaseDetails).toBe(true);
    await page.locator('#check').click();
    await expect.poll(() => page.evaluate(() => new Function('return composeUpdateCheckRevision.test')())).toBe(1);
    releaseDetails!();
    await expect(page.locator('.ct-updatecolumn')).toContainText('Unchecked');
    await expect(page.locator('.ct-updatecolumn')).not.toContainText(label);
  });
}
