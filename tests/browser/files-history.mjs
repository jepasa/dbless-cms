/** Focused human Images history/removal acceptance: visible file/settings actions, translated history, retired controls and responsive layout in synthetic source/export only. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import {pathToFileURL} from 'node:url';
import {pluginServer} from '../helpers/plugins.mjs';
if(!process.env.PLAYWRIGHT_MODULE)throw new Error('Set PLAYWRIGHT_MODULE to an authorized local installation.');
const {chromium}=await import(pathToFileURL(process.env.PLAYWRIGHT_MODULE));
const evidence=fs.mkdtempSync(path.join(os.tmpdir(),'dbless-files-history-')),tree=process.env.DBLESS_CMS_PACKAGE_ROOT?'production':'src',results=[];
const browser=await chromium.launch({headless:true,executablePath:'/usr/bin/google-chrome',args:['--no-sandbox']}),sandbox=await pluginServer(tree),context=await browser.newContext({viewport:{width:1440,height:1000}}),page=await context.newPage(),errors=[];
await context.route('**/*',route=>new URL(route.request().url()).origin===sandbox.base?route.continue():route.abort());page.on('pageerror',error=>errors.push(error.message));page.on('console',message=>{if(message.type()==='error')errors.push(message.text());});
/** Navigate through the actual plugin sidebar, opening its dropdown as needed; propagates locator/navigation failures. */
async function route(label){const menu=page.locator('[data-plugin-menu=files-manager]');if(!await menu.evaluate(node=>node.open))await menu.locator('summary').click();await menu.getByRole('link',{name:label,exact:true}).click();}
/** Enter a name in the visible prompt and submit; receives text, waits for the real operation, no direct API calls. */
async function confirm(value){const prompt=page.locator('[data-prompt]');await prompt.waitFor({state:'visible'});await prompt.locator('input').fill(value);await prompt.getByRole('button',{name:'Confirmar',exact:true}).click();await prompt.waitFor({state:'hidden'});await page.locator('[data-status]').filter({hasText:'Operação concluída.'}).waitFor();}
try{
  await page.goto(sandbox.base+'/cms-admin/');await page.getByLabel('Usuário',{exact:true}).fill('editor');await page.getByLabel('Senha',{exact:true}).fill('Synthetic-DBLESS-CMS-password-2026');await page.getByRole('button',{name:'Entrar no painel',exact:true}).click();await route('Visão Geral');await page.locator('[data-action=mkdir]').waitFor();assert.equal(await page.locator('a[href*="route=rights"],[data-action=metadata]').count(),0);
  await page.getByRole('button',{name:'Nova pasta',exact:true}).click();await confirm('History folder');await page.getByRole('radio',{name:'Selecionado: History folder',exact:true}).check();await page.getByRole('button',{name:'Renomear',exact:true}).click();await confirm('Renamed folder');
  const chooser=page.waitForEvent('filechooser');await page.getByRole('button',{name:'Enviar arquivos',exact:true}).click();await(await chooser).setFiles({name:'sample.png',mimeType:'image/png',buffer:fs.readFileSync(path.join(sandbox.application,'public_html/templates/essencial/essencial.png'))});await page.getByRole('radio',{name:'Selecionado: sample.png',exact:true}).waitFor();await page.locator('[data-dropzone]').getByRole('button',{name:'sample.png',exact:true}).click({button:'right'});await page.getByRole('menu').waitFor();assert.equal(await page.getByRole('menuitem',{name:/Metadados|direitos/i}).count(),0);await page.keyboard.press('Escape');
  await route('Configurações');await page.getByLabel('Itens por página (25 / 50 / 100)',{exact:true}).fill('50');await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Salvar alterações',exact:true}).click()]);await route('Histórico');
  for(const text of ['Pasta criada.','Arquivo ou pasta renomeado.','Arquivo enviado.'])await page.getByText(text,{exact:true}).waitFor();await page.getByText(/Cópia das configurações preservada/).waitFor();await page.screenshot({path:path.join(evidence,tree+'-pt-desktop.png'),fullPage:true});
  await page.locator('[data-user-menu] > summary').click();await page.locator('[data-user-menu]').getByRole('link',{name:'Configurações',exact:true}).click();await page.locator('[data-admin-locale]').selectOption('en-us');await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Salvar alterações',exact:true}).click()]);await route('History');
  for(const text of ['Folder created.','File or folder renamed.','File uploaded.'])await page.getByText(text,{exact:true}).waitFor();assert.equal(await page.locator('a[href*="route=rights"]').count(),0);await page.getByText(/Configuration copy preserved/).waitFor();
  for(const width of [1440,390]){await page.setViewportSize({width,height:1000});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:path.join(evidence,`${tree}-en-${width}.png`),fullPage:true});}
  assert.deepEqual(errors,[]);assert.doesNotMatch(sandbox.logs(),/PHP (?:Warning|Fatal|Parse)/);results.push({tree,status:'passed',checks:['real mkdir/rename/chooser','removed sidebar/toolbar/context action','settings copy description','file action descriptions','pt-br/en-us','desktop/mobile','console']});
}finally{await context.close();await sandbox.close();await browser.close();fs.writeFileSync(path.join(evidence,'results.json'),JSON.stringify(results,null,2)+'\n');}
console.log(JSON.stringify({evidence,results},null,2));
