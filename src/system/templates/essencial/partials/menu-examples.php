<?php
/** Demonstração oficial Menu: quatro locais declarados, mesmos dados editoriais e renderização nativa acessível do Essencial. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<section class="menu-examples"><h2>Quatro formas de orientar a navegação.</h2><p>Os exemplos abaixo utilizam o mesmo conjunto de links em locais diferentes. Abra os grupos para explorar os submenus.</p><div class="menu-demo-grid">
<?php foreach(['navbar'=>'Navbar','sidebar'=>'Sidebar','list'=>'Lista','dropdown'=>'Dropdown'] as $type=>$title): $menuData=$navigation->location('demo-'.$type);if($menuData===null)continue; ?>
<div class="menu-demo menu-demo-<?= e($type) ?>" data-menu-demo="<?= e($type) ?>"><h3><?= e($title) ?></h3><nav aria-label="<?= e($title.' de demonstração') ?>"><?php require $store->templates->path($id,'partials',$manifest['partials']['menu']); ?></nav></div>
<?php endforeach; ?></div></section>
