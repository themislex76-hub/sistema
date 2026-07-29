<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_login();
require_csrf();

// Mismo listado que ESTADOS_MX en assets/app.js — mantenerlos sincronizados.
const ESTADOS_MX = [
    'Aguascalientes', 'Baja California', 'Baja California Sur', 'Campeche', 'Chiapas',
    'Chihuahua', 'Ciudad de México', 'Coahuila de Zaragoza', 'Colima', 'Durango',
    'Estado de México', 'Guanajuato', 'Guerrero', 'Hidalgo', 'Jalisco', 'Michoacán',
    'Morelos', 'Nayarit', 'Nuevo León', 'Oaxaca', 'Puebla', 'Querétaro', 'Quintana Roo',
    'San Luis Potosí', 'Sinaloa', 'Sonora', 'Tabasco', 'Tamaulipas', 'Tlaxcala',
    'Veracruz', 'Yucatán', 'Zacatecas',
];

$in = json_input();
$estado = trim((string)($in['estado'] ?? ''));
$nombre = trim((string)($in['nombre'] ?? ''));

if (!in_array($estado, ESTADOS_MX, true)) fail('Estado no válido.', 400);
if ($nombre === '' || mb_strlen($nombre) > 300) fail('Nombre de tribunal inválido.', 400);

$pdo = db();
$stmt = $pdo->prepare(
    'INSERT INTO tribunales_custom (estado, nombre) VALUES (:estado, :nombre)
     ON DUPLICATE KEY UPDATE estado = estado'
);
$stmt->execute([':estado' => $estado, ':nombre' => $nombre]);

respond(['ok' => true]);
