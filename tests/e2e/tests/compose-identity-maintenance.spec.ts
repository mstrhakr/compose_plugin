import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(__dirname, '../../../source/compose.manager');
const source = readFileSync(resolve(root, 'javascript/composeIdentityMaintenance.js'), 'utf8');
const settings = readFileSync(resolve(root, 'compose.manager.settings.page'), 'utf8');
const main = readFileSync(resolve(root, 'javascript/composeManagerMain.js'), 'utf8');

test.describe('Project identity maintenance', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test.beforeEach(async ({ page }) => {
    await page.setContent('<button type="button" id="compose-identity-check">Check stack project identities</button><div id="compose-identity-report"></div>');
    await page.addScriptTag({ content: `
      var requests = [], notices = [], confirmChoice;
      function $(ready) { ready(); }
      $.ajax = function(options) {
        var request = { options };
        requests.push(request);
        return {
          done(callback) { request.done = callback; return this; },
          fail(callback) { request.fail = callback; return this; },
          always(callback) { request.always = callback; return this; }
        };
      };
      function swal(options, callback) { notices.push(options); confirmChoice = callback; }
      ${source}
    ` });
  });

  test('lives outside the settings form and removes the everyday launcher', () => {
    expect(settings.indexOf('id="compose-identity-check"')).toBeGreaterThan(settings.indexOf('</form>'));
    expect(settings).toContain('javascript/composeIdentityMaintenance.js');
    expect(main).not.toContain('composeAddIdentityAuditLauncher');
    expect(main).not.toContain('Every stack\'s saved project name matches');
    const maintenance = settings.slice(settings.indexOf('<div id="compose-tab-maintenance"'), settings.indexOf('<!-- Updates Tab -->'));
    expect(settings).toContain('data-tab="maintenance"');
    expect(settings).toContain("settingsUnifiedState.activeTab === 'maintenance'");
    expect(maintenance).toContain('class="title"');
    expect(maintenance).toContain('<dl>');
    expect(maintenance).toContain('onclick="clearAllIconCache()"');
    expect(maintenance).toContain('onclick="clearUpdateCache()"');
    expect(settings.match(/onclick="clearAllIconCache\(\)"/g)).toHaveLength(1);
    expect(settings.match(/onclick="clearUpdateCache\(\)"/g)).toHaveLength(1);
  });

  test('checks only on demand and reports all outcomes without HTML injection', async ({ page }) => {
    expect(await page.evaluate(() => new Function('return requests.length;')())).toBe(0);
    await page.getByRole('button', { name: 'Check stack project identities' }).click();
    await expect(page.locator('#compose-identity-check')).toBeDisabled();
    await page.evaluate(() => new Function(`
      requests[0].done({ result: 'success', identities:
        ['matched', 'attention', 'blocked', 'unavailable', 'no-evidence'].map(auditStatus => ({
          projectFolder: auditStatus, projectName: 'saved', displayName: '<img src=x onerror=alert(1)>',
          auditStatus, auditMessage: 'Evidence details', canChoose: auditStatus === 'attention',
          candidates: ['a.b', 'legacy_name']
        }))
      });
      requests[0].always();
    `)());
    await expect(page.locator('#compose-identity-report tbody tr')).toHaveCount(5);
    await expect(page.locator('#compose-identity-report tbody tr').first()).toContainText('Ownership needs review');
    await expect(page.locator('#compose-identity-report')).toContainText('5 stacks checked; 2 need ownership review.');
    await expect(page.locator('#compose-identity-report img')).toHaveCount(0);
    await expect(page.getByText('Unable to verify', { exact: false })).toBeVisible();
    await expect(page.getByText('No runtime evidence', { exact: false })).toBeVisible();
    await expect(page.locator('#compose-identity-report button')).toHaveCount(2);
    await expect(page.locator('#compose-identity-check')).toBeEnabled();
  });

  test('requires confirmation, preserves exact candidates and rechecks after saving', async ({ page }) => {
    await page.locator('#compose-identity-check').click();
    await page.evaluate(() => new Function(`
      requests[0].done({ result: 'success', identities: [{
        projectFolder: 'folder', projectName: 'folder', auditStatus: 'attention',
        auditMessage: 'Choose the existing runtime project.', canChoose: true, candidates: ['legacy_name']
      }] });
      requests[0].always();
    `)());
    await page.getByRole('button', { name: 'Use project: legacy_name' }).click();
    await page.evaluate(() => new Function('confirmChoice(false);')());
    expect(await page.evaluate(() => new Function('return requests.length;')())).toBe(1);
    await page.getByRole('button', { name: 'Use project: legacy_name' }).click();
    await page.evaluate(() => new Function('confirmChoice(true);')());
    expect(await page.evaluate(() => new Function('return requests[1].options.data;')())).toEqual({
      action: 'setProjectIdentity', stackName: 'folder', projectName: 'legacy_name',
    });
    await page.evaluate(() => new Function('requests[1].done({ result: "success" });')());
    expect(await page.evaluate(() => new Function('return requests[2].options.data;')())).toEqual({
      action: 'getProjectIdentities',
    });
  });

  test('reports network and malformed responses explicitly and permits retry', async ({ page }) => {
    await page.locator('#compose-identity-check').click();
    await page.evaluate(() => new Function('requests[0].fail(); requests[0].always();')());
    await expect(page.locator('#compose-identity-report')).toContainText('Could not check');
    await expect(page.locator('#compose-identity-check')).toBeEnabled();
    await page.locator('#compose-identity-check').click();
    await page.evaluate(() => new Function('requests[1].done({result: "success", identities: [{}]}); requests[1].always();')());
    await expect(page.locator('#compose-identity-report')).toContainText('Invalid project identity report');
    await expect(page.locator('#compose-identity-check')).toBeEnabled();
  });

  test('shows save errors instead of claiming recovery succeeded', async ({ page }) => {
    await page.locator('#compose-identity-check').click();
    await page.evaluate(() => new Function(`
      requests[0].done({ result: 'success', identities: [{
        projectFolder: 'folder', projectName: 'folder', auditStatus: 'attention',
        auditMessage: 'Choose the existing runtime project.', canChoose: true, candidates: ['legacy_name']
      }] });
      requests[0].always();
    `)());
    await page.getByRole('button', { name: 'Use project: legacy_name' }).click();
    await page.evaluate(() => new Function(`
      confirmChoice(true);
      requests[1].done({result: 'error', message: 'Another stack folder also claims this project identity.'});
    `)());
    await expect(page.locator('#compose-identity-report')).toContainText('Another stack folder also claims');
    await expect(page.locator('#compose-identity-check')).toBeEnabled();
    expect(await page.evaluate(() => new Function('return requests.length;')())).toBe(2);

    await page.locator('#compose-identity-check').click();
    await page.evaluate(() => new Function(`
      requests[2].done({result: 'success', identities: []});
      requests[2].always();
    `)());
    await expect(page.locator('#compose-identity-report')).toHaveText('No stacks found.');
  });
});
