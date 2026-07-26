<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT evento_id FROM google_calendar_sync WHERE usuario_id = :id');
    $stmt->execute([':id' => $user['id']]);
    respond(['evento_ids' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $in = json_input();
    $ids = $in['evento_ids'] ?? [];
    if (!is_array($ids)) fail('evento_ids debe ser una lista.', 400);
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));

    $pdo = db();
    $pdo->beginTransaction();
    $del = $pdo->prepare('DELETE FROM google_calendar_sync WHERE usuario_id = :id');
    $del->execute([':id' => $user['id']]);
    if ($ids) {
        $ins = $pdo->prepare('INSERT INTO google_calendar_sync (usuario_id, evento_id) VALUES (:uid, :eid)');
        foreach ($ids as $eid) {
            $ins->execute([':uid' => $user['id'], ':eid' => $eid]);
        }
    }
    $pdo->commit();
    respond(['ok' => true]);
}

fail('Método no permitido.', 405);
