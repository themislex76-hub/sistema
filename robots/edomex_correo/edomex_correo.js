// Robot de monitoreo del correo de notificaciones electrónicas del Poder
// Judicial del Estado de México (Tribunales Laborales, región Tlalnepantla,
// mail.pjedomex.gob.mx -- webmail Carbonio, SIN acceso IMAP, solo por la
// página con usuario y contraseña).
//
// Reemplaza al robot viejo (robots/edomex/edomex.js), que leía el boletín
// público con captcha y tenía que "adivinar" si un caso coincidía por
// nombre de las partes. Aquí cada acuerdo llega por correo, dirigido
// personalmente al abogado, con el expediente/partes/juzgado ya
// estructurados Y una liga directa (con token) para bajar el PDF del
// acuerdo -- mucho más confiable, sin necesidad del filtro de seguridad por
// nombres.
//
// IMPORTANTE -- primera corrida real: esto se construyó viendo capturas de
// pantalla del correo (el login y la bandeja), nunca se probó en vivo desde
// aquí (sin acceso a mail.pjedomex.gob.mx en esta sesión). Es muy probable
// que algún selector no funcione a la primera, sobre todo al abrir un
// correo y leer su contenido (puede venir dentro de un <iframe>, como pasó
// con el robot Federal). Si truena, manda el mensaje de error COMPLETO de
// la terminal y, si se generó, el archivo debug_error_<cuenta>.png que se
// guarda en esta misma carpeta -- con eso se ajusta rápido, no se puede
// adivinar el selector exacto sin eso.
const { chromium } = require('playwright');
const axios = require('axios');
const fs = require('fs');
const path = require('path');
const AdmZip = require('adm-zip');
const config = require('./config');

const MAIL_BASE = 'https://mail.pjedomex.gob.mx';
const ESTADO_FILE = path.join(__dirname, 'procesados_edomex_correo.json');

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

function sinAcentos(s) {
  return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase();
}

// El boletin/correo de Edomex trae el numero de expediente con ceros a la
// izquierda a veces (ej. "00479/2025"), pero en el sistema se capturan sin
// ellos (ej. "479/2025") -- mismo normalizador que ya usa robots/edomex/edomex.js.
function normalizeExp(s) {
  const limpio = (s || '').replace(/\s+/g, '').toUpperCase();
  const m = /^0*(\d+)\/(\d{4})$/.exec(limpio);
  return m ? m[1] + '/' + m[2] : limpio;
}

function sanitizarParaArchivo(s) {
  return (s || '').replace(/[\/\\]/g, '-');
}

// Confirmado real: lo "descargado" puede ser una página de error/login en
// vez del PDF -- se valida que de verdad empiece con la cabecera mágica de
// PDF antes de aceptarlo (mismo criterio que ya usa robots/federal/federal.js).
function pdfEsValido(buf) {
  return !!buf && buf.length > 4 && buf.slice(0, 4).toString('latin1') === '%PDF';
}

// Confirmado real (8 nov 2026): la liga de "Descargar Documentos" del
// correo de Edomex no entrega el PDF directo -- entrega un .zip (cabecera
// mágica "PK") con el/los documento(s) del acuerdo adentro.
function zipEsValido(buf) {
  return !!buf && buf.length > 4 && buf[0] === 0x50 && buf[1] === 0x4b;
}

// Busca el primer PDF real (valida la cabecera "%PDF" de cada entrada, no
// solo la extensión) dentro del zip. Regresa {buf, nombre} o null si no
// traía ningún PDF válido.
function extraerPdfDeZip(buf) {
  try {
    const zip = new AdmZip(buf);
    const entradas = zip.getEntries().filter(e => !e.isDirectory);
    // Primero las que ya se llaman .pdf, luego cualquier otra por si el
    // nombre no trae la extensión.
    const ordenadas = [
      ...entradas.filter(e => /\.pdf$/i.test(e.entryName)),
      ...entradas.filter(e => !/\.pdf$/i.test(e.entryName)),
    ];
    for (const entrada of ordenadas) {
      const datos = entrada.getData();
      if (pdfEsValido(datos)) return { buf: datos, nombre: entrada.entryName };
    }
    return null;
  } catch (e) {
    console.log(`    (no se pudo abrir el zip del documento: ${e.message})`);
    return null;
  }
}

// Intenta bajar y validar el documento de la liga del correo -- hasta 5
// intentos completos (24 nov 2026, pedido explícito del usuario tras el
// caso del robot Federal: "que el robot haga más intentos para descargar
// los documentos y no trasladarle la responsabilidad al abogado"). La
// liga puede regresar el PDF directo o un .zip con el PDF adentro
// (confirmado real, ver zipEsValido()/extraerPdfDeZip()) -- un fallo
// transitorio de red entre la liga y el servidor del PJ Edomex es el caso
// más probable que esto resuelva solo; si el token del JWT ya expiró,
// ningún reintento va a ayudar, pero tampoco cuesta nada intentarlo.
async function obtenerPdfDeLigaConReintentos(context, page, ligaDocumento, etiquetaDebug, maxIntentos = 5) {
  let ultimoDiagnostico = '(sin detalle)';
  for (let intento = 1; intento <= maxIntentos; intento++) {
    try {
      const r = await context.request.get(ligaDocumento, { timeout: 15000 });
      const buf = r.ok() ? await r.body() : null;
      if (pdfEsValido(buf)) return { buf, nombre: null };
      if (zipEsValido(buf)) {
        const extraido = extraerPdfDeZip(buf);
        if (extraido) return extraido;
        ultimoDiagnostico = 'el zip no traía ningún PDF válido adentro';
      } else {
        ultimoDiagnostico = `status ${r.status()}, content-type "${r.headers()['content-type'] || '(sin dato)'}", ${buf ? buf.length : 0} bytes`;
      }
    } catch (e) {
      ultimoDiagnostico = e.message;
    }
    if (intento < maxIntentos) {
      console.log(`    (intento ${intento}/${maxIntentos} sin éxito para el documento de ${etiquetaDebug}: ${ultimoDiagnostico} -- reintentando...)`);
      await page.waitForTimeout(2000 * intento);
    }
  }
  console.log(`    AVISO: se agotaron los ${maxIntentos} intentos para el documento de ${etiquetaDebug} (${ultimoDiagnostico}) -- queda pendiente para reintentarse en una corrida futura.`);
  return null;
}

async function obtenerExpedientesMonitoreados() {
  const res = await axios.get(`${config.sistema.apiBase}/expedientes_monitorear.php`, {
    headers: { 'X-Robot-Key': config.sistema.robotKey },
  });
  return res.data.data.expedientes;
}

async function obtenerCuentasEdomexCorreo() {
  const res = await axios.get(`${config.sistema.apiBase}/edomex_correo_credenciales_listar.php`, {
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
    console.log(`    (no se pudo subir el PDF al sistema: ${e.message})`);
    return null;
  }
}

async function reportarAviso(expedienteId, resumen, fecha, documentoId) {
  await axios.post(`${config.sistema.apiBase}/avisos_ingest.php`, {
    expediente_id: expedienteId,
    fuente: 'edomex_local',
    resumen,
    fecha_publicacion: fecha,
    documento_id: documentoId || undefined,
  }, { headers: { 'X-Robot-Key': config.sistema.robotKey } });
}

// Le avisa al sistema que este robot corrió (con éxito o con error) --
// pedido explícito del usuario (20 nov 2026) tras descubrir que otros 2
// robots llevaban sin correr en automático por un run_diario.bat faltante
// y nadie lo notó. Nunca debe tronar el robot si esto falla (ej. sin
// internet en ese momento) -- se atrapa y se sigue de largo.
async function reportarHeartbeat(ok, resumen) {
  try {
    await axios.post(`${config.sistema.apiBase}/robot_heartbeat.php`, {
      robot: 'edomex_correo',
      ok: ok ? 1 : 0,
      resumen: String(resumen || '').slice(0, 250),
    }, { headers: { 'X-Robot-Key': config.sistema.robotKey } });
  } catch (e) {
    console.log('  (no se pudo avisar el heartbeat al sistema: ' + e.message + ')');
  }
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

// Busca un texto en la página principal O en cualquiera de sus frames hijos
// (el cuerpo del correo suele venir sandboxeado en un <iframe>, igual que
// el modal del robot Federal). Regresa el texto completo de dondequiera
// que se encontró, o null si no se encontró en ningún lado.
// Confirmado real: justo después de darle clic a la fila, el contenido del
// correo (el iframe) a veces todavía no termina de cargar -- se reintenta
// unas cuantas veces con pausas chicas antes de darse por vencido, en vez
// de checar una sola vez.
async function leerTextoCompleto(page, pistaTexto, intentos = 8, esperaMs = 700) {
  for (let i = 0; i < intentos; i++) {
    const delMainFrame = await page.locator('body').innerText().catch(() => '');
    if (delMainFrame.includes(pistaTexto)) return delMainFrame;
    for (const frame of page.frames()) {
      if (frame === page.mainFrame()) continue;
      const texto = await frame.locator('body').innerText({ timeout: 3000 }).catch(() => null);
      if (texto && texto.includes(pistaTexto)) return texto;
    }
    if (i < intentos - 1) await page.waitForTimeout(esperaMs);
  }
  return null;
}

function extraerCampo(texto, etiqueta) {
  const re = new RegExp(etiqueta + ':\\s*([^\\n]+)', 'i');
  const m = re.exec(texto);
  return m ? m[1].trim() : '';
}

function extraerLigaDocumento(texto) {
  const m = /https?:\/\/\S*frmGetDocument\.php\?token=\S+/i.exec(texto);
  if (!m) return null;
  // Confirmado real (captura del correo): el token viene envuelto entre
  // comillas simples en el texto -- "token='eyJ...A'" -- y esas comillas
  // NO son parte del token real. Al incluirlas, el servidor nunca
  // regresaba el PDF (token inválido). Se quitan junto con cualquier otra
  // puntuación que se haya colado al final de la liga.
  return m[0].replace(/token=['"]/i, 'token=').replace(/['")(,.]+$/, '');
}

async function procesarCuenta(cuenta, porNumero, estado, browser) {
  console.log(`--- Cuenta: ${cuenta.nombre} (${cuenta.edomex_correo_usuario}) ---`);
  const context = await browser.newContext();
  const page = await context.newPage();
  page.on('pageerror', (err) => console.log(`    [error de JS en la página] ${err.message}`));
  let reportados = 0;
  const key = normalizeExp(cuenta.edomex_correo_usuario); // solo para el estado por cuenta, no un expediente
  // Antes se guardaba solo el id MÁS GRANDE visto (una especie de "marca de
  // agua"), asumiendo que un id mayor siempre es más nuevo y que la
  // bandeja está ordenada de forma monótona -- confirmado real (19 nov
  // 2026, mismo expediente 841/2026 reportado 2 veces en corridas
  // distintas) que esa suposición no es segura: no se puede confiar en
  // que "mayor" signifique "más nuevo" para estos ids, ni en el orden de
  // la bandeja. Ahora se guarda el conjunto COMPLETO de ids ya procesados
  // por esta cuenta, para siempre, y se compara por pertenencia -- sin
  // asumir nada sobre el orden ni sobre qué id es más nuevo.
  //
  // (24 nov 2026) Se agrega `pendientesPdf` -- el conjunto de ids de
  // correos ya vistos (reportados como aviso) pero cuyo PDF no se logró
  // descargar esa vez. A diferencia de un correo sin documento que nunca
  // se vuelve a tocar, uno en `pendientesPdf` SÍ se reabre en corridas
  // futuras para reintentar (mismo criterio que ya se agregó en
  // federal.js el mismo día) -- no hace falta guardar nada más que el id:
  // como el correo sigue en la bandeja, re-abrirlo en una corrida futura
  // vuelve a dar el mismo texto/liga de siempre, así que todo se puede
  // volver a derivar fresco en el momento. `estado[cuenta...]` pasa de ser
  // un arreglo plano de ids a `{vistos, pendientesPdf}` -- compatible con
  // el formato viejo (un arreglo suelto se interpreta como `vistos`, con
  // `pendientesPdf` vacío).
  const estadoPrevio = estado[cuenta.edomex_correo_usuario];
  const estadoCuenta = Array.isArray(estadoPrevio)
    ? { vistos: estadoPrevio, pendientesPdf: [] }
    : { vistos: [], pendientesPdf: [], ...(estadoPrevio || {}) };
  const yaProcesados = new Set(estadoCuenta.vistos);
  const pendientesPdf = new Set(estadoCuenta.pendientesPdf);
  const persistirEstadoCuenta = () => {
    estado[cuenta.edomex_correo_usuario] = { vistos: Array.from(yaProcesados), pendientesPdf: Array.from(pendientesPdf) };
  };
  try {
    // El login exacto (nombres de los campos) no se pudo ver en vivo desde
    // aquí -- se intenta por el texto de la etiqueta ("Nombre de usuario"/
    // "Contraseña") y, de respaldo, por el primer input de texto/password
    // de la página.
    await page.goto(`${MAIL_BASE}/static/login/`, { waitUntil: 'networkidle' });
    const campoUsuario = page.locator('input[type="text"], input[type="email"]').first();
    const campoPassword = page.locator('input[type="password"]').first();
    await campoUsuario.waitFor({ state: 'visible', timeout: 15000 });
    await campoUsuario.fill(cuenta.edomex_correo_usuario);
    await campoPassword.fill(cuenta.edomex_correo_password);
    const entroLogin = await clickTextoVisible(page, 'INICIAR SESIÓN', false, 10000)
      || await clickTextoVisible(page, 'Iniciar sesión', false, 5000);
    if (!entroLogin) {
      // Respaldo: el botón puede ser un <button type="submit"> sin este
      // texto exacto -- se intenta con Enter en el campo de contraseña.
      await campoPassword.press('Enter').catch(() => {});
    }
    await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});

    // La bandeja lista los correos con el asunto visible -- se busca el
    // texto "Notificacion Electronica Laboral" (sin acentos, por si el
    // encoding real trae o no los acentos) entre los renglones de la
    // lista. Cada renglón, al dársele clic, cambia la URL a
    // ".../conversation/-<id>" (confirmado con una captura real) -- se usa
    // ese id numérico para no reprocesar lo ya visto.
    const filas = page.locator('text=/Notificacion Electronica Laboral/i');
    // Confirmado real: a veces la bandeja tarda en cargar la lista de
    // correos (el resto de la página -- carpetas, "Inbox 100" -- ya está)
    // más de lo esperado, de forma intermitente, y a veces ni con una
    // recarga de por medio basta (confirmado: falló 2 veces seguidas con
    // 45s+recarga). Se intenta varias veces, con esperas cada vez más
    // largas y una recarga entre cada intento, antes de darse por vencido.
    const esperasMs = [45000, 60000, 90000];
    let hayBandeja = false;
    for (let intento = 0; intento < esperasMs.length; intento++) {
      if (intento > 0) {
        console.log(`  (la bandeja no cargó -- se recarga la página y se espera ${esperasMs[intento] / 1000}s más, intento ${intento + 1}/${esperasMs.length})`);
        await page.reload({ waitUntil: 'networkidle' }).catch(() => {});
      }
      hayBandeja = await filas.first().waitFor({ state: 'visible', timeout: esperasMs[intento] }).then(() => true).catch(() => false);
      if (hayBandeja) break;
    }
    if (!hayBandeja) {
      console.log('  AVISO: no apareció la bandeja de entrada (o no hay correos de "Notificacion Electronica Laboral") -- el portal pudo cambiar.');
      await page.screenshot({ path: path.join(__dirname, `debug_error_${cuenta.edomex_correo_usuario}.png`), fullPage: true, timeout: 60000 })
        .then(() => console.log(`  Captura guardada: debug_error_${cuenta.edomex_correo_usuario}.png`))
        .catch((e) => console.log(`  (no se pudo guardar la captura: ${e.message})`));
      const textoBody = await page.locator('body').innerText().catch((e) => `(no se pudo leer: ${e.message})`);
      console.log(`  Texto visible de la página (primeros 800 caracteres): ${textoBody.replace(/\s+/g, ' ').trim().slice(0, 800)}`);
      return 0;
    }

    // La bandeja de Carbonio NO tiene botón de "cargar más" -- solo carga
    // correos más viejos conforme se hace scroll hacia abajo (confirmado
    // real por el usuario). Se baja hasta el final SIEMPRE, en cada
    // corrida (no solo la primera vez) -- confirmado real (18 nov 2026)
    // que el orden de la bandeja NO es estrictamente del más nuevo al más
    // viejo (un mismo id de conversación puede reaparecer en una posición
    // muy distinta del listado entre una lectura y otra, probablemente
    // porque Carbonio ordena por actividad de la conversación, no por el
    // id), así que detenerse en cuanto aparece un id "ya visto" ya no es
    // seguro -- un acuerdo real podría quedar más abajo de uno viejo y
    // nunca llegar a revisarse si solo se carga el primer lote. Se baja
    // hasta que el número de filas deja de crecer 3 veces seguidas (la
    // bandeja ya cargó todo lo que tiene) o se llega a un tope de vueltas,
    // por seguridad.
    console.log('  Se hace scroll hasta el final de la bandeja para cargar todo el historial.');
    let conteoAnterior = -1;
    let vecesIgual = 0;
    for (let vuelta = 0; vuelta < 80 && vecesIgual < 3; vuelta++) {
      const conteoActual = await filas.count();
      vecesIgual = conteoActual === conteoAnterior ? vecesIgual + 1 : 0;
      conteoAnterior = conteoActual;
      await filas.last().scrollIntoViewIfNeeded().catch(() => {});
      await page.waitForTimeout(1200);
    }
    console.log(`  Scroll terminado: ${await filas.count()} correo(s) cargado(s) en total.`);

    const n = await filas.count();
    console.log(`  ${n} correo(s) de "Notificacion Electronica Laboral" visibles en la bandeja.`);
    let yaAvisoDiagnostico = false;
    for (let i = 0; i < n; i++) {
      // Pausa chica entre fila y fila -- confirmado real: la página se
      // cayó ("Page crashed") después de varios clics seguidos sin
      // esperar, probablemente por abrir correos muy rápido uno tras
      // otro dentro de la misma app (Carbonio).
      await page.waitForTimeout(800);
      const fila = filas.nth(i);
      await fila.scrollIntoViewIfNeeded().catch(() => {});
      // Confirmado por el usuario (con capturas reales de la bandeja): el
      // texto que matchea es un <span> suelto adentro de la fila -- el
      // clic que de verdad abre el correo es en el CONTENEDOR de la fila
      // completa (remitente+asunto+fecha), no en ese texto. No se sabe
      // cuántos niveles hay que subir, así que se prueba el elemento y
      // hasta 4 niveles de padres, deteniéndose en el primero que sí
      // cambia la URL.
      let objetivo = fila;
      for (let nivel = 0; nivel <= 4; nivel++) {
        await objetivo.click({ timeout: 5000, force: true }).catch(() => {});
        const cambio = await page.waitForURL(/conversation\/-?\d+/, { timeout: 3000 }).then(() => true).catch(() => false);
        if (cambio) break;
        objetivo = objetivo.locator('..');
      }
      const url = page.url();
      const idConversacion = (/conversation\/(-?\d+)/.exec(url) || [])[1];
      if (!idConversacion) {
        console.log(`    (fila ${i}: no se pudo leer el id de conversación de la URL "${url}" -- se omite)`);
        if (!yaAvisoDiagnostico) {
          yaAvisoDiagnostico = true;
          await page.screenshot({ path: path.join(__dirname, `debug_fila_${i}.png`), fullPage: true }).catch(() => {});
          console.log(`    Captura guardada: debug_fila_${i}.png (para ver qué pasó al darle clic a esta fila)`);
        }
        continue;
      }
      const esReintentoPendiente = pendientesPdf.has(idConversacion);
      if (yaProcesados.has(idConversacion) && !esReintentoPendiente) {
        // Ya se vio este id antes y ya quedó resuelto (con documento, o
        // sin expediente monitoreado que coincida) -- en esta misma
        // corrida (el locator `filas` confirmado real que hace match más
        // de una vez por renglón, ver abajo) o en una corrida anterior. Se
        // omite sin volver a subir el PDF ni reportar el aviso otra vez.
        continue;
      }
      // Se marca como visto y se guarda EN CUANTO se identifica el id (no
      // solo si matchea un expediente monitoreado, ni solo al final del
      // ciclo) -- confirmado real: si la página truena a medio lote (ej.
      // "Target page, context or browser has been closed"), el error salta
      // al catch de afuera y el ciclo nunca llega más allá de donde iba,
      // así que sin guardar aquí mismo los correos ya procesados en ese
      // lote se repetirían (duplicados) en la siguiente corrida.
      yaProcesados.add(idConversacion);
      persistirEstadoCuenta();

      const texto = await leerTextoCompleto(page, 'EXPEDIENTE');
      if (!texto) {
        console.log(`    AVISO: no se encontró el texto "EXPEDIENTE" en el correo ${idConversacion} (¿viene en un iframe con otro formato?) -- se omite.`);
        await page.screenshot({ path: path.join(__dirname, `debug_correo_${idConversacion}.png`), fullPage: true }).catch(() => {});
        continue;
      }

      const expedienteTexto = extraerCampo(texto, 'EXPEDIENTE');
      const actor = extraerCampo(texto, 'Actor');
      const demandado = extraerCampo(texto, 'Demandado');
      const juzgado = extraerCampo(texto, 'Juzgado');
      const match = porNumero.get(normalizeExp(expedienteTexto));
      if (!match) {
        console.log(`    Correo ${idConversacion}: expediente "${expedienteTexto}" no está en los monitoreados -- se omite.`);
        pendientesPdf.delete(idConversacion); // ya no aplica -- no tiene caso seguir reintentándolo
        persistirEstadoCuenta();
      } else {
        const ligaDocumento = extraerLigaDocumento(texto);
        let documentoId = null;
        if (ligaDocumento) {
          const nombreDestino = `Acuerdo_Exp_${sanitizarParaArchivo(expedienteTexto)}_${idConversacion}.pdf`;
          const obtenido = await obtenerPdfDeLigaConReintentos(context, page, ligaDocumento, `correo ${idConversacion}`);
          if (obtenido) {
            documentoId = await subirPdfAlSistema(obtenido.buf, match.id, nombreDestino);
            if (obtenido.nombre) console.log(`    (PDF extraído del zip: ${obtenido.nombre})`);
          }
        } else {
          console.log(`    AVISO: no se encontró la liga de "frmGetDocument.php" en el correo ${idConversacion}.`);
        }

        // (24 nov 2026) Si de verdad no se pudo conseguir el PDF ni con
        // los 5 intentos, el correo queda en `pendientesPdf` -- se vuelve
        // a reintentar solo, sin límite de tiempo, en cualquier corrida
        // futura (el correo sigue en la bandeja, así que reabrirlo más
        // adelante vuelve a dar el mismo texto/liga de siempre).
        if (documentoId) {
          pendientesPdf.delete(idConversacion);
        } else {
          pendientesPdf.add(idConversacion);
        }
        persistirEstadoCuenta();

        const resumen = `Notificación electrónica Edomex -- expediente ${expedienteTexto}`
          + (juzgado ? `, ${juzgado}` : '') + `. Actor: ${actor || '(sin dato)'}. Demandado: ${demandado || '(sin dato)'}.`;
        // Mismo resumen cada vez (se deriva siempre del mismo texto del
        // correo) -- en un reintento de una corrida futura, avisos_
        // ingest.php reconoce este mismo aviso ya existente y le adjunta
        // el documento en vez de tratarlo como duplicado (ver arreglo del
        // 24 nov 2026 ahí mismo).
        await reportarAviso(match.id, resumen, new Date().toISOString().slice(0, 10), documentoId);
        if (esReintentoPendiente && documentoId) {
          console.log(`    PDF recuperado en esta corrida para el correo ${idConversacion} (ya se había reportado antes sin documento).`);
        }
        reportados++;
        console.log(`    Aviso reportado: expediente ${expedienteTexto} (correo ${idConversacion}).`);
      }

    }
  } catch (e) {
    console.log(`  Error con la cuenta de ${cuenta.nombre}: ${e.message}`);
    await page.screenshot({ path: path.join(__dirname, `debug_error_${cuenta.edomex_correo_usuario}.png`), fullPage: true }).catch(() => {});
  } finally {
    await context.close();
  }
  console.log(`  ${reportados} aviso(s) nuevo(s) para esta cuenta.`);
  return reportados;
}

async function main() {
  console.log(new Date().toISOString(), 'Iniciando revisión de correo Edomex...');

  const expedientes = await obtenerExpedientesMonitoreados();
  const porNumero = new Map();
  for (const e of expedientes) {
    if (e.es_federal) continue;
    const candidatos = [e.junta, e.tribunal].filter(Boolean);
    const esEdomexLocal = candidatos.some(c => /tlalnepantla/i.test(c));
    if (esEdomexLocal && e.exp) porNumero.set(normalizeExp(e.exp), e);
  }
  console.log(`${porNumero.size} expediente(s) con tribunal local de Edomex (Tlalnepantla) capturado(s).`);

  const cuentas = await obtenerCuentasEdomexCorreo();
  console.log(`${cuentas.length} cuenta(s) de correo de notificaciones guardada(s).`);
  if (!cuentas.length) {
    console.log('Nadie ha guardado su correo de notificaciones de Edomex en "Mi cuenta" -- nada que revisar.');
    return 'sin cuentas guardadas -- nada que revisar';
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
    guardarProcesados(estado);
  }
  await browser.close();

  console.log(`Listo. ${totalReportados} aviso(s) nuevo(s) reportado(s) en total.`);
  return `${totalReportados} aviso(s) nuevo(s)`;
}

main()
  .then(resumen => reportarHeartbeat(true, resumen))
  .catch(async err => {
    console.error('Error en el robot de correo Edomex:', err.message);
    await reportarHeartbeat(false, 'Error: ' + err.message);
    process.exit(1);
  });
