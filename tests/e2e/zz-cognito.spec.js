const {test,expect}=require('@playwright/test');
const fs=require('node:fs');const {execFileSync}=require('node:child_process');
const configPath='.ci/auth/schuit-cognito.json';
const cfg={enabled:true,origin:'https://localhost:19443',domain:'https://schuit-fixture.auth.eu-west-1.amazoncognito.com',issuer:'https://cognito-idp.eu-west-1.amazonaws.com/eu-west-1_test',client_id:'syntheticclient123456',users:{}};
const admin='/wp-admin/tools.php?page=schuit-gedcom-import';
test.describe.configure({mode:'serial'});
test.beforeAll(()=>{
 const ids=JSON.parse(execFileSync('docker',['compose','--env-file','.ci/env','-f','tests/ci/compose.yaml','-p','schuit-ci','exec','-T','web','php','/opt/schuit-repo/tests/ci/auth-map.php'],{encoding:'utf8'}));
 cfg.users={'11111111-1111-4111-8111-111111111111':{email:'ci_manager@example.invalid',wordpress:ids.manager},'22222222-2222-4222-8222-222222222222':{email:'ci_reader@example.invalid',wordpress:ids.reader},'33333333-3333-4333-8333-333333333333':{email:'ci_tree_admin@example.invalid',webtrees:1}};
 fs.writeFileSync(configPath,JSON.stringify(cfg));
});
test.afterAll(()=>fs.writeFileSync(configPath,JSON.stringify({enabled:false})));
async function start(page,path='/wp-login.php'){
 const r=await page.request.get(path,{maxRedirects:0});expect([302,303]).toContain(r.status());const location=r.headers().location;expect(location).toContain(cfg.domain);await page.goto(location);
}
async function provider(page,code='manager',modify=()=>{}){
 await page.route(cfg.domain+'/**',async route=>{
  const u=new URL(route.request().url());expect(u.searchParams.get('code_challenge_method')).toBe('S256');expect(u.searchParams.get('prompt')).toBe('login');
  const callback=new URL(u.searchParams.get('redirect_uri'));callback.searchParams.set('state',u.searchParams.get('state'));callback.searchParams.set('code',code);modify(callback);
  await route.fulfill({status:302,headers:{location:callback.href},body:''});
 });
}
test('Cognito manager retains GEDCOM rights; local logout clears access',async({page})=>{
 await provider(page);await start(page);await page.goto(admin);await expect(page.locator('#sg-file')).toBeVisible();
 const logout=await page.locator('#wp-admin-bar-logout a').getAttribute('href');await page.unrouteAll();
 await page.request.get(logout,{maxRedirects:0});const r=await page.request.get(admin,{maxRedirects:0});expect(r.status()).toBe(302);expect(r.headers().location).toContain('/wp-login.php');
});
test('subscriber remains unable to upload GEDCOM',async({page})=>{await provider(page,'reader');await start(page);await page.goto(admin);await expect(page.locator('#sg-file')).toHaveCount(0);await expect(page.locator('body')).toContainText(/not allowed|niet toegestaan/i);});
test('unlinked identity cannot create or claim a website account',async({page})=>{await provider(page,'unlinked');await start(page);await expect(page.getByRole('heading',{name:'Aanmelden niet voltooid'})).toBeVisible();});
test('forged state and replayed callbacks fail',async({page})=>{
 let callback;await provider(page,'manager',url=>{callback=url.href;url.searchParams.set('state','x'.repeat(43));});await start(page);await expect(page.getByRole('heading',{name:'Aanmelden niet voltooid'})).toBeVisible();
 await page.goto(callback);await page.goto(admin);await expect(page.locator('#sg-file')).toBeVisible();
 await page.goto(callback);await expect(page.getByRole('heading',{name:'Aanmelden niet voltooid'})).toBeVisible();
});
test('native WordPress/webtrees password POSTs cannot bypass AWS',async({request})=>{
 for(const url of ['/wp-login.php','/tree/login/0','/tree/index.php?route=/login/0']){const r=await request.post(url,{form:{log:'ci_manager',pwd:'irrelevant',username:'ci_tree_admin',password:'irrelevant'}});expect(r.status()).toBe(403);}
});
test('Cognito signs into existing webtrees administrator; logout works',async({page})=>{
 await provider(page,'tree');await page.goto('/');await start(page,'/tree/login/0');await page.goto('/tree/admin');await expect(page).toHaveURL(/\/tree\/admin$/);
 await page.getByRole('link',{name:/^(Sign out|Afmelden)$/}).click();await expect(page.getByRole('link',{name:/^(Sign in|Aanmelden)$/})).toBeVisible();
});
test('login initiation is bounded per browser with a Dutch retry response',async({request})=>{
 for(let i=0;i<3;i++){const r=await request.get('/wp-login.php',{maxRedirects:0});expect(r.status()).toBe(303);}
 const limited=await request.get('/wp-login.php',{maxRedirects:0});
 expect(limited.status()).toBe(429);expect(limited.headers()['retry-after']).toBe('600');
 expect(await limited.text()).toMatch(/aanmeld|probeer/i);
});
