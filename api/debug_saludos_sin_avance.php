<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: encuentra conversaciones donde TODOS los mensajes
// del cliente son solo un saludo genérico ("Hola", "Buenas tardes", "Lic")
// sin que en ningún punto llegue a explicar su caso -- para revisar si el
// bot responde de forma débil/genérica a un saludo (arreglable) o si de
// plano la persona nunca pensaba seguir. Solo Administrador. Se puede
// borrar cuando ya no haga falta.
//
// Uso: debug_saludos_sin_avance.php?desde=YYYY-MM-DD (opcional, default 7 días atrás)
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

$desde = trim((string)($_GET['desde'] ?? '')) ?: date('Y-m-d', strtotime('-7 days'));

$stmt = $pdo->prepare(
    "SELECT telefono, direccion, texto, creado_en FROM whatsapp_conversaciones
     WHERE creado_en >= :desde
     ORDER BY telefono, id"
);
$stmt->execute([':desde' => $desde]);
$filas = $stmt->fetchAll();

$porTelefono = [];
foreach ($filas as $f) {
    $porTelefono[$f['telefono']][] = $f;
}

// Patrón deliberadamente angosto (solo saludos/aperturas típicas, nada de
// contenido) -- ancla el texto completo (^...$) para no repetir el error
// de "enga[ñn]" de hoy (matchear como subcadena dentro de otra palabra).
// Permite combinaciones ("Buenas noches Lic", "Hola Licenciado") uniendo
// varias palabras de saludo seguidas.
$palabraSaludo = '(hola+|oigan?|disculpe|se\s+podr[aá]|buenas?|tardes?|noches?|d[ií]as?|buen|lic\.?|licenciado[.,]?|licenciada[.,]?|rub[eé]n)';
$patronSaludo = '/^' . $palabraSaludo . '([\s.,!¡¿?]+' . $palabraSaludo . ')*[\s.,!¡¿?]*$/iu';

$candidatos = [];
foreach ($porTelefono as $tel => $msgs) {
    $entrantes = array_values(array_filter($msgs, fn($m) => $m['direccion'] === 'entrante'));
    if (count($entrantes) === 0 || count($entrantes) > 3) continue;
    $todosSaludo = true;
    foreach ($entrantes as $e) {
        $t = trim((string)$e['texto']);
        if ($t === '' || mb_strlen($t) > 25 || preg_match($patronSaludo, $t) !== 1) {
            $todosSaludo = false;
            break;
        }
    }
    if ($todosSaludo) {
        $candidatos[$tel] = $msgs;
    }
}

echo "Conversaciones desde {$desde} donde TODOS los mensajes del cliente son solo saludo (sin explicar su caso):\n\n";
echo "Total encontradas: " . count($candidatos) . " de " . count($porTelefono) . " números en el periodo\n\n";

$i = 0;
foreach ($candidatos as $tel => $msgs) {
    $i++;
    if ($i > 30) {
        echo "... (se cortó en 30 para no hacer el reporte gigante, hay más)\n";
        break;
    }
    echo "=== {$tel} ===\n";
    foreach ($msgs as $m) {
        $quien = $m['direccion'] === 'entrante' ? 'Cliente' : 'Bot';
        echo "  [{$m['creado_en']}] {$quien}: " . mb_strimwidth((string)$m['texto'], 0, 200, '…') . "\n";
    }
    echo "\n";
}
