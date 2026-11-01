/** Forms enhancement: accessible public transport with values retained on failures, plus private route-bound configuration saves; never insert server HTML. */
(() => {
  'use strict';
  document.querySelectorAll('[data-cms-form-submit]').forEach(form => {
    let pending = false;
    form.addEventListener('submit', async event => {
      if (pending) { event.preventDefault(); return; }
      if (!form.reportValidity()) return;
      event.preventDefault(); const data = new FormData(form), button = form.querySelector('button[type="submit"]'), status = form.querySelector('[data-cms-form-status]');
      pending = true; button.disabled = true; form.setAttribute('aria-busy', 'true'); status.textContent = 'Sending…';
      try { const response = await fetch(form.action, {method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/json'}, body: data}); let result; try { result = await response.json(); } catch { throw new Error('The response could not be verified. Your fields have been retained.'); } if (!response.ok) throw new Error(result.error || 'Delivery unavailable.'); status.textContent = `${result.message} Reference: ${result.reference}`; for (const field of form.querySelectorAll('input:not([type="hidden"]),textarea,select')) { if (['checkbox', 'radio'].includes(field.type)) field.checked = false; else field.value = ''; } button.disabled = true; }
      catch (error) { status.textContent = error.message; pending = false; button.disabled = false; }
      finally { form.removeAttribute('aria-busy'); status.focus(); }
    });
  });
  document.querySelectorAll('[data-forms-editor]').forEach(editor => {
    const list = editor.querySelector('[data-form-field-list]'), sample = list.firstElementChild.cloneNode(true);
    /** Reindex native names after add/remove; no user text is interpreted as markup. */
    function reindex() { [...list.children].forEach((row, index) => row.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(/fields\[\d+\]/, `fields[${index}]`); })); }
    editor.addEventListener('click', event => {
      if (event.target.closest('[data-form-field-remove]')) { if (list.children.length > 1) event.target.closest('fieldset').remove(); reindex(); }
      if (event.target.closest('[data-form-field-add]') && list.children.length < 20) { const row = sample.cloneNode(true); row.querySelectorAll('input').forEach(input => { if (input.type === 'checkbox') input.checked = false; else input.value = input.type === 'number' ? '300' : ''; }); row.querySelectorAll('select').forEach(select => { select.selectedIndex = 0; }); list.append(row); reindex(); row.querySelector('input').focus(); }
    });
  });
  document.querySelectorAll('[data-forms-admin]').forEach(form => form.addEventListener('submit', async event => {
    if (event.defaultPrevented) return;
    event.preventDefault(); const button = form.querySelector('button[type="submit"]'), status = document.querySelector('[data-forms-admin-status]');
    if (button?.disabled) return;
    if (form.dataset.confirm && !confirm(form.dataset.confirm)) return;
    if (button) button.disabled = true;
    try { const response = await fetch(form.action, {method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/json'}, body: new FormData(form)}); const data = await response.json(); if (!response.ok) throw new Error(data.error || 'Request failed.'); location.assign(data.redirect); }
    catch (error) { status.textContent = error.message; status.hidden = false; status.focus(); if (button) button.disabled = false; }
  }));
})();
