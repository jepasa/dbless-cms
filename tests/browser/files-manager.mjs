/** QA de navegador com ações humanas em sandbox: chooser, cliques, teclado, dialogs, download e layout responsivo. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { pathToFileURL } from 'node:url';
import { pluginServer } from '../helpers/plugins.mjs';
import { trees } from '../helpers/runtime.mjs';
const modulePath = process.env.PLAYWRIGHT_MODULE;
if (!modulePath) throw new Error('Defina PLAYWRIGHT_MODULE para um módulo Playwright local instalado.');
const {chromium} = await import(pathToFileURL(modulePath));
const evidence = process.env.DBLESS_CMS_BROWSER_EVIDENCE || fs.mkdtempSync(path.join(os.tmpdir(),'dbless-cms-browser-evidence-'));
fs.mkdirSync(evidence,{recursive:true});
const browser = await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_EXECUTABLE || '/usr/bin/google-chrome',args:['--no-sandbox']});
const results = [];
try {
  for (const tree of trees) {
    const sandbox = await pluginServer(tree), context = await browser.newContext({viewport:{width:1440,height:1000},acceptDownloads:true});
    const page = await context.newPage(), errors = [];
    await context.route('**/*',route=> { if(new URL(route.request().url()).origin===sandbox.base) return route.continue(); errors.push('Unexpected external request');return route.abort(); });
    page.on('pageerror',error => errors.push(error.message));
    page.on('console',message => { if(message.type()==='error' && !message.text().includes('favicon.ico') && !message.text().includes('status of 404')) errors.push(message.text()); });
    /** Aguarda snapshot estável do gerenciador carregado; recebe frame e não modifica arquivos. */
    async function ready(frame) { await frame.locator('[data-status]').filter({hasText:/Carregando|Loading/}).waitFor({state:'hidden'}); await frame.locator('[data-action="upload"]').waitFor({state:'visible'}); }
    /** Abre gerenciador pelo botão real do painel; retorna página integrada após primeira listagem. */
    async function open(label='Visão Geral') {
      await navigate(label);
      await page.locator('[data-fm]').waitFor();await ready(page);return page;
    }
    /** Confirma entrada usando dialog visível, sem invocar endpoints diretamente. */
    async function prompt(frame,value=null) { const dialog=frame.locator('[data-prompt]');await dialog.waitFor({state:'visible'});if(value!==null)await dialog.locator('input').fill(value);await dialog.getByRole('button',{name:'Confirmar',exact:true}).click();await dialog.waitFor({state:'hidden'}); }
    /** Envia seleção de arquivos pelo chooser nativo disparado pelo botão do plugin. */
    async function upload(frame,files) {
      const pending=page.waitForEvent('filechooser');await frame.getByRole('button',{name:'Enviar arquivos',exact:true}).click();const chooser=await pending;await chooser.setFiles(files);await frame.locator('[data-status]').filter({hasText:'Operação concluída.'}).waitFor();
    }
    /** Abre destino pela navegação visível, incluindo drawer móvel; não invoca APIs. */
    async function navigate(label) {
      if (['Templates','Global settings','Configurações globais'].includes(label)) {
        await page.locator('[data-user-menu] > summary').click();
        const target=label==='Global settings'?'Settings':label==='Configurações globais'?'Configurações':label;
        await page.locator('[data-user-menu]').getByRole('link',{name:target,exact:true}).click();
      } else {
        const toggle=page.locator('[data-nav-toggle]');
        if (await toggle.isVisible() && await toggle.getAttribute('aria-expanded')!=='true') await toggle.click();
        const menu=page.locator('[data-plugin-menu="files-manager"]');if(!await menu.evaluate(node=>node.open))await menu.locator('summary').click();
        await menu.getByRole('link',{name:label,exact:true}).click();
      }
    }
    /** Salva pela API própria e aguarda recarregamento do painel de configurações. */
    async function saveSettings() {
      const navigation=page.waitForNavigation();await page.getByRole('button',{name:'Salvar alterações',exact:true}).click();await navigation;await page.getByRole('group',{name:'Uploads',exact:true}).waitFor();
    }
    try {
      console.log(tree+': login/settings');
      await page.goto(sandbox.base+'/cms-admin/');
      await page.screenshot({path:path.join(evidence,`${tree}-login.png`),fullPage:true});
      await page.getByLabel('Usuário',{exact:true}).fill('editor');await page.getByLabel('Senha',{exact:true}).fill('Synthetic-DBLESS-CMS-password-2026');await page.getByRole('button',{name:'Entrar no painel',exact:true}).click();
      await page.screenshot({path:path.join(evidence,`${tree}-dashboard.png`),fullPage:true});
      await page.locator('[data-plugin-menu="files-manager"] > summary').click();
      await page.locator('[data-plugin-menu="files-manager"]').getByRole('link',{name:'Configurações',exact:true}).click();
      assert.equal(await page.locator('.sidebar').getByRole('link',{name:'Plugins',exact:true}).count(),0);
      assert.equal(await page.locator('[data-plugin-menu="files-manager"] > summary').evaluate(node=>node.textContent.replace(/\s/g,'')),'Imagens⌄');
      const settings=page;
      await settings.getByRole('group',{name:'Uploads',exact:true}).waitFor();
      await page.screenshot({path:path.join(evidence,`${tree}-sidebar-settings.png`),fullPage:true});
      assert.equal(await settings.getByLabel('Público',{exact:true}).inputValue(),'media');assert.equal(await settings.getByLabel('Privado',{exact:true}).inputValue(),'media');
      const disclosure=page.locator('[data-plugin-menu="files-manager"] > summary');await disclosure.focus();await page.keyboard.press('Enter');assert.equal(await page.locator('[data-plugin-menu="files-manager"]').evaluate(node=>node.open),false);await page.keyboard.press('Space');
      assert.ok(await page.locator('[data-plugin-menu="files-manager"]').evaluate(node=>node.open));
      await page.locator('[data-plugin-menu="files-manager"]').getByRole('link',{name:'Visão Geral',exact:true}).click();
      const overviewFrame=page;await overviewFrame.locator('[data-fm]').waitFor();await ready(overviewFrame);
      assert.equal(await page.locator('iframe').count(),0,'Plugins devem ocupar o DOM da moldura, sem iframe.');
      assert.equal(await page.getByRole('heading',{level:1}).count(),1);
      assert.ok(await page.locator('.admin-plugin').evaluate(node=>Math.abs(node.getBoundingClientRect().width-node.querySelector('.fm').getBoundingClientRect().width)<2));
      const identity=await page.locator('[data-action=upload]').evaluate(node=>({font:getComputedStyle(node).fontFamily,accent:getComputedStyle(node).backgroundColor,bodyFont:getComputedStyle(document.body).fontFamily,token:getComputedStyle(document.documentElement).getPropertyValue('--accent').trim()}));
      assert.equal(identity.font,identity.bodyFont);assert.equal(identity.accent,'rgb(37, 99, 235)');
      await page.screenshot({path:path.join(evidence,`${tree}-sidebar-overview.png`),fullPage:true});
      await page.locator('[data-plugin-menu="files-manager"]').getByRole('link',{name:'Configurações',exact:true}).click();await settings.getByRole('group',{name:'Uploads',exact:true}).waitFor();
      await saveSettings();assert.equal(new URL(page.url()).searchParams.get('route'),'settings');
      await navigate('Histórico');let confirmations=0;
      /** Aceita somente a confirmação real de recuperação sintética e conta prompts duplicados. */
      const confirmRestore=async dialog=>{ confirmations++;await dialog.accept(); };
      page.on('dialog',confirmRestore);
      const restoredSettings=page.waitForNavigation();await page.getByRole('button',{name:'Restaurar revisão',exact:true}).first().click();await restoredSettings;
      page.off('dialog',confirmRestore);assert.equal(confirmations,1,'O core e o plugin não devem duplicar a confirmação.');
      let frame=await open(); console.log(tree+': manager open');
      await frame.getByRole('button',{name:'Nova pasta',exact:true}).click();await prompt(frame,'Fotos');await frame.locator('[data-dropzone]').getByRole('button',{name:'Fotos',exact:true}).waitFor();
      const image={name:'pixel.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=','base64')};
      console.log(tree+': upload');
      await upload(frame,[{name:'nota.txt',mimeType:'text/plain',buffer:Buffer.from('Arquivo sintético pelo navegador.\n')},image]);
      await frame.getByRole('radio',{name:'Selecionado: nota.txt',exact:true}).check();await frame.getByRole('button',{name:'Renomear',exact:true}).click();await prompt(frame,'anotacao.txt');await frame.getByRole('radio',{name:'Selecionado: anotacao.txt',exact:true}).waitFor();
      await frame.getByRole('radio',{name:'Selecionado: anotacao.txt',exact:true}).check();
      const downloadEvent=page.waitForEvent('download');await frame.getByRole('button',{name:'Baixar',exact:true}).click();const download=await downloadEvent;assert.equal(download.suggestedFilename(),'anotacao.txt');assert.equal(fs.readFileSync(await download.path(),'utf8'),'Arquivo sintético pelo navegador.\n');
      await frame.getByRole('button',{name:'Mover',exact:true}).click();await prompt(frame,'Fotos');
      await frame.locator('[data-dropzone]').getByRole('button',{name:'Fotos',exact:true}).click();await frame.getByRole('radio',{name:'Selecionado: anotacao.txt',exact:true}).waitFor();
      await frame.getByRole('radio',{name:'Selecionado: anotacao.txt',exact:true}).check();await frame.getByRole('button',{name:'Mover para lixeira',exact:true}).click();await prompt(frame);await frame.locator('[data-empty]').waitFor({state:'visible'});
      await frame.getByRole('button',{name:'Lixeira',exact:true}).click();await frame.getByRole('radio',{name:'Selecionado: anotacao.txt',exact:true}).check();await frame.getByRole('button',{name:'Restaurar',exact:true}).click();await prompt(frame);await frame.locator('[data-empty]').waitFor({state:'visible'});
      await frame.getByRole('button',{name:'Lixeira',exact:true}).click();await frame.getByRole('radio',{name:'Selecionado: anotacao.txt',exact:true}).check();await frame.getByRole('button',{name:'Mover para lixeira',exact:true}).click();await prompt(frame);await frame.locator('[data-empty]').waitFor({state:'visible'});
      await frame.getByRole('button',{name:'Lixeira',exact:true}).click();await frame.getByRole('radio',{name:'Selecionado: anotacao.txt',exact:true}).check();await frame.getByRole('button',{name:'Excluir definitivamente',exact:true}).click();await prompt(frame,'anotacao.txt');await frame.locator('[data-empty]').waitFor({state:'visible'});
      await frame.getByRole('button',{name:'Lixeira',exact:true}).click();await frame.getByRole('button',{name:'Subir',exact:true}).click();await frame.getByRole('radio',{name:'Selecionado: pixel.png',exact:true}).check();await frame.getByRole('button',{name:'Visualizar',exact:true}).click();
      await frame.locator('[data-preview-image]').waitFor({state:'visible'});await frame.waitForFunction(()=>{const image=document.querySelector('[data-preview-image]');return image.complete&&image.naturalWidth>0;});await frame.getByRole('button',{name:'Fechar',exact:true}).click();
      await frame.getByRole('button',{name:'Grade',exact:true}).click();await page.screenshot({path:path.join(evidence,`${tree}-desktop.png`),fullPage:true});
      await frame.getByRole('button',{name:'Privado',exact:true}).click();await ready(frame);await upload(frame,[{name:'privado.txt',mimeType:'text/plain',buffer:Buffer.from('Conteúdo privado sintético.')}]);
      assert.ok(fs.existsSync(path.join(sandbox.temporary,'storage/files/media/privado.txt')));
      const transfer=await frame.evaluateHandle(()=>{const data=new DataTransfer();data.items.add(new File(['Arquivo por arrastar e soltar.'],'arrastado.txt',{type:'text/plain'}));return data;});
      await frame.locator('[data-dropzone]').dispatchEvent('drop',{dataTransfer:transfer});await frame.getByRole('radio',{name:'Selecionado: arrastado.txt',exact:true}).waitFor();await transfer.dispose();
      const anonymous=await browser.newContext();assert.equal((await anonymous.request.get(sandbox.base+'/cms-admin/?view=plugin-api&plugin=files-manager&operation=download&scope=private&path=privado.txt')).status(),403);await anonymous.close();
      // Recuperação após MIME falso: erro visível e um novo envio válido funciona no mesmo input.
      const pending=page.waitForEvent('filechooser');await frame.getByRole('button',{name:'Enviar arquivos',exact:true}).click();await (await pending).setFiles([{name:'falso.jpg',mimeType:'image/jpeg',buffer:Buffer.from('Isto não é uma imagem.')}]);await frame.locator('[data-status].fm-error').waitFor({state:'visible'});
      await upload(frame,[{name:'depois-do-erro.txt',mimeType:'text/plain',buffer:Buffer.from('Recuperação de erro.')}]);
      await navigate('Configurações');
      await settings.getByLabel('Público',{exact:true}).fill('');await settings.getByLabel('Privado',{exact:true}).fill('');await saveSettings();
      assert.equal(await settings.getByLabel('Público',{exact:true}).inputValue(),'');assert.equal(await settings.getByLabel('Privado',{exact:true}).inputValue(),'');
      frame=await open();await upload(frame,[{name:'raiz.txt',mimeType:'text/plain',buffer:Buffer.from('Raiz pública sintética.')}]);assert.ok(fs.existsSync(path.join(sandbox.application,'public_html/raiz.txt')));
      await navigate('Configurações');await settings.getByLabel('Público',{exact:true}).fill('media');await settings.getByLabel('Privado',{exact:true}).fill('media');await saveSettings();
      await navigate('Configurações globais');await page.locator('[data-admin-locale]').selectOption('en-us');await page.getByRole('button',{name:'Salvar alterações',exact:true}).click();
      frame=await open('Overview');await frame.getByRole('region',{name:'Files manager',exact:true}).waitFor();
      await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(evidence,`${tree}-mobile.png`),fullPage:false});
      assert.ok(await frame.evaluate(()=>document.documentElement.scrollWidth<=innerWidth), 'O gerenciador não deve produzir rolagem horizontal no celular.');
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth), 'O painel não deve produzir rolagem horizontal no celular.');
      // Drawer móvel: fechamento por Escape e retorno de foco, fundo inerte e destino acessível.
      const toggle=page.getByRole('button',{name:'Open navigation',exact:true});await toggle.click();
      await page.screenshot({path:path.join(evidence,`${tree}-mobile-navigation.png`),fullPage:false});
      // Contenção de foco: Tab no último destino volta à marca e Shift+Tab faz o caminho inverso.
      const navControls=page.locator('#admin-navigation').locator('a[href],button,summary').filter({visible:true});
      await navControls.last().focus();await page.keyboard.press('Tab');assert.ok(await navControls.first().evaluate(node=>node===document.activeElement));
      await page.keyboard.press('Shift+Tab');assert.ok(await navControls.last().evaluate(node=>node===document.activeElement));
      assert.equal(await toggle.getAttribute('aria-expanded'),'true');assert.ok(await page.locator('.workspace').evaluate(node=>node.inert));
      await page.keyboard.press('Escape');assert.equal(await toggle.getAttribute('aria-expanded'),'false');assert.ok(await toggle.evaluate(node=>node===document.activeElement));
      await toggle.click();
      assert.equal(await page.locator('[data-plugin-menu="files-manager"] > summary').evaluate(node=>node.textContent.replace(/\s/g,'')),'Images⌄');
      await page.locator('[data-plugin-menu="files-manager"]').getByRole('link',{name:'Overview',exact:true}).click();
      let screen=page;await screen.locator('[data-fm]').waitFor();await ready(screen);
      await screen.getByRole('radio',{name:'Selected: pixel.png',exact:true}).check();await screen.getByRole('button',{name:'Move to trash',exact:true}).click();
      const deleteDialog=screen.locator('[data-prompt]');await deleteDialog.waitFor({state:'visible'});await deleteDialog.getByRole('button',{name:'Confirm',exact:true}).click();
      await screen.getByRole('radio',{name:'Selected: pixel.png',exact:true}).waitFor({state:'hidden'});
      await navigate('Trash');screen=page;await screen.locator('[data-fm]').waitFor();await ready(screen);
      await screen.getByRole('radio',{name:'Selected: pixel.png',exact:true}).check();await screen.getByRole('button',{name:'Restore',exact:true}).waitFor({state:'visible'});
      await page.evaluate(()=>window.scrollTo(0,0));
      await page.screenshot({path:path.join(evidence,`${tree}-sidebar-trash-mobile.png`),fullPage:true});
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));assert.ok(await screen.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
      await screen.getByRole('button',{name:'Private',exact:true}).click();await ready(screen);await screen.getByRole('button',{name:'Restore',exact:true}).waitFor({state:'visible'});
      await screen.getByRole('radio',{name:'Selected: pixel.png',exact:true}).waitFor({state:'hidden'});
      await screen.getByRole('button',{name:'Public',exact:true}).click();await ready(screen);await screen.getByRole('radio',{name:'Selected: pixel.png',exact:true}).check();await screen.getByRole('button',{name:'Restore',exact:true}).click();
      const restoreDialog=screen.locator('[data-prompt]');await restoreDialog.waitFor({state:'visible'});await restoreDialog.getByRole('button',{name:'Confirm',exact:true}).click();
      await navigate('Overview');screen=page;await screen.getByRole('radio',{name:'Selected: pixel.png',exact:true}).waitFor();
      await navigate('Settings');const translatedSettings=page;await translatedSettings.getByRole('heading',{name:'Settings',exact:true}).waitFor();await translatedSettings.getByRole('button',{name:'Save changes',exact:true}).waitFor();
      for (const width of [320,768,1024]) {
        await page.setViewportSize({width,height:900});
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`Configurações sem overflow em ${width}px.`);
        await navigate('Overview');await ready(page);
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`Gerenciador sem overflow em ${width}px.`);
        await page.screenshot({path:path.join(evidence,`${tree}-overview-${width}.png`),fullPage:true});
        await navigate('Settings');
      }
      await page.setViewportSize({width:1440,height:1000});
      await navigate('Templates');await page.getByRole('heading',{name:'Templates',exact:true}).waitFor();
      await page.screenshot({path:path.join(evidence,`${tree}-templates.png`),fullPage:true});
      await navigate('Global settings');await page.locator('[data-admin-locale]').waitFor();
      await page.screenshot({path:path.join(evidence,`${tree}-global-settings.png`),fullPage:true});
      assert.deepEqual(errors.filter(message=>!message.includes('status of 422')&&!message.includes('status of 403')),[]);
      assert.ok(!/PHP (?:Warning|Fatal|Parse)/.test(sandbox.logs()),sandbox.logs());
      results.push({tree,status:'passed',screenshots:[`${tree}-desktop.png`,`${tree}-mobile.png`],checks:['sidebar dropdown/overview/settings/trash','soft delete/restore from sidebar','login','settings/defaults','multiple uploads via chooser','file drop','rename','move','download bytes','trash/restore/purge','image preview','private access','invalid MIME/recovery','blank roots','global language','mobile drawer/Escape/focus/inert','shared typography/colors/single heading/no iframe','desktop/mobile overflow','console/CSP']});
    } catch(error) { await page.screenshot({path:path.join(evidence,tree+'-failure.png'),fullPage:true}); console.error('URLs',page.frames().map(frame=>frame.url())); throw error; } finally {await context.close();await sandbox.close();}
  }
} finally {await browser.close();fs.writeFileSync(path.join(evidence,'results.json'),JSON.stringify(results,null,2)+'\n');}
console.log(JSON.stringify(results,null,2));
