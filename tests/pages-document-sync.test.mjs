/** Single Pages envelope regressions on disposable source/package fixtures: exact source, preserved data, validation and native projection without persistence/provider snapshots. */
import test from 'node:test';
import assert from 'node:assert/strict';
import {applications,fixture,inspect} from './helpers/extension-media.mjs';
for(const application of applications){
  test(`${application}: Pages envelope preserves authored bytes, native data and unresolved references`,()=>{
    const c=fixture(application);try{
      const result=inspect(c,`$p=$s->read('exemplo-cms')['data'];$original=$p['content'];$value=DBLESSCMS\\PageEditorDocument::open($p);$value['html'].="\\n<section>{{#blog.latest.limit.2.items}}<h2>{{title}}</h2>{{/blog.latest.limit.2.items}}</section>";$next=DBLESSCMS\\PageEditorDocument::apply($p,DBLESSCMS\\JsonStore::encode($value));echo json_encode(['source'=>$value['html'],'reopened'=>DBLESSCMS\\PageEditorDocument::open($next),'native'=>$original,'unchanged'=>$s->read('exemplo-cms')['data']===$p]);`);
      assert.equal(result.reopened.html,result.source);assert.deepEqual(result.reopened.content,result.native);assert.match(result.source,/Conheça o dbless-cms/);assert.match(result.source,/blog.latest.limit.2.items/);assert.equal(result.unchanged,true);
    }finally{c.close();}
  });
  test(`${application}: Pages rejects malformed, ambiguous and unsafe canonical envelopes`,()=>{
    const c=fixture(application);try{
      const codes=inspect(c,`$valid=DBLESSCMS\\PageEditorDocument::open($s->read('exemplo-cms')['data']);$cases=['{bad',json_encode(['html'=>'x']),json_encode(array_replace($valid,['html'=>3])),json_encode(array_replace($valid,['content'=>'bad'])),json_encode($valid+['unknown'=>true]),json_encode(array_replace($valid,['html'=>'<script>alert(1)</script>'])),json_encode(array_replace($valid,['html'=>'{{#blog.latest.items}}'])),json_encode(array_replace($valid,['html'=>'<img src="/cms-admin/private">']))];$codes=[];foreach($cases as $json){try{DBLESSCMS\\PageEditorDocument::decode($json);$codes[]=200;}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}}echo json_encode($codes);`);
      assert.deepEqual(codes,Array(8).fill(422));
    }finally{c.close();}
  });
}
for(const application of applications)test(`${application}: converted native integrations stay live source and forged/interactive slots are rejected`,()=>{
  const c=fixture(application);try{
    const result=inspect(c,`$p=$s->read('exemplo-cms')['data'];$p['content']['sections'][0]['body'][]='{{slides:page-showcase}}';$p['content']['blocks']=[['type'=>'slides','location'=>'page-showcase']];$value=DBLESSCMS\\PageEditorDocument::open($p);$ctx=new DBLESSCMS\\ContentContext($s,'essencial','','exemplo-cms',true);$rendered=DBLESSCMS\\ContentMarkup::render(['html'=>$value['html'],'bindings'=>[]],$ctx);$codes=[];foreach(['<div data-cms-component-slot="forged"></div>','<a href="/">{{slides:page-showcase}}</a>'] as $html){try{DBLESSCMS\\ContentMarkup::render(['html'=>$html,'bindings'=>[]],$ctx);$codes[]=200;}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}}echo json_encode(['html'=>$value['html'],'rendered'=>$rendered,'content'=>$value['content']===$p['content'],'codes'=>$codes]);`);
    assert.equal((result.html.match(/\{\{slides:page-showcase\}\}/g)||[]).length,2);assert.doesNotMatch(result.rendered,/\{\{slides:|data-cms-component-slot/);assert.equal(result.content,true);assert.deepEqual(result.codes,[422,422]);
  }finally{c.close();}
});

for(const application of applications)test(`${application}: publication time alone does not mark identical editorial snapshots as modified`,()=>{
  const c=fixture(application);try{const states=inspect(c,`$p=$s->read('exemplo-cms')['data'];$draft=['document'=>$p,'url'=>'example','seo'=>[],'editorial'=>[]];$live=$draft;$live['publishedAt']='2020-01-01T00:00:00Z';$row=['deletedAt'=>null,'scheduled'=>null,'publishAt'=>'2020-01-01T00:00:00Z','draft'=>$draft,'published'=>$live];$states=[DBLESSCMS\\PagePublications::status($row)];$row['draft']['document']['meta']['title']='Changed';$states[]=DBLESSCMS\\PagePublications::status($row);echo json_encode($states);`);assert.deepEqual(states,['published','modified']);}finally{c.close();}
});
