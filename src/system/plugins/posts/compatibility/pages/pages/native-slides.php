<?php
/** Legacy typed Slides insertion controls shared by native Pages editors; receives declared locations and translations, emits escaped controls without writes. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<section class="panel" data-page-slides><div class="field-grid"><label><?= e($t('pages.slidesLocation')) ?><select data-page-slide-location><?php foreach($slideLocations as $slideKey=>$slideLocation): ?><option value="<?= e($slideKey) ?>"><?= e($slideLocation['description'].' · '.$slideKey) ?></option><?php endforeach; ?></select></label><label><?= e($t('pages.slidesMethod')) ?><select data-page-slide-method><option value="block"><?= e($t('pages.slidesBlock')) ?></option><option value="marker"><?= e($t('pages.slidesMarker')) ?></option></select></label></div><p class="muted"><?= e($t('pages.slidesHelp')) ?></p><button type="button" class="button-secondary" data-insert-page-slide><?= e($t('pages.slidesInsert')) ?></button></section>
