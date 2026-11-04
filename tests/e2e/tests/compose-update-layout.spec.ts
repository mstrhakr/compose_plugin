import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(__dirname, '../../../source/compose.manager');
const source = readFileSync(`${root}/javascript/composeManagerMain.js`, 'utf8');
const statusSource = readFileSync(`${root}/javascript/composeUpdateStatus.js`, 'utf8');
const styles = readFileSync(`${root}/include/ComposeManager.php`, 'utf8').match(/<style>([\s\S]*?)<\/style>/)![1];
const functions = [
  'composeUpdateDetailsTable', 'composeUpdateDigestDetails',
  'composeUpdateScopeOptionHtml', 'renderStackActionDialog',
].map(name => source.match(new RegExp(`^function ${name}\\([^]*?\\n\\}`, 'm'))![0]).join('\n');

test.use({ storageState: { cookies: [], origins: [] } });

for (const viewport of [
  { width: 1280, height: 720 },
  { width: 1920, height: 1080 },
  { width: 390, height: 844 },
  { width: 854, height: 480 },
]) {
  for (const theme of ['dark', 'light']) {
  for (const count of [1, 2, 18]) {
    test(`${theme} update dialog fits ${count} services at ${viewport.width}x${viewport.height}`, async ({ page }) => {
      await page.setViewportSize(viewport);
      // Reproduce host-theme rules that previously squeezed the modal and overlapped its columns.
      await page.setContent(`<style>
        :root { --alt-background-color: ${theme === 'light' ? '#eee' : '#262626'}; }
        body { font: 14px Arial; background: ${theme === 'light' ? '#fff' : '#111'}; color: ${theme === 'light' ? '#222' : '#eee'}; }
        .sweet-alert { position: fixed; width: 600px !important; padding: 20px;
          margin-left: -300px; margin-top: -200px; background: ${theme === 'light' ? '#fafafa' : '#222'}; border: 1px solid #888; }
        .sweet-alert p { text-align: center; }
        table td, table th { white-space: nowrap; }
        dt { float: left; width: 160px; font-weight: bold; }
        dd { margin-left: 160px; }
        .sweet-alert button { margin: 16px 5px 0; padding: 8px 16px; }
        ${styles}
      </style><main></main>`);
      await page.evaluate(({ functions, statusSource, count }) => {
        const escapeHtml = (value: string) => {
          const element = document.createElement('span');
          element.textContent = value;
          return element.innerHTML.replace(/"/g, '&quot;');
        };
        const scope = {
          document, window, setTimeout: () => {},
          composeEscapeHtml: escapeHtml, composeEscapeAttr: escapeHtml,
          composeIconSrc: () => 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"/>',
          dockerVersionsInstalled: true,
          getConfig: () => Promise.resolve({}),
          ComposeUpConfirmed() {}, ComposeDownConfirmed() {}, ComposeStopConfirmed() {},
          ComposeRestartConfirmed() {}, ComposePullConfirmed() {}, UpdateStackConfirmed() {},
          ForceUpdateStackConfirmed() {},
          swal: (options: { title: string; text: string; customClass: string; confirmButtonText: string }) => {
            const modal = document.createElement('div');
            modal.className = `sweet-alert showSweetAlert ${options.customClass}`;
            modal.innerHTML = '<h2></h2><p style="display:block"></p><div class="sa-button-container"><button>Cancel</button><button class="confirm"></button></div>';
            modal.querySelector('h2')!.textContent = options.title;
            modal.querySelector('p')!.innerHTML = options.text;
            modal.querySelector('.confirm')!.textContent = options.confirmButtonText;
            document.querySelector('main')!.append(modal);
          },
        };
        const containers = Array.from({ length: count }, (_, index) => ({
          name: `servarr-service-${index}`, service: `service-${index}`,
          image: `ghcr.io/a-very-long-organization/a-long-application-image-${index}:latest`,
          state: 'running', hasUpdate: index % 2 === 0,
          updateReadiness: index % 2 === 0 ? 'download-required' : 'current',
          updateKind: index === 1 ? 'build-base' : 'image',
          localSha: '428769644eda', localImageSha: '428769644eda', remoteSha: '7284f3c712af',
        }));
        const invoke = new Function(...Object.keys(scope), 'containers', `${statusSource}\n${functions}\n
          renderStackActionDialog('update', 'servarr-001', '/stacks/test', '', containers, true, false);`);
        invoke(...Object.values(scope), containers);
      }, { functions, statusSource, count });

      const modal = page.locator('.compose-update-dialog');
      await expect(modal).toBeVisible();
      await expect(modal.locator('tbody tr')).toHaveCount(count);
      const layout = await modal.evaluate(element => {
        const rect = element.getBoundingClientRect();
        const list = element.querySelector('.compose-update-table-wrap')!;
        const listRect = list.getBoundingClientRect();
        const options = element.querySelector('.compose-update-options')!.getBoundingClientRect();
        const body = element.querySelector(':scope > p')!.getBoundingClientRect();
        const buttons = element.querySelector('.sa-button-container')!.getBoundingClientRect();
        const overflows: string[] = [];
        element.querySelectorAll('td').forEach(cell => {
          const bounds = cell.getBoundingClientRect();
          const walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT);
          while (walker.nextNode()) {
            if (!walker.currentNode.textContent?.trim()) continue;
            const range = document.createRange();
            range.selectNodeContents(walker.currentNode);
            for (const textRect of Array.from(range.getClientRects())) {
              if (textRect.left < bounds.left - 1 || textRect.right > bounds.right + 1) {
                overflows.push(walker.currentNode.textContent!);
              }
            }
          }
        });
        const overlappingIds = Array.from(element.querySelectorAll('dt')).some(label => {
          const a = label.getBoundingClientRect();
          const b = label.nextElementSibling!.getBoundingClientRect();
          return a.right > b.left + 1;
        });
        return {
          width: rect.width, top: rect.top, bottom: rect.bottom,
          modalScroll: element.scrollHeight - element.clientHeight,
          listScroll: list.scrollHeight - list.clientHeight,
          horizontalScroll: list.scrollWidth - list.clientWidth,
          optionsBelowList: options.top >= listRect.bottom,
          footerGap: buttons.top - body.bottom,
          buttonsVisible: buttons.bottom <= innerHeight,
          overflows, overlappingIds,
        };
      });
      expect(layout.top).toBeGreaterThanOrEqual(0);
      expect(layout.bottom).toBeLessThanOrEqual(viewport.height);
      expect(layout.width).toBeLessThanOrEqual(viewport.width - 30);
      if (viewport.width >= 1280) expect(layout.width).toBeGreaterThan(900);
      expect(layout.modalScroll).toBeLessThanOrEqual(1);
      expect(layout.horizontalScroll).toBeLessThanOrEqual(1);
      expect(layout.optionsBelowList).toBe(true);
      expect(layout.footerGap).toBeGreaterThanOrEqual(-1);
      expect(layout.buttonsVisible).toBe(true);
      expect(layout.overflows).toEqual([]);
      expect(layout.overlappingIds).toBe(false);
      if (count === 18) expect(layout.listScroll).toBeGreaterThan(0);
      if (count <= 2 && viewport.width >= 1280) expect(layout.listScroll).toBeLessThanOrEqual(1);
      await page.screenshot({ path: test.info().outputPath('update-dialog.png') });
      await page.locator('#swal-include-stopped').check();
      await expect(page.locator('#swal-include-stopped')).toBeChecked();
      if (count === 18) {
        const footer = modal.locator('.sa-button-container');
        const before = await footer.boundingBox();
        await page.locator('.compose-update-table-wrap').evaluate(element => { element.scrollTop = element.scrollHeight; });
        await expect(page.locator('.confirm')).toBeVisible();
        await modal.evaluate(element => { element.scrollTop = element.scrollHeight; });
        await modal.locator(':scope > p').evaluate(element => { element.scrollTop = element.scrollHeight; });
        expect(await footer.boundingBox()).toEqual(before);
        expect(await modal.evaluate(element => element.scrollTop)).toBe(0);
        await modal.locator('.confirm').click({ trial: true });
        if (viewport.width > 640) {
          const headerPinned = await modal.evaluate(element => {
            const list = element.querySelector('.compose-update-table-wrap')!.getBoundingClientRect();
            const header = element.querySelector('th')!.getBoundingClientRect();
            return Math.abs(header.top - list.top) <= 2;
          });
          expect(headerPinned).toBe(true);
        }
      }
      await modal.evaluate(element => {
        element.classList.remove('showSweetAlert');
        (element as HTMLElement).style.display = 'none';
      });
      await expect(modal).toBeHidden();
    });
  }
  }
}
