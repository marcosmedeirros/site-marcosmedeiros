<?php
declare(strict_types=1);
require_once __DIR__ . '/records.php';

// Import from the previous app (marcosmedeiros.page). Source tables are only read.
// A row that fails validation is reported and never blocks the other rows.

const CV_LEGACY_TABLES = ['fin_transactions','finances','fin_categories','fin_settings','tasks','task_completions','activities','habits','events','routine_items','workout_plan','workouts','runs','food_logs','daily_notes','goals','life_rules'];
const CV_LEGACY_ACTIVITY = ['gym'=>'forca','run'=>'corrida','rest'=>'descanso','other'=>'outro'];

function cv_legacy_day($value): string {
    try { return cv_day(substr((string)($value ?? ''), 0, 10)); } catch (DomainException $e) { return ''; }
}
function cv_legacy_days($list): array {
    $out = [];
    foreach (is_array($list) ? $list : [] as $d) { $d = cv_legacy_day($d); if ($d !== '') $out[$d] = true; }
    $out = array_keys($out); sort($out);
    return $out;
}
function cv_legacy_recurrence(array $r, string $default): array {
    $rec = in_array($r['recurrence'] ?? null, ['once','daily','weekly','monthly'], true) ? $r['recurrence'] : $default;
    $n = (int)($r['recurrence_day'] ?? 0);
    // The old app never showed a weekly/monthly item without a valid day; keep it visible instead.
    if (($rec === 'weekly' && ($n < 1 || $n > 7)) || ($rec === 'monthly' && ($n < 1 || $n > 31))) $rec = $default;
    return ['recurrence' => $rec, 'weekdays' => $rec === 'weekly' ? [$n] : [], 'month_day' => $rec === 'monthly' ? $n : 1];
}
function cv_legacy_meal(string $label): string {
    foreach (['caf' => 'cafe', 'almo' => 'almoco', 'lanch' => 'lanche', 'jant' => 'jantar'] as $needle => $meal) if (mb_stripos($label, $needle) !== false) return $meal;
    return 'outro';
}

function cv_legacy_put(string $user, array &$report, string $table, $key, string $kind, $title, $day, array $details, string $status = 'open'): void {
    $id = md5('legacy:' . $table . ':' . $user . ':' . $key);
    if (cv_query('SELECT id FROM cv_records WHERE id=?', [$id])->fetchColumn()) { $report['skipped'][$table] = ($report['skipped'][$table] ?? 0) + 1; return; }
    try {
        $day = cv_legacy_day($day);
        if ($day === '' && in_array($kind, ['event','meal'], true)) cv_fail('Data inválida.');
        if (isset($details['notes'])) $details['notes'] = mb_substr((string)$details['notes'], 0, 12000);
        $clean = cv_details($kind, $details, $details);
        $title = mb_substr(trim((string)$title), 0, 200) ?: 'Registro importado';
        cv_query('INSERT INTO cv_records (id,user_id,kind,title,day,status,details,revision,created_at,updated_at) VALUES (?,?,?,?,?,?,?,1,?,?)', [$id, $user, $kind, $title, $day, $status, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), cv_now(), cv_now()]);
        $report['created'][$table] = ($report['created'][$table] ?? 0) + 1;
    } catch (DomainException $e) {
        $report['invalid'][$table][] = ['id' => (string)$key, 'reason' => $e->getMessage()];
    }
}

function cv_legacy_task(string $user, array &$report, array $r, array $doneDates): void {
    $area = in_array($r['area'] ?? null, ['pessoal','casa','trabalho'], true) ? $r['area'] : 'pessoal';
    $status = !empty($r['archived']) ? 'archived' : (!empty($r['status']) ? 'done' : 'open');
    cv_legacy_put($user, $report, 'tasks', $r['id'], 'task', $r['title'] ?? '', $r['due_date'] ?? '', ['area' => $area, 'priority' => !empty($r['priority']) ? 'alta' : 'normal', 'completed_dates' => cv_legacy_days($doneDates)] + cv_legacy_recurrence($r, 'once'), $status);
}
function cv_legacy_habit(string $user, array &$report, array $r): void {
    $dates = json_decode((string)($r['checked_dates'] ?? '') ?: '[]', true);
    cv_legacy_put($user, $report, 'habits', $r['id'], 'habit', $r['name'] ?? '', '', ['completed_dates' => cv_legacy_days($dates)] + cv_legacy_recurrence($r, 'daily'));
}
function cv_legacy_goal(string $user, array &$report, array $r): void {
    $target = (float)($r['target_amount'] ?? 0);
    $notes = $target > 0 ? 'Meta financeira anterior: R$ ' . number_format($target, 2, ',', '.') . '; acumulado: R$ ' . number_format((float)($r['current_amount'] ?? 0), 2, ',', '.') . '.' : '';
    cv_legacy_put($user, $report, 'goals', $r['id'], 'goal', $r['title'] ?? '', $r['deadline'] ?? '', ['notes' => $notes], !empty($r['status']) ? 'done' : 'open');
}
function cv_legacy_plan(string $user, array &$report, array $r): void {
    if (($r['type'] ?? 'rest') === 'rest' && empty($r['name'])) return;
    cv_legacy_put($user, $report, 'workout_plan', $r['weekday'], 'workout', ($r['name'] ?? '') ?: 'Descanso', '', ['activity' => CV_LEGACY_ACTIVITY[$r['type'] ?? ''] ?? 'outro', 'recurrence' => 'weekly', 'weekdays' => [(int)$r['weekday']]]);
}

function cv_legacy_run(string $user, array &$report, array $r): void {
    cv_legacy_put($user, $report, 'runs', $r['id'], 'workout', ($r['title'] ?? '') ?: 'Corrida', $r['run_date'] ?? '', ['activity' => 'corrida', 'duration_min' => max(0, min(1440, (int)($r['duration_min'] ?? 0))), 'notes' => trim(($r['notes'] ?? '') . ' Distância registrada: ' . ($r['distance_km'] ?? 0) . ' km.')], 'done');
}

// Tasks, habits, goals, runs and workout plan as exported by the old public API (scripts/export-legacy.mjs).
function cv_import_legacy_state(string $user, array $state, array &$report): void {
    $rows = fn(string $key) => array_filter(is_array($state[$key] ?? null) ? $state[$key] : [], fn($r) => is_array($r) && isset($r['id']) || ($key === 'workout_plan' && is_array($r) && isset($r['weekday'])));
    cv_db()->beginTransaction();
    try {
        foreach ($rows('tasks') as $r) cv_legacy_task($user, $report, $r, array_filter(explode(',', (string)($r['done_dates'] ?? ''))));
        foreach ($rows('habits') as $r) cv_legacy_habit($user, $report, $r);
        foreach ($rows('goals') as $r) cv_legacy_goal($user, $report, $r);
        foreach ($rows('runs') as $r) cv_legacy_run($user, $report, $r);
        foreach ($rows('workout_plan') as $r) cv_legacy_plan($user, $report, $r);
        cv_db()->commit();
    } catch (Throwable $e) { cv_db()->rollBack(); throw $e; }
}

// File import from the settings screen: finances, plus the old app state when the snapshot carries it.
function cv_import_snapshot(string $user, array $snapshot): array {
    $result = cv_import($user, $snapshot);
    if (is_array($snapshot['state'] ?? null)) {
        $report = ['created' => [], 'skipped' => [], 'invalid' => []];
        cv_import_legacy_state($user, $snapshot['state'], $report);
        $result['state'] = $report;
    }
    return $result;
}

function cv_legacy_read(PDO $source, int $legacyUser): array {
    $mysql = $source->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $all = $source->query($mysql ? 'SHOW TABLES' : "SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    $raw = [];
    foreach (CV_LEGACY_TABLES as $table) {
        if (!in_array($table, $all, true)) { $raw[$table] = []; continue; }
        $columns = $mysql ? $source->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN) : array_column($source->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        // Embedded photos can weigh megabytes per row and have no place in the new records.
        $select = implode(',', array_map(fn($c) => "`$c`", array_values(array_diff($columns, ['photo_data']))));
        $scoped = in_array('user_id', $columns, true);
        $stmt = $source->prepare("SELECT $select FROM `$table`" . ($scoped ? ' WHERE user_id=?' : ''));
        $stmt->execute($scoped ? [$legacyUser] : []);
        $raw[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $raw;
}

// Full migration straight from the old database. Safe to repeat: existing records are skipped.
function cv_migrate_legacy(string $user, ?PDO $source = null, int $legacyUser = 1): array {
    $raw = cv_legacy_read($source ?? cv_db(), $legacyUser);
    if (!array_filter($raw)) cv_fail('Nenhum dado do app anterior foi encontrado neste banco.', 404);
    $dir = cv_config()['data_dir'];
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $backup = $dir . '/legacy-backup-' . gmdate('Ymd-His') . '-' . substr(cv_id(), 0, 8) . '.json';
    file_put_contents($backup, json_encode(['exported_at' => cv_now(), 'tables' => $raw], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), LOCK_EX);
    @chmod($backup, 0600);
    $report = ['finance' => null, 'created' => [], 'skipped' => [], 'invalid' => [], 'backup' => $backup];

    $cats = array_column($raw['fin_categories'], 'name', 'id'); $candidates = []; $linked = [];
    foreach ($raw['fin_transactions'] as $r) {
        if (!empty($r['legacy_id'])) $linked[(string)$r['legacy_id']] = true;
        $candidates[] = ['fin_transactions', ['id' => $r['id'], 'type' => $r['type'] ?? '', 'amount' => $r['amount'] ?? '', 'description' => $r['description'] ?? '', 'cat_name' => $cats[$r['category_id'] ?? 0] ?? 'Outros', 'transaction_date' => cv_legacy_day($r['transaction_date'] ?? '')]];
    }
    // Rows of the older "finances" table already copied into fin_transactions are not counted twice.
    foreach ($raw['finances'] as $r) if (!isset($linked[(string)$r['id']])) $candidates[] = ['finances', ['id' => 'legacy-' . $r['id'], 'type' => $r['type'] ?? '', 'amount' => $r['amount'] ?? '', 'description' => $r['description'] ?? '', 'cat_name' => 'Outros', 'transaction_date' => cv_legacy_day($r['created_at'] ?? '')]];
    $transactions = [];
    foreach ($candidates as [$table, $tx]) {
        $tx['amount'] = is_float($tx['amount']) ? number_format($tx['amount'], 2, '.', '') : (string)$tx['amount'];
        $tx['description'] = mb_substr(trim((string)$tx['description']), 0, 200);
        $tx['cat_name'] = mb_substr(trim((string)$tx['cat_name']), 0, 80) ?: 'Outros';
        $reason = !in_array($tx['type'], ['income','expense'], true) ? 'Tipo desconhecido.' : (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $tx['amount']) || (float)$tx['amount'] <= 0 ? 'Valor inválido ou zerado.' : ($tx['transaction_date'] === '' ? 'Data inválida.' : ''));
        if ($reason !== '') $report['invalid'][$table][] = ['id' => (string)$tx['id'], 'reason' => $reason]; else $transactions[] = $tx;
    }
    $dates = array_column($transactions, 'transaction_date'); sort($dates);
    $report['finance'] = cv_import($user, ['format' => 'controlevida-legacy-finance-v1', 'from' => $dates[0] ?? date('Y-m-d'), 'to' => $dates ? end($dates) : date('Y-m-d'), 'initial_balance_cents' => (int)round((float)($raw['fin_settings'][0]['initial_balance'] ?? 0) * 100), 'transactions' => $transactions, 'categories' => array_values(array_filter(array_map(fn($c) => mb_substr(trim((string)($c['name'] ?? '')), 0, 80), $raw['fin_categories'])))]);

    cv_db()->beginTransaction();
    try {
        $doneDates = []; foreach ($raw['task_completions'] as $r) $doneDates[$r['task_id']][] = $r['done_date'];
        $fromActivities = [];
        foreach ($raw['tasks'] as $r) {
            if (!empty($r['legacy_id'])) $fromActivities[$r['legacy_id']] = true;
            cv_legacy_task($user, $report, $r, $doneDates[$r['id']] ?? []);
        }
        foreach ($raw['activities'] as $r) if (!isset($fromActivities[$r['id']])) cv_legacy_put($user, $report, 'activities', $r['id'], 'task', $r['title'] ?? '', $r['day_date'] ?? '', ['recurrence' => 'once'], !empty($r['status']) ? 'done' : 'open');
        foreach ($raw['habits'] as $r) cv_legacy_habit($user, $report, $r);
        foreach ($raw['routine_items'] as $r) cv_legacy_put($user, $report, 'routine_items', $r['id'], 'habit', $r['activity'] ?? '', '', ['recurrence' => 'daily', 'time' => substr((string)($r['routine_time'] ?? ''), 0, 5)]);
        foreach ($raw['events'] as $r) cv_legacy_put($user, $report, 'events', $r['id'], 'event', $r['title'] ?? '', $r['start_date'] ?? '', ['time' => substr((string)($r['start_date'] ?? ''), 11, 5), 'notes' => $r['description'] ?? '']);
        foreach ($raw['workout_plan'] as $r) cv_legacy_plan($user, $report, $r);
        foreach ($raw['workouts'] as $r) cv_legacy_put($user, $report, 'workouts', $r['id'], 'workout', ($r['name'] ?? '') ?: 'Treino', $r['workout_date'] ?? '', ['activity' => CV_LEGACY_ACTIVITY[$r['type'] ?? 'other'] ?? 'outro', 'notes' => $r['notes'] ?? ''], !empty($r['done']) ? 'done' : 'open');
        foreach ($raw['runs'] as $r) cv_legacy_run($user, $report, $r);
        foreach ($raw['food_logs'] as $r) {
            $text = trim((string)($r['description'] ?? '')); $label = trim((string)($r['meal_label'] ?? '')); $meal = cv_legacy_meal($label);
            cv_legacy_put($user, $report, 'food_logs', $r['id'], 'meal', $text, $r['log_date'] ?? '', ['meal' => $meal, 'notes' => trim(($meal === 'outro' ? $label . ' ' : '') . (mb_strlen($text) > 200 ? $text : ''))]);
        }
        foreach ($raw['daily_notes'] as $r) if (trim((string)($r['content'] ?? '')) !== '') { $day = cv_legacy_day($r['note_date'] ?? ''); cv_legacy_put($user, $report, 'daily_notes', $r['id'], 'note', 'Nota de ' . ($day ? date('d/m/Y', strtotime($day)) : 'data antiga'), $day, ['notes' => $r['content']]); }
        foreach ($raw['goals'] as $r) cv_legacy_goal($user, $report, $r);
        foreach ($raw['life_rules'] as $r) cv_legacy_put($user, $report, 'life_rules', $r['id'], 'note', $r['rule_text'] ?? '', '', ['notes' => $r['rule_text'] ?? '']);
        cv_audit($user, 'legacy.migrate', '', 'import');
        cv_db()->commit();
    } catch (Throwable $e) { cv_db()->rollBack(); throw $e; }
    return $report;
}
