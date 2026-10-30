/** Actual admin usage with manifest-owned SVG icons and template previews: desktop/mobile, external-file loading and custom filenames; synthetic state only. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import {pathToFileURL} from 'node:url';
import {applications,password,server} from '../helpers/extension-media.mjs';
if(!process.env.PLAYWRIGHT_MODULE)throw new Error('Set PLAYWRIGHT_MODULE to the authorized local installation.');
const {chromium}=await import(pathToFileURL(process.env.PLAYWRIGHT_MODULE));
// Each run owns a private unpredictable directory; the optional setting selects only its parent.
const evidenceParent=process.env.DBLESS_CMS_MEDIA_EVIDENCE||os.tmpdir();fs.mkdirSync(evidenceParent,{recursive:true});
const evidence=fs.mkdtempSync(path.join(evidenceParent,'dbless-extension-media-browser-'));
console.log('Browser evidence: '+evidence);
const browser=await chromium.launch({headless:true,executablePath:'/usr/bin/google-chrome',args:['--no-sandbox']}),results=[];
try{
  for(const application of applications){
    const sandbox=await server(application),context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'}),page=await context.newPage(),errors=[];
    /** Block requests outside the isolated loopback application; receives route, returns continuation/abort and records unexpected hosts. */
    await context.route('**/*',route=>{if(new URL(route.request().url()).origin===sandbox.base)return route.continue();errors.push('External request');return route.abort();});
    page.on('pageerror',error=>errors.push(error.message));page.on('console',message=>{if(message.type()==='error')errors.push(message.text());});
    try{
      await page.goto(sandbox.base+'/cms-admin/');await page.getByLabel('Username',{exact:true}).fill('editor');await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in',exact:true}).click();
      assert.match(await page.locator('link[rel="icon"]').getAttribute('href'),/\/cms-admin\/assets\/favicon\.ico$/);
      await page.waitForSelector('[data-plugin-menu="slides"]');
      const icons=page.locator('.sidebar .plugin-icon');assert.equal(await icons.count(),4);
      const urls=await icons.evaluateAll(nodes=>nodes.map(node=>node.src));assert.equal(new Set(urls).size,4);
      assert.ok(urls.some(url=>url.endsWith('/plugins/slides/slides.svg')));assert.ok(urls.some(url=>url.endsWith('/plugins/files-manager/files-manager.svg')));
      await page.screenshot({path:path.join(evidence,'sidebar-desktop.png')});
      await page.locator('[data-plugin-menu="slides"] > summary').click();await page.locator('[data-plugin-menu="files-manager"] > summary').click();
      const menu=page.locator('[data-user-menu]');await menu.locator('summary').click();await menu.getByRole('link',{name:'Templates',exact:true}).click();
      const thumbnails=page.locator('.template-thumbnail');assert.ok(await thumbnails.count()>=1);
      /** Ensure both natural and rendered previews have exact 16:9 proportions; receives images, decodes them and returns valid dimensions. */
      const dimensions=await thumbnails.evaluateAll(async images=>{await Promise.all(images.map(image=>image.decode()));return images.map(image=>({src:image.src,width:image.naturalWidth,height:image.naturalHeight,rendered:image.getBoundingClientRect().width/image.getBoundingClientRect().height}));});
      for(const image of dimensions){assert.equal(image.width*9,image.height*16);assert.ok(Math.abs(image.rendered-16/9)<.02);}
      for(const width of [1440,390,320]){
        await page.setViewportSize({width,height:1000});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        await page.screenshot({path:path.join(evidence,`templates-${width}.png`),fullPage:true});
      }
      // Change only the synthetic manifest, then navigate normally to prove runtime precedence over the default filename.
      const manifest=path.join(sandbox.app,'system/plugins/slides/plugin.json'),data=JSON.parse(fs.readFileSync(manifest));
      fs.copyFileSync(path.join(sandbox.app,'public_html/plugins/slides/slides.svg'),path.join(sandbox.app,'public_html/plugins/slides/alternate.svg'));data.assets.icon='alternate.svg';fs.writeFileSync(manifest,JSON.stringify(data));
      await page.reload();assert.match(await page.locator('[data-plugin-menu="slides"] img').getAttribute('src'),/\/plugins\/slides\/alternate\.svg$/);
      assert.ok(await page.locator('.sidebar .plugin-icon').evaluateAll(images=>images.every(image=>image.complete&&image.naturalWidth>0)));
      // Human upload via the file chooser, followed by the global settings form.
      await page.setViewportSize({width:1440,height:1000});
      const imagesMenu=page.locator('[data-plugin-menu="files-manager"]');if(!await imagesMenu.evaluate(node=>node.open))await imagesMenu.locator('summary').click();
      await imagesMenu.locator('a[href*="route=overview"]').click();await page.locator('[data-action="upload"]').waitFor();
      await page.waitForFunction(()=>!document.querySelector('[data-action="upload"]').disabled);
      const chooserPromise=page.waitForEvent('filechooser');await page.locator('[data-action="upload"]').click();const chooser=await chooserPromise;
      await chooser.setFiles({name:'brand.ico',mimeType:'image/vnd.microsoft.icon',buffer:fs.readFileSync(path.join(sandbox.app,'public_html/cms-admin/assets/favicon.ico'))});
      await page.getByText('brand.ico',{exact:true}).first().waitFor();
      const user=page.locator('[data-user-menu]');if(!await user.evaluate(node=>node.open))await user.locator('summary').click();await user.getByRole('link',{name:'Settings',exact:true}).click();
      await page.locator('[data-site-favicon]').fill('media/brand.ico');
      await page.screenshot({path:path.join(evidence,'favicon-global-settings.png'),fullPage:true});
      await page.locator('.document-form button[type="submit"]').click();
      assert.equal(await page.locator('[data-site-favicon]').inputValue(),'media/brand.ico');
      const publicPage=await context.newPage();await publicPage.goto(sandbox.base+'/');assert.equal(await publicPage.locator('link[rel="icon"]').getAttribute('href'),sandbox.base+'/media/brand.ico');
      const iconResponse=await context.request.get(sandbox.base+'/media/brand.ico');assert.equal(iconResponse.status(),200);await publicPage.close();
      assert.equal(await page.locator('svg').count(),0);assert.deepEqual(errors,[]);assert.doesNotMatch(sandbox.logs(),/PHP (?:Warning|Fatal|Parse)/);
      results.push({application,status:'passed',icons:urls,thumbnails:dimensions,checks:['actual sign-in and dropdown clicks','four distinct addressed SVGs','desktop/mobile 16:9 previews','manifest filename override','ICO upload via chooser and global favicon saved/read in public header','independent hardcoded admin favicon','no horizontal overflow, inline SVG, external requests or runtime errors']});
    }catch(error){await page.screenshot({path:path.join(evidence,'failure.png'),fullPage:true});throw error;}finally{await context.close();await sandbox.close();}
  }
}finally{await browser.close();fs.writeFileSync(path.join(evidence,'results.json'),JSON.stringify(results,null,2)+'\n');}
console.log(JSON.stringify(results,null,2));
