<?php
declare(strict_types=1);
require_once __DIR__ . '/server/records.php';
cv_headers();

// Progress photos. They live outside public_html, are served only to the signed-in owner, and are
// deliberately kept out of the records table: that way they never reach the calendar feed, the
// widget or the assistant's tools. A body photo is the one thing here that must not travel by link.

const CV_PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const CV_PHOTO_MAX = 12582912;   // 12 MB, enough for anything a phone produces
const CV_PHOTO_SIDE = 1600;      // a comparison never needs more than this

function cv_photo_dir(): string {
    $dir = cv_config()['data_dir'] . '/fotos';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) cv_fail('Não foi possível guardar a foto.', 500);
    return $dir;
}

function cv_photo_file(array $row): string {
    return cv_photo_dir() . '/' . $row['id'] . '.' . (CV_PHOTO_TYPES[$row['mime']] ?? 'jpg');
}

// Re-encoding also drops the EXIF block, and with it where the photo was taken.
function cv_photo_save(string $source, string $target): string {
    $data = file_get_contents($source);
    if (!function_exists('imagecreatefromstring')) {
        if (file_put_contents($target, $data, LOCK_EX) === false) cv_fail('Não foi possível guardar a foto.', 500);
        return '';
    }
    $image = @imagecreatefromstring($data);
    if (!$image) cv_fail('Não consegui ler essa imagem. Tente JPEG ou PNG.');
    // Phones record orientation in EXIF instead of rotating the pixels.
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($source);
        $turn = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 0] ?? 0;
        if ($turn !== 0) { $rotated = @imagerotate($image, $turn, 0); if ($rotated) { imagedestroy($image); $image = $rotated; } }
    }
    $side = max(imagesx($image), imagesy($image));
    if ($side > CV_PHOTO_SIDE) {
        $scaled = @imagescale($image, (int)round(imagesx($image) * CV_PHOTO_SIDE / $side));
        if ($scaled) { imagedestroy($image); $image = $scaled; }
    }
    $ok = imagejpeg($image, $target, 86);
    imagedestroy($image);
    if (!$ok) cv_fail('Não foi possível guardar a foto.', 500);
    return 'image/jpeg';
}

try {
    cv_schema();
    cv_session();
    $user = cv_user();
    if (!$user) cv_fail('Entre para continuar.', 401);
    $uid = $user['id'];
    $action = $_GET['action'] ?? 'list';
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';

    if ($action === 'file') {
        $row = cv_query('SELECT * FROM cv_photos WHERE id=? AND user_id=?', [cv_text($_GET['id'] ?? '', 40), $uid])->fetch();
        if (!$row) cv_fail('Foto não encontrada.', 404);
        $file = cv_photo_file($row);
        if (!is_file($file)) cv_fail('Foto não encontrada.', 404);
        header('Content-Type: ' . $row['mime']);
        header('Content-Length: ' . filesize($file));
        // The only cacheable response in the app: it is immutable per id and stays behind the session.
        header('Cache-Control: private, max-age=3600');
        readfile($file);
        exit;
    }

    if ($action === 'upload' && $post) {
        cv_csrf();
        $file = $_FILES['foto'] ?? null;
        if (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) cv_fail('Envie uma imagem.');
        if ($file['size'] > CV_PHOTO_MAX) cv_fail('Imagem muito grande. O limite é 12 MB.', 413);
        if (!is_uploaded_file($file['tmp_name'])) cv_fail('Envio inválido.');
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(CV_PHOTO_TYPES[$mime])) cv_fail('Formato não aceito. Use JPEG, PNG ou WebP.');
        $day = cv_day($_POST['day'] ?? date('Y-m-d'));
        $id = cv_id();
        $target = cv_photo_dir() . '/' . $id . '.' . CV_PHOTO_TYPES[$mime];
        $stored = cv_photo_save($file['tmp_name'], $target);
        if ($stored !== '' && $stored !== $mime) {
            $renamed = cv_photo_dir() . '/' . $id . '.jpg';
            if ($target !== $renamed) @rename($target, $renamed);
            $mime = $stored;
        }
        cv_query('INSERT INTO cv_photos (id,user_id,day,mime,created_at) VALUES (?,?,?,?,?)', [$id, $uid, $day, $mime, cv_now()]);
        cv_audit($uid, 'photo.add', $id, 'web');
    } elseif ($action === 'delete' && $post) {
        cv_csrf();
        $row = cv_query('SELECT * FROM cv_photos WHERE id=? AND user_id=?', [cv_text(cv_input()['id'] ?? '', 40), $uid])->fetch();
        if (!$row) cv_fail('Foto não encontrada.', 404);
        @unlink(cv_photo_file($row));
        cv_query('DELETE FROM cv_photos WHERE id=? AND user_id=?', [$row['id'], $uid]);
        cv_audit($uid, 'photo.delete', $row['id'], 'web');
    } elseif ($action !== 'list') cv_fail('Ação não encontrada.', 404);

    cv_json(['ok' => true, 'data' => cv_query('SELECT id,day,created_at FROM cv_photos WHERE user_id=? ORDER BY day ASC, created_at ASC', [$uid])->fetchAll()]);
} catch (DomainException $e) {
    cv_json(['ok' => false, 'error' => $e->getMessage()], $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('ControleVida photos: ' . get_class($e));
    cv_json(['ok' => false, 'error' => 'Não foi possível concluir. Tente novamente.'], 503);
}
