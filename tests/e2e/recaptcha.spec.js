const {test, expect} = require('@playwright/test');
const {captcha} = require('./captcha-helper');
const password = JSON.parse(require('node:fs').readFileSync('.ci/credentials.json','utf8')).password;

test('login forms show Dutch reCAPTCHA; public content does not load it', async ({page}) => {
  await captcha(page,false);
  for(const url of ['/wp-login.php','/tree/login/0']) {
    await page.goto(url);
    await expect(page.getByRole('button',{name:'Ik ben geen robot (CI)'})).toBeVisible();
    await expect(page.getByText('Google reCAPTCHA beschermt', {exact:false})).toBeVisible();
  }
  await page.goto('/'); await expect(page.locator('.g-recaptcha')).toHaveCount(0);
});

for(const site of ['wordpress','webtrees']) {
  test(`${site}: valid credentials cannot bypass a missing challenge`, async ({page}) => {
    await captcha(page,false);
    await page.goto(site==='wordpress'?'/wp-login.php':'/tree/login/0');
    await page.locator(site==='wordpress'?'#user_login':'#username').fill(site==='wordpress'?'ci_manager':'ci_tree_admin');
    await page.locator(site==='wordpress'?'#user_pass':'#password').fill(password);
    const response=page.waitForResponse(r=>r.request().method()==='POST');
    await page.locator(site==='wordpress'?'#wp-submit':'button[type=submit]').click();
    expect((await response).status()).toBe(403);
    await expect(page.getByRole('alert')).toContainText('beveiligingscontrole');
    await page.getByRole('link',{name:'Terug naar aanmelden'}).click();
    await expect(page.locator('.g-recaptcha')).toBeVisible();
  });
}

test('verification outage fails closed with a retry message', async ({page}) => {
  await captcha(page,false); await page.goto('/wp-login.php');
  await page.locator('#user_login').fill('ci_manager'); await page.locator('#user_pass').fill(password);
  await page.locator('[name=g-recaptcha-response]').evaluate(el=>{el.value='ci-unavailable';});
  const response=page.waitForResponse(r=>r.request().method()==='POST'); await page.locator('#wp-submit').click();
  expect((await response).status()).toBe(503);
  await expect(page.getByRole('alert')).toContainText('tijdelijk niet beschikbaar');
});

test('rejected token does not become a successful login', async ({page}) => {
  await captcha(page,false); await page.goto('/wp-login.php');
  await page.locator('#user_login').fill('ci_manager'); await page.locator('#user_pass').fill(password);
  await page.locator('[name=g-recaptcha-response]').evaluate(el=>{el.value='ci-rejected';});
  const response=page.waitForResponse(r=>r.request().method()==='POST'); await page.locator('#wp-submit').click();
  expect((await response).status()).toBe(403);
});
