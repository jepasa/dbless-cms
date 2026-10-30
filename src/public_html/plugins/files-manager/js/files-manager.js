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
  const messages = JSON.parse(root.dataset.messages);
  const base = new URL(root.dataset.api, location.href);
  const items = document.querySelector('[data-items]');
  const status = document.querySelector('[data-status]');
  const upload = document.querySelector('[data-upload]');
  const prompt = document.querySelector('[data-prompt]');
  const preview = document.querySelector('[data-preview]');
  let scope = 'public', path = '', revision = '', entries = [], selected = null, trash = root.dataset.screen === 'trash', busy = false, generation = 0;

  /** Traduz mensagem com placeholders textuais; recebe chave/argumentos e retorna string para textContent. */
  function t(key, args = {}) {
    let text = messages[`fm.${key}`] || key;
    for (const [name, value] of Object.entries(args)) text = text.replaceAll(`{${name}}`, String(value));
    return text;
  }
  /** Cria elemento sem HTML dinâmico; recebe tag/texto/classe e retorna nó sem inserção global. */
  function element(tag, text = '', className = '') {
    const node = document.createElement(tag); node.textContent = text; node.className = className; return node;
  }
  /** Atualiza mensagem acessível; recebe texto/erro; sem efeitos externos. */
  function notify(text, error = false) { status.textContent = text; status.classList.toggle('fm-error', error); }
  /** Monta endpoint restrito ao próprio plugin; recebe operação/parâmetros e retorna URL local. */
  function url(operation, values = {}) {
    const endpoint = new URL(base); endpoint.searchParams.set('view', 'plugin-api'); endpoint.searchParams.set('operation', operation);
    for (const [key, value] of Object.entries({scope, ...values})) endpoint.searchParams.set(key, value);
    return endpoint;
  }
  /** Faz requisição JSON protegida; recebe operação/dados opcionais; propaga mensagem segura de erro/status. */
  async function request(operation, values = null) {
    const options = {credentials: 'same-origin', headers: {'Accept': 'application/json'}};
    if (values) { options.method = 'POST'; options.body = new URLSearchParams({csrf_token: root.dataset.csrf, scope, path, revision, ...values}); }
    const response = await fetch(url(operation, values ? {} : {path}), options);
    let data;
    try { data = await response.json(); } catch { throw new Error(t('error.network')); }
    if (!response.ok) throw new Error(data.error || t('error.network'));
    return data;
  }
  /** Atualiza seleção e disponibilidade das ações; recebe item ou null; sem escrita de arquivos. */
  function select(item) {
    selected = item;
    document.querySelector('[data-selected]').textContent = item ? t('selected', {name: item.name}) : t(trash ? 'trash' : 'files');
    for (const action of ['rename','move','download','preview','delete','restore','purge']) {
      const button = document.querySelector(`[data-action="${action}"]`);
      const isTrash = ['restore','purge'].includes(action);
      button.hidden = isTrash !== trash;
      button.disabled = busy || !item || (action === 'preview' && (item.directory || !/\.(jpe?g|png|gif|webp)$/i.test(item.name))) || (action === 'download' && item.directory);
    }
    for (const row of items.children) {
      const active = row.dataset.id === (item?.id || item?.path);
      row.classList.toggle('fm-selected', active); row.querySelector('input').checked = active;
    }
  }
  /** Navega por botão de pasta; recebe texto/caminho e retorna botão com ação de leitura. */
  function folderButton(label, destination) {
    const button = element('button', label); button.type = 'button';
    if (!trash && destination === path) button.setAttribute('aria-current', 'page');
    button.addEventListener('click', () => { if (!busy) { path = destination; trash = false; load(); } });
    return button;
  }
  /** Renderiza navegação progressiva e breadcrumbs; não interpola nomes como HTML. */
  function navigation() {
    const tree = document.querySelector('[data-tree]'), breadcrumb = document.querySelector('[data-breadcrumb]');
    tree.replaceChildren(); breadcrumb.replaceChildren();
    tree.append(folderButton(`▸ ${t('root')}`, '')); breadcrumb.append(folderButton(t('root'), ''));
    let partial = '';
    if (!trash) for (const segment of path.split('/').filter(Boolean)) {
      partial = partial ? `${partial}/${segment}` : segment;
      breadcrumb.append(element('span', '/'), folderButton(segment, partial)); tree.append(folderButton(`↳ ${segment}`, partial));
    }
    if (trash) breadcrumb.append(element('span', `/ ${t('trash')}`));
    else for (const entry of entries.filter(item => item.directory)) tree.append(folderButton(`▸ ${entry.name}`, entry.path));
    document.querySelector('[data-scope-help]').textContent = t(scope === 'public' ? 'publicHelp' : 'privateHelp');
  }
  /** Formata bytes; recebe número não negativo e retorna unidade legível. */
  function size(bytes) { return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MiB` : bytes >= 1024 ? `${(bytes / 1024).toFixed(1)} KiB` : `${bytes} B`; }
  /** Renderiza lista ou grade filtrada; nomes são somente texto, seleção usa radio nativo. */
  function render() {
    const filter = document.querySelector('[data-filter]').value.toLocaleLowerCase();
    items.replaceChildren();
    for (const entry of entries.filter(item => item.name.toLocaleLowerCase().includes(filter))) {
      const row = element('article', '', 'fm-item'); row.dataset.id = entry.id || entry.path;
      const radio = document.createElement('input'); radio.type = 'radio'; radio.name = 'fm-selection'; radio.setAttribute('aria-label', t('selected', {name: entry.name}));
      radio.addEventListener('change', () => select(entry));
      const name = element('button', '', 'fm-file-name'); name.type = 'button';
      name.append(element('span', entry.directory ? '▰' : '▤', 'fm-file-icon'), element('span', entry.name));
      name.addEventListener('click', () => {
        if (!trash && entry.directory && !busy) { path = entry.path; load(); } else select(entry);
      });
      row.append(radio, name, element('small', entry.directory ? '—' : size(entry.size)), element('small', new Date(entry.modified || entry.deleted).toLocaleDateString(document.documentElement.lang)));
      if (!trash) {
        row.draggable = true;
        row.addEventListener('dragstart', event => { event.dataTransfer.setData('application/x-cms2json-file', JSON.stringify({scope, path, target: entry.name})); event.dataTransfer.effectAllowed = 'move'; });
        if (entry.directory) {
          row.addEventListener('dragover', event => { if (event.dataTransfer.types.includes('application/x-cms2json-file')) { event.preventDefault(); row.classList.add('fm-dragover'); } });
          row.addEventListener('dragleave', () => row.classList.remove('fm-dragover'));
          row.addEventListener('drop', event => {
            event.preventDefault(); event.stopPropagation(); row.classList.remove('fm-dragover');
            try {
              const data = JSON.parse(event.dataTransfer.getData('application/x-cms2json-file'));
              if (data.scope === scope && data.path === path && typeof data.target === 'string') mutate('move', {target: data.target, destination: entry.path});
            } catch { notify(t('error.path'), true); }
          });
        }
      }
      items.append(row);
    }
    const empty = document.querySelector('[data-empty]'); empty.hidden = items.children.length > 0; empty.textContent = t(trash ? 'trashEmpty' : 'empty');
    select(null); navigation();
    for (const action of ['upload','mkdir','up']) document.querySelector(`[data-action="${action}"]`).disabled = busy || trash || (action === 'up' && path === '');
  }
  /** Busca snapshot atual; mantém resposta mais recente e limpa seleção; falha fica visível sem alterar arquivos. */
  async function load() {
    const current = ++generation; notify(t('loading')); setBusy(true);
    try {
      const data = await request(trash ? 'trash' : 'list'); if (current !== generation) return;
      entries = data.entries; revision = data.revision; notify('');
    } catch (error) { if (current === generation) notify(error.message, true); }
    finally { if (current === generation) setBusy(false); }
  }
  /** Bloqueia comandos durante mutação/upload; recebe flag e atualiza controles sem trocar o snapshot. */
  function setBusy(value) {
    busy = value;
    document.querySelectorAll('button').forEach(button => { if (!button.closest('dialog')) button.disabled = value; });
    if (!value) { render(); document.querySelector('[data-filter]').disabled = false; }
    else document.querySelector('[data-filter]').disabled = true;
  }
  /** Envia mutação e nova revisão; recebe operação/dados; exibe falha sem sobrescrever snapshot silenciosamente. */
  async function mutate(operation, values = {}) {
    if (busy) return;
    setBusy(true);
    try { const data = await request(operation, values); entries = data.entries; revision = data.revision; notify(t('done')); }
    catch (error) { notify(error.message, true); }
    finally { setBusy(false); }
  }
  /** Coleta confirmação/entrada em dialog; recebe título/label/valor/aviso e retorna valor ou null no cancelamento. */
  function ask(title, label = '', value = '', help = '') {
    return new Promise(resolve => {
      const input = document.querySelector('[data-prompt-input]');
      document.querySelector('#fm-prompt-title').textContent = title;
      document.querySelector('[data-prompt-text]').textContent = label;
      document.querySelector('[data-prompt-help]').textContent = help;
      document.querySelector('[data-prompt-label]').hidden = !label;
      input.value = value; input.required = false; prompt.returnValue = '';
      /** Resolve uma interação e remove listeners; recebe fechamento nativo, sem escrita. */
      const closed = () => { prompt.removeEventListener('close', closed); resolve(prompt.returnValue === 'ok' ? input.value : null); };
      prompt.addEventListener('close', closed); prompt.showModal(); if (label) { input.focus(); input.select(); }
    });
  }
  document.querySelector('[data-prompt-form]').addEventListener('submit', event => { event.preventDefault(); prompt.close('ok'); });
  document.querySelector('[data-prompt-cancel]').addEventListener('click', () => prompt.close('cancel'));
  /** Dispara ação de toolbar; recebe nome; confirma operações e mantém revisão obtida na navegação. */
  async function action(name) {
    if (busy) return;
    const entry = selected;
    if (name === 'upload') return upload.click();
    if (name === 'refresh') return load();
    if (name === 'trash') { trash = !trash; return load(); }
    if (name === 'up') { path = path.split('/').slice(0,-1).join('/'); return load(); }
    if (name === 'grid' || name === 'list') {
      items.classList.toggle('fm-grid', name === 'grid');
      for (const mode of ['grid','list']) document.querySelector(`[data-action="${mode}"]`).setAttribute('aria-pressed', String(name === mode));
      return;
    }
    if (name === 'mkdir') { const value = await ask(t('newFolder'), t('name')); if (value !== null) return mutate('mkdir', {name: value}); return; }
    if (!entry) return;
    if (name === 'download') { location.href = url('download', {path: entry.path}); return; }
    if (name === 'preview') {
      const image = document.querySelector('[data-preview-image]'); image.alt = entry.name; image.src = url('preview', {path: entry.path}); preview.showModal(); return;
    }
    if (name === 'rename') { const value = await ask(t('rename'), t('name'), entry.name); if (value !== null) return mutate(name, {target: entry.name, name: value}); }
    if (name === 'move') { const value = await ask(t('move'), t('destination')); if (value !== null) return mutate(name, {target: entry.name, destination: value}); }
    if (name === 'delete') { if (await ask(t('delete'), '', '', t('confirmDelete')) !== null) return mutate(name, {target: entry.name}); }
    if (name === 'restore') { if (await ask(t('restore'), '', '', entry.path) !== null) return mutate(name, {target: entry.id}); }
    if (name === 'purge') { const value = await ask(t('purge'), t('name'), '', `${t('confirmPurge')} (${entry.name})`); if (value !== null) return mutate(name, {target: entry.id, name: value}); }
  }
  /** Envia um arquivo multipart com progresso; recebe File, retorna snapshot ou rejeita sem inventar sucesso. */
  function sendFile(file) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest(), data = new FormData();
      for (const [key, value] of Object.entries({csrf_token: root.dataset.csrf, scope, path, revision})) data.append(key, value);
      data.append('file', file); xhr.open('POST', url('upload')); xhr.responseType = 'json'; xhr.timeout = 120000;
      xhr.upload.addEventListener('progress', event => { if (event.lengthComputable) document.querySelector('[data-progress]').value = event.loaded / event.total * 100; });
      xhr.addEventListener('load', () => xhr.status >= 200 && xhr.status < 300 && xhr.response ? resolve(xhr.response) : reject(new Error(xhr.response?.error || t('error.upload'))));
      xhr.addEventListener('error', () => reject(new Error(t('error.network')))); xhr.addEventListener('timeout', () => reject(new Error(t('error.network'))));
      xhr.send(data);
    });
  }
  /** Envia lote sequencial com revisão atualizada; recebe FileList/array; interrompe na primeira falha mantendo confirmações anteriores. */
  async function uploadFiles(files) {
    if (busy || trash || !files.length) return;
    setBusy(true); const progress = document.querySelector('[data-progress]'); progress.hidden = false;
    try {
      for (const file of files) {
        notify(t('uploading', {name: file.name})); progress.value = 0;
        if (file.size === 0 || file.size > 10485760) throw new Error(t('error.size'));
        const data = await sendFile(file); entries = data.entries; revision = data.revision;
      }
      notify(t('done'));
    } catch (error) { notify(error.message, true); }
    finally { upload.value = ''; progress.hidden = true; setBusy(false); }
  }
  document.querySelectorAll('[data-action]').forEach(button => button.addEventListener('click', () => action(button.dataset.action).catch(error => notify(error.message, true))));
  document.querySelectorAll('[data-scope]').forEach(button => button.addEventListener('click', () => {
    if (busy) return; scope = button.dataset.scope; path = '';
    document.querySelectorAll('[data-scope]').forEach(item => item.setAttribute('aria-pressed', String(item === button))); load();
  }));
  document.querySelector('[data-filter]').addEventListener('input', render);
  upload.addEventListener('change', () => uploadFiles([...upload.files]));
  const zone = document.querySelector('[data-dropzone]');
  zone.addEventListener('dragover', event => { if (event.dataTransfer.types.includes('Files') && !busy && !trash) { event.preventDefault(); zone.classList.add('fm-dragover'); } });
  zone.addEventListener('dragleave', () => zone.classList.remove('fm-dragover'));
  zone.addEventListener('drop', event => { event.preventDefault(); zone.classList.remove('fm-dragover'); uploadFiles([...event.dataTransfer.files]); });
  document.querySelector('[data-close-preview]').addEventListener('click', () => preview.close());
  preview.addEventListener('close', () => document.querySelector('[data-preview-image]').removeAttribute('src'));
  load();
})();
