// Disposable host and synthetic records only. No live website or heating calls.
import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {mkdir} from 'node:fs/promises';
const base=process.env.MGF_HEATING_BROWSER_BASE||'http://localhost:8088';
assert.match(base,/^http:\/\/(localhost|127\.0\.0\.1):\d+$/);
const {chromium}=await import(pathToFileURL(process.env.MGF_BROWSER_MODULE).href);
const browser=await chromium.launch({headless:true,...(process.env.MGF_BROWSER_CHANNEL?{channel:process.env.MGF_BROWSER_CHANNEL}:{})});
const context=await browser.newContext();const page=await context.newPage();
page.setDefaultTimeout(20000);const errors=[];page.on('pageerror',e=>errors.push(e.message));
await mkdir('browser-artifacts',{recursive:true});
try{
 await page.goto(base+'/wp-login.php');
 await page.locator('#user_login').fill('admin');await page.locator('#user_pass').fill(process.env.MGF_HEATING_BROWSER_PASSWORD||'integration-only');
 await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);
 const open=kind=>page.goto(base+'/wp-admin/admin.php?page=mathlin-booking&action=view&ref=HEAT-UI-'+kind);
 const save=async()=>{
   await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Save heating preference',exact:true}).click()]);
 };
 await open('ONEOFF');const choice=page.locator('#mbs-heating-choice');
 assert.equal(await choice.isChecked(),false,'One-off defaults off');
 await choice.focus();await page.keyboard.press('Space');
 await save();
 assert.equal(await choice.isChecked(),true,'Keyboard opt-in persisted');
 const stale=await context.newPage();await stale.goto(page.url());
 await choice.uncheck();await save();
 assert.equal(await choice.isChecked(),false,'Opt-out persisted');
 await stale.locator('#mbs-heating-choice').uncheck();await stale.getByRole('button',{name:'Save heating preference',exact:true}).click();
 await stale.getByRole('status').filter({hasText:'booking changed'}).waitFor();await stale.close();
 await page.setViewportSize({width:390,height:844});await open('ONEOFF');
 assert.equal(await page.locator('#mbs-booking-heating').evaluate(el=>el.scrollWidth<=el.clientWidth+1),true,'Heating controls fit narrow width');
 await page.screenshot({path:'browser-artifacts/heating-390.png',fullPage:true});
 await page.setViewportSize({width:1280,height:900});await open('ONEOFF');await page.screenshot({path:'browser-artifacts/heating-1280.png',fullPage:true});
 await open('SERIES');assert.equal(await page.locator('#mbs-heating-choice').count(),0,'Series has no opt-in');
 await page.getByText('Off — Scout series use their separately configured Home Assistant schedules.',{exact:false}).waitFor();
 await open('EXTERNAL');assert.equal(await page.locator('#mbs-heating-choice').count(),0,'External policy read-only');
 assert.deepEqual(errors,[],'No browser JavaScript errors');
 console.log('SCOUT_HEATING_BROWSER_OK: defaults, keyboard save, opt-out, stale CAS, mobile, series/external scope');
}finally{await browser.close();}
