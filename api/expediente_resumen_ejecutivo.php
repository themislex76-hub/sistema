<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';
require_once __DIR__ . '/ia_helpers.php';

// Informe ejecutivo con IA de un expediente (ver ia_generar_resumen_expediente()
// en ia_helpers.php) -- para que un socio/jefe entienda el asunto sin abrir
// todo el expediente. Se cachea en expedientes.resumen_ejecutivo: solo se
// vuelve a llamar a la IA cuando el expediente cambió desde la última vez
// que se generó (actualizado_en más reciente que resumen_ejecutivo_generado_en)
// o cuando se pide regenerar a mano -- así el costo real es una llamada por
// cambio de expediente, no una por cada vez que alguien lo abre.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$forzar = !empty($in['forzar']);
if ($id <= 0) fail('Falta el id del expediente.', 400);

$pdo = db();
$row = guard_expediente_access($pdo, $user, $id);
$row = cast_numeric_fields($row);

$necesitaRegenerar = $forzar
    || empty($row['resumen_ejecutivo'])
    || empty($row['resumen_ejecutivo_generado_en'])
    || strtotime((string)$row['actualizado_en']) > strtotime((string)$row['resumen_ejecutivo_generado_en']);

if (!$necesitaRegenerar) {
    respond([
        'resumen' => $row['resumen_ejecutivo'],
        'generado_en' => $row['resumen_ejecutivo_generado_en'],
        'regenerado' => false,
    ]);
}

$resumen = ia_generar_resumen_expediente($row);

if ($resumen === null) {
    // La IA falló (red, sin crédito, etc.) -- si ya había un resumen viejo
    // guardado, mejor mostrar ese que dejar al usuario sin nada.
    if (!empty($row['resumen_ejecutivo'])) {
        respond([
            'resumen' => $row['resumen_ejecutivo'],
            'generado_en' => $row['resumen_ejecutivo_generado_en'],
            'regenerado' => false,
            'aviso' => 'No se pudo actualizar el resumen ahora mismo, se muestra el último generado.',
        ]);
    }
    fail('No se pudo generar el resumen ejecutivo. Revisa ia_debug.log.', 502);
}

$ahora = date('Y-m-d H:i:s');
$pdo->prepare('UPDATE expedientes SET resumen_ejecutivo = :r, resumen_ejecutivo_generado_en = :f WHERE id = :id')
    ->execute([':r' => $resumen, ':f' => $ahora, ':id' => $id]);

respond([
    'resumen' => $resumen,
    'generado_en' => $ahora,
    'regenerado' => true,
]);
