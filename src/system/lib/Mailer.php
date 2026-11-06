<?php
/** Common text-mail transport for account security notifications, recovery and enabled contact/forms; no queue, retries or secret logging. */
declare(strict_types=1);
/** Private Core mail delivery namespace. */
namespace DBLESSCMS;

/** Select exactly the configured transport and build bounded injection-safe UTF-8 MIME messages. */
final class Mailer {
  /** Require a bounded ASCII mailbox without header delimiters. */
  public static function address(string $value): bool {return strlen($value)<=254&&filter_var($value,FILTER_VALIDATE_EMAIL)!==false&&!preg_match('/[^\x21-\x7e]|[<>]/',$value);}

  /** Encode a bounded display name/subject into folded RFC 2047 words without splitting UTF-8 code points. */
  private static function encoded(string $value): string {
    $words=[];$part='';foreach(preg_split('//u',$value,-1,PREG_SPLIT_NO_EMPTY) as $character){if(strlen($part.$character)>42){$words[]='=?UTF-8?B?'.base64_encode($part).'?=';$part='';}$part.=$character;}if($part!=='')$words[]='=?UTF-8?B?'.base64_encode($part).'?=';return implode("\r\n ",$words);
  }

  /** Report local transport prerequisites without connecting to any server or claiming mailbox delivery. */
  public static function available(Config $config): bool {$data=(new MailSettings($config))->snapshot()['data'];return $data['method']==='smtp'?extension_loaded('openssl')&&extension_loaded('sodium')&&function_exists('stream_socket_client')&&function_exists('stream_socket_enable_crypto')&&function_exists('stream_socket_get_name')&&$data['from']!==''&&$data['smtp']['host']!==''&&$data['smtp']['username']!==''&&$data['smtp']['passwordSet']:function_exists('mail');}

  /** Send one plain-text message; optional legacy sender preserves existing contact behavior until the global sender is saved. Returns transport acceptance, throws only for uncertain SMTP acceptance/private storage failures. */
  public static function send(Config $config,string $to,string $subject,string $body,string $reply='',string $legacyFrom=''): bool {
    if(!self::address($to)||($reply!==''&&!self::address($reply))||strlen($subject)>512||preg_match('//u',$subject)!==1||preg_match('/[\x00-\x1f\x7f]/',$subject)||strlen($body)>1048576||preg_match('//u',$body)!==1||str_contains($body,"\0"))return false;
    $options=(new MailSettings($config))->delivery();$from=$options['from']!==''?$options['from']:$legacyFrom;if(!self::address($from))return false;
    $headers=['From'=>$options['name']===''?$from:self::encoded($options['name']).' <'.$from.'>','MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8'];if($reply!=='')$headers['Reply-To']=$reply;
    $subject=self::encoded($subject);
    if($options['method']==='mail')return function_exists('mail')&&@mail($to,$subject,$body,$headers);
    $headers=['Date'=>gmdate('D, d M Y H:i:s').' +0000','Message-ID'=>'<'.bin2hex(random_bytes(16)).'@'.substr(strrchr($from,'@'),1).'>','To'=>$to,'Subject'=>$subject]+$headers+['Content-Transfer-Encoding'=>'base64'];
    $lines=[];foreach($headers as $key=>$value)$lines[]=$key.': '.$value;
    $message=implode("\r\n",$lines)."\r\n\r\n".rtrim(chunk_split(base64_encode($body),76,"\r\n"),"\r\n")."\r\n";
    return SmtpClient::send($options['smtp'],$from,$to,$message);
  }
}
