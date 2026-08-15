<?php
declare(strict_types=1);

// Upsert compartido de prospectos — lo usan whatsapp_procesar.php (al
// registrar un lead recién detectado por la IA), ia_helpers.php (cuando el
// flujo automático de la asesoría de pago ya no puede seguir solo) y
// mercadopago_webhook.php (cuando se confirma un pago), para no duplicar
// el mismo SQL de upsert en varios archivos.

/**
 * Guarda o actualiza un prospecto. $lead trae ['tipo','estado','nombre','resumen'].
 * $forzarPausa=true fuerza pausado_bot=1 sin importar el tipo (se usa
 * cuando ya no hay nada automático que el bot pueda seguir haciendo: el
 * flujo de agendado/pago se atoró, o el pago ya se confirmó). Un lead de
 * despido siempre se pausa de inmediato (necesita intake humano real) y
 * nunca se "degrada" a asesoría paga si llega uno nuevo después. Esto
 * nunca vuelve a activar solo un bot que un abogado ya pausó a mano.
 *
 * $reactivarEstatus=true vuelve a poner estatus='nuevo' aunque ya
 * estuviera "Descartado" o "Contactado" — se usa cuando de verdad hay algo
 * nuevo que requiere atención (se atoró el flujo, o se confirmó un pago),
 * para no dejar un caso real escondido bajo un estatus viejo. En ese caso
 * SÍ se vuelve a pausar el bot como siempre (se está reabriendo el caso a
 * propósito).
 *
 * Si el prospecto YA está "Descartado" y esta llamada no es para
 * reactivarlo (Reactivar=false), NO se toca pausado_bot aunque la
 * conversación vuelva a calificar como despido — "descartado" es que el
 * despacho no toma el litigio, no que se le deje de contestar a la
 * persona (ver prospectosHTML() en app.js), así que el bot debe poder
 * seguirle contestando normal si vuelve a escribir.
 */
function guardar_prospecto(PDO $pdo, string $telefono, ?string $nombrePerfil, array $lead, bool $forzarPausa = false, bool $reactivarEstatus = false): void
{
    $nombre = $lead['nombre'] !== '' ? $lead['nombre'] : $nombrePerfil;
    $estado = $lead['estado'] !== '' ? $lead['estado'] : null;
    $pausar = ($forzarPausa || $lead['tipo'] === 'despido') ? 1 : 0;
    $estatusUpdate = $reactivarEstatus ? "estatus = 'nuevo'," : '';
    $pausadoUpdate = $reactivarEstatus
        ? "IF(tipo = 'despido' OR VALUES(tipo) = 'despido' OR VALUES(pausado_bot) = 1, 1, pausado_bot)"
        : "IF(estatus = 'descartado', pausado_bot, IF(tipo = 'despido' OR VALUES(tipo) = 'despido' OR VALUES(pausado_bot) = 1, 1, pausado_bot))";
    $stmt = $pdo->prepare(
        "INSERT INTO prospectos (telefono, tipo, nombre, estado_ubicacion, resumen_caso, pausado_bot)
         VALUES (:t, :tipo, :nombre, :estado, :resumen, :pausar)
         ON DUPLICATE KEY UPDATE
           tipo = IF(tipo = 'despido', 'despido', VALUES(tipo)),
           nombre = COALESCE(VALUES(nombre), nombre),
           estado_ubicacion = COALESCE(VALUES(estado_ubicacion), estado_ubicacion),
           resumen_caso = VALUES(resumen_caso),
           {$estatusUpdate}
           pausado_bot = {$pausadoUpdate}"
    );
    $stmt->execute([
        ':t' => $telefono,
        ':tipo' => $lead['tipo'],
        ':nombre' => $nombre,
        ':estado' => $estado,
        ':resumen' => $lead['resumen'],
        ':pausar' => $pausar,
    ]);
}
