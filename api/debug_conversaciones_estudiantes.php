<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta de solo lectura: busca conversaciones reales de gente que
// suena a estudiante de derecho / abogado joven (no trabajador con un
// problema laboral propio) por palabras clave típicas, para ver
// cualitativamente qué pasa con ellos en el chat -- si el bot les
// ofrece bien el curso, si la conversación se desvía, o si de plano casi
// no llegan. Complementa debug_embudo_cursos.php (que da el número, no
// el por qué). Solo Administrador. Se puede borrar cuando ya no haga
// falta.
//
// Uso: debug_conversaciones_estudiantes.php?dias=60 (default 60)
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$dias = max(1, min(180, (int)($_GET['dias'] ?? 60)));
$pdo = db();

$patron = '/(c[eé]dula|pasante|recién\s+egresad|reci[eé]n\s+egresad|estudio\s+derecho|estudiante\s+de\s+derecho|soy\s+abogad|litigar|litigante|preparator|facultad\s+de\s+derecho|voy\s+en\s+el\s+semestre)/iu';

$stmt = $pdo->prepare(
    "SELECT telefono, texto, creado_en FROM whatsapp_conversaciones
     WHERE direccion = 'entrante' AND creado_en >= :desde ORDER BY telefono, id"
);
$stmt->execute([':desde' => date('Y-m-d', strtotime("-{$dias} days"))]);

$porTelefono = [];
foreach ($stmt->fetchAll() as $r) {
    $porTelefono[$r['telefono']][] = $r;
}

$stmtProspecto = $pdo->prepare(
    "SELECT tipo, curso_interes FROM prospectos WHERE telefono = :t ORDER BY id DESC LIMIT 1"
);

$encontrados = 0;
$conInteresCurso = 0;
echo "=== Conversaciones que suenan a estudiante/abogado joven -- últimos {$dias} días ===\n\n";

foreach ($porTelefono as $telefono => $mensajes) {
    $match = null;
    foreach ($mensajes as $m) {
        if (preg_match($patron, (string)$m['texto']) === 1) {
            $match = $m;
            break;
        }
    }
    if ($match === null) continue;

    $encontrados++;
    $stmtProspecto->execute([':t' => $telefono]);
    $prospecto = $stmtProspecto->fetch();
    $tieneCursoInteres = $prospecto && $prospecto['tipo'] === 'interes_curso';
    if ($tieneCursoInteres) $conInteresCurso++;

    echo "[{$match['creado_en']}] {$telefono}";
    echo $tieneCursoInteres ? " -- SÍ quedó registrado interés en curso ({$prospecto['curso_interes']})\n" : " -- NO quedó registrado interés en curso\n";
    echo "  \"" . mb_strimwidth((string)$match['texto'], 0, 150, '…') . "\"\n\n";
}

echo "--------------------------------------------------\n";
echo "{$encontrados} conversación(es) que suenan a estudiante/abogado encontradas.\n";
echo "{$conInteresCurso} de esas sí terminaron con interés en curso registrado.\n";
if (!$encontrados) {
    echo "(ninguna -- puede que el patrón de palabras no esté capturando bien cómo se presentan, o que de verdad casi no estén llegando por WhatsApp)\n";
}
