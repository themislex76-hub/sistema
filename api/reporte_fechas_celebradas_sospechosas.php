<?php
declare(strict_types=1);

// Reporte de diagnóstico, pensado para abrirse directo en el navegador (ya
// logueado como Administrador) -- NO es un endpoint JSON normal. Pedido del
// usuario (26 nov 2026) tras encontrar 2 expedientes reales con "Fecha en
// que se celebró" inventada con la fecha del día en que alguien guardó
// "Etapas del juicio" (bug ya corregido en app.js?v=163, ver CLAUDE.md):
// "como corregimos lo que metio mal sin revisar asunto por asunto porque
// eso me llevaria mucho tiempo".
//
// Firma del bug: el botón "Guardar etapas" guardaba TODAS las filas del
// formulario a la vez, y cualquier fila cuyo checkbox quedara marcado SIN
// fecha escrita se guardaba con la fecha de HOY (del día en que se dio
// clic), inventando que esa etapa se celebró justo ese día. Esto SOLO
// podía pasar en guardados hechos por un humano desde el formulario (los 5
// mecanismos de IA que llenan etapas solas dejan usuario_id NULL en
// expediente_historial y SIEMPRE citan una fecha literal de una fuente
// real, nunca "hoy porque sí") -- por eso el candado es:
//   1. La entrada de historial la guardó un humano (usuario_id NOT NULL).
//   2. La fecha que guardó coincide EXACTO con el día en que la guardó.
//   3. (más confianza, no obligatorio) En ESE MISMO guardado (mismo
//      expediente + mismo usuario + mismo segundo) se tocó más de UNA
//      etapa -- la firma de "Guardar etapas" guardando el formulario
//      completo por otra razón, no de alguien confirmando a propósito una
//      sola etapa justo hoy.
// Solo se muestra si la etapa SIGUE teniendo esa misma fecha ahora mismo --
// si ya se corrigió desde entonces, no aparece. NUNCA corrige nada solo.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

require_admin();
$pdo = db();

// Mismo filtro de "activo" que usan los demás reportes de este tipo.
$expStmt = $pdo->query(
    "SELECT id, exp, actor, demandado, abogado_id, abogado_id_2, status
     FROM expedientes WHERE concluido_manual = 0"
);
$expedientes = [];
foreach ($expStmt->fetchAll() as $r) {
    $s = strtolower((string)($r['status'] ?? ''));
    if (strpos($s, 'pagado') !== false || strpos($s, 'ya no quiso') !== false) continue;
    $expedientes[(int)$r['id']] = $r;
}

$abogadosStmt = $pdo->query('SELECT id, nombre FROM usuarios');
$nombresPorId = [];
foreach ($abogadosStmt->fetchAll() as $u) $nombresPorId[(int)$u['id']] = $u['nombre'];
$abogadoNombre = function (array $r) use ($nombresPorId) {
    $nombres = [];
    if (!empty($r['abogado_id']) && isset($nombresPorId[(int)$r['abogado_id']])) $nombres[] = $nombresPorId[(int)$r['abogado_id']];
    if (!empty($r['abogado_id_2']) && isset($nombresPorId[(int)$r['abogado_id_2']])) $nombres[] = $nombresPorId[(int)$r['abogado_id_2']];
    return $nombres ? implode(' / ', $nombres) : 'Sin asignar';
};

// Todo el historial de etapas guardado por un humano -- se usa dos veces:
// para encontrar candidatas (fecha == día del guardado) y para contar
// cuántas etapas distintas se tocaron en el mismo guardado (mismo
// expediente + usuario + segundo exacto).
$histStmt = $pdo->query(
    "SELECT expediente_id, usuario_id, usuario_nombre, campo, despues, creado_en
     FROM expediente_historial
     WHERE usuario_id IS NOT NULL AND campo LIKE 'etapa_%'
     ORDER BY creado_en DESC"
);
$historial = $histStmt->fetchAll();

$loteConteo = []; // "expediente_id|usuario_id|creado_en" => Set de campos tocados
foreach ($historial as $h) {
    $loteKey = $h['expediente_id'] . '|' . $h['usuario_id'] . '|' . $h['creado_en'];
    $loteConteo[$loteKey][$h['campo']] = true;
}

$candidatas = []; // dedupe: solo la más reciente por expediente_id+campo
$vistos = [];
foreach ($historial as $h) {
    $dedupeKey = $h['expediente_id'] . '|' . $h['campo'];
    if (isset($vistos[$dedupeKey])) continue; // ya se vio una más reciente -- esta quedó superada
    $vistos[$dedupeKey] = true;

    if (!preg_match('/^fecha:\s*(\d{4}-\d{2}-\d{2})/', (string)$h['despues'], $m)) continue;
    $fechaGuardada = $m[1];
    $diaGuardado = substr((string)$h['creado_en'], 0, 10);
    if ($fechaGuardada !== $diaGuardado) continue; // no coincide -- no es sospechosa

    $expedienteId = (int)$h['expediente_id'];
    if (!isset($expedientes[$expedienteId])) continue; // concluido/pagado desde entonces

    $etapaKey = substr($h['campo'], strlen('etapa_'));
    if (!in_array($etapaKey, ETAPA_KEYS, true)) continue;

    $loteKey = $h['expediente_id'] . '|' . $h['usuario_id'] . '|' . $h['creado_en'];
    $tamanoLote = count($loteConteo[$loteKey] ?? []);

    $candidatas[] = [
        'expediente' => $expedientes[$expedienteId],
        'etapa_key' => $etapaKey,
        'fecha' => $fechaGuardada,
        'usuario_nombre' => $h['usuario_nombre'],
        'creado_en' => $h['creado_en'],
        'tamano_lote' => $tamanoLote,
    ];
}

// Confirmar contra el valor ACTUAL de expediente_etapas -- si ya se
// corrigió (la fecha cambió o se borró), no se muestra.
$confirmadas = [];
foreach ($candidatas as $c) {
    $eid = (int)$c['expediente']['id'];
    $actualStmt = $pdo->prepare('SELECT fecha FROM expediente_etapas WHERE expediente_id = :eid AND etapa_key = :key');
    $actualStmt->execute([':eid' => $eid, ':key' => $c['etapa_key']]);
    $actual = $actualStmt->fetchColumn();
    if ($actual === $c['fecha']) $confirmadas[] = $c;
}

usort($confirmadas, fn($a, $b) => $b['tamano_lote'] <=> $a['tamano_lote']);

header('Content-Type: text/html; charset=utf-8');
$labels = defined('ETAPA_LABELS') ? ETAPA_LABELS : [];
$fila = function (array $c) use ($abogadoNombre, $labels) {
    $r = $c['expediente'];
    $sospecha = $c['tamano_lote'] >= 2 ? '<strong style="color:#b00;">ALTA</strong> (se tocaron ' . $c['tamano_lote'] . ' etapas en el mismo guardado)' : 'media (solo esta etapa en ese guardado)';
    return '<tr>'
        . '<td>' . htmlspecialchars($r['exp'] ?? '—', ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td>' . htmlspecialchars($r['actor'] ?? '', ENT_QUOTES, 'UTF-8') . ' vs ' . htmlspecialchars($r['demandado'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td>' . htmlspecialchars($labels[$c['etapa_key']] ?? $c['etapa_key'], ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td>' . htmlspecialchars($c['fecha'], ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td>' . htmlspecialchars($c['usuario_nombre'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td>' . htmlspecialchars($c['creado_en'], ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td>' . $sospecha . '</td>'
        . '<td>' . htmlspecialchars($abogadoNombre($r), ENT_QUOTES, 'UTF-8') . '</td>'
        . '</tr>';
};
?>
<!doctype html>
<html lang="es-MX"><head><meta charset="utf-8"><title>Reporte: fechas celebradas posiblemente inventadas</title></head>
<body style="font-family: Arial, sans-serif; max-width: 1200px; margin: 24px auto; padding: 0 16px;">
<h1>Reporte: "Fecha en que se celebró" posiblemente inventada con la fecha del guardado</h1>
<p>Generado <?= date('d/m/Y H:i') ?>. Busca el bug corregido el 26 nov 2026 (checkbox marcado sin fecha = se guardaba la fecha de hoy sola). Solo expedientes activos. Solo se listan etapas que SIGUEN con esa fecha ahora mismo -- si ya se corrigió, no aparece aquí. Nunca se corrige nada solo desde este reporte -- hazlo desde "Etapas del juicio" de cada expediente.</p>

<p><strong><?= count($confirmadas) ?></strong> etapa(s) sospechosa(s) encontrada(s)
(<?= count(array_filter($confirmadas, fn($c) => $c['tamano_lote'] >= 2)) ?> de sospecha ALTA).</p>

<?php if (!$confirmadas): ?>
<p style="color:#2a7; font-weight:600;">Ninguna -- no se encontró ningún caso con esta firma.</p>
<?php else: ?>
<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%; font-size:13px;">
<tr style="background:#eee;"><th>Expediente</th><th>Actor vs Demandado</th><th>Etapa</th><th>Fecha guardada</th><th>Quién la guardó</th><th>Cuándo</th><th>Sospecha</th><th>Abogado</th></tr>
<?= implode('', array_map($fila, $confirmadas)) ?>
</table>
<p style="margin-top:14px; color:#555;">Las de sospecha <strong>ALTA</strong> son casi seguro el bug (se guardaron varias etapas de golpe el mismo día que supuestamente "se celebraron" todas) -- revisa esas primero. Las de sospecha media pueden ser legítimas (el abogado sí confirmó esa etapa justo el día que pasó) -- revisa con más calma si hace falta.</p>
<?php endif; ?>
</body></html>
