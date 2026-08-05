<?php
declare(strict_types=1);

// El servidor de hosting normalmente corre en UTC por default — sin esto,
// TODAS las fechas/horas que calcula PHP (DateTimeImmutable, date(), etc.)
// quedan varias horas adelantadas respecto a México. Se pone aquí (no en
// config.php) porque db.php lo cargan tanto las vistas con sesión como los
// webhooks públicos (WhatsApp, Mercado Pago), que nunca pasan por
// config.php — así queda garantizado sin importar la puerta de entrada.
date_default_timezone_set('America/Mexico_City');

$credentialsFile = __DIR__ . '/db_credentials.php';
if (!file_exists($credentialsFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Falta api/db_credentials.php. Copia db_credentials.example.php y llena tus datos reales de MySQL.']);
    exit;
}
require_once $credentialsFile;

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // El servidor de hosting normalmente corre en UTC — sin esto,
        // NOW()/CURDATE() de MySQL quedan varias horas adelantados
        // respecto a México (se usa el offset fijo -06:00 en vez del
        // nombre "America/Mexico_City" porque muchos hostings compartidos
        // no tienen cargadas las tablas de nombres de zona horaria).
        $pdo->exec("SET time_zone = '-06:00'");
    }
    return $pdo;
}
