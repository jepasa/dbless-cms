<?php
/** Essencial: documento público sem dependências remotas; idioma global, opções fechadas e conteúdo editorial escapado. */
declare(strict_types=1);
use function DBLESSCMS\e;
/** Textos públicos literais do template; idioma global identifica metadados, sem selecionar dicionários. */
$contactLabels=['email'=>'E-mail','phone'=>'Telefone','address'=>'Localização'];
$contactMessages=['site.sending'=>'Enviando mensagem…','site.sent'=>'Mensagem aceita pelo serviço de envio.','site.unavailable'=>'O envio está indisponível no momento. Utilize os contatos informados ou tente novamente mais tarde.','site.failed'=>'Não foi possível enviar a mensagem. Verifique os campos e tente novamente.','site.session'=>'A sessão expirou. Atualize a página antes de enviar novamente.','site.network'=>'A conexão falhou. A mensagem foi mantida para uma nova tentativa.'];
/** Resolve mídia validada relativa ou absoluta; recebe referência e retorna URL, sem ler/gravar arquivos. */
$mediaUrl=static fn(string $reference): string => preg_match('~^https?://~i',$reference)?$reference:($reference===''?'':$base_url.'/'.ltrim($reference,'/'));
/** Resolve destino editorial interno sem perder subdiretório; recebe referência, retorna URL sem mutações. */
$pageUrl=static fn(string $reference): string => str_starts_with($reference,'/')?$base_url.$reference:$reference;
$appearance=$templateSettings['appearance'];
$palette=$appearance['palette']; $mode=$appearance['mode'];
$direction=$appearance['direction']==='auto'?$publicLocale['direction']:$appearance['direction'];
$classes='palette-'.$palette.($appearance['wide']?' content-wide':'').' mode-'.$mode.' font-'.$appearance['fontSize'].' align-'.$appearance['textAlign'];
$canonical=$contentMetadata['canonical']??rtrim($settings['canonical'],'/').($currentPage==='home'?'/':'/'.$currentPage);
$themeColors=['forest'=>'#16614b','ocean'=>'#2155a3','violet'=>'#6942a6','amber'=>'#87521a','graphite'=>'#384456'];
?>
<!doctype html>
<html lang="<?= e($publicLocale['tag']) ?>" dir="<?= e($direction) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/x-icon" href="<?= e(\DBLESSCMS\SiteFavicon::url($store,$base_url)) ?>">
  <link rel="apple-touch-icon" href="<?= e($store->templates->asset($id,$manifest['assets']['files']['appleTouch'],$base_url)) ?>">
  <title><?= e($page['meta']['title']) ?></title>
  <meta name="description" content="<?= e($page['meta']['description']) ?>">
  <meta name="author" content="<?= e($settings['author']) ?>">
  <meta name="keywords" content="<?= e($settings['keywords']) ?>">
  <meta name="theme-color" content="<?= e($themeColors[$palette]) ?>">
  <meta name="robots" content="<?= e($contentMetadata['robots']??'index, follow') ?>">
  <link rel="canonical" href="<?= e($canonical) ?>">
  <meta property="og:locale" content="<?= e($publicLocale['og']) ?>">
  <meta property="og:title" content="<?= e($page['meta']['title']) ?>">
  <meta property="og:description" content="<?= e($page['meta']['description']) ?>">
  <meta property="og:url" content="<?= e($canonical) ?>">
  <meta property="og:type" content="<?= e($contentMetadata['type']??$settings['type']) ?>">
  <meta property="og:site_name" content="<?= e($siteContent['brand']['name']) ?>">
  <?php $siteImage=$page['meta']['image']?:$settings['image'];$siteImageAlt=($page['meta']['image']??'')!==''?($page['meta']['imageAlt']??$page['meta']['title']):$settings['imageAlt'];if ($siteImage!==''): ?><meta property="og:image" content="<?= e($mediaUrl($siteImage)) ?>"><meta property="og:image:alt" content="<?= e($siteImageAlt) ?>"><?php endif; ?>
  <?php if (($page['meta']['image']??'')===''&&$settings['image']!==''): ?>
  <meta property="og:image:type" content="<?= e($settings['imageType']) ?>"><meta property="og:image:width" content="<?= e($settings['imageWidth']) ?>"><meta property="og:image:height" content="<?= e($settings['imageHeight']) ?>">
  <?php endif; ?>
  <?php if (isset($contentMetadata)): ?>
  <link rel="alternate" type="application/rss+xml" title="RSS" href="<?= e($contentMetadata['feed']) ?>">
  <meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="<?= e($page['meta']['title']) ?>"><meta name="twitter:description" content="<?= e($page['meta']['description']) ?>">
  <?php if ($contentMetadata['publishedAt']!==''): ?><meta property="article:published_time" content="<?= e($contentMetadata['publishedAt']) ?>"><meta property="article:modified_time" content="<?= e($contentMetadata['updatedAt']) ?>"><?php endif; ?>
  <?php endif; ?>
  <?php foreach ($manifest['assets']['styles'] as $stylesheet): ?><link rel="stylesheet" href="<?= e($store->templates->asset($id,$stylesheet,$base_url)) ?>"><?php endforeach; ?>
</head>
<body class="<?= e($classes) ?>" data-page="<?= e($currentPage) ?>">
<a class="skip-link" href="#main"><?= e('Pular para o conteúdo') ?></a>
<?php require $store->templates->path($id,'partials',$manifest['partials']['header']); ?>
<main id="main" tabindex="-1"><?php require $contentFile; ?></main>
<?php require $store->templates->path($id,'partials',$manifest['partials']['footer']); ?>
<?php foreach ($manifest['assets']['scripts'] as $script): ?><script src="<?= e($store->templates->asset($id,$script,$base_url)) ?>" defer></script><?php endforeach; ?>
</body>
</html>
