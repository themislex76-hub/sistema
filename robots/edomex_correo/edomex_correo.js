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
  const ultimoVisto = estado[cuenta.edomex_correo_usuario] || null;
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

    const n = await filas.count();
    console.log(`  ${n} correo(s) de "Notificacion Electronica Laboral" visibles en la bandeja.`);
    let maxVistoEstaCorrida = ultimoVisto;
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
      if (ultimoVisto !== null && Number(idConversacion) <= Number(ultimoVisto)) {
        console.log(`    Correo ${idConversacion} ya visto antes (<= ${ultimoVisto}) -- la lista está ordenada del más nuevo al más viejo, se deja de revisar.`);
        break;
      }

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
      } else {
        const ligaDocumento = extraerLigaDocumento(texto);
        let documentoId = null;
        if (ligaDocumento) {
          try {
            const r = await context.request.get(ligaDocumento);
            const buf = r.ok() ? await r.body() : null;
            const nombreDestino = `Acuerdo_Exp_${sanitizarParaArchivo(expedienteTexto)}_${idConversacion}.pdf`;
            if (pdfEsValido(buf)) {
              documentoId = await subirPdfAlSistema(buf, match.id, nombreDestino);
            } else if (zipEsValido(buf)) {
              // Confirmado real: la liga entrega un .zip con el/los
              // documento(s) del acuerdo adentro, no el PDF directo.
              const extraido = extraerPdfDeZip(buf);
              if (extraido) {
                documentoId = await subirPdfAlSistema(extraido.buf, match.id, nombreDestino);
                console.log(`    (PDF extraído del zip: ${extraido.nombre})`);
              } else {
                console.log(`    AVISO: el zip del documento no traía ningún PDF válido adentro (correo ${idConversacion}).`);
              }
            } else {
              // Diagnóstico: sin esto no se puede saber si la liga vino
              // incompleta (cortada por un salto de línea en el correo),
              // si el servidor regresó una página de error/login, o si de
              // plano el token ya expiró.
              console.log(`    AVISO: la liga del documento no regresó un PDF ni un zip reconocible (correo ${idConversacion}).`);
              console.log(`      Liga extraída (${ligaDocumento.length} caracteres): ${ligaDocumento.slice(0, 120)}...`);
              console.log(`      Respuesta HTTP: status ${r.status()}, content-type "${r.headers()['content-type'] || '(sin dato)'}", ${buf ? buf.length : 0} bytes, primeros bytes: ${buf ? buf.slice(0, 40).toString('latin1').replace(/[^\x20-\x7e]/g, '.') : '(sin cuerpo)'}`);
            }
          } catch (e) {
            console.log(`    (no se pudo bajar el PDF del correo ${idConversacion}: ${e.message})`);
          }
        } else {
          console.log(`    AVISO: no se encontró la liga de "frmGetDocument.php" en el correo ${idConversacion}.`);
        }

        const resumen = `Notificación electrónica Edomex -- expediente ${expedienteTexto}`
          + (juzgado ? `, ${juzgado}` : '') + `. Actor: ${actor || '(sin dato)'}. Demandado: ${demandado || '(sin dato)'}.`;
        await reportarAviso(match.id, resumen, new Date().toISOString().slice(0, 10), documentoId);
        reportados++;
        console.log(`    Aviso reportado: expediente ${expedienteTexto} (correo ${idConversacion}).`);
      }

      if (maxVistoEstaCorrida === null || Number(idConversacion) > Number(maxVistoEstaCorrida)) {
        maxVistoEstaCorrida = idConversacion;
        // Se guarda en CADA correo procesado, no solo al final del ciclo
        // -- confirmado real: si la página truena a medio lote (ej. "Target
        // page, context or browser has been closed"), el error salta a este
        // catch y el ciclo nunca llega a la línea de después del `for`, así
        // que sin esto los avisos ya reportados en ese lote se repetirían
        // (duplicados) en la siguiente corrida.
        if (maxVistoEstaCorrida !== null) estado[cuenta.edomex_correo_usuario] = maxVistoEstaCorrida;
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
    guardarProcesados(estado);
  }
  await browser.close();

  console.log(`Listo. ${totalReportados} aviso(s) nuevo(s) reportado(s) en total.`);
}

main().catch(err => {
  console.error('Error en el robot de correo Edomex:', err.message);
  process.exit(1);
});
