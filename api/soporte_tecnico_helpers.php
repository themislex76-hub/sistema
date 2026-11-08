<?php
declare(strict_types=1);

// Soporte técnico del sistema Control de Expedientes (el multidespacho),
// atendido por el MISMO número de WhatsApp del bot de asesoría laboral --
// se decidió no abrir un número aparte por el trabajo extra que implica.
// Para no mezclar dominios (ni el prompt ni las herramientas de litigio
// laboral tienen nada que ver con "cómo uso el sistema"), esto vive
// completamente separado de ia_helpers.php: su propio system prompt, su
// propio modelo (Haiku -- es solo responder FAQs del manual, no requiere
// razonamiento caro) y su propia llamada a la API. Solo comparte
// ia_registrar_prospecto_atorado() para escalar a un humano, que ya es
// genérica.
require_once __DIR__ . '/ia_helpers.php';

// Detección determinística de que el mensaje es sobre el SISTEMA (control
// de expedientes), no sobre un caso laboral -- igual que el detector de
// reclamos, nunca se le pregunta a la IA "¿esto es soporte técnico?"
// porque eso puede fallar en silencio; se detecta con regex ANTES de
// llamar a cualquier IA. Un cliente laboral (trabajador despedido) nunca
// usa este vocabulario -- no tiene usuario ni contraseña de nada, no
// "agrega expedientes", no le preocupa Google Calendar del despacho.
function soporte_parece_pregunta_tecnica(string $texto): bool
{
    return preg_match('/control de expedientes|controldeexpedientes/iu', $texto) === 1
        || preg_match('/(restablecer|recuperar|olvid[eé]|reset).{0,20}contrase[ñn]a/iu', $texto) === 1
        || preg_match('/no (me deja |puedo )?entrar al sistema|no (puedo|me deja) (iniciar sesi[oó]n|entrar)/iu', $texto) === 1
        || preg_match('/(agregar|dar de alta|subir) (un |el )?(expediente|socio|abogado|usuario)/iu', $texto) === 1
        || preg_match('/generar (el )?escrito|generar la demanda en word|plantilla de demanda|formato de demanda/iu', $texto) === 1
        || preg_match('/descargar (el )?respaldo|respaldo\.json|hacer (un )?backup/iu', $texto) === 1
        || preg_match('/mi suscripci[oó]n|mi plan de control de expedientes|cancelar mi cuenta del sistema|facturaci[oó]n del sistema/iu', $texto) === 1
        || preg_match('/portal (del|de) cliente/iu', $texto) === 1
        || preg_match('/google calendar/iu', $texto) === 1
        || preg_match('/(agregar|configurar|d[oó]nde pongo|c[oó]mo (pongo|agrego)).{0,20}d[ií]as inh[aá]biles/iu', $texto) === 1
        || preg_match('/(marcar|guardar|checklist de) etapas del juicio|c[aá]lculo de liquidaci[oó]n del sistema|tasa de [eé]xito del despacho/iu', $texto) === 1;
}

// Base de conocimiento: versión condensada del Manual de uso real de
// Control de Expedientes (la misma guía que ve el usuario dentro del
// sistema, en "Manual de uso") -- nunca se inventan pasos ni botones que
// no estén aquí.
const SOPORTE_SYSTEM_PROMPT = <<<'TXT'
Eres el soporte técnico de "Control de Expedientes", un sistema web para
despachos de abogados laboralistas (gestión de expedientes, prescripción,
convenios, agenda, escritos). Te escriben administradores o abogados de
despachos YA DADOS DE ALTA que tienen dudas de CÓMO USAR el sistema --
nunca son clientes finales con un caso laboral, así que nunca des
asesoría legal aquí ni hables de despidos, liquidaciones de un trabajador,
etc. -- eso es un bot completamente distinto.

REGLA DURA: contesta SOLO con lo que dice esta guía. Nunca inventes un
botón, una pantalla o un paso que no esté aquí -- si la duda no está
cubierta, o no estás seguro, usa la herramienta escalar_soporte_a_humano
en vez de adivinar.

=== GUÍA (resumen del Manual de uso real del sistema) ===

ENTRAR AL SISTEMA: se busca el nombre en una lista y se le da clic. Si
dice "sin contraseña aún", el sistema pide crear una contraseña ahí mismo.
Si dice "protegida", pide la contraseña ya creada.
- Si CUALQUIER usuario (administrador o abogado) olvidó su contraseña: en
  la pantalla de acceso hay un botón "¿Olvidaste tu contraseña?" -- escribe
  su correo ahí y le llega un enlace (válido 30 minutos) para crear una
  contraseña nueva él mismo, sin depender de nadie más. Dale ese paso
  directo, no hace falta escalar esto.
- Si además quiere que el Administrador de su despacho se la resetee
  directamente (sin esperar el correo): puede hacerlo desde "Equipo" →
  "Restablecer contraseña" en su nombre -- pero solo funciona si quien
  olvidó la contraseña NO es el único Administrador del despacho.

TABLERO (pantalla inicial): botón "Generar reporte ejecutivo (PDF)"
arriba a la derecha; 4 tarjetas (asuntos activos, prescripción crítica
≤10 días, próximos a vencer 11-30 días, total en convenios); casos
atorados y sin movimiento en 30+ días (si los hay); prioridad de
prescripción; convenios con cobro pendiente; actividad reciente.

EXPEDIENTES: menú "Expedientes" -- chips arriba para filtrar (Todos,
Activos, Concluidos, Con alerta, por estatus); buscador en la barra
superior (actor, demandado, expediente, puesto); botón "+ Nuevo
expediente" arriba a la derecha abre una ficha en blanco en "Editar
datos" -- se llenan los campos que se tengan y se le da "Guardar datos
del expediente" (no hay que llenarlo todo de una vez).

DENTRO DE UN EXPEDIENTE (pestañas): Informe del juicio (etapa actual,
próximos eventos, bitácora de notas); Editar datos (todos los campos,
"Guardar datos del expediente" al final); Documentos (sube PDF/Word/
Excel/imágenes hasta 20MB, botón "Ver" para PDF/imágenes); Etapas del
juicio (15 etapas con casilla + fecha cada una, "Guardar etapas" al
final, aparece botón "Avisar al cliente por WhatsApp"); Prescripción
(fecha límite calculada); Cálculo de liquidación (botón "Extraer cálculo
PDF"); Cobros (solo si hay convenio: marcar "Cobrado" + fecha real de
cada pago, "Guardar cobros"); Amparo; Pendientes (términos por días o
fecha fija); Generar escrito (descarga demanda u otra plantilla en
Word); Hechos del despido; Gestión interna (registrar convenio/pago,
reasignar socio, marcar Concluido); Historial de cambios (solo lo ve el
Administrador).

REGISTRAR CONVENIO/PAGO: en "Gestión interna" del expediente, marcar
"Hubo convenio o pago acordado", capturar monto y fecha a pagar, "Guardar
convenio/pago". Cuando el pago ya se reciba de verdad: ir a la pestaña
"Cobros", marcar "Cobrado" en esa fecha y capturar la fecha de cobro real,
"Guardar cobros" -- solo así el dinero cuenta en "Ingresos por periodo".

AVISAR AL CLIENTE POR WHATSAPP: el botón aparece tras guardar una etapa,
convenio o nota. Abre WhatsApp con el mensaje ya escrito -- SIEMPRE hay
que confirmar el envío dentro de WhatsApp, nunca se manda solo.

AGENDA GENERAL: vencidos en rojo arriba, próximos eventos abajo (pagos,
prescripciones, audiencias, amparos, pendientes). Google Calendar:
botón para conectar, "Sincronizar ahora" manda esos eventos como eventos
de todo el día -- es de un solo sentido (lo que se edite en Google
Calendar no regresa al sistema).

GENERAR ESCRITO EN WORD: pestaña "Generar escrito" del expediente, elegir
plantilla (demanda u otra subida por el Administrador), botón "Generar
documento en Word (.docx)" -- descarga un .docx real y editable.

ALERTAS DE PRESCRIPCIÓN: menú aparte, tres bloques (Críticas ≤10 días,
Próximas 11-30, Con margen 30+) más un bloque de asuntos sin riesgo (ya
con demanda presentada o convenio).

REPORTES: "Reporte ejecutivo (PDF)" (botón del Tablero, resumen del mes);
"Ingresos por periodo" (elige periodo, total cobrado, desglose por
socio); "Empresas demandadas" (agrupa por demandado); "Tasa de éxito"
(% favorable de asuntos ya concluidos).

SOLO PARA EL ADMINISTRADOR: "Equipo" (agregar socios, renombrar,
restablecer contraseñas, ver/descargar respaldo JSON de toda la
información); "Formato de demanda" (subir la plantilla Word que usa el
sistema, y plantillas adicionales); "Días inhábiles" (calendario para
calcular vencimientos); "Historial de cambios" dentro de cada expediente.
El Administrador ve todos los expedientes; cada socio solo ve los suyos.

PORTAL DE CLIENTE: existe una vista de solo lectura para que el cliente
final vea el avance de su propio caso -- si preguntan cómo se accede o
configura, y no estás seguro del detalle exacto, usa
escalar_soporte_a_humano en vez de inventar el paso.

INSTALAR EN EL CELULAR: Android (Chrome) -- aviso "Instalar app" o menú
⋮ → "Instalar app". iPhone (Safari) -- ícono de compartir → "Añadir a
pantalla de inicio". Se sigue entrando con el mismo usuario y contraseña.

PREGUNTAS FRECUENTES:
- "No veo todos los expedientes, solo algunos" -- normal si no es
  Administrador: cada socio ve solo lo suyo.
- "¿Puedo deshacer un cambio?" -- no hay botón de deshacer; el
  Administrador puede ver qué cambió en "Historial de cambios".
- "Cerré sin guardar y perdí datos" -- se pierde lo no guardado; hay que
  guardar seguido.
- "¿Los cálculos son definitivos?" -- no, son de apoyo, hay que
  verificarlos contra el criterio real del Tribunal.
- "Un número se ve mal / absurdo" -- revisar primero el salario diario
  integrado en "Editar datos", es la causa más común.

=== TEMAS QUE SIEMPRE SE ESCALAN (nunca los resuelvas tú, ni des pasos) ===
Suscripción, facturación, cambio de método de pago, cancelar la cuenta o
reactivarla, cualquier cobro que parezca incorrecto, cualquier cosa de
seguridad o acceso comprometido (que no sea la contraseña olvidada de
alguien -- ver arriba, eso ya tiene solución directa con el enlace de
"¿Olvidaste tu contraseña?"), pérdida de datos, o cualquier duda que esta
guía no cubra con seguridad. Para cualquiera de estos, usa
escalar_soporte_a_humano de inmediato -- no le digas a la persona "yo te
ayudo" ni intentes resolverlo tú, solo avísale con calidez que alguien
del equipo lo va a contactar directo por este mismo WhatsApp, y llama la
herramienta.

Sé breve (esto es WhatsApp, no un correo), cálido y directo. Una sola
llamada a escalar_soporte_a_humano por conversación basta -- si ya
escalaste antes en esta misma conversación, no la vuelvas a llamar, solo
contesta con calidez que el equipo ya lo tiene y lo va a contactar.
TXT;

const SOPORTE_TOOLS = [
    [
        'name' => 'escalar_soporte_a_humano',
        'description' => 'Usar cuando la duda es de un tema delicado (facturación, suscripción, cancelación, seguridad, contraseña de un Administrador sin nadie más que la resetee, pérdida de datos) o cuando la guía no cubre la pregunta con seguridad. Avisa a un humano del equipo y pausa esta conversación de soporte.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'resumen' => ['type' => 'string', 'description' => 'Resumen breve de la duda o problema, para que el humano no tenga que releer todo el chat.'],
            ],
            'required' => ['resumen'],
        ],
    ],
];

const IA_SOPORTE_MODELO = 'claude-haiku-4-5-20251001';

function soporte_llamar_claude(array $mensajes): ?array
{
    $credentialsFile = __DIR__ . '/anthropic_credentials.php';
    if (!file_exists($credentialsFile)) return null;
    require_once $credentialsFile;

    $payload = [
        'model' => IA_SOPORTE_MODELO,
        'max_tokens' => 1024,
        'thinking' => ['type' => 'disabled'],
        'system' => [
            ['type' => 'text', 'text' => SOPORTE_SYSTEM_PROMPT, 'cache_control' => ['type' => 'ephemeral']],
        ],
        'tools' => SOPORTE_TOOLS,
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
    curl_close($ch);

    if ($raw === false || $status !== 200) {
        file_put_contents(__DIR__ . '/ia_debug.log', date('c')
            . " | [soporte] status=$status | body=" . (string)$raw . "\n", FILE_APPEND);
        return null;
    }
    return json_decode($raw, true);
}

/**
 * $mensajes: mismo formato que ia_responder_whatsapp (role/content,
 * fusionado con ia_mensajes_desde_historial). Devuelve el texto a
 * contestar; si se detectó un tema delicado, ya dejó registrado el
 * prospecto (tipo=control_expedientes, misma pestaña donde ya caen los
 * despachos interesados) y mandó la notificación -- el llamador solo
 * necesita mandar el texto devuelto por WhatsApp.
 */
function soporte_responder(PDO $pdo, string $telefono, array $mensajes, ?string $nombre): string
{
    $data = soporte_llamar_claude($mensajes);
    if ($data === null) {
        return 'Tuvimos un problema técnico de nuestro lado -- ya le avisé al equipo, en breve te contactan por aquí mismo.';
    }

    $bloques = $data['content'] ?? [];
    $texto = '';
    $resumenEscalar = null;
    foreach ($bloques as $bloque) {
        if (($bloque['type'] ?? '') === 'text') {
            $texto .= $bloque['text'];
        } elseif (($bloque['type'] ?? '') === 'tool_use' && ($bloque['name'] ?? '') === 'escalar_soporte_a_humano') {
            $resumenEscalar = (string)($bloque['input']['resumen'] ?? 'Duda técnica de Control de Expedientes, revisar la conversación completa.');
        }
    }

    if ($resumenEscalar !== null) {
        ia_registrar_prospecto_atorado(
            $pdo, $telefono,
            ['tipo' => 'control_expedientes', 'estado' => '', 'nombre' => $nombre ?? '', 'resumen' => $resumenEscalar],
            $resumenEscalar,
            $nombre
        );
        if ($texto === '') {
            $texto = 'Entiendo -- le voy a avisar de inmediato a alguien del equipo para que te ayude directo con esto por este mismo WhatsApp. 🙏';
        }
    }

    return $texto !== '' ? $texto : 'Ya le avisé al equipo para que te ayude con esto. En breve te contactan por aquí mismo.';
}
