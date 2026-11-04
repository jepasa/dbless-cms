/** Locale tutorial contracts: bounded declarations, dictionary separation, canonical example inheritance and explicit safe fallback; all files/credentials are disposable. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications,fixture,inspect} from './helpers/extension-media.mjs';
/** Replace only a fixture document; receive file/value, write readable JSON or propagate I/O errors. */
function write(file,value){fs.writeFileSync(file,JSON.stringify(value));}
for(const application of applications){
 test(`${application}: declared locale documents stay outside dictionaries and inherit canonical code without GET writes`,()=>{
  const c=fixture(application);try{
   const root=path.join(c.app,'system/plugins/menu'),before=fs.readFileSync(path.join(root,'languages/presentation-pt-br.json'));
   const r=inspect(c,`$plugins=new DBLESSCMS\\Plugins($s);$m=$plugins->get('menu');$root=$s->config->root.'/plugins/menu';$language=new DBLESSCMS\\Language($root.'/languages');$pt=DBLESSCMS\\PluginPresentation::select($root,$m,'pt-br',$plugins->language('menu','pt-br'));$en=DBLESSCMS\\PluginPresentation::select($root,$m,'en-us',$plugins->language('menu','en-us'));echo json_encode([$pt,$en,$language->invalid(),array_keys($language->all()),array_values(array_filter(array_keys($language->messages()),static fn(string $key):bool=>str_starts_with($key,'menu.presentation.')))]);`);
   assert.equal(r[0].warning,null);assert.equal(r[0].document.language,'pt-br');assert.match(r[0].document.title,/apresentação/);assert.equal(r[1].document.language,'en');assert.equal(r[1].warning,null);assert.equal(r[0].document.sections[5].code,r[1].document.sections[5].code);assert.deepEqual(r.slice(2),[[],['en-us','pt-br'],[]]);assert.deepEqual(fs.readFileSync(path.join(root,'languages/presentation-pt-br.json')),before);
  }finally{c.close();}
 });
 test(`${application}: unsafe locale declarations and stale/unsafe source outlines fail while absent optional content has visible English fallback`,()=>{
  const c=fixture(application);try{
   const root=path.join(c.app,'system/plugins/menu'),manifestFile=path.join(root,'plugin.json'),manifest=JSON.parse(fs.readFileSync(manifestFile)),canonical=JSON.parse(fs.readFileSync(path.join(root,'presentation.json'))),file=path.join(root,'languages/presentation-pt-br.json'),translated=JSON.parse(fs.readFileSync(file));
   for(const map of [null,[],{'pt_BR':'languages/presentation-pt_BR.json'},{'pt-br':'../presentation.json'},{'pt-br':'languages/../presentation.json'},{'pt-br':'https://example.invalid/a'},{'pt-br':'languages/presentation-en-us.json'},Object.fromEntries(Array.from({length:21},(_,i)=>['xx-'+String(i).padStart(2,'0'),'languages/presentation-xx-'+String(i).padStart(2,'0')+'.json']))]){
    const next={...manifest,presentationLocales:map};if(Array.isArray(map)&&!map.length)next.presentationLocales=['languages/presentation-pt-br.json'];write(manifestFile,next);assert.equal(inspect(c,"echo json_encode(isset((new DBLESSCMS\\Plugins($s))->invalid()['menu']));"),true);
   }write(manifestFile,manifest);
   for(const mutate of [d=>{d.language='en';},d=>{d.sections.pop();},d=>{d.sections[0].id=d.sections[1].id;},d=>{d.sections[0].steps=[];},d=>{d.sections[0].body=[' '];},d=>{d.sections.reverse();},d=>{d.sections[5].code='{{menu.changed.items}}';},d=>{d.sections[0].html='<script>';},d=>{d.lead='x'.repeat(65537);}]){
    const next=structuredClone(translated);mutate(next);write(file,next);const result=inspect(c,`$p=new DBLESSCMS\\Plugins($s);$r=DBLESSCMS\\PluginPresentation::select($s->config->root.'/plugins/menu',$p->get('menu'),'pt-br');echo json_encode([$r['document']['language'],$r['warning'],isset($p->invalid()['menu'])]);`);assert.deepEqual(result,['en','invalid',false]);
   }
   fs.writeFileSync(file,'{broken');assert.equal(inspect(c,`echo json_encode(DBLESSCMS\\PluginPresentation::select($s->config->root.'/plugins/menu',json_decode(file_get_contents($s->config->root.'/plugins/menu/plugin.json'),true),'pt-br')['warning']);`),'invalid');
   fs.unlinkSync(file);const selected=()=>inspect(c,`$m=json_decode(file_get_contents($s->config->root.'/plugins/menu/plugin.json'),true);$r=DBLESSCMS\\PluginPresentation::select($s->config->root.'/plugins/menu',$m,'pt-br');echo json_encode([$r['document']['language'],$r['warning']]);`);assert.deepEqual(selected(),['en','invalid']);
   const outside=path.join(c.temporary,'outside-presentation.json');write(outside,translated);fs.symlinkSync(outside,file);assert.deepEqual(selected(),['en','invalid']);fs.unlinkSync(file);write(file,translated);
   const languageDirectory=path.join(root,'languages'),movedDirectory=path.join(c.temporary,'moved-languages');fs.renameSync(languageDirectory,movedDirectory);fs.symlinkSync(movedDirectory,languageDirectory);assert.deepEqual(selected(),['en','invalid']);fs.unlinkSync(languageDirectory);fs.renameSync(movedDirectory,languageDirectory);
   const missing={...manifest,presentationLocales:{}};write(manifestFile,missing);assert.deepEqual(selected(),['en','missing']);
   write(manifestFile,manifest);const wrong=structuredClone(canonical);delete wrong.sections[0].id;write(path.join(root,'presentation.json'),wrong);assert.equal(inspect(c,"echo json_encode(isset((new DBLESSCMS\\Plugins($s))->invalid()['menu']));"),true);
  }finally{c.close();}
 });
 test(`${application}: legacy documents/dictionary projections remain readable and canonical corruption is never hidden by locale fallback`,()=>{
  const c=fixture(application);try{
   const root=path.join(c.app,'system/plugins/menu'),manifestFile=path.join(root,'plugin.json'),manifest=JSON.parse(fs.readFileSync(manifestFile));delete manifest.presentationLocales;write(manifestFile,manifest);
   write(path.join(root,'presentation.json'),{language:'en',title:'Menu',lead:'Legacy source',sections:[{title:'Start',body:[],steps:['Open Menu.'],code:'{{menu.main.name}}'}]});
   for(const locale of ['en-us','pt-br']){const file=path.join(root,'languages',locale+'.json'),d=JSON.parse(fs.readFileSync(file));Object.assign(d.messages,{'menu.presentation.title':'Menu','menu.presentation.lead':'Manual legado','menu.presentation.sections.0.title':'Iniciar','menu.presentation.sections.0.steps.0':'Abra o Menu.'});write(file,d);}
   const result=inspect(c,`$p=new DBLESSCMS\\Plugins($s);echo json_encode(DBLESSCMS\\PluginPresentation::select($s->config->root.'/plugins/menu',$p->get('menu'),'pt-br',$p->language('menu','pt-br')));`);assert.equal(result.document.language,'pt-br');assert.equal(result.document.lead,'Manual legado');assert.equal(result.document.sections[0].code,'{{menu.main.name}}');assert.equal(result.warning,null);
   const fallback=inspect(c,`$p=new DBLESSCMS\\Plugins($s);echo json_encode(DBLESSCMS\\PluginPresentation::select($s->config->root.'/plugins/menu',$p->get('menu'),'ar',$p->language('menu','ar')));`);assert.equal(fallback.document.language,'en');assert.equal(fallback.warning,'missing');
   fs.writeFileSync(path.join(root,'presentation.json'),'{broken');const status=inspect(c,`try{DBLESSCMS\\PluginPresentation::select($s->config->root.'/plugins/menu',json_decode(file_get_contents($s->config->root.'/plugins/menu/plugin.json'),true),'pt-br');echo '200';}catch(DBLESSCMS\\HttpError $e){echo json_encode($e->status);}`);assert.equal(status,422);
  }finally{c.close();}
 });
}
