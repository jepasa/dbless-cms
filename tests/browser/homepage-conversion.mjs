/** Chromium regression for editable native Homepages: real editor geometry, preserved public DOM, responsive source/export views and per-request dynamic integration using synthetic state. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import {pathToFileURL} from 'node:url';
import {applications,server,password,inspect} from '../helpers/extension-media.mjs';
if(!process.env.PLAYWRIGHT_MODULE)throw new Error('Set the authorized PLAYWRIGHT_MODULE.');
const {chromium}=await import(pathToFileURL(process.env.PLAYWRIGHT_MODULE));
const evidence=fs.mkdtempSync(path.join(os.tmpdir(),'dbless-homepage-conversion-')),results=[];
console.log('Browser evidence: '+evidence);
const browser=await chromium.launch({headless:true,executablePath:'/usr/bin/google-chrome',args:['--no-sandbox']});
try{
  for(const [index,application] of applications.entries()){
    const sandbox=await server(application),context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'}),page=await context.newPage(),publicPage=await context.newPage(),errors=[],directory=path.join(evidence,String(index));fs.mkdirSync(directory);
    /** Fail on horizontal overflow; receive visible page, return nothing and mutate no state. */
    async function overflow(target){await target.evaluate(async()=>{await document.fonts.ready;await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));});await target.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth+1,{},{timeout:2000});assert.ok(await target.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));}
    /** Read the semantic body of this template, preserving all IDs/classes/attributes except request-only CSRF values; receive page, return a stable tree without writes. */
    async function semantic(target){return target.evaluate(()=>{const root=document.querySelector('.page-wrapper')||document.querySelector('main#main');function walk(n){if(n.nodeType===3){const text=n.textContent.replace(/\s+/g,' ').trim();return text||null;}if(n.nodeType!==1)return null;const a=[...n.attributes].filter(x=>!(n.getAttribute('name')==='csrf_token'&&x.name==='value')).map(x=>[x.name,x.value]).sort((a,b)=>a[0].localeCompare(b[0]));return [n.tagName,a,[...n.childNodes].map(walk).filter(n=>n!==null)];}return walk(root);});}
    /** Capture the complete unchanged DOM at an identical animation tick; receive URL, return semantic tree, resume client time even on failure, without changing server time or skipping timers. */
    async function snapshot(url){
      await publicPage.clock.pauseAt(await publicPage.evaluate(()=>Date.now())+1000);
      try{await publicPage.goto(url);await publicPage.waitForLoadState('networkidle');await publicPage.clock.runFor(500);return await semantic(publicPage);}finally{await publicPage.clock.resume();}
    }
    try{
      const file=path.join(sandbox.contents,'site.json'),site=JSON.parse(fs.readFileSync(file));site.template=process.env.DBLESS_CMS_TEST_TEMPLATE||'essencial';site.settings.cache={enabled:true,development:true,ttl:86400};fs.writeFileSync(file,JSON.stringify(site));
      await context.route('**/*',r=>new URL(r.request().url()).origin===sandbox.base?r.continue():r.abort());
      for(const target of [page,publicPage]){target.on('pageerror',e=>errors.push(e.message));target.on('console',m=>{if(m.type()==='error')errors.push(m.text());});}
      await publicPage.clock.install();const before=await snapshot(sandbox.base+'/');
      await page.goto(sandbox.base+'/cms-admin/');await page.getByLabel('Username',{exact:true}).fill('editor');await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button').click();await page.locator('[data-user-menu]').waitFor();
      const target=sandbox.base+'/cms-admin/?view=plugin-panel&plugin=homepage&route=editor&id=legacy-home';
      await page.goto(target);await page.locator('[data-homepage-publication]').waitFor();assert.deepEqual(await page.locator('[data-homepage-mode] option').evaluateAll(options=>options.map(n=>n.value)),['html','source']);assert.equal(await page.locator('[data-homepage-mode]').inputValue(),'html');assert.ok((await page.locator('[data-markup-html]').inputValue()).includes('template:fragment'));
      const gaps=[];
      for(const width of [1440,390,320]){
        await page.setViewportSize({width,height:900});await overflow(page);
        const gap=await page.locator('[data-homepage-publication] .panel').first().evaluate(panel=>{const grid=panel.querySelector('.field-grid').getBoundingClientRect(),labels=[...panel.children].filter(n=>n.tagName==='LABEL');return [labels[0].getBoundingClientRect().top-grid.bottom,labels[1].getBoundingClientRect().top-labels[0].getBoundingClientRect().bottom];});assert.ok(gap.every(x=>x>=23),JSON.stringify({width,gap}));gaps.push({width,gap});await page.screenshot({path:path.join(directory,'editor-'+width+'.png'),fullPage:true});
        await publicPage.setViewportSize({width,height:900});await overflow(publicPage);await publicPage.screenshot({path:path.join(directory,'native-'+width+'.png'),fullPage:true});
      }
      assert.equal(fs.existsSync(path.join(sandbox.contents,'homepage/publications/catalog.json')),false);
      const migration=inspect(sandbox,"(new DBLESSCMS\\Plugins($s))->instance('homepage');$r=new DBLESSCMS\\HomepageCatalog($s);$r->convert($r->read()['revision'],$s->read('site')['revision'],$s->read('home')['revision']);echo 'true';");assert.equal(migration,true);
      await publicPage.setViewportSize({width:1440,height:1000});assert.deepEqual(await snapshot(sandbox.base+'/'),before);
      for(const width of [1440,390,320]){await publicPage.setViewportSize({width,height:900});await overflow(publicPage);await publicPage.screenshot({path:path.join(directory,'converted-'+width+'.png'),fullPage:true});}
      await page.setViewportSize({width:1440,height:1000});await page.goto(target);await page.locator('[data-homepage-publication]').waitFor();const editor=page.locator('[data-markup-html]');await editor.fill((await editor.inputValue())+'\n<section class="section"><p>Conversion editor regression</p></section>');await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Save draft',exact:true}).click()]);await publicPage.reload();assert.doesNotMatch(await publicPage.locator('body').innerText(),/Conversion editor regression/);await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Publish / Schedule',exact:true}).click()]);await publicPage.reload();assert.match(await publicPage.locator('body').innerText(),/Conversion editor regression/);
      assert.deepEqual(errors,[]);assert.doesNotMatch(sandbox.logs(),/PHP (?:Warning|Fatal|Parse)/);results.push({application,template:site.template,status:'passed',gaps,DOMPreserved:true,GETWrites:false,privateDraft:true,publication:true,cacheEnabled:true});
    }catch(e){await page.screenshot({path:path.join(directory,'failure.png'),fullPage:true});throw e;}finally{await context.close();await sandbox.close();}
  }
}finally{await browser.close();fs.writeFileSync(path.join(evidence,'results.json'),JSON.stringify(results,null,2)+'\n');}
console.log(JSON.stringify(results,null,2));
