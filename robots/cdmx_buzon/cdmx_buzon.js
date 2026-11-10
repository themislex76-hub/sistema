// Robot de monitoreo del Buzón Electrónico Laboral del Poder Judicial de la
// Ciudad de México (buzonelectronicolaboral.poderjudicialcdmx.gob.mx:2083).
//
// Complementa (NO reemplaza) al robot viejo de boletín público
// (robots/cdmx/cdmx.js, que se queda intacto) -- este portal es mucho más
// confiable: cada cuenta (correo + contraseña, una por abogado -- cada
// abogado tiene o debe tener la suya, confirmado por el usuario 9 nov 2026)
// ve directo la lista de expedientes donde ese abogado está dado de alta
// como apoderado ("Consulta de expedientes" -> "LISTA DE BUZONES"), con un
// botón "Ver Acuerdos" por expediente que abre la lista completa de
// acuerdos y liga directa para descargar el PDF de cada uno -- sin
// necesidad de "adivinar" si un caso coincide por nombre de las partes,
// como sí hacía que hacer el robot de boletín viejo.
//
// IMPORTANTE -- primera versión, nunca se ha corrido en vivo desde esta
// sesión (sin acceso a internet externo aquí): se construyó viendo
// capturas de pantalla reales del portal (login, lista de buzones, modal
// de acuerdos, PDF de un acuerdo real abierto). Es muy probable que algún
// selector no funcione a la primera -- sobre todo la paginación de "LISTA
// DE BUZONES" (los botones «/‹/›/» son iconos, no texto, así que se
// localizan por posición, lo más frágil de este archivo) y la descarga del
// PDF al darle clic a "Ver acuerdo" (puede abrir pestaña nueva o disparar
// una descarga -- se escuchan los dos casos). Si truena, manda el mensaje
// de error COMPLETO de la terminal y el archivo debug_error_<cuenta>.png
// que se guarda en esta misma carpeta -- con eso se ajusta rápido, no se
// puede adivinar el selector exacto sin eso.
const { chromium } = require('playwright');
const axios = require('axios');
const fs = require('fs');
const path = require('path');
const config = require('./config');

const BASE = 'https://buzonelectronicolaboral.poderjudicialcdmx.gob.mx:2083';
const ESTADO_FILE = path.join(__dirname, 'procesados_cdmx_buzon.json');

function cargarProcesados() {
  try {
    return JSON.parse(fs.readFileSync(ESTADO_FILE, 'utf8'));
  } catch (e) {
    return {};
  }
}
function guardarProcesados(obj) {
  fs.writeFileSync(ESTADO_FILE, JSON.stringify(obj, null, 2));
}

// El Buzón muestra el expediente como "982/2025" tal cual, pero en el
// sistema puede estar capturado con un prefijo propio del despacho (ej.
// "CDMX/CI/163249/2026", "ECA/06571/2026") -- en vez de exigir que el
// formato completo coincida, se compara solo el ÚLTIMO tramo "NNN/AAAA"
// (sin ceros a la izquierda), que es el número real de expediente en
// ambos lados.
function normalizeExp(s) {
  const limpio = (s || '').replace(/\s+/g, '');
  const m = /(\d+)\/(\d{4})(?!.*\d+\/\d{4})/.exec(limpio); // último NNN/AAAA de la cadena
  if (!m) return limpio.toUpperCase();
  return String(parseInt(m[1], 10)) + '/' + m[2];
}

function sanitizarParaArchivo(s) {
  return (s || '').replace(/[\/\\]/g, '-');
}

function pdfEsValido(buf) {
  return !!buf && buf.length > 4 && buf.slice(0, 4).toString('latin1') === '%PDF';
}

async function obtenerExpedientesMonitoreados() {
  const res = await axios.get(`${config.sistema.apiBase}/expedientes_monitorear.php`, {
    headers: { 'X-Robot-Key': config.sistema.robotKey },
  });
  return res.data.data.expedientes;
}

async function obtenerCuentasCdmxBuzon() {
  const res = await axios.get(`${config.sistema.apiBase}/cdmx_buzon_credenciales_listar.php`, {
    headers: { 'X-Robot-Key': config.sistema.robotKey },
  });
  return res.data.data.cuentas;
}

async function subirPdfAlSistema(buffer, expedienteIdSistema, nombreArchivo) {
  try {
    const res = await axios.post(`${config.sistema.apiBase}/documentos_subir_robot.php`, {
      expediente_id: expedienteIdSistema,
      nombre_archivo: nombreArchivo,
      contenido_base64: buffer.toString('base64'),
      categoria: 'acuerdo',
    }, { headers: { 'X-Robot-Key': config.sistema.robotKey } });
    return res.data.data.id;
  } catch (e) {
    console.log(`      (no se pudo subir el PDF al sistema: ${e.message})`);
    return null;
  }
}

async function reportarAviso(expedienteId, resumen, fecha, documentoId) {
  await axios.post(`${config.sistema.apiBase}/avisos_ingest.php`, {
    expediente_id: expedienteId,
    fuente: 'cdmx_local',
    resumen,
    fecha_publicacion: fecha,
    documento_id: documentoId || undefined,
  }, { headers: { 'X-Robot-Key': config.sistema.robotKey } });
}

// Mismo problema ya documentado en los otros robots: un texto puede existir
// duplicado en el DOM, o aparecer un instante después de lo que isVisible()
// cree -- se espera de verdad a que exista al menos un candidato visible.
async function clickTextoVisible(page, texto, exact = false, timeoutMs = 15000) {
  const candidatos = page.getByText(texto, { exact });
  await candidatos.first().waitFor({ state: 'attached', timeout: timeoutMs }).catch(() => {});
  const n = await candidatos.count();
  for (let i = 0; i < n; i++) {
    const el = candidatos.nth(i);
    const visible = await el.waitFor({ state: 'visible', timeout: timeoutMs }).then(() => true).catch(() => false);
    if (visible) {
      await el.click({ timeout: timeoutMs }).catch(() => {});
      return true;
    }
  }
  return false;
}

// El link "Ver acuerdo" puede abrir una pestaña nueva con el PDF, o
// disparar una descarga directa -- se escuchan los dos antes de hacer
// clic, igual que ya se resolvió para el robot Federal con el portal del
// PJF.
async function descargarPdfDesdeLink(context, page, locatorLink) {
  const nuevaPaginaPromise = context.waitForEvent('page', { timeout: 20000 }).catch(() => null);
  const descargaPromise = page.waitForEvent('download', { timeout: 20000 }).catch(() => null);
  await locatorLink.click({ timeout: 15000 }).catch(() => {});
  const [nuevaPagina, descarga] = await Promise.all([nuevaPaginaPromise, descargaPromise]);

  if (descarga) {
    const rutaTemp = await descarga.path().catch(() => null);
    if (rutaTemp) return fs.readFileSync(rutaTemp);
  }
  if (nuevaPagina) {
    await nuevaPagina.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
    try {
      const resp = await nuevaPagina.request.get(nuevaPagina.url());
      const buf = await resp.body();
      await nuevaPagina.close().catch(() => {});
      return buf;
    } catch (e) {
      await nuevaPagina.close().catch(() => {});
    }
  }
  return null;
}

// "LISTA DE BUZONES": cada fila trae el expediente (tribunal + número),
// las partes, y el link "Ver Acuerdos". Se ubica cada link "Ver Acuerdos"
// y se sube por el DOM hasta la fila (<tr>) que lo contiene, para sacar el
// texto completo de esa fila y extraer el número de expediente.
async function leerFilasBuzones(page) {
  const links = page.locator('text=/Ver Acuerdos/i');
  const n = await links.count();
  const filas = [];
  for (let i = 0; i < n; i++) {
    const link = links.nth(i);
    const fila = link.locator('xpath=ancestor::tr[1]');
    const textoFila = await fila.innerText().catch(() => '');
    const matchExp = /(\d+\/\d{4})/.exec(textoFila);
    if (!matchExp) continue;
    filas.push({ expTexto: matchExp[1], textoCompleto: textoFila, linkVerAcuerdos: link });
  }
  return filas;
}

// Paginación con iconos («/‹/›/», sin texto) -- se localizan los botones
// por posición dentro del contenedor que trae el texto "Página X de Y".
// Esto es lo más frágil del archivo (ver nota al principio): si deja de
// avanzar de página, confirmar aquí primero con una captura real del HTML.
async function avanzarPagina(page) {
  const textoPagina = await page.getByText(/Página \d+ de \d+/i).first().innerText().catch(() => null);
  if (!textoPagina) return false;
  const m = /Página (\d+) de (\d+)/i.exec(textoPagina);
  if (!m) return false;
  const actual = parseInt(m[1], 10);
  const total = parseInt(m[2], 10);
  if (actual >= total) return false;

  const contenedor = page.getByText(/Página \d+ de \d+/i).first().locator('xpath=..');
  const botones = contenedor.locator('button');
  const nBotones = await botones.count();
  if (nBotones < 2) return false;
  // Orden visto en pantalla: « ‹ (Página X de Y) › » -- el botón "siguiente"
  // es el que sigue inmediatamente después del texto de la página.
  await botones.nth(Math.floor(nBotones / 2)).click({ timeout: 10000 }).catch(() => {});
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(500);
  return true;
}

async function procesarCuenta(cuenta, porNumero, estado, browser) {
  console.log(`--- Cuenta: ${cuenta.nombre} (${cuenta.cdmx_buzon_usuario}) ---`);
  const context = await browser.newContext();
  const page = await context.newPage();
  page.on('pageerror', (err) => console.log(`    [error de JS en la página] ${err.message}`));
  let reportados = 0;
  const vistos = estado[cuenta.cdmx_buzon_usuario] || {};

  try {
    await page.goto(BASE, { waitUntil: 'networkidle', timeout: 30000 });
    const campoUsuario = page.locator('input[type="email"], input[type="text"]').first();
    const campoPassword = page.locator('input[type="password"]').first();
    await campoUsuario.waitFor({ state: 'visible', timeout: 15000 });
    await campoUsuario.fill(cuenta.cdmx_buzon_usuario);
    await campoPassword.fill(cuenta.cdmx_buzon_password);
    const entroPorBoton = await clickTextoVisible(page, 'ENTRAR', false, 10000);
    if (!entroPorBoton) await campoPassword.press('Enter').catch(() => {});
    await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});

    // Confirmado real (10 nov 2026): saltar directo con page.goto() a la URL
    // de "Consulta de expedientes" a veces pierde la sesión recién iniciada
    // (el portal regresa "Error 102 Por favor inicie sesión", típico de una
    // app que guarda la sesión en memoria del navegador en vez de solo en
    // una cookie -- una navegación completa la reinicia). Se navega dándole
    // clic al menú, como lo haría una persona, para no perderla.
    const entroMenu = await clickTextoVisible(page, 'Consulta de expedientes', false, 15000);
    if (!entroMenu) {
      console.log('  AVISO: no se encontró el menú "Consulta de expedientes" -- el login probablemente falló (revisa usuario/contraseña guardados en "Mi cuenta").');
      await page.screenshot({ path: path.join(__dirname, `debug_error_${sanitizarParaArchivo(cuenta.cdmx_buzon_usuario)}.png`), fullPage: true }).catch(() => {});
      return 0;
    }
    await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});

    // La tabla de "LISTA DE BUZONES" se ve armada por una librería (tipo
    // DataTables) que a veces sigue inicializándose un instante después de
    // que la página ya se ve lista -- leerla demasiado pronto puede dar
    // menos filas de las reales o pisarse con un error de JS de esa
    // librería (confirmado real: "Cannot set properties of undefined
    // (setting '_DT_CellIndex')"). Se espera a que el número de filas se
    // quede estable entre 2 lecturas antes de confiar en la lista.
    const hayLista = await page.getByText(/LISTA DE BUZONES/i).first().waitFor({ state: 'visible', timeout: 15000 }).then(() => true).catch(() => false);
    if (!hayLista) {
      console.log('  AVISO: no apareció "LISTA DE BUZONES" -- el login pudo fallar, o el portal cambió.');
      await page.screenshot({ path: path.join(__dirname, `debug_error_${sanitizarParaArchivo(cuenta.cdmx_buzon_usuario)}.png`), fullPage: true }).catch(() => {});
      return 0;
    }
    let conteoAnterior = -1;
    for (let intento = 0; intento < 6; intento++) {
      const conteoActual = await page.locator('text=/Ver Acuerdos/i').count();
      if (conteoActual > 0 && conteoActual === conteoAnterior) break;
      conteoAnterior = conteoActual;
      await page.waitForTimeout(800);
    }

    let pagina = 1;
    let seguir = true;
    while (seguir) {
      console.log(`  Página ${pagina} de "LISTA DE BUZONES"...`);
      const filas = await leerFilasBuzones(page);
      console.log(`    ${filas.length} expediente(s) en esta página.`);

      for (const fila of filas) {
        const expNormalizado = normalizeExp(fila.expTexto);
        const expedienteSistema = porNumero.get(expNormalizado);
        if (!expedienteSistema) continue; // no es un expediente monitoreado en el sistema

        console.log(`    Expediente ${fila.expTexto} (Exp. sistema ${expedienteSistema.exp}, id ${expedienteSistema.id}) -- revisando acuerdos...`);
        await fila.linkVerAcuerdos.click({ timeout: 15000 }).catch(() => {});
        const hayModal = await page.getByText(/LISTA DE ACUERDOS/i).first().waitFor({ state: 'visible', timeout: 15000 }).then(() => true).catch(() => false);
        if (!hayModal) {
          console.log('      (no se abrió la lista de acuerdos -- se omite este expediente en esta corrida)');
          continue;
        }

        const filasAcuerdo = page.locator('text=/Ver acuerdo/i');
        const nAcuerdos = await filasAcuerdo.count();
        for (let i = 0; i < nAcuerdos; i++) {
          const linkVerAcuerdo = filasAcuerdo.nth(i);
          const filaAcuerdo = linkVerAcuerdo.locator('xpath=ancestor::tr[1]');
          const textoFilaAcuerdo = await filaAcuerdo.innerText().catch(() => '');
          const matchId = /(\d+\/\d{4}-\d+)/.exec(textoFilaAcuerdo);
          const matchFecha = /(\d{4}-\d{2}-\d{2})/.exec(textoFilaAcuerdo);
          if (!matchId) continue;
          const acuerdoId = matchId[1];
          const fechaPublicacion = matchFecha ? matchFecha[1] : null;

          if (vistos[acuerdoId]) continue; // ya reportado en una corrida anterior

          console.log(`      Acuerdo nuevo: ${acuerdoId} (${fechaPublicacion || 'sin fecha'})`);
          const pdfBuffer = await descargarPdfDesdeLink(context, page, linkVerAcuerdo);
          let documentoId = null;
          if (pdfEsValido(pdfBuffer)) {
            documentoId = await subirPdfAlSistema(pdfBuffer, expedienteSistema.id, `Acuerdo_${sanitizarParaArchivo(acuerdoId)}.pdf`);
          } else {
            console.log('      (no se pudo descargar un PDF válido para este acuerdo -- se reporta el aviso sin documento adjunto)');
          }

          try {
            await reportarAviso(
              expedienteSistema.id,
              `Acuerdo ${acuerdoId} del expediente ${expedienteSistema.exp || fila.expTexto}.`,
              fechaPublicacion,
              documentoId
            );
            reportados++;
          } catch (e) {
            console.log(`      (no se pudo reportar el aviso: ${e.message})`);
          }

          vistos[acuerdoId] = true;
          estado[cuenta.cdmx_buzon_usuario] = vistos;
          guardarProcesados(estado); // se guarda en cada acuerdo, no solo al final -- mismo criterio que edomex_correo.js
        }

        // Cierra el modal antes de seguir con el siguiente expediente de
        // la lista (el ícono "×" visto en la captura real).
        await clickTextoVisible(page, '×', true, 5000).catch(() => {});
        await page.waitForTimeout(300);
      }

      seguir = await avanzarPagina(page);
      if (seguir) pagina++;
    }
  } catch (e) {
    console.log(`  Error con la cuenta de ${cuenta.nombre}: ${e.message}`);
    await page.screenshot({ path: path.join(__dirname, `debug_error_${sanitizarParaArchivo(cuenta.cdmx_buzon_usuario)}.png`), fullPage: true }).catch(() => {});
  } finally {
    await context.close();
  }
  console.log(`  ${reportados} aviso(s) nuevo(s) para esta cuenta.`);
  return reportados;
}

async function main() {
  console.log(new Date().toISOString(), 'Iniciando revisión del Buzón Electrónico Laboral (CDMX)...');

  const expedientes = await obtenerExpedientesMonitoreados();
  const porNumero = new Map();
  for (const e of expedientes) {
    if (e.es_federal) continue; // el Buzón solo cubre tribunales laborales LOCALES de CDMX
    if (!e.exp) continue;
    porNumero.set(normalizeExp(e.exp), e);
  }
  console.log(`${porNumero.size} expediente(s) con número de expediente capturado (no federal).`);

  const cuentas = await obtenerCuentasCdmxBuzon();
  console.log(`${cuentas.length} cuenta(s) del Buzón Electrónico Laboral guardada(s).`);
  if (!cuentas.length) {
    console.log('Nadie ha guardado su cuenta del Buzón Electrónico Laboral en "Mi cuenta" -- nada que revisar.');
    return;
  }

  const estado = cargarProcesados();
  const browser = await chromium.launch({ headless: true });
  let totalReportados = 0;
  for (const cuenta of cuentas) {
    try {
      totalReportados += await procesarCuenta(cuenta, porNumero, estado, browser);
    } catch (e) {
      console.log(`Error con la cuenta de ${cuenta.nombre}: ${e.message}`);
    }
  }
  await browser.close();

  console.log(`Listo. ${totalReportados} aviso(s) nuevo(s) reportado(s) en total.`);
}

main().catch(err => {
  console.error('Error en el robot del Buzón Electrónico Laboral (CDMX):', err.message);
  process.exit(1);
});
