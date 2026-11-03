/**
 * Suporte de integração PHP com dados sintéticos, mail bloqueado e sessão isolada.
 * Os diretórios temporários são removidos em sucesso/falha; nenhum servidor é iniciado.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

export const root = fileURLToPath(new URL('../../', import.meta.url));

/** Retorna árvores executáveis: fonte sempre; pacote somente quando fornecido pelo gate explícito. */
export const trees = process.env.DBLESS_CMS_PACKAGE_ROOT ? ['src','production'] : ['src'];

/** Resolve raiz da aplicação; recebe árvore e retorna caminho absoluto; rejeita pacote ausente. */
export function appRoot(tree) {
  if (tree === 'src') return path.join(root,'src');
  assert.ok(tree === 'production' && process.env.DBLESS_CMS_PACKAGE_ROOT);
  return process.env.DBLESS_CMS_PACKAGE_ROOT;
}

/** Cria cópia editorial/estado sintéticos; retorna diretório privado a remover pelo chamador. */
export function workspace(tree = 'src') {
  const temporary = fs.mkdtempSync(path.join(os.tmpdir(),'dbless-cms-test-'));
  fs.cpSync(path.join(appRoot(tree),tree==='src'?'system/resources/contents':'system/storage/contents'),path.join(temporary,'contents'),{recursive:true});
  const siteFile=path.join(temporary,'contents/site.json');const site=JSON.parse(fs.readFileSync(siteFile));site.template='essencial';fs.writeFileSync(siteFile,JSON.stringify(site,null,2)+'\n');
  const hash = tree === 'production' ? spawnSync('php',['-r',"echo password_hash('Synthetic-DBLESS-CMS-password-2026', PASSWORD_ARGON2ID, ['memory_cost'=>8192,'time_cost'=>1,'threads'=>1]);"],{encoding:'utf8'}).stdout : '';
  fs.writeFileSync(path.join(temporary,'app.json'),JSON.stringify({schema_version:1,environment:'production',editor:{enabled:true,username:'editor',password_hash:hash},contact_enabled:false}));
  return temporary;
}

/** Ambiente isolado para processos; recebe pasta e retorna variáveis, sem ler o .env real. */
export function environment(temporary) {
  return {...process.env,DBLESS_CMS_IGNORE_DOTENV:'1',DBLESS_CMS_CONTENTS_ROOT:path.join(temporary,'contents'),DBLESS_CMS_STORAGE_ROOT:path.join(temporary,'storage'),DBLESS_CMS_APP_CONFIG:path.join(temporary,'app.json'),DBLESS_CMS_ADMIN_USER:'editor',DBLESS_CMS_ADMIN_PASSWORD:'Synthetic-DBLESS-CMS-password-2026',DBLESS_CMS_CONTACT_ENABLED:'0'};
}

/**
 * Renderiza o entrypoint em PHP isolado. Recebe árvore/requisição e pasta opcional para sessão persistente.
 * Retorna status/corpo/sessão. Mail é bloqueado; warnings/erros falham e temporários próprios são removidos.
 */
export function requestPhp(tree, request = {}, context = null) {
  const temporary = context ?? workspace(tree);
  try {
    const result = spawnSync('php', [
      '-d','disable_functions=mail','-d','display_errors=stderr','-d','error_reporting=-1','-d','log_errors=0',
      '-d',`sys_temp_dir=${temporary}`,'-d',`session.save_path=${temporary}`,
      path.join(root,'tests/helpers/request.php'),appRoot(tree),
    ],{input:JSON.stringify(request),encoding:'utf8',timeout:10000,env:environment(temporary)});
    if (result.error) throw result.error;
    assert.equal(result.status,0,result.stderr);
    assert.equal(result.stderr,'','PHP não deve emitir warnings/fatais.');
    return JSON.parse(result.stdout);
  } finally { if (!context) fs.rmSync(temporary,{recursive:true,force:true}); }
}
