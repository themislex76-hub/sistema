<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Llamado por el robot de notificaciones de Edomex (no por el frontend)
// para obtener TODAS las cuentas de correo que los abogados del despacho
// hayan guardado, y así revisar la bandeja de cada uno por turno. Descifra
// la contraseña aquí mismo, server-side -- el robot necesita el texto
// plano para poder iniciar sesión de verdad en el correo.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_robot_key();

$pdo = db();
$rows = $pdo->query(
    "SELECT id, nombre, edomex_correo_usuario, edomex_correo_password_enc
     FROM usuarios
     WHERE edomex_correo_usuario IS NOT NULL AND edomex_correo_usuario <> '' AND edomex_correo_password_enc IS NOT NULL"
)->fetchAll();

$cuentas = [];
foreach ($rows as $r) {
    $cuentas[] = [
        'usuario_id' => (int)$r['id'],
        'nombre' => $r['nombre'],
        'edomex_correo_usuario' => $r['edomex_correo_usuario'],
        'edomex_correo_password' => pjf_decrypt($r['edomex_correo_password_enc']),
    ];
}

respond(['cuentas' => $cuentas]);
