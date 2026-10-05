<?php
declare(strict_types=1);
require_once __DIR__ . '/records.php';

// Shared by the web installer (install.php) and the CLI (scripts/setup.php).

function cv_setup_target(): array {
    $env = getenv('CV_CONFIG');
    if ($env) return [$env, getenv('CV_DATA_DIR') ?: dirname($env) . '/data'];
    foreach (cv_config_paths() as $file) {
        $dir = dirname($file);
        if ((@is_dir($dir) || @mkdir($dir, 0700, true)) && @is_writable($dir)) return [$file, getenv('CV_DATA_DIR') ?: $dir];
    }
    cv_fail('O servidor não permite gravar a configuração. Crie a pasta controlevida/.private com permissão de escrita.', 500);
}

// Creates the tables and the account first; the config file is written last so a failure leaves nothing half-installed.
function cv_setup(array $config, string $configFile, string $email, string $name, string $password): string {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) cv_fail('Informe um e-mail válido.');
    if (strlen($password) < 8 || strlen($password) > 200) cv_fail('A senha precisa ter pelo menos 8 caracteres.');
    $name = mb_substr(trim($name), 0, 100) ?: 'Marcos';
    if (cv_config_file() !== null || is_file($configFile)) cv_fail('O Controle Vida já está configurado.', 409);
    if (!is_dir($config['data_dir']) && !@mkdir($config['data_dir'], 0700, true)) cv_fail('Não foi possível criar a pasta de dados.', 500);
    // Inside public_html the folder is already blocked by controlevida/.htaccess; this is a second lock.
    @file_put_contents($config['data_dir'] . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    cv_config($config);
    cv_schema();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $uid = cv_query('SELECT id FROM cv_users ORDER BY id LIMIT 1')->fetchColumn();
    // Reinstalling over existing tables keeps the records and only resets the sign-in.
    if ($uid) cv_query('UPDATE cv_users SET email=?,name=?,password_hash=? WHERE id=?', [$email, $name, $hash, $uid]);
    else { $uid = cv_id(); cv_query('INSERT INTO cv_users (id,email,name,password_hash) VALUES (?,?,?,?)', [$uid, $email, $name, $hash]); }
    cv_save_settings($uid, cv_settings($uid));
    if (file_put_contents($configFile, "<?php\nreturn " . var_export($config, true) . ";\n", LOCK_EX) === false) cv_fail('Não foi possível gravar a configuração.', 500);
    @chmod($configFile, 0600);
    return (string)$uid;
}
