/** Real Chromium usage of core Dashboard and cache global settings with synthetic data; verifies grouped plugin cards, desktop/mobile, two dictionaries, purge and session isolation. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {pathToFileURL} from 'node:url';
import {applications,server,password} from '../helpers/extension-media.mjs';
if(!process.env.PLAYWRIGHT_MODULE)throw new Error('Set PLAYWRIGHT_MODULE to the authorized local Playwright.');
const {chromium}=await import(pathToFileURL(process.env.PLAYWRIGHT_MODULE)),evidence=fs.mkdtempSync(path.join(os.tmpdir(),'dbless-cache-dashboard-browser-')),results=[];
console.log('Browser evidence: '+evidence);
/** Assert one semantic card per plugin with localized labels and two finite counts; receives page/locale, performs no mutations, throws on split, missing or duplicated metrics. */
async function assertPluginCards(page,locale='en-us'){
  const labels=locale==='pt-br'?{pages:['Páginas publicadas','Rascunhos de páginas'],blog:['Artigos publicados','Comentários aguardando moderação']}:{pages:['Published pages','Page drafts'],blog:['Published articles','Comments awaiting moderation']};
  for(const [id,names] of Object.entries(labels)){
    const card=page.locator('[data-dashboard-plugin="'+id+'"]');assert.equal(await card.count(),1);assert.ok(await card.isVisible());assert.equal(await card.locator('h2').count(),1);assert.deepEqual(await card.locator('dt').allTextContents(),names);
    const values=await card.locator('dd').allTextContents();assert.equal(values.length,2);assert.ok(values.every(value=>/^\d+$/.test(value)));
  }
}
const browser=await chromium.launch({headless:true,executablePath:'/usr/bin/google-chrome',args:['--no-sandbox']});
try{
  for(const [index,application] of applications.entries()){
    const sandbox=await server(application),context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'}),page=await context.newPage(),errors=[],directory=path.join(evidence,String(index));fs.mkdirSync(directory);
    await context.route('**/*',route=>{if(new URL(route.request().url()).origin===sandbox.base)return route.continue();errors.push('External traffic');return route.abort();});
    page.on('pageerror',error=>errors.push(error.message));page.on('console',message=>{if(message.type()==='error')errors.push(message.text());});
    try{
      await page.goto(sandbox.base+'/cms-admin/');await page.getByLabel('Username',{exact:true}).fill('editor');await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in',exact:true}).click();
      assert.equal(new URL(page.url()).searchParams.get('view'),'dashboard');await page.locator('[data-dashboard]').waitFor();assert.equal(await page.locator('.sidebar nav > a').first().innerText(),'Dashboard');assert.equal(await page.locator('.dashboard-link').evaluate(node=>getComputedStyle(node).position),'sticky');
      await assertPluginCards(page);assert.ok(await page.getByText('No data',{exact:true}).count()>=1);
      await page.goto(sandbox.base+'/cms-admin/?view=site');await page.locator('.global-card').first().waitFor();assert.deepEqual(await page.locator('.global-card > h2').allTextContents(),['Global','Identity','Contact','Cache']);
      // Optional cache fields removed or partially entered through JSON must recover typed defaults in Fields mode.
      await page.getByRole('button',{name:'JSON',exact:true}).click();let document=JSON.parse(await page.locator('[data-document]').inputValue());delete document.settings.cache;await page.locator('[data-document]').fill(JSON.stringify(document));await page.getByRole('button',{name:'Fields',exact:true}).click();assert.equal(await page.getByLabel('Entry lifetime (seconds)',{exact:true}).inputValue(),'300');
      await page.getByRole('button',{name:'JSON',exact:true}).click();document=JSON.parse(await page.locator('[data-document]').inputValue());document.settings.cache={enabled:true};await page.locator('[data-document]').fill(JSON.stringify(document));await page.getByRole('button',{name:'Fields',exact:true}).click();assert.equal(await page.getByLabel('Entry limit',{exact:true}).inputValue(),'500');
      await page.getByLabel('Enable public data caching',{exact:true}).check();await page.getByLabel('Allow caching in development',{exact:true}).check();await page.getByLabel('Entry lifetime (seconds)',{exact:true}).fill('120');await page.getByLabel('Entry limit',{exact:true}).fill('10');
      await page.screenshot({path:path.join(directory,'cache-settings-desktop.png'),fullPage:true});
      await page.getByRole('button',{name:'Save changes',exact:true}).click();await page.locator('.notice.success').waitFor();
      // A separate context creates real anonymous public reads without sharing the authenticated editor cookie.
      const publicContext=await browser.newContext();try{await publicContext.request.get(sandbox.base+'/');await publicContext.request.get(sandbox.base+'/');}finally{await publicContext.close();}
      await page.goto(sandbox.base+'/cms-admin/');await page.locator('[data-dashboard]').waitFor();assert.ok(await page.getByText('Enabled',{exact:true}).isVisible());assert.equal(await page.getByText('No data',{exact:true}).count(),0);await page.screenshot({path:path.join(directory,'dashboard-desktop.png'),fullPage:true});
      // Keyboard access to the permanent entry, then the existing responsive drawer at 390 and 320 pixels.
      await page.locator('.dashboard-link').focus();await page.keyboard.press('Enter');await page.waitForURL('**/?view=dashboard');assert.equal(new URL(page.url()).searchParams.get('view'),'dashboard');
      for(const width of [390,320]){await page.setViewportSize({width,height:844});await assertPluginCards(page);assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await page.screenshot({path:path.join(directory,'dashboard-'+width+'.png'),fullPage:true});await page.locator('[data-nav-toggle]').click();assert.ok(await page.locator('.dashboard-link').isVisible());await page.locator('.dashboard-link').click();await page.locator('[data-dashboard]').waitFor();}
      page.once('dialog',dialog=>dialog.accept());await page.getByRole('button',{name:'Clear cache',exact:true}).click();await page.getByText('Cache cleared and metrics reset.',{exact:true}).waitFor();assert.ok(await page.getByText('No data',{exact:true}).count()>=1);
      await page.setViewportSize({width:1440,height:1000});await page.goto(sandbox.base+'/cms-admin/?view=site');await page.getByLabel('Default administration language',{exact:true}).selectOption('pt-br');await page.getByRole('button',{name:'Save changes',exact:true}).click();await page.locator('.notice.success').waitFor();
      await page.goto(sandbox.base+'/cms-admin/');await page.getByRole('heading',{name:'Dashboard',exact:true}).waitFor();await assertPluginCards(page,'pt-br');assert.ok(await page.getByText('Sem dados',{exact:true}).count()>=1);await page.screenshot({path:path.join(directory,'dashboard-pt-br.png'),fullPage:true});
      for(const width of [390,320]){await page.setViewportSize({width,height:844});await assertPluginCards(page,'pt-br');assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await page.screenshot({path:path.join(directory,'dashboard-pt-br-'+width+'.png'),fullPage:true});}
      assert.equal(await page.locator('svg').count(),0);assert.deepEqual(errors,[]);assert.doesNotMatch(sandbox.logs(),/PHP (?:Warning|Fatal|Parse)/);results.push({application,status:'passed',viewports:[1440,390,320],locales:['en-us','pt-br'],defaultRoute:'dashboard',globalCards:4,pluginCards:{pages:2,blog:2},settingsSaved:true,realAnonymousHits:true,purgeResetMetrics:true,keyboard:true,inlineSVG:false,errors});
    }catch(error){await page.screenshot({path:path.join(directory,'failure.png'),fullPage:true});throw error;}finally{await context.close();await sandbox.close();}
  }
}finally{await browser.close();fs.writeFileSync(path.join(evidence,'results.json'),JSON.stringify(results,null,2)+'\n');}
console.log(JSON.stringify(results,null,2));
