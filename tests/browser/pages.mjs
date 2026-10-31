/** Jornada real de páginas em cópias sintéticas: dropdown, tabela, criação, lixeira/restauração, idiomas e responsividade. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import {applications,server,inspect,password} from '../helpers/extension-media.mjs';
if (!process.env.PLAYWRIGHT_MODULE) throw new Error('Defina PLAYWRIGHT_MODULE para a instalação local autorizada.');
const {chromium}=await import(pathToFileURL(process.env.PLAYWRIGHT_MODULE));
const evidence=process.env.DBLESS_CMS_PAGES_EVIDENCE||'/tmp/dbless-cms-pages-evidence';
fs.mkdirSync(evidence,{recursive:true});
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/google-chrome',args:['--no-sandbox']});
const results=[];
try {
  for (const [index,application] of applications.entries()) {
    const tree=index===0?'src':'production';
    const sandbox=await server(application),context=await browser.newContext({viewport:{width:1440,height:1000}}),page=await context.newPage(),errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    page.on('console',message=>{if(message.type()==='error'&&!/favicon|status of 404/.test(message.text()))errors.push(message.text());});
    await context.route('**/*',route=>{if(new URL(route.request().url()).origin===sandbox.base)return route.continue();errors.push('External request');return route.abort();});
    /** Abre item do grupo de páginas pelo drawer/summary visível; recebe rótulo e não modifica estado por API. */
    async function navigate(label) {
      await page.locator('body.nav-ready').waitFor();
      const toggle=page.locator('[data-nav-toggle]');
      if(await toggle.isVisible()&&await toggle.getAttribute('aria-expanded')==='false')await toggle.click();
      const menu=page.locator('[data-plugin-menu="pages"]');
      if(!(await menu.evaluate(node=>node.open)))await menu.locator('summary').click();
      await menu.getByRole('link',{name:label,exact:true}).click();
    }
    /** Envia ação visível e aguarda navegação; recebe locator, sem alterações diretas no conteúdo. */
    async function submit(locator) {const navigation=page.waitForNavigation();await locator.click();await navigation;}
    try {
      inspect(sandbox,"$d=$s->read('site');$d['data']['settings']['adminLocale']='pt-br';$s->save('site',DBLESSCMS\\JsonStore::encode($d['data']),$d['revision']);echo 'true';");
      await page.goto(sandbox.base+'/cms-admin/');await page.getByLabel('Usuário',{exact:true}).fill('editor');await page.getByLabel('Senha',{exact:true}).fill(password);await submit(page.getByRole('button',{name:'Entrar no painel',exact:true}));
      // The portable fixture uses only local Essencial assets, retaining the active-default protection.
      await page.locator('[data-user-menu] > summary').click();await page.locator('[data-user-menu]').getByRole('link',{name:'Templates',exact:true}).click();
      const activate=page.locator('[data-extension-id="essencial"] button[aria-pressed]');if(!await activate.isDisabled())await submit(activate);else assert.equal(await activate.getAttribute('aria-pressed'),'true');await navigate('Visão Geral');
      const menu=page.locator('[data-plugin-menu="pages"]'),summary=menu.locator('summary');
      assert.deepEqual(await menu.locator('a').allTextContents(),['Visão Geral','Nova Página','Histórico','Lixeira']);assert.equal(await menu.locator('hr').count(),2);
      await summary.focus();await page.keyboard.press('Enter');assert.equal(await menu.evaluate(node=>node.open),false);await page.keyboard.press('Space');assert.ok(await menu.evaluate(node=>node.open));
      for(const id of ['files-manager']) {
        const dropdown=page.locator(`[data-plugin-menu="${id}"]`);
        if(!(await dropdown.evaluate(node=>node.open)))await dropdown.locator('summary').click();
        await dropdown.getByRole('link',{name:'Configurações',exact:true}).click();
        assert.equal(new URL(page.url()).searchParams.get('plugin'),id);
        assert.equal(new URL(page.url()).searchParams.get('route'),'settings');
        await page.locator(`[data-${id==='files-manager'?'fm-settings':id+'-form'}]`).first().waitFor();
      }
      await navigate('Visão Geral');
      assert.deepEqual(await page.locator('thead th').allTextContents(),['Nome','Descrição','Status','Ação']);
      assert.equal(await page.locator('.stats-grid,.create-form').count(),0);
      assert.ok(await page.locator('.row-actions button,.row-actions a').evaluateAll(nodes=>nodes.every(node=>node.textContent.trim()===''&&node.title&&node.getAttribute('aria-label'))));
      assert.equal(await page.locator('[data-page-id="home"] .danger').isDisabled(),true);assert.equal(await page.locator('[data-page-id="404"] .danger').isDisabled(),true);
      await page.screenshot({path:path.join(evidence,tree+'-overview-desktop.png'),fullPage:true});
      await navigate('Lixeira');await page.getByText('Nenhuma página na lixeira.',{exact:true}).waitFor();
      await navigate('Nova Página');await page.locator('[data-native-editor] > summary').click();const legacy=page.locator('.create-form'),slugField=legacy.getByLabel('Identificador da URL',{exact:true});
      await slugField.fill('invalid/slug');assert.equal(await slugField.evaluate(node=>node.checkValidity()),false);
      await slugField.fill('pagina-browser');assert.equal(await slugField.evaluate(node=>node.checkValidity()),true);await legacy.getByRole('combobox',{name:/^Estrutura inicial/}).selectOption('starter-page');await submit(legacy.getByRole('button',{name:'Criar rascunho',exact:true}));
      assert.equal(new URL(page.url()).searchParams.get('key'),'pagina-browser');
      await navigate('Visão Geral');const row=page.locator('[data-page-id="pagina-browser"]');await row.getByText('Rascunho',{exact:true}).waitFor();
      const title=await row.locator('strong').innerText();
      const popupEvent=page.waitForEvent('popup');await row.getByRole('link',{name:'Visualizar '+title,exact:true}).click();const popup=await popupEvent;await popup.waitForLoadState();assert.equal(new URL(popup.url()).searchParams.get('key'),'pagina-browser');await popup.close();
      const confirmation=page.waitForEvent('dialog'),clicking=row.locator('.danger').click({noWaitAfter:true});const dialog=await confirmation;assert.equal(dialog.type(),'confirm');const navigation=page.waitForNavigation();await dialog.accept();await clicking;await navigation;
      await navigate('Lixeira');await page.getByText('pagina-browser',{exact:true}).waitFor();await page.screenshot({path:path.join(evidence,tree+'-trash.png'),fullPage:true});
      await page.getByRole('link',{name:'Histórico e restauração',exact:true}).click();
      const restoreDialog=page.waitForEvent('dialog'),restoring=page.getByRole('button',{name:'Restaurar revisão',exact:true}).first().click({noWaitAfter:true});const restore=await restoreDialog;const restored=page.waitForNavigation();await restore.accept();await restoring;await restored;
      await navigate('Lixeira');await page.getByText('Nenhuma página na lixeira.',{exact:true}).waitFor();
      await navigate('Visão Geral');assert.equal(await page.locator('[data-page-id="pagina-browser"]').count(),1);
      for(const width of [320,390,768,1024,1440]) {
        await page.setViewportSize({width,height:900});await page.evaluate(async()=>{await document.fonts.ready;await new Promise(requestAnimationFrame);});
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${tree}: overflow ${width}`);
        await page.screenshot({path:path.join(evidence,`${tree}-overview-${width}.png`),fullPage:true});
        await navigate('Nova Página');await page.getByRole('heading',{name:'Nova Página',level:1,exact:true}).waitFor();await navigate('Lixeira');await navigate('Visão Geral');
      }
      await page.locator('[data-user-menu] > summary').click();await page.locator('[data-user-menu]').getByRole('link',{name:'Configurações',exact:true}).click();await page.locator('[data-admin-locale]').selectOption('en-us');await submit(page.getByRole('button',{name:'Salvar alterações',exact:true}));
      await navigate('Overview');assert.deepEqual(await menu.locator('a').allTextContents(),['Overview','New Page','History','Trash']);assert.deepEqual(await page.locator('thead th').allTextContents(),['Name','Description','Status','Action']);
      await navigate('New Page');await page.getByRole('heading',{name:'New Page',level:1,exact:true}).waitFor();await navigate('Trash');await page.getByText('No pages in the trash.',{exact:true}).waitFor();
      assert.deepEqual(errors,[]);assert.ok(!/PHP (?:Warning|Fatal|Parse)/.test(sandbox.logs()),sandbox.logs());
      results.push({tree,status:'passed',checks:['dropdown/order/separator/keyboard','shared responsive table/icon actions/protected pages','create draft/preview/trash/history/restore','pt-br/en-us','320/390/768/1024/1440/no overflow','console/CSP']});
    } catch(error) {await page.screenshot({path:path.join(evidence,tree+'-failure.png'),fullPage:true});throw error;} finally {await context.close();await sandbox.close();}
  }
} finally {await browser.close();fs.writeFileSync(path.join(evidence,'results.json'),JSON.stringify(results,null,2)+'\n');}
console.log(JSON.stringify(results,null,2));
