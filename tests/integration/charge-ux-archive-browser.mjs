// Real WordPress forms, synthetic identities and intercepted mail. Never live.
import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {mkdir} from 'node:fs/promises';
const base='http://localhost:8088';const fixture=JSON.parse(process.env.MGF_BROWSER_FIXTURE||'{}');
assert.ok(fixture.portal_url?.startsWith(base+'/'),'Disposable local fixture required');
const{chromium}=await import(pathToFileURL(process.env.MGF_BROWSER_MODULE).href);
const browser=await chromium.launch();await mkdir('browser-artifacts',{recursive:true});
try{
 const p=await browser.newPage({viewport:{width:1280,height:1000}});const errors=[];p.on('pageerror',e=>errors.push(e.message));
 await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill('admin');await p.locator('#user_pass').fill('integration-only');await Promise.all([p.waitForNavigation(),p.locator('#wp-submit').click()]);
 await p.goto(base+'/wp-admin/admin.php?page=mathlin-additional-charges&view=create&related_ref='+fixture.booking_ref);
 await p.locator('[name=description]').fill('Synthetic cleaning UX');await p.locator('[name=amount]').fill('25.00');await p.getByRole('button',{name:'Save and continue to review',exact:true}).click();await p.getByRole('link',{name:'Back to edit draft'}).click();await p.locator('[name=amount]').fill('99.00');assert.equal(await p.getByRole('button',{name:'Issue and send invoice',exact:true}).count(),0);await p.getByRole('button',{name:'Save and review invoice',exact:true}).click();
 assert.ok((await p.locator('.mbs-charge-review').innerText()).includes('£99.00'));
 await p.getByText('Preview customer email',{exact:true}).click();await p.frameLocator('iframe').getByText('Synthetic cleaning UX',{exact:false}).waitFor();
 for(const width of [320,390,768,1280]){await p.setViewportSize({width,height:1000});await p.locator('iframe').scrollIntoViewIfNeeded();await p.screenshot({path:'browser-artifacts/charge-preview-'+width+'.png'});assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Review fits '+width);await p.screenshot({path:'browser-artifacts/charge-review-'+width+'.png',fullPage:true});}
 await p.locator('[name=review_confirm]').check();await p.getByRole('button',{name:'Issue and send invoice',exact:true}).click();assert.ok((await p.locator('body').innerText()).includes('£99.00'));await p.getByText('Cancel this charge',{exact:true}).click();assert.equal(await p.locator('[name=notify_customer]').isChecked(),false);await p.locator('[name=notify_customer]').check();await p.locator('[name=reason]').filter({visible:true}).first().fill('Synthetic cancellation');await p.getByRole('button',{name:'Cancel charge — issue full credit note',exact:true}).click();await p.getByText('Additional charge cancelled. Nothing to pay.',{exact:true}).waitFor();assert.equal(await p.getByRole('button',{name:'Confirm and send invoice now',exact:true}).count(),0);assert.equal(await p.getByText('Overdue reminder preference',{exact:true}).count(),0);await p.screenshot({path:'browser-artifacts/charge-cancelled.png',fullPage:true});
 const pdf=p.getByRole('link',{name:'Download credit PDF',exact:true});const response=await p.request.get(await pdf.getAttribute('href'));assert.equal(response.status(),200);assert.equal((await response.body()).subarray(0,5).toString(),'%PDF-');
 await p.goto(base+'/wp-admin/admin.php?page=mathlin-archived');assert.equal(await p.locator('tbody tr').count(),50);await p.getByRole('link',{name:'Next',exact:true}).first().click();await p.getByRole('link',{name:'View',exact:true}).first().click();await p.getByRole('link',{name:'Back to archived results'}).click();assert.ok(p.url().includes('archive_page=2'));
 for(const width of [320,390,768,1280]){await p.setViewportSize({width,height:1000});assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Archive fits '+width);await p.screenshot({path:'browser-artifacts/archive-'+width+'.png'});}
 await p.locator('[name=archive_range]').selectOption('custom');await p.locator('[name=date_from]').fill('2021-01-01');await p.locator('[name=date_to]').fill('2021-01-02');await p.getByRole('button',{name:'Apply filters',exact:true}).click();assert.ok((await p.locator('.mbs-archive-pager').first().innerText()).includes('1 of 1 bookings'));assert.ok(!p.url().includes('archive_page=2'));
 assert.deepEqual(errors,[]);console.log('CHARGE_UX_ARCHIVE_BROWSER_OK: edit/review/issue/send/cancel/credit PDF; paging/return/filter; four viewport sizes');
}finally{await browser.close()}
