// Local POC E2E: no external website, real genealogy or database writes.
const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const { spawn, execFileSync } = require('node:child_process');
const path = require('node:path');

(async () => {
  const server = spawn('python3', [path.join(__dirname, 'run.py'), '--serve', '--port', '0']);
  let browser;
  try {
    const url = await new Promise((resolve, reject) => {
      const timeout = setTimeout(() => reject(Error('POC start timeout')), 10000);
      server.once('error', reject);
      server.stdout.on('data', data => {
        const match = data.toString().match(/http:\/\/127\.0\.0\.1:\d+\//);
        if (match) { clearTimeout(timeout); resolve(match[0]); }
      });
      server.once('exit', code => { clearTimeout(timeout); reject(Error(`POC exited: ${code}`)); });
    });
    const sample = execFileSync('python3', ['-c',
      "import sys; sys.path.insert(0,'tests/python'); from test_gedcom_poc import fixture,G7_FAMILY; sys.stdout.buffer.write(fixture('7.0',G7_FAMILY))"],
      {cwd: path.resolve(__dirname,'../..')});
    browser = await chromium.launch({headless:true,
      ...(process.env.POC_BROWSER_CHANNEL ? {channel:process.env.POC_BROWSER_CHANNEL} : {})});
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(url);
    assert.equal(await page.locator('html').getAttribute('lang'), 'nl');
    await page.selectOption('#format', 'gedcom');
    await page.locator('#file').setInputFiles({name:'synthetic-7.ged',mimeType:'application/octet-stream',buffer:sample});
    await page.getByRole('button',{name:'Bestand controleren'}).click();
    await page.getByText('Controle geslaagd — gereed voor verdere importtests',{exact:true}).waitFor();
    const report = JSON.parse(await page.locator('#report').textContent());
    assert.equal(report.before.counts.INDI,3);
    assert.equal(report.after.counts.NOTE,1);
    assert.equal(report.all_nonheader_fields_preserved,true);
    // Unsupported GEDCOM 7 information must be reported, never dropped.
    await page.locator('#file').setInputFiles({name:'unsupported.ged',mimeType:'application/octet-stream',
      buffer:Buffer.from(sample.toString().replace('1 SEX F','1 SEX F\n1 NO DEAT'))});
    await page.getByRole('button',{name:'Bestand controleren'}).click();
    await page.getByText('Verdere verwerking geblokkeerd',{exact:true}).waitFor();
    assert.match(await page.locator('#messages').textContent(),/unsupported_structure/);
    // Native Aldfaer path is available but clearly blocked pending a converter.
    await page.selectOption('#format','aldfaer');
    await page.locator('#file').setInputFiles({name:'synthetic.aldf',mimeType:'application/octet-stream',
      buffer:Buffer.concat([Buffer.from('00e8faad','hex'),Buffer.alloc(100)])});
    await page.getByRole('button',{name:'Bestand controleren'}).click();
    await page.locator('#messages').getByText(/Exporteer dit bestand in Aldfaer als GEDCOM/).waitFor();
    assert.equal(JSON.parse(await page.locator('#report').textContent()).format,'Aldfaer');
    // A misleading extension or explicit format choice does not bypass detection.
    await page.selectOption('#format','gedcom');
    await page.getByRole('button',{name:'Bestand controleren'}).click();
    await page.getByText(/Bestand afgewezen/).waitFor();
    const forbidden = await page.request.post(url+'inspect?format=gedcom',{data:sample});
    assert.equal(forbidden.status(),403);
    const unknown = await page.request.get(url+'anything');
    assert.equal(unknown.status(),404);
    assert.deepEqual(errors,[]);
    console.log('POC browser checks passed: Dutch UI, GEDCOM 7 conversion, unsupported-data block, Aldfaer block, format mismatch, CSRF protection, no page errors.');
  } finally {
    if (browser) await browser.close();
    server.kill('SIGTERM');
  }
})().catch(e => { console.error(e); process.exitCode=1; });
