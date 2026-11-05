<?php
/** Menu's bounded Blog adapter: published source choices and stable-ID destinations through the existing public provider, never editorial bodies or writes. */
declare(strict_types=1);
/** Shared CMS services and validated extension integration.
 * @package DBLESSCMS
 */
namespace DBLESSCMS;
/** Project stable article identities into Menu destinations without exposing drafts or coupling Menu to an installed Blog package. */
final class MenuTargets {
  /** @var ContentReferenceInterface|null Enabled historical article provider resolved through the installed owner. */
  private ?ContentReferenceInterface $provider=null;
  /** @var bool Whether provider discovery has been attempted, including an unavailable result. */
  private bool $loaded=false;
  /** @var array<string,array|null> Current public article metadata or a remembered miss, keyed by immutable ID. */
  private array $resolved=[];
  /** Receive shared read context; defer provider discovery and perform no writes. */
  public function __construct(
    /** @var ContentContext Immutable trusted read context; no persistence is performed by construction. */
    private readonly ContentContext $context
  ) {}
  /** Discover only the enabled Blog public-reference provider; return provider/null, propagate invalid plugin contracts, without mutation. */
  private function provider(): ?ContentReferenceInterface {
    if(!$this->loaded){$this->loaded=true;$plugins=new Plugins($this->context->store);if(isset($plugins->available()['blog'])&&$plugins->state('blog')['enabled']){$instance=$plugins->instance('blog');if($instance instanceof ContentReferenceInterface)$this->provider=$instance;}}
    return $this->provider;
  }
  /** Resolve a stable Blog ID to public metadata; receive validated ID, return current public value/null with request-local memoization, never drafts/comments or writes. */
  public function post(string $id): ?array {
    if(!array_key_exists($id,$this->resolved))$this->resolved[$id]=$this->provider()?->reference('id:'.$id,[],$this->context);
    return $this->resolved[$id];
  }
  /** List every released Blog target up to its 500-record contract; return ID/name/slug/href choices, preserve global provider pagination, propagate source errors and never write. */
  public function posts(): array {
    $provider=$this->provider();if($provider===null)return [];$result=[];
    for($page=1;$page<=500;$page++){
      $data=$provider->reference('latest',['limit'=>500,'page'=>$page],$this->context);
      foreach($data['items']??[] as $item)$result[$item['id']]=['name'=>$item['title'],'slug'=>$item['slug'],'href'=>$item['url']];
      if(!($data['hasNext']??false))break;
    }
    return $result;
  }
}
