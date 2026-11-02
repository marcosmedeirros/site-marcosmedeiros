<?php
declare(strict_types=1);
// /controlevida/miro is a doorway: the app itself opens already on the board.
require_once __DIR__ . '/server/core.php';
cv_headers();
header('Location: ' . (cv_config_file() === null ? '/controlevida/install.php' : '/controlevida/#miro'), true, 302);
