<?php
/** Shared read-only overview query, stable column sorting and accessible controls; modules supply trusted projections/routes, never callbacks or storage paths from query input. */
declare(strict_types=1);
namespace DBLESSCMS;
final class AdminTable {
  public readonly array $rows;
  private Language $language;
  private array $query;
  private int $total;
  private int $page;
  private int $pages;
  private string $sort;
  private string $direction;
  private array $names;
  private static bool $assets=false;
  /** Filter/sort/slice authenticated rows; receive trusted column definitions (label/value/type), fixed route parameters, locale/base and optional states/query names/page size/defaults; returns instance or 400/422 on invalid query, never writes. */
  public function __construct(array $rows,private array $columns,private array $route,string $locale,private string $base='',private array $options=[]) {
    $this->language=new Language();$this->language->select($locale);$this->names=($options['names']??[])+['search'=>'search','status'=>'status','sort'=>'sort','direction'=>'direction','page'=>'table_page'];$this->query=[];
    foreach($this->names as $kind=>$name){$value=$_GET[$name]??'';if(!is_string($value)||strlen($value)>($kind==='search'?128:64)||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1F\x7F]/',$value))$this->invalid();$this->query[$name]=$value;}
    $this->sort=$this->query[$this->names['sort']]?:($options['sort']??array_key_first($columns));if(!isset($columns[$this->sort]['value']))$this->invalid();
    $this->direction=$this->query[$this->names['direction']]?:($options['direction']??'asc');if(!in_array($this->direction,['asc','desc'],true))$this->invalid();
    $status=$this->query[$this->names['status']];if($status==='all')$status='';if($status!==''&&!array_key_exists($status,$options['states']??[]))$this->invalid();$this->query[$this->names['status']]=$status;
    $needle=$this->query[$this->names['search']];$filtered=[];
    foreach($rows as $id=>$row){$values=[];foreach($columns as $key=>$column)if(isset($column['value'])){$value=($column['value'])($row,$id);if(!is_scalar($value)&&$value!==null)throw new \LogicException('Table projections must be scalar.');$values[$key]=$value??'';}
      $state=isset($options['state'])?($options['state'])($row,$id):'';$text=isset($options['search'])?($options['search'])($row,$id):implode(' ',[...array_values($values),(string)$id]);
      if(($status!==''&&$state!==$status)||($needle!==''&&preg_match('/'.preg_quote($needle,'/').'/iu',(string)$text)!==1))continue;$filtered[$id]=['row'=>$row,'value'=>$values[$this->sort]];
    }
    $direction=$this->direction==='asc'?1:-1;$numeric=($columns[$this->sort]['type']??'text')==='number';
    uksort($filtered,static function(string|int $a,string|int $b)use(&$filtered,$direction,$numeric):int{$first=$filtered[$a]['value'];$second=$filtered[$b]['value'];return $direction*($numeric?($first<=>$second):strnatcasecmp((string)$first,(string)$second))?:strcmp((string)$a,(string)$b);});
    $this->total=count($filtered);$size=$options['pageSize']??25;if(!is_int($size)||$size<1||$size>100)throw new \LogicException('Invalid overview page size.');$this->pages=max(1,(int)ceil($this->total/$size));$number=$this->query[$this->names['page']];if($number!==''&&!preg_match('/^[1-9][0-9]{0,5}$/D',$number))$this->invalid();$this->page=min($this->pages,max(1,(int)$number));$this->rows=array_map(static fn(array $entry):array=>$entry['row'],array_slice($filtered,($this->page-1)*$size,$size,true));
    $this->query[$this->names['sort']]=$this->sort;$this->query[$this->names['direction']]=$this->direction;
  }
  /** Raise a localized bounded-query error; no inputs/output/effects beyond HttpError using the module-compatible status. */
  private function invalid(): never {throw new HttpError($this->options['errorStatus']??400,'Invalid table query.','error.parameter');}
  /** Translate common controls; receive closed key/parameters, return plain text without storage access. */
  private function text(string $key,array $parameters=[]): string {return $this->language->text('table.'.$key,$parameters);}
  /** Build a fixed local catalog link; receive trusted overrides, return URL preserving current validated controls and route, never echo arbitrary GET parameters. */
  public function url(array $overrides=[]): string {return $this->base.'/cms-admin/?'.http_build_query(array_replace($this->route,$this->query,[$this->names['page']=>$this->page],$overrides));}
  /** Emit addressed shared assets once per response; receive base, return trusted tags, never inline code or change persistent state. */
  public static function assets(string $base=''): string {if(self::$assets)return '';self::$assets=true;return '<link rel="stylesheet" href="'.e(asset('cms-admin/assets/tables.css',$base)).'"><script defer src="'.e(asset('cms-admin/assets/tables.js',$base)).'"></script>';}
  /** Build one contextual icon link; receive fixed route/icon/translated label/base and optional new-tab flag, return escaped trusted markup without I/O. */
  public static function action(string $url,string $icon,string $label,string $base='',bool $blank=false): string {return '<a class="icon-button"'.($blank?' target="_blank" rel="noopener noreferrer"':'').' href="'.e($url).'" title="'.e($label).'" aria-label="'.e($label).'">'.Icons::svg($icon,$base).'</a>';}
  /** Render one GET search/state bar; optional trusted action markup comes from the owning module, return escaped controls with translated icon titles, no write. */
  public function toolbar(string $actions=''): string {
    $html=self::assets($this->base).'<form method="get" class="catalog-toolbar" data-catalog-filter><div class="catalog-actions">'.$actions.'</div><div class="catalog-search">';
    foreach(array_replace($this->route,[$this->names['sort']=>$this->sort,$this->names['direction']=>$this->direction]) as $key=>$value)$html.='<input type="hidden" name="'.e($key).'" value="'.e((string)$value).'">';
    $html.='<label><span class="sr-only">'.e($this->text('search')).'</span><input type="search" name="'.e($this->names['search']).'" maxlength="128" placeholder="'.e($this->text('search')).'" value="'.e($this->query[$this->names['search']]).'"></label><label><span class="sr-only">'.e($this->text('status')).'</span><select name="'.e($this->names['status']).'" title="'.e($this->text('status')).'" aria-label="'.e($this->text('status')).'"'.(($this->options['states']??[])===[]?' disabled':'').'><option value="">'.e($this->text('all')).'</option>';
    foreach($this->options['states']??[] as $key=>$label)$html.='<option value="'.e($key).'"'.($this->query[$this->names['status']]===$key?' selected':'').'>'.e($label).'</option>';
    $clear=array_replace($this->route,[$this->names['sort']=>$this->sort,$this->names['direction']=>$this->direction]);foreach($this->options['clearRoute']??[] as $key)unset($clear[$key]);
    return $html.'</select></label><a class="icon-button" href="'.e($this->base.'/cms-admin/?'.http_build_query($clear)).'" title="'.e($this->text('clear')).'" aria-label="'.e($this->text('clear')).'">'.Icons::svg('clear',$this->base).'</a><button type="submit" class="icon-button" title="'.e($this->text('search')).'" aria-label="'.e($this->text('search')).'">'.Icons::svg('search',$this->base).'</button></div></form>';
  }
  /** Render all declared headings; no input, return escaped sortable links/aria-sort and a right-hand direction icon; Actions remains plain. */
  public function headings(): string {
    $html='';foreach($this->columns as $key=>$column){$active=$this->sort===$key;$sort=$active?($this->direction==='asc'?'ascending':'descending'):'none';$html.='<th scope="col"'.(isset($column['value'])?' aria-sort="'.$sort.'"':'').'>';
      if(isset($column['value'])){$direction=$active&&$this->direction==='asc'?'desc':'asc';$label=$this->text($direction==='asc'?'sortAscending':'sortDescending',['column'=>$column['label']]);$html.='<a class="catalog-sort" href="'.e($this->url([$this->names['sort']=>$key,$this->names['direction']=>$direction,$this->names['page']=>1])).'" title="'.e($label).'">'.e($column['label']).Icons::svg($active&&$this->direction==='desc'?'sort-down':'sort-up',$this->base,'admin-icon'.($active?'':' catalog-sort-idle')).'</a>';}else $html.=e($column['label']);$html.='</th>';
    }return $html;
  }
  /** Render the centered permanent pager and totals; no input, returns shared server/client state with ordinary previous/next links available without JS. */
  public function footer(): string {
    $size=$this->options['pageSize']??25;return self::pager(['page'=>$this->page,'pages'=>$this->pages,'total'=>$this->total,'count'=>count($this->rows),'start'=>$this->total?($this->page-1)*$size+1:0,'end'=>$this->total?($this->page-1)*$size+count($this->rows):0,'href'=>$this->url([$this->names['page']=>null]),'pageKey'=>$this->names['page']],$this->language->locale(),$this->base);
  }
  /** Render a reusable pager for server or asynchronous catalogs; receive bounded numeric state/optional fixed catalog URL and page key, locale/base, return initial seven controls plus escaped labels; no persistence. */
  public static function pager(array $state,string $locale,string $base=''): string {
    $language=new Language();$language->select($locale);$labels=[];foreach(['previous','next','earlier','later','page','summary','unavailable'] as $key)$labels[$key]=$language->text('table.'.$key);
    $state+=['page'=>1,'pages'=>1,'total'=>0,'count'=>0,'start'=>0,'end'=>0];$state['labels']=$labels;$state['paginationLabel']=$language->text('table.pagination');$state['icons']=['previous'=>Icons::url('previous',$base),'next'=>Icons::url('next',$base),'more'=>Icons::url('more',$base)];
    $html=self::assets($base).'<footer class="catalog-footer" data-catalog-pager data-pager="'.e(json_encode($state,JSON_THROW_ON_ERROR)).'"><nav class="catalog-pagination" aria-label="'.e($language->text('table.pagination')).'">';
    foreach([$state['page']-1,null,$state['page']-1,$state['page'],$state['page']+1,null,$state['page']+1] as $index=>$number){
      $valid=$number!==null&&$number>=1&&$number<=$state['pages'];$disabled=$state['total']===0||!$valid||$index===3;
      $label=$index===0?$labels['previous']:($index===6?$labels['next']:($index===1?$labels['earlier']:($index===5?$labels['later']:($valid?strtr($labels['page'],['{page}'=>(string)$number]):$labels['unavailable']))));
      $content=$number===null?Icons::svg('more',$base):($index===0?Icons::svg('previous',$base):($index===6?Icons::svg('next',$base):e($valid?(string)$number:'—')));
      $html.='<a class="icon-button" title="'.e($label).'" aria-label="'.e($label).'"'.(!$disabled&&isset($state['href'])?' href="'.e($state['href'].'&'.rawurlencode($state['pageKey']??'table_page').'='.(string)$number).'"':' aria-disabled="true" tabindex="-1"').($index===3?' aria-current="page"':'').'>'.$content.'</a>';
    }
    $tokens=[];foreach(['page','pages','total','count','start','end'] as $key)$tokens['{'.$key.'}']=(string)$state[$key];
    return $html.'</nav><p class="catalog-summary" data-page-info role="status">'.e(strtr($labels['summary'],$tokens)).'</p></footer>';
  }
}
