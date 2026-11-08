<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: dice cuál fue el ÚLTIMO mensaje entrante que se
// guardó en TODO el sistema (de cualquier número) -- si está detenido
// desde hace horas mientras clientes siguen escribiendo por WhatsApp (se
// les entrega según su check azul/gris, pero nada llega a guardarse
// aquí), significa que Meta ya no está logrando entregar el webhook a
// este servidor -- no es un bug de la lógica de negocio, es que el aviso
// nunca está llegando. Ver docs/DEPLOY_CPANEL.md, sección "Puente con
// Cloudflare Workers", como posible solución si el hosting empezó a
// bloquear las conexiones de Meta. Solo Administrador. Se puede borrar
// cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

echo "Hora del servidor ahora mismo: " . date('Y-m-d H:i:s') . "\n\n";

$pdo = db();

$ultimo = $pdo->query(
    "SELECT telefono, texto, creado_en FROM whatsapp_conversaciones WHERE direccion = 'entrante' ORDER BY id DESC LIMIT 5"
)->fetchAll();

echo "Últimos 5 mensajes ENTRANTES guardados (de cualquier número):\n\n";
foreach ($ultimo as $r) {
    echo "  " . $r['creado_en'] . " -- " . $r['telefono'] . " -- \"" . mb_strimwidth((string)$r['texto'], 0, 80, '…') . "\"\n";
}

if ($ultimo) {
    $minutos = (int)((time() - strtotime($ultimo[0]['creado_en'])) / 60);
    echo "\nHan pasado {$minutos} minuto(s) desde el último mensaje entrante guardado, de CUALQUIER número.\n";
    if ($minutos > 60) {
        echo "Eso es sospechoso si sabes que te han escrito clientes en ese tiempo -- sugiere que Meta ya\n";
        echo "no está logrando entregar el webhook a este servidor (no es un bug de la lógica del bot).\n";
    }
}

echo "\n¿Existe api/whatsapp_credentials.php? " . (file_exists(__DIR__ . '/whatsapp_credentials.php') ? "Sí" : "NO -- ESO SERÍA GRAVE, revisa esto ya") . "\n";
echo "Última modificación de whatsapp_webhook.php: " . date('Y-m-d H:i:s', filemtime(__DIR__ . '/whatsapp_webhook.php')) . "\n";
