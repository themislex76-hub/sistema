<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal ultra simple: solo dice cuándo se modificó por
// última vez ia_helpers.php en el servidor, para confirmar si una subida
// de archivo realmente se guardó. Solo Administrador.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$f = __DIR__ . '/ia_helpers.php';
echo "Hora del servidor ahora mismo: " . date('Y-m-d H:i:s') . "\n";
echo "ia_helpers.php se modificó por última vez: " . (file_exists($f) ? date('Y-m-d H:i:s', filemtime($f)) : 'NO EXISTE') . "\n";
