<?php
/** Opt-in editorial shell: trusted plugin-rendered toolbar/content/properties slots; no persistence, route logic or arbitrary includes. Labels are escaped. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<div class="editorial-workspace" data-editorial-workspace>
  <div class="editorial-toolbar"><?= $editorialToolbar ?></div>
  <div class="editorial-content"><?= $editorialContent ?></div>
  <details class="editorial-properties" id="editorial-properties" data-editorial-properties open>
    <summary><?= e($editorialPropertiesLabel) ?></summary>
    <div class="editorial-properties-body"><?= $editorialProperties ?></div>
  </details>
</div>
