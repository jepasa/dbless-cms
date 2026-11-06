<?php
/** Users transactional e-mail through the selected Core transport; links never derive from request Host or editorial configuration. */
declare(strict_types=1);
/** Native private authentication transport services. */
namespace DBLESSCMS;

/** Construct trusted recovery URLs and distinguish transport acceptance from actual mailbox delivery. */
final class UserMail {
  /** Validate configured sender and trusted HTTPS origin; loopback HTTP is supported for isolated local qualification only. */
  public static function configured(Config $config): bool {
    $options=(new MailSettings($config))->snapshot()['data'];
    return MailSettings::origin($options['origin'])&&Mailer::address($options['from']);
  }

  /** Build the absolute reset URL from deployment configuration and a bounded token; throws 503/422 rather than accepting Host-derived origins. */
  public static function resetUrl(Config $config,string $token): string {
    try{if(!self::configured($config))throw new HttpError(503,'Identity mail transport is not configured.','users.error.mail');$origin=(new MailSettings($config))->snapshot()['data']['origin'];}
    catch(HttpError $failure){if(!str_starts_with($failure->messageKey,'mail.'))throw $failure;throw new HttpError(503,'Identity mail transport is not configured.','users.error.mail');}
    if(!preg_match('/^[a-f0-9]{32}\.[a-f0-9]{64}$/D',$token))throw new HttpError(422,'Invalid recovery token.','users.error.reset');
    return rtrim($origin,'/').'/cms-admin/?view=reset&token='.rawurlencode($token);
  }

  /** Send plain UTF-8 mail using validated deployment headers; returns transport acceptance only and never logs message/token contents. */
  private static function send(Config $config,string $to,string $subject,string $body): bool {
    try{if(!self::configured($config))return false;return Mailer::send($config,$to,$subject,$body);}
    catch(HttpError $failure){if(!str_starts_with($failure->messageKey,'mail.'))throw $failure;return false;}
  }

  /** Deliver a generated reset/initial-password link in the administrative language; throws on unconfigured/rejected transport without claiming delivery. */
  public static function recovery(Config $config,Language $language,array $recipient): void {
    $url=self::resetUrl($config,$recipient['token']);$minutes=(new Users($config))->policy()['resetLifetimeMinutes'];
    // Preserve the original issued limit if policy was lengthened before transport; existing callers without metadata retain the effective default.
    $minutes=min($minutes,$recipient['lifetimeMinutes']??$minutes);$body=$language->text('users.mail.reset_body',['name'=>$recipient['name'],'url'=>"\n\n".$url."\n\n",'minutes'=>$minutes]);
    if(!self::send($config,$recipient['email'],$language->text('users.mail.reset_subject'),$body))throw new HttpError(503,'Identity mail transport rejected the request.','users.error.mail');
  }

  /** Notify a registered recipient after a password/factor change; returns acceptance, never rolls back an already committed security operation. */
  public static function notification(Config $config,Language $language,array $user,string $kind): bool {
    if(!in_array($kind,['password','mfa'],true))throw new \InvalidArgumentException('Invalid identity notification.');
    return self::send($config,$user['email'],$language->text('users.mail.changed_subject'),$language->text('users.mail.'.$kind.'_changed',['name'=>$user['name']]));
  }
}
