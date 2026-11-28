// Robot de monitoreo del Portal de Servicios en Línea del Poder Judicial de
// la Federación (Tribunales Laborales). Revisa la cuenta de CADA abogado
// del despacho que haya guardado su usuario/contraseña del portal desde
// "Mi cuenta" (ver api/pjf_credenciales_*.php) -- el portal solo muestra
// los acuerdos de los expedientes donde el usuario logueado aparece como
// autorizado, por eso hace falta revisar una cuenta a la vez. Reporta al
// sistema los acuerdos que correspondan a expedientes con tribunal federal
// capturado. Pensado para correr una vez al día vía cron -- ver README.md.
//
// REESCRITO (8 nov 2026): el Portal cambió por completo la forma de ver
// acuerdos -- ya no existe la pantalla vieja "Acuerdos Recientes/
// Pendientes" que el robot usaba (confirmado con una corrida real: el
// login funcionaba pero esas tablas regresaban 0 filas siempre). Ahora hay
// que entrar a "Consulta de Expediente Electrónico", que lista los
// expedientes autorizados, y abrir cada uno (botón "Ver Expediente
// Electrónico") para ver su tabla de acuerdos -- es una pantalla moderna
// que carga por JavaScript, así que esta vez se usa un navegador
// automatizado (Playwright, igual que el robot de jurisprudencia) en vez
// de peticiones HTTP simples.
//
// IMPORTANTE -- primera corrida real: esto se construyó viendo capturas de
// pantalla del portal, nunca se probó en vivo desde aquí (sin acceso a
// internet en esta sesión). Es muy probable que algún selector no
// funcione a la primera. Si truena, manda el mensaje de error COMPLETO de
// la terminal y, si se generó, el archivo debug_error_<cuenta>.png que se
// guarda en esta misma carpeta -- con eso se ajusta rápido.
//
// AGREGADO (8 nov 2026): el Portal de Servicios en Línea en realidad tiene
// DOS entidades separadas detrás de la misma pantalla de login --
// "Tribunales Laborales" (donde vive el juicio laboral) y "Juzgados de
// Distrito y Tribunales de Circuito" (donde se resuelven los amparos
// directos/indirectos contra la sentencia laboral) -- cada una es una
// sesión/cookie DISTINTA que solo ve SU propia "Vista Global" de
// expedientes, aunque el resto de la pantalla (menú, tabla, modal de
// documento) es idéntico. Confirmado con una captura real de la petición
// de login del navegador: cambia el campo EntidadExterna ("Laboral" vs
// "SinEntidad") y el campo tipo ("3" vs "1") -- ver SESIONES más abajo.
// Por eso cada cuenta ahora se revisa DOS veces, una por cada sesión.
// Sin confirmar todavía con una corrida real completa (sí se confirmó en
// vivo, con el navegador normal del despacho, que esa sección SÍ lista
// los amparos reales del despacho con esas mismas columnas) -- si el
// login de la sesión "colegiados" falla, el payload real también traía
// un campo "ip" que el login de Laboral nunca manda; probar agregándolo
// antes de seguir adivinando.
const { chromium } = require('playwright');
const axios = require('axios');
const { wrapper } = require('axios-cookiejar-support');
const { CookieJar } = require('tough-cookie');
const fs = require('fs');
const path = require('path');
const config = require('./config');

const PJF_BASE = 'https://www.serviciosenlinea.pjf.gob.mx/juicioenlinea';
const ESTADO_FILE = path.join(__dirname, 'procesados_federal.json');

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

function normalizeExp(s) {
  return (s || '').replace(/\s+/g, '').toUpperCase();
}
// El número de expediente trae "/" (ej. "114/2026") -- usarlo tal cual en
// un nombre de archivo de captura hace que Windows (y Playwright) lo
// interprete como una subcarpeta que no existe, y la captura se pierde en
// silencio (bug real: pasó en la corrida del 8 nov 2026 con los PDF de
// acuerdos -- "revisa debug_pdf_114/2026_1.png" nunca se había guardado).
function sanitizarParaArchivo(s) {
  return (s || '').replace(/[\/\\]/g, '-');
}
// dd/mm/yyyy (formato del portal) -> yyyy-mm-dd (formato que usa el sistema)
function parseFechaMX(s) {
  const m = /(\d{1,2})\/(\d{1,2})\/(\d{4})/.exec(s || '');
  if (!m) return null;
  return `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}`;
}

async function obtenerExpedientesMonitoreados() {
  const res = await axios.get(`${config.sistema.apiBase}/expedientes_monitorear.php`, {
    headers: { 'X-Robot-Key': config.sistema.robotKey },
  });
  return res.data.data.expedientes;
}

async function obtenerCuentasPJF() {
  const res = await axios.get(`${config.sistema.apiBase}/pjf_credenciales_listar.php`, {
    headers: { 'X-Robot-Key': config.sistema.robotKey },
  });
  return res.data.data.cuentas;
}

async function reportarAviso(expedienteId, resumen, fecha, documentoId) {
  await axios.post(`${config.sistema.apiBase}/avisos_ingest.php`, {
    expediente_id: expedienteId,
    fuente: 'federal_laboral',
    resumen,
    fecha_publicacion: fecha,
    documento_id: documentoId || undefined,
  }, { headers: { 'X-Robot-Key': config.sistema.robotKey } });
}

// (28 nov 2026) Avisos que este robot reportó alguna vez SIN PDF, según la
// BASE (no según procesados_federal.json). La memoria local solo guarda los
// fallos posteriores al 24-nov; los anteriores (ej. Exp. 501/2026, Acuerdo
// No. 2) nunca se volvían a intentar. Mapa: expediente normalizado ->
// [{no, resumen, fechaIso, esAmparo}]. Si el sistema no contesta, se sigue
// con la memoria local de siempre.
let PENDIENTES_SERVIDOR = new Map();
async function cargarPendientesDelServidor() {
  try {
    const res = await axios.get(`${config.sistema.apiBase}/robot_avisos_sin_pdf.php`, {
      params: { fuente: 'federal_laboral' },
      headers: { 'X-Robot-Key': config.sistema.robotKey },
    });
    const datos = res.data.data || {};
    const mapa = new Map();
    for (const p of datos.pendientes || []) {
      const m = /^Acuerdo( de amparo)? No\. (\d+) del expediente (.+?), de fecha/.exec(p.resumen || '');
      if (!m) continue;
      const key = normalizeExp(m[3]);
      if (!mapa.has(key)) mapa.set(key, []);
      mapa.get(key).push({ no: parseInt(m[2], 10), resumen: p.resumen, fechaIso: p.fecha_publicacion || null, esAmparo: !!m[1] });
    }
    PENDIENTES_SERVIDOR = mapa;
    const total = Array.from(mapa.values()).reduce((n, l) => n + l.length, 0);
    console.log(`Avisos sin PDF según el sistema: ${total} por reintentar`
      + (datos.pegados_ya_existentes ? ` (+${datos.pegados_ya_existentes} que ya estaban en Documentos y se pegaron solos).` : '.'));
  } catch (e) {
    console.log('  (no se pudo pedir al sistema la lista de avisos sin PDF: ' + e.message + ' -- se sigue solo con la memoria local)');
  }
}

// Le avisa al sistema que este robot corrió (con éxito o con error) --
// pedido explícito del usuario (20 nov 2026) tras descubrir que este
// mismo robot llevaba sin correr en automático por un run_diario.bat
// faltante y nadie lo notó. Nunca debe tronar el robot si esto falla
// (ej. sin internet en ese momento) -- se atrapa y se sigue de largo.
async function reportarHeartbeat(ok, resumen) {
  try {
    await axios.post(`${config.sistema.apiBase}/robot_heartbeat.php`, {
      robot: 'federal',
      ok: ok ? 1 : 0,
      resumen: String(resumen || '').slice(0, 250),
    }, { headers: { 'X-Robot-Key': config.sistema.robotKey } });
  } catch (e) {
    console.log('  (no se pudo avisar el heartbeat al sistema: ' + e.message + ')');
  }
}

// Sube el PDF del acuerdo (ya descargado del portal) al mismo almacén de
// documentos que usa cada expediente -- regresa el id del documento, o null
// si algo falla (nunca debe tronar el reporte del aviso por esto).
async function subirPdfAlSistema(buffer, expedienteIdSistema, nombreArchivo, categoria) {
  try {
    const res = await axios.post(`${config.sistema.apiBase}/documentos_subir_robot.php`, {
      expediente_id: expedienteIdSistema,
      nombre_archivo: nombreArchivo,
      contenido_base64: buffer.toString('base64'),
      categoria: categoria || 'acuerdo',
    }, { headers: { 'X-Robot-Key': config.sistema.robotKey } });
    return res.data.data.id;
  } catch (e) {
    console.log(`    (no se pudo subir el PDF al sistema: ${e.message})`);
    return null;
  }
}

// Intenta conseguir los bytes del PDF de un acuerdo. Confirmado con una
// captura real del modal: el primer clic (botón de la celda "Documento
// Acuerdo") NO descarga nada -- abre un modal "Acuerdo" (data-keyboard
// "false", backdrop "static", así que Escape/clic afuera no lo cierran)
// con una tabla de archivos, cada uno con un ícono de descarga (flechita,
// primero) y uno de ver (ojito, después) -- hace falta un SEGUNDO clic
// adentro de ese modal, en el ícono de descarga, para que de verdad se
// dispare la descarga del PDF. Si no se puede, se regresa null (el aviso
// se manda igual, solo sin documento) y se deja evidencia para ajustar
// sin adivinar.
// Confirmado real (8 nov 2026): lo "descargado" a veces es el cascarón
// HTML de la mini app (~1000 bytes, "<!doctype html>...") en vez del PDF
// -- se valida que de verdad empiece con la cabecera mágica de PDF antes
// de aceptarlo.
function pdfEsValido(buf) {
  return !!buf && buf.length > 4 && buf.slice(0, 4).toString('latin1') === '%PDF';
}

async function intentarCapturarPdf(context, page, disparador) {
  // No solo "pdf" en el content-type -- la mini app Angular de adentro del
  // iframe puede pedir el archivo con otro content-type (ej.
  // "octet-stream" es común para descargas binarias genéricas).
  const esPdf = (resp) => {
    const ct = (resp.headers()['content-type'] || '').toLowerCase();
    return ct.includes('pdf') || ct.includes('octet-stream');
  };
  // 15s, no 10s -- cuando el disparador es "esperar a que el iframe cargue
  // su propia mini app", esta necesita tiempo real para bootstrapear
  // (bundles de Angular) antes de pedir el archivo por su cuenta.
  const esperaDescarga = page.waitForEvent('download', { timeout: 15000 }).catch(() => null);
  const esperaPaginaNueva = context.waitForEvent('page', { timeout: 15000 }).catch(() => null);
  const esperaRespuesta = page.waitForResponse(esPdf, { timeout: 15000 }).catch(() => null);

  await disparador();

  const [descarga, paginaNueva, respuesta] = await Promise.all([esperaDescarga, esperaPaginaNueva, esperaRespuesta]);

  if (descarga) {
    const ruta = await descarga.path().catch(() => null);
    if (ruta) return fs.readFileSync(ruta);
  }
  if (paginaNueva) {
    await paginaNueva.waitForLoadState('load', { timeout: 8000 }).catch(() => {});
    const descargaEnNueva = await paginaNueva.waitForEvent('download', { timeout: 5000 }).catch(() => null);
    if (descargaEnNueva) {
      const ruta = await descargaEnNueva.path().catch(() => null);
      await paginaNueva.close().catch(() => {});
      if (ruta) return fs.readFileSync(ruta);
      return null;
    }
    const urlNueva = paginaNueva.url();
    await paginaNueva.close().catch(() => {});
    if (/\.pdf(\?|$)/i.test(urlNueva)) {
      const r = await context.request.get(urlNueva).catch(() => null);
      if (r && r.ok()) return await r.body();
    }
    return null;
  }
  if (respuesta) {
    return await respuesta.body().catch(() => null);
  }
  return null;
}

async function descargarPdfAcuerdo(context, page, celda, etiquetaDebug) {
  const abrirModal = celda.locator('button, a, [role="button"]').first();
  if ((await abrirModal.count().catch(() => 0)) === 0) return null;

  // Confirmado real: un intento que empezó a escuchar descarga/respuesta
  // DESPUÉS de que el modal ya estaba abierto no vio NADA en 6s -- la
  // mini app del iframe debe cargar y pedir el archivo muy rápido, antes
  // de que isVisible()/waitFor() del modal siquiera terminen. Por eso
  // intentarCapturarPdf() ahora envuelve TODO el clic + espera del modal
  // como "disparador", para que sus listeners (download/response/página
  // nueva) queden armados DESDE ANTES del primer clic, no después.
  const peticiones = [];
  const verPeticion = (req) => peticiones.push(`${req.method()} ${req.url()}`);
  page.on('request', verPeticion);

  let modal = page.locator('.modal.in, .modal.show').first();
  const pdfTemprano = await intentarCapturarPdf(context, page, async () => {
    await abrirModal.click({ timeout: 8000 }).catch(() => {});
    await modal.waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
    // Margen extra por si la mini app del iframe tarda un poco más.
    await page.waitForTimeout(4000);
  });
  page.off('request', verPeticion);

  const hayModal = await modal.isVisible().catch(() => false);
  if (pdfEsValido(pdfTemprano)) {
    await cerrarCualquierModalAbierto(page);
    return pdfTemprano;
  }
  if (!hayModal) {
    await page.screenshot({ path: path.join(__dirname, `debug_pdf_${etiquetaDebug}.png`), fullPage: true, timeout: 90000 }).catch(() => {});
    // La captura ya mostró que SÍ aparece un fondo oscuro (el backdrop) pero
    // nunca el cuadro del modal -- en vez de seguir adivinando por CSS, se
    // vuelca el estado real del DOM del modal (clases, estilo, si existe)
    // directo en la consola, para ver qué le falta de verdad.
    const estadoModal = await page.evaluate(() => {
      const el = document.getElementById('DocumentosModal') || document.querySelector('.modal');
      if (!el) return 'NO existe ningún elemento con id="DocumentosModal" ni .modal en el DOM.';
      return `id=${el.id} class="${el.className}" style="${el.getAttribute('style') || ''}" `
        + `display-computado=${getComputedStyle(el).display} opacity-computada=${getComputedStyle(el).opacity}`;
    }).catch((e) => `(no se pudo leer el DOM: ${e.message})`);
    console.log(`    AVISO: no apareció el modal del documento al darle clic -- revisa debug_pdf_${etiquetaDebug}.png.`);
    console.log(`    Estado real del modal en el DOM: ${estadoModal}`);
    return null;
  }

  // Confirmado real (8 nov 2026): el <iframe src="...cjf-downloadfileweb/
  // files?param1=...&param2=...&param3=..."> NO es la URL directa del PDF
  // -- es el cascarón HTML de una mini app Angular ("cjf-downloadfileweb",
  // confirmado viendo su contenido real: <title>Cjfdownloadfileweb</title>,
  // <cjf-root>, bundles "runtime-es2015...js") que necesita ejecutar su
  // propio JavaScript, ya adentro de un navegador real, para ir a pedir el
  // PDF de verdad -- por eso pedir esa URL directo con context.request
  // siempre regresaba el mismo cascarón de ~1000 bytes, nunca el archivo.
  // Playwright SÍ ejecuta ese JS (Chromium es un navegador real), así que
  // basta con dejar que el iframe cargue solo y escuchar la respuesta/
  // descarga real que su propio código dispare -- igual que ya hace
  // intentarCapturarPdf() para el camino de respaldo, pero sin necesidad
  // de darle clic a nada (el iframe ya se puso en marcha solo al abrirse
  // el modal).
  const iframe = modal.locator('iframe').first();
  const hayIframe = await iframe.waitFor({ state: 'attached', timeout: 8000 }).then(() => true).catch(() => false);
  let pdf = null;
  if (hayIframe) {
    // Confirmado real con el HTML de adentro del iframe: es una mini app
    // Angular ("cjf-lista-archivos") con una tabla igual a la que se vio
    // en las capturas manuales -- el ícono de descarga es
    // <i class="fas fa-download iconDescarga"></i> (el de ver es
    // "fa-eye"). Viven DENTRO del documento propio del iframe, no en la
    // página que lo contiene -- hay que entrar con contentFrame() para
    // poder verlos/clickearlos; buscarlos en "modal"/"page" directo nunca
    // iba a encontrar nada (confirmado: siempre fallaba antes de este
    // cambio).
    const manija = await iframe.elementHandle({ timeout: 3000 }).catch(() => null);
    const frame = manija ? await manija.contentFrame().catch(() => null) : null;
    if (frame) {
      const botonDescarga = frame.locator('i.fa-download, i.iconDescarga').first();
      const hayBoton = await botonDescarga.waitFor({ state: 'visible', timeout: 10000 }).then(() => true).catch(() => false);
      if (hayBoton) {
        // Confirmado real (19 nov 2026, muchas corridas, ~20 documentos
        // distintos): la petición real de descarga que dispara este clic
        // (`.../wsFileManagerDownLoad/api/files/Download/<id>`) truena
        // SIEMPRE con "net::ERR_ABORTED" del lado del navegador, a la vez
        // que la consola muestra "table.autoFill is not a function" --
        // probablemente un bug del propio JS del portal que cancela la
        // petición antes de que llegue respuesta, no un rechazo real del
        // servidor (nunca se vio un código de error HTTP, solo la
        // cancelación). Se guarda esa URL exacta mientras se intenta el
        // camino normal, para poder volver a pedirla DIRECTO si el camino
        // normal no trae nada -- mismas cookies de la sesión (misma
        // técnica que ya usa `context.request` en otras partes de este
        // archivo), sin pasar por el JS roto que la cancela.
        let urlDescargaIntentada = null;
        const verUrlDescarga = (req) => {
          if (/wsFileManagerDownLoad\/api\/files\/Download\//i.test(req.url())) urlDescargaIntentada = req.url();
        };
        page.on('request', verUrlDescarga);
        pdf = await intentarCapturarPdf(context, page, () => botonDescarga.click({ timeout: 5000 }).catch(() => {}));
        page.off('request', verUrlDescarga);
        if (!pdf) {
          // Diagnóstico (19 nov 2026): con el ícono de descarga ya
          // cargado del todo (ya no el spinner de antes), el patrón de
          // falla cambió -- ya no se ve "net::ERR_ABORTED" en el log,
          // así que hace falta confirmar si el clic de verdad disparó
          // ALGUNA petición a esta URL o si no disparó nada en absoluto.
          console.log(`    (diagnóstico: ${urlDescargaIntentada ? `sí se detectó una petición de descarga -- ${urlDescargaIntentada}` : 'NO se detectó ninguna petición de descarga tras el clic'})`);
        }
        if (!pdf && urlDescargaIntentada) {
          // Candado real (19 nov 2026): sin un timeout explícito aquí, el
          // robot se quedó COLGADO para siempre en una corrida real -- si
          // el servidor no rechaza la petición sino que simplemente nunca
          // contesta nada (en vez de abortarla, como hace el navegador),
          // `context.request.get()` esperaba indefinidamente. 15s y se
          // rinde, igual que el resto de los intentos de este archivo.
          const r = await context.request.get(urlDescargaIntentada, { timeout: 15000 }).catch((e) => {
            console.log(`    (reintento directo también falló: ${e.message})`);
            return null;
          });
          const cuerpo = r ? await r.body().catch(() => null) : null;
          if (r) console.log(`    (reintento directo respondió: status=${r.status()}, ${cuerpo ? cuerpo.length : 0} bytes, content-type=${r.headers()['content-type'] || '?'})`);
          if (pdfEsValido(cuerpo)) {
            pdf = cuerpo;
            console.log('    (PDF recuperado reintentando la URL de descarga directo, sin pasar por el JS del portal)');
          }
        }
      } else {
        console.log('    AVISO: no se encontró el ícono de descarga (i.fa-download) dentro del iframe.');
      }
    } else {
      console.log('    AVISO: no se pudo entrar al documento real del iframe (contentFrame devolvió null).');
    }
  } else {
    // Respaldo, por si algún acuerdo SÍ muestra una tabla con varios
    // archivos en vez del iframe directo (no confirmado todavía con una
    // corrida real) -- se intenta la fila visible y su ícono de descarga.
    const filaArchivo = modal.locator('tbody tr:visible').first();
    const hayArchivo = await filaArchivo.waitFor({ state: 'visible', timeout: 5000 }).then(() => true).catch(() => false);
    if (hayArchivo) {
      const candidatosDescarga = [
        filaArchivo.locator('[title*="escargar" i], [aria-label*="escargar" i]').first(),
        filaArchivo.locator('a, button, [role="button"]').first(),
        filaArchivo.locator('i, svg, img').first(),
      ];
      for (const candidato of candidatosDescarga) {
        if ((await candidato.count().catch(() => 0)) === 0) continue;
        pdf = await intentarCapturarPdf(context, page, () => candidato.click({ timeout: 5000 }).catch(() => {}));
        if (pdf) break;
      }
    }
  }

  // Confirmado real (8 nov 2026): los primeros PDF "descargados" por este
  // camino pesaban exactamente 1000 bytes y salían en blanco al abrirlos
  // -- casi seguro una página de error/redirección (probablemente porque
  // context.request.get() no repite TODOS los headers que el navegador sí
  // manda, ej. Referer) guardada como si fuera el archivo real. Se valida
  // que de verdad empiece con la cabecera mágica de PDF ("%PDF") antes de
  // aceptarlo -- si no, se trata como si no se hubiera conseguido nada,
  // para no seguir subiendo basura al sistema.
  if (pdf && !pdfEsValido(pdf)) {
    console.log(`    AVISO: lo descargado no es un PDF real (no empieza con "%PDF", pesa ${pdf.length} bytes) -- se descarta.`);
    console.log(`    Contenido real de esos bytes (primeros 500 caracteres): ${pdf.toString('utf8').replace(/\s+/g, ' ').trim().slice(0, 500)}`);
    pdf = null;
  }

  if (!pdf) {
    await page.screenshot({ path: path.join(__dirname, `debug_pdf_${etiquetaDebug}.png`), fullPage: true, timeout: 90000 }).catch(() => {});
    const htmlModal = await modal.innerHTML().catch((e) => `(no se pudo leer: ${e.message})`);
    console.log(`    AVISO: no se pudo descargar el PDF desde el modal del documento -- revisa debug_pdf_${etiquetaDebug}.png.`);
    console.log(`    HTML real adentro del modal (primeros 1000 caracteres): ${htmlModal.replace(/\s+/g, ' ').trim().slice(0, 1000)}`);
    // Nunca se había mirado qué hay DENTRO del <iframe> una vez que carga
    // -- 0 peticiones de red capturadas sugiere que tal vez el archivo ya
    // viene incluido en la respuesta inicial (sin petición nueva que
    // interceptar) y la mini app solo lo muestra (ej. en un <embed>,
    // <canvas> o con una URL "blob:"). Se entra al documento REAL del
    // iframe (no el de la página que lo contiene) para ver qué armó.
    try {
      const manija = await iframe.elementHandle({ timeout: 3000 });
      const frame = manija ? await manija.contentFrame() : null;
      if (frame) {
        const htmlIframe = await frame.locator('body').innerHTML().catch((e) => `(no se pudo leer: ${e.message})`);
        console.log(`    HTML real DENTRO del iframe (primeros 1500 caracteres): ${htmlIframe.replace(/\s+/g, ' ').trim().slice(0, 1500)}`);
      } else {
        console.log('    No se pudo obtener el frame del iframe (contentFrame devolvió null).');
      }
    } catch (e) {
      console.log(`    (no se pudo leer adentro del iframe: ${e.message})`);
    }
  }
  await cerrarCualquierModalAbierto(page);
  return pdf;
}

// Envuelve descargarPdfAcuerdo() en varios intentos completos (24 nov
// 2026, pedido explícito del usuario: "sería bueno que los robots hagan
// más intentos para descargar los documentos y no trasladarle la
// responsabilidad al abogado"). Cada intento vuelve a abrir el modal
// desde cero (cerrando cualquier modal/backdrop que haya quedado a medio
// camino del intento anterior) y espera un poco más cada vez, por si el
// portal necesita "respirar" entre intentos -- mismo criterio que ya usa
// el resto de este robot para los tropiezos conocidos del portal
// (timeouts, overlays atorados). No reintenta recargando la página
// completa del expediente a propósito -- eso obligaría a volver a
// localizar la fila/celda desde cero y arriesgaría más que lo que
// resuelve; si el problema es de la página completa (no solo del modal),
// el acuerdo queda pendiente y se reintenta solo en la siguiente corrida
// (ver pendientesPdf en procesarExpediente()).
async function descargarPdfAcuerdoConReintentos(context, page, celda, etiquetaDebug, maxIntentos = 5) {
  for (let intento = 1; intento <= maxIntentos; intento++) {
    const pdf = await descargarPdfAcuerdo(context, page, celda, `${etiquetaDebug}_intento${intento}`);
    if (pdf) return pdf;
    if (intento < maxIntentos) {
      console.log(`    (intento ${intento}/${maxIntentos} sin éxito para el PDF de ${etiquetaDebug} -- reintentando...)`);
      await cerrarCualquierModalAbierto(page);
      await page.waitForTimeout(2000 * intento);
    }
  }
  console.log(`    AVISO: se agotaron los ${maxIntentos} intentos para el PDF de ${etiquetaDebug} -- queda pendiente para reintentarse en una corrida futura.`);
  return null;
}

// Login confirmado que SÍ funciona (corrida real: "Login PJF OK") -- se
// mantiene igual, vía API directa, en vez de llenar el formulario visible
// (del que no conocemos los selectores exactos). Regresa el CookieJar ya
// autenticado, para pasarle esas cookies a Playwright después.
//
// El portal en realidad tiene DOS "entidades" separadas con la MISMA
// pantalla de login: "Tribunales Laborales" (EntidadExterna=Laboral,
// tipo=3 -- la de siempre) y "Juzgados de Distrito y Tribunales de
// Circuito" (EntidadExterna=SinEntidad, tipo=1 -- la de los amparos),
// confirmado el 8 nov 2026 capturando la petición real del navegador con
// las herramientas de desarrollador. Cada una es una sesión/cookie
// DISTINTA que solo ve SU propia "Vista Global" (Laboral ve los
// expedientes laborales, Colegiados ve los amparos directos) -- por eso
// cada cuenta hay que revisarla dos veces, una por cada SESIONES[].
async function loginPJF(usuario, password, entidadExterna, tipo) {
  const jar = new CookieJar();
  const cliente = wrapper(axios.create({ jar, timeout: 20000 }));
  const params = new URLSearchParams();
  params.append('EntidadExterna', entidadExterna);
  params.append('UserName', usuario);
  params.append('UserPassword', password);
  params.append('tipo', tipo);
  const res = await cliente.post(`${PJF_BASE}/Home/Login`, params.toString(), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  const data = typeof res.data === 'string' ? JSON.parse(res.data) : res.data;
  if (!data.success) throw new Error('Login PJF falló: ' + (data.error || 'motivo desconocido'));
  return jar;
}

// Las dos sesiones que hay que revisar por cada cuenta -- ver el
// comentario de loginPJF(). La petición real capturada traía también un
// campo "ip" (la IP pública de quien inicia sesión) que el login de
// Laboral, ya confirmado funcionando, nunca manda -- se prueba primero
// sin mandarlo; si el login de Colegiados llegara a fallar por eso, hay
// que agregarlo.
const SESIONES = [
  { etiqueta: 'laboral', entidadExterna: 'Laboral', tipo: '3', esAmparo: false },
  { etiqueta: 'colegiados (amparo)', entidadExterna: 'SinEntidad', tipo: '1', esAmparo: true },
];

async function cookiesParaPlaywright(jar) {
  const cookiesTough = await jar.getCookies(PJF_BASE);
  return cookiesTough.map(c => ({
    name: c.key,
    value: c.value,
    domain: (c.domain || '').replace(/^\./, ''),
    path: c.path || '/',
    secure: !!c.secure,
    httpOnly: !!c.httpOnly,
  })).filter(c => c.domain && c.name);
}

// Confirmado real: buscar "Consulta de Expediente Electrónico" como
// cadena exacta no encontraba NADA (0 coincidencias) aunque el texto se
// viera perfecto en pantalla -- muy probable que el portal use un espacio
// especial (NBSP u otro) entre alguna palabra, invisible pero distinto a
// un espacio normal. Esta función arma un regex que acepta cualquier tipo
// de espacio entre palabras, para no depender de que coincida byte por
// byte.
function textoFlexible(frase) {
  return new RegExp(frase.trim().split(/\s+/).map(p => p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('\\s+'), 'i');
}

// Mismo problema ya documentado en el robot de jurisprudencia: un texto
// puede existir duplicado en el DOM (version de escritorio/movil, un menu
// colapsado que sigue en el HTML aunque este oculto, etc.) -- se busca
// entre todos los candidatos el primero que de verdad este visible.
async function clickTextoVisible(page, texto, exact = true, timeoutMs = 30000) {
  const candidatos = page.getByText(texto, { exact });
  // OJO: la espera de visibilidad por candidato estaba fija en 3000ms SIN
  // IMPORTAR el "timeoutMs" que mandara quien llama -- confirmado real: el
  // 3er intento (recarga completa, pensado para dar hasta 30s) en realidad
  // solo esperaba 3s antes de rendirse, y el diagnóstico mostró el mismo
  // candidato perfectamente visible (rect 122x29, visibility=visible)
  // apenas un instante después. Ahora la espera escala con "timeoutMs".
  const esperaVisibilidad = Math.max(3000, timeoutMs - 2000);
  // OJO también: "candidatos.count()" es una sola foto del DOM en este
  // instante -- si la página apenas está renderizando (ej. justo después
  // de recargar), puede dar 0 aunque el elemento aparezca un instante
  // después, y entonces el "for" de abajo ni siquiera se ejecuta una vez
  // (0 iteraciones), sin importar lo generoso que sea "esperaVisibilidad".
  // Se espera a que exista AL MENOS uno antes de contar/iterar.
  await candidatos.first().waitFor({ state: 'attached', timeout: esperaVisibilidad }).catch(() => {});
  const n = await candidatos.count();
  for (let i = 0; i < n; i++) {
    const el = candidatos.nth(i);
    // OJO: el.isVisible() NO espera de verdad, es una revisión instantánea
    // (mismo defecto real ya encontrado con el modal del documento: a
    // veces el elemento SÍ se vuelve visible un instante después de que
    // isVisible() ya había dicho que no -- confirmado con una corrida
    // real donde este clic tronaba con 0 modales/fondos bloqueando nada).
    // waitFor({state:'visible'}) sí reintenta hasta que aparezca.
    const visible = await el.waitFor({ state: 'visible', timeout: esperaVisibilidad }).then(() => true).catch(() => false);
    if (visible) {
      await el.click({ timeout: timeoutMs });
      return true;
    }
  }
  return false;
}

// Cierra cualquier modal de Bootstrap que haya quedado abierto (sin
// importar cuál) -- pensado para usarse después de intentar ver el
// documento de un acuerdo (ver descargarPdfAcuerdo): si el clic abrió un
// modal (ej. "DocumentosModal", confirmado con una corrida real vía
// "subtree intercepts pointer events") y no se pudo leer su contenido, hay
// que cerrarlo de todos modos antes de seguir con el siguiente acuerdo o
// expediente -- si no, el modal se queda tapando la pantalla y el
// SIGUIENTE clic (que no tiene nada que ver) truena interceptado por él.
// Nunca debe tronar esta función: es una limpieza de respaldo, no la
// lógica principal.
async function cerrarCualquierModalAbierto(page) {
  const modal = page.locator('.modal.in, .modal.show').first();
  const hayModal = await modal.isVisible({ timeout: 1500 }).catch(() => false);
  if (!hayModal) {
    // Aunque el modal YA no esté (su clase "in"/"show" se quitó), puede
    // quedar huérfano el fondo oscuro (".modal-backdrop") sin que nada lo
    // detecte -- confirmado como sospechoso real: ambas cuentas, en la
    // misma corrida, tronaron justo después de cerrar la ventana de
    // "Seleccione un perfil" con clic forzado, y ese fondo no coincide con
    // ".modal.in"/".modal.show" una vez el modal ya se ocultó. Se limpia
    // de todos modos, por si acaso, sin importar si hacía falta.
    await page.evaluate(() => {
      document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
      document.body.classList.remove('modal-open');
      document.body.style.removeProperty('padding-right');
      document.body.style.removeProperty('overflow');
    }).catch(() => {});
    return;
  }
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(400);
  if (!(await modal.isVisible().catch(() => false))) return;
  // El modal "Acuerdo" visto en una captura real tiene data-keyboard
  // "false" (Escape no lo cierra) y su botón de cierre es un "Cerrar" de
  // texto plano, sin data-dismiss/aria-label -- se intenta primero por
  // texto y de respaldo con los atributos genéricos de siempre.
  const candidatosCerrar = [
    modal.getByText('Cerrar', { exact: false }),
    modal.locator('button[data-dismiss="modal"], button.close, [aria-label="Close"], .close'),
  ];
  for (const candidato of candidatosCerrar) {
    if (await candidato.count().catch(() => 0)) {
      await candidato.first().click({ force: true, timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(400);
      if (!(await modal.isVisible().catch(() => false))) return;
    }
  }
  // Último recurso: se quita a la fuerza por JS para no dejar bloqueado el
  // resto de la corrida (otros expedientes de la misma cuenta siguen
  // después de este).
  await page.evaluate(() => {
    document.querySelectorAll('.modal.in, .modal.show').forEach(el => {
      el.classList.remove('in', 'show');
      el.style.display = 'none';
    });
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
    document.body.style.removeProperty('overflow');
  }).catch(() => {});
}

// El portal muestra, al menos la primera vez después de iniciar sesión,
// una ventana emergente ("modalModalidad") pidiendo elegir un perfil para
// entrar: "Persona Física", "Representante de persona jurídica pública" o
// "Representante de persona jurídica privada" -- tapa la pantalla y
// bloquea cualquier clic hasta que se cierra (confirmado con una corrida
// real: "subtree intercepts pointer events" apuntando exactamente a ese
// modal, y con capturas de pantalla reales del despacho). La opción
// correcta, confirmada por el despacho, es siempre "Persona Física". Se
// imprime el texto del modal en la consola por si en algún momento
// cambiara, y de respaldo se intenta con botones genéricos.
async function cerrarModalSiAparece(page, etiquetaDebug) {
  const modal = page.locator('.modal.in, .modal.show').first();
  const hayModal = await modal.isVisible({ timeout: 3000 }).catch(() => false);
  if (!hayModal) return;

  // El modal es de Bootstrap ("modal fade in") -- tiene una transición de
  // aparición de ~300-500ms; si se le da clic demasiado pronto (justo
  // cuando "isVisible" empieza a dar true) a veces el clic no llega a
  // registrarse del todo (confirmado con una corrida real: a veces sí
  // cerraba, a veces no, con el mismo código). Se espera a que termine la
  // animación antes de intentar nada.
  await page.waitForTimeout(700);

  const texto = await modal.innerText().catch(() => '(no se pudo leer el texto)');
  console.log(`  Apareció una ventana emergente al entrar -- texto: "${texto.replace(/\s+/g, ' ').trim().slice(0, 300)}"`);
  await page.screenshot({ path: path.join(__dirname, `debug_modal_${etiquetaDebug}.png`), fullPage: true, timeout: 90000 }).catch(() => {});

  // "Persona Física" es una tarjeta (ícono + texto) -- se intenta con el
  // texto, con su tarjeta contenedora (el padre) y, si nada cierra el
  // modal, con un clic "forzado" (que ignora si Playwright cree que el
  // elemento todavía no es clickeable del todo).
  const candidatosPersonaFisica = [
    modal.getByText('Persona Física', { exact: false }),
    modal.getByText('Persona Física', { exact: false }).locator('..'),
  ];
  const otrosBotones = [
    modal.getByRole('button', { name: /aceptar|continuar|entendido|confirmar|cerrar/i }),
    modal.locator('button[data-dismiss="modal"], button.close, [aria-label="Close"]'),
    modal.getByRole('button').first(),
  ];

  for (const forzar of [false, true]) {
    for (const candidato of [...candidatosPersonaFisica, ...otrosBotones]) {
      const n = await candidato.count();
      for (let i = 0; i < n; i++) {
        const el = candidato.nth(i);
        if (forzar || (await el.isVisible().catch(() => false))) {
          await el.click({ force: forzar, timeout: 5000 }).catch(() => {});
          await page.waitForTimeout(800);
          const sigueAhi = await modal.isVisible().catch(() => false);
          if (!sigueAhi) {
            // El modal ya no es visible, pero a veces (sobre todo con el
            // clic forzado) queda vivo el fondo oscuro (".modal-backdrop")
            // que Bootstrap pone detrás -- eso basta para que cualquier
            // clic posterior en la página (ej. el menú) quede bloqueado
            // aunque el elemento se vea clickeable (confirmado con una
            // corrida real: el modal cerraba pero el siguiente clic
            // tronaba en timeout). Se limpia por si acaso, sin importar si
            // realmente quedó ahí.
            await page.evaluate(() => {
              document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
              document.body.classList.remove('modal-open');
              document.body.style.removeProperty('padding-right');
              document.body.style.removeProperty('overflow');
            }).catch(() => {});
            console.log(`  Ventana emergente cerrada${forzar ? ' (con clic forzado)' : ''}.`);
            return;
          }
        }
      }
    }
  }
  console.log('  AVISO: no se pudo cerrar la ventana emergente automáticamente -- revisa debug_modal_' + etiquetaDebug + '.png para ver qué botón hace falta agregar.');
}

// Si la tabla (DataTables) permite elegir cuantas entradas mostrar por
// pagina ("Mostrar X entradas"), se pone el maximo disponible, para no
// tener que navegar pagina por pagina. No es grave si no lo encuentra.
async function maximizarEntradasPorPagina(page, tabla) {
  try {
    // La página puede traer MÁS de una tabla DataTables (algunas ocultas,
    // ej. "tableOficialia") -- cada una tiene su propio "Mostrar X
    // entradas", así que buscarlo por texto a secas (sin acotar a la
    // tabla que realmente nos interesa) puede agarrar el select de otra
    // tabla, invisible, y quedarse esperando 30s en vano (confirmado con
    // una corrida real). Se acota por el "aria-controls" del select, que
    // en DataTables apunta al id de SU tabla.
    const tablaId = tabla ? await tabla.getAttribute('id').catch(() => null) : null;
    const select = tablaId
      ? page.locator(`select[aria-controls="${tablaId}"]`).first()
      : page.getByText('Mostrar', { exact: false }).locator('..').locator('select').first();
    if (await select.count() === 0) return;
    const opciones = await select.locator('option').allInnerTexts();
    if (!opciones.length) return;
    // La ultima opcion suele ser la mas grande (ej. 100) -- se elige por
    // posicion, no por valor, para no depender de que opciones exactas trae.
    await select.selectOption({ index: opciones.length - 1 });
    await page.waitForTimeout(800);
  } catch (e) {
    console.log('    (no se pudo maximizar entradas por página, se sigue con el default: ' + e.message + ')');
  }
}

// Lee una tabla por el TEXTO de sus encabezados (no por clases/ids, que no
// conocemos) -- cada fila regresa un objeto {encabezado: {texto, celda}},
// donde "celda" es el locator de Playwright de esa celda (para poder darle
// clic a un botón que traiga adentro, ej. el "ojito").
async function leerTablaPorEncabezados(tabla) {
  const headers = (await tabla.locator('thead th').allInnerTexts()).map(h => h.trim());
  const filas = [];
  const trs = tabla.locator('tbody tr');
  const n = await trs.count();
  for (let i = 0; i < n; i++) {
    const tr = trs.nth(i);
    const celdas = tr.locator('td');
    const m = await celdas.count();
    if (m < headers.length) continue; // fila de "sin resultados" de DataTables, etc.
    const fila = {};
    for (let j = 0; j < headers.length; j++) {
      fila[headers[j]] = { texto: (await celdas.nth(j).innerText()).trim(), celda: celdas.nth(j) };
    }
    filas.push(fila);
  }
  return filas;
}

// Lee una tabla DataTables completa, pasando de página en página si hace
// falta -- no basta con "maximizarEntradasPorPagina()" sola: si la opción
// más grande del "Mostrar X entradas" (ej. 100) sigue siendo menor que el
// total de filas reales, la tabla igual se queda paginada. Se detecta el
// botón "Siguiente" por el id que DataTables le pone siempre
// (`<tablaId>_next`) y se avanza hasta que quede deshabilitado (clase
// "disabled") o se deje de ver -- tope de 50 páginas por seguridad, para
// nunca quedarse en un ciclo infinito si algo raro pasa con el portal.
async function leerTodasLasFilasDataTable(page, tabla) {
  await maximizarEntradasPorPagina(page, tabla);
  let filas = await leerTablaPorEncabezados(tabla);
  const tablaId = await tabla.getAttribute('id').catch(() => null);
  if (!tablaId) return filas;
  const siguiente = page.locator(`#${tablaId}_next`);
  for (let i = 0; i < 50; i++) {
    const hay = await siguiente.count().catch(() => 0);
    if (!hay) break;
    const clase = await siguiente.getAttribute('class').catch(() => '') || '';
    if (clase.includes('disabled')) break;
    await siguiente.click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(700);
    const nuevas = await leerTablaPorEncabezados(tabla);
    if (!nuevas.length) break;
    filas = filas.concat(nuevas);
  }
  return filas;
}

async function leerFilasVistaGlobal(page) {
  const tabla = page.locator('table').filter({ has: page.getByText('Número de expediente') }).first();
  await tabla.waitFor({ timeout: 15000 });
  // Confirmado real (19 nov 2026, captura del usuario): esta tabla SÍ pagina
  // -- el expediente 463/2026 de Anel Yadira Hernández Zaragoza aparecía
  // visible en el portal para la cuenta de Lindsay, pero el robot solo
  // reportaba 10 expedientes en esa sesión (la primera página, default de
  // DataTables) y nunca llegaba a verlo. A diferencia de la tabla de
  // acuerdos de cada expediente (que sí llama maximizarEntradasPorPagina()
  // antes de leer), esta lista principal nunca lo hacía -- corregido con
  // `leerTodasLasFilasDataTable()`, que además de maximizar entradas por
  // página también pasa de página en página por si el máximo disponible no
  // alcanza a cubrir todo.
  return leerTodasLasFilasDataTable(page, tabla);
}

// Busca EN VIVO, pasando de página en página si hace falta, la fila de
// Vista Global cuyo "Número de expediente" coincide con el buscado, y
// regresa esa fila recién leída (con su propio locator de celda, válido en
// este momento).
//
// Causa real encontrada el 19 nov 2026, con una corrida real: una vez que
// la cuenta de Lindsay tuvo expedientes repartidos en más de una página de
// Vista Global, el robot procesó bien el primer expediente de la 2ª página
// (300/2026) pero tronó con "locator.click: Timeout 30000ms exceeded" en
// los 2 siguientes (296/2026, 388/2026) -- ambos más allá de la fila 10.
// Razón: `leerTablaPorEncabezados()` regresa locators por POSICIÓN
// (`tbody tr`.nth(i)), que Playwright nunca "congela" -- se vuelven a
// resolver contra el DOM en vivo justo en el momento del clic, no en el
// momento en que se leyó la tabla. Como esos locators se armaron leyendo
// VARIAS páginas seguidas (ej. la fila 11 venía de la página 2, posición 1
// dentro de ESA página), y la tabla de Vista Global vuelve a su estado por
// default (10 filas, página 1) cada vez que se regresa de ver un
// expediente, para cuando el bucle llegaba a la fila 11 esa posición ya no
// existía en la página 1 -- el clic esperaba en vano una fila que nunca
// iba a aparecer ahí. Por eso ya no se reusa ningún locator capturado antes
// del bucle -- cada expediente se vuelve a buscar aquí, fresco, justo antes
// de dar el clic.
async function buscarFilaPorExpediente(page, numExpBuscado) {
  const tabla = page.locator('table').filter({ has: page.getByText('Número de expediente') }).first();
  await tabla.waitFor({ timeout: 15000 });
  await maximizarEntradasPorPagina(page, tabla);
  const tablaId = await tabla.getAttribute('id').catch(() => null);
  const siguiente = tablaId ? page.locator(`#${tablaId}_next`) : null;
  const buscado = normalizeExp(numExpBuscado);
  for (let i = 0; i < 50; i++) {
    const filasPagina = await leerTablaPorEncabezados(tabla);
    const encontrada = filasPagina.find(f => normalizeExp((f['Número de expediente'] || {}).texto || '') === buscado);
    if (encontrada) return encontrada;
    if (!siguiente) break;
    const hay = await siguiente.count().catch(() => 0);
    if (!hay) break;
    const clase = await siguiente.getAttribute('class').catch(() => '') || '';
    if (clase.includes('disabled')) break;
    await siguiente.click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(700);
  }
  return null;
}

// Regresa de ver un expediente a la tabla de Vista Global, y CONFIRMA que
// de verdad se llegó ahí antes de seguir -- no basta con que el clic en
// "Regresar" no haya tronado.
//
// Causa real encontrada el 19 nov 2026, con una corrida real: tras abrir 4
// acuerdos seguidos del expediente 296/2026 (cada uno con un intento de
// descarga de PDF fallido, dejando en la consola "table.autoFill is not a
// function" las 4 veces), el clic en "Regresar" ya no tronaba, pero la
// tabla de Vista Global quedaba en un estado roto del lado del portal --
// los 3 expedientes siguientes (388/2026, 463/2026, 501/2026, el propio
// 463/2026 que motivó toda esta investigación entre ellos) ya no se volvían
// a encontrar NUNCA, aunque seguían apareciendo en la lista de "Números
// vistos" de esa misma cuenta. Por eso ya no basta con que "Regresar" no
// haya lanzado un error -- se confirma que la tabla de Vista Global
// de verdad está ahí (con un timeout corto) antes de seguir; si no, se cae
// al respaldo de recargar desde cero y volver a entrar al submenú completo.
async function regresarAVistaGlobal(page) {
  const regreso = await clickTextoVisible(page, 'Regresar', false).catch(() => false);
  if (regreso) {
    const ok = await page.locator('table').filter({ has: page.getByText('Número de expediente') }).first()
      .waitFor({ timeout: 8000 }).then(() => true).catch(() => false);
    if (ok) return true;
  }
  await cerrarCualquierModalAbierto(page);
  // Respaldo: si no hay botón "Regresar" visible, o si lo hubo pero la
  // tabla de Vista Global no quedó en buen estado, se vuelve a entrar al
  // menú desde cero.
  await page.goto(`${PJF_BASE}/Home/OpcionesFirel`, { waitUntil: 'networkidle' }).catch(() => {});
  await clickTextoVisible(page, textoFlexible('Consulta de Expediente Electrónico'), false).catch(() => false);
  await page.waitForTimeout(500);
  return await clickTextoVisible(page, 'Vista Global', false).catch(() => false);
}

// Busca en la fila la celda que tenga el botón para ver/descargar el
// documento del acuerdo. No se conoce el nombre exacto del encabezado (no
// se pudo ver en vivo desde aquí) -- se intentan varios nombres probables
// primero y, si ninguno aparece, se cae de respaldo a la ÚLTIMA columna de
// la fila (ahí suele vivir la acción, como ya pasó con "Ver Expediente
// Electrónico" en Vista Global).
function celdaBotonDocumento(fila) {
  const candidatosHeader = ['Documento Acuerdo', 'Ver Documento', 'Documento', 'Acuerdo', 'Ver Acuerdo', 'Acciones', 'Ver'];
  for (const h of candidatosHeader) {
    if (fila[h] && fila[h].celda) return fila[h].celda;
  }
  const headers = Object.keys(fila);
  if (!headers.length) return null;
  return fila[headers[headers.length - 1]].celda;
}

// Procesa UN expediente ya abierto (después de darle clic al "ojito"):
// lee su tabla de acuerdos, reporta los que tengan un "No." mayor al que
// ya se tenía guardado para ese expediente, y regresa cuántos reportó.
async function procesarExpediente(context, page, numeroExpediente, match, estado, esAmparo) {
  const ok = await page.getByText('Fecha de Acuerdo', { exact: false }).first()
    .waitFor({ timeout: 15000 }).then(() => true).catch(() => false);
  if (!ok) {
    console.log(`    AVISO: no cargó la tabla de acuerdos del expediente ${numeroExpediente} -- se omite esta vez.`);
    return 0;
  }
  const tabla = page.locator('table').filter({ has: page.getByText('Fecha de Acuerdo') }).first();
  // Mismo candado que leerFilasVistaGlobal() (19 nov 2026): maximizar
  // entradas por página no basta si un expediente acumula más acuerdos que
  // el máximo disponible en el selector -- se pasa de página también aquí.
  const filas = await leerTodasLasFilasDataTable(page, tabla);

  // (24 nov 2026) estado[key] guardaba solo un número (el "No." de acuerdo
  // más alto ya visto) -- una vez que un acuerdo se reportaba, el robot
  // NUNCA volvía a mirarlo, aunque el PDF no se hubiera podido descargar
  // (caso real: Exp. 388/2026, Acuerdo No. 7 y 8, el PDF nunca se adjuntó
  // y nadie lo iba a reintentar jamás). Ahora estado[key] guarda un objeto
  // {maxNo, pendientesPdf} -- compatible con el formato viejo (un número
  // suelto se interpreta como maxNo con pendientesPdf vacío).
  const key = normalizeExp(numeroExpediente);
  let entry = estado[key];
  if (typeof entry !== 'object' || entry === null) entry = { maxNo: entry || 0, pendientesPdf: [] };
  if (!Array.isArray(entry.pendientesPdf)) entry.pendientesPdf = [];
  // (28 nov 2026) Se suman los pendientes que el SISTEMA sabe que no tienen
  // PDF (incluye los anteriores al arreglo del 24-nov), con el resumen
  // exacto de la base para que avisos_ingest.php los reconozca.
  for (const p of PENDIENTES_SERVIDOR.get(key) || []) {
    if (p.esAmparo !== !!esAmparo) continue;
    if (entry.pendientesPdf.some((x) => x.no === p.no)) continue;
    entry.pendientesPdf.push({ no: p.no, resumen: p.resumen, fechaIso: p.fechaIso });
  }
  const ultimoVisto = entry.maxNo || 0;
  let maxNo = ultimoVisto;
  let reportados = 0;

  // Paso 1: reintentar los PDF que quedaron pendientes de corridas
  // anteriores (acuerdos ya reportados, sin documento todavía) -- antes de
  // procesar acuerdos nuevos, usando la misma tabla que ya se acaba de leer
  // completa para este expediente. Si el acuerdo ya no aparece en la tabla
  // (caso raro, ej. se reorganizó la paginación), se deja pendiente para
  // la siguiente corrida -- sin límite de tiempo, el costo de reintentar es
  // bajo y nunca se cierra la puerta a que se recupere solo.
  if (entry.pendientesPdf.length) {
    const siguenPendientes = [];
    for (const pend of entry.pendientesPdf) {
      const filaPend = filas.find((f) => parseInt((f['No.'] || {}).texto || '', 10) === pend.no);
      if (!filaPend) { siguenPendientes.push(pend); continue; }
      let pdfRecuperado = null;
      try {
        const celdaPend = celdaBotonDocumento(filaPend);
        pdfRecuperado = celdaPend
          ? await descargarPdfAcuerdoConReintentos(context, page, celdaPend, `${sanitizarParaArchivo(key)}_${pend.no}_retry`)
          : null;
      } catch (e) {
        console.log(`    (reintento del PDF pendiente No. ${pend.no} del expediente ${numeroExpediente} falló: ${e.message})`);
      }
      await cerrarCualquierModalAbierto(page);
      if (pdfRecuperado) {
        const documentoId = await subirPdfAlSistema(
          pdfRecuperado, match.id, `Acuerdo_No_${pend.no}_Exp_${numeroExpediente.replace(/\//g, '-')}.pdf`,
          esAmparo ? 'acuerdo_amparo' : 'acuerdo'
        );
        if (documentoId) {
          // Mismo resumen con el que se reportó la primera vez -- avisos_
          // ingest.php lo reconoce como el mismo aviso ya existente y le
          // adjunta este documento en vez de tratarlo como duplicado (ver
          // arreglo del 24 nov 2026 ahí mismo).
          await reportarAviso(match.id, pend.resumen, pend.fechaIso, documentoId);
          console.log(`    PDF recuperado en esta corrida para el acuerdo No. ${pend.no} del expediente ${numeroExpediente} (ya se había reportado antes sin documento).`);
          reportados++;
          continue;
        }
      }
      siguenPendientes.push(pend);
    }
    entry.pendientesPdf = siguenPendientes;
  }

  // Paso 2: acuerdos nuevos (mismo flujo de siempre, con 5 intentos por
  // PDF en vez de 1, y guardando en pendientesPdf lo que de verdad no se
  // pudo bajar esta vez).
  for (const fila of filas) {
    const no = parseInt((fila['No.'] || {}).texto || '', 10);
    if (!no || no <= ultimoVisto) continue;
    const fechaAcuerdo = (fila['Fecha de Acuerdo'] || {}).texto || '';
    const fechaPublicacion = (fila['Fecha de Publicación'] || {}).texto || '';
    const resumen = `Acuerdo${esAmparo ? ' de amparo' : ''} No. ${no} del expediente ${numeroExpediente}, de fecha ${fechaAcuerdo || '(sin fecha)'}`
      + (fechaPublicacion ? `, publicado el ${fechaPublicacion}.` : '.');
    const fechaIso = parseFechaMX(fechaPublicacion || fechaAcuerdo);

    let documentoId = null;
    let pdf = null;
    try {
      const celda = celdaBotonDocumento(fila);
      pdf = celda ? await descargarPdfAcuerdoConReintentos(context, page, celda, `${sanitizarParaArchivo(key)}_${no}`) : null;
      if (pdf) {
        documentoId = await subirPdfAlSistema(
          pdf, match.id, `Acuerdo_No_${no}_Exp_${numeroExpediente.replace(/\//g, '-')}.pdf`,
          esAmparo ? 'acuerdo_amparo' : 'acuerdo'
        );
      }
    } catch (e) {
      console.log(`    (no se pudo obtener el PDF del acuerdo No. ${no}: ${e.message})`);
    }
    await cerrarCualquierModalAbierto(page);

    // El clic para ver el documento pudo haber navegado la MISMA pestaña
    // directo al PDF (en vez de abrir una pestaña nueva) -- si la tabla de
    // acuerdos ya no está visible, hay que regresar antes de seguir con la
    // siguiente fila (si no, las filas restantes de este "for" ya no
    // encuentran sus celdas y truena).
    const siguetabla = await page.getByText('Fecha de Acuerdo', { exact: false }).first()
      .isVisible({ timeout: 2000 }).catch(() => false);
    if (!siguetabla) {
      await page.goBack({ timeout: 10000 }).catch(() => {});
      await page.getByText('Fecha de Acuerdo', { exact: false }).first()
        .waitFor({ timeout: 10000 }).catch(() => {});
    }

    await reportarAviso(match.id, resumen, fechaIso, documentoId);
    reportados++;
    console.log(`    Aviso reportado: expediente ${numeroExpediente}, acuerdo No. ${no}.`);
    if (no > maxNo) maxNo = no;
    if (!pdf) {
      // No se logró el PDF ni con los 5 intentos de esta corrida -- queda
      // pendiente para reintentarse solo, sin límite de tiempo, en
      // cualquier corrida futura (ver Paso 1).
      entry.pendientesPdf.push({ no, resumen, fechaIso });
    }
  }
  entry.maxNo = maxNo;
  estado[key] = entry;
  return reportados;
}

async function revisarCuenta(cuenta, porNumero, estado, browser) {
  console.log(`--- Cuenta: ${cuenta.nombre} (${cuenta.pjf_usuario}) ---`);
  let totalReportados = 0;
  for (const sesion of SESIONES) {
    try {
      totalReportados += await revisarSesion(cuenta, sesion, porNumero, estado, browser);
    } catch (e) {
      console.log(`  Error en la sesión "${sesion.etiqueta}" de la cuenta ${cuenta.pjf_usuario}: ${e.message}`);
    }
  }
  return totalReportados;
}

async function revisarSesion(cuenta, sesion, porNumero, estado, browser) {
  const etiquetaDebug = `${cuenta.pjf_usuario}_${sesion.etiqueta.replace(/[^a-z0-9]+/gi, '-')}`;
  console.log(`  Sesión "${sesion.etiqueta}" (EntidadExterna=${sesion.entidadExterna}, tipo=${sesion.tipo})...`);
  const jar = await loginPJF(cuenta.pjf_usuario, cuenta.pjf_password, sesion.entidadExterna, sesion.tipo);
  console.log('    Login PJF OK.');

  const context = await browser.newContext();
  await context.addCookies(await cookiesParaPlaywright(jar));
  const page = await context.newPage();
  // Las capturas de hoy mostraron el fondo oscuro del modal del documento
  // pero nunca el cuadro -- probablemente la llamada AJAX que carga su
  // contenido falla en silencio. Se escuchan los errores reales de la
  // página (consola, JS, peticiones fallidas) para verlo directo en la
  // terminal en vez de seguir adivinando.
  page.on('console', (msg) => { if (msg.type() === 'error') console.log(`    [consola del navegador] ${msg.text()}`); });
  page.on('pageerror', (err) => console.log(`    [error de JS en la página] ${err.message}`));
  page.on('requestfailed', (req) => console.log(`    [petición fallida] ${req.url()} -- ${(req.failure() || {}).errorText || '?'}`));
  page.on('response', (resp) => { if (resp.status() >= 400) console.log(`    [respuesta con error ${resp.status()}] ${resp.url()}`); });
  let reportados = 0;
  try {
    // Confirmado real varias veces en este portal (ver CLAUDE.md): un
    // "Timeout 30000ms exceeded" navegando aquí, sin relación con nada que
    // el robot haga, es un simple tropiezo de red -- pero como ésta es la
    // PRIMERA navegación de la sesión, sin ningún reintento se perdía toda
    // la sesión completa de la cuenta (incluyendo, una vez, la que SÍ ve
    // el 463/2026) por un solo tropiezo. 2 intentos más antes de rendirse.
    let navegoOk = false;
    for (let intento = 0; intento < 3 && !navegoOk; intento++) {
      try {
        await page.goto(`${PJF_BASE}/Home/OpcionesFirel`, { waitUntil: 'networkidle', timeout: 30000 });
        navegoOk = true;
      } catch (e) {
        if (intento === 2) throw e;
        console.log(`    AVISO: tropiezo de red navegando al portal (intento ${intento + 1}/3: ${e.message}) -- reintentando...`);
        await page.waitForTimeout(3000);
      }
    }
    await cerrarModalSiAparece(page, etiquetaDebug);
    // De respaldo, por si quedó huérfano el fondo oscuro del modal que se
    // acaba de cerrar (confirmado real: pasó en las 2 cuentas de la misma
    // corrida, justo después del clic forzado) -- nunca está de más.
    await cerrarCualquierModalAbierto(page);
    // Confirmado real: justo después de cerrar el modal con clic forzado,
    // el primer intento de clic al menú puede tronar con "no encontrado"
    // aunque el texto SÍ esté en el DOM -- probablemente la página sigue
    // reacomodándose (AJAX pendiente) justo en ese instante. Un respiro
    // corto aquí reduce ese intermitente sin perder tiempo cuando no hace
    // falta (networkidle ya resuelve rápido si no hay nada pendiente).
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
    await page.waitForTimeout(1000);
    // "Consulta de Expediente Electrónico" es un menú con submenú (se
    // confirmó con una corrida real: el texto de la página mostraba
    // "...Consulta de Expediente Electrónico Vista Global Vista por Órgano
    // Jurisdiccional..." justo después de darle clic) -- hace falta un
    // segundo clic en "Vista Global" para que de verdad aparezca la tabla.
    // Timeouts cortos (8s) en los primeros intentos para no perder 30s por
    // intento cuando el problema real es un fondo oscuro atorado (se
    // arregla en menos de un segundo, no hace falta esperar tanto) --
    // confirmado real: este clic ha tronado más de una vez justo después
    // de cerrar la ventana de "Seleccione un perfil".
    let entroMenu = await clickTextoVisible(page, textoFlexible('Consulta de Expediente Electrónico'), false, 8000).catch(() => false);
    if (!entroMenu) {
      await cerrarCualquierModalAbierto(page);
      entroMenu = await clickTextoVisible(page, textoFlexible('Consulta de Expediente Electrónico'), false, 8000).catch(() => false);
    }
    if (!entroMenu) {
      // Último recurso: recargar desde cero por si quedó algo atorado que
      // la limpieza de modales no cubre, con el timeout normal de 30s.
      await page.goto(`${PJF_BASE}/Home/OpcionesFirel`, { waitUntil: 'networkidle' }).catch(() => {});
      await cerrarModalSiAparece(page, etiquetaDebug);
      await cerrarCualquierModalAbierto(page);
      entroMenu = await clickTextoVisible(page, textoFlexible('Consulta de Expediente Electrónico'), false).catch(() => false);
    }
    if (!entroMenu) {
      console.log('  AVISO: no se encontró el menú "Consulta de Expediente Electrónico" -- el portal pudo cambiar otra vez.');
      await page.screenshot({ path: path.join(__dirname, `debug_error_${etiquetaDebug}.png`), fullPage: true, timeout: 90000 })
        .then(() => console.log(`  Captura guardada: debug_error_${etiquetaDebug}.png`))
        .catch((e) => console.log(`  (no se pudo guardar la captura: ${e.message})`));
      const diagnostico = await page.evaluate(() => ({
        modales: document.querySelectorAll('.modal').length,
        modalesAbiertos: document.querySelectorAll('.modal.in, .modal.show').length,
        backdrops: document.querySelectorAll('.modal-backdrop').length,
        url: location.href,
        // Confirmado real: la búsqueda EXACTA daba 0 aunque el texto se
        // viera perfecto en pantalla (probable espacio especial entre
        // palabras) -- se usa el mismo regex flexible que ya usa el clic,
        // para que este número sea consistente con lo que de verdad busca
        // clickTextoVisible().
        textoMenuEnBody: ((document.body.innerText || '').match(/Consulta\s+de\s+Expediente\s+Electr[oó]nico/gi) || []).length,
      })).catch((e) => `(no se pudo leer: ${e.message})`);
      console.log(`  Diagnóstico de la página en ese momento: ${JSON.stringify(diagnostico)}`);
      const textoBody = await page.locator('body').innerText().catch((e) => `(no se pudo leer: ${e.message})`);
      console.log(`  Texto visible de la página (primeros 800 caracteres): ${textoBody.replace(/\s+/g, ' ').trim().slice(0, 800)}`);
      // El texto SÍ aparece (textoMenuEnBody>0 arriba) pero igual nunca se
      // consideró "visible" para el clic -- puede que el que coincide con
      // el texto no sea el link real del menú (un duplicado oculto en otro
      // lado). Se usa el MISMO locator que usa el clic (getByText con el
      // regex flexible) para ver cuántos hay y el estado real de cada uno.
      const candidatosMenu = page.getByText(textoFlexible('Consulta de Expediente Electrónico'));
      const nCandidatos = await candidatosMenu.count().catch(() => -1);
      console.log(`  Candidatos que matchea el locator del clic: ${nCandidatos}`);
      for (let i = 0; i < nCandidatos && i < 5; i++) {
        const info = await candidatosMenu.nth(i).evaluate((el) => {
          const r = el.getBoundingClientRect();
          const cs = getComputedStyle(el);
          return `tag=${el.tagName} class="${el.className}" rect=${Math.round(r.width)}x${Math.round(r.height)} `
            + `display=${cs.display} visibility=${cs.visibility} opacity=${cs.opacity}`;
        }).catch((e) => `(no se pudo leer: ${e.message})`);
        console.log(`    candidato[${i}]: ${info}`);
      }
      return 0;
    }
    await page.waitForTimeout(500);
    // Confirmado real (19 nov 2026): este clic puede tronar igual que el
    // de "Consulta de Expediente Electrónico" de arriba (mismo tipo de
    // error: "locator.click: Timeout 30000ms exceeded", click visible/
    // habilitado/estable pero sin completarse -- probablemente un fondo/
    // overlay que reaparece justo al hacer clic). Antes este clic no tenía
    // NINGÚN reintento (a diferencia del de arriba, que sí) -- un solo
    // tropiezo tiraba la sesión completa sin revisar ningún expediente.
    // Mismo patrón de 3 intentos que ya usa el menú de arriba.
    let entroVistaGlobal = await clickTextoVisible(page, 'Vista Global', false, 8000).catch(() => false);
    if (!entroVistaGlobal) {
      await cerrarCualquierModalAbierto(page);
      entroVistaGlobal = await clickTextoVisible(page, 'Vista Global', false, 8000).catch(() => false);
    }
    if (!entroVistaGlobal) {
      // Último recurso: recargar desde cero y volver a entrar al submenú
      // completo, por si quedó algo atorado que la limpieza de modales no
      // cubre.
      await page.goto(`${PJF_BASE}/Home/OpcionesFirel`, { waitUntil: 'networkidle' }).catch(() => {});
      await cerrarModalSiAparece(page, etiquetaDebug);
      await cerrarCualquierModalAbierto(page);
      await clickTextoVisible(page, textoFlexible('Consulta de Expediente Electrónico'), false).catch(() => false);
      await page.waitForTimeout(500);
      entroVistaGlobal = await clickTextoVisible(page, 'Vista Global', false).catch(() => false);
    }
    if (!entroVistaGlobal) {
      console.log('  AVISO: no se encontró "Vista Global" dentro del submenú -- el portal pudo cambiar otra vez.');
      await page.screenshot({ path: path.join(__dirname, `debug_error_${etiquetaDebug}.png`), fullPage: true, timeout: 90000 }).catch(() => {});
      return 0;
    }

    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(1000);
    let filas;
    try {
      filas = await leerFilasVistaGlobal(page);
    } catch (e) {
      console.log('  AVISO: no apareció la tabla de expedientes después de entrar al menú -- se deja evidencia para diagnosticar.');
      await page.screenshot({ path: path.join(__dirname, `debug_error_${etiquetaDebug}.png`), fullPage: true, timeout: 90000 }).catch(() => {});
      const url = page.url();
      const textoPagina = await page.locator('body').innerText().catch(() => '(no se pudo leer)');
      console.log(`  URL en ese momento: ${url}`);
      console.log(`  Texto visible en ese momento (primeros 500 caracteres): "${textoPagina.replace(/\s+/g, ' ').trim().slice(0, 500)}"`);
      return 0;
    }
    console.log(`  ${filas.length} expediente(s) visibles en el portal para esta cuenta.`);
    // Diagnóstico (19 nov 2026): sin esto no hay forma de saber, por el
    // log, si un expediente monitoreado específico salió en esta página o
    // no -- el "continue" de abajo cuando no hay match es silencioso.
    console.log(`    Números vistos en esta página: ${filas.map(f => (f['Número de expediente'] || {}).texto || '?').join(', ') || '(ninguno)'}`);

    for (const fila of filas) {
      const numExp = (fila['Número de expediente'] || {}).texto || '';
      const match = porNumero.get(normalizeExp(numExp));
      if (!match) continue;

      // Se vuelve a buscar esta fila EN VIVO en vez de reusar el locator
      // que trajo `filas` (ver el comentario de `buscarFilaPorExpediente()`
      // -- un locator por posición ya no sirve después de que la tabla
      // pasó de página/regresó de ver otro expediente).
      const filaViva = await buscarFilaPorExpediente(page, numExp).catch(() => null);
      if (!filaViva) {
        console.log(`    AVISO: no se volvió a encontrar el expediente ${numExp} en Vista Global al intentar abrirlo -- se omite esta vez.`);
        continue;
      }
      const botonVer = (filaViva['Ver Expediente Electrónico'] || {}).celda;
      if (!botonVer) continue;
      const boton = botonVer.locator('button, a, [role="button"]').first();
      try {
        await boton.click();
        reportados += await procesarExpediente(context, page, numExp, match, estado, sesion.esAmparo);
      } catch (e) {
        console.log(`    Error revisando el expediente ${numExp}: ${e.message}`);
        await page.screenshot({ path: path.join(__dirname, `debug_error_${etiquetaDebug}_${sanitizarParaArchivo(normalizeExp(numExp))}.png`), fullPage: true, timeout: 90000 }).catch(() => {});
      }

      // Regresa a la vista global para poder abrir el siguiente expediente.
      await regresarAVistaGlobal(page);
    }
  } finally {
    await context.close();
  }
  console.log(`  ${reportados} aviso(s) nuevo(s) en la sesión "${sesion.etiqueta}".`);
  return reportados;
}

async function main() {
  console.log(new Date().toISOString(), 'Iniciando revisión Federal...');

  const expedientes = await obtenerExpedientesMonitoreados();
  const porNumero = new Map();
  let conAmparo = 0;
  for (const e of expedientes) {
    const candidatos = [e.junta, e.tribunal].filter(Boolean);
    const esFederal = candidatos.some(c => /FEDERAL/i.test(c));
    if (esFederal && e.exp) porNumero.set(normalizeExp(e.exp), e);
    // El amparo directo SIEMPRE lo resuelve un Tribunal Colegiado de
    // Circuito (órgano federal), sin importar si el juicio laboral
    // original fue local o federal -- por eso se indexa aparte, sin
    // condicionarlo a esFederal. Ambas entradas (el expediente original y
    // el del amparo) apuntan al MISMO expediente 'e', así que un acuerdo
    // de cualquiera de los dos números se reporta contra el mismo asunto.
    if (e.amparo_expediente) {
      porNumero.set(normalizeExp(e.amparo_expediente), e);
      conAmparo++;
    }
  }
  console.log(`${porNumero.size} número(s) de expediente monitoreados (incluye ${conAmparo} de amparo).`);

  const cuentas = await obtenerCuentasPJF();
  console.log(`${cuentas.length} cuenta(s) del Portal Federal guardada(s).`);
  if (!cuentas.length) {
    console.log('Nadie ha guardado su cuenta del Portal Federal en "Mi cuenta" todavía.');
    return 'sin cuentas guardadas -- nada que revisar';
  }

  const estado = cargarProcesados();
  await cargarPendientesDelServidor();
  const browser = await chromium.launch({ headless: true });
  let totalReportados = 0;
  try {
    for (const cuenta of cuentas) {
      try {
        totalReportados += await revisarCuenta(cuenta, porNumero, estado, browser);
      } catch (e) {
        console.log(`  Error con la cuenta de ${cuenta.nombre}: ${e.message}`);
      }
      guardarProcesados(estado);
    }
  } finally {
    await browser.close();
  }
  console.log(`Listo. ${totalReportados} aviso(s) nuevo(s) reportado(s) en total.`);
  return `${totalReportados} aviso(s) nuevo(s)`;
}

main()
  .then(resumen => reportarHeartbeat(true, resumen))
  .catch(async err => {
    console.error('Error en checker Federal:', err.message);
    await reportarHeartbeat(false, 'Error: ' + err.message);
    process.exit(1);
  });
