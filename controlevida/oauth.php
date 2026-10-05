<?php
declare(strict_types=1);
require_once __DIR__ . '/server/records.php';
cv_headers();

function cv_issue_token(string $user,string $client,string $name,string $scope): array {
    $access=cv_secret(); $refresh=cv_secret();
    cv_query('INSERT INTO cv_tokens (id,user_id,client_id,name,access_hash,refresh_hash,scope,resource,expires_at,refresh_expires_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[cv_id(),$user,$client,$name,hash('sha256',$access),hash('sha256',$refresh),$scope,cv_resource(),time()+3600,time()+2592000,time()]);
    return ['access_token'=>$access,'refresh_token'=>$refresh,'token_type'=>'Bearer','expires_in'=>3600,'scope'=>$scope];
}

try {
    $route=$_GET['route'] ?? '';
    if ($route==='register') {
        if ($_SERVER['REQUEST_METHOD']!=='POST') cv_fail('Metodo nao permitido.',405);
        cv_limit('oauth.register',20,3600);
        $in=cv_input(); $uris=$in['redirect_uris'] ?? [];
        if (!is_array($uris) || !$uris || count($uris)>8) cv_fail('redirect_uris invalido.');
        foreach($uris as $uri) {
            $p=parse_url(cv_text($uri,2048));
            if (!$p || empty($p['host']) || isset($p['fragment']) || isset($p['user']) || !in_array($p['scheme'] ?? '',['https','http'],true)) cv_fail('Redirecionamento invalido.');
            if ($p['scheme']==='http' && !in_array($p['host'],['127.0.0.1','localhost','[::1]'],true)) cv_fail('O redirecionamento requer HTTPS.');
        }
        if (($in['token_endpoint_auth_method'] ?? 'none')!=='none') cv_fail('Este servidor usa clientes publicos com PKCE.');
        $name=cv_text($in['client_name'] ?? 'Assistente conectado',100);
        $id=cv_secret();
        cv_query('INSERT INTO cv_clients (id,name,redirects,created_at) VALUES (?,?,?,?)',[$id,$name,json_encode($uris),time()]);
        cv_json(['client_id'=>$id,'client_name'=>$name,'redirect_uris'=>$uris,'token_endpoint_auth_method'=>'none','grant_types'=>['authorization_code','refresh_token'],'response_types'=>['code'],'client_id_issued_at'=>time()],201);
    }
    if ($route==='token') {
        if ($_SERVER['REQUEST_METHOD']!=='POST') cv_fail('Metodo nao permitido.',405);
        cv_limit('oauth.token',80,900);
        $in=$_POST;
        if (($in['resource'] ?? '')!==cv_resource()) cv_fail('Recurso invalido.');
        $client=cv_query('SELECT * FROM cv_clients WHERE id=?',[$in['client_id'] ?? ''])->fetch();
        if (!$client) cv_fail('Cliente invalido.');
        cv_db()->beginTransaction();
        if (($in['grant_type'] ?? '')==='authorization_code') {
            $hash=hash('sha256',$in['code'] ?? '');
            $code=cv_query('SELECT * FROM cv_codes WHERE hash=?',[$hash])->fetch();
            $verifier=$in['code_verifier'] ?? '';
            $challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
            if (!$code || $code['expires_at']<time() || $code['client_id']!==$client['id'] || $code['redirect_uri']!==($in['redirect_uri'] ?? '') || $code['resource']!==cv_resource() || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/',$verifier) || !hash_equals($code['challenge'],$challenge)) cv_fail('Codigo invalido ou expirado.');
            if (cv_query('DELETE FROM cv_codes WHERE hash=?',[$hash])->rowCount()!==1) cv_fail('Codigo ja utilizado.');
            $result=cv_issue_token($code['user_id'],$client['id'],$client['name'],$code['scope']);
        } elseif (($in['grant_type'] ?? '')==='refresh_token') {
            $hash=hash('sha256',$in['refresh_token'] ?? '');
            $old=cv_query('SELECT * FROM cv_tokens WHERE refresh_hash=?',[$hash])->fetch();
            if (!$old || $old['refresh_expires_at']<time() || $old['client_id']!==$client['id'] || $old['resource']!==cv_resource()) cv_fail('Conexao expirada ou revogada.');
            if (cv_query('DELETE FROM cv_tokens WHERE refresh_hash=?',[$hash])->rowCount()!==1) cv_fail('Token ja utilizado.');
            $result=cv_issue_token($old['user_id'],$client['id'],$client['name'],$old['scope']);
        } else cv_fail('Tipo de autorizacao invalido.');
        cv_db()->commit();
        cv_json($result);
    }
    if ($route!=='authorize') cv_fail('Pagina nao encontrada.',404);
    cv_session();
    $in=$_SERVER['REQUEST_METHOD']==='POST' ? ($_SESSION['oauth_pending'] ?? []) : $_GET;
    $client=cv_query('SELECT * FROM cv_clients WHERE id=?',[$in['client_id'] ?? ''])->fetch();
    if (!$client || !in_array($in['redirect_uri'] ?? '',json_decode($client['redirects'],true),true)) cv_fail('Cliente ou redirecionamento invalido.');
    if (($in['resource'] ?? '')!==cv_resource() || ($in['response_type'] ?? '')!=='code' || ($in['code_challenge_method'] ?? '')!=='S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/',$in['code_challenge'] ?? '')) cv_fail('Solicitacao OAuth invalida.');
    $scopes=array_unique(explode(' ',$in['scope'] ?? 'read write'));
    if (array_diff($scopes,['read','write']) || !in_array('read',$scopes,true)) cv_fail('Permissoes invalidas.');
    if (!is_string($in['state'] ?? '') || strlen($in['state'] ?? '')>1024) cv_fail('Estado invalido.');
    if ($_SERVER['REQUEST_METHOD']!=='POST') { $_SESSION['oauth_pending']=$in; $_SESSION['oauth_request']=cv_secret(); }
    $user=cv_user();
    if (!$user) {
        $_SESSION['oauth_return']='/controlevida/oauth.php?' . http_build_query($in);
        header('Location: /controlevida/'); exit;
    }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        cv_csrf($_POST['csrf'] ?? '');
        if (!hash_equals($_SESSION['oauth_request'] ?? '',$_POST['request_id'] ?? '') || empty($_SESSION['oauth_request'])) cv_fail('Esta solicitacao mudou. Reabra a conexao para autorizar.');
        $args=['state'=>$in['state'] ?? ''];
        if (($_POST['decision'] ?? '')!=='allow') $args['error']='access_denied';
        else {
            $scope=in_array('write',$scopes,true) && isset($_POST['write']) ? 'read write' : 'read';
            $code=cv_secret();
            cv_query('DELETE FROM cv_codes WHERE expires_at<?',[time()]);
            cv_query('INSERT INTO cv_codes (hash,user_id,client_id,redirect_uri,challenge,scope,resource,expires_at) VALUES (?,?,?,?,?,?,?,?)',[hash('sha256',$code),$user['id'],$client['id'],$in['redirect_uri'],$in['code_challenge'],$scope,cv_resource(),time()+300]);
            $args['code']=$code;
        }
        unset($_SESSION['oauth_pending'],$_SESSION['oauth_return'],$_SESSION['oauth_request']);
        header('Location: '.$in['redirect_uri'].(str_contains($in['redirect_uri'],'?') ? '&' : '?').http_build_query($args)); exit;
    }
} catch (DomainException $e) {
    if (cv_db()->inTransaction()) cv_db()->rollBack();
    cv_json(['error'=>'invalid_request','error_description'=>$e->getMessage()],$e->getCode() ?: 400);
} catch (Throwable $e) {
    if (isset($client) && cv_db()->inTransaction()) cv_db()->rollBack();
    cv_json(['error'=>'server_error','error_description'=>'Nao foi possivel conectar agora.'],503);
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#000000"><meta name="color-scheme" content="dark"><title>Conectar | Controle Vida</title><link rel="stylesheet" href="assets/app.css"></head>
<body class="login-page"><main class="login-box"><div class="brand-mark">CV</div><p class="eyebrow">CONTROLE VIDA</p><h1>Conectar assistente</h1><p><strong><?=cv_escape($client['name'])?></strong> solicita acesso aos seus registros pessoais: finanças, rotina, agenda, treinos e alimentação.</p><p>Destino: <?=cv_escape(parse_url($in['redirect_uri'],PHP_URL_HOST))?></p><form method="post" action="oauth.php?route=authorize"><input type="hidden" name="csrf" value="<?=cv_escape($_SESSION['csrf'])?>"><input type="hidden" name="request_id" value="<?=cv_escape($_SESSION['oauth_request'])?>"><p>Conta: <?=cv_escape($user['email'])?></p><?php if(in_array('write',$scopes,true)): ?><label class="check-label"><input type="checkbox" name="write" value="1"> Permitir criar e atualizar registros</label><?php endif; ?><p class="muted">Você pode revogar essa conexão nos ajustes.</p><button class="primary" name="decision" value="allow">Autorizar acesso</button><button class="secondary" name="decision" value="deny">Cancelar</button></form></main></body></html>
