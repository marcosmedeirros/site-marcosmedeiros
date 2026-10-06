<?php
declare(strict_types=1);
require_once __DIR__ . '/server/legacy.php';
cv_headers();
try {
    cv_session();
    $action = $_GET['action'] ?? 'bootstrap';
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    if (!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)) cv_fail('Método não permitido.',405);
    $input = $post ? cv_input() : $_GET;
    if ($post) cv_csrf();
    if ($action === 'session') cv_json(['ok'=>true,'data'=>['user'=>cv_user(),'csrf'=>$_SESSION['csrf']]]);
    if ($action === 'login' && $post) cv_json(['ok'=>true,'data'=>['user'=>cv_login(cv_text($input['email'] ?? '',190),cv_text($input['password'] ?? '',200),!empty($input['remember'])),'csrf'=>$_SESSION['csrf']]]);
    $user = cv_user();
    if (!$user) cv_fail('Entre para continuar.',401);
    $uid = $user['id'];
    if ($action === 'logout' && $post) {
        $_SESSION=[]; session_destroy();
        setcookie(session_name(),'',cv_cookie(1));
        cv_json(['ok'=>true]);
    }
    if ($action === 'bootstrap') cv_json(['ok'=>true,'data'=>['user'=>$user,'today'=>date('Y-m-d'),'records'=>cv_list($uid),'settings'=>cv_settings_safe(cv_settings($uid)),'csrf'=>$_SESSION['csrf'],'mcp_url'=>cv_resource()]]);
    if ($action === 'list') cv_json(['ok'=>true,'data'=>cv_list($uid,$input)]);
    if ($action === 'save' && $post) $data = cv_save($uid,$input);
    elseif ($action === 'mark' && $post) $data = cv_mark($uid,$input);
    elseif ($action === 'archive' && $post) $data = cv_mark($uid,$input,'web',true);
    elseif ($action === 'import' && $post) $data = cv_import_snapshot($uid,$input);
    elseif ($action === 'migrate' && $post) $data = cv_migrate_legacy($uid);
    elseif ($action === 'settings' && $post) {
        $data = cv_settings($uid);
        $data['initial_balance_cents'] = cv_int($input['initial_balance_cents'] ?? 0,-99999999999,99999999999);
        if (!is_array($input['categories'] ?? null) || count($input['categories']) > 80) cv_fail('Categorias inválidas.');
        $data['categories'] = array_values(array_unique(array_filter(array_map(fn($v)=>cv_text($v,80),$input['categories']))));
        cv_save_settings($uid,$data);
    }
    elseif ($action === 'link' && $post) {
        $name = cv_enum($input['name'] ?? '',['calendar','widget','atalhos']);
        if (!is_bool($input['enable'] ?? null)) cv_fail('Informe enable como verdadeiro ou falso.');
        $data = cv_settings($uid); $key = $name . '_token';
        if ($input['enable']) { if (empty($data[$key])) $data[$key] = cv_secret(); }
        else unset($data[$key]);
        cv_save_settings($uid,$data);
        cv_audit($uid,'link.' . ($input['enable'] ? 'enable' : 'revoke'),$name,'web');
    }
    elseif ($action === 'connections') $data = cv_query('SELECT id,name,scope,created_at,expires_at FROM cv_tokens WHERE user_id=? ORDER BY created_at DESC',[$uid])->fetchAll();
    elseif ($action === 'revoke' && $post) { cv_query('DELETE FROM cv_tokens WHERE id=? AND user_id=?',[cv_text($input['id'] ?? '',40),$uid]); $data = ['revoked'=>true]; }
    elseif ($action === 'audit') $data = cv_query('SELECT action,source,happened_at FROM cv_audit WHERE user_id=? ORDER BY happened_at DESC LIMIT 50',[$uid])->fetchAll();
    elseif ($action === 'export') $data = ['format'=>'controlevida-backup-v1','exported_at'=>cv_now(),'records'=>cv_list($uid,['archived'=>true]),'settings'=>cv_settings($uid)];
    else cv_fail('Ação não encontrada.',404);
    cv_json(['ok'=>true,'data'=>is_array($data) && isset($data['categories']) ? cv_settings_safe($data) : $data]);
} catch (DomainException $e) { cv_json(['ok'=>false,'error'=>$e->getMessage()],$e->getCode() ?: 400); }
catch (Throwable $e) { error_log('ControleVida API: ' . get_class($e)); cv_json(['ok'=>false,'error'=>'Não foi possível concluir. Tente novamente.'],503); }
