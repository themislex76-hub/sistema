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

// Match preciso pedido por el usuario (10 nov 2026): el número de
// expediente solo es único DENTRO de cada tribunal, no en todo CDMX -- dos
// tribunales distintos pueden traer el mismo "982/2025" por coincidencia.
// Por eso no basta con que el número coincida: también se exige que el
// NÚMERO ORDINAL del tribunal coincida (ej. "6" en "6 LABORAL DE ASUNTOS
// INDIVIDUALES DE LA CDMX" del Buzón debe ser el mismo "6"/"Sexto" que el
// Tribunal capturado en el sistema). El usuario se encarga de que todos
// los expedientes tengan Tribunal y número capturados -- si a alguno le
// falta, o el ordinal no coincide, el robot lo omite y lo dice en el log
// (en vez de adivinar o aplicar un match dudoso).
const ORDINALES_TEXTO = {
  PRIMERO: 1, PRIMER: 1, SEGUNDO: 2, TERCERO: 3, TERCER: 3, CUARTO: 4, QUINTO: 5,
  SEXTO: 6, SEPTIMO: 7, SÉPTIMO: 7, OCTAVO: 8, NOVENO: 9, DECIMO: 10, DÉCIMO: 10,
  DECIMOPRIMERO: 11, DECIMOPRIMER: 11, DECIMOSEGUNDO: 12, DECIMOTERCERO: 13, DECIMOTERCER: 13,
  DECIMOCUARTO: 14, DECIMOQUINTO: 15, DECIMOSEXTO: 16, DECIMOSEPTIMO: 17, DECIMOSÉPTIMO: 17,
  DECIMOCTAVO: 18, DECIMONOVENO: 19, VIGESIMO: 20, VIGÉSIMO: 20,
};
function sinAcentos(s) {
  return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase();
}
function extraerOrdinal(texto) {
  if (!texto) return null;
  const limpio = sinAcentos(texto).trim();
  const matchNumero = /^(\d+)\b/.exec(limpio);
  if (matchNumero) return parseInt(matchNumero[1], 10);
  for (const palabra of limpio.split(/[^A-ZÑ]+/)) {
    if (ORDINALES_TEXTO[palabra]) return ORDINALES_TEXTO[palabra];
  }
  return null;
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
    // El nombre del Tribunal es el texto de la columna "EXPEDIENTE" ANTES
    // del número (ej. "7 LABORAL DE ASUNTOS INDIVIDUALES DE LA CDMX" antes
    // de "982/2025") -- se usa para llenar solo el Tribunal en el sistema
    // cuando el expediente no lo tenía capturado (ver actualizarTribunalSiFalta()).
    const tribunalTexto = textoFila.slice(0, matchExp.index).replace(/\s+/g, ' ').trim();
    filas.push({ expTexto: matchExp[1], tribunalTexto, textoCompleto: textoFila, linkVerAcuerdos: link });
  }
  return filas;
}

// Paginación con iconos («/‹/›/», sin texto). Confirmado real con F12 (10
// nov 2026): NO son <button> (por eso las versiones anteriores que subían
// por los ancestros buscando "button" siempre encontraban 0) -- son <a
// class="page-link"> con nombre de accesibilidad en INGLÉS aunque el resto
// de la pantalla esté en español: "Next" para ›, "Last" para ». Se usa
// el rol de accesibilidad directo, sin adivinar posición.
async function avanzarPagina(page) {
  const textoPagina = await page.getByText(/Página \d+ de \d+/i).first().innerText().catch(() => null);
  if (!textoPagina) {
    console.log('      [paginación] no se encontró el texto "Página X de Y" -- no se puede avanzar.');
    return false;
  }
  const m = /Página (\d+) de (\d+)/i.exec(textoPagina);
  if (!m) {
    console.log(`      [paginación] el texto encontrado no tiene el formato esperado: "${textoPagina}".`);
    return false;
  }
  const actual = parseInt(m[1], 10);
  const total = parseInt(m[2], 10);
  if (actual >= total) {
    console.log(`      [paginación] ya está en la última página (${actual} de ${total}).`);
    return false;
  }

  // Confirmado real (10 nov 2026): puede haber más de un elemento con este
  // mismo nombre de accesibilidad en el DOM (2 vistos en una corrida real),
  // y el primero visible no siempre es el que de verdad mueve la tabla (el
  // clic no tira error -- el elemento existe y es clicable -- pero el
  // texto "Página X de Y" no cambia). En vez de apostarle a uno solo, se
  // prueban todos los visibles en orden hasta que alguno sí tenga efecto.
  const candidatos = page.getByRole('link', { name: 'Next', exact: true });
  const nCandidatos = await candidatos.count();
  console.log(`      [paginación] "${textoPagina}" -- ${nCandidatos} enlace(s) "Next" en el DOM.`);
  if (!nCandidatos) {
    console.log('      [paginación] no se encontró ningún enlace con nombre de accesibilidad "Next".');
    return false;
  }

  for (let i = 0; i < nCandidatos; i++) {
    const cand = candidatos.nth(i);
    const visible = await cand.isVisible().catch(() => false);
    const caja = await cand.boundingBox().catch(() => null);
    console.log(`      [paginación] candidato ${i}: visible=${visible}${caja ? ` x=${Math.round(caja.x)} y=${Math.round(caja.y)}` : ''}`);
    if (!visible) continue;

    await cand.scrollIntoViewIfNeeded().catch(() => {});
    await cand.click({ timeout: 10000 }).catch((e) => console.log(`      [paginación] el clic al candidato ${i} falló: ${e.message}`));

    // El cambio de página aquí es del lado del cliente (no siempre dispara
    // una espera de red) -- en vez de una sola revisión a tiempo fijo, se
    // reintenta varias veces por si el re-render tarda.
    let textoPaginaDespues = textoPagina;
    for (let intento = 0; intento < 6; intento++) {
      await page.waitForTimeout(600);
      textoPaginaDespues = await page.getByText(/Página \d+ de \d+/i).first().innerText().catch(() => textoPagina);
      if (textoPaginaDespues !== textoPagina) break;
    }
    if (textoPaginaDespues !== textoPagina) {
      console.log(`      [paginación] avanzó con el candidato ${i}: ahora dice "${textoPaginaDespues}".`);
      return true;
    }
    console.log(`      [paginación] el candidato ${i} no tuvo efecto.`);
  }

  console.log('      [paginación] ningún candidato "Next" avanzó de página de verdad.');
  return false;
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
    await page.waitForTimeout(1500); // margen fijo antes de empezar a contar, para no agarrar el primer render a medias
    let conteoAnterior = -1;
    for (let intento = 0; intento < 8; intento++) {
      const conteoActual = await page.locator('text=/Ver Acuerdos/i').count();
      if (conteoActual > 0 && conteoActual === conteoAnterior) break;
      conteoAnterior = conteoActual;
      await page.waitForTimeout(900);
    }
    console.log(`    (${conteoAnterior} fila(s) detectada(s) después de esperar a que se estabilizara la tabla)`);

    let pagina = 1;
    let seguir = true;
    while (seguir) {
      console.log(`  Página ${pagina} de "LISTA DE BUZONES"...`);
      const filas = await leerFilasBuzones(page);
      console.log(`    ${filas.length} expediente(s) en esta página.`);

      for (const fila of filas) {
        const expNormalizado = normalizeExp(fila.expTexto);
        const candidatos = porNumero.get(expNormalizado);
        if (!candidatos || !candidatos.length) continue; // no es un expediente monitoreado en el sistema

        const ordinalBuzon = extraerOrdinal(fila.tribunalTexto);
        if (ordinalBuzon === null) {
          console.log(`    Expediente ${fila.expTexto}: coincide el número pero no se pudo leer el ordinal del Tribunal en el Buzón ("${fila.tribunalTexto}") -- se omite por seguridad.`);
          continue;
        }

        const expedienteSistema = candidatos.find(c => extraerOrdinal(c.tribunal || c.junta) === ordinalBuzon);
        if (!expedienteSistema) {
          const vistos = candidatos.map(c => `"${c.tribunal || c.junta || '(sin Tribunal capturado)'}"`).join(', ');
          console.log(`    Expediente ${fila.expTexto}: el número coincide con ${candidatos.length} expediente(s) del sistema, pero ninguno tiene el Tribunal "${fila.tribunalTexto}" (ordinal ${ordinalBuzon}) -- Tribunal(es) capturado(s): ${vistos}. Se omite; captura/corrige el Tribunal en el sistema para que cruce.`);
          continue;
        }

        console.log(`    Expediente ${fila.expTexto} (Exp. sistema ${expedienteSistema.exp}, id ${expedienteSistema.id}) -- Tribunal confirmado, revisando acuerdos...`);
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
  // Mapea a un ARREGLO de candidatos por número (no un solo expediente) --
  // el número de expediente solo es único DENTRO de cada tribunal, así que
  // dos tribunales distintos pueden compartir "982/2025" por coincidencia;
  // el match final se desempata por el ordinal del Tribunal (ver
  // extraerOrdinal() y el ciclo principal más abajo).
  const porNumero = new Map();
  for (const e of expedientes) {
    if (e.es_federal) continue; // el Buzón solo cubre tribunales laborales LOCALES de CDMX
    if (!e.exp) continue;
    const key = normalizeExp(e.exp);
    if (!porNumero.has(key)) porNumero.set(key, []);
    porNumero.get(key).push(e);
  }
  console.log(`${porNumero.size} número(s) de expediente capturado(s) (no federal).`);

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
