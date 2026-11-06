<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/prospectos_helpers.php';

// Pausa o reactiva el bot para un número de WhatsApp desde Conversaciones
// (WhatsApp) -- a diferencia de prospectos_update.php, funciona AUNQUE la
// conversación todavía no esté clasificada como ningún tipo de prospecto
// (crea un registro mínimo tipo 'reclamo' la primera vez que se pausa,
// ya que pausar manualmente una conversación sin clasificar es, en los
// hechos, marcarla como que necesita atención humana urgente).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_admin();
require_csrf();

$in = json_input();
$telefono = trim((string)($in['telefono'] ?? ''));
$pausar = !empty($in['pausado_bot']);
if ($telefono === '') fail('Falta el teléfono.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM prospectos WHERE telefono = :t LIMIT 1');
$stmt->execute([':t' => $telefono]);
$existente = $stmt->fetch();

if ($existente) {
    $pdo->prepare('UPDATE prospectos SET pausado_bot = :p WHERE id = :id')
        ->execute([':p' => $pausar ? 1 : 0, ':id' => $existente['id']]);
} elseif ($pausar) {
    guardar_prospecto($pdo, $telefono, null, [
        'tipo' => 'reclamo',
        'estado' => '',
        'nombre' => '',
        'resumen' => 'Conversación pausada manualmente desde Conversaciones (WhatsApp) por un administrador.',
    ], true, true);
}
// Si no existe y se pide "activar" no hay nada que hacer -- sin registro
// de prospecto el bot ya contesta normal por default.

respond(['ok' => true]);
