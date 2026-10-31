/** Exercise the approved addressed brand, ICO and sidebar tint in isolated desktop/mobile Chromium; records actual painted icons without reading real state. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import {pathToFileURL} from 'node:url';
import {applications,password,server} from '../helpers/extension-media.mjs';
if(!process.env.PLAYWRIGHT_MODULE)throw new Error('Set PLAYWRIGHT_MODULE to the authorized local installation.');
const {chromium}=await import(pathToFileURL(process.env.PLAYWRIGHT_MODULE));
const evidence=fs.mkdtempSync(path.join(os.tmpdir(),'dbless-admin-brand-browser-')),results=[];
console.log('Browser evidence: '+evidence);
const browser=await chromium.launch({headless:true,executablePath:'/usr/bin/google-chrome',args:['--no-sandbox']});
try{
  for(const [index,application] of applications.entries()){
    const directory=path.join(evidence,String(index));fs.mkdirSync(directory);
    const sandbox=await server(application),context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'}),page=await context.newPage(),errors=[];
    /** Confine browser traffic to this fixture; receives route, returns continue/abort and records unexpected requests. */
    await context.route('**/*',route=>{if(new URL(route.request().url()).origin===sandbox.base)return route.continue();errors.push('External request');return route.abort();});
    context.on('requestfailed',request=>errors.push(request.failure()?.errorText||'Request failed'));
    page.on('pageerror',error=>errors.push(error.message));page.on('console',message=>{if(message.type()==='error')errors.push(message.text());});
    try{
      await page.goto(sandbox.base+'/cms-admin/');assert.equal(await page.locator('.auth-avatar .icon-person-circle').count(),1);assert.equal(await page.locator('.brand-logo').count(),0);
      const favicon=await page.locator('link[rel="icon"]').getAttribute('href');assert.match(favicon,/cms-admin\/assets\/favicon\.ico$/);
      const response=await context.request.get(favicon);assert.equal(response.status(),200);assert.equal((await response.body()).readUInt16LE(4),7);
      await page.getByLabel('Username',{exact:true}).fill('editor');await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in',exact:true}).click();
      const logo=page.locator('.brand-logo');await logo.evaluate(image=>image.decode());assert.equal(await logo.getAttribute('alt'),'DBLess-CMS');assert.match(await logo.getAttribute('src'),/brand\/dbless-cms-inverse\.svg$/);
      /** Measure the displayed signature against its parent; receives image and returns geometry without changing DOM. */
      const dimensions=await logo.evaluate(image=>{const a=image.getBoundingClientRect(),b=image.closest('.brand').getBoundingClientRect();return {width:a.width,height:a.height,fits:a.left>=b.left&&a.right<=b.right+1};});
      assert.ok(dimensions.width>=170&&dimensions.width<=188&&dimensions.height>=36&&dimensions.fits);
      const icons=page.locator('.sidebar .plugin-icon');assert.equal(await icons.count(),6);
      const urls=await icons.evaluateAll(async images=>{await Promise.all(images.map(image=>image.decode()));return images.map(image=>image.src);});assert.equal(new Set(urls).size,6);
      for(const [number,icon] of (await icons.all()).entries()){
        assert.match(await icon.evaluate(image=>getComputedStyle(image).filter),/brand\/plugin-icon-color\.svg#mint/);
        await icon.screenshot({path:path.join(directory,'icon-'+number+'.png')});
      }
      const filterResponse=await context.request.get(sandbox.base+'/cms-admin/assets/brand/plugin-icon-color.svg');assert.equal(filterResponse.status(),200);
      await page.screenshot({path:path.join(directory,'panel-desktop.png')});
      await icons.first().hover();await icons.first().screenshot({path:path.join(directory,'icon-hover.png')});
      const firstMenu=page.locator('[data-plugin-menu]').first();if(!await firstMenu.evaluate(node=>node.open))await firstMenu.locator('summary').click();
      await icons.first().screenshot({path:path.join(directory,'icon-open.png')});
      await firstMenu.locator('a').first().click();await page.screenshot({path:path.join(directory,'panel-active.png')});
      await page.setViewportSize({width:390,height:844});await page.locator('[data-nav-toggle]').click();await logo.waitFor({state:'visible'});
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await icons.first().screenshot({path:path.join(directory,'icon-mobile.png')});
      await page.screenshot({path:path.join(directory,'panel-mobile.png')});
      await page.setViewportSize({width:320,height:844});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await page.screenshot({path:path.join(directory,'panel-mobile-320.png')});
      assert.equal(await page.locator('svg').count(),0);assert.equal(await page.locator('.brand-mark').count(),0);assert.deepEqual(errors,[]);assert.doesNotMatch(sandbox.logs(),/PHP (?:Warning|Fatal|Parse)/);
      results.push({application,status:'passed',viewports:[1440,390,320],loginAvatarPreserved:true,logoAddressedSVG:true,logoDimensions:dimensions,pluginIcons:urls.map(url=>new URL(url).pathname),filterLoaded:true,paintedIconSamples:9,icoFrames:7,inlineSVG:false,unexpectedErrors:errors});
    }catch(error){await page.screenshot({path:path.join(directory,'failure.png'),fullPage:true});throw error;}finally{await context.close();await sandbox.close();}
  }
}finally{await browser.close();fs.writeFileSync(path.join(evidence,'results.json'),JSON.stringify(results,null,2)+'\n');}
console.log(JSON.stringify(results,null,2));
