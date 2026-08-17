<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/prospectos_helpers.php';

// Botón manual en "Conversaciones (WhatsApp)" para que el Administrador
// marque (o reactive) un prospecto a mano, para los casos donde la IA no
// llamó la herramienta de registro (o donde ya existía pero quedó con un
// estatus viejo — p. ej. "contactado" de una llamada anterior — y por eso
// no aparece en la lista de pendientes de "Prospectos"). Siempre reactiva
// el estatus a "nuevo" para que no quede escondido.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_admin();
require_csrf();

$in = json_input();
$telefono = trim((string)($in['telefono'] ?? ''));
$tipo = (string)($in['tipo'] ?? '');
$resumen = trim((string)($in['resumen'] ?? ''));

if ($telefono === '') fail('Falta el teléfono.', 400);
if (!in_array($tipo, ['despido', 'asesoria_paga'], true)) fail('Tipo no válido.', 400);

$pdo = db();

// Si ya existe un prospecto para este teléfono, se conserva su nombre y
// estado_ubicacion actuales en vez de perderlos — guardar_prospecto() ya
// hace COALESCE de esos campos si se mandan vacíos.
$existente = $pdo->prepare('SELECT nombre FROM prospectos WHERE telefono = :t');
$existente->execute([':t' => $telefono]);
$prospectoExistente = $existente->fetch();

if ($resumen === '') {
    // Se usa el resumen de calidad ya generado para esta conversación, si
    // existe (ver conversaciones_resumen.php / whatsapp_resumenes), para
    // no dejar el campo vacío — si tampoco hay ese, un texto genérico que
    // dice que fue marcado a mano.
    $resumenIa = $pdo->prepare('SELECT resumen FROM whatsapp_resumenes WHERE telefono = :t');
    $resumenIa->execute([':t' => $telefono]);
    $filaResumen = $resumenIa->fetch();
    $resumen = $filaResumen['resumen'] ?? 'Marcado manualmente por el despacho desde Conversaciones.';
}

guardar_prospecto($pdo, $telefono, null, [
    'tipo' => $tipo,
    'estado' => '',
    'nombre' => $prospectoExistente['nombre'] ?? '',
    'resumen' => $resumen,
], false, true);

respond(['ok' => true]);
