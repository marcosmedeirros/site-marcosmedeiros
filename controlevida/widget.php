<?php
declare(strict_types=1);
require_once __DIR__ . '/server/records.php';

// Feeds the home-screen widget on the phone (Scriptable, on iOS). A widget has no place to sign
// in either, so the same rule as the calendar applies: long random token in the URL, revocable in
// Ajustes, and nothing about money in the payload.

header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');

// Mirrors the rules the interface uses in assets/app.js; keep both in step.
function cv_w_done(array $r, string $day): bool {
    $recurrence = $r['details']['recurrence'] ?? 'once';
    return $recurrence !== 'once'
        ? in_array($day, $r['details']['completed_dates'] ?? [], true)
        : $r['status'] === 'done';
}
function cv_w_due(array $r, string $day): bool {
    $d = $r['details'];
    if ($r['day'] !== '' && $r['day'] > $day) return false;
    return match ($d['recurrence'] ?? 'once') {
        'daily' => true,
        'weekly' => in_array((int)date('N', strtotime($day)), $d['weekdays'] ?? [], true),
        'monthly' => (int)substr($day, 8, 2) === (int)($d['month_day'] ?? 1),
        default => $r['day'] === '' || $r['day'] === $day
            || ($r['kind'] === 'task' && $r['day'] < $day && !cv_w_done($r, $day)),
    };
}

function cv_widget_script(string $url): string {
    $json = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return <<<SCRIPT
// Controle Vida — widget para o Scriptable.
// Cole no Scriptable, salve, e adicione o widget na tela de inicio escolhendo este script.
// O endereco abaixo e pessoal: vale como senha, nao compartilhe.
const ENDERECO = $json;

const FUNDO = new Color("#000000");
const TINTA = new Color("#f1f1f1");
const FRACO = new Color("#8e8e8e");
const AZUL  = new Color("#8ab4ff");

let dados = null;
try {
  const pedido = new Request(ENDERECO);
  pedido.timeoutInterval = 15;
  dados = await pedido.loadJSON();
} catch (erro) {
  dados = null;
}

const w = new ListWidget();
w.backgroundColor = FUNDO;
w.setPadding(14, 15, 14, 15);
w.url = "https://marcosmedeiros.site/controlevida/";

const texto = (pai, valor, cor, fonte, limite) => {
  const t = pai.addText(valor);
  t.textColor = cor;
  t.font = fonte;
  if (limite) t.lineLimit = limite;
  return t;
};

if (!dados || !dados.hoje) {
  texto(w, "Controle Vida", TINTA, Font.semiboldSystemFont(14));
  w.addSpacer(6);
  texto(w, "Sem conexao com o site.", FRACO, Font.systemFont(11));
} else {
  const topo = w.addStack();
  texto(topo, dados.titulo.toUpperCase(), FRACO, Font.semiboldSystemFont(9.5));
  topo.addSpacer();
  if (dados.proximo) texto(topo, dados.proximo.dias + "d", AZUL, Font.semiboldSystemFont(9.5));

  w.addSpacer(7);
  const faltam = dados.hoje.total - dados.hoje.feitos;
  texto(w, dados.hoje.feitos + "/" + dados.hoje.total, TINTA, Font.boldSystemFont(32));
  texto(w, faltam === 0 ? "dia fechado" : faltam + (faltam === 1 ? " pendente" : " pendentes"), FRACO, Font.systemFont(11));

  w.addSpacer(9);
  for (const item of dados.hoje.itens.slice(0, 4)) {
    const linha = w.addStack();
    linha.spacing = 6;
    linha.centerAlignContent();
    texto(linha, item.feito ? "●" : "○", item.feito ? AZUL : FRACO, Font.systemFont(9));
    texto(linha, item.titulo, item.feito ? FRACO : TINTA, Font.systemFont(11.5), 1);
    w.addSpacer(3);
  }

  w.addSpacer();
  texto(w, "Treinos da semana " + dados.semana.feitos + "/" + dados.semana.total, FRACO, Font.systemFont(9.5));
}

if (config.runsInWidget) Script.setWidget(w);
else w.presentMedium();
Script.complete();
SCRIPT;
}

try {
    $token = $_GET['t'] ?? '';
    $user = '';
    if (preg_match('/^[A-Za-z0-9_-]{30,120}$/', $token)) {
        foreach (cv_query('SELECT user_id,data FROM cv_settings')->fetchAll() as $row) {
            $saved = json_decode($row['data'], true, 32, JSON_THROW_ON_ERROR)['widget_token'] ?? '';
            if (is_string($saved) && $saved !== '' && hash_equals($saved, $token)) { $user = $row['user_id']; break; }
        }
    }
    if ($user === '') { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Pagina nao encontrada.'; exit; }

    $base = cv_url() . '/widget.php?t=' . $token;
    if (isset($_GET['script'])) {
        header('Content-Type: text/plain; charset=utf-8');
        echo cv_widget_script($base);
        exit;
    }

    $today = date('Y-m-d');
    $records = cv_list($user);
    $itens = []; $feitos = 0;
    foreach ($records as $r) {
        if (!in_array($r['kind'], ['task', 'habit', 'workout'], true) || !cv_w_due($r, $today)) continue;
        $done = cv_w_done($r, $today);
        $feitos += $done ? 1 : 0;
        // Unfinished first, so a glance at the widget shows what is still missing.
        $itens[] = ['titulo' => $r['title'], 'feito' => $done, 'tipo' => $r['kind'], 'ordem' => ($done ? 1 : 0)];
    }
    usort($itens, fn($a, $b) => $a['ordem'] <=> $b['ordem']);
    $itens = array_map(fn($i) => ['titulo' => $i['titulo'], 'feito' => $i['feito'], 'tipo' => $i['tipo']], $itens);

    $monday = date('Y-m-d', strtotime('monday this week'));
    $semana = ['feitos' => 0, 'total' => 0];
    for ($i = 0; $i < 7; $i++) {
        $day = date('Y-m-d', strtotime("$monday +$i day"));
        foreach ($records as $r) {
            if ($r['kind'] !== 'workout' || !cv_w_due($r, $day)) continue;
            $semana['total']++;
            if (cv_w_done($r, $day)) $semana['feitos']++;
        }
    }

    $proximo = null;
    foreach ($records as $r) {
        if ($r['kind'] !== 'event' || $r['day'] < $today || $r['status'] === 'done') continue;
        if ($proximo === null || $r['day'] < $proximo['dia']) {
            $proximo = ['titulo' => $r['title'], 'dia' => $r['day'],
                'dias' => (int)((strtotime($r['day']) - strtotime($today)) / 86400)];
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'titulo' => 'Hoje',
        'dia' => $today,
        'hoje' => ['feitos' => $feitos, 'total' => count($itens), 'itens' => $itens],
        'semana' => $semana,
        'proximo' => $proximo,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('ControleVida widget: ' . get_class($e));
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"erro":"indisponivel"}';
}
