<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/server/setup.php';
try {
    [$configFile, $dataDir] = cv_setup_target();
    $url = rtrim(getenv('CV_URL') ?: '', '/');
    if (!filter_var($url, FILTER_VALIDATE_URL)) cv_fail('Defina CV_ADMIN_EMAIL, CV_ADMIN_PASSWORD (8+ caracteres) e CV_URL.');
    $config = ['url' => $url, 'data_dir' => $dataDir, 'dsn' => getenv('CV_DSN') ?: 'sqlite:' . $dataDir . '/controlevida.sqlite', 'db_user' => getenv('CV_DB_USER') ?: null, 'db_password' => getenv('CV_DB_PASSWORD') ?: null];
    if (!is_dir($dataDir)) mkdir($dataDir, 0700, true);
    cv_setup($config, $configFile, getenv('CV_ADMIN_EMAIL') ?: '', getenv('CV_ADMIN_NAME') ?: 'Marcos', getenv('CV_ADMIN_PASSWORD') ?: '');
    echo "Conta criada. Senha armazenada somente como hash.\n";
} catch (DomainException $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
