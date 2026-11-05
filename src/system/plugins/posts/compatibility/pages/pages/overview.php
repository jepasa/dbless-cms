<?php
/** Catálogo de páginas no padrão de extensões: tabela responsiva, ações acessíveis e remoção recuperável revisionada. */
declare(strict_types=1);
use function DBLESSCMS\e;
use DBLESSCMS\{Icons,PagePublications};
$homepageRegistry=new \DBLESSCMS\Plugins($context->store);$homepageEnabled=isset($homepageRegistry->all()['homepage'])&&$homepageRegistry->state('homepage')['enabled'];
$publicationReader=new PagePublications($context->store);$catalog=$publicationReader->read();$siteRevision=$context->store->read('site')['revision'];
$parentFilter=$_GET['parent']??'';if(!is_string($parentFilter)||($parentFilter!==''&&!PagePublications::identifier($parentFilter)))throw new \DBLESSCMS\HttpError(422,'Invalid parent filter.','pages.editorial.invalid');$rows=[];
foreach($pages as $id=>$item){$row=$catalog['data']['records'][$id]??null;$item['state']=$row===null?$item['data']['status']:PagePublications::status($row);$item['updated']=$row['updatedAt']??'';$item['publishedAt']=$row['publishAt']??'';$item['model']=$row['model']??'legacy';$item['order']=$row['draft']['editorial']['order']??0;$item['title']=$row['draft']['document']['meta']['title']??$item['data']['meta']['title'];$item['parent']=$row['parent']??'';$liveSlug=$row!==null?(PagePublications::effective($row)['url']??$row['draft']['url']):(string)$id;$item['url']=rtrim($context->base,'/').'/'.($liveSlug==='home'?'':rawurlencode($liveSlug));$item['parentName']=$catalog['data']['records'][$item['parent']]['draft']['document']['meta']['title']??($pages[$item['parent']]['data']['meta']['title']??$item['parent']);if($parentFilter!==''&&$item['parent']!==$parentFilter)continue;$rows[$id]=$item;}
$columns=[];foreach(['title'=>'catalog.name','parent'=>'pages.parent','order'=>'pages.order','description'=>'catalog.description','state'=>'catalog.status','updated'=>'pages.updated','publishedAt'=>'pages.publishedAt'] as $key=>$label)$columns[$key]=['label'=>$t($label),'type'=>$key==='order'?'number':'text','value'=>static fn(array $row):string|int=>$key==='description'?$row['data']['meta']['description']:($key==='parent'?$row['parentName']:($key==='state'?$t('pages.state.'.$row['state']):$row[$key]))];$columns['actions']=['label'=>$t('catalog.actions')];
$states=[];foreach(['draft','pending','published','modified','scheduled'] as $state)$states[$state]=$t('pages.state.'.$state);
$catalogTable=new \DBLESSCMS\AdminTable($rows,$columns,['view'=>'plugin-panel','plugin'=>$context->manifest['id'],'route'=>'overview']+($parentFilter!==''?['parent'=>$parentFilter]:[]),$context->language->locale(),$context->base,['states'=>$states,'state'=>static fn(array $row):string=>$row['state'],'sort'=>'title','names'=>['page'=>'catalog_page'],'pageSize'=>$context->settings['browser']['pageSize']??25,'errorStatus'=>422,'clearRoute'=>['parent']]);$rows=$catalogTable->rows;
?>
<section class="panel extension-panel overview-catalog" data-overview-catalog="pages">
<?= $catalogTable->toolbar(\DBLESSCMS\AdminTable::action($link('new'),'create',$t('pages.new'),$context->base)) ?>
<div class="table-scroll"><table class="extension-table"><caption class="sr-only"><?= e($t('nav.pages')) ?></caption>
<thead><tr><?= $catalogTable->headings() ?></tr></thead>
<tbody><?php foreach ($rows as $slug=>$item): $data=$item['data']; $name=$item['title']; $protected=in_array((string)$slug,['home','404'],true); ?>
<tr data-page-id="<?= e($slug) ?>">
<td data-label="<?= e($t('catalog.name')) ?>"><strong><?= e($name) ?></strong><small class="extension-id"><?= e($t('pages.model.'.$item['model'])) ?></small><small class="extension-id"><?= e($item['url']) ?></small></td>
<td data-label="<?= e($t('pages.parent')) ?>"><?php if($item['parent']!==''): ?><a href="<?= e($catalogTable->url(['parent'=>$item['parent'],'catalog_page'=>1])) ?>"><?= e($item['parentName']) ?></a><?php else: ?>—<?php endif; ?></td>
<td data-label="<?= e($t('pages.order')) ?>"><?= $item['order'] ?></td>
<td data-label="<?= e($t('catalog.description')) ?>"><?= e($data['meta']['description']) ?></td>
<td data-label="<?= e($t('catalog.status')) ?>"><span class="status <?= e($data['status']) ?>"><?= e($t('pages.state.'.$item['state'])) ?></span></td><td class="catalog-date" data-label="<?= e($t('pages.updated')) ?>"><?= e($item['updated']?str_replace(['T','Z'],[' ',' UTC'],$item['updated']):$t('pages.unknownDate')) ?></td><td class="catalog-date" data-label="<?= e($t('pages.publishedAt')) ?>"><?= e($item['publishedAt']?str_replace(['T','Z'],[' ',' UTC'],$item['publishedAt']):$t('pages.unknownDate')) ?></td>
<td data-label="<?= e($t('catalog.actions')) ?>"><div class="row-actions">
<?php foreach (['preview','history','edit'] as $action): $label=$t('pages.'.$action.'_action',['name'=>$name]); ?>
<a class="icon-button" href="<?= e($action==='edit'&&$slug==='home'&&$homepageEnabled?$context->base.'/cms-admin/?view=plugin-panel&plugin=homepage&route=overview':$link($action==='edit'?'editor':$action,(string)$slug)) ?>" title="<?= e($label) ?>" aria-label="<?= e($label) ?>" <?= $action==='preview'?'target="_blank" rel="noopener"':'' ?>><?= Icons::svg($action,$context->base) ?></a>
<?php endforeach; ?>
<form method="post" action="<?= e($context->url($protected?'remove':'editorial-trash')) ?>" data-pages-form data-confirm="<?= e($t('editor.confirm_remove')) ?>">
<input type="hidden" name="catalog_revision" value="<?= e($catalog['revision']) ?>"><input type="hidden" name="site_revision" value="<?= e($siteRevision) ?>"><input type="hidden" name="source_revision" value="<?= e(isset($catalog['data']['records'][$slug])?'':$item['revision']) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="key" value="<?= e($slug) ?>"><input type="hidden" name="revision" value="<?= e($item['revision']) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
<button type="submit" class="icon-button danger" title="<?= e($protected?$t('pages.required'):$t('pages.remove_action',['name'=>$name])) ?>" aria-label="<?= e($t('pages.remove_action',['name'=>$name])) ?>" <?= $protected?'disabled':'' ?>><?= Icons::svg('uninstall',$context->base) ?></button>
</form></div></td></tr>
<?php endforeach; ?></tbody></table></div>
<?= $catalogTable->footer() ?></section>
