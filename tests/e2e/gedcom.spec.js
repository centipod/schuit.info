const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const password = JSON.parse(fs.readFileSync('.ci/credentials.json', 'utf8')).password;
const adminPath = '/wp-admin/tools.php?page=schuit-gedcom-import';
function worker(mode, expected = '') { execFileSync('python3', ['tests/ci/process-jobs.py', mode, expected], { stdio: 'pipe', timeout: 60000 }); }
async function login(page, username = 'ci_manager') {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(username);
  await page.locator('#user_pass').fill(password);
  await page.locator('#wp-submit').click();
  await page.goto(adminPath);
}
async function upload(page, filename = 'replacement.ged', content) {
  await page.locator('#sg-file').setInputFiles(content ? { name: filename, mimeType: 'application/octet-stream', buffer: Buffer.from(content) } : path.resolve('tests/ci', filename));
  await page.getByRole('button', { name: 'Uploaden en controleren' }).click();
}

test.describe.configure({ mode: 'serial' });
test('anonymous visitors cannot administer GEDCOM or see living-person names', async ({ page }) => {
  await page.goto(adminPath);
  await expect(page.locator('#loginform')).toBeVisible();
  await page.goto('/');
  await page.goto('/tree/tree/0');
  await expect(page).not.toHaveTitle(/Cookie check/i);
  await expect(page.getByText('Synthetic CI family tree').first()).toBeVisible();
  await page.goto('/tree/tree/0/individual/LIVING');
  await expect(page.locator('body')).not.toContainText('Synthetic Living');
  await expect(page.locator('body')).toContainText(/private|privacy|access|privé/i);
});
test('wrong passwords fail; an authorised manager can log in and log out', async ({ page }) => {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill('ci_manager');await page.locator('#user_pass').fill('incorrect-ci-password');await page.locator('#wp-submit').click();
  await expect(page.locator('#login_error')).toBeVisible();
  await login(page);await expect(page.locator('#sg-file')).toBeVisible();
  const logout = await page.locator('#wp-admin-bar-logout a').getAttribute('href');
  await page.goto(logout);await page.goto(adminPath);await expect(page.locator('#loginform')).toBeVisible();
});
test('subscriber cannot access GEDCOM management', async ({ page }) => {
  await login(page, 'ci_reader');
  await expect(page.locator('body')).toContainText(/not allowed|Geen toegang|niet toegestaan/i);
  await expect(page.locator('#sg-file')).toHaveCount(0);
});
test('disguised active content is rejected before application processing', async ({ page }) => {
  await login(page);
  const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('admin-post.php'));
  await upload(page,'unsafe.ged','<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>');
  expect((await response).status()).toBe(415);
  await expect(page.getByRole('heading',{name:'Bestand niet toegestaan'})).toBeVisible();
});
test('malformed GEDCOM fails analysis and cannot be confirmed', async ({ page }) => {
  await login(page);await upload(page,'invalid.ged','This is not a GEDCOM file.');worker('analyze', 'expect-invalid');await page.reload();
  await expect(page.locator('#sg-state')).toHaveText('Niet verwerkt');
  await expect(page.getByRole('button',{name:'Bevestigen en verwerken'})).toHaveCount(0);
});
test('review shows metrics; cancellation keeps the current data', async ({ page }) => {
  await login(page);await upload(page);worker('analyze');await page.reload();
  await expect(page.locator('#sg-state')).toHaveText('Klaar voor uw controle');
  await expect(page.locator('.sg-metrics')).toContainText('12');await expect(page.locator('.sg-metrics')).toContainText('4');
  await expect(page.locator('input[name=replace_confirmed]')).not.toBeChecked();
  await page.getByRole('button',{name:/annuleren/i}).click();
  await expect(page.locator('#sg-state')).toHaveText('Geannuleerd');
});
test('upload → verify → explicit confirmation → real import → Webtrees statistics', async ({ page, browser }) => {
  await login(page);await upload(page);worker('analyze');await page.reload();
  const reviewURL=page.url();
  const stranger = await browser.newContext({ ignoreHTTPSErrors: true, baseURL:'https://localhost:19443' });
  const strangerPage=await stranger.newPage();await login(strangerPage,'ci_other');await strangerPage.goto(reviewURL);
  await expect(strangerPage.locator('body')).toContainText('Taak niet beschikbaar');await stranger.close();
  // HTML validation must prevent an unchecked confirmation.
  await page.getByRole('button',{name:'Bevestigen en verwerken'}).click();
  await expect(page.locator('#sg-state')).toHaveText('Klaar voor uw controle');
  await page.locator('input[name=replace_confirmed]').check();await page.getByRole('button',{name:'Bevestigen en verwerken'}).click();
  worker('import');await page.reload();await expect(page.locator('#sg-state')).toHaveText('Import voltooid');
  await expect(page.getByRole('button',{name:'Bevestigen en verwerken'})).toHaveCount(0);
  await page.getByRole('link',{name:'Open Webtrees',exact:true}).click();
  await expect(page.locator('tr').filter({has:page.getByText('Individuals',{exact:true})})).toContainText('12');
  await expect(page.locator('tr').filter({has:page.getByText('Families',{exact:true})})).toContainText('4');
  await page.goto('/');await expect(page.locator('body')).toContainText('12');await expect(page.locator('body')).toContainText('Gezinnen');
});
test('Webtrees native administrator login and logout work', async ({ page }) => {
  await page.goto('/');await page.goto('/tree/login/0');
  await page.locator('input[name=username]').fill('ci_tree_admin');await page.locator('input[name=password]').fill(password);
  await page.getByRole('button',{name:'sign in',exact:true}).click();await page.goto('/tree/admin');
  await expect(page).toHaveURL(/\/tree\/admin$/);
  const logout=page.getByRole('link',{name:'Sign out',exact:true});await expect(logout).toBeAttached();await logout.click();
  await page.goto('/tree/admin');await expect(page).toHaveURL(/\/login/);
});
