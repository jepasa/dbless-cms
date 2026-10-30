/** Editor estruturado CMS2JSON: campos/JSON sincronizados, erros textuais e confirmação de remoção/restauração. */
'use strict';
const editor = document.querySelector('[data-document]');
const fields = document.querySelector('[data-fields]');
const error = document.querySelector('[data-editor-error]');
const jsonLabel = document.querySelector('[data-json-label]');
const language = document.querySelector('[data-admin-language]');
const messages = {...(language ? JSON.parse(language.dataset.adminLanguage) : {}),...(editor ? JSON.parse(editor.dataset.editorCommonMessages || '{}') : {})};
const languages = language ? JSON.parse(language.dataset.languages) : {};
const templates = language ? JSON.parse(language.dataset.templateOptions || '{}') : {};
const editorSchema = editor ? JSON.parse(editor.dataset.editorSchema || '{}') : {};
const editorMessages = editor ? JSON.parse(editor.dataset.editorMessages || '{}') : {};
const editorChoices = editor ? JSON.parse(editor.dataset.editorChoices || '{}') : {};
const globalChoices = [
  {selector:'[data-admin-locale]', trail:['settings','adminLocale'], options:languages},
  {selector:'[data-site-locale]', trail:['settings','siteLocale'], options:null},
  {selector:'[data-site-template]', trail:['template'], options:templates},
];

/** Traduz chave enviada pelo servidor; recebe chave/argumentos, retorna texto simples sem alterar DOM. */
function translate(key, parameters = {}) {
  if (typeof messages[key] !== 'string') throw new Error('Missing admin translation: ' + key);
  return messages[key].replace(/\{([a-zA-Z][a-zA-Z0-9_]*)\}/g, (token, name) => Object.hasOwn(parameters, name) ? String(parameters[name]) : token);
}

/** Constrói campos a partir de JSON válido; recebe objeto/pai/caminho e altera somente DOM, sem HTML livre. */
function renderFields(value, parent, trail = []) {
  for (const [key, child] of Object.entries(value)) {
    const path = [...trail, key];
    const contract = path.reduce((schema, segment) => schema?.properties?.[segment], editorSchema) || {};
    const fieldTitle = editorMessages[contract.title] || messages['field.' + key];
    if ((key === 'adminLocale' && document.querySelector('[data-admin-locale]')) || (key === 'siteLocale' && document.querySelector('[data-site-locale]')) || (trail.length === 0 && key === 'template' && document.querySelector('[data-site-template]'))) continue;
    if (child !== null && typeof child === 'object') {
      const group = document.createElement('fieldset'); group.className = 'field-group';
      const legend = document.createElement('legend'); legend.textContent = fieldTitle || (/^\d+$/.test(key) ? translate('editor.item', {number: Number(key) + 1}) : key); group.append(legend);
      const grid = document.createElement('div'); grid.className = 'field-grid'; group.append(grid);
      renderFields(child, grid, path); parent.append(group); continue;
    }
    const label = document.createElement('label'); label.textContent = fieldTitle || key;
    let input;
    const choices = editorChoices[path.join('.')] || (contract.enum ? Object.fromEntries(contract.enum.map(value => [value, editorMessages[contract.enumLabels?.[value]] || String(value)])) : null);
    if (choices) {
      input = document.createElement('select');
      const values = {...choices};
      if (!Object.hasOwn(values, String(child))) values[String(child)] = String(child);
      for (const [optionValue, text] of Object.entries(values)) {
        const option = document.createElement('option'); option.value = optionValue; option.textContent = text; input.append(option);
      }
    } else if (key === 'status' || key === 'adminLocale') {
      input = document.createElement('select');
      const options = key === 'adminLocale' ? Object.entries(languages) : [['draft',translate('status.draft')],['published',translate('status.published')]];
      for (const [optionValue, text] of options) {
        const option = document.createElement('option'); option.value = optionValue; option.textContent = text; input.append(option);
      }
    } else if (typeof child === 'string' && (child.length > 100 || /description|intro|lead|summary|tagline/.test(key))) {
      input = document.createElement('textarea'); input.rows = 3; label.classList.add('wide');
    } else { input = document.createElement('input'); input.type = typeof child === 'boolean' ? 'checkbox' : typeof child === 'number' ? 'number' : 'text'; }
    input.dataset.path = JSON.stringify(path); input.dataset.kind = child === null ? 'null' : typeof child;
    if (editor.dataset.editorSchema) {
      input.name = key;
      input.setAttribute('aria-label', fieldTitle || key);
    }
    if (contract.maxLength) input.maxLength = contract.maxLength;
    if (contract.minimum !== undefined) input.min = contract.minimum;
    if (contract.maximum !== undefined) input.max = contract.maximum;
    if (contract.type === 'integer') input.step = '1';
    if (typeof child === 'boolean') input.checked = child; else input.value = child ?? '';
    if (['schema_version','slug','page'].includes(key)) { input.readOnly = true; input.classList.add('read-only'); }
    if (typeof child === 'boolean' && editor.dataset.editorSchema) {
      const caption = document.createElement('span'); caption.textContent = label.textContent;
      label.classList.add('editor-checkbox'); label.replaceChildren(input, caption);
    } else label.append(input);
    parent.append(label);
  }
}

/** Serializa campos para JSON; sem parâmetros; preserva tipos e estrutura, lança erro se JSON base estiver inválido. */
function synchronize() {
  const data = JSON.parse(editor.value);
  for (const input of fields.querySelectorAll('[data-path]')) {
    const trail = JSON.parse(input.dataset.path); let target = data;
    for (const segment of trail.slice(0,-1)) target = target[segment];
    target[trail.at(-1)] = input.dataset.kind === 'boolean' ? input.checked : input.dataset.kind === 'number' ? Number(input.value) : input.dataset.kind === 'null' && input.value === '' ? null : input.value;
  }
  editor.value = JSON.stringify(data, null, 2) + '\n';
}

/** Alterna representação mantendo dados; recebe modo, retorna nada; erros são exibidos sem apagar JSON. */
function setMode(mode) {
  try {
    if (!fields.hidden) synchronize();
    if (mode === 'fields') {
      const data = JSON.parse(editor.value); fields.replaceChildren(); renderFields(data, fields); fields.hidden = false; jsonLabel.hidden = true;
    } else { fields.hidden = true; jsonLabel.hidden = false; }
    error.hidden = true;
    for (const tab of document.querySelectorAll('[data-mode]')) tab.setAttribute('aria-pressed', String(tab.dataset.mode === mode));
  } catch { error.textContent = translate('editor.invalid_fields'); error.hidden = false; }
}

if (editor && fields) {
  setMode('fields');
  for (const tab of document.querySelectorAll('[data-mode]')) tab.addEventListener('click', () => setMode(tab.dataset.mode));
  editor.closest('form').addEventListener('submit', (event) => {
    try { if (!fields.hidden) synchronize(); const data = JSON.parse(editor.value);
      for (const choice of globalChoices) {
        const select = document.querySelector(choice.selector);
        if (select) {
          let target = data;
          for (const key of choice.trail.slice(0,-1)) target = target[key];
          target[choice.trail.at(-1)] = select.value;
        }
      }
      editor.value = JSON.stringify(data, null, 2) + '\n';
    }
    catch { event.preventDefault(); error.textContent = translate('editor.invalid_json'); error.hidden = false; }
  });
}
for (const form of document.querySelectorAll('[data-confirm]')) form.addEventListener('submit', (event) => { if (!form.matches('[data-settings-form]') && !window.confirm(form.dataset.confirm)) event.preventDefault(); });

for (const choice of globalChoices) {
  const select = document.querySelector(choice.selector);
  if (!editor || !select) continue;
  /** Sincroniza escolha global no JSON; preserva campos e sinaliza JSON inválido sem sobrescrevê-lo. */
  select.addEventListener('change', () => {
    try {
      if (!fields.hidden) synchronize();
      const data = JSON.parse(editor.value);
      let target = data;
      for (const key of choice.trail.slice(0,-1)) target = target[key];
      target[choice.trail.at(-1)] = select.value;
      editor.value = JSON.stringify(data, null, 2) + '\n';
    } catch { error.textContent = translate('editor.invalid_json'); error.hidden = false; }
  });
  /** Reflete escolha editada em JSON somente quando disponível; mantém digitação incompleta intacta. */
  editor.addEventListener('input', () => {
    try {
      let value = JSON.parse(editor.value);
      for (const key of choice.trail) value = value?.[key];
      if (choice.options ? Object.hasOwn(choice.options, value) : typeof value === 'string') select.value = value;
    } catch { /* Submissão valida o JSON sem apagar a digitação incompleta. */ }
  });
}

/** Navegação móvel: disclosure com foco contido, Escape e fundo inerte; desktop permanece navegável sem drawer. */
{
  const toggle = document.querySelector('[data-nav-toggle]');
  const sidebar = document.querySelector('#admin-navigation');
  if (toggle && sidebar) {
    const close = document.querySelector('[data-nav-close]');
    const backdrop = document.querySelector('[data-nav-backdrop]');
    const workspace = document.querySelector('.workspace');
    const mobile = window.matchMedia('(max-width: 800px)');
    let opened = false;
    /** Aplica estado do drawer; recebe booleano/restauração de foco, altera inert/ARIA sem navegação ou escrita. */
    function navigation(open, restore = true) {
      opened = mobile.matches && open;
      document.body.classList.toggle('nav-open', opened);
      toggle.setAttribute('aria-expanded', String(opened));
      sidebar.inert = mobile.matches && !opened;
      workspace.inert = opened;
      backdrop.hidden = !opened;
      if (opened) close.focus();
      else if (restore && mobile.matches) toggle.focus();
    }
    document.body.classList.add('nav-ready');
    toggle.addEventListener('click', () => navigation(!opened));
    close.addEventListener('click', () => navigation(false));
    backdrop.addEventListener('click', () => navigation(false));
    mobile.addEventListener('change', () => navigation(false, false));
    sidebar.addEventListener('keydown', event => {
      if (!opened) return;
      if (event.key === 'Escape') { event.preventDefault(); navigation(false); }
      if (event.key === 'Tab') {
        const controls = [...sidebar.querySelectorAll('a[href],button,summary')].filter(node => node.getClientRects().length && !node.closest('details:not([open]) > :not(summary)'));
        const first = controls[0], last = controls.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    });
    navigation(false, false);
  }
}

/** Dropdown da conta: disclosure nativo, estado acessível, Escape/foco e fechamento fora do componente; sem mutações de dados. */
{
  const menu = document.querySelector('[data-user-menu]');
  if (menu) {
    const summary = menu.querySelector('summary');
    menu.addEventListener('toggle', () => summary.setAttribute('aria-expanded', String(menu.open)));
    menu.addEventListener('keydown', event => {
      if (event.key === 'Escape' && menu.open) { event.preventDefault(); menu.open = false; summary.focus(); }
    });
    document.addEventListener('click', event => { if (menu.open && !menu.contains(event.target)) menu.open = false; });
  }
}
