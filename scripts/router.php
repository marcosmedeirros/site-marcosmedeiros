<?php
declare(strict_types=1);
// Local router mirrors Apache's private paths and metadata routes.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (preg_match('~/(\.|server/|scripts/|tests/|node_modules/)~',$path) && !str_starts_with($path,'/.well-known/')) { http_response_code(404); exit; }
if (str_starts_with($path,'/.well-known/')) { require dirname(__DIR__) . '/controlevida/metadata.php'; return true; }
if ($path==='/') { header('Location: /controlevida/'); return true; }
if ($path==='/controlevida' || $path==='/controlevida/') { require dirname(__DIR__) . '/controlevida/index.php'; return true; }
if (is_file(dirname(__DIR__) . $path)) return false;
http_response_code(404); echo 'Pagina nao encontrada.';
