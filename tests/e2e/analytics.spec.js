const {test,expect} = require('@playwright/test');
const fs = require('node:fs');
const code = fs.readFileSync('wordpress-mu-plugins/schuit-analytics.js','utf8');
const consentKey = 'schuit-analytics-consent-v1';
async function fixture(page, url='https://schuit.centipod.ai/') {
  const google=[];
  await page.route('**/*', async route => {
    if (/google/.test(new URL(route.request().url()).hostname)) {google.push(route.request().url());return route.fulfill({contentType:'text/javascript',body:''});}
    return route.fulfill({contentType:'text/html',body:'<!doctype html><html><head><title>Private genealogy title</title></head><body><h1>SCHUIT fixture</h1></body></html>'});
  });
  await page.goto(url);
  await page.addScriptTag({content:code});
  return google;
}
const consent = (page,name) => page.getByRole('button',{name,exact:true}).click();
test('no Google requests or analytics cookies before consent or after refusal',async({page})=>{
  const requests=await fixture(page);await expect(page.getByText('Bezoekersstatistieken',{exact:true})).toBeVisible();
  expect(requests).toEqual([]);expect(await page.evaluate(()=>window.dataLayer)).toBeUndefined();
  await consent(page,'Weigeren');expect(requests).toEqual([]);
  await page.reload();await page.addScriptTag({content:code});
  await expect(page.locator('#schuit-analytics-choice')).toBeHidden();expect(requests).toEqual([]);
  expect((await page.context().cookies()).filter(c=>c.name.startsWith('_ga'))).toEqual([]);
});
test('opt-in counts once with a broad webtrees section and no names or IDs',async({page})=>{
  const requests=await fixture(page,'https://schuit.centipod.ai/tree/tree/Schuit/individual/I123/Private-Name');
  await consent(page,'Toestaan');await expect.poll(()=>requests.length).toBe(1);
  const data=await page.evaluate(()=>window.dataLayer.map(x=>Array.from(x)));
  const events=data.filter(x=>x[0]==='event');expect(events).toHaveLength(1);
  expect(events[0][2].page_location).toBe('https://schuit.centipod.ai/tree/');
  expect(events[0][2].page_title).toBe('SCHUIT stamboom');
  expect(JSON.stringify(data)).not.toMatch(/I123|Private|individual|Schuit\/individual/);
  expect(data.find(x=>x[0]==='config')[2].send_page_view).toBe(false);
  expect(data.find(x=>x[0]==='consent')[2].ad_storage).toBe('denied');
  await consent(page,'Statistiekvoorkeuren');await consent(page,'Toestaan');expect(requests).toHaveLength(1);
});
test('withdrawal persists and deletes analytics cookies while keeping login cookies',async({page})=>{
  const requests=await fixture(page);await consent(page,'Toestaan');await expect.poll(()=>requests.length).toBe(1);
  await page.evaluate(()=>{document.cookie='_ga=test; Path=/; Secure';document.cookie='_ga_LB9BXB5TC4=test; Path=/; Secure';document.cookie='session=test; Path=/; Secure';});
  await consent(page,'Statistiekvoorkeuren');await consent(page,'Weigeren');await page.waitForLoadState();
  await page.addScriptTag({content:code});
  expect(requests).toHaveLength(1);
  const cookies=await page.context().cookies();expect(cookies.filter(c=>c.name.startsWith('_ga'))).toEqual([]);expect(cookies.some(c=>c.name==='session')).toBe(true);
});
test('admin, auth, contact, queries and unrelated hosts never initialise Analytics',async({browser})=>{
  for(const url of ['https://schuit.centipod.ai/wp-admin/','https://schuit.centipod.ai/wp-login.php','https://schuit.centipod.ai/tree/login/0','https://schuit.centipod.ai/contact/','https://schuit.centipod.ai/?s=Private','https://centipod.io/','https://evil.schuit.info/']) {
    const page=await browser.newPage();const requests=await fixture(page,url);
    expect(await page.locator('#schuit-analytics-choice').count()).toBe(0);expect(requests).toEqual([]);await page.close();
  }
});
test('expired consent asks again',async({page})=>{
  await page.addInitScript(key=>localStorage.setItem(key,JSON.stringify({value:'yes',until:1})),consentKey);
  const requests=await fixture(page);await expect(page.locator('#schuit-analytics-choice')).toBeVisible();expect(requests).toEqual([]);
});
test('valid saved consent works on the final public domain',async({page})=>{
  await page.addInitScript(key=>localStorage.setItem(key,JSON.stringify({value:'yes',until:Date.now()+100000})),consentKey);
  const requests=await fixture(page,'https://www.schuit.info/publicaties/');await expect.poll(()=>requests.length).toBe(1);
  const config=await page.evaluate(()=>Array.from(window.dataLayer.find(x=>x[0]==='config')));
  expect(config[2].page_location).toBe('https://www.schuit.info/publicaties/');expect(config[2].cookie_domain).toBe('www.schuit.info');
});
