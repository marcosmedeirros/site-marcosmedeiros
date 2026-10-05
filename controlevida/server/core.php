<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Preferred location is outside public_html; the fallback folder is blocked by controlevida/.htaccess.
function cv_config_paths(): array {
    $app = dirname(__DIR__);
    return [dirname($app, 2) . '/.controlevida/config.php', $app . '/.private/config.php'];
}

function cv_config_file(): ?string {
    $env = getenv('CV_CONFIG');
    if ($env) return is_file($env) ? $env : null;
    foreach (cv_config_paths() as $file) if (@is_file($file)) return $file;
    return null;
}

function cv_config(?array $install = null): array {
    static $config;
    if ($install !== null) $config = $install;
    if ($config !== null) return $config;
    $file = cv_config_file();
    if (!$file) throw new RuntimeException('O Controle Vida ainda precisa ser configurado no servidor.');
    $config = require $file;
    // Keeps PHP's own log out of public_html.
    if (!empty($config['data_dir']) && is_dir($config['data_dir'])) ini_set('error_log', $config['data_dir'] . '/php-errors.log');
    return $config;
}

function cv_db(): PDO {
    static $db;
    if ($db) return $db;
    $c = cv_config();
    $db = new PDO($c['dsn'], $c['db_user'] ?? null, $c['db_password'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;');
    } else {
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }
    return $db;
}

function cv_schema(): void {
    $db = cv_db();
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $long = $mysql ? 'MEDIUMTEXT' : 'TEXT';
    $tables = [
        'users' => 'id VARCHAR(40) PRIMARY KEY, email VARCHAR(190) UNIQUE NOT NULL, name VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL',
        'records' => 'id VARCHAR(40) PRIMARY KEY, user_id VARCHAR(40) NOT NULL, kind VARCHAR(24) NOT NULL, title VARCHAR(200) NOT NULL, day VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL, details ' . $long . ' NOT NULL, revision INTEGER NOT NULL, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL',
        'settings' => 'user_id VARCHAR(40) PRIMARY KEY, data ' . $long . ' NOT NULL',
        'audit' => 'id VARCHAR(40) PRIMARY KEY, user_id VARCHAR(40) NOT NULL, action VARCHAR(50) NOT NULL, record_id VARCHAR(40) NOT NULL, source VARCHAR(20) NOT NULL, happened_at VARCHAR(30) NOT NULL',
        'limits' => 'id VARCHAR(40) PRIMARY KEY, bucket VARCHAR(64) NOT NULL, expires_at BIGINT NOT NULL',
        'clients' => 'id VARCHAR(80) PRIMARY KEY, name VARCHAR(100) NOT NULL, redirects TEXT NOT NULL, created_at BIGINT NOT NULL',
        'codes' => 'hash VARCHAR(64) PRIMARY KEY, user_id VARCHAR(40) NOT NULL, client_id VARCHAR(80) NOT NULL, redirect_uri TEXT NOT NULL, challenge VARCHAR(128) NOT NULL, scope VARCHAR(100) NOT NULL, resource TEXT NOT NULL, expires_at BIGINT NOT NULL',
        'tokens' => 'id VARCHAR(40) PRIMARY KEY, user_id VARCHAR(40) NOT NULL, client_id VARCHAR(80) NOT NULL, name VARCHAR(100) NOT NULL, access_hash VARCHAR(64) UNIQUE NOT NULL, refresh_hash VARCHAR(64) UNIQUE NOT NULL, scope VARCHAR(100) NOT NULL, resource TEXT NOT NULL, expires_at BIGINT NOT NULL, refresh_expires_at BIGINT NOT NULL, created_at BIGINT NOT NULL'
    ];
    $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    foreach ($tables as $name => $columns) $db->exec("CREATE TABLE IF NOT EXISTS cv_$name ($columns)$suffix");
}

function cv_query(string $sql, array $values = []): PDOStatement {
    $stmt = cv_db()->prepare($sql);
    $stmt->execute($values);
    return $stmt;
}

function cv_id(): string { return bin2hex(random_bytes(16)); }
function cv_now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function cv_url(): string { return rtrim(cv_config()['url'], '/'); }
function cv_resource(): string { return cv_url() . '/mcp.php'; }
function cv_secret(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
function cv_fail(string $message, int $status = 400): void { throw new DomainException($message, $status); }
function cv_escape(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function cv_headers(): void {
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
}

function cv_json($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function cv_input(): array {
    $raw = file_get_contents('php://input', false, null, 0, 5242881);
    if (strlen($raw) > 5242880) cv_fail('Arquivo muito grande.', 413);
    try { $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { cv_fail('JSON inválido.'); }
    if (!is_array($data)) cv_fail('Envie um objeto JSON.');
    return $data;
}

const CV_REMEMBER_SECONDS = 5184000; // 60 dias

function cv_cookie(int $expires = 0): array {
    return ['expires' => $expires, 'path' => '/controlevida', 'secure' => str_starts_with(cv_url(), 'https://'), 'httponly' => true, 'samesite' => 'Lax'];
}

function cv_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $c = cv_config();
    $path = $c['data_dir'] . '/sessions';
    if (!is_dir($path)) mkdir($path, 0700, true);
    session_save_path($path);
    ini_set('session.use_strict_mode', '1');
    // Files must outlive a remembered sign-in; cv_user() enforces the real limits.
    ini_set('session.gc_maxlifetime', (string)CV_REMEMBER_SECONDS);
    session_name('CONTROLEVIDA');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/controlevida', 'secure' => str_starts_with(cv_url(), 'https://'), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = cv_secret();
}

function cv_user(): ?array {
    cv_session();
    if (!isset($_SESSION['user_id'])) return null;
    $remember = !empty($_SESSION['remember']);
    if (time() - ($_SESSION['last_seen'] ?? 0) > ($remember ? 2592000 : 7200) || time() - ($_SESSION['started'] ?? 0) > ($remember ? CV_REMEMBER_SECONDS : 43200)) {
        $_SESSION = ['csrf' => cv_secret()];
        return null;
    }
    $_SESSION['last_seen'] = time();
    return cv_query('SELECT id,email,name FROM cv_users WHERE id=?', [$_SESSION['user_id']])->fetch() ?: null;
}

function cv_csrf(?string $value = null): void {
    cv_session();
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $u = parse_url(cv_url());
    $expected = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    if ($origin !== '' && $origin !== $expected) cv_fail('Origem não autorizada.', 403);
    if (!hash_equals($_SESSION['csrf'], $value ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) cv_fail('Sessão expirada. Atualize a página.', 403);
}

function cv_limit(string $name, int $max, int $seconds): void {
    $key = hash('sha256', $name . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
    cv_query('DELETE FROM cv_limits WHERE expires_at < ?', [time()]);
    cv_query('INSERT INTO cv_limits (id,bucket,expires_at) VALUES (?,?,?)', [cv_id(), $key, time() + $seconds]);
    if ((int)cv_query('SELECT COUNT(*) FROM cv_limits WHERE bucket=?', [$key])->fetchColumn() > $max) cv_fail('Muitas tentativas. Aguarde alguns minutos.', 429);
}

function cv_login(string $email, string $password, bool $remember = false): array {
    cv_limit('login', 10, 900);
    $user = cv_query('SELECT * FROM cv_users WHERE email=?', [strtolower(trim($email))])->fetch();
    $valid = password_verify($password, $user['password_hash'] ?? '$2y$10$C9QQzgahmmYW2j.uNGMTqeg.CRo4xpnh.w/r/WFbHvukAflbk/uKS');
    if (!$user || !$valid) cv_fail('E-mail ou senha incorretos.', 401);
    cv_session();
    $oauth = array_intersect_key($_SESSION, array_flip(['oauth_pending','oauth_return','oauth_request']));
    session_regenerate_id(true);
    $_SESSION = ['user_id' => $user['id'], 'csrf' => cv_secret(), 'started' => time(), 'last_seen' => time(), 'remember' => $remember] + $oauth;
    if ($remember) { header_remove('Set-Cookie'); setcookie(session_name(), session_id(), cv_cookie(time() + CV_REMEMBER_SECONDS)); }
    return ['id' => $user['id'], 'email' => $user['email'], 'name' => $user['name']];
}

function cv_token(string $required = 'read'): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) foreach (getallheaders() as $name => $value) if (strcasecmp($name, 'Authorization') === 0) $header = $value;
    if (!preg_match('/^Bearer ([A-Za-z0-9_-]{30,})$/', $header, $m)) cv_fail('Autenticação necessária.', 401);
    $token = cv_query('SELECT * FROM cv_tokens WHERE access_hash=? AND expires_at>?', [hash('sha256', $m[1]), time()])->fetch();
    if (!$token || $token['resource'] !== cv_resource()) cv_fail('Conexão expirada ou revogada.', 401);
    if (!in_array($required, explode(' ', $token['scope']), true)) cv_fail('Permissão insuficiente.', 403);
    return $token;
}

function cv_audit(string $user, string $action, string $record, string $source): void {
    cv_query('INSERT INTO cv_audit (id,user_id,action,record_id,source,happened_at) VALUES (?,?,?,?,?,?)', [cv_id(),$user,$action,$record,$source,cv_now()]);
}

function cv_settings(string $user): array {
    $raw = cv_query('SELECT data FROM cv_settings WHERE user_id=?', [$user])->fetchColumn();
    return $raw ? json_decode($raw, true, 64, JSON_THROW_ON_ERROR) : ['initial_balance_cents' => 0, 'categories' => ['Alimentação','Moradia','Transporte','Saúde','Lazer','Trabalho','Outros']];
}

function cv_save_settings(string $user, array $data): void {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (cv_query('SELECT user_id FROM cv_settings WHERE user_id=?', [$user])->fetchColumn()) cv_query('UPDATE cv_settings SET data=? WHERE user_id=?', [$json, $user]);
    else cv_query('INSERT INTO cv_settings (user_id,data) VALUES (?,?)', [$user,$json]);
}
