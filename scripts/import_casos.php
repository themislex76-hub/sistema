<?php
declare(strict_types=1);

/**
 * Script de importación — se ejecuta UNA SOLA VEZ, después de importar
 * sql/schema.sql, para: (1) crear el usuario Administrador y el de la
 * Lic. Lindsay Luna Maldonado, y (2) cargar los 49 expedientes reales que
 * antes venían embebidos en el HTML.
 *
 * Cómo usarlo (ver docs/DEPLOY_CPANEL.md paso a paso):
 *   1. Sube esta carpeta scripts/ completa (con casos_data.json) al servidor.
 *   2. Ábrelo desde el navegador: https://tudominio/.../scripts/import_casos.php?clave=TU_APP_SECRET
 *      (la misma cadena que pusiste en APP_SECRET dentro de api/db_credentials.php)
 *   3. Copia y guarda en un lugar seguro las contraseñas temporales que se
 *      muestran en pantalla — no se volverán a mostrar.
 *   4. IMPORTANTE: borra o renombra este archivo (y casos_data.json) del
 *      servidor en cuanto termines. Si alguien más lo ejecuta con la clave
 *      correcta, podría volver a crear usuarios.
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../api/db_credentials.php';
require_once __DIR__ . '/../api/db.php';

$clave = $_GET['clave'] ?? '';
if (!hash_equals(APP_SECRET, (string)$clave)) {
    http_response_code(403);
    echo "Clave incorrecta. Pasa ?clave=TU_APP_SECRET (el mismo valor de APP_SECRET en api/db_credentials.php).\n";
    exit;
}

$pdo = db();

$yaHayUsuarios = (int)$pdo->query('SELECT COUNT(*) c FROM usuarios')->fetch()['c'] > 0;
$yaHayCasos = (int)$pdo->query('SELECT COUNT(*) c FROM expedientes')->fetch()['c'] > 0;
if ($yaHayUsuarios || $yaHayCasos) {
    echo "Ya hay datos en la base (usuarios o expedientes). Por seguridad este script no se ejecuta dos veces.\n";
    echo "Si de verdad quieres reimportar, vacía las tablas 'usuarios' y 'expedientes' (y las relacionadas) desde phpMyAdmin y vuelve a intentar.\n";
    exit;
}

function random_temp_password(int $len = 10): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $out;
}

echo "=== Creando usuarios ===\n\n";

$usuariosSemilla = [
    ['nombre' => 'Administrador', 'email' => 'admin@expertoslaborales.com', 'rol' => 'administrador'],
    ['nombre' => 'Lic. Lindsay Luna Maldonado', 'email' => 'lindsay@expertoslaborales.com', 'rol' => 'abogado'],
];

$idsPorNombre = [];
foreach ($usuariosSemilla as $u) {
    $temp = random_temp_password();
    $hash = password_hash($temp, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare(
        'INSERT INTO usuarios (nombre, email, password_hash, rol, debe_cambiar_password, activo) VALUES (:n, :e, :h, :r, 1, 1)'
    );
    $stmt->execute([':n' => $u['nombre'], ':e' => $u['email'], ':h' => $hash, ':r' => $u['rol']]);
    $id = (int)$pdo->lastInsertId();
    $idsPorNombre[$u['nombre']] = $id;
    echo "Usuario: {$u['nombre']}\n";
    echo "  Correo:               {$u['email']}\n";
    echo "  Contraseña temporal:  {$temp}\n";
    echo "  (el sistema pedirá cambiarla en el primer inicio de sesión)\n\n";
}

echo "IMPORTANTE: si el correo real de la Lic. Lindsay Luna Maldonado (o el del\n";
echo "Administrador) es distinto al que se acaba de crear, entra a la vista\n";
echo "'Equipo' ya dentro del sistema (como Administrador) y corrígelo, o edítalo\n";
echo "directamente en phpMyAdmin antes de compartir la contraseña temporal.\n\n";

echo "=== Importando expedientes ===\n\n";

$casos = json_decode(file_get_contents(__DIR__ . '/casos_data.json'), true);
if (!is_array($casos)) {
    echo "No se pudo leer casos_data.json (¿subiste el archivo junto a este script?).\n";
    exit;
}

$columnas = [
    'exp','status','instancia','ultima_nota','actor','demandado','puesto','junta','dom_demandado',
    'fecha_ingreso','salario_mensual','salario_diario','sdi','fecha_baja','curp','antiguedad_anios',
    'telefono','correo','indemnizacion_90','indemnizacion_60','prima_antiguedad','vacaciones_dias',
    'vacaciones_monto','prima_vacacional','aguinaldo_dias','aguinaldo_monto','total_90','total_60',
    'honorario_90','honorario_60','fecha_inicio_conciliacion','firmo_renuncia','fecha_constancia',
    'tribunal','giro_empresa','quien_despidio','puesto_despidio','hora_despido','testigos','dom_testigos',
];
$columnasFecha = ['fecha_ingreso','fecha_baja','fecha_inicio_conciliacion','fecha_constancia'];

$placeholders = implode(',', array_map(fn($c) => ":$c", $columnas));
$colList = implode(',', array_map(fn($c) => "`$c`", $columnas));
$sql = "INSERT INTO expedientes ($colList, abogado_id) VALUES ($placeholders, :abogado_id)";
$ins = $pdo->prepare($sql);

$importados = 0;
$avisosFecha = [];
foreach ($casos as $c) {
    $params = [];
    foreach ($columnas as $col) {
        $v = $c[$col] ?? null;
        if ($v === '') $v = null;
        // La hoja original trae algún dato mal capturado en columnas de fecha
        // (p.ej. una hora en vez de una fecha) — se descarta en vez de tronar.
        if ($v !== null && in_array($col, $columnasFecha, true) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) {
            $avisosFecha[] = "Expediente {$c['exp']} (id {$c['id']}): '{$col}' tenía un valor no válido como fecha (\"{$v}\"), se dejó en blanco.";
            $v = null;
        }
        $params[":$col"] = $v;
    }
    $nombreAbogado = $c['abogado'] ?? null;
    $params[':abogado_id'] = $nombreAbogado && isset($idsPorNombre[$nombreAbogado]) ? $idsPorNombre[$nombreAbogado] : null;
    $ins->execute($params);
    $importados++;
}

if ($avisosFecha) {
    echo "Avisos (revisa estos datos manualmente si hace falta):\n";
    foreach ($avisosFecha as $a) echo "  - {$a}\n";
    echo "\n";
}

echo "Expedientes importados: {$importados}\n\n";
echo "=== Listo ===\n";
echo "Ahora borra (o renombra) scripts/import_casos.php y scripts/casos_data.json del servidor.\n";
