/** Shared editor regressions: source fidelity, closed profiles/HTML policy, bounded Mustache and inert program authoring in source and exported packages. Synthetic inputs only. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {spawnSync} from 'node:child_process';
import {applications} from './helpers/extension-media.mjs';

/** Query a pure core method without sessions/configuration or persistent state; receives application/expression, returns decoded JSON or throws on PHP diagnostics. */
function php(application,expression){const result=spawnSync('php',['-d','display_errors=stderr','-r',"require $argv[1].'/system/bootstrap.php';"+expression,application],{encoding:'utf8'});if(result.error)throw result.error;assert.equal(result.status,0,result.stderr);assert.equal(result.stderr,'');return JSON.parse(result.stdout);}

for(const application of applications){
  const asset=path.join(application,'public_html/cms-admin/assets'),context=vm.createContext({TextEncoder,URL});
  vm.runInContext(fs.readFileSync(path.join(asset,fs.existsSync(path.join(asset,'editor-model.js'))?'editor-model.js':'editor-model.min.js'),'utf8'),context);
  const model=context.DBLESSEditorModel,policy=php(application,'echo json_encode(DBLESSCMS\\ContentMarkup::policy());');
  /** Analyze synthetic HTML using the exact PHP policy; receives source/options, returns its original-byte model or propagates a contract failure. */
  const analyze=(source,options={language:'mustache'})=>model.analyze(source,options,policy);
  test(`${application}: HTML source and table loops retain their original byte order without DOM normalization`,()=>{
    const source="<section class='layout' data-section='news'>\n<table><tbody>{{#news.items}}<tr><td>{{ title }}</td></tr>{{/news.items}}</tbody></table>\n<p>{{cms.base}}</p></section>";
    const result=analyze(source);assert.equal(result.source,source);assert.ok(result.tokens.length===4);assert.equal(result.blocks.length,1);assert.equal(source.slice(result.blocks[0].start,result.blocks[0].end),'{{cms.base}}');
    const mixed=analyze('<section><h2>Editable title</h2>{{#news.items}}<article><p>{{title}}</p></article>{{/news.items}}<p>Editable footer</p></section>');assert.equal(mixed.blocks.length,2);assert.deepEqual(mixed.blocks.map(block=>mixed.source.slice(block.start,block.end)).join('|'),'Editable title|Editable footer');
    const collision='<p>__QME_MUSTACHE_0__ {{title}}</p>';assert.equal(analyze(collision).source,collision);
    assert.equal(php(application,'echo json_encode(substr_count(DBLESSCMS\\Mustache::render('+JSON.stringify(source)+',["news"=>["items"=>[["title"=>"A"],["title"=>"B"]]]]),"<tr>"));'),2);
  });
  test(`${application}: profiles cover HTML, Mustache, PHP, CSS and JS without enabling program execution`,()=>{
    for(const language of ['html','mustache','php','css','js']){const profile=php(application,'echo json_encode(DBLESSCMS\\Editor::profile('+JSON.stringify(language)+'));');assert.deepEqual(JSON.parse(JSON.stringify(model.options({language}))),profile);}
    for(const [language,source] of [['php','<?php echo "<script>alert(1)</script>"; ?>'],['js','globalThis.editorExecuted=true;'],['css','body { display: none; }']]){const result=analyze(source,{language});assert.equal(result.source,source);assert.equal(result.tree,null);assert.equal(result.settings.visual,false);}
    for(const options of [{language:'python'},{language:'php',visual:true},{language:'js',mustache:true},{language:'html',height:1},{language:'html',sanitize:false},{language:'html',toolbar:['eval']}])assert.throws(()=>model.options(options));
  });
  test(`${application}: malformed or unsupported HTML/Mustache is rejected while its input stays unchanged`,()=>{
    for(const source of ['{{{csrf_field}}}','{{title','{{bad:name}}','{{#items}}{{/other}}','<p style="color:red">Text</p>','<iframe srcdoc="plain"></iframe>','<form><input name="value"></form>','<p onclick="evil()">Text</p>','<?php echo 1; ?>','<p data-dbless-token="forged">Text</p>']){const original=source;assert.throws(()=>analyze(source));assert.equal(source,original);}
    assert.throws(()=>analyze('{{title}}',{language:'html'}));assert.throws(()=>analyze('{{#a}}'.repeat(9)+'{{/a}}'.repeat(9)));
    assert.throws(()=>analyze('<p>'+('a'.repeat(131073))+'</p>'));
    for(const token of ['{{template:fragment id="contact"}}','{{forms:form id="contact"}}'])assert.equal(analyze(token).source,token);
  });
  test(`${application}: editor URLs reject active schemes, encoded traversal, protocol-relative addresses and credentials`,()=>{
    for(const value of ['javascript:alert(1)','java&#x73;cript:alert(1)','data:text/html,x','//example.invalid/a','%2e%2e/private','https://user:pass@example.invalid/a'])assert.throws(()=>analyze('<p><a href="'+value+'">Link</a></p>'));
    for(const value of ['/media/example.png','{{cms.base}}/media/example.png','https://example.invalid/image.png'])assert.equal(analyze('<p><img src="'+value+'" alt="Example"></p>').blocks.length,1);
  });
  test(`${application}: addressed editor icons remain local static geometry without executable or external content`,()=>{
    const directory=path.join(asset,'editor-icons'),files=fs.readdirSync(directory);assert.equal(files.length,13);const hashes=new Set();
    for(const file of files){assert.match(file,/^[a-z]+\.svg$/);const source=fs.readFileSync(path.join(directory,file),'utf8');assert.match(source,/viewBox="0 0 20 20"/);assert.match(source,/Apache-2.0/);assert.doesNotMatch(source,/<(?:script|foreignObject|image|use|style)\b|\bon[a-z]+\s*=|\b(?:href|src)\s*=|url\s*\(/i);hashes.add(source.replace(/<!--[\s\S]*?-->/g,''));}assert.equal(hashes.size,files.length,'Every action must have distinct geometry');
  });
  test(`${application}: server rejects forged editor markup and keeps PHP outside editorial content`,()=>{
    const result=php(application,'$cases=["<p data-dbless-token=\\"x\\">Text</p>","<?php echo 1; ?>"]; $codes=[];foreach($cases as $html){try{DBLESSCMS\\ContentMarkup::definition(["html"=>$html,"bindings"=>[]]);$codes[]=200;}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}}echo json_encode($codes);');assert.deepEqual(result,[422,422]);
    const javascript=fs.readFileSync(path.join(asset,fs.existsSync(path.join(asset,'editor.js'))?'editor.js':'editor.min.js'),'utf8');assert.doesNotMatch(javascript,/\beval\s*\(|new\s+Function\s*\(|execCommand\s*\(/);
    const frame=fs.readFileSync(path.join(asset,fs.existsSync(path.join(asset,'editor-frame.js'))?'editor-frame.js':'editor-frame.min.js'),'utf8');assert.doesNotMatch(frame,/\beval\s*\(|new\s+Function\s*\(|execCommand\s*\(/);
  });
}
