<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: muestra el registro de prospectos (si existe) para
// un teléfono dado, tal como está en la base de datos — para diagnosticar
// por qué una conversación calificada como lead no aparece en la vista
// "Prospectos". Solo Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$telefono = trim((string)($_GET['telefono'] ?? ''));
if ($telefono === '') {
    echo "Usa: debug_ver_prospecto.php?telefono=52XXXXXXXXXX\n";
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM prospectos WHERE telefono = :t');
$stmt->execute([':t' => $telefono]);
$p = $stmt->fetch();

if (!$p) {
    echo "No existe ningún registro en 'prospectos' para {$telefono}.\n";
    exit;
}

foreach ($p as $k => $v) {
    if (is_int($k)) continue;
    echo str_pad($k, 18) . ": " . ($v ?? '(null)') . "\n";
}
