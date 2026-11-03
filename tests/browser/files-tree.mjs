/** Focused human-style tree regression: expand/collapse nested siblings and inspect painted connector endpoints in synthetic source/export applications. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import {pathToFileURL} from 'node:url';
import {pluginServer} from '../helpers/plugins.mjs';
import {trees} from '../helpers/runtime.mjs';
if(!process.env.PLAYWRIGHT_MODULE)throw new Error('Set PLAYWRIGHT_MODULE to an authorized local installation.');
const {chromium}=await import(pathToFileURL(process.env.PLAYWRIGHT_MODULE));
const evidence=fs.mkdtempSync(path.join(os.tmpdir(),'dbless-files-tree-'));
const browser=await chromium.launch({headless:true,executablePath:'/usr/bin/google-chrome',args:['--no-sandbox']}),results=[];
// An explicit export selects only that tree; source proof is collected separately.
try{for(const tree of process.env.DBLESS_CMS_PACKAGE_ROOT ? ['production'] : trees){
  const sandbox=await pluginServer(tree),context=await browser.newContext({viewport:{width:1440,height:1200}}),page=await context.newPage(),errors=[];
  for(const folder of ['Albums/First/Inner','Albums/Last/Long_directory_name_for_wrapped_row/Leaf'])fs.mkdirSync(path.join(sandbox.application,'public_html/media',folder),{recursive:true});
  await context.route('**/*',route=>new URL(route.request().url()).origin===sandbox.base?route.continue():route.abort());
  page.on('pageerror',error=>errors.push(error.message));page.on('console',message=>{if(message.type()==='error')errors.push(message.text());});
  /** Click the visible expansion control, await its state and the requested child; errors propagate from real UI waits. */
  async function expand(name,child){await page.getByRole('button',{name:'Expandir '+name,exact:true}).click();await page.getByRole('button',{name:'Recolher '+name,exact:true}).waitFor();await page.locator('[data-tree]').getByRole('button',{name:child,exact:true}).waitFor();}
  /** Capture the rendered sidebar and assert background pixels below every last-child junction; no app handlers or CSS are modified. */
  async function inspect(label){
    const sidebar=page.locator('.fm-sidebar');await sidebar.scrollIntoViewIfNeeded();
    const geometry=await sidebar.evaluate(node=>{
      const box=node.getBoundingClientRect();return {background:getComputedStyle(node).backgroundColor,branches:[...node.querySelectorAll('nav ul ul')].filter(ul=>ul.lastElementChild).map(ul=>{const li=ul.lastElementChild,row=li.querySelector(':scope > .fm-tree-row'),u=ul.getBoundingClientRect(),r=row.getBoundingClientRect(),l=li.getBoundingClientRect();return {x:u.left-box.left,top:r.top-box.top,center:r.top-box.top+r.height/2,bottom:Math.min(l.bottom,box.bottom-2)-box.top};}).filter(branch=>branch.top>=0&&branch.center<box.height-2)};
    });
    const capture=await sidebar.screenshot({path:path.join(evidence,`${tree}-${label}.png`)});
    const defects=await page.evaluate(async({bytes,geometry})=>{
      const bitmap=await createImageBitmap(new Blob([new Uint8Array(bytes)],{type:'image/png'})),canvas=document.createElement('canvas');canvas.width=bitmap.width;canvas.height=bitmap.height;const ctx=canvas.getContext('2d');ctx.fillStyle=geometry.background;ctx.fillRect(0,0,1,1);const background=[...ctx.getImageData(0,0,1,1).data];ctx.drawImage(bitmap,0,0);const pixels=ctx.getImageData(0,0,canvas.width,canvas.height).data;bitmap.close();const failures=[];
      for(const branch of geometry.branches){const x=Math.round(branch.x);let painted=false;
        for(let y=Math.ceil(branch.center)+2;y<Math.floor(branch.bottom)-1;y++)for(let offset=0;offset<=1;offset++){const index=(y*canvas.width+x+offset)*4;if([0,1,2].some(channel=>Math.abs(pixels[index+channel]-background[channel])>3)){failures.push({x:x+offset,y,center:branch.center});break;}}
        for(let y=Math.ceil(branch.top)+2;y<Math.floor(branch.center)-2;y++){const index=(y*canvas.width+x)*4;if([0,1,2].some(channel=>Math.abs(pixels[index+channel]-background[channel])>3))painted=true;}
        if(!painted)failures.push({x,missingConnector:true});
      }return failures.slice(0,10);
    },{bytes:[...capture],geometry});
    assert.deepEqual(defects,[],label+': connectors must exist and stop at the final direct child');assert.ok(geometry.branches.length>=1);assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  }
  try{
    await page.goto(sandbox.base+'/cms-admin/');await page.getByLabel('Usuário',{exact:true}).fill('editor');await page.getByLabel('Senha',{exact:true}).fill('Synthetic-DBLESS-CMS-password-2026');await page.getByRole('button',{name:'Entrar no painel',exact:true}).click();await page.locator('[data-plugin-menu=files-manager] > summary').click();await page.locator('[data-plugin-menu=files-manager]').getByRole('link',{name:'Visão Geral',exact:true}).click();
    await expand('Albums','Last');await expand('First','Inner');await expand('Last','Long_directory_name_for_wrapped_row');await expand('Long_directory_name_for_wrapped_row','Leaf');await inspect('desktop-expanded');
    await page.getByRole('button',{name:'Recolher Last',exact:true}).click();await page.getByRole('button',{name:'Expandir Last',exact:true}).waitFor();await inspect('desktop-collapsed');await expand('Last','Leaf');
    await page.setViewportSize({width:390,height:1200});await inspect('mobile-expanded');await page.locator('[data-tree]').getByRole('button',{name:'Leaf',exact:true}).scrollIntoViewIfNeeded();await inspect('mobile-scrolled');assert.deepEqual(errors,[]);assert.doesNotMatch(sandbox.logs(),/PHP (?:Warning|Fatal|Parse)/);results.push({tree,status:'passed',checks:['nested siblings and last child expanded','wrapped row','collapse/reopen','painted connector endpoints','desktop/mobile','console']});
  }finally{await context.close();await sandbox.close();}
}}finally{await browser.close();fs.writeFileSync(path.join(evidence,'results.json'),JSON.stringify(results,null,2)+'\n');}
console.log(JSON.stringify({evidence,results},null,2));
