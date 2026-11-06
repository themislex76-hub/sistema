<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: borra TODO lo relacionado a un número de teléfono
// (conversación, prospecto, citas, cálculos, compras de curso y de
// documento) para poder probar el flujo del bot desde cero con ese
// número, como si nunca hubiera escrito antes. Acción DESTRUCTIVA e
// IRREVERSIBLE -- pide ?confirmar=si a propósito, para que no se borre
// nada por accidente si alguien solo pega la URL sin querer. Úsala SOLO
// con tu propio número de prueba, nunca con el de un cliente real. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_borrar_conversacion_prueba.php?telefono=52XXXXXXXXXX&confirmar=si
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$telefono = trim((string)($_GET['telefono'] ?? ''));
$confirmar = (string)($_GET['confirmar'] ?? '');

if ($telefono === '') {
    echo "Falta el teléfono. Uso: debug_borrar_conversacion_prueba.php?telefono=52XXXXXXXXXX&confirmar=si\n";
    exit;
}

$pdo = db();

if ($confirmar !== 'si') {
    // Modo de solo lectura: muestra qué se borraría, sin tocar nada,
    // para que puedas confirmar que es el número correcto antes de
    // agregar &confirmar=si.
    echo "=== VISTA PREVIA (nada se ha borrado todavía) ===\n";
    echo "Para borrar de verdad, agrega &confirmar=si al final de la URL.\n\n";
} else {
    echo "=== BORRANDO datos de {$telefono} ===\n\n";
}

$tablas = [
    'whatsapp_conversaciones' => 'telefono',
    'prospectos' => 'telefono',
    'citas_asesoria' => 'telefono',
    'calculos_liquidacion' => 'telefono',
    'compras_curso' => 'telefono',
    'compras_documento_calculo' => 'telefono',
];

foreach ($tablas as $tabla => $columna) {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM {$tabla} WHERE {$columna} = :t");
    $stmtCount->execute([':t' => $telefono]);
    $n = (int)$stmtCount->fetchColumn();

    if ($confirmar === 'si' && $n > 0) {
        $pdo->prepare("DELETE FROM {$tabla} WHERE {$columna} = :t")->execute([':t' => $telefono]);
        echo "{$tabla}: {$n} fila(s) borrada(s).\n";
    } else {
        echo "{$tabla}: {$n} fila(s) " . ($confirmar === 'si' ? '(nada que borrar)' : 'se borrarían') . ".\n";
    }
}

echo "\n" . ($confirmar === 'si'
    ? "Listo -- el número {$telefono} queda como si nunca hubiera escrito antes."
    : "Nada se borró todavía. Agrega &confirmar=si para borrar de verdad.");
echo "\n";
