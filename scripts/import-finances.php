<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/controlevida/server/records.php';
if (!isset($argv[1]) || !is_file($argv[1])) { fwrite(STDERR,"Informe o caminho do snapshot JSON privado.\n"); exit(1); }
$snapshot=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
$user=cv_query('SELECT id FROM cv_users')->fetchColumn();
$result=cv_import($user,$snapshot);
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n";
