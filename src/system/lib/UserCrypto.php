<?php
/** Users cryptographic primitives: password hashes, random tokens, protected TOTP seeds and RFC 6238 verification. */
declare(strict_types=1);
/** Private identity services; no editorial data or public provider is exposed. */
namespace DBLESSCMS;

/** Provide bounded native cryptography without remote services or application-defined password ciphers. */
final class UserCrypto {
  /** RFC 4648 alphabet used by authenticator enrollment keys. */
  private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

  /** Encode bytes as unpadded Base32; returns canonical text without persistence. */
  public static function base32(string $bytes): string {
    $buffer=0;$bits=0;$result='';
    foreach(str_split($bytes) as $byte){$buffer=($buffer<<8)|ord($byte);$bits+=8;while($bits>=5){$bits-=5;$result.=self::ALPHABET[($buffer>>$bits)&31];}$buffer&=(1<<$bits)-1;}
    if($bits>0)$result.=self::ALPHABET[($buffer<<(5-$bits))&31];return $result;
  }

  /** Decode a bounded canonical Base32 seed; throws InvalidArgumentException for malformed input, with no effects. */
  private static function decode(string $secret): string {
    if(!preg_match('/^[A-Z2-7]{16,128}$/D',$secret))throw new \InvalidArgumentException('Invalid authenticator key.');
    $buffer=0;$bits=0;$result='';
    foreach(str_split($secret) as $character){$buffer=($buffer<<5)|strpos(self::ALPHABET,$character);$bits+=5;if($bits>=8){$bits-=8;$result.=chr(($buffer>>$bits)&255);}$buffer&=(1<<$bits)-1;}
    return $result;
  }

  /** Generate a cryptographically random 160-bit authenticator seed; returns Base32, propagates entropy failures. */
  public static function seed(): string {return self::base32(random_bytes(20));}

  /** Hash a newly selected password with the project policy and independent salt; throws on invalid input or native hashing failure. */
  public static function password(string $password): string {if(!defined('PASSWORD_ARGON2ID')||!in_array('argon2id',password_algos(),true))throw new HttpError(503,'Identity cryptography unavailable.','users.error.crypto');Passwords::provision($password);return password_hash($password,PASSWORD_ARGON2ID,['memory_cost'=>65536,'time_cost'=>4,'threads'=>1]);}

  /** Compute RFC 6238 at an explicit Unix timestamp; supports reference-vector algorithms/digits, returns padded code or rejects invalid parameters. */
  public static function totp(string $secret,int $timestamp,int $digits=6,string $algorithm='sha1'): string {
    if($timestamp<0||!in_array($digits,[6,8],true)||!in_array($algorithm,['sha1','sha256','sha512'],true))throw new \InvalidArgumentException('Invalid TOTP parameters.');
    $step=intdiv($timestamp,30);$counter=pack('N2',intdiv($step,4294967296),$step&0xffffffff);
    $hash=hash_hmac($algorithm,$counter,self::decode($secret),true);$offset=ord($hash[strlen($hash)-1])&15;
    $number=unpack('N',substr($hash,$offset,4))[1]&0x7fffffff;return str_pad((string)($number%(10**$digits)),$digits,'0',STR_PAD_LEFT);
  }

  /** Find an unused TOTP counter in the ±1 window; returns counter/null, never writes or accepts a previously successful step. */
  public static function counter(string $secret,string $code,int $timestamp,int $lastStep=-1): ?int {
    if(!preg_match('/^[0-9]{6}$/D',$code))return null;$step=intdiv($timestamp,30);
    foreach([0,-1,1] as $offset){$candidate=$step+$offset;if($candidate>=0&&$candidate>$lastStep&&hash_equals(self::totp($secret,$candidate*30),$code))return $candidate;}
    return null;
  }

  /** Encrypt a seed with XChaCha20-Poly1305, binding ciphertext to its immutable user ID; returns encoded nonce/ciphertext, never stores the key. */
  public static function seal(string $secret,string $key,string $userId): string {
    self::native($key);$nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    return base64_encode($nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($secret,$userId,$nonce,$key));
  }

  /** Authenticate and decrypt one seed for its owner; throws a generic private-storage error on damaged/swapped ciphertext. */
  public static function unseal(string $value,string $key,string $userId): string {
    self::native($key);$bytes=base64_decode($value,true);$size=SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
    if($bytes===false||strlen($bytes)<$size+16)throw new HttpError(503,'Identity storage unavailable.','users.error.storage');
    $secret=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($bytes,$size),$userId,substr($bytes,0,$size),$key);
    if(!is_string($secret))throw new HttpError(503,'Identity storage unavailable.','users.error.storage');try{self::decode($secret);}catch(\InvalidArgumentException){throw new HttpError(503,'Identity storage unavailable.','users.error.storage');}return $secret;
  }

  /** Require the native AEAD extension and exactly one 256-bit installation key; throws 503 without insecure fallbacks. */
  private static function native(string $key): void {
    if(!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')||strlen($key)!==32)throw new HttpError(503,'Identity cryptography unavailable.','users.error.crypto');
  }
}
