/** Shared human UI actions for immutable-ID Pages drafts; never writes storage or bypasses the browser. */
/** Create a blank draft using visible model fields; receives page/base/title/URL, returns immutable ID after navigation or propagates UI failure. */
export async function createDraft(page,base,title,slug){
  await page.goto(base+'/cms-admin/?view=plugin-panel&plugin=pages&route=new');
  const form=page.locator('form[action*="editorial-create"]');await form.locator('[name="title"]').fill(title);await form.locator('[name="slug"]').fill(slug);await form.locator('[name="model"]').selectOption('blank');
  await Promise.all([page.waitForNavigation(),form.locator('button[type="submit"]').click()]);return new URL(page.url()).searchParams.get('key');
}
/** Save the visible working form, then explicitly publish its saved snapshot; receives page, no return, propagates validation/navigation failure. */
export async function publishDraft(page){
  await Promise.all([page.waitForNavigation(),page.locator('form[action*="editorial-save"] button[type="submit"]').click()]);
  await Promise.all([page.waitForNavigation(),page.locator('form[action*="editorial-publish"] button[type="submit"]').click()]);
}
