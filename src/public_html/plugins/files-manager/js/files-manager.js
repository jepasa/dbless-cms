/** Files Manager API 1: conteúdo integrado ao painel, DOM seguro, árvore/lista/grade, uploads sequenciais, revisão e operações recuperáveis. */
(() => {
  'use strict';
  const settings = document.querySelector('[data-fm-settings]');
  if (settings) { setupSettings(settings); return; }
  /** Envia formulários das páginas próprias de configuração; recebe container, preserva erros e recarrega após confirmação do servidor. */
  function setupSettings(container) {
    let pending = false;
    container.querySelectorAll('[data-settings-form]').forEach(form => form.addEventListener('submit', async event => {
      if (event.defaultPrevented) return;
      event.preventDefault(); if (pending) return;
      if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) return;
      pending = true; const buttons = container.querySelectorAll('button'); buttons.forEach(button => { button.disabled = true; });
      const status = container.querySelector('[data-settings-status]'); status.textContent = ''; status.classList.remove('fm-error');
      try {
        const response = await fetch(form.action, {method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:new FormData(form)});
        let data; try { data = await response.json(); } catch { throw new Error(container.dataset.networkError); }
        if (!response.ok) throw new Error(data.error || container.dataset.networkError);
        location.reload();
      } catch (error) { status.textContent = error.message; status.classList.add('fm-error'); pending = false; buttons.forEach(button => { button.disabled = false; }); }
    }));
  }
  const root = document.querySelector('[data-fm]');
  if (!root) return;
  const messages = JSON.parse(root.dataset.messages), icons = JSON.parse(root.dataset.icons);
  const base = new URL(root.dataset.api, location.href), prompt = root.querySelector('[data-prompt]'), preview = root.querySelector('[data-preview]');
  const menu = root.querySelector('[data-context]'), upload = root.querySelector('[data-upload]'), replacement = root.querySelector('[data-replace]');
  const selectionActions = ['rename','move','download','preview','replace','delete','restore','purge'];
  let scope = 'public', path = '', revision = '', entries = [], selected = null, trash = root.dataset.screen === 'trash';
  let busy = false, generation = 0, page = 1, mode = 'list', sort = 'name', direction = 1, pages = 1, replacementTarget = null, menuOrigin = null;
  const pageSize = Number(root.dataset.pageSize), branches = new Map(), expanded = new Set(['']);
  /** Translate a key/parameters to plain text; no HTML interpolation or writes. */
  function t(key, args = {}) { let value = messages[`fm.${key}`] || key; for (const [name, text] of Object.entries(args)) value = value.replaceAll(`{${name}}`, String(text)); return value; }
  /** Create a safe text element; returns a detached node. */
  function el(tag, text = '', className = '') { const node = document.createElement(tag); node.textContent = text; node.className = className; return node; }
  /** Resolve an allowlisted decorative local icon; unknown names fall back to file. */
  function icon(name) { const image = document.createElement('img'); image.src = icons[name] || icons['file-earmark-text']; image.alt = ''; image.width = 24; image.height = 24; image.className = 'admin-icon'; image.setAttribute('aria-hidden','true'); return image; }
  /** Create an icon action with translated tooltip/accessibility text; receives action and optional label. */
  function actionButton(name, label = name) { const button = el('button','','fm-action'); button.type = 'button'; button.dataset.action = name; button.title = t(label); button.setAttribute('aria-label',t(label)); button.append(icon(name)); return button; }
  /** Announce a status without hiding previous errors in a generic success. */
  function notify(text, error = false) { const status = root.querySelector('[data-status]'); status.textContent = text; status.classList.toggle('fm-error',error); }
  /** Build same-origin authenticated plugin URL; receives operation and validated UI parameters. */
  function url(operation, values = {}) { const target = new URL(base); target.searchParams.set('view','plugin-api'); target.searchParams.set('operation',operation); for (const [key,value] of Object.entries({scope,...values})) target.searchParams.set(key,value); return target; }
  /** Fetch a read snapshot or POST mutation; errors propagate with server-safe localized text. */
  async function request(operation, values = null, directory = path) {
    const options = {credentials:'same-origin',headers:{Accept:'application/json'}};
    if (values) { options.method = 'POST'; options.body = new URLSearchParams({csrf_token:root.dataset.csrf,scope,path,revision,...values}); }
    const response = await fetch(url(operation,values ? {} : {path:directory}),options);
    let data; try { data = await response.json(); } catch { throw new Error(t('error.network')); }
    if (!response.ok) throw new Error(data.error || t('error.network')); return data;
  }
  /** Determine bounded media group from server-listed type; never executes uploaded content. */
  function group(entry) { if (entry.directory) return 'folders'; if (/^(jpg|jpeg|png|gif|webp|ico)$/.test(entry.type)) return 'images'; return entry.type === 'mp4' ? 'videos' : 'documents'; }
  /** Return stable item identity within the current root/scope. */
  function id(entry) { return entry.id || entry.path; }
  /** Decide action availability consistently for toolbar/menu; receives action/item, no mutation. */
  function available(name, entry = selected) {
    if (busy || !revision && ['upload','mkdir',...selectionActions].includes(name)) return false;
    if (['restore','purge'].includes(name)) return trash && !!entry;
    if (selectionActions.includes(name)) return !trash && !!entry && (!['download','replace','preview'].includes(name) || !entry.directory) && (name !== 'preview' || ['images','videos'].includes(group(entry)));
    if (['upload','mkdir'].includes(name)) return !trash;
    if (name === 'up') return !trash && path !== '';
    return true;
  }
  /** Update only explorer controls, selection highlights and accessible states; global navigation stays untouched. */
  function controls() {
    root.querySelectorAll('[data-action]').forEach(button => {
      const name = button.dataset.action;
      if (['cancel','close'].includes(name)) return;
      button.disabled = !available(name);
      if (selectionActions.includes(name)) button.hidden = ['restore','purge'].includes(name) !== trash;
      if (['grid','list'].includes(name)) button.setAttribute('aria-pressed',String(mode === name));
    });
    root.querySelectorAll('[data-scope], [data-filter], [data-type-filter], [data-sort], [data-direction], [data-column-sort], .fm-file-name, .fm-folder-name, .fm-expand, .fm-name-cell > input').forEach(node => { node.disabled = busy; });
    root.querySelector('[data-selected]').textContent = selected ? t('selected',{name:selected.name}) : t(trash ? 'trash' : 'files');
    root.querySelectorAll('[data-id]').forEach(row => { const active = !!selected && row.dataset.id === id(selected); row.classList.toggle('fm-selected',active); row.querySelector('input').checked = active; });
    root.querySelector('[data-page="previous"]').disabled = busy || page <= 1;
    root.querySelector('[data-page="next"]').disabled = busy || page >= pages;
  }
  /** Select an existing entry and refresh availability without replacing focused nodes. */
  function select(entry) { selected = entry; controls(); }
  /** Navigate from a visible folder control; pending responses are invalidated by load generation. */
  function navigate(destination) { if (busy) return; path = destination; trash = false; page = 1; selected = null; load(); }
  /** Render breadcrumbs and lazy nested folder tree with CSS connectors and explicit expand buttons. */
  function navigation() {
    const tree = root.querySelector('[data-tree]'), breadcrumb = root.querySelector('[data-breadcrumb]'); tree.replaceChildren(); breadcrumb.replaceChildren();
    /** Create a folder name button; receives label/path, never interpolates HTML. */
    function folder(label, destination) { const button = el('button',label,'fm-folder-name'); button.type = 'button'; if (!trash && destination === path) button.setAttribute('aria-current','page'); button.addEventListener('click',() => navigate(destination)); return button; }
    breadcrumb.append(folder(t('root'),'')); let partial = '';
    if (!trash) for (const segment of path.split('/').filter(Boolean)) { partial = partial ? `${partial}/${segment}` : segment; breadcrumb.append(el('span','/'),folder(segment,partial)); }
    if (trash) breadcrumb.append(el('span',`/ ${t('trash')}`));
    /** Render one branch recursively from already loaded data; expansion fetches only the requested folder. */
    function branch(label, destination, parent) {
      const node = el('li'), row = el('div','','fm-tree-row'), toggle = el('button','','fm-action fm-expand'); toggle.type = 'button';
      const open = expanded.has(destination); toggle.title = t(open ? 'collapse' : 'expand',{name:label}); toggle.setAttribute('aria-label',toggle.title); toggle.setAttribute('aria-expanded',String(open)); toggle.append(icon(open ? 'chevron-down' : 'chevron-right'));
      toggle.addEventListener('click',async () => {
        if (busy) return; const current = generation;
        try { if (expanded.has(destination)) expanded.delete(destination); else { if (!branches.has(destination)) { toggle.disabled = true; const data = await request('list',null,destination); if (current !== generation) return; branches.set(destination,data.entries.filter(entry => entry.directory)); } expanded.add(destination); } navigation(); tree.querySelectorAll('[data-branch]').forEach(item => { if (item.dataset.branch === destination) item.querySelector('button').focus(); }); }
        catch (error) { toggle.disabled = false; notify(error.message,true); }
      });
      row.dataset.branch = destination; const name = folder(label,destination); name.prepend(icon('folder')); row.append(toggle,name); node.append(row);
      row.addEventListener('keydown',event => { if (!['ArrowRight','ArrowLeft'].includes(event.key)) return; const expandKey = document.documentElement.dir === 'rtl' ? 'ArrowLeft' : 'ArrowRight'; if ((event.key === expandKey) !== expanded.has(destination)) { event.preventDefault(); toggle.click(); } });
      if (open) { const children = el('ul'); for (const entry of branches.get(destination) || []) branch(entry.name,entry.path,children); node.append(children); }
      parent.append(node);
    }
    const list = el('ul'); branch(t('root'),'',list); tree.append(list);
    root.querySelector('[data-scope-help]').textContent = `${t(scope)} · ${t(scope === 'public' ? 'publicHelp' : 'privateHelp')}`;
  }
  /** Format a byte count numerically; directory sizes are not invented. */
  function size(bytes) { return bytes >= 1048576 ? `${(bytes/1048576).toFixed(1)} MiB` : bytes >= 1024 ? `${(bytes/1024).toFixed(1)} KiB` : `${bytes} B`; }
  /** Attach thumbnail with authenticated no-store URL; failed/unsupported media retains its type icon. */
  function thumbnail(entry, parent) {
    const kind = group(entry); parent.append(icon(kind === 'folders' ? 'folder' : kind === 'images' ? 'image' : kind === 'videos' ? 'video' : 'file-earmark-text'));
    if (mode !== 'grid' || trash || !['images','videos'].includes(kind)) return;
    const media = document.createElement(kind === 'videos' ? 'video' : 'img'); media.className = 'fm-thumbnail';
    if (kind === 'images') { media.alt = ''; media.loading = 'lazy'; media.decoding = 'async'; }
    else { media.muted = true; media.playsInline = true; media.preload = 'metadata'; media.setAttribute('aria-hidden','true'); }
    media.addEventListener('error',() => { media.remove(); parent.title = t('videoUnavailable'); });
    media.addEventListener(kind === 'images' ? 'load' : 'loadeddata',() => parent.classList.add('fm-thumbnail-ready'));
    media.src = url('preview',{path:entry.path,v:revision}) + (kind === 'videos' ? '#t=0.1' : ''); parent.append(media);
  }
  /** Render a page after filtering and stable sorting; keep headers in list and the same sort in grid. */
  function render() {
    const filter = root.querySelector('[data-filter]').value.toLocaleLowerCase(document.documentElement.lang), type = root.querySelector('[data-type-filter]').value;
    const collator = new Intl.Collator(document.documentElement.lang,{numeric:true,sensitivity:'base'});
    const filtered = entries.filter(entry => entry.name.toLocaleLowerCase(document.documentElement.lang).includes(filter) && (type === 'allTypes' || group(entry) === type));
    filtered.sort((a,b) => {
      if (a.directory !== b.directory) return Number(b.directory)-Number(a.directory);
      if (sort === 'added' && (!a.added || !b.added)) { if (!!a.added !== !!b.added) return a.added ? -1 : 1; }
      const primary = sort === 'size' ? a.size-b.size : collator.compare(String(a[sort] || ''),String(b[sort] || ''));
      return direction*primary || collator.compare(a.name,b.name) || a.path.localeCompare(b.path);
    });
    pages = Math.max(1,Math.ceil(filtered.length/pageSize)); page = Math.min(page,pages);
    const visible = filtered.slice((page-1)*pageSize,page*pageSize);
    if (selected && !visible.some(entry => id(entry) === id(selected))) selected = null;
    const list = root.querySelector('[data-list-items]'), grid = root.querySelector('[data-grid-items]'); list.replaceChildren(); grid.replaceChildren(); grid.hidden = mode !== 'grid'; root.querySelector('[data-table]').hidden = mode !== 'list';
    for (const entry of visible) {
      const row = el(mode === 'list' ? 'tr' : 'article','','fm-item'); row.dataset.id = id(entry);
      const nameCell = el(mode === 'list' ? 'td' : 'div','','fm-name-cell'), radio = document.createElement('input'); radio.type = 'radio'; radio.name = 'fm-selection'; radio.setAttribute('aria-label',t('selected',{name:entry.name})); radio.addEventListener('change',() => select(entry));
      const name = el('button','','fm-file-name'); name.type = 'button'; name.setAttribute('aria-label',entry.name); const picture = el('span','','fm-picture'); thumbnail(entry,picture); name.append(picture,el('span',entry.name));
      name.addEventListener('click',() => entry.directory && !trash ? navigate(entry.path) : select(entry)); name.addEventListener('dblclick',() => { select(entry); if (available('preview')) action('preview'); });
      const more = actionButton('more'); more.addEventListener('click',event => { event.stopPropagation(); select(entry); openMenu(more); }); nameCell.append(radio,name,more); row.append(nameCell);
      for (const text of [entry.directory ? t('folders') : entry.type.toUpperCase(),entry.directory ? '—' : size(entry.size),entry.added ? new Date(entry.added).toLocaleString(document.documentElement.lang) : t('unknownDate')]) row.append(el(mode === 'list' ? 'td' : 'small',text));
      row.addEventListener('contextmenu',event => { event.preventDefault(); event.stopPropagation(); select(entry); openMenu(name,event); });
      row.addEventListener('keydown',event => { if (event.key === 'ContextMenu' || event.key === 'F10' && event.shiftKey) { event.preventDefault(); select(entry); openMenu(event.target); } });
      if (!trash) {
        row.draggable = true; row.addEventListener('dragstart',event => { if (busy) return event.preventDefault(); event.dataTransfer.setData('application/x-dbless-cms-file',JSON.stringify({scope,path,target:entry.name})); });
        if (entry.directory) { row.addEventListener('dragover',event => { if (!busy && event.dataTransfer.types.includes('application/x-dbless-cms-file')) { event.preventDefault(); row.classList.add('fm-dragover'); } }); row.addEventListener('dragleave',() => row.classList.remove('fm-dragover')); row.addEventListener('drop',event => { if (!event.dataTransfer.types.includes('application/x-dbless-cms-file')) return; event.preventDefault(); event.stopPropagation(); row.classList.remove('fm-dragover'); try { const data = JSON.parse(event.dataTransfer.getData('application/x-dbless-cms-file')); if (data.scope === scope && data.path === path && typeof data.target === 'string') mutate('move',{target:data.target,destination:entry.path}); } catch { notify(t('error.path'),true); } }); }
      }
      (mode === 'list' ? list : grid).append(row);
    }
    const empty = root.querySelector('[data-empty]'); empty.hidden = filtered.length !== 0; empty.textContent = t(entries.length ? 'noResults' : trash ? 'trashEmpty' : 'empty');
    root.querySelector('[data-page-info]').textContent = t('page',{page,pages,count:filtered.length}); root.querySelector('[data-sort]').value = sort;
    const arrow = root.querySelector('[data-direction]'); arrow.replaceChildren(icon(direction === 1 ? 'sort-up' : 'sort-down')); arrow.title = t(direction === 1 ? 'ascending' : 'descending'); arrow.setAttribute('aria-label',arrow.title);
    root.querySelectorAll('[data-column]').forEach(column => { const active = column.dataset.column === sort; column.setAttribute('aria-sort',active ? direction === 1 ? 'ascending' : 'descending' : 'none'); const mark = column.querySelector('[data-sort-icon]'); mark.replaceChildren(); if (active) mark.append(icon(direction === 1 ? 'sort-up' : 'sort-down')); });
    controls();
  }
  /** Refresh current root snapshot and ancestor branches, rejecting stale responses and clearing failed-folder data. */
  async function load() {
    const current = ++generation; busy = true; selected = null; closeMenu(); controls(); notify(t('loading'));
    try {
      const data = await request(trash ? 'trash' : 'list'); if (current !== generation) return;
      entries = data.entries; revision = data.revision;
      if (!trash) { branches.set(path,entries.filter(entry => entry.directory)); let prefix = ''; for (const segment of ['',...path.split('/').filter(Boolean)]) { prefix = segment ? prefix ? `${prefix}/${segment}` : segment : ''; expanded.add(prefix); if (!branches.has(prefix)) { const branch = await request('list',null,prefix); if (current !== generation) return; branches.set(prefix,branch.entries.filter(entry => entry.directory)); } } }
      notify(''); return true;
    } catch (error) { if (current === generation) { entries = []; revision = ''; notify(error.message,true); } return false; }
    finally { if (current === generation) { busy = false; render(); navigation(); } }
  }
  /** Execute a revisioned mutation and refresh branch state; server errors remain visible and do not claim success. */
  async function mutate(operation, values = {}) {
    if (busy || !revision) return; busy = true; closeMenu(); controls();
    try { const data = await request(operation,values); entries = data.entries; revision = data.revision; branches.clear(); selected = null; busy = false; if (await load()) notify(t('done')); }
    catch (error) { notify(error.message,true); }
    finally { busy = false; render(); }
  }
  /** Ask for translated confirmation/input in the visible native dialog; resolves null on cancel/Escape. */
  function ask(title, label = '', value = '', help = '') {
    closeMenu(); return new Promise(resolve => {
      const input = root.querySelector('[data-prompt-input]'); root.querySelector('#fm-prompt-title').textContent = title; root.querySelector('[data-prompt-text]').textContent = label; root.querySelector('[data-prompt-help]').textContent = help; root.querySelector('[data-prompt-label]').hidden = !label; input.value = value; prompt.returnValue = '';
      prompt.addEventListener('close',() => resolve(prompt.returnValue === 'ok' ? input.value : null),{once:true}); prompt.showModal(); if (label) { input.focus(); input.select(); }
    });
  }
  /** Dispatch shared actions from toolbar/menu; every mutation uses the same confirmation and revision path. */
  async function action(name) {
    if (name === 'cancel') return prompt.close('cancel'); if (name === 'close') return preview.close();
    if (!available(name)) return; closeMenu(); const entry = selected;
    if (name === 'upload') return upload.click(); if (name === 'refresh') { branches.clear(); return load(); }
    if (name === 'trash') { trash = !trash; page = 1; return load(); } if (name === 'up') return navigate(path.split('/').slice(0,-1).join('/'));
    if (['grid','list'].includes(name)) { mode = name; return render(); }
    if (name === 'mkdir') { const value = await ask(t('newFolder'),t('name')); if (value !== null) return mutate('mkdir',{name:value}); return; }
    if (!entry) return;
    if (name === 'download') { location.href = url('download',{path:entry.path}); return; }
    if (name === 'preview') { const video = group(entry) === 'videos', media = root.querySelector(video ? '[data-preview-video]' : '[data-preview-image]'); media.hidden = false; if (!video) media.alt = entry.name; media.src = url('preview',{path:entry.path,v:revision}); preview.showModal(); return; }
    if (name === 'replace') { if (await ask(t('replace'),'', '',t('confirmReplace')) !== null) { replacementTarget = {name:entry.name,scope,path,revision}; replacement.accept = '.'+entry.type; replacement.click(); } return; }
    if (name === 'rename') { const value = await ask(t('rename'),t('name'),entry.name); if (value !== null) return mutate(name,{target:entry.name,name:value}); }
    if (name === 'move') { const value = await ask(t('move'),t('destination')); if (value !== null) return mutate(name,{target:entry.name,destination:value}); }
    if (name === 'delete' && await ask(t('delete'),'','',t('confirmDelete')) !== null) return mutate(name,{target:entry.name});
    if (name === 'restore' && await ask(t('restore'),'','',entry.path) !== null) return mutate(name,{target:entry.id});
    if (name === 'purge') { const value = await ask(t('purge'),t('name'),'',`${t('confirmPurge')} (${entry.name})`); if (value !== null) return mutate(name,{target:entry.id,name:value}); }
  }
  /** Send one real multipart upload/replacement with progress and timeout; reject HTTP/network failures. */
  function sendFile(file, target = null) {
    return new Promise((resolve,reject) => {
      const xhr = new XMLHttpRequest(), data = new FormData(); for (const [key,value] of Object.entries({csrf_token:root.dataset.csrf,scope,path,revision,...(target ? {target:target.name,scope:target.scope,path:target.path,revision:target.revision} : {})})) data.append(key,value);
      data.append('file',file); xhr.open('POST',url(target ? 'replace' : 'upload')); xhr.responseType = 'json'; xhr.timeout = 120000;
      xhr.upload.addEventListener('progress',event => { if (event.lengthComputable) root.querySelector('[data-progress]').value = event.loaded/event.total*100; });
      xhr.addEventListener('load',() => xhr.status >= 200 && xhr.status < 300 && xhr.response ? resolve(xhr.response) : reject(new Error(xhr.response?.error || t('error.upload'))));
      xhr.addEventListener('error',() => reject(new Error(t('error.network')))); xhr.addEventListener('timeout',() => reject(new Error(t('error.network')))); xhr.send(data);
    });
  }
  /** Upload selected files sequentially; replacement is bound to the confirmed item/revision and never falls back to upload. */
  async function uploadFiles(files, target = null) {
    if (busy || trash || !files.length) return; if (target && (target.scope !== scope || target.path !== path)) { notify(t('error.revision'),true); return; }
    busy = true; controls(); const progress = root.querySelector('[data-progress]'); progress.hidden = false;
    try { for (const file of files) { if (!file.size || file.size > 10485760) throw new Error(t('error.size')); notify(t('uploading',{name:file.name})); progress.value = 0; const data = await sendFile(file,target); entries = data.entries; revision = data.revision; } branches.clear(); busy = false; if (await load()) notify(t('done')); }
    catch (error) { notify(error.message,true); }
    finally { busy = false; upload.value = ''; replacement.value = ''; replacementTarget = null; progress.hidden = true; render(); }
  }
  /** Close the nonmodal context menu and restore its invoking control when requested. */
  function closeMenu(focus = false) { if (menu.matches(':popover-open')) menu.hidePopover(); if (focus && menuOrigin?.isConnected) menuOrigin.focus(); }
  /** Open bounded context menu for item/background; position inside viewport and reuse canonical actions. */
  function openMenu(origin, event = null) {
    if (busy) return; closeMenu(); menuOrigin = origin; menu.replaceChildren();
    const names = selected ? [...selectionActions,'upload','mkdir','refresh'] : trash ? ['refresh'] : ['upload','mkdir','refresh'];
    for (const name of names.filter(name => available(name))) { const button = actionButton(name,name === 'mkdir' ? 'newFolder' : name); button.setAttribute('role','menuitem'); menu.append(button); }
    if (!menu.children.length) return; menu.showPopover(); const rect = origin.getBoundingClientRect();
    menu.style.left = `${Math.max(8,Math.min(event?.clientX ?? rect.left,innerWidth-menu.offsetWidth-8))}px`; menu.style.top = `${Math.max(8,Math.min(event?.clientY ?? rect.bottom,innerHeight-menu.offsetHeight-8))}px`; menu.querySelector('button').focus();
  }
  root.addEventListener('click',event => { const button = event.target.closest('[data-action]'); if (button && button.dataset.action !== 'more') action(button.dataset.action).catch(error => notify(error.message,true)); });
  root.querySelector('[data-prompt-form]').addEventListener('submit',event => { event.preventDefault(); prompt.close('ok'); });
  root.querySelectorAll('[data-scope]').forEach(button => button.addEventListener('click',() => { if (busy) return; scope = button.dataset.scope; path = ''; page = 1; branches.clear(); expanded.clear(); expanded.add(''); root.querySelectorAll('[data-scope]').forEach(item => item.setAttribute('aria-pressed',String(item === button))); load(); }));
  for (const selector of ['[data-filter]','[data-type-filter]']) root.querySelector(selector).addEventListener(selector === '[data-filter]' ? 'input' : 'change',() => { page = 1; render(); });
  root.querySelector('[data-sort]').addEventListener('change',event => { sort = event.target.value; page = 1; render(); });
  root.querySelector('[data-direction]').addEventListener('click',() => { direction *= -1; page = 1; render(); });
  root.querySelectorAll('[data-column-sort]').forEach(button => button.addEventListener('click',() => { if (sort === button.dataset.columnSort) direction *= -1; else { sort = button.dataset.columnSort; direction = 1; } page = 1; render(); }));
  root.querySelectorAll('[data-page]').forEach(button => button.addEventListener('click',() => { page += button.dataset.page === 'next' ? 1 : -1; selected = null; render(); }));
  upload.addEventListener('change',() => uploadFiles([...upload.files])); replacement.addEventListener('change',() => { if (replacementTarget) uploadFiles([...replacement.files].slice(0,1),replacementTarget); });
  const zone = root.querySelector('[data-dropzone]'); zone.addEventListener('dragover',event => { if (!busy && !trash && event.dataTransfer.types.includes('Files')) { event.preventDefault(); zone.classList.add('fm-dragover'); } }); zone.addEventListener('dragleave',() => zone.classList.remove('fm-dragover')); zone.addEventListener('drop',event => { event.preventDefault(); zone.classList.remove('fm-dragover'); uploadFiles([...event.dataTransfer.files]); });
  zone.addEventListener('contextmenu',event => { if (event.target.closest('[data-id]')) return; event.preventDefault(); select(null); openMenu(zone,event); });
  menu.addEventListener('keydown',event => { const buttons = [...menu.querySelectorAll('button')], index = buttons.indexOf(document.activeElement); if (['ArrowRight','ArrowDown','ArrowLeft','ArrowUp','Home','End'].includes(event.key)) { event.preventDefault(); buttons[event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length-1 : (index+(['ArrowRight','ArrowDown'].includes(event.key)?1:-1)+buttons.length)%buttons.length].focus(); } if (['Escape','Tab'].includes(event.key)) { if (event.key === 'Escape') event.preventDefault(); closeMenu(true); } });
  document.addEventListener('pointerdown',event => { if (!menu.contains(event.target)) closeMenu(); }); window.addEventListener('resize',() => closeMenu());
  for (const selector of ['[data-preview-image]','[data-preview-video]']) root.querySelector(selector).addEventListener('error',() => { root.querySelector('[data-preview-error]').hidden = false; });
  preview.addEventListener('close',() => { const video = root.querySelector('[data-preview-video]'); video.pause(); for (const media of [video,root.querySelector('[data-preview-image]')]) { media.removeAttribute('src'); media.hidden = true; } video.load(); root.querySelector('[data-preview-error]').hidden = true; });
  load();
})();
