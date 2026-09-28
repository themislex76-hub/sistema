// Robot de monitoreo del Boletin Judicial local de la Ciudad de Mexico.
// Descarga el PDF de cada boletin publicado en el rango de fechas, extrae
// el texto de la seccion "TRIBUNALES EN MATERIA LABORAL" y busca ahi los
// numeros de expediente que tenemos capturados con tribunal de CDMX.
// No requiere usuario ni contrasena: es un buscador publico. Pensado para
// correr una vez al dia via cron -- ver README.md.
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const axios = require('axios');
const { wrapper } = require('axios-cookiejar-support');
const { CookieJar } = require('tough-cookie');
const cheerio = require('cheerio');
const config = require('./config');

const BASE = 'https://consultabpj.poderjudicialcdmx.gob.mx:2096';
const BUSQUEDA_URL = BASE + '/consultaboletinpjcdmx';
const FILTRAR_URL = BASE + '/consultaboletinpjcdmx/filtrar';
const ESTADO_FILE = path.join(__dirname, 'procesados_cdmx.json');

const jar = new CookieJar();
const client = wrapper(axios.create({ jar, timeout: 30000 }));

const MESES = {
  ene: '01', feb: '02', mar: '03', abr: '04', may: '05', jun: '06',
  jul: '07', ago: '08', sep: '09', oct: '10', nov: '11', dic: '12',
};

function normalizeExp(s) {
  return (s || '').replace(/\s+/g, '').toUpperCase();
}

function sinAcentos(s) {
  return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase();
}

// El numero de expediente NO es unico entre tribunales -- distintos
// juzgados reutilizan el mismo numero cada año, así que hicieron falta
// las partes para confirmar que de verdad es el mismo caso (se detectó
// con datos reales: dos casos ajenos por completo, de otro tribunal,
// coincidieron por número con el expediente de una clienta real).
const PALABRAS_VACIAS = new Set([
  'DE', 'DEL', 'LA', 'LAS', 'LOS', 'EL', 'Y', 'S', 'A', 'C', 'V', 'SA', 'CV', 'SC', 'SRL', 'VS',
]);
function tokensSignificativos(nombre) {
  return sinAcentos(nombre)
    .replace(/[^A-Z0-9\s]/g, ' ')
    .split(/\s+/)
    .filter(t => t.length > 1 && !PALABRAS_VACIAS.has(t));
}
function pareceElMismoCaso(resumen, actor, demandado) {
  const texto = sinAcentos(resumen);
  const coincideTodo = (nombre) => {
    const tokens = tokensSignificativos(nombre);
    return tokens.length > 0 && tokens.every(t => texto.includes(t));
  };
  return coincideTodo(actor) || coincideTodo(demandado);
}

// El boletin agrupa los casos bajo su propio encabezado de tribunal (ej.
// "SEGUNDO TRIBUNAL LABORAL DE ASUNTOS INDIVIDUALES DE LA CIUDAD DE
// MÉXICO") -- confirmado revisando el PDF real. Se identifica el
// ordinal (PRIMER/SEGUNDO/...) y el tipo (COLECTIVOS/INDIVIDUALES) por
// separado, en vez de comparar el texto completo, porque el sistema
// puede tener capturado el mismo tribunal con una redacción/sufijo
// distinto (ej. sin "de la Ciudad de México").
function analizarTribunalLaboral(texto) {
  const m = /(PRIMER|SEGUNDO|TERCER|CUARTO|QUINTO|SEXTO|S[EÉ]PTIMO|OCTAVO|NOVENO|D[EÉ]CIMO)\s+TRIBUNAL\s+LABORAL\s+DE\s+ASUNTOS\s+(COLECTIVOS|INDIVIDUALES)/i.exec(sinAcentos(texto || ''));
  if (!m) return null;
  return { ordinal: m[1].toUpperCase(), tipo: m[2].toUpperCase() };
}
// true = mismo tribunal, false = tribunales distintos confirmados,
// null = no se pudo identificar el tribunal en alguno de los dos lados
// (formato inesperado) -- en ese caso no se puede usar como criterio.
function tribunalCoincide(tribunalBoletin, tribunalSistema) {
  const a = analizarTribunalLaboral(tribunalBoletin);
  const b = analizarTribunalLaboral(tribunalSistema);
  if (!a || !b) return null;
  return a.ordinal === b.ordinal && a.tipo === b.tipo;
}

function cargarProcesados() {
  try {
    return new Set(JSON.parse(fs.readFileSync(ESTADO_FILE, 'utf8')));
  } catch (e) {
    return new Set();
  }
}

function guardarProcesados(set) {
  fs.writeFileSync(ESTADO_FILE, JSON.stringify(Array.from(set)));
}

function parseFechaModal(texto) {
  const m = /de fecha\s+(\d{1,2})-([a-z]{3})\.?-(\d{4})/i.exec(texto || '');
  if (!m) return null;
  const mes = MESES[m[2].toLowerCase()];
  if (!mes) return null;
  return m[3] + '-' + mes + '-' + m[1].padStart(2, '0');
}

function extraerUrlPdf(src) {
  if (!src) return null;
  const limpio = src.split('#')[0];
  const partes = limpio.split('https://').filter(Boolean);
  if (!partes.length) return null;
  return 'https://' + partes[partes.length - 1];
}

async function obtenerToken() {
  const res = await client.get(BUSQUEDA_URL);
  const $ = cheerio.load(res.data);
  return $('input[name="_token"]').val();
}

function formatoFecha(d) {
  return d.toISOString().slice(0, 10);
}

async function buscarBoletines(diasAtras) {
  const token = await obtenerToken();
  const hoy = new Date();
  const inicio = new Date(hoy);
  inicio.setDate(inicio.getDate() - diasAtras);
  const params = new URLSearchParams();
  params.append('_token', token);
  params.append('fechainicial', formatoFecha(inicio));
  params.append('fechafinal', formatoFecha(hoy));
  const res = await client.post(FILTRAR_URL, params.toString(), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  const $ = cheerio.load(res.data);
  const boletines = [];
  $('div[id^="boletinexterno"]').each((_, div) => {
    const titulo = $(div).find('.modal-title').text();
    const fecha = parseFechaModal(titulo);
    const src = $(div).find('embed').attr('src');
    const url = extraerUrlPdf(src);
    if (fecha && url) boletines.push({ fecha, url });
  });
  return boletines;
}

async function descargarYExtraerTexto(url) {
  const res = await client.get(url, { responseType: 'arraybuffer' });
  const tmpPdf = path.join(os.tmpdir(), 'boletin_cdmx_' + Date.now() + '.pdf');
  fs.writeFileSync(tmpPdf, res.data);
  try {
    return execFileSync('pdftotext', [tmpPdf, '-'], { maxBuffer: 80 * 1024 * 1024 }).toString('utf8');
  } finally {
    fs.unlinkSync(tmpPdf);
  }
}

function extraerSeccionLaboral(texto) {
  const ocurrencias = [];
  const re = /TRIBUNALES EN MATERIA LABORAL/g;
  let m;
  while ((m = re.exec(texto))) ocurrencias.push(m.index);
  if (ocurrencias.length < 2) return '';
  const inicio = ocurrencias[ocurrencias.length - 1];
  const finRe = /\nEDICTOS\n/g;
  finRe.lastIndex = inicio;
  const finMatch = finRe.exec(texto);
  const fin = finMatch ? finMatch.index : texto.length;
  return texto.slice(inicio, fin);
}

const ENCABEZADO_TRIBUNAL_RE = /((?:PRIMER|SEGUNDO|TERCER|CUARTO|QUINTO|SEXTO|S[EÉ]PTIMO|OCTAVO|NOVENO|D[EÉ]CIMO)\s+TRIBUNAL\s+LABORAL\s+DE\s+ASUNTOS\s+(?:COLECTIVOS|INDIVIDUALES)\s+DE\s+LA\s+CIUDAD\s+DE\s+M[EÉ]XICO)/gi;

function extraerCasosDeTramo(tramo, tribunal) {
  // Un caso puede citar más de un número de expediente seguido (ej.
  // expediente + cuaderno de amparo: "Núm. Exp. 2079/2024 3334/2026.") --
  // si el separador solo esperaba un número, ese caso completo se quedaba
  // pegado al siguiente en vez de separarse, mezclando el resumen de dos
  // asuntos distintos. Ahora acepta uno o más números antes del punto
  // final, y registra el caso bajo cada número que mencione.
  const partes = tramo.split(/(?<=N[uú]m\.\s*Exp\.\s*(?:\d+\/\d{4}\s*)+\.)/);
  const casos = [];
  for (const parte of partes) {
    const m = /N[uú]m\.\s*Exp\.\s*((?:\d+\/\d{4}\s*)+)\./.exec(parte);
    if (!m) continue;
    const resumen = parte.replace(/\s+/g, ' ').trim().slice(-500);
    const numeros = m[1].match(/\d+\/\d{4}/g) || [];
    for (const exp of numeros) {
      casos.push({ exp, resumen, tribunal });
    }
  }
  return casos;
}

function extraerCasos(seccionTexto) {
  // El boletin agrupa los casos bajo su propio encabezado de tribunal --
  // se recorre cada tramo entre un encabezado y el siguiente, para saber
  // a qué tribunal exacto pertenece cada caso (el número de expediente se
  // repite entre tribunales distintos, confirmado con datos reales).
  const encabezados = [];
  let m;
  ENCABEZADO_TRIBUNAL_RE.lastIndex = 0;
  while ((m = ENCABEZADO_TRIBUNAL_RE.exec(seccionTexto))) {
    encabezados.push({ index: m.index, tribunal: m[1].replace(/\s+/g, ' ').trim() });
  }

  // Respaldo por si el sitio cambia de formato y no se reconoce ningún
  // encabezado -- sigue funcionando como antes (sin tribunal), en vez de
  // perder todos los casos en silencio.
  if (!encabezados.length) {
    console.log('  AVISO: no se reconoció ningún encabezado de tribunal en esta sección -- se sigue sin ese dato (solo se comparará por expediente y partes).');
    return extraerCasosDeTramo(seccionTexto, null);
  }

  const casos = [];
  for (let i = 0; i < encabezados.length; i++) {
    const inicio = encabezados[i].index;
    const fin = i + 1 < encabezados.length ? encabezados[i + 1].index : seccionTexto.length;
    const tramo = seccionTexto.slice(inicio, fin);
    casos.push(...extraerCasosDeTramo(tramo, encabezados[i].tribunal));
  }
  return casos;
}

async function obtenerExpedientesMonitoreados() {
  const res = await axios.get(config.sistema.apiBase + '/expedientes_monitorear.php', {
    headers: { 'X-Robot-Key': config.sistema.robotKey },
  });
  return res.data.data.expedientes;
}

async function reportarAviso(expedienteId, resumen, fecha) {
  await axios.post(config.sistema.apiBase + '/avisos_ingest.php', {
    expediente_id: expedienteId,
    fuente: 'cdmx_local',
    resumen,
    fecha_publicacion: fecha,
  }, { headers: { 'X-Robot-Key': config.sistema.robotKey } });
}

async function main() {
  console.log(new Date().toISOString(), 'Iniciando revision CDMX...');

  const expedientes = await obtenerExpedientesMonitoreados();
  const porNumero = new Map();
  for (const e of expedientes) {
    // Se confía en 'es_federal' para descartar tribunales federales antes
    // de mirar la sede -- un tribunal FEDERAL puede tener su sede física
    // en Ciudad de México y mencionarlo en su nombre, sin ser un tribunal
    // local de CDMX (bug real detectado: reportaba casos federales como
    // si fueran locales solo por mencionar "Ciudad de México").
    if (e.es_federal) continue;
    const candidatos = [e.junta, e.tribunal].filter(Boolean);
    const esCdmx = candidatos.some(c => /ciudad de m[eé]xico|cdmx/i.test(c));
    if (esCdmx && e.exp) porNumero.set(normalizeExp(e.exp), e);
  }
  console.log(porNumero.size + ' expediente(s) con tribunal de CDMX capturado.');

  const DIAS_ATRAS = 21;
  const procesados = cargarProcesados();
  const boletines = await buscarBoletines(DIAS_ATRAS);
  console.log(boletines.length + ' boletin(es) encontrado(s) en los ultimos ' + DIAS_ATRAS + ' dias.');

  let reportados = 0;
  for (const b of boletines) {
    if (procesados.has(b.url)) continue;
    console.log('Revisando boletin del ' + b.fecha + '...');
    let texto;
    try {
      texto = await descargarYExtraerTexto(b.url);
    } catch (e) {
      console.log('  No se pudo leer el PDF: ' + e.message);
      continue;
    }
    const seccion = extraerSeccionLaboral(texto);
    if (!seccion) {
      console.log('  No se encontro la seccion de materia laboral en este boletin.');
      procesados.add(b.url);
      continue;
    }
    const casos = extraerCasos(seccion);
    for (const c of casos) {
      const match = porNumero.get(normalizeExp(c.exp));
      if (!match) continue;

      const tribunalSistema = match.tribunal || match.junta;
      const mismoTribunal = tribunalCoincide(c.tribunal, tribunalSistema);
      if (mismoTribunal === false) {
        console.log('  Coincide el numero de expediente ' + c.exp + ' pero es OTRO TRIBUNAL ("' + c.tribunal
          + '" vs "' + tribunalSistema + '") -- se omite.');
        continue;
      }
      // Si el tribunal coincidió con certeza, ya no hace falta el respaldo
      // de nombres; si no se pudo determinar (formato inesperado en algún
      // lado), se exige que las partes coincidan antes de reportar.
      if (mismoTribunal !== true && !pareceElMismoCaso(c.resumen, match.actor, match.demandado)) {
        console.log('  Coincide el numero de expediente ' + c.exp + ' pero no se pudo confirmar tribunal ni '
          + 'partes -- se omite por seguridad. Esperaba "' + match.actor + ' vs ' + match.demandado
          + '", el boletin dice: "' + c.resumen.slice(0, 150) + '..."');
        continue;
      }
      await reportarAviso(match.id, c.resumen, b.fecha);
      reportados++;
      console.log('  Aviso reportado: expediente ' + c.exp);
    }
    procesados.add(b.url);
  }
  guardarProcesados(procesados);
  console.log('Listo. ' + reportados + ' aviso(s) nuevo(s) reportado(s).');
}

main().catch(err => {
  console.error('Error en checker CDMX:', err.message);
  process.exit(1);
});
