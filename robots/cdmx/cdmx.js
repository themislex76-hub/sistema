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

function extraerCasos(seccionTexto) {
  const partes = seccionTexto.split(/(?<=N[uú]m\.\s*Exp\.\s*\d+\/\d{4}\.)/);
  const casos = [];
  for (const parte of partes) {
    const m = /N[uú]m\.\s*Exp\.\s*(\d+\/\d{4})\./.exec(parte);
    if (!m) continue;
    const resumen = parte.replace(/\s+/g, ' ').trim().slice(-500);
    casos.push({ exp: m[1], resumen });
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
