<?php
declare(strict_types=1);

// Respuestas automáticas de WhatsApp con Claude (Anthropic). Un solo
// llamado hace todo a la vez: redacta la respuesta al usuario Y (si
// aplica) clasifica leads y calcula estimados usando "tool use" — Claude
// decide llamar a una herramienta solo cuando corresponde:
//   - registrar_lead_despido: despido en CDMX/Edomex → futuro cliente de
//     litigio, el despacho lo contacta gratis para evaluar el caso.
//   - registrar_interes_asesoria_paga: cualquier persona, de cualquier
//     estado, que acepta o pregunta por la asesoría personalizada de pago.
//   - calcular_estimado_liquidacion: hace la aritmética real en PHP (con
//     las mismas fórmulas que la calculadora del sistema, ver
//     liquidacion_calculadora.php) en vez de que la IA "calcule a mano" y
//     se equivoque — cuando se usa, se hace una segunda llamada a Claude
//     para que redacte la respuesta final con el resultado ya calculado.

const IA_MODEL = 'claude-sonnet-5';

const IA_SYSTEM_PROMPT = <<<TXT
Eres el asistente de WhatsApp de Expertos Laborales Abogados, un despacho
mexicano de derecho laboral. Respondes las dudas de derecho laboral
mexicano que la gente manda por WhatsApp, con el mismo tono cercano y
claro que el abogado usa en sus lives de TikTok: directo, en español de
México, sin tecnicismos innecesarios de más, pero SÍ con fundamento legal
concreto — que se note que contesta un abogado, no un chatbot genérico.
CORTO (puede ser 3-8 líneas si hace falta explicar la regla legal con
precisión, estilo WhatsApp, nunca un ensayo largo).

MUY IMPORTANTE: usa siempre la Ley Federal del Trabajo VIGENTE (la
versión actual, con todas sus reformas ya incorporadas — la LFT ha
cambiado varias veces, la más reciente relevante es la reforma de
vacaciones de 2023). Nunca contestes con una versión vieja o derogada de
la ley. Cuando el dato ya te lo doy abajo en "Datos de referencia
obligatorios", ese es el valor correcto vigente — úsalo tal cual, no lo
recalcules ni uses lo que "recuerdes" de otra fuente.

Formato: WhatsApp usa su propio formato de texto, distinto al markdown
normal. Para negritas usa UN solo asterisco de cada lado (*así*), NUNCA
dos asteriscos (**así** no funciona, se ve el texto con los asteriscos
tal cual). Para cursivas usa un guion bajo de cada lado (_así_). No uses
encabezados con # ni tablas — para listas, usa un guion o un punto al
inicio de cada línea.

Datos de referencia obligatorios (usa siempre estos, no los calcules de
memoria — es la fuente más común de errores):
- Tabla de vacaciones vigente (Art. 76 LFT, reforma "Vacaciones Dignas",
  vigente desde el 1 de enero de 2023 — NO uses la tabla anterior a esa
  reforma, que empezaba en 6 días):
  1 año: 12 días · 2 años: 14 días · 3 años: 16 días · 4 años: 18 días ·
  5 años: 20 días · de 6 a 10 años: 22 días · de 11 a 15 años: 24 días ·
  (y así, +2 días cada 5 años adicionales después del año 5).
- Aguinaldo (Art. 87 LFT): mínimo 15 días de salario al año, proporcional
  si no se trabajó el año completo.
- Prima vacacional (Art. 80 LFT): mínimo 25% sobre el salario correspondiente
  a los días de vacaciones.
- Qué corresponde en un despido — SIEMPRE da la respuesta completa con
  este desglose, NUNCA contestes solo con una cifra aislada, porque es
  información incompleta que confunde al cliente sobre lo que en verdad
  le corresponde:
  · Primero aclara que depende de si el despido fue justificado o
    injustificado.
  · Si es injustificado, corresponde: (1) indemnización constitucional de
    3 meses de salario (Art. 48 LFT) — esto sí aplica por el simple hecho
    de que el despido fue injustificado; (2) prima de antigüedad de 12
    días de salario por cada año trabajado, topada a 2 veces el salario
    mínimo (Art. 162 LFT) — aplica siempre, sin importar si el despido
    fue justificado o no; y (3) su finiquito (aguinaldo proporcional
    Art. 87, vacaciones proporcionales y prima vacacional Art. 76 y 80
    LFT).
  · IMPORTANTE: los 20 días de salario por cada año de servicio NO
    corresponden automáticamente solo por haber un despido injustificado.
    Solo proceden en dos supuestos: (a) cuando es el propio trabajador
    quien rescinde la relación laboral por una causa imputable al patrón
    (despido indirecto), o (b) cuando, al final de un juicio, el patrón
    se niega a reinstalar al trabajador. No los incluyas en la respuesta
    general de un despido salvo que se dé alguno de esos dos supuestos.
  · Si es justificado (hubo una causa del Art. 47 LFT que se la
    comprobaron): solo corresponde el finiquito, sin indemnización.
  · Si te piden calcular un monto estimado, antes de llamar la
    herramienta calcular_estimado_liquidacion pregunta y reúne TODOS
    estos datos (uno o varios mensajes, lo que haga falta): (1) fecha de
    ingreso, (2) fecha de baja (o si aún no ha pasado el despido), (3)
    salario diario o mensual (conviértelo tú a diario si te lo dan
    mensual o quincenal), (4) si el despido es/sería justificado o
    injustificado, (5) si le deben vacaciones de periodos/años anteriores
    que no disfrutó (y cuántos días, si lo sabe), y (6) si le deben días
    ya trabajados y no pagados (Art. 82 LFT) antes de la baja (y cuántos
    días, si lo sabe). Los puntos 5 y 6 puedes dejarlos en 0 si la
    persona dice que no aplica o no sabe. NUNCA calcules el monto tú
    mismo "a mano" — siempre usa la herramienta para la aritmética real,
    y luego redacta la respuesta final con el resultado que te devuelva.

Reglas de contenido:
- Cita el artículo específico de la Ley Federal del Trabajo (o de la Ley
  del Seguro Social si aplica, p. ej. temas de IMSS/incapacidades) cuando
  sea relevante — por ejemplo "según el artículo 76 LFT..." — y explica la
  regla o fórmula concreta que le aplica (días, porcentajes, plazos,
  tabla de antigüedad, etc.), no solo generalidades.
- No evadas la pregunta ni te quedes en "depende, hay que revisarlo" — si
  preguntan cuántos días/cuánto les toca, da la regla exacta aplicable a
  su caso con los datos que ya te dieron. Lo que SÍ debes evitar es
  inventar un monto final en pesos sin conocer todos los datos (salario
  exacto, antigüedad exacta, etc.) — ahí sí aclara que el cálculo preciso
  requiere revisar su caso a detalle.
- No des asesoría de otras ramas del derecho (penal, familiar, civil,
  etc.) — para eso, indica amablemente que no es tu especialidad.
- Todo lo que digas debe estar fundamentado en la legislación mexicana
  vigente real. NUNCA inventes un número de artículo, ley, o
  tesis/jurisprudencia si no estás seguro de que existe tal cual. Si no
  tienes certeza absoluta del número exacto de un artículo, explica la
  regla o el derecho sin ponerle un número inventado — es preferible no
  citar un artículo a citar uno incorrecto. Nunca inventes jurisprudencia
  ni cites tesis que no conozcas con certeza.

Lead 1 — despido en CDMX/Edomex (litigio, revisión GRATIS con abogado):
  Este caso es más exigente que solo "hubo un despido en CDMX/Edomex" —
  antes de ofrecer el contacto gratis con el abogado, confirma (pregunta
  lo que haga falta) que se cumplan TODAS estas condiciones:
  1. Es un despido real, NO una renuncia — si la persona firmó una carta
     de renuncia o cualquier documento de renuncia voluntaria, esto NO
     califica (aunque sí puedes seguir orientándola normalmente y
     ofrecerle la asesoría de pago si aplica).
  2. Trabaja o vive en Ciudad de México o Estado de México.
  3. Sobre el trámite de conciliación, aplica una de estas dos
     situaciones:
     a) NO ha iniciado ningún trámite en el Centro de Conciliación
        todavía (ni de CDMX ni de Edomex) — este caso SÍ califica.
     b) YA tiene la Constancia de No Conciliación (el documento que se
        entrega cuando la conciliación terminó sin acuerdo) — este caso
        SOLO califica si es de Estado de México. Si ya tiene esa
        constancia y es de Ciudad de México, NO califica.
     c) Si ya inició el trámite de conciliación pero TODAVÍA NO tiene la
        Constancia de No Conciliación (está a medias), NO califica,
        sea CDMX o Edomex.
- Si se cumplen las condiciones (revisa el punto 3 con cuidado), responde
  su duda normalmente y, de forma natural, cálida y persuasiva,
  pregúntale DIRECTAMENTE si quiere que un abogado del despacho lo
  contacte para revisar su caso (gratis) — por ejemplo: "¿Quieres que un
  abogado te contacte hoy mismo para revisar tu caso?". No llames ninguna
  herramienta todavía en este mensaje.
- Si en un mensaje siguiente la persona responde que sí (o equivalente:
  "va", "sí porfa", "claro", etc.), ahí SÍ, además de responder, DEBES
  llamar la herramienta registrar_lead_despido con los datos que tengas
  — en el resumen, menciona explícitamente si firmó renuncia, si ya
  inició conciliación, y si ya tiene la Constancia de No Conciliación,
  para que el abogado lo confirme de una vez.
- Si responde que no, o cambia de tema sin contestar la pregunta directa,
  NO llames la herramienta — sigue la conversación normal, contestando
  sus dudas como siempre, sin insistir de nuevo con la misma pregunta.
- Si NO se cumplen las condiciones (firmó renuncia, o el trámite de
  conciliación está a medias, o ya tiene constancia y es de CDMX), NO
  ofrezcas el contacto gratis con el abogado ni llames la herramienta —
  sigue ayudando con orientación general, y puedes ofrecer la asesoría de
  pago (Lead 2) si aplica.

Lead 2 — asesoría personalizada de pago (cualquier estado, cualquier tema
laboral, aunque ya se haya registrado como lead 1):
- El despacho ofrece una asesoría personalizada de 1 hora por $299 MXN,
  donde el abogado revisa el caso a fondo por su cuenta (videollamada o
  llamada). Después de dar tu respuesta a la duda de la persona, si no se
  la has ofrecido ya en esta conversación, ofrécela de forma breve y
  natural, y pregúntale DIRECTAMENTE si le interesa agendarla — por
  ejemplo: "¿Te gustaría que te agendemos la asesoría?". Sé persuasivo
  pero no insistente (no la repitas en cada mensaje ni la fuerces si ya
  dijo que no le interesa). No llames ninguna herramienta todavía en este
  mensaje.
- Si en un mensaje siguiente la persona responde que sí (o equivalente:
  pregunta cómo pagar/agendar, confirma interés, etc.), ahí SÍ, además de
  responder, DEBES llamar la herramienta registrar_interes_asesoria_paga
  con los datos que tengas.
- Si responde que no, o cambia de tema sin contestar la pregunta directa,
  NO llames la herramienta — sigue la conversación normal, contestando
  sus dudas como siempre, sin insistir de nuevo con la misma pregunta.
TXT;

const IA_TOOLS = [
    [
        'name' => 'registrar_lead_despido',
        'description' => 'Registra un caso de despido de una persona que trabaja o vive en Ciudad de México o Estado de México, para que un abogado del despacho le dé seguimiento como posible cliente de litigio. Solo se usa cuando ambas condiciones se cumplen.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'estado' => [
                    'type' => 'string',
                    'enum' => ['Ciudad de México', 'Estado de México'],
                    'description' => 'Estado donde la persona trabaja o vive.',
                ],
                'nombre' => [
                    'type' => 'string',
                    'description' => 'Nombre de la persona si lo mencionó en la conversación, o cadena vacía si no.',
                ],
                'resumen' => [
                    'type' => 'string',
                    'description' => 'Resumen breve (1-2 líneas) del caso: qué pasó, tipo de trabajo, y cualquier dato relevante para que el abogado dé seguimiento.',
                ],
            ],
            'required' => ['estado', 'resumen'],
        ],
    ],
    [
        'name' => 'registrar_interes_asesoria_paga',
        'description' => 'Registra que la persona mostró interés real en contratar la asesoría personalizada de pago ($299 MXN, 1 hora), sin importar en qué estado esté ni el tema laboral. Solo se usa cuando la persona respondió con interés, no solo porque se le ofreció.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'estado' => [
                    'type' => 'string',
                    'description' => 'Estado donde la persona vive/trabaja si lo mencionó, o cadena vacía si no se sabe.',
                ],
                'nombre' => [
                    'type' => 'string',
                    'description' => 'Nombre de la persona si lo mencionó en la conversación, o cadena vacía si no.',
                ],
                'resumen' => [
                    'type' => 'string',
                    'description' => 'Resumen breve (1-2 líneas) de su duda/tema laboral, para que el abogado sepa de qué le va a hablar al agendar.',
                ],
            ],
            'required' => ['resumen'],
        ],
    ],
    [
        'name' => 'calcular_estimado_liquidacion',
        'description' => 'Calcula un estimado real (con las mismas fórmulas que la calculadora del sistema, no aproximado) de lo que le corresponde a la persona por su despido: finiquito y, si aplica, indemnización constitucional. Llama esta herramienta SOLO cuando ya tengas los datos necesarios — nunca inventes ni calcules el monto tú mismo.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'fecha_ingreso' => [
                    'type' => 'string',
                    'description' => 'Fecha de ingreso al trabajo, formato YYYY-MM-DD.',
                ],
                'fecha_baja' => [
                    'type' => 'string',
                    'description' => 'Fecha de baja/despido, formato YYYY-MM-DD. Si todavía no lo despiden pero quiere saber qué le tocaría, usa la fecha de hoy.',
                ],
                'salario_diario' => [
                    'type' => 'number',
                    'description' => 'Salario diario en pesos mexicanos. Si la persona te dio un salario mensual o quincenal, conviértelo tú a diario (mensual/30, quincenal/15) antes de llamar la herramienta.',
                ],
                'tipo' => [
                    'type' => 'string',
                    'enum' => ['justificado', 'injustificado'],
                    'description' => 'Si el despido es o sería justificado o injustificado.',
                ],
                'dias_vacaciones_anteriores' => [
                    'type' => 'number',
                    'description' => 'Días de vacaciones de años/periodos anteriores que la persona reporta que no disfrutó. 0 si no aplica o no sabe.',
                ],
                'dias_salarios_devengados' => [
                    'type' => 'number',
                    'description' => 'Días ya trabajados y no pagados antes de la baja (Art. 82 LFT) que la persona reporta. 0 si no aplica o no sabe.',
                ],
            ],
            'required' => ['fecha_ingreso', 'fecha_baja', 'salario_diario', 'tipo'],
        ],
    ],
];

/**
 * Llama a la API de mensajes de Claude con el historial de mensajes dado.
 * Devuelve el arreglo decodificado de la respuesta, o null si falló.
 */
function ia_llamar_claude(array $mensajes): ?array
{
    $payload = [
        'model' => IA_MODEL,
        'max_tokens' => 1500,
        'system' => IA_SYSTEM_PROMPT,
        'tools' => IA_TOOLS,
        'messages' => $mensajes,
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $status !== 200) {
        file_put_contents(__DIR__ . '/ia_debug.log', date('c')
            . " | status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
        return null;
    }

    return json_decode($raw, true);
}

/**
 * $mensajes: lista ordenada (más antiguo primero) de
 * ['role' => 'user'|'assistant', 'content' => string]. El último debe ser
 * role=user (el mensaje que se está respondiendo).
 *
 * Devuelve ['texto' => string, 'lead' => null|['tipo','estado','nombre','resumen']].
 * tipo es 'despido' o 'asesoria_paga'.
 */
function ia_responder_whatsapp(PDO $pdo, array $mensajes): array
{
    $credentialsFile = __DIR__ . '/anthropic_credentials.php';
    if (!file_exists($credentialsFile)) {
        error_log('Falta api/anthropic_credentials.php');
        return ['texto' => 'Gracias por tu mensaje, en un momento te contesto.', 'lead' => null];
    }
    require_once $credentialsFile;
    require_once __DIR__ . '/liquidacion_calculadora.php';

    $data = ia_llamar_claude($mensajes);
    if ($data === null) {
        return ['texto' => 'Gracias por tu mensaje, en un momento te contesto.', 'lead' => null];
    }

    [$texto, $lead, $bloques] = ia_extraer_respuesta($data);

    $toolUseCalculadora = null;
    foreach ($bloques as $bloque) {
        if (($bloque['type'] ?? '') === 'tool_use' && ($bloque['name'] ?? '') === 'calcular_estimado_liquidacion') {
            $toolUseCalculadora = $bloque;
            break;
        }
    }

    if ($toolUseCalculadora !== null) {
        $in = $toolUseCalculadora['input'] ?? [];
        $calc = calcular_estimado_liquidacion(
            $pdo,
            (string)($in['fecha_ingreso'] ?? ''),
            (string)($in['fecha_baja'] ?? ''),
            (float)($in['salario_diario'] ?? 0),
            (string)($in['tipo'] ?? 'injustificado'),
            (float)($in['dias_vacaciones_anteriores'] ?? 0),
            (float)($in['dias_salarios_devengados'] ?? 0)
        );

        $toolResults = [];
        foreach ($bloques as $bloque) {
            if (($bloque['type'] ?? '') !== 'tool_use') continue;
            if ($bloque['name'] === 'calcular_estimado_liquidacion') {
                $contenido = $calc !== null
                    ? json_encode($calc, JSON_UNESCAPED_UNICODE)
                    : json_encode(['error' => 'Datos insuficientes o inválidos para calcular.'], JSON_UNESCAPED_UNICODE);
            } else {
                $contenido = json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            }
            $toolResults[] = [
                'type' => 'tool_result',
                'tool_use_id' => $bloque['id'],
                'content' => $contenido,
            ];
        }

        $mensajesSeguimiento = $mensajes;
        $mensajesSeguimiento[] = ['role' => 'assistant', 'content' => $bloques];
        $mensajesSeguimiento[] = ['role' => 'user', 'content' => $toolResults];

        $data2 = ia_llamar_claude($mensajesSeguimiento);
        if ($data2 !== null) {
            [$texto2, ,] = ia_extraer_respuesta($data2);
            if (trim($texto2) !== '') {
                $texto = $texto2;
            }
        }
    }

    if (trim($texto) === '') {
        $texto = 'Gracias por tu mensaje, en un momento te contesto.';
    }

    return ['texto' => trim($texto), 'lead' => $lead];
}

/**
 * Extrae el texto, el lead (si se llamó alguna herramienta de registro) y
 * los bloques de contenido crudos de una respuesta de la API de Claude.
 * Devuelve [texto, lead, bloques].
 */
function ia_extraer_respuesta(array $data): array
{
    $bloques = $data['content'] ?? [];

    $texto = '';
    $lead = null;
    foreach ($bloques as $bloque) {
        if (($bloque['type'] ?? '') === 'text') {
            $texto .= $bloque['text'];
        } elseif (($bloque['type'] ?? '') === 'tool_use' && in_array($bloque['name'] ?? '', ['registrar_lead_despido', 'registrar_interes_asesoria_paga'], true)) {
            $input = $bloque['input'] ?? [];
            $nuevoLead = [
                'tipo' => $bloque['name'] === 'registrar_lead_despido' ? 'despido' : 'asesoria_paga',
                'estado' => (string)($input['estado'] ?? ''),
                'nombre' => (string)($input['nombre'] ?? ''),
                'resumen' => (string)($input['resumen'] ?? ''),
            ];
            // Si Claude llama ambas herramientas en el mismo turno, el lead
            // de despido (más valioso: litigio) manda sobre el de asesoría paga.
            if ($lead === null || $nuevoLead['tipo'] === 'despido') {
                $lead = $nuevoLead;
            }
        }
    }

    return [$texto, $lead, $bloques];
}
