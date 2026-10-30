<?php
/** Ferramentas PHP CLI de produção: diagnóstico, manutenção, retenção, snapshots privados e atualização em destino novo. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Operations {
  private readonly string $installation;
  private readonly string $privateConfig;
  /** Recebe configuração local, resolve raízes e recusa links; não imprime nem altera dados. */
  public function __construct(private readonly Config $config) {
    $this->installation=Lifecycle::path(dirname($config->root));
    $this->privateConfig=Lifecycle::path(getenv('DBLESS_CMS_APP_CONFIG')?:$config->root.'/config/app.json');
    Lifecycle::path($config->data['contents']);Lifecycle::path($config->data['storage']);
  }
  /** Testa contenção por limite de segmento; recebe caminhos absolutos e retorna bool sem efeitos. */
  private static function inside(string $path,string $root): bool {return $path===$root || str_starts_with($path,rtrim($root,'/').'/');}
  /** Exige arquivo regular legível sem links; recebe caminho e retorna bytes; falhas são explícitas. */
  private static function read(string $file): string {
    Lifecycle::path($file);
    if (!is_file($file) || !is_readable($file)) throw new \RuntimeException('Arquivo operacional ausente ou ilegível.');
    $bytes=file_get_contents($file);
    if ($bytes===false) throw new \RuntimeException('Falha de leitura operacional.');
    return $bytes;
  }
  /** Grava arquivo novo ou substituição atômica privada; recebe caminho/bytes; falha sem substituir em escrita incompleta. */
  private static function write(string $file,string $bytes): void {
    Lifecycle::path($file);$temporary=tempnam(dirname($file),'.dbless-cms-ops-');
    if ($temporary===false) throw new \RuntimeException('Área temporária indisponível.');
    try {
      $stream=fopen($temporary,'wb');if ($stream===false) throw new \RuntimeException('Falha de gravação operacional.');
      try {if (fwrite($stream,$bytes)!==strlen($bytes) || !fflush($stream) || !fsync($stream)) throw new \RuntimeException('Gravação operacional incompleta.');}
      finally {fclose($stream);}
      if (!chmod($temporary,0600) || !rename($temporary,$file)) throw new \RuntimeException('Publicação operacional indisponível.');
    } finally {if (is_file($temporary)) unlink($temporary);}
  }
  /** Confere núcleo de inventários DBLESS-CMS em caixa alta ou baixa; permite novos dicionários e extensões, mas rejeita núcleo extra/ausente/alterado; sem escrita. */
  private function coreIntegrity(): bool {
    $file=$this->installation.'/export-manifest.json';
    if (!is_file($file)) return false;
    $manifest=json_decode(self::read($file),true,32,JSON_THROW_ON_ERROR);$expected=[];$actual=[];
    if (!in_array($manifest['name']??null,['dbless-cms','DBLESS-CMS'],true)||!is_array($manifest['files']??null)) return false;
    foreach ($manifest['files'] as $entry) {
      $relative=$entry['path']??'';
      if (!is_string($relative)||!preg_match('~^(?:system/(?:lib/|admin/|bin/|languages/|config/(?:schemas/|settings\.php$|production\.php$)|bootstrap\.php$|core\.php$)|public_html/(?:cms-admin/|index\.php$|plugins/index\.php$|\.htaccess$))~D',$relative)) continue;
      Lifecycle::path($this->installation.'/'.$relative);
      if (isset($expected[$relative])) return false;
      $expected[$relative]=['bytes'=>$entry['bytes']??null,'sha256'=>$entry['sha256']??null];
    }
    foreach (['system/lib','system/admin','system/bin','system/config/schemas','public_html/cms-admin'] as $directory) {
      if (!is_dir($this->installation.'/'.$directory)) return false;
      foreach (self::inventory($this->installation.'/'.$directory) as $relative=>$entry) $actual[$directory.'/'.$relative]=$entry;
    }
    foreach (['system/bootstrap.php','system/core.php','system/config/settings.php','system/config/production.php','public_html/index.php','public_html/plugins/index.php','public_html/.htaccess'] as $relative) {
      $path=Lifecycle::path($this->installation.'/'.$relative);
      if (!is_file($path)) return false;
      $actual[$relative]=['bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path)];
    }
    foreach ($expected as $relative=>$entry) if (str_starts_with($relative,'system/languages/')) {
      $path=Lifecycle::path($this->installation.'/'.$relative);
      if (!is_file($path)) return false;
      $actual[$relative]=['bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path)];
    }
    ksort($expected,SORT_STRING);ksort($actual,SORT_STRING);
    return $actual===$expected;
  }
  /** Diagnostica instalação sem segredos nem escrita; retorna checks/ready; contratos inválidos tornam o resultado reprovado. */
  public function doctor(): array {
    $store=new JsonStore($this->config);$checks=[];
    $checks['production']=$this->config->production&&is_file($this->config->root.'/config/production.php');
    $checks['php']=PHP_VERSION_ID>=80200;
    foreach (['fileinfo','json','session','openssl'] as $extension) $checks['extension_'.$extension]=extension_loaded($extension);
    $checks['credentials']=is_bool($this->config->credentials['enabled']??true)&&$this->config->configured()&&password_get_info($this->config->credentials['password_hash']??'')['algoName']==='argon2id';
    $checks['private_config']=is_file($this->privateConfig)&&!self::inside($this->privateConfig,$this->installation.'/public_html')&&((fileperms($this->privateConfig)&0007)===0);
    foreach (['contents','storage'] as $key) {
      $directory=$this->config->data[$key];
      $checks[$key.'_private']=is_dir($directory)&&!self::inside($directory,$this->installation.'/public_html')&&((fileperms($directory)&0007)===0);
      $checks[$key.'_writable']=is_writable($directory);
    }
    $site=$store->read('site')['data'];$checks['canonical_https']=parse_url($site['settings']['canonical'],PHP_URL_SCHEME)==='https';
    $checks['base_https']=($site['settings']['baseUrl']??'')===''||parse_url($site['settings']['baseUrl'],PHP_URL_SCHEME)==='https';
    $checks['pages']=isset($store->pages()['home'],$store->pages()['404']);
    $checks['active_template']=isset($store->compatibleTemplates()[$site['template']]);
    $registry=new Plugins($store);$checks['plugin_manifests']=$registry->invalid()===[];
    $registry->validateDocument($registry->document()['data']);
    $checks['plugins']=true;
    foreach ($registry->all() as $id=>$manifest) if ($registry->state($id)['enabled']&&($manifest['launcher']??'')==='files') {
      $settings=$registry->state($id)['settings'];$relative=$settings['uploads']['public'];
      $public=$this->installation.'/public_html'.($relative===''?'':'/'.$relative);Lifecycle::path($public);
      $checks['public_uploads']=is_dir($public)&&is_writable($public);
      $private=$this->config->data['storage'].'/files'.($settings['uploads']['private']===''?'':'/'.$settings['uploads']['private']);Lifecycle::path($private);
      $checks['private_uploads']=is_dir($private)&&is_writable($private);
    }
    $checks['release_manifest']=is_file($this->installation.'/export-manifest.json');
    $checks['core_integrity']=$this->coreIntegrity();
    return ['ready'=>!in_array(false,$checks,true),'checks'=>$checks,'contact_enabled'=>$this->config->data['contact_enabled'],'version'=>$this->config->data['version'],'storage_free_bytes'=>is_dir($this->config->data['storage'])?disk_free_space($this->config->data['storage']):null,'maintenance'=>is_file($this->config->data['storage'].'/maintenance.json')];
  }
  /** Liga/desliga manutenção sob trava exclusiva; recebe bool e retorna estado; não interrompe requisições já em execução. */
  public function maintenance(bool $enabled): array {
    return Lifecycle::exclusive($this->config,function() use($enabled): array {
      $marker=Lifecycle::path($this->config->data['storage'].'/maintenance.json');
      if ($enabled) self::write($marker,JsonStore::encode(['since'=>gmdate(DATE_ATOM)]));
      elseif (file_exists($marker)&&(!is_file($marker)||!unlink($marker))) throw new \RuntimeException('Não foi possível encerrar manutenção.');
      return ['maintenance'=>$enabled];
    });
  }
  /** Remove somente sessões/cotas expiradas e gira auditorias; recebe idade em dias/max bytes/quantidade, retorna contagens sem tocar conteúdo/lixeira. */
  public function cleanup(int $days=7,int $maxBytes=10485760,int $keep=5): array {
    if ($days<1||$days>365||$maxBytes<1024||$maxBytes>1073741824||$keep<1||$keep>20) throw new \RuntimeException('Política de retenção inválida.');
    return Lifecycle::exclusive($this->config,function() use($days,$maxBytes,$keep): array {
      $root=$this->config->data['storage'];$cutoff=time()-$days*86400;$removed=0;$rotated=0;
      foreach (['sessions'=>'~^sess_[A-Za-z0-9,-]+$~D','rate'=>'~^[a-f0-9]{64}\\.json$~D'] as $folder=>$pattern) {
        $directory=Lifecycle::path($root.'/'.$folder);
        if (!is_dir($directory)) continue;
        foreach (scandir($directory)?:[] as $name) {
          if (!preg_match($pattern,$name)) continue;
          $file=Lifecycle::path($directory.'/'.$name);
          if (!is_file($file)) throw new \RuntimeException('Estado temporário irregular.');
          if (filemtime($file)>=$cutoff) continue;
          if ($folder==='rate') {
            $times=json_decode(self::read($file),true,16,JSON_THROW_ON_ERROR);
            if (!is_array($times)||array_filter($times,static fn($value): bool=>!is_int($value))!==[]) throw new \RuntimeException('Cota inválida; limpeza interrompida.');
            if (array_filter($times,static fn(int $value): bool=>$value>time()-86400)!==[]) continue;
          }
          if (!unlink($file)) throw new \RuntimeException('Falha na limpeza de estado temporário.');
          $removed++;
        }
      }
      foreach ([$root.'/audit.jsonl',$root.'/plugins/files-manager/audit.jsonl'] as $audit) {
        Lifecycle::path($audit);if (!is_file($audit)||filesize($audit)<$maxBytes) continue;
        $last=$audit.'.'.$keep;Lifecycle::path($last);
        if (is_file($last)&&!unlink($last)) throw new \RuntimeException('Falha na retenção da auditoria.');
        for ($index=$keep-1;$index>=1;$index--) {
          $from=Lifecycle::path($audit.'.'.$index);$to=Lifecycle::path($audit.'.'.($index+1));
          if (is_file($from)&&!rename($from,$to)) throw new \RuntimeException('Falha na rotação da auditoria.');
        }
        if (!rename($audit,$audit.'.1')) throw new \RuntimeException('Falha na rotação da auditoria.');
        self::write($audit,'');$rotated++;
      }
      return ['removed_temporary'=>$removed,'rotated_audits'=>$rotated,'retention_days'=>$days,'audit_keep'=>$keep];
    });
  }
  /** Copia árvore regular sem links para destino novo; recebe origem/destino/filtro opcional e preserva somente bytes, com permissões privadas. */
  private static function copyTree(string $source,string $target,?callable $include=null): void {
    Lifecycle::path($source);Lifecycle::path($target);
    if (!is_dir($source)||file_exists($target)||!mkdir($target,0700)) throw new \RuntimeException('Árvore de cópia inválida ou destino existente.');
    foreach (scandir($source)?:[] as $name) {
      if ($name==='.'||$name==='..') continue;
      $from=$source.'/'.$name;$to=$target.'/'.$name;
      if ($include!==null&&!$include($from)) continue;
      Lifecycle::path($from);
      if (is_dir($from)) self::copyTree($from,$to,$include);
      elseif (is_file($from)) {
        if (!copy($from,$to)||!chmod($to,0600)||hash_file('sha256',$from)!==hash_file('sha256',$to)) throw new \RuntimeException('Falha ou alteração concorrente na cópia.');
      } else throw new \RuntimeException('Arquivo especial não pode integrar snapshot.');
    }
  }
  /** Inventaria arquivos por caminho relativo, tamanho e hash; recebe raiz e retorna mapa ordenado sem seguir links. */
  private static function inventory(string $root): array {
    $files=[];$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      Lifecycle::path($file->getPathname());
      if (!$file->isFile()) throw new \RuntimeException('Inventário contém arquivo especial.');
      $relative=substr($file->getPathname(),strlen($root)+1);$hash=hash_file('sha256',$file->getPathname());
      if ($hash===false) throw new \RuntimeException('Falha no inventário.');
      $files[$relative]=['bytes'=>$file->getSize(),'sha256'=>$hash];
    }
    ksort($files,SORT_STRING);return $files;
  }
  /** Exige destino novo separado das raízes ativas e fontes; recebe absoluto e raízes extras, retorna absoluto sem criar diretórios. */
  private function destination(string $destination,array $sources=[]): string {
    $destination=Lifecycle::path($destination);
    if (file_exists($destination)||!is_dir(dirname($destination))) throw new \RuntimeException('Destino deve ser novo e seu pai deve existir.');
    foreach (array_merge([$this->installation,$this->config->data['contents'],$this->config->data['storage']],$sources) as $source) {
      $source=Lifecycle::path($source);
      if (self::inside($destination,$source)||self::inside($source,$destination)) throw new \RuntimeException('Destino sobrepõe instalação, dados ou origem.');
    }
    return $destination;
  }
  /** Remove somente staging privado criado por esta operação; recebe caminho, sem retorno; rejeita links e propaga falhas. */
  private static function erase(string $directory): void {
    Lifecycle::path($directory);
    foreach (scandir($directory)?:[] as $name) {
      if ($name==='.'||$name==='..') continue;
      $file=Lifecycle::path($directory.'/'.$name);
      if (is_dir($file)) self::erase($file);
      elseif (!is_file($file)||!unlink($file)) throw new \RuntimeException('Não foi possível remover staging privado.');
    }
    if (!rmdir($directory)) throw new \RuntimeException('Não foi possível remover staging privado.');
  }
  /** Publica árvore nova com staging privado; recebe destino e produtor, retorna caminho; falha preserva origem e destino anterior. */
  private function publish(string $destination,callable $producer): array {
    $stage=dirname($destination).'/.dbless-cms-ops-'.bin2hex(random_bytes(12));
    if (!mkdir($stage,0700)) throw new \RuntimeException('Staging operacional indisponível.');
    try {
      $producer($stage);
      if (file_exists($destination)||is_link($destination)||!rename($stage,$destination)) throw new \RuntimeException('Destino ocupado ou publicação indisponível.');
      return ['destination'=>$destination];
    } finally {if (is_dir($stage)) self::erase($stage);}
  }
  /** Copia instalação sem estado/configuração real; recebe origem/destino e preserva código/ativos existentes sem executar extensões. */
  private static function installation(string $source,string $target): void {
    if (!mkdir($target,0700)) throw new \RuntimeException('Destino de instalação indisponível.');
    foreach (['system','public_html'] as $part) self::copyTree($source.'/'.$part,$target.'/'.$part,static fn(string $file): bool=>!self::inside($file,$source.'/system/storage')&&$file!==$source.'/system/config/app.json');
    foreach (['LICENSE','NOTICE','THIRD_PARTY.md','export-manifest.json'] as $name) if (is_file($source.'/'.$name)) self::write($target.'/'.$name,self::read($source.'/'.$name));
  }
  /** Copia estado durável e configuração para instalação nova; recebe destino, não reutiliza sessões/cotas/travas nem estado de manutenção. */
  private function persistent(string $target): void {
    $storage=$this->config->data['storage'];$contents=$this->config->data['contents'];
    self::copyTree($storage,$target.'/system/storage',static fn(string $file): bool=>!self::inside($file,$contents)&&!in_array(substr($file,strlen($storage)+1),['sessions','rate','operations.lock','contents.lock','maintenance.json','plugins/files-manager/files.lock'],true));
    self::copyTree($contents,$target.'/system/storage/contents');
    self::write($target.'/system/config/app.json',self::read($this->privateConfig));
  }
  /** Gera snapshot consistente privado sob trava exclusiva; recebe destino novo, retorna caminho/hash do manifesto ou falha sem afetar origem. */
  public function backup(string $destination): array {
    $destination=$this->destination($destination);
    return Lifecycle::exclusive($this->config,function() use($destination): array {
      return $this->publish($destination,function(string $stage): void {
        self::installation($this->installation,$stage.'/installation');$this->persistent($stage.'/installation');
        self::write($stage.'/snapshot.json',JsonStore::encode(['schema_version'=>1,'created_at'=>gmdate(DATE_ATOM),'files'=>self::inventory($stage.'/installation')]));
      })+['manifest_sha256'=>hash_file('sha256',$destination.'/snapshot.json')];
    });
  }
  /** Valida snapshot estritamente por inventário; recebe diretório privado e retorna raiz de instalação ou erro em corrupção/link/entrada extra. */
  private static function snapshot(string $source): string {
    Lifecycle::path($source);$manifest=json_decode(self::read($source.'/snapshot.json'),true,32,JSON_THROW_ON_ERROR);
    if (($manifest['schema_version']??null)!==1||!is_array($manifest['files']??null)||$manifest['files']!==self::inventory($source.'/installation')) throw new \RuntimeException('Snapshot inválido ou corrompido.');
    return $source.'/installation';
  }
  /** Restaura snapshot validado em instalação nova; recebe backup/destino e retorna caminho; nunca sobrescreve instalação ou dados ativos. */
  public function restore(string $source,string $destination): array {
    $destination=$this->destination($destination,[$source]);$root=self::snapshot($source);
    return Lifecycle::exclusive($this->config,fn(): array=>$this->publish($destination,static function(string $stage) use($root): void {
      // copyTree exige destino novo; o staging já existe, portanto copia cada entrada validada.
      foreach (scandir($root)?:[] as $name) {
        if ($name==='.'||$name==='..') continue;
        if (is_dir($root.'/'.$name)) self::copyTree($root.'/'.$name,$stage.'/'.$name);
        else self::write($stage.'/'.$name,self::read($root.'/'.$name));
      }
    }));
  }
  /** Verifica pacote DBLESS-CMS em caixa alta ou baixa, nomes relativos e ausência de entradas extras; recebe raiz e retorna mapa de arquivos íntegros ou falha. */
  public static function verifyPackage(string $source): array {
    Lifecycle::path($source);$manifest=json_decode(self::read($source.'/export-manifest.json'),true,32,JSON_THROW_ON_ERROR);$expected=[];
    if (!in_array($manifest['name']??null,['dbless-cms','DBLESS-CMS'],true)||!is_string($manifest['version']??null)||!preg_match('/^2\\.\\d+\\.\\d+$/D',$manifest['version'])||!is_array($manifest['files']??null)) throw new \RuntimeException('Manifesto de pacote inválido.');
    foreach ($manifest['files'] as $entry) {
      $path=$entry['path']??null;
      if (!is_string($path)||$path===''||str_starts_with($path,'/')||str_contains($path,'\\')||preg_match('~(?:^|/)\.{1,2}(?:/|$)~',$path)||str_contains($path,"\0")||isset($expected[$path])||!is_int($entry['bytes']??null)||!is_string($entry['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$entry['sha256'])) throw new \RuntimeException('Entrada de pacote inválida.');
      $expected[$path]=['bytes'=>$entry['bytes'],'sha256'=>$entry['sha256']];
    }
    ksort($expected,SORT_STRING);$actual=self::inventory($source);unset($actual['export-manifest.json']);
    if ($actual!==$expected||!isset($expected['system/config/production.php'])) throw new \RuntimeException('Integridade do pacote reprovada.');
    return $expected;
  }
  /** Prepara atualização sem trocar instalação ativa; recebe pacote íntegro/destino novo e retorna caminho; preserva dados e extensões locais. */
  public function upgrade(string $package,string $destination): array {
    $destination=$this->destination($destination,[$package]);self::verifyPackage($package);
    return Lifecycle::exclusive($this->config,function() use($package,$destination): array {
      return $this->publish($destination,function(string $stage) use($package): void {
        self::installation($package,$stage.'/new');$this->persistent($stage.'/new');$next=$stage.'/new';
        // Somente áreas públicas fora do código são uploads; a raiz vazia/customizada permanece preservada.
        foreach (scandir($this->installation.'/public_html')?:[] as $name) {
          if (in_array($name,['.','..','index.php','.htaccess','cms-admin','templates','plugins','robots.txt','sitemap.xml'],true)) continue;
          $from=$this->installation.'/public_html/'.$name;$to=$next.'/public_html/'.$name;
          if (is_dir($to)) self::erase($to);
          if (is_dir($from)) self::copyTree($from,$to);else self::write($to,self::read($from));
        }
        $oldManifest=is_file($this->installation.'/export-manifest.json')?json_decode(self::read($this->installation.'/export-manifest.json'),true,32,JSON_THROW_ON_ERROR):['files'=>[]];
        foreach (['plugins','templates'] as $kind) {
          $old=$this->config->root.'/'.$kind;$new=$next.'/system/'.$kind;$known=[];
          foreach ($oldManifest['files']??[] as $entry) if (preg_match('~^system/'.$kind.'/([A-Za-z][A-Za-z0-9-]*)/~',$entry['path']??'',$match)) $known[$match[1]]=true;
          foreach (scandir($new)?:[] as $id) if (isset($known[$id])&&!file_exists($old.'/'.$id)) self::erase($new.'/'.$id);
          foreach (glob($old.'/*',GLOB_ONLYDIR)?:[] as $directory) {
            $id=basename($directory);
            if (!is_dir($new.'/'.$id)) {
              self::copyTree($directory,$new.'/'.$id);
              $assets=$this->installation.'/public_html/'.$kind.'/'.$id;
              if (is_dir($assets)) self::copyTree($assets,$next.'/public_html/'.$kind.'/'.$id);
            }
          }
        }
        foreach (scandir($next)?:[] as $name) if ($name!=='.'&&$name!=='..'&&!rename($next.'/'.$name,$stage.'/'.$name)) throw new \RuntimeException('Preparação de atualização indisponível.');
        if (!rmdir($next)) throw new \RuntimeException('Preparação de atualização indisponível.');
      });
    });
  }
}
