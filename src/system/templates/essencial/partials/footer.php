<?php
/** Rodapé Essencial: identidade, navegação gerenciada e contatos opcionais; dados editoriais escapados, sem dependências externas. */
declare(strict_types=1);
use function DBLESSCMS\e;
$menuData=$navigation->location('footer-links');
$contact=$settings['contact'];
$hasContact=$contact['email']!==''||$contact['phone']!==''||$contact['address']!==''||$siteContent['brand']['socials']!==[];
?>
<footer class="site-footer" aria-label="Rodapé do site">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-identity text-flow">
        <a class="footer-brand" href="<?= e($base_url.'/') ?>"><span class="footer-mark" aria-hidden="true">↗</span><?= e($siteContent['brand']['name']) ?></a>
        <p class="footer-role"><?= e($siteContent['brand']['role']) ?></p>
        <p><?= e($siteContent['brand']['tagline']) ?></p>
        <a class="text-link" href="<?= e($base_url.'/#contact') ?>">Entre em contato <span aria-hidden="true">↗</span></a>
      </div>
      <nav class="footer-navigation" aria-label="Links do rodapé">
        <h2 class="footer-heading">Explore</h2>
        <?php if ($menuData!==null): require $store->templates->path($id,'partials',$manifest['partials']['menu']);else: ?>
        <ul class="footer-list"><li><a href="<?= e($base_url.'/') ?>">Início</a></li><li><a href="<?= e($base_url.'/#about') ?>">Sobre</a></li><li><a href="<?= e($base_url.'/#services') ?>">Serviços</a></li><li><a href="<?= e($base_url.'/#portfolio') ?>">Projetos</a></li></ul>
        <?php endif; ?>
      </nav>
      <?php if ($hasContact): ?><div class="footer-contact">
        <h2 class="footer-heading">Conecte-se</h2>
        <ul class="footer-list">
          <?php if ($contact['email']!==''): ?><li><span class="footer-label">E-mail</span><a href="<?= e('mailto:'.$contact['email']) ?>"><?= e($contact['email']) ?></a></li><?php endif; ?>
          <?php if ($contact['phone']!==''): ?><li><span class="footer-label">Telefone</span><a href="<?= e('tel:'.preg_replace('/[^+0-9]/','',$contact['phone'])) ?>"><?= e($contact['phone']) ?></a></li><?php endif; ?>
          <?php if ($contact['address']!==''): ?><li><span class="footer-label">Localização</span><span><?= e($contact['address']) ?></span></li><?php endif; ?>
        </ul>
        <?php if ($siteContent['brand']['socials']!==[]): ?><nav class="footer-profiles" aria-label="Redes e perfis"><?php foreach ($siteContent['brand']['socials'] as $social): ?><a href="<?= e($pageUrl($social['href'])) ?>"><?= e($social['label']) ?> <span aria-hidden="true">↗</span></a><?php endforeach; ?></nav><?php endif; ?>
      </div><?php endif; ?>
    </div>
    <div class="footer-bottom"><small>© <?= e(gmdate('Y')) ?> <?= e($siteContent['brand']['name']) ?></small><a class="back-link" href="#main">Voltar ao topo <span aria-hidden="true">↑</span></a></div>
  </div>
</footer>
