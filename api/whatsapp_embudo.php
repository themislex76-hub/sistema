<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Embudo de WhatsApp por fecha, con rango elegible (ver "Ingresos por
// periodo" para el mismo patrón de chips en el frontend): cuántos
// WhatsApps se atendieron cada día, de esos cuántos se volvieron
// propuesta (prospecto nuevo), cuántos se contactaron y cuántos se
// convirtieron a clientes. "Contactados" y "convertidos" son
// acumulativos (un convertido también cuenta como contactado), igual
// que un embudo normal. Solo Administrador, misma razón que
// conversaciones_list.php: es control de calidad del bot, no trabajo
// diario de los abogados.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_admin();

$pdo = db();

$hoy = date('Y-m-d');
$desde = (string)($_GET['desde'] ?? '');
$hasta = (string)($_GET['hasta'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = date('Y-m-d', strtotime('-29 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) $hasta = $hoy;
if ($hasta > $hoy) $hasta = $hoy;
if ($desde < '2020-01-01') $desde = '2020-01-01';
if ($hasta < $desde) $hasta = $desde;

$hastaExclusiva = date('Y-m-d', strtotime($hasta . ' +1 day'));

$stmtMensajes = $pdo->prepare(
    "SELECT DATE(creado_en) AS dia, COUNT(*) AS mensajes, COUNT(DISTINCT telefono) AS numeros
     FROM whatsapp_conversaciones
     WHERE direccion = 'entrante' AND creado_en >= :desde AND creado_en < :hasta
     GROUP BY DATE(creado_en)"
);
$stmtMensajes->execute([':desde' => $desde, ':hasta' => $hastaExclusiva]);
$porDiaMensajes = $stmtMensajes->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

$stmtProspectos = $pdo->prepare(
    "SELECT DATE(creado_en) AS dia,
            COUNT(*) AS nuevos,
            SUM(estatus IN ('contactado', 'convertido')) AS contactados,
            SUM(estatus = 'convertido') AS convertidos
     FROM prospectos
     WHERE creado_en >= :desde AND creado_en < :hasta
     GROUP BY DATE(creado_en)"
);
$stmtProspectos->execute([':desde' => $desde, ':hasta' => $hastaExclusiva]);
$porDiaProspectos = $stmtProspectos->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

$porDia = [];
$cursor = new DateTimeImmutable($desde);
$fin = new DateTimeImmutable($hasta);
while ($cursor <= $fin) {
    $dia = $cursor->format('Y-m-d');
    $porDia[] = [
        'dia' => $dia,
        'mensajes' => (int)($porDiaMensajes[$dia]['mensajes'] ?? 0),
        'numeros' => (int)($porDiaMensajes[$dia]['numeros'] ?? 0),
        'prospectos_nuevos' => (int)($porDiaProspectos[$dia]['nuevos'] ?? 0),
        'prospectos_contactados' => (int)($porDiaProspectos[$dia]['contactados'] ?? 0),
        'prospectos_convertidos' => (int)($porDiaProspectos[$dia]['convertidos'] ?? 0),
    ];
    $cursor = $cursor->modify('+1 day');
}

respond([
    'desde' => $desde,
    'hasta' => $hasta,
    'por_dia' => $porDia,
]);
