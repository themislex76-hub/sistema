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
function sinAcentos(s) {
  return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase();
}

// El numero de expediente NO es unico entre tribunales -- distintos
// órganos reutilizan el mismo numero cada año (bug real detectado en el
// robot de CDMX con datos de producción). Se exige además que el resumen
// mencione a las partes antes de confiar en una coincidencia de número.
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

async function reportarAviso(expedienteId, resumen, fecha) {
  await axios.post(`${config.sistema.apiBase}/avisos_ingest.php`, {
    expediente_id: expedienteId,
    fuente: 'federal_laboral',
    resumen,
    fecha_publicacion: fecha,
  }, { headers: { 'X-Robot-Key': config.sistema.robotKey } });
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

// El portal muestra, al menos la primera vez después de iniciar sesión,
// una ventana emergente ("modalModalidad" -- "Asignar Modalidad") que tapa
// la pantalla y bloquea cualquier clic hasta que se cierra (confirmado con
// una corrida real: "subtree intercepts pointer events" apuntando
// exactamente a ese modal). Se imprime su texto en la consola (para saber
// qué decía, por si el botón correcto resulta ser otro) y se intenta
// cerrarla probando varios botones comunes, del más específico al más
// genérico.
async function cerrarModalSiAparece(page, etiquetaDebug) {
  const modal = page.locator('.modal.in, .modal.show').first();
  const hayModal = await modal.isVisible({ timeout: 3000 }).catch(() => false);
  if (!hayModal) return;

  const texto = await modal.innerText().catch(() => '(no se pudo leer el texto)');
  console.log(`  Apareció una ventana emergente al entrar -- texto: "${texto.replace(/\s+/g, ' ').trim().slice(0, 300)}"`);
  await page.screenshot({ path: path.join(__dirname, `debug_modal_${etiquetaDebug}.png`), fullPage: true }).catch(() => {});

  const botones = [
    modal.getByRole('button', { name: /aceptar|continuar|entendido|confirmar|cerrar/i }),
    modal.locator('button[data-dismiss="modal"], button.close, [aria-label="Close"]'),
    modal.getByRole('button').first(),
  ];
  for (const candidato of botones) {
    const n = await candidato.count();
    for (let i = 0; i < n; i++) {
      const el = candidato.nth(i);
      if (await el.isVisible().catch(() => false)) {
        await el.click().catch(() => {});
        await page.waitForTimeout(800);
        const sigueAhi = await modal.isVisible().catch(() => false);
        if (!sigueAhi) {
          console.log('  Ventana emergente cerrada.');
          return;
        }
      }
    }
  }
  console.log('  AVISO: no se pudo cerrar la ventana emergente automáticamente -- revisa debug_modal_' + etiquetaDebug + '.png para ver qué botón hace falta agregar.');
}

// Si la tabla (DataTables) permite elegir cuantas entradas mostrar por
// pagina ("Mostrar X entradas"), se pone el maximo disponible, para no
// tener que navegar pagina por pagina. No es grave si no lo encuentra.
async function maximizarEntradasPorPagina(page) {
  try {
    const select = page.getByText('Mostrar', { exact: false }).locator('..').locator('select').first();
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

// Procesa UN expediente ya abierto (después de darle clic al "ojito"):
// lee su tabla de acuerdos, reporta los que tengan un "No." mayor al que
// ya se tenía guardado para ese expediente, y regresa cuántos reportó.
async function procesarExpediente(page, numeroExpediente, match, estado) {
  const ok = await page.getByText('Fecha de Acuerdo', { exact: false }).first()
    .waitFor({ timeout: 15000 }).then(() => true).catch(() => false);
  if (!ok) {
    console.log(`    AVISO: no cargó la tabla de acuerdos del expediente ${numeroExpediente} -- se omite esta vez.`);
    return 0;
  }
  await maximizarEntradasPorPagina(page);

  const tabla = page.locator('table').filter({ has: page.getByText('Fecha de Acuerdo') }).first();
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
    if (!pareceElMismoCaso(`${resumen} ${numeroExpediente}`, match.actor, match.demandado)) {
      // Esto no debería pasar casi nunca aquí (ya se filtró por cuenta
      // autorizada + número de expediente), pero se deja la misma
      // protección que en CDMX por si dos expedientes federales
      // distintos comparten número.
      console.log(`    Expediente ${numeroExpediente}: acuerdo No. ${no} no menciona a "${match.actor}"/"${match.demandado}" -- se omite por seguridad.`);
    } else {
      await reportarAviso(match.id, resumen, parseFechaMX(fechaPublicacion || fechaAcuerdo));
      reportados++;
      console.log(`    Aviso reportado: expediente ${numeroExpediente}, acuerdo No. ${no}.`);
    }
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
  let reportados = 0;
  try {
    await page.goto(`${PJF_BASE}/Home/OpcionesFirel`, { waitUntil: 'networkidle' });
    await cerrarModalSiAparece(page, cuenta.pjf_usuario);
    const entroMenu = await clickTextoVisible(page, 'Consulta de Expediente Electrónico', false);
    if (!entroMenu) {
      console.log('  AVISO: no se encontró el menú "Consulta de Expediente Electrónico" -- el portal pudo cambiar otra vez.');
      await page.screenshot({ path: path.join(__dirname, `debug_error_${cuenta.pjf_usuario}.png`), fullPage: true }).catch(() => {});
      return 0;
    }

    const filas = await leerFilasVistaGlobal(page);
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
        reportados += await procesarExpediente(page, numExp, match, estado);
      } catch (e) {
        console.log(`    Error revisando el expediente ${numExp}: ${e.message}`);
        await page.screenshot({ path: path.join(__dirname, `debug_error_${cuenta.pjf_usuario}_${normalizeExp(numExp)}.png`), fullPage: true }).catch(() => {});
      }

      // Regresa a la vista global para poder abrir el siguiente expediente.
      const regreso = await clickTextoVisible(page, 'Regresar', false);
      if (!regreso) {
        // Respaldo: si no hay botón "Regresar" visible, se vuelve a entrar
        // al menú desde cero.
        await page.goto(`${PJF_BASE}/Home/OpcionesFirel`, { waitUntil: 'networkidle' });
        await clickTextoVisible(page, 'Consulta de Expediente Electrónico', false);
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
