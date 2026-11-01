<?php
/** Escaped accessible public form renderer and minimal public result document; requests never supply recipients, HTML, templates or script paths. */
declare(strict_types=1);
namespace DBLESSCMS;
final class FormsView {
  /** Render a bounded form fragment with current session-only ticket; receive trusted definition/base/current values/error, return escaped accessible HTML without delivery or persistence. */
  public static function fragment(array $form,string $base,array $values=[],string $error=''): string {
    $prefix='form-'.$form['id'].'-'.bin2hex(random_bytes(4));$ticket=FormsDelivery::ticket($form);$html='<section class="cms-form" data-cms-form aria-labelledby="'.$prefix.'-title"><h2 id="'.$prefix.'-title">'.e($form['name']).'</h2><p>'.e($form['description']).'</p><p class="cms-form-notice">'.e($form['noticeText']).'</p>';
    if($form['privacyPage']!=='')$html.='<p><a href="'.e($base.'/'.$form['privacyPage']).'">Privacy information</a></p>';
    if($form['demo'])$html.='<p role="status">Demonstration only. This form does not accept submissions.</p>';
    $html.='<form method="post" action="'.e($base.'/plugins/forms/submit').'" data-cms-form-submit><input type="hidden" name="id" value="'.e($form['id']).'"><input type="hidden" name="csrf_token" value="'.e(Security::token()).'"><input type="hidden" name="ticket" value="'.e($ticket).'">';
    foreach($form['fields'] as $field){$name=$field['name'];$id=$prefix.'-'.$name;$value=is_string($values[$name]??null)?substr($values[$name],0,$field['maxLength']):'';$required=$field['required']?' required':'';$label=e($field['label']).($field['required']?'<span aria-hidden="true"> *</span>':'');
      if($field['type']==='radio'){$html.='<fieldset><legend>'.$label.'</legend>';foreach($field['options'] as $index=>$option)$html.='<label><input type="radio" name="'.e($name).'" value="'.e($option).'"'.$required.($value===$option?' checked':'').'> '.e($option).'</label>';$html.='</fieldset>';continue;}
      if($field['type']==='checkbox'){$html.='<label class="cms-form-checkbox"><input type="checkbox" name="'.e($name).'" value="1"'.$required.($value==='1'?' checked':'').'> '.$label.'</label>';continue;}
      $html.='<label for="'.$id.'">'.$label.'</label>';
      if($field['type']==='textarea')$html.='<textarea id="'.$id.'" name="'.e($name).'" rows="5" maxlength="'.$field['maxLength'].'" autocomplete="'.e($field['autocomplete']).'"'.$required.'>'.e($value).'</textarea>';
      elseif($field['type']==='select'){$html.='<select id="'.$id.'" name="'.e($name).'"'.$required.'><option value="">Choose an option</option>';foreach($field['options'] as $option)$html.='<option value="'.e($option).'"'.($value===$option?' selected':'').'>'.e($option).'</option>';$html.='</select>';}
      else $html.='<input id="'.$id.'" type="'.e($field['type']).'" name="'.e($name).'" value="'.e($value).'" maxlength="'.$field['maxLength'].'" autocomplete="'.e($field['autocomplete']).'"'.$required.'>';
    }
    $html.='<div class="cms-form-trap" aria-hidden="true"><label>Leave empty<input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label></div>';
    if($form['legalBasis']==='consent')$html.='<label class="cms-form-checkbox"><input type="checkbox" name="consent" value="1" required> '.e($form['consentText']).'</label>';
    $html.='<p role="status" aria-live="polite" data-cms-form-status tabindex="-1">'.e($error).'</p><button type="submit"'.($form['demo']?' disabled':'').'>'.e($form['submitLabel']).'</button></form></section>';return $html;
  }
  /** Render public result/error/form page with active template styles and fixed local Forms assets; receive store/definition/base/values/message/success, return full HTML without executing template PHP from input. */
  public static function page(JsonStore $store,array $form,string $base,array $values=[],string $message='',bool $success=false): string {
    $site=$store->read('site')['data'];$locale=SiteLocale::resolve($site['settings']);$manifest=$store->templates->get($site['template']);$styles='';foreach($manifest['assets']['styles'] as $file)$styles.='<link rel="stylesheet" href="'.e($store->templates->asset($site['template'],$file,$base)).'">';
    return require __DIR__.'/pages/public.php';
  }
}
