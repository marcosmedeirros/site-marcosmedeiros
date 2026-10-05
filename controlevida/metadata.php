<?php
declare(strict_types=1);
require_once __DIR__ . '/server/core.php';
cv_headers();
header('Access-Control-Allow-Origin: *');
$path = parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (isset($_GET['resource']) || str_contains($path,'oauth-protected-resource')) {
    cv_json(['resource'=>cv_resource(),'authorization_servers'=>[cv_url()],'scopes_supported'=>['read','write'],'bearer_methods_supported'=>['header'],'resource_name'=>'Controle Vida']);
}
cv_json(['issuer'=>cv_url(),'authorization_endpoint'=>cv_url().'/oauth.php?route=authorize','token_endpoint'=>cv_url().'/oauth.php?route=token','registration_endpoint'=>cv_url().'/oauth.php?route=register','response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code','refresh_token'],'code_challenge_methods_supported'=>['S256'],'token_endpoint_auth_methods_supported'=>['none'],'scopes_supported'=>['read','write']]);
