import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(__dirname, '../../../source/compose.manager');
const source = readFileSync(`${root}/javascript/composeDockerFilter.js`, 'utf8');
const filterStyle = readFileSync(`${root}/compose.manager.docker-filter.page`, 'utf8')
  .match(/<style>([\s\S]*?)<\/style>/)![1];

test.use({ storageState: { cookies: [], origins: [] } });

for (const header of [false, true]) {
  test(`Docker rows stay filtered with ${header ? 'header menu' : 'inline Compose'}`, async ({ page }) => {
    await page.setContent(`<style>${filterStyle}
      .another-plugin-hidden { display: none; }
      </style>${header ? '' : '<table id="compose_stacks"><tr><td>Compose stack</td></tr></table>'}
      <main id="docker"></main>`);
    await page.evaluate(() => {
      const state = window as typeof window & {
        filterNames: string[];
        $: unknown;
      };
      state.filterNames = ['app', 'stopped'];
      class Elements {
        constructor(private elements: Element[]) {}
        get length() { return this.elements.length; }
        each(callback: (this: Element) => void) {
          this.elements.forEach(element => callback.call(element));
        }
        find(selector: string) {
          return new Elements(this.elements.flatMap(element =>
            Array.from(element.querySelectorAll(selector.replace('td:first', 'td:first-child')))));
        }
        first() { return new Elements(this.elements.slice(0, 1)); }
        text() { return this.elements[0]?.textContent || ''; }
        toggleClass(name: string, value: boolean) {
          this.elements.forEach(element => element.classList.toggle(name, value));
        }
        closest(selector: string) {
          return new Elements(this.elements.flatMap(element => {
            const parent = element.closest(selector);
            return parent ? [parent] : [];
          }));
        }
      }
      const dollar = (selector: string | Element | Document | (() => void)) => {
        if (typeof selector === 'function') { selector(); return; }
        if (selector === document) {
          return { on: (names: string, callback: EventListener) =>
            names.split(' ').forEach(name => document.addEventListener(name, callback)) };
        }
        return new Elements(typeof selector === 'string'
          ? Array.from(document.querySelectorAll(selector)) : [selector as Element]);
      };
      dollar.post = () => {
        const request = {
          done(callback: (data: unknown) => void) {
            callback({ result: 'success', names: state.filterNames });
            return request;
          },
          fail() { return request; },
          always(callback: () => void) { callback(); return request; },
        };
        return request;
      };
      state.$ = dollar;
    });
    await page.addScriptTag({ content: source });
    await page.locator('#docker').evaluate(element => {
      element.innerHTML = `<table id="docker_list"><tbody>
        <tr class="sortable" id="app"><td><span class="appname">app</span></td></tr>
        <tr class="sortable" id="stopped"><td><span class="appname">stopped</span></td></tr>
        <tr class="sortable" id="native"><td><span class="appname">native</span></td></tr>
        <tr class="sortable another-plugin-hidden" id="other"><td><span class="appname">other</span></td></tr>
      </tbody></table>`;
    });
    await expect(page.locator('#app')).toBeHidden();
    await expect(page.locator('#stopped')).toBeHidden();
    await expect(page.locator('#native')).toBeVisible();
    await expect(page.locator('#other')).toBeHidden();
    if (!header) await expect(page.locator('#compose_stacks')).toBeVisible();

    await page.locator('#app').evaluate(element => {
      const replacement = element.cloneNode(true) as Element;
      replacement.classList.remove('compose-manager-hidden');
      element.replaceWith(replacement);
    });
    await expect(page.locator('#app')).toBeHidden();
    await page.evaluate(() => {
      (window as typeof window & { filterNames: string[] }).filterNames = [];
      document.dispatchEvent(new Event('compose-stack-removed'));
    });
    await expect(page.locator('#app')).toBeVisible();
    await expect(page.locator('#stopped')).toBeVisible();
    await expect(page.locator('#other')).toBeHidden();
  });
}
