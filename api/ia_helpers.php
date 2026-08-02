<?php
declare(strict_types=1);

// Respuestas automáticas de WhatsApp con Claude (Anthropic). Un solo
// llamado hace todo a la vez: redacta la respuesta al usuario Y (si aplica)
// clasifica dos tipos de lead usando "tool use" — Claude decide llamar a
// una herramienta solo cuando corresponde, sin pedir una segunda llamada
// aparte para clasificar:
//   - registrar_lead_despido: despido en CDMX/Edomex → futuro cliente de
//     litigio, el despacho lo contacta gratis para evaluar el caso.
//   - registrar_interes_asesoria_paga: cualquier persona, de cualquier
//     estado, que acepta o pregunta por la asesoría personalizada de pago.

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

Lead 1 — despido en CDMX/Edomex (litigio):
- Si la persona relata que la despidieron (o la están despidiendo, sea
  justificado o no) Y menciona que trabaja o vive en Ciudad de México o
  Estado de México, responde su duda normalmente y, de forma natural,
  cálida y persuasiva, pregúntale DIRECTAMENTE si quiere que un abogado
  del despacho lo contacte para revisar su caso (gratis) — por ejemplo:
  "¿Quieres que un abogado te contacte hoy mismo para revisar tu caso?".
  No llames ninguna herramienta todavía en este mensaje.
- Si en un mensaje siguiente la persona responde que sí (o equivalente:
  "va", "sí porfa", "claro", etc.), ahí SÍ, además de responder, DEBES
  llamar la herramienta registrar_lead_despido con los datos que tengas.
- Si responde que no, o cambia de tema sin contestar la pregunta directa,
  NO llames la herramienta — sigue la conversación normal, contestando
  sus dudas como siempre, sin insistir de nuevo con la misma pregunta.

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
];

/**
 * $mensajes: lista ordenada (más antiguo primero) de
 * ['role' => 'user'|'assistant', 'content' => string]. El último debe ser
 * role=user (el mensaje que se está respondiendo).
 *
 * Devuelve ['texto' => string, 'lead' => null|['tipo','estado','nombre','resumen']].
 * tipo es 'despido' o 'asesoria_paga'.
 */
function ia_responder_whatsapp(array $mensajes): array
{
    $credentialsFile = __DIR__ . '/anthropic_credentials.php';
    if (!file_exists($credentialsFile)) {
        error_log('Falta api/anthropic_credentials.php');
        return ['texto' => 'Gracias por tu mensaje, en un momento te contesto.', 'lead' => null];
    }
    require_once $credentialsFile;

    $payload = [
        'model' => IA_MODEL,
        'max_tokens' => 700,
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
        return ['texto' => 'Gracias por tu mensaje, en un momento te contesto.', 'lead' => null];
    }

    $data = json_decode($raw, true);
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

    if (trim($texto) === '') {
        $texto = 'Gracias por tu mensaje, en un momento te contesto.';
    }

    return ['texto' => trim($texto), 'lead' => $lead];
}
