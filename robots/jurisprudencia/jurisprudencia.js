// Robot de monitoreo de tesis y jurisprudencia NUEVA en materia laboral,
// del Semanario Judicial de la Federación (SCJN) -- sjf2.scjn.gob.mx.
// No requiere usuario ni contrasena: es un buscador publico. A diferencia
// de los boletines (que avisan de un expediente concreto), esto alimenta
// una biblioteca local de tesis (jurisprudencia_tesis en el servidor) que
// despues sirve para el buscador de jurisprudencia con IA -- por eso cada
// tesis nueva se guarda con su texto completo, no solo el titulo.
//
// El sitio es una aplicacion moderna (no cambia la URL al buscar), asi que
// usa un navegador automatizado como el robot de Edomex, no peticiones
// HTTP simples como el de CDMX. Pensado para correr una vez por semana.
//
// IMPORTANTE -- primera corrida: es muy probable que algun selector no
// funcione a la primera (el sitio nunca se probo en vivo desde aqui,
// solo se revisaron capturas de pantalla). Si truena, manda el mensaje
// de error completo para ajustarlo.
const { chromium } = require('playwright');
const axios = require('axios');
const config = require('./config');

const BASE = 'https://sjf2.scjn.gob.mx';
const URL_BUSQUEDA = BASE + '/busqueda-principal-tesis';
const ESTADO_FILE = require('path').join(__dirname, 'procesados_jurisprudencia.json');
const fs = require('fs');

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

// Convierte "viernes 14 de agosto de 2026 10:22 h" (o variantes sin hora)
// a 'YYYY-MM-DD'. Devuelve null si no reconoce el formato.
const MESES = {
  enero: '01', febrero: '02', marzo: '03', abril: '04', mayo: '05', junio: '06',
  julio: '07', agosto: '08', septiembre: '09', octubre: '10', noviembre: '11', diciembre: '12',
};
function parseFechaPublicacion(texto) {
  const m = /(\d{1,2})\s+de\s+([a-záéíóú]+)\s+de\s+(\d{4})/i.exec(texto || '');
  if (!m) return null;
  const mes = MESES[m[2].toLowerCase()];
  if (!mes) return null;
  return m[3] + '-' + mes + '-' + m[1].padStart(2, '0');
}

async function aplicarFiltroMateriaLaboral(page) {
  // El panel de filtros a la izquierda tiene un acordeon "Materia" -- se
  // abre, se marca la casilla "Laboral", y el listado se filtra solo.
  await page.getByText('Materia', { exact: true }).click();
  await page.waitForTimeout(800);
  await page.getByText('Laboral', { exact: true }).click();
  await page.waitForTimeout(1500);
}

async function buscarTesisRecientes(page) {
  await page.goto(URL_BUSQUEDA, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);

  // Intenta buscar sin escribir nada (para traer TODO, filtrado despues
  // solo por materia) -- si el sitio exige texto, esto puede no traer
  // resultados; en ese caso el aviso de la corrida lo va a dejar ver.
  const botonBuscar = page.locator('button:has(svg), [role="button"]').filter({ hasText: '' }).first();
  try {
    await page.getByPlaceholder(/Introduzca alguna palabra/i).press('Enter');
  } catch (e) {
    console.log('No se pudo enviar la busqueda vacia con Enter: ' + e.message);
  }
  await page.waitForTimeout(2000);

  await aplicarFiltroMateriaLaboral(page);

  // Ordena por fecha de publicacion mas reciente -- ya suele venir asi por
  // default, pero se fuerza por si acaso.
  try {
    await page.locator('text=/Ordenar por/i').locator('..').locator('select, [role="combobox"]').first().click();
    await page.waitForTimeout(500);
    await page.getByText(/Fecha de publicaci[oó]n \(reciente/i).click();
    await page.waitForTimeout(1500);
  } catch (e) {
    console.log('No se pudo forzar el orden por fecha reciente (puede que ya viniera asi): ' + e.message);
  }

  const tarjetas = page.locator('text=/Registro digital:\\s*\\d+/');
  const total = await tarjetas.count();
  console.log(total + ' resultado(s) visibles en la primera pagina.');

  const resultados = [];
  for (let i = 0; i < total; i++) {
    const bloque = tarjetas.nth(i).locator('..');
    const texto = await bloque.innerText().catch(() => '');
    const mReg = /Registro digital:\s*(\d+)/.exec(texto);
    if (!mReg) continue;
    const registro = parseInt(mReg[1], 10);
    const lineas = texto.split('\n').map(l => l.trim()).filter(Boolean);
    // La primera linea suele ser "N. Registro digital: NNNNN" y la
    // siguiente (o las siguientes hasta la linea de metadatos) es el
    // rubro/titulo de la tesis.
    const idxReg = lineas.findIndex(l => /Registro digital:/.test(l));
    const idxMeta = lineas.findIndex(l => /Publicaci[oó]n:/.test(l));
    const rubro = idxReg >= 0 && idxMeta > idxReg
      ? lineas.slice(idxReg + 1, idxMeta).join(' ')
      : (lineas[idxReg + 1] || '');
    const metaLinea = idxMeta >= 0 ? lineas[idxMeta] : '';
    const fecha = parseFechaPublicacion(metaLinea);
    resultados.push({ registro, rubro, fecha });
  }
  return resultados;
}

async function obtenerDetalleTesis(page, registro) {
  await page.goto(BASE + '/detalle/tesis/' + registro, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1000);
  const texto = await page.locator('body').innerText();

  const instancia = /Instancia:\s*([^\n]+)/.exec(texto)?.[1]?.trim() || '';
  const epoca = /(\S+\s+[EÉ]poca)/.exec(texto)?.[1]?.trim() || '';
  const numeroTesis = /Tesis:\s*([^\n]+)/.exec(texto)?.[1]?.trim() || '';
  const materias = /Materia\(s\):\s*([^\n]+)/.exec(texto)?.[1]?.trim() || '';
  const tipo = /Tipo:\s*([^\n]+)/.exec(texto)?.[1]?.trim() || '';

  return { instancia, epoca, numero_tesis: numeroTesis, materias, tipo, texto_completo: texto };
}

async function reportarTesis(lote) {
  await axios.post(config.sistema.apiBase + '/jurisprudencia_ingest.php', { tesis: lote }, {
    headers: { 'X-Robot-Key': config.sistema.robotKey },
  });
}

async function main() {
  console.log(new Date().toISOString(), 'Iniciando revision de jurisprudencia laboral...');
  const procesados = cargarProcesados();

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  try {
    const resultados = await buscarTesisRecientes(page);
    const nuevos = resultados.filter(r => r.registro && !procesados.has(r.registro));
    console.log(nuevos.length + ' tesis nueva(s) sin procesar de ' + resultados.length + ' revisadas.');

    const lote = [];
    for (const r of nuevos) {
      console.log('  Descargando detalle de la tesis ' + r.registro + '...');
      try {
        const detalle = await obtenerDetalleTesis(page, r.registro);
        lote.push({
          registro_digital: r.registro,
          rubro: r.rubro,
          fecha_publicacion: r.fecha,
          ...detalle,
        });
        procesados.add(r.registro);
      } catch (e) {
        console.log('    No se pudo leer el detalle: ' + e.message);
      }
    }

    if (lote.length) {
      await reportarTesis(lote);
      console.log(lote.length + ' tesis nueva(s) guardada(s) en la biblioteca.');
    } else {
      console.log('Nada nuevo que guardar esta vez.');
    }
    guardarProcesados(procesados);
  } finally {
    await browser.close();
  }
  console.log('Listo.');
}

main().catch(err => {
  console.error('Error en checker de jurisprudencia:', err.message);
  process.exit(1);
});
