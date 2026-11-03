/** Instala extensão exclusivamente sintética com páginas pública/privada para testar o contrato sem publicar funcionalidades de exemplo. */
import fs from 'node:fs';
import path from 'node:path';

/** Escreve código/manifesto/dicionários/dados sintéticos no sandbox existente; recebe contexto e retorna ID/diretório. */
export function installPublicPlugin(context) {
  const id='public-example', directory=path.join(context.application,'system/plugins',id);
  fs.mkdirSync(path.join(directory,'pages'),{recursive:true});fs.mkdirSync(path.join(directory,'languages'));
  const manifest={id,name:'Synthetic Public Plugin',version:'1.0.0',api:1,cmsMajor:2,entry:'Plugin.php',class:'DBLESSCMS\\TestPublicPlugin',router:'Router.php',assets:{css:'css/plugin.css',js:'js/plugin.js',icon:'public-example.svg'},defaults:{publicMessage:'PUBLIC MESSAGE',privateValue:'PRIVATE SYNTHETIC VALUE'},publicSettings:['publicMessage'],routes:{private:{title:'page.private',file:'pages/private.php',requiresEnabled:true,visibility:'private'},home:{title:'page.home',file:'pages/home.php',requiresEnabled:true,visibility:'public',methods:['GET']},submit:{title:'page.submit',file:'pages/submit.php',requiresEnabled:true,visibility:'public',methods:['POST'],maxBodyBytes:256}},navigation:{label:'page.private',route:'private'}};
  fs.writeFileSync(path.join(directory,'plugin.json'),JSON.stringify(manifest,null,2));
  const publicIcon=path.join(context.application,'public_html/plugins',id,'public-example.svg');fs.mkdirSync(path.dirname(publicIcon),{recursive:true});
  fs.copyFileSync(path.join(context.application,'public_html/plugins/files-manager/files-manager.svg'),publicIcon);
  fs.writeFileSync(path.join(directory,'settings.schema.json'),JSON.stringify({type:'object',additionalProperties:false,required:['publicMessage','privateValue'],properties:{publicMessage:{type:'string',title:'setting.public',maxLength:100},privateValue:{type:'string',title:'setting.private',maxLength:100}}}));
  for(const [locale,name,heading,label,submit] of [['pt-br','Português','Página pública sintética','Mensagem','Enviar'],['en-us','English','Synthetic public page','Message','Submit']])fs.writeFileSync(path.join(directory,'languages',locale+'.json'),JSON.stringify({locale,name,direction:'ltr',messages:{'page.private':'PRIVATE-ONLY','page.home':heading,'page.submit':submit,'form.message':label,'setting.public':'Public','setting.private':'Private'}}));
  fs.writeFileSync(path.join(directory,'Plugin.php'),`<?php
/** Extensão sintética para provar separação de contratos e contexto, nunca instalada no projeto real. */
declare(strict_types=1); namespace DBLESSCMS;
require_once __DIR__.'/Router.php';
final class TestPublicPlugin implements PluginInterface, PublicPluginInterface {
  /** Valida settings sintéticos sem efeitos; entrada tipada e erros pelo Schema. */
  public function validateSettings(array $settings,Config $config): void { Schema::validate($settings,json_decode(file_get_contents(__DIR__.'/settings.schema.json'),true)); }
  /** Renderiza página administrativa sintética sob contexto autenticado. */
  public function render(PluginContext $context): string { return TestPublicRouter::privatePage($context); }
  /** Rejeita API administrativa não declarada no fixture. */
  public function api(PluginContext $context,string $operation): ?array { throw new HttpError(404,'Não encontrado.'); }
  /** Delega página pública ao roteador próprio com contexto restrito. */
  public function publicPage(PublicPluginContext $context): string { return TestPublicRouter::publicPage($context); }
}
`);
  fs.writeFileSync(path.join(directory,'Router.php'),`<?php
/** Roteador sintético com mapas separados, usado somente no sandbox de testes. */
declare(strict_types=1); namespace DBLESSCMS;
final class TestPublicRouter {
  /** Retorna marcador privado apenas para a rota autenticada conhecida. */
  public static function privatePage(PluginContext $context): string { if($context->route!=='private')throw new HttpError(404,'Não encontrado.');return 'PRIVATE-ONLY'; }
  /** Executa somente script público do mapa local; retorna HTML e propaga validações. */
  public static function publicPage(PublicPluginContext $context): string {
    $file=match($context->route){'home'=>'home.php','submit'=>'submit.php',default=>throw new HttpError(404,'Não encontrado.')};
    ob_start();try{require __DIR__.'/pages/'.$file;return (string)ob_get_clean();}catch(\\Throwable $error){ob_end_clean();throw $error;}
  }
}
`);
  fs.writeFileSync(path.join(directory,'pages/private.php'),'<?php /** Marcador privado sintético. */ echo "PRIVATE-ONLY";');
  fs.writeFileSync(path.join(directory,'pages/home.php'),`<?php
/** Formulário público sintético; dados e token escapados sem expor configurações privadas. */
declare(strict_types=1);use function DBLESSCMS\\e;use function DBLESSCMS\\asset;
?><!doctype html><html lang="<?= e($context->language->locale()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($context->language->text('page.home')) ?></title><link rel="stylesheet" href="<?= e(asset('plugins/public-example/css/plugin.css',$context->base)) ?>"></head><body><main><h1><?= e($context->language->text('page.home')) ?></h1><p><?= e($context->settings['publicMessage']) ?></p><form method="post" action="<?= e($context->url('submit')) ?>"><input type="hidden" name="csrf_token" value="<?= e($context->csrfToken) ?>"><label><?= e($context->language->text('form.message')) ?><input name="message" maxlength="60" required></label><button><?= e($context->language->text('page.submit')) ?></button></form><pre id="public-settings"><?= e(json_encode($context->settings)) ?></pre><pre id="context-shape"><?= e(json_encode(array_keys(get_object_vars($context)))) ?></pre></main></body></html>`);
  fs.writeFileSync(path.join(directory,'pages/submit.php'),`<?php
/** Resposta sintética de POST público; valida mensagem e escapa a saída. */
declare(strict_types=1);use function DBLESSCMS\\e;
$message=$_POST['message']??null;if(!is_string($message)||trim($message)===''||strlen($message)>60)throw new \\DBLESSCMS\\HttpError(422,'Mensagem inválida.');
echo '<!doctype html><meta charset="utf-8"><p id="result">'.e($message).'</p>';`);
  const publicRoot=path.join(context.application,'public_html/plugins',id);
  for(const kind of ['css','js']) {fs.mkdirSync(path.join(publicRoot,kind),{recursive:true});const bytes=kind==='css'?'/* Synthetic public page. */*{box-sizing:border-box}body{margin:0;background:#f3f6fa;font:16px system-ui;color:#18304c}main{max-width:700px;margin:40px auto;padding:24px}form,label{display:grid;gap:12px}input,button{padding:12px;font:inherit;max-width:100%}pre{white-space:pre-wrap;overflow-wrap:anywhere}':'/** Synthetic asset; no browser behavior is required. */';for(const name of ['plugin.'+kind,'plugin.min.'+kind])fs.writeFileSync(path.join(publicRoot,kind,name),bytes);}
  const configFile=path.join(context.temporary,'contents/plugins.json'),config=JSON.parse(fs.readFileSync(configFile));config.plugins[id]={enabled:true,settings:manifest.defaults};fs.writeFileSync(configFile,JSON.stringify(config,null,2));
  return {id,directory,manifest};
}
