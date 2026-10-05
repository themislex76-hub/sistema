<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Mensajes de WhatsApp enviados (salientes) por día, últimos 30 días --
// cada fila de whatsapp_conversaciones con direccion='saliente' es un
// mensaje real mandado por la API de WhatsApp (bot + recordatorios +
// mensajes manuales de un abogado), así que esto es el número real que le
// importa a Meta para el cobro por mensaje (arriba de 1,000 servicio al
// mes por número de negocio -- ver nota de costos en CLAUDE.md). También
// trae el acumulado del mes en curso para comparar contra ese umbral.
// Solo Administrador -- es información de costo del despacho completo.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_admin();

$pdo = db();

$dias = $pdo->query(
    "SELECT DATE(creado_en) AS dia, COUNT(*) AS enviados
     FROM whatsapp_conversaciones
     WHERE direccion = 'saliente' AND creado_en >= NOW() - INTERVAL 30 DAY
     GROUP BY DATE(creado_en)
     ORDER BY dia DESC"
)->fetchAll();

$mesActual = $pdo->query(
    "SELECT COUNT(*) FROM whatsapp_conversaciones
     WHERE direccion = 'saliente' AND creado_en >= DATE_FORMAT(NOW(), '%Y-%m-01')"
)->fetchColumn();

respond([
    'dias' => $dias,
    'mes_actual_total' => (int)$mesActual,
    'umbral_gratis' => 1000,
]);
