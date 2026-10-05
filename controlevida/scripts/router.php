<?php
declare(strict_types=1);
// Local router mirrors the Apache rules: OAuth discovery at the site root and the private paths of controlevida/.
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^(?:/controlevida)?/\.well-known/(?:oauth-protected-resource|oauth-authorization-server|openid-configuration)(?:/|$)~', $path)) { require $root . '/controlevida/metadata.php'; return true; }
if (preg_match('~/\.|^/controlevida/(?:server|scripts|tests|docs|node_modules)(?:/|$)|^/controlevida/(?:package(?:-lock)?\.json|README\.md)$|\.sqlite|\.log$~', $path)) { http_response_code(404); exit; }
if ($path === '/controlevida' || $path === '/controlevida/') { require $root . '/controlevida/index.php'; return true; }
if ($path === '/' || is_file($root . $path)) return false;
http_response_code(404); echo 'Página não encontrada.';
