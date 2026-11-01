<?php
/** Read-only technical privacy preflight: reports configuration coverage and unresolved operator assessments, never certifies legal compliance or scans private message bodies. */
declare(strict_types=1);
namespace DBLESSCMS;
final class PrivacyAudit {
  /** Inspect known declarations without network/mail; receive store, return aggregate technical checks and explicit human-review limits, propagate malformed state. */
  public static function report(JsonStore $store): array {
    $site=$store->read('site')['data'];$settings=Privacy::settings($site['settings']['privacy']??[]);$privacy=new Privacy($store);$catalog=$privacy->repository()->read()['data'];$links=$privacy->links('',$settings);$issues=[];
    if($settings['controller']==='')$issues[]='controller-not-configured';if($settings['email']==='')$issues[]='privacy-contact-not-configured';if(!isset($links['privacy']))$issues[]='published-privacy-page-not-configured';
    if($store->config->data['contact_enabled']&&$settings['contactNotice']==='')$issues[]='enabled-legacy-contact-missing-notice';if($catalog['resources']!==[]&&($settings['controller']===''||$settings['email']===''||!isset($links['privacy'])))$issues[]='managed-resources-blocked-until-privacy-configured';
    $registry=new Plugins($store);if($registry->invalid()!==[])$issues[]='invalid-plugin-manifest';$forms=['published'=>0,'noncollecting'=>0,'collecting'=>0,'retainedAnswersEnabled'=>0];
    if(isset($registry->all()['forms'])){require_once $store->config->root.'/plugins/forms/Plugin.php';foreach(FormsModel::repository($store)->read()['data']['forms'] as $form){if($form['deleted']||$form['status']!=='published')continue;$forms['published']++;$forms[$form['demo']?'noncollecting':'collecting']++;if($form['retainDays']>0)$forms['retainedAnswersEnabled']++;if(!$form['demo']&&$form['privacyPage']==='')$issues[]='live-form-missing-privacy-reference';}}
    $assets=['publicRegistered'=>0,'privateRegistered'=>0,'approved'=>0,'needsReview'=>0];foreach((new MediaRights($store))->repository()->read()['data']['assets'] as $asset){if($asset['trash']!=='')continue;$assets[$asset['scope']==='public'?'publicRegistered':'privateRegistered']++;$assets[$asset['review']==='approved'?'approved':'needsReview']++;}
    return ['schema_version'=>1,'technicalIssues'=>array_values(array_unique($issues)),'legalCompliance'=>'not-certified','operatorReview'=>['jurisdiction-and-audience','legal-basis-per-purpose','mail-host-and-backup-retention','supplier-and-transfer-assessment','truthfulness-and-image-rights','applicable-commercial-offer-and-terms','legal-access-log-and-incident-retention'],'identity'=>['controllerConfigured'=>$settings['controller']!=='','privacyContactConfigured'=>$settings['email']!==''],'legalPagesPublished'=>array_keys($links),'managedOptionalResources'=>count($catalog['resources']),'consentBannerNeeded'=>$catalog['resources']!==[],'forms'=>$forms,'registeredImageRights'=>$assets,'imageCoverage'=>'registered-assets-only','optionalSanitizedDerivatives'=>function_exists('imagecreatefromstring'),'trackingCoverage'=>'declared-resources-only; trusted PHP/JS and hosting require separate review'];
  }
}
