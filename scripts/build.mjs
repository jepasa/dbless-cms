/**
 * Exportação EXPLÍCITA de produção dbless-cms. Não é necessária ao desenvolvimento.
 * Minifica código/ativos, sanitiza conteúdo via contrato PHP e preserva JSON/config legíveis.
 * Exclui segredos e storage. Publica staging completo; qualquer falha aborta sem substituir o pacote anterior.
 */
import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import CleanCSS from 'clean-css';
import { minify as minifyJs } from 'terser';
import { minify as minifyHtml } from 'html-minifier-terser';

const root = fileURLToPath(new URL('../', import.meta.url));
const source = path.join(root, 'src');
const args = process.argv.slice(2);
if (args.length && (args.length !== 2 || args[0] !== '--out')) throw new Error('Uso: npm run export:production -- [--out /tmp/dbless-cms-...]');
const target = path.resolve(args[1] || path.join(root, 'build'));
if (target !== path.join(root, 'build') && !(target.startsWith(os.tmpdir() + path.sep) && path.basename(target).startsWith('dbless-cms-'))) throw new Error('Destino permitido: build/ ou diretório /tmp/dbless-cms-*.');
try { if ((await fs.lstat(target)).isSymbolicLink()) throw new Error('Destino não pode ser symlink.'); }
catch (error) { if (error.code !== 'ENOENT') throw error; }
await fs.mkdir(path.dirname(target), { recursive: true });
const staging = await fs.mkdtemp(path.join(path.dirname(target), '.dbless-cms-stage-'));

/** Executa PHP local; recebe argumentos, retorna stdout; erros/status não zero abortam a exportação. */
function php(args) {
  const result = spawnSync('php', args, { encoding: 'utf8', cwd: root, maxBuffer: 8 * 1024 * 1024 });
  if (result.error) throw result.error;
  if (result.status !== 0 || result.stderr) throw new Error(result.stderr || `PHP terminou com status ${result.status}.`);
  return result.stdout;
}

/** Copia árvore regular, sem symlinks/segredos; recebe pasta, gera staging e propaga qualquer falha. */
async function exportDirectory(directory) {
  for (const entry of await fs.readdir(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name);
    const relative = path.relative(source, file).split(path.sep).join('/');
    // Publica somente código conhecido: uploads em media, pastas customizadas ou raiz vazia ficam fora.
    if (relative.startsWith('public_html/')) {
      const top = relative.slice('public_html/'.length).split('/')[0];
      if (!['index.php','.htaccess','cms-admin','templates','plugins','robots.txt','sitemap.xml'].includes(top)) continue;
    }
    if (/\.s[ac]ss$/i.test(entry.name) || relative === 'system/resources/contents' || relative === 'system/storage' || relative === 'system/storage/contents' || relative === 'system/config/app.json' || relative === 'system/config/production.php' || entry.name.startsWith('.env') || /\.(log|jsonl|pem|key)$/i.test(entry.name) || /\s/.test(entry.name)) continue;
    if (entry.isSymbolicLink()) throw new Error(`Symlink proibido no pacote: ${relative}`);
    if (entry.isDirectory()) { await fs.mkdir(path.join(staging, relative), { recursive: true }); await exportDirectory(file); continue; }
    if (!entry.isFile()) throw new Error(`Arquivo não regular: ${relative}`);
    const extension = path.extname(entry.name).toLowerCase();
    let output = relative;
    let bytes;
    if (extension === '.json') bytes = JSON.stringify(JSON.parse(await fs.readFile(file, 'utf8')), null, 2) + '\n';
    else if (extension === '.php') {
      php(['-l', file]);
      bytes = relative.startsWith('system/config/') ? await fs.readFile(file) : php(['-w', file]);
    } else if (extension === '.css' && !entry.name.endsWith('.min.css')) {
      const result = new CleanCSS({ level: 2 }).minify(await fs.readFile(file, 'utf8'));
      if (result.errors.length) throw new Error(result.errors.join('; '));
      bytes = result.styles; output = relative.replace(/\.css$/, '.min.css');
    } else if (extension === '.js' && !entry.name.endsWith('.min.js')) {
      const result = await minifyJs(await fs.readFile(file, 'utf8'), { compress: true, mangle: true, format: { comments: /^!|@license|@preserve|@copyright|Author:|URL:/ } });
      if (!result.code) throw new Error(`Minificação sem resultado: ${relative}`);
      bytes = result.code; output = relative.replace(/\.js$/, '.min.js');
    } else if (extension === '.html') bytes = await minifyHtml(await fs.readFile(file, 'utf8'), { collapseWhitespace: true, removeComments: true });
    else bytes = await fs.readFile(file);
    const destination = path.join(staging, output);
    await fs.mkdir(path.dirname(destination), { recursive: true });
    await fs.writeFile(destination, bytes);
  }
}

/** Calcula inventário verificável; recebe diretório e retorna entradas ordenadas, sem efeitos de escrita. */
async function inventory(directory) {
  const items = [];
  for (const entry of await fs.readdir(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) items.push(...await inventory(file));
    else { const bytes = await fs.readFile(file); items.push({ path: path.relative(staging, file).split(path.sep).join('/'), bytes: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex') }); }
  }
  return items.sort((a,b) => a.path.localeCompare(b.path));
}

let previous;
try {
  await exportDirectory(source);
  process.stdout.write(php([path.join(source, 'system/bin/export-contents.php'), staging]));
  await fs.writeFile(path.join(staging, 'system/config/production.php'), '<?php\n/** Marcador gerado pelo exportador; impede uso de .env no pacote de produção. */\ndeclare(strict_types=1);\nreturn true;\n');
  await fs.mkdir(path.join(staging, 'system/storage/files'), { recursive: true });
  await fs.mkdir(path.join(staging, 'public_html/media'), { recursive: true });
  await fs.copyFile(path.join(root, 'LICENSE'), path.join(staging, 'LICENSE'));
  await fs.copyFile(path.join(root, 'THIRD_PARTY.md'), path.join(staging, 'THIRD_PARTY.md'));
  try { await fs.copyFile(path.join(root, 'NOTICE'), path.join(staging, 'NOTICE')); }
  catch (error) { if (error.code !== 'ENOENT') throw error; }
  await fs.writeFile(path.join(staging, 'export-manifest.json'), JSON.stringify({ name: 'dbless-cms', version: '2.0.0', files: await inventory(staging) }, null, 2) + '\n');
  try { await fs.access(target); previous = target + '.previous-' + Date.now(); await fs.rename(target, previous); }
  catch (error) { if (error.code !== 'ENOENT') throw error; }
  try { await fs.rename(staging, target); }
  catch (error) { if (previous) await fs.rename(previous, target); throw error; }
  if (previous) await fs.rm(previous, { recursive: true });
  console.log(`dbless-cms 2.0.0: pacote de produção exportado para ${target}.`);
} finally { await fs.rm(staging, { recursive: true, force: true }); }
