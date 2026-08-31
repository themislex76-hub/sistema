<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Llamado por el robot de jurisprudencia laboral (con la llave compartida
// de robot) para guardar las tesis nuevas que encuentra en el Semanario
// Judicial de la Federación. Upsert por registro_digital — si el robot
// vuelve a mandar una tesis ya guardada (reintento, o corrida de prueba),
// no la duplica, solo actualiza el texto por si cambió algo.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_robot_key();

$in = json_input();
$tesis = $in['tesis'] ?? null;
if (!is_array($tesis)) fail('Falta el arreglo "tesis".', 400);

$pdo = db();
$stmt = $pdo->prepare(
    'INSERT INTO jurisprudencia_tesis
        (registro_digital, instancia, epoca, numero_tesis, tipo, materias, rubro, texto_completo, fecha_publicacion)
     VALUES (:reg, :instancia, :epoca, :numero, :tipo, :materias, :rubro, :texto, :fecha)
     ON DUPLICATE KEY UPDATE
        instancia = VALUES(instancia), epoca = VALUES(epoca), numero_tesis = VALUES(numero_tesis),
        tipo = VALUES(tipo), materias = VALUES(materias), rubro = VALUES(rubro),
        texto_completo = VALUES(texto_completo), fecha_publicacion = VALUES(fecha_publicacion)'
);

$guardadas = 0;
$registrosNuevos = []; // solo las que de verdad son insert (no un refresco de una ya existente)
foreach ($tesis as $t) {
    $registro = (int)($t['registro_digital'] ?? 0);
    $rubro = trim((string)($t['rubro'] ?? ''));
    $texto = trim((string)($t['texto_completo'] ?? ''));
    if ($registro <= 0 || $rubro === '' || $texto === '') continue;
    $stmt->execute([
        ':reg' => $registro,
        ':instancia' => ($t['instancia'] ?? '') !== '' ? $t['instancia'] : null,
        ':epoca' => ($t['epoca'] ?? '') !== '' ? $t['epoca'] : null,
        ':numero' => ($t['numero_tesis'] ?? '') !== '' ? $t['numero_tesis'] : null,
        ':tipo' => ($t['tipo'] ?? '') !== '' ? $t['tipo'] : null,
        ':materias' => ($t['materias'] ?? '') !== '' ? $t['materias'] : null,
        ':rubro' => $rubro,
        ':texto' => $texto,
        ':fecha' => ($t['fecha_publicacion'] ?? '') !== '' ? $t['fecha_publicacion'] : null,
    ]);
    // rowCount() de un INSERT ... ON DUPLICATE KEY UPDATE en MySQL: 1 = sí
    // se insertó, 2 = ya existía y se actualizó. Solo lo nuevo dispara el
    // cruce automático contra expedientes activos, para no repetirlo cada
    // vez que el robot vuelve a mandar una tesis que solo se refrescó.
    if ($stmt->rowCount() === 1) $registrosNuevos[] = $registro;
    $guardadas++;
}

// Cruce automático contra expedientes activos -- ver
// jurisprudencia_cruce_helpers.php. Un fallo aquí (IA caída, etc.) nunca
// debe tumbar el ingreso de las tesis, que ya se guardaron arriba.
if ($registrosNuevos) {
    try {
        require_once __DIR__ . '/jurisprudencia_cruce_helpers.php';
        jurisprudencia_cruzar_con_activos($pdo, $registrosNuevos);
    } catch (\Throwable $e) {
        error_log('jurisprudencia_cruzar_con_activos falló: ' . $e->getMessage());
    }
}

respond(['guardadas' => $guardadas, 'nuevas' => count($registrosNuevos)]);
