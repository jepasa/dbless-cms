<?php
/** Ícones SVG oficiais locais endereçados por URL; sem SVG embutido, HTML livre ou caminhos fornecidos pelo usuário. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Icons {
  private const FILES=['arrow-right', 'arrow-up-short', 'box-arrow-in-right', 'box-arrow-right', 'box-arrow-up-right', 'briefcase', 'chat-dots', 'check-circle', 'check2-circle', 'clock-history', 'code-slash', 'envelope', 'eye', 'facebook', 'file-earmark-text', 'gear', 'github', 'globe', 'globe2', 'grid', 'hdd-stack', 'house', 'image', 'images', 'instagram', 'kanban', 'link-45deg', 'linkedin', 'list', 'list-ul', 'palette', 'pencil', 'person', 'person-circle', 'person-fill', 'shield-check', 'toggle-off', 'toggle-on', 'trash', 'twitter', 'twitter-x', 'whatsapp', 'x', 'youtube'];
  private const ALIASES=['settings'=>'gear','plugins'=>'grid','templates'=>'palette','activate'=>'toggle-on','deactivate'=>'toggle-off','uninstall'=>'trash','configure'=>'gear','logout'=>'box-arrow-right','login'=>'box-arrow-in-right','edit'=>'pencil','preview'=>'eye','history'=>'clock-history','menu'=>'list-ul','pages'=>'file-earmark-text','image'=>'image','external'=>'box-arrow-up-right'];
  /** Resolve somente nome conhecido para SVG local; recebe nome/base/template opcional, retorna URL escapável ou InvalidArgumentException, sem escrita. */
  public static function url(string $name,string $base='',?string $template=null): string {
    $file=self::ALIASES[$name]??$name;
    if (!preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$file)) throw new \InvalidArgumentException('Ícone desconhecido.');
    if (!in_array($file,self::FILES,true)) throw new \InvalidArgumentException('Ícone desconhecido.');
    if ($template!==null&&!Templates::identifier($template)) throw new \InvalidArgumentException('Template de ícone inválido.');
    return asset(($template===null?'cms-admin/assets':'templates/'.$template).'/icons/'.$file.'.svg',$base);
  }
  /** Retorna imagem SVG decorativa externa; recebe nome/base/classes estáticas/template opcional, não inclui SVG no HTML e propaga ícone desconhecido. */
  public static function svg(string $name,string $base='',string $class='admin-icon',?string $template=null): string {
    return '<img class="'.e($class.' icon-'.$name).'" src="'.e(self::url($name,$base,$template)).'" width="24" height="24" alt="" aria-hidden="true">';
  }
  /** Renderiza ícone editorial seguro com fallback; recebe identificador bi-/base/classes estáticas/template e retorna img sem executar conteúdo. */
  public static function site(string $name,string $base,string $class='site-icon',string $template=Templates::DEFAULT_ID): string {
    $name=preg_replace('/^bi-/','',trim($name));
    try {return self::svg($name,$base,$class,$template);}
    catch (\InvalidArgumentException) {return self::svg('link-45deg',$base,$class,$template);}
  }
}
