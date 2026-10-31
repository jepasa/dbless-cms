<?php
/** CLI-only v2 global settings / Widgets upgrade: lifecycle + content locks, idempotent widget imports, original site backups, no credentials or examples. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/bootstrap.php';
if(count($argv)!==2||!in_array($argv[1],['--check','--apply'],true))throw new InvalidArgumentException('Usage: php src/system/bin/migrate-global-settings.php --check|--apply');
$config=new \DBLESSCMS\Config();$apply=$argv[1]==='--apply';
\DBLESSCMS\Lifecycle::exclusive($config,static function()use($config,$apply):void{
  $store=new \DBLESSCMS\JsonStore($config);$registry=new \DBLESSCMS\Plugins($store);$registry->instance('widgets');
  $store->transaction(static function()use($store,$registry,$apply):void{
    $file=\DBLESSCMS\Lifecycle::path($store->config->data['contents'].'/site.json');
    $raw=file_get_contents($file);if($raw===false)throw new RuntimeException('Site read failed.');
    $original=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$site=$store->read('site');$repository=new \DBLESSCMS\WidgetsStore($store);$before=$repository->read();$data=$before['data'];$imported=0;
    if(($original['schema_version']??null)===1){
      $template=$site['data']['template'];$socials=$original['brand']['socials']??[];
      foreach($socials as $index=>$social){
        $id='legacy-'.substr(hash('sha256',$template.':social:'.$index),0,24);
        if(isset($data['widgets'][$id]))continue;
        $icon=str_replace('bi-','',$social['icon']);$key=match($icon){'globe','globe2'=>'social-website','chat','chat-dots'=>'social-contact','github'=>'social-github','linkedin'=>'social-linkedin','instagram'=>'social-instagram','facebook'=>'social-facebook','youtube'=>'social-youtube','whatsapp'=>'social-whatsapp',default=>'legacy-social-'.($index+1)};
        foreach($data['widgets'] as $record)if(!$record['deleted']&&$record['template']===$template&&$record['key']===$key){$key='legacy-social-'.($index+1);break;}
        $data['widgets'][$id]=['id'=>$id,'template'=>$template,'key'=>$key,'value'=>$social['href'],'description'=>$social['label'],'deleted'=>false];$imported++;
      }
      // Preserve old contact destinations as widgets even when no active template currently declares that key.
      foreach(['address'=>'contact-address','whatsapp'=>'social-whatsapp'] as $field=>$key){
        $value=$original['settings']['contact'][$field]??'';if($value==='')continue;
        $id='legacy-'.substr(hash('sha256',$template.':contact:'.$field),0,24);if(isset($data['widgets'][$id]))continue;
        foreach($data['widgets'] as $record)if(!$record['deleted']&&$record['template']===$template&&$record['key']===$key){$key='legacy-contact-'.$field;break;}
        $data['widgets'][$id]=['id'=>$id,'template'=>$template,'key'=>$key,'value'=>$value,'description'=>$field,'deleted'=>false];$imported++;
      }
    }
    \DBLESSCMS\WidgetsStore::validate($data);
    if(!$apply){echo json_encode(['siteVersion'=>2,'currentVersion'=>$original['schema_version'],'widgetImports'=>$imported,'apply'=>false],JSON_THROW_ON_ERROR)."\n";return;}
    if($data!==$before['data'])$repository->mutate($before['revision'],static fn(array $current):array=>$data);
    // Imports precede site rewrite. A retry recognizes deterministic IDs after any interrupted multi-document upgrade.
    $plugins=$registry->document();if(!isset($plugins['data']['plugins']['widgets']))$registry->save('widgets',true,[],$plugins['revision']);
    if(($original['schema_version']??null)===1)$store->save('site',\DBLESSCMS\JsonStore::encode($site['data']),$site['revision']);
    echo json_encode(['siteVersion'=>2,'widgetImports'=>$imported,'apply'=>true],JSON_THROW_ON_ERROR)."\n";
  });
});
