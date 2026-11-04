/** Human-style tutorial navigation in isolated source/package copies: menus, contents, desktop/mobile, localized controls and inert examples. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {browserApplications,server,password} from '../helpers/extension-media.mjs';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const evidence=process.env.DBLESS_CMS_PRESENTATION_EVIDENCE||'/tmp/dbless-presentation-browser';fs.mkdirSync(evidence,{recursive:true});
const browser=await chromium.launch({executablePath:'/usr/bin/google-chrome',headless:true,args:['--no-sandbox']});
/** Follow the actual Menu presentation link in each panel locale/viewport; receive application/index, assert locale documents, safe English fallback and complete inert examples and normal navigation, remove only its fixture. */
async function menuTutorial(application,index){
 const c=await server(application),context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'}),page=await context.newPage(),errors=[];
 page.on('pageerror',error=>errors.push(error.message));page.on('console',message=>{if(message.type()==='error')errors.push(message.text());});await context.route('**/*',route=>new URL(route.request().url()).origin===c.base?route.continue():route.abort());
 try{
  const file=path.join(c.contents,'site.json'),site=JSON.parse(fs.readFileSync(file)),doc=JSON.parse(fs.readFileSync(path.join(c.app,'system/plugins/menu/presentation.json'))),before=fs.readFileSync(path.join(c.contents,'menu/menus.json'));
  await page.goto(c.base+'/cms-admin/');await page.getByLabel('Username',{exact:true}).fill('editor');await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.locator('[data-dashboard]').waitFor();
  for(const locale of ['en-us','pt-br']){
   site.settings.adminLocale=locale;fs.writeFileSync(file,JSON.stringify(site));
   for(const width of [1440,390]){
    await page.setViewportSize({width,height:1000});await page.goto(c.base+'/cms-admin/');
    if(width===390)await page.getByRole('button',{name:locale==='en-us'?'Open navigation':'Abrir navegação',exact:true}).click();
    const menu=page.locator('[data-plugin-menu="menu"]');if(!await menu.evaluate(node=>node.open))await menu.locator('summary').click();await menu.getByRole('link',{name:locale==='en-us'?'Presentation':'Apresentação',exact:true}).click();
    const article=page.locator('[data-plugin-presentation]');await article.waitFor();const translated=JSON.parse(fs.readFileSync(path.join(c.app,'system/plugins/menu/languages/presentation-pt-br.json'))),expected=locale==='pt-br'?translated:doc;assert.equal(await article.getAttribute('lang'),expected.language);assert.equal(await article.locator('h2').innerText(),expected.title);assert.equal(await article.locator('section').count(),11);assert.equal(await article.locator('[data-presentation-fallback]').count(),0);
    const geometry=await article.evaluate(node=>{const parent=node.parentElement,style=getComputedStyle(parent);return {width:node.getBoundingClientRect().width,available:parent.clientWidth-parseFloat(style.paddingLeft)-parseFloat(style.paddingRight)};});assert.ok(Math.abs(geometry.width-geometry.available)<=2,JSON.stringify(geometry));assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
    assert.equal(await article.locator('pre code').count(),2);assert.equal(await article.locator('pre nav, pre a').count(),0);assert.equal(await article.locator('pre code').first().textContent(),doc.sections[5].code);
    const last=article.locator('nav a').last(),target=await last.getAttribute('href');await last.click();await page.locator(target).getByRole('heading',{name:locale==='pt-br'?'Recuperar menus e revisões do catálogo':'Recover removed menus and catalog revisions',exact:true}).waitFor();assert.ok(page.url().endsWith(target));
    await page.locator('#presentation-section-mustache-layout').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(evidence,`menu-${index}-${locale}-${width}.png`)});
   }
  }
  fs.unlinkSync(path.join(c.app,'system/plugins/menu/languages/presentation-pt-br.json'));await page.goto(c.base+'/cms-admin/?view=plugin-about&plugin=menu');await page.locator('[data-presentation-fallback]').filter({hasText:/indisponível ou inválida/}).waitFor();assert.equal(await page.locator('[data-plugin-presentation]').getAttribute('lang'),'en');assert.equal(await page.locator('[data-plugin-presentation]').getAttribute('dir'),'ltr');
  const dictionary=JSON.parse(fs.readFileSync(path.join(c.app,'system/languages/en-us.json')));dictionary.locale='ar';dictionary.name='Synthetic RTL';dictionary.direction='rtl';fs.writeFileSync(path.join(c.app,'system/languages/ar.json'),JSON.stringify(dictionary));site.settings.adminLocale='ar';fs.writeFileSync(file,JSON.stringify(site));await page.reload();assert.equal(await page.locator('html').getAttribute('dir'),'rtl');assert.equal(await page.locator('[data-plugin-presentation]').getAttribute('dir'),'ltr');await page.locator('[data-presentation-fallback]').waitFor();
  const registry=path.join(c.contents,'plugins.json'),state=JSON.parse(fs.readFileSync(registry));state.plugins.menu.enabled=false;fs.writeFileSync(registry,JSON.stringify(state));await page.goto(c.base+'/cms-admin/?view=plugin-about&plugin=menu');await page.getByRole('heading',{name:doc.title,exact:true}).waitFor();assert.deepEqual(fs.readFileSync(path.join(c.contents,'menu/menus.json')),before);assert.deepEqual(errors,[]);assert.doesNotMatch(c.logs(),/PHP (?:Warning|Fatal|Parse)/);console.log(JSON.stringify({application,menuPresentation:true,sections:11,locales:2,rtlFallback:true,viewports:[1440,390],disabledPlugin:true,editorialWrites:0,evidence}));
 }catch(error){await page.screenshot({path:path.join(evidence,`menu-${index}-failure.png`)});throw error;}finally{await context.close();await c.close();}
}
try{
  for(const [index,application] of browserApplications.entries()){
    if(process.env.DBLESS_CMS_PRESENTATION_MENU_ONLY==='1'){await menuTutorial(application,index);continue;}

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
