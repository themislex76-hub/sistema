<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$path = __DIR__ . '/../data/plantilla_demanda.docx';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    require_login();
    if (!file_exists($path)) respond(['custom' => false]);
    respond(['custom' => true, 'base64' => base64_encode(file_get_contents($path))]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    require_csrf();
    $in = json_input();
    $action = (string)($in['action'] ?? 'upload');

    if ($action === 'reset') {
        if (file_exists($path)) unlink($path);
        respond(['custom' => false]);
    }

    $b64 = (string)($in['base64'] ?? '');
    if ($b64 === '') fail('Falta el archivo.', 400);
    $bytes = base64_decode($b64, true);
    if ($bytes === false || strlen($bytes) < 4 || substr($bytes, 0, 2) !== 'PK') {
        fail('El archivo no parece un .docx válido.', 400);
    }
    file_put_contents($path, $bytes);
    respond(['custom' => true]);
}

fail('Método no permitido.', 405);
