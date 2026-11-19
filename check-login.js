const { chromium } = require('playwright-chromium');
const path = require('path');

(async () => {
  const b = await chromium.launch({ headless: true });
  const p = await b.newPage();

  await p.goto('https://nfarmcoil2stg.wpenginepowered.com/wp-login.php', { waitUntil: 'domcontentloaded', timeout: 15000 });
  console.log('wp-login title:', await p.title());
  console.log('url:', p.url());

  const hasUser = await p.$('input[name="log"]');
  console.log('has user input:', !!hasUser);

  await p.screenshot({ path: path.join(__dirname, 'screenshots', 'staging', 'wp-login-check.png') });
  await b.close();
})().catch(e => {
  console.error('Error:', e.message);
  process.exit(1);
});
