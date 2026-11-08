<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp_procesar.php';

// Reporte temporal (solo Administrador) — punto de comparación contra
// reporte_seguimiento_live.php: para números que NUNCA recibieron el
// aviso de fuera de horario (o sea, el bot les contestó siempre en
// horario normal), mide si el cliente volvió a escribir después de la
// última respuesta real del bot o se quedó callado — mismo criterio que
// el otro reporte, para saber si el % de "silencio" es distinto en
// horario normal vs. después de esperar el aviso automático. Solo cuenta
// casos donde ya pasaron al menos 3 horas desde esa última respuesta
// (para dar tiempo real a que conteste). Se puede borrar cuando ya no
// haga falta.
require_admin();
$pdo = db();

$stmt = $pdo->prepare(
    "SELECT DISTINCT telefono FROM whatsapp_conversaciones
     WHERE direccion = 'saliente' AND texto = :texto AND creado_en >= NOW() - INTERVAL 3 DAY"
);
$stmt->execute([':texto' => WHATSAPP_MENSAJE_FUERA_HORARIO]);
$excluir = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

$stmt = $pdo->query(
    "SELECT DISTINCT telefono FROM whatsapp_conversaciones WHERE creado_en >= NOW() - INTERVAL 3 DAY"
);
$todosTelefonos = $stmt->fetchAll(PDO::FETCH_COLUMN);

$filas = [];
foreach ($todosTelefonos as $telefono) {
    if (isset($excluir[$telefono])) continue;

    $stmt = $pdo->prepare(
        'SELECT direccion, texto, creado_en FROM whatsapp_conversaciones WHERE telefono = :t ORDER BY id ASC'
    );
    $stmt->execute([':t' => $telefono]);
    $historial = $stmt->fetchAll();

    $ultimaRespuestaReal = null;
    $clienteVolvio = false;
    foreach ($historial as $h) {
        if ($h['direccion'] === 'saliente' && $h['texto'] !== WHATSAPP_MENSAJE_FUERA_HORARIO) {
            $ultimaRespuestaReal = $h['creado_en'];
            $clienteVolvio = false;
        } elseif ($h['direccion'] === 'entrante' && $ultimaRespuestaReal !== null) {
            $clienteVolvio = true;
        }
    }
    if ($ultimaRespuestaReal === null) continue;
    if (strtotime($ultimaRespuestaReal) > time() - 3 * 3600) continue;

    $stmtP = $pdo->prepare('SELECT tipo, estatus FROM prospectos WHERE telefono = :t LIMIT 1');
    $stmtP->execute([':t' => $telefono]);
    $prospecto = $stmtP->fetch();

    $filas[] = [
        'telefono' => $telefono,
        'ultima_respuesta' => $ultimaRespuestaReal,
        'estado' => $clienteVolvio ? 'Cliente retomó' : 'Cliente NO volvió a escribir',
        'prospecto' => $prospecto ? ($prospecto['tipo'] . ' / ' . $prospecto['estatus']) : '—',
    ];
}

usort($filas, fn($a, $b) => strcmp((string)$b['ultima_respuesta'], (string)$a['ultima_respuesta']));

$totNoVolvio = 0; $totRetomo = 0; $totNoVolvioPeroEsProspecto = 0;
foreach ($filas as $f) {
    if ($f['estado'] === 'Cliente retomó') $totRetomo++;
    else {
        $totNoVolvio++;
        if ($f['prospecto'] !== '—') $totNoVolvioPeroEsProspecto++;
    }
}
$total = count($filas);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Reporte: seguimiento en horario normal (baseline)</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 1000px; margin: 2rem auto; padding: 0 1rem; color: #222; }
h1 { font-size: 1.3rem; }
table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; font-size: 0.9rem; }
.resumen { background: #f4f4f4; padding: 1rem; border-radius: 8px; margin-top: 1rem; }
.ok { color: #1a7f37; }
.mal { color: #b91c1c; }
</style>
</head>
<body>
<h1>Baseline: seguimiento cuando el bot contestó en horario normal (últimos 3 días)</h1>
<p>Compara esto contra reporte_seguimiento_live.php (59% retomó / 41% silencio) — mismo criterio, pero para
mensajes que el bot SIEMPRE contestó de inmediato, sin pasar por el aviso de fuera de horario.</p>
<div class="resumen">
  <p>Total (con al menos 3h desde la última respuesta): <b><?= $total ?></b></p>
  <p class="mal">Cliente NO volvió a escribir: <b><?= $totNoVolvio ?></b><?= $total > 0 ? ' (' . round($totNoVolvio / $total * 100) . '%)' : '' ?>
     — de esos, <b><?= $totNoVolvioPeroEsProspecto ?></b> ya eran prospecto de todos modos</p>
  <p class="ok">Cliente retomó: <b><?= $totRetomo ?></b><?= $total > 0 ? ' (' . round($totRetomo / $total * 100) . '%)' : '' ?></p>
</div>
<table>
<thead><tr><th>Teléfono</th><th>Última respuesta real</th><th>Estado</th><th>¿Es prospecto?</th></tr></thead>
<tbody>
<?php foreach ($filas as $f): ?>
  <tr>
    <td><?= htmlspecialchars($f['telefono']) ?></td>
    <td><?= htmlspecialchars((string)$f['ultima_respuesta']) ?></td>
    <td class="<?= $f['estado'] === 'Cliente retomó' ? 'ok' : 'mal' ?>"><?= htmlspecialchars($f['estado']) ?></td>
    <td><?= htmlspecialchars($f['prospecto']) ?></td>
  </tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
