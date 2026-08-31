<?php
declare(strict_types=1);

// Cruce automático de tesis nuevas contra los expedientes activos del
// despacho -- hasta ahora la jurisprudencia solo se buscaba a mano
// (jurisprudencia_buscar.php). Se llama desde jurisprudencia_ingest.php,
// una sola vez por corrida del robot, SOLO con las tesis que de verdad son
// nuevas (no las que ya existían y solo se refrescó su texto) para no
// disparar esto en cada corrida sin necesidad.
//
// Costo controlado a propósito: el Paso 1 (candidatas por FULLTEXT sobre
// el rubro) es gratis y se hace por expediente contra el lote chico de
// tesis nuevas -- la mayoría de los expedientes no van a tener ninguna
// coincidencia y ahí termina, sin gastar nada de IA. Solo cuando SÍ hay
// coincidencia de palabras clave se manda una llamada barata a Claude
// (Haiku, sin razonamiento extendido) para decidir con criterio si de
// verdad aplica -- igual que el buscador manual, pero aquí el "candidatas"
// que se le manda es mínimo (nada más las tesis nuevas de esta corrida que
// ya coincidieron por palabras clave con ESTE expediente en particular).

const JURISPRUDENCIA_CRUCE_MODELO = 'claude-haiku-4-5-20251001';

// Descripción corta de los hechos del caso a partir de lo que ya está
// capturado en el expediente -- no es tan rica como lo que un abogado
// escribiría a mano en el buscador manual, pero basta para encontrar
// candidatas por palabras clave. Los campos vacíos simplemente se omiten.
function jurisprudencia_expediente_pregunta(array $exp): string
{
    $partes = [(string)($exp['tipo_asunto'] ?: 'Asunto laboral')];
    if (!empty($exp['puesto'])) $partes[] = 'puesto: ' . $exp['puesto'];
    if (!empty($exp['giro_empresa'])) $partes[] = 'giro de la empresa demandada: ' . $exp['giro_empresa'];
    if (!empty($exp['quien_despidio'])) $partes[] = 'quién dio el aviso de despido: ' . $exp['quien_despidio'];
    return implode('. ', $partes);
}

function jurisprudencia_cruzar_candidatas(PDO $pdo, string $pregunta, array $registrosNuevos): array
{
    if (trim($pregunta) === '' || !$registrosNuevos) return [];
    $placeholders = implode(',', array_fill(0, count($registrosNuevos), '?'));
    $stmt = $pdo->prepare(
        "SELECT registro_digital, rubro, instancia, tipo, epoca, numero_tesis, fecha_publicacion, texto_completo
         FROM jurisprudencia_tesis
         WHERE registro_digital IN ($placeholders) AND MATCH(rubro) AGAINST (? IN NATURAL LANGUAGE MODE)
         ORDER BY MATCH(rubro) AGAINST (? IN NATURAL LANGUAGE MODE) DESC"
    );
    $stmt->execute(array_merge($registrosNuevos, [$pregunta, $pregunta]));
    return $stmt->fetchAll();
}

// Le pregunta a Claude, por cada candidata (ya preseleccionada por
// palabras clave), si de verdad aplica a los hechos del expediente --
// responde en un formato de una línea por tesis, fácil de parsear:
// "REGISTRO 123456: SI | interpretación corta" o "REGISTRO 123456: NO".
function jurisprudencia_cruzar_evaluar(string $pregunta, array $candidatas): array
{
    $bloques = [];
    foreach ($candidatas as $t) {
        $bloques[] = "[Registro digital: {$t['registro_digital']}]\nRubro: {$t['rubro']}\n"
            . 'Texto: ' . mb_strimwidth((string)$t['texto_completo'], 0, 900, '…');
    }
    $payload = [
        'model' => JURISPRUDENCIA_CRUCE_MODELO,
        'max_tokens' => 800,
        'thinking' => ['type' => 'disabled'],
        'system' => 'Eres el asistente jurídico interno de un despacho de derecho laboral en México. Te doy una '
            . 'descripción breve (generada automáticamente a partir de los datos capturados, no escrita por el '
            . 'abogado) de un expediente activo, y una lista corta de tesis de la SCJN recién publicadas que '
            . 'coincidieron por palabras clave con el rubro. Decide con criterio jurídico real, caso por caso, si '
            . 'cada tesis de verdad aplica al expediente -- la descripción es escueta a propósito, así que sé '
            . 'conservador: solo marca SI cuando el criterio de la tesis conecta de forma clara y directa con los '
            . "datos dados, no por coincidencia superficial de tema.\n\n"
            . "Responde EXACTAMENTE una línea por cada tesis de la lista, en este formato y en este orden:\n"
            . "REGISTRO [registro digital]: SI | [interpretación de 1-2 líneas: qué establece la tesis y cómo "
            . "conecta con este expediente]\n"
            . "REGISTRO [registro digital]: NO\n\n"
            . 'No agregues nada más, ni introducciones ni cierres.',
        'messages' => [[
            'role' => 'user',
            'content' => "Expediente: {$pregunta}\n\nTesis candidatas:\n\n" . implode("\n\n---\n\n", $bloques),
        ]],
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
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $status !== 200) return [];

    $data = json_decode($raw, true);
    $texto = '';
    foreach (($data['content'] ?? []) as $bloque) {
        if (($bloque['type'] ?? '') === 'text') $texto .= $bloque['text'];
    }

    $aplicables = [];
    foreach (explode("\n", trim($texto)) as $linea) {
        if (!preg_match('/^REGISTRO\s+(\d+):\s*SI\s*\|\s*(.+)$/iu', trim($linea), $m)) continue;
        $aplicables[(int)$m[1]] = trim($m[2]);
    }
    return $aplicables;
}

function jurisprudencia_cruzar_con_activos(PDO $pdo, array $registrosNuevos): void
{
    if (!$registrosNuevos) return;
    $credentialsFile = __DIR__ . '/anthropic_credentials.php';
    if (!file_exists($credentialsFile)) return;
    require_once $credentialsFile;
    require_once __DIR__ . '/push_helpers.php';

    // "Activo" aquí es una aproximación simple (no concluido a mano) -- a
    // diferencia de la prescripción, una sugerencia de jurisprudencia que
    // le llegue a un caso ya resuelto en los hechos (pero sin marcar en el
    // sistema) no tiene ningún riesgo real, así que no hace falta la misma
    // lógica exacta de caseStage() del frontend.
    $expedientes = $pdo->query(
        "SELECT id, tipo_asunto, puesto, giro_empresa, quien_despidio, abogado_id, actor, demandado
         FROM expedientes WHERE concluido_manual = 0"
    )->fetchAll();

    $insertMatch = $pdo->prepare(
        'INSERT IGNORE INTO jurisprudencia_expediente_match (expediente_id, registro_digital, interpretacion)
         VALUES (:eid, :reg, :interp)'
    );

    foreach ($expedientes as $exp) {
        $pregunta = jurisprudencia_expediente_pregunta($exp);
        $candidatas = jurisprudencia_cruzar_candidatas($pdo, $pregunta, $registrosNuevos);
        if (!$candidatas) continue;

        $aplicables = jurisprudencia_cruzar_evaluar($pregunta, $candidatas);
        if (!$aplicables) continue;

        $nuevasParaEsteExp = 0;
        foreach ($aplicables as $registro => $interpretacion) {
            $insertMatch->execute([':eid' => $exp['id'], ':reg' => $registro, ':interp' => $interpretacion]);
            if ($insertMatch->rowCount() > 0) $nuevasParaEsteExp++;
        }

        if ($nuevasParaEsteExp > 0 && $exp['abogado_id']) {
            $caso = trim(($exp['actor'] ?? '') . ' vs ' . ($exp['demandado'] ?? ''), ' vs');
            $titulo = $nuevasParaEsteExp === 1
                ? '📚 Tesis nueva aplicable a un expediente'
                : "📚 {$nuevasParaEsteExp} tesis nuevas aplicables a un expediente";
            push_enviar_a_usuario($pdo, (int)$exp['abogado_id'], $titulo, $caso, '/sistema/');
        }
    }
}
