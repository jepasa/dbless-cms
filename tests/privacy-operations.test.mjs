/** Synthetic privacy operations: minimum audit output and deletion tombstones applied before supported restored answers become readable. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications,fixture,inspect} from './helpers/extension-media.mjs';
for(const app of applications){
  test(`${path.basename(app)}: privacy preflight separates technical setup from unresolved legal/operator review`,()=>{
    const c=fixture(app);try{
      const before=inspect(c,'echo json_encode(DBLESSCMS\\PrivacyAudit::report($s));');assert.ok(before.technicalIssues.includes('controller-not-configured'));assert.equal(before.legalCompliance,'not-certified');assert.equal(before.consentBannerNeeded,false);
      const after=inspect(c,"$site=$s->read('site');$data=$site['data'];$data['settings']['privacy']=['controller'=>'Synthetic operator','email'=>'rights@example.test','privacyPage'=>'starter-page','contactNotice'=>'Synthetic point-of-collection notice'];$s->save('site',DBLESSCMS\\JsonStore::encode($data),$site['revision']);echo json_encode(DBLESSCMS\\PrivacyAudit::report($s));");assert.deepEqual(after.technicalIssues,[]);assert.equal(after.legalCompliance,'not-certified');assert.ok(after.operatorReview.length>=7);assert.doesNotMatch(JSON.stringify(after),/rights@example\.test|Synthetic operator|password_hash|csrf_token/);
    }finally{c.close();}
  });
  test(`${path.basename(app)}: erased retained answers remain unavailable and are removed from an old restored receipt`,()=>{
    const c=fixture(app);try{
      const file=path.join(c.temporary,'app.json'),config=JSON.parse(fs.readFileSync(file));config.contact_enabled=true;fs.writeFileSync(file,JSON.stringify(config));
      const siteFile=path.join(c.contents,'site.json'),site=JSON.parse(fs.readFileSync(siteFile));site.settings.contact.email='operator@example.test';fs.writeFileSync(siteFile,JSON.stringify(site));
      const result=inspect(c,`require $s->config->root.'/plugins/forms/Plugin.php';$f=DBLESSCMS\\FormsModel::published($s,'contact-example');$f['demo']=false;$f['privacyPage']='starter-page';$f['retainDays']=30;$ticket=DBLESSCMS\\FormsDelivery::ticket($f);$delivery=new DBLESSCMS\\FormsDelivery($s,static fn():bool=>true);$id=$delivery->submit($f,['id'=>$f['id'],'ticket'=>$ticket,'email'=>'synthetic@example.test','message'=>'ERASE-ME-CANARY']);$file=DBLESSCMS\\FormsDelivery::directory($s).'/'.$id.'.json';$raw=file_get_contents($file);$restore=dirname($s->config->data['storage']).'/restored-storage';DBLESSCMS\\ExtensionDocument::atomic($restore.'/plugins/forms/receipts/'.$id.'.json',$raw);DBLESSCMS\\ErasureLedger::mark($s,$id);$guarded=DBLESSCMS\\FormsDelivery::receipt($file);$redacted=DBLESSCMS\\ErasureLedger::reconcile($s->config->data['storage'],$restore);$restored=DBLESSCMS\\FormsDelivery::receipt($restore.'/plugins/forms/receipts/'.$id.'.json');echo json_encode(['hadAnswers'=>str_contains($raw,'ERASE-ME-CANARY'),'guarded'=>$guarded['values'],'redacted'=>$redacted,'restored'=>$restored['values'],'bytesRemoved'=>!str_contains(file_get_contents($restore.'/plugins/forms/receipts/'.$id.'.json'),'ERASE-ME-CANARY'),'ledgerHasPII'=>str_contains(file_get_contents($restore.'/privacy/erasures.json'),'synthetic@example.test')]);`);
      assert.deepEqual(result,{hadAnswers:true,guarded:[],redacted:1,restored:[],bytesRemoved:true,ledgerHasPII:false});
    }finally{c.close();}
  });
  test(`${path.basename(app)}: retention masks immediately and physically removes expired answers while preserving a receipt`,()=>{
    const c=fixture(app);try{
      const result=inspect(c,`require $s->config->root.'/plugins/forms/Plugin.php';$id=str_repeat('a',32);$file=DBLESSCMS\\FormsDelivery::directory($s).'/'.$id.'.json';$record=['id'=>$id,'form'=>'contact-example','createdAt'=>time()-172800,'expiresAt'=>time()+86400,'retainUntil'=>time()-86400,'status'=>'accepted','values'=>['email'=>'EXPIRY-CANARY@example.test']];DBLESSCMS\\ExtensionDocument::atomic($file,DBLESSCMS\\ExtensionDocument::encode($record));$masked=DBLESSCMS\\FormsDelivery::receipt($file);$dry=DBLESSCMS\\FormsDelivery::cleanup($s,false);$before=str_contains(file_get_contents($file),'EXPIRY-CANARY');$applied=DBLESSCMS\\FormsDelivery::cleanup($s,true);echo json_encode(['masked'=>$masked['values'],'dry'=>$dry,'before'=>$before,'applied'=>$applied,'after'=>str_contains(file_get_contents($file),'EXPIRY-CANARY'),'exists'=>is_file($file)]);`);
      assert.deepEqual(result.masked,[]);assert.equal(result.dry.redacted,1);assert.equal(result.before,true);assert.equal(result.applied.redacted,1);assert.equal(result.after,false);assert.equal(result.exists,true);
    }finally{c.close();}
  });

}
