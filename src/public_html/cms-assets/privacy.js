/** Local privacy choices: optional resources stay unloaded until a current explicit category choice; no visitor IDs, server logs or network consent service. */
(() => {
  'use strict';
  const panel = document.querySelector('[data-cms-consent]');
  if (!panel) return;
  const options = JSON.parse(panel.dataset.options), key = options.key, form = panel.querySelector('form'), mounted = new Map();
  let choice = null;
  /** Validate untrusted local preference; receive object, return category map or null without treating it as a credential. */
  function validate(value) {
    if (!value || value.version !== options.version || !Number.isFinite(value.at) || value.at > Date.now() || value.at < Date.now() - options.days * 86400000) return null;
    if (!['analytics', 'marketing', 'media'].every(name => typeof value[name] === 'boolean')) return null;
    return value;
  }
  /** Reconcile declared resource nodes after an explicit choice; withdrawal removes nodes but cannot undo data already transmitted or arbitrary trusted script effects. */
  function apply() {
    for (const resource of options.resources) {
      const allowed = choice?.[resource.category] === true;
      if (!allowed && mounted.has(resource.id)) { mounted.get(resource.id).remove(); mounted.delete(resource.id); }
      if (!allowed || mounted.has(resource.id)) continue;
      const node = document.createElement({script: 'script', image: 'img', embed: 'iframe'}[resource.type]);
      node.dataset.privacyResource = resource.id;
      if (resource.type === 'script') { node.defer = true; node.src = resource.url; }
      else if (resource.type === 'image') { node.alt = ''; node.hidden = true; node.src = resource.url; }
      else { node.title = resource.name; node.loading = 'lazy'; node.referrerPolicy = 'no-referrer'; node.src = resource.url; }
      document.body.append(node); mounted.set(resource.id, node);
    }
    document.dispatchEvent(new CustomEvent('dbless:privacy-change', {detail: {analytics: choice?.analytics === true, marketing: choice?.marketing === true, media: choice?.media === true}}));
  }
  /** Persist minimum preference and reload when withdrawing previously executed resources; receive category map, avoid extra consent identifiers. */
  function save(values) {
    const withdraw = options.resources.some(item => choice?.[item.category] && !values[item.category]);
    choice = {...values, version: options.version, at: Date.now()};
    try { localStorage.setItem(key, JSON.stringify(choice)); } catch { /* In-memory preference only; storage failure must not enable unchecked categories. */ }
    panel.hidden = true;
    document.querySelector('[data-privacy-open]').focus();
    if (withdraw) { location.reload(); return; }
    apply();
  }
  /** Show nonmodal accessible preferences without trapping focus or blocking necessary navigation; restore the prior category selection. */
  function open() { panel.hidden = false; for (const input of form.querySelectorAll('input[type="checkbox"]')) input.checked = choice?.[input.name] === true; panel.querySelector('input,button').focus(); }
  try { choice = validate(JSON.parse(localStorage.getItem(key))); } catch { choice = null; }
  panel.hidden = choice !== null;
  apply();
  document.querySelector('[data-privacy-open]').addEventListener('click', open);
  panel.querySelector('[data-privacy-accept]').addEventListener('click', () => save({analytics: true, marketing: true, media: true}));
  panel.querySelector('[data-privacy-reject]').addEventListener('click', () => save({analytics: false, marketing: false, media: false}));
  form.addEventListener('submit', event => { event.preventDefault(); const values = {}; for (const name of ['analytics', 'marketing', 'media']) values[name] = form.elements.namedItem(name)?.checked === true; save(values); });
  panel.addEventListener('keydown', event => { if (event.key === 'Escape') { panel.hidden = true; document.querySelector('[data-privacy-open]').focus(); } });
  window.addEventListener('storage', event => { if (event.key === key) location.reload(); });
  window.setInterval(() => { if (choice && !validate(choice)) { try { localStorage.removeItem(key); } catch { /* New page remains unconsented. */ } location.reload(); } }, 60000);
})();
