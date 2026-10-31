/** Reproduce the administrative ICO from the canonical original SVG using locally installed Inkscape; no network, runtime dependencies or other repositories are touched. */
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const root=fileURLToPath(new URL('../',import.meta.url)),source=path.join(root,'src/public_html/cms-admin/assets/brand/dbless-cms-symbol.svg');
if(process.argv.length!==2)throw new Error('Usage: node scripts/generate-admin-favicon.mjs');
const temporary=fs.mkdtempSync(path.join(os.tmpdir(),'dbless-brand-ico-')),sizes=[16,24,32,48,64,128,256],frames=[];
try{
  for(const size of sizes){
    const destination=path.join(temporary,size+'.png');
    const result=spawnSync('inkscape',[source,'--export-type=png','--export-filename='+destination,'--export-width='+size,'--export-height='+size],{encoding:'utf8',timeout:15000,env:{...process.env,INKSCAPE_PROFILE_DIR:path.join(temporary,'profile'),XDG_CACHE_HOME:path.join(temporary,'cache')}});
    if(result.error)throw result.error;if(result.status!==0)throw new Error('SVG rendering failed: '+result.stderr);
    const bytes=fs.readFileSync(destination);
    if(bytes.length<33||!bytes.subarray(0,8).equals(Buffer.from([137,80,78,71,13,10,26,10]))||bytes.readUInt32BE(16)!==size||bytes.readUInt32BE(20)!==size)throw new Error('Invalid rendered icon frame.');
    frames.push({size,bytes});
  }
  const header=Buffer.alloc(6+frames.length*16);header.writeUInt16LE(1,2);header.writeUInt16LE(frames.length,4);let offset=header.length;
  for(const [index,{size,bytes}] of frames.entries()){
    const entry=6+index*16;header[entry]=size===256?0:size;header[entry+1]=size===256?0:size;header.writeUInt16LE(1,entry+4);header.writeUInt16LE(32,entry+6);header.writeUInt32LE(bytes.length,entry+8);header.writeUInt32LE(offset,entry+12);offset+=bytes.length;
  }
  const target=path.join(root,'src/public_html/cms-admin/assets/favicon.ico'),descriptor=fs.openSync(target,fs.constants.O_WRONLY|fs.constants.O_CREAT|fs.constants.O_TRUNC|fs.constants.O_NOFOLLOW,0o644);
  try{fs.writeFileSync(descriptor,Buffer.concat([header,...frames.map(frame=>frame.bytes)]));fs.fsyncSync(descriptor);}finally{fs.closeSync(descriptor);}
  const previews=path.join(root,'doc/identidade_visual');fs.mkdirSync(previews,{recursive:true});
  for(const {size,bytes} of frames)if([16,32,48,64].includes(size))fs.writeFileSync(path.join(previews,'favicon-'+size+'.png'),bytes);
  console.log('Admin ICO generated from original SVG: '+sizes.join(', ')+' px.');
}finally{fs.rmSync(temporary,{recursive:true,force:true});}
