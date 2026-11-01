<?php
/** Core optional-resource registry editor; scoped origins and editable public notice, no automatic tracking installation or legal approval. */
declare(strict_types=1);
use DBLESSCMS\{Privacy,Security,ExtensionDocument};
use function DBLESSCMS\e;
$privacySnapshot=(new Privacy($this->store))->repository()->read();
$privacyDocument=ExtensionDocument::encode($privacySnapshot['data']);
if($error!==null&&is_string($_POST['privacy_document']??null))$privacyDocument=$_POST['privacy_document'];
?>
<p class="notice"><?= e($t('privacy.help')) ?></p>
<form method="post" class="stack"><input type="hidden" name="action" value="privacy-save"><input type="hidden" name="csrf_token" value="<?= e(Security::token()) ?>"><input type="hidden" name="revision" value="<?= e($privacySnapshot['revision']) ?>">
<section class="panel"><h2><?= e($t('privacy.registry')) ?></h2><p><?= e($t('privacy.registryHelp')) ?></p><label><?= e($t('privacy.document')) ?><textarea name="privacy_document" class="json-editor" rows="24" maxlength="131072" spellcheck="false" required><?= e($privacyDocument) ?></textarea></label></section>
<div class="savebar"><a href="?view=site"><?= e($t('nav.site')) ?></a><button type="submit" class="button-primary"><?= e($t('editor.save')) ?></button></div></form>
<section class="panel"><h2><?= e($t('privacy.example')) ?></h2><p><?= e($t('privacy.exampleHelp')) ?></p><pre><code><?= e(ExtensionDocument::encode(['id'=>'optional-measurement','name'=>'Example analytics resource — not installed','category'=>'analytics','type'=>'script','url'=>'/tracking/measurement.js','origins'=>['connect'=>['https://metrics.example.com']]])) ?></code></pre></section>
<section class="panel"><h2><?= e($t('nav.history')) ?></h2><?php foreach((new Privacy($this->store))->repository()->history() as $backup): ?><form method="post" class="history-row"><input type="hidden" name="action" value="privacy-restore"><input type="hidden" name="csrf_token" value="<?= e(Security::token()) ?>"><input type="hidden" name="revision" value="<?= e($privacySnapshot['revision']) ?>"><input type="hidden" name="backup" value="<?= e($backup) ?>"><span><?= e(substr($backup,0,22)) ?></span><button type="submit" class="button-secondary"><?= e($t('editor.restore')) ?></button></form><?php endforeach; ?></section>
