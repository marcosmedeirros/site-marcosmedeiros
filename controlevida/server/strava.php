<?php
declare(strict_types=1);
require_once __DIR__ . '/records.php';

// Strava connection. The watch syncs to Strava through FitBeing, so Strava is where the runs,
// rides and gym sessions already live — this pulls them in instead of asking him to type.
// Client id and secret are typed into the site and stay in the database, never in Git.

const CV_STRAVA_SPORTS = [
    'Run' => 'corrida', 'TrailRun' => 'corrida', 'VirtualRun' => 'corrida',
    'Ride' => 'outro', 'VirtualRide' => 'outro', 'EBikeRide' => 'outro', 'MountainBikeRide' => 'outro',
    'Walk' => 'caminhada', 'Hike' => 'caminhada',
    'WeightTraining' => 'forca', 'Workout' => 'forca', 'Crossfit' => 'forca',
    'Soccer' => 'futebol', 'Yoga' => 'mobilidade',
];

function cv_strava_api(): string { return rtrim(getenv('CV_STRAVA_API') ?: 'https://www.strava.com/api/v3', '/'); }
function cv_strava_oauth(): string { return rtrim(getenv('CV_STRAVA_OAUTH') ?: 'https://www.strava.com/oauth', '/'); }
function cv_strava_redirect(): string { return cv_url() . '/strava.php?route=callback'; }

function cv_http(string $url, ?array $form = null, array $headers = []): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => $headers]);
        if ($form !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => ['method' => $form === null ? 'GET' : 'POST', 'timeout' => 25,
            'ignore_errors' => true, 'header' => implode("\r\n", array_merge($headers, $form === null ? [] : ['Content-Type: application/x-www-form-urlencoded'])),
            'content' => $form === null ? null : http_build_query($form)]]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) $status = (int)$m[1];
    }
    if ($body === false) cv_fail('Não foi possível falar com o Strava agora.', 503);
    return [$status, json_decode((string)$body, true)];
}

function cv_strava_settings(string $user): array {
    $data = cv_settings($user);
    return is_array($data['strava'] ?? null) ? $data['strava'] : [];
}
function cv_strava_store(string $user, array $strava): void {
    $data = cv_settings($user);
    if ($strava) $data['strava'] = $strava; else unset($data['strava']);
    cv_save_settings($user, $data);
}

// Access tokens last six hours; the refresh token is what keeps the connection alive.
function cv_strava_access(string $user, array &$strava): string {
    if (!empty($strava['access_token']) && ($strava['expires_at'] ?? 0) > time() + 120) return $strava['access_token'];
    if (empty($strava['refresh_token'])) cv_fail('Conecte o Strava primeiro.', 400);
    [$status, $data] = cv_http(cv_strava_oauth() . '/token', [
        'client_id' => $strava['client_id'] ?? '', 'client_secret' => $strava['client_secret'] ?? '',
        'grant_type' => 'refresh_token', 'refresh_token' => $strava['refresh_token']]);
    if ($status !== 200 || empty($data['access_token'])) cv_fail('A conexão com o Strava expirou. Conecte de novo.', 401);
    $strava['access_token'] = $data['access_token'];
    $strava['refresh_token'] = $data['refresh_token'] ?? $strava['refresh_token'];
    $strava['expires_at'] = (int)($data['expires_at'] ?? time() + 3600);
    cv_strava_store($user, $strava);
    return $strava['access_token'];
}

function cv_strava_note(array $activity, float $km, int $minutes): string {
    $parts = [];
    if ($km > 0) $parts[] = number_format($km, 2, ',', '.') . ' km';
    if ($minutes > 0) $parts[] = $minutes . ' min';
    $seconds = (int)($activity['moving_time'] ?? 0);
    if ($km > 0 && $seconds > 0) {
        $pace = (int)round($seconds / $km);
        $parts[] = sprintf('%d:%02d/km', intdiv($pace, 60), $pace % 60);
    }
    if (!empty($activity['average_heartrate'])) $parts[] = round((float)$activity['average_heartrate']) . ' bpm em média';
    if (!empty($activity['total_elevation_gain'])) $parts[] = round((float)$activity['total_elevation_gain']) . ' m de subida';
    return 'Importado do Strava' . ($parts ? ': ' . implode(' · ', $parts) : '') . '.';
}

// Each activity becomes a record of what happened; the matching planned session is ticked off.
function cv_strava_import(string $user, array $activities): array {
    $report = ['novos' => 0, 'repetidos' => 0, 'marcados' => 0, 'ignorados' => 0];
    $planned = array_values(array_filter(cv_list($user),
        fn($r) => $r['kind'] === 'workout' && ($r['details']['recurrence'] ?? 'once') !== 'once'));
    $tick = [];
    cv_db()->beginTransaction();
    try {
        foreach ($activities as $activity) {
            $stravaId = (string)($activity['id'] ?? '');
            $day = substr((string)($activity['start_date_local'] ?? ''), 0, 10);
            if ($stravaId === '' || $day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { $report['ignorados']++; continue; }
            $sport = CV_STRAVA_SPORTS[$activity['sport_type'] ?? $activity['type'] ?? ''] ?? 'outro';
            $id = md5('strava:' . $stravaId);
            if (cv_query('SELECT id FROM cv_records WHERE id=?', [$id])->fetchColumn()) { $report['repetidos']++; continue; }

            $km = round(((float)($activity['distance'] ?? 0)) / 1000, 2);
            $minutes = min(1440, (int)round(((int)($activity['moving_time'] ?? 0)) / 60));
            $details = cv_details('workout', ['activity' => $sport, 'duration_min' => $minutes, 'notes' => cv_strava_note($activity, $km, $minutes)], []);
            $title = mb_substr(trim((string)($activity['name'] ?? '')) ?: 'Atividade', 0, 200);
            cv_query('INSERT INTO cv_records (id,user_id,kind,title,day,status,details,revision,created_at,updated_at) VALUES (?,?,?,?,?,?,?,1,?,?)',
                [$id, $user, 'workout', $title, $day, 'done', json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), cv_now(), cv_now()]);
            $report['novos']++;

            foreach ($planned as $plan) {
                if (($plan['details']['activity'] ?? '') !== $sport || !cv_due($plan, $day)) continue;
                $tick[] = [$plan['id'], $day];
                break;
            }
        }
        cv_audit($user, 'strava.sync', '', 'strava');
        cv_db()->commit();
    } catch (Throwable $e) { cv_db()->rollBack(); throw $e; }
    // cv_mark opens a transaction of its own, so the planned sessions are ticked after the import commits.
    foreach ($tick as [$id, $day]) {
        $plan = cv_get($user, $id);
        if (cv_done($plan, $day)) continue;
        cv_mark($user, ['id' => $plan['id'], 'revision' => $plan['revision'], 'done' => true, 'day' => $day], 'strava');
        $report['marcados']++;
    }
    return $report;
}

function cv_strava_sync(string $user): array {
    $strava = cv_strava_settings($user);
    if (empty($strava['refresh_token'])) cv_fail('Conecte o Strava primeiro.', 400);
    $token = cv_strava_access($user, $strava);
    // First run reaches back 45 days; after that, only what happened since the last sync.
    $after = (int)($strava['last_sync'] ?? strtotime('-45 days'));
    [$status, $activities] = cv_http(cv_strava_api() . '/athlete/activities?' . http_build_query(['after' => $after - 3600, 'per_page' => 100]),
        null, ['Authorization: Bearer ' . $token]);
    if ($status === 401) cv_fail('A conexão com o Strava expirou. Conecte de novo.', 401);
    if ($status !== 200 || !is_array($activities)) cv_fail('O Strava não respondeu como esperado.', 503);
    $report = cv_strava_import($user, $activities);
    $strava = cv_strava_settings($user);
    $strava['last_sync'] = time();
    cv_strava_store($user, $strava);
    return $report + ['em' => cv_now()];
}
