<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Reporte temporal (solo Administrador) para medir, antes de construir el
// cobro por la calculadora fuera de zona, cuántos cálculos por día son de
// gente dentro vs. fuera de la cobertura del despacho. Ver
// sql/migraciones/026_zona_calculos_liquidacion.sql e
// ia_clasificar_zona_cobertura() en ia_helpers.php. Se puede borrar
// cuando ya no haga falta.
require_admin();
$pdo = db();

$stmt = $pdo->query(
    "SELECT DATE(creado_en) AS dia,
            SUM(zona = 'dentro') AS dentro,
            SUM(zona = 'fuera') AS fuera,
            SUM(zona = 'desconocido') AS desconocido,
            COUNT(*) AS total
     FROM calculos_liquidacion
     GROUP BY DATE(creado_en)
     ORDER BY dia DESC
     LIMIT 60"
);
$filas = $stmt->fetchAll();

$totDentro = 0; $totFuera = 0; $totDesconocido = 0;
foreach ($filas as $f) {
    $totDentro += (int)$f['dentro'];
    $totFuera += (int)$f['fuera'];
    $totDesconocido += (int)$f['desconocido'];
}
$totGeneral = $totDentro + $totFuera + $totDesconocido;
$diasConDatos = count($filas);
$promedioFueraDia = $diasConDatos > 0 ? round($totFuera / $diasConDatos, 1) : 0;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Reporte: zona de la calculadora</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 900px; margin: 2rem auto; padding: 0 1rem; color: #222; }
h1 { font-size: 1.3rem; }
table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: right; font-size: 0.9rem; }
th:first-child, td:first-child { text-align: left; }
.resumen { background: #f4f4f4; padding: 1rem; border-radius: 8px; margin-top: 1rem; }
.resumen b { font-size: 1.1rem; }
</style>
</head>
<body>
<h1>Cálculos de liquidación por zona (últimos <?= $diasConDatos ?> días con datos)</h1>

<div class="resumen">
  <p>Total de cálculos registrados: <b><?= $totGeneral ?></b></p>
  <p>Dentro de zona (CDMX/Edomex cubierto — se queda gratis): <b><?= $totDentro ?></b></p>
  <p>Fuera de zona (candidatos a cobro): <b><?= $totFuera ?></b> — promedio de <b><?= $promedioFueraDia ?></b> por día</p>
  <p>Sin ubicación detectada (no se puede clasificar): <b><?= $totDesconocido ?></b></p>
  <?php if ($totFuera > 0): ?>
    <p>Si se cobrara $40 MXN a cada uno de fuera de zona: <b>$<?= number_format($totFuera * 40, 0) ?></b> acumulado en este periodo
       (~$<?= number_format($promedioFueraDia * 40, 0) ?> MXN/día antes de comisión de Mercado Pago).</p>
  <?php endif; ?>
  <p style="color:#666; font-size:0.85rem;">Nota: "sin ubicación detectada" pesa mucho todavía — la IA solo anota la
  ubicación cuando la persona la menciona por su cuenta, así que estos números son un piso (mínimo), no el total real.</p>
</div>

<table>
<thead><tr><th>Día</th><th>Dentro</th><th>Fuera</th><th>Desconocido</th><th>Total</th></tr></thead>
<tbody>
<?php foreach ($filas as $f): ?>
  <tr>
    <td><?= htmlspecialchars((string)$f['dia']) ?></td>
    <td><?= (int)$f['dentro'] ?></td>
    <td><?= (int)$f['fuera'] ?></td>
    <td><?= (int)$f['desconocido'] ?></td>
    <td><?= (int)$f['total'] ?></td>
  </tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
