const { chromium } = require('playwright-chromium');
const http = require('http');
const fs = require('fs');
const path = require('path');

(async () => {
  const html = fs.readFileSync(path.join(__dirname, 'demo.html'), 'utf8');
  const server = http.createServer((req, res) => {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(html);
  });
  await new Promise(r => server.listen(7789, '127.0.0.1', r));
  const BASE = 'http://127.0.0.1:7789/';

  const dir = path.join(__dirname, 'screenshots');
  if (!fs.existsSync(dir)) fs.mkdirSync(dir);

  const browser = await chromium.launch({ headless: true });

  async function capture(viewportW, viewportH, label) {
    const page = await browser.newPage();
    await page.setViewportSize({ width: viewportW, height: viewportH });
    await page.goto(BASE, { waitUntil: 'load' });
    await page.waitForSelector('.asc-drawer.asc-open', { timeout: 5000 });
    // Wait for Track B (~1400ms delay) + open offset (400ms) + margin
    await page.waitForTimeout(2400);

    const stats = await page.evaluate(() => ({
      connectors:  document.querySelectorAll('.asc-connector').length,
      guide:       !!document.querySelector('.asc-connector.asc-guide'),
      promoCards:  document.querySelectorAll('.asc-card.asc-promo').length,
      promoBadges: document.querySelectorAll('.asc-promo-badge').length,
      hasSubCards: document.querySelectorAll('.asc-card.asc-has-sub').length,
      cards:       document.querySelectorAll('.asc-card:not(.asc-skeleton)').length,
      bannerGone:  !document.querySelector('.asc-ai-message.asc-visible'),
      firstCardTop: (() => { const c = document.querySelector('.asc-card:not(.asc-skeleton)'); return c ? Math.round(c.getBoundingClientRect().top) : -1; })(),
      guideText:   (document.querySelector('.asc-connector.asc-guide') || {}).textContent || '',
      connectorTexts: Array.from(document.querySelectorAll('.asc-connector:not(.asc-guide)')).map(c => c.textContent.trim()),
    }));
    console.log(`[${label}] Stats:`, JSON.stringify(stats, null, 2));

    // Fail-fast checks
    if (!stats.guide)        console.warn(`  ⚠ No guiding sentence (.asc-connector.asc-guide)`);
    if (stats.connectors < 2) console.warn(`  ⚠ Only ${stats.connectors} connector(s) — expected ≥2 (guiding + between groups)`);
    if (stats.hasSubCards < 1) console.warn(`  ⚠ No substitute card (.asc-card.asc-has-sub)`);
    if (stats.promoBadges < 1) console.warn(`  ⚠ No promo badge (.asc-promo-badge)`);
    if (stats.firstCardTop > 300) console.warn(`  ⚠ First product too far down: ${stats.firstCardTop}px — connector may push product below fold`);

    return page;
  }

  // ---- 1. Desktop ----
  console.log('\n1. Desktop (1280×900)...');
  const desk = await capture(1280, 900, 'desktop');
  await desk.screenshot({ path: path.join(dir, 'r5-desktop.png') });
  console.log('   r5-desktop.png saved');

  // ---- 2. Desktop — scroll to see connectors between results ----
  console.log('\n2. Desktop scrolled (show connectors between cards)...');
  await desk.evaluate(() => { const r = document.querySelector('.asc-results'); if (r) r.scrollTop = 150; });
  await desk.waitForTimeout(100);
  await desk.screenshot({ path: path.join(dir, 'r5-desktop-scrolled.png') });
  console.log('   r5-desktop-scrolled.png saved');

  // ---- 3. Mobile ----
  console.log('\n3. Mobile (390×844)...');
  const mob = await capture(390, 844, 'mobile');
  await mob.screenshot({ path: path.join(dir, 'r5-mobile.png') });
  console.log('   r5-mobile.png saved');

  // ---- 4. Options modal (verify R4 not regressed) ----
  console.log('\n4. Options modal on desktop...');
  const optBtns = await desk.$$('.asc-options-btn');
  if (optBtns.length > 0) {
    await optBtns[0].click();
    await desk.waitForSelector('.asc-modal-overlay.asc-visible', { timeout: 2000 });
    await desk.waitForTimeout(250);
    await desk.screenshot({ path: path.join(dir, 'r5-options-modal.png') });
    console.log('   r5-options-modal.png saved');
    const mc = await desk.$('.asc-modal-close');
    if (mc) { await mc.click(); await desk.waitForTimeout(200); }
  } else {
    console.warn('   ⚠ No .asc-options-btn — R4 regression?');
  }

  await browser.close();
  server.close();
  console.log('\n✓ Done. Files:');
  fs.readdirSync(dir).filter(f => f.startsWith('r5')).forEach(f => console.log('  ', f));
})().catch(e => { console.error('FAILED:', e.message); process.exit(1); });
