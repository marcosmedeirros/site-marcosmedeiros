<?php
declare(strict_types=1);
require_once __DIR__ . '/core.php';

const CV_KINDS = ['transaction','task','event','habit','workout','meal','note','goal'];

function cv_text($value, int $max = 1000): string {
    if (!is_string($value) || mb_strlen($value) > $max) cv_fail('Texto inválido ou muito longo.');
    return trim($value);
}
function cv_day($value, bool $optional = false): string {
    if ($optional && ($value === '' || $value === null)) return '';
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) cv_fail('Data inválida.');
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value || $value < '1900-01-01' || $value > '2200-12-31') cv_fail('Data inválida.');
    return $value;
}
function cv_int($value, int $min, int $max): int {
    if (filter_var($value, FILTER_VALIDATE_INT) === false || $value < $min || $value > $max) cv_fail('Número fora do intervalo permitido.');
    return (int)$value;
}
function cv_enum($value, array $allowed): string {
    if (!is_string($value) || !in_array($value, $allowed, true)) cv_fail('Opção inválida.');
    return $value;
}
function cv_record(array $row): array {
    $row['details'] = json_decode($row['details'], true, 64, JSON_THROW_ON_ERROR);
    $row['revision'] = (int)$row['revision'];
    unset($row['user_id']);
    return $row;
}
function cv_get(string $user, string $id): array {
    $row = cv_query('SELECT * FROM cv_records WHERE user_id=? AND id=?', [$user,$id])->fetch();
    if (!$row) cv_fail('Registro não encontrado.', 404);
    return cv_record($row);
}
function cv_list(string $user, array $filter = []): array {
    $sql = 'SELECT * FROM cv_records WHERE user_id=?'; $args = [$user];
    if (isset($filter['kind']) && $filter['kind'] !== '') { $sql .= ' AND kind=?'; $args[] = cv_enum($filter['kind'], CV_KINDS); }
    if (empty($filter['archived'])) $sql .= " AND status<>'archived'";
    foreach (['from' => '>=', 'to' => '<='] as $key => $op) if (!empty($filter[$key])) { $sql .= " AND day $op ?"; $args[] = cv_day($filter[$key]); }
    $sql .= ' ORDER BY day DESC, created_at DESC';
    return array_map('cv_record', cv_query($sql, $args)->fetchAll());
}
function cv_details(string $kind, array $d, array $old = []): array {
    $out = ['notes' => cv_text($d['notes'] ?? '', 12000)];
    if ($kind === 'transaction') {
        $out += ['direction' => cv_enum($d['direction'] ?? 'expense',['income','expense']), 'amount_cents' => cv_int($d['amount_cents'] ?? 0, 1, 99999999999), 'category' => cv_text($d['category'] ?? 'Outros',80)];
    }
    if (in_array($kind,['task','habit','workout'],true)) {
        $out['recurrence'] = cv_enum($d['recurrence'] ?? 'once',['once','daily','weekly','monthly']);
        $out['month_day'] = cv_int($d['month_day'] ?? 1,1,31);
        $weekdays = $d['weekdays'] ?? [];
        if (!is_array($weekdays) || count($weekdays) > 7) cv_fail('Dias da semana inválidos.');
        $out['weekdays'] = array_values(array_unique(array_map(fn($n) => cv_int($n,1,7), $weekdays)));
        if ($out['recurrence'] === 'weekly' && !$out['weekdays']) cv_fail('Escolha ao menos um dia da semana.');
        $out['completed_dates'] = $old['completed_dates'] ?? [];
    }
    if ($kind === 'task') $out += ['area' => cv_enum($d['area'] ?? 'pessoal',['pessoal','casa','trabalho']), 'priority' => cv_enum($d['priority'] ?? 'normal',['baixa','normal','alta'])];
    if ($kind === 'habit') $out['size'] = cv_enum($d['size'] ?? 'habit',['habit','mini']);
    if (in_array($kind,['event','meal','workout','habit','task'],true)) {
        $time = cv_text($d['time'] ?? '',5);
        if ($time !== '' && !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/',$time)) cv_fail('Horário inválido.');
        $out['time'] = $time;
    }
    if ($kind === 'event') {
        $out['location'] = cv_text($d['location'] ?? '',200);
        $out['end_time'] = cv_text($d['end_time'] ?? '',5);
        if ($out['end_time'] !== '' && (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/',$out['end_time']) || $out['end_time'] <= $out['time'])) cv_fail('O fim deve ser depois do início.');
    }
    if ($kind === 'meal') {
        $out['meal'] = cv_enum($d['meal'] ?? 'almoco',['cafe','almoco','lanche','jantar','outro']);
        $out['reflection'] = cv_text($d['reflection'] ?? '',8000);
    }
    if ($kind === 'workout') {
        $out['activity'] = cv_enum($d['activity'] ?? 'caminhada',['caminhada','corrida','forca','futebol','mobilidade','descanso','outro']);
        $out['duration_min'] = cv_int($d['duration_min'] ?? 0,0,1440);
    }
    if ($kind === 'goal') $out['progress'] = cv_int($d['progress'] ?? 0,0,100);
    return $out;
}

function cv_save(string $user, array $input, string $source = 'web'): array {
    $id = isset($input['id']) ? cv_text($input['id'],40) : cv_id();
    $old = isset($input['id']) ? cv_get($user,$id) : null;
    if ($old && $old['status'] === 'archived') cv_fail('Restaure o registro antes de editar.');
    $kind = cv_enum($input['kind'] ?? '',CV_KINDS);
    if ($old && $old['kind'] !== $kind) cv_fail('O tipo do registro não pode mudar.');
    $title = cv_text($input['title'] ?? '',200);
    if ($title === '') cv_fail('Preencha o título.');
    $day = cv_day($input['day'] ?? date('Y-m-d'), in_array($kind,['task','note','goal','habit','workout'],true));
    if (!is_array($input['details'] ?? [])) cv_fail('Detalhes inválidos.');
    $details = cv_details($kind,$input['details'] ?? [],$old['details'] ?? []);
    $status = $old['status'] ?? 'open';
    if ($kind === 'goal') $status = $details['progress'] === 100 ? 'done' : 'open';
    $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $now = cv_now();
    cv_db()->beginTransaction();
    try {
        if ($old) {
            $version = cv_int($input['revision'] ?? 0,1,PHP_INT_MAX);
            $stmt = cv_query('UPDATE cv_records SET title=?,day=?,status=?,details=?,revision=revision+1,updated_at=? WHERE id=? AND user_id=? AND revision=?',[$title,$day,$status,$json,$now,$id,$user,$version]);
            if ($stmt->rowCount() !== 1) cv_fail('Este registro mudou em outra tela. Atualize antes de salvar.',409);
        } else cv_query('INSERT INTO cv_records (id,user_id,kind,title,day,status,details,revision,created_at,updated_at) VALUES (?,?,?,?,?,?,?,1,?,?)',[$id,$user,$kind,$title,$day,$status,$json,$now,$now]);
        cv_audit($user,$old ? 'record.update' : 'record.create',$id,$source);
        cv_db()->commit();
    } catch (Throwable $e) { cv_db()->rollBack(); throw $e; }
    return cv_get($user,$id);
}

function cv_mark(string $user, array $input, string $source = 'web', bool $archive = false): array {
    $old = cv_get($user,cv_text($input['id'] ?? '',40));
    $version = cv_int($input['revision'] ?? 0,1,PHP_INT_MAX);
    $status = $old['status']; $details = $old['details'];
    if ($archive) {
        if (!is_bool($input['archived'] ?? null)) cv_fail('Informe archived como verdadeiro ou falso.');
        if ($input['archived'] && $status !== 'archived') { $details['_status_before_archive']=$status; $status='archived'; }
        elseif (!$input['archived'] && $status === 'archived') { $status=$details['_status_before_archive'] ?? 'open'; unset($details['_status_before_archive']); }
    }
    else {
        if (!in_array($old['kind'],['task','habit','workout','event'],true) || $status === 'archived') cv_fail('Este registro não pode ser concluído.');
        if (!is_bool($input['done'] ?? null)) cv_fail('Informe done como verdadeiro ou falso.');
        $day = cv_day($input['day'] ?? date('Y-m-d'));
        if (($details['recurrence'] ?? 'once') === 'once') $status = $input['done'] ? 'done' : 'open';
        else {
            $dates = array_values(array_diff($details['completed_dates'] ?? [],[$day]));
            if ($input['done']) $dates[] = $day;
            sort($dates); $details['completed_dates'] = $dates;
        }
    }
    cv_db()->beginTransaction();
    try {
        $stmt = cv_query('UPDATE cv_records SET status=?,details=?,revision=revision+1,updated_at=? WHERE id=? AND user_id=? AND revision=?',[$status,json_encode($details,JSON_UNESCAPED_UNICODE),cv_now(),$old['id'],$user,$version]);
        if ($stmt->rowCount() !== 1) cv_fail('Este registro mudou. Atualize a tela.',409);
        cv_audit($user,$archive ? 'record.archive' : 'record.complete',$old['id'],$source);
        cv_db()->commit();
    } catch (Throwable $e) { cv_db()->rollBack(); throw $e; }
    return cv_get($user,$old['id']);
}

function cv_summary(string $user, string $month): array {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month)) cv_fail('Mês inválido.');
    $all = cv_list($user); $settings = cv_settings($user);
    $income = 0; $expense = 0; $balance = $settings['initial_balance_cents'];
    foreach ($all as $r) if ($r['kind'] === 'transaction') {
        $amount = $r['details']['amount_cents'];
        $sign = $r['details']['direction'] === 'income' ? 1 : -1;
        if (substr($r['day'],0,7) <= $month) $balance += $sign * $amount;
        if (substr($r['day'],0,7) === $month) { if ($sign === 1) $income += $amount; else $expense += $amount; }
    }
    return ['month'=>$month,'income_cents'=>$income,'expense_cents'=>$expense,'balance_cents'=>$balance,'initial_balance_cents'=>$settings['initial_balance_cents']];
}

function cv_import(string $user, array $snapshot): array {
    if (($snapshot['format'] ?? '') !== 'controlevida-legacy-finance-v1' || !is_array($snapshot['transactions'] ?? null)) cv_fail('Formato de importação inválido.');
    if (count($snapshot['transactions']) > 20000) cv_fail('Limite de 20 mil lançamentos por arquivo.');
    $settings = cv_settings($user); $count = 0; $skipped = 0;
    cv_db()->beginTransaction();
    try {
        foreach ($snapshot['transactions'] as $tx) {
            $legacy = cv_text((string)($tx['id'] ?? ''),100);
            if ($legacy === '') cv_fail('Lançamento sem identificador.');
            $id = md5('marcosmedeiros.page:fin_transactions:' . $user . ':' . $legacy);
            if (cv_query('SELECT id FROM cv_records WHERE id=?',[$id])->fetchColumn()) { $skipped++; continue; }
            $amount = $tx['amount'] ?? null;
            if (!preg_match('/^\d+(\.\d{1,2})?$/',(string)$amount)) cv_fail('Valor financeiro inválido na importação.');
            $parts = explode('.',(string)$amount);
            $cents = cv_int((int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '',2,'0'),1,99999999999);
            $d = cv_details('transaction',['direction'=>$tx['type'] ?? '', 'amount_cents'=>$cents,'category'=>($tx['cat_name'] ?? '') ?: 'Outros','notes'=>'']);
            $title = cv_text(($tx['description'] ?? '') ?: 'Lançamento importado',200);
            $day = cv_day($tx['transaction_date'] ?? '');
            cv_query('INSERT INTO cv_records (id,user_id,kind,title,day,status,details,revision,created_at,updated_at) VALUES (?,?,?,?,?,?,?,1,?,?)',[$id,$user,'transaction',$title,$day,'open',json_encode($d,JSON_UNESCAPED_UNICODE),cv_now(),cv_now()]);
            $count++;
        }
        if (!isset($settings['migration'])) $settings['initial_balance_cents'] = cv_int($snapshot['initial_balance_cents'] ?? 0,-99999999999,99999999999);
        $cats = array_map(fn($x)=>cv_text(is_array($x) ? ($x['name'] ?? '') : $x,80),$snapshot['categories'] ?? []);
        $settings['categories'] = array_values(array_unique(array_merge($settings['categories'] ?? [],$cats)));
        $settings['migration'] = ['source'=>'marcosmedeiros.page','imported_at'=>cv_now(),'from'=>cv_day($snapshot['from'] ?? ''),'to'=>cv_day($snapshot['to'] ?? ''),'new_records'=>$count,'already_present'=>$skipped,'snapshot_sha256'=>hash('sha256',json_encode($snapshot))];
        cv_save_settings($user,$settings);
        cv_audit($user,'finance.import','', 'import');
        cv_db()->commit();
    } catch (Throwable $e) { cv_db()->rollBack(); throw $e; }
    return $settings['migration'];
}
