<?php
declare(strict_types=1);

// Carga el expediente y verifica que el usuario actual pueda verlo/editarlo
// (admin: cualquiera; abogado: solo el suyo). Se usa en TODOS los endpoints
// de escritura para que la autorización real viva en el servidor.
function guard_expediente_access(PDO $pdo, array $user, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM expedientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) fail('Expediente no encontrado.', 404);
    if ($user['rol'] !== 'administrador'
        && (int)$row['abogado_id'] !== (int)$user['id']
        && (int)($row['abogado_id_2'] ?? 0) !== (int)$user['id']) {
        fail('No tienes acceso a este expediente.', 403);
    }
    return $row;
}

// La indemnización constitucional, la prima de antigüedad, las vacaciones
// proporcionales, la prima vacacional y el aguinaldo proporcional YA NO se
// capturan a mano: el frontend los calcula (ver computeLiquidacion() en
// app.js) a partir de fecha_ingreso, fecha_baja, salario_diario/sdi y el
// salario mínimo vigente (tabla configuracion, clave salario_minimo_diario).
// Los 4 campos siguientes SÍ se siguen capturando a mano porque no se
// pueden derivar solo de esos datos (aguinaldo pactado por contrato,
// salarios devengados pendientes, vacaciones de años anteriores).
const EDITABLE_CAMPOS = [
    'tipo_asunto','tipo_demanda','actor','curp','telefono','correo','demandado','giro_empresa','dom_demandado','puesto',
    'fecha_ingreso','fecha_baja','salario_mensual','salario_diario','sdi','instancia','junta',
    'tribunal','exp','status','quien_despidio','puesto_despidio','hora_despido','testigos',
    'dom_testigos','ultima_nota','aguinaldo_dias_pactados','dias_salarios_devengados',
    'fecha_desde_salarios_devengados','dias_vacaciones_anteriores_reclamados',
    'prima_vacacional_pct_pactada',
    // Jornada y horas extra (para la demanda) -- ver mergeTags()/calcularJornada() en app.js.
    'hora_entrada','hora_salida','comida_inicio','comida_fin','dias_laborados','dias_laborados_num','tipo_jornada',
    // Rescisión Art. 51 y Prima de antigüedad (ver "Otras plantillas de escritos").
    // banco1/2/3_{nombre,cuenta} se quitaron del todo (11 nov 2026, pedido
    // explícito del usuario) -- las columnas siguen en la base (datos
    // viejos, si los había, no se borraron) pero ya no son editables aquí.
    'rescision_fraccion','rescision_motivo_breve','fecha_rescision','hecho_causa_narrativa',
    'periodo_vacaciones_anterior','periodo_vacaciones_actual',
    'inspeccion_extremos_texto',
    'monto_prima_antiguedad','fecha_solicitud_interna','hora_solicitud_interna','folio_solicitud_interna',
    'funcionario_rh_nombre','funcionario_rh_puesto','numero_oficio_respuesta','fecha_oficio_respuesta',
    'director_firma_nombre','coordinador_archivo_nombre','gerente_gestion_nombre','folio_fiscal_cfdi',
    'periodo_recibo_desde','periodo_recibo_hasta','salario_quincenal_num','rfc_emisor_demandado',
    'fecha_hoja_servicios','numero_documento_fump','fecha_documento_fump',
    'fecha_desde_incumplimiento','persona_responsable_incumplimiento',
];

const ETAPA_KEYS = [
    'conciliacion_prejudicial','conciliacion_solicitada','conciliacion_primer_citatorio','conciliacion_segundo_citatorio',
    'conciliacion_convenio','constancia_no_conciliacion','demanda_presentada','prevencion','demanda_admitida',
    'emplazamiento','contestacion_recibida','objeciones_replica','contrarreplica',
    'manifestaciones_3dias','audiencia_preliminar','audiencia_juicio','sentencia','amparo_directo',
];

// Confirmado real (10 nov 2026): la auditoría integral con IA llenó una
// vez "Objeciones y réplica del actor" con una fecha ANTERIOR a
// "Contestación de demanda recibida" -- imposible, porque esa etapa por
// ley (Art. 873-B LFT) solo puede pasar DESPUÉS de que se reciba la
// contestación. La IA sí vio una fecha real en el contexto, pero la
// atribuyó a la etapa equivocada. Esta función es el candado que faltaba
// en los mecanismos que llenan una etapa sola (acuerdos de boletín,
// documentos subidos, respuesta del abogado al supervisor, auditoría
// integral): ETAPA_KEYS ya trae el orden real del trámite, así que basta
// comparar la fecha nueva contra las de las demás etapas YA registradas
// -- si una etapa ANTERIOR en el orden tiene una fecha más tardía, o una
// POSTERIOR tiene una más temprana, algo está mal y no se aplica.
function etapa_fecha_es_consistente(string $etapaKey, string $fecha, array $etapas): bool
{
    $idx = array_search($etapaKey, ETAPA_KEYS, true);
    if ($idx === false) return true; // clave desconocida -- no es este candado el que debe atraparlo

    $porKey = [];
    foreach ($etapas as $e) {
        if (!empty($e['etapa_key']) && !empty($e['fecha']) && $e['fecha'] !== '0000-00-00') {
            $porKey[$e['etapa_key']] = $e['fecha'];
        }
    }
    foreach (ETAPA_KEYS as $i => $key) {
        if (!isset($porKey[$key])) continue;
        $otraFecha = $porKey[$key];
        if ($i < $idx && $otraFecha > $fecha) return false;
        if ($i > $idx && $otraFecha < $fecha) return false;
    }
    return true;
}

// Candado nuevo (12 nov 2026): confirmado real que `ia_auditar_expediente()`
// (con Haiku) ignoró la instrucción explícita de "nunca calcular un plazo" y
// reportó "Contestación de demanda recibida" con la fecha de VENCIMIENTO de
// los 15 días para contestar (calculada a partir del emplazamiento), no una
// fecha donde el documento confirme que la contestación de verdad se
// recibió -- y por separado atribuyó a "Conciliación prejudicial" la fecha
// del DESPIDO (un evento distinto, no la conciliación en sí). El candado de
// orden cronológico (`etapa_fecha_es_consistente()`) no atrapa ninguno de
// los 2 porque ambas fechas sí quedaban en orden válido. Este candado es un
// respaldo heurístico sobre el texto del MOTIVO que la IA da para
// justificar la fecha -- si el motivo delata que la fecha salió de un
// cálculo de plazo o de un evento distinto al que se está llenando, se
// rechaza en vez de aplicarse a ciegas. No reemplaza la instrucción del
// prompt (que ya pedía esto) -- es la verificación de que de verdad se
// cumplió.
function motivo_sugiere_fecha_calculada(string $motivo): bool
{
    $m = mb_strtolower($motivo, 'UTF-8');
    $patrones = [
        'vence', 'vencimiento', 'venciendo', 'plazo de', 'concediéndole',
        'concediendole', 'concede', 'días para contestar', 'dias para contestar',
        'término de', 'termino de', 'a partir del', 'a partir de la notificación',
        'lo que da', 'contados a partir', 'días hábiles para', 'dias habiles para',
    ];
    foreach ($patrones as $p) {
        if (mb_strpos($m, $p) !== false) return true;
    }
    return false;
}

// Panel "Posibles irregularidades" (12 nov 2026) -- a diferencia de
// etapa_fecha_es_consistente() (que solo revisa una fecha NUEVA candidata
// contra las ya registradas, usada por los 5 mecanismos de llenado
// automático), esta función revisa TODAS las parejas de etapas YA
// registradas de un expediente entre sí -- detecta violaciones que
// entraron por captura MANUAL del abogado (ese candado nunca corre ahí,
// solo cuando la IA llena algo sola). Devuelve [etapa_key => motivo] --
// la clave es la etapa cuya fecha queda ANTES de lo que debería (la que
// tiene algo que "debió pasar antes" con una fecha más tardía).
function detectar_irregularidades_orden_cronologico(array $etapas): array
{
    $porKey = [];
    foreach ($etapas as $e) {
        if (!empty($e['etapa_key']) && !empty($e['fecha']) && $e['fecha'] !== '0000-00-00') {
            $porKey[$e['etapa_key']] = $e['fecha'];
        }
    }
    $labels = defined('ETAPA_LABELS') ? ETAPA_LABELS : [];
    $resultado = [];
    foreach (ETAPA_KEYS as $idx => $key) {
        if (!isset($porKey[$key])) continue;
        $fecha = $porKey[$key];
        $motivos = [];
        foreach (ETAPA_KEYS as $idxAnterior => $keyAnterior) {
            if ($idxAnterior >= $idx || !isset($porKey[$keyAnterior])) continue;
            $fechaAnterior = $porKey[$keyAnterior];
            if ($fechaAnterior > $fecha) {
                $motivos[] = ($labels[$keyAnterior] ?? $keyAnterior) . " ($fechaAnterior) es POSTERIOR a "
                    . ($labels[$key] ?? $key) . " ($fecha), pero por el orden del trámite debería ser antes";
            }
        }
        if ($motivos) $resultado[$key] = implode('; ', $motivos);
    }
    return $resultado;
}

// Texto corto para el historial de cambios (log_historial) cuando se
// guarda una etapa -- null si la etapa quedó vacía (para que
// log_historial no registre nada en ese caso, ya trae su propio guard de
// "antes === despues").
function etapa_texto_resumen(?array $r): ?string
{
    if (!$r) return null;
    $partes = [];
    if (!empty($r['fecha'])) $partes[] = 'fecha: ' . $r['fecha'];
    if (!empty($r['hora'])) $partes[] = 'hora: ' . $r['hora'];
    if (!empty($r['fecha_programada'])) $partes[] = 'programada: ' . $r['fecha_programada'];
    if (!empty($r['resultado'])) $partes[] = 'resultado: ' . $r['resultado'];
    return $partes ? implode(', ', $partes) : null;
}

// Columnas DECIMAL de MySQL: PDO siempre las entrega como string (para no
// perder precisión), pero el frontend hace aritmética/.toFixed() esperando
// números reales. Hay que convertirlas antes de mandarlas como JSON.
const CAMPOS_NUMERICOS_EXPEDIENTE = [
    'salario_mensual','salario_diario','sdi','antiguedad_anios',
    'indemnizacion_90','indemnizacion_60','prima_antiguedad','vacaciones_dias','vacaciones_monto',
    'prima_vacacional','aguinaldo_dias','aguinaldo_monto','total_90','total_60','honorario_90','honorario_60',
    'convenio_monto','aguinaldo_dias_pactados','dias_salarios_devengados','dias_vacaciones_anteriores_reclamados',
    'prima_vacacional_pct_pactada','monto_prima_antiguedad','salario_quincenal_num',
];

function cast_numeric_fields(array $row): array
{
    foreach (CAMPOS_NUMERICOS_EXPEDIENTE as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null) $row[$k] = (float)$row[$k];
    }
    return $row;
}

// ----------------------------------------------------------------------------
// Gestión documental por expediente. Los archivos viven en disco, fuera del
// alcance HTTP directo (data/.htaccess tiene "Require all denied"); solo se
// sirven a través de documentos_descargar.php, que valida sesión y permisos.
// ----------------------------------------------------------------------------
const CATEGORIA_DOCUMENTO_KEYS = ['constancia_no_conciliacion', 'demanda', 'contestacion', 'pruebas', 'actas', 'convenio', 'acuerdo', 'acuerdo_amparo', 'otro'];

const EXTENSION_DOCUMENTO_PERMITIDA = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'txt'];

// Tipos que el navegador puede mostrar solo, sin descargar (PDF, imágenes y
// texto plano -- este último es lo que sube el robot de CDMX, que no tiene
// un PDF que descargar, solo el texto ya recortado del boletín).
// Word/Excel no se pueden visualizar así — siempre se descargan.
const EXTENSION_VISUALIZABLE_INLINE = ['pdf', 'jpg', 'jpeg', 'png', 'txt'];

function documentos_dir(int $expedienteId): string
{
    $dir = __DIR__ . '/../data/documentos/' . $expedienteId;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}
