<?php
declare(strict_types=1);
$portfolio = json_decode(file_get_contents(__DIR__.'/data/portfolio.json'),true,512,JSON_THROW_ON_ERROR);
$portfolioBySlug = array_column($portfolio,null,'slug');
$gallerySlugs = ['brand','muzsky-portret-2','portret','tehotenstvi','auta','parove-foceni','rodina'];
$galleries = array_values(array_filter($portfolio,fn(array $p): bool => in_array($p['slug'],$gallerySlugs,true)));
$gallery = isset($_GET['gallery']) ? ($portfolioBySlug[(string)$_GET['gallery']] ?? null) : null;
$contentPage = isset($_GET['page']) ? ($portfolioBySlug[(string)$_GET['page']] ?? null) : null;
if ((isset($_GET['gallery']) && (!$gallery || !in_array($gallery['slug'],$gallerySlugs,true))) || (isset($_GET['page']) && (!$contentPage || !in_array($contentPage['slug'],['o-me','contact','blog'],true)))) { http_response_code(404); $notFound=true; }
function clean_portfolio_text(string $text): string { return trim((string)preg_replace('/\n(?:↑|\(c\)|Domů).*/su','',$text)); }
