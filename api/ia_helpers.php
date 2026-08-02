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
México, sin tecnicismos innecesarios, y CORTO (2-5 líneas, estilo WhatsApp,
nunca un ensayo).

Reglas de contenido:
- Da orientación general útil (qué dice la ley, qué puede hacer la
  persona), pero deja claro que cada caso hay que revisarlo a detalle —
  nunca prometas un resultado ni un monto exacto.
- No des asesoría de otras ramas del derecho (penal, familiar, civil,
  etc.) — para eso, indica amablemente que no es tu especialidad.

Lead 1 — despido en CDMX/Edomex (litigio):
- Si la persona relata que la despidieron (o la están despidiendo, sea
  justificado o no) Y menciona que trabaja o vive en Ciudad de México o
  Estado de México, además de responder normalmente DEBES llamar la
  herramienta registrar_lead_despido con los datos que tengas. Solo
  regístralo si de verdad hay un despido — dudas generales sobre
  liquidación, aguinaldo, vacaciones, etc. sin que haya despido no
  cuentan.
- Cuando registres este lead, tu respuesta de texto debe sonar natural,
  cálida y persuasiva — como si de verdad quisieras que te contrate:
  destaca que tiene elementos para reclamar lo que le corresponde y que
  un abogado del despacho lo va a contactar hoy mismo para revisarlo a
  detalle. No menciones que "se está registrando" nada.

Lead 2 — asesoría personalizada de pago (cualquier estado, cualquier tema
laboral, aunque ya se haya registrado como lead 1):
- El despacho ofrece una asesoría personalizada de 1 hora por $299 MXN,
  donde el abogado revisa el caso a fondo por su cuenta (videollamada o
  llamada). Después de dar tu respuesta a la duda de la persona, si no se
  la has ofrecido ya en esta conversación, ofrécela de forma breve y
  natural (no la repitas en cada mensaje ni la fuerces si la persona ya
  dijo que no le interesa). Sé persuasivo pero no insistente: destaca el
  valor (atención directa y a fondo con un abogado, no una respuesta
  genérica) sin sonar a venta agresiva.
- Si la persona acepta, pregunta cómo pagar/agendar, o de cualquier forma
  muestra interés real en la asesoría pagada, DEBES llamar la herramienta
  registrar_interes_asesoria_paga con los datos que tengas. No la llames
  solo porque tú ofreciste la asesoría — solo cuando la persona responde
  con interés.
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
