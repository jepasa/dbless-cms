/** Lint distributed PHP/JS without changing runtime content; syntax failures stop the command. */
import fs from 'node:fs';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
/** Walk regular source files; receives directory, returns paths, rejects links and propagates filesystem errors. */
function files(directory){return fs.readdirSync(directory,{withFileTypes:true}).flatMap(entry=>{const file=path.join(directory,entry.name);if(entry.isSymbolicLink())throw new Error('Source links are forbidden.');return entry.isDirectory()?(file==='src/system/storage'?[]:files(file)):[file];});}
let count=0;
for(const file of ['src','scripts','tests'].flatMap(files)){const command=file.endsWith('.php')?['php',['-l',file]]:/\.(mjs|js)$/.test(file)?[process.execPath,['--check',file]]:null;if(!command)continue;const result=spawnSync(...command,{encoding:'utf8'});if(result.error)throw result.error;if(result.status!==0)throw new Error(result.stderr||result.stdout);count++;}
console.log('Syntax passed: '+count+' files.');
