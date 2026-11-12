#!/usr/bin/env node
// ============================================================================
// Verificación rápida de que las {{tags}} que usan las plantillas de Word
// (demanda, Rescisión Art. 51, Prima de Antigüedad) siguen existiendo en
// mergeTags() y no salen vacías/"undefined"/"NaN" -- sin necesitar un
// navegador ni una base de datos real. Corre en segundos.
//
// Uso: node scripts/verificar_tags.js
// Sale con código 1 (y detalle de qué falló) si encuentra algo mal; 0 si
// todo está bien. Pensado para correr después de editar assets/app.js y
// antes de mandarle los archivos al usuario.
//
// Qué SÍ detecta: una tag que el código de "Campos disponibles" dice que
// existe pero mergeTags() ya no produce (o viceversa), un valor que se
// vuelve "undefined"/"NaN" en vez de un placeholder, un ciclo infinito o
// una excepción al construir las prestaciones/pruebas dinámicas.
// Qué NO detecta: que el texto generado sea correcto legalmente, ni que la
// plantilla .docx real (que vive solo en el servidor) tenga exactamente
// esas mismas tags escritas -- esa parte se sigue revisando a mano, igual
// que siempre, generando un documento de prueba.
// ============================================================================
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const APP_JS_PATH = path.join(__dirname, '..', 'assets', 'app.js');
let fallas = 0;
function fallo(msg){ fallas++; console.error('✗ ' + msg); }
function ok(msg){ console.log('✓ ' + msg); }

// ---------------------------------------------------------------------
// 1) Cargar app.js en un sandbox mínimo (sin navegador real), quitando la
//    única línea que arranca la app de verdad (init();) para que no
//    intente hacer fetch a una API que no existe aquí.
// ---------------------------------------------------------------------
let src = fs.readFileSync(APP_JS_PATH, 'utf8');
const INIT_RE = /^init\(\);\s*$/m;
if (!INIT_RE.test(src)) {
  console.error('Aviso: no encontré la línea "init();" tal como se esperaba -- revisa si el archivo cambió de forma que este script deba ajustarse (podría estar corriendo la app de verdad).');
} else {
  src = src.replace(INIT_RE, '// init(); -- desactivado por scripts/verificar_tags.js');
}
// Las const/let de nivel superior (mergeTags, FRACCION_51, etc.) no quedan
// como propiedades del contexto de vm por sí solas -- se exponen aquí mismo,
// en el mismo scope, para poder leerlas después desde fuera del sandbox.
src += `
globalThis.__VERIFICAR_EXPORTS__ = {
  mergeTags, calcularJornada, construirPrestacionesOpcionalesTexto,
  construirPruebasAdicionalesTexto, FRACCION_51,
  PRESTACIONES_RESCISION_OPCIONES,
};
`;

const sandbox = {
  console,
  localStorage: { getItem: () => null, setItem: () => {}, removeItem: () => {} },
  navigator: { userAgent: 'node-verificacion' },
  fetch: async () => ({ ok: false, status: 0, json: async () => ({}) }),
  URL: { createObjectURL: () => '', revokeObjectURL: () => {} },
  btoa: (s) => Buffer.from(s, 'binary').toString('base64'),
  atob: (s) => Buffer.from(s, 'base64').toString('binary'),
};
const domStub = () => ({
  style: {}, classList: { add(){}, remove(){}, contains: ()=>false },
  addEventListener(){}, removeEventListener(){}, appendChild(){}, removeChild(){},
  querySelector: () => null, querySelectorAll: () => [],
  getContext: () => null, remove(){},
});
sandbox.document = {
  addEventListener(){}, removeEventListener(){},
  getElementById: () => null, querySelector: () => null, querySelectorAll: () => [],
  createElement: () => domStub(), body: domStub(), head: domStub(),
};
sandbox.window = sandbox;
vm.createContext(sandbox);

try {
  vm.runInContext(src, sandbox, { filename: 'assets/app.js' });
} catch (e) {
  console.error('No se pudo cargar assets/app.js en el sandbox de verificación:');
  console.error(e.stack || e.message);
  process.exit(1);
}

const { mergeTags, calcularJornada, construirPrestacionesOpcionalesTexto,
  construirPruebasAdicionalesTexto, FRACCION_51,
  PRESTACIONES_RESCISION_OPCIONES } = sandbox.__VERIFICAR_EXPORTS__;

// ---------------------------------------------------------------------
// 2) Lista oficial de tags: se extrae del propio texto de "Campos
//    disponibles para usar en el Word" (la misma fuente que ve el
//    abogado en pantalla) en vez de mantener una copia aparte que se
//    puede desactualizar sola.
// ---------------------------------------------------------------------
const legendMatch = src.match(/Campos disponibles para usar en el Word:<\/strong>\s*<code>([\s\S]*?)<\/code>/);
if (!legendMatch) {
  fallo('No encontré el texto de "Campos disponibles para usar en el Word" -- ¿se movió o se renombró?');
}
const tagsEnLeyenda = legendMatch
  ? Array.from(legendMatch[1].matchAll(/\{\{(\w+)\}\}/g)).map(m => m[1])
  : [];
if (tagsEnLeyenda.length) ok(`${tagsEnLeyenda.length} tags encontradas en la leyenda de "Campos disponibles".`);

// prestaciones_opcionales NO la produce mergeTags() -- se agrega aparte,
// como extraTag, solo al generar la Rescisión Art. 51 (ver sección 6 más
// abajo, que sí la prueba por su cuenta).
const TAGS_SOLO_EXTRATAG = ['prestaciones_opcionales'];

// ---------------------------------------------------------------------
// 3) Expedientes de prueba: completo, vacío, y con jornada a medias.
// ---------------------------------------------------------------------
const FIXTURES = {
  completo: {
    actor: 'Juan Pérez Prueba', demandado: 'Empresa de Prueba SA de CV',
    giro_empresa: 'Comercio', dom_demandado: 'Calle Falsa 123, CDMX',
    dom_testigos: 'Calle Falsa 456, CDMX', puesto: 'Auxiliar administrativo',
    puesto_despidio: 'Gerente', fecha_ingreso: '2022-01-10', fecha_baja: '2026-06-15',
    salario_mensual: 12000, salario_diario: 400, curp: 'PEPJ900101HDFRRN01',
    instancia: 'H. Tribunal Laboral de la Ciudad de México', quien_despidio: 'Carlos López',
    hora_despido: '10:00', testigos: 'María García, Pedro Ramírez',
    hora_entrada: '09:00', hora_salida: '19:00', comida_inicio: '14:00', comida_fin: '15:00',
    dias_laborados: 'lunes a viernes', dias_laborados_num: 5, tipo_jornada: 'Diurna',
    rescision_fraccion: 'V', fecha_rescision: '2026-07-01', fecha_desde_incumplimiento: '2026-05-01',
    persona_responsable_incumplimiento: 'Carlos López', hecho_causa_narrativa: 'Es el caso que, a partir del 1 de mayo de 2026, la demandada incumplió con sus obligaciones de pago, hecho redactado íntegramente por el abogado de prueba.',
    monto_prima_antiguedad: 15000, fecha_solicitud_interna: '2026-01-14', hora_solicitud_interna: '17:07',
    folio_solicitud_interna: '0153', funcionario_rh_nombre: 'Ana Ruiz', funcionario_rh_puesto: 'Encargada de RH',
    numero_oficio_respuesta: 'DCAF/0001/2026', fecha_oficio_respuesta: '2026-03-02',
    director_firma_nombre: 'Héctor Amado', coordinador_archivo_nombre: 'Luis Martínez',
    gerente_gestion_nombre: 'Sara Jarquín', folio_fiscal_cfdi: 'ABC123', periodo_recibo_desde: '2025-12-16',
    periodo_recibo_hasta: '2025-12-31', salario_quincenal_num: 9000, rfc_emisor_demandado: 'EMP860820CF5',
    fecha_hoja_servicios: '2026-01-05', numero_documento_fump: '2025-00835', fecha_documento_fump: '2025-12-12',
  },
  vacio: { actor: null, fecha_ingreso: null },
  jornada_parcial: {
    actor: 'Prueba Parcial', fecha_ingreso: '2023-05-01', fecha_baja: '2026-01-01',
    hora_entrada: '08:00', hora_salida: '18:00',
    // sin comida_inicio/comida_fin, sin dias_laborados_num, sin rescision_fraccion
  },
};

// ---------------------------------------------------------------------
// 4) mergeTags(): cada tag de la leyenda debe existir y no verse como
//    "undefined"/"NaN" en texto; cada clave que SÍ produce mergeTags()
//    pero no está en la leyenda se avisa (para no olvidar documentarla).
// ---------------------------------------------------------------------
for (const [nombreFixture, k] of Object.entries(FIXTURES)) {
  let tags;
  try {
    tags = mergeTags(k);
  } catch (e) {
    fallo(`mergeTags() lanzó una excepción con el expediente "${nombreFixture}": ${e.message}`);
    continue;
  }
  for (const tag of tagsEnLeyenda) {
    if (TAGS_SOLO_EXTRATAG.includes(tag)) continue;
    if (!(tag in tags)) {
      fallo(`La tag {{${tag}}} está en "Campos disponibles" pero mergeTags() no la produce (expediente "${nombreFixture}").`);
      continue;
    }
    const val = String(tags[tag]);
    if (/\bundefined\b/.test(val) || /\bNaN\b/.test(val)) {
      fallo(`{{${tag}}} sale como "${val}" en el expediente "${nombreFixture}" -- debería ser un valor real o un placeholder tipo "____".`);
    }
  }
  const tagsNoDocumentadas = Object.keys(tags).filter(k2 => !tagsEnLeyenda.includes(k2));
  if (tagsNoDocumentadas.length) {
    console.warn(`  (aviso) mergeTags() produce ${tagsNoDocumentadas.join(', ')} pero no están en la leyenda de "Campos disponibles" -- agrégalas si son para usar en una plantilla.`);
  }
}
if (!fallas) ok('mergeTags() -- todas las tags de la leyenda salen bien en los 3 expedientes de prueba.');

// ---------------------------------------------------------------------
// 5) calcularJornada(): casos límite no deben tronar ni dar NaN.
// ---------------------------------------------------------------------
const CASOS_JORNADA = [
  { nombre: 'sin horario', k: {} },
  { nombre: 'entrada = salida', k: { hora_entrada: '09:00', hora_salida: '09:00' } },
  { nombre: 'cruza medianoche', k: { hora_entrada: '22:00', hora_salida: '06:00', tipo_jornada: 'Nocturna' } },
  { nombre: 'jornada normal', k: { hora_entrada: '08:00', hora_salida: '18:30', dias_laborados_num: 6 } },
];
for (const { nombre, k } of CASOS_JORNADA) {
  try {
    const r = calcularJornada(k);
    if (r && (isNaN(r.horasExtraDiarias) || isNaN(r.horasExtraSemanales))) {
      fallo(`calcularJornada() da NaN en el caso "${nombre}".`);
    }
  } catch (e) {
    fallo(`calcularJornada() lanzó una excepción en el caso "${nombre}": ${e.message}`);
  }
}
if (!fallas) ok('calcularJornada() -- casos límite (sin horario, cruce de medianoche, entrada=salida) sin errores.');

// ---------------------------------------------------------------------
// 6) Prestaciones opcionales de Rescisión Art. 51: sin selección, con
//    todas, y con una selección parcial -- nunca debe quedar "undefined"
//    y las letras deben ir B, C, D... en orden sin huecos.
// ---------------------------------------------------------------------
const kRescision = FIXTURES.completo;
const todasLasClaves = PRESTACIONES_RESCISION_OPCIONES.map(o => o.key);
for (const [nombreCaso, claves] of Object.entries({
  ninguna: [],
  todas: todasLasClaves,
  parcial: todasLasClaves.filter((_, i) => i % 2 === 0),
})) {
  try {
    const texto = construirPrestacionesOpcionalesTexto(kRescision, claves);
    if (/\bundefined\b/.test(texto)) fallo(`Prestaciones opcionales (${nombreCaso}): el texto contiene "undefined".`);
    const letras = Array.from(texto.matchAll(/^([A-Z])\)/gm)).map(m => m[1]);
    const esperadas = 'BCDEFGHIJKLMNOPQRSTUVWXYZ'.slice(0, claves.length).split('');
    if (JSON.stringify(letras) !== JSON.stringify(esperadas)) {
      fallo(`Prestaciones opcionales (${nombreCaso}): letras ${JSON.stringify(letras)}, esperaba ${JSON.stringify(esperadas)}.`);
    }
  } catch (e) {
    fallo(`construirPrestacionesOpcionalesTexto() lanzó una excepción (${nombreCaso}): ${e.message}`);
  }
}
if (!fallas) ok('Prestaciones opcionales de Rescisión Art. 51 -- letras correctas, sin "undefined".');

// ---------------------------------------------------------------------
// 7) El hecho de la causal de Rescisión Art. 51 ahora lo redacta el
//    abogado a mano (11 nov 2026, pedido explícito del usuario) -- el
//    catálogo de 9 frases automáticas por fracción se quitó del todo.
//    hecho_causa_auto debe salir SIEMPRE vacío (nunca "undefined", nunca
//    el párrafo viejo) y hecho_causa_narrativa debe traer tal cual lo que
//    haya escrito el abogado, o el placeholder de siempre si está vacío.
// ---------------------------------------------------------------------
for (const code of Object.keys(FRACCION_51)) {
  const tags = mergeTags(Object.assign({}, FIXTURES.completo, { rescision_fraccion: code }));
  if (tags.hecho_causa_auto !== '') {
    fallo(`Fracción ${code} del art. 51: hecho_causa_auto debía salir vacío y salió ("${tags.hecho_causa_auto}").`);
  }
  if (!tags.hecho_causa_narrativa || /\bundefined\b/.test(tags.hecho_causa_narrativa)) {
    fallo(`Fracción ${code} del art. 51: hecho_causa_narrativa sale mal ("${tags.hecho_causa_narrativa}").`);
  }
}
const tagsSinHecho = mergeTags(Object.assign({}, FIXTURES.completo, { hecho_causa_narrativa: null }));
if (tagsSinHecho.hecho_causa_narrativa !== '________________') {
  fallo(`hecho_causa_narrativa vacío debía caer al placeholder y salió ("${tagsSinHecho.hecho_causa_narrativa}").`);
}
if (!fallas) ok('hecho_causa_auto vacío en las 9 fracciones; hecho_causa_narrativa trae el párrafo del abogado o el placeholder.');

// ---------------------------------------------------------------------
// 8) Pruebas adicionales (inspección + testimonial) -- los bancos se
//    quitaron del todo (11 nov 2026), la numeración ya no depende de
//    nada capturado y siempre debe arrancar en 5/6.
// ---------------------------------------------------------------------
try {
  const tags = mergeTags(FIXTURES.completo);
  const numeros = Array.from(tags.pruebas_adicionales.matchAll(/^(\d+)\.-/gm)).map(m => Number(m[1]));
  if (JSON.stringify(numeros) !== JSON.stringify([5, 6])) {
    fallo(`Pruebas adicionales: numeración ${JSON.stringify(numeros)}, esperaba [5,6] (inspección, testimonial).`);
  }
  if (/\bundefined\b/.test(tags.pruebas_adicionales)) {
    fallo('Pruebas adicionales: el texto contiene "undefined".');
  }
} catch (e) {
  fallo(`construirPruebasAdicionalesTexto() lanzó una excepción: ${e.message}`);
}
if (!fallas) ok('Pruebas adicionales (inspección + testimonial, sin bancos) -- numeración 5/6 fija.');

// ---------------------------------------------------------------------
console.log('');
if (fallas) {
  console.error(`${fallas} problema(s) encontrados.`);
  process.exit(1);
} else {
  console.log('Todo bien ✓');
  process.exit(0);
}
