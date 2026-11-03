/** Pages working/public snapshots, URL redirects, schedules, metadata and recovery through synthetic authenticated HTTP only. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {trees} from './helpers/runtime.mjs';
import {pluginServer} from './helpers/plugins.mjs';
/** Decode one escaped form field; receives HTML/name, fails for missing contracts. */
function field(html,name){const value=html.match(new RegExp(`name="${name}" value="([^"]*)"`))?.[1];assert.notEqual(value,undefined,name);return value.replaceAll('&quot;','"').replaceAll('&amp;','&');}
for(const tree of trees)test(`${tree}: Pages draft never replaces publication until an explicit authorized transition`,async()=>{
  const sandbox=await pluginServer(tree);let cookie='',key='',route='new';
  /** Perform loopback HTTP and retain only the synthetic session; receives URL/form, returns manual-redirect response. */
  async function request(url,form=null){const response=await fetch(new URL(url,sandbox.base),{method:form?'POST':'GET',headers:cookie?{Cookie:cookie}:{},body:form,redirect:'manual'});for(const value of response.headers.getSetCookie())cookie=value.split(';')[0];return response;}
  /** Submit one editorial operation using fresh CSRF/CAS fields; receives action/extra, returns response without bypassing UI contracts. */
  async function change(action,extra={}){const opened=await request(`/cms-admin/?view=plugin-panel&plugin=pages&route=${route}&key=${key}`);const html=await opened.text();assert.equal(opened.status,200,sandbox.logs());const values={key,...Object.fromEntries(['csrf_token','catalog_revision','site_revision','source_revision'].map(name=>[name,field(html,name)])),...extra};return request(`/cms-admin/?view=plugin-api&plugin=pages&route=${route}&operation=editorial-${action}`,new URLSearchParams(values));}
  try{
    let html=await(await request('/cms-admin/')).text();await request('/cms-admin/',new URLSearchParams({action:'login',username:'editor',password:'Synthetic-DBLESS-CMS-password-2026',csrf_token:field(html,'csrf_token')}));
    let response=await change('create',{title:'Editorial fixture',slug:'editorial-fixture',model:'blank',parent:''});assert.equal(response.status,200,await response.clone().text());let result=await response.json();key=new URL(result.redirect,sandbox.base).searchParams.get('key');route='editor';assert.match(key,/^p-[a-f0-9]+$/);
    assert.equal((await request('/editorial-fixture')).status,404);
    response=await change('save',{slug:'editorial-fixture',parent:'',meta_title:'Editorial fixture',meta_description:'Description',meta_image:'',meta_imageAlt:'',meta_keywords:'',page_status:'draft',markup_enabled:'1',html:'<section><h1>Published first</h1><a href="https://example.org" target="_blank">External</a></section>',bindings:'[]',seo_title:'Search title',seo_description:'Search description',seo_canonical:'',seo_socialTitle:'Social title',seo_socialDescription:'',seo_socialImage:'',seo_socialImageAlt:''});assert.equal(response.status,200,await response.clone().text());
    response=await change('publish');assert.equal(response.status,200,await response.clone().text());html=await(await request('/editorial-fixture')).text();assert.match(html,/Published first/);assert.match(html,/<title>Search title<\/title>/);assert.match(html,/noopener noreferrer/);assert.match(html,/property="og:title" content="Social title"/);
    const catalogFile=path.join(sandbox.temporary,'contents/page-publications/catalog.json');let catalog=JSON.parse(fs.readFileSync(catalogFile)),snapshot=catalog.records[key].draft;
    response=await change('save',{slug:'editorial-new',parent:'',meta_title:'Changed title',meta_description:'Changed description',meta_image:'',meta_imageAlt:'',meta_keywords:'',page_status:'draft',markup_enabled:'1',html:'<section><h1>Pending second</h1></section>',bindings:'[]',...Object.fromEntries(Object.entries(snapshot.seo).map(([k,v])=>['seo_'+k,typeof v==='boolean'?(v?'1':''):v]))});assert.equal(response.status,200,await response.clone().text());
    html=await(await request('/editorial-fixture')).text();assert.match(html,/Published first/);assert.doesNotMatch(html,/Pending second/);assert.equal((await request('/editorial-new')).status,404);
    response=await change('publish');assert.equal(response.status,200);response=await request('/editorial-fixture');assert.equal(response.status,301);assert.ok(response.headers.get('location').endsWith('/editorial-new'));assert.match(await(await request('/editorial-new')).text(),/Pending second/);
    response=await change('save',{slug:'editorial-new',parent:key,meta_title:'Cycle',meta_description:'Cycle',meta_image:'',meta_imageAlt:'',meta_keywords:'',page_status:'draft',markup_enabled:'1',html:'<p>Cycle</p>',bindings:'[]',...Object.fromEntries(Object.entries(snapshot.seo).map(([k,v])=>['seo_'+k,typeof v==='boolean'?'':v]))});assert.equal(response.status,422);
    response=await change('unpublish');assert.equal(response.status,200);assert.equal((await request('/editorial-new')).status,404);
    response=await change('schedule',{publish_at:'2099-01-01T00:00:00Z'});assert.equal(response.status,200);assert.equal((await request('/editorial-new')).status,404);catalog=JSON.parse(fs.readFileSync(catalogFile));catalog.records[key].publishAt='2020-01-01T00:00:00Z';fs.writeFileSync(catalogFile,JSON.stringify(catalog));assert.match(await(await request('/editorial-new')).text(),/Pending second/);
    assert.doesNotMatch(sandbox.logs(),/PHP (?:Warning|Fatal|Parse)/);
  }finally{await sandbox.close();}
});
