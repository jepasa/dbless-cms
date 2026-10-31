<?php
/** Password provisioning and verification policy: Unicode lengths, bounded inputs and legacy bcrypt safety; never persists secrets. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Passwords {
  /** Small offline baseline of whole commonly expected passwords; not a comprehensive breached-password corpus. */
  private const BLOCKED = ['1234567890123456','12345678901234567890','passwordpassword','passwordpassword123','qwertyuiopasdfgh','qwertyuiopasdfghjkl','administrator123','adminadminadminadmin','dbless-cmsdbless-cms','dbless-cms12345678'];

  /** Count UTF-8 code points without optional extensions; input password, returns count or -1 for invalid/control/oversize input, no effects. */
  public static function length(string $password): int {
    if (strlen($password)>800 || preg_match('/[\x00-\x1F\x7F]/',$password)) return -1;
    $count=preg_match_all('/./us',$password);
    return $count===false ? -1 : $count;
  }

  /** Validate a new secret against 16–200 code points and an offline whole-value blocklist; throws InvalidArgumentException, never alters existing credentials. */
  public static function provision(string $password): void {
    $count=self::length($password);
    if ($count<16 || $count>200) throw new \InvalidArgumentException('Senha deve conter de 16 a 200 caracteres Unicode válidos, sem caracteres de controle.');
    if (in_array(strtolower($password),self::BLOCKED,true)) throw new \InvalidArgumentException('Senha presente na lista local de valores comuns; escolha outra frase secreta.');
  }

  /** Verify the entire supplied secret against a recognized hash; returns false on malformed/oversize inputs or bcrypt over 72 bytes; no writes or migrations. */
  public static function verify(string $password,string $hash): bool {
    $length=self::length($password);
    if ($length<1 || $length>200 || (password_get_info($hash)['algoName']==='bcrypt' && strlen($password)>72)) return false;
    return password_verify($password,$hash);
  }
}
