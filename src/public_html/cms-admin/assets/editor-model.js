/** DBLess-CMS source-preserving HTML/Mustache model. Lexes source without DOM normalization; PHP/CSS/JS are inert code profiles. No I/O, evaluation or provider dispatch. */
(() => {
  'use strict';
  const profiles = Object.freeze({html:{language:'html',visual:true,mustache:false},mustache:{language:'html',visual:true,mustache:true},php:{language:'php',visual:false,mustache:false},css:{language:'css',visual:false,mustache:false},js:{language:'js',visual:false,mustache:false}});
  const blockTags = new Set('div section article aside header footer nav p h1 h2 h3 h4 h5 h6 li dt dd blockquote figcaption td th pre'.split(' '));
  const voidTags = new Set(['br','hr','img','source']);
  /** Decode inert attribute/text entities; accepts source text, returns Unicode without interpreting tags or making requests. */
  function decode(value) {
    if(typeof document!=='undefined') {const area=document.createElement('textarea');area.innerHTML=value.replace(/</g,'&lt;');return area.value;}
    const names={amp:'&',quot:'"',apos:"'",lt:'<',gt:'>',nbsp:'\u00a0',colon:':',Tab:'\t',NewLine:'\n'};
    return value.replace(/&(#x[0-9a-f]+|#[0-9]+|[a-z]+);/gi,(all,name)=>{if(name[0]!=='#')return names[name]??all;const number=parseInt(name.slice(name[1].toLowerCase()==='x'?2:1),name[1].toLowerCase()==='x'?16:10);return number>0&&number<=0x10ffff?String.fromCodePoint(number):'\ufffd';});
  }
  /** Validate and merge a closed instance configuration; receives options, returns a fresh profile or throws TypeError before mounting. */
  function options(input={}) {
    const keys=['language','mustache','visual','readOnly','height','toolbar'];
    if(!input||typeof input!=='object'||Array.isArray(input)||Object.keys(input).some(key=>!keys.includes(key)))throw new TypeError('Invalid editor options.');
    const language=input.language??'html';if(!Object.hasOwn(profiles,language))throw new TypeError('Unsupported editor language.');
    const result={...profiles[language],readOnly:false,height:420,toolbar:['bold','italic','link','image','video','list','paragraph','heading','quote'],...input};
    if(language==='mustache')result.language='html';
    if(['visual','mustache','readOnly'].some(key=>typeof result[key]!=='boolean')||!Number.isInteger(result.height)||result.height<220||result.height>1200||!Array.isArray(result.toolbar)||result.toolbar.length>12||result.toolbar.some(tool=>!['bold','italic','link','image','video','list','paragraph','heading','quote'].includes(tool))||new Set(result.toolbar).size!==result.toolbar.length)throw new TypeError('Invalid editor options.');
    if(result.language!=='html'&&(result.visual||result.mustache))throw new TypeError('Code profiles cannot render content.');return result;
  }
  /** Validate the bounded data-only grammar plus registered marker shapes; receives source/enabled flag, returns tokens or throws an indexed SyntaxError. */
  function mustache(source,enabled) {
    let position=0;const tokens=[],stack=[];let fragments=0,forms=0;
    while((position=source.indexOf('{{',position))!==-1) {
      if(!enabled)throw new SyntaxError('Mustache requires the mustache profile at '+position);
      const end=source.indexOf('}}',position+2);if(end<0||end-position>512||tokens.length>=1024)throw new SyntaxError('Invalid Mustache token at '+position);
      const raw=source.slice(position,end+2),name=raw.slice(2,-2).trim();let kind='value';
      if(/^template:fragment id="[a-z][a-z0-9-]{0,47}"(?: key="[a-z][a-z0-9-]{0,47}")?$/.test(name)){kind='fragment';if(raw!=='{{'+name+'}}')throw new SyntaxError('Invalid template fragment syntax.');if(++fragments>20)throw new SyntaxError('Template fragment limit.');}
      else if(/^[a-z][a-z0-9-]{0,63}:form\s+id="[a-z][a-z0-9-]{0,47}"$/.test(name)){kind='form';if(++forms>10)throw new SyntaxError('Form marker limit.');}
      else if(name.startsWith('!'))kind='comment';
      else {
        const match=/^([#^\/]?)(\.|[a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]*)*)$/.exec(name);if(!match)throw new SyntaxError('Unsupported Mustache syntax at '+position);
        kind=match[1]||'value';if(kind==='#'||kind==='^'){stack.push(match[2]);if(stack.length>8)throw new SyntaxError('Mustache depth limit.');}
        if(kind==='/'&&stack.pop()!==match[2])throw new SyntaxError('Mismatched Mustache section at '+position);
      }
      tokens.push({raw,start:position,end:end+2,kind});position=end+2;
    }
    if(stack.length)throw new SyntaxError('Unclosed Mustache section.');return tokens;
  }
  /** Validate an addressed URL without fetching; receives decoded attribute, rejects active/encoded schemes, credentials and traversal. */
  function url(value,attribute) {
    let address=value.replace(/\{\{\s*[a-zA-Z][a-zA-Z0-9_.-]*\s*\}\}/g,'safe'),decoded=address;
    try{decoded=decodeURIComponent(decodeURIComponent(address));}catch{throw new SyntaxError('Invalid URL encoding.');}
    if(/[\x00-\x20\x7f]/.test(address)||/[\x00-\x1f\x7f\\]/.test(decoded)||decoded.startsWith('//')||decoded.includes('..'))throw new SyntaxError('Unsafe URL.');
    if(decoded.includes(':')&&!(attribute==='href'?/^(https?:\/\/|mailto:|tel:)/i:/^https?:\/\//i).test(decoded))throw new SyntaxError('Unsupported URL.');
    if(/^https?:\/\//i.test(decoded)){let parsed;try{parsed=new URL(address);}catch{throw new SyntaxError('Invalid URL.');}if(parsed.username||parsed.password)throw new SyntaxError('URL credentials are forbidden.');}
    if(/(?:^|\/)cms-admin(?:\/|[?]|$)/i.test(decoded)||/(?:^|\/)system(?:\/|$)/i.test(decoded))throw new SyntaxError('Private media cannot be embedded in public content.');
    return value;
  }
  /** Read a quote-aware HTML tag boundary; receives source/start offset, returns exclusive end or throws for unfinished markup. */
  function tagEnd(source,start) {
    let quote='';for(let index=start+1;index<source.length;index++){const character=source[index];if(quote){if(character===quote)quote='';}else if(character==='"'||character==="'")quote=character;else if(character==='>')return index+1;}throw new SyntaxError('Unclosed HTML tag at '+start);
  }
  /** Validate tag attributes from the shared server policy; receives tail/tag/policy, returns decoded attributes without rewriting source. */
  function attributes(source,tag,policy) {
    let position=0;const attrs={};
    while(position<source.length){const white=/^\s+/.exec(source.slice(position));if(white)position+=white[0].length;if(position===source.length)break;
      const match=/^([a-zA-Z][a-zA-Z0-9:-]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/.exec(source.slice(position));if(!match)throw new SyntaxError('Invalid HTML attribute.');position+=match[0].length;
      const key=match[1].toLowerCase(),value=decode(match[2]??match[3]??match[4]??'');
      if(Object.hasOwn(attrs,key)||key.startsWith('data-dbless-')||/^data-cms-(form|template)-slot$/.test(key))throw new SyntaxError('Reserved or duplicate HTML attribute.');
      if(!policy.global.includes(key)&&!(policy.specific[tag]||[]).includes(key)&&!/^(aria|data)-[a-z][a-z0-9-]*$/.test(key))throw new SyntaxError('Unsupported HTML attribute: '+key);
      if(key==='src'||key==='href')url(value,key);if(key==='target'&&!['_self','_blank'].includes(value))throw new SyntaxError('Unsupported link target.');attrs[key]=value;
    }
    return attrs;
  }
  /** Parse HTML into source-offset nodes and safe editable leaves; receives source/profile/server policy, returns a model or throws without mutating its input. */
  function analyze(source,input,policy) {
    const settings=options(input);if(typeof source!=='string'||new TextEncoder().encode(source).length>131072||source.split('\n').length>4097)throw new SyntaxError('Editor source limit.');
    if(settings.language!=='html')return {source,settings,tree:null,blocks:[],tokens:[]};
    if(/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/.test(source)||source.includes('<?'))throw new SyntaxError('Executable or invalid editorial source.');
    const tokens=mustache(source,settings.mustache),root={tag:'',children:[],start:0,openEnd:0,end:source.length,closeStart:source.length},stack=[root];let position=0,id=0;
    while(position<source.length) {
      const parent=stack.at(-1);
      if(source.startsWith('<!--',position)){const end=source.indexOf('-->',position+4);if(end<0)throw new SyntaxError('Unclosed HTML comment.');parent.children.push({type:'comment',raw:source.slice(position,end+3),start:position,end:end+3});position=end+3;continue;}
      if(source[position]!=='<'||!/^<\/?[a-zA-Z!]/.test(source.slice(position))) {
        let end=position+1;while(end<source.length&&!(source[end]==='<'&&/^<\/?[a-zA-Z!]/.test(source.slice(end))))end++;
        parent.children.push({type:'text',raw:source.slice(position,end),start:position,end});position=end;continue;
      }
      const end=tagEnd(source,position),raw=source.slice(position,end),match=/^<(\/?)([a-zA-Z][a-zA-Z0-9-]*)([\s\S]*?)>$/.exec(raw);if(!match)throw new SyntaxError('Unsupported declaration.');
      const tag=match[2].toLowerCase();if(!policy.tags.includes(tag))throw new SyntaxError('Unsupported HTML element: '+tag);
      if(match[1]){if(match[3].trim()||stack.length<2||parent.tag!==tag)throw new SyntaxError('Unbalanced HTML; use Code to repair.');parent.closeStart=position;parent.end=end;stack.pop();}
      else {const tail=match[3].replace(/\/\s*$/,'');const child={type:'element',id:'b'+(++id),tag,attrs:attributes(tail,tag,policy),children:[],start:position,openEnd:end,end,closeStart:end};parent.children.push(child);if(!voidTags.has(tag))stack.push(child);}
      position=end;if(id>8000||stack.length>64)throw new SyntaxError('HTML structure limit.');
    }
    if(stack.length!==1)throw new SyntaxError('Unclosed HTML element; source preserved.');
    const blocks=[];
    const intervals=[],open=[];for(const token of tokens){if(token.kind==='#'||token.kind==='^')open.push(token);else if(token.kind==='/'){const start=open.pop();intervals.push([start.start,token.end]);}}
    /** Identify editable static leaves while preserving loop bodies; receives node/ancestor flag, returns whether it contains a block and never protects unrelated siblings. */
    function mark(node,protectedParent=false) {
      const scoped=protectedParent||intervals.some(([start,end])=>node.start>=start&&node.end<=end);
      let nested=false;for(const child of node.children||[])if(child.type==='element'){if(mark(child,scoped))nested=true;}
      const structural=tokens.some(token=>['#','^','/','form','fragment'].includes(token.kind)&&node.children.some(child=>child.type==='text'&&token.start>=child.start&&token.start<child.end));
      node.protected=scoped||(structural&&!nested);
      if(node.tag&&blockTags.has(node.tag)&&!nested&&!node.protected&&!settings.readOnly){node.editable=true;blocks.push({id:node.id,start:node.openEnd,end:node.closeStart,outerStart:node.start,outerEnd:node.end,tag:node.tag});}
      return nested||blockTags.has(node.tag);
    }
    mark(root);if(!blocks.length&&!root.protected&&!settings.readOnly&&!tokens.some(token=>['#','^','/','form','fragment'].includes(token.kind))){root.id='root';root.editable=true;blocks.push({id:'root',start:0,end:source.length,outerStart:0,outerEnd:source.length,tag:''});}return {source,settings,tree:root,blocks,tokens};
  }
  globalThis.DBLESSEditorModel=Object.freeze({profiles,options,analyze,url,decode,mustache});
})();
