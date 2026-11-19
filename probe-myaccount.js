/**
 * Check if WC My Account page has a login form we can use
 */
const { chromium } = require('playwright-chromium');
const path = require('path');

(async () => {
  const b = await chromium.launch({ headless: true });
  const p = await b.newPage();
  const OUT = path.join(__dirname, 'screenshots', 'staging');

  await p.goto('https://nfarmcoil2stg.wpenginepowered.com/my-account/', { waitUntil: 'domcontentloaded', timeout: 20000 });
  console.log('My Account title:', await p.title());
  console.log('URL:', p.url());

  const forms = await p.evaluate(() => {
    return Array.from(document.querySelectorAll('form')).map(f => ({
      id: f.id,
      action: f.action,
      inputs: Array.from(f.querySelectorAll('input')).map(i => ({ name: i.name, type: i.type }))
    }));
  });
  console.log('Forms:', JSON.stringify(forms, null, 2));

  await p.screenshot({ path: path.join(OUT, 'my-account-page.png') });

  // Also try SSH to WP Engine gateway
  await b.close();
})().catch(e => {
  console.error('Error:', e.message);
  process.exit(1);
});
