/** Published site-asset release compiler; no runtime or live-state input discovery. */
import fs from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
import CleanCSS from 'clean-css';
import {minify as minifyJs} from 'terser';

/** Minify only verified published customizations, recompute addressed URLs and retain readable published sources; receives staging root, throws on syntax/hash errors and never touches live assets or drafts. */
export async function customizations(directory){
  const catalog=path.join(directory,'system/storage/customizations/catalog.json');let state;
  try{state=JSON.parse(await fs.readFile(catalog,'utf8'));}catch(error){if(error.code==='ENOENT')return;throw error;}
  const assets=path.join(directory,'public_html/customizations');
  for(const kind of ['css','js']){
    const descriptor=state.published.files[kind];if(!descriptor)continue;
    const original=path.join(assets,'site-'+descriptor.sha256+'.'+kind),bytes=await fs.readFile(original);
    if(createHash('sha256').update(bytes).digest('hex')!==descriptor.sha256||bytes.length!==descriptor.bytes)throw new Error('Published customization integrity failed.');
    let result;
    if(kind==='css'){const parsed=new CleanCSS({level:2,rebase:false,inline:['none']}).minify(bytes.toString());if(parsed.errors.length)throw new Error(parsed.errors.join('; '));result=parsed.styles;}
    else result=(await minifyJs(bytes.toString(),{compress:true,mangle:true,format:{comments:/^!|@license|@preserve|@copyright/}})).code;
    result=Buffer.from(result||'\n');const digest=createHash('sha256').update(result).digest('hex'),output=path.join(assets,'site-'+digest+'.'+kind);
    await fs.writeFile(output,result);if(output!==original)await fs.unlink(original);state.published.files[kind]={sha256:digest,bytes:result.length};
  }
  await fs.writeFile(catalog,JSON.stringify(state,null,2)+'\n');
}

