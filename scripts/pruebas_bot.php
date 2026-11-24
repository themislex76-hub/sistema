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
require_once $base . '/api/whatsapp_helpers.php';

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
    ['HOY', 'Veo que ya tienes una asesoría agendada y confirmada para hoy miércoles 14 de octubre a las 4:00 pm', date('Y-m-d') !== '2026-10-14'],
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
$t24 = calcular_estimado_liquidacion($pdo, '2016-01-01', '2024-06-30', 680, 'injustificado', 0, 0, 'despido');
$esperado24 = round(12 * ((strtotime('2024-06-30') - strtotime('2016-01-01')) / 86400 + 1) / 365 * 2 * 248.93, 2);
caso('prima de antigüedad: tope con el salario mínimo de la FECHA DEL DESPIDO (2024: 2 × $248.93)', abs($t24['prima_antiguedad_monto'] - $esperado24) < 1, $t24['prima_antiguedad_monto'] . ' vs ' . $esperado24);
$t26 = calcular_estimado_liquidacion($pdo, '2018-11-14', '2026-11-13', 680, 'injustificado', 0, 0, 'despido');
caso('prima de antigüedad 2026: tope con el salario mínimo vigente (2 × $315.04), nunca sobre $680 completo', $t26['prima_antiguedad_monto'] < 12 * 8 * 680 && $t26['prima_antiguedad_monto'] > 0);
caso('lada 664 se reconoce como Tijuana (frontera)', telefono_ciudad_frontera('5216641234567') === 'Tijuana');
caso('lada 55 (CDMX) no es frontera', telefono_ciudad_frontera('5215512345678') === null);
$f24 = calcular_estimado_liquidacion($pdo, '2016-01-01', '2024-06-30', 900, 'injustificado', 0, 0, 'despido', false, null, true);
caso('frontera norte 2024: tope con 2 × $374.89', abs($f24['prima_antiguedad_tope_salario_minimo'] - 374.89) < 0.01, (string)$f24['prima_antiguedad_tope_salario_minimo']);
$f26 = calcular_estimado_liquidacion($pdo, '2018-11-14', '2026-11-13', 680, 'injustificado', 0, 0, 'despido', false, null, true);
$g26 = calcular_estimado_liquidacion($pdo, '2018-11-14', '2026-11-13', 680, 'injustificado', 0, 0, 'despido');
caso('frontera norte 2026: $680 no rebasa el tope (2 × $440.87), zona general sí', $f26['prima_antiguedad_monto'] > $g26['prima_antiguedad_monto']);
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
caso('cierre de empresa: Arts. 434-436, no "despido injustificado" (casos reales 13-nov)', strpos($p, '(Art. 436)') !== false && strpos($p, 'Cierre de la empresa') !== false);
caso('despido vs. rescisión: no convertir una baja en rescisión para sumar 20 días', strpos($p, 'Despido vs. rescisión') !== false);
caso('horas extra: transitorios 2026 (48 h / 9 extra), Art. 66 doble, Art. 68 triple, no Art. 67', strpos($p, '2026: jornada máxima de 48 horas') !== false && strpos($p, 'El Art. 67 ya NO es el de las') !== false);
caso('montos solo de la calculadora (prima de antigüedad con tope, caso real $64,361)', strpos($p, 'nunca calcules a mano la prima de antigüedad') !== false);
caso('guía de artículos verificados (Art. 41 sustitución, 47-XIII embriaguez, 684-E XIII cosa juzgada)', strpos($p, 'Sustitución patronal: Art. 41') !== false && strpos($p, 'fracc. XIII = embriaguez') !== false && strpos($p, 'cosa juzgada: Art. 684-E') !== false);
caso('no citar números de artículos de otras leyes (LSS, ISR)', strpos($p, 'NUNCA cites números de sus artículos') !== false);
caso('rescisión: hecho concreto dentro de 30 días antes de calcular', strpos($p, 'confirma (1) qué hecho') !== false);
caso('revisor: "Art. 47-X" se entiende como fracción X del 47', ia_articulos_citados('Art. 47-X') === ['47-X']);
caso('seguimiento: "ahorita no tengo dinero" cuenta como posponer', whatsapp_texto_parece_declinar('si quiero pero ahorita no tengo dinero') && whatsapp_texto_parece_declinar('cuando me paguen la agendo') && !whatsapp_texto_parece_declinar('si quiero agendar'));
caso('el despacho también asesora empresas', strpos($p, 'el despacho SÍ asesora también a empresas') !== false);
caso('embarazo: Art. 166 / 170-I, nunca 132 XXVII Bis (es paternidad)', strpos($p, 'Art. 166') !== false && strpos($p, 'PATERNIDAD') !== false);
$rev = ia_parsear_revision_bot('{"veredicto":"revisar","gravedad":"media","resumen":"x","hallazgos":[{"cita":"a","problema":"no hay error aquí en realidad","correccion":"N/A - revisar si aplica"}]}');
caso('revisión diaria: descarta hallazgos que la propia IA reconoce como no-error', $rev['veredicto'] === 'ok');
caso('consultar la LFT antes de citar un artículo', strpos($p, 'consultar_articulo_lft') !== false);
caso('jurisprudencia solo desde buscar_jurisprudencia', strpos($p, 'buscar_jurisprudencia') !== false);
$tools = array_column(IA_TOOLS, 'name');
foreach (['consultar_articulo_lft', 'buscar_en_lft', 'buscar_jurisprudencia', 'consultar_cita_pago', 'consultar_salario_minimo_vigente'] as $t) {
    caso("herramienta $t disponible para el bot", in_array($t, $tools, true));
}

// ---------------------------------------------------------------------
echo "7) Respuestas duplicadas (bug real 13-14 nov: 50 casos en 7 días)\n";
$wp = file_get_contents($base . '/api/whatsapp_procesar.php');
caso('cron de reanudar no contesta mensajes de menos de 10 min (los contesta el flujo normal)', strpos($wp, '< 600') !== false && strpos($wp, 'lo está contestando el flujo normal') !== false);
caso('aviso de fuera de horario con candado GET_LOCK (no se manda 2-3 veces)', strpos($wp, "GET_LOCK(:k, 10)") !== false);

// ---------------------------------------------------------------------
echo "8) Revisión forzada del 14-nov: falsas alarmas del revisor y huecos reales\n";
caso('revisor: seguimientos y confirmaciones del webhook se etiquetan como SISTEMA',
    ia_es_mensaje_automatico('¡Hola! Sigo al pendiente de tu caso. Si quieres...')
    && ia_es_mensaje_automatico('¡Hola! No quiero que se te vaya a pasar — según lo que me contaste...')
    && ia_es_mensaje_automatico('¡Hola de nuevo! Sigo por aquí para cuando gustes.')
    && ia_es_mensaje_automatico('¡Tu pago quedó confirmado! Tu asesoría telefónica de 1 hora queda agendada')
    && ia_es_mensaje_automatico('¡Hola Ana! Tu pago del curso *Nuevo Procedimiento* quedó confirmado. 🎉')
    && !ia_es_mensaje_automatico('Con lo que me cuentas, es un despido injustificado (Art. 48 LFT).'));
caso('bot: preguntar por la solicitud de conciliación antes de decir que el plazo venció', strpos($p, 'pregúntale si presentó solicitud ante el') !== false);
caso('Art. 51 por fracción: falta de pago = V, reducción = IV (error real: fracc. II)', strpos($p, 'IV reducción del salario; V no pagarle el salario') !== false);
caso('vacaciones: 6 a 10 años = 22 días (revisor marcó mal 22 días con 6 años)', strpos($p, 'de 6 a 10 años = 22') !== false);
caso('paro técnico: Art. 430, no afirmar salario normal', strpos($p, 'un mes de salario (Art. 430)') !== false);
caso('cliente corrige su salario: no insistir, recalcular', strpos($p, 'no insistas en que él está confundido') !== false);
caso('seguimiento: "no tengo economía" / "no me alcanza" cuentan como posponer', whatsapp_texto_parece_declinar('gracias pero no tengo economía para más gastos') && whatsapp_texto_parece_declinar('ahorita no me alcanza'));

// ---------------------------------------------------------------------
echo "9) Gerente de $65,000/mes (14-nov): SDI dividido entre 15 y promesa de abogado sin escalar\n";
caso('prompt: salario diario y SDI del recibo ya son diarios, nunca dividir entre 15', strpos($p, 'YA SON DIARIOS') !== false);
caso('detecta promesa de abogado ("Voy a pedir que un abogado del despacho revise tu caso")',
    ia_texto_promete_abogado('Voy a pedir que un abogado del despacho revise tu caso directamente contigo para aclarar el cálculo.')
    && ia_texto_promete_abogado('En un momento un abogado del despacho te contacta por aquí mismo.')
    && !ia_texto_promete_abogado('El Lic. Rubén Buerhend te va a llamar a este mismo número a esa hora.')
    && !ia_texto_promete_abogado('Eso lo revisa a detalle el abogado en la asesoría telefónica.'));
$pdoC = pdo_nuevo();
$pdoC->exec("CREATE TABLE configuracion (clave TEXT, valor TEXT)");
$pdoC->exec("INSERT INTO configuracion VALUES ('salario_minimo_diario','315.04')");
$g = calcular_estimado_liquidacion($pdoC, '2025-06-09', '2026-11-14', 2166.67, 'injustificado', 6, 0, 'despido', false, 2349.11);
caso('calculadora con los datos reales del recibo (SDI $2,349.11): 3 meses = $211,419.90', abs($g['indemnizacion_90_dias_monto'] - 211419.90) < 0.5);

// ---------------------------------------------------------------------
echo "10) Revisión del 15-nov: día de descanso obligatorio y conciliación\n";
caso('descanso obligatorio: triple es correcto, pero no "tienes derecho a no presentarte" (Art. 75)', strpos($p, 'quedarán obligados a prestar los servicios') !== false);
caso('solicitud ante el Centro vs. demanda ante el Tribunal', strpos($p, 'la DEMANDA se') !== false);

// ---------------------------------------------------------------------
echo "11) Revisión del 16-nov: anuncios sin hacer y conciliación ignorada\n";
caso('detecta "déjame calcular/confirmar" sin hacerlo',
    ia_texto_anuncia_sin_hacer('Déjame calcular esto con los datos exactos.')
    && ia_texto_anuncia_sin_hacer('Voy a calcularlo con el salario mínimo vigente real para que el número sea exacto.')
    && ia_texto_anuncia_sin_hacer('Déjame confirmar el fundamento exacto antes de explicarte esto con precisión.')
    && !ia_texto_anuncia_sin_hacer('Con lo que me cuentas, te corresponden 3 meses de salario. ¿Me confirmas tu fecha de ingreso?')
    && !ia_texto_anuncia_sin_hacer('Voy a pedir que un abogado del despacho revise tu caso.'));
caso('detecta que el cliente ya mencionó conciliación',
    ia_conversacion_menciona_conciliacion([['role' => 'user', 'content' => 'Ya fui a conciliación y el día 2 de diciembre tengo la audiencia']])
    && !ia_conversacion_menciona_conciliacion([['role' => 'user', 'content' => 'Me despidieron ayer sin razón']]));
caso('recordatorio de pago y aviso de PDF también son mensajes automáticos', ia_es_mensaje_automatico('¡No se te vaya a ir tu horario! Te quedan 10 minutos') && ia_es_mensaje_automatico('📄 PDF de cálculo de liquidación enviado.'));
caso('Art. 56 no es para cambios de horario', strpos($p, 'El Art. 56 es de igualdad') !== false);

// ---------------------------------------------------------------------
echo "12) Municipios donde el despacho sí lleva juicios (lista del 17-nov)\n";
$municipios = ['Tlalnepantla', 'Atizapán de Zaragoza', 'Cuautitlán Izcalli', 'Coyotepec', 'Huixquilucan', 'Huehuetoca',
    'Isidro Fabela', 'Jilotzingo', 'Melchor Ocampo', 'Naucalpan', 'Nicolás Romero', 'Teoloyucan', 'Tepotzotlán', 'Tultepec',
    'Tultitlán', 'Ecatepec', 'Apaxco', 'Coacalco', 'Hueypoxtla', 'Jaltenco', 'Nextlalpan', 'Tecámac', 'Tequixquiac',
    'Tonanitla', 'Zumpango'];
$faltan = array_filter($municipios, fn($m) => strpos($p, $m) === false);
caso('la lista completa de municipios está en el prompt' . ($faltan ? ' (faltan: ' . implode(', ', $faltan) . ')' : ''), !$faltan);
// ---------------------------------------------------------------------
echo "13) Coach de tenis (13-nov): plazo vencido, Art. 89, vacaciones, pagos de terceros\n";
caso('detecta que el bot ya dijo que el plazo del despido venció',
    ia_bot_dijo_plazo_despido_vencido([['role' => 'assistant', 'content' => 'la ley (Art. 518 LFT) da un plazo de 2 meses para demandar un despido — ese plazo venció el 13 de octubre de 2026']])
    && !ia_bot_dijo_plazo_despido_vencido([['role' => 'assistant', 'content' => 'Tienes 2 meses para demandar, todavía estás a tiempo.']])
    && !ia_bot_dijo_plazo_despido_vencido([['role' => 'user', 'content' => 'creo que ya se me venció el plazo']]));
caso('salario variable con Art. 89 (30 días), no Art. 289', strpos($p, 'treinta días') !== false && strpos($p, 'efectivamente trabajados antes del nacimiento del derecho') !== false && strpos($p, 'cites el Art. 289 para esto') !== false);
caso('vacaciones nunca disfrutadas se pasan a la calculadora', strpos($p, 'Vacaciones nunca disfrutadas') !== false);
caso('pagos de terceros: no afirmar que es trabajador', strpos($p, 'Cuando le pagan TERCEROS') !== false);

// ---------------------------------------------------------------------
echo "14) Revisión del 17-nov: cierre, negociación, abandono, desglose completo\n";
caso('cierre de empresa nunca "despido injustificado" (error repetido 17-nov)', strpos($p, 'NUNCA escribas "despido injustificado"') !== false);
caso('no validar porcentajes de negociación con cifras', strpos($p, 'Escenarios de negociación') !== false);
caso('dejó de ir por su cuenta: no estimado de rescisión', strpos($p, 'DEJÓ DE IR por su cuenta') !== false);
caso('presunción Art. 21 y comisiones Art. 84', strpos($p, 'Presunción de la relación de trabajo: Art. 21') !== false);

caso('fuera de la lista: solo se aclara si preguntan por representación, nunca se promete', strpos($p, 'NO saques el tema de la zona por tu cuenta') !== false && strpos($p, 'En ningún caso le prometas ni le insinúes') !== false);

// ---------------------------------------------------------------------
echo "15) Documento de \$49 (17-nov, cajero de Los Mochis): pagó el 1er link y el bot generó otro\n";
$pdo = pdo_nuevo();
$pdo->exec("CREATE TABLE compras_documento_calculo (id INTEGER PRIMARY KEY, telefono TEXT, calculo_json TEXT, salario_diario REAL, monto REAL, estado TEXT DEFAULT 'pendiente', pagado_en TEXT, creado_en TEXT, link_pago TEXT, mp_preference_id TEXT, nombre_cliente TEXT)");
$pdo->exec("CREATE TABLE calculos_liquidacion (id INTEGER PRIMARY KEY, telefono TEXT, calculo_json TEXT, salario_diario REAL)");
$pdo->exec("INSERT INTO calculos_liquidacion (telefono, calculo_json, salario_diario) VALUES ('M', '{}', 315)");
$hace2 = date('Y-m-d H:i:s', strtotime('-2 minutes'));
$pdo->exec("INSERT INTO compras_documento_calculo (telefono, calculo_json, salario_diario, monto, estado, pagado_en, creado_en) VALUES ('M', '{}', 315, 49, 'confirmada', '$hace2', '$hace2')");
$pdo->exec("INSERT INTO compras_documento_calculo (telefono, calculo_json, salario_diario, monto, estado, creado_en, link_pago) VALUES ('M', '{}', 315, 49, 'pendiente', '" . date('Y-m-d H:i:s') . "', 'http://link2')");
$r = json_decode(ia_resultado_consultar_compra_documento_calculo($pdo, 'M'), true);
caso('consultar: un link nuevo sin pagar no esconde el pago del primero', !empty($r['documento_pagado']), json_encode($r, JSON_UNESCAPED_UNICODE));
$r = json_decode(ia_resultado_confirmar_compra_documento_calculo($pdo, 'M'), true);
$n = (int)$pdo->query("SELECT COUNT(*) FROM compras_documento_calculo")->fetchColumn();
caso('confirmar: ya pagado en 30 días -> no genera otro cobro', empty($r['ok']) && !empty($r['ya_pagado']) && $n === 2, json_encode($r, JSON_UNESCAPED_UNICODE));
$pdo->exec("UPDATE compras_documento_calculo SET pagado_en = '" . date('Y-m-d H:i:s', strtotime('-40 days')) . "' WHERE estado = 'confirmada'");
caso('compra pagada hace 40 días ya no bloquea una nueva', ia_compra_documento_confirmada_reciente($pdo, 'M', 30) === null);

// ---------------------------------------------------------------------
echo "16) Revisión del 19-nov: vacaciones de todos los años, accidente de trabajo, revisor calibrado\n";
$p = IA_SYSTEM_PROMPT;
caso('pregunta por TODOS los años anteriores y pasa la suma', strpos($p, 'TODOS los años anteriores') !== false && strpos($p, 'SUMA de lo no tomado') !== false);
caso('antes del primer año: el proporcional cubre todo', strpos($p, 'Si aún NO cumple su primer año') !== false);
caso('accidente de trabajo: orientación mínima y Art. 685 Ter fracc. III', strpos($p, 'Art. 685 Ter fracc. III') !== false);
caso('cifras exactas de la herramienta', strpos($p, 'Copia las cifras EXACTAS') !== false);
$src = file_get_contents($base . '/api/ia_helpers.php');
caso('revisor: no marca "solo el último año" si no aplica', strpos($src, 'Solo calculó las vacaciones del último año') !== false);
caso('revisor: tabla del Art. 76 hasta 30 años', strpos($src, 'de 16 a 20 = 26, de 21 a 25 = 28') !== false);
$cron = file_get_contents($base . '/api/cron_revisar_conversaciones_bot.php');
caso('revisor recibe días de vacaciones y prescritos de la calculadora', strpos($cron, 'vacaciones_anteriores_dias_prescritos') !== false);

// ---------------------------------------------------------------------
echo "17) Revisión del 19-22 nov: montos a mano, reestructura, trabajo del hogar, curso pagado\n";
$resp = json_encode([['role' => 'assistant', 'content' => 'Total estimado: $67,166.59']]) . ' {"total_estimado":115672.98,"aguinaldo_monto":2187.74}';
caso('cifra con centavos hecha a mano se detecta', ia_montos_sin_respaldo('serían hasta $1,070.01', $resp) === ['$1,070.01']);
caso('cifra de la calculadora (JSON sin comas) no se marca', ia_montos_sin_respaldo('Total: $115,672.98 y aguinaldo $2,187.74', $resp) === []);
caso('cifra ya dicha en la conversación no se marca', ia_montos_sin_respaldo('Como te dije, $67,166.59', $resp) === []);
caso('aproximación sin centavos no se marca', ia_montos_sin_respaldo('serían unos $6,000 a la semana', $resp) === []);
$p = IA_SYSTEM_PROMPT;
caso('reestructura: dos escenarios y sin salarios caídos', strpos($p, 'Reestructura, recorte de personal') !== false && strpos($p, 'NO prometas salarios caídos en este caso') !== false);
caso('trabajo del hogar: capítulo especial Arts. 331 a 343', strpos($p, 'Arts. 331 a 343') !== false);
$cron = file_get_contents($base . '/api/cron_seguimiento_cursos.php');
caso('seguimiento de curso no le vende a quien ya dijo que pagó', strpos($cron, 'yaDijoQuePago') !== false && strpos($cron, 'p2.pausado_bot = 1') !== false);

// ---------------------------------------------------------------------
echo "18) Salario semanal entre 7 (criterio del Lic. Rubén, 24-nov, Art. 89)\n";
$p = IA_SYSTEM_PROMPT;
caso('regla del prompt: semanal entre 7 siempre', strpos($p, 'divide el salario semanal entre 7, SIEMPRE') !== false && strpos($p, 'NUNCA lo\n    dividas entre 7') === false);
caso('herramienta: el parámetro salario_diario dice semanal entre 7', substr_count(json_encode(IA_TOOLS, JSON_UNESCAPED_UNICODE), 'divídelo entre 7 siempre (Art. 89 LFT)') === 2);

echo "\n" . ($fallas ? "❌ $fallas de $total casos FALLARON -- no mandes el cambio así." : "✅ Los $total casos pasan.") . "\n";
exit($fallas ? 1 : 0);
