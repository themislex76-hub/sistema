<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Guarda (upsert por fecha) o borra las estadísticas de un día de lives de
// TikTok, capturadas a mano desde "Embudo del negocio". Solo Administrador.
// Body JSON: {fecha, visualizaciones, espectadores_unicos, espectadores_activos,
// duracion_media_min, pico_simultaneos, media_simultaneos, duracion_live_min,
// notas} -- o {fecha, eliminar:true} para borrar ese día.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_admin();
require_csrf();

$in = json_input();
$fecha = trim((string)($in['fecha'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) fail('Falta la fecha (formato AAAA-MM-DD).', 400);

$pdo = db();

if (!empty($in['eliminar'])) {
    $pdo->prepare('DELETE FROM tiktok_lives WHERE fecha = :f')->execute([':f' => $fecha]);
    respond(['ok' => true]);
}

$entero = static function ($v): ?int {
    if ($v === null || $v === '') return null;
    $n = (int)round((float)$v);
    return $n < 0 ? 0 : $n;
};
$decimal = static function ($v): ?float {
    if ($v === null || $v === '') return null;
    $n = (float)$v;
    return $n < 0 ? 0.0 : round($n, 2);
};

$unicos = $entero($in['espectadores_unicos'] ?? null);
if ($unicos === null) fail('Falta el número de espectadores únicos.', 400);

$stmt = $pdo->prepare(
    "INSERT INTO tiktok_lives (fecha, visualizaciones, espectadores_unicos, espectadores_activos,
        duracion_media_min, pico_simultaneos, media_simultaneos, duracion_live_min, notas, actualizado_por)
     VALUES (:f, :vis, :uni, :act, :dur, :pico, :media, :live, :notas, :uid)
     ON DUPLICATE KEY UPDATE
        visualizaciones = VALUES(visualizaciones),
        espectadores_unicos = VALUES(espectadores_unicos),
        espectadores_activos = VALUES(espectadores_activos),
        duracion_media_min = VALUES(duracion_media_min),
        pico_simultaneos = VALUES(pico_simultaneos),
        media_simultaneos = VALUES(media_simultaneos),
        duracion_live_min = VALUES(duracion_live_min),
        notas = VALUES(notas),
        actualizado_por = VALUES(actualizado_por)"
);
$stmt->execute([
    ':f' => $fecha,
    ':vis' => $entero($in['visualizaciones'] ?? null) ?? 0,
    ':uni' => $unicos,
    ':act' => $entero($in['espectadores_activos'] ?? null) ?? 0,
    ':dur' => $decimal($in['duracion_media_min'] ?? null),
    ':pico' => $entero($in['pico_simultaneos'] ?? null),
    ':media' => $entero($in['media_simultaneos'] ?? null),
    ':live' => $entero($in['duracion_live_min'] ?? null),
    ':notas' => mb_substr(trim((string)($in['notas'] ?? '')), 0, 255) ?: null,
    ':uid' => $user['id'],
]);

respond(['ok' => true]);
