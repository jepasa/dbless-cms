/** Focused human workflows for Pages/Blog editorial parity, using synthetic accounts/content and actual clicks, typing, saving and public navigation. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {browserApplications,server,password} from '../helpers/extension-media.mjs';
import {revealField} from './helpers/editorial-pages.mjs';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const evidence=process.env.DBLESS_CMS_FIELDS_EVIDENCE||'/tmp/dbless-editorial-fields';fs.mkdirSync(evidence,{recursive:true});
const browser=await chromium.launch({executablePath:'/usr/bin/google-chrome',headless:true,args:['--no-sandbox']});
/** Submit a visible button and await the resulting document; receives page/button, throws on validation/navigation errors, with no API shortcuts. */
async function save(page,button){await Promise.all([page.waitForNavigation(),button.click()]);}
try{
  for(const [index,application] of browserApplications.entries()){
    const sandbox=await server(application),context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'}),page=await context.newPage(),errors=[];
    page.on('pageerror',error=>errors.push(error.message));page.on('console',message=>{if(message.type()==='error')errors.push(message.text());});
    await context.route('**/*',route=>new URL(route.request().url()).origin===sandbox.base?route.continue():route.abort());
    try{
      const file=path.join(sandbox.contents,'plugins.json'),registry=JSON.parse(fs.readFileSync(file));registry.plugins.posts.settings.discussion.enabled=true;fs.writeFileSync(file,JSON.stringify(registry));
      await page.goto(sandbox.base+'/cms-admin/');await page.getByLabel('Username',{exact:true}).fill('editor');await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.locator('[data-dashboard]').waitFor();
      await page.goto(sandbox.base+'/cms-admin/?view=plugin-panel&plugin=pages&route=new');
      await page.locator('[name="title"]').fill('Editorial parity example');const slug=page.locator('[name="slug"]').first();await revealField(slug);await slug.fill('editorial-parity');
      const author=page.getByLabel('Editorial author',{exact:true});await revealField(author);await author.fill('Example Editorial Team');await page.getByLabel('Pending review',{exact:true}).check();
      const order=page.getByLabel('Order',{exact:true});await revealField(order);await order.fill('-3');const layout=page.getByLabel('Page template',{exact:true});assert.ok(await layout.locator('option').count()>0,await layout.evaluate(n=>n.outerHTML));await layout.selectOption('service-details');
      await save(page,page.getByRole('button',{name:'Save draft',exact:true}));assert.equal(await page.locator('[data-page-work-state]').innerText(),'Pending review');const id=new URL(page.url()).searchParams.get('key');
      await revealField(author);assert.equal(await author.inputValue(),'Example Editorial Team');assert.equal(await page.getByLabel('Pending review',{exact:true}).isChecked(),true);await revealField(order);assert.equal(await order.inputValue(),'-3');assert.equal(await page.getByLabel('Page template',{exact:true}).inputValue(),'service-details');
      // The same visible date control supports explicit backdating and scheduling; no duplicate date fields.
      const date=page.getByLabel('Publish at (UTC)',{exact:true});await revealField(date);await date.fill('2020-01-01T12:30');await save(page,page.getByRole('button',{name:'Publish',exact:true}));assert.equal(await page.locator('[data-page-work-state]').innerText(),'Published');await revealField(date);assert.equal(await date.inputValue(),'2020-01-01T12:30');
      const reader=await context.newPage();await reader.goto(sandbox.base+'/editorial-parity');assert.match(await reader.title(),/Editorial parity example/);
      await date.fill('2099-01-01T12:30');await save(page,page.getByRole('button',{name:'Schedule',exact:true}));assert.equal(await page.locator('[data-page-work-state]').innerText(),'Scheduled');
      await page.screenshot({path:path.join(evidence,`${index}-pages-desktop.png`)});
      // Blog keeps its actual author/taxonomy/featured controls; pending review is a real nonpublic state.
      await page.goto(sandbox.base+'/cms-admin/?view=plugin-panel&plugin=blog&route=overview');await page.locator('[data-article-id="designing-with-clarity"]').getByRole('link',{name:'Edit',exact:true}).click();
      const articleForm=page.locator('[data-blog-editor]');await articleForm.getByLabel('Status',{exact:true}).selectOption('pending');await articleForm.getByLabel('Allow comments on this article',{exact:true}).uncheck();assert.ok(await articleForm.getByLabel('Template',{exact:true}).inputValue());await save(page,articleForm.getByRole('button',{name:'Save',exact:true}));
      assert.equal(await articleForm.getByLabel('Status',{exact:true}).inputValue(),'pending');assert.equal(await articleForm.getByLabel('Allow comments on this article',{exact:true}).isChecked(),false);
      const response=await reader.goto(sandbox.base+'/plugins/blog/article?slug=designing-with-clarity');assert.equal(response.status(),404);
      await articleForm.getByLabel('Status',{exact:true}).selectOption('published');await save(page,articleForm.getByRole('button',{name:'Save',exact:true}));await reader.goto(sandbox.base+'/plugins/blog/article?slug=designing-with-clarity');assert.equal(await reader.locator('.blog-comment-form').count(),0);assert.match(await reader.locator('body').innerText(),/Comments are closed/);
      await page.setViewportSize({width:390,height:844});await articleForm.getByLabel('Allow comments on this article',{exact:true}).scrollIntoViewIfNeeded();assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await page.screenshot({path:path.join(evidence,`${index}-blog-mobile.png`)});
      await page.goto(sandbox.base+'/cms-admin/?view=plugin-panel&plugin=pages&route=editor&key='+id);await revealField(page.getByLabel('Editorial author',{exact:true}));assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await page.screenshot({path:path.join(evidence,`${index}-pages-mobile.png`)});
      assert.deepEqual(errors,[]);console.log(JSON.stringify({application,pages:'author/order/review/layout/backdate/schedule',blog:'review/discussion',desktop:1440,mobile:390,evidence}));await reader.close();
    }finally{await context.close();await sandbox.close();}
  }
}finally{await browser.close();}
