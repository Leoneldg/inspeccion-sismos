<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

requierePermiso('ingenieros', 'ver');

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ingenieros' => obtenerIngenierosActivos()], JSON_UNESCAPED_UNICODE);
