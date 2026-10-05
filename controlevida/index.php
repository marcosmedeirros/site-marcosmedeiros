<?php
declare(strict_types=1);
require_once __DIR__ . '/server/core.php';
cv_headers();
$ready=true; $return='';
try {
    cv_session();
    if (cv_user() && isset($_SESSION['oauth_return'])) {
        $return=$_SESSION['oauth_return']; unset($_SESSION['oauth_return']);
        if (str_starts_with($return,'/controlevida/oauth.php?')) { header('Location: '.$return); exit; }
    }
} catch(Throwable $e) { $ready=false; http_response_code(503); }
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="theme-color" content="#000000"><meta name="color-scheme" content="dark"><meta name="robots" content="noindex,nofollow"><title>Controle Vida</title><link rel="icon" href="/assets/icons/icon-48.png"><link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png"><link rel="manifest" href="manifest.webmanifest"><link rel="stylesheet" href="assets/app.css?v=<?=filemtime(__DIR__.'/assets/app.css')?>"><script src="assets/vendor/lucide.min.js" defer></script><script src="assets/app.js?v=<?=filemtime(__DIR__.'/assets/app.js')?>" defer></script></head>
<body>
<?php if(!$ready): ?><main class="login-box"><div class="brand-mark">CV</div><h1>Controle Vida</h1><p>A configuração do servidor está em andamento.</p><p class="muted">Seus dados ficam disponíveis depois que a instalação for concluída.</p></main><?php else: ?>
<div id="boot" class="boot" role="status">Abrindo seu dia...</div>
<main id="login" class="login-page" hidden><section class="login-box"><img class="profile-logo" src="/assets/icons/icon-192.png" alt="Marcos Medeiros" width="48" height="48"><p class="eyebrow">SEU ESPAÇO PESSOAL</p><h1>Controle Vida</h1><form id="login-form"><label>E-mail<input name="email" type="email" autocomplete="username" required></label><label>Senha<span class="password-field"><input id="password" name="password" type="password" autocomplete="current-password" required minlength="8"><button class="icon-button" type="button" id="show-password" aria-label="Mostrar senha" title="Mostrar senha"><i data-lucide="eye"></i></button></span></label><p id="login-error" class="error" role="alert"></p><button class="primary full" type="submit">Entrar <i data-lucide="arrow-right"></i></button></form><p class="login-footer"><i data-lucide="lock-keyhole"></i> Acesso pessoal</p></section></main>
<div id="shell" class="shell" hidden><button id="menu-backdrop" class="menu-backdrop" aria-label="Fechar menu"></button><aside class="sidebar"><a href="#today" class="brand"><span class="brand-mark">CV</span><span>Controle Vida<small>Marcos Medeiros</small></span></a><p class="nav-label">MEU ESPAÇO</p><nav id="navigation" aria-label="Navegação principal"></nav><div class="sidebar-bottom"><button class="nav-item" id="install" hidden><i data-lucide="download"></i>Instalar app</button><button class="nav-item" data-view="settings"><i data-lucide="settings-2"></i>Ajustes e conexões</button><div class="account"><img src="/assets/icons/icon-48.png" alt="" width="32" height="32"><span>Marcos<small>Conta pessoal</small></span><button id="logout" class="icon-button" title="Sair" aria-label="Sair"><i data-lucide="log-out"></i></button></div></div></aside><div class="main-wrap"><header class="topbar"><button class="icon-button mobile-menu" id="menu-button" title="Menu" aria-label="Abrir menu" aria-expanded="false"><i data-lucide="menu"></i></button><span id="breadcrumb">Hoje</span><div class="topbar-actions"><span id="connection-status" class="connection-status"><span></span>Sincronizado</span><button class="icon-button" id="refresh" title="Atualizar" aria-label="Atualizar"><i data-lucide="refresh-cw"></i></button><button class="primary compact" id="quick-add" aria-label="Novo registro"><i data-lucide="plus"></i><span>Novo registro</span></button></div></header><main id="main" tabindex="-1"></main><footer class="app-footer">Controle Vida<span id="footer-date"></span></footer></div></div>
<dialog id="editor"><form id="record-form"><header class="dialog-header"><h2 id="editor-title">Novo registro</h2><button type="button" class="icon-button close-dialog" title="Fechar" aria-label="Fechar"><i data-lucide="x"></i></button></header><div id="editor-fields" class="dialog-content"></div><p id="form-error" class="error dialog-error" role="alert"></p><footer class="dialog-footer"><button type="button" class="secondary close-dialog">Cancelar</button><button class="primary" type="submit"><i data-lucide="check"></i>Salvar</button></footer></form></dialog>
<dialog id="quick-menu"><header class="dialog-header"><h2>O que vamos registrar?</h2><button class="icon-button close-dialog" title="Fechar" aria-label="Fechar"><i data-lucide="x"></i></button></header><div id="quick-options" class="quick-options"></div></dialog>
<div id="toast" role="status" class="toast" hidden></div>
<?php endif; ?>
</body></html>
