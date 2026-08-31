<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// El navegador manda aquí, en silencio, los pendientes de los próximos días
// (audiencias, pagos, prescripción, amparo, pendientes/atrasos) tal como los
// calculó buildAgendaEntries() -- este endpoint solo los guarda en caché
// para que el cron diario (cron_recordatorio_abogado.php) los lea y avise
// por notificación push. Nunca recalcula ninguna fecha aquí.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$entries = is_array($in['entries'] ?? null) ? $in['entries'] : [];
$todos = !empty($in['todos']) && $user['rol'] === 'administrador';

$pdo = db();

// Alcance de expedientes que el navegador pudo ver al calcular $entries:
// administrador ve todos, un abogado normal solo los propios (mismo
// criterio que expedientes_bootstrap.php) -- así solo se toca el caché de
// lo que este usuario de verdad tenía enfrente.
if ($todos) {
    $idsVisibles = array_map('intval', $pdo->query('SELECT id FROM expedientes')->fetchAll(PDO::FETCH_COLUMN));
} else {
    $stmt = $pdo->prepare('SELECT id FROM expedientes WHERE abogado_id = :uid');
    $stmt->execute([':uid' => $user['id']]);
    $idsVisibles = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

if (!$idsVisibles) respond(['ok' => true]);

$upsert = $pdo->prepare(
    'INSERT INTO agenda_avisos_cache (expediente_id, clave, tipo, fecha, hora, label)
     VALUES (:eid, :clave, :tipo, :fecha, :hora, :label)
     ON DUPLICATE KEY UPDATE tipo = VALUES(tipo), fecha = VALUES(fecha), hora = VALUES(hora), label = VALUES(label)'
);

$enviadas = [];
foreach ($entries as $e) {
    if (!is_array($e)) continue;
    $eid = (int)($e['expediente_id'] ?? 0);
    if (!in_array($eid, $idsVisibles, true)) continue; // fuera de lo que este usuario pudo haber visto
    $clave = substr((string)($e['clave'] ?? ''), 0, 40);
    $tipo = substr((string)($e['tipo'] ?? ''), 0, 30);
    $fecha = (string)($e['fecha'] ?? '');
    if ($clave === '' || $tipo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) continue;
    $hora = ($e['hora'] ?? '') !== '' ? substr((string)$e['hora'], 0, 8) : null;
    $label = substr((string)($e['label'] ?? ''), 0, 255);
    $upsert->execute([':eid' => $eid, ':clave' => $clave, ':tipo' => $tipo, ':fecha' => $fecha, ':hora' => $hora, ':label' => $label]);
    $enviadas[] = $eid . ':' . $clave;
}

// Limpia del caché lo que ya no aplica (se cumplió, se movió fuera de la
// ventana, el caso se concluyó) dentro del mismo alcance visible.
$placeholders = implode(',', array_fill(0, count($idsVisibles), '?'));
$actuales = $pdo->prepare("SELECT id, expediente_id, clave FROM agenda_avisos_cache WHERE expediente_id IN ($placeholders)");
$actuales->execute($idsVisibles);
$delStmt = $pdo->prepare('DELETE FROM agenda_avisos_cache WHERE id = :id');
foreach ($actuales->fetchAll() as $row) {
    $key = $row['expediente_id'] . ':' . $row['clave'];
    if (!in_array($key, $enviadas, true)) {
        $delStmt->execute([':id' => $row['id']]);
    }
}

respond(['ok' => true]);
