<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root=dirname(__DIR__); $path=$root.'/app/data/portfolio.json';
$pages=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
$originals=$root.'/storage/adobe-originals';
if (!is_dir($originals)) mkdir($originals,0700,true);
$converted=[];
foreach ($pages as &$page) {
    foreach ($page['images'] as &$image) convert_photo($image);
    unset($image);
    if ($page['cover']) convert_photo($page['cover']);
}
unset($page);
file_put_contents($path,json_encode($pages,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
echo 'Optimized '.count($converted)." photographs to WebP. Original copies retained in storage/adobe-originals.\n";
function convert_photo(array &$image): void {
    global $root,$originals,$converted;
    $old=$image['src'];
    if (!str_starts_with($old,'/assets/portfolio/') || str_contains($old,'..')) throw new RuntimeException('Unexpected image path');
    if (str_ends_with($old,'.webp')) return;
    $new=preg_replace('/\.[^.]+$/','.webp',$old);
    if (!isset($converted[$old])) {
        $bytes=file_get_contents($root.'/public'.$old);
        $im=imagecreatefromstring($bytes);
        if (!$im) throw new RuntimeException('Cannot decode photograph');
        imagepalettetotruecolor($im); imagealphablending($im,true); imagesavealpha($im,true);
        if (!imagewebp($im,$root.'/public'.$new,83)) throw new RuntimeException('Cannot optimize photograph');
        imagedestroy($im);
        rename($root.'/public'.$old,$originals.'/'.basename($old));
        $converted[$old]=$new;
    }
    $image['src']=$new;
}
