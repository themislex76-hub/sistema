<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Hilo completo de una conversación de WhatsApp, para revisión de calidad
// del bot (vista "Conversaciones (WhatsApp)"). Solo Administrador — a
// diferencia de prospectos_mensajes.php, aquí no hay dueño/asignado: es
// para ver cualquier número que le haya escrito al bot, haya calificado o no.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_admin();

$telefono = trim((string)($_GET['telefono'] ?? ''));
if ($telefono === '') fail('Falta el teléfono.', 400);

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT direccion, texto, respondido_por, creado_en FROM whatsapp_conversaciones WHERE telefono = :t ORDER BY id ASC LIMIT 500'
);
$stmt->execute([':t' => $telefono]);

respond(['mensajes' => $stmt->fetchAll()]);
