<?php
declare(strict_types=1);
require_once __DIR__ . '/server/setup.php';
require_once __DIR__ . '/server/legacy.php';
cv_headers();

// One-time web installer for hosts without a shell. It only answers while the app has no configuration,
// and only to whoever holds the install key whose hash is in server/install-key.php.
if (cv_config_file() !== null) { http_response_code(404); echo 'Página não encontrada.'; exit; }

$post = fn(string $key): string => is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
$expected = getenv('CV_INSTALL_KEY_SHA256') ?: (is_file(__DIR__ . '/server/install-key.php') ? (string)require __DIR__ . '/server/install-key.php' : '');
$f = ['host' => 'localhost', 'database' => '', 'db_user' => '', 'name' => 'Marcos', 'email' => '', 'import' => true];
$error = ''; $done = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        foreach (['host','database','db_user','name','email'] as $key) $f[$key] = mb_substr(trim($post($key)), 0, 190);
        $f['import'] = isset($_POST['import']);
        if (!preg_match('/^[a-f0-9]{64}$/', $expected)) cv_fail('A instalação pela web está desativada neste servidor.', 403);
        if (!hash_equals($expected, hash('sha256', trim($post('install_key'))))) { usleep(500000); cv_fail('Chave de instalação incorreta.', 403); }
        if ($post('password') !== $post('password_confirm')) cv_fail('As senhas não conferem.');
        if (!preg_match('/^[A-Za-z0-9._-]{1,120}(:\d{1,5})?$/', $f['host']) || !preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $f['database'])) cv_fail('Confira o host e o nome do banco.');
        $site = strtolower($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/^[a-z0-9.-]{1,190}(:\d{1,5})?$/', $site)) cv_fail('Endereço do site inválido.');
        $local = (bool)preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $site);
        [$configFile, $dataDir] = cv_setup_target();
        [$host, $port] = array_pad(explode(':', $f['host'], 2), 2, '');
        $dsn = getenv('CV_INSTALL_DSN') ?: 'mysql:host=' . $host . ($port !== '' ? ';port=' . $port : '') . ';dbname=' . $f['database'] . ';charset=utf8mb4';
        $dbUser = $f['db_user'] !== '' ? $f['db_user'] : null; $dbPassword = $post('db_password') !== '' ? $post('db_password') : null;
        try { new PDO($dsn, $dbUser, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]); }
        catch (PDOException $e) { cv_fail('Não foi possível conectar ao banco. Confira host, nome do banco, usuário e senha.'); }
        $config = ['url' => ($local ? 'http' : 'https') . '://' . $site . '/controlevida', 'data_dir' => $dataDir, 'dsn' => $dsn, 'db_user' => $dbUser, 'db_password' => $dbPassword];
        $uid = cv_setup($config, $configFile, $f['email'], $f['name'], $post('password'));
        $done = ['email' => strtolower($f['email']), 'report' => null, 'problem' => ''];
        if ($f['import']) {
            // The account already exists at this point; an import problem never undoes the installation.
            try { $done['report'] = cv_migrate_legacy($uid); }
            catch (DomainException $e) { $done['problem'] = $e->getMessage(); }
            catch (Throwable $e) { error_log('ControleVida install import: ' . get_class($e) . ': ' . $e->getMessage()); $done['problem'] = 'A importação encontrou um erro inesperado (' . get_class($e) . '). Nada foi perdido: repita em Ajustes > Seus dados.'; }
        }
    } catch (DomainException $e) {
        $error = $e->getMessage(); http_response_code($e->getCode() ?: 400);
    } catch (Throwable $e) {
        error_log('ControleVida install: ' . get_class($e) . ': ' . $e->getMessage());
        $error = 'Não foi possível concluir a instalação' . ($e instanceof PDOException ? ': ' . mb_substr($e->getMessage(), 0, 300) : '.'); http_response_code(500);
    }
}

$labels = ['fin_transactions' => 'lançamentos', 'finances' => 'lançamentos antigos', 'tasks' => 'tarefas', 'activities' => 'atividades', 'habits' => 'hábitos', 'routine_items' => 'itens de rotina', 'events' => 'eventos', 'workout_plan' => 'treinos da semana', 'workouts' => 'treinos', 'runs' => 'corridas', 'food_logs' => 'refeições', 'daily_notes' => 'notas', 'goals' => 'metas', 'life_rules' => 'regras pessoais'];
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#000000"><meta name="color-scheme" content="dark"><meta name="robots" content="noindex,nofollow"><title>Instalação | Controle Vida</title><link rel="icon" href="/assets/icons/icon-48.png"><link rel="stylesheet" href="assets/app.css?v=<?=filemtime(__DIR__ . '/assets/app.css')?>"></head>
<body class="login-page"><main class="login-box install-box"><div class="brand-mark">CV</div><p class="eyebrow">CONTROLE VIDA</p>
<?php if ($done): ?>
<h1>Tudo pronto</h1>
<p>Conta criada para <strong><?=cv_escape($done['email'])?></strong>. A senha fica guardada somente como hash.</p>
<?php if ($done['report']): $r = $done['report']; $invalid = array_sum(array_map('count', $r['invalid'])); ?>
<h2>Dados trazidos do app anterior</h2>
<dl class="settings-dl"><div><dt>Lançamentos financeiros</dt><dd><?=(int)$r['finance']['new_records']?></dd></div>
<?php foreach ($r['created'] as $table => $count): ?><div><dt><?=cv_escape(ucfirst($labels[$table] ?? $table))?></dt><dd><?=(int)$count?></dd></div><?php endforeach; ?></dl>
<?php if ($invalid): ?><p class="muted"><?=$invalid?> registro(s) antigo(s) com dados inválidos ficaram de fora. O banco anterior não foi alterado.</p><?php endif; ?>
<?php elseif ($done['problem'] !== ''): ?><p class="error"><?=cv_escape($done['problem'])?></p>
<?php endif; ?>
<a class="primary full" href="./">Entrar no Controle Vida</a>
<?php else: ?>
<h1>Instalação</h1>
<p class="muted">Esta página aparece uma única vez. Depois de concluir, ela deixa de existir.</p>
<form method="post" action="install.php" class="install-form" autocomplete="off">
<?php if ($error !== ''): ?><p class="error" role="alert"><?=cv_escape($error)?></p><?php endif; ?>
<label>Chave de instalação<input name="install_key" type="password" required autocomplete="off"></label>
<h2>Banco de dados</h2>
<p class="muted">Use o banco MySQL do app anterior para trazer seus dados. Os valores estão no hPanel da Hostinger, em Bancos de dados.</p>
<label>Servidor<input name="host" value="<?=cv_escape($f['host'])?>" required maxlength="126"></label>
<label>Nome do banco<input name="database" value="<?=cv_escape($f['database'])?>" required maxlength="64"></label>
<label>Usuário do banco<input name="db_user" value="<?=cv_escape($f['db_user'])?>" required maxlength="80"></label>
<label>Senha do banco<input name="db_password" type="password" autocomplete="off"></label>
<label class="check-label"><input type="checkbox" name="import" value="1" <?=$f['import'] ? 'checked' : ''?>> Trazer finanças, tarefas, hábitos e metas do app anterior</label>
<h2>Seu acesso</h2>
<label>Nome<input name="name" value="<?=cv_escape($f['name'])?>" required maxlength="100"></label>
<label>E-mail<input name="email" type="email" value="<?=cv_escape($f['email'])?>" required maxlength="190" autocomplete="username"></label>
<label>Senha<input name="password" type="password" required minlength="8" maxlength="200" autocomplete="new-password"></label>
<label>Repita a senha<input name="password_confirm" type="password" required minlength="8" maxlength="200" autocomplete="new-password"></label>
<button class="primary full" type="submit">Concluir instalação</button>
</form>
<?php endif; ?>
</main></body></html>
