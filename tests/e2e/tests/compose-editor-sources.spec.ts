import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const source = readFileSync(resolve(__dirname, '../../../source/compose.manager/javascript/composeEditorSources.js'), 'utf8');
interface Harness extends Window {
  postEditorSourceSave(payload: Record<string, string>): { done(fn: (body: string) => void): void };
  loadEditorSharedSources(project: string): void;
  requests: { data: Record<string, string>; deferred: { resolve(data: string): void; reject(): void } }[];
  prompts: { options: { text: string }; callback: (accepted: boolean) => void }[];
  result: string;
  values: Record<string, unknown>;
  editorModal: { currentProject: string };
}
test.use({ storageState: { cookies: [], origins: [] } });
test.beforeEach(async ({ page }) => {
  await page.addScriptTag({ content: `
    var requests = [], prompts = [], values = {}, result = '', caURL = '/exec';
    var editorModal = { currentProject: 'a' };
    function composeEscapeHtml(text) { return text.replace(/&/g, '&amp;').replace(/</g, '&lt;'); }
    function swal(options, callback) { prompts.push({options, callback}); }
    swal.close = function() {};
    function $(selector) { return {
      hide() {values[selector + ':visible'] = false; return this;},
      text(value) {values[selector + ':text'] = value; return this;},
      toggle(value) {values[selector + ':visible'] = value; return this;},
      prop(name, value) {if (value === undefined) return values[selector + ':' + name]; values[selector + ':' + name] = value; return this;}
    }; }
    $.Deferred = function() {
      var done = [], fail = [], state = '', value;
      var promise = {
        done(fn) {done.push(fn); if(state === 'done')fn(value); return this;},
        fail(fn) {fail.push(fn); if(state === 'fail')fn(); return this;},
        always(fn) {this.done(fn); this.fail(fn); return this;}
      };
      return {resolve(data) {state='done'; value=data; done.forEach(fn=>fn(data));},
        reject() {state='fail'; fail.forEach(fn=>fn());}, promise() {return promise;}};
    };
    $.post = function(url, data) {var deferred=$.Deferred(); requests.push({data: {...data},deferred}); return deferred.promise();};
    ${source}
  ` });
});

test('shared confirmation preserves edits on cancel and escapes file and stack names', async ({ page }) => {
  const state = await page.evaluate(() => {
    const w = window as typeof window & Harness;
    w.postEditorSourceSave({ action: 'saveYml', script: 'a', scriptContents: 'keep edits' }).done(body => { w.result = body; });
    w.requests[0].deferred.resolve(JSON.stringify({ errorCode: 'shared_source_confirmation', confirmation: 'token',
      shared: [{ path: '<file>', stacks: ['<stack>'] }], incomplete: [] }));
    const text = w.prompts[0].options.text;
    w.prompts[0].callback(false);
    return { text, result: w.result, count: w.requests.length };
  });
  expect(state.text).toContain('&lt;file>');
  expect(state.text).not.toContain('<stack>');
  expect(JSON.parse(state.result).result).toBe('error');
  expect(state.count).toBe(1);
});

test('accepted save retries with exact server confirmation and retained content', async ({ page }) => {
  const state = await page.evaluate(() => {
    const w = window as typeof window & Harness;
    w.postEditorSourceSave({ action: 'saveEnv', script: 'a', scriptContents: 'SECRET=not logged' }).done(body => { w.result = body; });
    w.requests[0].deferred.resolve(JSON.stringify({ errorCode: 'shared_source_confirmation', confirmation: 'token', shared: [], incomplete: [] }));
    w.prompts[0].callback(true);
    const retry = w.requests[1].data;
    w.requests[1].deferred.resolve('/source saved');
    return { retry, result: w.result };
  });
  expect(state.retry.sharedConfirmation).toBe('token');
  expect(state.retry.scriptContents).toBe('SECRET=not logged');
  expect(state.result).toBe('/source saved');
});

test('dismissed opening notice is quiet but exposes a reversible preference', async ({ page }) => {
  const state = await page.evaluate(() => {
    const w = window as typeof window & Harness;
    w.loadEditorSharedSources('a');
    w.requests[0].deferred.resolve(JSON.stringify({ result: 'success', dismissed: true, shared: [], incomplete: [] }));
    return { values: w.values, prompts: w.prompts.length };
  });
  expect(state.values['#editor-shared-sources-message:visible']).toBe(false);
  expect(state.values['#editor-shared-sources:visible']).toBe(true);
  expect(state.values['#editor-shared-sources-dismissed:checked']).toBe(true);
  expect(state.prompts).toBe(0);
});

test('older sharing response cannot replace a newer opening notice', async ({ page }) => {
  const values = await page.evaluate(() => {
    const w = window as typeof window & Harness;
    w.loadEditorSharedSources('a'); w.loadEditorSharedSources('a');
    w.requests[1].deferred.resolve(JSON.stringify({ result: 'success', dismissed: true, shared: [], incomplete: [] }));
    w.requests[0].deferred.resolve(JSON.stringify({ result: 'success', dismissed: false, shared: [{path:'old', stacks:['b']}], incomplete: [] }));
    return w.values;
  });
  expect(values['#editor-shared-sources-dismissed:checked']).toBe(true);
});
