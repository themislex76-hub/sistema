<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Llamado por el robot de monitoreo Federal (no por el frontend) para
// obtener TODAS las cuentas del Portal Federal que los abogados del
// despacho hayan guardado, y así revisar los expedientes de cada uno por
// turno. Descifra la contraseña aquí mismo, server-side — el robot
// necesita el texto plano para poder iniciar sesión de verdad en el
// portal del PJF.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_robot_key();

$pdo = db();
$rows = $pdo->query(
    "SELECT id, nombre, pjf_usuario, pjf_password_enc
     FROM usuarios
     WHERE pjf_usuario IS NOT NULL AND pjf_usuario <> '' AND pjf_password_enc IS NOT NULL"
)->fetchAll();

$cuentas = [];
foreach ($rows as $r) {
    $cuentas[] = [
        'usuario_id' => (int)$r['id'],
        'nombre' => $r['nombre'],
        'pjf_usuario' => $r['pjf_usuario'],
        'pjf_password' => pjf_decrypt($r['pjf_password_enc']),
    ];
}

respond(['cuentas' => $cuentas]);
