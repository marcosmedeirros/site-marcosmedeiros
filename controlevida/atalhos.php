<?php
declare(strict_types=1);
require_once __DIR__ . '/server/strava.php';
cv_headers();

// Write endpoint for the iPhone Shortcuts app: marking a habit by voice, logging the workout the
// watch wrote into Apple Health, jotting a line into the day's note. A shortcut has nowhere to sign
// in, so the token travels in the body — not in the URL, where it would end up in logs and history.

const CV_ATALHO_SPORTS = [
    'corrida' => 'corrida', 'run' => 'corrida', 'correr' => 'corrida', 'trote' => 'corrida',
    'caminhada' => 'caminhada', 'walk' => 'caminhada', 'caminhar' => 'caminhada', 'trilha' => 'caminhada',
    'bicicleta' => 'outro', 'ciclismo' => 'outro', 'pedal' => 'outro', 'bike' => 'outro', 'ride' => 'outro',
    'forca' => 'forca', 'musculacao' => 'forca', 'treino' => 'forca', 'funcional' => 'forca', 'academia' => 'forca',
    'futebol' => 'futebol', 'football' => 'futebol', 'soccer' => 'futebol',
    'mobilidade' => 'mobilidade', 'alongamento' => 'mobilidade', 'yoga' => 'mobilidade',
    'descanso' => 'descanso',
];

function cv_slug(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    return str_replace(
        ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ'],
        ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n'],
        $value);
}

// Siri hears a title, not an id: an exact name wins, otherwise a single partial match is enough.
function cv_atalho_find(string $user, string $title, string $day): array {
    $wanted = cv_slug($title);
    if ($wanted === '') cv_fail('Diga o nome do registro.');
    $exact = []; $partial = [];
    foreach (cv_list($user) as $record) {
        if (!in_array($record['kind'], ['task', 'habit', 'workout', 'goal'], true)) continue;
        $slug = cv_slug($record['title']);
        if ($slug === $wanted) $exact[] = $record;
        elseif (str_contains($slug, $wanted) || str_contains($wanted, $slug)) $partial[] = $record;
    }
    $found = $exact ?: $partial;
    if (!$found) cv_fail('Não achei nada chamado "' . $title . '".', 404);
    if (count($found) > 1) {
        $due = array_values(array_filter($found, fn($r) => cv_due($r, $day)));
        if (count($due) === 1) return $due[0];
        cv_fail('Achei mais de um: ' . implode(', ', array_map(fn($r) => $r['title'], array_slice($found, 0, 4))) . '. Seja mais específico.');
    }
    return $found[0];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); cv_fail('Use POST com um corpo JSON.', 405); }
    $in = cv_input();
    $token = (string)($in['t'] ?? '');
    $user = '';
    if (preg_match('/^[A-Za-z0-9_-]{30,120}$/', $token)) {
        foreach (cv_query('SELECT user_id,data FROM cv_settings')->fetchAll() as $row) {
            $saved = json_decode($row['data'], true, 32, JSON_THROW_ON_ERROR)['atalhos_token'] ?? '';
            if (is_string($saved) && $saved !== '' && hash_equals($saved, $token)) { $user = $row['user_id']; break; }
        }
    }
    if ($user === '') cv_fail('Atalho não autorizado.', 401);
    cv_limit('atalhos', 300, 3600);

    $day = cv_day($in['dia'] ?? date('Y-m-d'));
    $acao = cv_enum($in['acao'] ?? '', ['marcar', 'treino', 'nota', 'hoje']);

    if ($acao === 'marcar') {
        $record = cv_atalho_find($user, cv_text($in['titulo'] ?? '', 200), $day);
        $done = !isset($in['feito']) || !empty($in['feito']);
        if (cv_done($record, $day) === $done) {
            $mensagem = $record['title'] . ($done ? ' já estava marcado.' : ' já estava aberto.');
        } else {
            cv_mark($user, ['id' => $record['id'], 'revision' => $record['revision'], 'done' => $done, 'day' => $day], 'atalho');
            $mensagem = $record['title'] . ($done ? ' marcado.' : ' desmarcado.');
        }
    } elseif ($acao === 'treino') {
        $minutes = cv_int($in['minutos'] ?? 0, 0, 1440);
        $km = round((float)($in['km'] ?? 0), 2);
        if ($minutes <= 0 && $km <= 0) cv_fail('Informe ao menos a duração ou a distância.');
        $sport = CV_ATALHO_SPORTS[cv_slug((string)($in['tipo'] ?? ''))] ?? 'outro';
        $name = cv_text($in['nome'] ?? '', 200) ?: ucfirst($sport === 'outro' ? 'Atividade' : $sport);
        $report = cv_import_sessions($user, [[
            // One session per day and type: a shortcut that runs twice does not log it twice.
            'id' => (string)($in['id'] ?? $day . ':' . $sport),
            'name' => $name,
            'cv_activity' => $sport,
            'start_date_local' => $day . 'T12:00:00Z',
            'distance' => $km * 1000,
            'moving_time' => $minutes * 60,
            'average_heartrate' => (float)($in['bpm'] ?? 0),
        ]], 'atalho');
        $mensagem = $report['novos'] > 0
            ? $name . ' registrado' . ($report['marcados'] > 0 ? ' e treino do dia marcado.' : '.')
            : $name . ' já estava registrado hoje.';
    } elseif ($acao === 'nota') {
        $text = cv_text($in['texto'] ?? '', 4000);
        if ($text === '') cv_fail('Diga o que anotar.');
        $title = 'Nota de ' . date('d/m/Y', strtotime($day));
        $existing = null;
        foreach (cv_list($user) as $record) if ($record['kind'] === 'note' && $record['day'] === $day && $record['title'] === $title) { $existing = $record; break; }
        $notes = $existing ? rtrim($existing['details']['notes']) . "\n" . $text : $text;
        $payload = ['kind' => 'note', 'title' => $title, 'day' => $day, 'details' => ['notes' => $notes]];
        if ($existing) { $payload['id'] = $existing['id']; $payload['revision'] = $existing['revision']; }
        cv_save($user, $payload, 'atalho');
        $mensagem = 'Anotado.';
    } else {
        $pendentes = [];
        foreach (cv_list($user) as $record) {
            if (!in_array($record['kind'], ['task', 'habit', 'workout'], true)) continue;
            if (cv_due($record, $day) && !cv_done($record, $day)) $pendentes[] = $record['title'];
        }
        $mensagem = $pendentes ? count($pendentes) . ' pendentes: ' . implode(', ', array_slice($pendentes, 0, 6)) . '.' : 'Dia fechado.';
    }

    cv_json(['ok' => true, 'mensagem' => $mensagem] + ($report ?? []));
} catch (DomainException $e) {
    cv_json(['ok' => false, 'mensagem' => $e->getMessage()], $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('ControleVida atalhos: ' . get_class($e) . ': ' . $e->getMessage());
    cv_json(['ok' => false, 'mensagem' => 'Não foi possível concluir.'], 503);
}
