<?php
declare(strict_types=1);
require_once __DIR__ . '/server/strava.php';
cv_headers();

// Web routes of the Strava connection. Everything here needs the signed-in session: the
// credentials are typed in Ajustes and the authorisation happens in his own browser, so no
// secret ever travels through a chat message or a link.

try {
    cv_session();
    $user = cv_user();
    if (!$user) { header('Location: /controlevida/'); exit; }
    $uid = $user['id'];
    $route = $_GET['route'] ?? '';
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';

    // Strava appends code, scope and state to the bare file, so that is how the callback announces itself.
    if (!$post && ($route === 'callback' || isset($_GET['code']) || isset($_GET['error']))) {
        $state = $_GET['state'] ?? '';
        if (empty($_SESSION['strava_state']) || !hash_equals($_SESSION['strava_state'], (string)$state)) cv_fail('Esta autorização mudou. Tente conectar de novo.', 403);
        unset($_SESSION['strava_state']);
        if (!empty($_GET['error'])) { header('Location: /controlevida/#settings'); exit; }
        $strava = cv_strava_settings($uid);
        [$status, $data] = cv_http(cv_strava_oauth() . '/token', [
            'client_id' => $strava['client_id'] ?? '', 'client_secret' => $strava['client_secret'] ?? '',
            'code' => (string)($_GET['code'] ?? ''), 'grant_type' => 'authorization_code']);
        if ($status !== 200 || empty($data['refresh_token'])) cv_fail('O Strava recusou a autorização. Confira o Client ID e o Secret.', 400);
        $strava['refresh_token'] = $data['refresh_token'];
        $strava['access_token'] = $data['access_token'] ?? '';
        $strava['expires_at'] = (int)($data['expires_at'] ?? 0);
        $strava['atleta'] = trim(($data['athlete']['firstname'] ?? '') . ' ' . ($data['athlete']['lastname'] ?? '')) ?: 'conectado';
        cv_strava_store($uid, $strava);
        cv_audit($uid, 'strava.connect', '', 'web');
        header('Location: /controlevida/#settings');
        exit;
    }

    if (!$post) cv_fail('Método não permitido.', 405);
    cv_csrf();

    if ($route === 'connect') {
        // Asked for by fetch, like every other route here, so the browser reports the same origin
        // it does everywhere else: a plain form POST does not, and was refused.
        $strava = cv_strava_settings($uid);
        if (empty($strava['client_id']) || empty($strava['client_secret'])) cv_fail('Informe o Client ID e o Client Secret antes de conectar.');
        $_SESSION['strava_state'] = cv_secret();
        $report = ['url' => (getenv('CV_STRAVA_AUTHORIZE') ?: 'https://www.strava.com/oauth/authorize') . '?' . http_build_query([
            'client_id' => $strava['client_id'],
            'redirect_uri' => cv_strava_redirect(),
            'response_type' => 'code',
            'approval_prompt' => 'auto',
            // Reading past activities is the whole point of the connection.
            'scope' => 'activity:read_all',
            'state' => $_SESSION['strava_state'],
        ])];
    } elseif ($route === 'credentials') {
        $in = cv_input();
        $strava = cv_strava_settings($uid);
        $id = preg_replace('/\D/', '', (string)($in['client_id'] ?? ''));
        $secret = trim((string)($in['client_secret'] ?? ''));
        if ($id === '' || !preg_match('/^[A-Za-z0-9]{20,80}$/', $secret)) cv_fail('Confira o Client ID e o Client Secret copiados do Strava.');
        // New credentials invalidate any previous authorisation.
        cv_strava_store($uid, ['client_id' => $id, 'client_secret' => $secret] + (($strava['client_id'] ?? '') === $id ? $strava : []));
    } elseif ($route === 'sync') {
        $report = cv_strava_sync($uid);
    } elseif ($route === 'disconnect') {
        cv_strava_store($uid, []);
        cv_audit($uid, 'strava.disconnect', '', 'web');
    } else cv_fail('Página não encontrada.', 404);

    cv_json(['ok' => true, 'data' => ($report ?? []) + ['settings' => cv_settings_safe(cv_settings($uid))]]);
} catch (DomainException $e) {
    cv_json(['ok' => false, 'error' => $e->getMessage()], $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('ControleVida strava: ' . get_class($e) . ': ' . $e->getMessage());
    cv_json(['ok' => false, 'error' => 'Não foi possível concluir. Tente novamente.'], 503);
}
