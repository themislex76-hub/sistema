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

// Respuesta de emergencia cuando la IA no pudo contestar (sin credenciales,
// sin saldo, la API caída, etc.) — una sola constante para poder detectar
// después, desde "Conversaciones (WhatsApp)", qué conversaciones se
// quedaron sin respuesta real y reintentarlas. Ver conversaciones_reintentar.php.
const IA_FALLBACK_TEXTO = 'Gracias por tu mensaje, en un momento te contesto.';

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
- Cursos en línea que vende el despacho (si preguntan por cursos, cómo
  prepararse, dónde aprender más, etc.):
  · *Nuevo Procedimiento Laboral Mexicano* — $499 MXN, pago único. 9
    módulos (desde la conciliación prejudicial hasta la ejecución de
    sentencia), documentos y formatos reales del juicio laboral,
    jurisprudencias vigentes de la SCJN aplicadas a casos concretos,
    evaluación final de 15 preguntas con retroalimentación, acceso de
    por vida. Es un curso interactivo de lectura (no video, se consulta
    en segundos, sin horarios).
  · *El Juicio de Amparo en Materia del Trabajo* — $499 MXN, pago único.
    18 módulos (qué es el amparo, suspensión, recursos, cumplimiento), 5
    escritos modelo reales (amparos adhesivos, alegatos, demanda de
    amparo directo) listos para usar como plantilla, jurisprudencias
    vigentes de la SCJN, autoevaluación en cada módulo, acceso de por
    vida. También en formato de lectura interactiva.
  · *Actas Administrativas Laborales* — $299 MXN, pago único. 11 módulos
    (desde qué es un acta hasta la rescisión laboral), 6 formatos modelo
    listos para usar (citatorios, actas, sanciones y rescisión),
    referencia rápida con plazos/razonamientos/checklist, 5 casos
    prácticos resueltos (desde la perspectiva del patrón y del
    trabajador), evaluación final de 12 preguntas con retroalimentación
    inmediata, acceso de por vida. También en formato de lectura
    interactiva.
  · Para inscribirse: mándalos directo a https://www.expertoslaborales.com/cursos,
    ahí seleccionan "Inscribirse" y el pago se procesa automático con
    Mercado Pago; al pagar les llega un correo con el link de acceso. Tú
    NO puedes procesar el pago ni mandar un link de pago — siempre manda
    a la persona a esa página.

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
  2. El domicilio de la fuente de trabajo (la empresa/patrón donde
     trabajaba, no donde vive el trabajador) está en Ciudad de México o
     Estado de México — eso es lo que determina la jurisdicción, así que
     pregunta específicamente dónde está ubicada la empresa, no dónde
     vive la persona.
  3. Sobre el trámite de conciliación, aplica una de estas dos
     situaciones:
     a) NO ha iniciado ningún trámite en el Centro de Conciliación
        todavía, O ya lo inició pero está en proceso (TODAVÍA NO tiene
        la Constancia de No Conciliación) — este caso SÍ califica, sea
        CDMX o Edomex.
     b) YA tiene la Constancia de No Conciliación (el documento que se
        entrega cuando la conciliación terminó sin acuerdo) — este caso
        SOLO califica si es de Estado de México. Si ya tiene esa
        constancia y es de Ciudad de México, NO califica.
- Si se cumplen las condiciones (revisa el punto 3 con cuidado), responde
  su duda normalmente y, de forma natural, cálida y persuasiva,
  pregúntale DIRECTAMENTE si quiere que un abogado del despacho lo
  contacte para revisar su caso (gratis) — por ejemplo: "¿Quieres que un
  abogado te contacte para revisar tu caso, sin costo?" (NO prometas un
  horario ni "hoy mismo" — el contacto depende de la disponibilidad de
  agenda del abogado, que tú no conoces). Además, si aplica, menciona la
  urgencia real: el trabajador tiene solo 2 meses desde el despido para
  demandar (Art. 518 LFT) — después de eso prescribe su derecho a
  reclamar. Es un dato legal real, no lo uses si no aplica al caso. No
  llames ninguna herramienta todavía en este mensaje.
- Si en un mensaje siguiente la persona responde que sí (o equivalente:
  "va", "sí porfa", "claro", etc.), ahí SÍ, además de responder, DEBES
  llamar la herramienta registrar_lead_despido con los datos que tengas
  — en el resumen, menciona explícitamente si firmó renuncia, si ya
  inició conciliación, y si ya tiene la Constancia de No Conciliación,
  para que el abogado lo confirme de una vez.
- Si responde que no, o cambia de tema sin contestar la pregunta directa,
  NO llames la herramienta — sigue la conversación normal, contestando
  sus dudas como siempre, sin insistir de nuevo con la misma pregunta.
- Si NO se cumplen las condiciones (firmó renuncia, o la fuente de
  trabajo no está en CDMX/Edomex, o ya tiene la Constancia de No
  Conciliación y es de CDMX), NO ofrezcas el contacto gratis con el
  abogado ni llames la herramienta —
  sigue ayudando con orientación general, y puedes ofrecer la asesoría de
  pago (Lead 2) si aplica.

Lead 2 — asesoría personalizada de pago (cualquier estado, cualquier tema
laboral, aunque ya se haya registrado como lead 1):
- El despacho ofrece una asesoría personalizada por $299 MXN, vía
  llamada telefónica (NO videollamada) con duración de 1 hora, donde el
  abogado revisa el caso a fondo. Al ofrecerla, deja claro que es
  telefónica y de 1 hora (por ejemplo: "es una llamada telefónica de 1
  hora donde el abogado revisa tu caso a fondo"). Después de dar tu
  respuesta a la duda de la persona, si no se la has ofrecido ya en esta
  conversación, ofrécela de forma breve y natural, y pregúntale
  DIRECTAMENTE si le interesa agendarla — por ejemplo: "¿Te gustaría que
  te agendemos la asesoría telefónica de 1 hora?" (NO prometas un horario
  específico ni "para hoy" — depende de la disponibilidad de agenda del
  abogado, que tú no conoces). SI ya calculaste un estimado con la
  herramienta calcular_estimado_liquidacion en esta conversación, ancla
  el precio contra ese monto — por ejemplo: "Por $299 revisamos a fondo
  cómo recuperar los ~$[monto] que te corresponden — es una inversión
  mínima contra lo que está en juego." Sé directo pero no insistente (no
  la repitas en cada mensaje ni la fuerces si ya dijo que no le
  interesa). No llames ninguna herramienta todavía en este mensaje.
- Si en un mensaje siguiente la persona responde que sí (o equivalente:
  pregunta cómo pagar/agendar, confirma interés, etc.), ahí SÍ, en el
  mismo mensaje llama DOS herramientas: primero registrar_interes_asesoria_paga
  con los datos que tengas, y también ofrecer_horarios_asesoria para traer
  horarios reales de la agenda del despacho. Con el resultado, ofrécele
  los horarios de forma clara y numerada (ejemplo: "Tengo estos horarios
  disponibles:\n1. Lunes 10 de agosto, 9:00 am\n2. Martes 11 de agosto,
  4:00 pm\n¿Cuál te acomoda?"). Si la herramienta te dice que no hay
  horarios disponibles en este momento, dile a la persona que un abogado
  la va a contactar directo para coordinar — nunca inventes un horario ni
  des un link de pago sin haber usado esta herramienta.
- Cuando la persona elija uno de los horarios que le ofreciste (por
  número o describiéndolo), llama confirmar_horario_asesoria con la fecha
  y hora EXACTAS de esa opción (tal como venían en el resultado de
  ofrecer_horarios_asesoria, nunca las inventes ni las redondees). Si te
  regresa un link de pago (ok=true), mándaselo junto con: que tiene
  [vigencia_minutos] minutos para pagar antes de que se libere ese
  horario, que el link solo acepta tarjeta de crédito o débito, y que la
  asesoría es una llamada telefónica de 1 hora — el abogado le llama a
  este mismo número de WhatsApp a la hora acordada. Si te regresa ok=false
  con horarios alternativos, discúlpate brevemente (ese horario ya se
  ocupó) y ofrécele esos horarios alternativos de la misma forma clara y
  numerada. Si te regresa ok=false sin horarios alternativos, dile que un
  abogado del despacho le va a contactar directo — no le des ningún link
  ni horario tú mismo.
- Si responde que no, o cambia de tema sin contestar la pregunta directa,
  NO llames ninguna herramienta — sigue la conversación normal,
  contestando sus dudas como siempre, sin insistir de nuevo con la misma
  pregunta.
TXT;

const IA_TOOLS = [
    [
        'name' => 'registrar_lead_despido',
        'description' => 'Registra un caso de despido donde la fuente de trabajo (empresa/patrón) está en Ciudad de México o Estado de México, para que un abogado del despacho le dé seguimiento como posible cliente de litigio. Solo se usa cuando se cumplen todas las condiciones de calificación.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'estado' => [
                    'type' => 'string',
                    'enum' => ['Ciudad de México', 'Estado de México'],
                    'description' => 'Estado donde está ubicada la fuente de trabajo (la empresa/patrón), no donde vive el trabajador.',
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
        'name' => 'ofrecer_horarios_asesoria',
        'description' => 'Consulta horarios reales y disponibles ahora mismo para agendar la asesoría telefónica de pago, cruzando la agenda real de los abogados del despacho. Llama esta herramienta cuando la persona confirme que quiere agendar la asesoría (normalmente junto con registrar_interes_asesoria_paga, en el mismo mensaje), o si pregunta directamente qué días/horas hay disponibles. Te regresa una lista de horarios concretos y reales para que se los ofrezcas — nunca inventes ni prometas un horario sin usar esta herramienta primero.',
        'input_schema' => [
            'type' => 'object',
        ],
    ],
    [
        'name' => 'confirmar_horario_asesoria',
        'description' => 'Aparta uno de los horarios que ya le ofreciste a la persona con ofrecer_horarios_asesoria, y genera el link de pago único de esa cita. Llama esta herramienta SOLO cuando la persona ya eligió con claridad uno de los horarios exactos que le ofreciste (por número o describiéndolo) — nunca inventes ni confirmes un horario que no venía en esa lista, y nunca generes un link de pago sin que la persona haya elegido un horario primero.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'fecha' => [
                    'type' => 'string',
                    'description' => 'Fecha exacta (YYYY-MM-DD) del horario elegido, tal como venía en el campo "fecha" de esa opción devuelta por ofrecer_horarios_asesoria.',
                ],
                'hora_inicio' => [
                    'type' => 'string',
                    'description' => 'Hora de inicio exacta (HH:MM, 24 horas) del horario elegido, tal como venía en el campo "hora_inicio" de esa opción devuelta por ofrecer_horarios_asesoria.',
                ],
                'nombre' => [
                    'type' => 'string',
                    'description' => 'Nombre de la persona si lo sabes, o cadena vacía si no.',
                ],
            ],
            'required' => ['fecha', 'hora_inicio'],
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
    // El system prompt y las tools son fijos — se mandan idénticos en cada
    // llamada. Poner cache_control en el bloque de system cachea tools+system
    // juntos (tools se renderiza antes que system en la solicitud a la API),
    // así que solo hace falta un breakpoint aquí. La primera llamada de cada
    // ventana de caché (5 min) paga el precio normal; las siguientes pagan
    // ~10% de esa parte del prompt en vez de 100%.
    $payload = [
        'model' => IA_MODEL,
        'max_tokens' => 1500,
        'system' => [
            ['type' => 'text', 'text' => IA_SYSTEM_PROMPT, 'cache_control' => ['type' => 'ephemeral']],
        ],
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
 * $telefono: número de WhatsApp de la conversación — lo necesitan las
 * herramientas de agendado para saber a nombre de quién apartar la cita.
 *
 * Devuelve ['texto' => string, 'lead' => null|['tipo','estado','nombre','resumen']].
 * tipo es 'despido' o 'asesoria_paga'.
 */
function ia_responder_whatsapp(PDO $pdo, array $mensajes, string $telefono): array
{
    $credentialsFile = __DIR__ . '/anthropic_credentials.php';
    if (!file_exists($credentialsFile)) {
        error_log('Falta api/anthropic_credentials.php');
        return ['texto' => IA_FALLBACK_TEXTO, 'lead' => null];
    }
    require_once $credentialsFile;
    require_once __DIR__ . '/liquidacion_calculadora.php';
    require_once __DIR__ . '/citas_helpers.php';
    require_once __DIR__ . '/mercadopago_helpers.php';

    $data = ia_llamar_claude($mensajes);
    if ($data === null) {
        return ['texto' => IA_FALLBACK_TEXTO, 'lead' => null];
    }

    [$texto, $lead, $bloques] = ia_extraer_respuesta($data);

    // Herramientas cuyo resultado hay que calcular de verdad en PHP (no
    // dejar que la IA "invente" el número o el horario) y devolverle a
    // Claude para que redacte la respuesta final ya con el dato real.
    $herramientasConSeguimiento = ['calcular_estimado_liquidacion', 'ofrecer_horarios_asesoria', 'confirmar_horario_asesoria'];
    $necesitaSeguimiento = false;
    foreach ($bloques as $bloque) {
        if (($bloque['type'] ?? '') === 'tool_use' && in_array($bloque['name'] ?? '', $herramientasConSeguimiento, true)) {
            $necesitaSeguimiento = true;
            break;
        }
    }

    if ($necesitaSeguimiento) {
        $toolResults = [];
        foreach ($bloques as $bloque) {
            if (($bloque['type'] ?? '') !== 'tool_use') continue;
            $in = $bloque['input'] ?? [];
            if ($bloque['name'] === 'calcular_estimado_liquidacion') {
                $calc = calcular_estimado_liquidacion(
                    $pdo,
                    (string)($in['fecha_ingreso'] ?? ''),
                    (string)($in['fecha_baja'] ?? ''),
                    (float)($in['salario_diario'] ?? 0),
                    (string)($in['tipo'] ?? 'injustificado'),
                    (float)($in['dias_vacaciones_anteriores'] ?? 0),
                    (float)($in['dias_salarios_devengados'] ?? 0)
                );
                $contenido = $calc !== null
                    ? json_encode($calc, JSON_UNESCAPED_UNICODE)
                    : json_encode(['error' => 'Datos insuficientes o inválidos para calcular.'], JSON_UNESCAPED_UNICODE);
            } elseif ($bloque['name'] === 'ofrecer_horarios_asesoria') {
                $contenido = ia_resultado_ofrecer_horarios($pdo, $telefono);
            } elseif ($bloque['name'] === 'confirmar_horario_asesoria') {
                $contenido = ia_resultado_confirmar_horario($pdo, $telefono, $in);
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
        $texto = IA_FALLBACK_TEXTO;
    }

    return ['texto' => trim($texto), 'lead' => $lead];
}

// URL pública del webhook de Mercado Pago — a donde Mercado Pago avisa
// cuando se confirma un pago. Debe coincidir con dónde vive el sistema.
const MERCADOPAGO_WEBHOOK_URL = 'https://sistema.expertoslaborales.com/sistema/api/mercadopago_webhook.php';

/**
 * Resultado (como JSON) de la herramienta ofrecer_horarios_asesoria: la
 * lista de horarios reales disponibles ahora mismo. Si no hay ninguno (por
 * ejemplo, todavía ningún abogado configuró su disponibilidad), pausa el
 * bot para esa conversación — a partir de ahí un humano tiene que
 * coordinar directo, igual que antes de tener este agendado automático.
 */
function ia_resultado_ofrecer_horarios(PDO $pdo, string $telefono): string
{
    $horarios = citas_calcular_horarios_disponibles($pdo);
    if (!$horarios) {
        ia_pausar_prospecto($pdo, $telefono);
        return json_encode([
            'horarios' => [],
            'nota' => 'No hay horarios disponibles en este momento. Dile a la persona que un abogado del despacho la va a contactar directo para coordinar — no le des ningún link de pago ni le prometas un horario.',
        ], JSON_UNESCAPED_UNICODE);
    }
    return json_encode(['horarios' => $horarios], JSON_UNESCAPED_UNICODE);
}

/**
 * Resultado (como JSON) de la herramienta confirmar_horario_asesoria:
 * aparta el horario elegido y genera el link de pago (solo tarjeta) de
 * Mercado Pago. Si el horario ya no está libre, o si algo falla al
 * generar el link, avisa y — cuando ya no hay nada más que el bot pueda
 * hacer — pausa la conversación para que un humano la retome.
 */
function ia_resultado_confirmar_horario(PDO $pdo, string $telefono, array $in): string
{
    $fecha = (string)($in['fecha'] ?? '');
    $horaInicio = (string)($in['hora_inicio'] ?? '');
    $nombre = trim((string)($in['nombre'] ?? '')) ?: null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !preg_match('/^\d{2}:\d{2}$/', $horaInicio)) {
        return json_encode(['ok' => false, 'motivo' => 'Formato de fecha/hora inválido — vuelve a ofrecer los horarios disponibles con ofrecer_horarios_asesoria.'], JSON_UNESCAPED_UNICODE);
    }

    $citaId = citas_crear_pendiente($pdo, $telefono, $fecha, $horaInicio, $nombre);
    if ($citaId === null) {
        return json_encode([
            'ok' => false,
            'motivo' => 'Ese horario ya no está disponible.',
            'horarios_alternativos' => citas_calcular_horarios_disponibles($pdo),
        ], JSON_UNESCAPED_UNICODE);
    }

    $pref = mercadopago_crear_preferencia_asesoria($citaId, $telefono, MERCADOPAGO_WEBHOOK_URL);
    if ($pref === null) {
        // No dejamos la cita "atorada" ocupando el horario si no se pudo
        // generar el link de pago.
        $stmt = $pdo->prepare("UPDATE citas_asesoria SET estado = 'cancelada' WHERE id = :id");
        $stmt->execute([':id' => $citaId]);
        ia_pausar_prospecto($pdo, $telefono);
        return json_encode([
            'ok' => false,
            'motivo' => 'Hubo un problema técnico generando el link de pago. Dile a la persona que un abogado del despacho le va a mandar el link directo en breve — no le des ningún link tú.',
        ], JSON_UNESCAPED_UNICODE);
    }

    $stmt = $pdo->prepare("UPDATE citas_asesoria SET mp_preference_id = :pref WHERE id = :id");
    $stmt->execute([':pref' => $pref['id'], ':id' => $citaId]);

    return json_encode([
        'ok' => true,
        'link_pago' => $pref['init_point'],
        'horario' => citas_formatear_fecha_hora($fecha, $horaInicio),
        'vigencia_minutos' => CITAS_HOLD_MINUTOS,
        'monto' => MERCADOPAGO_MONTO_ASESORIA,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Pausa el bot para un teléfono cuando ya no hay nada más que pueda hacer
 * de forma automática (sin horarios, o falló el link de pago) — mismo
 * criterio de "a partir de aquí un humano lo atiende" que un lead de
 * despido. No falla si el teléfono todavía no tiene fila en prospectos
 * (el UPDATE simplemente no afecta nada en ese caso).
 */
function ia_pausar_prospecto(PDO $pdo, string $telefono): void
{
    $stmt = $pdo->prepare('UPDATE prospectos SET pausado_bot = 1 WHERE telefono = :t');
    $stmt->execute([':t' => $telefono]);
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

/**
 * Genera (o regenera) un resumen breve, de uso interno, de una conversación
 * completa de WhatsApp — para que el abogado no tenga que leer todo el
 * hilo para saber de qué se trató y cómo respondió el bot. Llamada
 * completamente aparte del flujo de respuesta en vivo (no usa IA_SYSTEM_PROMPT
 * ni IA_TOOLS): no toca ni arriesga la lógica del bot en producción.
 * $historial: lista ordenada (más antiguo primero) de ['direccion' => 'entrante'|'saliente', 'texto' => string].
 * Devuelve el resumen en texto plano, o null si falló la llamada a la API.
 */
function ia_generar_resumen_conversacion(array $historial): ?string
{
    $credentialsFile = __DIR__ . '/anthropic_credentials.php';
    if (!file_exists($credentialsFile)) {
        error_log('Falta api/anthropic_credentials.php');
        return null;
    }
    require_once $credentialsFile;

    $transcript = '';
    foreach ($historial as $h) {
        $quien = ($h['direccion'] ?? '') === 'entrante' ? 'Cliente' : 'Bot/Despacho';
        $transcript .= $quien . ': ' . ($h['texto'] ?? '') . "\n";
    }
    if (trim($transcript) === '') return null;

    $payload = [
        'model' => IA_MODEL,
        'max_tokens' => 400,
        'system' => 'Eres un asistente interno del despacho Expertos Laborales. Te doy la transcripción '
            . 'completa de una conversación de WhatsApp entre un posible cliente y el bot de asesoría '
            . 'laboral del despacho. Escribe un resumen breve (4-6 líneas, en español, sin encabezados ni '
            . 'viñetas) para que un abogado entienda rápido: de qué se trata el caso o duda, qué le '
            . 'contestó el bot, si el bot calificó bien la situación o si parece que se equivocó o dio '
            . 'información dudosa, y en qué quedó la conversación (pendiente, resuelta, el cliente dejó '
            . 'de contestar, etc.). Sé directo y crítico si notas algo que el bot debería mejorar.',
        'messages' => [['role' => 'user', 'content' => $transcript]],
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
            . " | [resumen] status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
        return null;
    }

    $data = json_decode($raw, true);
    $texto = '';
    foreach (($data['content'] ?? []) as $bloque) {
        if (($bloque['type'] ?? '') === 'text') $texto .= $bloque['text'];
    }
    $texto = trim($texto);
    return $texto !== '' ? $texto : null;
}
