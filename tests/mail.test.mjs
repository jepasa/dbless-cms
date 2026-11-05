/** Core mail qualification: private encrypted settings/CAS, real local TLS/AUTH, MIME safety, integration and uncertain delivery without retries. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications} from './helpers/extension-media.mjs';
import {usersFixture,inspect,prelude} from './helpers/users.mjs';
import {smtpServer,smtpInput,saveExpression,phpMail} from './helpers/smtp.mjs';

/** Enable only synthetic contact/forms and set their destination in the fixture's private example document. */
function enableForms(c){const file=c.env.DBLESS_CMS_APP_CONFIG,app=JSON.parse(fs.readFileSync(file));app.contact_enabled=true;fs.writeFileSync(file,JSON.stringify(app));const siteFile=path.join(c.contents,'site.json'),site=JSON.parse(fs.readFileSync(siteFile));site.settings.contact.email='recipient@example.invalid';fs.writeFileSync(siteFile,JSON.stringify(site));}
/** Build the existing synthetic form through its real model/ticket contracts, without bypassing delivery or receipts. */
const formSetup=`require $s->config->root.'/plugins/forms/Plugin.php';DBLESSCMS\\Security::start($s->config);$form=DBLESSCMS\\FormsModel::published($s,'contact-example');$form['demo']=false;$form['privacyPage']='starter-page';$ticket=DBLESSCMS\\FormsDelivery::ticket($form);$input=['id'=>$form['id'],'email'=>'visitor@example.invalid','message'=>'SYNTHETIC FORM BODY','ticket'=>$ticket];$delivery=new DBLESSCMS\\FormsDelivery($s);`;

for(const application of applications){
 test(`${path.basename(application)}: mail settings are read-only by default, encrypted/redacted, CAS protected and fail closed`,()=>{
  const c=usersFixture(application);try{
   const input=smtpInput({port:587,security:'starttls'}),encoded=Buffer.from(JSON.stringify(input)).toString('base64');
   const result=inspect(c,prelude+`$mail=new DBLESSCMS\\MailSettings($s->config);$initial=$mail->snapshot();$check($initial['data']['method']==='mail'&&$initial['data']['from']==='cms@example.invalid','Legacy defaults');$check(!file_exists($s->config->data['storage'].'/mail')&&!file_exists($s->config->data['storage'].'/keys/mail.key'),'GET creates nothing');$input=json_decode(base64_decode('${encoded}'),true);$mail->save($input,$initial['revision']);$file=$s->config->data['storage'].'/mail/settings.json';$key=$s->config->data['storage'].'/keys/mail.key';$raw=file_get_contents($file);$check(!str_contains($raw,$input['password']),'No plaintext stored');$snapshot=$mail->snapshot();$check(!isset($snapshot['data']['smtp']['password'])&&$snapshot['data']['smtp']['passwordSet'],'Redacted snapshot');$check(!str_contains(json_encode($snapshot),$input['password']),'No secret in projection');$check((fileperms($file)&0777)===0600&&(fileperms($key)&0777)===0600,'Private permissions');$check(!file_exists($s->config->data['storage'].'/keys/users.key'),'Independent key');$fails(fn()=>$mail->save($input,$initial['revision']),409,'mail.error.conflict');$check(file_get_contents($file)===$raw,'CAS no overwrite');$cipher=json_decode($raw,true)['smtp']['password'];$input['password']='';$input['name']='Changed display';$mail->save($input,$mail->snapshot()['revision']);$check(json_decode(file_get_contents($file),true)['smtp']['password']===$cipher,'Blank preserves credential');$bad=$input;$bad['host']='smtp://attacker.invalid/path';$fails(fn()=>$mail->save($bad,$mail->snapshot()['revision']),422,'mail.error.invalid');$bad=$input;$bad['security']='none';$fails(fn()=>$mail->save($bad,$mail->snapshot()['revision']),422,'mail.error.invalid');$bad=$input;$bad['from']="sender@example.invalid\r\nBcc: x@example.invalid";$fails(fn()=>$mail->save($bad,$mail->snapshot()['revision']),422,'mail.error.invalid');$input['method']='mail';$input['clear_password']='1';$mail->save($input,$mail->snapshot()['revision']);$check(!$mail->snapshot()['data']['smtp']['passwordSet'],'Explicit removal');$input['method']='smtp';$input['clear_password']='0';$input['password']='Synthetic-Second-SMTP-Secret';$mail->save($input,$mail->snapshot()['revision']);$good=file_get_contents($file);$damaged=json_decode($good,true);$damaged['smtp']['password']=base64_encode(str_repeat('x',64));file_put_contents($file,json_encode($damaged));$fails(fn()=>$mail->snapshot(),503,'mail.error.storage');file_put_contents($file,$good);unlink($key);$fails(fn()=>$mail->snapshot(),503,'mail.error.storage');$check(!file_exists($key),'Never regenerate a missing key');echo json_encode(['encrypted'=>true,'redacted'=>true,'cas'=>true,'readOnly'=>true,'lostKeyClosed'=>true]);`);
   assert.deepEqual(result,{encrypted:true,redacted:true,cas:true,readOnly:true,lostKeyClosed:true});
   assert.equal(fs.existsSync(path.join(c.env.DBLESS_CMS_STORAGE_ROOT,'backups/mail')),false);
  }finally{c.close();}
 });

 for(const [security,mechanism] of [['starttls','LOGIN'],['tls','PLAIN']])test(`${path.basename(application)}: ${security}/${mechanism} verifies TLS and sends Users, Contact and Forms independently of PHP mail`,async()=>{
  const c=usersFixture(application),smtp=await smtpServer({security,mechanism});try{
   enableForms(c);inspect(c,saveExpression(smtpInput(smtp))+`echo json_encode(true);`);
   const result=await phpMail(c,`$native=function_exists('mail');$ok=DBLESSCMS\\Mailer::send($s->config,'recipient@example.invalid','Synthetic ação','Dot test:'."\r\n.\r\n".'Olá!','visitor@example.invalid');$language=new DBLESSCMS\\Language();DBLESSCMS\\UserMail::recovery($s->config,$language,['email'=>'owner@example.invalid','name'=>'Synthetic Owner','token'=>str_repeat('a',32).'.'.str_repeat('b',64)]);$notice=DBLESSCMS\\UserMail::notification($s->config,$language,['email'=>'owner@example.invalid','name'=>'Synthetic Owner'],'password');DBLESSCMS\\Security::start($s->config);$_SERVER['REQUEST_METHOD']='POST';$_POST=['name'=>'Synthetic visitor','email'=>'visitor@example.invalid','subject'=>'Synthetic contact','message'=>'SYNTHETIC CONTACT BODY','csrf_token'=>DBLESSCMS\\Security::token()];ob_start();DBLESSCMS\\Contact::handle($s->config,$s,'https://cms.example.invalid');$contact=ob_get_clean();${formSetup}$a=$delivery->submit($form,$input);$b=$delivery->submit($form,$input);echo json_encode(['nativeMail'=>$native,'sent'=>$ok,'notice'=>$notice,'contact'=>$contact,'formOnce'=>$a===$b]);`);
   assert.deepEqual(result,{nativeMail:false,sent:true,notice:true,contact:'OK',formOnce:true});assert.equal(smtp.captures.messages.length,5);assert.ok(smtp.captures.authentication.every(auth=>auth.encrypted&&auth.username==='synthetic-user'&&auth.password==='Synthetic-SMTP-Secret-2026'));
   const decoded=smtp.captures.messages.map(message=>Buffer.from(message.split('\r\n\r\n')[1].replaceAll('\r\n',''),'base64').toString());assert.equal(decoded[0],'Dot test:\r\n.\r\nOlá!');assert.match(decoded[1],/https:\/\/cms.example.invalid\/cms-admin\/\?view=reset&token=a{32}\.b{64}/);assert.match(decoded[3],/SYNTHETIC CONTACT BODY/);assert.match(decoded[4],/SYNTHETIC FORM BODY/);assert.ok(!smtp.captures.messages.join('').includes('Synthetic-SMTP-Secret-2026'));
   const commands=smtp.captures.commands;assert.ok(commands.filter(item=>item.command.startsWith('EHLO ')).every(item=>item.command==='EHLO [127.0.0.1]'),'RFC 5321 literal client identity');if(security==='starttls'){assert.ok(commands.some(item=>item.command==='STARTTLS'&&!item.encrypted));assert.equal(commands.filter(item=>item.command.startsWith('EHLO ')&&!item.encrypted).length,5);}assert.ok(commands.filter(item=>item.command.startsWith('AUTH ')).every(item=>item.encrypted));
  }finally{await smtp.close();c.close();}
 });

 test(`${path.basename(application)}: SMTP rejects invalid certificates, downgrade, auth failure, malformed replies and timeout without fallback`,async()=>{
  for(const mode of ['wrongName','untrusted','omitStarttls','rejectAuth','badReply','stallGreeting']){
   const c=usersFixture(application),smtp=await smtpServer({[mode]:true});try{
    inspect(c,saveExpression(smtpInput(smtp))+`echo json_encode(true);`);const start=performance.now();
    const result=await phpMail(c,`echo json_encode(DBLESSCMS\\Mailer::send($s->config,'recipient@example.invalid','Synthetic test','Synthetic body'));`,mode==='untrusted'?'/dev/null':smtp.ca);assert.equal(result,false,mode);assert.equal(smtp.captures.messages.length,0,mode);
    if(mode!=='rejectAuth')assert.equal(smtp.captures.authentication.length,0,'No credential before verified TLS: '+mode);if(mode==='stallGreeting')assert.ok(performance.now()-start<10000,'Bounded protocol timeout');
   }finally{await smtp.close();c.close();}
  }
 });

 test(`${path.basename(application)}: uncertain DATA is not reported as success or replayed by Forms, and header injection never connects`,async()=>{
  const c=usersFixture(application),smtp=await smtpServer({dropAfterData:true});try{
   enableForms(c);inspect(c,saveExpression(smtpInput(smtp))+`echo json_encode(true);`);
   const invalid=await phpMail(c,`echo json_encode([DBLESSCMS\\Mailer::send($s->config,'recipient@example.invalid',"Subject\r\nBcc: attacker@example.invalid",'Body'),DBLESSCMS\\Mailer::send($s->config,"recipient@example.invalid\r\nBcc: x@example.invalid",'Subject','Body')]);`);assert.deepEqual(invalid,[false,false]);assert.equal(smtp.captures.connections,0);
   const result=await phpMail(c,`${formSetup}$out=[];for($i=0;$i<2;$i++){try{$delivery->submit($form,$input);$out[]=200;}catch(DBLESSCMS\\HttpError $failure){$out[]=$failure->status;}}$files=glob(DBLESSCMS\\FormsDelivery::directory($s).'/*.json');$record=DBLESSCMS\\FormsDelivery::receipt($files[0]);echo json_encode(['statuses'=>$out,'receipts'=>count($files),'state'=>$record['status']]);`);
   assert.deepEqual(result,{statuses:[503,503],receipts:1,state:'uncertain'});assert.equal(smtp.captures.messages.length,1);
  }finally{await smtp.close();c.close();}
 });
}
