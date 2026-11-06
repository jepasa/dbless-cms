<?php
/** Bounded native SMTP submission client: verified TLS 1.2+, explicit AUTH, no plaintext downgrade, fallback, retries or protocol/credential logs. */
declare(strict_types=1);
/** Private SMTP submission namespace. */
namespace DBLESSCMS;

/** Deliver a single message through authenticated STARTTLS or implicit TLS using trusted Core configuration only. */
final class SmtpClient {
  /** Maximum overall connected protocol duration in seconds; DNS resolution additionally follows host resolver policy. */
  private const DEADLINE = 20;

  /** Apply a bounded per-operation timeout within the connected deadline; throws a generic protocol error on expiry. */
  private static function timeout(mixed $stream,float $until): void {$remaining=$until-microtime(true);if($remaining<=0)throw new \RuntimeException('SMTP timeout.');$seconds=min(5.0,$remaining);stream_set_timeout($stream,(int)$seconds,max(1,(int)(($seconds-(int)$seconds)*1000000)));}

  /** Write every byte, handling short writes and deadlines; no command or payload is included in failures. */
  private static function write(mixed $stream,string $value,float $until): void {$offset=0;$length=strlen($value);while($offset<$length){self::timeout($stream,$until);$written=@fwrite($stream,substr($value,$offset,8192));if(!is_int($written)||$written<1)throw new \RuntimeException('SMTP write failed.');$offset+=$written;}}

  /** Read at most 32 bounded reply lines with consistent status codes; remote reply text is never surfaced or logged. */
  private static function reply(mixed $stream,float $until): array {
    $lines=[];$code=null;for($i=0;$i<32;$i++){self::timeout($stream,$until);$line=@fgets($stream,1026);if(!is_string($line)||strlen($line)>1024||!preg_match('/^([2-5][0-9]{2})([ -])([^\r\n]*)\r\n$/D',$line,$match))throw new \RuntimeException('Invalid SMTP reply.');$current=(int)$match[1];if($code!==null&&$code!==$current)throw new \RuntimeException('Inconsistent SMTP reply.');$code=$current;$lines[]=$match[3];if($match[2]===' ')return [$code,$lines];}throw new \RuntimeException('SMTP reply limit exceeded.');
  }

  /** Send one fixed/generated protocol command and require a closed set of success statuses; never builds commands from remote replies. */
  private static function command(mixed $stream,string $command,array $expected,float $until): array {self::write($stream,$command."\r\n",$until);$reply=self::reply($stream,$until);if(!in_array($reply[0],$expected,true))throw new \RuntimeException('SMTP command rejected.');return $reply[1];}

  /** Enable TLS 1.2/1.3 using the verified peer context; failure aborts before any credential or message can be sent. */
  private static function secure(mixed $stream,float $until): void {self::timeout($stream,$until);$methods=STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;if(defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT'))$methods|=STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;if(@stream_socket_enable_crypto($stream,true,$methods)!==true)throw new \RuntimeException('SMTP TLS failed.');}

  /** Identify the connected client using its kernel-supplied IP literal, as RFC 5321 permits without a configured client FQDN; never trust request Host or invent a domain. */
  private static function hello(mixed $stream): string {
    $local=stream_socket_get_name($stream,false);$parts=is_string($local)?parse_url('tcp://'.$local):false;$address=is_array($parts)?trim($parts['host']??'','[]'):'';
    if(filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false)return '['.$address.']';
    if(filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false)return '[IPv6:'.$address.']';
    throw new \RuntimeException('SMTP client address unavailable.');
  }

  /** Submit one MIME document; return definite acceptance/rejection, or raise a generic uncertainty error if DATA acceptance cannot be established. */
  public static function send(array $options,string $from,string $to,string $message): bool {
    if(!extension_loaded('openssl')||!function_exists('stream_socket_client')||!function_exists('stream_socket_enable_crypto')||!function_exists('stream_socket_get_name')||!is_string($options['host']??null)||!MailSettings::host($options['host'])||!is_int($options['port']??null)||$options['port']<1||$options['port']>65535||!in_array($options['security']??null,['tls','starttls'],true)||!is_string($options['username']??null)||$options['username']===''||strlen($options['username'])>256||!is_string($options['password']??null)||$options['password']===''||strlen($options['password'])>1024||preg_match('/[\x00-\x1f\x7f]/',$options['username'].$options['password'])||!Mailer::address($from)||!Mailer::address($to)||strlen($message)>1500000)return false;
    $stream=null;$uncertain=false;$until=microtime(true)+self::DEADLINE;
    try{
      $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$options['host'],'SNI_enabled'=>true,'disable_compression'=>true]]);
      $host=str_contains($options['host'],':')?'['.$options['host'].']':$options['host'];$stream=@stream_socket_client('tcp://'.$host.':'.$options['port'],$errorCode,$errorMessage,5,STREAM_CLIENT_CONNECT,$context);if($stream===false)return false;
      if($options['security']==='tls')self::secure($stream,$until);
      if(self::reply($stream,$until)[0]!==220)return false;
      $hello=self::hello($stream);$capabilities=self::command($stream,'EHLO '.$hello,[250],$until);
      if($options['security']==='starttls'){
        if(!preg_grep('/^STARTTLS(?:\s|$)/i',$capabilities))return false;
        self::command($stream,'STARTTLS',[220],$until);self::secure($stream,$until);
        // RFC 3207 discards all pre-TLS capabilities, including authentication mechanisms.
        $capabilities=self::command($stream,'EHLO '.$hello,[250],$until);
      }
      $mechanisms=[];foreach($capabilities as $capability)if(preg_match('/^AUTH(?:[ =])(.+)$/i',$capability,$match))$mechanisms=array_merge($mechanisms,preg_split('/\s+/',strtoupper(trim($match[1]))));
      if(in_array('PLAIN',$mechanisms,true)){self::command($stream,'AUTH PLAIN',[334],$until);self::command($stream,base64_encode("\0".$options['username']."\0".$options['password']),[235],$until);}
      elseif(in_array('LOGIN',$mechanisms,true)){self::command($stream,'AUTH LOGIN',[334],$until);self::command($stream,base64_encode($options['username']),[334],$until);self::command($stream,base64_encode($options['password']),[235],$until);}
      else return false;
      self::command($stream,'MAIL FROM:<'.$from.'>',[250],$until);self::command($stream,'RCPT TO:<'.$to.'>',[250,251],$until);self::command($stream,'DATA',[354],$until);
      $uncertain=true;self::write($stream,preg_replace('/(?m)^\./','..',$message).".\r\n",$until);$reply=self::reply($stream,$until);$uncertain=false;
      if($reply[0]!==250)return false;
      // A confirmed DATA acceptance stays successful even if QUIT cannot be written/read.
      @fwrite($stream,"QUIT\r\n");return true;
    }catch(\RuntimeException){if($uncertain)throw new HttpError(503,'Mail acceptance could not be confirmed.','mail.error.uncertain');return false;}
    finally{if(is_resource($stream))fclose($stream);}
  }
}
