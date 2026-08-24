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
// Recorre el listado completo pagina por pagina, no solo la primera, y
// marca tambien las epocas mas viejas (8a a 5a) que el sitio no
// selecciona por default. La primera corrida (biblioteca vacia) trae asi
// todo el historial de tesis laborales -- puede tardar horas. Las
// corridas siguientes son rapidas: en cuanto una pagina entera no aporta
// tesis nuevas, se detiene ahi (ver buscarTesisRecientes) -- a menos que
// se corra con el argumento --completo (node jurisprudencia.js --completo),
// que desactiva ese atajo y fuerza una recorrida de todo el listado. Hace
// falta usar --completo si se agregan mas filtros/epocas en el futuro.
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

// La fecha de la TARJETA del listado (arriba) falla seguido en tesis viejas
// -- el listado no siempre trae la etiqueta "Publicación:" en el mismo
// formato para épocas antiguas, así que muchas tesis se guardaban sin
// fecha (se detectó porque, tras bajar miles de tesis viejas, la fecha más
// vieja guardada en la base de datos seguía siendo de 2013 -- osea que
// casi ninguna tesis pre-2013 traía fecha real). La página de DETALLE de
// cada tesis, en cambio, siempre trae al final la misma frase fija sin
// importar la época: "Esta tesis se publicó el [día] DD de MES de YYYY...".
// Se usa un regex específico a esa frase (no el genérico de arriba) para
// no toparse por accidente con alguna otra fecha mencionada en el cuerpo
// de la tesis (fechas de ejecutorias, notas, citas a otras tesis, etc.).
function parseFechaPublicacionDetalle(texto) {
  const m = /publicó el \S+\s+(\d{1,2})\s+de\s+([a-záéíóú]+)\s+de\s+(\d{4})/i.exec(texto || '');
  if (!m) return null;
  const mes = MESES[m[2].toLowerCase()];
  if (!mes) return null;
  return m[3] + '-' + mes + '-' + m[1].padStart(2, '0');
}

// El sitio es una app Angular que duplica varios controles en el HTML (una
// version de escritorio y otra de movil -- la que no se usa sigue
// presente pero oculta con CSS), lo que revienta los locators por texto
// exacto con "strict mode violation: resolved to 2 elements". Esta
// funcion elige, de todos los que coincidan con el texto, el primero que
// de verdad este visible en pantalla.
async function clickTextoVisible(page, texto, exact = true) {
  const candidatos = page.getByText(texto, { exact });
  const n = await candidatos.count();
  for (let i = 0; i < n; i++) {
    const el = candidatos.nth(i);
    if (await el.isVisible().catch(() => false)) {
      await el.click();
      return;
    }
  }
  throw new Error('No se encontro ningun elemento visible con el texto "' + texto + '" (' + n + ' candidato(s) en el DOM).');
}

// La pantalla inicial trae una tabla de "epocas" (12a a 5a) con una
// casilla por cada combinacion valida de epoca/instancia -- por default
// solo vienen marcadas las epocas mas recientes (12a a 9a); las mas
// viejas (8a, 7a, 6a, 5a) quedan sin marcar, y si no se corrige aqui el
// robot nunca trae ese historial (se queda solo con tesis de los ultimos
// anos). Cada casilla sin marcar muestra junto su etiqueta visible ("8a.
// Epoca", etc, repetida una vez por instancia aplicable) -- se le da clic
// a todas las que aparezcan de cada epoca vieja.
async function seleccionarEpocasAntiguas(page) {
  const epocas = ['8a. Época', '7a. Época', '6a. Época', '5a. Época'];
  // Antes esto fallaba en TOTAL silencio si las casillas no estaban visibles
  // todavia cuando corria (el sitio tarda en cargar esa seccion, o cambio de
  // estructura) -- la corrida seguia sin avisar, solo con las epocas
  // recientes del default, y nunca se notaba hasta que alguien comparaba el
  // total contra el sitio real. Ahora se cuenta y se reporta cada caso, para
  // que un fallo aqui se vea de inmediato en la consola en vez de descubrirse
  // semanas despues.
  const marcadas = [];
  const noEncontradas = [];
  for (const etiqueta of epocas) {
    const candidatos = page.getByText(etiqueta, { exact: true });
    const n = await candidatos.count();
    let seMarco = false;
    for (let i = 0; i < n; i++) {
      const el = candidatos.nth(i);
      if (await el.isVisible().catch(() => false)) {
        await el.click().catch(() => {});
        await page.waitForTimeout(200);
        seMarco = true;
      }
    }
    if (seMarco) marcadas.push(etiqueta); else noEncontradas.push(etiqueta);
  }
  if (noEncontradas.length) {
    console.log('  AVISO: no se pudieron marcar estas épocas viejas (puede que el sitio haya cambiado o '
      + 'tardó en cargar esa sección): ' + noEncontradas.join(', ')
      + ' -- esta corrida puede quedarse corta contra el total real del sitio.');
  }
  if (marcadas.length) {
    console.log('  Épocas viejas marcadas correctamente: ' + marcadas.join(', '));
  }
  await page.waitForTimeout(500);
}

async function aplicarFiltroMateriaLaboral(page) {
  // El panel de filtros a la izquierda tiene un acordeon "Materia" -- se
  // abre, se marca la casilla "Laboral", y el listado se filtra solo.
  await clickTextoVisible(page, 'Materia');
  await page.waitForTimeout(800);
  await clickTextoVisible(page, 'Laboral');
  await page.waitForTimeout(1500);
}

// Lee las tarjetas de resultado visibles en la pagina actual del listado
// (sin paginar) -- se usa una vez por cada pagina que recorre buscarTesisRecientes.
async function leerTarjetasPaginaActual(page) {
  const tarjetas = page.locator('text=/Registro digital:\\s*\\d+/');
  const total = await tarjetas.count();
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

// Intenta avanzar el listado a la siguiente pagina. Devuelve true si avanzo,
// false si ya no hay mas paginas -- en ese caso se asume que se llego al
// final del listado, no que algo tronó.
//
// El paginador de este sitio NO es un boton "Siguiente" -- es una fila de
// numeros de pagina clicables (1, 2, 3, 4...) mas flechas de
// primera/ultima pagina en los extremos. Por eso se navega dando clic
// directo al numero de la pagina que sigue (paginaActual + 1), buscandolo
// solo entre botones/enlaces (para no toparse por accidente con ese mismo
// numero suelto en el texto de alguna tesis). Si ese numero no aparece
// visible (p.ej. el paginador solo muestra una ventana de numeros cercanos
// y no se corrio como se esperaba), se intenta como respaldo con las
// flechas de avance por si el sitio cambia de diseño.
async function avanzarSiguientePagina(page, paginaActual) {
  const siguienteNumero = String(paginaActual + 1);
  const candidatos = [
    page.getByRole('button', { name: siguienteNumero, exact: true }),
    page.getByRole('link', { name: siguienteNumero, exact: true }),
    page.getByRole('button', { name: /siguiente|next/i }),
    page.locator('button[aria-label*="iguiente" i]'),
    page.locator('button.mat-paginator-navigation-next'),
  ];
  for (const candidato of candidatos) {
    const el = candidato.first();
    if (await el.count() === 0) continue;
    if (!(await el.isVisible().catch(() => false))) continue;
    if (await el.isDisabled().catch(() => false)) continue;
    await el.click().catch(() => {});
    await page.waitForTimeout(1500);
    return true;
  }
  return false;
}

// Si el listado permite elegir cuantos resultados mostrar por pagina, se
// pone el maximo disponible -- reduce el numero de "siguiente" que hay que
// dar para recorrer el historial completo. No es grave si no lo encuentra
// (el sitio pudo cambiar ese control, o no existir): sigue con lo que ya
// venia por default.
async function maximizarResultadosPorPagina(page) {
  try {
    const selector = page.locator('text=/por p[aá]gina/i').locator('..').locator('select, [role="combobox"]').first();
    if (await selector.count() === 0) return;
    await selector.click();
    await page.waitForTimeout(500);
    const opciones = page.locator('[role="option"], option');
    const n = await opciones.count();
    if (n === 0) return;
    await opciones.nth(n - 1).click();
    await page.waitForTimeout(1500);
  } catch (e) {
    console.log('No se pudo ajustar resultados por pagina (se sigue con el default): ' + e.message);
  }
}

// Corre una promesa con un limite de tiempo -- si se pasa, la rechaza en
// vez de dejarla colgada para siempre. Playwright ya pone limite a cada
// accion individual (clicks, etc.), pero por si alguna combinacion se
// queda esperando de verdad (el sitio se atora, deja de responder, etc.)
// esto es la ultima salvaguarda para que el robot nunca se quede pegado
// sin avisar.
function conLimiteTiempo(promesa, ms, etiqueta) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('Se tardo mas de ' + Math.round(ms / 1000) + 's en: ' + etiqueta)), ms);
    promesa.then(
      (v) => { clearTimeout(timer); resolve(v); },
      (e) => { clearTimeout(timer); reject(e); }
    );
  });
}

async function buscarTesisRecientes(page, procesados, modoCompleto) {
  await page.goto(URL_BUSQUEDA, { waitUntil: 'networkidle' });
  // Antes se esperaba fijo 1500ms y ya -- si la tabla de épocas (una tabla
  // pesada, con checkbox por cada combinación época/instancia) tardaba más
  // en renderizar, seleccionarEpocasAntiguas() de abajo se topaba con nada
  // visible todavía y fallaba en silencio. Ahora se espera explícitamente a
  // que aparezca al menos una etiqueta de época antes de seguir (con un
  // límite de 8s -- si ni así aparece, se sigue de todos modos, y el aviso
  // de seleccionarEpocasAntiguas() lo va a dejar ver en el log).
  await page.getByText('8a. Época', { exact: true }).first().waitFor({ timeout: 8000 }).catch(() => {});
  await page.waitForTimeout(500);

  await seleccionarEpocasAntiguas(page);

  // La pantalla inicial trae un boton "Ver todo" junto a la caja de
  // busqueda -- lleva directo al listado completo (todas las materias,
  // todas las epocas e instancias que hayan quedado marcadas) sin tener
  // que escribir ningun termino. De ahi se filtra por Materia = Laboral,
  // que solo aparece ya adentro del listado.
  // El texto visible es "Ver todo", pero existe duplicado en el HTML (una
  // version de escritorio y otra de movil, la que no se ve sigue estando
  // en el DOM) -- el boton de escritorio tiene como nombre accesible real
  // "Realizar busqueda", que es lo que lo identifica sin ambiguedad.
  await page.getByRole('button', { name: 'Realizar búsqueda' }).click();
  await page.waitForTimeout(2500);

  await aplicarFiltroMateriaLaboral(page);

  // Ordena por fecha de publicacion mas reciente -- ya suele venir asi por
  // default, pero se fuerza por si acaso. Es indispensable para que el
  // "paro anticipado" de abajo tenga sentido (si no viniera ordenado de lo
  // mas nuevo a lo mas viejo, parar en la primera tesis ya conocida podria
  // saltarse tesis nuevas que aparecieran despues en el listado).
  try {
    await page.locator('text=/Ordenar por/i').locator('..').locator('select, [role="combobox"]').first().click();
    await page.waitForTimeout(500);
    await page.getByText(/Fecha de publicaci[oó]n \(reciente/i).click();
    await page.waitForTimeout(1500);
  } catch (e) {
    console.log('No se pudo forzar el orden por fecha reciente (puede que ya viniera asi): ' + e.message);
  }

  await maximizarResultadosPorPagina(page);

  const resultados = [];
  const vistos = new Set();
  let pagina = 1;
  let paginasVaciasSeguidas = 0;
  // Cuántas páginas SEGUIDAS sin nada nuevo hacen falta para asumir que ya
  // se llegó al final de lo nuevo y parar. Antes bastaba con UNA sola
  // página vacía -- pero el "forzar orden por fecha reciente" de arriba
  // puede fallar en silencio (solo un console.log, sin abortar la
  // corrida) si el sitio cambia ese selector, y sin ese orden garantizado
  // una sola página sin nada nuevo no prueba que TODO lo de después ya sea
  // conocido (empates de fecha, reordenamientos, etc. pueden intercalar
  // una tesis vieja-pero-nueva-para-nosotros entre páginas ya conocidas).
  // Exigir varias seguidas reduce el riesgo de saltarse tesis reales sin
  // perder el ahorro de las corridas semanales normales.
  const PAGINAS_VACIAS_PARA_PARAR = 3;
  const MAX_PAGINAS = 2000; // limite de seguridad, muy por encima de lo esperable
  // Si algun paso se queda atorado mas de esto (el sitio deja de responder,
  // se atora en alguna animacion, etc.), se toma una foto de lo que se ve
  // en ese momento (debug_pagina_atascada.png, en esta misma carpeta) y se
  // corta el listado ahi -- mejor eso que quedarse pegado para siempre sin
  // avisar. Con la foto se puede diagnosticar que fue.
  const LIMITE_POR_PASO_MS = 60000;
  while (pagina <= MAX_PAGINAS) {
    let enPagina;
    try {
      enPagina = await conLimiteTiempo(leerTarjetasPaginaActual(page), LIMITE_POR_PASO_MS, 'leer la pagina ' + pagina);
    } catch (e) {
      console.log('  ' + e.message + ' -- se toma una captura (debug_pagina_atascada.png) y se corta el listado aqui.');
      await page.screenshot({ path: require('path').join(__dirname, 'debug_pagina_atascada.png'), fullPage: true }).catch(() => {});
      break;
    }

    let nuevosEnPagina = 0;
    for (const r of enPagina) {
      if (vistos.has(r.registro)) continue;
      vistos.add(r.registro);
      resultados.push(r);
      if (!procesados.has(r.registro)) nuevosEnPagina++;
    }
    console.log('  Pagina ' + pagina + ': ' + enPagina.length + ' tesis (' + nuevosEnPagina + ' nueva(s) sin procesar).');

    // Corrida inicial (biblioteca vacia): esto nunca se cumple, asi que
    // recorre el historial completo. Corridas siguientes: en cuanto varias
    // paginas SEGUIDAS no aportan nada nuevo, se asume (dado el orden por
    // fecha reciente) que de ahi en adelante todo es ya conocido, y no hace
    // falta seguir avanzando semana tras semana por miles de tesis viejas.
    // Con --completo se desactiva este atajo (ver mas abajo por que hace
    // falta a veces incluso con la biblioteca no vacia).
    if (enPagina.length > 0) {
      paginasVaciasSeguidas = nuevosEnPagina === 0 ? paginasVaciasSeguidas + 1 : 0;
    }
    if (!modoCompleto && pagina > 1 && paginasVaciasSeguidas >= PAGINAS_VACIAS_PARA_PARAR) break;

    let avanzo;
    try {
      avanzo = await conLimiteTiempo(avanzarSiguientePagina(page, pagina), LIMITE_POR_PASO_MS, 'avanzar de la pagina ' + pagina);
    } catch (e) {
      console.log('  ' + e.message + ' -- se toma una captura (debug_pagina_atascada.png) y se corta el listado aqui.');
      await page.screenshot({ path: require('path').join(__dirname, 'debug_pagina_atascada.png'), fullPage: true }).catch(() => {});
      break;
    }
    if (!avanzo) break;
    pagina++;
  }

  console.log(resultados.length + ' resultado(s) revisados en total (' + pagina + ' pagina(s)).');
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
  const fechaDetalle = parseFechaPublicacionDetalle(texto);

  return { instancia, epoca, numero_tesis: numeroTesis, materias, tipo, texto_completo: texto, fecha_detalle: fechaDetalle };
}

async function reportarTesis(lote) {
  await axios.post(config.sistema.apiBase + '/jurisprudencia_ingest.php', { tesis: lote }, {
    headers: { 'X-Robot-Key': config.sistema.robotKey },
  });
}

// Se manda en lotes (no todo junto hasta el final) por dos razones: en una
// corrida grande (primera vez, historial completo) un solo POST con miles
// de tesis con texto completo podria exceder los limites de tamano del
// servidor, y si algo se interrumpe a medio camino no se pierde el avance
// ya guardado.
const TAMANO_LOTE = 25;

// --completo fuerza una recorrida de todo el listado sin el atajo de parar
// en cuanto una pagina ya sea toda conocida. Hace falta corerlo asi al
// menos una vez cada vez que cambia lo que se busca (p.ej. se agregan
// epocas nuevas al filtro) -- si no, el atajo se dispara en la primera
// pagina ya conocida (las tesis recientes, que siempre salen primero) y
// nunca llega a las tesis "nuevas para el filtro" que quedan mas atras en
// el listado. En corridas normales (sin el flag) no hace falta: una vez
// que la biblioteca ya tiene el historial completo para el filtro actual,
// el atajo es seguro y hace las corridas semanales rapidas.
const MODO_COMPLETO = process.argv.includes('--completo');

async function main() {
  console.log(new Date().toISOString(), 'Iniciando revision de jurisprudencia laboral...' + (MODO_COMPLETO ? ' (modo completo, sin atajos)' : ''));
  const procesados = cargarProcesados();

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  let totalGuardadas = 0;
  try {
    const resultados = await buscarTesisRecientes(page, procesados, MODO_COMPLETO);
    const nuevos = resultados.filter(r => r.registro && !procesados.has(r.registro));
    console.log(nuevos.length + ' tesis nueva(s) sin procesar de ' + resultados.length + ' revisadas.');

    let lote = [];
    for (const r of nuevos) {
      console.log('  Descargando detalle de la tesis ' + r.registro + '...');
      try {
        const detalle = await obtenerDetalleTesis(page, r.registro);
        lote.push({
          registro_digital: r.registro,
          rubro: r.rubro,
          ...detalle,
          // La fecha de la página de detalle (fecha_detalle) es más confiable que
          // la de la tarjeta del listado -- se usa como fuente principal, con la
          // del listado (r.fecha) solo de respaldo si por algo no se pudo leer.
          fecha_publicacion: detalle.fecha_detalle || r.fecha,
        });
        procesados.add(r.registro);
      } catch (e) {
        console.log('    No se pudo leer el detalle: ' + e.message);
        continue;
      }

      if (lote.length >= TAMANO_LOTE) {
        await reportarTesis(lote);
        totalGuardadas += lote.length;
        console.log('  -> ' + lote.length + ' tesis guardada(s) (' + totalGuardadas + ' en total hasta ahora).');
        lote = [];
        guardarProcesados(procesados);
      }
    }

    if (lote.length) {
      await reportarTesis(lote);
      totalGuardadas += lote.length;
    }

    if (totalGuardadas) {
      console.log(totalGuardadas + ' tesis nueva(s) guardada(s) en la biblioteca.');
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
