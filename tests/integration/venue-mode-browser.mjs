// Disposable host and synthetic records only. No live site or heating calls.
// Requires venue-mode-browser-seed.php: venue mode, payments on, policy fixture.
import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {mkdir} from 'node:fs/promises';
const base=process.env.MGF_VENUE_BROWSER_BASE||'http://localhost:8088';
assert.match(base,/^http:\/\/(localhost|127\.0\.0\.1):\d+$/);
const {chromium}=await import(pathToFileURL(process.env.MGF_BROWSER_MODULE).href);
// Deterministic: the seed enrols the policy with approved_at fixed at
// 2026-11-20 12:00 Europe/London, so the 48h deposit deadline formats as:
const expected={deposit_deadline:'22 November 2026 at 12:00 GMT'};
const browser=await chromium.launch({headless:true,...(process.env.MGF_BROWSER_CHANNEL?{channel:process.env.MGF_BROWSER_CHANNEL}:{})});
const context=await browser.newContext();const page=await context.newPage();
page.setDefaultTimeout(20000);const errors=[];page.on('pageerror',e=>errors.push(e.message));
await mkdir('browser-artifacts',{recursive:true});
const open=ref=>page.goto(base+'/wp-admin/admin.php?page=mathlin-booking&action=view&ref='+ref);
try{
 await page.goto(base+'/wp-login.php');
 await page.locator('#user_login').fill('admin');await page.locator('#user_pass').fill(process.env.MGF_VENUE_BROWSER_PASSWORD||'integration-only');
 await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);

 // Venue booking with money: billing chrome, payment actions and heating card.
 await open('VENUE-UI');
 assert.equal(await page.locator('a[href="#mbs-section-billing"]').count(),1,'Venue booking has the Billing jump link');
 assert.equal(await page.locator('#mbs-section-billing').count(),1,'Venue booking has the Billing & Extras section');
 assert.equal(await page.getByRole('button',{name:/Mark Fully Paid/}).count(),1,'Venue booking offers Mark Fully Paid');
 assert.equal(await page.getByRole('button',{name:/Mark Deposit Paid/}).count(),1,'Venue booking offers Mark Deposit Paid');
 assert.equal(await page.getByRole('link',{name:'View legacy invoice preview',exact:true}).count(),1,'Venue booking offers the invoice preview');
 assert.equal(await page.locator('#mbs-heating-heading').count(),1,'Venue booking shows the heating card');
 await page.screenshot({path:'browser-artifacts/venue-booking-1280.png',fullPage:true});
 await page.setViewportSize({width:390,height:844});
 await open('VENUE-UI');
 assert.equal(await page.locator('#mbs-section-billing').count(),1,'Mobile venue booking keeps the billing section');
 assert.equal(await page.locator('#mbs-heating-heading').count(),1,'Mobile venue booking keeps the heating card');
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,'Mobile venue booking has no horizontal overflow');
 await page.screenshot({path:'browser-artifacts/venue-booking-390.png',fullPage:true});
 await page.setViewportSize({width:1280,height:900});

 // Staff invoice preview prints the enforced policy deadline, not legacy terms.
 await page.goto(base+'/wp-admin/admin.php?page=mathlin-booking&action=invoice&ref=VENUE-UI');
 const body=await page.locator('body').innerText();
 assert.ok(body.includes(expected.deposit_deadline),'Invoice preview prints the enforced deposit deadline');
 assert.ok(!body.includes('Deposit due immediately'),'Invoice preview has no legacy immediate-deposit wording');
 await page.screenshot({path:'browser-artifacts/venue-invoice-preview-1280.png',fullPage:true});

 // Settings: HA card visible in venue mode.
 await page.goto(base+'/wp-admin/admin.php?page=mathlin-settings');
 const haCard=page.locator('.nms-card',{has:page.locator('h2',{hasText:'Home Assistant Integration'})});
 assert.equal(await haCard.count(),1,'HA settings card exists in the DOM');
 assert.equal(await haCard.isHidden(),false,'HA settings card is visible in venue mode');
 await page.screenshot({path:'browser-artifacts/venue-settings-1280.png',fullPage:true});

 assert.deepEqual(errors,[],'No browser JavaScript errors');
 console.log('VENUE_MODE_BROWSER_OK: billing/heating visible desktop and mobile, preview deadline, settings card, no JS errors');
}finally{await browser.close();}
