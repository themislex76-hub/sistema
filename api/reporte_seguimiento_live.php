<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp_procesar.php';

// Reporte temporal (solo Administrador): para cada número que recibió el
// aviso automático de "fuera de horario" en los últimos días, muestra si
// ya se le contestó su pregunta real (vía cron_reanudar_horario.php) y,
// si sí, si el CLIENTE volvió a escribir después de esa respuesta o se
// quedó callado — para medir qué tanto se enfría la gente que escribe
// fuera de horario vs. si de verdad retoma la conversación. Se puede
// borrar cuando ya no haga falta.
require_admin();
$pdo = db();

$stmt = $pdo->prepare(
    "SELECT DISTINCT telefono FROM whatsapp_conversaciones
     WHERE direccion = 'saliente' AND texto = :texto AND creado_en >= NOW() - INTERVAL 3 DAY"
);
$stmt->execute([':texto' => WHATSAPP_MENSAJE_FUERA_HORARIO]);
$telefonos = $stmt->fetchAll(PDO::FETCH_COLUMN);

$filas = [];
foreach ($telefonos as $telefono) {
    $stmt = $pdo->prepare(
        'SELECT direccion, texto, creado_en FROM whatsapp_conversaciones WHERE telefono = :t ORDER BY id ASC'
    );
    $stmt->execute([':t' => $telefono]);
    $historial = $stmt->fetchAll();

    $ultimoAviso = null;
    $primeraRespuestaReal = null;
    $clienteVolvio = false;
    $huboRespuestaReal = false;

    foreach ($historial as $h) {
        if ($h['direccion'] === 'saliente' && $h['texto'] === WHATSAPP_MENSAJE_FUERA_HORARIO) {
            $ultimoAviso = $h['creado_en'];
            $huboRespuestaReal = false;
        } elseif ($h['direccion'] === 'saliente' && $ultimoAviso !== null && !$huboRespuestaReal) {
            $primeraRespuestaReal = $h['creado_en'];
            $huboRespuestaReal = true;
        } elseif ($h['direccion'] === 'entrante' && $huboRespuestaReal) {
            $clienteVolvio = true;
        }
    }

    if ($huboRespuestaReal) {
        $estado = $clienteVolvio ? 'Contestado — cliente retomó' : 'Contestado — cliente NO volvió a escribir';
    } else {
        $estado = 'Aún sin contestar';
    }

    $filas[] = [
        'telefono' => $telefono,
        'ultimo_aviso' => $ultimoAviso,
        'respuesta_real' => $primeraRespuestaReal,
        'estado' => $estado,
    ];
}

usort($filas, fn($a, $b) => strcmp((string)$b['ultimo_aviso'], (string)$a['ultimo_aviso']));

$totSinContestar = 0; $totNoVolvio = 0; $totRetomo = 0;
foreach ($filas as $f) {
    if ($f['estado'] === 'Aún sin contestar') $totSinContestar++;
    elseif (str_contains($f['estado'], 'NO volvió')) $totNoVolvio++;
    else $totRetomo++;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Reporte: seguimiento fuera de horario</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 1000px; margin: 2rem auto; padding: 0 1rem; color: #222; }
h1 { font-size: 1.3rem; }
table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; font-size: 0.9rem; }
.resumen { background: #f4f4f4; padding: 1rem; border-radius: 8px; margin-top: 1rem; }
.ok { color: #1a7f37; }
.mal { color: #b91c1c; }
.pend { color: #92600a; }
</style>
</head>
<body>
<h1>Seguimiento de números que escribieron fuera de horario (últimos 3 días)</h1>
<div class="resumen">
  <p>Total: <b><?= count($filas) ?></b></p>
  <p class="pend">Aún sin contestar: <b><?= $totSinContestar ?></b></p>
  <p class="mal">Contestados pero el cliente NO volvió a escribir: <b><?= $totNoVolvio ?></b></p>
  <p class="ok">Contestados y el cliente retomó: <b><?= $totRetomo ?></b></p>
</div>
<table>
<thead><tr><th>Teléfono</th><th>Último aviso automático</th><th>Respuesta real del bot</th><th>Estado</th></tr></thead>
<tbody>
<?php foreach ($filas as $f): ?>
  <tr>
    <td><?= htmlspecialchars($f['telefono']) ?></td>
    <td><?= htmlspecialchars((string)$f['ultimo_aviso']) ?></td>
    <td><?= htmlspecialchars((string)($f['respuesta_real'] ?? '—')) ?></td>
    <td class="<?= str_contains($f['estado'], 'retomó') ? 'ok' : (str_contains($f['estado'], 'NO volvió') ? 'mal' : 'pend') ?>"><?= htmlspecialchars($f['estado']) ?></td>
  </tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
