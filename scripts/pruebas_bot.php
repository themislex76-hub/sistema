<?php
declare(strict_types=1);

// Casos de prueba de regresión del bot de WhatsApp (14 nov 2026, pieza 6
// del plan de precisión, conversación "chats"). Cada caso sale de un error
// REAL que el bot ya cometió con un cliente; si alguno vuelve a fallar,
// un cambio nuevo rompió un arreglo viejo.
//
// Uso (antes de mandar cualquier cambio de ia_helpers.php,
// liquidacion_calculadora.php, mercadopago_helpers.php o lft_helpers.php):
//   php scripts/pruebas_bot.php
// No usa la base real ni la API de Anthropic: SQLite en memoria, sin costo.
// Prueba las partes deterministas (candados de salida, calculadora,
// precio, consulta de la LFT). Lo que depende del criterio del modelo lo
// cubre la revisión diaria (cron_revisar_conversaciones_bot.php).

$base = dirname(__DIR__);
require $base . '/api/ia_helpers.php';
require_once $base . '/api/mercadopago_helpers.php';
require_once $base . '/api/liquidacion_calculadora.php';
require_once $base . '/api/lft_helpers.php';

$fallas = 0; $total = 0;
function caso(string $nombre, bool $ok, string $detalle = ''): void
{
    global $fallas, $total;
    $total++;
    if (!$ok) $fallas++;
    echo ($ok ? '  OK   ' : '  FALLA ') . $nombre . ($ok || $detalle === '' ? '' : "  -> $detalle") . "\n";
}
function pdo_nuevo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('CURDATE', fn() => date('Y-m-d'), 0);
    $pdo->sqliteCreateFunction('regexp', fn($p, $s) => preg_match('/' . str_replace(['\\\\', '/'], ['\\', '\/'], $p) . '/', (string)$s) ? 1 : 0, 2);
    return $pdo;
}

// ---------------------------------------------------------------------
echo "1) Precio de la asesoría (bug real: se cobró $299 en noviembre)\n";
$pdo = pdo_nuevo();
$pdo->exec("CREATE TABLE citas_asesoria (telefono TEXT, estado TEXT)");
$pdo->exec("CREATE TABLE whatsapp_conversaciones (telefono TEXT, direccion TEXT, texto TEXT, creado_en TEXT)");
$ins = $pdo->prepare("INSERT INTO whatsapp_conversaciones VALUES (?, 'saliente', ?, ?)");
$ins->execute(['A', 'la asesoría es por $299 MXN', '2026-08-20 08:18:00']);
$ins->execute(['A', 'la asesoría de $299 con el Lic. Rubén Buerhend', date('Y-m-d H:i:s', strtotime('-1 day'))]);
$ins->execute(['E', 'Total $61,299.40 ... la asesoría es de $499', date('Y-m-d H:i:s', strtotime('-1 day'))]);
$r = mercadopago_monto_asesoria_a_respetar($pdo, 'A');
caso('cotizado en $299 antes: cobra $499 y avisa del aumento', $r['monto'] == 499 && !empty($r['aviso_cambio_precio']), json_encode($r));
$r = mercadopago_monto_asesoria_a_respetar($pdo, 'E');
caso('"$61,299.40" no se confunde con un precio de $299', $r['monto'] == 499 && empty($r['aviso_cambio_precio']), json_encode($r));
$r = mercadopago_monto_asesoria_a_respetar($pdo, 'NUEVO');
caso('cliente nuevo: $499 sin aviso', $r['monto'] == 499 && empty($r['aviso_cambio_precio']), json_encode($r));

// ---------------------------------------------------------------------
echo "2) Cita \"de hoy\" inexistente (bug real: clienta de Oxxo, asesoría ya realizada el 30-oct)\n";
$pdo = pdo_nuevo();
$pdo->exec("CREATE TABLE citas_asesoria (telefono TEXT, estado TEXT, fecha TEXT)");
$pdo->exec("INSERT INTO citas_asesoria VALUES ('VIEJA','confirmada','2026-10-30')");
$pdo->exec("INSERT INTO citas_asesoria VALUES ('HOY','confirmada','" . date('Y-m-d') . "')");
foreach ([
    ['VIEJA', 'Esto es justo del tipo de detalles que conviene platicar a fondo en tu asesoría de hoy a las 5:00 pm con el Lic. Rubén Buerhend', true],
    ['VIEJA', 'Perfecto, nos vemos entonces hoy a las 5:00 pm como estaba — el Lic. Rubén Buerhend te llamará del número 55 7991 3025.', true],
    ['VIEJA', 'Entiendo, en un momento te debe contactar el Lic. Rubén Buerhend al 55 7991 3025 para tu asesoría de las 5:00 pm', true],
    ['VIEJA', 'El Lic. Rubén te va a llamar hoy a las 5:00 pm a este mismo número.', true],
    ['VIEJA', 'Tengo estos horarios: 1. Hoy viernes 6 de noviembre, 1:00 pm 2. Hoy viernes, 2:00 pm', false],
    ['VIEJA', 'Tu asesoría del viernes 13 de noviembre a las 3:00 pm quedó confirmada', false],
    ['HOY', 'Recuerda tu asesoría de hoy a las 5:00 pm, el Lic. te llamará.', false],
] as [$tel, $txt, $bloquear]) {
    $r = ia_texto_menciona_cita_hoy_sin_evidencia($pdo, $tel, $txt);
    caso(($bloquear ? 'bloquea: ' : 'deja pasar: ') . mb_substr($txt, 0, 60), $r === $bloquear);
}

// ---------------------------------------------------------------------
echo "3) Confirmar pagos (bug real: curso pagado tratado como pago no confirmado)\n";
$pdo = pdo_nuevo();
foreach (['citas_asesoria', 'compras_documento_calculo', 'compras_curso'] as $t) $pdo->exec("CREATE TABLE $t (telefono TEXT, estado TEXT)");
$pdo->exec("INSERT INTO compras_curso VALUES ('CURSO','confirmada')");
$pdo->exec("INSERT INTO compras_curso VALUES ('CURSO_PEND','pendiente')");
$txt = '¡Listo! Tu pago quedó confirmado, ya tienes tu acceso al curso.';
caso('curso confirmado: deja pasar la confirmación', ia_texto_confirma_pago_sin_evidencia($pdo, 'CURSO', $txt) === false);
caso('curso pendiente: bloquea la confirmación', ia_texto_confirma_pago_sin_evidencia($pdo, 'CURSO_PEND', $txt) === true);
caso('sin ningún pago: bloquea la confirmación', ia_texto_confirma_pago_sin_evidencia($pdo, 'NADA', $txt) === true);

// ---------------------------------------------------------------------
echo "4) Calculadora (bug real: a Norma, de confianza, se le prometieron ~\$571 mil con 20 días por año)\n";
$pdo = pdo_nuevo();
$pdo->exec("CREATE TABLE configuracion (clave TEXT, valor TEXT)");
$pdo->exec("INSERT INTO configuracion VALUES ('salario_minimo_diario','315.04')");
$c = calcular_estimado_liquidacion($pdo, '2006-03-06', '2026-11-11', 705, 'injustificado', 14, 0, 'despido', true);
caso('despido de confianza: los 20 días NO entran al total', $c['indemnizacion_20_dias_monto'] == 0 && abs($c['total_estimado'] - 262161.17) < 0.01, 'total=' . $c['total_estimado']);
caso('despido de confianza: 20 días solo como escenario aparte', ($c['indemnizacion_20_dias_si_patron_no_reinstala'] ?? 0) > 0);
$r = calcular_estimado_liquidacion($pdo, '2006-03-06', '2026-11-11', 705, 'injustificado', 14, 0, 'rescision');
caso('rescisión (Art. 52): los 20 días SÍ entran', $r['indemnizacion_20_dias_monto'] > 0 && $r['total_estimado'] > $c['total_estimado']);
$v = calcular_estimado_liquidacion($pdo, '2025-02-11', '2026-11-13', 315.04, 'injustificado', 14, 0, 'despido');
caso('vacaciones reportadas de más: "excedentes", no "prescritas"', $v['vacaciones_anteriores_dias_excedentes'] == 2 && $v['vacaciones_anteriores_dias_prescritos'] == 0,
    'exc=' . $v['vacaciones_anteriores_dias_excedentes'] . ' presc=' . $v['vacaciones_anteriores_dias_prescritos']);
$v = calcular_estimado_liquidacion($pdo, '2020-01-10', '2026-11-13', 315.04, 'injustificado', 60, 0, 'despido');
caso('vacaciones de hace más de 1 año y 6 meses: sí prescritas', $v['vacaciones_anteriores_dias_prescritos'] > 0);

// ---------------------------------------------------------------------
echo "5) Consulta de la LFT (bug real: citar el Art. 47 para una suspensión)\n";
foreach ([['art. 423 fracción X', '423'], ['39 a', '39-A'], ['3 bis', '3 Bis'], ['512-d ter', '512-D Ter'], ['Artículo 48.', '48']] as [$in, $esp]) {
    caso("\"$in\" se busca como \"$esp\"", lft_normalizar_numero($in) === $esp, lft_normalizar_numero($in));
}
$pdo = pdo_nuevo();
$r = lft_consultar_articulos($pdo, ['423']);
caso('sin la tabla de la LFT: avisa "no cites" en vez de tronar', isset($r['error']) && stripos($r['error'], 'no cites') !== false);
caso('detecta los artículos que citó el bot', ia_articulos_citados('Art. 423 fracc. X y los Arts. 76, 81 y 516') === ['423', '76', '81', '516']);

// ---------------------------------------------------------------------
echo "6) Reglas del prompt que no se deben perder\n";
$p = IA_SYSTEM_PROMPT;
caso('criterio Apartado A/B por decreto + contrato colectivo', stripos($p, 'DECRETO DE CREACIÓN') !== false && stripos($p, 'CONTRATO COLECTIVO') !== false);
caso('IMSS como patrón se atiende como Apartado A (bug real: trabajadora del IMSS rechazada)', strpos($p, 'SÍ atiende como Apartado A: IMSS como patrón') !== false);
caso('suspensión: Art. 423 fracc. X, máximo 8 días', strpos($p, '423 fracc. X') !== false && strpos($p, '8 días') !== false);
caso('domingos: Art. 71 (por lo menos 25%) y Art. 73 (salario doble)', strpos($p, 'Art. 71 LFT') !== false && strpos($p, 'Art. 73 LFT') !== false);
caso('salarios caídos con tope de 12 meses', strpos($p, 'tope de 12') !== false);
caso('consultar la LFT antes de citar un artículo', strpos($p, 'consultar_articulo_lft') !== false);
caso('jurisprudencia solo desde buscar_jurisprudencia', strpos($p, 'buscar_jurisprudencia') !== false);
$tools = array_column(IA_TOOLS, 'name');
foreach (['consultar_articulo_lft', 'buscar_en_lft', 'buscar_jurisprudencia', 'consultar_cita_pago', 'consultar_salario_minimo_vigente'] as $t) {
    caso("herramienta $t disponible para el bot", in_array($t, $tools, true));
}

echo "\n" . ($fallas ? "❌ $fallas de $total casos FALLARON -- no mandes el cambio así." : "✅ Los $total casos pasan.") . "\n";
exit($fallas ? 1 : 0);
