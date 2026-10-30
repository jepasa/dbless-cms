/** Identity regressions use synthetic state: canonical PHP namespace/environment, cookie isolation and no retired namespace alias. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fixture,inspect,applications} from './helpers/extension-media.mjs';

for(const application of applications){
  test(`${application}: canonical namespace and no retired alias`,()=>{
    const context=fixture(application);
    try{
      const retired=['CMS','2JSON'].join('');
      const output=inspect(context,"echo json_encode([get_class($s),class_exists('"+retired+"\\\\JsonStore'),$s->config->configured()]);");
      assert.deepEqual(output,['DBLESSCMS\\JsonStore',false,true]);
      const config=fs.readFileSync(path.join(context.app,'system/lib/Config.php'),'utf8');assert.match(config,/DBLESS_CMS_ADMIN_USER/);assert.match(config,/DBLESS_CMS_IGNORE_DOTENV/);assert.doesNotMatch(config,new RegExp(retired));
    }finally{context.close();}
  });
  test(`${application}: session cookie and plugin classes use canonical identity`,()=>{
    const context=fixture(application);
    try{
      const output=inspect(context,"$p=new DBLESSCMS\\Plugins($s);DBLESSCMS\\Security::start($s->config);echo json_encode([session_name(),array_column($p->all(),'class'),$p->invalid()]);");
      assert.equal(output[0],'DBLESSCMSSESSID');assert.deepEqual(output[2],[]);for(const name of output[1])assert.ok(name.startsWith('DBLESSCMS\\'));
    }finally{context.close();}
  });
}
