/** Synthetic delivery regression: exclusive modes across direct/slot/legacy readers, preserved assignments, literal missing and falsy values. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications,fixture,inspect} from './helpers/extension-media.mjs';
for(const application of applications){
 test(`${application}: Menu/Slides modes isolate every delivery channel without mutating legacy reads`,()=>{
  const c=fixture(application);try{
   const files=['menu/menus.json','slides/slides.json'].map(f=>path.join(c.contents,f)),before=files.map(f=>fs.readFileSync(f));
   const legacy=inspect(c,`$ctx=new DBLESSCMS\\ContentContext($s,'essencial','','home');$r=new DBLESSCMS\\ContentReferences($ctx);echo json_encode([$r->value('menu.demo-principal.name'),$r->value('slides.exemplo-banners.name'),$r->value('template.primary-menu.items'),$r->value('template.slides-home-hero.items')]);`);
   assert.ok(legacy.every(Boolean));files.forEach((f,i)=>assert.deepEqual(fs.readFileSync(f),before[i]));
   const result=inspect(c,`$out=[];foreach(['template','mustache'] as $mode){foreach(['menu','slides'] as $plugin){$repository=$plugin==='menu'?new DBLESSCMS\\MenuStore($s):new DBLESSCMS\\SlidesStore($s);$snapshot=$repository->read();$repository->mutate($snapshot['revision'],static function(array $d)use($mode,$plugin):array{$group=$plugin==='menu'?'menus':'collections';$id=$plugin==='menu'?'demo-principal':'exemplo-banners';$d[$group][$id]['insertionMode']=$mode;return $d;});}$r=new DBLESSCMS\\ContentReferences(new DBLESSCMS\\ContentContext($s,'essencial','','home'));$out[$mode]=[$r->value('menu.demo-principal.name'),$r->value('menu.id.demo-principal.name'),$r->value('menu.slug.demo-principal.name'),$r->value('slides.exemplo-banners.name'),$r->value('slides.id.exemplo-banners.name'),$r->value('slides.slug.exemplo-banners.name'),$r->value('template.primary-menu.items'),$r->value('template.slides-home-hero.items'),(new DBLESSCMS\\Navigation($s,'essencial',''))->location('header-primary'),(new DBLESSCMS\\Slides($s,'essencial'))->location('home-hero')];}$bad=[];foreach(['menu','slides'] as $plugin){$repository=$plugin==='menu'?new DBLESSCMS\\MenuStore($s):new DBLESSCMS\\SlidesStore($s);$d=$repository->read()['data'];$group=$plugin==='menu'?'menus':'collections';$id=$plugin==='menu'?'demo-principal':'exemplo-banners';$d[$group][$id]['insertionMode']='page';try{$repository::validate($d);$bad[]=200;}catch(DBLESSCMS\\HttpError $e){$bad[]=$e->status;}}echo json_encode([$out,$bad]);`);
   assert.deepEqual(result[0].template.slice(0,6),Array(6).fill(null));assert.ok(result[0].template.slice(6).every(Boolean));assert.ok(result[0].mustache.slice(0,6).every(Boolean));assert.deepEqual(result[0].mustache.slice(6),Array(4).fill(null));assert.deepEqual(result[1],[422,422]);
   files.forEach((f,i)=>assert.deepEqual(JSON.parse(fs.readFileSync(f)).assignments,JSON.parse(before[i]).assignments));
  }finally{c.close();}
 });
 test(`${application}: missing references remain literal while falsy fields, lexical scope and section semantics remain intact`,()=>{
  const c=fixture(application);try{
   const file=path.join(c.contents,'widgets/widgets.json'),d=JSON.parse(fs.readFileSync(file));d.reusable['falsy']={id:'falsy',key:'falsy',type:'json',value:'{"zero":0,"off":false,"empty":"","nil":null,"items":[]}',description:'Synthetic',deleted:false};fs.writeFileSync(file,JSON.stringify(d));
   const r=inspect(c,`$ctx=new DBLESSCMS\\ContentContext($s,'essencial','','home');$html=DBLESSCMS\\ContentMarkup::render(['html'=>'<p>{{ slides.absent.name }}|{{menu.absent.name}}|{{widgets.absent}}|{{forms.absent}}|{{unknown.item.name}}</p><p>{{widgets.falsy.data.zero}}|{{widgets.falsy.data.off}}|{{widgets.falsy.data.empty}}|{{widgets.falsy.data.nil}}</p>{{#slides.absent.items}}<b>BAD</b>{{/slides.absent.items}}{{^slides.absent.items}}<p>Alternative</p>{{/slides.absent.items}}<p>{{widgets.falsy.data.absent}}</p>'],$ctx);$refs=new DBLESSCMS\\ContentReferences($ctx);$refs->value('menu.absent.name');$lexical=DBLESSCMS\\Mustache::render('{{#menu.absent}}{{name}}{{/menu.absent}}',['menu'=>['absent'=>[['name'=>'Lexical']]]],$refs->value(...),$refs->missing(...));echo json_encode([$html,$lexical,DBLESSCMS\\ContentMarkup::diagnostics($s)]);`);
   for(const token of ['{{ slides.absent.name }}','{{menu.absent.name}}','{{widgets.absent}}','{{forms.absent}}','{{unknown.item.name}}','{{widgets.falsy.data.absent}}'])assert.ok(r[0].includes(token),token);
   assert.match(r[0],/<p>0\|false\|\|<\/p>/);assert.match(r[0],/<p>Alternative<\/p>/);assert.doesNotMatch(r[0],/BAD|\{\{[#^/]/);assert.equal(r[1],'Lexical');assert.ok(r[2].length);
  }finally{c.close();}
 });
}
