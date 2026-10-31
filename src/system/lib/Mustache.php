<?php
/** Bounded data-only Mustache AST: escaped variables, sections/inverted sections, lexical contexts and no executable helpers or recursive expansion. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Mustache {
  /** Validate/parse bounded source into an AST; receive source, return nodes or 422 on unsupported/unbalanced syntax, no I/O. */
  public static function parse(string $source): array {
    if(strlen($source)>131072||preg_match('//u',$source)!==1)throw new HttpError(422,'Invalid markup size/encoding.','markup.error');
    $position=0;$tokens=0;$nodes=self::nodes($source,$position,$tokens,0,null);return $nodes;
  }
  /** Recursively parse a bounded section; receive source and parser counters/current section, return AST; reject unsupported features and mismatched closures. */
  private static function nodes(string $source,int &$position,int &$tokens,int $depth,?string $section): array {
    if($depth>8)throw new HttpError(422,'Mustache nesting exceeds limit.','markup.error');$nodes=[];$length=strlen($source);
    while($position<$length){
      $start=strpos($source,'{{',$position);if($start===false){$nodes[]=['text',substr($source,$position)];$position=$length;break;}
      if($start>$position)$nodes[]=['text',substr($source,$position,$start-$position)];$end=strpos($source,'}}',$start+2);
      if($end===false||$end-$start>512||++$tokens>1024)throw new HttpError(422,'Invalid Mustache token.','markup.error');
      $tag=trim(substr($source,$start+2,$end-$start-2));$position=$end+2;if(str_starts_with($tag,'!'))continue;
      if(!preg_match('/^([#^\/]?)(\.|[a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]*)*)$/D',$tag,$parts))throw new HttpError(422,'Unsupported Mustache syntax.','markup.error');
      [$unused,$operator,$name]=$parts;
      if($operator==='/'){if($section!==$name)throw new HttpError(422,'Mismatched Mustache section.','markup.error');return $nodes;}
      $nodes[]=in_array($operator,['#','^'],true)?[$operator,$name,self::nodes($source,$position,$tokens,$depth+1,$name)]:['value',$name];
    }
    if($section!==null)throw new HttpError(422,'Unclosed Mustache section.','markup.error');return $nodes;
  }
  /** Resolve JSON-only lexical paths; receive path/context stack, return scalar/array/null, never access objects, callables or arbitrary PHP properties. */
  private static function value(string $path,array $stack): mixed {
    if($path==='.')return end($stack);$keys=explode('.',$path);
    for($i=count($stack)-1;$i>=0;$i--){$value=$stack[$i];if(!is_array($value)||!array_key_exists($keys[0],$value))continue;foreach($keys as $key){if(!is_array($value)||!array_key_exists($key,$value))return null;$value=$value[$key];}return $value;}
    return null;
  }
  /** Evaluate Mustache presence while keeping numeric zero visible; receive JSON value, return bool without effects. */
  private static function present(mixed $value): bool {return $value!==null&&$value!==false&&$value!==[]&&$value!=='';}
  /** Render bounded AST with escaped scalar output; receive nodes/context/counters, return HTML text or 422 on expansion/iteration limits, no I/O or data reparsing. */
  private static function evaluate(array $nodes,array $stack,int &$iterations,int &$bytes): string {
    $output='';foreach($nodes as $node){
      if(++$iterations>10000)throw new HttpError(422,'Mustache work limit exceeded.','markup.error');$piece='';
      if($node[0]==='text')$piece=$node[1];
      else{
        $value=self::value($node[1],$stack);$present=self::present($value);
        if($node[0]==='value')$piece=is_bool($value)?($value?'true':'false'):(is_string($value)||is_int($value)||is_float($value)?e((string)$value):'');
        elseif($node[0]==='^'&&!$present)$piece=self::evaluate($node[2],$stack,$iterations,$bytes);
        elseif($node[0]==='#'&&$present){
          $items=is_array($value)&&array_is_list($value)?$value:[$value];if(count($items)>100)throw new HttpError(422,'Mustache list exceeds limit.','markup.error');
          foreach($items as $item)$piece.=self::evaluate($node[2],[...$stack,$item],$iterations,$bytes);
        }
      }
      // Count emitted leaf bytes once; parent concatenation never expands the budget twice.
      if($node[0]==='text'||$node[0]==='value'){$bytes+=strlen($piece);if($bytes>524288)throw new HttpError(422,'Mustache output exceeds limit.','markup.error');}$output.=$piece;
    }return $output;
  }
  /** Render JSON data through the safe subset; receive source/context, return escaped expansion, preserve literal provider values and reject excessive work. */
  public static function render(string $source,array $data): string {$iterations=0;$bytes=0;return self::evaluate(self::parse($source),[$data],$iterations,$bytes);}
}
