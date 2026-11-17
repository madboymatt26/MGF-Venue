// Real HTTP/session tests against the disposable stack only, never a real invoice.
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {pathToFileURL} from 'node:url';
import {mkdir} from 'node:fs/promises';
const probe=mode=>execFileSync('docker',['compose','-f','tests/integration/docker-compose.yml','run','--rm','-T','cli','wp','eval-file','/workspace/tests/integration/scenarios/invoice-checkout-intent.php',mode,'--allow-root'],{encoding:'utf8'}).trim();
const fixture=JSON.parse(probe('seed'));
assert.ok(fixture.payment_url.startsWith('http://localhost:8088/'),'Refuse non-local fixture');
const {chromium}=await import(pathToFileURL(process.env.MGF_BROWSER_MODULE).href);
const browser=await chromium.launch({headless:true});
await mkdir('browser-artifacts',{recursive:true});
const form=page=>page.locator('.mbs-pay-confirm form').evaluate(el=>Object.fromEntries(new FormData(el)));
try{
 const scanner=await browser.newContext();
 const head=await scanner.request.head(fixture.payment_url,{maxRedirects:0});
 assert.equal(head.status(),200);assert.equal((await head.body()).length,0);probe('pristine');
 assert.equal((await scanner.request.get(fixture.payment_url,{maxRedirects:0})).status(),200);probe('pristine');
 const payer=await browser.newContext();const page=await payer.newPage();
 assert.equal((await page.goto(fixture.payment_url)).status(),200);
 await page.getByRole('button',{name:'Continue to secure checkout',exact:true}).waitFor();
 await page.screenshot({path:'browser-artifacts/invoice-payment-confirm.png',fullPage:true});
 const postUrl=await page.locator('.mbs-pay-confirm form').getAttribute('action');
 const fields=await form(page);
 assert.equal((await payer.request.post(postUrl,{form:{...fields,_mbs_invoice_nonce:'bad'},maxRedirects:0})).status(),403);
 assert.equal((await payer.request.post(postUrl,{form:{...fields,mbs_invoice_confirm:'0'},maxRedirects:0})).status(),403);
 assert.equal((await payer.request.put(fixture.payment_url,{maxRedirects:0})).status(),405);probe('pristine');
 await Promise.all([page.waitForURL(url=>!url.searchParams.has('mbs_invoice_pay')),page.getByRole('button',{name:'Continue to secure checkout',exact:true}).click()]);
 await page.getByText(fixture.invoice_ref,{exact:false}).first().waitFor();probe('active');
 const competitor=await browser.newContext();const other=await competitor.newPage();
 assert.equal((await other.goto(fixture.payment_url)).status(),200);probe('active');
 assert.equal((await competitor.request.post(postUrl,{form:await form(other),maxRedirects:0})).status(),409);probe('active');
 await page.goto(fixture.payment_url);
 assert.equal((await payer.request.post(postUrl,{form:await form(page),maxRedirects:0})).status(),303);probe('active');
 // Truly simultaneous deliberate submissions must still have one exclusive winner.
 probe('release');
 const a=await browser.newContext(),b=await browser.newContext();const ap=await a.newPage(),bp=await b.newPage();
 await Promise.all([ap.goto(fixture.payment_url),bp.goto(fixture.payment_url)]);
 const [af,bf]=await Promise.all([form(ap),form(bp)]);
 const race=await Promise.all([a.request.post(postUrl,{form:af,maxRedirects:0}),b.request.post(postUrl,{form:bf,maxRedirects:0})]);
 assert.deepEqual(race.map(r=>r.status()).sort(),[303,409]);probe('active');
 probe('release');probe('stale');
 assert.equal((await a.request.post(postUrl,{form:af,maxRedirects:0})).status(),403);probe('unchanged-release');
 console.log('INVOICE_INTENT_BROWSER_OK: HEAD/GET/invalid POST read-only; real click; same-session resume; competing session; concurrent POST; stale balance; booking/email isolation');
}finally{await browser.close();}
