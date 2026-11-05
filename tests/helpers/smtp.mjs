/** Loopback-only SMTP/TLS qualification with generated synthetic CA/certificates; no real credentials, domains or MTA are consulted. */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import tls from 'node:tls';
import {spawn,spawnSync} from 'node:child_process';
import {once} from 'node:events';
/** @type {object|undefined} Process-local generated certificate material, removed on exit. */
let material;

/** Generate one local CA and signed server identity with loopback SANs; errors abort qualification instead of disabling verification. */
export function certificates(){
 if(material)return material;
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'dbless-smtp-tls-'));
 /** Run OpenSSL with literal local arguments and bounded execution; never print a generated private key. */
 const command=args=>{const result=spawnSync('openssl',args,{cwd:directory,encoding:'utf8',timeout:10000});if(result.error)throw result.error;if(result.status!==0)throw new Error('Synthetic certificate generation failed: '+result.stderr);};
 command(['req','-x509','-newkey','rsa:2048','-nodes','-keyout','ca.key','-out','ca.crt','-subj','/CN=DBLESS synthetic SMTP CA','-days','1','-addext','basicConstraints=critical,CA:TRUE','-addext','keyUsage=critical,keyCertSign,cRLSign']);
 command(['req','-new','-newkey','rsa:2048','-nodes','-keyout','server.key','-out','server.csr','-subj','/CN=localhost']);
 for(const [name,san] of [['server','DNS:localhost,IP:127.0.0.1,IP:::1'],['wrong','DNS:wrong.example.invalid']]){
  fs.writeFileSync(path.join(directory,name+'.ext'),'basicConstraints=CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName='+san+'\n');
  command(['x509','-req','-in','server.csr','-CA','ca.crt','-CAkey','ca.key','-CAcreateserial','-out',name+'.crt','-days','1','-sha256','-extfile',name+'.ext']);
 }
 material={directory,ca:path.join(directory,'ca.crt'),key:fs.readFileSync(path.join(directory,'server.key')),cert:fs.readFileSync(path.join(directory,'server.crt')),wrong:fs.readFileSync(path.join(directory,'wrong.crt'))};
 process.once('exit',()=>fs.rmSync(directory,{recursive:true,force:true}));return material;
}

/** Start a loopback SMTP fixture with actual TLS and closed adversarial modes; return only synthetic captures plus async cleanup. */
export async function smtpServer(options={}){
 const cert=certificates(),security=options.security||'starttls',captures={commands:[],messages:[],authentication:[],connections:0},sockets=new Set();
 /** Attach the bounded synthetic protocol state to a plain or already-secured stream; never reuse it for a real target. */
 function attach(socket,encrypted,greeting=true){
  sockets.add(socket);socket.on('error',()=>{});socket.on('close',()=>sockets.delete(socket));let buffer='',phase='command',username='',data=[];
  /** Send one synthetic protocol reply; socket closure is an expected negative-test outcome. */
  const reply=value=>{if(!socket.destroyed)socket.write(value+'\r\n');};
  /** Consume complete CRLF lines from this stream; TLS framing remains exclusively owned by Node's TLS implementation. */
  const receive=chunk=>{buffer+=chunk.toString('utf8');if(buffer.length>2000000){socket.destroy();return;}let at;while((at=buffer.indexOf('\r\n'))!==-1){const line=buffer.slice(0,at);buffer=buffer.slice(at+2);
   if(phase==='data'){if(line==='.'){
    captures.messages.push(data.join('\r\n'));data=[];phase='command';if(options.dropAfterData){socket.destroy();return;}reply(options.rejectData?'550 rejected':'250 accepted');
   }else data.push(line.startsWith('..')?line.slice(1):line);continue;}
   if(phase==='plain'){const decoded=Buffer.from(line,'base64').toString().split('\0');captures.authentication.push({username:decoded[1],password:decoded[2],encrypted});phase='command';reply(options.rejectAuth?'535 denied':'235 authenticated');continue;}
   if(phase==='username'){username=Buffer.from(line,'base64').toString();phase='password';reply('334 UGFzc3dvcmQ6');continue;}
   if(phase==='password'){captures.authentication.push({username,password:Buffer.from(line,'base64').toString(),encrypted});phase='command';reply(options.rejectAuth?'535 denied':'235 authenticated');continue;}
   captures.commands.push({command:line,encrypted});
   if(line.startsWith('EHLO ')){reply('250-localhost');if(!encrypted&&!options.omitStarttls)reply('250-STARTTLS');reply('250 AUTH '+(options.mechanism||'PLAIN'));}
   else if(line==='STARTTLS'){
    reply('220 Ready for TLS');socket.removeListener('data',receive);
    const secure=new tls.TLSSocket(socket,{isServer:true,secureContext:tls.createSecureContext({key:cert.key,cert:options.wrongName?cert.wrong:cert.cert,minVersion:'TLSv1.2'})});
    attach(secure,true,false);return;
   }
   else if(line==='AUTH PLAIN'){phase='plain';reply('334 ');}
   else if(line==='AUTH LOGIN'){phase='username';reply('334 VXNlcm5hbWU6');}
   else if(line.startsWith('MAIL FROM:'))reply('250 sender');
   else if(line.startsWith('RCPT TO:'))reply('250 recipient');
   else if(line==='DATA'){phase='data';reply('354 send content');}
   else if(line==='QUIT'){reply('221 goodbye');socket.end();}
   else reply('500 unexpected command');
  }};
  socket.on('data',receive);
  if(greeting&&!options.stallGreeting){if(options.badReply)socket.write('220-first\r\n250 inconsistent\r\n');else reply('220 localhost synthetic SMTP');}
 }
 const server=security==='tls'?tls.createServer({key:cert.key,cert:options.wrongName?cert.wrong:cert.cert,minVersion:'TLSv1.2'},socket=>attach(socket,true)):net.createServer(socket=>attach(socket,false));
 server.on('connection',()=>captures.connections++);server.on('tlsClientError',()=>{});server.listen(0,'127.0.0.1');await once(server,'listening');
 return {port:server.address().port,security,ca:cert.ca,captures,async close(){for(const socket of sockets)socket.destroy();server.close();await once(server,'close');}};
}

/** Execute trusted synthetic PHP while the Node SMTP server can service sockets; real-mail fallback is disabled and output is bounded. */
export async function phpMail(context,expression,ca=certificates().ca){
 const child=spawn('php',['-d','disable_functions=mail','-d','display_errors=stderr','-d','openssl.cafile='+ca,'-r',"require $argv[1].'/system/bootstrap.php';$s=new DBLESSCMS\\JsonStore(new DBLESSCMS\\Config());"+expression,context.app],{env:context.env});
 let output='',errors='';child.stdout.on('data',chunk=>{output+=chunk;if(output.length>2000000)child.kill();});child.stderr.on('data',chunk=>{errors+=chunk;if(errors.length>2000000)child.kill();});const timer=setTimeout(()=>child.kill(),30000);
 try{const [status]=await once(child,'close');if(status!==0||errors)throw new Error('Synthetic PHP mail failed: '+errors);return JSON.parse(output);}finally{clearTimeout(timer);}
}

/** Return literal synthetic SMTP options used by both HTTP and domain tests; never accepts an external host. */
export function smtpInput(server,overrides={}){return {method:'smtp',from:'cms@example.invalid',name:'Synthetic CMS',origin:'https://cms.example.invalid',host:'127.0.0.1',port:String(server.port),security:server.security,username:'synthetic-user',password:'Synthetic-SMTP-Secret-2026',clear_password:'0',...overrides};}

/** Persist a synthetic configuration through its actual CAS API, returning only its redacted snapshot. */
export function saveExpression(input){const encoded=Buffer.from(JSON.stringify(input)).toString('base64');return `$mail=new DBLESSCMS\\MailSettings($s->config);$mail->save(json_decode(base64_decode('${encoded}'),true,8,JSON_THROW_ON_ERROR),$mail->snapshot()['revision']);`;}
