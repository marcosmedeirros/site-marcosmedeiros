<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function legacy_put(string $table,$key,string $kind,string $title,string $day,array $details,string $status='open'):void {
    global $user,$report;
    $id=md5('legacy:'.$table.':'.$user.':'.$key);
    if(cv_query('SELECT id FROM cv_records WHERE id=?',[$id])->fetchColumn()){ $report['skipped'][$table]=($report['skipped'][$table] ?? 0)+1;return; }
    $clean=cv_details($kind,$details,$details);
    $title=mb_substr(trim($title) ?: 'Registro importado',0,200);
    $day=cv_day($day,true);
    cv_query('INSERT INTO cv_records (id,user_id,kind,title,day,status,details,revision,created_at,updated_at) VALUES (?,?,?,?,?,?,?,1,?,?)',[$id,$user,$kind,$title,$day,$status,json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),cv_now(),cv_now()]);
    $report['created'][$table]=($report['created'][$table] ?? 0)+1;
}
