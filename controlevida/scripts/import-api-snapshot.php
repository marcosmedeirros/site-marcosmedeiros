<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/server/legacy.php';
if (!isset($argv[1]) || !is_file($argv[1])) throw new RuntimeException('Informe o snapshot privado.');
$snapshot = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
$user = cv_query('SELECT id FROM cv_users')->fetchColumn();
echo json_encode(cv_import_snapshot((string)$user, $snapshot) + ['coverage' => 'API snapshot; a migracao direta do banco cobre o restante'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
