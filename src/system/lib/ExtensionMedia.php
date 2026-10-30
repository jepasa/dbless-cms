<?php
/** Validate package-owned SVG icons and 16:9 PNG previews without executing markup or relying on upload plugins. */
declare(strict_types=1);
namespace DBLESSCMS;
final class ExtensionMedia {
  /** Read bounded regular media; receives a previously confined path/limit, returns bytes or throws without exposing file content. */
  private static function bytes(string $file,int $limit): string {
    if (is_link($file) || !is_file($file) || filesize($file)>$limit) throw new \RuntimeException('Invalid package media.');
    $bytes=file_get_contents($file);
    if ($bytes===false || $bytes==='') throw new \RuntimeException('Unavailable package media.');
    return $bytes;
  }
  /** Validate a static SVG icon; receives confined file, rejects active/external markup, returns void and never renders inline SVG. */
  public static function icon(string $file): void {
    $bytes=self::bytes($file,65536);
    if (!preg_match('~<svg\b[^>]*\bxmlns=["\x27]http://www\.w3\.org/2000/svg["\x27]~i',$bytes) || !preg_match('~</svg>\s*$~i',$bytes) || preg_match('~<\?(?!xml\s)|<!DOCTYPE|<!ENTITY|<\s*(?:script|foreignObject|iframe|image|style|a|animate\w*|set)\b|\bon[a-z]+\s*=|\b(?:href|src)\s*=\s*["\x27](?!#)|url\s*\(~i',$bytes)) throw new \RuntimeException('Invalid package SVG.');
  }
  /** Validate bounded PNG framing, CRCs and 16:9 dimensions; receives confined file, returns dimensions or throws on corruption/trailing bytes, without rewriting it. */
  public static function thumbnail(string $file): array {
    $size=self::png(self::bytes($file,4194304));
    if ($size['width']<16 || $size['height']<9 || $size['width']*9!==$size['height']*16) throw new \RuntimeException('Thumbnail must be 16:9.');
    return $size;
  }
  /** Validate PNG framing/CRCs and return dimensions; receives complete bytes, rejects trailing/corrupt data without decoding or modifying image content. */
  private static function png(string $bytes): array {
    $total=strlen($bytes);$offset=8;$size=null;$data=false;$end=false;
    if (!str_starts_with($bytes,"\x89PNG\r\n\x1a\n")) throw new \RuntimeException('Invalid package PNG.');
    while ($offset<$total) {
      if ($offset+12>$total) throw new \RuntimeException('Truncated package PNG.');
      $length=unpack('N',substr($bytes,$offset,4))[1];$type=substr($bytes,$offset+4,4);
      if ($length>$total-$offset-12 || !preg_match('/^[A-Za-z]{4}$/D',$type)) throw new \RuntimeException('Invalid PNG chunk.');
      $chunk=substr($bytes,$offset+4,$length+4);
      if (!hash_equals(hash('crc32b',$chunk,true),substr($bytes,$offset+8+$length,4))) throw new \RuntimeException('Invalid PNG CRC.');
      if ($offset===8) {
        if ($type!=='IHDR' || $length!==13) throw new \RuntimeException('Missing PNG header.');
        $size=unpack('Nwidth/Nheight',substr($bytes,$offset+8,8));
        if ($size['width']<1 || $size['height']<1 || $size['width']>4096 || $size['height']>2304) throw new \RuntimeException('Invalid PNG dimensions.');
      } elseif ($type==='IHDR') throw new \RuntimeException('Duplicate PNG header.');
      if ($type==='IDAT') $data=true;
      if ($type==='IEND') {
        if ($length!==0 || !$data || $offset+12!==$total) throw new \RuntimeException('Invalid PNG ending.');
        $end=true;
      }
      $offset+=$length+12;
    }
    if (!$end || $size===null) throw new \RuntimeException('Incomplete PNG.');
    return $size;
  }
  /** Validate a bounded ICO container with contiguous PNG frames; receives confined file, rejects overlap/trailing data and malformed/non-square frames without executing bytes. */
  public static function ico(string $file): void {
    $bytes=self::bytes($file,1048576);$total=strlen($bytes);
    if ($total<22 || substr($bytes,0,4)!=="\0\0\1\0") throw new \RuntimeException('Invalid ICO header.');
    $count=unpack('v',substr($bytes,4,2))[1];
    if ($count<1 || $count>16 || 6+$count*16>$total) throw new \RuntimeException('Invalid ICO directory.');
    $offset=6+$count*16;
    for ($index=0;$index<$count;$index++) {
      $entry=substr($bytes,6+$index*16,16);$width=ord($entry[0])?:256;$height=ord($entry[1])?:256;
      $frame=unpack('Vlength/Voffset',substr($entry,8,8));
      if ($entry[3]!=="\0" || $width!==$height || $frame['offset']!==$offset || $frame['length']<33 || $frame['length']>$total-$offset) throw new \RuntimeException('Invalid ICO frame.');
      $size=self::png(substr($bytes,$offset,$frame['length']));
      if ($size['width']!==$width || $size['height']!==$height) throw new \RuntimeException('ICO dimensions mismatch.');
      $offset+=$frame['length'];
    }
    if ($offset!==$total) throw new \RuntimeException('Trailing ICO data.');
  }
}
