<?php
/** Árvore pública do template: dados resolvidos pelo núcleo, submenu nativo acessível e saída contextual escapada. */
declare(strict_types=1);
use function DBLESSCMS\e;
/** Renderiza ramo limitado já validado; recebe itens e escreve HTML, sem ler/gravar conteúdo ou executar callbacks externos. */
$renderMenu=static function(array $items) use (&$renderMenu,$currentPage): void {
  foreach ($items as $item) {
    $active=$item['slug']===$currentPage&&$item['anchor']==='';
    $attributes=($active?' aria-current="page"':'').($item['newTab']?' target="_blank" rel="noopener noreferrer"':'');
    echo '<li>';
    if ($item['children']!==[]) {
      echo '<details><summary>'.e($item['label']).'</summary><ul>';
      if ($item['href']!=='') echo '<li><a href="'.e($item['href']).'"'.$attributes.'>'.e($item['label']).'</a></li>';
      $renderMenu($item['children']);echo '</ul></details>';
    } else echo '<a href="'.e($item['href']).'"'.$attributes.'>'.e($item['label']).'</a>';
    echo '</li>';
  }
};
?>
<ul class="cms-menu menu-<?= e($menuData['type']) ?>" data-public-menu><?php $renderMenu($menuData['items']); ?></ul>
