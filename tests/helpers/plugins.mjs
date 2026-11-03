/** Aplicação de upload totalmente sintética: copia código autorizado, sem .env, app.json ou mídia reais. */
import fs from 'node:fs';
import {installTemplate} from './template-fixture.mjs';
import path from 'node:path';
import net from 'node:net';
import { once } from 'node:events';
import { spawn, spawnSync } from 'node:child_process';
import { root, appRoot, workspace, environment } from './runtime.mjs';

/** Cria sandbox pt-br isolado para mutações públicas/privadas; recebe árvore, retorna paths sem iniciar servidor e propaga falhas de I/O. */
export function pluginSandbox(tree) {
  const temporary = workspace(tree), application = path.join(temporary, 'src');
  for (const relative of ['system/bootstrap.php','system/core.php','system/lib','system/admin','system/config/settings.php','system/config/schemas','system/templates','system/languages','system/plugins','public_html/index.php','public_html/.htaccess','public_html/templates','public_html/plugins','public_html/cms-admin','public_html/cms-assets']) {
    const destination = path.join(application, relative); fs.mkdirSync(path.dirname(destination), {recursive:true}); fs.cpSync(path.join(appRoot(tree), relative), destination, {recursive:true});
  }
  const id=installTemplate(application);const siteFile=path.join(temporary,'contents/site.json'),site=JSON.parse(fs.readFileSync(siteFile));site.template=id;site.settings.adminLocale='pt-br';fs.writeFileSync(siteFile,JSON.stringify(site,null,2)+'\n');
  const marker = path.join(appRoot(tree),'system/config/production.php');
  if (fs.existsSync(marker)) fs.copyFileSync(marker,path.join(application,'system/config/production.php'));
  return {temporary, application};
}

/** Inicia PHP efêmero sem mail e com sessões sintéticas; recebe árvore, retorna servidor/base/contexto/cleanup assíncrono. */
export async function pluginServer(tree) {
  const context = pluginSandbox(tree), socket = net.createServer(); socket.listen(0,'127.0.0.1'); await once(socket,'listening');
  const port = socket.address().port; await new Promise(resolve => socket.close(resolve));
  const base = `http://127.0.0.1:${port}`;
  // Produção resolve URLs pela origem canônica: a cópia sintética deve apontar somente ao servidor efêmero.
  const siteFile=path.join(context.temporary,'contents/site.json');
  const site=JSON.parse(fs.readFileSync(siteFile,'utf8'));site.settings.canonical=base;fs.writeFileSync(siteFile,JSON.stringify(site,null,2)+'\n');
  const apache=process.env.DBLESS_CMS_BROWSER_APACHE==='1';
  const container=`dbless-cms-browser-${port}`;
  const command=apache?'docker':'php';
  // Uma única montagem mantém código/estado no mesmo filesystem para rename recuperável de pacotes.
  const args=apache?['run','--rm','--name',container,'-p',`127.0.0.1:${port}:80`,'-v',`${context.temporary}:/var/www/dbless-cms`,'-e','DBLESS_CMS_IGNORE_DOTENV=1','-e','DBLESS_CMS_CONTENTS_ROOT=/var/www/dbless-cms/contents','-e','DBLESS_CMS_STORAGE_ROOT=/var/www/dbless-cms/storage','-e','DBLESS_CMS_APP_CONFIG=/var/www/dbless-cms/app.json','-e','DBLESS_CMS_ADMIN_USER=editor','-e','DBLESS_CMS_ADMIN_PASSWORD=Synthetic-DBLESS-CMS-password-2026','-e','DBLESS_CMS_CONTACT_ENABLED=0','-v',`${path.join(root,'docker/uploads.ini')}:/usr/local/etc/php/conf.d/dbless-cms-uploads.ini:ro`,'dbless-cms-dev:2.0.0']:['-d','disable_functions=mail','-d','display_errors=0','-d','upload_max_filesize=12M','-d','post_max_size=13M','-d',`session.save_path=${context.temporary}`,'-S',`127.0.0.1:${port}`,'-t',path.join(context.application,'public_html'),path.join(root,'tests/helpers/server-router.php')];
  const server = spawn(command,args,{env:{...environment(context.temporary),DBLESS_CMS_APP_ROOT:context.application}});
  let logs = ''; server.stderr.on('data',data => logs += data); server.stdout.on('data',data => logs += data);
  /** Encerra processo e remove somente o sandbox criado; falhas PHP permanecem disponíveis no log. */
  async function close() {
    if (server.exitCode === null) {
      const exited=once(server,'exit');
      if(apache) spawnSync('docker',['stop','-t','2',container],{encoding:'utf8',timeout:10000});
      else server.kill();
      await exited;
    }
    fs.rmSync(context.temporary,{recursive:true,force:true});
  }
  try {
    for (let attempt=0; attempt<160; attempt++) {
      try { const response = await fetch(base+'/cms-admin/'); if (response.status===200) return {...context,base,server,close,logs:()=>logs}; }
      catch { /* O processo ainda está abrindo a porta; o prazo de tentativas é limitado. */ }
      await new Promise(resolve => setTimeout(resolve,50));
    }
    throw new Error(logs);
  } catch(error) { await close(); throw error; }
}
