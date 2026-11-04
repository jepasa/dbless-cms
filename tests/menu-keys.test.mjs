/** Portable Menu regressions on disposable code/content: cross-template keys, nonfatal compatibility, CAS ownership, legacy preservation and localized tutorials. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications,fixture,inspect} from './helpers/extension-media.mjs';
import {installTemplate} from './helpers/template-fixture.mjs';
/** Declare one shared Menu key on two different typed locations; receive fixture, change only temporary manifests and return their paths. */
function templates(c){
 installTemplate(c.app);
 const paths=['essencial','synthetic-theme'].map(id=>path.join(c.app,'system/templates',id,'template.json'));
 for(const [i,file] of paths.entries()){const m=JSON.parse(fs.readFileSync(file));const key=i?'sidebar-primary':'header-primary';m.menuLocations[key].types=['navbar','list'];m.menuLocations[key].defaultType='navbar';m.mustacheSlots['portable-nav']={plugin:'menu',location:key,description:'Synthetic portable navigation'};fs.writeFileSync(file,JSON.stringify(m));}
 return paths;
}
for(const application of applications){
 test(`${application}: portable keys resolve across templates and missing/type/depth cases only produce notices`,()=>{
  const c=fixture(application);try{
   const files=templates(c),menus=path.join(c.contents,'menu/menus.json'),d=JSON.parse(fs.readFileSync(menus));d.menus.portable={...structuredClone(d.menus['demo-principal']),id:'portable',name:'PORTABLE',insertionMode:'template',templateKeys:[{key:'portable-nav',type:'navbar'}]};fs.writeFileSync(menus,JSON.stringify(d));const before=fs.readFileSync(menus);
   const r=inspect(c,`$out=[];foreach(['essencial','synthetic-theme'] as $template){$r=new DBLESSCMS\\ContentReferences(new DBLESSCMS\\ContentContext($s,$template,'','home'));$out[]=$r->value('template.portable-nav.name');$out[]=(new DBLESSCMS\\Navigation($s,$template,''))->location($template==='essencial'?'header-primary':'sidebar-primary')['name'];}$refs=new DBLESSCMS\\ContentReferences(new DBLESSCMS\\ContentContext($s,'essencial','','home'));$direct=$refs->value('menu.portable.name');echo json_encode([$out,$direct]);`);
   assert.deepEqual(r,[Array(4).fill('PORTABLE'),null]);assert.deepEqual(fs.readFileSync(menus),before);
   const m=JSON.parse(fs.readFileSync(files[1]));delete m.mustacheSlots['portable-nav'];fs.writeFileSync(files[1],JSON.stringify(m));
   const missing=inspect(c,`$ctx=new DBLESSCMS\\ContentContext($s,'synthetic-theme','','home');$r=new DBLESSCMS\\ContentReferences($ctx);$v=$r->value('template.portable-nav.items');$html=DBLESSCMS\\ContentMarkup::render(['html'=>'<p>{{template.portable-nav.name}}</p>{{^template.portable-nav.items}}<p>Empty</p>{{/template.portable-nav.items}}'],$ctx);$d=(new DBLESSCMS\\MenuStore($s))->read()['data'];$w=DBLESSCMS\\MenuKeys::warnings($d['menus']['portable'],$s->templates->get('synthetic-theme'));echo json_encode([$v,$html,$w]);`);
   assert.equal(missing[0],null);assert.match(missing[1],/\{\{template.portable-nav.name\}\}/);assert.match(missing[1],/<p>Empty<\/p>/);assert.equal(missing[2][0].key,'keyMissing');
   const incompatible=inspect(c,`$d=(new DBLESSCMS\\MenuStore($s))->read()['data'];$menu=$d['menus']['portable'];$menu['templateKeys'][0]['type']='sidebar';$warnings=DBLESSCMS\\MenuKeys::warnings($menu,$s->templates->get('essencial'));$repository=new DBLESSCMS\\MenuStore($s);$repository->mutate($repository->read()['revision'],static function(array $d)use($menu):array{$d['menus']['portable']=$menu;return $d;});echo json_encode([$warnings,(new DBLESSCMS\\Navigation($s,'essencial',''))->slot('portable-nav')]);`);
   assert.equal(incompatible[0][0].key,'keyTypeMismatch');assert.equal(incompatible[1],null);
   const edge=inspect(c,`$repo=new DBLESSCMS\\MenuStore($s);$d=$repo->read()['data'];$menu=$d['menus']['portable'];$menu['templateKeys'][0]['type']='navbar';$manifest=$s->templates->get('essencial');$manifest['menuLocations']['header-primary']['maxDepth']=1;$depth=DBLESSCMS\\MenuKeys::warnings($menu,$manifest);$empty=$menu;$empty['templateKeys']=[];$none=DBLESSCMS\\MenuKeys::warnings($empty,$manifest);$repo->mutate($repo->read()['revision'],static function(array $d)use($menu):array{$d['menus']['portable']=$menu;$d['menus']['duplicate']=$menu;$d['menus']['duplicate']['id']='duplicate';return $d;});$d=$repo->read()['data'];$ambiguous=DBLESSCMS\\MenuKeys::warnings($d['menus']['portable'],$s->templates->get('essencial'),$d['menus']);$output=(new DBLESSCMS\\Navigation($s,'essencial',''))->slot('portable-nav');echo json_encode([$depth,$none,$ambiguous,$output]);`);
   assert.equal(edge[0][0].key,'keyDepthMismatch');assert.equal(edge[1][0].key,'keysEmpty');assert.equal(edge[2][0].key,'keyAmbiguous');assert.equal(edge[3],null);

  }finally{c.close();}
 });
 test(`${application}: v2 saves independent keys under menu CAS, preserves legacy/inactive bindings and claims ownership atomically`,()=>{
  const c=fixture(application);try{
   templates(c);
   const r=inspect(c,`$plugins=new DBLESSCMS\\Plugins($s);$plugins->instance('menu');$ctx=$plugins->context('menu','','pt-br','editor',true);$repo=new DBLESSCMS\\MenuStore($s);$before=$repo->read();$repo->mutate($before['revision'],static function(array $d)use($ctx):array{$d=DBLESSCMS\\MenuPlacement::apply($ctx,$d,'demo-principal',['placement_editor'=>'2','placementMode'=>'template','keys'=>['portable-nav','future-menu'],'keyTypes'=>['portable-nav'=>'navbar','future-menu'=>'list']]);return $d;});$first=$repo->read();$legacy=$first['data']['assignments']===$before['data']['assignments'];$repo->mutate($first['revision'],static function(array $d)use($ctx):array{$d['menus']['claimant']=$d['menus']['demo-rodape'];$d['menus']['claimant']['id']='claimant';return DBLESSCMS\\MenuPlacement::apply($ctx,$d,'claimant',['placement_editor'=>'2','placementMode'=>'template','keys'=>['portable-nav'],'keyTypes'=>['portable-nav'=>'list']]);});$claimed=$repo->read();$repo->mutate($claimed['revision'],static fn(array $d):array=>DBLESSCMS\\MenuPlacement::apply($ctx,$d,'claimant',['placement_editor'=>'2','placementMode'=>'mustache']));$inactive=$repo->read();$errors=[];try{$repo->mutate($first['revision'],static fn(array $d):array=>$d);$errors[]=200;}catch(DBLESSCMS\\HttpError $e){$errors[]=$e->status;}$bad=$inactive['data'];$bad['menus']['claimant']['templateKeys']=[['key'=>'../bad','type'=>'list']];try{DBLESSCMS\\MenuStore::validate($bad);$errors[]=200;}catch(DBLESSCMS\\HttpError $e){$errors[]=$e->status;}echo json_encode([$legacy,$claimed['data']['menus']['demo-principal']['templateKeys'],$inactive['data']['menus']['claimant']['templateKeys'],$inactive['data']['menus']['claimant']['insertionMode'],$errors,count($repo->history())]);`);
   assert.equal(r[0],true);assert.deepEqual(r[1],[{key:'future-menu',type:'list'}]);assert.deepEqual(r[2],[{key:'portable-nav',type:'list'}]);assert.equal(r[3],'mustache');assert.deepEqual(r[4],[409,422]);assert.equal(r[5],3);
  }finally{c.close();}
 });
 test(`${application}: Menu presentation uses complete dictionaries and leaves technical examples unchanged`,()=>{
  const c=fixture(application);try{
   const r=inspect(c,`$plugins=new DBLESSCMS\\Plugins($s);$doc=DBLESSCMS\\PluginPresentation::read($s->config->root.'/plugins/menu','presentation.json');$pt=DBLESSCMS\\PluginPresentation::localized($doc,$plugins->language('menu','pt-br'),'menu');$en=DBLESSCMS\\PluginPresentation::localized($doc,$plugins->language('menu','en-us'),'menu');echo json_encode([$pt,$en,$doc]);`);
   assert.equal(r[0].language,'pt-br');assert.match(r[0].lead,/chaves portáveis/);assert.equal(r[1].language,'en-us');assert.equal(r[1].lead,r[2].lead);assert.equal(r[0].sections[3].code,r[2].sections[3].code);
  }finally{c.close();}
 });
}
