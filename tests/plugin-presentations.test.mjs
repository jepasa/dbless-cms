/** Bounded tutorial metadata and generic sidebar ordering regressions; only synthetic package copies are modified. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications,fixture,inspect} from './helpers/extension-media.mjs';
/** Replace fixture JSON for one candidate; takes file/value, writes only within the caller's disposable package and propagates I/O errors. */
function write(file,value){fs.writeFileSync(file,JSON.stringify(value));}
for(const application of applications){
  test(`${application}: required adjacent English tutorials reject missing, malformed, oversized and empty metadata`,()=>{
    const c=fixture(application);try{
      const directory=path.join(c.app,'system/plugins/posts'),manifestFile=path.join(directory,'plugin.json'),docFile=path.join(directory,'presentation.json'),manifest=JSON.parse(fs.readFileSync(manifestFile)),doc=JSON.parse(fs.readFileSync(docFile));
      const mutations=[d=>{delete d.presentation;},d=>{d.presentation='about.json';},d=>{d.presentation='docs/presentation.json';}];
      for(const mutate of mutations){const next=structuredClone(manifest);mutate(next);write(manifestFile,next);assert.equal(inspect(c,"echo json_encode(isset((new DBLESSCMS\\Plugins($s))->invalid()['posts']));"),true);}
      write(manifestFile,manifest);
      for(const mutate of [d=>{d.language='pt-br';},d=>{d.sections=[];},d=>{delete d.sections[0].steps;},d=>{d.sections[0].steps=[];},d=>{d.sections[0].steps=[' '];},d=>{d.lead='x'.repeat(65537);},d=>{d.sections[0].html='<img>'; }]){
        const next=structuredClone(doc);mutate(next);write(docFile,next);assert.equal(inspect(c,"echo json_encode(isset((new DBLESSCMS\\Plugins($s))->invalid()['posts']));"),true);
      }
      fs.unlinkSync(docFile);assert.equal(inspect(c,"echo json_encode(isset((new DBLESSCMS\\Plugins($s))->invalid()['posts']));"),true);
      write(docFile,doc);assert.deepEqual(inspect(c,"echo json_encode((new DBLESSCMS\\Plugins($s))->invalid());"),[]);
    }finally{c.close();}
  });
  test(`${application}: presentation precedes semantic trash regardless of route name and is last without trash`,()=>{
    const c=fixture(application);try{
      const file=path.join(c.app,'system/plugins/posts/plugin.json'),manifest=JSON.parse(fs.readFileSync(file));
      const original=inspect(c,"echo json_encode((new DBLESSCMS\\Plugins($s))->navigation('en-us'));");
      for(const menu of Object.values(original)){assert.equal(menu.items.at(-1).role,'trash');assert.deepEqual(menu.items.slice(-4,-1),[{separator:true},{presentation:true},{separator:true}]);}
      manifest.routes.bin=manifest.routes.trash;delete manifest.routes.trash;
      const trash=manifest.navigation.items.pop();trash.route='bin';manifest.navigation.items.pop();manifest.navigation.items.unshift(trash);write(file,manifest);
      let menu=inspect(c,"echo json_encode((new DBLESSCMS\\Plugins($s))->navigation('en-us')['posts']);");assert.equal(menu.items.at(-1).route,'bin');assert.equal(menu.items[0].route,'overview');
      manifest.navigation={label:'nav.pages',route:'overview'};write(file,manifest);
      menu=inspect(c,"echo json_encode((new DBLESSCMS\\Plugins($s))->navigation('pt-br')['posts']);");assert.equal(menu.dropdown,true);assert.deepEqual(menu.items.slice(1),[{separator:true},{presentation:true}]);
      manifest.navigation={label:'nav.pages',items:[{label:'pages.overview',route:'overview',role:'trash'},{label:'pages.trash',route:'bin',role:'trash'}]};write(file,manifest);assert.equal(inspect(c,"echo json_encode(isset((new DBLESSCMS\\Plugins($s))->invalid()['posts']));"),true);
      manifest.navigation.items[0].role='arbitrary';write(file,manifest);assert.equal(inspect(c,"echo json_encode(isset((new DBLESSCMS\\Plugins($s))->invalid()['posts']));"),true);
    }finally{c.close();}
  });
}
