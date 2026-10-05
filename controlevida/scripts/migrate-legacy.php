<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/server/legacy.php';
$user = cv_query('SELECT id FROM cv_users')->fetchColumn();
if (!$user) throw new RuntimeException('Execute setup.php primeiro.');
// By default the old tables are read from the configured database; CV_LEGACY_DSN points to a separate one.
$source = getenv('CV_LEGACY_DSN') ? new PDO(getenv('CV_LEGACY_DSN'), getenv('CV_LEGACY_DB_USER') ?: null, getenv('CV_LEGACY_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) : null;
echo json_encode(cv_migrate_legacy((string)$user, $source, (int)(getenv('CV_LEGACY_USER_ID') ?: 1)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
