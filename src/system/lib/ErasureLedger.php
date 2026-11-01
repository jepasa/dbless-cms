<?php
/** Minimal private tombstones for erased Forms answers; opaque receipt IDs only, preserved across supported restores to prevent old backups from republishing retained answers. */
declare(strict_types=1);
namespace DBLESSCMS;
final class ErasureLedger {
  /** Read a fixed bounded erasure ledger; receive trusted storage root, return opaque IDs/times or throw on corruption/links, never log identities. */
  public static function read(string $storage): array {
    try{$file=Lifecycle::path($storage.'/privacy/erasures.json');}catch(\RuntimeException){throw new HttpError(503,'Unsafe erasure ledger.','forms.error.storage');}
    if(!file_exists($file))return [];
    if(!is_file($file)||filesize($file)>1048576)throw new HttpError(503,'Erasure ledger unavailable.','forms.error.storage');
    try{$data=json_decode((string)file_get_contents($file),true,8,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(503,'Invalid erasure ledger.','forms.error.storage');}
    if(!is_array($data)||count($data)!==2||($data['schema_version']??null)!==1||!is_array($data['receipts']??null)||count($data['receipts'])>10000)throw new HttpError(503,'Invalid erasure ledger.','forms.error.storage');
    foreach($data['receipts'] as $id=>$at)if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||!is_int($at)||$at<1)throw new HttpError(503,'Invalid erasure record.','forms.error.storage');return $data['receipts'];
  }
  /** Persist erasure intent before answer redaction; receive store/opaque receipt ID, return void under contents lock, preserve existing tombstones without storing user data. */
  public static function mark(JsonStore $store,string $id): void {
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new HttpError(422,'Invalid receipt ID.','forms.error.contract');
    $store->transaction(function()use($store,$id):void{$storage=$store->config->data['storage'];$entries=self::read($storage);if(count($entries)>=10000&&!isset($entries[$id]))throw new HttpError(503,'Review erasure ledger retention before further writes.','forms.error.storage');$entries[$id]=time();ExtensionDocument::atomic($storage.'/privacy/erasures.json',ExtensionDocument::encode(['schema_version'=>1,'receipts'=>$entries]));});
  }
  /** Merge current tombstones into a supported restore and physically redact matching copied receipts before publication; receive trusted current/restored storage roots, return redacted count, never deletes legal logs/backups. */
  public static function reconcile(string $current,string $restored): int {
    $entries=self::read($restored);foreach(self::read($current) as $id=>$at)$entries[$id]=max($at,$entries[$id]??0);if($entries===[])return 0;if(count($entries)>10000)throw new HttpError(503,'Erasure ledger quota exceeded.','forms.error.storage');
    ExtensionDocument::atomic($restored.'/privacy/erasures.json',ExtensionDocument::encode(['schema_version'=>1,'receipts'=>$entries]));$count=0;
    foreach($entries as $id=>$at){$file=Lifecycle::path($restored.'/plugins/forms/receipts/'.$id.'.json');if(!file_exists($file))continue;if(!is_file($file)||filesize($file)>131072)throw new HttpError(503,'Invalid restored receipt.','forms.error.storage');try{$record=json_decode((string)file_get_contents($file),true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(503,'Invalid restored receipt.','forms.error.storage');}if(!is_array($record)||($record['id']??null)!==$id||!is_array($record['values']??null))throw new HttpError(503,'Invalid restored receipt.','forms.error.storage');if($record['values']!==[]){$record['values']=[];$record['retainUntil']=min($at,$record['retainUntil']??$at);ExtensionDocument::atomic($file,ExtensionDocument::encode($record));$count++;}}
    return $count;
  }
}
