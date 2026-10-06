<?php
declare(strict_types=1);
require_once __DIR__ . '/server/records.php';

// Read-only calendar feed for phones (iPhone, Android) and for Google Calendar.
// A subscription has no place to sign in, so the long random token in the URL is the whole
// credential: it is generated on demand, never indexed, and revocable from Ajustes.

header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');

const CV_ICS_KINDS = ['event', 'task', 'workout'];
const CV_ICS_DAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

function cv_ics_text(string $value): string {
    return str_replace(["\\", "\n", ",", ";"], ["\\\\", "\\n", "\\,", "\\;"], trim($value));
}

// Long values must be folded at 75 octets or strict parsers reject the line.
function cv_ics_fold(string $line): string {
    if (strlen($line) <= 73) return $line;
    $out = substr($line, 0, 73);
    $rest = substr($line, 73);
    foreach (str_split($rest, 72) as $chunk) $out .= "\r\n " . $chunk;
    return $out;
}

function cv_ics_utc(string $day, string $time): string {
    return (new DateTimeImmutable($day . ' ' . $time, new DateTimeZone('America/Sao_Paulo')))
        ->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
}

// A repeating record has no date of its own, so the series starts at its first real occurrence.
function cv_ics_first(array $record): string {
    $d = $record['details'];
    $recurrence = $d['recurrence'] ?? 'once';
    $anchor = $record['day'] !== '' ? $record['day'] : substr($record['created_at'], 0, 10);
    $from = new DateTimeImmutable($anchor);
    if ($recurrence === 'weekly') {
        $weekdays = $d['weekdays'] ?? [];
        if (!$weekdays) return '';
        for ($i = 0; $i < 7; $i++) {
            $try = $from->modify("+$i day");
            if (in_array((int)$try->format('N'), $weekdays, true)) return $try->format('Y-m-d');
        }
        return '';
    }
    if ($recurrence === 'monthly') {
        $day = (int)($d['month_day'] ?? 1);
        for ($i = 0; $i < 14; $i++) {
            $month = $from->modify("first day of +$i month");
            if (!checkdate((int)$month->format('n'), $day, (int)$month->format('Y'))) continue;
            $candidate = $month->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
            if ($candidate >= $anchor) return $candidate;
        }
        return '';
    }
    return $anchor;
}

function cv_ics_rule(array $details): string {
    $weekdays = $details['weekdays'] ?? [];
    return match ($details['recurrence'] ?? 'once') {
        'daily' => 'RRULE:FREQ=DAILY',
        'weekly' => $weekdays ? 'RRULE:FREQ=WEEKLY;BYDAY=' . implode(',', array_map(fn($n) => CV_ICS_DAYS[$n - 1], $weekdays)) : '',
        'monthly' => 'RRULE:FREQ=MONTHLY;BYMONTHDAY=' . (int)($details['month_day'] ?? 1),
        default => '',
    };
}

function cv_ics_event(array $record, string $stamp): array {
    $d = $record['details'];
    $start = cv_ics_first($record);
    if ($start === '') return [];
    $lines = ['BEGIN:VEVENT', 'UID:' . $record['id'] . '@controlevida', 'DTSTAMP:' . $stamp];
    $time = $d['time'] ?? '';
    if ($time !== '') {
        $lines[] = 'DTSTART:' . cv_ics_utc($start, $time);
        $end = $d['end_time'] ?? '';
        $minutes = $end !== '' ? null : max(15, (int)($d['duration_min'] ?? 0) ?: ($record['kind'] === 'workout' ? 45 : 30));
        $lines[] = 'DTEND:' . ($end !== '' ? cv_ics_utc($start, $end)
            : (new DateTimeImmutable($start . ' ' . $time, new DateTimeZone('America/Sao_Paulo')))
                ->modify("+$minutes minutes")->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'));
    } else {
        $lines[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $start);
        $lines[] = 'DTEND;VALUE=DATE:' . (new DateTimeImmutable($start))->modify('+1 day')->format('Ymd');
    }
    $rule = cv_ics_rule($d);
    if ($rule !== '') $lines[] = $rule;
    $lines[] = 'SUMMARY:' . cv_ics_text($record['title']);
    $lines[] = 'CATEGORIES:' . ['event' => 'Agenda', 'task' => 'Tarefa', 'workout' => 'Treino'][$record['kind']];
    if (!empty($d['notes'])) $lines[] = 'DESCRIPTION:' . cv_ics_text($d['notes']);
    if (!empty($d['location'])) $lines[] = 'LOCATION:' . cv_ics_text($d['location']);
    $lines[] = 'TRANSP:TRANSPARENT';
    $lines[] = 'END:VEVENT';
    return $lines;
}

try {
    $token = $_GET['t'] ?? '';
    $user = '';
    if (preg_match('/^[A-Za-z0-9_-]{30,120}$/', $token)) {
        foreach (cv_query('SELECT user_id,data FROM cv_settings')->fetchAll() as $row) {
            $saved = json_decode($row['data'], true, 32, JSON_THROW_ON_ERROR)['calendar_token'] ?? '';
            if (is_string($saved) && $saved !== '' && hash_equals($saved, $token)) { $user = $row['user_id']; break; }
        }
    }
    // A wrong token is indistinguishable from a feed that was never turned on.
    if ($user === '') { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Pagina nao encontrada.'; exit; }

    $stamp = gmdate('Ymd\THis\Z');
    $horizon = date('Y-m-d', strtotime('-30 days'));
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Controle Vida//PT-BR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'X-WR-CALNAME:Controle Vida', 'X-WR-TIMEZONE:America/Sao_Paulo',
        'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H'];
    foreach (cv_list($user) as $record) {
        if (!in_array($record['kind'], CV_ICS_KINDS, true)) continue;
        $repeats = ($record['details']['recurrence'] ?? 'once') !== 'once';
        // Old one-off entries would only clutter the phone; repeating ones always belong.
        if (!$repeats && ($record['day'] === '' || $record['day'] < $horizon)) continue;
        array_push($lines, ...cv_ics_event($record, $stamp));
    }
    $lines[] = 'END:VCALENDAR';
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="controlevida.ics"');
    echo implode("\r\n", array_map('cv_ics_fold', $lines)) . "\r\n";
} catch (Throwable $e) {
    error_log('ControleVida calendar: ' . get_class($e));
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Servico indisponivel.';
}
