// Rendering only: synthetic local HTML, no login, mail, payment or external requests.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const assert=require('node:assert/strict');const path=require('node:path');const{pathToFileURL}=require('node:url');
assert(process.env.MBS_EMAIL_PREVIEW_DIR,'Synthetic preview directory required');
(async()=>{const browser=await chromium.launch({headless:true,channel:process.env.MBS_TEST_BROWSER_CHANNEL||'chrome'});let checks=0;
try{for(const kind of ['warning','cancel','admin'])for(const width of [600,390]){
 const page=await browser.newPage({viewport:{width,height:1000}});await page.route('**/*',route=>route.request().url().startsWith('file:')?route.continue():route.abort());
 await page.goto(pathToFileURL(path.join(process.env.MBS_EMAIL_PREVIEW_DIR,'expiry-'+kind+'.html')).href);
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No horizontal overflow '+kind+' '+width);checks++;
 assert((await page.locator('body').innerText()).includes('Registered Charity No. 1234567'),'Branded footer');checks++;
 assert(await page.locator('h1').evaluate(el=>getComputedStyle(el.parentElement).backgroundColor)==='rgb(116, 19, 220)','Existing purple header');checks++;
 assert(await page.getByRole('heading',{name:'Payment record'}).count()===1,'Payment panel');checks++;
 if(kind==='cancel'){assert(await page.getByRole('link',{name:'Sign in to your booking account'}).count()===0,'No cancelled checkout action');checks++;}
 else{const link=page.getByRole('link',{name:kind==='admin'?'Review booking and payment':'Sign in to your booking account'});await link.focus();assert(await link.evaluate(el=>el===document.activeElement),'Keyboard action reachable');checks++;}
 await page.screenshot({path:path.join(process.env.MBS_EMAIL_PREVIEW_DIR,'expiry-'+kind+'-'+width+'.png'),fullPage:true});await page.close();
}console.log('EXPIRY_EMAIL_PREVIEW_BROWSER_OK: '+checks+' checks');}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
