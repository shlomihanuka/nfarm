/**
 * Probe for WP login URL by looking at page source for hints
 */
const { chromium } = require('playwright-chromium');
const path = require('path');
const fs = require('fs');

(async () => {
  const b = await chromium.launch({ headless: true });
  const p = await b.newPage();
  const OUT = path.join(__dirname, 'screenshots', 'staging');

  // Load homepage and look for login links
  await p.goto('https://nfarmcoil2stg.wpenginepowered.com/', { waitUntil: 'domcontentloaded', timeout: 20000 });

  const loginLinks = await p.evaluate(() => {
    const links = Array.from(document.querySelectorAll('a[href]'))
      .map(a => a.href)
      .filter(h => h.includes('login') || h.includes('admin') || h.includes('wp-') || h.includes('account') || h.includes('sign'));
    return links.slice(0, 20);
  });
  console.log('Login-related links on homepage:', loginLinks);

  // Check page source for wp-login hints
  const content = await p.content();
  const wpLoginMatch = content.match(/href="([^"]*login[^"]*)"/gi);
  console.log('wp-login href matches:', wpLoginMatch ? wpLoginMatch.slice(0, 5) : 'none');

  // Check for WP REST API root
  const restPage = await b.newPage();
  await restPage.goto('https://nfarmcoil2stg.wpenginepowered.com/wp-json/', { waitUntil: 'domcontentloaded', timeout: 15000 });
  const restText = await restPage.textContent('body').catch(() => '');
  if (restText.length > 10) {
    try {
      const restJson = JSON.parse(restText);
      console.log('WP REST API available:', !!restJson.name);
      console.log('Site name:', restJson.name);
      console.log('Authentication routes:', Object.keys(restJson.authentication || {}));
      console.log('Namespaces:', (restJson.namespaces || []).filter(n => n.includes('wp/') || n.includes('asc')));
    } catch (e) {
      console.log('REST API response (first 300):', restText.substring(0, 300));
    }
  }
  await restPage.close();

  // Try WP Application Passwords endpoint
  const appPassPage = await b.newPage();
  await appPassPage.goto('https://nfarmcoil2stg.wpenginepowered.com/wp-json/wp/v2/users/me', { waitUntil: 'domcontentloaded', timeout: 10000 });
  const usersText = await appPassPage.textContent('body').catch(() => '');
  console.log('WP /users/me (unauthenticated):', usersText.substring(0, 200));
  await appPassPage.close();

  // Check for plugin REST endpoint (might be active from a prior install)
  const ascPage = await b.newPage();
  await ascPage.goto('https://nfarmcoil2stg.wpenginepowered.com/wp-json/asc/v1/health', { waitUntil: 'domcontentloaded', timeout: 10000 });
  const ascText = await ascPage.textContent('body').catch(() => '');
  console.log('ASC /health endpoint:', ascText.substring(0, 300));
  await ascPage.close();

  await b.close();
})().catch(e => {
  console.error('Error:', e.message);
  process.exit(1);
});
