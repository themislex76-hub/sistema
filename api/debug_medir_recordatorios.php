<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: para decidir si vale la pena recortar algún
// recordatorio automático (cada uno es un mensaje de WhatsApp cobrable),
// mide cuánto volumen genera cada uno y cuánta conversión REAL le
// atribuye (pago después de ese recordatorio) -- para no cortar a
// ciegas algo que de verdad está generando ventas. Solo Administrador.
// Se puede borrar cuando ya no haga falta.
//
// Uso: debug_medir_recordatorios.php?dias=30 (default 30)
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$dias = (int)($_GET['dias'] ?? 30);
if ($dias <= 0) $dias = 30;
$desde = date('Y-m-d H:i:s', strtotime("-{$dias} days"));

$pdo = db();

echo "=== Medición de recordatorios automáticos -- últimos {$dias} día(s) ===\n";
echo "(para decidir cuáles vale la pena recortar por costo vs. cuáles generan ventas reales)\n\n";

// --- 1) Recordatorio 1h antes de la llamada (recordatorio_1_hora) ---
// No es un recordatorio de VENTA (la cita ya está pagada) -- es para
// evitar que no conteste la llamada. No tiene un grupo de comparación
// real (se manda a casi todas las citas confirmadas), así que solo se
// reporta volumen, no conversión.
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM citas_asesoria WHERE creado_en >= :d AND recordatorio_enviado = 1"
);
$stmt->execute([':d' => $desde]);
$totalRecordatorio1h = (int)$stmt->fetchColumn();
echo "1) Recordatorio 1h antes de la llamada (cita ya pagada, evita no-show):\n";
echo "   {$totalRecordatorio1h} mensaje(s) enviados. No es candidato a recortar -- no vende, evita perder la llamada ya cobrada.\n\n";

// --- 2) Recordatorio de pago pendiente (~10 min antes de que expire) ---
$stmt = $pdo->prepare(
    "SELECT recordatorio_pago_enviado, estado, COUNT(*) AS n
     FROM citas_asesoria WHERE creado_en >= :d
     GROUP BY recordatorio_pago_enviado, estado"
);
$stmt->execute([':d' => $desde]);
$filas = $stmt->fetchAll();
$conRecordatorioConfirmada = 0; $conRecordatorioTotal = 0;
$sinRecordatorioConfirmada = 0; $sinRecordatorioTotal = 0;
foreach ($filas as $f) {
    if ((int)$f['recordatorio_pago_enviado'] === 1) {
        $conRecordatorioTotal += (int)$f['n'];
        if ($f['estado'] === 'confirmada') $conRecordatorioConfirmada += (int)$f['n'];
    } else {
        $sinRecordatorioTotal += (int)$f['n'];
        if ($f['estado'] === 'confirmada') $sinRecordatorioConfirmada += (int)$f['n'];
    }
}
$tasaCon = $conRecordatorioTotal > 0 ? round($conRecordatorioConfirmada / $conRecordatorioTotal * 100, 1) : 0;
$tasaSin = $sinRecordatorioTotal > 0 ? round($sinRecordatorioConfirmada / $sinRecordatorioTotal * 100, 1) : 0;
echo "2) Recordatorio de pago pendiente (\"te quedan 10 minutos\"):\n";
echo "   Recibieron el recordatorio: {$conRecordatorioTotal} -- de esas, {$conRecordatorioConfirmada} terminaron pagando ({$tasaCon}%)\n";
echo "   NO lo recibieron (pagaron rápido o expiraron antes de los 20 min): {$sinRecordatorioTotal} -- de esas, {$sinRecordatorioConfirmada} terminaron pagando ({$tasaSin}%)\n";
echo "   Lectura: si {$tasaCon}% es claramente más alto que {$tasaSin}%, el recordatorio sí está salvando ventas que se habrían perdido.\n\n";

// --- 3) Seguimiento de cálculo de liquidación (hasta 2 recordatorios) ---
$stmt = $pdo->prepare(
    "SELECT telefono, primer_seguimiento_en, segundo_seguimiento_en
     FROM calculos_liquidacion WHERE creado_en >= :d"
);
$stmt->execute([':d' => $desde]);
$calculos = $stmt->fetchAll();
$primerEnviados = 0; $primerConvertidos = 0;
$segundoEnviados = 0; $segundoConvertidos = 0;
$stmtPago = $pdo->prepare(
    "SELECT MIN(pagado_en) FROM (
        SELECT pagado_en FROM citas_asesoria WHERE telefono = :t AND estado = 'confirmada' AND pagado_en IS NOT NULL
        UNION ALL
        SELECT pagado_en FROM compras_documento_calculo WHERE telefono = :t2 AND estado = 'confirmada' AND pagado_en IS NOT NULL
    ) t"
);
foreach ($calculos as $c) {
    if ($c['primer_seguimiento_en'] !== null) {
        $primerEnviados++;
        $stmtPago->execute([':t' => $c['telefono'], ':t2' => $c['telefono']]);
        $pago = $stmtPago->fetchColumn();
        if ($pago && strtotime((string)$pago) > strtotime((string)$c['primer_seguimiento_en'])) $primerConvertidos++;
    }
    if ($c['segundo_seguimiento_en'] !== null) {
        $segundoEnviados++;
        $stmtPago->execute([':t' => $c['telefono'], ':t2' => $c['telefono']]);
        $pago = $stmtPago->fetchColumn();
        if ($pago && strtotime((string)$pago) > strtotime((string)$c['segundo_seguimiento_en'])) $segundoConvertidos++;
    }
}
echo "3) Seguimiento de cálculo de liquidación (quien se quedó callado tras el cálculo):\n";
echo "   Primer recordatorio (~3h de silencio): {$primerEnviados} enviados -- {$primerConvertidos} pagaron (asesoría o documento) después de recibirlo (" . ($primerEnviados > 0 ? round($primerConvertidos / $primerEnviados * 100, 1) : 0) . "%)\n";
echo "   Segundo recordatorio (~20h de silencio): {$segundoEnviados} enviados -- {$segundoConvertidos} pagaron después de recibirlo (" . ($segundoEnviados > 0 ? round($segundoConvertidos / $segundoEnviados * 100, 1) : 0) . "%)\n\n";

// --- 4) Seguimiento de interés en curso ---
$stmt = $pdo->prepare(
    "SELECT telefono, curso_interes, seguimiento_en FROM prospectos
     WHERE tipo = 'interes_curso' AND seguimiento_en IS NOT NULL AND seguimiento_en >= :d"
);
$stmt->execute([':d' => $desde]);
$cursos = $stmt->fetchAll();
$cursosEnviados = count($cursos);
$cursosConvertidos = 0;
$stmtPagoCurso = $pdo->prepare(
    "SELECT pagado_en FROM compras_curso WHERE telefono = :t AND estado = 'confirmada' AND pagado_en IS NOT NULL ORDER BY pagado_en ASC LIMIT 1"
);
foreach ($cursos as $c) {
    $stmtPagoCurso->execute([':t' => $c['telefono']]);
    $pago = $stmtPagoCurso->fetchColumn();
    if ($pago && strtotime((string)$pago) > strtotime((string)$c['seguimiento_en'])) $cursosConvertidos++;
}
echo "4) Seguimiento de interés en curso (una sola vez, 3-20h de silencio):\n";
echo "   {$cursosEnviados} marcado(s) como enviado (incluye los que ya habían comprado o declinado, no se filtró aquí) -- {$cursosConvertidos} compraron después (" . ($cursosEnviados > 0 ? round($cursosConvertidos / $cursosEnviados * 100, 1) : 0) . "%)\n";
echo "   OJO: este número de 'enviados' incluye casos que el cron detectó y saltó sin mandar nada (ya había comprado, o declinó) -- es un techo, no el envío real exacto.\n";
