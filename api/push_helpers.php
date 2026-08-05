<?php
declare(strict_types=1);

// Notificaciones push del navegador (como WhatsApp): avisan aunque el
// sistema esté cerrado. Usa la librería minishlink/web-push (carpeta
// api/vendor/, no se sube a git — se instala aparte, ver
// push_credentials.example.php). Si todavía no está instalada o
// configurada, todas las funciones de aquí simplemente no hacen nada — el
// resto del sistema sigue funcionando normal sin notificaciones.

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use Http\Client\Curl\Client as PushCurlClient;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * Instancia lista para mandar notificaciones, o null si todavía no se
 * instaló la librería (api/vendor/) o no existe push_credentials.php.
 */
function push_webpush_instancia(): ?WebPush
{
    $vendorAutoload = __DIR__ . '/vendor/autoload.php';
    $credentialsFile = __DIR__ . '/push_credentials.php';
    if (!file_exists($vendorAutoload) || !file_exists($credentialsFile)) {
        return null;
    }
    require_once $vendorAutoload;
    require_once $credentialsFile;

    $psr17 = new Psr17Factory();
    $curl = new PushCurlClient($psr17, $psr17);

    return new WebPush(
        ['VAPID' => [
            'subject' => PUSH_VAPID_SUBJECT,
            'publicKey' => PUSH_VAPID_PUBLIC_KEY,
            'privateKey' => PUSH_VAPID_PRIVATE_KEY,
        ]],
        [],
        $curl,
        $psr17,
        $psr17
    );
}

/**
 * Manda una notificación a TODOS los dispositivos donde ese usuario activó
 * las notificaciones. Si un dispositivo ya no existe (desinstaló la app,
 * borró el navegador, etc. — la suscripción "caduca" del lado de Google/
 * Apple), se borra sola de la base de datos para no seguir intentando.
 */
function push_enviar_a_usuario(PDO $pdo, int $usuarioId, string $titulo, string $cuerpo, string $url = ''): void
{
    // Las notificaciones son un extra — un error aquí (librería mal
    // instalada, versión de PHP incompatible, Mercado Pago/Google caídos,
    // etc.) NUNCA debe tumbar el flujo que las llama (confirmar un pago,
    // contestar por WhatsApp), así que se atrapa cualquier error y solo se
    // registra en el log de PHP.
    try {
        $webPush = push_webpush_instancia();
        if ($webPush === null) return;

        $stmt = $pdo->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE usuario_id = :uid');
        $stmt->execute([':uid' => $usuarioId]);
        $filas = $stmt->fetchAll();
        if (!$filas) return;

        $payload = json_encode(['title' => $titulo, 'body' => $cuerpo, 'url' => $url], JSON_UNESCAPED_UNICODE);

        $porId = [];
        foreach ($filas as $f) {
            $porId[$f['endpoint']] = (int)$f['id'];
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $f['endpoint'],
                    'keys' => ['p256dh' => $f['p256dh'], 'auth' => $f['auth']],
                ]),
                $payload
            );
        }

        foreach ($webPush->flush() as $reporte) {
            if ($reporte->isSuccess()) continue;
            $endpoint = $reporte->getEndpoint();
            // 404/410 = el navegador ya no reconoce esa suscripción (caducó o
            // se desactivó del otro lado) — hay que dejar de intentarle.
            if ($reporte->isSubscriptionExpired() && isset($porId[$endpoint])) {
                $del = $pdo->prepare('DELETE FROM push_subscriptions WHERE id = :id');
                $del->execute([':id' => $porId[$endpoint]]);
            }
        }
    } catch (\Throwable $e) {
        error_log('push_enviar_a_usuario falló: ' . $e->getMessage());
    }
}

/**
 * Notifica al abogado turnado de un prospecto, o si nadie lo ha turnado
 * todavía, a todos los administradores — mismo criterio de "quién debe
 * enterarse" que ya se usa en el resto del sistema.
 */
function push_notificar_prospecto(PDO $pdo, ?int $asignadoA, string $titulo, string $cuerpo, string $url = ''): void
{
    if ($asignadoA) {
        push_enviar_a_usuario($pdo, $asignadoA, $titulo, $cuerpo, $url);
        return;
    }
    $stmt = $pdo->query("SELECT id FROM usuarios WHERE rol = 'administrador' AND activo = 1");
    foreach ($stmt->fetchAll() as $r) {
        push_enviar_a_usuario($pdo, (int)$r['id'], $titulo, $cuerpo, $url);
    }
}
