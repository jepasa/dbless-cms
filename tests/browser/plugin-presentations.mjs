/** Human-style tutorial navigation in isolated source/package copies: menus, contents, desktop/mobile, localized controls and inert examples. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {browserApplications,server,password} from '../helpers/extension-media.mjs';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const evidence=process.env.DBLESS_CMS_PRESENTATION_EVIDENCE||'/tmp/dbless-presentation-browser';fs.mkdirSync(evidence,{recursive:true});
const browser=await chromium.launch({executablePath:'/usr/bin/google-chrome',headless:true,args:['--no-sandbox']});
try{
  for(const [index,application] of browserApplications.entries()){
    const sandbox=await server(application),context=await browser.newContext({viewport:{width:1920,height:1080},reducedMotion:'reduce'}),page=await context.newPage(),errors=[];
    page.on('pageerror',error=>errors.push(error.message));page.on('console',message=>{if(message.type()==='error')errors.push(message.text());});
    await context.route('**/*',route=>new URL(route.request().url()).origin===sandbox.base?route.continue():route.abort());
    try{
      // Exercise escaped examples and an inactive plugin using fixture data, never a live repository.
      const registry=path.join(sandbox.contents,'plugins.json'),state=JSON.parse(fs.readFileSync(registry));state.plugins.blog.enabled=false;fs.writeFileSync(registry,JSON.stringify(state));
      const docFile=path.join(sandbox.app,'system/plugins/blog/presentation.json'),doc=JSON.parse(fs.readFileSync(docFile));doc.sections[0].steps.push('<script>window.presentationExecuted=true</script>');doc.sections[0].code='<img src=x onerror="window.presentationExecuted=true">';fs.writeFileSync(docFile,JSON.stringify(doc));
      await page.goto(sandbox.base+'/cms-admin/');await page.getByLabel('Username',{exact:true}).fill('editor');await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.locator('[data-dashboard]').waitFor();
      for(const id of ['blog','files-manager','forms','homepage','menu','pages','slides','widgets']){
        const menu=page.locator(`[data-plugin-menu="${id}"]`);if(!await menu.evaluate(n=>n.open))await menu.locator('summary').click();
        const links=menu.locator('.sidebar-submenu > a');assert.match(await links.last().getAttribute('href'),/route=trash/);assert.equal(await links.nth(await links.count()-2).innerText(),'Presentation');
        await menu.getByRole('link',{name:'Presentation',exact:true}).click();const article=page.locator('[data-plugin-presentation]');await article.waitFor();assert.equal(await article.getAttribute('lang'),'en');assert.equal(await menu.getByRole('link',{name:'Presentation',exact:true}).getAttribute('aria-current'),'page');assert.equal(await menu.evaluate(n=>n.open),true);
        assert.ok(await article.locator('section ol li').count()>0);assert.equal(await page.evaluate(()=>window.presentationExecuted),undefined);
        const width=await article.evaluate(n=>({width:n.getBoundingClientRect().width,parent:n.parentElement.clientWidth})).catch(()=>null);
        assert.ok(width&&width.width>1400,'presentation uses the wide workspace');
        const last=article.locator('nav a').last(),target=await last.getAttribute('href');await last.click();assert.ok(page.url().endsWith(target));await page.locator(target).getByRole('heading').waitFor();
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
      }
      // Pages reference, then the same screen in Portuguese with English tutorial text.
      if(!await page.locator('[data-plugin-menu="pages"]').evaluate(n=>n.open))await page.locator('[data-plugin-menu="pages"] summary').click();await page.locator('[data-plugin-menu="pages"]').getByRole('link',{name:'Presentation',exact:true}).click();
      await page.screenshot({path:path.join(evidence,`${index}-desktop.png`)});
      const siteFile=path.join(sandbox.contents,'site.json'),site=JSON.parse(fs.readFileSync(siteFile));site.settings.adminLocale='pt-br';fs.writeFileSync(siteFile,JSON.stringify(site));await page.reload();assert.equal(await page.locator('[data-plugin-menu="pages"] [aria-current="page"]').innerText(),'Apresentação');assert.equal(await page.locator('[data-plugin-presentation]').getAttribute('lang'),'en');
      await page.setViewportSize({width:390,height:844});await page.getByRole('button',{name:'Abrir navegação',exact:true}).click();await page.locator('[data-plugin-menu="pages"]').getByRole('link',{name:'Apresentação',exact:true}).click();await page.locator('[data-plugin-presentation]').waitFor();
      await page.locator('[data-plugin-presentation] nav a').first().click();assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await page.screenshot({path:path.join(evidence,`${index}-mobile.png`)});assert.deepEqual(errors,[]);console.log(JSON.stringify({application,plugins:8,desktop:1920,mobile:390,errors:0,evidence}));
    }finally{await context.close();await sandbox.close();}
  }
}finally{await browser.close();}
