/** Shared human UI actions for immutable-ID Pages drafts; disclosure controls are clicked normally, never opened through DOM mutation. */
/** Reveal a field through its native disclosures; receives locator, clicks closed ancestor summaries without changing content. */
export async function revealField(field){for(const details of await field.locator('xpath=ancestor::details').all())if(await details.getAttribute('open')===null){if(await details.locator(':scope > summary').isVisible())await details.locator(':scope > summary').click();else await field.page().locator('[data-editorial-properties-toggle]').click();}}
/** Create a blank draft with optional first-save visual text; receives page/base/title/URL/text, returns immutable ID after ordinary save/navigation. */
export async function createDraft(page,base,title,slug,body=''){
  await page.goto(base+'/cms-admin/?view=plugin-panel&plugin=pages&route=new');
  const form=page.locator('form[action*="editorial-create"]');await form.locator('[name="title"]').fill(title);await revealField(form.locator('[name="slug"]'));await form.locator('[name="slug"]').fill(slug);
  if(body){const editor=form.locator('[data-core-editor]').first();await editor.frameLocator('[data-editor-canvas]').locator('.dbless-canvas').waitFor();await editor.locator('[data-editor-action=paragraph]').click();await editor.frameLocator('[data-editor-canvas]').getByRole('textbox').first().fill(body);}
  await Promise.all([page.waitForNavigation(),form.locator('button[type="submit"]:not([form])').click()]);return new URL(page.url()).searchParams.get('key');
}
/** Save the working form then publish its saved snapshot; receives page, propagates validation/navigation failures. */
export async function publishDraft(page){
  await Promise.all([page.waitForNavigation(),page.locator('form[action*="editorial-save"] button[type="submit"]:not([form])').click()]);
  await Promise.all([page.waitForNavigation(),page.locator('button[form="page-publication"]:not([formaction])').click()]);
}
