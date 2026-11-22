// Disposable host and synthetic records only. No live site or heating calls.
// Requires campsite-mode-browser-seed.php to have enabled campsite mode.
import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {mkdir} from 'node:fs/promises';
const base=process.env.MGF_CAMPSITE_BROWSER_BASE||'http://localhost:8088';
assert.match(base,/^http:\/\/(localhost|127\.0\.0\.1):\d+$/);
const {chromium}=await import(pathToFileURL(process.env.MGF_BROWSER_MODULE).href);
const browser=await chromium.launch({headless:true,...(process.env.MGF_BROWSER_CHANNEL?{channel:process.env.MGF_BROWSER_CHANNEL}:{})});
const context=await browser.newContext();const page=await context.newPage();
page.setDefaultTimeout(20000);const errors=[];page.on('pageerror',e=>errors.push(e.message));
await mkdir('browser-artifacts',{recursive:true});
const open=ref=>page.goto(base+'/wp-admin/admin.php?page=mathlin-booking&action=view&ref='+ref);
try{
 await page.goto(base+'/wp-login.php');
 await page.locator('#user_login').fill('admin');await page.locator('#user_pass').fill(process.env.MGF_CAMPSITE_BROWSER_PASSWORD||'integration-only');
 await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);

 // Free campsite booking: no venue-only billing chrome, no heating card.
 await open('CMPS-UI-FREE');
 assert.equal(await page.locator('a[href="#mbs-section-billing"]').count(),0,'Free campsite booking has no Billing jump link');
 assert.equal(await page.locator('#mbs-section-billing').count(),0,'Free campsite booking has no Billing & Extras section');
 assert.equal(await page.getByText('Billing & Invoices',{exact:true}).count(),0,'Free campsite booking has no Billing & Invoices group');
 assert.equal(await page.locator('#mbs-heating-heading').count(),0,'Free campsite booking has no heating card');
 assert.equal(await page.getByRole('button',{name:'Cancel Booking',exact:true}).count(),1,'Workflow actions remain');
 await page.screenshot({path:'browser-artifacts/campsite-booking-free-1280.png',fullPage:true});
 await page.setViewportSize({width:390,height:844});
 await open('CMPS-UI-FREE');
 assert.equal(await page.locator('#mbs-section-billing').count(),0,'Mobile free campsite booking has no billing section');
 assert.equal(await page.locator('#mbs-heating-heading').count(),0,'Mobile free campsite booking has no heating card');
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,'Mobile free campsite booking has no horizontal overflow');
 await page.screenshot({path:'browser-artifacts/campsite-booking-free-390.png',fullPage:true});
 await page.setViewportSize({width:1280,height:900});

 // Campsite booking carrying money: billing chrome stays for the record,
 // but venue-only payment actions remain feature-gated off.
 await open('CMPS-UI-MONEY');
 assert.equal(await page.getByText('Billing & Invoices',{exact:true}).count(),1,'Campsite booking with money keeps the billing group');
 assert.equal(await page.getByRole('link',{name:'View legacy invoice preview',exact:true}).count(),1,'Campsite booking with money keeps its invoice preview');
 assert.equal(await page.getByRole('button',{name:/Mark Fully Paid/}).count(),0,'Mark Fully Paid stays gated off in campsite mode');
 assert.equal(await page.getByRole('button',{name:/Mark Deposit Paid/}).count(),0,'Mark Deposit Paid stays gated off in campsite mode');
 assert.equal(await page.getByRole('button',{name:'Chase Payment',exact:true}).count(),0,'Chase Payment stays gated off in campsite mode');
 assert.equal(await page.locator('#mbs-heating-heading').count(),0,'Campsite booking with money has no heating card');
 await page.screenshot({path:'browser-artifacts/campsite-booking-money-1280.png',fullPage:true});

 // Settings: HA card hidden in campsite mode, stored webhook input preserved.
 await page.goto(base+'/wp-admin/admin.php?page=mathlin-settings');
 const haCard=page.locator('.nms-card',{has:page.locator('h2',{hasText:'Home Assistant Integration'})});
 assert.equal(await haCard.count(),1,'HA settings card exists in the DOM');
 assert.equal(await haCard.isHidden(),true,'HA settings card is hidden in campsite mode');
 assert.equal(await page.locator('input[name="ha_webhook_url"]').count(),1,'Stored webhook input preserved while hidden');
 await page.screenshot({path:'browser-artifacts/campsite-settings-1280.png',fullPage:true});
 await page.setViewportSize({width:390,height:844});
 await page.goto(base+'/wp-admin/admin.php?page=mathlin-settings');
 assert.equal(await haCard.isHidden(),true,'HA settings card hidden on mobile too');
 await page.screenshot({path:'browser-artifacts/campsite-settings-390.png',fullPage:true});

 assert.deepEqual(errors,[],'No browser JavaScript errors');
 console.log('CAMPSITE_MODE_BROWSER_OK: free/money booking gating, desktop and mobile, settings hidden card, no JS errors');
}finally{await browser.close();}
