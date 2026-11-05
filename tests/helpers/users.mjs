/** Synthetic Users fixtures and independent Node TOTP oracle; never reads installation credentials or sends external mail. */
import fs from 'node:fs';
import path from 'node:path';
import {createHmac} from 'node:crypto';
import {server,inspect,password,fixture} from './extension-media.mjs';
export {password,inspect};
/** Decode a canonical synthetic Base32 key and compute RFC 6238 independently from the PHP implementation. */
export function totp(secret,timestamp=Math.floor(Date.now()/1000)){
  const alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';let bits='',bytes=[];
  for(const value of secret)bits+=alphabet.indexOf(value).toString(2).padStart(5,'0');
  for(let i=0;i+8<=bits.length;i+=8)bytes.push(parseInt(bits.slice(i,i+8),2));
  const counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(timestamp/30)));const hash=createHmac('sha1',Buffer.from(bytes)).update(counter).digest(),offset=hash.at(-1)&15;
  return String((hash.readUInt32BE(offset)&0x7fffffff)%1000000).padStart(6,'0');
}
/** Configure an isolated application to use its synthetic hash, a loopback reset origin and an invalid-domain sender. */
export function configure(context,base='https://cms.example.invalid'){
  context.env.DBLESS_CMS_ENV='production';const file=path.join(context.temporary,'app.json'),config=JSON.parse(fs.readFileSync(file));config.users={origin:base,from:'cms@example.invalid'};fs.writeFileSync(file,JSON.stringify(config));
}
/** Create a domain-only sandbox with no mail transport; caller must close it. */
export function usersFixture(application){const context=fixture(application);configure(context);return context;}
/** Start a loopback server with all mail captured in the temporary fixture; never uses a real recipient or MTA. */
export async function usersServer(application){return server(application,{syntheticMail:true,configure});}
/** Common PHP test-only assertions/services; exceptions produce narrow test labels instead of secret values. */
export const prelude=`DBLESSCMS\\Security::start($s->config);$u=new DBLESSCMS\\Users($s->config);
$check=static function($condition,$label){if(!$condition)throw new RuntimeException($label);};
$fails=static function($callback,$status,$key='')use($check){try{$callback();}catch(DBLESSCMS\\HttpError $error){$check($error->status===$status&&($key===''||$error->messageKey===$key),'Expected rejection: '.$key);return;}throw new RuntimeException('Expected failure: '.$key);};
$seed='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$initialize=static function()use($u,$s,$seed){$result=$u->initialize('Synthetic owner','owner@example.invalid','${password}',$seed,DBLESSCMS\\UserCrypto::totp($seed,time()-30),$u->snapshot()['revision']);$u->establish($result['user'],'totp',$result['fingerprint']);return $result;};`;
/** Extract a required hidden form field from synthetic rendered HTML without exposing full pages in errors. */
export function field(html,name){const result=html.match(new RegExp('name="'+name+'"[^>]*value="([^"]*)"'));if(!result)throw new Error('Missing form field: '+name);return result[1].replaceAll('&amp;','&').replaceAll('&quot;','"').replaceAll('&#039;',"'");}
/** Hold one independent synthetic HTTP cookie jar; requests do not follow redirects so security statuses remain observable. */
export function client(context){let cookie='';return async(url,options={})=>{const response=await fetch(context.base+url,{...options,redirect:'manual',headers:{...(cookie?{Cookie:cookie}:{}),...options.headers}});for(const value of response.headers.getSetCookie())if(value.startsWith('DBLESSCMSSESSID='))cookie=value.split(';')[0];return response;};}
