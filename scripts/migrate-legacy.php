<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/controlevida/server/records.php';
$db=cv_db();
$user=cv_query('SELECT id FROM cv_users')->fetchColumn();
if(!$user)throw new RuntimeException('Execute setup.php primeiro.');
$legacyUser=(int)(getenv('CV_LEGACY_USER_ID') ?: 1);
$source=getenv('CV_LEGACY_DSN') ? new PDO(getenv('CV_LEGACY_DSN'),getenv('CV_LEGACY_DB_USER') ?: null,getenv('CV_LEGACY_DB_PASSWORD') ?: null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]) : $db;
$allTables=$source->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? $source->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) : $source->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
$raw=[];
$tables=['fin_transactions','finances','fin_categories','fin_settings','tasks','task_completions','activities','habits','events','routine_items','workout_plan','workouts','runs','food_logs','daily_notes','goals','life_rules'];
foreach($tables as $table){
    if(!in_array($table,$allTables,true)){ $raw[$table]=[];continue; }
    $columns=$source->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? $source->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN) : array_column($source->query("PRAGMA table_info($table)")->fetchAll(),'name');
    $stmt=$source->prepare("SELECT * FROM `$table`".(in_array('user_id',$columns,true)?' WHERE user_id=?':''));
    $stmt->execute(in_array('user_id',$columns,true)?[$legacyUser]:[]);$raw[$table]=$stmt->fetchAll();
}
$backup=cv_config()['data_dir'].'/legacy-backup-'.gmdate('Ymd-His').'-'.substr(cv_id(),0,8).'.json';
file_put_contents($backup,json_encode(['exported_at'=>cv_now(),'tables'=>$raw],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX);chmod($backup,0600);
$cats=array_column($raw['fin_categories'],'name','id');$transactions=[];$legacyIds=[];
foreach($raw['fin_transactions'] as $r){$r['cat_name']=$cats[$r['category_id'] ?? 0] ?? 'Outros';$transactions[]=$r;if(!empty($r['legacy_id']))$legacyIds[(string)$r['legacy_id']]=true;}
foreach($raw['finances'] as $r)if(!isset($legacyIds[(string)$r['id']]))$transactions[]=['id'=>'legacy-'.$r['id'],'type'=>$r['type'],'amount'=>$r['amount'],'description'=>$r['description'],'cat_name'=>'Outros','transaction_date'=>substr($r['created_at'],0,10)];
$dates=array_column($transactions,'transaction_date');sort($dates);
$snapshot=['format'=>'controlevida-legacy-finance-v1','from'=>$dates[0] ?? date('Y-m-d'),'to'=>$dates ? end($dates) : date('Y-m-d'),'initial_balance_cents'=>(int)round((float)($raw['fin_settings'][0]['initial_balance'] ?? 0)*100),'transactions'=>$transactions,'categories'=>$raw['fin_categories']];
$report=['finance'=>cv_import($user,$snapshot),'created'=>[],'skipped'=>[],'backup'=>$backup];

require __DIR__.'/legacy-import-lib.php';
$db->beginTransaction();
try{
    $taskDates=[];foreach($raw['task_completions'] as $r)$taskDates[$r['task_id']][]=$r['done_date'];
    $activityIds=[];
    foreach($raw['tasks'] as $r){
        if(!empty($r['legacy_id']))$activityIds[$r['legacy_id']]=true;
        $rec=$r['recurrence'] ?? 'once';
        legacy_put('tasks',$r['id'],'task',$r['title'],$r['due_date'] ?? '',['area'=>$r['area'] ?? 'pessoal','priority'=>!empty($r['priority'])?'alta':'normal','recurrence'=>$rec,'weekdays'=>$rec==='weekly'?[(int)$r['recurrence_day']]:[],'month_day'=>$rec==='monthly'?(int)$r['recurrence_day']:1,'completed_dates'=>$taskDates[$r['id']] ?? []],!empty($r['archived'])?'archived':(!empty($r['status'])?'done':'open'));
    }
    foreach($raw['activities'] as $r)if(!isset($activityIds[$r['id']]))legacy_put('activities',$r['id'],'task',$r['title'],$r['day_date'] ?? '',['recurrence'=>'once'],!empty($r['status'])?'done':'open');
    foreach($raw['habits'] as $r){$rec=$r['recurrence'] ?? 'daily';legacy_put('habits',$r['id'],'habit',$r['name'],'',['recurrence'=>$rec,'weekdays'=>$rec==='weekly'?[(int)$r['recurrence_day']]:[],'completed_dates'=>json_decode($r['checked_dates'] ?: '[]',true) ?: [],'size'=>'habit']);}
    foreach($raw['routine_items'] as $r)legacy_put('routine_items',$r['id'],'habit',$r['activity'],'',['recurrence'=>'daily','size'=>'mini','time'=>substr($r['routine_time'],0,5)]);
    foreach($raw['events'] as $r)legacy_put('events',$r['id'],'event',$r['title'],substr($r['start_date'],0,10),['time'=>substr($r['start_date'],11,5),'notes'=>$r['description'] ?? '']);
    $activityMap=['gym'=>'forca','run'=>'corrida','rest'=>'descanso','other'=>'outro'];
    foreach($raw['workout_plan'] as $r){if($r['type']==='rest'&&!$r['name'])continue;legacy_put('workout_plan',$r['weekday'],'workout',$r['name'] ?: 'Descanso','',['activity'=>$activityMap[$r['type']] ?? 'outro','recurrence'=>'weekly','weekdays'=>[(int)$r['weekday']]]);}
    foreach($raw['workouts'] as $r)legacy_put('workouts',$r['id'],'workout',$r['name'] ?: 'Treino',$r['workout_date'],['activity'=>$activityMap[$r['type'] ?? 'other'] ?? 'outro','notes'=>$r['notes'] ?? ''],!empty($r['done'])?'done':'open');
    foreach($raw['runs'] as $r)legacy_put('runs',$r['id'],'workout',$r['title'],$r['run_date'],['activity'=>'corrida','duration_min'=>(int)($r['duration_min'] ?? 0),'notes'=>trim(($r['notes'] ?? '').' Distancia registrada: '.$r['distance_km'].' km.')],'done');
    foreach($raw['food_logs'] as $r)legacy_put('food_logs',$r['id'],'meal',mb_substr($r['description'],0,200),$r['log_date'],['meal'=>'outro','notes'=>trim(($r['meal_label'] ?? '').' '.$r['description'])]);
    foreach($raw['daily_notes'] as $r)if(trim($r['content'] ?? '')!=='')legacy_put('daily_notes',$r['id'],'note','Nota de '.$r['note_date'],$r['note_date'],['notes'=>$r['content']]);
    foreach($raw['goals'] as $r){$target=(float)($r['target_amount'] ?? 0);$progress=!empty($r['status'])?100:($target>0?min(100,(int)round((float)$r['current_amount']/$target*100)):0);legacy_put('goals',$r['id'],'goal',$r['title'],$r['deadline'] ?? '',['progress'=>$progress,'notes'=>$target>0?'Meta financeira anterior: R$ '.number_format($target,2,',','.').'; acumulado: R$ '.number_format((float)$r['current_amount'],2,',','.').'.':''],!empty($r['status'])?'done':'open');}
    foreach($raw['life_rules'] as $r)legacy_put('life_rules',$r['id'],'note',mb_substr($r['rule_text'],0,200),'',['notes'=>$r['rule_text']]);
    cv_audit($user,'legacy.migrate','','import');$db->commit();
}catch(Throwable $e){$db->rollBack();throw $e;}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
