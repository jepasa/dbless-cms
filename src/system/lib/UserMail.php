<?php
/** Users transactional e-mail through the deployment's local MTA; links never derive from request Host or editorial configuration. */
declare(strict_types=1);
/** Native private authentication transport services. */
namespace DBLESSCMS;

/** Construct trusted recovery URLs and distinguish transport acceptance from actual mailbox delivery. */
final class UserMail {
  /** Validate configured sender and trusted HTTPS origin; loopback HTTP is supported for isolated local qualification only. */
  public static function configured(Config $config): bool {
    $options=$config->data['users'];$parts=parse_url($options['origin']);
    return filter_var($options['origin'],FILTER_VALIDATE_URL)!==false&&filter_var($options['from'],FILTER_VALIDATE_EMAIL)!==false&&is_array($parts)&&isset($parts['scheme'],$parts['host'])&&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['query'])&&!isset($parts['fragment'])&&($parts['scheme']==='https'||($parts['scheme']==='http'&&in_array($parts['host'],['127.0.0.1','localhost','[::1]'],true)))&&preg_match('~^(?:/[A-Za-z0-9_-]+)*/?$~D',$parts['path']??'')===1;
  }

  /** Build the absolute reset URL from deployment configuration and a bounded token; throws 503/422 rather than accepting Host-derived origins. */
  public static function resetUrl(Config $config,string $token): string {
    if(!self::configured($config))throw new HttpError(503,'Identity mail transport is not configured.','users.error.mail');
    if(!preg_match('/^[a-f0-9]{32}\.[a-f0-9]{64}$/D',$token))throw new HttpError(422,'Invalid recovery token.','users.error.reset');
    return rtrim($config->data['users']['origin'],'/').'/cms-admin/?view=reset&token='.rawurlencode($token);
  }

  /** Send plain UTF-8 mail using validated deployment headers; returns transport acceptance only and never logs message/token contents. */
  private static function send(Config $config,string $to,string $subject,string $body): bool {
    if(!self::configured($config)||!function_exists('mail')||filter_var($to,FILTER_VALIDATE_EMAIL)===false||preg_match('/[\r\n\x00]/',$to.$subject))return false;
    return @mail($to,'=?UTF-8?B?'.base64_encode($subject).'?=',$body,['From'=>$config->data['users']['from'],'MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8']);
  }

  /** Deliver a generated reset/initial-password link in the administrative language; throws on unconfigured/rejected transport without claiming delivery. */
  public static function recovery(Config $config,Language $language,array $recipient): void {
    $url=self::resetUrl($config,$recipient['token']);$body=$language->text('users.mail.reset_body',['name'=>$recipient['name'],'url'=>"\n\n".$url."\n\n",'minutes'=>30]);
    if(!self::send($config,$recipient['email'],$language->text('users.mail.reset_subject'),$body))throw new HttpError(503,'Identity mail transport rejected the request.','users.error.mail');
  }

  /** Notify a registered recipient after a password/factor change; returns acceptance, never rolls back an already committed security operation. */
  public static function notification(Config $config,Language $language,array $user,string $kind): bool {
    if(!in_array($kind,['password','mfa'],true))throw new \InvalidArgumentException('Invalid identity notification.');
    return self::send($config,$user['email'],$language->text('users.mail.changed_subject'),$language->text('users.mail.'.$kind.'_changed',['name'=>$user['name']]));
  }
}
