<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/liquidacion_calculadora.php';

// Herramienta temporal: muestra el salario mínimo diario que el sistema
// tiene guardado ahora mismo (tabla configuracion, clave
// 'salario_minimo_diario') -- para confirmar si está actualizado al
// valor real vigente de CONASAMI o si se quedó desactualizado de un año
// anterior (bug real detectado: el bot usó $278.80, que es el mínimo
// general de 2025, para un caso en Cuernavaca en octubre 2026). Solo
// Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'salario_minimo_diario'");
$stmt->execute();
$crudo = $stmt->fetchColumn();

echo "Valor crudo en la tabla configuracion: " . ($crudo === false ? '(no existe esa fila)' : $crudo) . "\n";
echo "Valor que de verdad usa el sistema ahora mismo (con el fallback si no existe): $" . salario_minimo_diario_actual($pdo) . "\n\n";
echo "Si este NO es el salario mínimo general vigente real (CONASAMI 2026), corrígelo con este SQL en phpMyAdmin:\n\n";
echo "INSERT INTO configuracion (clave, valor) VALUES ('salario_minimo_diario', 'EL_VALOR_CORRECTO')\n";
echo "  ON DUPLICATE KEY UPDATE valor = 'EL_VALOR_CORRECTO';\n";
