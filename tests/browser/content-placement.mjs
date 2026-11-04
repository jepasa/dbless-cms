/** Human-style exclusive delivery journey on disposable fixtures: create, choose, save/reopen, publish and switch both plugins; desktop/mobile in both locales. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {browserApplications,server,password} from '../helpers/extension-media.mjs';
import {createDraft} from './helpers/editorial-pages.mjs';
import {installTemplate} from '../helpers/template-fixture.mjs';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const evidence=process.env.DBLESS_CMS_PLACEMENT_EVIDENCE||'/tmp/dbless-content-placement';fs.mkdirSync(evidence,{recursive:true});
const browser=await chromium.launch({executablePath:'/usr/bin/google-chrome',headless:true,args:['--no-sandbox']});
/** Save via the visible form button and await its redirect; receive page, throw on UI/persistence failure, no direct API mutation. */
async function save(page){await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Save changes',exact:true}).click()]);}
try{for(const [index,application] of browserApplications.entries()){
 const sandbox=await server(application),context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'}),page=await context.newPage(),errors=[];
 page.on('pageerror',error=>errors.push(error.message));page.on('dialog',dialog=>dialog.accept());
 await context.route('**/*',route=>new URL(route.request().url()).origin===sandbox.base?route.continue():route.abort());
 const editor=(plugin,id='')=>sandbox.base+`/cms-admin/?view=plugin-panel&plugin=${plugin}&route=editor`+(id?'&id='+id:'');
 try{
  await page.goto(sandbox.base+'/cms-admin/');await page.getByLabel('Username',{exact:true}).fill('editor');await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.locator('[data-dashboard]').waitFor();
  for(const plugin of ['slides','menu']){
   await page.goto(editor(plugin));await page.locator('[name="id"]').fill('delivery-'+plugin);await page.locator('[name="name"]').fill('Delivery '+plugin);
   const mode=page.getByLabel('Insertion method',{exact:true});assert.equal(await mode.inputValue(),'mustache');assert.equal(await page.locator('[data-template-positions]').isVisible(),false);assert.equal(await page.locator('[data-placement-page]').count(),0);
   if(plugin==='slides'){await page.locator('[name="status"]').selectOption('published');await page.getByRole('button',{name:'Add item',exact:true}).click();await page.locator('[data-item-field=title]').fill('Slide item');}
   await mode.selectOption('template');const position=page.locator('[data-placement-location="'+(plugin==='slides'?'home-hero':'header-primary')+'"]');await position.getByRole('checkbox').check();assert.match(await position.innerText(),/\{\{template\./);await save(page);assert.equal(await mode.inputValue(),'template');await page.reload();assert.equal(await mode.inputValue(),'template');
   await page.locator('[data-'+plugin+'-placement]').screenshot({path:path.join(evidence,`${index}-${plugin}-template-desktop.png`)});
  }
  await createDraft(page,sandbox.base,'Delivery channels','delivery-channels');await page.getByRole('tab',{name:'HTML/Mustache',exact:true}).click();const core=page.locator('[data-core-editor]').first();await core.locator('[data-editor-action="code"]').click();await core.locator('textarea').first().fill('<p id="direct-slides">{{slides.delivery-slides.name}}</p><p id="direct-menu">{{menu.delivery-menu.name}}</p><p id="slot-slides">{{template.slides-home-hero.name}}</p><p id="slot-menu">{{template.primary-menu.name}}</p><p id="missing">{{ slides.absent.name }}</p>{{^slides.absent.items}}<p id="alternative">Alternative</p>{{/slides.absent.items}}');
  for(const name of ['Save draft','Publish'])await Promise.all([page.waitForNavigation(),page.getByRole('button',{name,exact:true}).click()]);
  const reader=await context.newPage();await reader.goto(sandbox.base+'/delivery-channels');assert.equal(await reader.locator('#direct-slides').innerText(),'{{slides.delivery-slides.name}}');assert.equal(await reader.locator('#direct-menu').innerText(),'{{menu.delivery-menu.name}}');assert.equal(await reader.locator('#slot-slides').innerText(),'Delivery slides');assert.equal(await reader.locator('#slot-menu').innerText(),'Delivery menu');
  for(const plugin of ['slides','menu']){
   await page.goto(editor(plugin,'delivery-'+plugin));await page.getByLabel('Insertion method',{exact:true}).selectOption('mustache');assert.equal(await page.locator('[data-template-positions]').isVisible(),false);assert.ok(await page.locator('[data-placement-location]').evaluateAll(rows=>rows.every(row=>row.disabled)));await save(page);await page.reload();assert.equal(await page.getByLabel('Insertion method',{exact:true}).inputValue(),'mustache');
  }
  await reader.reload();assert.equal(await reader.locator('#direct-slides').innerText(),'Delivery slides');assert.equal(await reader.locator('#direct-menu').innerText(),'Delivery menu');assert.equal(await reader.locator('#slot-slides').innerText(),'{{template.slides-home-hero.name}}');assert.equal(await reader.locator('#slot-menu').innerText(),'{{template.primary-menu.name}}');assert.equal(await reader.locator('#missing').innerText(),'{{ slides.absent.name }}');await reader.locator('#alternative').waitFor();
  for(const plugin of ['slides','menu']){
   await page.goto(editor(plugin,'delivery-'+plugin));await page.getByLabel('Insertion method',{exact:true}).selectOption('template');const key=plugin==='slides'?'home-hero':'header-primary';assert.equal(await page.locator('[data-placement-location="'+key+'"] input[type=checkbox]').isChecked(),true);await save(page);
  }
  await reader.reload();assert.equal(await reader.locator('#slot-slides').innerText(),'Delivery slides');assert.equal(await reader.locator('#slot-menu').innerText(),'Delivery menu');
  const geometry=[];
  for(const locale of ['en-us','pt-br']){
   const siteFile=path.join(sandbox.contents,'site.json'),site=JSON.parse(fs.readFileSync(siteFile));site.settings.adminLocale=locale;fs.writeFileSync(siteFile,JSON.stringify(site));
   for(const plugin of ['slides','menu'])for(const width of [1440,390]){
    await page.setViewportSize({width,height:1000});await page.goto(editor(plugin,'delivery-'+plugin));assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));assert.equal(await page.locator('[data-placement-mode]').inputValue(),'template');await page.locator('[data-'+plugin+'-placement]').screenshot({path:path.join(evidence,`${index}-${plugin}-${locale}-${width}.png`)});geometry.push({plugin,locale,width});
   }
  }
  installTemplate(sandbox.app);await page.setViewportSize({width:1440,height:1000});await page.locator('[data-user-menu] summary').click();await page.locator('[data-user-menu]').getByRole('link',{name:'Templates',exact:true}).click();await Promise.all([page.waitForNavigation(),page.locator('[data-extension-id="synthetic-theme"]').getByRole('button',{name:'Ativar synthetic-theme',exact:true}).click()]);
  for(const plugin of ['slides','menu']){await page.goto(editor(plugin,'delivery-'+plugin));assert.match(await page.locator('[data-active-template]').innerText(),/synthetic-theme/);}
  assert.deepEqual(errors,[]);assert.doesNotMatch(sandbox.logs(),/PHP (?:Warning|Fatal|Parse)/);await reader.close();console.log(JSON.stringify({application,passed:true,checks:'both modes/create-save-reopen/public output/literal missing/inverted fallback/dynamic template/desktop-mobile/en-pt',geometry,evidence}));
 }finally{await context.close();await sandbox.close();}
}}finally{await browser.close();}
