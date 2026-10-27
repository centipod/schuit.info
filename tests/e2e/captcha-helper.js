// Mock Google's UI only on synthetic CI pages; the PHP verifier is still exercised.
async function captcha(page, autoSolve = true) {
  await page.route('https://www.google.com/recaptcha/api.js?hl=nl', route => route.fulfill({
    contentType: 'application/javascript',
    body: `document.querySelectorAll('.g-recaptcha').forEach(node => {
      const token = document.createElement('textarea'); token.name='g-recaptcha-response'; token.hidden=true;
      token.value=${JSON.stringify(autoSolve ? 'ci-solved-token' : '')}; node.append(token);
      const button=document.createElement('button'); button.type='button'; button.textContent='Ik ben geen robot (CI)';
      button.onclick=()=>{token.value='ci-solved-token';}; node.append(button);
    });`,
  }));
}
module.exports = { captcha };
