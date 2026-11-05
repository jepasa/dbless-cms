<?php
/** Private Users policy contracts and server-side expiry rules; no I/O or credential generation occurs here. */
declare(strict_types=1);
/** Shared policy validation for HTTP, locked mutations and authentication. */
namespace DBLESSCMS;

/** Apply closed, bounded authentication policies while retaining read compatibility with catalogs lacking optional fields. */
final class UserPolicy {
  /** Each policy field declares [default, minimum, maximum]; durations name their unit explicitly. */
  public const FIELDS = [
    'loginAttempts'=>[5,1,20],
    'accountAttempts'=>[10,1,30],
    'loginWindowMinutes'=>[15,1,60],
    'sessionIdleMinutes'=>[30,5,120],
    'sessionAbsoluteMinutes'=>[480,30,1440],
    'passwordMinLength'=>[16,16,128],
    'passwordMaxAgeDays'=>[0,0,365],
    'resetLifetimeMinutes'=>[30,5,60],
    'factorAttempts'=>[5,1,10],
    'factorWindowMinutes'=>[5,1,15],
    'challengeMinutes'=>[5,1,10],
  ];

  /**
   * Resolve policy from a validated private catalog without adding fields or persisting defaults.
   * @param ?array $data Existing catalog or null before explicit Users initialization.
   * @return array<string,int> Complete effective policy.
   * @throws HttpError If an explicitly stored policy is corrupt; defaults never mask corruption.
   */
  public static function from(?array $data): array {
    if($data!==null&&array_key_exists('policy',$data)){
      if(!is_array($data['policy']))throw new HttpError(503,'Invalid identity policy.','users.error.policy');
      return self::validate($data['policy'],503);
    }
    return array_map(static fn(array $definition):int=>$definition[0],self::FIELDS);
  }

  /**
   * Validate the exact policy key set, scalar types, bounds and related session durations.
   * @param array $policy Decoded private policy, never arbitrary PHP options or regexes.
   * @param int $status Rejection status: stored corruption uses 503; submitted data uses 422.
   * @return array<string,int> Unmodified validated values.
   * @throws HttpError For unknown/missing fields, non-integers or inconsistent limits.
   */
  public static function validate(array $policy,int $status=422): array {
    $valid=count($policy)===count(self::FIELDS)&&array_diff(array_keys($policy),array_keys(self::FIELDS))===[];
    foreach(self::FIELDS as $key=>[, $minimum,$maximum])$valid=$valid&&is_int($policy[$key]??null)&&$policy[$key]>=$minimum&&$policy[$key]<=$maximum;
    if(!$valid||$policy['sessionAbsoluteMinutes']<$policy['sessionIdleMinutes'])throw new HttpError($status,'Invalid identity policy.','users.error.policy');
    return $policy;
  }

  /**
   * Parse the closed HTML form object without numeric coercion, arrays, booleans or overflow.
   * @param mixed $input Submitted policy fields.
   * @return array<string,int> Validated policy suitable for a locked CAS mutation.
   * @throws HttpError With 422 on malformed input; no state is modified.
   */
  public static function input(mixed $input): array {
    if(!is_array($input)||count($input)!==count(self::FIELDS)||array_diff(array_keys($input),array_keys(self::FIELDS)))throw new HttpError(422,'Invalid identity policy.','users.error.policy');
    $policy=[];
    foreach(self::FIELDS as $key=>$definition){$value=$input[$key]??null;if(!is_string($value)||!preg_match('/^(?:0|[1-9][0-9]{0,3})$/D',$value))throw new HttpError(422,'Invalid identity policy.','users.error.policy');$policy[$key]=(int)$value;}
    return self::validate($policy);
  }

  /**
   * Check a newly chosen secret against the immutable project floor and configured minimum.
   * @param string $password Entire submitted UTF-8 secret; never normalized, truncated or stored here.
   * @param array<string,int> $policy Current validated policy under the caller's transaction.
   * @return void
   * @throws \InvalidArgumentException If the existing blocklist/16–200 floor or configured minimum fails.
   */
  public static function password(string $password,array $policy): void {
    Passwords::provision($password);
    if(Passwords::length($password)<$policy['passwordMinLength'])throw new \InvalidArgumentException('Password does not meet the configured minimum.');
  }

  /**
   * Determine password age without treating unrelated profile edits as a password change.
   * @param array $user Validated account; older accounts use their known creation timestamp.
   * @param array<string,int> $policy Effective policy; zero days disables periodic expiry.
   * @param int $now Server timestamp, explicit for deterministic boundary verification.
   * @return bool Whether recovery is required before any private session can be issued or used.
   */
  public static function passwordExpired(array $user,array $policy,int $now): bool {
    return $policy['passwordMaxAgeDays']>0&&($user['passwordChangedAt']??$user['createdAt'])+$policy['passwordMaxAgeDays']*86400<=$now;
  }

  /**
   * Verify idle and absolute lifetimes using server-owned session timestamps on every authorization path.
   * @param array $auth Full session envelope.
   * @param array<string,int> $policy Current policy, including limits changed after login.
   * @param int $now Current server timestamp.
   * @return bool False for missing timestamps or either expired lifetime; caller must invalidate that session.
   */
  public static function session(array $auth,array $policy,int $now): bool {
    return is_int($auth['last_seen']??null)&&is_int($auth['at']??null)&&$auth['last_seen']+$policy['sessionIdleMinutes']*60>$now&&$auth['at']+$policy['sessionAbsoluteMinutes']*60>$now;
  }

  /**
   * Bound a pending login/enrollment by both its issued expiry and the current policy.
   * @param array $entry Server-held challenge; older sessions used a fixed five-minute lifetime.
   * @param array<string,int> $policy Current validated policy.
   * @param int $now Current server timestamp.
   * @return bool Whether the challenge remains live; raising a duration cannot extend issued expiry.
   */
  public static function challenge(array $entry,array $policy,int $now): bool {
    if(!is_int($entry['expires']??null)||$entry['expires']<=$now)return false;
    $issued=$entry['at']??($entry['expires']-300);
    return is_int($issued)&&$issued+$policy['challengeMinutes']*60>$now;
  }
}
