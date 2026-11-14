<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Panel nuevo (13 nov 2026), pedido explícito del usuario tras preguntar
// "por qué hay asuntos en los que ya se guardó bien el tribunal y
// expediente... que aún no baja el robot" -- revisando un caso real
// (Escalona Jimenez Yuliana Yadira, Exp. 388/2026, Tribunal Federal bien
// capturado) se confirmó que no existía forma de que el Administrador
// viera, sin pedirle a cada abogado uno por uno, quién SÍ tiene guardadas
// sus credenciales de cada portal (PJF/Buzón CDMX/correo Edomex) en "Mi
// cuenta" -- si el abogado asignado a un expediente nunca guardó su
// cuenta de ese portal, el robot correspondiente no tiene con qué
// entrar a revisar NINGUNO de sus expedientes, aunque el Tribunal y el
// número estén perfectos en el sistema.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();
if ($user['rol'] !== 'administrador') fail('Esta acción requiere permisos de administrador.', 403);

$pdo = db();
$stmt = $pdo->query(
    "SELECT id, nombre, rol,
            pjf_usuario, pjf_actualizado_en,
            edomex_correo_usuario, edomex_correo_actualizado_en,
            cdmx_buzon_usuario, cdmx_buzon_actualizado_en
     FROM usuarios
     WHERE activo = 1 AND rol IN ('abogado', 'administrador')
     ORDER BY nombre ASC"
);
$filas = $stmt->fetchAll();

$resultado = array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'nombre' => $r['nombre'],
        'rol' => $r['rol'],
        'pjf' => !empty($r['pjf_usuario']),
        'pjf_actualizado_en' => $r['pjf_actualizado_en'],
        'edomex_correo' => !empty($r['edomex_correo_usuario']),
        'edomex_correo_actualizado_en' => $r['edomex_correo_actualizado_en'],
        'cdmx_buzon' => !empty($r['cdmx_buzon_usuario']),
        'cdmx_buzon_actualizado_en' => $r['cdmx_buzon_actualizado_en'],
    ];
}, $filas);

respond(['usuarios' => $resultado]);
