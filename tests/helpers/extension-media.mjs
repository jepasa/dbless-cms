/** Portable, isolated extension-media fixtures: only code/versioned resources are copied; credentials and mail stay synthetic. */
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import net from 'node:net';
import {once} from 'node:events';
import {spawn,spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
export const root=fileURLToPath(new URL('../../',import.meta.url));
export const password='Synthetic-Media-Password-2026';
export const applications=[path.join(root,'src'),...(process.env.DBLESS_CMS_PACKAGE_ROOT?[process.env.DBLESS_CMS_PACKAGE_ROOT]:[])];

/** Select browser targets without repeating established source evidence; package-only mode requires an explicit synthetic package path. */
export const browserApplications=process.env.DBLESS_CMS_BROWSER_PACKAGE_ONLY==='1'?(process.env.DBLESS_CMS_PACKAGE_ROOT?[process.env.DBLESS_CMS_PACKAGE_ROOT]:(()=>{throw new Error('Package-only browser validation requires DBLESS_CMS_PACKAGE_ROOT.');})()):applications;

/** Copy trusted code and initial content into a new fixture; receives application path, returns temporary paths/env/cleanup, propagates I/O and PHP errors. */
export function fixture(application=applications[0]){
  const temporary=fs.mkdtempSync(path.join(os.tmpdir(),'dbless-extension-media-')),app=path.join(temporary,'src'),contents=path.join(temporary,'contents');
  try{
    for(const relative of ['system/bootstrap.php','system/bin/install-posts.php','system/core.php','system/lib','system/admin','system/config/settings.php','system/config/schemas','system/templates','system/languages','system/plugins','public_html/index.php','public_html/cms-admin','public_html/cms-assets','public_html/templates','public_html/plugins']){const destination=path.join(app,relative);fs.mkdirSync(path.dirname(destination),{recursive:true});fs.cpSync(path.join(application,relative),destination,{recursive:true});}
    for(const id of fs.readdirSync(path.join(application,'system/templates'))){const manifest=JSON.parse(fs.readFileSync(path.join(application,'system/templates',id,'template.json'),'utf8'));for(const file of Object.values(manifest.assets.files)){if(!file.startsWith('/media/'))continue;if(!/^\/media\/[A-Za-z0-9_./-]+\.(?:png|jpe?g|gif|webp|avif|svg|ico)$/i.test(file)||file.includes('..'))throw new Error('Unsafe bundled template media.');const source=path.join(application,'public_html',file.slice(1)),destination=path.join(app,'public_html',file.slice(1));if(fs.lstatSync(source).isSymbolicLink())throw new Error('Bundled media link forbidden.');fs.mkdirSync(path.dirname(destination),{recursive:true});fs.copyFileSync(source,destination);}}
    if(fs.existsSync(path.join(application,'system/config/production.php')))fs.copyFileSync(path.join(application,'system/config/production.php'),path.join(app,'system/config/production.php'));
    fs.cpSync(path.join(application,fs.existsSync(path.join(application,'system/resources/contents'))?'system/resources/contents':'system/storage/contents'),contents,{recursive:true});
    const hash=spawnSync('php',['-r',"echo password_hash('Synthetic-Media-Password-2026',PASSWORD_ARGON2ID,['memory_cost'=>8192,'time_cost'=>1]);"],{encoding:'utf8'});if(hash.error)throw hash.error;if(hash.status!==0)throw new Error(hash.stderr);
    const config=path.join(temporary,'app.json');fs.writeFileSync(config,JSON.stringify({schema_version:1,environment:'production',editor:{enabled:true,username:'editor',password_hash:hash.stdout},contact_enabled:false}));
    const env={...process.env,DBLESS_CMS_IGNORE_DOTENV:'1',DBLESS_CMS_APP_CONFIG:config,DBLESS_CMS_CONTENTS_ROOT:contents,DBLESS_CMS_STORAGE_ROOT:path.join(temporary,'storage'),DBLESS_CMS_ADMIN_USER:'editor',DBLESS_CMS_ADMIN_PASSWORD:password,DBLESS_CMS_CONTACT_ENABLED:'0'};
    return {temporary,app,contents,env,close(){fs.rmSync(temporary,{recursive:true,force:true});}};
  }catch(error){fs.rmSync(temporary,{recursive:true,force:true});throw error;}
}

/** Query actual registries in isolated PHP; receives fixture/expression, returns parsed JSON, fails on process errors or warnings without exposing credentials. */
export function inspect(context,expression){
  const result=spawnSync('php',['-d','disable_functions=mail','-d','display_errors=stderr','-r',"require $argv[1].'/system/bootstrap.php';$s=new DBLESSCMS\\JsonStore(new DBLESSCMS\\Config());"+expression,context.app],{env:context.env,encoding:'utf8',timeout:10000});
  if(result.error)throw result.error;if(result.status!==0||result.stderr)throw new Error(result.stderr||result.stdout);return JSON.parse(result.stdout);
}

/** Start an ephemeral loopback fixture for real browser usage; receives application/options with optional synthetic-mail and pre-start fixture callback, returns base/logs/async close and removes the fixture even on startup failure. */
export async function server(application=applications[0],options={}){
  const context=fixture(application),socket=net.createServer();let processHandle;
  try{
    socket.listen(0,'127.0.0.1');await once(socket,'listening');const port=socket.address().port;await new Promise(resolve=>socket.close(resolve));
    const base=`http://127.0.0.1:${port}`,siteFile=path.join(context.contents,'site.json'),site=JSON.parse(fs.readFileSync(siteFile));site.settings.canonical=base;site.settings.adminLocale='en-us';fs.writeFileSync(siteFile,JSON.stringify(site));
    fs.mkdirSync(path.join(context.temporary,'scripts'));fs.copyFileSync(path.join(root,'scripts/dev-router.php'),path.join(context.temporary,'scripts/dev-router.php'));
    const mailArguments=[];
    if(options.syntheticMail===true){const script=path.join(context.temporary,'synthetic-sendmail.sh'),output=path.join(context.temporary,'synthetic-mail.txt');if(!/^[A-Za-z0-9_./-]+$/.test(output))throw new Error('Unsafe synthetic mail path.');fs.writeFileSync(script,'#!/bin/sh\ncat >> \"'+output+'\"\nexit 0\n',{mode:0o700});mailArguments.push('-d','disable_functions=','-d','sendmail_path='+script);const configFile=path.join(context.temporary,'app.json'),settings=JSON.parse(fs.readFileSync(configFile));settings.contact_enabled=true;fs.writeFileSync(configFile,JSON.stringify(settings));}
    if(options.configure)await options.configure(context,base);
    processHandle=spawn('php',['-d','disable_functions=mail',...mailArguments,'-d','upload_max_filesize=12M','-d','post_max_size=13M','-d',`session.save_path=${context.temporary}`,'-S',`127.0.0.1:${port}`,'-t',path.join(context.app,'public_html'),path.join(context.temporary,'scripts/dev-router.php')],{env:context.env});
    let logs='';processHandle.stderr.on('data',data=>{logs+=data;});processHandle.stdout.on('data',data=>{logs+=data;});
    /** Stop this server and delete only its fixture; no arguments/return value, preserves logs in closure and propagates cleanup errors. */
    async function close(){if(processHandle.exitCode===null){const done=once(processHandle,'exit');processHandle.kill();await done;}context.close();}
    for(let attempt=0;attempt<120;attempt++){
      try{if((await fetch(base+'/cms-admin/')).status===200)return {...context,base,logs:()=>logs,close};}catch{/* Bounded startup wait; final failure includes server logs. */}
      await new Promise(resolve=>setTimeout(resolve,50));
    }
    throw new Error('Fixture startup failed: '+logs);
  }catch(error){if(socket.listening)socket.close();if(processHandle&&processHandle.exitCode===null){const done=once(processHandle,'exit');processHandle.kill();await done;}context.close();throw error;}
}
