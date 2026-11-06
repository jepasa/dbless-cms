<?php
/** Central Core/plugin authorization and private publication ownership; navigation visibility never grants access. */
declare(strict_types=1);
/** Request authorization for authenticated private administration only. */
namespace DBLESSCMS;

/** Map closed capabilities to trusted server operations and deny unknown destinations by default. */
final class Access {
  /** Closed group capability vocabulary; Administrator always owns this complete set. */
  public const CAPABILITIES = ['users.manage','groups.manage','system.manage','posts.read','posts.create','posts.edit','posts.own','posts.publish','posts.taxonomies','comments.moderate','media.read','media.manage','media.private','plugin.homepage','plugin.menu','plugin.slides','plugin.widgets','plugin.forms'];

  /** Identify permissions that require mandatory MFA even in a custom group; receives validated names, returns bool. */
  public static function sensitive(array $permissions): bool {return (bool)array_intersect(['users.manage','groups.manage','system.manage'],$permissions);}

  /** Resolve a capability against the current catalog/session; preserve the explicit pre-migration single-credential mode only until initialization. */
  public static function allows(Config $config,string $capability): bool {
    if(!in_array($capability,self::CAPABILITIES,true))return false;
    $users=new Users($config);if(!$users->enabled())return Security::authenticated($config);
    $user=$users->current();if($user===null)return false;$data=$users->snapshot()['data'];$fresh=$data['users'][$user['id']]??null;$group=$data['groups'][$user['group']]??null;if(!is_array($fresh)||!is_array($group)||!hash_equals($_SESSION['users_auth']['fingerprint'],Users::fingerprint($fresh,$data['groups'][$fresh['group']])))return false;
    return $user['group']==='administrator'||in_array($capability,$group['permissions'],true);
  }

  /** Enforce one named capability, raising a localized 403 without disclosing private storage or membership details. */
  public static function require(Config $config,string $capability): void {if(!self::allows($config,$capability))self::denied();}

  /** Raise the common private authorization denial; never returns or modifies state. */
  private static function denied(): never {throw new HttpError(403,'Operation is not permitted.','users.error.forbidden');}

  /** Authorize a Core view before rendering or loading private editor state; receives normalized view/key and never writes. */
  public static function view(Config $config,string $view,string $key=''): void {
    if(in_array($view,['dashboard','users','login'],true))return;
    if(in_array($view,['site','plugins','templates','template-settings','customizations','customizations-preview','privacy'],true)||($view==='history'&&in_array($key,['site','plugins','templates'],true))){self::require($config,'system.manage');return;}
    if($view==='preview'){self::require($config,'posts.edit');return;}
    if(in_array($view,['plugin','plugin-panel','plugin-about','plugin-settings','pages','page-new','page-trash','edit','history'],true))return;
    self::denied();
  }

  /** Authorize fixed Core mutations before business dispatch; receives action/key and never trusts submitted role/group fields. */
  public static function action(Config $config,string $action,string $key=''): void {
    if($action==='logout')return;
    if(in_array($action,['create','save','remove','restore'],true)&&in_array($key,['home','404'],true)){self::require($config,'system.manage');return;}
    if(in_array($action,['create','save','remove','restore'],true)&&!in_array($key,['site','plugins','templates'],true)){self::require($config,'posts.edit');self::require($config,'posts.publish');return;}
    self::require($config,'system.manage');
  }

  /** Check a private plugin destination/operation, including legacy owner aliases; non-administrators cannot invoke unmapped extension operations. */
  public static function plugin(Config $config,JsonStore $store,string $id,string $route,string $operation='',string $method='GET'): void {
    if(self::allows($config,'system.manage'))return;
    if($route==='settings'||$operation==='settings'||$route==='options'||str_starts_with($operation,'settings-')||str_starts_with($operation,'options-'))self::denied();
    if(in_array($id,['posts','pages','blog'],true)){
      if(in_array($operation,['create','duplicate'],true)||$route==='new')self::require($config,'posts.create');
      if(in_array($route,['comments','moderation'],true)||in_array($operation,['comment','moderate'],true)){self::require($config,'comments.moderate');return;}
      if(in_array($route,['groups','tags','authors'],true)||str_starts_with($operation,'term-')){self::require($config,'posts.taxonomies');return;}
      if(in_array($route,['settings','type-dialog','special'],true)||str_starts_with($operation,'type-'))self::denied();
      if(in_array($operation,['publish','schedule','unpublish'],true)){self::require($config,'posts.publish');self::require($config,'posts.edit');return;}
      if(self::allows($config,'posts.edit')){
        if($id!=='posts'&&$operation!=='')self::require($config,'posts.publish');
        if($operation!==''&&!in_array($operation,['create','save','save-native','duplicate','remove','trash','recover','restore','restore-native','restore-source','adopt'],true))self::denied();return;
      }
      // Own-draft access is deliberately limited to canonical managed Posts IDs.
      if($id!=='posts')self::denied();
      if($route==='new'||$operation==='create'){self::require($config,'posts.create');return;}
      if(in_array($route,['overview','trash'],true)&&$operation===''&&self::allows($config,'posts.read'))return;
      self::require($config,'posts.own');
      if($operation!==''&&!in_array($operation,['save','duplicate','remove','trash','recover','restore'],true))self::denied();
      if(in_array($route,['overview','trash'],true)&&$operation==='')return;
      $record=$_POST['id']??$_GET['id']??'';if(!is_string($record)||!self::record($config,$store,$record))self::denied();return;
    }
    if($id==='files-manager'){
      if($route==='history')self::denied();self::require($config,'media.manage');$scope=$_POST['scope']??$_GET['scope']??'public';if($scope!=='public')self::require($config,'media.private');return;
    }
    if(in_array($id,['homepage','menu','slides','widgets','forms'],true)){self::require($config,'plugin.'.$id);return;}
    self::denied();
  }

  /** Allow all records for global editors, otherwise only the current user's currently unpublished, unscheduled managed draft; receives immutable ID. */
  public static function record(Config $config,JsonStore $store,string $id): bool {
    if(self::allows($config,'posts.edit'))return true;$users=new Users($config);$current=$users->current();if($current===null||!self::allows($config,'posts.own'))return false;
    $owner=$users->snapshot()['data']['ownership'][$id]??null;if($owner!==$current['id'])return false;
    $record=(new Posts($store))->records()[$id]??null;return is_array($record)&&($record['published']??null)===null&&($record['scheduled']??null)===null;
  }

  /** Filter trusted managed records to the actor's own currently unpublished and unscheduled drafts; global editors retain the complete catalog. */
  public static function records(Config $config,array $records): array {
    if(self::allows($config,'posts.edit'))return $records;$users=new Users($config);if(!$users->enabled())return $records;/* Legacy trusted CLI contexts retain their old projection; HTTP authentication stays in Admin. */$user=$users->current();
    if($user===null)return [];if(!self::allows($config,'posts.own'))return self::allows($config,'posts.read')?array_filter($records,static fn(array $record):bool=>Posts::effective($record)!==null):[];$ownership=$users->snapshot()['data']['ownership'];
    return array_filter($records,static fn(array $record):bool=>($ownership[$record['id']]??null)===$user['id']&&$record['published']===null&&$record['scheduled']===null);
  }

  /** Attach a newly created canonical publication to its authenticated creator; unknown legacy content is never claimed implicitly. */
  public static function claim(Config $config,string $id): void {
    $users=new Users($config);$current=$users->current();if($current!==null)$users->claim($id,$current['id']);
  }
}
