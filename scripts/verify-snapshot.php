<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/controlevida/server/records.php';
$snapshot=json_decode(file_get_contents($argv[1] ?? ''),true,64,JSON_THROW_ON_ERROR);
$uid=cv_query('SELECT id FROM cv_users')->fetchColumn();$sum=0;
foreach($snapshot['transactions'] as $tx){
    $id=md5('marcosmedeiros.page:fin_transactions:'.$uid.':'.$tx['id']);$r=cv_get($uid,$id);
    $amount=(int)round((float)$tx['amount']*100);
    if($r['details']['amount_cents']!==$amount || $r['details']['direction']!==$tx['type'] || $r['day']!==$tx['transaction_date'])throw new RuntimeException('Diferenca encontrada na migracao.');
    $sum+=($tx['type']==='income'?1:-1)*$amount;
}
if(cv_settings($uid)['initial_balance_cents']!==$snapshot['initial_balance_cents'])throw new RuntimeException('Saldo inicial divergente.');
echo json_encode(['verified_transactions'=>count($snapshot['transactions']),'initial_balance_matches'=>true,'dates_and_amounts_match'=>true])."\n";
