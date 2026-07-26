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
    if ($user['rol'] !== 'administrador' && (int)$row['abogado_id'] !== (int)$user['id']) {
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
    'actor','curp','telefono','correo','demandado','giro_empresa','dom_demandado','puesto',
    'fecha_ingreso','fecha_baja','salario_mensual','salario_diario','sdi','instancia','junta',
    'tribunal','exp','status','quien_despidio','puesto_despidio','hora_despido','testigos',
    'dom_testigos','ultima_nota','aguinaldo_dias_pactados','dias_salarios_devengados',
    'fecha_desde_salarios_devengados','dias_vacaciones_anteriores_reclamados',
];

const ETAPA_KEYS = [
    'conciliacion_solicitada','constancia_no_conciliacion','demanda_presentada','demanda_admitida',
    'emplazamiento','contestacion_recibida','objeciones_replica','contrarreplica',
    'manifestaciones_3dias','audiencia_preliminar','audiencia_juicio','sentencia','amparo_directo',
];

// Columnas DECIMAL de MySQL: PDO siempre las entrega como string (para no
// perder precisión), pero el frontend hace aritmética/.toFixed() esperando
// números reales. Hay que convertirlas antes de mandarlas como JSON.
const CAMPOS_NUMERICOS_EXPEDIENTE = [
    'salario_mensual','salario_diario','sdi','antiguedad_anios',
    'indemnizacion_90','indemnizacion_60','prima_antiguedad','vacaciones_dias','vacaciones_monto',
    'prima_vacacional','aguinaldo_dias','aguinaldo_monto','total_90','total_60','honorario_90','honorario_60',
    'convenio_monto','aguinaldo_dias_pactados','dias_salarios_devengados','dias_vacaciones_anteriores_reclamados',
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
const CATEGORIA_DOCUMENTO_KEYS = ['demanda', 'contestacion', 'pruebas', 'actas', 'convenio', 'otro'];

const EXTENSION_DOCUMENTO_PERMITIDA = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];

// Tipos que el navegador puede mostrar solo, sin descargar (PDF e imágenes).
// Word/Excel no se pueden visualizar así — siempre se descargan.
const EXTENSION_VISUALIZABLE_INLINE = ['pdf', 'jpg', 'jpeg', 'png'];

function documentos_dir(int $expedienteId): string
{
    $dir = __DIR__ . '/../data/documentos/' . $expedienteId;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}
