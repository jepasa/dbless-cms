/** Minify only explicitly registered local optional JavaScript in an isolated export; preserve human JSON and exact configured URLs. */
import fs from 'node:fs/promises';
import path from 'node:path';
import {minify} from 'terser';
/** Receive fresh package root, minify declared local script bytes, throw on parser/I/O errors; never reads live state, fetches resources or changes remote scripts. */
export async function privacyResources(directory) {
  const catalog = JSON.parse(await fs.readFile(path.join(directory,'system/storage/contents/privacy/catalog.json'),'utf8'));
  for (const resource of catalog.resources) {
    if (resource.type !== 'script' || !resource.url.startsWith('/')) continue;
    const file = path.join(directory,'public_html',resource.url.slice(1)), source = await fs.readFile(file,'utf8');
    const result = await minify(source,{compress:true,mangle:true,format:{comments:/^!|@license|@preserve|@copyright/}});
    await fs.writeFile(file,result.code || '\n');
  }
}
