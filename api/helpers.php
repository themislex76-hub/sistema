<?php
declare(strict_types=1);

function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function respond($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    return [
        'id' => (int)$_SESSION['user_id'],
        'nombre' => $_SESSION['user_nombre'],
        'email' => $_SESSION['user_email'],
        'rol' => $_SESSION['user_rol'],
    ];
}

function require_login(): array
{
    $u = current_user();
    if (!$u) fail('No has iniciado sesión.', 401);
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['rol'] !== 'administrador') fail('Esta acción requiere permisos de administrador.', 403);
    return $u;
}

// Autenticación para los robots de monitoreo de boletines (Federal, CDMX,
// Edomex) — no tienen sesión de usuario, mandan una llave secreta compartida
// en el header X-Robot-Key. Ver api/robot_credentials.php.
function require_robot_key(): void
{
    require_once __DIR__ . '/robot_credentials.php';
    $sent = $_SERVER['HTTP_X_ROBOT_KEY'] ?? '';
    if ($sent === '' || !hash_equals(ROBOT_API_KEY, $sent)) {
        fail('Llave de robot inválida o ausente.', 401);
    }
}

// Protección CSRF de "doble envío": el token se entrega al hacer login (en el
// cuerpo JSON de la respuesta) y el frontend debe reenviarlo en el header
// X-CSRF-Token en cada petición que modifique datos (POST/PUT/DELETE).
function require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf'] ?? '';
    if ($expected === '' || !hash_equals($expected, $sent)) {
        fail('Token de seguridad inválido o ausente. Vuelve a intentar (puede que tu sesión haya expirado).', 419);
    }
}

function new_csrf_token(): string
{
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf'] = $token;
    return $token;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Contraseña temporal legible (sin caracteres ambiguos 0/O/1/l/I).
function random_temp_password(int $len = 10): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

// Código de acceso para el portal de cliente (además de expediente + apellido).
function random_access_code(int $len = 8): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function log_historial(PDO $pdo, int $expedienteId, array $usuario, string $campo, ?string $antes, ?string $despues): void
{
    if ($antes === $despues) return;
    $stmt = $pdo->prepare(
        'INSERT INTO expediente_historial (expediente_id, usuario_id, usuario_nombre, campo, antes, despues)
         VALUES (:eid, :uid, :uname, :campo, :antes, :despues)'
    );
    $stmt->execute([
        ':eid' => $expedienteId,
        ':uid' => $usuario['id'],
        ':uname' => $usuario['nombre'],
        ':campo' => $campo,
        ':antes' => $antes,
        ':despues' => $despues,
    ]);
}

// true si el expediente es visible para el usuario actual (admin ve todo,
// abogado solo lo suyo). Se aplica siempre en el servidor, nunca solo en JS.
function expediente_visible_where(array $usuario): string
{
    if ($usuario['rol'] === 'administrador') return '1=1';
    return 'e.abogado_id = ' . (int)$usuario['id'];
}
