/** Validate actual static brand assets and the PNG frames inside the derived ICO in source and exported applications; uses synthetic fixtures only. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications,fixture,inspect} from './helpers/extension-media.mjs';
for(const application of applications){
  test(`${application}: original administrative SVGs and seven ICO frames remain valid`,()=>{
    const context=fixture(application);
    try{
      const sizes=inspect(context,"$base=$argv[1].'/public_html/cms-admin/assets/';foreach(glob($base.'brand/*.svg') as $file)DBLESSCMS\\ExtensionMedia::icon($file);DBLESSCMS\\ExtensionMedia::ico($base.'favicon.ico');$ico=file_get_contents($base.'favicon.ico');$sizes=[];for($i=0;$i<unpack('v',substr($ico,4,2))[1];$i++)$sizes[]=ord($ico[6+$i*16])?:256;echo json_encode($sizes);");
      assert.deepEqual(sizes,[16,24,32,48,64,128,256]);
      for(const name of ['dbless-cms-symbol.svg','dbless-cms.svg','dbless-cms-inverse.svg'])assert.ok(fs.statSync(path.join(context.app,'public_html/cms-admin/assets/brand',name)).size>0);
    }finally{context.close();}
  });
}
