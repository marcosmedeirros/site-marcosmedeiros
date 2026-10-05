<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$configFile = getenv('CV_CONFIG') ?: $root . '/.controlevida.local.php';
if (is_file($configFile)) { fwrite(STDERR,"Configuracao ja existe; nenhuma alteracao realizada.\n"); exit(1); }
$email = getenv('CV_ADMIN_EMAIL') ?: '';
$password = getenv('CV_ADMIN_PASSWORD') ?: '';
$url = rtrim(getenv('CV_URL') ?: '', '/');
if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || !filter_var($url,FILTER_VALIDATE_URL)) { fwrite(STDERR,"Defina CV_ADMIN_EMAIL, CV_ADMIN_PASSWORD (8+ caracteres) e CV_URL.\n"); exit(1); }
$dataDir = getenv('CV_DATA_DIR') ?: dirname($root) . '/.controlevida-data';
if (!is_dir($dataDir)) mkdir($dataDir,0700,true);
$config = ['url'=>$url,'data_dir'=>$dataDir,'dsn'=>getenv('CV_DSN') ?: 'sqlite:' . $dataDir . '/controlevida.sqlite','db_user'=>getenv('CV_DB_USER') ?: null,'db_password'=>getenv('CV_DB_PASSWORD') ?: null];
file_put_contents($configFile,"<?php\nreturn " . var_export($config,true) . ";\n",LOCK_EX);
chmod($configFile,0600);
require $root . '/controlevida/server/records.php';
cv_schema();
$uid=cv_id();
cv_query('INSERT INTO cv_users (id,email,name,password_hash) VALUES (?,?,?,?)',[$uid,strtolower($email),'Marcos',password_hash($password,PASSWORD_DEFAULT)]);
cv_save_settings($uid,cv_settings($uid));
echo "Conta criada. Senha armazenada somente como hash.\n";
