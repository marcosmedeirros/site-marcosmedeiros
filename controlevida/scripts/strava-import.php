<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/server/strava.php';
ini_set('display_errors', 'stderr');
// Lets the test exercise the mapping without talking to Strava.
if (!isset($argv[1]) || !is_file($argv[1])) throw new RuntimeException('Informe o arquivo de atividades.');
$user = cv_query('SELECT id FROM cv_users')->fetchColumn();
$activities = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
echo json_encode(cv_strava_import((string)$user, $activities), JSON_UNESCAPED_UNICODE) . "\n";
