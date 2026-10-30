/** Essencial: navegação móvel acessível e retorno verdadeiro do contato, sem dependências, HTML dinâmico ou armazenamento de dados. */
'use strict';
const menu = document.querySelector('[data-site-menu]');
if (menu) {
  const summary = menu.querySelector('summary');
  menu.addEventListener('toggle', () => summary.setAttribute('aria-expanded', String(menu.open)));
  menu.querySelectorAll('a').forEach(link => link.addEventListener('click', () => { menu.open = false; }));
  menu.addEventListener('keydown', event => { if (event.key === 'Escape' && menu.open) { event.preventDefault(); menu.open = false; summary.focus(); } });
  document.addEventListener('click', event => { if (menu.open && !menu.contains(event.target)) menu.open = false; });
}
const form = document.querySelector('[data-site-contact]');
if (form) {
  const messages = JSON.parse(form.dataset.messages);
  const status = form.querySelector('[data-contact-status]');
  const button = form.querySelector('button[type=submit]');
  let pending = false;
  /** Publica estado acessível do envio; recebe chave/erro, altera somente texto/estilo e nunca insere HTML. */
  function notify(key, error = false) { status.textContent = messages['site.' + key]; status.hidden = false; status.classList.toggle('is-error', error); }
  /** Envia campos validados ao contrato de contato existente; recebe submit, preserva dados em falhas e reconhece sucesso apenas em OK real. */
  form.addEventListener('submit', async event => {
    event.preventDefault(); if (pending || !form.reportValidity()) return;
    pending = true; button.disabled = true; form.setAttribute('aria-busy', 'true'); notify('sending');
    try {
      const response = await fetch(form.action, {method:'POST',credentials:'same-origin',headers:{Accept:'text/plain'},body:new URLSearchParams(new FormData(form))});
      const result = await response.text();
      if (response.ok && result.trim() === 'OK') { notify('sent'); form.reset(); }
      else notify(response.status === 503 ? 'unavailable' : response.status === 403 ? 'session' : 'failed', true);
    } catch { notify('network', true); }
    finally { pending = false; button.disabled = false; form.setAttribute('aria-busy', 'false'); }
  });
}
