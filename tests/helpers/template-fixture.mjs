/** Original alternate-template fixture, created only inside disposable applications from the generic Essencial bundle; never installed into source or export. */
import fs from 'node:fs';
import path from 'node:path';
/** Clone Essencial into a disposable app; receive app root/optional ID, return ID, never read private content, reject existing destinations and propagate filesystem/JSON errors. */
export function installTemplate(application,id='synthetic-theme') {
  if(!/^[a-z][a-z0-9-]{0,63}$/.test(id))throw new Error('Invalid fixture ID.');
  for(const surface of ['system','public_html']){const target=path.join(application,surface,'templates',id);if(fs.existsSync(target))throw new Error('Fixture destination exists.');fs.cpSync(path.join(application,surface,'templates/essencial'),target,{recursive:true});}
  const directory=path.join(application,'system/templates',id),file=path.join(directory,'template.json'),manifest=JSON.parse(fs.readFileSync(file));manifest.id=id;manifest.name=id;delete manifest.configuration;delete manifest.themeFields;if(manifest.mustacheSlots)delete manifest.mustacheSlots['primary-menu'];manifest.slideLocations={};for(const [key,slot] of Object.entries(manifest.mustacheSlots||{}))if(slot.plugin==='slides')delete manifest.mustacheSlots[key];manifest.menuLocations={'sidebar-primary':{name:'Navegação lateral',description:'Synthetic sidebar placement.',types:['sidebar'],defaultType:'sidebar',maxDepth:3},'footer-links':manifest.menuLocations['footer-links']};
  for(const [key,slot] of Object.entries(manifest.mustacheSlots||{}))if(slot.plugin==='menu'&&!manifest.menuLocations[slot.location])delete manifest.mustacheSlots[key];manifest.mustacheSlots['synthetic-sidebar']={plugin:'menu',location:'sidebar-primary',description:'Synthetic sidebar'};fs.writeFileSync(file,JSON.stringify(manifest,null,2)+'\n');
  const layout=path.join(directory,'partials/layout.php');fs.writeFileSync(layout,fs.readFileSync(layout,'utf8').replace("$appearance=$templateSettings['appearance'];","$appearance=array_replace(['palette'=>'forest','mode'=>'light','direction'=>'auto','wide'=>false,'fontSize'=>'medium','textAlign'=>'start'],$templateSettings['appearance']??[]);"));
  fs.writeFileSync(path.join(directory,'partials/header.php'),`<?php
/** Synthetic sidebar placement observer; renders validated menu data through the generic partial without persistence. */
declare(strict_types=1);
$menuData=$navigation->location('sidebar-primary');
?>
<header class="site-header"><div class="container"><?php if($menuData!==null)require $store->templates->path($id,'partials',$manifest['partials']['menu']); ?></div></header>
`);
  return id;
}
