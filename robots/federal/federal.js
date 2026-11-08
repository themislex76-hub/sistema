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

// Sube el PDF del acuerdo (ya descargado del portal) al mismo almacén de
// documentos que usa cada expediente -- regresa el id del documento, o null
// si algo falla (nunca debe tronar el reporte del aviso por esto).
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
async function intentarCapturarPdf(context, page, disparador) {
  const esPdf = (resp) => (resp.headers()['content-type'] || '').toLowerCase().includes('pdf');
  const esperaDescarga = page.waitForEvent('download', { timeout: 10000 }).catch(() => null);
  const esperaPaginaNueva = context.waitForEvent('page', { timeout: 10000 }).catch(() => null);
  const esperaRespuesta = page.waitForResponse(esPdf, { timeout: 10000 }).catch(() => null);

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
  await abrirModal.click({ timeout: 8000 }).catch(() => {});

  // OJO: modal.isVisible({timeout}) NO espera de verdad -- es una revisión
  // instantánea (confirmado con una corrida real: el volcado del DOM del
  // modal, tomado justo DESPUÉS de que isVisible() ya había dicho que no,
  // a veces mostraba "class=modal fade in" display:block, es decir, ya
  // estaba abierto). waitFor({state:'visible'}) sí reintenta de verdad
  // hasta que aparezca o se cumpla el timeout.
  const modal = page.locator('.modal.in, .modal.show').first();
  const hayModal = await modal.waitFor({ state: 'visible', timeout: 8000 }).then(() => true).catch(() => false);
  if (!hayModal) {
    await page.screenshot({ path: path.join(__dirname, `debug_pdf_${etiquetaDebug}.png`), fullPage: true }).catch(() => {});
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

  const filaArchivo = modal.locator('tbody tr').first();
  const hayArchivo = await filaArchivo.waitFor({ state: 'visible', timeout: 5000 }).then(() => true).catch(() => false);
  if (!hayArchivo) {
    await page.screenshot({ path: path.join(__dirname, `debug_pdf_${etiquetaDebug}.png`), fullPage: true }).catch(() => {});
    console.log(`    AVISO: el modal del documento no mostró ningún archivo -- revisa debug_pdf_${etiquetaDebug}.png.`);
    await cerrarCualquierModalAbierto(page);
    return null;
  }

  // El ícono de descarga (flechita) va primero que el de ver (ojito) en la
  // captura real -- se intenta por título/aria-label primero (más
  // confiable si cambia el orden) y, de respaldo, por posición.
  const candidatosDescarga = [
    filaArchivo.locator('[title*="escargar" i], [aria-label*="escargar" i]').first(),
    filaArchivo.locator('a, button, [role="button"]').first(),
  ];

  let pdf = null;
  for (const candidato of candidatosDescarga) {
    if ((await candidato.count().catch(() => 0)) === 0) continue;
    pdf = await intentarCapturarPdf(context, page, () => candidato.click({ timeout: 5000 }).catch(() => {}));
    if (pdf) break;
  }

  if (!pdf) {
    await page.screenshot({ path: path.join(__dirname, `debug_pdf_${etiquetaDebug}.png`), fullPage: true }).catch(() => {});
    console.log(`    AVISO: no se pudo descargar el PDF desde el modal del documento -- revisa debug_pdf_${etiquetaDebug}.png.`);
  }
  await cerrarCualquierModalAbierto(page);
  return pdf;
}

// Login confirmado que SÍ funciona (corrida real: "Login PJF OK") -- se
// mantiene igual, vía API directa, en vez de llenar el formulario visible
// (del que no conocemos los selectores exactos). Regresa el CookieJar ya
// autenticado, para pasarle esas cookies a Playwright después.
async function loginPJF(usuario, password) {
  const jar = new CookieJar();
  const cliente = wrapper(axios.create({ jar, timeout: 20000 }));
  const params = new URLSearchParams();
  params.append('EntidadExterna', 'Laboral');
  params.append('UserName', usuario);
  params.append('UserPassword', password);
  params.append('tipo', '3');
  const res = await cliente.post(`${PJF_BASE}/Home/Login`, params.toString(), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  const data = typeof res.data === 'string' ? JSON.parse(res.data) : res.data;
  if (!data.success) throw new Error('Login PJF falló: ' + (data.error || 'motivo desconocido'));
  return jar;
}

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

// Mismo problema ya documentado en el robot de jurisprudencia: un texto
// puede existir duplicado en el DOM (version de escritorio/movil, un menu
// colapsado que sigue en el HTML aunque este oculto, etc.) -- se busca
// entre todos los candidatos el primero que de verdad este visible.
async function clickTextoVisible(page, texto, exact = true) {
  const candidatos = page.getByText(texto, { exact });
  const n = await candidatos.count();
  for (let i = 0; i < n; i++) {
    const el = candidatos.nth(i);
    if (await el.isVisible().catch(() => false)) {
      await el.click();
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
  await page.screenshot({ path: path.join(__dirname, `debug_modal_${etiquetaDebug}.png`), fullPage: true }).catch(() => {});

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

async function leerFilasVistaGlobal(page) {
  const tabla = page.locator('table').filter({ has: page.getByText('Número de expediente') }).first();
  await tabla.waitFor({ timeout: 15000 });
  return leerTablaPorEncabezados(tabla);
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
async function procesarExpediente(context, page, numeroExpediente, match, estado) {
  const ok = await page.getByText('Fecha de Acuerdo', { exact: false }).first()
    .waitFor({ timeout: 15000 }).then(() => true).catch(() => false);
  if (!ok) {
    console.log(`    AVISO: no cargó la tabla de acuerdos del expediente ${numeroExpediente} -- se omite esta vez.`);
    return 0;
  }
  const tabla = page.locator('table').filter({ has: page.getByText('Fecha de Acuerdo') }).first();
  await maximizarEntradasPorPagina(page, tabla);
  const filas = await leerTablaPorEncabezados(tabla);

  const key = normalizeExp(numeroExpediente);
  const ultimoVisto = estado[key] || 0;
  let maxNo = ultimoVisto;
  let reportados = 0;
  for (const fila of filas) {
    const no = parseInt((fila['No.'] || {}).texto || '', 10);
    if (!no || no <= ultimoVisto) continue;
    const fechaAcuerdo = (fila['Fecha de Acuerdo'] || {}).texto || '';
    const fechaPublicacion = (fila['Fecha de Publicación'] || {}).texto || '';
    const resumen = `Acuerdo No. ${no} del expediente ${numeroExpediente}, de fecha ${fechaAcuerdo || '(sin fecha)'}`
      + (fechaPublicacion ? `, publicado el ${fechaPublicacion}.` : '.');

    let documentoId = null;
    try {
      const celda = celdaBotonDocumento(fila);
      const pdf = celda ? await descargarPdfAcuerdo(context, page, celda, `${sanitizarParaArchivo(key)}_${no}`) : null;
      if (pdf) {
        documentoId = await subirPdfAlSistema(pdf, match.id, `Acuerdo_No_${no}_Exp_${numeroExpediente.replace(/\//g, '-')}.pdf`);
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

    await reportarAviso(match.id, resumen, parseFechaMX(fechaPublicacion || fechaAcuerdo), documentoId);
    reportados++;
    console.log(`    Aviso reportado: expediente ${numeroExpediente}, acuerdo No. ${no}.`);
    if (no > maxNo) maxNo = no;
  }
  estado[key] = maxNo;
  return reportados;
}

async function revisarCuenta(cuenta, porNumero, estado, browser) {
  console.log(`--- Cuenta: ${cuenta.nombre} (${cuenta.pjf_usuario}) ---`);
  const jar = await loginPJF(cuenta.pjf_usuario, cuenta.pjf_password);
  console.log('  Login PJF OK.');

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
    await page.goto(`${PJF_BASE}/Home/OpcionesFirel`, { waitUntil: 'networkidle' });
    await cerrarModalSiAparece(page, cuenta.pjf_usuario);
    // De respaldo, por si quedó huérfano el fondo oscuro del modal que se
    // acaba de cerrar (confirmado real: pasó en las 2 cuentas de la misma
    // corrida, justo después del clic forzado) -- nunca está de más.
    await cerrarCualquierModalAbierto(page);
    // "Consulta de Expediente Electrónico" es un menú con submenú (se
    // confirmó con una corrida real: el texto de la página mostraba
    // "...Consulta de Expediente Electrónico Vista Global Vista por Órgano
    // Jurisdiccional..." justo después de darle clic) -- hace falta un
    // segundo clic en "Vista Global" para que de verdad aparezca la tabla.
    let entroMenu = await clickTextoVisible(page, 'Consulta de Expediente Electrónico', false).catch(() => false);
    if (!entroMenu) {
      // Pudo haber quedado un modal/fondo oscuro bloqueando el clic sin
      // avisar -- confirmado con una corrida real: timeout de 30s en este
      // mismo clic justo después de cerrar la ventana de "Seleccione un
      // perfil" con clic forzado. Se limpia y se reintenta una vez antes
      // de darse por vencido.
      await cerrarCualquierModalAbierto(page);
      entroMenu = await clickTextoVisible(page, 'Consulta de Expediente Electrónico', false).catch(() => false);
    }
    if (!entroMenu) {
      console.log('  AVISO: no se encontró el menú "Consulta de Expediente Electrónico" -- el portal pudo cambiar otra vez.');
      await page.screenshot({ path: path.join(__dirname, `debug_error_${cuenta.pjf_usuario}.png`), fullPage: true }).catch(() => {});
      return 0;
    }
    await page.waitForTimeout(500);
    const entroVistaGlobal = await clickTextoVisible(page, 'Vista Global', false);
    if (!entroVistaGlobal) {
      console.log('  AVISO: no se encontró "Vista Global" dentro del submenú -- el portal pudo cambiar otra vez.');
      await page.screenshot({ path: path.join(__dirname, `debug_error_${cuenta.pjf_usuario}.png`), fullPage: true }).catch(() => {});
      return 0;
    }

    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(1000);
    let filas;
    try {
      filas = await leerFilasVistaGlobal(page);
    } catch (e) {
      console.log('  AVISO: no apareció la tabla de expedientes después de entrar al menú -- se deja evidencia para diagnosticar.');
      await page.screenshot({ path: path.join(__dirname, `debug_error_${cuenta.pjf_usuario}.png`), fullPage: true }).catch(() => {});
      const url = page.url();
      const textoPagina = await page.locator('body').innerText().catch(() => '(no se pudo leer)');
      console.log(`  URL en ese momento: ${url}`);
      console.log(`  Texto visible en ese momento (primeros 500 caracteres): "${textoPagina.replace(/\s+/g, ' ').trim().slice(0, 500)}"`);
      return 0;
    }
    console.log(`  ${filas.length} expediente(s) visibles en el portal para esta cuenta.`);

    for (const fila of filas) {
      const numExp = (fila['Número de expediente'] || {}).texto || '';
      const match = porNumero.get(normalizeExp(numExp));
      if (!match) continue;

      const botonVer = (fila['Ver Expediente Electrónico'] || {}).celda;
      if (!botonVer) continue;
      const boton = botonVer.locator('button, a, [role="button"]').first();
      try {
        await boton.click();
        reportados += await procesarExpediente(context, page, numExp, match, estado);
      } catch (e) {
        console.log(`    Error revisando el expediente ${numExp}: ${e.message}`);
        await page.screenshot({ path: path.join(__dirname, `debug_error_${cuenta.pjf_usuario}_${sanitizarParaArchivo(normalizeExp(numExp))}.png`), fullPage: true }).catch(() => {});
      }

      // Regresa a la vista global para poder abrir el siguiente expediente.
      // Si algo (ej. un modal que quedó atorado) truena aquí, no debe
      // tirar TODO el resto de los expedientes de esta cuenta -- se limpia
      // y se cae al respaldo de volver a entrar desde cero.
      const regreso = await clickTextoVisible(page, 'Regresar', false).catch(() => false);
      if (!regreso) {
        await cerrarCualquierModalAbierto(page);
        // Respaldo: si no hay botón "Regresar" visible, se vuelve a entrar
        // al menú desde cero.
        await page.goto(`${PJF_BASE}/Home/OpcionesFirel`, { waitUntil: 'networkidle' }).catch(() => {});
        await clickTextoVisible(page, 'Consulta de Expediente Electrónico', false).catch(() => false);
        await page.waitForTimeout(500);
        await clickTextoVisible(page, 'Vista Global', false).catch(() => false);
      }
      await leerFilasVistaGlobal(page).catch(() => {});
    }
  } finally {
    await context.close();
  }
  console.log(`  ${reportados} aviso(s) nuevo(s) para esta cuenta.`);
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
    return;
  }

  const estado = cargarProcesados();
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
}

main().catch(err => {
  console.error('Error en checker Federal:', err.message);
  process.exit(1);
});
