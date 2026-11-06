<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: muestra el contenido de whatsapp_send_debug.log,
// donde whatsapp_enviar() anota el motivo exacto cuando un envío falla
// (status HTTP + error de cURL + cuerpo de la respuesta de Meta). Solo
// Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$logFile = __DIR__ . '/whatsapp_send_debug.log';
if (!file_exists($logFile)) {
    echo "No existe whatsapp_send_debug.log todavía — eso significa que whatsapp_enviar() no ha registrado ningún fallo (o el log se creó en otro lado).\n";
    exit;
}

echo file_get_contents($logFile);
