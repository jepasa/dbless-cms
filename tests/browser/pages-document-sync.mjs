/** Human Pages synchronization journey: existing native content, visual/code/JSON edits, invalid-buffer recovery, draft/public isolation and reopen; fixtures only, no live content. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {browserApplications,server,password} from '../helpers/extension-media.mjs';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const evidence=process.env.DBLESS_CMS_SYNC_EVIDENCE||'/tmp/dbless-pages-document-sync';fs.mkdirSync(evidence,{recursive:true});
const browser=await chromium.launch({executablePath:'/usr/bin/google-chrome',headless:true,args:['--no-sandbox']});
/** Submit a real button and await the resulting navigation; receive page/button, propagates timeout/errors without forced clicks. */
async function save(page,button){await Promise.all([page.waitForNavigation(),button.click()]);}
try{for(const [index,application] of browserApplications.entries()){
  const sandbox=await server(application),context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'}),page=await context.newPage(),errors=[];
  page.on('pageerror',error=>errors.push(error.message));page.on('dialog',dialog=>dialog.accept());
  await context.route('**/*',route=>new URL(route.request().url()).origin===sandbox.base?route.continue():route.abort());
  try{
    await page.goto(sandbox.base+'/cms-admin/');await page.getByLabel('Username',{exact:true}).fill('editor');await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.locator('[data-dashboard]').waitFor();
    await page.goto(sandbox.base+'/cms-admin/?view=plugin-panel&plugin=pages&route=editor&key=exemplo-cms');
    const jsonTab=page.getByRole('tab',{name:'JSON',exact:true}),htmlTab=page.getByRole('tab',{name:'HTML/Mustache',exact:true}),json=page.locator('[data-page-document]'),html=page.locator('[data-markup-html]');
    const initial=JSON.parse(await json.inputValue());assert.ok(initial.content.sections.length>0);assert.match(initial.html,/Conheça o dbless-cms/);
    await htmlTab.click();await page.frameLocator('[data-editor-canvas]').getByText('Conheça o dbless-cms',{exact:true}).first().waitFor();
    // A normal visual edit must reach JSON, including a pending frame edit before tab switching.
    const paragraph=page.frameLocator('[data-editor-canvas]').getByRole('textbox',{name:'Editable content block'}).filter({hasText:'Crie páginas como rascunho'});await paragraph.click();await page.keyboard.press('End');await page.keyboard.type(' Visual synchronization.');await jsonTab.click();
    let value=JSON.parse(await json.inputValue());assert.match(value.html,/Visual synchronization/);assert.deepEqual(value.content,initial.content);
    value.html+='<p>JSON synchronization.</p>\n{{#blog.latest.limit.1.items}}<article><h2>{{title}}</h2></article>{{/blog.latest.limit.1.items}}';await json.fill(JSON.stringify(value,null,2));await htmlTab.click();assert.equal(await html.inputValue(),value.html);
    await page.locator('[data-editor-action="code"]').first().click();const code=page.locator('[data-core-editor] textarea').first();await code.fill(value.html+'\n<p>Code synchronization.</p>');await jsonTab.click();value=JSON.parse(await json.inputValue());assert.match(value.html,/Code synchronization/);
    // Invalid JSON must remain visible and must never blank HTML or reach persistence.
    const valid=await json.inputValue(),before=await html.inputValue();await json.fill('{invalid');await htmlTab.click();assert.equal(await json.isVisible(),true);assert.equal(await json.inputValue(),'{invalid');assert.equal(await html.inputValue(),before);await page.locator('[data-pages-status]').waitFor({state:'visible'});await json.fill(valid);
    await save(page,page.getByRole('button',{name:'Save draft',exact:true}));assert.equal(await html.inputValue(),value.html);assert.deepEqual(JSON.parse(await json.inputValue()).content,initial.content);
    const reader=await context.newPage();await reader.goto(sandbox.base+'/exemplo-cms');assert.doesNotMatch(await reader.locator('body').innerText(),/JSON synchronization/);
    await save(page,page.getByRole('button',{name:'Publish',exact:true}));assert.equal(await page.locator('[data-page-work-state]').innerText(),'Published');await reader.reload();assert.match(await reader.locator('body').innerText(),/JSON synchronization/);assert.match(await reader.locator('body').innerText(),/Code synchronization/);assert.match(await reader.locator('body').innerText(),/Designing with clarity/);assert.equal(await html.inputValue(),value.html);
    await jsonTab.click();assert.equal(JSON.parse(await json.inputValue()).html,value.html);await htmlTab.click();assert.equal(await page.locator('[data-page-work-state]').getAttribute('data-dirty'),null);await page.screenshot({path:path.join(evidence,`${index}-html-desktop.png`)});await jsonTab.click();await page.screenshot({path:path.join(evidence,`${index}-json-desktop.png`)});
    await page.setViewportSize({width:390,height:844});if(await page.locator('[data-editorial-properties]').isVisible())await page.locator('[data-editorial-properties-toggle]').click();assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await page.screenshot({path:path.join(evidence,`${index}-json-mobile.png`)});
    assert.deepEqual(errors,[]);assert.doesNotMatch(sandbox.logs(),/PHP (?:Warning|Fatal|Parse)/);await reader.close();console.log(JSON.stringify({application,passed:true,checks:'native/visual/code/JSON/invalid/save/reopen/publish/mobile',evidence}));
  }catch(error){await page.screenshot({path:path.join(evidence,`${index}-failure.png`)});throw error;}finally{await context.close();await sandbox.close();}
}}finally{await browser.close();}
