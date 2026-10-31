<?php
/** Gerenciamento de extensões: catálogo sem execução, ativação exclusiva, opções tipadas e desinstalação recuperável. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Extensions {
  /** Recebe persistência privada; sem leitura de credenciais ou efeitos de escrita. */
  public function __construct(private readonly JsonStore $store) {}

  /** Lista extensões e suas mídias declaradas; recebe tipo/locale, retorna linhas de catálogo sem executar código ou escrever conteúdo. */
  public function catalog(string $kind,string $locale): array {
    $rows=[];
    if ($kind==='plugins') {
      $registry=new Plugins($this->store); $valid=$registry->all(); $invalid=$registry->invalid(); $incompatible=[];
    } elseif ($kind==='templates') {
      $registry=$this->store->templates; $valid=$registry->all(); $invalid=$registry->invalid();
      $this->store->compatibleTemplates(); $incompatible=$this->store->incompatibleTemplates();
    } else throw new HttpError(400,'Tipo inválido.','error.operation');
    $active=$this->store->read('site')['data']['template'];
    foreach ($valid as $id=>$manifest) {
      $labels=$registry->labels($id,$locale);
      $enabled=$kind==='plugins'?$registry->state($id)['enabled']:$active===$id;
      $rows[$id]=['id'=>$id,'name'=>$labels['name'],'description'=>$labels['description'],'version'=>$manifest['version'],'status'=>isset($incompatible[$id])?'incompatible':($enabled?'active':'inactive'),'enabled'=>$enabled,'configuration'=>$manifest['configuration']??null,'protected'=>$kind==='templates'&&$id===Templates::DEFAULT_ID];
      $rows[$id]['presentation']=$kind==='plugins'&&isset($manifest['presentation']);
      $rows[$id]['media']=$kind==='templates'?'templates/'.$id.'/'.$manifest['assets']['files']['thumbnail']:'plugins/'.$id.'/'.$manifest['assets']['icon'];
    }
    foreach ($invalid as $id=>$reason) $rows[$id]=['id'=>$id,'name'=>$id,'description'=>'','version'=>'','status'=>'invalid','enabled'=>false,'configuration'=>null,'protected'=>$kind==='templates'&&$id===Templates::DEFAULT_ID];
    ksort($rows); return $rows;
  }

  /** Confere revisão dos bytes sob lock; recebe chave/revisão, retorna snapshot ou 409, sem alteração. */
  private function current(string $key,string $revision): array {
    $document=$this->store->read($key);
    if (!hash_equals($document['revision'],$revision)) throw new HttpError(409,'Revisão desatualizada.','error.revision');
    return $document;
  }

  /** Ativa/desativa pacote com revisão; recebe tipo/ID/estado/revisão, grava sob trava e propaga falhas sem preferência parcial. */
  public function toggle(string $kind,string $id,bool $enabled,string $revision): void {
    $this->store->transaction(function() use($kind,$id,$enabled,$revision): void {
      if ($kind==='templates') {
        $site=$this->current('site',$revision); $this->store->templates->get($id);
        if (!$enabled && $site['data']['template']!==$id) throw new HttpError(409,'Estado desatualizado.','error.revision');
        $site['data']['template']=$enabled?$id:Templates::DEFAULT_ID;
        $this->store->save('site',JsonStore::encode($site['data']),$revision);
      } elseif ($kind==='plugins') {
        $document=$this->current('plugins',$revision); $registry=new Plugins($this->store); $state=$registry->state($id);
        $document['data']['plugins'][$id]=['enabled'=>$enabled,'settings'=>$state['settings']];
        $this->store->save('plugins',JsonStore::encode($document['data']),$revision);
      } else throw new HttpError(400,'Tipo inválido.','error.operation');
    });
  }

  /** Obtém valores validados do tema; recebe ID, retorna defaults combinados/configuração/revisão sem modificar bytes antigos; não grava e rejeita estado inválido. */
  public function options(string $id): array {
    $definition=$this->store->templates->configuration($id); $document=$this->store->read('templates');
    $stored=$document['data']['templates'][$id]??$definition['defaults'];
    Schema::validate($stored,$definition['schema']);
    $values=array_replace_recursive($definition['defaults'],$stored); Schema::validate($values,$definition['schema']);
    return ['values'=>$values,'revision'=>$document['revision'],'schema'=>$definition['schema']];
  }

  /** Salva opções do template sob revisão; recebe ID/POST/revisão e retorna nada, preservando temas não selecionados e histórico. */
  public function saveOptions(string $id,array $input,string $revision): void {
    $this->store->transaction(function() use($id,$input,$revision): void {
      $document=$this->current('templates',$revision); $definition=$this->store->templates->configuration($id);
      $submitted=OptionFields::input($definition['schema'],$input);
      $values=array_replace_recursive($definition['defaults'],$document['data']['templates'][$id]??[],$submitted); Schema::validate($values,$definition['schema']);
      $document['data']['templates'][$id]=$values;
      $this->store->save('templates',JsonStore::encode($document['data']),$revision);
    });
  }

  /** Valida árvore regular sem links, antes de mover; recebe diretório, retorna nada ou 422 sem executar/remover arquivos. */
  private function regularTree(string $directory): void {
    if (!is_dir($directory) || is_link($directory)) throw new HttpError(422,'Pacote inseguro.','error.extension_archive');
    $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $entry) if ($entry->isLink() || (!$entry->isFile() && !$entry->isDir())) throw new HttpError(422,'Pacote inseguro.','error.extension_archive');
  }

  /** Desinstala por rename privado, preservando dados/assets; recebe tipo/ID/revisão/confirmação exata; falhas recuperam o pacote ou informam 503. */
  public function uninstall(string $kind,string $id,string $revision,string $confirmation): void {
    if (!in_array($kind,['plugins','templates'],true) || !Templates::identifier($id) || ($kind==='plugins'&&!Plugins::identifier($id)) || $confirmation!==$id) throw new HttpError(422,'Confirmação inválida.','error.extension_confirmation');
    if ($kind==='templates' && $id===Templates::DEFAULT_ID) throw new HttpError(422,'Template padrão protegido.','error.default_template');
    $this->store->transaction(function() use($kind,$id,$revision): void {
      $key=$kind==='plugins'?'plugins':'site'; $document=$this->current($key,$revision);
      $root=$this->store->config->root.'/'.$kind; $package=$root.'/'.$id;
      if (is_link($root)) throw new HttpError(422,'Pacote inseguro.','error.extension_archive');
      $this->regularTree($package);
      $storage=rtrim($this->store->config->data['storage'],'/');
      // Verificar todos os ancestrais existentes da quarentena, inclusive storage customizado.
      $cursor=''; foreach (explode('/', $storage.'/uninstalled/'.$kind) as $part) {
        if ($part==='') continue; $cursor.='/'.$part;
        if ($part==='..' || is_link($cursor)) throw new HttpError(422,'Quarentena insegura.','error.extension_archive');
      }
      $archive=$storage.'/uninstalled/'.$kind; Security::directory($archive);
      if (stat($package)['dev']!==stat($archive)['dev']) throw new HttpError(503,'Quarentena exige o mesmo filesystem.','error.extension_archive');
      if ($kind==='plugins') {
        $state=$document['data']['plugins'][$id]??null;
        if ($state!==null) $document['data']['plugins'][$id]['enabled']=false;
      } elseif ($document['data']['template']===$id) $document['data']['template']=Templates::DEFAULT_ID;
      $destination=$archive.'/'.$id.'-'.bin2hex(random_bytes(12));
      if (!@rename($package,$destination)) throw new HttpError(503,'Arquivamento indisponível.','error.extension_archive');
      try { $this->store->save($key,JsonStore::encode($document['data']),$revision); }
      catch (\Throwable $failure) {
        if (!@rename($destination,$package)) throw new HttpError(503,'Pacote requer recuperação da quarentena.','error.extension_recovery');
        throw $failure;
      }
    });
  }
}
