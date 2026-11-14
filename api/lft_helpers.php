<?php
declare(strict_types=1);

// Consulta del TEXTO OFICIAL de la Ley Federal del Trabajo vigente
// (tabla lft_articulos, migraciones 070/070b) y de las tesis de la SCJN
// ya guardadas por el robot de jurisprudencia (tabla jurisprudencia_tesis)
// para que el bot de WhatsApp cite solo lo que de verdad dice la ley o
// una tesis real, nunca de memoria (errores reales: citar el Art. 47 para
// una suspensión, que es el Art. 423-X; citar una jurisprudencia con un
// número que no se pudo verificar).
//
// Ninguna de estas funciones truena si la tabla todavía no existe en el
// servidor (migración sin correr): regresan un aviso para que el bot no
// cite el artículo en vez de romper la respuesta completa.

const LFT_MAX_CARACTERES_ARTICULO = 6000;
const LFT_MAX_ARTICULOS_POR_CONSULTA = 5;

// Convierte lo que escriba la IA ("art. 3o", "3 bis", "39 A", "512-d ter",
// "Artículo 47") a la clave con la que se guardó el artículo ("3 Bis",
// "39-A", "512-D Ter", "47").
function lft_normalizar_numero(string $s): string
{
    $s = trim(mb_strtolower($s));
    $s = preg_replace('/^(art[ií]culo|art\.?)\s*/u', '', $s);
    $s = str_replace(['º', '°'], '', $s);
    // "423 fracción X", "132 fracc. XXVII", "132 xxvii": la fracción no es
    // parte de la clave del artículo (el texto completo ya la incluye).
    $s = preg_replace('/[\s,]+(fracc?(i[oó]n|\.)?\s*[ivxlc]+|[ivxlc]{2,})\.?\s*$/u', '', $s);
    if (!preg_match('/^(\d+)\s*o?\.?\s*(?:-?\s*([a-zñ])(?![a-zñ]))?\s*(bis|ter|qu[aá]ter|quater|quintus|quinquies|sexies)?\s*(\d)?\s*$/u', $s, $m)) {
        return trim($s);
    }
    $clave = $m[1];
    if (!empty($m[2])) $clave .= '-' . mb_strtoupper($m[2]);
    if (!empty($m[3])) {
        $suf = str_replace('quater', 'quáter', $m[3]);
        $clave .= ' ' . mb_strtoupper(mb_substr($suf, 0, 1)) . mb_substr($suf, 1);
    }
    if (!empty($m[4])) $clave .= ' ' . $m[4];
    return $clave;
}

function lft_consultar_articulos(PDO $pdo, array $numeros): array
{
    $numeros = array_slice(array_values(array_unique(array_filter(array_map(
        static fn($n) => lft_normalizar_numero((string)$n), $numeros
    )))), 0, LFT_MAX_ARTICULOS_POR_CONSULTA);
    if (!$numeros) return ['error' => 'No indicaste ningún número de artículo.'];

    try {
        $stmt = $pdo->prepare('SELECT numero, titulo, capitulo, texto, reforma_dof FROM lft_articulos WHERE numero = ?');
        $res = [];
        $reforma = null;
        foreach ($numeros as $n) {
            $stmt->execute([$n]);
            $f = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$f) {
                $res[] = ['numero' => $n, 'encontrado' => false];
                continue;
            }
            $reforma = $f['reforma_dof'];
            $texto = (string)$f['texto'];
            $recortado = mb_strlen($texto) > LFT_MAX_CARACTERES_ARTICULO;
            $res[] = [
                'numero' => $f['numero'],
                'encontrado' => true,
                'ubicacion' => trim($f['titulo'] . ($f['capitulo'] ? ' / ' . $f['capitulo'] : '')),
                'texto' => $recortado ? mb_substr($texto, 0, LFT_MAX_CARACTERES_ARTICULO) . ' […texto recortado]' : $texto,
            ];
        }
    } catch (Throwable $e) {
        return ['error' => 'La consulta de la ley no está disponible en este momento. No cites ningún número de artículo en tu respuesta; explica el concepto sin número.'];
    }
    return [
        'fuente' => 'Ley Federal del Trabajo vigente' . ($reforma ? ', última reforma publicada en el DOF el ' . $reforma : ''),
        'articulos' => $res,
        'instruccion' => 'Usa SOLO lo que dice este texto. Si un artículo dice "encontrado": false, ese número no existe en la LFT: no lo cites. Si el texto no respalda lo que ibas a afirmar, no lo afirmes ni lo atribuyas a ese artículo. Explícalo al cliente con palabras sencillas; no pegues el artículo completo.',
    ];
}

function lft_buscar_texto(PDO $pdo, string $consulta): array
{
    $consulta = trim($consulta);
    if ($consulta === '') return ['error' => 'Indica qué tema buscar.'];
    try {
        $stmt = $pdo->prepare(
            'SELECT numero, titulo, capitulo, texto,
                    MATCH(texto) AGAINST (:q IN NATURAL LANGUAGE MODE) AS relevancia
             FROM lft_articulos
             WHERE MATCH(texto) AGAINST (:q2 IN NATURAL LANGUAGE MODE)
             ORDER BY relevancia DESC
             LIMIT 6'
        );
        $stmt->execute([':q' => $consulta, ':q2' => $consulta]);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['error' => 'La búsqueda en la ley no está disponible en este momento. No cites ningún número de artículo en tu respuesta.'];
    }
    $res = [];
    foreach ($filas as $f) {
        $res[] = [
            'numero' => $f['numero'],
            'ubicacion' => trim($f['titulo'] . ($f['capitulo'] ? ' / ' . $f['capitulo'] : '')),
            'extracto' => mb_strimwidth((string)$f['texto'], 0, 700, '…'),
        ];
    }
    return [
        'resultados' => $res,
        'instruccion' => 'Son posibles artículos relacionados, ordenados por coincidencia de palabras (no todos aplican necesariamente). Antes de citar uno, confirma que su texto de verdad dice lo que vas a afirmar; si hace falta el texto completo, usa consultar_articulo_lft. Si ninguno respalda lo que ibas a decir, no cites número de artículo.',
    ];
}

// Busca en el catálogo de tesis de la SCJN que ya descargó el robot de
// jurisprudencia (sjf2.scjn.gob.mx). Misma búsqueda FULLTEXT sobre el
// rubro que usa el buscador del panel (jurisprudencia_buscar.php), sin
// los pasos de IA de ese buscador: aquí la propia IA del bot ya da los
// términos jurídicos.
function jurisprudencia_buscar_para_bot(PDO $pdo, string $terminos): array
{
    $terminos = trim($terminos);
    if ($terminos === '') return ['error' => 'Indica los términos a buscar.'];
    try {
        $stmt = $pdo->prepare(
            'SELECT registro_digital, instancia, epoca, numero_tesis, tipo, rubro, texto_completo, fecha_publicacion,
                    MATCH(rubro) AGAINST (:q IN NATURAL LANGUAGE MODE) AS relevancia
             FROM jurisprudencia_tesis
             WHERE MATCH(rubro) AGAINST (:q2 IN NATURAL LANGUAGE MODE)
             ORDER BY relevancia DESC
             LIMIT 5'
        );
        $stmt->execute([':q' => $terminos, ':q2' => $terminos]);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['error' => 'La búsqueda de jurisprudencia no está disponible en este momento. No cites ninguna tesis ni jurisprudencia en tu respuesta.'];
    }
    $res = [];
    foreach ($filas as $f) {
        $res[] = [
            'registro_digital' => $f['registro_digital'],
            'tipo' => $f['tipo'],
            'numero_tesis' => $f['numero_tesis'],
            'instancia' => $f['instancia'],
            'epoca' => $f['epoca'],
            'fecha_publicacion' => $f['fecha_publicacion'],
            'rubro' => $f['rubro'],
            'extracto' => mb_strimwidth((string)$f['texto_completo'], 0, 1200, '…'),
        ];
    }
    return [
        'fuente' => 'Semanario Judicial de la Federación (SCJN), catálogo guardado en el sistema',
        'resultados' => $res,
        'instruccion' => 'Solo puedes mencionar una tesis o jurisprudencia que aparezca AQUÍ y cuyo rubro/extracto de verdad respalde lo que vas a decir; identifícala por su registro digital. Distingue "jurisprudencia" (obligatoria) de "tesis aislada" según el campo tipo. Si no hay resultados o ninguno aplica, NO menciones ninguna jurisprudencia ni inventes números; orienta solo con la ley. Con el cliente, menciónala en palabras sencillas y sin exagerar su alcance: lo que resuelva un caso concreto depende de las pruebas.',
    ];
}
