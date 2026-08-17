// Robot de monitoreo del Boletin Judicial local del Estado de Mexico
// (Tribunales Laborales, region Tlalnepantla). A diferencia de Federal y
// CDMX, este sitio pide un captcha por cada consulta y bloquea las IPs de
// centros de datos, por eso corre en una computadora de oficina en vez de
// la VPS. Cuando aparece un captcha lo publica en el sistema y espera a
// que alguien del despacho lo resuelva desde la pantalla de Agenda.
const { chromium } = require('playwright');
const fs = require('fs');
const os = require('os');
const path = require('path');
const axios = require('axios');
const pdfParse = require('pdf-parse');
const config = require('./config');

const URL_CONSULTA = 'https://notificacion.pjedomex.gob.mx/notificacion/vista/php/consultaWebBoletinDoc.php';
const REGION_TEXTO = 'TLALNEPANTLA';
const INSTANCIA_TEXTO = 'TRIBUNALES LABORALES';
const JUZGADOS = [
  'PRIMER TRIBUNAL LABORAL DE LA REGION JUDICIAL DE TLALNEPANTLA',
  'SEGUNDO TRIBUNAL LABORAL DE LA REGION JUDICIAL DE TLALNEPANTLA',
  'TERCER TRIBUNAL LABORAL DE LA REGION JUDICIAL DE TLALNEPANTLA',
];

function sinAcentos(s) {
  return (s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase();
}

function normalizeExp(s) {
  return (s || '').replace(/\s+/g, '').toUpperCase();
}

function fechaDDMMYYYY(d) {
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  return dd + '/' + mm + '/' + d.getFullYear();
}

async function obtenerExpedientesMonitoreados() {
  const res = await axios.get(config.sistema.apiBase + '/expedientes_monitorear.php', {
    headers: { 'X-Robot-Key': config.sistema.robotKey },
  });
  return res.data.data.expedientes;
}

async function publicarCaptcha(imagenBuffer, juzgado) {
  const b64 = imagenBuffer.toString('base64');
  const res = await axios.post(config.sistema.apiBase + '/edomex_captcha_publicar.php', {
    imagen_base64: b64,
    juzgado: juzgado,
  }, { headers: { 'X-Robot-Key': config.sistema.robotKey } });
  return res.data.data.id;
}

async function esperarRespuestaCaptcha(id) {
  const limite = Date.now() + 20 * 60 * 1000;
  while (Date.now() < limite) {
    const res = await axios.get(config.sistema.apiBase + '/edomex_captcha_resultado.php?id=' + id, {
      headers: { 'X-Robot-Key': config.sistema.robotKey },
    });
    if (res.data.data && res.data.data.respuesta) return res.data.data.respuesta;
    await new Promise(r => setTimeout(r, 20000));
  }
  throw new Error('Nadie resolvio el captcha a tiempo.');
}

async function reportarAviso(expedienteId, resumen, fecha) {
  await axios.post(config.sistema.apiBase + '/avisos_ingest.php', {
    expediente_id: expedienteId,
    fuente: 'edomex_local',
    resumen,
    fecha_publicacion: fecha,
  }, { headers: { 'X-Robot-Key': config.sistema.robotKey } });
}

async function seleccionarPorTexto(page, contenedor, textoBuscado) {
  const opciones = await page.locator(contenedor + ' option').evaluateAll(
    opts => opts.map(o => ({ value: o.value, text: o.textContent }))
  );
  const buscado = sinAcentos(textoBuscado);
  const encontrada = opciones.find(o => sinAcentos(o.text).includes(buscado));
  if (!encontrada) throw new Error('No se encontro la opcion "' + textoBuscado + '" en ' + contenedor);
  await page.locator(contenedor + ' select').selectOption({ value: encontrada.value });
}

function extraerCasos(texto) {
  const casos = [];
  const re = /(\d+)_\s*(\d+\/\d{4})\s+([^\n\r]*)/g;
  let m;
  while ((m = re.exec(texto))) {
    casos.push({ exp: m[2], resumen: (m[3] || '').replace(/\s+/g, ' ').trim().slice(0, 500) });
  }
  return casos;
}

async function procesarJuzgado(page, juzgadoTexto, porNumero) {
  console.log('--- Juzgado: ' + juzgadoTexto + ' ---');

  await page.goto(URL_CONSULTA, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1000);
  await seleccionarPorTexto(page, '#divCmbRegion', REGION_TEXTO);
  await page.waitForTimeout(1500);
  await seleccionarPorTexto(page, '#divCmbInstancia', INSTANCIA_TEXTO);
  await page.waitForTimeout(1500);
  await seleccionarPorTexto(page, '#divCmbJuzgado', juzgadoTexto);
  await page.waitForTimeout(500);

  let fecha = new Date();
  fecha.setDate(fecha.getDate() - 1);
  let pdfUrl = null;
  let intentos = 0;

  while (intentos < 8 && !pdfUrl) {
    intentos++;
    const fechaTexto = fechaDDMMYYYY(fecha);
    console.log('  Probando fecha ' + fechaTexto + ' (intento ' + intentos + ')...');

    let alertaTemprana = null;
    const capturarDialog = d => { alertaTemprana = d; };
    page.once('dialog', capturarDialog);

    await page.fill('#txtFechaBoletin', fechaTexto);
    await page.locator('#txtFechaBoletin').blur();
    await page.waitForTimeout(2000);

    if (alertaTemprana) {
      const mensaje = alertaTemprana.message();
      await alertaTemprana.accept().catch(() => {});
      console.log('  Aviso al llenar la fecha: "' + mensaje + '"');
      if (/car[aá]tula|existe/i.test(mensaje)) {
        console.log('  Sin boletin ese dia, probando el dia anterior (sin gastar captcha)...');
        fecha.setDate(fecha.getDate() - 1);
        continue;
      }
    } else {
      page.off('dialog', capturarDialog);
      const popupTemprano = page.locator('.swal2-modal, .swal2-popup');
      const haySwalTemprano = (await popupTemprano.count()) > 0 && await popupTemprano.first().isVisible().catch(() => false);
      if (haySwalTemprano) {
        const mensaje = await popupTemprano.first().evaluate(el => el.innerText || el.textContent || '').catch(() => '');
        console.log('  Aviso al llenar la fecha: "' + mensaje.trim() + '"');
        await page.locator('.swal2-confirm').first().click({ force: true }).catch(() => {});
        await page.waitForTimeout(500);
        if (/car[aá]tula|existe/i.test(mensaje)) {
          console.log('  Sin boletin ese dia, probando el dia anterior (sin gastar captcha)...');
          fecha.setDate(fecha.getDate() - 1);
          continue;
        }
      }
    }

    if (intentos > 1) {
      await page.locator('.refresh-captcha').click({ force: true }).catch(() => {});
      await page.waitForTimeout(1000);
    }
    const imagen = await page.locator('img.captcha-image').screenshot();
    const captchaId = await publicarCaptcha(imagen, juzgadoTexto + ' - ' + fechaTexto);
    console.log('  Captcha publicado (id ' + captchaId + '), esperando que alguien lo resuelva en el sistema...');
    const respuesta = await esperarRespuestaCaptcha(captchaId);
    console.log('  Captcha resuelto: ' + respuesta);

    await page.fill('#captcha', respuesta);

    const pdfEsperado = page.waitForResponse(r => r.url().toLowerCase().includes('.pdf'), { timeout: 25000 }).catch(() => null);
    const alertaEsperada = page.waitForEvent('dialog', { timeout: 25000 }).catch(() => null);
    const swalEsperada = page.waitForSelector('.swal2-modal, .swal2-popup', { state: 'visible', timeout: 25000 }).catch(() => null);

    await page.click('#btnConsultar', { force: true });

    const resultado = await Promise.race([pdfEsperado, alertaEsperada, swalEsperada]);

    if (resultado && typeof resultado.url === 'function') {
      pdfUrl = resultado.url();
    } else if (resultado && typeof resultado.accept === 'function') {
      const mensaje = resultado.message();
      await resultado.accept();
      if (/car[aá]tula|existe/i.test(mensaje)) {
        console.log('  Sin boletin ese dia ("' + mensaje + '"), probando el dia anterior...');
        fecha.setDate(fecha.getDate() - 1);
      } else {
        console.log('  Aviso del sitio: "' + mensaje + '" -- reintentando el mismo dia con un nuevo captcha.');
      }
      await page.waitForTimeout(1000);
    } else if (resultado) {
      const mensaje = await resultado.evaluate(el => el.innerText || el.textContent || '').catch(() => '');
      console.log('  Ventana emergente del sitio: "' + mensaje.trim() + '"');
      await page.locator('.swal2-confirm').first().click({ force: true }).catch(() => {});
      await page.waitForTimeout(500);
      if (/car[aá]tula|existe/i.test(mensaje)) {
        console.log('  Sin boletin ese dia, probando el dia anterior...');
        fecha.setDate(fecha.getDate() - 1);
      } else {
        console.log('  Reintentando el mismo dia con un nuevo captcha.');
      }
      await page.waitForTimeout(1000);
    } else {
      console.log('  No hubo respuesta clara, reintentando el mismo dia...');
    }
  }

  if (!pdfUrl) {
    console.log('  No se encontro boletin disponible tras varios intentos para este juzgado.');
    return;
  }

  console.log('  PDF encontrado: ' + pdfUrl);
  const respuestaPdf = await page.context().request.get(pdfUrl);
  const buffer = await respuestaPdf.body();
  const datos = await pdfParse(buffer);
  const texto = datos.text;

  const casos = extraerCasos(texto);
  console.log('  ' + casos.length + ' caso(s) leidos del boletin.');

  let reportados = 0;
  for (const c of casos) {
    const match = porNumero.get(normalizeExp(c.exp));
    if (!match) continue;
    await reportarAviso(match.id, c.resumen, fecha.toISOString().slice(0, 10));
    reportados++;
    console.log('    Aviso reportado: expediente ' + c.exp);
  }
  console.log('  ' + reportados + ' aviso(s) nuevo(s) para este juzgado.');
}

async function main() {
  console.log(new Date().toISOString(), 'Iniciando revision Edomex...');

  const expedientes = await obtenerExpedientesMonitoreados();
  const porNumero = new Map();
  for (const e of expedientes) {
    // Se confía en 'es_federal' para descartar tribunales federales antes
    // de mirar la sede (ver bug de cdmx.js: un tribunal federal puede
    // mencionar una ciudad/región sin ser un tribunal local).
    if (e.es_federal) continue;
    const candidatos = [e.junta, e.tribunal].filter(Boolean);
    const esEdomexLocal = candidatos.some(c => /tlalnepantla/i.test(c));
    if (esEdomexLocal && e.exp) porNumero.set(normalizeExp(e.exp), e);
  }
  console.log(porNumero.size + ' expediente(s) con tribunal local de Edomex (Tlalnepantla) capturado.');

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1300, height: 900 } });

  for (const juzgado of JUZGADOS) {
    try {
      await procesarJuzgado(page, juzgado, porNumero);
    } catch (e) {
      console.log('  Error con juzgado "' + juzgado + '": ' + e.message);
    }
  }

  await browser.close();
  console.log('Listo.');
}

main().catch(err => {
  console.error('Error en checker Edomex:', err.message);
  process.exit(1);
});
