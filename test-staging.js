/**
 * Staging site probe — checks if the plugin is installed, then runs searches.
 * Usage: node test-staging.js
 */
const { chromium } = require('playwright-chromium');
const fs = require('fs');
const path = require('path');

const STAGING = 'https://nfarmcoil2stg.wpenginepowered.com';
const OUT_DIR = path.join(__dirname, 'screenshots', 'staging');

async function main() {
  if (!fs.existsSync(OUT_DIR)) fs.mkdirSync(OUT_DIR, { recursive: true });

  const browser = await chromium.launch({ headless: true });

  // ---- Helper ----
  async function shot(page, name) {
    const p = path.join(OUT_DIR, `${name}.png`);
    await page.screenshot({ path: p, fullPage: false });
    console.log(`  Screenshot: ${p}`);
    return p;
  }

  // ---- 1. Homepage probe ----
  console.log('\n1. Loading homepage...');
  const page = await browser.newPage();
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.setExtraHTTPHeaders({ 'Accept-Language': 'he-IL,he;q=0.9' });
  try {
    await page.goto(STAGING, { waitUntil: 'domcontentloaded', timeout: 30000 });
    console.log(`   Title: ${await page.title()}`);
    console.log(`   URL: ${page.url()}`);
    await shot(page, '01-homepage-desktop');
  } catch (e) {
    console.error('   Homepage failed:', e.message);
  }

  // ---- 2. Check if plugin is installed (look for ASC-specific markup) ----
  console.log('\n2. Checking for plugin presence...');
  const pluginPresent = await page.evaluate(() => {
    return !!(
      document.querySelector('.asc-trigger') ||
      document.querySelector('[class*="asc-"]') ||
      document.querySelector('#asc-drawer') ||
      document.querySelector('script[src*="asc"]') ||
      document.querySelector('link[href*="asc"]')
    );
  });
  console.log(`   Plugin markup found: ${pluginPresent}`);

  // Also check for search input
  const searchEl = await page.$('input[type="search"], .search-field, #woocommerce-product-search-field-0, input[name="s"]');
  console.log(`   Search input found: ${!!searchEl}`);

  // Check for ASC assets in page source
  const pageContent = await page.content();
  const hasAscScript = pageContent.includes('asc-') || pageContent.includes('ai-smart-commerce');
  console.log(`   ASC in page source: ${hasAscScript}`);

  // ---- 3. Try shop page ----
  console.log('\n3. Loading shop page...');
  try {
    await page.goto(`${STAGING}/shop`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    console.log(`   Shop title: ${await page.title()}`);
    await shot(page, '02-shop-desktop');

    // Check plugin there too
    const shopPlugin = await page.evaluate(() => {
      return {
        ascTrigger: !!document.querySelector('.asc-trigger'),
        ascDrawer: !!document.querySelector('.asc-drawer'),
        anyAsc: document.querySelectorAll('[class*="asc-"]').length,
        scripts: Array.from(document.querySelectorAll('script[src]')).map(s => s.src).filter(s => s.includes('asc')),
        links: Array.from(document.querySelectorAll('link[href]')).map(l => l.href).filter(h => h.includes('asc')),
      };
    });
    console.log('   Shop plugin check:', JSON.stringify(shopPlugin, null, 2));
  } catch (e) {
    console.error('   Shop page failed:', e.message);
  }

  // ---- 4. Try the search endpoint directly ----
  console.log('\n4. Testing WC Store API...');
  try {
    const apiPage = await browser.newPage();
    await apiPage.goto(`${STAGING}/wp-json/wc/store/v1/products?search=%D7%92%D7%91%D7%99%D7%A0%D7%94&per_page=4`, {
      waitUntil: 'domcontentloaded', timeout: 15000
    });
    const apiText = await apiPage.textContent('body');
    try {
      const products = JSON.parse(apiText);
      console.log(`   WC API products for "גבינה": ${Array.isArray(products) ? products.length : 'not array'}`);
      if (Array.isArray(products) && products.length > 0) {
        products.slice(0, 3).forEach(p => {
          console.log(`     - ${p.name}: price_html=${p.prices?.price_html || p.price_html || '?'}, add_to_cart_text=${p.add_to_cart?.text || '?'}`);
        });
      }
    } catch (parseErr) {
      console.log('   WC API response (first 500):', apiText.substring(0, 500));
    }
    await apiPage.close();
  } catch (e) {
    console.error('   WC API failed:', e.message);
  }

  // ---- 5. Check WP Admin login page (to understand auth requirements) ----
  console.log('\n5. Checking WP admin...');
  try {
    const adminPage = await browser.newPage();
    await adminPage.goto(`${STAGING}/wp-admin/`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    const adminTitle = await adminPage.title();
    console.log(`   WP Admin title: ${adminTitle}`);
    const isLoginPage = adminTitle.toLowerCase().includes('log in') || adminTitle.toLowerCase().includes('login');
    console.log(`   Is login page: ${isLoginPage}`);
    await adminPage.screenshot({ path: path.join(OUT_DIR, '03-wp-admin.png') });
    await adminPage.close();
  } catch (e) {
    console.error('   WP Admin check failed:', e.message);
  }

  await browser.close();
  console.log('\nDone. Screenshots in:', OUT_DIR);
}

main().catch(err => {
  console.error('Fatal:', err);
  process.exit(1);
});
