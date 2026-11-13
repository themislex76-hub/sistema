<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$texto = trim((string)($in['texto'] ?? ''));
if ($id <= 0) fail('Falta el id del expediente.', 400);
if ($texto === '') fail('La nota no puede estar vacía.', 400);

$pdo = db();
guard_expediente_access($pdo, $user, $id);

$stmt = $pdo->prepare(
    'INSERT INTO expediente_notas (expediente_id, usuario_id, usuario_nombre, texto) VALUES (:eid, :uid, :uname, :texto)'
);
$stmt->execute([':eid' => $id, ':uid' => $user['id'], ':uname' => $user['nombre'], ':texto' => $texto]);

// expediente_notas es una tabla aparte -- guardar aquí NO toca
// expedientes.actualizado_en solo. Sin este UPDATE explícito, el informe
// ejecutivo con IA (que ahora también lee esta bitácora, ver
// ia_generar_resumen_expediente()) nunca se enteraría de que hay una nota
// nueva y seguiría mostrando una "próxima acción" vieja hasta que algo más
// (Etapas, Editar datos, etc.) bumpeara la fecha por su cuenta -- bug real
// encontrado 13 nov 2026, mismo patrón que ya se corrigió para
// expedientes_update_etapas.php.
$pdo->prepare('UPDATE expedientes SET actualizado_en = NOW() WHERE id = :id')->execute([':id' => $id]);

respond(['ok' => true, 'fecha' => date('Y-m-d H:i:s')]);
