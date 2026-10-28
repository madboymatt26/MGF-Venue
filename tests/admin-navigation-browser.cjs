/* Local disposable WordPress only. Never submits a booking or changes settings. */
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const base = process.env.MBS_TEST_URL || 'http://127.0.0.1:8879';
assert(['127.0.0.1', 'localhost'].includes(new URL(base).hostname), 'Localhost required');
assert(process.env.MBS_TEST_PASSWORD, 'Disposable password required');
(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.MBS_TEST_BROWSER_CHANNEL || undefined });
  try {
    const page = await browser.newPage({ viewport: { width: 1366, height: 960 } });
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill(process.env.MBS_TEST_USER || 'admin');
    await page.locator('#user_pass').fill(process.env.MBS_TEST_PASSWORD);
    await Promise.all([page.waitForURL('**/wp-admin/**'), page.locator('#wp-submit').click()]);
    const sidebar = '#toplevel_page_mathlin-booking .wp-submenu';
    const paid = process.env.MBS_TEST_PAID !== '0';
    const routes = ['mathlin-booking', 'mathlin-add-booking', 'mathlin-calendar', 'mathlin-requests', 'mathlin-analytics', 'mathlin-settings', 'mathlin-guide'];
    for (const slug of routes) {
      const response = await page.goto(base + '/wp-admin/admin.php?page=' + slug);
      assert.equal(response.status(), 200, slug);
      assert(!/Cannot load|not allowed to access this page|critical error on this website/i.test(await page.locator('body').innerText()), slug);
      const labels = await page.locator(sidebar + ' > li:visible > a').allTextContents();
      assert.deepEqual(labels.map(s => s.trim().replace(/\s+\d+$/, '')), ['Bookings', 'Add booking', 'Calendar', 'Requests', paid ? 'Finance' : 'Reports', 'Settings', 'Help & health']);
    }
    for (const slug of ['mathlin-series', 'mathlin-archived', 'mathlin-blocked', 'mathlin-emails', 'mathlin-custom-fields', 'mathlin-google-calendar', 'mathlin-audit-log', 'mathlin-health', ...(paid ? ['mathlin-additional-charges', 'mathlin-osm'] : [])]) {
      const response = await page.goto(base + '/wp-admin/admin.php?page=' + slug);
      assert.equal(response.status(), 200, slug);
      assert(!/Cannot load|not allowed to access this page|critical error on this website/i.test(await page.locator('body').innerText()), slug);
      assert.equal(await page.locator('.mbs-section-navigation [aria-current="page"]').count(), 1, slug);
    }
    await page.goto(base + '/wp-admin/admin.php?page=mathlin-settings');
    assert.equal(await page.locator(sidebar + ' li.current > a').innerText(), 'Settings');
    const tabs = page.locator('.mbs-section-navigation');
    await tabs.getByRole('link', { name: 'General', exact: true }).focus();
    await page.keyboard.press('Tab');
    assert.equal(await page.locator(':focus').innerText(), 'Email templates');
    if (process.env.MBS_SCREENSHOT_DIR) await page.screenshot({ path: path.join(process.env.MBS_SCREENSHOT_DIR, paid ? 'navigation-paid.png' : 'navigation-free.png') });
    await page.setViewportSize({ width: 782, height: 960 });
    assert(await tabs.evaluate(el => el.scrollWidth <= el.clientWidth), 'Section tabs must wrap without horizontal overflow');
    console.log('ADMIN_NAVIGATION_BROWSER_OK: seven sidebar links, original routes, active section, keyboard and responsive tabs; ' + (paid ? 'paid' : 'free'));
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
