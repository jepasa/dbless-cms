/** Check active source, metadata and filenames for retired branding; private documentary history and runtime/generated state are excluded, never read or printed as secrets. */
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=fileURLToPath(new URL('../',import.meta.url));
const metadata=JSON.parse(fs.readFileSync(path.join(root,'package.json'),'utf8'));
const privateDevelopment=metadata.repository?.url==='https://github.com/jepasa/dbless-cms-dev.git';
const retired=new RegExp(['CMS','2JSON'].join(''),'i');
const excluded=new Set(['.git','node_modules','.tools','.temp','build','coverage','test-results','playwright-report','dist']);
const findings=[];let checked=0;

/** Walk active regular files without following links; receives directory, records matching paths only and propagates I/O failures without printing file content. */
function inspect(directory){
  for(const entry of fs.readdirSync(directory,{withFileTypes:true})){
    const file=path.join(directory,entry.name),relative=path.relative(root,file).split(path.sep).join('/');
    if(excluded.has(entry.name)||entry.name.startsWith('.env')||relative==='src/system/storage'||relative==='src/system/config/app.json'||(privateDevelopment&&relative==='doc/history'))continue;
    if(entry.isSymbolicLink())continue;
    if(retired.test(relative))findings.push(relative+' (filename)');
    if(entry.isDirectory()){inspect(file);continue;}
    if(!entry.isFile())continue;
    const bytes=fs.readFileSync(file);if(bytes.includes(0))continue;
    checked++;if(retired.test(bytes.toString('utf8')))findings.push(relative);
  }
}
inspect(root);
if(!privateDevelopment&&fs.existsSync(path.join(root,'doc/history')))throw new Error('Private documentary history cannot be distributed.');
if(findings.length)throw new Error('Retired identity in active files: '+findings.join(', '));
console.log(`Identity checked: ${checked} active files; no retired branding.`);
