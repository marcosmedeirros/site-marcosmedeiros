<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/controlevida/server/records.php';
require __DIR__.'/legacy-import-lib.php';
if(!isset($argv[1])||!is_file($argv[1]))throw new RuntimeException('Informe o snapshot privado.');
$snapshot=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
$user=cv_query('SELECT id FROM cv_users')->fetchColumn();
$report=['finance'=>cv_import($user,$snapshot),'created'=>[],'skipped'=>[],'coverage'=>'API snapshot; full DB migration remains pending'];
$state=$snapshot['state'] ?? [];
cv_db()->beginTransaction();
try {
    foreach($state['tasks'] ?? [] as $r){$rec=$r['recurrence'] ?? 'once';legacy_put('tasks',$r['id'],'task',$r['title'],$r['due_date'] ?? '',['area'=>$r['area'] ?? 'pessoal','priority'=>!empty($r['priority'])?'alta':'normal','recurrence'=>$rec,'weekdays'=>$rec==='weekly'?[(int)$r['recurrence_day']]:[],'month_day'=>$rec==='monthly'?(int)$r['recurrence_day']:1,'completed_dates'=>array_values(array_filter(explode(',',$r['done_dates'] ?? '')))],!empty($r['status'])?'done':'open');}
    foreach($state['habits'] ?? [] as $r){$rec=$r['recurrence'] ?? 'daily';legacy_put('habits',$r['id'],'habit',$r['name'],'',['recurrence'=>$rec,'weekdays'=>$rec==='weekly'?[(int)$r['recurrence_day']]:[],'completed_dates'=>json_decode($r['checked_dates'] ?: '[]',true) ?: []]);}
    foreach($state['goals'] ?? [] as $r){$target=(float)($r['target_amount'] ?? 0);legacy_put('goals',$r['id'],'goal',$r['title'],$r['deadline'] ?? '',['progress'=>!empty($r['status'])?100:($target>0?min(100,(int)round((float)$r['current_amount']/$target*100)):0)],!empty($r['status'])?'done':'open');}
    $map=['gym'=>'forca','run'=>'corrida','rest'=>'descanso','other'=>'outro'];
    foreach($state['workout_plan'] ?? [] as $r){if($r['type']==='rest'&&!$r['name'])continue;legacy_put('workout_plan',$r['weekday'],'workout',$r['name'] ?: 'Descanso','',['activity'=>$map[$r['type']] ?? 'outro','recurrence'=>'weekly','weekdays'=>[(int)$r['weekday']]]);}
    cv_db()->commit();
} catch(Throwable $e){cv_db()->rollBack();throw $e;}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
