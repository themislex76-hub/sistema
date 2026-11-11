<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Llamado por el robot del Buzón Electrónico Laboral del PJCDMX (no por el
// frontend) para obtener TODAS las cuentas que los abogados del despacho
// hayan guardado, y así revisar el buzón de cada uno por turno. Descifra la
// contraseña aquí mismo, server-side -- el robot necesita el texto plano
// para poder iniciar sesión de verdad en el portal.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_robot_key();

$pdo = db();
$rows = $pdo->query(
    "SELECT id, nombre, cdmx_buzon_usuario, cdmx_buzon_password_enc
     FROM usuarios
     WHERE cdmx_buzon_usuario IS NOT NULL AND cdmx_buzon_usuario <> '' AND cdmx_buzon_password_enc IS NOT NULL"
)->fetchAll();

$cuentas = [];
foreach ($rows as $r) {
    $cuentas[] = [
        'usuario_id' => (int)$r['id'],
        'nombre' => $r['nombre'],
        'cdmx_buzon_usuario' => $r['cdmx_buzon_usuario'],
        'cdmx_buzon_password' => pjf_decrypt($r['cdmx_buzon_password_enc']),
    ];
}

respond(['cuentas' => $cuentas]);
