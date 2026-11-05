<?php
/** Core Global Settings mail card: independent private form, translated controls, session/CSRF/capability enforcement and no credential echo. */
declare(strict_types=1);
/** Private mail administration namespace. */
namespace DBLESSCMS;

/** Render/save only the Core mail transport contract; keep SMTP credentials outside the public site editor. */
final class MailAdmin {
  /** Read one bounded scalar from the private form, without printing rejected submitted values. */
  private static function input(string $key,int $maximum=2048): string {$value=$_POST['mail_'.$key]??($key==='clear_password'?'0':'');if(!is_string($value)||strlen($value)>$maximum)throw new HttpError(422,'Invalid mail input.','mail.error.invalid');return $value;}

  /** Save only after Admin's shared authentication/CSRF checks; recheck capability here and redirect without retaining password input in session. */
  public static function save(Config $config,string $base): never {
    Access::require($config,'system.manage');$input=[];foreach(['method','from','name','origin','host','port','security','username','password','clear_password'] as $key)$input[$key]=self::input($key);
    (new MailSettings($config))->save($input,self::input('revision',64));$_SESSION['notice_key']='mail.saved';header('Location: '.$base.'/cms-admin/?view=site',true,303);exit;
  }

  /** Render escaped nonsecret settings in a standalone card/form, with a deliberately blank password and explicit optional deletion. */
  public static function render(Config $config,Language $language,string $base,string $csrf): string {
    $opened=(new MailSettings($config))->snapshot();$data=$opened['data'];$smtp=$data['smtp'];
    /** Resolve fixed control text through the current administrative dictionary; callers escape for their HTML context. */
    $t=static fn(string $key):string=>$language->text('mail.'.$key);
    /** Build a bounded single-line field; password is never passed to this builder from a saved/submitted credential. */
    $field=static fn(string $key,string $value,string $type='text',int $maximum=254):string=>'<label>'.e($t($key)).'<input type="'.e($type).'" name="mail_'.e($key).'" value="'.e($value).'" maxlength="'.$maximum.'"'.($key==='password'?' autocomplete="new-password"':'').'></label>';
    $html='<section class="panel" data-mail-settings><h2>'.e($t('title')).'</h2><p class="muted">'.e($t('help')).'</p><form method="post" action="'.e($base.'/cms-admin/?view=site').'" class="stack"><input type="hidden" name="action" value="mail-save"><input type="hidden" name="csrf_token" value="'.e($csrf).'"><input type="hidden" name="mail_revision" value="'.e($opened['revision']).'">';
    $html.='<label>'.e($t('method')).'<select name="mail_method" aria-label="'.e($t('method')).'">';foreach(['mail','smtp'] as $method)$html.='<option value="'.$method.'"'.($data['method']===$method?' selected':'').'>'.e($t('method.'.$method)).'</option>';$html.='</select></label>';
    $html.=$field('from',$data['from'],'email').$field('name',$data['name'],'text',100).$field('origin',$data['origin'],'url',2048).'<p class="muted">'.e($t('origin_help')).'</p>';
    $html.='<details open><summary>'.e($t('smtp_title')).'</summary><div class="stack">'.$field('host',$smtp['host'],'text',253).'<label>'.e($t('port')).'<input type="number" name="mail_port" value="'.$smtp['port'].'" min="1" max="65535" required></label><label>'.e($t('security')).'<select name="mail_security" aria-label="'.e($t('security')).'">';foreach(['starttls','tls'] as $mode)$html.='<option value="'.$mode.'"'.($smtp['security']===$mode?' selected':'').'>'.e($t('security.'.$mode)).'</option>';$html.='</select></label>';
    $html.=$field('username',$smtp['username'],'text',256).$field('password','','password',1024).'<p class="muted">'.e($t($smtp['passwordSet']?'password_saved':'password_empty')).' '.e($t('password_help')).'</p><label class="editor-checkbox"><input type="checkbox" name="mail_clear_password" value="1"> '.e($t('clear_password')).'</label></div></details><p><button type="submit" class="button-primary">'.e($t('save')).'</button></p></form></section>';
    return $html;
  }
}
