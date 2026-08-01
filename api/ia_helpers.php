<?php
declare(strict_types=1);

// Respuestas automáticas de WhatsApp con Claude (Anthropic). Un solo
// llamado hace dos cosas a la vez: redacta la respuesta al usuario Y (si
// aplica) clasifica el caso como lead de despido en CDMX/Edomex, usando
// "tool use" — Claude decide llamar a la herramienta registrar_lead_despido
// solo cuando el caso califica, sin que tengamos que pedir una segunda
// llamada aparte para clasificar.

const IA_MODEL = 'claude-sonnet-5';

const IA_SYSTEM_PROMPT = <<<TXT
Eres el asistente de WhatsApp de Expertos Laborales Abogados, un despacho
mexicano de derecho laboral. Respondes las dudas de derecho laboral
mexicano que la gente manda por WhatsApp, con el mismo tono cercano y
claro que el abogado usa en sus lives de TikTok: directo, en español de
México, sin tecnicismos innecesarios, y CORTO (2-5 líneas, estilo WhatsApp,
nunca un ensayo).

Reglas:
- Da orientación general útil (qué dice la ley, qué puede hacer la
  persona), pero deja claro que cada caso hay que revisarlo a detalle —
  nunca prometas un resultado ni un monto exacto.
- No des asesoría de otras ramas del derecho (penal, familiar, civil,
  etc.) — para eso, indica amablemente que no es tu especialidad.
- Si la persona relata que la despidieron (o la están despidiendo, sea
  justificado o no) Y menciona que trabaja o vive en Ciudad de México o
  Estado de México, además de responder normalmente DEBES llamar la
  herramienta registrar_lead_despido con los datos que tengas. Solo
  regístralo si de verdad hay un despido — dudas generales sobre
  liquidación, aguinaldo, vacaciones, etc. sin que haya despido no
  cuentan. Despidos fuera de esos dos estados tampoco (ahí solo orienta,
  sin registrar).
- Cuando registres el lead, tu respuesta de texto debe sonar natural y
  SIN mencionar que "se está registrando" nada — solo responde la duda de
  forma empática; el sistema añade automáticamente el aviso de contacto.
TXT;

const IA_TOOLS = [[
    'name' => 'registrar_lead_despido',
    'description' => 'Registra un caso de despido de una persona que trabaja o vive en Ciudad de México o Estado de México, para que un abogado del despacho le dé seguimiento. Solo se usa cuando ambas condiciones se cumplen.',
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
]];

/**
 * $mensajes: lista ordenada (más antiguo primero) de
 * ['role' => 'user'|'assistant', 'content' => string]. El último debe ser
 * role=user (el mensaje que se está respondiendo).
 *
 * Devuelve ['texto' => string, 'lead' => null|['estado','nombre','resumen']].
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
        error_log('Anthropic API error: status=' . $status . ' curl=' . $curlError . ' body=' . (string)$raw);
        return ['texto' => 'Gracias por tu mensaje, en un momento te contesto.', 'lead' => null];
    }

    $data = json_decode($raw, true);
    $bloques = $data['content'] ?? [];

    $texto = '';
    $lead = null;
    foreach ($bloques as $bloque) {
        if (($bloque['type'] ?? '') === 'text') {
            $texto .= $bloque['text'];
        } elseif (($bloque['type'] ?? '') === 'tool_use' && ($bloque['name'] ?? '') === 'registrar_lead_despido') {
            $input = $bloque['input'] ?? [];
            $lead = [
                'estado' => (string)($input['estado'] ?? ''),
                'nombre' => (string)($input['nombre'] ?? ''),
                'resumen' => (string)($input['resumen'] ?? ''),
            ];
        }
    }

    if (trim($texto) === '') {
        $texto = 'Gracias por tu mensaje, en un momento te contesto.';
    }

    return ['texto' => trim($texto), 'lead' => $lead];
}
