// =====================================================================
// Expertos Laborales Abogados — Sistema de Gestión de Litigios Laborales
// Marco legal: Ley Federal del Trabajo (México), vigente. Cómputo del
// plazo de prescripción según el Título Décimo LFT, en días naturales
// exactos contados a partir del día siguiente al hecho generador (ver
// TIPO_ASUNTO_PLAZOS): despido, Art. 518 LFT, 60 días, conforme a la
// jurisprudencia de la Segunda Sala de la SCJN (tesis 2028593); rescisión
// —el trabajador se separa por causa imputable al patrón—, Art. 517 LFT,
// 30 días; riesgo de trabajo y beneficiarios, Art. 519 LFT, 730 días; el
// resto de las prestaciones, Art. 516 LFT, 365 días. Todos los plazos se
// suspenden durante el procedimiento de conciliación (Art. 684-B / 521
// fracc. III LFT) y se reanudan al día siguiente de la constancia de no
// conciliación.
// =====================================================================

// =====================================================================
// Capa de datos: todo se guarda en MySQL a través de la API en api/*.php.
// La sesión del usuario vive en una cookie de sesión de PHP (httponly, no
// accesible desde JS), y cada petición que modifica datos incluye el token
// CSRF que entrega el servidor al iniciar sesión / al restaurar sesión.
// =====================================================================
const API_BASE = 'api/';
let CSRF_TOKEN = null;

async function api(method, path, body){
  const opts = {method, headers:{}, credentials:'same-origin'};
  if(body !== undefined){
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }
  if(method !== 'GET' && CSRF_TOKEN){
    opts.headers['X-CSRF-Token'] = CSRF_TOKEN;
  }
  let res, json;
  try{
    res = await fetch(API_BASE + path, opts);
    json = await res.json();
  }catch(e){
    throw new Error('No se pudo conectar con el servidor. Revisa tu conexión e intenta de nuevo.');
  }
  if(!json || json.ok !== true){
    const msg = (json && json.error) ? json.error : ('Error del servidor ('+res.status+').');
    throw new Error(msg);
  }
  return json.data;
}

function mapRol(rolDb){ return rolDb === 'administrador' ? 'Administrador' : 'Socio/a'; }

let CURRENT_USER = null;
let VIEW = "tablero";
let FILTER_STATUS = "todos";
let SEARCH_TERM = "";
let CASES_DATA = []; // expedientes que devuelve la API (ya con meta anidada)
let PROSPECTOS = []; // leads de despido CDMX/Edomex captados por el bot de WhatsApp
let PROSPECTO_ABIERTO = null; // id del prospecto cuyo chat está abierto en el modal
let PROSPECTO_MENSAJES = []; // historial de WhatsApp del prospecto abierto
let PROSPECTOS_MOSTRAR_ATENDIDOS = false; // solo se ve lo pendiente por atender por default (ver prospectosHTML)
let PROSPECTOS_TAB = 'despido'; // pestaña activa en Prospectos: 'despido' o 'asesoria_paga'

// Vista "Jurisprudencia" — buscador con IA sobre la biblioteca de tesis de
// la SCJN (compartida entre todos los despachos, ver jurisprudencia_tesis).
let JURISPRUDENCIA_PREGUNTA = '';
let JURISPRUDENCIA_CARGANDO = false;
let JURISPRUDENCIA_ERROR = '';
let JURISPRUDENCIA_RESULTADO = null; // {respuesta, tesis:[...]}
let JURISPRUDENCIA_TESIS_ABIERTA = null; // registro_digital mostrado en el modal de texto completo

// Vista "Conversaciones (WhatsApp)" (solo Administrador) — control de
// calidad del bot: TODAS las conversaciones, hayan calificado como
// prospecto o no, para poder revisar qué está contestando el bot.
let CONVERSACIONES = [];
let CONVERSACIONES_STATS = {};
let RESUMENES_EJECUTIVOS = [];
let RESUMEN_EJECUTIVO_GENERANDO = false;
let CONVERSACION_ABIERTA = null; // teléfono de la conversación expandida
let CONVERSACION_MENSAJES = [];
let CONVERSACION_RESUMEN = null; // {resumen, mensajes_contados, generado_en} o null
let REINTENTO_EN_PROGRESO = false; // true mientras corre el reintento en lote de respuestas fallidas
let REINTENTO_PROGRESO_TXT = "";
let ACTIVE_CASE = null;
let MODAL_TAB = "resumen";
let CLIENT_CASE = null; // expediente cargado por el portal de cliente (fuera de CASES_DATA)

// Campos que puede llenar/editar el abogado asignado. Cubre lo necesario
// para el seguimiento del asunto y para generar la demanda por combinación
// de correspondencia.
// Lista cerrada de estatus del expediente — reemplaza el campo de texto libre
// para que no queden variantes distintas del mismo estatus (errores de dedo,
// mayúsculas/minúsculas, etc.). Orden alfabético.
const STATUS_OPCIONES = [
  'Conciliación CDMX',
  'Conciliación Edomex',
  'Conciliación Federal CDMX',
  'Conciliación Federal Edomex',
  'Confirmar Federal',
  'Confirmar solicitud CDMX',
  'Constancia presentar demanda',
  'Convenio',
  'El cliente ya no quiso continuar',
  'En espera de fecha',
  'Pagado',
  'Presentar nuevamente la solicitud',
  'Tribunal CDMX',
  'Tribunal Edomex',
  'Tribunal Federal CDMX',
  'Tribunal Federal Edomex',
];

// Catálogo fijo de tipos de asunto y sus plazos de prescripción, en días
// naturales exactos: Art. 518 LFT (despido, 60 días), Art. 517 LFT
// (rescisión — el trabajador se separa por causa imputable al patrón, 30
// días), Art. 519 LFT (riesgo de trabajo y beneficiarios, 730 días) y
// Art. 516 LFT (regla general para el resto de las prestaciones, 365
// días) — ver computePrescripcion().
const TIPO_ASUNTO_DESPIDO = 'Despido';
const TIPO_ASUNTO_PRESTACIONES = 'Prestaciones';
const TIPO_ASUNTO_OTRO = 'Otro';
const TIPO_ASUNTO_OPCIONES = [
  TIPO_ASUNTO_DESPIDO,
  'Rescisión (Art. 51 LFT)',
  'Riesgo de trabajo',
  'Designación de beneficiarios',
  TIPO_ASUNTO_PRESTACIONES,
];
const TIPO_ASUNTO_PLAZOS = {
  'Despido': {dias: 60, articulo: 'Art. 518 LFT'},
  'Rescisión (Art. 51 LFT)': {dias: 30, articulo: 'Art. 517 LFT'},
  'Riesgo de trabajo': {dias: 730, articulo: 'Art. 519 LFT'},
  'Designación de beneficiarios': {dias: 730, articulo: 'Art. 519 LFT'},
  'Prestaciones': {dias: 365, articulo: 'Art. 516 LFT'},
};
// Los expedientes ya existentes, capturados antes de que se agregara este
// campo, no tienen tipo_asunto — como el sistema solo manejaba despido
// hasta ahora, se asume ese tipo por defecto para no alterar el plazo que
// ya se les venía calculando. Un tipo escrito a mano (opción "Otro") que
// no está en el catálogo usa la regla general de un año (Art. 516 LFT),
// por ser el plazo por default de cualquier acción de trabajo sin un
// artículo especial que le aplique.
function tipoAsuntoPlazo(kase){
  if(!kase.tipo_asunto) return TIPO_ASUNTO_PLAZOS[TIPO_ASUNTO_DESPIDO];
  return TIPO_ASUNTO_PLAZOS[kase.tipo_asunto] || TIPO_ASUNTO_PLAZOS[TIPO_ASUNTO_PRESTACIONES];
}

// Select del catálogo fijo + "Otro" con texto libre. El <select> y el
// <input> de texto NO llevan la clase edit-field (para que saveCamposBtn
// no los lea directo) — el que de verdad se guarda es el <input type=
// hidden> que ambos actualizan vía bindTipoAsuntoField().
function tipoAsuntoFieldHTML(actual){
  const esCatalogo = actual !== '' && TIPO_ASUNTO_OPCIONES.includes(actual);
  const esOtro = actual !== '' && !esCatalogo;
  return `
    <input type="hidden" class="edit-field" data-field="tipo_asunto" id="tipoAsuntoHidden" value="${escapeHTML(actual)}">
    <select id="tipoAsuntoSelect" style="width:100%; padding:9px 11px; border:1px solid var(--border); border-radius:8px; background:var(--parchment); font-size:13px;">
      <option value="">— Sin definir —</option>
      ${TIPO_ASUNTO_OPCIONES.map(o=>`<option value="${escapeHTML(o)}" ${actual===o?'selected':''}>${escapeHTML(o)}</option>`).join("")}
      <option value="${TIPO_ASUNTO_OTRO}" ${esOtro?'selected':''}>Otro (especifica)</option>
    </select>
    <input type="text" id="tipoAsuntoOtroInput" placeholder="Especifica el tipo de asunto" value="${esOtro?escapeHTML(actual):''}" style="width:100%; margin-top:8px; ${esOtro?'':'display:none;'}">
  `;
}
function bindTipoAsuntoField(){
  const sel = document.getElementById('tipoAsuntoSelect');
  const otro = document.getElementById('tipoAsuntoOtroInput');
  const hidden = document.getElementById('tipoAsuntoHidden');
  if(!sel || !otro || !hidden) return;
  sel.addEventListener('change', ()=>{
    if(sel.value === TIPO_ASUNTO_OTRO){
      otro.style.display = '';
      otro.focus();
      hidden.value = otro.value;
    } else {
      otro.style.display = 'none';
      hidden.value = sel.value;
    }
  });
  otro.addEventListener('input', ()=>{ hidden.value = otro.value; });
}

const EDITABLE_FIELDS = [
  {key:'tipo_asunto', label:'Tipo de asunto', type:'tipo_asunto'},
  {key:'actor', label:'Nombre del actor (trabajador)'},
  {key:'curp', label:'CURP del actor'},
  {key:'telefono', label:'Teléfono'},
  {key:'correo', label:'Correo electrónico'},
  {key:'demandado', label:'Nombre del demandado (razón social)'},
  {key:'giro_empresa', label:'Giro de la empresa'},
  {key:'dom_demandado', label:'Domicilio del demandado (para emplazamiento)'},
  {key:'puesto', label:'Puesto que desempeñaba'},
  {key:'fecha_ingreso', label:'Fecha de ingreso', type:'date'},
  {key:'fecha_baja', label:'Fecha de baja / despido', type:'date'},
  {key:'salario_mensual', label:'Salario mensual', type:'number'},
  {key:'salario_diario', label:'Salario diario', type:'number'},
  {key:'sdi', label:'Salario diario integrado', type:'number'},
  {key:'instancia', label:'Centro de Conciliación / Instancia'},
  {key:'junta', label:'Tribunal / Junta competente', type:'tribunal'},
  {key:'tribunal', label:'Tribunal (si ya está radicado)', type:'tribunal'},
  {key:'exp', label:'Número de expediente o registro'},
  {key:'status', label:'Estatus', type:'select', options:STATUS_OPCIONES},
  {key:'quien_despidio', label:'Nombre de quien despidió'},
  {key:'puesto_despidio', label:'Puesto de quien despidió'},
  {key:'hora_despido', label:'Hora del despido'},
  {key:'testigos', label:'Nombre de los testigos'},
  {key:'dom_testigos', label:'Domicilios de los testigos'},
  {key:'ultima_nota', label:'Última actuación / nota'},
  {key:'aguinaldo_dias_pactados', label:'Aguinaldo — días pactados (solo si es mayor al mínimo de 15)', type:'number'},
  {key:'dias_salarios_devengados', label:'Salarios devengados pendientes — días sin pagar', type:'number'},
  {key:'fecha_desde_salarios_devengados', label:'Salarios devengados — desde qué fecha', type:'date'},
  {key:'dias_vacaciones_anteriores_reclamados', label:'Vacaciones de periodos anteriores — días que reclama (ver el máximo no prescrito en "Cálculo de liquidación")', type:'number'},
  {key:'prima_vacacional_pct_pactada', label:'Prima vacacional pactada (%) — solo si es mayor al mínimo de 25% (deja vacío si es el mínimo)', type:'number'},
];
// La indemnización constitucional, la prima de antigüedad, las vacaciones
// proporcionales, la prima vacacional y el aguinaldo proporcional YA NO se
// capturan a mano aquí: el sistema los calcula solos a partir de fecha de
// ingreso, fecha de baja, salario y el salario mínimo vigente — ver
// computeLiquidacion() y la pestaña "Cálculo de liquidación".

// ---------------------------------------------------------------
// Catálogo nacional de tribunales laborales (locales + federales),
// construido a partir de investigación en fuentes oficiales de cada
// Poder Judicial estatal y del directorio OAJ/CJF (julio 2026). Los
// 13 estados con lista cerrada están verificados contra fuente oficial
// primaria; el resto usa 'Otro (especificar)' hasta verificarse. Lo
// que se escribe en 'Otro' se guarda vía tribunales_custom_add.php y
// se suma al catálogo compartido para todos — ver tribunalFieldHTML()
// y bindTribunalFieldEvents().
// ---------------------------------------------------------------
const ESTADOS_MX = [
  "Aguascalientes",
  "Baja California",
  "Baja California Sur",
  "Campeche",
  "Chiapas",
  "Chihuahua",
  "Ciudad de México",
  "Coahuila de Zaragoza",
  "Colima",
  "Durango",
  "Estado de México",
  "Guanajuato",
  "Guerrero",
  "Hidalgo",
  "Jalisco",
  "Michoacán",
  "Morelos",
  "Nayarit",
  "Nuevo León",
  "Oaxaca",
  "Puebla",
  "Querétaro",
  "Quintana Roo",
  "San Luis Potosí",
  "Sinaloa",
  "Sonora",
  "Tabasco",
  "Tamaulipas",
  "Tlaxcala",
  "Veracruz",
  "Yucatán",
  "Zacatecas",
];

// Estados con catálogo LOCAL cerrado y verificado al 100% contra fuente
// oficial primaria (julio 2026). Los demás estados no aparecen aquí: para
// ellos el selector solo ofrece 'Otro (especificar)' + lo ya agregado a
// tribunales_custom.
const TRIBUNALES_LOCALES_MX = {
  "Baja California": [
    "Tribunal Laboral Ensenada",
    "Tribunal Laboral Mexicali",
    "Tribunal Laboral San Quintín",
    "Tribunal Laboral Tecate",
    "Tribunal Laboral Tijuana",
  ],
  "Baja California Sur": [
    "Tribunal Laboral del Partido Judicial de La Paz",
    "Tribunal Laboral del Partido Judicial de Los Cabos, residencia San José del Cabo",
    "Tribunal Laboral del Partido Judicial de Los Cabos, residencia Cabo San Lucas",
    "Tribunal Laboral del Partido Judicial de Comondú, sede Ciudad Constitución",
    "Tribunal Laboral del Partido Judicial de Loreto",
    "Tribunal Laboral del Partido Judicial de Mulegé, residencia Santa Rosalía",
  ],
  "Chiapas": [
    "Juzgado Primero Especializado en Materia Laboral, Región Uno (Tuxtla Gutiérrez)",
    "Juzgado Segundo Especializado en Materia Laboral, Región Uno (Tuxtla Gutiérrez)",
    "Juzgado Especializado en Materia Laboral, Región Dos (Tapachula)",
  ],
  "Chihuahua": [
    "Tribunal Laboral — Chihuahua (capital)",
    "Tribunal Laboral — Delicias",
    "Tribunal Laboral — Ciudad Juárez",
  ],
  "Coahuila de Zaragoza": [
    "Tribunal Laboral del Distrito Judicial de Saltillo",
    "Tribunal Laboral del Distrito Judicial de Torreón",
    "Tribunal Laboral del Distrito Judicial de Monclova",
    "Tribunal Laboral del Distrito Judicial de la Región Carbonífera (San Juan de Sabinas)",
    "Tribunal Laboral del Distrito Judicial de Río Grande (Piedras Negras)",
    "Tribunal Laboral del Distrito Judicial de Acuña",
  ],
  "Estado de México": [
    "PRIMER TRIBUNAL LABORAL DE LA REGIÓN JUDICIAL DE ECATEPEC",
    "PRIMER TRIBUNAL LABORAL DE LA REGIÓN JUDICIAL DE TEXCOCO",
    "SEGUNDO TRIBUNAL LABORAL DE LA REGIÓN JUDICIAL DE TEXCOCO, CON RESIDENCIA EN NEZAHUALCÓYOTL",
    "TERCER TRIBUNAL LABORAL DE LA REGIÓN JUDICIAL DE TEXCOCO, CON RESIDENCIA EN TEOTIHUACÁN",
    "PRIMER TRIBUNAL LABORAL DE LA REGIÓN JUDICIAL DE TLALNEPANTLA",
    "SEGUNDO TRIBUNAL LABORAL DE LA REGIÓN JUDICIAL DE TLALNEPANTLA, CON RESIDENCIA EN NAUCALPAN",
    "TERCER TRIBUNAL LABORAL DE LA REGIÓN JUDICIAL DE TLALNEPANTLA, CON RESIDENCIA EN CUAUTITLÁN IZCALLI",
    "PRIMER TRIBUNAL LABORAL DE LA REGIÓN JUDICIAL DE TOLUCA, CON RESIDENCIA EN XONACATLÁN",
    "SEGUNDO TRIBUNAL LABORAL DE LA REGIÓN JUDICIAL DE TOLUCA, CON RESIDENCIA EN XONACATLÁN",
    "TERCER TRIBUNAL LABORAL DE LA REGIÓN JUDICIAL DE TOLUCA",
    "TRIBUNAL LABORAL ITINERANTE DEL ESTADO DE MÉXICO",
  ],
  "Hidalgo": [
    "Tribunal Laboral del Poder Judicial del Estado de Hidalgo (Pachuca)",
  ],
  "Jalisco": [
    "Primer Tribunal Laboral (Región I — Zapopan)",
    "Segundo Tribunal Laboral (Región I — Zapopan)",
    "Tercer Tribunal Laboral (Región I — Zapopan)",
    "Cuarto Tribunal Laboral (Región I — Zapopan)",
    "Quinto Tribunal Laboral (Región I — Zapopan)",
    "Sexto Tribunal Laboral (Región I — Zapopan)",
    "Séptimo Tribunal Laboral (Región I — Zapopan)",
    "Octavo Tribunal Laboral (Región I — Zapopan)",
    "Noveno Tribunal Laboral (Región I — Zapopan)",
    "Décimo Tribunal Laboral (Región I — Zapopan)",
    "Décimo Primer Tribunal Laboral (Región I — Zapopan)",
    "Décimo Segundo Tribunal Laboral (Región I — Zapopan)",
    "Décimo Tercer Tribunal Laboral (Región I — Zapopan)",
    "Décimo Cuarto Tribunal Laboral (Región I — Zapopan)",
    "Décimo Quinto Tribunal Laboral (Región I — Zapopan)",
    "Décimo Sexto Tribunal Laboral (Región I — Zapopan)",
    "Décimo Séptimo Tribunal Laboral (Región I — Zapopan)",
    "Décimo Octavo Tribunal Laboral (Región I — Zapopan)",
    "Décimo Noveno Tribunal Laboral (Región I — Zapopan)",
    "Vigésimo Tribunal Laboral (Región I — Zapopan)",
    "Vigésimo Primer Tribunal Laboral (Región I — Zapopan)",
    "Vigésimo Segundo Tribunal Laboral (Región I — Zapopan)",
    "Vigésimo Tercer Tribunal Laboral (Región I — Zapopan)",
    "Vigésimo Cuarto Tribunal Laboral (Región I — Zapopan)",
    "Primer Tribunal Laboral (Región 2 — Puerto Vallarta)",
    "Segundo Tribunal Laboral (Región 2 — Puerto Vallarta)",
    "Tribunal Laboral (Región 3 — Zapotlán El Grande / Ciudad Guzmán)",
    "Tribunal Laboral (Región 4 — Lagos de Moreno)",
    "Tribunal Laboral (Región 5 — Ocotlán)",
    "Tribunal Laboral (Región 6 — Autlán de Navarro)",
  ],
  "Oaxaca": [
    "Juzgado Primero Laboral del Circuito Judicial del Centro (Santa Lucía del Camino)",
    "Juzgado Segundo Laboral del Circuito Judicial del Centro (Santa Lucía del Camino)",
  ],
  "Quintana Roo": [
    "Tribunal Laboral de Chetumal",
    "Tribunal Primero Laboral de Cancún",
    "Tribunal Segundo Laboral de Cancún",
    "Tribunal Laboral de Playa del Carmen",
  ],
  "Sonora": [
    "Primer Tribunal Laboral del Distrito Judicial 1 (Hermosillo)",
    "Segundo Tribunal Laboral del Distrito Judicial 1 (Hermosillo)",
    "Tercer Tribunal Laboral del Distrito Judicial 1 (Hermosillo)",
    "Primer Tribunal Laboral del Distrito Judicial 2 (Ciudad Obregón)",
    "Primer Tribunal Laboral del Distrito Judicial 3 (Nogales)",
    "Primer Tribunal Laboral del Distrito Judicial 4 (San Luis Río Colorado)",
    "Primer Tribunal Laboral del Distrito Judicial 5 (Puerto Peñasco)",
    "Primer Tribunal Laboral del Distrito Judicial 6 (Navojoa)",
    "Primer Tribunal Laboral del Distrito Judicial 7 (Guaymas)",
  ],
  "Tabasco": [
    "Primer Tribunal Laboral Región 01, Centro (Villahermosa)",
    "Segundo Tribunal Laboral Región 01, Centro (Villahermosa)",
    "Tercer Tribunal Laboral Región 01, Centro (Villahermosa)",
    "Cuarto Tribunal Laboral Región 01, Centro (Villahermosa)",
    "Tribunal Laboral Región 02 (Cunduacán)",
    "Tribunal Laboral Región 03 (Macuspana)",
  ],
  "Tamaulipas": [
    "Región I — Ciudad Victoria: Juzgado de Justicia Laboral",
    "Región II — El Mante: Juzgado de Justicia Laboral",
    "Región III — Matamoros: Juzgado de Justicia Laboral",
    "Región IV — Nuevo Laredo: Juzgado de Justicia Laboral",
    "Región V — Reynosa: Primer Tribunal Laboral",
    "Región V — Reynosa: Segundo Tribunal Laboral",
    "Región VI — Altamira: Primer Tribunal Laboral",
    "Región VI — Altamira: Segundo Tribunal Laboral",
  ],
  "Zacatecas": [
    "Tribunal Laboral de Zacatecas",
    "Tribunal Laboral de Fresnillo",
  ],
};

// Tribunales Laborales Federales de primera instancia (Asuntos
// Individuales + Asuntos Colectivos) por estado — directorio oficial OAJ
// (oaj.gob.mx/Directorios), 254 órganos federales de materia de trabajo
// revisados, de los cuales estos 133 son de primera instancia (los que
// aplican para radicar una demanda; se excluyen a propósito Juzgados de
// Distrito y Tribunales Colegiados, que son de amparo, no de radicación).
const TRIBUNALES_FEDERALES_MX = {
  "Aguascalientes": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE AGUASCALIENTES, CON SEDE EN AGUASCALIENTES",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE AGUASCALIENTES, CON SEDE EN AGUASCALIENTES",
  ],
  "Baja California": [
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE BAJA CALIFORNIA, CON SEDE EN TIJUANA",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE BAJA CALIFORNIA, CON SEDE EN TIJUANA",
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE BAJA CALIFORNIA, CON SEDE EN ENSENADA",
  ],
  "Baja California Sur": [
    "TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE BAJA CALIFORNIA SUR, CON SEDE EN LA PAZ",
  ],
  "Campeche": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL DE ESTADO DE CAMPECHE, CON SEDE EN CAMPECHE.",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL DE ESTADO DE CAMPECHE, CON SEDE EN CAMPECHE.",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL DE ESTADO DE CAMPECHE, CON SEDE EN CD. DEL CARMEN.",
    "CUARTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL DE ESTADO DE CAMPECHE, CON SEDE EN CD. DEL CARMEN.",
    "QUINTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL DE ESTADO DE CAMPECHE, CON SEDE EN CD. DEL CARMEN.",
  ],
  "Chiapas": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL DE ESTADO DE CHIAPAS, CON SEDE EN TUXTLA.",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL DE ESTADO DE CHIAPAS, CON SEDE EN TUXTLA.",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL DE ESTADO DE CHIAPAS, CON SEDE EN TUXTLA.",
  ],
  "Chihuahua": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE CHIHUAHUA, CON SEDE EN CHIHUAHUA",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE CHIHUAHUA, CON SEDE EN CHIHUAHUA",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE CHIHUAHUA, CON SEDE EN CIUDAD JUÁREZ",
  ],
  "Ciudad de México": [
    "TRIBUNAL LABORAL FEDERAL DE ASUNTOS COLECTIVOS, CON SEDE EN LA CIUDAD DE MÉXICO.",
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
    "CUARTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
    "QUINTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
    "SEXTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
    "SÉPTIMO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
    "OCTAVO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
    "NOVENO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
    "DÉCIMO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
    "DECIMOPRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
    "DECIMOSEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
    "DECIMOTERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
    "DECIMOCUARTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
    "DECIMOQUINTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
    "DECIMOSEXTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
    "DECIMOSEPTIMO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, CON SEDE EN LA CIUDAD DE MÉXICO",
  ],
  "Coahuila de Zaragoza": [
    "QUINTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE COAHUILA, CON SEDE EN TORREÓN",
    "SEXTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE COAHUILA, CON SEDE EN TORREÓN",
    "SÉPTIMO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE COAHUILA, CON SEDE EN TORREÓN",
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE COAHUILA, CON SEDE EN SALTILLO",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE COAHUILA, CON SEDE EN SALTILLO",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE COAHUILA, CON SEDE EN SALTILLO",
    "CUARTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE COAHUILA, CON SEDE EN SALTILLO",
  ],
  "Colima": [
    "TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE COLIMA, CON SEDE EN COLIMA",
  ],
  "Durango": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE DURANGO, CON SEDE EN DURANGO",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE DURANGO, CON SEDE EN DURANGO",
  ],
  "Estado de México": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE MÉXICO, CON SEDE EN TOLUCA",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE MÉXICO, CON SEDE EN TOLUCA",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE MÉXICO, CON SEDE EN TOLUCA",
    "CUARTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE MÉXICO, CON SEDE EN NAUCALPAN DE JUÁREZ.",
    "QUINTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE MÉXICO, CON SEDE EN NAUCALPAN DE JUÁREZ.",
    "SEXTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE MÉXICO, CON SEDE EN NAUCALPAN DE JUÁREZ.",
    "SÉPTIMO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE MÉXICO, CON SEDE EN NAUCALPAN DE JUÁREZ.",
    "OCTAVO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE MÉXICO, CON SEDE EN NAUCALPAN DE JUÁREZ.",
    "NOVENO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE MÉXICO, CON SEDE EN NAUCALPAN DE JUÁREZ.",
    "DÉCIMO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE MÉXICO, CON SEDE EN NAUCALPAN DE JUÁREZ.",
    "DÉCIMO PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE MÉXICO, CON SEDE EN NAUCALPAN DE JUÁREZ",
  ],
  "Guanajuato": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE GUANAJUATO, CON SEDE EN GUANAJUATO",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE GUANAJUATO, CON SEDE EN GUANAJUATO",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE GUANAJUATO, CON SEDE EN GUANAJUATO",
    "CUARTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE GUANAJUATO, CON SEDE EN GUANAJUATO",
  ],
  "Guerrero": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE GUERRERO, CON SEDE EN ACAPULCO",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE GUERRERO, CON SEDE EN ACAPULCO",
  ],
  "Hidalgo": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE HIDALGO, CON SEDE EN PACHUCA",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE HIDALGO, CON SEDE EN PACHUCA",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE HIDALGO, CON SEDE EN PACHUCA",
  ],
  "Jalisco": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE JALISCO, CON SEDE EN ZAPOPAN",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE JALISCO, CON SEDE EN ZAPOPAN",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE JALISCO, CON SEDE EN ZAPOPAN",
    "CUARTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE JALISCO, CON SEDE EN ZAPOPAN",
    "QUINTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE JALISCO, CON SEDE EN ZAPOPAN",
    "SEXTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE JALISCO, CON SEDE EN ZAPOPAN",
    "SÉPTIMO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE JALISCO, CON SEDE EN ZAPOPAN",
    "OCTAVO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE JALISCO, CON SEDE EN ZAPOPAN",
  ],
  "Michoacán": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE MICHOACÁN, CON SEDE EN MORELIA",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE MICHOACÁN, CON SEDE EN MORELIA",
  ],
  "Morelos": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE MORELOS, CON SEDE EN CUERNAVACA",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE MORELOS, CON SEDE EN CUERNAVACA",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE MORELOS, CON SEDE EN CUERNAVACA",
  ],
  "Nayarit": [
    "TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE NAYARIT, CON SEDE EN TEPIC",
  ],
  "Nuevo León": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE NUEVO LEÓN, CON SEDE EN MONTERREY",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE NUEVO LEÓN, CON SEDE EN MONTERREY",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE NUEVO LEÓN, CON SEDE EN MONTERREY",
    "CUARTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE NUEVO LEÓN, CON SEDE EN MONTERREY",
    "QUINTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE NUEVO LEÓN, CON SEDE EN MONTERREY",
    "SEXTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE NUEVO LEÓN, CON SEDE EN MONTERREY",
    "SÉPTIMO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE NUEVO LEÓN, CON SEDE EN MONTERREY",
    "OCTAVO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE NUEVO LEÓN, CON SEDE EN MONTERREY",
    "NOVENO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE NUEVO LEÓN, CON SEDE EN MONTERREY",
    "DÉCIMO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE NUEVO LEÓN, CON SEDE EN MONTERREY",
  ],
  "Oaxaca": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE OAXACA, CON SEDE EN OAXACA",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE OAXACA, CON SEDE EN OAXACA",
  ],
  "Puebla": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE PUEBLA, CON SEDE EN PUEBLA",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE PUEBLA, CON SEDE EN PUEBLA",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE PUEBLA, CON SEDE EN PUEBLA",
    "CUARTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE PUEBLA, CON SEDE EN PUEBLA",
  ],
  "Querétaro": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE QUERÉTARO, CON SEDE EN QUERÉTARO",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE QUERÉTARO, CON SEDE EN QUERÉTARO",
  ],
  "Quintana Roo": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE QUINTANA ROO, CON SEDE EN CANCÚN",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE QUINTANA ROO, CON SEDE EN CANCÚN",
  ],
  "San Luis Potosí": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE SAN LUIS POTOSÍ, CON SEDE EN SAN LUIS POTOSÍ.",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE SAN LUIS POTOSÍ, CON SEDE EN SAN LUIS POTOSÍ.",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE SAN LUIS POTOSÍ, CON SEDE EN SAN LUIS POTOSÍ.",
  ],
  "Sinaloa": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE SINALOA, CON SEDE EN CULIACÁN",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE SINALOA, CON SEDE EN CULIACÁN",
  ],
  "Sonora": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE SONORA, CON SEDE EN HERMOSILLO",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE SONORA, CON SEDE EN HERMOSILLO",
  ],
  "Tabasco": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE TABASCO, CON SEDE EN VILLAHERMOSA",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE TABASCO, CON SEDE EN VILLAHERMOSA",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE TABASCO, CON SEDE EN VILLAHERMOSA",
    "CUARTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE TABASCO, CON SEDE EN VILLAHERMOSA",
    "QUINTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE TABASCO, CON SEDE EN VILLAHERMOSA",
    "SEXTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE TABASCO, CON SEDE EN VILLAHERMOSA",
  ],
  "Tamaulipas": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE TAMAULIPAS, CON SEDE EN CIUDAD VICTORIA",
    "CUARTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE TAMAULIPAS, CON SEDE EN REYNOSA",
    "QUINTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE TAMAULIPAS, CON SEDE EN REYNOSA",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE TAMAULIPAS, CON SEDE EN TAMPICO",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE TAMAULIPAS, CON SEDE EN TAMPICO",
  ],
  "Tlaxcala": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE TLAXCALA, CON SEDE EN TLAXCALA",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE TLAXCALA, CON SEDE EN TLAXCALA",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE TLAXCALA, CON SEDE EN TLAXCALA",
  ],
  "Veracruz": [
    "SEXTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE VERACRUZ, CON SEDE EN XALAPA",
    "SÉPTIMO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE VERACRUZ, CON SEDE EN XALAPA",
    "OCTAVO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE VERACRUZ, CON SEDE EN XALAPA",
    "NOVENO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE VERACRUZ, CON SEDE EN XALAPA",
    "TERCER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE VERACRUZ, CON SEDE EN VERACRUZ",
    "CUARTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE VERACRUZ, CON SEDE EN VERACRUZ",
    "QUINTO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE VERACRUZ, CON SEDE EN VERACRUZ",
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE VERACRUZ, CON SEDE EN COATZACOALCOS",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES EN EL ESTADO DE VERACRUZ, CON SEDE EN COATZACOALCOS",
  ],
  "Yucatán": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE YUCATÁN, CON SEDE EN MÉRIDA",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE YUCATÁN, CON SEDE EN MÉRIDA",
  ],
  "Zacatecas": [
    "PRIMER TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE ZACATECAS, CON SEDE EN ZACATECAS.",
    "SEGUNDO TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES, EN EL ESTADO DE ZACATECAS, CON SEDE EN ZACATECAS.",
  ],
};
// ---------------------------------------------------------------
// Selector de Tribunal (estado -> tribunal, con "Otro (especificar)") — ver
// EDITABLE_FIELDS ('junta' y 'tribunal', type:'tribunal'), TRIBUNALES_MX y
// TRIBUNALES_CUSTOM. El valor final sigue siendo un solo string plano (igual
// que antes, para no romper los datos ya capturados) — el widget de dos
// selects + texto libre es solo una ayuda para construirlo, guardado en un
// <input type="hidden" class="edit-field"> que el save genérico ya lee.
// ---------------------------------------------------------------
function findTribunalEstado(nombre){
  if(!nombre) return '';
  for(const estado of ESTADOS_MX){
    const locales = TRIBUNALES_LOCALES_MX[estado] || [];
    const federales = TRIBUNALES_FEDERALES_MX[estado] || [];
    const custom = TRIBUNALES_CUSTOM[estado] || [];
    if(locales.includes(nombre) || federales.includes(nombre) || custom.includes(nombre)) return estado;
  }
  return '';
}
function tribunalOptionsHTML(estado, selected){
  if(!estado) return `<option value="">Elige un estado primero…</option>`;
  const locales = TRIBUNALES_LOCALES_MX[estado] || [];
  const federales = TRIBUNALES_FEDERALES_MX[estado] || [];
  const custom = (TRIBUNALES_CUSTOM[estado] || []).filter(n => !locales.includes(n) && !federales.includes(n));
  const opt = o => `<option value="${escapeHTML(o)}" ${selected===o?'selected':''}>${escapeHTML(o)}</option>`;
  let html = `<option value="">— Selecciona —</option>`;
  if(locales.length){
    html += `<optgroup label="Tribunales locales (estatales)">${locales.map(opt).join("")}</optgroup>`;
  } else {
    html += `<optgroup label="Tribunales locales (estatales)"><option value="" disabled>Aún no verificado — usa "Otro (especificar)"</option></optgroup>`;
  }
  if(federales.length){
    html += `<optgroup label="Tribunales / Juzgados Laborales Federales">${federales.map(opt).join("")}</optgroup>`;
  }
  if(custom.length){
    html += `<optgroup label="Agregados manualmente">${custom.map(opt).join("")}</optgroup>`;
  }
  html += `<option value="__otro__">Otro (especificar)…</option>`;
  return html;
}
function tribunalFieldHTML(f, actual){
  actual = actual || '';
  const matchedEstado = findTribunalEstado(actual);
  const isLegacyOtro = !!actual && !matchedEstado;
  const estadoOptions = `<option value="">— Estado —</option>` +
    ESTADOS_MX.map(e=>`<option value="${escapeHTML(e)}" ${matchedEstado===e?'selected':''}>${escapeHTML(e)}</option>`).join("");
  const selStyle = 'width:100%; padding:9px 11px; border:1px solid var(--border); border-radius:8px; background:var(--parchment); font-size:13px;';
  return `
    <div class="trib-widget" data-field="${f.key}">
      <input type="hidden" class="edit-field trib-hidden" data-field="${f.key}" value="${escapeHTML(actual)}">
      <select class="trib-estado-select" data-target="${f.key}" style="${selStyle} margin-bottom:6px;">${estadoOptions}</select>
      <select class="trib-tribunal-select" data-target="${f.key}" style="${selStyle}">${tribunalOptionsHTML(matchedEstado, matchedEstado ? actual : '')}</select>
      <input type="text" class="trib-otro-input" data-target="${f.key}" placeholder="Escribe el nombre exacto del tribunal" value="${isLegacyOtro ? escapeHTML(actual) : ''}" style="${selStyle} margin-top:6px; display:${isLegacyOtro ? 'block' : 'none'};">
      ${isLegacyOtro ? `<div style="font-size:11px; color:var(--gray); margin-top:4px;">Dato anterior sin estandarizar — elige un estado arriba para usar el catálogo, o deja el texto libre como está.</div>` : ''}
    </div>
  `;
}
function bindTribunalFieldEvents(){
  document.querySelectorAll('.trib-widget').forEach(widget=>{
    const hidden = widget.querySelector('.trib-hidden');
    const estadoSel = widget.querySelector('.trib-estado-select');
    const tribSel = widget.querySelector('.trib-tribunal-select');
    const otroInput = widget.querySelector('.trib-otro-input');
    estadoSel.addEventListener('change', ()=>{
      tribSel.innerHTML = tribunalOptionsHTML(estadoSel.value, '');
      otroInput.style.display = 'none';
      otroInput.value = '';
      hidden.value = '';
    });
    tribSel.addEventListener('change', ()=>{
      if(tribSel.value === '__otro__'){
        otroInput.style.display = 'block';
        otroInput.focus();
        hidden.value = otroInput.value.trim();
      } else {
        otroInput.style.display = 'none';
        otroInput.value = '';
        hidden.value = tribSel.value;
      }
    });
    otroInput.addEventListener('input', ()=>{ hidden.value = otroInput.value.trim(); });
  });
}
// Los tribunales escritos en "Otro" se suman al catálogo compartido para que
// dejen de ser texto libre la próxima vez — se llama desde saveCamposBtn.
async function persistTribunalesCustomNuevos(){
  const nuevos = [];
  document.querySelectorAll('.trib-widget').forEach(widget=>{
    const estadoSel = widget.querySelector('.trib-estado-select');
    const tribSel = widget.querySelector('.trib-tribunal-select');
    const hidden = widget.querySelector('.trib-hidden');
    if(tribSel && tribSel.value === '__otro__' && estadoSel.value && hidden.value){
      nuevos.push({estado: estadoSel.value, nombre: hidden.value});
    }
  });
  for(const n of nuevos){
    try{ await api('POST', 'tribunales_custom_add.php', n); }catch(e){ /* no bloquea el guardado del expediente */ }
  }
  if(nuevos.length) await loadTribunalesCustom();
}

function allCases(){ return CASES_DATA; }

// En la versión con MySQL cada expediente que llega del servidor ya trae el
// valor "efectivo" real (editar un campo hace UPDATE directo en la base) —
// ya no existe la distinción entre "dato importado" y "override manual".
function effectiveCase(raw){ return raw; }
function findCase(id){
  return allCases().find(c=>c.id===id) || null;
}

// ---------------------------------------------------------------
// Utilidades de fecha
// ---------------------------------------------------------------
function parseDate(s){
  if(!s) return null;
  const d = new Date(s + "T00:00:00");
  if(isNaN(d.getTime())) return null;
  return d;
}
function fmtDate(s){
  const d = parseDate(s);
  if(!d) return "—";
  return d.toLocaleDateString('es-MX', {day:'2-digit', month:'short', year:'numeric'});
}
function addDaysDate(d, n){ const r = new Date(d); r.setDate(r.getDate()+n); return r; }
function isoDate(d){ return d.getFullYear()+"-"+String(d.getMonth()+1).padStart(2,"0")+"-"+String(d.getDate()).padStart(2,"0"); }
function addMonthsDate(d, n){
  const r = new Date(d);
  const day = r.getDate();
  r.setMonth(r.getMonth()+n);
  // si el mes destino tiene menos días, JS ya hace roll-over; corregir a último día del mes destino si aplica
  if(r.getDate() !== day){ r.setDate(0); }
  return r;
}
function isWeekend(d){ const wd = d.getDay(); return wd===0 || wd===6; }
// Días inhábiles adicionales a sábado/domingo (descansos obligatorios Art. 74
// LFT + los que cada tribunal declare), cargados desde el servidor — ver
// pantalla "Días inhábiles" (Administrador) y api/dias_inhabiles_*.php.
let DIAS_INHABILES_SET = new Set();
function isDiaInhabil(d){ return DIAS_INHABILES_SET.has(isoDate(d)); }
function fmtMoney(n){
  if(n===null || n===undefined || isNaN(n)) return "—";
  return "$" + Number(n).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function daysBetween(a,b){ return Math.round((b-a)/86400000); }

// ---------------------------------------------------------------
// Cómputo de prescripción — Art. 516 a 521 LFT
// ---------------------------------------------------------------
// ---------------------------------------------------------------
// Salarios caídos e intereses — Art. 48 LFT (reforma D.O.F. 30-nov-2012).
// Si no se acredita la causa del despido, el trabajador tiene derecho a los
// salarios vencidos desde la fecha del despido hasta por un tope máximo de
// 12 meses. Si al cumplirse ese plazo el juicio no ha concluido, se generan
// además intereses del 2% mensual, capitalizables, sobre el importe de 15
// meses de salario. Solo aplica cuando ya hay demanda presentada (etapa
// "juicio"), pues antes no existe controversia judicial que los genere.
// ---------------------------------------------------------------
// Resuelve qué salario diario integrado usar para cualquier cálculo basado
// en SDI (salarios caídos, indemnización constitucional): si no hay uno
// capturado, se usa el salario diario simple como aproximación; si el
// capturado es muy distinto al salario diario (fuera de 0.95x-1.5x, rango
// razonable considerando las partes proporcionales de aguinaldo y prima
// vacacional), se trata como un dato mal capturado y también se usa el
// salario diario simple, para no arrastrar el error a ningún cálculo.
function resolveSdi(kase){
  const sdiReal = kase.sdi != null;
  let sdiUsado = sdiReal ? kase.sdi : kase.salario_diario;
  let sdiSospechoso = false;
  if(sdiReal && kase.salario_diario!=null && kase.salario_diario>0){
    const ratio = kase.sdi / kase.salario_diario;
    if(ratio > 1.5 || ratio < 0.95){
      sdiSospechoso = true;
      sdiUsado = kase.salario_diario;
    }
  }
  return {sdiUsado, sdiReal, sdiSospechoso};
}

function computeSalariosCaidos(kase){
  if(caseStage(kase) !== 'juicio') return null;
  const fb = parseDate(kase.fecha_baja);
  const {sdiUsado, sdiReal, sdiSospechoso} = resolveSdi(kase);
  if(!fb || sdiUsado==null) return null;
  const meta = getMeta(kase.id);
  const start = addDaysDate(fb, 1);
  const capDate = addMonthsDate(start, 12); // tope de 12 meses (Art. 48, párrafo 2)
  const sentenciaFecha = meta.etapas.sentencia && meta.etapas.sentencia.fecha ? parseDate(meta.etapas.sentencia.fecha) : null;
  const today = new Date(); today.setHours(0,0,0,0);
  const refDate = sentenciaFecha || today;
  const finalDate = refDate < capDate ? refDate : capDate;
  const diasCaidos = Math.max(0, daysBetween(start, finalDate) + 1);
  const salariosCaidos = sdiUsado * diasCaidos;

  let intereses = 0, mesesIntereses = 0, baseIntereses = 0;
  const excedioTope = refDate > capDate;
  if(excedioTope){
    mesesIntereses = Math.max(0, Math.floor(daysBetween(capDate, refDate) / 30));
    baseIntereses = sdiUsado * 30 * 15; // "importe de quince meses de salario"
    intereses = mesesIntereses>0 ? baseIntereses * (Math.pow(1.02, mesesIntereses) - 1) : 0; // 2% mensual capitalizable
  }
  return {diasCaidos, salariosCaidos, capDate, refDate, excedioTope, mesesIntereses, baseIntereses, intereses,
    sdiReal, sdiUsado, sdiSospechoso, total: salariosCaidos + intereses};
}

// ---------------------------------------------------------------
// Cálculo de liquidación (indemnización constitucional + prestaciones
// proporcionales) — antes estos montos se capturaban a mano en "Editar
// datos"; ahora se calculan siempre a partir de fecha de ingreso, fecha de
// baja y salario, con las mismas fórmulas que la calculadora pública del
// despacho, para que nadie tenga que hacer la aritmética y el resultado sea
// consistente en todo el sistema. Todos los asuntos de este despacho son de
// despido (nunca renuncia), así que la prima de antigüedad siempre procede,
// sin el requisito de 15 años de servicio que aplicaría en una renuncia.
// ---------------------------------------------------------------
function completedYearsLFT(ing, baj){
  let y = baj.getFullYear() - ing.getFullYear();
  const a = new Date(baj.getFullYear(), ing.getMonth(), ing.getDate());
  if(baj < a) y--;
  return Math.max(0, y);
}
function lastAnniversaryLFT(ing, baj){
  const cy = completedYearsLFT(ing, baj);
  return new Date(ing.getFullYear()+cy, ing.getMonth(), ing.getDate());
}
// Tabla de vacaciones del art. 76 LFT (reforma DOF 27-12-2022): 12, 14, 16,
// 18, 20 días y +2 por cada quinquenio adicional a partir del 6° año.
function vacDaysLFT(y){
  if(y<=0) return 0;
  return y<=5 ? 10+2*y : 20+2*Math.ceil((y-5)/5);
}
// Días de vacaciones de años anteriores que TODAVÍA no prescriben: cada
// periodo anual prescribe 18 meses después de su aniversario (Art. 516 LFT,
// criterio de la Segunda Sala SCJN 2a./J. 1/97 — el año del 516 corre al
// vencer los 6 meses del 81, contados desde cada aniversario).
function maxVacNoPrescritoLFT(ing, baj){
  const cy = completedYearsLFT(ing, baj);
  let tot = 0, per = [];
  for(let i=cy; i>=1; i--){
    const A = new Date(ing.getFullYear()+i, ing.getMonth(), ing.getDate());
    if(baj < addMonthsDate(A, 18)){ tot += vacDaysLFT(i); per.push(i); } else break;
  }
  return {tot, per};
}

function computeLiquidacion(kase){
  const ing = parseDate(kase.fecha_ingreso);
  const baj = parseDate(kase.fecha_baja);
  const sd = kase.salario_diario;
  if(!ing || !baj || !sd || baj < ing) return null;

  const {sdiUsado: sdi, sdiReal, sdiSospechoso} = resolveSdi(kase);
  const antigDias = daysBetween(ing, baj) + 1;
  const aniosDec = antigDias / 365;
  const cy = completedYearsLFT(ing, baj);

  // Salarios devengados pendientes de pago (art. 82): días ya trabajados
  // que no se pagaron antes de la baja. Es un dato que solo el despacho
  // conoce (no se puede derivar de fecha/salario), así que se captura en
  // "Editar datos"; si tiene más de un año de antigüedad, se avisa que
  // parte podría estar prescrita (Art. 516).
  const diasDevengados = kase.dias_salarios_devengados > 0 ? kase.dias_salarios_devengados : 0;
  const salariosDevengadosMonto = diasDevengados * sd;
  let devengadosPosibleprescripcion = false;
  if(diasDevengados > 0 && kase.fecha_desde_salarios_devengados){
    const desde = parseDate(kase.fecha_desde_salarios_devengados);
    if(desde && daysBetween(desde, baj) > 365) devengadosPosibleprescripcion = true;
  }

  // Aguinaldo proporcional (art. 87) — mínimo de ley 15 días, o los días
  // pactados si el despacho capturó un contrato con un aguinaldo mayor.
  const aguinaldoDiasBase = kase.aguinaldo_dias_pactados > 0 ? kase.aguinaldo_dias_pactados : 15;
  const yearStart = new Date(baj.getFullYear(), 0, 1);
  const aguStart = ing > yearStart ? ing : yearStart;
  const aguDias = daysBetween(aguStart, baj) + 1;
  const aguinaldoDias = aguinaldoDiasBase * (aguDias/365);
  const aguinaldoMonto = aguinaldoDias * sd;

  // Vacaciones proporcionales del periodo en curso (arts. 76 y 79) — siempre
  // exigibles a la baja, sin importar el motivo de la separación.
  const anniv = lastAnniversaryLFT(ing, baj);
  const diasCurso = daysBetween(anniv, baj);
  const vacEnt = vacDaysLFT(cy+1);
  const vacacionesDiasProp = vacEnt * (diasCurso/365);

  // Vacaciones de periodos anteriores no disfrutadas, hasta el máximo no
  // prescrito (ver maxVacNoPrescritoLFT arriba).
  const vacNoPrescrito = maxVacNoPrescritoLFT(ing, baj);
  const vacAntPedidos = kase.dias_vacaciones_anteriores_reclamados > 0 ? kase.dias_vacaciones_anteriores_reclamados : 0;
  const vacAntDias = Math.min(vacAntPedidos, vacNoPrescrito.tot);
  const vacAntExcedePrescripcion = vacAntPedidos > vacNoPrescrito.tot;

  const vacacionesDias = vacacionesDiasProp + vacAntDias;
  const vacacionesMonto = vacacionesDias * sd;

  // Prima vacacional — mínimo de ley, 25% sobre el total de vacaciones
  // cubiertas (proporcionales + de años anteriores) (art. 80), salvo que el
  // despacho haya capturado un porcentaje mayor pactado por contrato.
  const primaVacacionalPct = kase.prima_vacacional_pct_pactada > 25 ? kase.prima_vacacional_pct_pactada : 25;
  const primaVacacionalMonto = vacacionesMonto * (primaVacacionalPct/100);

  // Prima de antigüedad — 12 días por año, con base topada a 2x el salario
  // mínimo diario vigente (art. 162). Siempre procede: en este despacho el
  // motivo de la separación siempre es el despido, nunca la renuncia (que
  // exigiría 15+ años de servicio).
  const topePrima = 2 * SALARIO_MINIMO_DIARIO;
  const primaTopada = sd > topePrima;
  const basePrima = Math.min(sd, topePrima);
  const primaAntiguedad = 12 * aniosDec * basePrima;

  // Indemnización constitucional — 90 días de salario diario integrado
  // (arts. 48 y 50, fracc. II).
  const indemnizacion90 = 90 * sdi;

  // Prestaciones adicionales capturadas a mano (p.ej. fondo de ahorro
  // pactado por contrato) — no forman parte del cálculo estándar de ley,
  // se suman tal cual el despacho las capturó en "Cálculo de liquidación".
  const meta = getMeta(kase.id);
  const prestacionesExtra = (meta.prestaciones_extra || []).filter(p => p && p.concepto);
  const prestacionesExtraTotal = prestacionesExtra.reduce((s,p)=> s + (parseFloat(p.monto) || 0), 0);

  const totalFiniquito = salariosDevengadosMonto + aguinaldoMonto + vacacionesMonto + primaVacacionalMonto + primaAntiguedad + prestacionesExtraTotal;
  const total90 = totalFiniquito + indemnizacion90;

  return {
    antigDias, aniosDec, cy,
    diasDevengados, salariosDevengadosMonto, devengadosPosibleprescripcion,
    aguinaldoDiasBase, aguinaldoDias, aguinaldoMonto,
    vacacionesDiasProp, vacAntDias, vacAntPedidos, vacAntExcedePrescripcion, vacNoPrescrito,
    vacacionesDias, vacacionesMonto,
    primaVacacionalMonto, primaVacacionalPct,
    primaAntiguedad, primaTopada, topePrima,
    indemnizacion90, sdiUsado: sdi, sdiReal, sdiSospechoso,
    prestacionesExtra, prestacionesExtraTotal,
    totalFiniquito, total90,
  };
}

// Plazo de prescripción: 60 días naturales corridos, contados uno por uno
// desde el día siguiente a la separación (no "2 meses de fecha a fecha" —
// esa variante puede dar 59 a 62 días según los meses que abarque, un día
// de diferencia real que ya causó una prescripción mal calculada). Mientras
// dura la conciliación prejudicial el plazo se suspende: los días desde que
// se presenta la solicitud hasta que se levanta la constancia de no
// conciliación (ambos inclusive) no cuentan, y el conteo se reanuda al día
// siguiente de la constancia.
function computePrescripcion(kase){
  const fb = parseDate(kase.fecha_baja);
  if(!fb) return null;
  const plazo = tipoAsuntoPlazo(kase);
  const start = addDaysDate(fb, 1); // corre a partir del día siguiente al hecho generador

  // Vencimiento sin contar la suspensión por conciliación: días naturales
  // exactos según el tipo de asunto (ver TIPO_ASUNTO_PLAZOS).
  const naiveDeadline = addDaysDate(start, plazo.dias - 1);

  const {inicio, constancia} = fechasConciliacionEfectivas(kase);
  const ci = parseDate(inicio);
  const today = new Date(); today.setHours(0,0,0,0);

  // Los días que el asunto pasó en conciliación no cuentan para el plazo —
  // basta con recorrer el vencimiento "sin suspensión" hacia adelante
  // exactamente esos días.
  let deadline = naiveDeadline, suspensionDays = 0;
  if(ci){
    const cf = parseDate(constancia);
    const suspEnd = cf ? cf : today; // si aún no hay constancia, estimado provisional a hoy
    suspensionDays = Math.max(0, daysBetween(ci, suspEnd) + 1); // ci..suspEnd, ambos inclusive, no cuentan
    deadline = addDaysDate(naiveDeadline, suspensionDays);
  }
  // Si concluye en día inhábil (sáb/dom), se traslada al día hábil siguiente (simplificación:
  // no incorpora el calendario oficial de días inhábiles de cada Tribunal/Centro, solo fines de semana).
  let adjustedForWeekend = false;
  while(isWeekend(deadline)){ deadline = addDaysDate(deadline,1); adjustedForWeekend = true; }

  const daysLeft = daysBetween(today, deadline);
  return {
    start, deadline, suspensionDays, daysLeft, adjustedForWeekend, plazo,
    enConciliacion: !!(ci && !kase.fecha_constancia)
  };
}

function addBusinessDays(start, n){
  let d = new Date(start), count = 0;
  while(count < n){
    if(!isWeekend(d) && !isDiaInhabil(d)) count++;
    if(count < n) d = addDaysDate(d,1);
  }
  return d;
}

// Amparo directo laboral — Art. 17, párrafo primero, de la Ley de Amparo: 15 días
// hábiles, contados a partir del día siguiente a que surta efectos la notificación
// de la sentencia/laudo definitivo (Art. 22 Ley de Amparo). addBusinessDays ya
// descuenta también los días inhábiles capturados en "Días inhábiles" — aun así,
// verifica el vencimiento con el calendario oficial del Tribunal Colegiado antes
// de un plazo crítico.
function computeAmparoDeadline(meta){
  const fn = parseDate(meta.amparo_fecha_notif);
  if(!fn) return null;
  const start = addDaysDate(fn,1);
  const deadline = addBusinessDays(start, 15);
  const today = new Date(); today.setHours(0,0,0,0);
  return {deadline, daysLeft: daysBetween(today, deadline)};
}

// ---------------------------------------------------------------
// Etapas del procedimiento ordinario laboral (Título Catorce, Cap. XVII
// LFT, reforma D.O.F. 01-mayo-2019) — bitácora editable por el abogado.
// Cuando el abogado marca una etapa como cumplida, el sistema recalcula
// automáticamente la prescripción y detecta si el asunto se "atoró"
// (venció el plazo legal de la siguiente etapa sin que se haya marcado).
// ---------------------------------------------------------------
const ETAPAS_DEF = [
  {key:'conciliacion_prejudicial', label:'Conciliación prejudicial', fundamento:''},
  {key:'conciliacion_solicitada', label:'Solicitud de conciliación presentada', fundamento:'Art. 684-B LFT'},
  {key:'conciliacion_primer_citatorio', label:'Conciliación prejudicial (Primer citatorio)', fundamento:'Art. 684-B LFT', conHora:true},
  {key:'conciliacion_segundo_citatorio', label:'Conciliación prejudicial (Segundo citatorio)', fundamento:'Art. 684-B LFT', conHora:true},
  {key:'conciliacion_convenio', label:'Convenio', fundamento:'Acuerdo entre las partes ante el Centro de Conciliación'},
  {key:'constancia_no_conciliacion', label:'Constancia de no conciliación recibida', fundamento:'Arts. 684-C y 521-III LFT', plazoDesdeEtapa:'conciliacion_solicitada', plazoDias:45, plazoTipo:'naturales', plazoLabel:'La conciliación dura hasta 45 días naturales (Art. 684-C LFT)'},
  {key:'demanda_presentada', label:'Demanda presentada ante el Tribunal', fundamento:'Art. 871 LFT', plazoLabel:'Debe presentarse antes de que venza la prescripción (ver pestaña Prescripción)'},
  {key:'prevencion', label:'Prevención (el Tribunal previno por defectos u omisiones en la demanda)', fundamento:'Art. 873 LFT', plazoDesdeEtapa:'demanda_presentada', plazoDias:3, plazoTipo:'habiles', plazoLabel:'Solo aplica si el Tribunal previno la demanda; el actor debe desahogarla dentro de los 3 días siguientes a la notificación (Art. 873 LFT). Si no hubo prevención, deja esta etapa en blanco y continúa con "Demanda admitida".'},
  {key:'demanda_admitida', label:'Demanda admitida', fundamento:'Art. 873 LFT', plazoDesdeEtapa:'demanda_presentada', plazoDias:3, plazoTipo:'habiles', plazoLabel:'El Tribunal admite dentro de los 3 días siguientes a que se le turne, o a que se desahogue la prevención si la hubo (Art. 873 LFT)'},
  {key:'emplazamiento', label:'Emplazamiento a la demandada realizado', fundamento:'Art. 873-A LFT', plazoDesdeEtapa:'demanda_admitida', plazoDias:5, plazoTipo:'habiles', plazoLabel:'El Tribunal emplaza dentro de los 5 días siguientes a la admisión (Art. 873-A LFT)'},
  {key:'contestacion_recibida', label:'Contestación de demanda recibida', fundamento:'Art. 873-A LFT', plazoDesdeEtapa:'emplazamiento', plazoDias:15, plazoTipo:'habiles', plazoLabel:'La demandada contesta dentro de los 15 días siguientes al emplazamiento (Art. 873-A LFT)'},
  {key:'objeciones_replica', label:'Objeciones y réplica del actor presentadas', fundamento:'Art. 873-B LFT', plazoDesdeEtapa:'contestacion_recibida', plazoDias:8, plazoTipo:'habiles', plazoLabel:'El actor objeta pruebas y formula réplica dentro de los 8 días siguientes al traslado de la contestación (Art. 873-B LFT)'},
  {key:'contrarreplica', label:'Contrarréplica de la demandada presentada', fundamento:'Art. 873-C LFT', plazoDesdeEtapa:'objeciones_replica', plazoDias:5, plazoTipo:'habiles', plazoLabel:'La demandada contrarreplica dentro de los 5 días siguientes al traslado de la réplica (Art. 873-C LFT)'},
  {key:'manifestaciones_3dias', label:'Manifestaciones sobre pruebas nuevas (si las hubo en la contrarréplica)', fundamento:'Art. 873-C LFT', plazoDesdeEtapa:'contrarreplica', plazoDias:3, plazoTipo:'habiles', plazoLabel:'Solo aplica si la demandada ofreció pruebas nuevas en su contrarréplica; el actor tiene 3 días para manifestarse (Art. 873-C LFT)'},
  {key:'audiencia_preliminar', label:'Audiencia preliminar celebrada', fundamento:'Arts. 873-E a 873-G LFT', plazoDesdeEtapa:'contrarreplica', plazoDias:10, plazoTipo:'habiles', plazoLabel:'El Tribunal la fija dentro de los 10 días siguientes a que concluya la fase escrita (Art. 873-C LFT)'},
  {key:'audiencia_juicio', label:'Audiencia de juicio celebrada', fundamento:'Arts. 873-H a 891 LFT'},
  {key:'sentencia', label:'Sentencia / laudo emitido', fundamento:'Art. 873-J LFT'},
  {key:'amparo_directo', label:'Amparo directo presentado (si procede)', fundamento:'Art. 17 Ley de Amparo — 15 días hábiles desde la notificación de la sentencia', plazoDesdeEtapa:'sentencia', plazoDias:15, plazoTipo:'habiles', plazoLabel:'Captura la fecha de notificación de la sentencia en la pestaña "Amparo" para un cómputo exacto del plazo'},
];

function etapaExpectedDeadline(def, fechaAnterior){
  const f = parseDate(fechaAnterior);
  if(!f || !def.plazoDias) return null;
  const start = addDaysDate(f,1);
  return def.plazoTipo === 'habiles' ? addBusinessDays(start, def.plazoDias) : addDaysDate(start, def.plazoDias-1);
}

// Recorre la bitácora de etapas y detecta si el asunto lleva más tiempo del
// plazo legal esperado sin que se haya registrado la siguiente etapa.
// Determina en qué etapa procesal se encuentra el asunto AHORA MISMO y desde
// qué fecha, combinando la bitácora manual (si el abogado la llenó) con lo que
// ya se puede inferir del estatus/fechas importados — para que el informe
// funcione aunque el abogado no haya tocado la pestaña de Etapas.
const AUDIENCIA_LABEL_CLIENTE = {
  audiencia_preliminar: 'Audiencia preliminar — donde se organiza tu caso y se admiten las pruebas',
  audiencia_juicio: 'Audiencia de juicio — donde se presentan las pruebas',
};

// Revisa si hay una audiencia (preliminar o de juicio) agendada que todavía
// no se ha celebrado, para avisarle al cliente y al abogado con anticipación.
function proximaAudiencia(kase){
  const meta = getMeta(kase.id);
  const candidatas = ['audiencia_preliminar','audiencia_juicio']
    .map(key=>{
      const e = meta.etapas[key];
      if(!e || e.fecha || !e.fecha_programada) return null; // ya celebrada, o sin fecha agendada
      return {key, fecha: e.fecha_programada, label: AUDIENCIA_LABEL_CLIENTE[key]};
    })
    .filter(Boolean)
    .sort((a,b)=> a.fecha.localeCompare(b.fecha));
  return candidatas[0] || null;
}

// Revisa si hay un pago de convenio programado que todavía no se cobra, para
// avisarle al trabajador (y al abogado) con anticipación.
// ---------------------------------------------------------------
// Aviso al cliente por WhatsApp con el avance de su asunto. No es un envío
// silencioso (eso requeriría la API de WhatsApp Business y un servidor
// propio) — abre WhatsApp con el mensaje ya redactado, listo para que el
// abogado solo presione enviar.
// ---------------------------------------------------------------
function telefonoWhatsApp(telefono){
  if(!telefono) return null;
  let texto = String(telefono);
  // si el dato distingue un número de WhatsApp explícitamente (ej. "Llamadas 55... Whats 720...")
  // priorizar ese, ya que puede ser distinto al número de llamadas
  const iWhats = texto.search(/whats/i);
  if(iWhats >= 0) texto = texto.slice(iWhats);
  let digits = texto.replace(/\D/g,'');
  if(digits.length > 13) digits = digits.slice(-10); // por si quedaron pegados varios números
  if(digits.length === 10) digits = '52' + digits;
  if(digits.length < 12 || digits.length > 13) return null;
  return digits;
}

function mensajeWhatsAppCliente(kase){
  const estado = clienteEstadoInfo(kase);
  const aud = proximaAudiencia(kase);
  const pago = proximoPago(kase);
  let msg = `Hola ${kase.actor ? kase.actor.split(' ')[0] : ''}, te escribo del despacho Expertos Laborales para contarte cómo va tu asunto:\n\n`;
  msg += `📌 ${estado.label}\n`;
  if(estado.siguiente) msg += `\n¿Qué sigue? ${estado.siguiente}\n`;
  if(aud) msg += `\n📅 Audiencia programada: ${fmtDate(aud.fecha)} (${aud.label})\n`;
  if(pago) msg += `\n💰 Pago programado: ${fmtDate(pago.fecha)}${pago.monto!=null? ' — '+fmtMoney(pago.monto):''}\n`;
  msg += `\nCualquier duda, quedo al pendiente.`;
  return msg;
}

function abrirWhatsAppCliente(kase){
  const tel = telefonoWhatsApp(kase.telefono);
  if(!tel){
    alert('No hay un teléfono capturado (o el formato no es válido) para este cliente. Captúralo en "Editar datos" para poder avisarle por WhatsApp.');
    return;
  }
  const msg = mensajeWhatsAppCliente(kase);
  const url = 'https://wa.me/' + tel + '?text=' + encodeURIComponent(msg);
  window.open(url, '_blank');
}

function proximoPago(kase){
  if(!tieneConvenio(kase)) return null;
  const meta = getMeta(kase.id);
  const pagos = meta.pagos || [];
  const pendientes = pagos.filter(p=>!p.cobrado && p.fecha).sort((a,b)=> a.fecha.localeCompare(b.fecha));
  if(!pendientes.length) return null;
  const prox = pendientes[0];
  const montoProx = prox.monto && !isNaN(parseFloat(prox.monto)) ? parseFloat(prox.monto) : null;
  return {fecha: prox.fecha, monto: montoProx, montoAproximado: montoProx==null};
}

function etapaActualInfo(kase){
  const meta = getMeta(kase.id);
  // 1) si hay etapas marcadas manualmente, usar la más avanzada
  let ultimaIdx = -1;
  for(let i=0;i<ETAPAS_DEF.length;i++){
    if(meta.etapas[ETAPAS_DEF[i].key] && meta.etapas[ETAPAS_DEF[i].key].fecha) ultimaIdx = i;
  }
  if(ultimaIdx >= 0){
    const def = ETAPAS_DEF[ultimaIdx];
    return {label: def.label, fundamento: def.fundamento, fecha: meta.etapas[def.key].fecha, origen: 'bitácora del abogado'};
  }
  // 2) si no, inferir a partir del estatus/fechas del expediente
  const stage = caseStage(kase);
  if(stage === 'pagado') return {label:'Convenio pagado — asunto concluido', fundamento:'Estatus del expediente', fecha: kase.fecha_baja, origen:'estatus importado'};
  if(stage === 'desistio') return {label:'Cliente desistió — asunto concluido', fundamento:'Estatus del expediente', fecha: kase.fecha_baja, origen:'estatus importado'};
  if(stage === 'convenio') return {label:'Convenio pactado, pendiente de cobro', fundamento:'Nota del expediente', fecha: null, origen:'nota del expediente'};
  if(stage === 'juicio') return {label:'En juicio ante el Tribunal (demanda presentada)', fundamento:'Art. 871 LFT', fecha: kase.fecha_baja, origen:'estatus importado (sin fecha exacta de demanda registrada)'};
  if(stage === 'constancia_demanda') return {label:'Constancia de no conciliación recibida — pendiente presentar demanda', fundamento:'Art. 871 LFT', fecha: kase.fecha_constancia, origen:'estatus importado'};
  if(kase.fecha_inicio_conciliacion) return {label:'En conciliación prejudicial', fundamento:'Art. 684-B LFT', fecha: kase.fecha_inicio_conciliacion, origen:'estatus importado'};
  return {label:'Despido registrado — pendiente iniciar conciliación', fundamento:'Art. 684-B LFT', fecha: kase.fecha_baja, origen:'estatus importado'};
}

function estancadoInfo(kase){
  const meta = getMeta(kase.id);
  let lastIdx = -1;
  for(let i=0;i<ETAPAS_DEF.length;i++){
    if(meta.etapas[ETAPAS_DEF[i].key] && meta.etapas[ETAPAS_DEF[i].key].fecha) lastIdx = i;
  }
  if(lastIdx === -1 || lastIdx === ETAPAS_DEF.length-1) return null;
  const next = ETAPAS_DEF[lastIdx+1];
  if(!next.plazoDias) return null;
  const lastFecha = meta.etapas[ETAPAS_DEF[lastIdx].key].fecha;
  const expected = etapaExpectedDeadline(next, lastFecha);
  if(!expected) return null;
  const today = new Date(); today.setHours(0,0,0,0);
  if(today > expected){
    return {ultima: ETAPAS_DEF[lastIdx].label, siguiente: next.label, expected, diasAtraso: daysBetween(expected, today)};
  }
  return null;
}

// Fechas efectivas de conciliación/constancia: la bitácora manual de
// etapas tiene prioridad sobre el dato importado de la hoja de cálculo,
// porque refleja el seguimiento real y más reciente del abogado.
function fechasConciliacionEfectivas(kase){
  const meta = getMeta(kase.id);
  const inicio = (meta.etapas.conciliacion_solicitada && meta.etapas.conciliacion_solicitada.fecha) || kase.fecha_inicio_conciliacion;
  const constancia = (meta.etapas.constancia_no_conciliacion && meta.etapas.constancia_no_conciliacion.fecha) || kase.fecha_constancia;
  return {inicio, constancia};
}

// ---------------------------------------------------------------
// Generación de demanda por combinación de correspondencia. La plantilla
// se basa en el formato de demanda que proporcionó el despacho y es
// editable por el Administrador. Los {{tags}} se sustituyen con los datos
// capturados de cada asunto.
// ---------------------------------------------------------------
const MESES_ES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
function fechaLetras(iso){
  const d = parseDate(iso);
  if(!d) return '____________';
  return `${d.getDate()} de ${MESES_ES[d.getMonth()]} de ${d.getFullYear()}`;
}

// Conversor de números a letras en español, para cantidades en pesos como
// se acostumbra en documentos legales ("nueve mil trescientos pesos 00/100 M.N.").
const UNIDADES = ['','uno','dos','tres','cuatro','cinco','seis','siete','ocho','nueve','diez',
  'once','doce','trece','catorce','quince','dieciséis','diecisiete','dieciocho','diecinueve','veinte'];
const DECENAS = ['','','veinte','treinta','cuarenta','cincuenta','sesenta','setenta','ochenta','noventa'];
const CENTENAS = ['','ciento','doscientos','trescientos','cuatrocientos','quinientos','seiscientos','setecientos','ochocientos','novecientos'];
function trescientosEnLetras(n){
  if(n===0) return '';
  if(n===100) return 'cien';
  let s = '';
  const c = Math.floor(n/100), d = Math.floor((n%100)/10), u = n%10;
  if(c) s += CENTENAS[c] + ' ';
  const resto = n%100;
  if(resto <= 20) s += UNIDADES[resto];
  else if(d===2) s += 'veinti' + UNIDADES[u];
  else { s += DECENAS[d]; if(u) s += ' y ' + UNIDADES[u]; }
  return s.trim();
}
function numeroEnLetras(n){
  n = Math.floor(n);
  if(n===0) return 'cero';
  if(n < 0) return 'menos ' + numeroEnLetras(-n);
  const millones = Math.floor(n/1000000);
  const miles = Math.floor((n%1000000)/1000);
  const resto = n%1000;
  let partes = [];
  if(millones) partes.push(millones===1 ? 'un millón' : trescientosEnLetras(millones)+' millones');
  if(miles) partes.push(miles===1 ? 'mil' : trescientosEnLetras(miles)+' mil');
  if(resto) partes.push(trescientosEnLetras(resto));
  return partes.join(' ').trim();
}
function pesosEnLetras(monto){
  if(monto===null || monto===undefined || isNaN(monto)) return '____________';
  const entero = Math.floor(monto);
  const centavos = Math.round((monto-entero)*100);
  const letras = numeroEnLetras(entero);
  return `${letras[0].toUpperCase()}${letras.slice(1)} pesos ${String(centavos).padStart(2,'0')}/100 M.N.`;
}

function xmlEscape(s){
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&apos;');
}

function mergeTags(k){
  const junta = k.junta || k.instancia || '';
  const ciudad = /tlalnepantla/i.test(junta) ? 'Tlalnepantla de Baz, Estado de México' :
                 /ciudad de méxico|cdmx/i.test(junta) ? 'Ciudad de México' :
                 /naucalpan/i.test(junta) ? 'Naucalpan de Juárez, Estado de México' : 'Estado de México';
  return {
    actor: k.actor || '________________',
    demandado: k.demandado || '________________',
    giro_empresa: k.giro_empresa || '________________',
    dom_demandado: k.dom_demandado || '________________',
    dom_testigos: k.dom_testigos || '________________',
    puesto: k.puesto || '________________',
    puesto_despidio: k.puesto_despidio || '________________',
    fecha_ingreso_letra: fechaLetras(k.fecha_ingreso),
    fecha_baja_letra: fechaLetras(k.fecha_baja),
    salario_mensual_num: k.salario_mensual!=null ? Number(k.salario_mensual).toFixed(2) : '____________',
    salario_diario_num: k.salario_diario!=null ? Number(k.salario_diario).toFixed(2) : '________',
    salario_mensual_letra: k.salario_mensual!=null ? pesosEnLetras(k.salario_mensual) : '____________',
    curp: (k.curp || '________________').toLowerCase(),
    instancia: k.instancia || k.junta || '________________',
    quien_despidio: k.quien_despidio || 'una persona que dijo representar a la empresa demandada',
    hora_despido: k.hora_despido || '____',
    testigos: k.testigos || '________________',
    fecha_hoy_letra: fechaLetras(dateToISO(new Date())),
    ciudad_firma: ciudad,
  };
}

// ---------------------------------------------------------------
// Generación real de la demanda en formato Word (.docx), a partir de la
// plantilla original del despacho con los MERGEFIELD convertidos a
// {{tags}}. Se procesa 100% en el navegador con JSZip: se descomprime el
// .docx (que es un .zip), se sustituyen los {{tags}} dentro de
// word/document.xml y se vuelve a comprimir — sin depender de ningún
// servidor y sin alterar el resto del formato original.
// ---------------------------------------------------------------
let JSZIP_LOADED = false;
function ensureJSZip(cb){
  if(window.JSZip){ JSZIP_LOADED = true; cb(); return; }
  const s = document.createElement('script');
  s.src = 'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js';
  s.onload = ()=>{ JSZIP_LOADED = true; cb(); };
  s.onerror = ()=> alert('No se pudo cargar la librería para generar el Word (JSZip). Verifica tu conexión a internet.');
  document.head.appendChild(s);
}

function base64ToUint8Array(b64){
  const bin = atob(b64);
  const arr = new Uint8Array(bin.length);
  for(let i=0;i<bin.length;i++) arr[i] = bin.charCodeAt(i);
  return arr;
}
function uint8ArrayToBase64(arr){
  let bin = '';
  const chunk = 0x8000;
  for(let i=0;i<arr.length;i+=chunk) bin += String.fromCharCode.apply(null, arr.subarray(i, i+chunk));
  return btoa(bin);
}

const PLANTILLA_DOCX_DEFAULT_B64 = "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";
const LOGO_DESPACHO_B64 = "iVBORw0KGgoAAAANSUhEUgAAAVUAAAB+CAYAAACUEIJvAACcRklEQVR4nOx9d5wV1fn+c8rU27bvwtI7IkWRooiKIFhiUCSKJbaoWPjGGo2xp6hJrFHRWH5qbDQrolFRQYpdRAHpZeks2/e2Kef8/ph7docV1CRi3Wc/s7fNnXZnnnnPW54XaEUrWtGKVrSiFa1oRSta0YpWtKIVrWhFK1rRila0ohWtaEUrWtGKVrSiFT9ukO97A36wICAAJQBjgGGAUAqC3BETAhIIJikhPQ9wXUAIAvU9QEKI73EPWtGKVnwP4N/3BnwVyP9I+jKgvf8SlIAyBhKLIa9zZ7iU6l07dfKp7/tbKipACUF1YyNMXUfdli0Q9fV5cWY11KUaGNG4lJR6yGZbibUVrfh54QdNqt8rKAi4ZbUbfMghw0effHK0uF07LWaaPvX9TLqhgUnATwJLP3r//Y9enToVdb5f21hdZ5hUczLCJyDkf2D0VrSiFT9StJLqHsEoIvF45wEDB5Z06dDBjufnNzRWVRkmY9FYLJZMum5Rt+7dSSIe7zmoX78XnrjvvuTyhQuz6boUMTQqso6DViu1Fa342aGVVPcEQikSxcV9Bg0ZYiZsu76hsjJZtWWLaXFuRmMxyqLRz9evW1dS1qFDm66dO4/KNDS8eNuyZcimMtLP+qAAJAjk/+KCaEUrWvFjQyup7hGcI1JQkF/Ups2nny5a9PrLM2b4OysqiosKCnr1P+CAHoNHjChpP2BAnUNp1bb6+qJO++xT2L1//6oPd+4EXJ8yMOHC/b73ohWtaMV3i58sqf5vQapgAaisrq7bvnNnzbZNm/y1K1YgVVlZuX7VqsrFS5fOm/vJJyded8cdXqSwMBbPz3crGxoKYsXFVR4hkYhhZ5LZJAHI/7wdrWhFK35UoN/3BnwVJCD/26lpIeS//JMAQGnVqlWr3B2bN6O2shKu60ICSLkuVq9du2junDkR6bqmk82S+mRy1ZwFCwg4z9ZnU8wHoz/w49uKVrTi28dP+6In/0NKlhACdVVVtWuXL4/4jgP4PjKOA9fzKNd1+J63T8eyMtZYXa1nampWfvzee0inUoYghAHM4DC+xT1pRSta8SPBT4ZUSQjqPa5pmnrOOOeUMdY0P6Vfs++eRzK1tdUbV6yIa0LAcxxq2jagacL1fcSj0U5Ftt05Qcjit1988d0Z/+//Aa4rpOdJQEoOKYDW6H8rWvEzw0/GpyqllEBArpRSKgmI57pNgSLf87xd5hdfl+7k+0A6vWHlZ58tXfHhh6CAEFJCsyz4uj7kkOHDy4tisc8/nT9/xUdvvYXk1q2A70tGiOvDcbJw0epPbUUrfnb4YZMqJV9pTVJKqRBCIEeoACAhA4KVkJCUEI1zAJC+7wMA5cFr4fs+voZYJbLZqtqt23zpSWimBkEpzEgkr3ufPoMOHz48Wb99+/tzX355+6dz5wIAGKUuJQQ6s+D4DgREa0pVK1rx88IPm1RDZLk7iBxR7hEEkC0sVBGyXhFyFXz5u0JCgvi+58PkJpjO4WkaCoqKDhxzxBElHdu1e/O5xx9f9fmHiyGzEpwx+MwPtlny/8mf24pWtOJHix82qf4nyA371UsppZSEUSjiVf7U8OuvImVCAF3T4XkefOqDcxOGZfU++JBDegzYb78169evnzt79mzU1tRCQID4BIwxCAi4wkXOVt6bu9yKVrTih4efTKAKUkrh+76apBBil+G9lPLrLN9dQSl8KaDZFrLCR2M2SwtLSo455thjM47nPf3UlCmorK5mYEGWgQ8fgghQSuHD5z7I/yoI04pWtOLHhx+2pfpVw3MAjDEmQ1DRf5qDk3UdbhgGAHjZbBYACOdcSinh+/5XD/8phdR1Tm2bwfM8wdiB/Q84IKFb1puvv/mmu3TFCniEEFd4HAQeJQKEahBSWhImB3gaSHuAt8d1tKIVrfjJYS+Taou0JSICSzE3LG5pyTUl7RMQEM6gmyYIY5CU7m4g7QshQIUAIQS6pknTtg3btm0rGjV1Xa+tra0tLMzP930pt2zbtg2+78fy8/N93/eTO3bs2MVyJS1TrBgDsywvlcl4PC+vrG2HDiNGH3PM4sWff77o7bfeghWJwItGpRPoqJpWJJ7JuC6E55mEWkIKQQNh1S8R926rrHbng211H7SiFT867EVSpTQQeM6BSAlAAEIip4tHAcoAFrwJCUIhKCBBCSKJhLXv4ME8v02bIfsPHkwFpRphLJ1OpxOJRCLjZDJggKBCSEIIYZRq3LIMwzR1zbY1znmyrrq6oDCRIGCssmrnTs/zvGg0Gs04rrtjx44dph2JCCGElFL6sjnH1Re5lCtfiNXLliwR2XR6+PBDDqmhur5i7YYNyGSzSCaTIJrmk0gE0vP8pOcBhOhM04TkwpNJjwNclaqqnFVCQASB8AV8wzAM1/d84cnATaFxHvh5JQAC+M1pYjJ0AyAAGGXMF+5XWsHfVYmsGiGIFtkU4e2mlFJCCPFzfmxN0zSvZZrb17hnWh6HMDjnXC3Ptm07lUql/pd9asU3g2EYRjY3CozH4/H6+vr6lu//3LAXfX6KVCkFhAhIVeT4M2AaBjBGwSAAD/CCDymhpq6TwtLSky+77rqdGSHgEmJQXXeSjmObluV5juMJz+OWprnwPF96XvBlxgihlME0GQkuYRAhTNM043l5eZYViUgCcKbrmmkYvi+l63melADTODcN2+acc08Cni+lLwHH830nm0rlRSMROJkMnEwm21BX56WTSbehpmbH5o0b165YunTrhnXrknW1tX46mUQmlYJ0XTDPg3Q9EEoI4VzuksYlBEApZO41IUTXNM31PE8KITRN09xQpoIifCmlbP7RxFeS0N4mVcMwDN/3/ZbkyDnnjDHmeZ7HcgFC13XdryNNNa+60fGcqyaMlt+xLMtKp9NpAIjFYrGGhoYGAIhGo9HGxsbGb2tfW7Fn6LquU0ppJpPJhIPFLW+yPxd8B6SqkCPVIFVJEoAwgEkEz33Al6DBIFjTdR6NRDzXdeH6PjwStDXxGQMIAQWowbnws9mgtYnnBUN55SrQNIBS6IYBN2cZtW3TxjRtO5VJpzWu6/mFBQXZrOsKALpumvmFBQWlJW3aJAry83Vd17PUMPK69e+fIppGQUgsHonU1ezcWZCXSBAIIf1sNmbbdsTSNM9zHCl8X6OEVNfs3Ll186ZNXiaVen/eG280Vm/fnqquqUEymUQ6k4HrOBCeB03TkMlkAIBDCCYFNCk1AU8QgAhAuGCuh8CKZTkAwcnq+77fVPCwB3L9rsVcNE3TpJSyJckqKEuzqUAj9zwej8e7dOnSZcCAAQP69OnTp127du2i0WiUMcai0Wg0Ho/HdV3XGxoaGjZt2rRp/fr16ysrKyunT58+vaKioiKTO466ruue53k/14v5u4bKEzdN0/R933dd11XvfdWo4qeOvUyqYT+lEC1FmzVONc8TwQVIGJWUkOA7ShfFk5CQuq7pTsbNRI1YLJNNpxkhzJeuLwEpQoxCCAghIERQSHAmwZiUQoAAoJoGglxKFaVglIJpGjzXhcwFrJimgRICz/NALQv57dqRrr179+vXr19eXjy+6LPFiyMRy4ol4vH8/ETC8T2vU6eOHeN5iYQVMc3CwsJCkethVVZUWBjljFVv27Zt/do1ayrWrlmzbePGjds3btiwbcOGDc62rVvh5ISs3WyWwPM0SEIhKAWoxnWt0XMa/W9AjN8nqeq6rmuapvm+7ytyI4QQwzAML4fw/JRSOnDgwIGDBw8ePHLkyJE9evTosc8+++xDCCE7d+7cuXjx4sUrVqxYUVVVVVVbW1vb2NjYqIaUpaWlpfvss88+ffv27duhQ4cO5eXl5cuXL18+b968eY899thjCxcuXAjs6gpoxd4FY4wpl04ikUikUqlUeIT1c8ReTfkhIVKVTU3x1GvI5h+EUnBNgy8ENF0HAIhsFpoX1O578CAgiGEZMpPNcF3TPSfrNAV3lLKUyNXaq0CYgOQUXATyKIFPkwXmsC9kiOApAc1ZwyRHzZQQ+JTGO3Tq1KVLly7bduzcuW35F1/A0DRQzuE6DkzTJKUlJVTjnOqa1q5Dx44lpaWl5eXl5T179umj81isIK+oKBaNRKgUAtL3vUw6vXPbtm3bN1dUvPfOO+9s21RRsW7F8uVI1dcT0zR1jbFsfUMDpO8HNwOAIbjrN1ePtbTEgtd7DPztJbR0UQABaaqhuq7ruuM4Tl5eXt6YMWPGnHbaaacdfPDBB0ej0WgymUy+++6773744YcfvvHGG28sXrx4cX19fb0aPra0NhljLPyZEEIcddRRR5100kknTZgwYYKu6/r777///l133XXX888///zP1Z/3XUKNOHzf98P+1PA58H1v4/eBvUyquy6fAlQFbILAVNB5lJu27XmeB19KYltWPJ6X19BQUyMydSmYmomMn+k5YOCAFZ8tXQpKKTzXBRESwhW7EKtCrjyUAYAPCALBGBgASAkpEcT2Xe+r050YBfV9+FwjmudJDwD0mB1zsn4Wnu+BEAJNDwoECKWgQQkspJTgpgkSieR369mze/fu3UtLS0vblZeXd+/erVv79u3bx6O2nU6n077vumtWr1z59ttvvz1//jvvNKxZuxYAYOs66mtrIV1XET0ju1r+zXoG3w+pAsEFpIZ8igjNHE499dRTjz/++ONHjhw5EgDef//991999dVXX3nllVc+/PDDD4GALE3TNKWUMpvNZv1QQYaydv3dFGkwxlgsFovV1tbWJhKJxIUXXnjhxRdffHFpaWnp9OnTp19wwQUXVFVVVe3t/W8FcMopp5xy88033zxp0qRJc+bMmfNz92V/J6SqdEXDkfBctJ+CUArTNOEEftGidh06xKKJxOYtGzc69fX1JBaPa9w0Tz7117+eNm3GjHRlZSWE74MKAZHNQvo+4DVfdJQHwTEiJaQb1N5/VckoJbQ5X5Xl9AYIAA9c+tJ34LRvX9R+x46dO4Qk0nGkAwkZi8VijuN5ni+l7/k+wBjnug5Q6nmuG5RkAaCUBtVchIAwxvPz8so7duxYVt6uXXFZmzYFJaWlhaVlZfnFxcW6aVlr169fv2DBggVrl372WSRZXZ2traxsqKmpAZBLCgjd/WU46PXl4763SZUxxlRQCQDy8vLyRo0aNeqMM844Y/To0aN1Xdc/+OCDD/71r3/969lnn31227Zt2yilVNd1PZPJZHRd13cXwGKMMc45V9amslJV9kCYZBljTGUfdOnSpcs//vGPf4wePXr0Aw888MAFF1xwwd7c/587VDBw+vTp08ePHz9+wIABAxYvXrwYACKRSCSZTCa/7238PvCdJf+rC73Zmsr5NKUQcHJDSDsSKSwuLmZgzGlIpYhh27IumXS4EDozjLLS8vJ1VXV1EI4DqmkQUhJ4XuATzV1oPiCVT5ZQCQoCRhlyVh3VdV1KIqXnegCjubQlCZL7T2TOjSmFR0GtuBYvLGlTUrFp5yZdIxqjoMKXorEhmaSglIAQDko5CBGe6xJQqoMxB67LdUozTmMjJKXENE0pCfF2btq0oWrr1g1fRKNwfR+JggISiUbteF5em/YdOpS1KS/v1KlbtwP22WcfsXPjxq1rVqxY8cUXX2zZsmmT7zgOobniBfH9+wwVuXXv3r37GWecccY555xzTmlpaemSJUuWPPjggw/ecccdd+zYsWNHMplMKjJ1HMdRvtcwoVJKqQrEeZ7nhYfvLYk0LPEopZS+7/uWZVkrVqxYccYZZ5xx1VVXXWXkij5asffQ2NjYuM8+++wzfvz48evWrVu3adOmTcDPm1D3PkhQqskAxgGuA7rK3QxIlXNwTYNmGIgXFCBeXHzqhZdeml/evTtIJAIYBmCaQDS6z/6HHnreZTfeCJafDxqPQ0skAMNgoMwADDOY0TQAQ62HgTIGzsMTAecEmtZyAjSNwDAQnriuQ9d1aLoOrutgpglqmiC2DWLbgGUBkQhBJMIQiXBEIjqiUR3RKINhgIKCt/yjHEzTwA0DPBIBj8fBCwqglZRAb9sWkU6dkNerFyvq0aO8e//+/YYcfPDhR40dW9K+a1ctWlAAomkgmgam602WvvIpNx32b1Yiq4gnnFmgXn/ddzVN00aMGDHiqaeeeqq2trY2m81mFy5cuPCkk0466Zt8vxU/DvCcqptpmibQ7O5Rn9999913Synlvffeey8QBC7D87fi2wYFBQlIVQd0RXgElILwgFw0w0AsLw+6ZY066de/HjTqmGNAo1HwWIxA1wHOiRaPw8jLO+vi3/++Q/8DD4QWj4NZFgjnDNgjqeqgOTb88sTBuQ4t9Khp4QnQNPAcoWqmCW6aTaSqJjVfjphZbuLQNAJKwcCghf44OJj6owxMkaNtA5FIMEWjoIkE1QsKQCMRUMsidn4+qGGA5CbNsgJy5fx/IdWmnylkJQLBhaQsS8uyrPBFBACjR48ePXPmzJnJZDKZTqfTL7/88ssHHXTQQerzVivxpwHlWlHEGj4PbNu2TdM016xZs8Z1Xbd///79gWZSbcXeAAEBDf6+TKqcB6SVs1BNy4p079HjnKuvvz5a3qULaCTCzfx8QnUdlFIYkQg0yzr81DPPPOq8SZOQKCyEblngnIMGpaCBZQpG1HpJ8N6upmfzFN4e9RieOMApASUUlBAQEoz1g9e5qYnMcvupUrrUazCwXYhUvZ8jQMqC4gcOcGXJq+1jAOOR/HwwywKzLBItKIAeiYCZJjTT/CpS/aaIx+PxPVmoWqhrgno9dOjQoS+99NJLKqj0xBNPPNG7d+/eQEDEtm3b//0J04ofGhSZqkd1s1T+7VNPPfVUKaV8++233w6fO5FIJPL9bPFPHQREEYkivYC8qE6gaSCGAW5ZyC8shGlZJ1x8ySX7jT7mGJiJRPPQn/PAojUMaNFo2aDhw8+48ZZbaKdevRDJywM3DBVxZ0GlASOKzBiYWu83ndQy1KSFTNGc34BTCkopKFEkqQgzPMRXVmmgYRDsQyhvV7lE1DpMAtMCrLDFrQEaqKZBi8VAIxE9v7S0TZfevWFEo6CKWHOkCkr/G1IFggtE0zSNMcYsy7LUBQQAhYWFhQCw77777jtjxowZjuM46XQ6/dBDDz3UpUuXLmo+0zTNaDQaVa9brZWfDpTvmucAAMXFxcUAMHv27NlSSnn66aefbhiGoebVNE1rObppxbcBAtJEMgACUqWGDt5MqkYkAtOyCvfbb79fX3PNNYgmErASiWDobxg6DENXQ2K7bVsUdev269//9a8Djj71VPDCQrBEAohGOTQtGNZTnYMGPlMaWLH/yx8hIDSoMW3ufxW2QMMkqv703B8DI+CcwzCCSdM4OGegLEzghIBoFBrn4Cw0UQ0amKbByssDj0Zh5+UNHXnUUYm2HTqAGgYM2wbhHDTkV/0v0NKqCF8MmqZpN998882VlZWVmUwm88Ybb7wxatSoUUBwke3Ob8Y555ZlWWGSbcWPF8oCVaXH6v3+/fv3r62trd2xY8cORbKGYRhqhPNzJtXvZsdJkEoVrI6gqdKKBcn2hx0+4vA5c+bMgeM4YAA8xzGoplGAEEjJNF1HJpNBOp3etGnTpv32339/cF0H4ZyAUgLGcsuFDNZB5JdUp/5zSEKIIJRIUCpBabBMRiAZIJmEoAKSBo9q8qkPn/qQVFIAFEJS+JJCNmU/SFAJBOoyAOATCF9C+hLSFxC+gBASMlJSWmrF8/KgaRpEMFzv1q1HDwCAStuSof38L4hVReaVK0AIIQoLCwvHjh079pNPPvnk6quvvrq6urr6xBNPPPGII444Yvbs2bOBwILJZDIZ0zRNdbEpCzWdTqd/7rmKPxUIIYQq/w2T6uWXX355IpFIPP30009XVlZWqnld13VN0zR/zqXCe5dUW1zku+ZNUgoBlPXp29cwDGPj559/Do0xpIN0G0jfl3ClD8fxkc2COQ6o46zcsGJFvDgW6zFs//2hCSE1KX0ihA/fd+G5PvF8yTwfxA/qqcSeJormx91MSnaPiCDdigCBrgClRFBKfMaIYIz4GmdNk6Ez39C5b+hcMAZ40g/EC4Jtg+f7EL4PSB+cSHAmpcaFb2pSmBp8S4ewjGCKmtFIfn46lUzCcV14rrt569atHTt27GgmEok9asH+B8Qa1hKor6+v933f79WrV68///nPf54xY8aMbt26dbvqqquuGjZs2LCZM2fOJIQQLQdVSRVO2FeaBEBrsOqngLBWQ/j9rl27dj322GOPTafT6QcffPBBIMhZVeeEZVnW97G9PxTsXVJtoQcqANFcy84YhKb13e/AA1cvX70KVFL4WQ9wPd3W9Yx0XZ8y4cPz4SVTxOYcvudt2b59e0NGiINGHnkkuKaBEyK4kD4VgXyTIhUpJSQhBIyRptrT4OwgABhAGIRkuSz9LzMR3fWtwLYUJLf1uQpSsKblUcoQJP6y4DMiAOGTQPQgtBjZtIDchlIARFIKcA5qWdDiceiRSGFpWRnVdR2QkpiGUbF+7VrTjkalmUiAWBZyOgO7HG9V7PANyNX3fT/s/5wwYcKE11577bXzzz///Pfee++9AQMGDLjnnnvu2blz506VT+rmoL6n1KSAIL9UXYStZaI/HajfUukpTJgwYUI8Ho/Pnj179tq1QQWgGpkwxliNKlb5mWKvkyo3eBAx5KA+hCCMMZ3ngjelffrktdl33zVLli9HY0MjybpZ0xCG4zSkwQPJfEkAXUKLOikfGdeVm+vr123JZMp7HXRQrMeAAZAANKrB1mwwyiL5xYUQlDCpcQaAQQgKKSl8EUyepPAkgSAMghEIQiFIoDooACYINMHABWtqwSJ9Aen7gfUcTAKeJ+D7PlzXh+u6ucnLTS5cV9KA2FRAioEyAs5BAVBPgnrCNCS3aZbryPiA5wGeB53SbocMG9Z1n333Fal0GtJxZKq2lriO06Z9x44i2qYNaF4ewHlQVdWs/LVLKhUBCfs91XNlRZqmaTqO4+i6rj/zzDPPPPXUU08lEonE7373u98dcsghh6xYsWKFktUDdtU7dRzHUc//U13UnwrUzURZZpxzbhiG8VPxJ7b8HaWUMhKJRM4999xzAeDGG2+8MeyT13VdVyOVcNAKCI6Nev5dBjJ1XdfD10DLTIa9gb1vqQoS1PpLSAkhJAGynueBGcbgkUceuW1HdXVtdV2dBhBNQnPTyILTIHKuBQEYAhAn7TgQUsLxvM8WLV5cW9PQcMDBhx0WBGwogeu5AJCsrqmhkhAI3w+KpKQEhBSAzDWtatYeCG+qoqKWVt6XGvgptS0h5C6T50l4np+bmqxmAkJzii1B4sCu6/Fd3xdCCgJBbMMwYOg6pBDUtu258+fNg5vNUk3TCAFJNtbX1zckk+XdevdGND8foEFngdz25dILcqsILO1MJpNRF73ygWaz2SxjjGWz2eygQYMGrVmzZs0xxxxzzMcff/zx6NGjR9922223/VyI8dtAOp1Om6Zpqkqwn5o/0TRNU9d1XUopjzrqqKM6duzYcd68efNWrVq1KqyvsDtXgbrBSCml67quUjT7LrbbsiwrXMFn27YtpZRKU2JvrXcv31FJoDaUs/cMBsP3PA/MsvK69ep14AF9+qxbtmiRX1tfL7nBgm+AUE45pCchs4HalEFJFsgSnRBonlf1+Xvv1W9bu7Z/n332ye/dvz9oxITgAkIIJj2ZZ1kxBkF9SOlDCB+QkgCSUCIJ4Ac1rX6g4ZpT5VfEKUJ//ytyywwP/3f5TEKCqc8JUtl0Go7jID8vr1uXLl3qN2/eDAAiJ1wtpZRVVVVVXbt27YqQpfhVME3TVNambdu2GsoZhmFMnDhx4iuvvPJKcXFx8dSpU6eOHDlyZFjo5H/e/584VCcJoPmGpeu6/lPK1zUMw1CkpGmaduWVV14JAA888MADShAcCCzTsA6EelTaqmEi9X3f/y7OL3XeqxGE4ziO7/t+2Pe/N7D3SDXwaBLhClelI0lVYx8vKDhg+GGHNVZv2bJp+SefAIBHGOU6OJWgcH0XAgI+fBAQn2tBBRKEIMhk0LB588pP5s1LRGy73/6DBwcVVrYNSWEyYnrZjEcgiITngeSG9SrpiNCm3FEfnAQBIxqUzaoAlQ//WyHVXQ5HyNoN271+sB5KWNNv0aV3797SzylfgRBV508ppZs2bdpUXFxcDI1zkLDa15dXqGu6pi4IzjlPpVIpNQy76aabbrr//vvvLyoqKpo0adKkc88999yGhoYGZVl8V9bEjxmapmnhLAfXdV3HcZxUKpX6KZRpKmk/IHD3HHvssccOGjRo0MKFCxfOnDlzJqWUqlQrpcGgvqeWofzs4de7a72zt6C2LZvNZpV1Go/H43sz5W8vD/8pgSBNB9n34YMSUtSzX799DzjggPfnz56Nms2bQYSAIyUYD5pYZQMlKJYjC1cyCcKodDMZmaqpgV/f8PHbL7+crK+t7dGzb1+7c69eMAsKwHTd9aXrCcezdMNqKkAgCDpDkZzmQFPMirXI96cUkpImK/LbaLy3p2UEVirzJYQHeOAamG6aiEWj3bt26/buvPnzkUomlSoVo4R6rutu27ZtWyyRn4+CoqImJSwAX3JZILgQlO9I/QaRSCQyderUqVdcccUVO3fu3Dlw4MCB06ZNm8YYY8on1pq8/83guq7LGGOapmkFBQUFvu/7ykpVN7MfMxQZqRvEFVdccQUAPPHEE08kk8mksvjCN2BV8qwyRdT7uq7reXl5eS0t2b0Jpeuqto9SSk3TNKuqqqr2ZsrfXiRVSgMZ6GDHhJTCB3xE8/N79OvfP+MLsfSzDz8EcRzogdXl+NKTgGQAMwh0IpCzHH0/yEsVYABLGIh7NZU7ly766KO2bdu1O/zI444Di0ZBTNMBFwBB2smmd9FabUlusqkSCeEOBbnMgCAx/3+RRgyRsmzy5waTCiwxBJaxD/iuhPRdxzEty7J1Tatds3p1EISSklAQ35c+hBBNdfp67oTNadLuaTMcx3EIIcR1XTcej8dffPHFF48//vjjFy1atGjUqFGjVqxYsUKlUzmO46jgVSwWi/3X+/4zAuecu67rVldXV3fq1KmTajgYrkz7MUNKKTOZTOaXv/zlL4cOHTp0/fr165999tlnw5+r50psRbX6cV3XJYQQxhhzHMepra2tBZqlHff2toc1fmOxWEz10QL2btrX3vapklz+PqSEhGYY7br36tWle48e73+wcKGs2bkT1KPwMhlACE8w+IDPAU4FKJE5UhM5y1JCmhymzqATCXww/5136murq/fdf/Dg/P6DByNSXAweicCOUB8I7p4SstlLKgSk6gEgBIhqutfcmJBCkP9UkOTroPRjJYRUhJrbAkGZpoHqmhtIFdLikpKS6qqdO4O225zD833pC58Eml9EQMqM47pl5e3b79JWWzYTa5OObe5OXVBQUKDruv7ss88+27dv3747duzYceKJJ564ePHixerkVhZXJpPJMMZY2F/Wit2DUkqz2Ww2Ly8v729/+9vfnn322WeVpfpTGf4rq/OSSy65JJVKpZ588sknVbK/Km9WJEkppS2H+yJnCAABkZ155plnduvWrdt3MRpS2w4ADQ0NDWr4f9ttt93217/+9a97a717mVQpzTllghIiOy+vpEPXrkWF+fmL3p8/H9L34QkPXiYbRPoZA+GckVzdPgNv8m1mslkiAc+D15BEA+fgVRvXrv3i88WLHR8Y8YvjjkN5t25gtp3KZrPEJDkVfkgSTGASlMgcv6m2JMT3g8nLdV2BoN/mccmJu6qUASBHehJS+pBCBHlb0AwDpq736dOnT9WO7duRTaUCbhdC41SLRIwIpJTV1dXVmWw2e8DgQYMABD7inMXdMiAmhBCcc15VVVV1++233z5q1KhRhmEYBxxwwAFr165dSwghdXV1dWrY73mepzqktgaqvh7KCiopKSn53e9+97uPP/7443Q6nTYMw/gpVJSpofOYMWPGjBgxYkRjY2PjrbfeeivQ3GJcDf9b9iMzcgACN0kkEomMGTNmzP3333//wIEDB34XLcR93/c1TdMUgVNKabt27dqdd9555ykhoL2BvUqqVNM0KQCNMw3gLFLWocOQ4SNHbtu4bh02r1/fNKMUEtl0Nuh5p2mehEcA4nrUJdA04noek65gAPMB30NQnoR0Mvnph+++m04nk8XtOnc+9Fe//jX0aBTReNz1pa92kEmwuMHjHOA2oTaHhyAn3w06YxPRpGwld0NO/zVCLoeWKVxECazwnNqULwQYY3Y0Etm+edMmSCmDCL+URAiSTQVR+8aqqqpt27ZtKy0tLc0Fq5q8qbpOdQDQuKYRBP5Tz/O8k0466aTzzz///HQ6nT777LPP3rhx40ageeimck6VDw1oDVTtDmFJRKC5Fcz//d///Z/ned5DDz30UEs/5A8Z4SF4eHvD2QuapmlXX3311QBwxx133KHEp8PdfHeHbDabDQesMplM5o9//OMfly1btmzq1KlTv6tc3rDlTCmlEydOnEgppaoSbG9gr+6YyJWtEc1koJrWve+gQXo0L2/tssWL4SSTTXXrTf7HoOVI8BakhMYAznV4TAO0nMUrPcADKDHikci2zxct+uT9BQsiUcvq1KtPnx6nnHkmBABGGY0YEaEsRQ9CB9G5JFwHdAZBm0tRc7REg+ORa+X67TnSczmpKhc2l08aeBn8YNgPKWXbbt27p51stnLrtm1wPQ9SCMjmNjRBgULQCdaOxuPRNuXl3LZthPQuCUB8z/WBoK4fAC677LLLOOf8hRdeeGH69OnTw8TQiq+GauViWZal2l+rm5Dv+75pmuYFF1xwwYYNGzZ8+OGHH6pk8x9DoEoRG+ecq+1V+bZAMPwfPHjw4IMOOuignTt37rz33nvvtW3b/qbR+0wmk1HrGD9+/PiuXbt2feqpp55qamK5l6G6+qryWdu27ZNOOumk7du3b585c+bMvbXevVz7H7SHzhJKES8sHDB0+HBPSLnkkw8+gO84AWlQySQok8i1pg7ySIPhMucUlCpVp+AzKkECRb5sbU0N3Ezmg7lvvLF6yeLFhAIHjxo9uve4CRNQ2LZIZKkHqnGi2UbaR9oFcT34ngamNQWiwlFz0pQn8K384E3WqFp20/vK2cwYZODyAOd80IFDh27dunUrGurrm7um5pr6Kf8yIcTxXNeK2LYVsW3PEz78oPNq+EQlkGCMsWOOOeaYwYMHD25sbGy8884771Tz/Bgu+u8buq7rKtihch6tHJS83XXXXXcdY4z9+c9//jMhhKhk8x+Dpaqq6RSJqpuB4zhOJBKJSCnlww8//DDnnN9www03EEJIKpVKqU6532Qdyt86adKkSY2NjY1PPPHEE99VYYmUUqpArWEYxrBhw4Z169at2xNPPPHE3jz/914EjggJRiWkEMIjpKxPnz7tOnbpsuij999PbdiwASQXNArKK6kEpGpk1xRkAliOVILWxIAIBE5y7j7KGNxMRq764os1n7z7bofOnToRjdIjjj7uuLYlhYVzZs6c6W/essXLBopOBEGTQApJESyU+uFa+eYW198CFKGqYJjSJICkuV6IuhmJpLLZLBjnRnmbNh07d+ny6qxZs8AZg+t58D2PACAiRPy+lHW19fWSUOoKKeE6DgilEEGQgAReVupJ6Unfl8cff/zxAFBRUVHx4YcffqguHM4535tVJT8FKItUNSgsLCws7NatW7fS0tJSzjkvKSkpueiiiy4CgD59+vS58sorr2xsbGz0PM/bsWPHjueff/7573cPvh6qb1h4OE4ppclkMjl+/PjxPXr06FFRUVHx2GOPPaZcQpqmaeEy5a9b9uGHH374wQcffPBdd911V2VlZeV35a83DMNQ7qxsNpu9/PLLL0+n0+lHHnnkkb253r2a1kCoR6XQJMx4vO/QQw8lYGzJp4sWQeR62gs/CNXkxEcgAzNdghIPEIDnCQjhoXlYHizYF5BAxI7Hk/WpFEgms/Stl14qLyss3Hfo0KHUjMX2HTpqVGHbbt1ef+6552oXffop/GRSs3TdaayuBhy3qZKqZZRfQjanKH0bBiulOYu9Ka1LDedd1/fV0P+AIQcdtL1q505n27ZtkCrhX0oGSSUgiSJl13W37wz0TTVN00AZo4Zti2RDg8wRNiFBxgUAFBUVFWWz2WwymUyGhU5UoOFb2MGfLGKxWKyhoaHBcRwnLy8v78ILL7xwxIgRI0zTNLds2bJl6NChQw3DMD799NNPY7FYzPM8r23btm2rqqqqfgyN71TxAmOM+b7vZzKZjG3btq7rem1tbe0111xzje/7/t/+9re/OY7jqECmStH7uuUrEr7ssssuy2QymX/+85//BALS/i589tlsNqvap3fu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UVFRy+NTUFBQoJ6r49myLXq4qEWtZ8GCBQtqampqRo8ePVod492NYsrKysrU8/z8/PzdPW+5/v8YYZ9q8A7nuVF2E6EGbadh2IBtAEaQpE8ZoGlgloVIQUGbo8eOvfCe++9HeXk5LNOCRjXoRCcU1KAwbAY7QhCxCKyAXCljahhNQb9EqE2kGgz/lQsgaAGtaRo0TYOuc+i6xm2bm/n5oNEoeGEh2vfvP/p3t9zy++lvvYV9hg8HLy0F4nENum6BWIrYaWgdX/m3qw+VkF0eKSUkIGkOcEJAtEgkAm4YMAoK9J6DB//mlvvvP/Omu+5Cfnk5rLw80ODYUqLrBJpGCGMEICaDGbg2KAWPRBDt0OHk6+6779Qb7r8fVnk52O5JNdiyXaunwsOkfv369bvwwgsvfOGFF15YvXr16rq6urpsNputra2traioqPjkk08++fe///3vRx999NG//OUvf7n88ssvv/baa6/95z//+c9p06ZNW79+/frq6upqGUJjY2NjVVVV1Y4dO3bMmjVr1qRJkyZ169atm1p/2KWg3A8tW1zE4/F427Zt2wLNF4dt2/Z+++2339SpU6fubpjfcri9u6G4Wr8idEopbXlhM8ZYly5duqhhrPpcz2HcuHHjamtra5988sknCSFEEaiav2W+p3quhoznnHPOOZ9//vnnGzdu3Dht2rRp++yzzz5qXrVt4f3am1DHQR2fd9555x0hhLj99ttvB3ZtPRLenrCrQsEwDEPdDLp06dJFSik3bNiw4d///ve/ldV4zTXXXKP2TblAlPUH7EpW+fn5+bqu62FXCbD731fdDFsOy3v37t1727Zt29atW7eu5fFUhByNRqNqPwkhJNx+5d577733448//viLL774QqWDha+f/x7NnlIKwnmYUG3AtgDLBMyAVDkHLIuwvDzYRUXQI5FBx48bd8FNf/oTookEtEgEWjQKGomARCKExGIElqXDMExwMwIayQfPTwCJCBAJlqemkNsh51PlBFxNGoGmEWgGgWEQGJyAg1BKqK7bgM0BDjsWs/sPHXrBv155Zfzt06ahbOhQ0HbtAMtSNwUOcJPBDG4mOd/pbuL2zYcn2JYWwqw5P3Dz8QIBgRWJQMvLQ0H37sf87vbbf//4q692OmzsWHDLAmdc49CC72katEgkON5AE6mSHKla7dqdePWdd55+0/33w27bFjwSAW8m1WDLKA2fZC0vgt35D+PxeHz//fff/7TTTjvtqquuuuruu++++9FHH3100aJFi8LEqYZaH3/88ccvv/zyy/fee++911577bXnn3/++ardhjpJ1XpVK2u1TcqyUP5ctQ2maZpXX3311alUKpVIJBJqOw866KCDXNd1b7rpppuAYPhYVFRU1L59+/b777///mp9ympijLGWQsRqPZZlWUccccQR48aNGzds2LBhYV9bQUFBwYcffvjhrFmzZh144IEHXn/99ddPmTJlyr333nsvACxcuHChlFIOGzZsGLAr+SUSiYSq3lHvde3atWtxcXExAAwcOHBgJpPJPP30008PHz58+KeffvrpuHHjxhUWFha2tMy+q+G/UsUfP378eCmlXLVq1Sp1Q1Ofq+eMMVZWVlY2adKkSWvXrl0rhBCff/7558CuFuisWbNmqUq0e+65557u3bt3r62trf3ss88+C+8bpYG+qaZpWkuf88qVK1e+/PLLL48YMWLElVdeeeWrr7766j333HNP+De94YYbbli+fPnyxsbGxvnz589XN+nS0tJSxhi7++67785ms9m///3vf1ff2Z3lOX78+PHHH3/88SNHjhypztdLLrnkkmw2m500adKkiRMnTtyxY8eOLl26dAGaf+f//GirgFGQNxoMiXO+RAYwHdAVoZqAaUGzTD0/n/DCQqCwELSwEFo02mfkmDFHnHb22Yi3aQOjuBiJjh2hl5fD7tgRvLwcetu2RG/ThtGCAgbLMsANG9SOgkd5M2fuQqpq+wgNGj8RCkoYWHgKrNuA1AqAgnwg3zKZhfzi4sKjTz/9utdWrWp35MUXo2jwYCAeD7E3tylsDsp3DUy1SIbaA5m2JFWqW5ZS34IWjaKgS5fOx5533k0vfPTR2KvvvBPRtm0VIeqAbhvUBjEMUMsKRgWBj1XdJAJpw/LyJlK12rYFi0TAKGtJqmpbY7FYjDHG1MUeHjLv7q6rTqx27dq1u/jiiy9etmzZMjWMf/XVV189//zzz+/atWtXZelFIpHInqK56qLZ0zqA4GK1LMtS702YMGGClFKeddZZZwHAaaeddprjOM7kyZMnt3Q/bNq0adPChQsXAsApp5xyyssvv/zy2rVr195yyy23qHkUadq2bU+ePHlyfX19fXV1dXVdXV1dMplMDhoUKH7FYrHYTTfddJNyX0gp5ZQpU6Z8+umnnzY2NjYOGDBgQENDQ8PixYsXq+UBgW9UuUyi0Wh08uTJk9evX79+6dKlS1UL77///e9/P+20006TUspx48aNA3YNWBmGYewp6Lc3YRiGYdu2vXnz5s1SSnnhhRdeCAS/SUsLf9iwYcPUDfbJJ5988m9/+9vf1qxZs0bdRCORSKR9+/btN23atCmbzWbPPvvss9V3a2pqahQBDxkyZMjs2bNnf/jhhx9u3759ezabzTY2NjaqhoJ/+9vf/qZKTD3P82bPnj174cKFC4UQQtM07dBDDz109erVq9V23HXXXXdVVFRUtG3btm34XKuoqKiQUsqSkpISYNcga+fOnTs//vjjj1fnUF9fX5/NZrMDBgwYQAghjz/++ONSSllUVFSkltcyGPqfQ1lnFHRXUgWULzQCREzA1IKUUy2InBcWgpWXI9atm9172LChp02adPjEq6/uMOrkkzuM+fWv248588yOR55zTtuRZ5zRccxZZ3U98uyze4457bQuh44dW9p/2DCjvEsX2Hl54JrWNNxv+ccQtFfhmgau68FkmuC2HUzRKLRoNHi0rHwgvwAoMAETpmaisFu3Eb+fPPnkm599tuSAE08ELShQNwsOcJNykymO3Q2phsk0TMZfJlVKwXQ9mKJRxMrLux515pmn3z516m/+8dxzeYOOPBJ6Xh5y/mUDMAwKg3DLClpQ56KZuYh+sLyAVE+++o47zrzxvvsUqZKWpEoCBaXwT6pet7RaLcuy1AWuaZp23HHHHTdlypQpytpYunTp0iuuuOIK5RdT39+ds1611wC+bG2FfW/AnqOo++67777bt2/f/sYbb7xx4YUXXiillKrkM+wuOO20007LZDKZioqKis8///zzVCqVuvLKK69866233kqn0+nTTjvtNNM0zYEDBw68//7776+oqKioqampueqqq64CgGefffZZKaXs0qVLF9M0zXbt2rXLZDKZbDabXb169eo+ffr0adu2bdvbbrvtttdff/31f/7zn/+UUkqVwwl82S/42WeffSallC+88MILI0aMGME55x9++OGHyWQyOWLEiBFVVVVVDz744IPhY9GmTZs2Rx111FFDhgwZ8l1mABBCSCwWi9144403Sinlm2+++SbQ/LuE3SOnnnrqqUIIsWnTpk2jR48eDQBjxowZc+ihhx4aPg8efPDBB6WU8rLLLrsMCM6H448//ngppXzuueee22efffbZunXr1mQymXzggQceKC8vL+/atWvXysrKyrfffvvtnj179kyn0+mGhoaGZcuWLVO51XfccccdL7744osTJkyY0NjY2FhdXV09cODAgbZt20cfffTRhxxyyCFqG/Lz8/OHDRs2LJPJZGbPnj1bVYmpz0tKSkreeuutt1KpVOr0008/HQBefPHFF13XdYcOHTqUc85/+9vf/lZKKUePHj1aWce6ruv5+fn5F1988cVh3/5/cMRDpEpCw2AE6VYqwKQBGiGEgBgGWF4ea9+v36BfTZp0+i1PPPGbybNmXffy55/f8PKSJZc/vWDBDc998MFlj73++nVT33nnkgdfeunEG+67b9T/3XDDQedcccWYS2+44cQbb7tt9CXXXlt06JFHIlFSAjMSgW4YwWRZwRSJQM+5EfRYDHoiAS0/H1phIbTCQvCiIvCiImhFRdCLi0EjEQMwijmKTcAEBUVp9+7offjht878/PN9j500CVabNqAB4TCAcdocjAuG4M37/nWEGk75YozkxFIiEcQ7dEDnIUNOuOHBB2967sMPux577rkwSksDXypoREfEZrAZwDTdDqzVHKk2CcoQEDDLgt2mzcl/uP32gFTLysAsK0yqhDBG6K7FCy0JjrFdSbdjx44dr7/++uvXrFmzRtWu33LLLbeok1pdIC0JWflsW1qjX0UO6jvhi7HlfO+88847KtD13HPPPafWr+YbNGjQoIaGhoZcQZn373//+9+dO3fuDAQXoLJ0hBBixYoVK95+++231bwvvPDCC/Pnz5+fTCaT55133nnq+CxatGhRNpvNLl26dKmybgkhRFkrK1asWCGllH369Omj0naA5qHve++9954QQkybNm1a+LOtW7dura2trWWMsWXLli3bsWPHDsMwjKFDhw595plnnlm9evXqadOmTVPE8F0WZhxwwAEHSCllQ0NDQ8+ePXvuzqd79NFHH71jx44dNTU1NQMGDBjAGGNPP/3001JKOWvWrFlqmyORSKS2trZ21apVq9R3DcMwXn311VeFEOLiiy++eO7cuXM9z/NUaxYAGDFixAgppXz99ddfv+++++5Lp9Pp9evXrw+7Zdq3b99euYDq6+vre/To0aOwsLDwsccee0xKKV999dVXbdu21Q133rx586SU8tJLL70UaPa76rquv/LKK69IKWUymUw+//zzz3/88ccfV1dXV1900UUXqf1XjQ3fe++99zjn/Fe/+tWvVBzhgQceeGB3boRvhpbR7hCpNgWxlHsgmkjAyMsbd9Utt/z5xYULb5q9cuVVc2trz/93ZeXNH7vuyfe9/vqQ82++GV2GDEGivBylHTtCj0RgRCKI5uWhrFOnfqdMnHjEH267bfwdTz55wp3PPHPq3VOnnvfA88+feOujj/Y/43e/Kxpx4ol5w375S3vAyJHouP/+evchQxDv2BF5HTuisHNn8MJC6EVFiJSVgRcUwCwvD0jHMEACotN00wxIuLR05Hl/+MOkO594AoVdusAoKFBDbsYiESBHrHsgVUWmEZ1GDBZE51UKGpAjFQICpmnQCgrQeciQ39w5Y8afXvj441GT/vIXxNq1gxaNguU0UdHsUlBLAAkuXJ3ksiMYZeC2Dau0NK//iBHn/vmBB6AXFipStQ0a+I5zvxdjzRc+0OycV340ILA2nn322Wez2WxWSinnzZs37+STTz75m7SbUD7RloTaMusA2DW1SS3bMAyjZ8+ePS+44IILpk6dOvXFF1988fnnn3/+zDPPPPP++++/P5PJZKqrq6vVNiuivuWWW26pqampUaT7yCOPPGKapmnbtv3AAw88oMj0rrvuuqt79+7dgSDhfM2aNWtOPfXUUy+99NJLL7rooouUj4wxxtSFX1VVVdWtW7dujAWdEDgPGiV27Nixo5RSrl8f6AArlSq1Tw8++OCDjuM42Ww2q8iAc87VUFb5gpVVWFdXV7d9+/btd9xxxx1q+Kzl8G2SangfgOY8UrXtn3766adh8mmZr8kYYx999NFHUkp5yimnnFJaWlr69ttvv63862F/5euvv/66lFLOmDFjhgqCnXjiiSdKKeWmTZs2DRkyZIiUUi5btmyZ+k4sFostWrRokeu67umnn356MplM1tfX13fu3LlzeGRlWZb10UcffeR5nnfqqaeeWlBQULBgwYIFUgZtW2655ZZbFLH/7ne/+52UgcB2eXl5eVioWm3P2rVr15577rnnXnbZZZedddZZZymRnFgsFrv//vvv3759+/Y5c+bM8X3fr6urq1u9evXqiy+++GK1PS2Ni/8C4bShXZPwNYMHOZe6YSBeXHzOHQ8+eMfczz77wxsrVly+IJ2+dKGUZ01fvRp9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x9955+x/LVgw/ubp0/sdd/nl8S7DhiGvY0fYBQW9x44bd97f77kHBe3bgxYWMqtNG6onEs2EGuxvS19q03NGOOUs8L3SXH4uj0ahxWIoKC8//PQLL/zTlDfeuOyhF1809z38cETbtUMk8KM2HVvCeRCY+jKpIkeqHOCUgoJpGoyiosS+Bx98xrW33QYtLw+6aXJOmyxnTTMM3Y7F1M0gPz8//7zzzjtv5cqVKxsaGhpWr169+sYbb7wxTBrKov1vIs/hXMOW7x9xxBFHfPDBBx9IKeW2bdu2TZ48efJZZ5111uTJkyfX1tbWbty4ceOll156aZiYlWUKBJbt6NGjRx911FFHxXK4995771XDu9ra2lolnLxkyZIlEyZMmKByUIHm4W40Go1OnDhx4lNPPfXU66+//vq0adOm3XTTTTcVFhYW9u/fv//vfve736mLZndFD4QQcuutt95aUVFRkU6n077v+++99957v/3tb3+r5rVt2x4wYMCAyy677LLzzz///MGDBw8uLy8v/+yzzz6rr6+vV9kMl1xyySVSSnnffffdFz5e6tibpml+WxkA4ZtjOB1JjRKuv/7664HmAojwUFndEE877bTTLr300kvHjx8/vuX29ujRo4c6jwYMGDDANE1z4sSJE19++eWX77zzzjsPOOCAA4DmDA9N07TRo0ePnjRp0qRTTjnllE6dOnWKxWKxbt26dbv88ssv31P2g2ma5pgxY8acf/7554fPDyBIcerZs2fPsIvpwAMPPPCzzz77bMmSJUueeuqpp8aMGTNG5RSbpmleeOGFF06fPn361KlTp06ZMmXK73//+9+fccYZZyif8KGHHnooAMyYMWOGEEL86le/+lX4WP7XgcRdSTWc/K8KAAJ/mmboRlAppGmwYjGY8TjKOnTocNTYsVc/PX36Kxu3b39i0fLlUz5bu/aVtTt2PDJ30aKTrvrznwv2PfBA5LdrBxaNwgjyMplGtWjEiBoWt1jUikJjGjjlTROjDIxzmLYNynkwaRrRLSuw2DQN1DCg5+ejoFevfX/zxz+e/fTHH1/08oYNl8/avv2aWZs3n3nbzJl9f3XxxegyYADf94ADJt52772FA4YOhZZIgEQiGrcs0uImsqdcVF3XdUWoNFFcDC0eB4/HUdi586ATJ07805Q33ph095NPtht27LHQCwpgJhKgoJbN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";
let PLANTILLA_DOCX_B64 = null; // se carga desde storage o desde el valor por defecto embebido
async function loadPlantillaDocx(){
  try{
    const r = await api('GET', 'plantilla.php');
    PLANTILLA_DOCX_B64 = (r && r.custom && r.base64) ? r.base64 : PLANTILLA_DOCX_DEFAULT_B64;
  }catch(e){ PLANTILLA_DOCX_B64 = PLANTILLA_DOCX_DEFAULT_B64; }
}
async function savePlantillaDocx(b64){
  PLANTILLA_DOCX_B64 = b64;
  try{ await api('POST', 'plantilla.php', {action:'upload', base64:b64}); }catch(e){ alert('No se pudo guardar la plantilla: '+e.message); }
}
async function resetPlantillaDocx(){
  PLANTILLA_DOCX_B64 = PLANTILLA_DOCX_DEFAULT_B64;
  try{ await api('POST', 'plantilla.php', {action:'reset'}); }catch(e){}
}

// Genera y descarga un .docx a partir de cualquier plantilla (en base64) con
// los mismos {{tags}} que usa la demanda — motor compartido por
// generarDemandaDocx() y por la biblioteca de plantillas de escritos.
function generarDocxDesdePlantilla(k, plantillaB64, nombreBase, onDone, onError){
  ensureJSZip(async ()=>{
    try{
      const tags = mergeTags(k);
      const bytes = base64ToUint8Array(plantillaB64);
      const zip = await JSZip.loadAsync(bytes);
      let xml = await zip.file('word/document.xml').async('string');
      Object.keys(tags).forEach(key=>{
        xml = xml.split('{{'+key+'}}').join(xmlEscape(tags[key]));
      });
      zip.file('word/document.xml', xml);
      const out = await zip.generateAsync({type:'blob', mimeType:'application/vnd.openxmlformats-officedocument.wordprocessingml.document'});
      const nombre = nombreBase + ' - ' + (k.actor||'expediente').replace(/[\\/:*?"<>|]/g,'') + '.docx';
      const url = URL.createObjectURL(out);
      const a = document.createElement('a');
      a.href = url; a.download = nombre;
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
      setTimeout(()=> URL.revokeObjectURL(url), 5000);
      if(onDone) onDone();
    }catch(e){
      console.error(e);
      if(onError) onError(e);
      else alert('No se pudo generar el documento: ' + e.message);
    }
  });
}

function generarDemandaDocx(k, onDone, onError){
  generarDocxDesdePlantilla(k, PLANTILLA_DOCX_B64, 'Demanda', onDone, onError);
}

// Vista previa de texto plano (rápida, sin depender de JSZip) para mostrar
// en pantalla antes de descargar el .docx real.
function generarDemandaTextoPreview(k){
  ensureJSZip; // no requerido aquí; solo se usa el mismo mergeTags
  const tags = mergeTags(k);
  // Extrae un resumen legible a partir del propio documento no es trivial sin abrir el zip;
  // se construye una vista previa basada en los campos capturados.
  return Object.keys(tags).map(key=>`{{${key}}} → ${tags[key]}`).join('\n');
}

function isCaseClosed(status){
  if(!status) return false;
  const s = status.toLowerCase();
  return s.includes("pagado") || s.includes("ya no quiso");
}

// Un convenio (acuerdo/conciliación con monto pactado) se detecta por la palabra
// "convenio" en la última nota. Esto excluye ofertas rechazadas ("solo ofreció $X")
// que no representan un acuerdo alcanzado.
function tieneConvenio(kase){
  const meta = getMeta(kase.id);
  if(meta.convenio_manual && meta.convenio_manual.activo) return true;
  return /convenio/i.test(kase.ultima_nota || "");
}

// Monto del convenio: prioriza lo capturado manualmente por el abogado sobre
// lo detectado en la nota importada, porque es el dato más confiable.
function montoConvenio(kase){
  const meta = getMeta(kase.id);
  if(meta.convenio_manual && meta.convenio_manual.activo && meta.convenio_manual.monto){
    const n = parseFloat(meta.convenio_manual.monto);
    if(!isNaN(n)) return n;
  }
  return extractMonto(kase.ultima_nota);
}

// Etapa procesal real del asunto — determina si la prescripción sigue
// corriendo o ya quedó superada por otro evento (demanda presentada,
// convenio alcanzado, cierre del asunto).
function caseStage(kase){
  const s = (kase.status||"").toLowerCase();
  const meta = getMeta(kase.id);
  if(s.includes("pagado")) return "pagado";
  if(s.includes("ya no quiso")) return "desistio";
  // La bitácora manual de etapas (registrada por el abogado) tiene prioridad
  // sobre el estatus importado de la hoja de cálculo, por ser más reciente.
  if(meta.etapas.demanda_presentada && meta.etapas.demanda_presentada.fecha) return "juicio";
  if(tieneConvenio(kase) || s === "convenio") return "convenio"; // convenio pactado, a la espera de cobro
  // "Tribunal CDMX/Edomex/Federal CDMX/Federal Edomex" (lista estandarizada) y el
  // texto legado "Juicio en Tribunal" indican lo mismo: demanda ya presentada,
  // Art. 521-I, prescripción interrumpida.
  if(s.includes("juicio en tribunal") || /^tribunal /.test(s)) return "juicio";
  if(s.includes("constancia presentar demanda")) return "constancia_demanda"; // ventana crítica: hay que demandar
  return "activo"; // conciliación en trámite u otra etapa previa a demanda
}

// Solo estas etapas mantienen vivo el riesgo de que corra (o siga corriendo) el
// plazo de prescripción.
function isPrescripcionRelevant(kase){
  const st = caseStage(kase);
  return st === "activo" || st === "constancia_demanda";
}

// Un asunto se considera "Concluido" si el abogado lo marcó manualmente como tal,
// o si el estatus importado/derivado ya lo cerró (pagado o cliente desistió).
function estaConcluido(kase){
  const meta = getMeta(kase.id);
  return !!meta.concluido_manual || caseStage(kase) === 'pagado' || caseStage(kase) === 'desistio';
}

// Resultado de un asunto ya concluido, para la tasa de éxito. No se inventa
// nada: si no hay convenio pagado, desistimiento, o un resultado de
// sentencia registrado a mano, el asunto simplemente no cuenta todavía.
function resultadoAsunto(kase){
  const stage = caseStage(kase);
  if(stage === 'pagado') return 'convenio_pagado';
  if(stage === 'desistio') return 'desistio';
  const meta = getMeta(kase.id);
  const sent = meta.etapas.sentencia;
  if(sent && sent.fecha && sent.resultado) return 'sentencia_' + sent.resultado;
  return null;
}

const RESULTADO_LABEL = {
  convenio_pagado: {label:'Convenio pagado', color:'var(--green)', exito:true},
  sentencia_favorable: {label:'Sentencia favorable', color:'var(--green)', exito:true},
  sentencia_parcial: {label:'Sentencia parcialmente favorable', color:'var(--brass)', exito:true},
  sentencia_desfavorable: {label:'Sentencia desfavorable', color:'var(--red)', exito:false},
  desistio: {label:'Cliente desistió', color:'var(--gray)', exito:null},
};

function manualStep(n, titulo, contenidoHtml){
  return `<details class="manual-item"><summary><span class="n">${n}</span> ${escapeHTML(titulo)}</summary><div class="manual-body">${contenidoHtml}</div></details>`;
}

function manualHTML(){
  let n = 0;
  const secciones = [];

  secciones.push(manualStep(++n, 'Cómo entrar al sistema', `
    <p>La primera vez que abres el sistema, ves una lista con los nombres del equipo, cada uno con un candado que dice "sin contraseña aún" o "protegida".</p>
    <ol>
      <li>Busca tu nombre en la lista y dale clic.</li>
      <li><strong>Si dice "sin contraseña aún":</strong> el sistema te va a pedir que escribas una contraseña, y luego que la repitas para confirmarla. A partir de ese momento, tu cuenta queda protegida con esa contraseña.</li>
      <li><strong>Si dice "protegida":</strong> te va a pedir la contraseña que ya creaste. Escríbela y dale continuar.</li>
      <li>Entras directo al Tablero.</li>
    </ol>
    <div class="tip"><strong>¿Se te olvidó tu contraseña?</strong> No hay forma de recuperarla tú mismo — pídele al Administrador que entre a "Equipo" y le dé clic a "Restablecer contraseña" en tu nombre. Después de eso, la próxima vez que entres el sistema te va a pedir crear una nueva.</div>
    <p>Para salir en cualquier momento, ve hasta abajo a la izquierda y dale clic a <span class="tag">Cambiar de usuario</span>.</p>
  `));

  secciones.push(manualStep(++n, 'El Tablero, de arriba hacia abajo', `
    <p>Es la primera pantalla que ves al entrar. Aquí está, en el orden exacto en que aparece:</p>
    <ol>
      <li><strong>Botón "Generar reporte ejecutivo (PDF)"</strong>, arriba a la derecha — genera un reporte imprimible con todos tus números del mes (lo explico a detalle en la sección 12).</li>
      <li><strong>Cuatro tarjetas</strong> con números grandes: cuántos asuntos activos tienes, cuántos con prescripción crítica (10 días o menos para vencer), cuántos próximos a vencer (11 a 30 días), y el total de dinero en convenios.</li>
      <li><strong>"Casos que podrían estar atorados"</strong> (solo aparece si hay alguno) — significa que ya pasó el plazo legal para la siguiente etapa del juicio y nadie la ha marcado todavía. Dale clic a cualquiera para abrir ese expediente directo.</li>
      <li><strong>"Asuntos activos sin movimiento en 30+ días"</strong> (solo aparece si hay alguno) — nadie ha tocado ese expediente en un mes completo, sin importar en qué etapa esté.</li>
      <li><strong>"Prioridad de prescripción"</strong> — hasta 8 asuntos, ordenados del que menos días le quedan al que más. Cada uno trae un círculo de color: rojo significa crítico, ámbar significa próximo, verde significa que hay margen.</li>
      <li><strong>"Convenios con cobro pendiente"</strong> — a quién le deben pagar, cuánto, y la fecha de pago agendada del lado derecho.</li>
      <li><strong>"Actividad reciente"</strong> — los últimos movimientos, sin importar el tipo.</li>
    </ol>
    <p>En cualquiera de estas listas, dale clic al nombre del actor para abrir ese expediente completo.</p>
  `));

  secciones.push(manualStep(++n, 'Buscar y filtrar en "Expedientes"', `
    <ol>
      <li>Ve a <span class="tag">Expedientes</span> en el menú de la izquierda.</li>
      <li>Arriba hay una fila de "chips" (botones redondos): Todos, Activos, Concluidos, Con alerta, y luego uno por cada estatus que tengas capturado (Pagado, Federal, etc.). Dale clic al que quieras usar como filtro.</li>
      <li>A la derecha del todo está <span class="tag">+ Nuevo expediente</span> (lo explico en la sección 5).</li>
      <li>Arriba de todo, en la barra superior, está el buscador — escribe el nombre del actor, del demandado, el número de expediente, o el puesto, y la tabla se filtra sola mientras escribes.</li>
      <li>La tabla tiene columnas: Actor, Demandado, Expediente, Instancia, Socio, Estatus, Activo/Concluido, y Alerta/Amparo. Si no caben todas en tu pantalla, la tabla tiene scroll horizontal — desliza con el mouse o el trackpad hacia la derecha.</li>
      <li>Dale clic a cualquier fila para abrir ese expediente.</li>
    </ol>
  `));

  secciones.push(manualStep(++n, 'Un expediente por dentro — pestaña por pestaña', `
    <p>Al abrir cualquier expediente, arriba ves su nombre, contra quién demanda, el número de expediente, y una fila de pestañas. Aquí está cada una a detalle:</p>
    <p><strong><span class="tag">Informe del juicio</span></strong> — lo primero que ves. Arriba, en un recuadro, dice en qué etapa está el asunto ahora mismo y desde cuándo. Si hay una audiencia o un pago agendados, salen destacados justo debajo. Si el asunto lleva 30+ días sin movimiento, o parece atorado, también te avisa aquí. Más abajo están los datos generales (puesto, salario, instancia, teléfono, correo) y la bitácora de notas con fecha, con un cuadro de texto para agregar una nueva.</p>
    <p><strong><span class="tag">Editar datos</span></strong> — todos los campos del expediente en modo edición: nombre del actor, CURP, teléfono, correo, demandado, giro, domicilio, puesto, fechas, salarios, instancia, quién despidió, testigos, y los montos de la liquidación (indemnización, prima de antigüedad, vacaciones, prima vacacional, aguinaldo). Escribe el dato y dale <span class="tag">Guardar datos del expediente</span> al final. Cada cambio que hagas aquí queda registrado con tu nombre y la fecha en el Historial (lo ve solo el Administrador).</p>
    <p><strong><span class="tag">Documentos</span></strong> — sube y organiza los archivos de este asunto (constancia de no conciliación, demanda, contestación, pruebas, actas de audiencia...) por categoría. Acepta PDF, Word, Excel e imágenes, hasta 20 MB por archivo. Si subes una o varias imágenes (JPG/PNG) a la vez, cada una se abre primero en un visor donde puedes arrastrar el recuadro para ajustarlo a las orillas del documento y girarla con los botones <span class="tag">Girar izquierda</span>/<span class="tag">Girar derecha</span> si salió de lado o al revés — igual que las fotos tomadas con la cámara. Si eliges varias imágenes juntas, después de recortarlas todas se arman en un solo PDF, ya acomodadas en orden, y se sube ese único archivo. Los PDF e imágenes tienen botón <span class="tag">Ver</span> para abrirlos directo en una pestaña nueva sin descargarlos; Word y Excel solo se pueden descargar. Cualquiera con acceso a este expediente puede descargarlos o eliminarlos.</p>
    <p><strong><span class="tag">Etapas del juicio</span></strong> — el checklist completo del procedimiento (lo explico a detalle en la sección 6).</p>
    <p><strong><span class="tag">Prescripción</span></strong> — te dice si ya tienen la constancia de no conciliación, desde cuándo corre el plazo, cuántos días de suspensión hubo por la conciliación, y la fecha límite calculada para presentar la demanda, con los días que faltan.</p>
    <p><strong><span class="tag">Cálculo de liquidación</span></strong> — el desglose completo: indemnización de 90 días, prima de antigüedad, vacaciones, prima vacacional, aguinaldo, y si el asunto ya está en juicio, también salarios caídos e intereses. Al final hay un recuadro oscuro con el total general, y un botón <span class="tag">Extraer cálculo (PDF con desglose)</span> para imprimirlo o guardarlo como PDF — listo para presentarlo como propuesta, sin honorarios del despacho incluidos.</p>
    <p><strong><span class="tag">Cobros</span></strong> — solo aparece si el asunto tiene un convenio. Aquí ves el monto total y una lista de pagos, cada uno con su fecha programada, monto, casilla de "Cobrado" y fecha de cobro real. Puedes agregar más fechas de pago con <span class="tag">+ Agregar fecha de pago</span>.</p>
    <p><strong><span class="tag">Amparo</span></strong> — marca si el asunto tiene o podría tener amparo, la fecha de notificación de la sentencia, y el sistema te calcula el vencimiento de los 15 días hábiles automáticamente.</p>
    <p><strong><span class="tag">Pendientes</span></strong> — para cualquier término que no sea una etapa fija: objeciones, un desahogo con plazo de días, una audiencia con fecha ya señalada. Cada uno puede ser "por días" (capturas fecha de inicio + número de días + si son hábiles o naturales, y el sistema calcula el vencimiento) o "fecha fija".</p>
    <p><strong><span class="tag">Generar escrito</span></strong> — descarga en Word la demanda o cualquier otra plantilla que hayas subido (sección 9).</p>
    <p><strong><span class="tag">Hechos del despido</span></strong> — giro de la empresa, hora del despido, quién despidió y su puesto, testigos y sus domicilios, domicilio del demandado.</p>
    <p><strong><span class="tag">Gestión interna</span></strong> — registrar convenio/pago (sección 7), reasignar el socio responsable, escribir notas internas, marcar "cobro pendiente", y marcar el asunto como <strong>Concluido</strong>.</p>
    <p><strong><span class="tag">Historial de cambios</span></strong> — solo la ve el Administrador. Lista cada cambio hecho al expediente: quién, qué campo, el valor antes y después, y cuándo.</p>
  `));

  secciones.push(manualStep(++n, 'Dar de alta un expediente nuevo', `
    <ol>
      <li>Ve a <span class="tag">Expedientes</span>.</li>
      <li>Dale clic a <span class="tag">+ Nuevo expediente</span>, arriba a la derecha.</li>
      <li>Se abre la ficha en blanco, directo en "Editar datos", con el nombre temporal "Nuevo asunto (sin capturar)".</li>
      <li>Llena los campos que ya tengas — no hace falta llenarlos todos de una vez, puedes regresar después las veces que quieras.</li>
      <li>Dale <span class="tag">Guardar datos del expediente</span>.</li>
    </ol>
    <div class="warn2">El sistema nunca inventa un número de expediente ni ningún otro dato — todos los campos quedan vacíos hasta que tú los escribes a mano.</div>
  `));

  secciones.push(manualStep(++n, 'Marcar avances en "Etapas del juicio", paso a paso', `
    <ol>
      <li>Abre el expediente y ve a la pestaña <span class="tag">Etapas del juicio</span>.</li>
      <li>Vas a ver una lista de 15 etapas en orden: conciliación prejudicial, solicitud de conciliación, constancia de no conciliación, demanda presentada, prevención, demanda admitida, emplazamiento, contestación recibida, objeciones y réplica, contrarréplica, manifestaciones de 3 días, audiencia preliminar, audiencia de juicio, sentencia, y amparo directo.</li>
      <li>Cada una tiene: una casilla para marcarla como cumplida, y un campo de fecha.</li>
      <li>Cuando esa etapa ya ocurrió: marca la casilla y pon la fecha en que pasó.</li>
      <li><strong>"Prevención"</strong> solo aplica si el Tribunal la notifica por defectos u omisiones en la demanda — si no hubo prevención, déjala en blanco y marca directo "Demanda admitida".</li>
      <li><strong>Para las dos audiencias</strong> (preliminar y de juicio) vas a ver un campo extra, "Fecha agendada" — úsalo para poner la fecha en que está programada la audiencia, <em>aunque todavía no se celebre</em>. Eso hace que le aparezca automáticamente al cliente en su portal como "próxima audiencia", y a ti en la Agenda.</li>
      <li><strong>Para "Sentencia"</strong> vas a ver un menú desplegable de "Resultado": Favorable, Parcialmente favorable, o Desfavorable. Selecciónalo cuando ya sepas el resultado — esto alimenta la Tasa de éxito del despacho (sección 13).</li>
      <li>Cuando termines, dale <span class="tag">Guardar etapas</span> al final.</li>
      <li>Justo después de guardar, te va a aparecer el botón <span class="tag">📱 Avisar al cliente por WhatsApp</span> — es opcional, dale clic si quieres que se entere de inmediato.</li>
    </ol>
    <div class="tip">En cuanto marcas "Demanda presentada", el sistema deja de contarte la prescripción para ese asunto automáticamente — ya no tienes que revisarlo tú.</div>
  `));

  secciones.push(manualStep(++n, 'Registrar un convenio o pago, paso a paso', `
    <ol>
      <li>Abre el expediente y ve a <span class="tag">Gestión interna</span>.</li>
      <li>Arriba de todo está el bloque "Registrar convenio o pago". Marca la casilla "Hubo convenio o pago acordado en este asunto".</li>
      <li>Captura el <strong>monto</strong> y la <strong>fecha a pagar</strong>.</li>
      <li>Dale <span class="tag">Guardar convenio/pago</span>.</li>
      <li>El sistema te confirma "Guardado ✓ — ya está en Cobros y en la Agenda", y te aparece el botón para avisarle al cliente por WhatsApp.</li>
    </ol>
    <p><strong>Cuando el pago ya se reciba de verdad:</strong></p>
    <ol>
      <li>Ve a la pestaña <span class="tag">Cobros</span> del mismo expediente.</li>
      <li>Busca esa fecha de pago, marca la casilla <strong>Cobrado</strong>.</li>
      <li>Captura la <strong>fecha de cobro real</strong> (el sistema pone la de hoy si la dejas en blanco).</li>
      <li>Dale <span class="tag">Guardar cobros</span>.</li>
    </ol>
    <p>Ese último paso es el que hace que el dinero aparezca en "Ingresos por periodo" — si no marcas "Cobrado", el sistema lo sigue contando como pendiente para siempre.</p>
  `));

  secciones.push(manualStep(++n, 'Avisar al cliente por WhatsApp', `
    <p>Cada vez que guardas una etapa nueva, un convenio, o agregas una nota, te va a aparecer un botón <span class="tag">📱 Avisar al cliente por WhatsApp</span>.</p>
    <ol>
      <li>Dale clic.</li>
      <li>Se abre WhatsApp (web o app) con el mensaje ya escrito — en lenguaje simple, sin tecnicismos: en qué va su caso, qué sigue, y si hay audiencia o pago programado.</li>
      <li>Revísalo si quieres, y presiona enviar <strong>dentro de WhatsApp</strong>.</li>
    </ol>
    <div class="warn2">Esto no se manda solo — siempre tienes que confirmar el envío dentro de WhatsApp. Si el cliente no tiene teléfono capturado (o el formato no se entiende), el sistema te lo dice en vez de fallar en silencio; ve a "Editar datos" y captúralo.</div>
  `));

  secciones.push(manualStep(++n, 'La Agenda general', `
    <ol>
      <li>Ve a <span class="tag">Agenda general</span> en el menú.</li>
      <li>Arriba, en rojo, están las cosas <strong>vencidas</strong> — requieren atención ya.</li>
      <li>Abajo, los <strong>próximos eventos</strong>, ordenados por fecha: pagos pendientes, vencimientos de prescripción, audiencias, términos de amparo, y tus pendientes personalizados.</li>
      <li>Cada fila trae un botón (varía según el tipo: "Marcar cobrado", "Marcar demanda presentada", "Revisar etapas", etc.) para resolverlo directo desde ahí, sin tener que buscar el expediente.</li>
      <li>Dale clic al nombre del actor en cualquier fila para abrir ese expediente completo.</li>
    </ol>
    <p><strong><span class="tag">Google Calendar</span></strong> — arriba de todo hay un botón para conectar tu cuenta de Google. Una vez conectada, el botón "Sincronizar ahora" manda tus audiencias, pagos, vencimientos de prescripción y de amparo a tu Google Calendar como eventos de todo el día, con recordatorios. Es de un solo sentido: lo que edites directo en Google Calendar no se refleja de vuelta en el sistema — la fecha correcta siempre la manda el sistema. Los "Pendientes" personalizados no se sincronizan.</p>
  `));

  secciones.push(manualStep(++n, 'Generar un escrito en Word', `
    <ol>
      <li>Abre el expediente y ve a <span class="tag">Generar escrito</span>.</li>
      <li>Si falta algún dato importante (nombre, domicilio, salario, fechas), el sistema te lo dice antes de generarlo, para que lo completes en "Editar datos" si quieres.</li>
      <li>Elige qué plantilla usar — la demanda, o cualquier otra que un Administrador haya subido (contestación, promoción, etc.).</li>
      <li>Dale <span class="tag">Generar documento en Word (.docx)</span>.</li>
      <li>Se descarga un archivo de Word real (no una imagen ni un PDF), con el membrete del despacho y todos los datos del expediente ya llenados en el lugar correcto.</li>
      <li>Ábrelo en Word como cualquier documento — puedes editarlo libremente antes de presentarlo.</li>
    </ol>
  `));

  secciones.push(manualStep(++n, 'Alertas de prescripción — vista completa', `
    <ol>
      <li>Ve a <span class="tag">Alertas de prescripción</span> en el menú.</li>
      <li>Están separadas en tres bloques: 🔴 Críticas (10 días o menos), 🟡 Próximas (11 a 30 días), 🟢 Con margen (más de 30 días).</li>
      <li>Abajo hay un bloque "Sin riesgo de prescripción (ya superada)" — asuntos donde ya se presentó la demanda o ya hay convenio, así que el plazo dejó de correr.</li>
      <li>Dale clic a cualquiera para abrir el expediente y ver el detalle completo en la pestaña "Prescripción".</li>
    </ol>
  `));

  secciones.push(manualStep(++n, 'Reporte ejecutivo, Ingresos, Empresas demandadas y Tasa de éxito', `
    <p><strong>Reporte ejecutivo (PDF)</strong> — botón arriba del Tablero. Genera un documento con el resumen general de tus asuntos, lo cobrado en el mes, el desglose por socio, tu tasa de éxito, y las empresas que más te demandan seguido. Útil para imprimir cada mes o mandarlo a quien lo pida.</p>
    <p><strong><span class="tag">Ingresos por periodo</span></strong> — elige el periodo (este mes, mes anterior, este año, todo el histórico, o uno personalizado con fecha desde/hasta) y ves el total cobrado, cuántos pagos fueron, y el desglose por socio. Si eres Administrador, puedes filtrar por un socio específico con el menú que aparece arriba a la derecha.</p>
    <p><strong><span class="tag">Empresas demandadas</span></strong> — agrupa tus asuntos por la empresa demandada, para que veas cuáles se repiten, cuánto han pagado en convenio, y el promedio. Dale clic a cualquier fila para ver esos asuntos en Expedientes.</p>
    <p><strong><span class="tag">Tasa de éxito</span></strong> — de todos los asuntos ya concluidos (convenio pagado, sentencia con resultado capturado, o cliente desistió), qué porcentaje se resolvió a favor. Solo cuenta lo que ya está resuelto — un asunto todavía en trámite no afecta esta cifra.</p>
  `));

  secciones.push(manualStep(++n, 'Solo para el Administrador', `
    <p><strong><span class="tag">Equipo</span></strong> — agregar socios nuevos, renombrarlos, restablecer contraseñas, y ver el respaldo completo de toda la información (descargar y restaurar).</p>
    <p><strong><span class="tag">Formato de demanda</span></strong> — descargar la plantilla de Word actual, editarla como cualquier documento, y volver a subirla para que el sistema la use en todas las demandas nuevas. Ahí mismo, más abajo, puedes subir cuantas plantillas adicionales quieras (contestación, promoción, oficio...) para usarlas desde "Generar escrito" en cualquier expediente.</p>
    <p><strong><span class="tag">Días inhábiles</span></strong> — el calendario que el sistema usa para calcular automáticamente los vencimientos de términos (etapas del expediente, amparo, pendientes). Ya viene con los descansos obligatorios de ley; agrega ahí los recesos propios de cada tribunal en cuanto los confirmes.</p>
    <p><strong>Historial de cambios</strong> — dentro de cada expediente, pestaña visible solo para ti: quién cambió qué, cuándo, y qué decía antes.</p>
    <div class="tip">Como Administrador ves todos los expedientes de todo el equipo. Cada socio asignado solo ve los suyos.</div>
  `));

  secciones.push(manualStep(++n, 'Preguntas frecuentes', `
    <p><strong>¿Por qué no veo todos los expedientes, solo algunos?</strong><br>Porque cada socio asignado ve únicamente los asuntos que tiene a su cargo. Solo el Administrador ve todo. Si crees que falta algo, pídele al Administrador que revise a quién está asignado ese expediente.</p>
    <p><strong>¿Puedo deshacer un cambio?</strong><br>No hay un botón de "deshacer", pero el Administrador puede ver el historial completo de cambios de cada expediente en la pestaña "Historial de cambios".</p>
    <p><strong>¿Qué pasa si cierro el sistema sin guardar?</strong><br>Se pierde lo que no hayas guardado con el botón correspondiente. Guarda seguido, sobre todo después de capturar varios campos.</p>
    <p><strong>¿Los cálculos son definitivos?</strong><br>No — son de apoyo. Siempre hay que verificarlos contra el criterio real del Tribunal antes de presentarlos.</p>
    <p><strong>¿El WhatsApp se manda solo?</strong><br>No. Siempre tienes que confirmar el envío dentro de la app de WhatsApp — el sistema solo prepara el mensaje.</p>
    <p><strong>¿Qué hago si un dato se ve mal (por ejemplo un cálculo con un número absurdo)?</strong><br>Revisa el salario diario integrado en "Editar datos" — es la causa más común. Si sigue viéndose mal, avísale al Administrador.</p>
  `));

  secciones.push(manualStep(++n, 'Instalar el sistema en tu celular', `
    <p>El sistema se puede "instalar" como si fuera una app, sin pasar por ninguna tienda de aplicaciones — queda un ícono en tu pantalla de inicio y se abre a pantalla completa.</p>
    <p><strong>Android (Chrome):</strong> entra a la página normalmente. Te va a salir un aviso abajo que dice "Instalar app" o "Añadir a pantalla de inicio" — confírmalo. Si no sale solo, ve al menú (⋮ arriba a la derecha de Chrome) → "Instalar app".</p>
    <p><strong>iPhone (Safari):</strong> entra a la página, toca el ícono de compartir (el cuadrito con la flecha hacia arriba, abajo de la pantalla) → "Añadir a pantalla de inicio".</p>
    <p>Después de instalarlo sigues entrando con tu mismo usuario y contraseña de siempre — no es una cuenta aparte, es el mismo sistema con un acceso más rápido.</p>
  `));

  return `
  <div class="notice" style="margin-bottom:18px;">Guía paso a paso del sistema, pestaña por pestaña y botón por botón. Dale clic a cualquier sección para abrirla — puedes tener varias abiertas a la vez.</div>
  ${secciones.join("")}
  `;
}

function tasaExitoHTML(){
  const list = visibleCases();
  const conResultado = list.map(k=>({k, r:resultadoAsunto(k)})).filter(x=>x.r);
  const sinResultado = list.length - conResultado.length;

  const conteos = {};
  conResultado.forEach(x=>{ conteos[x.r] = (conteos[x.r]||0) + 1; });
  const exitosos = conResultado.filter(x=> RESULTADO_LABEL[x.r].exito === true).length;
  const noExitosos = conResultado.filter(x=> RESULTADO_LABEL[x.r].exito === false).length;
  const base = exitosos + noExitosos; // el desistimiento no cuenta ni a favor ni en contra
  const tasa = base>0 ? (exitosos/base*100) : null;

  return `
  <div class="notice" style="margin-bottom:16px;">Se calcula solo sobre asuntos con un resultado ya registrado — convenio pagado, sentencia con resultado capturado (pestaña Etapas → Sentencia), o cliente desistió. Los ${sinResultado} asunto(s) todavía en trámite no se cuentan; nada se supone aquí.</div>
  <div class="stat-grid" style="grid-template-columns:repeat(3,1fr);">
    <div class="stat-card ok"><div class="bar"></div><div class="num">${tasa!=null? tasa.toFixed(0)+'%' : '—'}</div><div class="label">Tasa de éxito (convenios + sentencias favorables o parciales, sobre casos con resultado a favor o en contra)</div></div>
    <div class="stat-card"><div class="bar"></div><div class="num">${conResultado.length}</div><div class="label">Asuntos con resultado registrado</div></div>
    <div class="stat-card warn"><div class="bar"></div><div class="num">${sinResultado}</div><div class="label">Todavía en trámite (no cuentan para la tasa)</div></div>
  </div>
  <div class="panel">
    <div class="panel-head"><h3>Desglose por resultado</h3></div>
    <div class="panel-body" style="padding:0;">
      <table><thead><tr><th>Resultado</th><th>Cantidad</th><th>% de los concluidos</th></tr></thead>
      <tbody>${Object.keys(RESULTADO_LABEL).map(key=>{
        const n = conteos[key]||0;
        const pct = conResultado.length>0 ? (n/conResultado.length*100) : 0;
        const info = RESULTADO_LABEL[key];
        return `<tr><td><span style="display:inline-block; width:9px; height:9px; border-radius:50%; background:${info.color}; margin-right:8px;"></span>${info.label}</td><td><strong>${n}</strong></td><td>${n>0? pct.toFixed(0)+'%':'—'}</td></tr>`;
      }).join("")}</tbody></table>
    </div>
  </div>
  <div class="notice" style="max-width:100%;">El desistimiento del cliente no se cuenta ni a favor ni en contra de la tasa de éxito, porque no refleja el mérito del caso. Para que esta cifra sea confiable, registra el resultado de la sentencia en la pestaña "Etapas del juicio" de cada expediente en cuanto se resuelva.</div>
  `;
}// Última fecha de actividad registrada en el asunto: la etapa más reciente,
// un pendiente cumplido, un pago cobrado, o el último cambio en el historial.
// Sirve para detectar juicios activos sin movimiento reciente.
function fechaUltimaActividad(kase){
  const meta = getMeta(kase.id);
  let fechas = [];
  Object.values(meta.etapas).forEach(e=>{ if(e && e.fecha) fechas.push(e.fecha); });
  (meta.pendientes||[]).forEach(p=>{ if(p.cumplido && p.fecha_inicio) fechas.push(p.fecha_inicio); });
  (meta.pagos||[]).forEach(p=>{ if(p.cobrado && p.fecha) fechas.push(p.fecha); });
  (meta.notas_bitacora||[]).forEach(n=>{ if(n.fecha) fechas.push(dateToISO(new Date(n.fecha))); });
  if(meta.historial && meta.historial.length){
    fechas.push(dateToISO(new Date(meta.historial[meta.historial.length-1].ts)));
  }
  if(kase.fecha_baja) fechas.push(kase.fecha_baja);
  if(!fechas.length) return null;
  return fechas.filter(Boolean).sort().reverse()[0];
}

// Juicios activos (demanda ya presentada) sin ninguna actividad registrada en
// más de 30 días — posible aviso de que el asunto se atoró en el Tribunal.
// Cualquier asunto activo (no concluido) sin ninguna actividad registrada en
// más de 30 días — antes solo se revisaba para juicios, ahora aplica a
// cualquier etapa (conciliación, espera de audiencia, lo que sea) porque un
// caso "olvidado" en conciliación es igual de grave para el cliente.
function juicioDetenido(kase){
  if(estaConcluido(kase)) return null;
  const ultima = fechaUltimaActividad(kase);
  if(!ultima) return null;
  const today = new Date(); today.setHours(0,0,0,0);
  const dias = daysBetween(parseDate(ultima), today);
  if(dias >= 30) return {ultima, dias};
  return null;
}

function alertLevel(kase){
  const stage = caseStage(kase);
  if(stage === "pagado" || stage === "desistio") return "closed";
  if(stage === "convenio") return "convenio";
  if(stage === "juicio") return "interrumpida";
  const p = computePrescripcion(kase);
  if(!p) return "unknown";
  if(p.daysLeft <= 10) return "crit";
  if(p.daysLeft <= 30) return "warn";
  return "ok";
}

// ---------------------------------------------------------------
// Extracción aproximada de montos ($) de las notas de status
// ---------------------------------------------------------------
const MESES = {enero:1,febrero:2,marzo:3,abril:4,mayo:5,junio:6,julio:7,agosto:8,septiembre:9,setiembre:9,octubre:10,noviembre:11,diciembre:12};
function extractFechasMencionadas(text){
  if(!text) return [];
  const out = [];
  const re = /(\d{1,2})\s*(?:de)?\s*([a-záéíóú]+)\s*(?:de)?\s*(\d{4})/gi;
  let m;
  while((m = re.exec(text)) !== null){
    const mes = MESES[m[2].toLowerCase()];
    if(mes){
      const dd = String(m[1]).padStart(2,'0');
      const mm = String(mes).padStart(2,'0');
      out.push(`${m[3]}-${mm}-${dd}`);
    }
  }
  // también admite DD-MM-YYYY explícito
  const re2 = /\b(\d{2})-(\d{2})-(\d{4})\b/g;
  while((m = re2.exec(text)) !== null){ out.push(`${m[3]}-${m[2]}-${m[1]}`); }
  return [...new Set(out)];
}

function extractMonto(text){
  if(!text) return null;
  const matches = text.match(/\$\s?[\d,]+(\.\d{1,2})?/g);
  if(!matches || !matches.length) return null;
  const nums = matches.map(m=> parseFloat(m.replace(/[\$,\s]/g,'')) ).filter(n=>!isNaN(n));
  if(!nums.length) return null;
  return Math.max(...nums);
}

// ---------------------------------------------------------------
// Datos: todo vive en MySQL. refreshBootstrap() trae del servidor los
// expedientes visibles para el usuario actual (ya filtrados por rol) y el
// equipo, y se llama al iniciar sesión y después de cada guardado.
// ---------------------------------------------------------------
let EQUIPO = [];
let USUARIOS_ADMIN = []; // detalle completo (correo, activo, etc.) — solo lo usa la vista "Equipo" del Administrador
let DIAS_INHABILES = []; // {id, fecha, descripcion, ambito} — ver pantalla "Días inhábiles"

// Horarios semanales propios para atender asesorías telefónicas de pago —
// ver panel "Mi disponibilidad para asesorías" dentro de Agenda general.
let DISPONIBILIDAD = []; // {id, dia_semana (1=lunes..7=domingo), hora_inicio, hora_fin}
let DISPONIBILIDAD_ABIERTA = false; // si el panel está desplegado
const DIA_SEMANA_LABEL = {1:'Lunes', 2:'Martes', 3:'Miércoles', 4:'Jueves', 5:'Viernes', 6:'Sábado', 7:'Domingo'};

// Próximas asesorías agendadas (pagadas o con pago pendiente) — ver panel
// dentro de Agenda general.
let CITAS = [];

// Estado de la cuenta propia del Portal Federal (PJF) — ver pantalla "Mi
// cuenta". El robot Federal usa las cuentas guardadas por cada abogado
// para revisar los expedientes de todos, no solo de uno.
let PJF_CREDENCIALES = null;
async function loadPjfCredenciales(){
  try{
    const d = await api('GET', 'pjf_credenciales_get.php');
    PJF_CREDENCIALES = d;
  }catch(e){ PJF_CREDENCIALES = null; }
}

// Avisos de boletín/lista de acuerdos por expediente — registrados a mano
// o encontrados por los robots de monitoreo automático (Federal, CDMX,
// Edomex). Ver api/avisos_*.php.
let AVISOS_BOLETIN = [];
const FUENTE_BOLETIN_LABEL = {
  cdmx_local: 'Tribunales Laborales locales — CDMX',
  edomex_local: 'Tribunales Laborales locales — Estado de México',
  federal_laboral: 'Tribunales Laborales Federales',
  otro: 'Otra fuente',
};
function avisosNuevosCount(){ return AVISOS_BOLETIN.filter(a=>a.estado==='nuevo').length; }
function avisosDeExpediente(id){ return AVISOS_BOLETIN.filter(a=>a.expediente_id===id); }
async function loadAvisosBoletin(){
  try{
    const d = await api('GET', 'avisos_list.php');
    AVISOS_BOLETIN = d.avisos;
  }catch(e){ AVISOS_BOLETIN = []; }
}

// Captcha pendiente de Edomex — el robot que corre en la computadora del
// despacho publica aquí la imagen cuando necesita que alguien lo resuelva.
// Se revisa junto con el resto del bootstrap para que aparezca en Agenda
// sin tener que entrar a buscarlo.
let EDOMEX_CAPTCHA_PENDIENTE = null;
async function loadEdomexCaptchaPendiente(){
  try{
    const d = await api('GET', 'edomex_captcha_pendiente.php');
    EDOMEX_CAPTCHA_PENDIENTE = d.pendiente;
  }catch(e){ EDOMEX_CAPTCHA_PENDIENTE = null; }
}

const AMBITO_INHABIL_LABEL = {
  federal: 'Federal (Art. 74 LFT)',
  cdmx: 'Tribunales locales — CDMX',
  edomex: 'Tribunales locales — Estado de México',
  todos: 'Todos los ámbitos',
};

let DOCUMENTOS_ACTIVOS = []; // documentos del expediente actualmente abierto en el modal
let CAMARA_STREAM = null; // MediaStream activo del visor de cámara (pestaña Documentos), o null si está cerrado
let FOTOS_CAPTURADAS = []; // {blob, url, width, height} tomadas con la cámara, pendientes de armar el PDF
// Foto recién tomada, en pantalla de recorte antes de agregarse a FOTOS_CAPTURADAS:
// {canvas, dataURL, scale, dispW, dispH, rect:{x,y,w,h}, origen, nombreOriginal} — rect en px de
// pantalla (coordenadas del recuadro mostrado); origen es 'camara', 'archivo' (una sola imagen
// elegida del disco, se sube directo al confirmar) o 'archivo_multi' (varias imágenes elegidas
// juntas, se van juntando en FOTOS_CAPTURADAS para armar un solo PDF al terminar la cola).
let CROP_PENDING = null;
// Archivos de imagen todavía pendientes de pasar por el visor de recorte,
// cuando se eligieron varios de una vez desde "Archivo" (no cámara).
let ARCHIVO_CROP_QUEUE = [];
let PLANTILLAS_LIB = []; // biblioteca de plantillas .docx adicionales a la demanda

async function loadPlantillasLib(){
  try{
    const d = await api('GET', 'plantillas_list.php');
    PLANTILLAS_LIB = d.plantillas;
  }catch(e){ PLANTILLAS_LIB = []; }
}

// ---------------------------------------------------------------
// Sincronización de un solo sentido con Google Calendar: audiencias, pagos,
// prescripción y amparo se empujan como eventos de todo el día al calendario
// de Google del socio conectado. Nunca al revés — un cambio hecho directo en
// Google Calendar no se refleja aquí. Ver api/google_*.php.
// ---------------------------------------------------------------
let GOOGLE_STATUS = {conectado:false, email:null};
const GOOGLE_TIPOS_SINCRONIZABLES = ['audiencia','pago','prescripcion','amparo'];

async function loadGoogleStatus(){
  try{ GOOGLE_STATUS = await api('GET', 'google_status.php'); }
  catch(e){ GOOGLE_STATUS = {conectado:false, email:null}; }
}

async function googleEventId(usuarioId, e){
  const key = usuarioId + '|' + e.tipo + '|' + e.k.id + '|' + (e.actionIdx!=null ? e.actionIdx : '0');
  const bytes = new TextEncoder().encode(key);
  const hashBuf = await crypto.subtle.digest('SHA-256', bytes);
  const hex = Array.from(new Uint8Array(hashBuf)).map(b=>b.toString(16).padStart(2,'0')).join('');
  return 'ela' + hex.slice(0, 40);
}

async function googleCitaEventId(usuarioId, citaId){
  const key = usuarioId + '|cita_asesoria|' + citaId;
  const bytes = new TextEncoder().encode(key);
  const hashBuf = await crypto.subtle.digest('SHA-256', bytes);
  const hex = Array.from(new Uint8Array(hashBuf)).map(b=>b.toString(16).padStart(2,'0')).join('');
  return 'ela' + hex.slice(0, 40);
}

// Crea o actualiza (si ya existe, 409) un evento con id fijo — así
// resincronizar no crea duplicados, solo actualiza el mismo evento.
async function googlePushEvent(base, headers, id, body){
  let res = await fetch(base, {method:'POST', headers, body: JSON.stringify(Object.assign({id}, body))});
  if(res.status === 409){
    res = await fetch(base + '/' + id, {method:'PATCH', headers, body: JSON.stringify(body)});
  }
  return res.ok;
}

async function sincronizarGoogleCalendar(onProgress){
  const tok = await api('GET', 'google_access_token.php');
  const accessToken = tok.access_token;
  const entries = buildAgendaEntries().filter(e => GOOGLE_TIPOS_SINCRONIZABLES.includes(e.tipo));
  // Las asesorías de pago ya confirmadas (pagadas) también se agendan solas
  // en el calendario — con hora exacta, a diferencia de audiencias/pagos/
  // etc. que son de todo el día, porque aquí sí importa la hora exacta de
  // la llamada.
  const citas = CITAS.filter(c => c.estado === 'confirmada');
  const total = entries.length + citas.length;
  const headers = {'Authorization': 'Bearer ' + accessToken, 'Content-Type': 'application/json'};
  const base = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';
  const idsActuales = [];
  let fallos = 0;
  let hechos = 0;

  for(const e of entries){
    const id = await googleEventId(CURRENT_USER.id, e);
    idsActuales.push(id);
    const body = {
      summary: `[${AGENDA_TIPO[e.tipo].label}] ${e.k.actor} vs ${truncate(e.k.demandado,40)}`,
      description: e.label + '\n\nGenerado automáticamente por el sistema de Expertos Laborales — no lo edites aquí, los cambios no se reflejan de vuelta al sistema.',
      start: {date: e.fecha},
      end: {date: e.fecha},
    };
    try{ if(!(await googlePushEvent(base, headers, id, body))) fallos++; }catch(err){ fallos++; }
    hechos++;
    if(onProgress) onProgress(hechos, total);
  }

  for(const c of citas){
    const id = await googleCitaEventId(CURRENT_USER.id, c.id);
    idsActuales.push(id);
    const body = {
      summary: `[Asesoría] ${c.nombre_cliente || c.telefono}`,
      description: `Llamada telefónica de la asesoría pagada ($${c.monto.toFixed(0)} MXN). Teléfono: ${c.telefono}.\n\nGenerado automáticamente por el sistema de Expertos Laborales — no lo edites aquí, los cambios no se reflejan de vuelta al sistema.`,
      start: {dateTime: `${c.fecha}T${c.hora_inicio}:00`, timeZone: 'America/Mexico_City'},
      end: {dateTime: `${c.fecha}T${c.hora_fin}:00`, timeZone: 'America/Mexico_City'},
    };
    try{ if(!(await googlePushEvent(base, headers, id, body))) fallos++; }catch(err){ fallos++; }
    hechos++;
    if(onProgress) onProgress(hechos, total);
  }

  // Borra del calendario lo que ya no aplica (pago cobrado, prescripción
  // resuelta, cita cancelada/expirada, etc.) comparando contra lo que se
  // sincronizó la vez anterior.
  let previos = [];
  try{ const st = await api('GET', 'google_sync_state.php'); previos = st.evento_ids; }catch(e){}
  const obsoletos = previos.filter(id => !idsActuales.includes(id));
  for(const id of obsoletos){
    try{ await fetch(base + '/' + id, {method:'DELETE', headers}); }catch(e){}
  }

  await api('POST', 'google_sync_state.php', {evento_ids: idsActuales});
  return {sincronizados: total, borrados: obsoletos.length, fallos};
}

// Sincroniza en segundo plano, sin interrumpir ni avisar, justo después de
// guardar algo que puede mover una fecha de audiencia/pago/prescripción/
// amparo. Si el socio no ha conectado Google Calendar, no hace nada.
function syncGoogleIfConnected(){
  if(!GOOGLE_STATUS.conectado) return;
  sincronizarGoogleCalendar().catch(()=>{});
}

const CATEGORIA_DOCUMENTO_LABEL = {
  constancia_no_conciliacion: 'Constancia de no conciliación',
  demanda: 'Demanda',
  contestacion: 'Contestación',
  pruebas: 'Pruebas',
  actas: 'Actas de audiencia',
  convenio: 'Convenio',
  otro: 'Otro',
};

async function loadDocumentos(expedienteId){
  try{
    const d = await api('GET', 'documentos_list.php?expediente_id=' + expedienteId);
    DOCUMENTOS_ACTIVOS = d.documentos;
  }catch(e){ DOCUMENTOS_ACTIVOS = []; }
}

const EXTENSIONES_VISUALIZABLES = ['pdf','jpg','jpeg','png'];
function esVisualizableInline(nombreArchivo){
  const ext = (nombreArchivo||'').split('.').pop().toLowerCase();
  return EXTENSIONES_VISUALIZABLES.includes(ext);
}

function fmtBytes(n){
  if(n===null || n===undefined) return "—";
  if(n < 1024) return n + " B";
  if(n < 1024*1024) return (n/1024).toFixed(1) + " KB";
  return (n/(1024*1024)).toFixed(1) + " MB";
}

async function subirDocumento(expedienteId, file, categoria){
  const fd = new FormData();
  fd.append('expediente_id', String(expedienteId));
  fd.append('categoria', categoria);
  fd.append('archivo', file);
  const opts = {method:'POST', body: fd, credentials:'same-origin', headers:{}};
  if(CSRF_TOKEN) opts.headers['X-CSRF-Token'] = CSRF_TOKEN;
  let res, json;
  try{
    res = await fetch(API_BASE + 'documentos_upload.php', opts);
    json = await res.json();
  }catch(e){
    throw new Error('No se pudo conectar con el servidor. Revisa tu conexión e intenta de nuevo.');
  }
  if(!json || json.ok !== true){
    const msg = (json && json.error) ? json.error : ('Error del servidor ('+res.status+').');
    throw new Error(msg);
  }
  return json.data;
}

// ---------------------------------------------------------------
// Cámara del celular (pestaña Documentos): visor en vivo para tomar varias
// fotos (actas, pruebas, identificaciones...) y armar un solo PDF que se
// sube automáticamente al expediente, sin pasar por la galería del teléfono.
// ---------------------------------------------------------------
function detenerCamara(){
  if(CAMARA_STREAM){
    CAMARA_STREAM.getTracks().forEach(t=>t.stop());
    CAMARA_STREAM = null;
  }
}
function limpiarFotosCapturadas(){
  FOTOS_CAPTURADAS.forEach(f=>URL.revokeObjectURL(f.url));
  FOTOS_CAPTURADAS = [];
}

// Arma un PDF válido (una foto JPEG por página, a tamaño completo) sin
// depender de ninguna librería externa — cada imagen se incrusta tal cual
// (/Filter /DCTDecode) y el tamaño de página se deriva de sus píxeles
// asumiendo 150 dpi, para que el PDF resultante tenga un tamaño físico
// razonable en vez del típico "página" de metros de ancho.
// Intenta detectar el borde de la hoja dentro de la foto para proponer un
// recorte inicial (el usuario siempre puede ajustarlo a mano después). Es
// una heurística ligera por gradiente de contraste, no un escáner real:
// funciona bien cuando hay buen contraste entre la hoja y el fondo, y
// puede fallar con fondos muy texturizados o poca luz — por eso siempre
// se muestra el recuadro para corregir, nunca se recorta sin confirmar.
// Trabaja en escala de grises reducida para que sea rápido, y devuelve el
// rectángulo ya escalado a las coordenadas reales de la foto.
function detectarBordeHoja(canvas){
  const W = 240;
  const scale = W / canvas.width;
  const H = Math.max(1, Math.round(canvas.height * scale));
  const work = document.createElement('canvas');
  work.width = W; work.height = H;
  const wctx = work.getContext('2d');
  wctx.drawImage(canvas, 0, 0, W, H);
  const data = wctx.getImageData(0, 0, W, H).data;

  const gray = new Float32Array(W * H);
  for(let i = 0; i < W * H; i++){
    gray[i] = 0.299 * data[i*4] + 0.587 * data[i*4+1] + 0.114 * data[i*4+2];
  }

  const mag = new Float32Array(W * H);
  for(let y = 1; y < H - 1; y++){
    for(let x = 1; x < W - 1; x++){
      const i = y * W + x;
      const gx = gray[i-1-W] - gray[i+1-W] + 2*gray[i-1] - 2*gray[i+1] + gray[i-1+W] - gray[i+1+W];
      const gy = gray[i-W-1] + 2*gray[i-W] + gray[i-W+1] - gray[i+W-1] - 2*gray[i+W] - gray[i+W+1];
      mag[i] = Math.sqrt(gx*gx + gy*gy);
    }
  }

  const colEnergy = new Float32Array(W);
  const rowEnergy = new Float32Array(H);
  for(let y = 0; y < H; y++){
    for(let x = 0; x < W; x++){
      const v = mag[y*W + x];
      colEnergy[x] += v;
      rowEnergy[y] += v;
    }
  }

  function findEdge(energy, len, fromStart){
    let max = 0;
    for(let i = 0; i < len; i++) if(energy[i] > max) max = energy[i];
    if(max <= 0) return fromStart ? 0 : len - 1;
    const thresh = max * 0.35;
    const margin = Math.floor(len * 0.35); // nunca recorta más del 35% por lado
    if(fromStart){
      for(let i = 0; i < margin; i++) if(energy[i] > thresh) return Math.max(0, i - 2);
      return 0;
    }
    for(let i = len - 1; i > len - 1 - margin; i--) if(energy[i] > thresh) return Math.min(len - 1, i + 2);
    return len - 1;
  }

  let left = findEdge(colEnergy, W, true);
  let right = findEdge(colEnergy, W, false);
  let top = findEdge(rowEnergy, H, true);
  let bottom = findEdge(rowEnergy, H, false);

  // Recuadro demasiado chico o inválido: usar uno centrado de respaldo en
  // vez de proponer un recorte absurdo.
  if(right - left < W * 0.3 || bottom - top < H * 0.3 || right <= left || bottom <= top){
    left = W * 0.08; right = W * 0.92; top = H * 0.08; bottom = H * 0.92;
  }

  return {
    x: left / scale, y: top / scale,
    w: (right - left) / scale, h: (bottom - top) / scale,
  };
}

// Gira 90° (deg: -90 o 90) la imagen que está en el visor de recorte —
// las fotos de la galería del teléfono a veces llegan de lado o al revés
// (dos giros de 90°). Vuelve a intentar detectar el borde de la hoja sobre
// la imagen ya girada, para no dejar un recuadro con las proporciones del
// encuadre anterior.
function rotarCropPendiente(deg){
  if(!CROP_PENDING) return;
  const src = CROP_PENDING.canvas;
  const rotated = document.createElement('canvas');
  rotated.width = src.height;
  rotated.height = src.width;
  const ctx = rotated.getContext('2d');
  ctx.translate(rotated.width/2, rotated.height/2);
  ctx.rotate(deg * Math.PI/180);
  ctx.drawImage(src, -src.width/2, -src.height/2);

  const rectReal = detectarBordeHoja(rotated);
  const dispW = Math.min(320, rotated.width);
  const scale = dispW / rotated.width;
  const dispH = Math.round(rotated.height * scale);
  CROP_PENDING.canvas = rotated;
  CROP_PENDING.scale = scale;
  CROP_PENDING.dispW = dispW;
  CROP_PENDING.dispH = dispH;
  CROP_PENDING.dataURL = rotated.toDataURL('image/jpeg', 0.9);
  CROP_PENDING.rect = {x: rectReal.x*scale, y: rectReal.y*scale, w: rectReal.w*scale, h: rectReal.h*scale};
  renderModal();
}

function buildPdfFromJpegs(images){
  const DPI = 150;
  const enc = new TextEncoder();
  const chunks = [];
  let offset = 0;
  const offsets = {};
  function write(data){
    const bytes = typeof data === 'string' ? enc.encode(data) : data;
    chunks.push(bytes);
    offset += bytes.length;
  }
  let nextNum = 1;
  const catalogNum = nextNum++;
  const pagesNum = nextNum++;
  const entries = images.map(img => ({img, pageNum: nextNum++, contentNum: nextNum++, imageNum: nextNum++}));

  write('%PDF-1.4\n');

  entries.forEach(e=>{
    const {img, contentNum, imageNum} = e;
    offsets[imageNum] = offset;
    write(`${imageNum} 0 obj\n<< /Type /XObject /Subtype /Image /Width ${img.width} /Height ${img.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${img.bytes.length} >>\nstream\n`);
    write(img.bytes);
    write('\nendstream\nendobj\n');

    const pageW = (img.width / DPI * 72).toFixed(2);
    const pageH = (img.height / DPI * 72).toFixed(2);
    const content = `q\n${pageW} 0 0 ${pageH} 0 0 cm\n/Im${imageNum} Do\nQ`;
    offsets[contentNum] = offset;
    write(`${contentNum} 0 obj\n<< /Length ${content.length} >>\nstream\n${content}\nendstream\nendobj\n`);
    e.pageW = pageW; e.pageH = pageH;
  });

  entries.forEach(e=>{
    offsets[e.pageNum] = offset;
    write(`${e.pageNum} 0 obj\n<< /Type /Page /Parent ${pagesNum} 0 R /MediaBox [0 0 ${e.pageW} ${e.pageH}] /Resources << /XObject << /Im${e.imageNum} ${e.imageNum} 0 R >> >> /Contents ${e.contentNum} 0 R >>\nendobj\n`);
  });

  offsets[pagesNum] = offset;
  const kids = entries.map(e=>`${e.pageNum} 0 R`).join(' ');
  write(`${pagesNum} 0 obj\n<< /Type /Pages /Kids [${kids}] /Count ${entries.length} >>\nendobj\n`);

  offsets[catalogNum] = offset;
  write(`${catalogNum} 0 obj\n<< /Type /Catalog /Pages ${pagesNum} 0 R >>\nendobj\n`);

  const maxNum = nextNum - 1;
  const xrefOffset = offset;
  write(`xref\n0 ${maxNum + 1}\n0000000000 65535 f \n`);
  for(let n = 1; n <= maxNum; n++){
    write(String(offsets[n]).padStart(10, '0') + ' 00000 n \n');
  }
  write(`trailer\n<< /Size ${maxNum + 1} /Root ${catalogNum} 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`);

  return new Blob(chunks, {type: 'application/pdf'});
}

async function refreshBootstrap(){
  const data = await api('GET', 'expedientes_bootstrap.php');
  CASES_DATA = data.expedientes;
  EQUIPO = data.equipo.map(u => ({id:u.id, name:u.nombre, role:mapRol(u.rol)}));
  if(CURRENT_USER && CURRENT_USER.role === 'Administrador'){
    await loadUsuariosAdmin();
  }
  await loadDiasInhabiles();
  await loadConfiguracion();
  await loadPlantillasLib();
  await loadGoogleStatus();
  await loadTribunalesCustom();
  await loadAvisosBoletin();
  await loadEdomexCaptchaPendiente();
  await loadPjfCredenciales();
  if(CURRENT_USER && CURRENT_USER.role !== 'cliente'){
    await loadProspectos();
    await loadDisponibilidad();
    await loadCitas();
  }
  if(CURRENT_USER && CURRENT_USER.role === 'Administrador'){
    await loadConversaciones();
    await loadResumenesEjecutivos();
  }
}

async function loadProspectos(){
  try{
    const d = await api('GET', 'prospectos_list.php');
    PROSPECTOS = d.prospectos;
  }catch(e){ PROSPECTOS = []; }
}

async function loadDisponibilidad(){
  try{
    const d = await api('GET', 'disponibilidad_list.php');
    DISPONIBILIDAD = d.bloques;
  }catch(e){ DISPONIBILIDAD = []; }
}

async function loadCitas(){
  try{
    const d = await api('GET', 'citas_list.php');
    CITAS = d.citas;
  }catch(e){ CITAS = []; }
}

// Al darle clic a una notificación push (nuevo mensaje/prospecto), en vez
// de dejar al usuario a buscar manualmente quién escribió, se abre
// directo esa conversación — funciona tanto si la pestaña ya estaba
// abierta (mensaje del service worker, ver sw.js) como si se abrió una
// nueva (parámetro ?abrir= en la URL, ver init()).
async function abrirConversacionDesdeNotificacion(telefono){
  if(!CURRENT_USER || CURRENT_USER.role === 'cliente') return;
  await loadProspectos();
  const p = PROSPECTOS.find(x=>x.telefono===telefono);
  if(p){
    VIEW = 'prospectos';
    PROSPECTOS_TAB = p.tipo;
    render();
    await loadProspectoMensajes(p.telefono);
    abrirProspectoModal(p.id);
    if(p.mensaje_nuevo){
      p.mensaje_nuevo = false;
      api('POST', 'prospectos_update.php', {id: p.id, marcar_visto: true}).catch(()=>{});
    }
    return;
  }
  if(CURRENT_USER.role === 'Administrador'){
    await loadConversaciones();
    const c = CONVERSACIONES.find(x=>x.telefono===telefono);
    if(c){
      VIEW = 'conversaciones';
      render();
      if(!WA_EMBUDO) loadWhatsappEmbudo().then(renderViewBody);
      await Promise.all([loadConversacionMensajes(telefono), loadConversacionResumen(telefono)]);
      abrirConversacionModal(telefono);
      return;
    }
  }
  // No se encontró todavía (la notificación llegó antes de que el dato
  // esté listo) — al menos deja al usuario en la vista correcta.
  VIEW = 'prospectos';
  render();
}

async function loadProspectoMensajes(telefono){
  try{
    const d = await api('GET', 'prospectos_mensajes.php?telefono=' + encodeURIComponent(telefono));
    PROSPECTO_MENSAJES = d.mensajes;
  }catch(e){ PROSPECTO_MENSAJES = []; }
}

function prospectosNuevosCount(){ return PROSPECTOS.filter(p=>p.estatus!=='descartado' && (p.estatus==='nuevo' || p.mensaje_nuevo)).length; }

async function loadConversaciones(){
  try{
    const d = await api('GET', 'conversaciones_list.php');
    CONVERSACIONES = d.conversaciones;
    CONVERSACIONES_STATS = d.stats;
  }catch(e){ CONVERSACIONES = []; CONVERSACIONES_STATS = {}; }
}

async function loadResumenesEjecutivos(){
  try{
    const d = await api('GET', 'resumen_ejecutivo.php');
    RESUMENES_EJECUTIVOS = d.resumenes;
  }catch(e){ RESUMENES_EJECUTIVOS = []; }
}

async function loadConversacionMensajes(telefono){
  try{
    const d = await api('GET', 'conversaciones_mensajes.php?telefono=' + encodeURIComponent(telefono));
    CONVERSACION_MENSAJES = d.mensajes;
  }catch(e){ CONVERSACION_MENSAJES = []; }
}

async function loadConversacionResumen(telefono){
  try{
    const d = await api('GET', 'conversaciones_resumen.php?telefono=' + encodeURIComponent(telefono));
    CONVERSACION_RESUMEN = d.resumen;
  }catch(e){ CONVERSACION_RESUMEN = null; }
}

function fmtFechaHora(s){
  if(!s) return "—";
  const d = new Date(String(s).replace(' ', 'T'));
  if(isNaN(d.getTime())) return "—";
  return d.toLocaleString('es-MX', {day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit'});
}

async function loadDiasInhabiles(){
  try{
    const d = await api('GET', 'dias_inhabiles_list.php');
    DIAS_INHABILES = d.dias;
    DIAS_INHABILES_SET = new Set(d.dias.map(x=>x.fecha));
  }catch(e){ DIAS_INHABILES = []; DIAS_INHABILES_SET = new Set(); }
}

// Tribunales que se han agregado a mano vía "Otro (especificar)" en el
// selector de Tribunal — se suma a TRIBUNALES_LOCALES_MX/TRIBUNALES_FEDERALES_MX
// en el selector. Ver tribunalFieldHTML() y bindTribunalFieldEvents().
let TRIBUNALES_CUSTOM = {};
async function loadTribunalesCustom(){
  try{
    const d = await api('GET', 'tribunales_custom_list.php');
    TRIBUNALES_CUSTOM = d.tribunales || {};
  }catch(e){ TRIBUNALES_CUSTOM = {}; }
}

// Salario mínimo diario vigente, usado para topar la prima de antigüedad
// (art. 162 LFT: tope de 2x salario mínimo). Se guarda en la tabla
// "configuracion" y lo edita el Administrador en "Equipo" — así no hay que
// tocar el código cada vez que la CONASAMI publica un nuevo salario mínimo.
let SALARIO_MINIMO_DIARIO = 315.04; // valor de respaldo (zona general, 2026) si aún no se ha guardado ninguno
async function loadConfiguracion(){
  try{
    const d = await api('GET', 'configuracion_get.php');
    const v = parseFloat(d.configuracion && d.configuracion.salario_minimo_diario);
    if(!isNaN(v) && v > 0) SALARIO_MINIMO_DIARIO = v;
  }catch(e){ /* se conserva el valor de respaldo */ }
}
async function saveConfiguracion(clave, valor){
  await api('POST', 'configuracion_set.php', {clave, valor: String(valor)});
}

async function loadUsuariosAdmin(){
  try{
    const d = await api('GET', 'usuarios_list.php');
    USUARIOS_ADMIN = d.usuarios;
  }catch(e){ USUARIOS_ADMIN = []; }
}

function defaultMeta(){
  return {notas:"", cobro_pendiente:false,
    pagos:[], prestaciones_extra:[], amparo_activo:false, amparo_fecha_notif:"", amparo_notas:"", amparo_presentado:false,
    etapas:{}, pendientes:[], historial:[], concluido_manual:false, notas_bitacora:[],
    convenio_manual:{activo:false, monto:'', fecha_pago:''}};
}
function getMeta(id){
  let kase = CASES_DATA.find(c=>c.id===id);
  if(!kase && CLIENT_CASE && CLIENT_CASE.id===id) kase = CLIENT_CASE;
  const m = Object.assign(defaultMeta(), (kase && kase.meta) || {});
  m.etapas = Object.assign({}, m.etapas);
  m.pendientes = m.pendientes || [];
  m.prestaciones_extra = m.prestaciones_extra || [];
  m.historial = m.historial || [];
  m.notas_bitacora = m.notas_bitacora || [];
  return m;
}

function assignedLawyer(kase){
  return kase.abogado || "Sin asignar";
}

// ---------------------------------------------------------------
// Filtrado por usuario activo
// ---------------------------------------------------------------
function visibleCases(){
  // El servidor ya entrega solo los expedientes visibles para el usuario
  // actual (Administrador ve todos, abogado solo los suyos) — ver
  // api/expedientes_bootstrap.php. Aquí se aplica además la casilla de
  // búsqueda del topbar (siempre visible, en cualquier pantalla) — antes
  // solo se aplicaba dentro de "Expedientes", así que buscar algo en
  // Tablero, Alertas, Cobros, etc. no tenía ningún efecto.
  let list = CASES_DATA;
  if(SEARCH_TERM.trim()){
    const q = SEARCH_TERM.toLowerCase();
    list = list.filter(k =>
      (k.actor||"").toLowerCase().includes(q) ||
      (k.demandado||"").toLowerCase().includes(q) ||
      (k.exp||"").toLowerCase().includes(q) ||
      (k.puesto||"").toLowerCase().includes(q)
    );
  }
  return list;
}
function filteredCases(){
  let list = visibleCases();
  if(FILTER_STATUS !== "todos"){
    if(FILTER_STATUS === "alerta"){
      list = list.filter(k => ["crit","warn"].includes(alertLevel(k)));
    } else if(FILTER_STATUS === "activos"){
      list = list.filter(k => !estaConcluido(k));
    } else if(FILTER_STATUS === "concluidos"){
      list = list.filter(k => estaConcluido(k));
    } else {
      list = list.filter(k => (k.status||"").toLowerCase() === FILTER_STATUS);
    }
  }
  return list;
}

// ---------------------------------------------------------------
// Render: shell
// ---------------------------------------------------------------
const ICONS = {
  tablero:"&#9670;", expedientes:"&#9776;", alertas:"&#9888;", cliente:"&#9997;", equipo:"&#128101;"
};

function render(){
  const root = document.getElementById('root');
  if(!CURRENT_USER){ root.innerHTML = gateHTML(); bindGate(); return; }
  root.innerHTML = shellHTML();
  bindShell();
}

let GATE_MODE = 'login'; // 'login' | 'change_password'
let GATE_ERROR = '';
let PENDING_USER = null; // usuario que inició sesión pero debe cambiar su contraseña antes de continuar

function gateHTML(){
  if(GATE_MODE === 'change_password'){
    return `
    <div class="gate">
      <div class="gate-card">
        <img src="data:image/png;base64,${LOGO_DESPACHO_B64}" alt="Expertos Laborales" style="max-width:220px; height:auto; margin:0 auto 18px auto; display:block;">
        <h1>Crea tu nueva contraseña</h1>
        <p>Es tu primer ingreso, o un administrador reinició tu acceso. Usa la contraseña temporal que te dieron y elige una nueva que solo tú conozcas.</p>
        ${GATE_ERROR? `<div class="gate-error">${escapeHTML(GATE_ERROR)}</div>` : ""}
        <form class="gate-form" id="changePassForm">
          <label>Contraseña temporal</label>
          <input type="password" id="cpCurrent" autocomplete="current-password" required>
          <label>Contraseña nueva (mínimo 8 caracteres)</label>
          <input type="password" id="cpNew1" autocomplete="new-password" required minlength="8">
          <label>Repite la contraseña nueva</label>
          <input type="password" id="cpNew2" autocomplete="new-password" required minlength="8">
          <button type="submit" class="btn">Guardar y entrar</button>
        </form>
      </div>
    </div>`;
  }
  return `
  <div class="gate">
    <div class="gate-card">
      <img src="data:image/png;base64,${LOGO_DESPACHO_B64}" alt="Expertos Laborales" style="max-width:220px; height:auto; margin:0 auto 18px auto; display:block;">
      <p>Gestión de litigios laborales.<br>Ingresa con tu correo y contraseña.</p>
      ${GATE_ERROR? `<div class="gate-error">${escapeHTML(GATE_ERROR)}</div>` : ""}
      <form class="gate-form" id="loginForm">
        <label>Correo</label>
        <input type="email" id="loginEmail" autocomplete="username" required>
        <label>Contraseña</label>
        <input type="password" id="loginPassword" autocomplete="current-password" required>
        <button type="submit" class="btn">Entrar</button>
      </form>
      <div class="mode-toggle"><button id="clientModeBtn" type="button">Acceder como cliente para consultar mi asunto &#8594;</button></div>
    </div>
  </div>`;
}

function bindGate(){
  if(GATE_MODE === 'change_password'){
    document.getElementById('changePassForm').addEventListener('submit', async (e)=>{
      e.preventDefault();
      const current = document.getElementById('cpCurrent').value;
      const n1 = document.getElementById('cpNew1').value;
      const n2 = document.getElementById('cpNew2').value;
      if(n1 !== n2){ GATE_ERROR = 'Las dos contraseñas nuevas no coinciden.'; render(); return; }
      try{
        await api('POST', 'auth_change_password.php', {current_password: current, new_password: n1});
        CURRENT_USER = PENDING_USER; PENDING_USER = null; GATE_ERROR = ''; GATE_MODE = 'login';
        VIEW = "tablero";
        await refreshBootstrap();
        render();
      }catch(err){ GATE_ERROR = err.message; render(); }
    });
    return;
  }
  document.getElementById('loginForm').addEventListener('submit', async (e)=>{
    e.preventDefault();
    const email = document.getElementById('loginEmail').value.trim();
    const password = document.getElementById('loginPassword').value;
    try{
      const data = await api('POST', 'auth_login.php', {email, password});
      CSRF_TOKEN = data.csrf;
      if(data.user.debe_cambiar_password){
        PENDING_USER = {id:data.user.id, name:data.user.nombre, email:data.user.email, role:mapRol(data.user.rol)};
        GATE_MODE = 'change_password'; GATE_ERROR = '';
        render();
        return;
      }
      CURRENT_USER = {id:data.user.id, name:data.user.nombre, email:data.user.email, role:mapRol(data.user.rol)};
      VIEW = "tablero"; GATE_ERROR = '';
      await refreshBootstrap();
      render();
    }catch(err){ GATE_ERROR = err.message; render(); }
  });
  document.getElementById('clientModeBtn').addEventListener('click', ()=>{
    CURRENT_USER = {id:"__client__", name:"Cliente", role:"cliente"};
    VIEW = "cliente";
    render();
  });
}

// ---------------------------------------------------------------
// Notificaciones push (como WhatsApp) — avisan aunque el sistema esté
// cerrado cuando llega un mensaje nuevo de un prospecto. Ver
// api/push_helpers.php (servidor) y sw.js (qué hace el navegador con el
// aviso). En iPhone solo funcionan si el sistema está "Agregado a pantalla
// de inicio" (instalado como app) — es una limitación de Apple, no del
// sistema.
// ---------------------------------------------------------------
function urlBase64ToUint8Array(base64String){
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for(let i=0;i<rawData.length;i++) outputArray[i] = rawData.charCodeAt(i);
  return outputArray;
}

async function activarNotificacionesPush(){
  if(!('serviceWorker' in navigator) || !('PushManager' in window)){
    throw new Error('Este navegador no soporta notificaciones push.');
  }
  const permiso = await Notification.requestPermission();
  if(permiso !== 'granted'){
    throw new Error('No diste permiso de notificaciones. Si cambias de opinión, actívalo desde la configuración del navegador para este sitio.');
  }
  const reg = await navigator.serviceWorker.ready;
  const {public_key} = await api('GET', 'push_vapid_public_key.php');
  let sub = await reg.pushManager.getSubscription();
  if(!sub){
    sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(public_key),
    });
  }
  const json = sub.toJSON();
  await api('POST', 'push_subscribe.php', {endpoint: json.endpoint, keys: json.keys});
}

function shellHTML(){
  if(CURRENT_USER.role === "cliente"){
    return `<div class="app"><div class="main" style="max-width:720px; margin:0 auto; width:100%;">
      ${topBarClientHTML()}
      ${clientPortalHTML()}
    </div></div>`;
  }
  const isAdmin = CURRENT_USER.role === "Administrador";
  return `
  <div class="app">
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    <div class="sidebar" id="sidebar">
      <div class="brand">
        <img src="data:image/png;base64,${LOGO_DESPACHO_B64}" alt="Expertos Laborales" style="max-width:150px; height:auto; margin-bottom:10px;">
      </div>
      <div class="nav">
        <button data-v="tablero" class="${VIEW==='tablero'?'active':''}"><span class="ico">${ICONS.tablero}</span> Tablero</button>
        <button data-v="expedientes" class="${VIEW==='expedientes'?'active':''}"><span class="ico">${ICONS.expedientes}</span> Expedientes</button>
        <button data-v="alertas" class="${VIEW==='alertas'?'active':''}"><span class="ico">${ICONS.alertas}</span> Alertas de prescripción</button>
        <button data-v="cobros" class="${VIEW==='cobros'?'active':''}"><span class="ico">&#128176;</span> Cobros de convenios</button>
        <button data-v="ingresos" class="${VIEW==='ingresos'?'active':''}"><span class="ico">&#128202;</span> Ingresos por periodo</button>
        <button data-v="demandados" class="${VIEW==='demandados'?'active':''}"><span class="ico">&#127970;</span> Empresas demandadas</button>
        <button data-v="exito" class="${VIEW==='exito'?'active':''}"><span class="ico">&#127942;</span> Tasa de éxito</button>
        <button data-v="agenda" class="${VIEW==='agenda'?'active':''}"><span class="ico">&#128197;</span> Agenda general ${(avisosNuevosCount()+(EDOMEX_CAPTCHA_PENDIENTE?1:0))>0?`<span class="nav-badge">${avisosNuevosCount()+(EDOMEX_CAPTCHA_PENDIENTE?1:0)}</span>`:""}</button>
        <button data-v="prospectos" class="${VIEW==='prospectos'?'active':''}"><span class="ico">&#128172;</span> Prospectos (WhatsApp) ${prospectosNuevosCount()>0?`<span class="nav-badge">${prospectosNuevosCount()}</span>`:""}</button>
        ${isAdmin ? `<button data-v="conversaciones" class="${VIEW==='conversaciones'?'active':''}"><span class="ico">&#128269;</span> Conversaciones (WhatsApp)</button>` : ""}
        ${isAdmin ? `<button data-v="resumen_ejecutivo" class="${VIEW==='resumen_ejecutivo'?'active':''}"><span class="ico">&#128202;</span> Resumen ejecutivo (bot)</button>` : ""}
        <div class="section-label">Referencia</div>
        <button data-v="manual" class="${VIEW==='manual'?'active':''}"><span class="ico">&#128218;</span> Manual de uso</button>
        <button data-v="jurisprudencia" class="${VIEW==='jurisprudencia'?'active':''}"><span class="ico">&#9878;</span> Jurisprudencia</button>
        <button data-v="cliente_preview" class="${VIEW==='cliente_preview'?'active':''}"><span class="ico">${ICONS.cliente}</span> Vista de portal cliente</button>
        <button data-v="mi_cuenta" class="${VIEW==='mi_cuenta'?'active':''}"><span class="ico">&#128100;</span> Mi cuenta</button>
        ${isAdmin ? `<button data-v="equipo" class="${VIEW==='equipo'?'active':''}"><span class="ico">${ICONS.equipo}</span> Equipo</button>` : ""}
        ${isAdmin ? `<button data-v="formato_demanda" class="${VIEW==='formato_demanda'?'active':''}"><span class="ico">&#128221;</span> Formato de demanda</button>` : ""}
        ${isAdmin ? `<button data-v="dias_inhabiles" class="${VIEW==='dias_inhabiles'?'active':''}"><span class="ico">&#128198;</span> Días inhábiles</button>` : ""}
      </div>
      <div class="sidebar-foot">
        <div class="user-pill"><div class="dot"></div><span>${escapeHTML(CURRENT_USER.name)}</span></div>
        <button class="switch-user" id="pushNotifBtn" style="margin-bottom:6px;">&#128276; Activar notificaciones</button>
        <button class="switch-user" id="switchUserBtn">Cambiar de usuario</button>
      </div>
    </div>
    <div class="main">
      ${topBarHTML()}
      <div id="viewBody"></div>
    </div>
  </div>
  <div class="overlay" id="modalOverlay"></div>
  `;
}

// Título y subtítulo de cada vista — única fuente de verdad, usada tanto por
// topBarHTML() (primer render) como por highlightNav() (cuando se cambia de
// vista sin recargar todo el shell). Antes highlightNav() traía su propia
// lista duplicada que solo cubría el título — el subtítulo se quedaba
// pegado en el de la vista anterior cada vez que cambiabas de pantalla.
const VIEW_TITLES = {
  tablero:["Tablero general", "Panorama de todos los asuntos activos y alertas críticas"],
  expedientes:["Expedientes", "Consulta, filtra y administra cada asunto"],
  alertas:["Alertas de prescripción", "Cómputo conforme al Título Décimo de la Ley Federal del Trabajo, según el tipo de cada asunto"],
  cobros:["Cobros de convenios", "Seguimiento de pagos pactados en convenios de conciliación"],
  ingresos:["Ingresos por periodo", "Cuánto se ha cobrado, agrupado por el periodo que elijas"],
  demandados:["Empresas demandadas", "Qué demandados se repiten, cuánto han pagado y qué tanto negocian"],
  exito:["Tasa de éxito", "Qué tan seguido se resuelven los asuntos a favor del trabajador"],
  manual:["Manual de uso", "Guía paso a paso del sistema"],
  jurisprudencia:["Jurisprudencia", "Describe los hechos de tu caso y encuentra las tesis de la SCJN que de verdad aplican — la IA responde solo con tesis reales guardadas aquí, nunca inventadas"],
  agenda:["Agenda general", "Pagos, prescripciones, amparos y actuaciones — todo en una línea de tiempo"],
  prospectos:["Prospectos (WhatsApp)", "Casos de despido, asesorías de pago y despachos interesados en Control de Expedientes, captados por el asistente de IA en WhatsApp"],
  conversaciones:["Conversaciones (WhatsApp)", "Todas las conversaciones del bot, calificaran o no como prospecto — para revisar y perfeccionar sus respuestas"],
  resumen_ejecutivo:["Resumen ejecutivo (bot)", "Temas más comunes, patrones y oportunidades detectadas en las conversaciones de WhatsApp"],
  formato_demanda:["Formato de demanda", "Plantilla de demanda y biblioteca de otras plantillas de escritos"],
  cliente_preview:["Vista previa del portal de cliente", "Así es como un cliente vería el estado de su asunto"],
  equipo:["Equipo del despacho", "Socios asignados y asuntos a cargo"],
  dias_inhabiles:["Días inhábiles", "Calendario usado para calcular automáticamente los vencimientos de términos en todo el sistema"],
  mi_cuenta:["Mi cuenta", "Datos personales para el funcionamiento del sistema"]
};

function topBarHTML(){
  const [t,s] = VIEW_TITLES[VIEW] || ["",""];
  return `
  <div class="topbar">
    <button class="mobile-menu-btn" id="mobileMenuBtn">&#9776;</button>
    <div style="flex:1; min-width:0;"><h2>${t}</h2><div class="sub">${s}</div></div>
    <div class="search-box"><span>&#128269;</span><input id="searchInput" placeholder="Buscar por actor, demandado, expediente..." value="${escapeHTML(SEARCH_TERM)}"></div>
  </div>`;
}

function topBarClientHTML(){
  return `<div class="topbar" style="justify-content:center; margin-top:20px;">
    <div style="text-align:center;">
      <div style="display:inline-block; background:linear-gradient(160deg, var(--ink) 0%, var(--ink-2) 100%); border-radius:14px; padding:18px 28px;">
        <img src="data:image/png;base64,${LOGO_DESPACHO_B64}" alt="Expertos Laborales" style="max-width:200px; height:auto; display:block;">
      </div>
      <div class="sub" style="margin-top:10px;">Consulta el estado de tu asunto laboral</div>
    </div>
  </div>`;
}

function bindShell(){
  const sidebarEl = document.getElementById('sidebar');
  const backdropEl = document.getElementById('sidebarBackdrop');
  function closeMobileMenu(){
    if(sidebarEl) sidebarEl.classList.remove('open');
    if(backdropEl) backdropEl.classList.remove('open');
  }
  const menuBtn = document.getElementById('mobileMenuBtn');
  if(menuBtn) menuBtn.addEventListener('click', ()=>{
    if(sidebarEl) sidebarEl.classList.toggle('open');
    if(backdropEl) backdropEl.classList.toggle('open');
  });
  if(backdropEl) backdropEl.addEventListener('click', closeMobileMenu);
  document.querySelectorAll('.nav button[data-v]').forEach(b=>{
    b.addEventListener('click', ()=>{
      VIEW = b.dataset.v; renderViewBody(); highlightNav(); closeMobileMenu();
      if(VIEW==='conversaciones' && !WA_EMBUDO) loadWhatsappEmbudo().then(renderViewBody);
    });
  });
  const pushNotifBtn = document.getElementById('pushNotifBtn');
  if(pushNotifBtn){
    if(typeof Notification !== 'undefined' && Notification.permission === 'granted'){
      pushNotifBtn.textContent = '🔔 Notificaciones activadas ✓';
    }
    pushNotifBtn.addEventListener('click', async ()=>{
      pushNotifBtn.disabled = true;
      const textoOriginal = pushNotifBtn.textContent;
      pushNotifBtn.textContent = 'Activando...';
      try{
        await activarNotificacionesPush();
        pushNotifBtn.textContent = '🔔 Notificaciones activadas ✓';
        pushNotifBtn.disabled = true;
      }catch(err){
        pushNotifBtn.disabled = false;
        pushNotifBtn.textContent = textoOriginal;
        alert('No se pudieron activar las notificaciones: ' + err.message);
      }
    });
  }
  const su = document.getElementById('switchUserBtn');
  if(su) su.addEventListener('click', async ()=>{
    if(CURRENT_USER && CURRENT_USER.role !== 'cliente'){
      try{ await api('POST', 'auth_logout.php'); }catch(e){}
    }
    CURRENT_USER = null; CSRF_TOKEN = null; CASES_DATA = []; GATE_MODE = 'login'; GATE_ERROR = '';
    render();
  });
  const si = document.getElementById('searchInput');
  if(si) si.addEventListener('input', (e)=>{ SEARCH_TERM = e.target.value; renderViewBody(); });
  if(CURRENT_USER.role === "cliente"){ bindClientPortal(); return; }
  renderViewBody();
}

function highlightNav(){
  document.querySelectorAll('.nav button[data-v]').forEach(b=>{
    b.classList.toggle('active', b.dataset.v === VIEW);
  });
  const [t,s] = VIEW_TITLES[VIEW] || ["",""];
  const h2 = document.querySelector('.topbar h2');
  const sub = document.querySelector('.topbar .sub');
  if(h2) h2.textContent = t;
  if(sub) sub.textContent = s;
}

// ---------------------------------------------------------------
// Vista: Tablero
// ---------------------------------------------------------------
function renderViewBody(){
  const el = document.getElementById('viewBody');
  if(!el) return;
  if(VIEW==='tablero') el.innerHTML = tableroHTML();
  else if(VIEW==='expedientes') el.innerHTML = expedientesHTML();
  else if(VIEW==='alertas') el.innerHTML = alertasHTML();
  else if(VIEW==='cobros') el.innerHTML = cobrosHTML();
  else if(VIEW==='ingresos') el.innerHTML = ingresosHTML();
  else if(VIEW==='demandados') el.innerHTML = demandadosHTML();
  else if(VIEW==='exito') el.innerHTML = tasaExitoHTML();
  else if(VIEW==='manual') el.innerHTML = manualHTML();
  else if(VIEW==='jurisprudencia') el.innerHTML = jurisprudenciaHTML();
  else if(VIEW==='agenda') el.innerHTML = agendaHTML();
  else if(VIEW==='prospectos') el.innerHTML = prospectosHTML();
  else if(VIEW==='conversaciones') el.innerHTML = conversacionesHTML();
  else if(VIEW==='resumen_ejecutivo') el.innerHTML = resumenEjecutivoHTML();
  else if(VIEW==='formato_demanda') el.innerHTML = formatoDemandaHTML();
  else if(VIEW==='cliente_preview') el.innerHTML = clientePreviewHTML();
  else if(VIEW==='equipo') el.innerHTML = equipoHTML();
  else if(VIEW==='dias_inhabiles') el.innerHTML = diasInhabilesHTML();
  else if(VIEW==='mi_cuenta') el.innerHTML = miCuentaHTML();
  bindViewBody();
}

function ringSVG(daysLeft, level, size=44){
  const totalRef = 60;
  const pct = Math.max(0, Math.min(1, daysLeft/totalRef));
  const r = (size-6)/2, c = 2*Math.PI*r;
  const colors = {crit:'var(--red)', warn:'var(--amber)', ok:'var(--green)', closed:'#c9c2ab', unknown:'#c9c2ab'};
  const offset = c * (1-pct);
  return `<div class="ring" style="width:${size}px;height:${size}px;">
    <svg width="${size}" height="${size}"><circle class="bg" cx="${size/2}" cy="${size/2}" r="${r}" fill="none" stroke-width="4"/>
    <circle class="val" cx="${size/2}" cy="${size/2}" r="${r}" fill="none" stroke="${colors[level]}" stroke-width="4"
      stroke-dasharray="${c}" stroke-dashoffset="${offset}"/></svg>
    <div class="days" style="color:${colors[level]}">${level==='closed'?'&#10003;':daysLeft}</div>
  </div>`;
}

// Resumen de convenios: solo cuenta asuntos donde la nota menciona explícitamente
// un "convenio" (excluye ofertas rechazadas y otras cifras sueltas), sin duplicados.
function convenioSummary(){
  const list = visibleCases().filter(tieneConvenio);
  let total = 0, cobrado = 0, pendiente = 0;
  list.forEach(k=>{
    const totalCaso = montoConvenio(k) || 0;
    total += totalCaso;
    const stage = caseStage(k);
    const meta = getMeta(k.id);
    const ledger = (meta.pagos||[]).filter(p=>p.monto && !isNaN(parseFloat(p.monto)));
    if(ledger.length){
      // el abogado capturó montos por parcialidad: usar esas cifras exactas
      ledger.forEach(p=>{
        const m = parseFloat(p.monto);
        if(p.cobrado) cobrado += m; else pendiente += m;
      });
    } else if(stage === 'pagado'){
      cobrado += totalCaso;
    } else {
      pendiente += totalCaso;
    }
  });
  return {list, total, cobrado, pendiente};
}

function statCards(){
  const list = visibleCases();
  const open = list.filter(k=>!isCaseClosed(k.status));
  const relevantes = open.filter(isPrescripcionRelevant);
  const crit = relevantes.filter(k=>alertLevel(k)==='crit');
  const warn = relevantes.filter(k=>alertLevel(k)==='warn');
  return `
  <div class="stat-grid" style="grid-template-columns:repeat(3,1fr);">
    <div class="stat-card"><div class="bar"></div><div class="num">${open.length}</div><div class="label">Asuntos activos</div></div>
    <div class="stat-card crit"><div class="bar"></div><div class="num">${crit.length}</div><div class="label">Prescripción crítica (&le;10 días)</div></div>
    <div class="stat-card warn"><div class="bar"></div><div class="num">${warn.length}</div><div class="label">Próximos a vencer (11-30 días)</div></div>
  </div>`;
}

function tableroHTML(){
  const list = visibleCases().filter(isPrescripcionRelevant);
  const withAlert = list.map(k=>({k, p: computePrescripcion(k), lvl: alertLevel(k)}))
    .filter(x=>x.p)
    .sort((a,b)=> a.p.daysLeft - b.p.daysLeft)
    .slice(0,8);

  const recientes = [...visibleCases()].sort((a,b)=> (parseDate(b.fecha_baja)||0) - (parseDate(a.fecha_baja)||0)).slice(0,6);
  const conv = convenioSummary();
  const convPendientes = conv.list.filter(k=>caseStage(k)!=='pagado').slice(0,6);
  const estancados = visibleCases().map(k=>({k, est:estancadoInfo(k)})).filter(x=>x.est);
  const detenidos = visibleCases().map(k=>({k, det:juicioDetenido(k)})).filter(x=>x.det);

  return `
  <div style="display:flex; justify-content:flex-end; margin-bottom:14px;">
    <button class="btn secondary" id="reporteEjecutivoBtn">📄 Generar reporte ejecutivo (PDF)</button>
  </div>
  ${statCards()}
  ${detenidos.length ? `
  <div class="panel">
    <div class="panel-head"><h3>&#8987; Asuntos activos sin movimiento en 30+ días</h3><span class="count">${detenidos.length}</span></div>
    <div class="panel-body">
      ${detenidos.map(x=>`
        <div class="alert-row" data-id="${x.k.id}">
          <div class="alert-info">
            <div class="name">${escapeHTML(x.k.actor)} <span style="color:var(--gray); font-weight:400;">vs</span> ${escapeHTML(truncate(x.k.demandado,36))}</div>
            <div class="meta">Última actividad registrada: ${fmtDate(x.det.ultima)} — ${x.det.dias} día(s) sin movimiento</div>
          </div>
          <span class="badge warn">Revisar</span>
        </div>`).join("")}
    </div>
  </div>` : ""}
  ${estancados.length ? `
  <div class="panel">
    <div class="panel-head"><h3>&#9888; Casos que podrían estar atorados</h3><span class="count">${estancados.length}</span></div>
    <div class="panel-body">
      ${estancados.map(x=>`
        <div class="alert-row" data-id="${x.k.id}">
          <div class="alert-info">
            <div class="name">${escapeHTML(x.k.actor)} <span style="color:var(--gray); font-weight:400;">vs</span> ${escapeHTML(truncate(x.k.demandado,36))}</div>
            <div class="meta">Se esperaba "${escapeHTML(x.est.siguiente)}" desde el ${fmtDate(dateToISO(x.est.expected))} — ${x.est.diasAtraso} día(s) sin registrarse</div>
          </div>
          <span class="badge crit">Revisar</span>
        </div>`).join("")}
    </div>
  </div>` : ""}
  <div class="panel">
    <div class="panel-head"><h3>Prioridad de prescripción</h3><span class="count">Título Décimo LFT &middot; solo asuntos sin demanda ni convenio</span></div>
    <div class="panel-body">
      ${withAlert.length ? withAlert.map(x=>alertRowHTML(x.k, x.p, x.lvl)).join("") : `<div class="empty">Sin asuntos con riesgo de prescripción pendiente. Los que ya tienen demanda presentada o convenio no corren este riesgo.</div>`}
    </div>
  </div>
  <div class="panel">
    <div class="panel-head"><h3>Convenios con cobro pendiente</h3><span class="count">${convPendientes.length}</span></div>
    <div class="panel-body">
      ${convPendientes.length ? convPendientes.map(k=>{
        const pago = proximoPago(k);
        return `
        <div class="alert-row" data-id="${k.id}">
          <span class="badge convenio" style="flex-shrink:0;">${fmtMoney(montoConvenio(k))}</span>
          <div class="alert-info">
            <div class="name">${escapeHTML(k.actor)} <span style="color:var(--gray); font-weight:400;">vs</span> ${escapeHTML(truncate(k.demandado,36))}</div>
            <div class="meta">${escapeHTML(truncate(k.ultima_nota,90))}</div>
          </div>
          <div style="text-align:right; flex-shrink:0;">
            <div style="font-size:10px; text-transform:uppercase; letter-spacing:.04em; color:var(--gray);">Pago agendado</div>
            <div style="font-size:13px; font-weight:700; color:${pago?'var(--ink)':'var(--gray)'};">${pago ? fmtDate(pago.fecha) : 'sin fecha'}</div>
          </div>
        </div>`;
      }).join("") : `<div class="empty">Sin convenios pendientes de cobro.</div>`}
    </div>
  </div>
  <div class="panel">
    <div class="panel-head"><h3>Actividad reciente</h3><span class="count">${recientes.length} registros</span></div>
    <div class="panel-body">
      ${recientes.map(k=>`
        <div class="alert-row" data-id="${k.id}">
          <div style="width:44px; text-align:center; font-size:11px; color:var(--gray);">${fmtDate(k.fecha_baja)}</div>
          <div class="alert-info">
            <div class="name">${escapeHTML(k.actor)} <span style="color:var(--gray); font-weight:400;">vs</span> ${escapeHTML(truncate(k.demandado,40))}</div>
            <div class="meta">${escapeHTML(truncate(k.ultima_nota || k.instancia || '', 90))}</div>
          </div>
          ${statusBadge(k)}
        </div>`).join("")}
    </div>
  </div>`;
}

function alertRowHTML(k, p, lvl){
  const labelMap = {crit:'Crítico', warn:'Próximo', ok:'Con margen'};
  const constanciaTxt = k.fecha_constancia ? `Constancia: sí (${fmtDate(k.fecha_constancia)})` : (k.fecha_inicio_conciliacion ? 'Constancia: pendiente' : 'Constancia: no aplica aún');
  return `<div class="alert-row" data-id="${k.id}">
    ${ringSVG(p.daysLeft, lvl)}
    <div class="alert-info">
      <div class="name">${escapeHTML(k.actor)} <span style="color:var(--gray); font-weight:400;">vs</span> ${escapeHTML(truncate(k.demandado,36))}</div>
      <div class="meta">Vence: ${fmtDate(dateToISO(p.deadline))} &middot; ${p.plazo.articulo} &middot; ${constanciaTxt} &middot; ${assignedLawyer(k)}</div>
    </div>
    <span class="badge ${lvl}">${labelMap[lvl] || lvl}</span>
  </div>`;
}

function dateToISO(d){ if(!d) return ""; return d.toISOString().slice(0,10); }
function truncate(s,n){ if(!s) return ""; return s.length>n? s.slice(0,n-1)+"…" : s; }
function escapeHTML(s){ if(s===null||s===undefined) return ""; return String(s).replace(/[&<>"']/g, c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c])); }

function statusBadge(k){
  const stage = caseStage(k);
  if(stage === "pagado") return `<span class="badge closed">Pagado</span>`;
  if(stage === "desistio") return `<span class="badge closed">Cliente desistió</span>`;
  if(stage === "convenio") return `<span class="badge convenio">Convenio &middot; cobro pendiente</span>`;
  if(stage === "juicio") return `<span class="badge interrumpida">Demanda presentada</span>`;
  const lvl = alertLevel(k);
  const labelMap = {crit:'Crítico', warn:'Próximo', ok:'Con margen', unknown:'Sin fecha'};
  return `<span class="badge ${lvl==='unknown'?'closed':lvl}">${labelMap[lvl]||k.status||''}</span>`;
}

function expedientesHTML(){
  const statuses = ["todos","activos","concluidos","alerta", ...new Set(visibleCases().map(k=>(k.status||'').toLowerCase()).filter(Boolean))];
  const chipLabel = {todos:'Todos', activos:'Activos', concluidos:'Concluidos', alerta:'Con alerta'};
  const list = filteredCases();
  return `
  <div class="filters" style="justify-content:space-between; display:flex; flex-wrap:wrap; gap:10px;">
    <div class="filters" style="margin:0;">
      ${statuses.map(s=>`<div class="chip ${FILTER_STATUS===s?'active':''}" data-s="${escapeHTML(s)}">${chipLabel[s] || capitalize(s)}</div>`).join("")}
    </div>
    <button class="btn" id="nuevoExpedienteBtn">+ Nuevo expediente</button>
  </div>
  <div class="panel">
    <div class="panel-head"><h3>Listado de asuntos</h3><span class="count">${list.length} de ${visibleCases().length}</span></div>
    <div class="panel-body" style="padding:0; overflow-x:auto;">
      <table style="min-width:1000px;">
        <thead><tr><th>Actor</th><th>Demandado</th><th>Expediente</th><th>Instancia</th><th>Socio</th><th>Estatus</th><th>Activo</th><th>Alerta / Amparo</th></tr></thead>
        <tbody>
          ${list.map(k=>{
            const p = computePrescripcion(k);
            const meta = getMeta(k.id);
            return `<tr data-id="${k.id}">
              <td><strong>${escapeHTML(k.actor)}</strong><div style="font-size:11px;color:var(--gray)">${escapeHTML(k.puesto||'')}</div></td>
              <td>${escapeHTML(truncate(k.demandado,32))}</td>
              <td class="exp-code">${escapeHTML(k.exp||'—')}</td>
              <td>${escapeHTML(truncate(k.junta || k.instancia || '', 26))}</td>
              <td class="abogado-tag">${escapeHTML(truncate(assignedLawyer(k),18))}</td>
              <td>${statusBadge(k)}</td>
              <td>${estaConcluido(k) ? '<span class="badge closed">Concluido</span>' : '<span class="badge ok">Activo</span>'}</td>
              <td style="white-space:nowrap;">${ p && !isCaseClosed(k.status) ? (p.daysLeft+' d.') : '—'} ${meta.amparo_activo? '<span class="badge interrumpida">Amparo</span>' : ''}</td>
            </tr>`;
          }).join("") || `<tr><td colspan="8" class="empty">Sin resultados para el filtro/búsqueda actual.</td></tr>`}
        </tbody>
      </table>
    </div>
  </div>`;
}
function capitalize(s){ return s? s.charAt(0).toUpperCase()+s.slice(1) : s; }

function alertasHTML(){
  const all = visibleCases();
  const list = all.filter(isPrescripcionRelevant)
    .map(k=>({k, p:computePrescripcion(k), lvl:alertLevel(k)}))
    .filter(x=>x.p)
    .sort((a,b)=>a.p.daysLeft-b.p.daysLeft);
  const crit = list.filter(x=>x.lvl==='crit');
  const warn = list.filter(x=>x.lvl==='warn');
  const ok = list.filter(x=>x.lvl==='ok');
  const excluidosJuicio = all.filter(k=>caseStage(k)==='juicio');
  const excluidosConvenio = all.filter(k=>caseStage(k)==='convenio');
  return `
  <div class="panel"><div class="panel-head"><h3>&#128308; Críticas — 10 días o menos</h3><span class="count">${crit.length}</span></div>
    <div class="panel-body">${crit.length? crit.map(x=>alertRowHTML(x.k,x.p,x.lvl)).join(""): '<div class="empty">Sin alertas críticas.</div>'}</div></div>
  <div class="panel"><div class="panel-head"><h3>&#128992; Próximas — 11 a 30 días</h3><span class="count">${warn.length}</span></div>
    <div class="panel-body">${warn.length? warn.map(x=>alertRowHTML(x.k,x.p,x.lvl)).join(""): '<div class="empty">Sin asuntos en este rango.</div>'}</div></div>
  <div class="panel"><div class="panel-head"><h3>&#128994; Con margen — más de 30 días</h3><span class="count">${ok.length}</span></div>
    <div class="panel-body">${ok.length? ok.map(x=>alertRowHTML(x.k,x.p,x.lvl)).join(""): '<div class="empty">Sin asuntos en este rango.</div>'}</div></div>
  <div class="panel"><div class="panel-head"><h3>Sin riesgo de prescripción (ya superada)</h3><span class="count">${excluidosJuicio.length + excluidosConvenio.length}</span></div>
    <div class="panel-body">
      ${[...excluidosJuicio, ...excluidosConvenio].map(k=>`
        <div class="alert-row" data-id="${k.id}">
          <div class="alert-info">
            <div class="name">${escapeHTML(k.actor)} <span style="color:var(--gray); font-weight:400;">vs</span> ${escapeHTML(truncate(k.demandado,36))}</div>
            <div class="meta">${caseStage(k)==='juicio' ? 'Demanda ya presentada — prescripción interrumpida (Art. 521-I LFT)' : 'Convenio alcanzado — ya no aplica el plazo de dos meses'}</div>
          </div>
          ${statusBadge(k)}
        </div>`).join("") || '<div class="empty">—</div>'}
    </div>
  </div>
  <div class="notice" style="max-width:100%;">Herramienta de apoyo interno. El cómputo se basa en el Art. 518 de la LFT y en el criterio de la Segunda Sala de la SCJN sobre meses de calendario completos (suspensión durante conciliación, Art. 521 fracc. III). Los asuntos con demanda ya presentada o con convenio alcanzado no se incluyen porque la prescripción del Art. 518 ya quedó interrumpida o superada. No sustituye la verificación manual del calendario oficial de cada Tribunal o Centro de Conciliación, incluyendo días inhábiles específicos.</div>
  `;
}

const HONORARIO_PCT = 0.30;

function conceptoRow(nombre, fundamento, monto, destacado){
  if(monto===null || monto===undefined) return '';
  return `<div class="crow${destacado?' crow-total':''}">
    <div><div class="cname">${escapeHTML(nombre)}</div><div class="cfund">${escapeHTML(fundamento)}</div></div>
    <div class="camt">${fmtMoney(monto)}</div>
  </div>`;
}

function conceptoRowNum(nombre, fundamento, numero, destacado){
  if(numero===null || numero===undefined) return '';
  return `<div class="crow${destacado?' crow-total':''}">
    <div><div class="cname">${escapeHTML(nombre)}</div><div class="cfund">${escapeHTML(fundamento)}</div></div>
    <div class="camt">${numero}</div>
  </div>`;
}

function abrirReporteEjecutivoPDF(){
  const list = visibleCases();
  const activos = list.filter(k=>!estaConcluido(k));
  const enJuicio = list.filter(k=>caseStage(k)==='juicio');
  const conConvenioPend = list.filter(k=>tieneConvenio(k) && caseStage(k)!=='pagado');
  const pagados = list.filter(k=>caseStage(k)==='pagado');
  const conv = convenioSummary();

  const conResultado = list.map(k=>({k, r:resultadoAsunto(k)})).filter(x=>x.r);
  const exitosos = conResultado.filter(x=> RESULTADO_LABEL[x.r].exito === true).length;
  const noExitosos = conResultado.filter(x=> RESULTADO_LABEL[x.r].exito === false).length;
  const baseExito = exitosos + noExitosos;
  const tasaExito = baseExito>0 ? (exitosos/baseExito*100) : null;

  const rango = rangoPeriodo('mes_actual');
  const pagosDelMes = todosLosPagosCobrados().filter(p=> p.fecha >= rango.desde && p.fecha <= rango.hasta);
  const totalMes = pagosDelMes.reduce((s,p)=> s + (p.monto||0), 0);

  const porAbogado = {};
  list.forEach(k=>{
    const n = assignedLawyer(k);
    if(!porAbogado[n]) porAbogado[n] = {asuntos:0, cobradoMes:0};
    porAbogado[n].asuntos++;
  });
  pagosDelMes.forEach(p=>{
    const n = assignedLawyer(p.k);
    if(!porAbogado[n]) porAbogado[n] = {asuntos:0, cobradoMes:0};
    porAbogado[n].cobradoMes += (p.monto||0);
  });

  const grupos = {};
  list.forEach(k=>{
    const key = normalizarDemandado(k.demandado);
    if(!key) return;
    if(!grupos[key]) grupos[key] = {nombre:k.demandado, count:0};
    grupos[key].count++;
  });
  const topDemandados = Object.values(grupos).filter(g=>g.count>1).sort((a,b)=>b.count-a.count).slice(0,5);

  let filas = '';
  filas += `<div class="csection">RESUMEN GENERAL</div>`;
  filas += conceptoRowNum('Asuntos activos', 'Todos los que no han concluido', activos.length);
  filas += conceptoRowNum('En juicio (demanda presentada)', '—', enJuicio.length);
  filas += conceptoRowNum('Con convenio pendiente de cobro', '—', conConvenioPend.length);
  filas += conceptoRowNum('Concluidos / pagados', '—', pagados.length);

  filas += `<div class="csection">INGRESOS DEL MES ACTUAL (${fmtDate(rango.desde)} – ${fmtDate(rango.hasta)})</div>`;
  filas += conceptoRow('Total cobrado este mes', pagosDelMes.length+' pago(s)', totalMes, true);
  filas += conceptoRow('Honorario del despacho (30%)', '—', totalMes*HONORARIO_PCT);

  filas += `<div class="csection">POR ABOGADO</div>`;
  Object.keys(porAbogado).sort((a,b)=>porAbogado[b].asuntos-porAbogado[a].asuntos).forEach(n=>{
    filas += conceptoRow(n + ' — ' + porAbogado[n].asuntos + ' asunto(s)', 'Cobrado este mes', porAbogado[n].cobradoMes);
  });

  filas += `<div class="csection">TASA DE ÉXITO</div>`;
  filas += conceptoRowNum('Tasa de éxito (convenios + sentencias favorables)', baseExito+' asunto(s) con resultado a favor o en contra', tasaExito!=null? tasaExito.toFixed(0)+'%' : '—', true);
  filas += conceptoRowNum('Asuntos con resultado registrado', 'De ' + list.length + ' en total', conResultado.length);

  if(topDemandados.length){
  filas += `<div class="csection">EMPRESAS DEMANDADAS MÁS FRECUENTES</div>`;
    topDemandados.forEach(g=>{
      filas += conceptoRowNum(g.nombre, 'Asuntos con esta empresa', g.count);
    });
  }

  const hoy = fmtDate(dateToISO(new Date()));
  const html = `<!DOCTYPE html><html lang="es-MX"><head><meta charset="utf-8">
  <title>Reporte ejecutivo — ${hoy}</title>
  <style>
    @media print{ @page{ margin:0; } body{ -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
    *{box-sizing:border-box;}
    body{font-family:'Segoe UI', Arial, sans-serif; margin:0; padding:0; background:#f6f3ec; color:#16233d;}
    .sheet{max-width:720px; margin:24px auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 4px 18px rgba(0,0,0,.12);}
    .band{background:linear-gradient(105deg,#16233d 0%,#1e3358 70%,#24406b 100%); border-bottom:3px solid #a9814a; padding:26px 30px 22px;}
    .band img{max-width:190px; height:auto; display:block; margin-bottom:14px;}
    .band .kicker{font-family:'Courier New',monospace; font-size:10px; letter-spacing:.14em; color:#cdb27a; margin-bottom:6px;}
    .band .title{font-size:21px; font-weight:600; color:#fff;}
    .meta{padding:18px 30px 4px; display:flex; justify-content:space-between; font-size:11px; color:#68707e; letter-spacing:.04em;}
    .content{padding:6px 30px 26px;}
    .csection{font-family:'Courier New',monospace; font-size:10px; letter-spacing:.1em; color:#68707e; border-bottom:1px solid #e3dccb; padding:16px 0 5px; margin-top:6px; text-transform:uppercase;}
    .crow{display:flex; justify-content:space-between; align-items:baseline; padding:10px 0; border-bottom:1px dotted #c9c2ab;}
    .cname{font-size:13.5px; color:#16233d;}
    .cfund{font-family:'Courier New',monospace; font-size:10px; color:#68707e; margin-top:2px;}
    .camt{font-size:14px; font-weight:600; color:#16233d; white-space:nowrap; padding-left:14px;}
    .crow-total{background:#f6f3ec; margin:4px -14px 0; padding:12px 14px; border-radius:6px; border-bottom:none;}
    .crow-total .cname{font-weight:700;}
    .crow-total .camt{color:#a9814a; font-size:16px;}
    .foot{padding:16px 30px 26px; font-size:10.5px; color:#68707e; line-height:1.5; border-top:1px solid #e3dccb;}
    .printbar{max-width:720px; margin:14px auto; text-align:right;}
    .printbar button{background:#16233d; color:#fff; border:none; padding:10px 18px; border-radius:8px; font-size:13px; cursor:pointer;}
    @media print{ .printbar{display:none;} body{background:#fff;} .sheet{box-shadow:none; margin:0; max-width:100%; border-radius:0;} }
  </style></head>
  <body>
    <div class="printbar"><button onclick="window.print()">Imprimir / Guardar como PDF</button></div>
    <div class="sheet">
      <div class="band">
        <img src="data:image/png;base64,${LOGO_DESPACHO_B64}" alt="Expertos Laborales">
        <div class="kicker">— REPORTE EJECUTIVO INTERNO</div>
        <div class="title">Panorama del despacho</div>
      </div>
      <div class="meta"><span>${escapeHTML(CURRENT_USER.name)}</span><span>${hoy}</span></div>
      <div class="content">${filas}</div>
      <div class="foot">Generado automáticamente a partir de los asuntos visibles para tu usuario en el sistema. Los montos de convenios provienen de las notas y pagos capturados; verifica cifras importantes antes de usarlas en una presentación externa.</div>
    </div>
  </body></html>`;
  const w = window.open('', '_blank');
  if(w){ w.document.write(html); w.document.close(); }
}

function abrirCalculoPDF(k){
  const liq = computeLiquidacion(k);
  if(!liq){
    alert('Falta fecha de ingreso, fecha de baja o salario diario para poder calcular la liquidación. Captúralos en "Editar datos".');
    return;
  }
  const sc = computeSalariosCaidos(k);
  const hoy = fmtDate(dateToISO(new Date()));
  const totalGeneral90 = liq.total90 + (sc? sc.total : 0);

  let filas = '';
  filas += `<div class="csection">DATOS DEL EXPEDIENTE</div>`;
  filas += conceptoRowNum('Fecha de ingreso', '—', fmtDate(k.fecha_ingreso));
  filas += conceptoRowNum('Fecha de baja', '—', fmtDate(k.fecha_baja));
  filas += conceptoRowNum('Salario diario', '—', fmtMoney(k.salario_diario));
  filas += conceptoRowNum('Salario diario integrado (SDI)', liq.sdiReal ? '—' : 'Aproximado — no capturado, se usó el salario diario', fmtMoney(liq.sdiUsado));

  filas += `<div class="csection">INDEMNIZACIÓN CONSTITUCIONAL — ART. 48 Y 50 LFT</div>`;
  filas += conceptoRow('Indemnización constitucional (90 días — 3 meses de salario)', 'Art. 48 y 50, fracc. II, LFT', liq.indemnizacion90);

  if(liq.diasDevengados > 0){
    filas += `<div class="csection">SALARIOS DEVENGADOS PENDIENTES DE PAGO</div>`;
    filas += conceptoRow('Salarios devengados pendientes (' + liq.diasDevengados + ' día(s))', 'Art. 82 LFT' + (liq.devengadosPosibleprescripcion? ' — parte podría estar prescrita, Art. 516':''), liq.salariosDevengadosMonto);
  }

  filas += `<div class="csection">PRESTACIONES PROPORCIONALES</div>`;
  filas += conceptoRow('Prima de antigüedad (12 días × ' + liq.aniosDec.toFixed(2) + ' años' + (liq.primaTopada? ', topada a 2× salario mínimo':'') + ')', 'Art. 162 LFT', liq.primaAntiguedad);
  filas += conceptoRow('Vacaciones (' + liq.vacacionesDiasProp.toFixed(2) + ' días del periodo en curso' + (liq.vacAntDias>0? ' + '+liq.vacAntDias+' de periodos anteriores':'') + ')', 'Art. 76-79 LFT' + (liq.vacAntDias>0? ' y 516':''), liq.vacacionesMonto);
  filas += conceptoRow('Prima vacacional (' + liq.primaVacacionalPct + '% sobre vacaciones' + (liq.primaVacacionalPct>25? ' — pactada por contrato':'') + ')', 'Art. 80 LFT', liq.primaVacacionalMonto);
  filas += conceptoRow('Aguinaldo proporcional (' + liq.aguinaldoDias.toFixed(2) + ' días' + (liq.aguinaldoDiasBase!==15? ', '+liq.aguinaldoDiasBase+' días pactados':'') + ')', 'Art. 87 LFT', liq.aguinaldoMonto);

  if(liq.prestacionesExtra.length){
    filas += `<div class="csection">PRESTACIONES ADICIONALES (CAPTURADAS A MANO)</div>`;
    liq.prestacionesExtra.forEach(pr=>{
      filas += conceptoRow(pr.concepto, 'Capturado manualmente', parseFloat(pr.monto) || 0);
    });
  }

  if(sc){
    filas += `<div class="csection">SALARIOS CAÍDOS E INTERESES — ART. 48 LFT${!sc.sdiReal? ' (aprox., sin salario integrado capturado)':''}${sc.sdiSospechoso? ' (aprox., dato de salario integrado inconsistente)':''}</div>`;
    filas += conceptoRow('Salarios caídos (' + sc.diasCaidos + ' días desde el despido)', 'Art. 48 LFT, párr. 2 — tope de 12 meses', sc.salariosCaidos);
    if(sc.excedioTope){
      filas += conceptoRow('Intereses (2% mensual capitalizable, ' + sc.mesesIntereses + ' mes(es) sobre 15 meses de salario)', 'Art. 48 LFT, párr. 3', sc.intereses);
    }
    filas += conceptoRow('Total salarios caídos + intereses', 'Art. 48 LFT', sc.total, true);
  }

  filas += `<div class="csection">TOTAL DEL CÁLCULO</div>`;
  filas += conceptoRow('Total general' + (sc?' (indemnización + salarios caídos + intereses)':''), 'Suma de los conceptos anteriores', totalGeneral90, true);

  const html = `<!DOCTYPE html><html lang="es-MX"><head><meta charset="utf-8">
  <title>Cálculo — ${escapeHTML(k.actor||'')}</title>
  <style>
    @media print{ @page{ margin:0; } body{ -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
    *{box-sizing:border-box;}
    body{font-family:'Segoe UI', Arial, sans-serif; margin:0; padding:0; background:#f6f3ec; color:#16233d;}
    .sheet{max-width:720px; margin:24px auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 4px 18px rgba(0,0,0,.12);}
    .band{background:linear-gradient(105deg,#16233d 0%,#1e3358 70%,#24406b 100%); border-bottom:3px solid #a9814a; padding:26px 30px 22px;}
    .band img{max-width:190px; height:auto; display:block; margin-bottom:14px;}
    .band .kicker{font-family:'Courier New',monospace; font-size:10px; letter-spacing:.14em; color:#cdb27a; margin-bottom:6px;}
    .band .title{font-size:21px; font-weight:600; color:#fff;}
    .meta{padding:18px 30px 4px; display:flex; justify-content:space-between; font-size:11px; color:#68707e; letter-spacing:.04em;}
    .content{padding:6px 30px 26px;}
    .csection{font-family:'Courier New',monospace; font-size:10px; letter-spacing:.1em; color:#68707e; border-bottom:1px solid #e3dccb; padding:16px 0 5px; margin-top:6px;}
    .crow{display:flex; justify-content:space-between; align-items:baseline; padding:10px 0; border-bottom:1px dotted #c9c2ab;}
    .cname{font-size:13.5px; color:#16233d;}
    .cfund{font-family:'Courier New',monospace; font-size:10px; color:#68707e; margin-top:2px;}
    .camt{font-size:14px; font-weight:600; color:#16233d; white-space:nowrap; padding-left:14px;}
    .crow-total{background:#f6f3ec; margin:4px -14px 0; padding:12px 14px; border-radius:6px; border-bottom:none;}
    .crow-total .cname{font-weight:700;}
    .crow-total .camt{color:#a9814a; font-size:16px;}
    .foot{padding:16px 30px 26px; font-size:10.5px; color:#68707e; line-height:1.5; border-top:1px solid #e3dccb;}
    .printbar{max-width:720px; margin:14px auto; text-align:right;}
    .printbar button{background:#16233d; color:#fff; border:none; padding:10px 18px; border-radius:8px; font-size:13px; cursor:pointer;}
    @media print{ .printbar{display:none;} body{background:#fff;} .sheet{box-shadow:none; margin:0; max-width:100%; border-radius:0;} }
  </style></head>
  <body>
    <div class="printbar"><button onclick="window.print()">Imprimir / Guardar como PDF</button></div>
    <div class="sheet">
      <div class="band">
        <img src="data:image/png;base64,${LOGO_DESPACHO_B64}" alt="Expertos Laborales">
        <div class="kicker">— LEY FEDERAL DEL TRABAJO · VIGENTE</div>
        <div class="title">Cálculo de liquidación</div>
      </div>
      <div class="meta"><span>${escapeHTML(k.actor||'')} vs ${escapeHTML(k.demandado||'')}</span><span>${hoy}</span></div>
      <div class="content">${filas}</div>
      <div class="foot">Estimación de apoyo interno del despacho, elaborada con los datos capturados del expediente ${escapeHTML(k.exp||'')}. No constituye una liquidación definitiva ni sustituye la verificación del cálculo exacto conforme al criterio del Tribunal o Centro de Conciliación correspondiente.</div>
    </div>
  </body></html>`;
  const w = window.open('', '_blank');
  if(w){ w.document.write(html); w.document.close(); }
}

let INGRESOS_PERIODO = 'mes_actual';
let INGRESOS_ABOGADO = 'todos';
let INGRESOS_DESDE = '';
let INGRESOS_HASTA = '';

function rangoPeriodo(periodo, desdeCustom, hastaCustom){
  const hoy = new Date(); hoy.setHours(0,0,0,0);
  const y = hoy.getFullYear(), m = hoy.getMonth();
  const pad = n=>String(n).padStart(2,'0');
  if(periodo==='mes_actual'){
    return {desde: `${y}-${pad(m+1)}-01`, hasta: dateToISO(new Date(y, m+1, 0))};
  }
  if(periodo==='mes_anterior'){
    const d = new Date(y, m-1, 1);
    return {desde: dateToISO(d), hasta: dateToISO(new Date(d.getFullYear(), d.getMonth()+1, 0))};
  }
  if(periodo==='anio_actual'){
    return {desde: `${y}-01-01`, hasta: `${y}-12-31`};
  }
  if(periodo==='personalizado'){
    return {desde: (desdeCustom ?? INGRESOS_DESDE) || '0000-01-01', hasta: (hastaCustom ?? INGRESOS_HASTA) || '9999-12-31'};
  }
  return {desde:'0000-01-01', hasta:'9999-12-31'}; // 'todo'
}

// Recolecta todos los pagos marcados como cobrados de los asuntos visibles
// para el usuario actual, con referencia al caso al que pertenecen.
function todosLosPagosCobrados(){
  const out = [];
  visibleCases().filter(tieneConvenio).forEach(k=>{
    const meta = getMeta(k.id);
    (meta.pagos||[]).forEach(p=>{
      if(p.cobrado){
        const fecha = p.fecha_cobro || p.fecha;
        const monto = p.monto && !isNaN(parseFloat(p.monto)) ? parseFloat(p.monto) : null;
        if(fecha) out.push({fecha, monto, k});
      }
    });
  });
  return out;
}

function normalizarDemandado(nombre){
  return (nombre||'').trim().replace(/\s+/g,' ').toUpperCase();
}

function demandadosHTML(){
  const list = visibleCases();
  const grupos = {};
  list.forEach(k=>{
    const key = normalizarDemandado(k.demandado);
    if(!key) return;
    if(!grupos[key]) grupos[key] = {nombreOriginal: k.demandado, casos:[], conConvenio:[], domicilio:''};
    grupos[key].casos.push(k);
    if(tieneConvenio(k)) grupos[key].conConvenio.push(k);
    if(!grupos[key].domicilio && k.dom_demandado) grupos[key].domicilio = k.dom_demandado;
  });

  const filas = Object.values(grupos).map(g=>{
    const montos = g.conConvenio.map(k=>montoConvenio(k)).filter(m=>m!=null);
    const totalConvenios = montos.reduce((s,m)=>s+m,0);
    const promedio = montos.length ? totalConvenios/montos.length : null;
    const activos = g.casos.filter(k=>!estaConcluido(k)).length;
    return {...g, totalConvenios, promedio, activos, count: g.casos.length};
  }).sort((a,b)=> b.count - a.count);

  const recurrentes = filas.filter(f=>f.count > 1);

  return `
  <div class="notice" style="margin-bottom:16px;">Agrupa los asuntos por empresa demandada — útil para detectar patrones: quién litiga seguido contra ustedes, cuánto suele pagar en convenio, y con qué frecuencia. Ordenado de la empresa con más asuntos a la que menos.</div>
  <div class="stat-grid" style="grid-template-columns:repeat(3,1fr);">
    <div class="stat-card"><div class="bar"></div><div class="num">${filas.length}</div><div class="label">Empresas demandadas distintas</div></div>
    <div class="stat-card warn"><div class="bar"></div><div class="num">${recurrentes.length}</div><div class="label">Con más de un asunto (recurrentes)</div></div>
    <div class="stat-card ok"><div class="bar"></div><div class="num">${fmtMoney(filas.reduce((s,f)=>s+f.totalConvenios,0))}</div><div class="label">Total histórico en convenios</div></div>
  </div>
  <div class="panel">
    <div class="panel-head"><h3>Empresas demandadas</h3><span class="count">${filas.length}</span></div>
    <div class="panel-body" style="padding:0;">
      <table>
        <thead><tr><th>Empresa</th><th>Asuntos</th><th>Activos</th><th>Con convenio</th><th>Total en convenios</th><th>Promedio por convenio</th></tr></thead>
        <tbody>${filas.map(f=>`
          <tr data-demandado="${escapeHTML(f.nombreOriginal)}">
            <td><strong>${escapeHTML(truncate(f.nombreOriginal,50))}</strong>${f.domicilio? `<div style="font-size:11px; color:var(--gray);">${escapeHTML(truncate(f.domicilio,60))}</div>`:''}</td>
            <td>${f.count===1?'1':`<span class="badge ${f.count>=3?'crit':'warn'}">${f.count}</span>`}</td>
            <td>${f.activos}</td>
            <td>${f.conConvenio.length}</td>
            <td><strong>${fmtMoney(f.totalConvenios)}</strong></td>
            <td>${f.promedio!=null? fmtMoney(f.promedio) : '—'}</td>
          </tr>`).join("") || `<tr><td colspan="6" class="empty">Sin datos suficientes.</td></tr>`}</tbody>
      </table>
    </div>
  </div>
  `;
}

function ingresosHTML(){
  const rango = rangoPeriodo(INGRESOS_PERIODO);
  const isAdmin = CURRENT_USER.role === 'Administrador';
  let todos = todosLosPagosCobrados();
  if(isAdmin && INGRESOS_ABOGADO !== 'todos'){
    todos = todos.filter(p => assignedLawyer(p.k) === INGRESOS_ABOGADO);
  }
  const enPeriodo = todos.filter(p => p.fecha >= rango.desde && p.fecha <= rango.hasta).sort((a,b)=> b.fecha.localeCompare(a.fecha));

  const totalPeriodo = enPeriodo.reduce((s,p)=> s + (p.monto||0), 0);
  const conMontoCount = enPeriodo.filter(p=>p.monto!=null).length;
  const sinMontoCount = enPeriodo.length - conMontoCount;

  // desglose mensual de todo el historico, para ver tendencia
  const porMes = {};
  todos.forEach(p=>{
    const ym = p.fecha.slice(0,7);
    porMes[ym] = (porMes[ym]||0) + (p.monto||0);
  });
  const mesesOrdenados = Object.keys(porMes).sort().reverse();

  const chips = [
    ['mes_actual','Este mes'], ['mes_anterior','Mes anterior'], ['anio_actual','Este año'],
    ['todo','Todo el histórico'], ['personalizado','Personalizado']
  ];

  return `
  <div class="filters" style="margin-bottom:16px; display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px;">
    <div class="filters" style="margin:0;">
      ${chips.map(([v,label])=>`<div class="chip ${INGRESOS_PERIODO===v?'active':''}" data-periodo="${v}">${label}</div>`).join("")}
    </div>
    ${isAdmin ? `
    <div class="field" style="margin:0; min-width:220px;">
      <label>Socio</label>
      <select id="ingresosAbogadoSelect" style="width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:8px; background:var(--parchment); font-size:12.5px;">
        <option value="todos" ${INGRESOS_ABOGADO==='todos'?'selected':''}>Todos los socios</option>
        ${EQUIPO.map(u=>`<option value="${escapeHTML(u.name)}" ${INGRESOS_ABOGADO===u.name?'selected':''}>${escapeHTML(u.name)}</option>`).join("")}
      </select>
    </div>` : ''}
  </div>
  ${INGRESOS_PERIODO==='personalizado' ? `
  <div style="display:flex; gap:12px; align-items:end; margin-bottom:16px;">
    <div class="field"><label>Desde</label><input type="date" id="ingresosDesde" value="${INGRESOS_DESDE}"></div>
    <div class="field"><label>Hasta</label><input type="date" id="ingresosHasta" value="${INGRESOS_HASTA}"></div>
    <button class="btn" id="aplicarPeriodoBtn">Aplicar</button>
  </div>` : ''}

  <div class="stat-grid" style="grid-template-columns:repeat(3,1fr);">
    <div class="stat-card ok"><div class="bar"></div><div class="num">${fmtMoney(totalPeriodo)}</div><div class="label">Cobrado en el periodo${isAdmin && INGRESOS_ABOGADO!=='todos'? ' por '+escapeHTML(INGRESOS_ABOGADO):''} (${fmtDate(rango.desde)} – ${fmtDate(rango.hasta)})</div></div>
    <div class="stat-card"><div class="bar"></div><div class="num">${enPeriodo.length}</div><div class="label">Pagos cobrados en el periodo</div></div>
    <div class="stat-card"><div class="bar" style="background:var(--brass);"></div><div class="num">${fmtMoney(totalPeriodo*HONORARIO_PCT)}</div><div class="label">Honorario del despacho (30%)</div></div>
  </div>
  ${sinMontoCount ? `<div class="notice" style="margin-bottom:16px;">${sinMontoCount} pago(s) cobrado(s) en este periodo no tienen un monto capturado y no se incluyen en el total. Captúralos en la pestaña "Cobros" de cada expediente para que el resumen sea exacto.</div>` : ''}

  <div class="panel">
    <div class="panel-head"><h3>Cobrado por socio en el periodo</h3><span class="count">${fmtDate(rango.desde)} – ${fmtDate(rango.hasta)}</span></div>
    <div class="panel-body" style="padding:0;">
      <table><thead><tr><th>Socio</th><th>Pagos cobrados</th><th>Total cobrado</th><th>% del total del periodo</th></tr></thead>
      <tbody>${(()=>{
        const porAbogado = {};
        enPeriodo.forEach(p=>{
          const nombre = assignedLawyer(p.k);
          if(!porAbogado[nombre]) porAbogado[nombre] = {total:0, count:0};
          porAbogado[nombre].total += (p.monto||0);
          porAbogado[nombre].count += 1;
        });
        const nombres = Object.keys(porAbogado).sort((a,b)=> porAbogado[b].total - porAbogado[a].total);
        if(!nombres.length) return `<tr><td colspan="4" class="empty">Sin cobros en este periodo.</td></tr>`;
        return nombres.map(n=>{
          const d = porAbogado[n];
          const pct = totalPeriodo>0 ? (d.total/totalPeriodo*100) : 0;
          return `<tr><td><strong>${escapeHTML(n)}</strong></td><td>${d.count}</td><td style="font-weight:700;">${fmtMoney(d.total)}</td><td>${pct.toFixed(1)}%</td></tr>`;
        }).join("");
      })()}</tbody></table>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Detalle de pagos cobrados en el periodo</h3><span class="count">${enPeriodo.length}</span></div>
    <div class="panel-body" style="padding:0;">
      <table><thead><tr><th>Fecha de cobro</th><th>Actor</th><th>Demandado</th><th>Monto</th><th>Honorario (30%)</th><th>Socio</th></tr></thead>
      <tbody>${enPeriodo.map(p=>`
        <tr data-id="${p.k.id}">
          <td>${fmtDate(p.fecha)}</td>
          <td>${escapeHTML(p.k.actor)}</td>
          <td>${escapeHTML(truncate(p.k.demandado,34))}</td>
          <td><strong>${p.monto!=null? fmtMoney(p.monto) : 'sin monto capturado'}</strong></td>
          <td style="color:var(--brass); font-weight:600;">${p.monto!=null? fmtMoney(p.monto*HONORARIO_PCT) : '—'}</td>
          <td class="abogado-tag">${escapeHTML(assignedLawyer(p.k))}</td>
        </tr>`).join("") || `<tr><td colspan="6" class="empty">Sin pagos cobrados en este periodo.</td></tr>`}</tbody></table>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Resumen mensual (histórico completo)</h3><span class="count">${mesesOrdenados.length} mes(es)</span></div>
    <div class="panel-body" style="padding:0;">
      <table><thead><tr><th>Mes</th><th>Total cobrado</th><th>Honorario (30%)</th></tr></thead>
      <tbody>${mesesOrdenados.map(ym=>{
        const [y,m] = ym.split('-');
        const nombreMes = MESES_ES[parseInt(m)-1];
        return `<tr><td>${capitalize(nombreMes)} ${y}</td><td><strong>${fmtMoney(porMes[ym])}</strong></td><td style="color:var(--brass);">${fmtMoney(porMes[ym]*HONORARIO_PCT)}</td></tr>`;
      }).join("") || `<tr><td colspan="3" class="empty">Sin cobros registrados todavía.</td></tr>`}</tbody></table>
    </div>
  </div>
  `;
}

function cobrosHTML(){
  const conv = convenioSummary();
  const pendientes = conv.list.filter(k=>caseStage(k)!=='pagado');
  const pagados = conv.list.filter(k=>caseStage(k)==='pagado');
  return `
  <div class="stat-grid" style="grid-template-columns:repeat(4,1fr);">
    <div class="stat-card"><div class="bar"></div><div class="num">${fmtMoney(conv.total)}</div><div class="label">Total pactado en convenios (${conv.list.length})</div></div>
    <div class="stat-card ok"><div class="bar"></div><div class="num">${fmtMoney(conv.cobrado)}</div><div class="label">Cobrado / confirmado</div></div>
    <div class="stat-card warn"><div class="bar"></div><div class="num">${fmtMoney(conv.pendiente)}</div><div class="label">Pendiente de cobro</div></div>
    <div class="stat-card"><div class="bar" style="background:var(--brass);"></div><div class="num">${fmtMoney(conv.total*HONORARIO_PCT)}</div><div class="label">Honorarios (30% del total pactado)</div></div>
  </div>
  <div class="notice" style="margin-bottom:18px;">Los montos se toman de la cifra mencionada en la nota de cada asunto ("convenio por la cantidad de $X") y son un apoyo de seguimiento, no un estado de cuenta contable formal. El honorario se calcula como el 30% del monto de cada convenio — ajusta manualmente si algún caso tiene un porcentaje pactado distinto. Marca cada pago como cobrado desde la ficha del asunto (pestaña "Cobros") conforme se confirme.</div>
  <div class="panel">
    <div class="panel-head"><h3>Pendientes de cobro</h3><span class="count">${pendientes.length}</span></div>
    <div class="panel-body" style="padding:0;">
      <table><thead><tr><th>Actor</th><th>Demandado</th><th>Monto</th><th>Honorario (30%)</th><th>Fechas mencionadas</th><th>Socio</th></tr></thead>
      <tbody>${pendientes.map(k=>{ const m = montoConvenio(k); return `
        <tr data-id="${k.id}">
          <td>${escapeHTML(k.actor)}</td>
          <td>${escapeHTML(truncate(k.demandado,38))}</td>
          <td><strong>${fmtMoney(m)}</strong></td>
          <td style="color:var(--brass); font-weight:600;">${fmtMoney(m? m*HONORARIO_PCT : null)}</td>
          <td style="font-size:11.5px; color:var(--gray);">${extractFechasMencionadas(k.ultima_nota).map(fmtDate).join(', ') || '—'}</td>
          <td class="abogado-tag">${escapeHTML(assignedLawyer(k))}</td>
        </tr>`;}).join("") || `<tr><td colspan="6" class="empty">Sin convenios pendientes.</td></tr>`}</tbody></table>
    </div>
  </div>
  <div class="panel">
    <div class="panel-head"><h3>Convenios pagados</h3><span class="count">${pagados.length}</span></div>
    <div class="panel-body" style="padding:0;">
      <table><thead><tr><th>Actor</th><th>Demandado</th><th>Monto</th><th>Honorario (30%)</th><th>Socio</th></tr></thead>
      <tbody>${pagados.map(k=>{ const m = montoConvenio(k); return `
        <tr data-id="${k.id}">
          <td>${escapeHTML(k.actor)}</td>
          <td>${escapeHTML(truncate(k.demandado,38))}</td>
          <td><strong>${fmtMoney(m)}</strong></td>
          <td style="color:var(--brass); font-weight:600;">${fmtMoney(m? m*HONORARIO_PCT : null)}</td>
          <td class="abogado-tag">${escapeHTML(assignedLawyer(k))}</td>
        </tr>`;}).join("") || `<tr><td colspan="5" class="empty">Sin convenios pagados aún.</td></tr>`}</tbody></table>
    </div>
  </div>`;
}

function buildAgendaEntries(){
  const entries = [];
  const cases = visibleCases();

  // Audiencias agendadas (preliminar / de juicio)
  cases.forEach(k=>{
    const aud = proximaAudiencia(k);
    if(aud) entries.push({tipo:'audiencia', fecha: aud.fecha, k, label: aud.label, action:'pendiente_generico'});
  });

  // Pagos de convenio pendientes
  cases.filter(tieneConvenio).forEach(k=>{
    const meta = getMeta(k.id);
    let pagos = meta.pagos;
    if(!pagos || !pagos.length){
      const fechas = extractFechasMencionadas(k.ultima_nota);
      pagos = fechas.length ? fechas.map(f=>({fecha:f, monto:'', cobrado:false})) : [];
    }
    pagos.forEach((p,idx)=>{
      if(p.cobrado || !p.fecha) return;
      entries.push({
        tipo:'pago', fecha:p.fecha, k,
        label: `Pago programado — ${p.monto ? fmtMoney(parseFloat(p.monto)) : 'monto por confirmar (convenio total '+fmtMoney(montoConvenio(k))+')'}`,
        action:'pago', actionIdx: idx
      });
    });
  });

  // Vencimientos de prescripción (Título Décimo LFT, según tipo de asunto)
  cases.filter(isPrescripcionRelevant).forEach(k=>{
    const p = computePrescripcion(k);
    if(p) entries.push({tipo:'prescripcion', fecha: dateToISO(p.deadline), k, label:`Vence plazo de prescripción — ${p.plazo.articulo} (${p.daysLeft} día(s))`, action:'demanda'});
  });

  // Amparos
  cases.forEach(k=>{
    const meta = getMeta(k.id);
    if(meta.amparo_activo && meta.amparo_fecha_notif && !meta.amparo_presentado){
      const a = computeAmparoDeadline(meta);
      if(a) entries.push({tipo:'amparo', fecha: dateToISO(a.deadline), k, label:`Vence plazo para amparo directo — Art. 17 Ley de Amparo (${a.daysLeft} día(s) hábiles aprox.)`, action:'amparo'});
    }
  });

  // Pendientes / términos personalizados
  cases.forEach(k=>{
    const meta = getMeta(k.id);
    (meta.pendientes||[]).forEach((it,idx)=>{
      if(it.cumplido || !it.fecha_inicio) return;
      let fecha;
      if(it.tipo === 'fecha_fija'){ fecha = it.fecha_inicio; }
      else {
        const dias = parseInt(it.dias);
        if(!dias) return;
        const start = addDaysDate(parseDate(it.fecha_inicio),1);
        const dl = it.tipo === 'habiles' ? addBusinessDays(start, dias) : addDaysDate(start, dias-1);
        fecha = dateToISO(dl);
      }
      entries.push({tipo:'pendiente', fecha, k, label: it.descripcion || 'Pendiente sin descripción', action:'pendiente', actionIdx: idx});
    });
  });

  // Asuntos estancados (sin la siguiente etapa dentro del plazo legal esperado)
  cases.forEach(k=>{
    const est = estancadoInfo(k);
    if(est) entries.push({tipo:'atraso', fecha: dateToISO(est.expected), k, label:`Se esperaba "${est.siguiente}" y no se ha registrado (${est.diasAtraso} día(s) de atraso)`, action:'abrir_etapas'});
  });

  entries.sort((a,b)=> (a.fecha||'9999').localeCompare(b.fecha||'9999'));
  return entries;
}

const AGENDA_TIPO = {
  pago:{label:'Pago', color:'#a9814a'},
  prescripcion:{label:'Prescripción', color:'#a83a2f'},
  amparo:{label:'Amparo', color:'#3c5a86'},
  pendiente:{label:'Pendiente', color:'#3f6b4f'},
  atraso:{label:'Posible atraso', color:'#a83a2f'},
  audiencia:{label:'Audiencia', color:'#3c5a86'}
};

let AGENDA_SOCIO = 'todos';
let AGENDA_TAB = 'general';

const PROSPECTO_ESTATUS_LABEL = {nuevo:'Nuevo', contactado:'Contactado', descartado:'Descartado', convertido:'Convertido'};
const PROSPECTO_ESTATUS_BADGE = {nuevo:'crit', contactado:'warn', descartado:'closed', convertido:'ok'};
const PROSPECTO_TIPO_LABEL = {despido:'Despido (litigio)', asesoria_paga:'Asesoría $299', control_expedientes:'Control de Expedientes'};
const PROSPECTO_TIPO_BADGE = {despido:'convenio', asesoria_paga:'interrumpida', control_expedientes:'ok'};

function prospectosHTML(){
  if(!PROSPECTOS.length){
    return `<div class="panel"><div class="panel-body" style="padding:24px;">
      <div class="notice">Todavía no hay prospectos. En cuanto el asistente de WhatsApp detecte un caso de despido en CDMX/Edomex, o a alguien interesado en la asesoría de pago, aparecerá aquí.</div>
    </div></div>`;
  }
  // Por default la lista solo muestra lo pendiente por atender: los
  // "nuevo" y cualquiera con un mensaje sin leer (aunque ya esté
  // contactado o convertido, si escribió algo nuevo sigue necesitando que
  // alguien lo revise). En cuanto lo contactas, lo conviertes o lo
  // descartas (y no tiene nada sin leer) se quita solo de la lista, para
  // que no se acumulen los que ya se están atendiendo o ya se cerraron.
  // "descartado" es que el despacho no toma el litigio, no que se deje de
  // contestarle a la persona — el bot le sigue contestando solo (ver
  // prospectos_update.php), por eso también puede reaparecer si escribe.
  const esPendiente = p => p.estatus === 'nuevo' || (p.mensaje_nuevo && p.estatus !== 'descartado');
  // La casilla de búsqueda del topbar (nombre o teléfono) también aplica
  // aquí — antes solo filtraba Expedientes (ver visibleCases()). Mientras
  // se busca, se ignora el filtro de "solo pendientes": alguien que busca
  // a una persona por nombre/teléfono la quiere encontrar sin importar su
  // estatus.
  const buscando = SEARCH_TERM.trim() !== '';
  let base = PROSPECTOS.filter(p => p.tipo === PROSPECTOS_TAB);
  if(buscando){
    const q = SEARCH_TERM.trim().toLowerCase();
    base = base.filter(p => (p.nombre||'').toLowerCase().includes(q) || (p.telefono||'').toLowerCase().includes(q));
  }
  const visibles = buscando ? base : base.filter(p => PROSPECTOS_MOSTRAR_ATENDIDOS || esPendiente(p));
  const atendidosOcultos = buscando ? [] : base.filter(p => !esPendiente(p));

  const pendientesDespido = PROSPECTOS.filter(p=>p.tipo==='despido' && esPendiente(p)).length;
  const pendientesAsesoria = PROSPECTOS.filter(p=>p.tipo==='asesoria_paga' && esPendiente(p)).length;
  const pendientesControlExp = PROSPECTOS.filter(p=>p.tipo==='control_expedientes' && esPendiente(p)).length;
  const tabsHTML = `
  <div style="display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap;">
    <button class="btn ${PROSPECTOS_TAB==='despido'?'':'secondary'}" data-prospectos-tab="despido" style="padding:8px 16px;">Despido (litigio) ${pendientesDespido>0?`<span class="nav-badge">${pendientesDespido}</span>`:""}</button>
    <button class="btn ${PROSPECTOS_TAB==='asesoria_paga'?'':'secondary'}" data-prospectos-tab="asesoria_paga" style="padding:8px 16px;">Asesorías $299 ${pendientesAsesoria>0?`<span class="nav-badge">${pendientesAsesoria}</span>`:""}</button>
    <button class="btn ${PROSPECTOS_TAB==='control_expedientes'?'':'secondary'}" data-prospectos-tab="control_expedientes" style="padding:8px 16px;">Control de Expedientes ${pendientesControlExp>0?`<span class="nav-badge">${pendientesControlExp}</span>`:""}</button>
  </div>`;

  const TITULOS_TAB = {despido:'Prospectos de despido', asesoria_paga:'Prospectos de asesoría paga', control_expedientes:'Despachos interesados en Control de Expedientes'};
  return tabsHTML + `
  <div class="panel">
    <div class="panel-head"><h3>${TITULOS_TAB[PROSPECTOS_TAB]}</h3><span class="count">${visibles.length}</span></div>
    <div class="panel-body" style="padding:0;">
      ${!visibles.length ? `<div class="notice" style="margin:16px;">${buscando ? `Ningún prospecto coincide con "${escapeHTML(SEARCH_TERM.trim())}".` : 'No hay nada pendiente por atender ahora mismo.'}</div>` : visibles.map(p=>`
      <div class="alert-row" style="align-items:flex-start; cursor:pointer; ${PROSPECTO_ABIERTO===p.id?'background:var(--parchment);':(p.mensaje_nuevo?'background:#fdf3e7;':'')}" data-prospecto-abrir="${p.id}">
        <span class="badge ${PROSPECTO_ESTATUS_BADGE[p.estatus]||'warn'}" style="flex-shrink:0; margin-top:1px;">${PROSPECTO_ESTATUS_LABEL[p.estatus]||p.estatus}</span>
        <div class="alert-info">
          <div class="name" style="${p.mensaje_nuevo?'font-weight:700;':''}">${escapeHTML(p.nombre || 'Sin nombre')} <span style="color:var(--gray); font-weight:400;">&middot; ${escapeHTML(p.estado_ubicacion||'sin estado')}</span></div>
          <div class="meta" style="${p.mensaje_nuevo?'color:var(--ink); font-weight:600;':''}">${escapeHTML(p.telefono)} &middot; ${escapeHTML(truncate(p.resumen_caso||'',90))}</div>
        </div>
        <span class="badge ${PROSPECTO_TIPO_BADGE[p.tipo]||'closed'}" style="flex-shrink:0; margin-top:1px;">${PROSPECTO_TIPO_LABEL[p.tipo]||p.tipo}</span>
        <span class="badge ${p.asignado_nombre?'ok':'warn'}" style="flex-shrink:0; margin-top:1px;">${p.asignado_nombre ? escapeHTML(p.asignado_nombre) : 'Sin turnar'}</span>
        <div style="flex-shrink:0; display:flex; flex-direction:column; align-items:flex-end; gap:5px; min-width:34px;">
          <div style="font-size:11px; color:var(--gray);">${fmtFechaHora(p.ultima_actividad || p.actualizado_en)}</div>
          ${p.mensajes_sin_leer > 0 ? `<span style="background:#25D366; color:#fff; border-radius:999px; min-width:20px; height:20px; padding:0 6px; display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; line-height:1;">${p.mensajes_sin_leer > 99 ? '99+' : p.mensajes_sin_leer}</span>` : ''}
        </div>
      </div>`).join("")}
    </div>
  </div>
  ${atendidosOcultos.length ? `<div class="notice" style="max-width:100%;">${PROSPECTOS_MOSTRAR_ATENDIDOS
    ? `Mostrando también los ya atendidos, convertidos y descartados. <button class="btn secondary" data-prospectos-toggle-atendidos style="padding:4px 10px; font-size:11px; margin-left:6px;">Ocultarlos de nuevo</button>`
    : `Hay ${atendidosOcultos.length} prospecto${atendidosOcultos.length===1?'':'s'} ya contactado${atendidosOcultos.length===1?'':'s'}, convertido${atendidosOcultos.length===1?'':'s'} o descartado${atendidosOcultos.length===1?'':'s'} oculto${atendidosOcultos.length===1?'':'s'}. <button class="btn secondary" data-prospectos-toggle-atendidos style="padding:4px 10px; font-size:11px; margin-left:6px;">Mostrarlos</button>`}</div>` : ""}
  `;
}

function prospectoDetalleHTML(p){
  const isAdmin = CURRENT_USER.role === 'Administrador';
  return `
      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:14px;">
        <span class="badge ${PROSPECTO_TIPO_BADGE[p.tipo]||'closed'}">${PROSPECTO_TIPO_LABEL[p.tipo]||p.tipo}</span>
        <select data-prospecto-estatus="${p.id}" style="padding:7px 10px; border:1px solid var(--border); border-radius:8px; font-size:12px;">
          ${Object.keys(PROSPECTO_ESTATUS_LABEL).map(k=>`<option value="${k}" ${p.estatus===k?'selected':''}>${PROSPECTO_ESTATUS_LABEL[k]}</option>`).join("")}
        </select>
        <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--gray);">
          <input type="checkbox" data-prospecto-pausado="${p.id}" ${p.pausado_bot?'checked':''}> Bot pausado (seguimiento humano)
        </label>
        ${isAdmin ? `<label style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--gray);">
          Turnar a
          <select data-prospecto-asignado="${p.id}" style="padding:7px 10px; border:1px solid var(--border); border-radius:8px; font-size:12px;">
            <option value="">Sin asignar (yo lo atiendo)</option>
            ${EQUIPO.map(u=>`<option value="${u.id}" ${p.asignado_a===u.id?'selected':''}>${escapeHTML(u.name)}</option>`).join("")}
          </select>
        </label>` : `<span class="badge ok">Turnado a ti</span>`}
        ${p.expediente_id ? `<span class="badge ok">Convertido en expediente ${escapeHTML(p.expediente_exp||'')}</span>` : `<button class="btn secondary" data-prospecto-convertir="${p.id}" style="font-size:11px; padding:6px 10px;">Convertir en expediente</button>`}
      </div>
      ${p.resumen_caso ? `<div class="notice" style="margin-bottom:14px;"><strong>Resumen del caso (según el bot):</strong> ${escapeHTML(p.resumen_caso)}</div>` : ""}
      <div class="chat-scroll" style="border:1px solid var(--border); border-radius:8px; padding:12px; margin-bottom:12px; background:var(--parchment);">
        ${PROSPECTO_MENSAJES.length ? PROSPECTO_MENSAJES.map(m=>`
          <div style="margin-bottom:10px; text-align:${m.direccion==='entrante'?'left':'right'};">
            <div style="display:inline-block; max-width:80%; padding:8px 12px; border-radius:10px; font-size:13px; background:${m.direccion==='entrante'?'#fff':'var(--ink)'}; color:${m.direccion==='entrante'?'var(--ink)':'#fff'}; text-align:left;">
              ${escapeHTML(m.texto)}
              <div style="font-size:10px; opacity:.6; margin-top:3px;">${m.direccion==='saliente'?(m.respondido_por==='humano'?'Tú':'Bot'):'Cliente'} &middot; ${fmtFechaHora(m.creado_en)}</div>
            </div>
          </div>`).join("") : `<div class="notice">Sin mensajes todavía.</div>`}
      </div>
      <div style="display:flex; gap:8px;">
        <input type="text" id="prospectoRespuestaInput" placeholder="Escribe una respuesta por WhatsApp..." style="flex:1; padding:9px 11px; border:1px solid var(--border); border-radius:8px; font-size:16px;">
        <button class="btn" id="prospectoEnviarBtn" data-telefono="${escapeHTML(p.telefono)}">Enviar</button>
      </div>
  `;
}

// Abre el detalle de un prospecto en un modal (igual que en Conversaciones)
// para poder revisarlo de un vistazo sin perder el lugar en la lista.
function abrirProspectoModal(id){
  PROSPECTO_ABIERTO = id;
  renderProspectoModal();
  document.getElementById('modalOverlay').classList.add('show');
}

function cerrarProspectoModal(){
  closeModal();
  PROSPECTO_ABIERTO = null;
}

function renderProspectoModal(){
  const p = PROSPECTOS.find(x=>x.id===PROSPECTO_ABIERTO);
  if(!p) return;
  const overlay = document.getElementById('modalOverlay');
  overlay.innerHTML = `<div class="modal" style="max-width:720px;">
    <div class="modal-head">
      <button class="close" id="modalClose">&times;</button>
      <h2>${escapeHTML(p.nombre || 'Sin nombre')}</h2>
      <div class="sub">${escapeHTML(p.telefono)}</div>
    </div>
    <div class="modal-body">${prospectoDetalleHTML(p)}</div>
  </div>`;
  document.getElementById('modalClose').addEventListener('click', cerrarProspectoModal);
  overlay.addEventListener('click', (e)=>{ if(e.target===overlay) cerrarProspectoModal(); });
  bindProspectoModalEvents();
}

function bindProspectoModalEvents(){
  document.querySelectorAll('[data-prospecto-estatus]').forEach(sel=>{
    sel.addEventListener('change', async ()=>{
      try{
        await api('POST', 'prospectos_update.php', {id: parseInt(sel.dataset.prospectoEstatus), estatus: sel.value});
        await loadProspectos();
        renderViewBody();
        renderProspectoModal();
      }catch(err){ alert('No se pudo actualizar: ' + err.message); }
    });
  });
  document.querySelectorAll('[data-prospecto-pausado]').forEach(chk=>{
    chk.addEventListener('change', async ()=>{
      try{
        await api('POST', 'prospectos_update.php', {id: parseInt(chk.dataset.prospectoPausado), pausado_bot: chk.checked});
        await loadProspectos();
      }catch(err){ alert('No se pudo actualizar: ' + err.message); chk.checked = !chk.checked; }
    });
  });
  document.querySelectorAll('[data-prospecto-asignado]').forEach(sel=>{
    sel.addEventListener('change', async ()=>{
      try{
        await api('POST', 'prospectos_update.php', {id: parseInt(sel.dataset.prospectoAsignado), asignado_a: sel.value ? parseInt(sel.value) : 0});
        await loadProspectos();
        renderViewBody();
        renderProspectoModal();
      }catch(err){ alert('No se pudo turnar: ' + err.message); }
    });
  });
  document.querySelectorAll('[data-prospecto-convertir]').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      if(!confirm('¿Convertir este prospecto en expediente nuevo?')) return;
      btn.disabled = true;
      try{
        await api('POST', 'prospectos_convertir.php', {id: parseInt(btn.dataset.prospectoConvertir)});
        await Promise.all([refreshBootstrap()]);
        renderViewBody();
        renderProspectoModal();
      }catch(err){ alert('No se pudo convertir: ' + err.message); btn.disabled = false; }
    });
  });
  const prospectoEnviarBtn = document.getElementById('prospectoEnviarBtn');
  if(prospectoEnviarBtn){
    prospectoEnviarBtn.addEventListener('click', async ()=>{
      const input = document.getElementById('prospectoRespuestaInput');
      const texto = input.value.trim();
      if(!texto) return;
      const telefono = prospectoEnviarBtn.dataset.telefono;
      prospectoEnviarBtn.disabled = true;
      try{
        await api('POST', 'prospectos_enviar.php', {telefono, texto});
        await loadProspectoMensajes(telefono);
        renderProspectoModal();
      }catch(err){ alert('No se pudo enviar: ' + err.message); prospectoEnviarBtn.disabled = false; }
    });
    const prospectoRespuestaInput = document.getElementById('prospectoRespuestaInput');
    if(prospectoRespuestaInput){
      prospectoRespuestaInput.addEventListener('keydown', (e)=>{
        if(e.key === 'Enter'){ e.preventDefault(); prospectoEnviarBtn.click(); }
      });
      // Deja el chat ya abajo del todo (mensaje más reciente) en vez de
      // arriba, como en cualquier app de WhatsApp real.
      const scroll = document.querySelector('.modal .chat-scroll');
      if(scroll) scroll.scrollTop = scroll.scrollHeight;
    }
  }
}

// ---------------------------------------------------------------
// Vista "Conversaciones (WhatsApp)" (solo Administrador) — control de
// calidad del bot: todas las conversaciones, califiquen o no como
// prospecto, con estadísticas y un visor de solo lectura de cada hilo.
// ---------------------------------------------------------------
// Barra por día (últimos 14 días) de mensajes entrantes de WhatsApp, para
// comparar a simple vista los días de live contra los días normales.
const DIAS_SEMANA_CORTO = ['dom','lun','mar','mié','jué','vié','sáb'];
function actividadPorDiaHTML(porDia){
  if(!porDia.length) return '';
  const max = Math.max(1, ...porDia.map(d=>d.mensajes));
  const hoy = new Date().toISOString().slice(0,10);
  return `
  <div class="panel" style="margin-bottom:18px;">
    <div class="panel-head"><h3>Actividad de WhatsApp por día</h3><span class="count">últimos 30 días</span></div>
    <div class="panel-body" style="padding:16px 18px;">
      <div style="display:flex; align-items:flex-end; gap:4px; height:140px;">
        ${porDia.map(d=>{
          const fecha = new Date(d.dia + 'T00:00:00');
          const alto = Math.round((d.mensajes / max) * 110) + (d.mensajes>0 ? 4 : 0);
          const esHoy = d.dia === hoy;
          return `
          <div style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%;" title="${d.mensajes} mensaje(s), ${d.numeros} número(s), ${d.prospectos_nuevos} propuesta(s), ${d.prospectos_contactados} contactado(s), ${d.prospectos_convertidos} convertido(s)">
            <div style="font-size:10px; color:var(--ink); font-weight:700; margin-bottom:2px;">${d.mensajes||''}</div>
            <div style="width:100%; max-width:20px; height:${alto}px; background:${esHoy?'var(--ink)':'#b9c3d6'}; border-radius:4px 4px 0 0;"></div>
            <div style="font-size:9px; color:var(--gray); margin-top:4px; text-align:center;">${DIAS_SEMANA_CORTO[fecha.getDay()]}<br>${fecha.getDate()}</div>
          </div>`;
        }).join("")}
      </div>
      <div style="font-size:11px; color:var(--gray); margin-top:10px;">Pasa el cursor sobre cada barra para ver el detalle del día (mensajes, números distintos, propuestas, contactados y convertidos).</div>
    </div>
  </div>`;
}

// ---------- Embudo de WhatsApp por fecha (periodo elegible) ----------
let WA_EMBUDO = null;
let WA_EMBUDO_PERIODO = 'mes_actual';
let WA_EMBUDO_DESDE = '';
let WA_EMBUDO_HASTA = '';
let WA_EMBUDO_CARGANDO = false;

async function loadWhatsappEmbudo(){
  WA_EMBUDO_CARGANDO = true;
  try{
    const rango = rangoPeriodo(WA_EMBUDO_PERIODO, WA_EMBUDO_DESDE, WA_EMBUDO_HASTA);
    const d = await api('GET', 'whatsapp_embudo.php?desde=' + encodeURIComponent(rango.desde) + '&hasta=' + encodeURIComponent(rango.hasta));
    WA_EMBUDO = d;
  }catch(e){ WA_EMBUDO = null; }
  WA_EMBUDO_CARGANDO = false;
}

// Tabla con el embudo exacto por fecha: cuántos WhatsApps se atendieron,
// de esos cuántos se volvieron propuesta (prospecto nuevo), cuántos se
// contactaron y cuántos se convirtieron a clientes — "contactados" y
// "convertidos" son acumulativos (un convertido también cuenta como
// contactado), igual que un embudo normal. El periodo se elige con los
// mismos chips que "Ingresos por periodo", jalando del servidor solo el
// rango pedido (no depende de los 30 días fijos de la gráfica de arriba).
function whatsappEmbudoHTML(){
  const chips = [
    ['mes_actual','Este mes'], ['mes_anterior','Mes anterior'], ['anio_actual','Este año'],
    ['todo','Todo el histórico'], ['personalizado','Personalizado']
  ];
  const chipsHTML = `
    <div class="filters" style="margin:0 0 12px;">
      ${chips.map(([v,label])=>`<div class="chip ${WA_EMBUDO_PERIODO===v?'active':''}" data-embudo-periodo="${v}">${label}</div>`).join("")}
    </div>
    ${WA_EMBUDO_PERIODO==='personalizado' ? `
    <div style="display:flex; gap:12px; align-items:end; margin-bottom:12px;">
      <div class="field"><label>Desde</label><input type="date" id="embudoDesde" value="${WA_EMBUDO_DESDE}"></div>
      <div class="field"><label>Hasta</label><input type="date" id="embudoHasta" value="${WA_EMBUDO_HASTA}"></div>
      <button class="btn" id="embudoAplicarBtn">Aplicar</button>
    </div>` : ''}`;

  if(WA_EMBUDO_CARGANDO || !WA_EMBUDO){
    return `
    <div class="panel" style="margin-bottom:18px;">
      <div class="panel-head"><h3>Embudo de WhatsApp por fecha</h3></div>
      <div class="panel-body" style="padding:16px 18px;">
        ${chipsHTML}
        <div class="notice" style="margin:0;">Cargando...</div>
      </div>
    </div>`;
  }

  const porDia = WA_EMBUDO.por_dia || [];
  const filas = porDia.filter(d => d.numeros > 0 || d.prospectos_nuevos > 0).slice().reverse();
  const totales = porDia.reduce((acc,d)=>({
    numeros: acc.numeros + d.numeros,
    nuevos: acc.nuevos + d.prospectos_nuevos,
    contactados: acc.contactados + d.prospectos_contactados,
    convertidos: acc.convertidos + d.prospectos_convertidos,
  }), {numeros:0, nuevos:0, contactados:0, convertidos:0});
  const pct = (num, den) => den > 0 ? Math.round(num/den*100) + '%' : '—';

  return `
  <div class="panel" style="margin-bottom:18px;">
    <div class="panel-head"><h3>Embudo de WhatsApp por fecha</h3><span class="count">${fmtDate(WA_EMBUDO.desde)} – ${fmtDate(WA_EMBUDO.hasta)}</span></div>
    <div class="panel-body" style="padding:16px 18px 0;">${chipsHTML}</div>
    <div class="panel-body" style="padding:0;">
      <table><thead><tr><th>Fecha</th><th>WhatsApps atendidos</th><th>Propuestas</th><th>Contactados</th><th>Convertidos a clientes</th></tr></thead>
      <tbody>${!filas.length ? `<tr><td colspan="5" class="empty">Sin actividad en este periodo.</td></tr>` : filas.map(d=>`
        <tr>
          <td>${fmtDate(d.dia)}</td>
          <td>${d.numeros}</td>
          <td>${d.prospectos_nuevos}</td>
          <td>${d.prospectos_contactados}</td>
          <td>${d.prospectos_convertidos}</td>
        </tr>`).join("")}
      </tbody>
      ${filas.length ? `<tfoot><tr style="font-weight:700;">
        <td>Total del periodo</td>
        <td>${totales.numeros}</td>
        <td>${totales.nuevos} <span style="font-weight:400; color:var(--gray); font-size:11px;">(${pct(totales.nuevos, totales.numeros)} de atendidos)</span></td>
        <td>${totales.contactados} <span style="font-weight:400; color:var(--gray); font-size:11px;">(${pct(totales.contactados, totales.nuevos)} de propuestas)</span></td>
        <td>${totales.convertidos} <span style="font-weight:400; color:var(--gray); font-size:11px;">(${pct(totales.convertidos, totales.nuevos)} de propuestas)</span></td>
      </tr></tfoot>` : ''}
      </table>
    </div>
  </div>`;
}

function conversacionesHTML(){
  const s = CONVERSACIONES_STATS || {};
  const statsHTML = `
  <div class="stat-grid" style="grid-template-columns:repeat(5,1fr); margin-bottom:18px;">
    <div class="stat-card"><div class="bar"></div><div class="num">${s.entrantes_hoy ?? '—'}</div><div class="label">Mensajes entrantes hoy</div></div>
    <div class="stat-card"><div class="bar"></div><div class="num">${s.entrantes_semana ?? '—'}</div><div class="label">Mensajes entrantes (últimos 7 días)</div></div>
    <div class="stat-card"><div class="bar"></div><div class="num">${s.conversaciones_totales ?? '—'}</div><div class="label">Conversaciones totales (números distintos)</div></div>
    <div class="stat-card"><div class="bar"></div><div class="num">${s.conversaciones_nuevas_hoy ?? '—'}</div><div class="label">Conversaciones nuevas hoy</div></div>
    <div class="stat-card ok"><div class="bar"></div><div class="num">${s.tasa_conversion!=null? s.tasa_conversion+'%':'—'}</div><div class="label">Calificaron como prospecto (${s.total_prospectos ?? 0} de ${s.conversaciones_totales ?? 0})</div></div>
  </div>
  ${(s.sin_responder > 0 || REINTENTO_EN_PROGRESO) ? `
  <div class="notice" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:18px; margin-top:0;">
    <div>${REINTENTO_EN_PROGRESO ? escapeHTML(REINTENTO_PROGRESO_TXT) : `<strong>${s.sin_responder}</strong> conversación${s.sin_responder===1?'':'es'} se quedaron sin respuesta real (falló la IA) y ${s.sin_responder===1?'puede':'pueden'} reintentarse ahora.`}</div>
    <button class="btn" id="reintentarLoteBtn" ${REINTENTO_EN_PROGRESO?'disabled':''}>${REINTENTO_EN_PROGRESO ? 'Reintentando...' : 'Reintentar respuestas fallidas'}</button>
  </div>` : ''}
  ${actividadPorDiaHTML(s.por_dia || [])}
  ${whatsappEmbudoHTML()}`;
  if(!CONVERSACIONES.length){
    return statsHTML + `<div class="panel"><div class="panel-body" style="padding:24px;">
      <div class="notice">Todavía no hay conversaciones registradas del bot de WhatsApp.</div>
    </div></div>`;
  }
  // El buscador del topbar (nombre, teléfono, o texto del último mensaje)
  // no filtraba nada en esta vista — se conectó aquí, igual que ya
  // filtraba en Expedientes y Prospectos.
  const buscando = SEARCH_TERM.trim() !== '';
  const q = SEARCH_TERM.trim().toLowerCase();
  const lista = buscando
    ? CONVERSACIONES.filter(c =>
        (c.telefono||'').toLowerCase().includes(q) ||
        (c.prospecto_nombre||'').toLowerCase().includes(q) ||
        (c.ultimo_texto||'').toLowerCase().includes(q))
    : CONVERSACIONES;
  return statsHTML + `
  <div class="panel">
    <div class="panel-head"><h3>Todas las conversaciones</h3><span class="count">${lista.length}</span></div>
    <div class="panel-body" style="padding:0;">
      ${!lista.length ? `<div class="notice" style="margin:16px;">Ninguna conversación coincide con "${escapeHTML(SEARCH_TERM.trim())}".</div>` : lista.map(c=>{
        const calificado = !!c.prospecto_tipo;
        return `
      <div class="alert-row" style="align-items:flex-start; cursor:pointer; ${CONVERSACION_ABIERTA===c.telefono?'background:var(--parchment);':''}" data-conversacion-abrir="${escapeHTML(c.telefono)}">
        <span class="badge ${calificado ? (PROSPECTO_TIPO_BADGE[c.prospecto_tipo]||'closed') : 'warn'}" style="flex-shrink:0; margin-top:1px;">${calificado ? (PROSPECTO_TIPO_LABEL[c.prospecto_tipo]||c.prospecto_tipo) : 'Sin calificar'}</span>
        ${c.fallo ? '<span class="badge crit" style="flex-shrink:0; margin-top:1px;">Falló</span>' : ''}
        <div class="alert-info">
          <div class="name">${escapeHTML(c.prospecto_nombre || c.telefono)}</div>
          <div class="meta">${c.ultima_direccion==='entrante'?'Cliente: ':'Bot/Tú: '}${escapeHTML(truncate(c.ultimo_texto||'',90))}</div>
        </div>
        <span class="badge" style="flex-shrink:0; margin-top:1px;">${c.total_mensajes} mensaje${c.total_mensajes===1?'':'s'}</span>
        <div style="flex-shrink:0; font-size:11px; color:var(--gray); text-align:right;">${fmtFechaHora(c.ultimo_mensaje)}</div>
      </div>`;}).join("")}
    </div>
  </div>
  `;
}

// ---------------------------------------------------------------
// "Resumen ejecutivo (bot)" — análisis con IA de todas las conversaciones
// de WhatsApp (temas comunes, por qué no califican, oportunidades), para
// que el despacho (o quien lo asesore en optimizar el bot) lo lea directo
// desde el sistema en vez de tener que pedirlo por fuera. Se guarda cada
// uno generado para llevar historial.
// ---------------------------------------------------------------
// Conversión muy básica de markdown a HTML — el reporte lo redacta la IA
// con # encabezados, **negritas** y guiones de lista; aquí solo se pinta
// bien dentro del sistema (esto NO es el formato de WhatsApp, es un
// reporte interno de solo lectura).
function mdBasicoHTML(md){
  const escapado = escapeHTML(md);
  const lineas = escapado.split('\n');
  let html = '';
  let enLista = false;
  for(const linea of lineas){
    const esItem = /^\s*[-*]\s+/.test(linea);
    if(esItem && !enLista){ html += '<ul style="margin:6px 0 10px 0; padding-left:20px;">'; enLista = true; }
    if(!esItem && enLista){ html += '</ul>'; enLista = false; }
    const conNegritas = linea.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    if(/^###\s+/.test(linea)) html += `<h4 style="margin:14px 0 6px 0; font-size:13.5px;">${conNegritas.replace(/^###\s+/,'')}</h4>`;
    else if(/^##\s+/.test(linea)) html += `<h3 style="margin:16px 0 6px 0; font-size:15px; font-family:var(--serif);">${conNegritas.replace(/^##\s+/,'')}</h3>`;
    else if(/^#\s+/.test(linea)) html += `<h2 style="margin:0 0 10px 0; font-size:17px; font-family:var(--serif);">${conNegritas.replace(/^#\s+/,'')}</h2>`;
    else if(esItem) html += `<li style="font-size:13px; margin-bottom:4px;">${conNegritas.replace(/^\s*[-*]\s+/,'')}</li>`;
    else if(linea.trim()==='') html += '<div style="height:6px;"></div>';
    else html += `<div style="font-size:13px; margin-bottom:4px;">${conNegritas}</div>`;
  }
  if(enLista) html += '</ul>';
  return html;
}

function resumenEjecutivoHTML(){
  const ultimo = RESUMENES_EJECUTIVOS[0];
  return `
  <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:16px; flex-wrap:wrap;">
    <div style="font-size:12.5px; color:var(--gray);">${RESUMENES_EJECUTIVOS.length ? `Último generado: ${fmtFechaHora(ultimo.creado_en)} · ${ultimo.num_conversaciones} conversaciones` : 'Todavía no se ha generado ningún resumen.'}</div>
    <button class="btn" id="resumenEjecutivoGenerarBtn" ${RESUMEN_EJECUTIVO_GENERANDO?'disabled':''}>${RESUMEN_EJECUTIVO_GENERANDO ? 'Generando... (puede tardar ~1 min)' : 'Generar nuevo resumen'}</button>
  </div>
  ${!RESUMENES_EJECUTIVOS.length ? `<div class="panel"><div class="panel-body" style="padding:24px;"><div class="notice">Dale clic a "Generar nuevo resumen" — la IA revisa todas las conversaciones recientes y arma un reporte de temas comunes, patrones y oportunidades.</div></div></div>` : ''}
  ${RESUMENES_EJECUTIVOS.map((r,i)=>`
  <div class="panel" style="margin-bottom:16px;">
    <div class="panel-head"><h3>${i===0?'Más reciente':'Resumen anterior'} — ${fmtFechaHora(r.creado_en)}</h3>
      <button class="btn secondary" data-resumen-copiar="${r.id}" style="font-size:11px; padding:6px 10px;">Copiar texto</button>
    </div>
    <div class="panel-body" style="padding:20px 24px;">${mdBasicoHTML(r.contenido)}</div>
  </div>`).join("")}
  `;
}

function bindResumenEjecutivoEvents(){
  const generarBtn = document.getElementById('resumenEjecutivoGenerarBtn');
  if(generarBtn){
    generarBtn.addEventListener('click', async ()=>{
      RESUMEN_EJECUTIVO_GENERANDO = true;
      renderViewBody();
      try{
        await api('POST', 'resumen_ejecutivo.php', {});
        await loadResumenesEjecutivos();
      }catch(err){
        alert('No se pudo generar el resumen: ' + err.message);
      }
      RESUMEN_EJECUTIVO_GENERANDO = false;
      renderViewBody();
    });
  }
  document.querySelectorAll('[data-resumen-copiar]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = parseInt(btn.dataset.resumenCopiar);
      const r = RESUMENES_EJECUTIVOS.find(x=>x.id===id);
      if(!r) return;
      navigator.clipboard.writeText(r.contenido).then(()=>{
        const original = btn.textContent;
        btn.textContent = '¡Copiado!';
        setTimeout(()=>{ btn.textContent = original; }, 1500);
      }).catch(()=> alert('No se pudo copiar. Selecciona el texto manualmente.'));
    });
  });
}

// Arma el texto completo de una tesis (para el botón "Copiar", tanto en la
// lista como en el modal) -- listo para pegar en un escrito.
function jurisprudenciaTextoParaCopiar(registro){
  const t = ((JURISPRUDENCIA_RESULTADO && JURISPRUDENCIA_RESULTADO.tesis) || []).find(x=>x.registro_digital===registro);
  if(!t) return '';
  const meta = [t.instancia, t.epoca, t.fecha_publicacion ? ('Publicación: '+t.fecha_publicacion) : null].filter(Boolean).join(' · ');
  return `[Registro digital: ${t.registro_digital}]\n${t.rubro}\n${meta}\n\n${t.texto_completo}`;
}

function copiarTesisAlPortapapeles(registro, btn){
  const texto = jurisprudenciaTextoParaCopiar(registro);
  if(!texto) return;
  navigator.clipboard.writeText(texto).then(()=>{
    if(!btn) return;
    const original = btn.textContent;
    btn.textContent = '¡Copiado!';
    setTimeout(()=>{ btn.textContent = original; }, 1500);
  }).catch(()=> alert('No se pudo copiar. Selecciona el texto manualmente.'));
}

// La respuesta de la IA menciona cada tesis por su registro digital (se le
// pide explícitamente en el prompt) -- esta función convierte esos números,
// donde aparezcan dentro del texto ya convertido a HTML, en una liga que
// abre esa tesis directo (mismo modal que "Ver texto completo"), para no
// tener que bajar hasta el final a buscarla.
function jurisprudenciaVincularRegistros(html, listaTesis){
  let resultado = html;
  const vistos = new Set();
  (listaTesis||[]).forEach(t=>{
    const num = String(t.registro_digital);
    if(vistos.has(num)) return;
    vistos.add(num);
    const re = new RegExp('\\b' + num + '\\b', 'g');
    resultado = resultado.replace(re, `<a href="javascript:void(0)" data-jurisprudencia-ver="${num}" style="color:var(--brass-dim); font-weight:700; text-decoration:underline; cursor:pointer;" title="Ver esta tesis">${num}</a>`);
  });
  return resultado;
}

function jurisprudenciaHTML(){
  const r = JURISPRUDENCIA_RESULTADO;
  return `
  <div class="panel" style="margin-bottom:16px;">
    <div class="panel-body" style="padding:20px 24px;">
      <div class="notice" style="margin-bottom:14px;">Describe los hechos de tu caso (no hace falta que sea una pregunta) y la IA busca en la biblioteca de tesis y jurisprudencia laboral de la SCJN, compartida entre todos los despachos, cuáles de verdad aplican a ese caso concreto — citando solo tesis reales guardadas aquí, nunca inventa una.</div>
      <textarea id="jurisprudenciaPreguntaInput" placeholder="Describe los hechos de tu caso, entre más detalle mejor (ej. &quot;el trabajador fue despedido pero el patrón argumenta que ya no se presentó a trabajar, sin haberle dado ningún aviso de rescisión por escrito&quot;)..." style="width:100%; min-height:110px; padding:11px 13px; border:1px solid var(--border); border-radius:8px; font-size:14px; font-family:inherit; resize:vertical;">${escapeHTML(JURISPRUDENCIA_PREGUNTA)}</textarea>
      <div style="display:flex; justify-content:flex-end; align-items:center; gap:10px; margin-top:10px;">
        <span style="font-size:11.5px; color:var(--gray);">Ctrl+Enter para buscar</span>
        <button class="btn" id="jurisprudenciaBuscarBtn" ${JURISPRUDENCIA_CARGANDO?'disabled':''}>${JURISPRUDENCIA_CARGANDO ? 'Revisando toda la biblioteca por partes... (puede tardar 30-90s)' : 'Buscar'}</button>
      </div>
      ${JURISPRUDENCIA_ERROR ? `<div class="notice" style="margin-top:12px; border-color:var(--red); color:var(--red);">${escapeHTML(JURISPRUDENCIA_ERROR)}</div>` : ''}
    </div>
  </div>
  ${r ? `
  <div class="panel" style="margin-bottom:16px;">
    <div class="panel-head"><h3>Análisis del caso</h3></div>
    <div class="panel-body" style="padding:20px 24px;">${jurisprudenciaVincularRegistros(mdBasicoHTML(r.respuesta), r.tesis)}</div>
  </div>
  ${r.tesis.length ? `
  <div class="panel">
    <div class="panel-head"><h3>Tesis consultadas (${r.tesis.length})</h3></div>
    <div class="panel-body" style="padding:6px 24px;">
      ${r.tesis.map(t=>`
      <div style="padding:12px 0; border-bottom:1px solid var(--border);">
        <div style="font-size:13px; font-weight:600; margin-bottom:4px;">${escapeHTML(t.rubro)}</div>
        <div style="font-size:11.5px; color:var(--gray); margin-bottom:6px;">Registro digital: ${t.registro_digital} · ${escapeHTML(t.instancia||'—')} · ${escapeHTML(t.epoca||'—')}${t.fecha_publicacion?(' · Publicación: '+escapeHTML(t.fecha_publicacion)):''}</div>
        <button class="btn secondary" data-jurisprudencia-ver="${t.registro_digital}" style="font-size:11px; padding:5px 10px;">Ver texto completo</button>
        <button class="btn secondary" data-jurisprudencia-copiar="${t.registro_digital}" style="font-size:11px; padding:5px 10px;">Copiar</button>
      </div>`).join("")}
    </div>
  </div>` : ''}
  ` : ''}
  `;
}

async function buscarJurisprudencia(){
  const input = document.getElementById('jurisprudenciaPreguntaInput');
  const pregunta = (input ? input.value : JURISPRUDENCIA_PREGUNTA).trim();
  if(!pregunta) return;
  JURISPRUDENCIA_PREGUNTA = pregunta;
  JURISPRUDENCIA_CARGANDO = true;
  JURISPRUDENCIA_ERROR = '';
  renderViewBody();
  try{
    JURISPRUDENCIA_RESULTADO = await api('POST', 'jurisprudencia_buscar.php', {pregunta});
  }catch(err){
    JURISPRUDENCIA_ERROR = err.message;
    JURISPRUDENCIA_RESULTADO = null;
  }
  JURISPRUDENCIA_CARGANDO = false;
  renderViewBody();
}

function bindJurisprudenciaEvents(){
  const buscarBtn = document.getElementById('jurisprudenciaBuscarBtn');
  if(buscarBtn) buscarBtn.addEventListener('click', buscarJurisprudencia);
  const input = document.getElementById('jurisprudenciaPreguntaInput');
  if(input){
    input.addEventListener('keydown', (e)=>{
      if(e.key==='Enter' && (e.ctrlKey || e.metaKey)){ e.preventDefault(); buscarJurisprudencia(); }
    });
  }
  document.querySelectorAll('[data-jurisprudencia-ver]').forEach(btn=>{
    btn.addEventListener('click', ()=> abrirJurisprudenciaTesisModal(parseInt(btn.dataset.jurisprudenciaVer)));
  });
  document.querySelectorAll('[data-jurisprudencia-copiar]').forEach(btn=>{
    btn.addEventListener('click', ()=> copiarTesisAlPortapapeles(parseInt(btn.dataset.jurisprudenciaCopiar), btn));
  });
}

// Texto completo de una tesis consultada — en un modal, igual que el resto
// del sistema, para no perder la respuesta de la IA ni la lista de abajo.
function abrirJurisprudenciaTesisModal(registro){
  JURISPRUDENCIA_TESIS_ABIERTA = registro;
  renderJurisprudenciaTesisModal();
  document.getElementById('modalOverlay').classList.add('show');
}
function cerrarJurisprudenciaTesisModal(){
  document.getElementById('modalOverlay').classList.remove('show');
  document.getElementById('modalOverlay').innerHTML = "";
  JURISPRUDENCIA_TESIS_ABIERTA = null;
}
function renderJurisprudenciaTesisModal(){
  const t = ((JURISPRUDENCIA_RESULTADO && JURISPRUDENCIA_RESULTADO.tesis) || []).find(x=>x.registro_digital===JURISPRUDENCIA_TESIS_ABIERTA);
  if(!t) return;
  const overlay = document.getElementById('modalOverlay');
  overlay.innerHTML = `<div class="modal" style="max-width:720px;">
    <div class="modal-head">
      <button class="close" id="modalClose">&times;</button>
      <h2>${escapeHTML(t.rubro)}</h2>
      <div class="sub">Registro digital: ${t.registro_digital} · ${escapeHTML(t.instancia||'—')} · ${escapeHTML(t.epoca||'—')}</div>
    </div>
    <div class="modal-body" style="padding:20px 24px;">
      <button class="btn secondary" id="jurisprudenciaModalCopiarBtn" style="font-size:11px; padding:6px 12px; margin-bottom:14px;">Copiar tesis</button>
      <div style="white-space:pre-wrap; font-size:13px; line-height:1.5;">${escapeHTML(t.texto_completo)}</div>
    </div>
  </div>`;
  document.getElementById('modalClose').addEventListener('click', cerrarJurisprudenciaTesisModal);
  document.getElementById('jurisprudenciaModalCopiarBtn').addEventListener('click', (e)=> copiarTesisAlPortapapeles(t.registro_digital, e.target));
}

// Detalle de una conversación — se muestra en un modal (ver
// abrirConversacionModal/renderConversacionModal) para poder abrirlo con un
// clic sin importar en qué parte de la lista esté la fila, sin tener que
// bajar entre decenas de conversaciones para verlo.
function conversacionDetalleHTML(c){
  const r = CONVERSACION_RESUMEN;
  return `
      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:14px;">
        ${c.prospecto_tipo ? `<span class="badge ${PROSPECTO_TIPO_BADGE[c.prospecto_tipo]||'closed'}">${PROSPECTO_TIPO_LABEL[c.prospecto_tipo]||c.prospecto_tipo}</span>` : `<span class="badge warn">Sin calificar como prospecto</span>`}
        ${c.asignado_nombre ? `<span class="badge ok">Turnado a ${escapeHTML(c.asignado_nombre)}</span>` : ""}
        ${c.expediente_id ? `<span class="badge ok">Convertido en expediente</span>` : ""}
        ${c.fallo ? `<span class="badge crit">La IA no pudo contestar el último mensaje</span> <button class="btn secondary" id="conversacionReintentarBtn" data-telefono="${escapeHTML(c.telefono)}" style="font-size:11px; padding:5px 10px;">Reintentar esta conversación</button>` : ""}
      </div>
      <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:14px;">
        ${!c.prospecto_tipo ? `
          <button class="btn secondary" data-marcar-prospecto="despido" data-telefono="${escapeHTML(c.telefono)}" style="font-size:11px; padding:6px 12px;">Marcar como prospecto de despido</button>
          <button class="btn secondary" data-marcar-prospecto="asesoria_paga" data-telefono="${escapeHTML(c.telefono)}" style="font-size:11px; padding:6px 12px;">Marcar como asesoría de pago</button>
        ` : (!c.expediente_id && c.prospecto_estatus !== 'nuevo' ? `
          <button class="btn secondary" data-marcar-prospecto="${escapeHTML(c.prospecto_tipo)}" data-telefono="${escapeHTML(c.telefono)}" style="font-size:11px; padding:6px 12px;">Reactivar como pendiente en Prospectos</button>
        ` : "")}
      </div>
      <div style="background:var(--parchment); border-radius:8px; padding:12px 14px; margin-bottom:14px;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:6px;">
          <div style="font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:var(--gray);">Resumen (IA) — para revisar cómo va contestando el bot</div>
          <button class="btn secondary" id="conversacionResumenBtn" data-telefono="${escapeHTML(c.telefono)}" style="font-size:11px; padding:5px 10px;">${r ? 'Actualizar resumen' : 'Generar resumen'}</button>
        </div>
        <div id="conversacionResumenTexto" style="font-size:13px; color:var(--ink); white-space:pre-wrap;">${r ? escapeHTML(r.resumen) : 'Sin generar todavía. Dale clic a "Generar resumen".'}</div>
        ${r ? `<div style="font-size:10.5px; color:var(--gray); margin-top:6px;">Generado el ${fmtFechaHora(r.generado_en)} con ${r.mensajes_contados} mensaje(s)${r.mensajes_contados !== c.total_mensajes ? ' — hay mensajes nuevos desde entonces, considera actualizarlo' : ''}.</div>` : ''}
      </div>
      <div class="chat-scroll" style="border:1px solid var(--border); border-radius:8px; padding:12px; background:var(--parchment);">
        ${CONVERSACION_MENSAJES.length ? CONVERSACION_MENSAJES.map(m=>`
          <div style="margin-bottom:10px; text-align:${m.direccion==='entrante'?'left':'right'};">
            <div style="display:inline-block; max-width:80%; padding:8px 12px; border-radius:10px; font-size:13px; background:${m.direccion==='entrante'?'#fff':'var(--ink)'}; color:${m.direccion==='entrante'?'var(--ink)':'#fff'}; text-align:left;">
              ${escapeHTML(m.texto)}
              <div style="font-size:10px; opacity:.6; margin-top:3px;">${m.direccion==='saliente'?(m.respondido_por==='humano'?'Humano':'Bot'):'Cliente'} &middot; ${fmtFechaHora(m.creado_en)}</div>
            </div>
          </div>`).join("") : `<div class="notice">Sin mensajes.</div>`}
      </div>
      <div style="display:flex; gap:8px; margin-top:12px;">
        <input type="text" id="conversacionRespuestaInput" placeholder="Escribe una respuesta por WhatsApp..." style="flex:1; padding:9px 11px; border:1px solid var(--border); border-radius:8px; font-size:16px;">
        <button class="btn" id="conversacionEnviarBtn" data-telefono="${escapeHTML(c.telefono)}">Enviar</button>
      </div>
  `;
}

// Abre el detalle de una conversación en un modal (en vez de tener que
// desplazarse hasta el fondo de la lista) — así queda encima de la pantalla
// sin importar en qué fila se dio clic.
function abrirConversacionModal(telefono){
  CONVERSACION_ABIERTA = telefono;
  renderConversacionModal();
  document.getElementById('modalOverlay').classList.add('show');
}

function cerrarConversacionModal(){
  closeModal();
  CONVERSACION_ABIERTA = null;
}

function renderConversacionModal(){
  const c = CONVERSACIONES.find(x=>x.telefono===CONVERSACION_ABIERTA);
  if(!c) return;
  const overlay = document.getElementById('modalOverlay');
  overlay.innerHTML = `<div class="modal" style="max-width:720px;">
    <div class="modal-head">
      <button class="close" id="modalClose">&times;</button>
      <h2>${escapeHTML(c.prospecto_nombre || c.telefono)}</h2>
      <div class="sub">${escapeHTML(c.telefono)}</div>
    </div>
    <div class="modal-body">${conversacionDetalleHTML(c)}</div>
  </div>`;
  document.getElementById('modalClose').addEventListener('click', cerrarConversacionModal);
  overlay.addEventListener('click', (e)=>{ if(e.target===overlay) cerrarConversacionModal(); });
  bindConversacionModalEvents();
  const scroll = overlay.querySelector('.chat-scroll');
  if(scroll) scroll.scrollTop = scroll.scrollHeight;
}

function bindConversacionModalEvents(){
  const conversacionResumenBtn = document.getElementById('conversacionResumenBtn');
  if(conversacionResumenBtn){
    conversacionResumenBtn.addEventListener('click', async ()=>{
      const telefono = conversacionResumenBtn.dataset.telefono;
      conversacionResumenBtn.disabled = true;
      conversacionResumenBtn.textContent = 'Generando...';
      try{
        await api('POST', 'conversaciones_resumen.php', {telefono});
        await loadConversacionResumen(telefono);
        renderConversacionModal();
      }catch(err){
        alert('No se pudo generar el resumen: ' + err.message);
        conversacionResumenBtn.disabled = false;
        conversacionResumenBtn.textContent = CONVERSACION_RESUMEN ? 'Actualizar resumen' : 'Generar resumen';
      }
    });
  }
  document.querySelectorAll('[data-marcar-prospecto]').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const telefono = btn.dataset.telefono;
      const tipo = btn.dataset.marcarProspecto;
      const textoOriginal = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Guardando...';
      try{
        await api('POST', 'conversaciones_marcar_prospecto.php', {telefono, tipo});
        await loadConversaciones();
        renderConversacionModal();
      }catch(err){
        alert('No se pudo marcar el prospecto: ' + err.message);
        btn.disabled = false;
        btn.textContent = textoOriginal;
      }
    });
  });
  const conversacionReintentarBtn = document.getElementById('conversacionReintentarBtn');
  if(conversacionReintentarBtn){
    conversacionReintentarBtn.addEventListener('click', async ()=>{
      const telefono = conversacionReintentarBtn.dataset.telefono;
      conversacionReintentarBtn.disabled = true;
      conversacionReintentarBtn.textContent = 'Reintentando...';
      try{
        const d = await api('POST', 'conversaciones_reintentar.php', {telefono});
        if(!d.ok){ alert('No se pudo reintentar: ' + d.motivo); }
        await loadConversaciones();
        await Promise.all([loadConversacionMensajes(telefono), loadConversacionResumen(telefono)]);
        renderConversacionModal();
      }catch(err){
        alert('No se pudo reintentar: ' + err.message);
        conversacionReintentarBtn.disabled = false;
        conversacionReintentarBtn.textContent = 'Reintentar esta conversación';
      }
    });
  }
  const conversacionEnviarBtn = document.getElementById('conversacionEnviarBtn');
  if(conversacionEnviarBtn){
    conversacionEnviarBtn.addEventListener('click', async ()=>{
      const input = document.getElementById('conversacionRespuestaInput');
      const texto = input.value.trim();
      if(!texto) return;
      const telefono = conversacionEnviarBtn.dataset.telefono;
      conversacionEnviarBtn.disabled = true;
      try{
        await api('POST', 'prospectos_enviar.php', {telefono, texto});
        await loadConversacionMensajes(telefono);
        renderConversacionModal();
      }catch(err){
        alert('No se pudo enviar: ' + err.message);
        conversacionEnviarBtn.disabled = false;
      }
    });
    const conversacionRespuestaInput = document.getElementById('conversacionRespuestaInput');
    if(conversacionRespuestaInput){
      conversacionRespuestaInput.addEventListener('keydown', (e)=>{
        if(e.key === 'Enter'){ e.preventDefault(); conversacionEnviarBtn.click(); }
      });
    }
  }
}

// ---------------------------------------------------------------
// "Próximas asesorías agendadas" — lo que el bot agendó y cobró solo,
// cruzando la disponibilidad de cada abogado con Mercado Pago. Un abogado
// ve las suyas; el administrador las ve todas.
// ---------------------------------------------------------------
function citasPanelHTML(forzar){
  if(!CITAS.length && !forzar) return "";
  const isAdmin = CURRENT_USER.role === 'Administrador';
  const CITA_ESTADO_BADGE = {confirmada:'ok', pendiente_pago:'warn'};
  const CITA_ESTADO_LABEL = {confirmada:'Pagada', pendiente_pago:'Esperando pago'};
  const ahora = new Date();
  const vencidas = CITAS.filter(c => new Date(`${c.fecha}T${c.hora_inicio}:00`) < ahora);
  return `
  <div class="panel">
    <div class="panel-head"><h3>Próximas asesorías agendadas</h3><span class="count">${CITAS.length}</span></div>
    ${vencidas.length ? `<div class="notice" style="margin:0 16px 12px 16px;"><strong>${vencidas.length}</strong> ya pasó su hora y sigue sin marcarse como atendida — revisa si el cliente recibió su llamada.</div>` : ''}
    <div class="panel-body" style="padding:0;">
      ${CITAS.length ? CITAS.map(c=>{
        const vencida = new Date(`${c.fecha}T${c.hora_inicio}:00`) < ahora;
        return `
      <div class="alert-row" style="align-items:flex-start;">
        <span class="badge ${CITA_ESTADO_BADGE[c.estado]||'closed'}" style="flex-shrink:0; margin-top:1px;">${CITA_ESTADO_LABEL[c.estado]||c.estado}</span>
        ${vencida ? '<span class="badge crit" style="flex-shrink:0; margin-top:1px;">¡Ya pasó!</span>' : ''}
        <div class="alert-info" ${isAdmin?`data-cita-telefono="${escapeHTML(c.telefono)}" data-cita-nombre="${escapeHTML(c.nombre_cliente || '')}" style="cursor:pointer;"`:''}>
          <div class="name">${escapeHTML(c.texto)}</div>
          <div class="meta">${escapeHTML(c.nombre_cliente || c.telefono)} &middot; ${escapeHTML(c.telefono)} &middot; con ${escapeHTML(c.usuario_nombre)}</div>
        </div>
        <div style="flex-shrink:0; font-size:12px; color:var(--gray); text-align:right;">$${c.monto.toFixed(0)} MXN</div>
        <button class="btn secondary cita-atendida-btn" data-cita-id="${c.id}" style="flex-shrink:0; font-size:11px; padding:6px 10px;">Marcar atendida</button>
        <button class="btn secondary cita-cancelar-btn" data-cita-id="${c.id}" style="flex-shrink:0; font-size:11px; padding:6px 10px; color:var(--red); border-color:var(--red);">Cancelar</button>
      </div>`;}).join("") : '<div class="empty">Sin asesorías agendadas por ahora.</div>'}
    </div>
  </div>`;
}

// Abre en una ventana emergente el chat de WhatsApp completo detrás de una
// cita agendada — para revisar qué le contestó el bot sin tener que ir a
// buscar el número en Conversaciones. No depende de que la vista
// "Conversaciones (WhatsApp)" ya se haya cargado antes.
async function abrirCitaConversacionModal(telefono, nombre){
  await loadConversacionMensajes(telefono);
  const overlay = document.getElementById('modalOverlay');
  overlay.innerHTML = `<div class="modal" style="max-width:720px;">
    <div class="modal-head">
      <button class="close" id="modalClose">&times;</button>
      <h2>${escapeHTML(nombre || telefono)}</h2>
      <div class="sub">${escapeHTML(telefono)}</div>
    </div>
    <div class="modal-body">
      <div class="chat-scroll" style="border:1px solid var(--border); border-radius:8px; padding:12px; background:var(--parchment);">
        ${CONVERSACION_MENSAJES.length ? CONVERSACION_MENSAJES.map(m=>`
          <div style="margin-bottom:10px; text-align:${m.direccion==='entrante'?'left':'right'};">
            <div style="display:inline-block; max-width:80%; padding:8px 12px; border-radius:10px; font-size:13px; background:${m.direccion==='entrante'?'#fff':'var(--ink)'}; color:${m.direccion==='entrante'?'var(--ink)':'#fff'}; text-align:left;">
              ${escapeHTML(m.texto)}
              <div style="font-size:10px; opacity:.6; margin-top:3px;">${m.direccion==='saliente'?(m.respondido_por==='humano'?'Humano':'Bot'):'Cliente'} &middot; ${fmtFechaHora(m.creado_en)}</div>
            </div>
          </div>`).join("") : `<div class="notice">Sin mensajes.</div>`}
      </div>
    </div>
  </div>`;
  document.getElementById('modalClose').addEventListener('click', closeModal);
  overlay.addEventListener('click', (e)=>{ if(e.target===overlay) closeModal(); });
  overlay.classList.add('show');
  const scroll = overlay.querySelector('.chat-scroll');
  if(scroll) scroll.scrollTop = scroll.scrollHeight;
}

// ---------------------------------------------------------------
// "Mi disponibilidad para asesorías" — cada abogado (o administrador que
// también las atienda) define los días y horas de la semana en que puede
// tomar una llamada de asesoría de pago. Es la base para poder ofrecer
// horarios reales desde el bot de WhatsApp más adelante.
// ---------------------------------------------------------------
function disponibilidadPanelHTML(){
  const porDia = {};
  DISPONIBILIDAD.forEach(b=>{
    if(!porDia[b.dia_semana]) porDia[b.dia_semana] = [];
    porDia[b.dia_semana].push(b);
  });
  const filas = Object.keys(porDia).map(Number).sort((a,b)=>a-b).map(dia=>
    porDia[dia].map(b=>`
      <tr>
        <td>${DIA_SEMANA_LABEL[dia]}</td>
        <td>${b.hora_inicio} – ${b.hora_fin}</td>
        <td><button class="btn secondary" data-delete-disponibilidad="${b.id}" style="padding:5px 10px; font-size:11px;">Quitar</button></td>
      </tr>`).join("")
  ).join("");
  return `
  <div class="panel">
    <div class="panel-head" style="cursor:pointer;" data-toggle-disponibilidad>
      <h3>Mi disponibilidad para asesorías</h3>
      <span class="count">${DISPONIBILIDAD.length}</span>
    </div>
    ${DISPONIBILIDAD_ABIERTA ? `
    <div class="panel-body" style="padding:16px 20px;">
      <div class="notice" style="margin-bottom:14px;">Define los días y horas en que puedes atender una asesoría telefónica de 1 hora. Esto es lo que se va a usar para ofrecer horarios reales a los clientes que agenden.</div>
      <form id="addDisponibilidadForm" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px;">
        <div class="field" style="margin:0;"><label>Día</label>
          <select id="disponibilidadDia" style="padding:9px 11px; border:1px solid var(--border); border-radius:8px; background:var(--parchment); font-size:13px;">
            ${[1,2,3,4,5,6,7].map(d=>`<option value="${d}">${DIA_SEMANA_LABEL[d]}</option>`).join("")}
          </select>
        </div>
        <div class="field" style="margin:0;"><label>Desde</label><input type="time" id="disponibilidadInicio" required></div>
        <div class="field" style="margin:0;"><label>Hasta</label><input type="time" id="disponibilidadFin" required></div>
        <button type="submit" class="btn">Agregar horario</button>
      </form>
      ${DISPONIBILIDAD.length ? `
      <table><thead><tr><th>Día</th><th>Horario</th><th></th></tr></thead>
      <tbody>${filas}</tbody></table>
      ` : `<div class="notice">Todavía no tienes horarios registrados.</div>`}
    </div>` : ""}
  </div>`;
}

function agendaHTML(){
  const isAdmin = CURRENT_USER.role === 'Administrador';
  let all = buildAgendaEntries();
  const today = new Date(); today.setHours(0,0,0,0);
  const todayISO = dateToISO(today);

  // Resumen por socio (solo Administrador) — cuenta pendientes/vencidos de
  // cada socio sin filtrar todavía, para poder elegir a quién ver de un vistazo.
  const resumenPorSocio = {};
  if(isAdmin){
    all.forEach(e=>{
      const n = assignedLawyer(e.k);
      if(!resumenPorSocio[n]) resumenPorSocio[n] = {vencidas:0, proximas:0};
      if(e.fecha < todayISO) resumenPorSocio[n].vencidas++; else resumenPorSocio[n].proximas++;
    });
  }

  if(isAdmin && AGENDA_SOCIO !== 'todos'){
    all = all.filter(e=> assignedLawyer(e.k) === AGENDA_SOCIO);
  }
  const vencidas = all.filter(e=>e.fecha < todayISO);
  const proximas = all.filter(e=>e.fecha >= todayISO);

  const actionLabel = {pago:'Marcar cobrado', demanda:'Marcar demanda presentada', amparo:'Marcar amparo presentado', pendiente:'Marcar cumplido', abrir_etapas:'Revisar etapas', pendiente_generico:'Revisar etapas'};

  const row = (e,i)=>{
    const t = AGENDA_TIPO[e.tipo];
    return `<div class="alert-row" style="align-items:flex-start;">
      <div style="width:76px; flex-shrink:0; text-align:center; padding-top:2px;">
        <div style="font-size:11.5px; font-weight:700; color:${t.color};">${fmtDate(e.fecha)}</div>
      </div>
      <span class="badge" style="background:${t.color}1a; color:${t.color}; flex-shrink:0; margin-top:1px;">${t.label}</span>
      <div class="alert-info" data-id="${e.k.id}" style="cursor:pointer;">
        <div class="name">${escapeHTML(e.k.actor)} <span style="color:var(--gray); font-weight:400;">vs</span> ${escapeHTML(truncate(e.k.demandado,30))}</div>
        <div class="meta">${escapeHTML(e.label)} &middot; ${escapeHTML(assignedLawyer(e.k))}</div>
      </div>
      <button class="btn secondary agenda-complete" data-action="${e.action}" data-kid="${e.k.id}" data-idx="${e.actionIdx!=null?e.actionIdx:''}" style="flex-shrink:0; font-size:11px; padding:6px 10px;">${actionLabel[e.action]||'Marcar cumplido'}</button>
    </div>`;
  };

  const tabsHTML = `
  <div style="display:flex; gap:8px; margin-bottom:16px;">
    <button class="btn ${AGENDA_TAB==='general'?'':'secondary'}" data-agenda-tab="general" style="padding:8px 16px;">General</button>
    <button class="btn ${AGENDA_TAB==='asesorias'?'':'secondary'}" data-agenda-tab="asesorias" style="padding:8px 16px;">Asesorías</button>
  </div>`;

  if(AGENDA_TAB === 'asesorias'){
    return tabsHTML + citasPanelHTML(true) + disponibilidadPanelHTML();
  }

  return tabsHTML + `
  <div class="panel">
    <div class="panel-head"><h3>Google Calendar</h3></div>
    <div class="panel-body" style="padding:16px 20px;">
      ${GOOGLE_STATUS.conectado ? `
        <div style="font-size:13px; color:var(--ink); margin-bottom:10px;">Conectado como <strong>${escapeHTML(GOOGLE_STATUS.email||'')}</strong>. Audiencias, pagos, prescripción y amparo se envían a tu calendario de Google — los cambios solo van del sistema hacia Google, no al revés.</div>
        <button class="btn" id="googleSyncBtn">Sincronizar ahora</button>
        <button class="btn secondary" id="googleDisconnectBtn" style="margin-left:8px;">Desconectar</button>
        <span id="googleSyncStatus" style="margin-left:10px; font-size:12px; color:var(--gray);"></span>
      ` : `
        <div class="notice" style="margin-bottom:12px;">Conecta tu cuenta de Google para que tus audiencias, pagos, vencimientos de prescripción y de amparo aparezcan solos en tu Google Calendar (recordatorios incluidos). Es de un solo sentido: lo que edites directo en Google Calendar no se refleja aquí.</div>
        <button class="btn" id="googleConnectBtn">Conectar con Google Calendar</button>
      `}
    </div>
  </div>
  ${isAdmin ? `
  <div class="panel">
    <div class="panel-head"><h3>Pendientes por socio</h3><span class="count">${Object.keys(resumenPorSocio).length}</span></div>
    <div class="panel-body" style="padding:0;">
      <table><thead><tr><th>Socio</th><th>Vencidas</th><th>Próximas</th><th></th></tr></thead><tbody>
        <tr data-agenda-socio="todos" style="cursor:pointer; ${AGENDA_SOCIO==='todos'?'background:var(--parchment);':''}">
          <td><strong>Todos</strong></td>
          <td>${Object.values(resumenPorSocio).reduce((a,b)=>a+b.vencidas,0)}</td>
          <td>${Object.values(resumenPorSocio).reduce((a,b)=>a+b.proximas,0)}</td>
          <td></td>
        </tr>
        ${Object.keys(resumenPorSocio).sort((a,b)=>resumenPorSocio[b].vencidas-resumenPorSocio[a].vencidas).map(n=>`
        <tr data-agenda-socio="${escapeHTML(n)}" style="cursor:pointer; ${AGENDA_SOCIO===n?'background:var(--parchment);':''}">
          <td>${escapeHTML(n)}</td>
          <td style="color:${resumenPorSocio[n].vencidas>0?'var(--red)':'inherit'}; font-weight:${resumenPorSocio[n].vencidas>0?'700':'400'};">${resumenPorSocio[n].vencidas}</td>
          <td>${resumenPorSocio[n].proximas}</td>
          <td><button class="btn secondary" data-agenda-socio="${escapeHTML(n)}" style="padding:5px 10px; font-size:11px;">Ver</button></td>
        </tr>`).join("")}
      </tbody></table>
    </div>
  </div>` : ""}
  ${isAdmin && AGENDA_SOCIO!=='todos' ? `<div class="notice" style="max-width:100%;">Mostrando solo lo de <strong>${escapeHTML(AGENDA_SOCIO)}</strong>. <button class="btn secondary" data-agenda-socio="todos" style="padding:4px 10px; font-size:11px; margin-left:6px;">Ver todos</button></div>` : ""}
  ${EDOMEX_CAPTCHA_PENDIENTE? `
  <div class="panel">
    <div class="panel-head"><h3>&#128274; Captcha de Edomex pendiente de resolver</h3></div>
    <div class="panel-body" style="padding:16px 20px;">
      <div class="notice" style="margin-bottom:12px;">El robot que revisa el boletín de Edomex${EDOMEX_CAPTCHA_PENDIENTE.expediente_exp?` está consultando el expediente <strong>${escapeHTML(EDOMEX_CAPTCHA_PENDIENTE.expediente_exp)}</strong> y`:''} necesita que alguien escriba el código de la imagen para continuar.</div>
      <img src="data:image/png;base64,${EDOMEX_CAPTCHA_PENDIENTE.imagen_base64}" alt="Captcha de Edomex" style="display:block; margin-bottom:10px; border:1px solid var(--border); border-radius:6px; max-width:260px;">
      <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <input type="text" id="edomexCaptchaInput" placeholder="Código de la imagen" style="padding:9px 11px; border:1px solid var(--border); border-radius:8px; background:var(--parchment); font-size:13px; max-width:200px;">
        <button class="btn" id="edomexCaptchaBtn">Enviar</button>
      </div>
    </div>
  </div>` : ""}
  ${avisosNuevosCount()>0? `
  <div class="panel">
    <div class="panel-head"><h3>&#128276; Avisos de boletines por revisar</h3><span class="count">${avisosNuevosCount()}</span></div>
    <div class="panel-body" style="padding:16px 20px;">${AVISOS_BOLETIN.filter(a=>a.estado==='nuevo').map(a=>`
      <div class="aviso-card" data-aviso-id="${a.id}">
        <div class="aviso-head">
          <div data-id="${a.expediente_id}" data-tab="boletin" style="cursor:pointer;">
            <div class="aviso-caso">${escapeHTML(a.expediente_actor)} <span style="color:var(--gray); font-weight:400;">vs</span> ${escapeHTML(truncate(a.expediente_demandado||'—',30))} ${a.expediente_exp? `&middot; Exp. ${escapeHTML(a.expediente_exp)}`:''}</div>
            <div class="aviso-fuente">${escapeHTML(FUENTE_BOLETIN_LABEL[a.fuente]||a.fuente)} ${a.origen==='manual'?'&middot; revisión registrada a mano':'&middot; encontrado automáticamente'}</div>
          </div>
          <span class="badge warn" style="flex-shrink:0;">nuevo</span>
        </div>
        <div class="aviso-resumen">${escapeHTML(a.resumen)}</div>
        <div class="aviso-meta">${a.fecha_publicacion? 'Fecha del boletín: '+fmtDate(a.fecha_publicacion)+' &middot; ':''}${a.origen==='manual'?'Registrado por '+escapeHTML(a.creado_por_nombre||'—')+' el ':'Encontrado el '}${new Date(a.creado_en).toLocaleDateString('es-MX')}${a.url_verificacion? ` &middot; <a href="${escapeHTML(a.url_verificacion)}" target="_blank" rel="noopener">ver fuente</a>`:''}</div>
        <div class="aviso-actions">
          <button class="btn secondary" data-aviso-mark="${a.id}" data-estado="revisado" style="padding:6px 12px; font-size:11.5px;">Marcar revisado</button>
          <button class="btn secondary" data-aviso-mark="${a.id}" data-estado="descartado" style="padding:6px 12px; font-size:11.5px;">Descartar</button>
        </div>
      </div>`).join("")}</div>
  </div>` : ""}
  ${vencidas.length? `
  <div class="panel">
    <div class="panel-head"><h3>&#9888; Vencidas / requieren atención inmediata</h3><span class="count">${vencidas.length}</span></div>
    <div class="panel-body">${vencidas.map(row).join("")}</div>
  </div>` : ""}
  <div class="panel">
    <div class="panel-head"><h3>Próximos eventos</h3><span class="count">${proximas.length}</span></div>
    <div class="panel-body">${proximas.length? proximas.map(row).join("") : '<div class="empty">Sin eventos programados. Agrega pagos, amparos, pendientes o marca etapas desde la ficha de cada asunto.</div>'}</div>
  </div>
  <div class="notice" style="max-width:100%;">Esta agenda combina: pagos de convenios pendientes de cobro, vencimientos del plazo de prescripción (Título Décimo LFT, según el tipo de cada asunto), términos de amparo directo (Art. 17 Ley de Amparo, 15 días hábiles), pendientes que registres manualmente (objeciones, audiencias, desahogos) y avisos de posible atraso frente a los plazos del procedimiento ordinario laboral (Arts. 873 y 873-A LFT). Usa el botón de cada fila para marcarlo cumplido y que deje de aparecer como pendiente. Verifica siempre el calendario oficial de días inhábiles del Tribunal correspondiente.</div>
  `;
}

function formatoDemandaHTML(){
  const esOriginal = PLANTILLA_DOCX_B64 === PLANTILLA_DOCX_DEFAULT_B64;
  return `
  <div class="notice" style="margin-bottom:18px;">La demanda se genera a partir de un archivo <strong>.docx real</strong> de Word (no un texto plano): se conserva el formato, el membrete y las fuentes del despacho. Para cambiarla, sigue estos 2 pasos:</div>

  <div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
    <div style="flex:1; min-width:240px; border:1px solid var(--border); border-radius:10px; padding:18px; background:var(--paper);">
      <div style="font-family:var(--serif); font-weight:700; font-size:15px; color:var(--ink); margin-bottom:8px;">1. Modificar</div>
      <div style="font-size:12.5px; color:var(--gray); margin-bottom:12px;">Descarga el archivo de Word actual y edítalo con Word normalmente: agrega, quita o cambia lo que necesites. No borres los campos entre llaves dobles (<code>{{actor}}</code>, etc.) que quieras que se sigan llenando solos.</div>
      <button class="btn" id="descargarPlantillaBtn" style="width:100%;">Modificar (descargar .docx)</button>
    </div>
    <div style="flex:1; min-width:240px; border:1px solid var(--border); border-radius:10px; padding:18px; background:var(--paper);">
      <div style="font-family:var(--serif); font-weight:700; font-size:15px; color:var(--ink); margin-bottom:8px;">2. Guardar</div>
      <div style="font-size:12.5px; color:var(--gray); margin-bottom:12px;">Cuando termines de editarlo y lo hayas guardado en tu computadora, súbelo aquí para que el sistema lo use de inmediato en todas las demandas nuevas.</div>
      <label class="btn" style="width:100%; cursor:pointer; display:block; text-align:center;">Guardar (subir .docx editado)
        <input type="file" id="subirPlantillaInput" accept=".docx" style="display:none;">
      </label>
    </div>
  </div>

  <div class="legal-box" style="margin-bottom:16px;">
    <strong>Campos disponibles para usar en el Word:</strong> <code>{{actor}}, {{demandado}}, {{giro_empresa}}, {{dom_demandado}}, {{dom_testigos}}, {{puesto}}, {{puesto_despidio}}, {{fecha_ingreso_letra}}, {{fecha_baja_letra}}, {{salario_mensual_num}}, {{salario_diario_num}}, {{salario_mensual_letra}}, {{curp}}, {{instancia}}, {{quien_despidio}}, {{hora_despido}}, {{testigos}}, {{fecha_hoy_letra}}, {{ciudad_firma}}</code>
  </div>
  <div id="plantillaStatus" style="font-size:12px; color:var(--gray);">${esOriginal ? 'Estado actual: usando la plantilla original del despacho.' : 'Estado actual: usando una plantilla personalizada, subida por el Administrador.'}</div>
  ${!esOriginal ? `<button class="btn secondary" id="resetPlantillaBtn" style="margin-top:10px;">Restaurar plantilla original del despacho</button>` : ''}

  <div class="divider"></div>
  <div style="font-family:var(--serif); font-size:17px; font-weight:700; color:var(--ink); margin-bottom:8px;">Otras plantillas de escritos</div>
  <div class="notice" style="margin-bottom:16px;">Además de la demanda, puedes subir tantas plantillas .docx como necesites (contestación, promoción, oficio, lo que uses seguido). Funcionan igual: mismos campos entre llaves dobles, se autocompletan con los datos del expediente. Cada una aparece como opción al generar un escrito desde la ficha de cualquier asunto.</div>
  <div class="grid2" style="margin-bottom:6px;">
    <div class="field"><label>Nombre de la plantilla</label><input type="text" id="nuevaPlantillaNombre" placeholder="Ej. Contestación de demanda"></div>
    <div class="field"><label>Descripción (opcional)</label><input type="text" id="nuevaPlantillaDescripcion" placeholder="Para qué se usa"></div>
  </div>
  <label class="btn secondary" style="cursor:pointer; display:inline-block;">Elegir archivo .docx
    <input type="file" id="nuevaPlantillaInput" accept=".docx" style="display:none;">
  </label>
  <span id="nuevaPlantillaNombreArchivo" style="font-size:12px; color:var(--gray); margin-left:8px;"></span>
  <div style="margin-top:10px;"><button class="btn" id="agregarPlantillaBtn">Agregar plantilla</button> <span id="nuevaPlantillaStatus" style="font-size:12px; color:var(--gray); margin-left:8px;"></span></div>

  <div class="panel" style="margin-top:16px;">
    <div class="panel-head"><h3>Plantillas subidas</h3><span class="count">${PLANTILLAS_LIB.length}</span></div>
    <div class="panel-body" style="padding:0;">
      <table><thead><tr><th>Nombre</th><th>Descripción</th><th>Subida por</th><th>Fecha</th><th></th></tr></thead><tbody>
        ${PLANTILLAS_LIB.map(p=>`
          <tr>
            <td>${escapeHTML(p.nombre)}</td>
            <td>${escapeHTML(p.descripcion||'—')}</td>
            <td>${escapeHTML(p.creado_por_nombre||'—')}</td>
            <td style="font-size:11.5px;">${new Date(p.creado_en).toLocaleDateString('es-MX')}</td>
            <td style="white-space:nowrap;">
              <button class="btn secondary" data-descargar-plantilla="${p.id}" data-nombre-plantilla="${escapeHTML(p.nombre)}" style="padding:5px 10px; font-size:11px;">Descargar</button>
              <button class="btn secondary" data-delete-plantilla="${p.id}" style="padding:5px 10px; font-size:11px;">Eliminar</button>
            </td>
          </tr>`).join("") || `<tr><td colspan="5" class="empty">Sin plantillas adicionales todavía.</td></tr>`}
      </tbody></table>
    </div>
  </div>
  `;
}



// ---------------------------------------------------------------
// Respaldo completo del sistema: junta todo lo que vive en el almacenamiento
// (notas, etapas, convenios, casos nuevos, equipo, plantilla de demanda) en
// un solo archivo descargable, y permite restaurarlo después.
// ---------------------------------------------------------------
function exportarRespaldo(){
  window.open(API_BASE + 'backup_export.php', '_blank');
}

function equipoHTML(){
  const rows = USUARIOS_ADMIN.map(u=>{
    return `<tr data-rename="${u.id}"><td><strong>${escapeHTML(u.nombre)}</strong>${u.activo? '' : ' <span class="badge closed">inactivo</span>'}${u.debe_cambiar_password? ' <span class="badge warn">pendiente de 1er acceso</span>' : ''}</td><td>${escapeHTML(u.email)}</td><td>${mapRol(u.rol)}</td><td>${u.num_casos} asuntos</td>
      <td style="white-space:nowrap;">
        <button class="btn secondary" data-rename-btn="${u.id}" style="padding:5px 10px; font-size:11px;">Renombrar</button>
        <button class="btn secondary" data-resetpass-btn="${u.id}" style="padding:5px 10px; font-size:11px;">Restablecer contraseña</button>
        <button class="btn secondary" data-toggle-activo="${u.id}" data-activo="${u.activo?1:0}" style="padding:5px 10px; font-size:11px;">${u.activo? 'Desactivar' : 'Activar'}</button>
      </td></tr>`;
  }).join("");
  return `
  <div class="panel">
    <div class="panel-head"><h3>Socios del equipo</h3><span class="count">${USUARIOS_ADMIN.length}</span></div>
    <div class="panel-body" style="padding:0;">
      <table><thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Carga de trabajo</th><th></th></tr></thead><tbody>${rows}</tbody></table>
    </div>
  </div>
  <div class="panel"><div class="panel-body" style="padding:18px 24px;">
    <button class="btn" id="addLawyerBtn2">+ Agregar socio</button>
    <div class="notice" style="margin-top:14px;">Cada asunto puede reasignarse a un socio del equipo desde su ficha (pestaña "Gestión interna"). Solo el usuario Administrador ve la totalidad de la cartera; cada socio asignado ve únicamente los asuntos que tiene a su cargo. Al crear un socio o restablecer su contraseña, el sistema genera una contraseña temporal — compártesela por un medio seguro (no por este sistema); se le pedirá cambiarla en su primer ingreso. "Desactivar" le quita el acceso de inmediato sin borrar su historial.</div>
  </div></div>
  <div class="panel"><div class="panel-body" style="padding:18px 24px;">
    <div style="font-family:var(--serif); font-weight:700; font-size:15px; color:var(--ink); margin-bottom:8px;">Parámetros del cálculo de liquidación</div>
    <div class="notice" style="margin-bottom:14px;">La prima de antigüedad (Art. 162 LFT) se topa a 2 veces el salario mínimo diario vigente. La CONASAMI publica un nuevo salario mínimo cada 1° de enero — actualízalo aquí cuando eso pase; el sistema recalcula todo solo, sin necesidad de tocar código.</div>
    <form id="salarioMinimoForm" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
      <div class="field" style="margin:0;"><label>Salario mínimo diario (zona general)</label><input type="text" id="salarioMinimoInput" value="${SALARIO_MINIMO_DIARIO.toFixed(2)}" style="max-width:160px;"></div>
      <button type="submit" class="btn">Guardar</button>
    </form>
  </div></div>
  <div class="panel"><div class="panel-body" style="padding:18px 24px;">
    <div style="font-family:var(--serif); font-weight:700; font-size:15px; color:var(--ink); margin-bottom:8px;">Respaldo de toda la información</div>
    <div class="notice" style="margin-bottom:14px;">Toda la información (etapas, notas, convenios, pagos, historial) vive ahora en la base de datos MySQL de tu hosting — el respaldo más confiable es el de cPanel (phpMyAdmin &rarr; Exportar, o la herramienta de "Copias de seguridad" de cPanel). Este botón descarga además un respaldo en JSON como referencia rápida.</div>
    <button class="btn secondary" id="descargarRespaldoBtn">Descargar respaldo (.json)</button>
  </div></div>`;
}

function diasInhabilesHTML(){
  const rows = DIAS_INHABILES.map(d=>`
    <tr data-diainhabil-row="${d.id}">
      <td>${fmtDate(d.fecha)}</td>
      <td>${escapeHTML(d.descripcion)}</td>
      <td>${escapeHTML(AMBITO_INHABIL_LABEL[d.ambito] || d.ambito)}</td>
      <td><button class="btn secondary" data-delete-diainhabil="${d.id}" style="padding:5px 10px; font-size:11px;">Quitar</button></td>
    </tr>`).join("");
  return `
  <div class="notice" style="margin-bottom:18px;">Este calendario se suma a sábados y domingos en <strong>todos</strong> los cómputos automáticos de plazos del sistema (etapas del expediente, amparo, y la pestaña "Pendientes"). Ya viene precargado con los descansos obligatorios de ley (Art. 74 LFT) para 2026 y 2027. Los recesos propios de cada tribunal (Semana Santa, fin de año, "puentes" locales) <strong>no vienen incluidos</strong> porque varían por tribunal y año — agrégalos aquí en cuanto los confirmes con el calendario oficial de cada uno. Ante un vencimiento crítico, verifica siempre con el tribunal.</div>
  <div class="panel"><div class="panel-body" style="padding:18px 24px;">
    <div style="font-family:var(--serif); font-weight:700; font-size:15px; color:var(--ink); margin-bottom:10px;">Agregar día inhábil</div>
    <form id="addDiaInhabilForm" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
      <div class="field" style="margin:0;"><label>Fecha</label><input type="date" id="diaInhabilFecha" required></div>
      <div class="field" style="margin:0; flex:1; min-width:220px;"><label>Descripción</label><input type="text" id="diaInhabilDescripcion" placeholder="Ej. Receso de Semana Santa, Tribunal Laboral CDMX" required></div>
      <div class="field" style="margin:0;"><label>Ámbito</label>
        <select id="diaInhabilAmbito" style="padding:9px 11px; border:1px solid var(--border); border-radius:8px; background:var(--parchment); font-size:13px;">
          <option value="todos">Todos los ámbitos</option>
          <option value="federal">Federal (Art. 74 LFT)</option>
          <option value="cdmx">Tribunales locales — CDMX</option>
          <option value="edomex">Tribunales locales — Estado de México</option>
        </select>
      </div>
      <button type="submit" class="btn">Agregar</button>
    </form>
  </div></div>
  <div class="panel">
    <div class="panel-head"><h3>Calendario de días inhábiles</h3><span class="count">${DIAS_INHABILES.length}</span></div>
    <div class="panel-body" style="padding:0;">
      <table><thead><tr><th>Fecha</th><th>Descripción</th><th>Ámbito</th><th></th></tr></thead>
      <tbody>${rows || '<tr><td colspan="4" class="empty">Sin días inhábiles registrados.</td></tr>'}</tbody></table>
    </div>
  </div>`;
}

function miCuentaHTML(){
  const c = PJF_CREDENCIALES;
  const configurado = c && c.configurado;
  return `
  <div class="notice" style="margin-bottom:18px;">El robot que revisa el boletín Federal todos los días necesita iniciar sesión en el Portal de Servicios en Línea del Poder Judicial de la Federación (serviciosenlinea.pjf.gob.mx) para ver los expedientes donde apareces como autorizado. Guarda aquí tu propio usuario y contraseña de ese portal (los mismos con los que tú entras) para que el robot también revise tus casos federales. Tu contraseña se guarda cifrada — nadie del despacho puede verla, ni siquiera un administrador.</div>
  <div class="panel"><div class="panel-body" style="padding:18px 24px;">
    <div style="font-family:var(--serif); font-weight:700; font-size:15px; color:var(--ink); margin-bottom:10px;">Cuenta del Portal Federal (PJF)</div>
    ${configurado ? `<div class="notice" style="margin-bottom:14px; background:var(--amber-bg,#fdf6e3);">Ya tienes guardado el usuario <strong>${escapeHTML(c.pjf_usuario)}</strong>${c.actualizado_en ? ' (actualizado el '+new Date(c.actualizado_en).toLocaleDateString('es-MX')+')' : ''}. Para cambiarlo, captura los datos de nuevo abajo.</div>` : ''}
    <form id="pjfCredencialesForm" class="grid2">
      <div class="field"><label>Usuario del Portal Federal</label><input type="text" id="pjfUsuarioInput" placeholder="Tu usuario de serviciosenlinea.pjf.gob.mx" value="${configurado ? escapeHTML(c.pjf_usuario) : ''}"></div>
      <div class="field"><label>Contraseña del Portal Federal</label><input type="password" id="pjfPasswordInput" placeholder="Tu contraseña (siempre hay que volver a escribirla para guardar)"></div>
    </form>
    <div style="margin-top:12px; display:flex; gap:10px;">
      <button class="btn" id="savePjfCredencialesBtn">Guardar</button>
      ${configurado ? `<button class="btn secondary" id="borrarPjfCredencialesBtn">Quitar cuenta guardada</button>` : ''}
    </div>
  </div></div>`;
}

function clientePreviewHTML(){
  const list = visibleCases();
  return `
  <div class="notice" style="margin-bottom:18px;">Esta pantalla simula, dentro del propio sistema, lo que un cliente vería al consultar su asunto. Útil para decidir qué información compartir. Selecciona un expediente para previsualizarlo.</div>
  <div class="panel"><div class="panel-body" style="padding:0;">
    <table><thead><tr><th>Actor</th><th>Expediente</th><th>Estatus</th></tr></thead>
    <tbody>${list.map(k=>`<tr data-preview="${k.id}"><td>${escapeHTML(k.actor)}</td><td class="exp-code">${escapeHTML(k.exp||'—')}</td><td>${statusBadge(k)}</td></tr>`).join("")}</tbody></table>
  </div></div>`;
}

// ---------------------------------------------------------------
// Bindings de la vista activa (tabla, filas, chips)
// ---------------------------------------------------------------
function bindViewBody(){
  const descargarPlantillaBtn = document.getElementById('descargarPlantillaBtn');
  if(descargarPlantillaBtn){
    descargarPlantillaBtn.addEventListener('click', ()=>{
      const bytes = base64ToUint8Array(PLANTILLA_DOCX_B64);
      const blob = new Blob([bytes], {type:'application/vnd.openxmlformats-officedocument.wordprocessingml.document'});
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = 'Plantilla de demanda.docx';
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
      setTimeout(()=> URL.revokeObjectURL(url), 5000);
    });
  }
  const subirPlantillaInput = document.getElementById('subirPlantillaInput');
  if(subirPlantillaInput){
    subirPlantillaInput.addEventListener('change', async (e)=>{
      const file = e.target.files[0];
      if(!file) return;
      const status = document.getElementById('plantillaStatus');
      if(status) status.textContent = 'Cargando...';
      const reader = new FileReader();
      reader.onload = async ()=>{
        const b64 = reader.result.split(',')[1];
        await savePlantillaDocx(b64);
        renderViewBody();
      };
      reader.readAsDataURL(file);
    });
  }
  const resetPlantillaBtn = document.getElementById('resetPlantillaBtn');
  if(resetPlantillaBtn){
    resetPlantillaBtn.addEventListener('click', async ()=>{
      if(!confirm('¿Restaurar la plantilla original del despacho? Se perderá la plantilla personalizada actual.')) return;
      await resetPlantillaDocx();
      renderViewBody();
    });
  }
  const nuevaPlantillaInput = document.getElementById('nuevaPlantillaInput');
  if(nuevaPlantillaInput){
    nuevaPlantillaInput.addEventListener('change', ()=>{
      const nombreEl = document.getElementById('nuevaPlantillaNombreArchivo');
      if(nombreEl) nombreEl.textContent = nuevaPlantillaInput.files[0] ? nuevaPlantillaInput.files[0].name : '';
    });
  }
  const agregarPlantillaBtn = document.getElementById('agregarPlantillaBtn');
  if(agregarPlantillaBtn){
    agregarPlantillaBtn.addEventListener('click', ()=>{
      const nombre = document.getElementById('nuevaPlantillaNombre').value.trim();
      const descripcion = document.getElementById('nuevaPlantillaDescripcion').value.trim();
      const input = document.getElementById('nuevaPlantillaInput');
      const status = document.getElementById('nuevaPlantillaStatus');
      const file = input.files[0];
      if(!nombre){ if(status) status.textContent = 'Escribe un nombre para la plantilla.'; return; }
      if(!file){ if(status) status.textContent = 'Elige un archivo .docx.'; return; }
      agregarPlantillaBtn.disabled = true;
      if(status) status.textContent = 'Subiendo...';
      const reader = new FileReader();
      reader.onload = async ()=>{
        try{
          const b64 = reader.result.split(',')[1];
          await api('POST', 'plantillas_create.php', {nombre, descripcion, base64: b64});
          await loadPlantillasLib();
          renderViewBody();
        }catch(err){
          if(status) status.textContent = 'Error: ' + err.message;
          agregarPlantillaBtn.disabled = false;
        }
      };
      reader.readAsDataURL(file);
    });
  }
  document.querySelectorAll('[data-descargar-plantilla]').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const id = parseInt(btn.dataset.descargarPlantilla);
      btn.disabled = true;
      try{
        const d = await api('GET', 'plantillas_get.php?id=' + id);
        const bytes = base64ToUint8Array(d.base64);
        const blob = new Blob([bytes], {type:'application/vnd.openxmlformats-officedocument.wordprocessingml.document'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = (btn.dataset.nombrePlantilla || d.nombre) + '.docx';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        setTimeout(()=> URL.revokeObjectURL(url), 5000);
      }catch(err){ alert('No se pudo descargar: ' + err.message); }
      finally{ btn.disabled = false; }
    });
  });
  document.querySelectorAll('[data-delete-plantilla]').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const id = parseInt(btn.dataset.deletePlantilla);
      const p = PLANTILLAS_LIB.find(x=>x.id===id);
      if(!confirm('¿Eliminar la plantilla "'+(p?p.nombre:'')+'"? Esta acción no se puede deshacer.')) return;
      try{
        await api('POST', 'plantillas_delete.php', {id});
        await loadPlantillasLib();
        renderViewBody();
      }catch(err){ alert('No se pudo eliminar: ' + err.message); }
    });
  });
  const nuevoBtn = document.getElementById('nuevoExpedienteBtn');
  if(nuevoBtn){
    nuevoBtn.addEventListener('click', async ()=>{
      nuevoBtn.disabled = true;
      try{
        const creado = await api('POST', 'expedientes_create.php', {});
        await refreshBootstrap();
        MODAL_TAB = 'editar';
        const nuevo = findCase(creado.id);
        renderViewBody();
        if(nuevo) openModal(nuevo);
      }catch(err){ alert('No se pudo crear el expediente: ' + err.message); }
      finally{ nuevoBtn.disabled = false; }
    });
  }
  document.querySelectorAll('[data-id]').forEach(el=>{
    el.addEventListener('click', ()=>{
      const id = parseInt(el.dataset.id);
      const k = findCase(id);
      if(k) openModal(k, el.dataset.tab);
    });
  });
  document.querySelectorAll('.chip[data-s]').forEach(el=>{
    el.addEventListener('click', ()=>{ FILTER_STATUS = el.dataset.s; renderViewBody(); });
  });
  document.querySelectorAll('.chip[data-periodo]').forEach(el=>{
    el.addEventListener('click', ()=>{ INGRESOS_PERIODO = el.dataset.periodo; renderViewBody(); });
  });
  document.querySelectorAll('.chip[data-embudo-periodo]').forEach(el=>{
    el.addEventListener('click', ()=>{
      WA_EMBUDO_PERIODO = el.dataset.embudoPeriodo;
      if(WA_EMBUDO_PERIODO !== 'personalizado') loadWhatsappEmbudo().then(renderViewBody);
      else renderViewBody();
    });
  });
  const embudoAplicarBtn = document.getElementById('embudoAplicarBtn');
  if(embudoAplicarBtn){
    embudoAplicarBtn.addEventListener('click', ()=>{
      WA_EMBUDO_DESDE = document.getElementById('embudoDesde').value;
      WA_EMBUDO_HASTA = document.getElementById('embudoHasta').value;
      loadWhatsappEmbudo().then(renderViewBody);
    });
  }
  const ingresosAbogadoSelect = document.getElementById('ingresosAbogadoSelect');
  if(ingresosAbogadoSelect){
    ingresosAbogadoSelect.addEventListener('change', ()=>{
      INGRESOS_ABOGADO = ingresosAbogadoSelect.value;
      renderViewBody();
    });
  }
  document.querySelectorAll('[data-agenda-tab]').forEach(el=>{
    el.addEventListener('click', ()=>{
      AGENDA_TAB = el.dataset.agendaTab;
      renderViewBody();
    });
  });
  document.querySelectorAll('[data-agenda-socio]').forEach(el=>{
    el.addEventListener('click', ()=>{
      AGENDA_SOCIO = el.dataset.agendaSocio;
      renderViewBody();
    });
  });
  const toggleDisponibilidad = document.querySelector('[data-toggle-disponibilidad]');
  if(toggleDisponibilidad){
    toggleDisponibilidad.addEventListener('click', ()=>{
      DISPONIBILIDAD_ABIERTA = !DISPONIBILIDAD_ABIERTA;
      renderViewBody();
    });
  }
  const addDisponibilidadForm = document.getElementById('addDisponibilidadForm');
  if(addDisponibilidadForm){
    addDisponibilidadForm.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const dia_semana = parseInt(document.getElementById('disponibilidadDia').value, 10);
      const hora_inicio = document.getElementById('disponibilidadInicio').value;
      const hora_fin = document.getElementById('disponibilidadFin').value;
      if(!hora_inicio || !hora_fin){ alert('Captura la hora de inicio y de fin.'); return; }
      if(hora_inicio >= hora_fin){ alert('La hora de inicio debe ser antes que la hora de fin.'); return; }
      try{
        await api('POST', 'disponibilidad_agregar.php', {dia_semana, hora_inicio, hora_fin});
        await loadDisponibilidad();
        renderViewBody();
      }catch(err){ alert('No se pudo agregar el horario: ' + err.message); }
    });
  }
  document.querySelectorAll('[data-delete-disponibilidad]').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const id = parseInt(btn.dataset.deleteDisponibilidad, 10);
      try{
        await api('POST', 'disponibilidad_eliminar.php', {id});
        await loadDisponibilidad();
        renderViewBody();
      }catch(err){ alert('No se pudo quitar el horario: ' + err.message); }
    });
  });
  const googleConnectBtn = document.getElementById('googleConnectBtn');
  if(googleConnectBtn){
    googleConnectBtn.addEventListener('click', ()=>{
      window.location.href = API_BASE + 'google_oauth_start.php';
    });
  }
  const googleDisconnectBtn = document.getElementById('googleDisconnectBtn');
  if(googleDisconnectBtn){
    googleDisconnectBtn.addEventListener('click', async ()=>{
      if(!confirm('¿Desconectar tu cuenta de Google Calendar? Se borrarán los eventos que el sistema ya había creado ahí.')) return;
      try{
        const tok = await api('GET', 'google_access_token.php').catch(()=>null);
        if(tok){
          const st = await api('GET', 'google_sync_state.php').catch(()=>({evento_ids:[]}));
          const headers = {'Authorization':'Bearer '+tok.access_token};
          for(const id of (st.evento_ids||[])){
            try{ await fetch('https://www.googleapis.com/calendar/v3/calendars/primary/events/'+id, {method:'DELETE', headers}); }catch(e){}
          }
        }
      }catch(e){}
      await api('POST', 'google_disconnect.php');
      await loadGoogleStatus();
      renderViewBody();
    });
  }
  const googleSyncBtn = document.getElementById('googleSyncBtn');
  if(googleSyncBtn){
    googleSyncBtn.addEventListener('click', async ()=>{
      const status = document.getElementById('googleSyncStatus');
      googleSyncBtn.disabled = true;
      if(status) status.textContent = 'Sincronizando...';
      try{
        const r = await sincronizarGoogleCalendar((n,total)=>{ if(status) status.textContent = `Sincronizando ${n}/${total}...`; });
        if(status) status.textContent = `Listo ✓ ${r.sincronizados} evento(s) actualizados${r.borrados?`, ${r.borrados} quitado(s)`:''}${r.fallos?`, ${r.fallos} con error`:''}.`;
      }catch(err){
        if(status) status.textContent = 'Error: ' + err.message;
      } finally {
        googleSyncBtn.disabled = false;
      }
    });
  }
  const aplicarPeriodoBtn = document.getElementById('aplicarPeriodoBtn');
  if(aplicarPeriodoBtn){
    aplicarPeriodoBtn.addEventListener('click', ()=>{
      INGRESOS_DESDE = document.getElementById('ingresosDesde').value;
      INGRESOS_HASTA = document.getElementById('ingresosHasta').value;
      renderViewBody();
    });
  }
  document.querySelectorAll('[data-demandado]').forEach(el=>{
    el.addEventListener('click', ()=>{
      SEARCH_TERM = el.dataset.demandado;
      FILTER_STATUS = 'todos';
      VIEW = 'expedientes';
      render();
    });
  });
  document.querySelectorAll('[data-preview]').forEach(el=>{
    el.addEventListener('click', ()=>{
      const id = parseInt(el.dataset.preview);
      const k = findCase(id);
      if(k) openClientPreviewModal(k);
    });
  });
  document.querySelectorAll('.agenda-complete').forEach(btn=>{
    btn.addEventListener('click', async (ev)=>{
      ev.stopPropagation();
      const kid = parseInt(btn.dataset.kid);
      const action = btn.dataset.action;
      const idx = btn.dataset.idx !== '' ? parseInt(btn.dataset.idx) : null;
      const k = findCase(kid);
      const meta = getMeta(kid);
      try{
        if(action === 'pago'){
          let pagos = meta.pagos;
          if(!pagos || !pagos.length){
            const fechas = extractFechasMencionadas(k.ultima_nota);
            pagos = fechas.length ? fechas.map(f=>({fecha:f, monto:'', cobrado:false})) : [];
          }
          if(pagos[idx]) { pagos[idx].cobrado = true; pagos[idx].fecha_cobro = pagos[idx].fecha_cobro || dateToISO(new Date()); }
          await api('POST', 'expedientes_update_pagos.php', {id:kid, pagos});
        } else if(action === 'demanda'){
          const etapas = Object.assign({}, meta.etapas);
          etapas.demanda_presentada = {fecha: dateToISO(new Date())};
          await api('POST', 'expedientes_update_etapas.php', {id:kid, etapas});
        } else if(action === 'amparo'){
          await api('POST', 'expedientes_update_amparo.php', {id:kid, amparo_activo:true, amparo_presentado:true, amparo_fecha_notif: meta.amparo_fecha_notif, amparo_notas: meta.amparo_notas});
        } else if(action === 'pendiente'){
          const items = meta.pendientes || [];
          if(items[idx]) items[idx].cumplido = true;
          await api('POST', 'expedientes_update_pendientes.php', {id:kid, pendientes: items});
        } else if(action === 'abrir_etapas' || action === 'pendiente_generico'){
          MODAL_TAB = 'etapas';
          openModal(k);
          return;
        }
        await refreshBootstrap();
        syncGoogleIfConnected();
        renderViewBody();
      }catch(err){ alert('No se pudo actualizar: ' + err.message); }
    });
  });
  document.querySelectorAll('[data-aviso-mark]').forEach(btn=>{
    btn.addEventListener('click', async (ev)=>{
      ev.stopPropagation();
      try{
        await api('POST', 'avisos_mark.php', {id: parseInt(btn.dataset.avisoMark), estado: btn.dataset.estado});
        await loadAvisosBoletin();
        if(ACTIVE_CASE) ACTIVE_CASE = findCase(ACTIVE_CASE.id);
        renderModal();
        renderViewBody();
      }catch(err){ alert('No se pudo actualizar: ' + err.message); }
    });
  });
  document.querySelectorAll('[data-prospecto-abrir]').forEach(el=>{
    el.addEventListener('click', async ()=>{
      const id = parseInt(el.dataset.prospectoAbrir);
      const p = PROSPECTOS.find(x=>x.id===id);
      if(!p) return;
      await loadProspectoMensajes(p.telefono);
      abrirProspectoModal(id);
      if(p.mensaje_nuevo){
        p.mensaje_nuevo = false;
        api('POST', 'prospectos_update.php', {id, marcar_visto: true}).catch(()=>{});
      }
    });
  });
  document.querySelectorAll('[data-cita-telefono]').forEach(el=>{
    el.addEventListener('click', async ()=>{
      // Una cita agendada casi siempre tiene un prospecto detrás (se creó
      // al mostrar interés en la asesoría) — se abre ese modal completo
      // (resumen del caso, cambiar estatus, turnar, enviar mensaje) en vez
      // del visor de solo lectura, para no tener que ir a buscarlo aparte
      // en "Prospectos (WhatsApp)". Si por alguna razón no hay prospecto
      // para ese teléfono, se cae de vuelta al visor simple de siempre.
      const p = PROSPECTOS.find(x=>x.telefono===el.dataset.citaTelefono);
      if(p){
        await loadProspectoMensajes(p.telefono);
        abrirProspectoModal(p.id);
        if(p.mensaje_nuevo){
          p.mensaje_nuevo = false;
          api('POST', 'prospectos_update.php', {id: p.id, marcar_visto: true}).catch(()=>{});
        }
      } else {
        abrirCitaConversacionModal(el.dataset.citaTelefono, el.dataset.citaNombre);
      }
    });
  });
  document.querySelectorAll('.cita-atendida-btn').forEach(el=>{
    el.addEventListener('click', async (e)=>{
      e.stopPropagation();
      const id = parseInt(el.dataset.citaId);
      el.disabled = true;
      try{
        await api('POST', 'citas_marcar_atendida.php', {id});
        await loadCitas();
        renderViewBody();
      }catch(err){
        alert('No se pudo marcar: ' + err.message);
        el.disabled = false;
      }
    });
  });
  document.querySelectorAll('.cita-cancelar-btn').forEach(el=>{
    el.addEventListener('click', async (e)=>{
      e.stopPropagation();
      if(!confirm('¿Cancelar esta asesoría? El horario se libera de inmediato para que alguien más lo pueda apartar. Esto no avisa nada al cliente por WhatsApp — si hace falta, escríbele tú directo.')) return;
      const id = parseInt(el.dataset.citaId);
      el.disabled = true;
      try{
        await api('POST', 'citas_cancelar.php', {id});
        await loadCitas();
        renderViewBody();
      }catch(err){
        alert('No se pudo cancelar: ' + err.message);
        el.disabled = false;
      }
    });
  });
  const prospectosToggleAtendidos = document.querySelector('[data-prospectos-toggle-atendidos]');
  if(prospectosToggleAtendidos){
    prospectosToggleAtendidos.addEventListener('click', ()=>{
      PROSPECTOS_MOSTRAR_ATENDIDOS = !PROSPECTOS_MOSTRAR_ATENDIDOS;
      renderViewBody();
    });
  }
  document.querySelectorAll('[data-prospectos-tab]').forEach(el=>{
    el.addEventListener('click', ()=>{
      PROSPECTOS_TAB = el.dataset.prospectosTab;
      renderViewBody();
    });
  });
  document.querySelectorAll('[data-conversacion-abrir]').forEach(el=>{
    el.addEventListener('click', async ()=>{
      const telefono = el.dataset.conversacionAbrir;
      await Promise.all([loadConversacionMensajes(telefono), loadConversacionResumen(telefono)]);
      abrirConversacionModal(telefono);
    });
  });
  bindResumenEjecutivoEvents();
  bindJurisprudenciaEvents();
  const reintentarLoteBtn = document.getElementById('reintentarLoteBtn');
  if(reintentarLoteBtn){
    reintentarLoteBtn.addEventListener('click', async ()=>{
      REINTENTO_EN_PROGRESO = true;
      REINTENTO_PROGRESO_TXT = 'Reintentando respuestas fallidas...';
      renderViewBody();
      let motivosFallo = {}; // motivo -> cuántas veces pasó, para el resumen final
      let atorado = false;
      try{
        let restantes = Infinity;
        let restantesAnterior = Infinity;
        let totalOk = 0, totalFallo = 0;
        while(restantes > 0){
          const d = await api('POST', 'conversaciones_reintentar.php', {lote:true, tamano:3});
          totalOk += d.resultados.filter(r=>r.ok).length;
          totalFallo += d.resultados.filter(r=>!r.ok).length;
          d.resultados.filter(r=>!r.ok).forEach(r=>{
            motivosFallo[r.motivo] = (motivosFallo[r.motivo]||0) + 1;
          });
          restantes = d.restantes;
          REINTENTO_PROGRESO_TXT = `Reintentando... ${totalOk} contestadas, ${totalFallo} sin poder contestar, ${restantes} pendientes.`;
          renderViewBody();
          if(d.resultados.length === 0) break; // no debería pasar, pero evita loop infinito
          // Si nadie se contestó en esta vuelta y el número de pendientes no bajó,
          // son casos que se van a quedar fallando siempre (ej. sin saldo de
          // Anthropic) — seguir insistiendo solo gastaría llamadas a la API sin
          // avanzar, así que nos detenemos y lo explicamos en el resumen.
          if(d.resultados.every(r=>!r.ok) && restantes === restantesAnterior){
            atorado = true;
            break;
          }
          restantesAnterior = restantes;
        }
        await loadConversaciones();
        const resumenMotivos = Object.entries(motivosFallo).map(([m,n])=>`• ${n}× ${m}`).join('\n');
        let mensaje = `Reintento terminado: ${totalOk} conversación${totalOk===1?'':'es'} contestada${totalOk===1?'':'s'}, ${totalFallo} sin poder contestar.`;
        if(resumenMotivos) mensaje += `\n\nMotivos de las que no se pudieron:\n${resumenMotivos}`;
        if(atorado) mensaje += `\n\nSe detuvo porque las restantes van a seguir fallando por la misma razón — dale clic de nuevo cuando esté resuelto.`;
        if(totalOk > 0 || totalFallo > 0) alert(mensaje);
      }catch(err){
        alert('No se pudo completar el reintento en lote: ' + err.message);
      }finally{
        REINTENTO_EN_PROGRESO = false;
        REINTENTO_PROGRESO_TXT = "";
        renderViewBody();
      }
    });
  }
  const edomexCaptchaBtn = document.getElementById('edomexCaptchaBtn');
  if(edomexCaptchaBtn){
    edomexCaptchaBtn.addEventListener('click', async ()=>{
      const input = document.getElementById('edomexCaptchaInput');
      const respuesta = input.value.trim();
      if(!respuesta){ alert('Escribe el código que ves en la imagen.'); return; }
      edomexCaptchaBtn.disabled = true;
      try{
        await api('POST', 'edomex_captcha_resolver.php', {id: EDOMEX_CAPTCHA_PENDIENTE.id, respuesta});
        await loadEdomexCaptchaPendiente();
        renderViewBody();
      }catch(err){ alert('No se pudo enviar: ' + err.message); }
      finally{ edomexCaptchaBtn.disabled = false; }
    });
  }
  document.querySelectorAll('[data-rename-btn]').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const id = parseInt(btn.dataset.renameBtn);
      const u = USUARIOS_ADMIN.find(x=>x.id===id);
      const nuevo = prompt("Nuevo nombre para "+u.nombre+":", u.nombre);
      if(!nuevo || !nuevo.trim()) return;
      try{
        await api('POST', 'usuarios_rename.php', {id, nombre: nuevo.trim()});
        if(CURRENT_USER && CURRENT_USER.id === id) CURRENT_USER.name = nuevo.trim();
        await refreshBootstrap();
        renderViewBody();
      }catch(err){ alert('No se pudo renombrar: ' + err.message); }
    });
  });
  document.querySelectorAll('[data-resetpass-btn]').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const id = parseInt(btn.dataset.resetpassBtn);
      const u = USUARIOS_ADMIN.find(x=>x.id===id);
      if(!confirm('¿Restablecer la contraseña de "'+u.nombre+'"? Se generará una contraseña temporal nueva.')) return;
      try{
        const r = await api('POST', 'usuarios_reset_password.php', {id});
        alert('Contraseña temporal para '+u.nombre+':\n\n'+r.temp_password+'\n\nCompártela por un medio seguro. Se le pedirá cambiarla en su próximo ingreso.');
        await loadUsuariosAdmin();
        renderViewBody();
      }catch(err){ alert('No se pudo restablecer: ' + err.message); }
    });
  });
  document.querySelectorAll('[data-toggle-activo]').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const id = parseInt(btn.dataset.toggleActivo);
      const activo = btn.dataset.activo === '1';
      const u = USUARIOS_ADMIN.find(x=>x.id===id);
      const verbo = activo ? 'desactivar' : 'activar';
      if(!confirm('¿Seguro que quieres '+verbo+' a "'+u.nombre+'"?')) return;
      try{
        await api('POST', 'usuarios_set_activo.php', {id, activo: !activo});
        await refreshBootstrap();
        renderViewBody();
      }catch(err){ alert('No se pudo actualizar: ' + err.message); }
    });
  });
  const reporteEjecutivoBtn = document.getElementById('reporteEjecutivoBtn');
  if(reporteEjecutivoBtn) reporteEjecutivoBtn.addEventListener('click', abrirReporteEjecutivoPDF);
  const descargarRespaldoBtn = document.getElementById('descargarRespaldoBtn');
  if(descargarRespaldoBtn) descargarRespaldoBtn.addEventListener('click', exportarRespaldo);
  const addBtn = document.getElementById('addLawyerBtn2');
  if(addBtn) addBtn.addEventListener('click', async ()=>{
    const name = prompt("Nombre completo del socio/a a agregar:");
    if(!name || !name.trim()) return;
    const email = prompt("Correo electrónico de "+name.trim()+" (será su usuario para entrar):");
    if(!email || !email.trim()) return;
    try{
      const r = await api('POST', 'usuarios_create.php', {nombre:name.trim(), email:email.trim(), rol:'abogado'});
      alert('Cuenta creada para '+r.nombre+'.\n\nCorreo: '+r.email+'\nContraseña temporal: '+r.temp_password+'\n\nCompártesela por un medio seguro (no por este sistema). Se le pedirá cambiarla en su primer ingreso.');
      await refreshBootstrap();
      renderViewBody();
    }catch(err){ alert('No se pudo crear la cuenta: ' + err.message); }
  });
  const salarioMinimoForm = document.getElementById('salarioMinimoForm');
  if(salarioMinimoForm){
    salarioMinimoForm.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const v = parseFloat(document.getElementById('salarioMinimoInput').value);
      if(isNaN(v) || v <= 0){ alert('Captura un salario mínimo diario válido.'); return; }
      try{
        await saveConfiguracion('salario_minimo_diario', v.toFixed(2));
        SALARIO_MINIMO_DIARIO = v;
        alert('Salario mínimo actualizado. La prima de antigüedad de todos los asuntos se recalculará con este valor.');
        renderViewBody();
      }catch(err){ alert('No se pudo guardar: ' + err.message); }
    });
  }
  const addDiaInhabilForm = document.getElementById('addDiaInhabilForm');
  if(addDiaInhabilForm){
    addDiaInhabilForm.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const fecha = document.getElementById('diaInhabilFecha').value;
      const descripcion = document.getElementById('diaInhabilDescripcion').value.trim();
      const ambito = document.getElementById('diaInhabilAmbito').value;
      if(!fecha || !descripcion) return;
      try{
        await api('POST', 'dias_inhabiles_create.php', {fecha, descripcion, ambito});
        await loadDiasInhabiles();
        renderViewBody();
      }catch(err){ alert('No se pudo agregar: ' + err.message); }
    });
  }
  document.querySelectorAll('[data-delete-diainhabil]').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const id = parseInt(btn.dataset.deleteDiainhabil);
      const d = DIAS_INHABILES.find(x=>x.id===id);
      if(!confirm('¿Quitar "'+(d?d.descripcion:'')+'" ('+fmtDate(d?d.fecha:'')+') del calendario de días inhábiles?')) return;
      try{
        await api('POST', 'dias_inhabiles_delete.php', {id});
        await loadDiasInhabiles();
        renderViewBody();
      }catch(err){ alert('No se pudo quitar: ' + err.message); }
    });
  });
  const savePjfCredencialesBtn = document.getElementById('savePjfCredencialesBtn');
  if(savePjfCredencialesBtn){
    savePjfCredencialesBtn.addEventListener('click', async ()=>{
      const pjf_usuario = document.getElementById('pjfUsuarioInput').value.trim();
      const pjf_password = document.getElementById('pjfPasswordInput').value;
      if(!pjf_usuario || !pjf_password){ alert('Escribe tu usuario y tu contraseña del Portal Federal.'); return; }
      savePjfCredencialesBtn.disabled = true;
      try{
        await api('POST', 'pjf_credenciales_set.php', {pjf_usuario, pjf_password});
        await loadPjfCredenciales();
        renderViewBody();
      }catch(err){ alert('No se pudo guardar: ' + err.message); }
      finally{ savePjfCredencialesBtn.disabled = false; }
    });
  }
  const borrarPjfCredencialesBtn = document.getElementById('borrarPjfCredencialesBtn');
  if(borrarPjfCredencialesBtn){
    borrarPjfCredencialesBtn.addEventListener('click', async ()=>{
      if(!confirm('¿Quitar tu cuenta guardada del Portal Federal? El robot dejará de revisar tus casos federales hasta que la vuelvas a capturar.')) return;
      try{
        await api('POST', 'pjf_credenciales_set.php', {borrar: true});
        await loadPjfCredenciales();
        renderViewBody();
      }catch(err){ alert('No se pudo quitar: ' + err.message); }
    });
  }
}

// ---------------------------------------------------------------
// Modal de expediente (uso interno)
// ---------------------------------------------------------------
async function openModal(k, tab){
  detenerCamara();
  limpiarFotosCapturadas();
  CROP_PENDING = null;
  ARCHIVO_CROP_QUEUE = [];
  ACTIVE_CASE = k; MODAL_TAB = tab || "resumen";
  if(MODAL_TAB === 'documentos') await loadDocumentos(k.id);
  renderModal();
  document.getElementById('modalOverlay').classList.add('show');
}
function closeModal(){
  detenerCamara();
  limpiarFotosCapturadas();
  CROP_PENDING = null;
  ARCHIVO_CROP_QUEUE = [];
  document.getElementById('modalOverlay').classList.remove('show');
  document.getElementById('modalOverlay').innerHTML = "";
}

function renderModal(){
  const k = ACTIVE_CASE;
  const p = computePrescripcion(k);
  const meta = getMeta(k.id);
  const overlay = document.getElementById('modalOverlay');
  overlay.innerHTML = `
  <div class="modal">
    <div class="modal-head">
      <button class="close" id="modalClose">&times;</button>
      <h2>${escapeHTML(k.actor)}</h2>
      <div class="sub">vs ${escapeHTML(k.demandado||'—')} &middot; Exp. ${escapeHTML(k.exp||'—')}</div>
    </div>
    <div class="modal-tabs">
      <button data-t="resumen" class="${MODAL_TAB==='resumen'?'active':''}">Informe del juicio</button>
      <button data-t="editar" class="${MODAL_TAB==='editar'?'active':''}">Editar datos</button>
      <button data-t="documentos" class="${MODAL_TAB==='documentos'?'active':''}">Documentos</button>
      <button data-t="etapas" class="${MODAL_TAB==='etapas'?'active':''}">Etapas del juicio</button>
      <button data-t="prescripcion" class="${MODAL_TAB==='prescripcion'?'active':''}">Prescripción</button>
      <button data-t="calculo" class="${MODAL_TAB==='calculo'?'active':''}">Cálculo de liquidación</button>
      ${tieneConvenio(k) ? `<button data-t="cobros" class="${MODAL_TAB==='cobros'?'active':''}">Cobros</button>` : ""}
      <button data-t="amparo" class="${MODAL_TAB==='amparo'?'active':''}">Amparo</button>
      ${caseStage(k)==='juicio' ? `<button data-t="boletin" class="${MODAL_TAB==='boletin'?'active':''}">Boletín ${avisosDeExpediente(k.id).filter(a=>a.estado==='nuevo').length>0?`<span class="nav-badge">${avisosDeExpediente(k.id).filter(a=>a.estado==='nuevo').length}</span>`:""}</button>` : ""}
      <button data-t="pendientes" class="${MODAL_TAB==='pendientes'?'active':''}">Pendientes</button>
      <button data-t="demanda" class="${MODAL_TAB==='demanda'?'active':''}">Generar escrito</button>
      <button data-t="hechos" class="${MODAL_TAB==='hechos'?'active':''}">Hechos del despido</button>
      <button data-t="interno" class="${MODAL_TAB==='interno'?'active':''}">Gestión interna</button>
      ${CURRENT_USER.role==='Administrador' ? `<button data-t="historial" class="${MODAL_TAB==='historial'?'active':''}">Historial de cambios</button>` : ""}
    </div>
    <div class="modal-body" id="modalBody">${modalTabContent(k,p,meta)}</div>
  </div>`;
  document.getElementById('modalClose').addEventListener('click', closeModal);
  overlay.addEventListener('click', (e)=>{ if(e.target===overlay) closeModal(); });
  document.querySelectorAll('.modal-tabs button').forEach(b=>{
    b.addEventListener('click', async ()=>{
      if(b.dataset.t !== 'documentos'){ detenerCamara(); CROP_PENDING = null; ARCHIVO_CROP_QUEUE = []; } // no dejar la cámara prendida ni una cola de recorte a medias al salir de la pestaña
      MODAL_TAB = b.dataset.t;
      if(MODAL_TAB === 'documentos') await loadDocumentos(ACTIVE_CASE.id);
      renderModal();
    });
  });
  bindModalTabEvents();
}

function fotosPanelHTML(){
  return `
  <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
    ${FOTOS_CAPTURADAS.map((f,i)=>`
      <div style="position:relative;">
        <img src="${f.url}" style="width:70px; height:70px; object-fit:cover; border-radius:6px; border:1px solid var(--border); display:block;">
        <button data-quitar-foto="${i}" title="Quitar" style="position:absolute; top:-6px; right:-6px; width:20px; height:20px; border-radius:50%; border:none; background:var(--red); color:#fff; font-size:12px; cursor:pointer; line-height:1; padding:0;">&times;</button>
      </div>`).join("")}
  </div>
  ${FOTOS_CAPTURADAS.length ? `<button class="btn" id="generarPdfFotosBtn">Generar PDF (${FOTOS_CAPTURADAS.length} foto${FOTOS_CAPTURADAS.length>1?'s':''}) y subir al expediente</button> <span id="fotosPdfStatus" style="font-size:12px; color:var(--gray); margin-left:8px;"></span>` : ''}
  `;
}

function bindFotosPanelEvents(){
  document.querySelectorAll('[data-quitar-foto]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const i = parseInt(btn.dataset.quitarFoto);
      URL.revokeObjectURL(FOTOS_CAPTURADAS[i].url);
      FOTOS_CAPTURADAS.splice(i,1);
      const panel = document.getElementById('fotosPanel');
      if(panel){ panel.innerHTML = fotosPanelHTML(); bindFotosPanelEvents(); }
    });
  });
  const generarPdfFotosBtn = document.getElementById('generarPdfFotosBtn');
  if(generarPdfFotosBtn){
    generarPdfFotosBtn.addEventListener('click', async ()=>{
      const status = document.getElementById('fotosPdfStatus');
      generarPdfFotosBtn.disabled = true;
      if(status) status.textContent = 'Generando PDF...';
      try{
        const imagenes = [];
        for(const f of FOTOS_CAPTURADAS){
          imagenes.push({bytes: new Uint8Array(await f.blob.arrayBuffer()), width: f.width, height: f.height});
        }
        const pdfBlob = buildPdfFromJpegs(imagenes);
        const categoria = document.getElementById('documentoCategoria').value;
        const nombre = 'fotos-' + (ACTIVE_CASE.exp || ACTIVE_CASE.id) + '-' + dateToISO(new Date()) + '.pdf';
        const pdfFile = new File([pdfBlob], nombre, {type:'application/pdf'});
        if(status) status.textContent = 'Subiendo...';
        await subirDocumento(ACTIVE_CASE.id, pdfFile, categoria);
        limpiarFotosCapturadas();
        detenerCamara();
        await loadDocumentos(ACTIVE_CASE.id);
        renderModal();
      }catch(err){
        if(status) status.textContent = 'Error: ' + err.message;
        generarPdfFotosBtn.disabled = false;
      }
    });
  }
}

function cropPanelHTML(){
  const r = CROP_PENDING.rect;
  const esArchivo = CROP_PENDING.origen === 'archivo' || CROP_PENDING.origen === 'archivo_multi';
  const restantes = ARCHIVO_CROP_QUEUE.length;
  return `
  <div class="notice" style="margin-bottom:12px;">El sistema ya intentó detectar el borde de la hoja. Arrastra las esquinas o los lados del recuadro para ajustarlo, o el recuadro completo para moverlo. Si la imagen salió de lado o al revés, gírala antes de confirmar.${esArchivo && restantes ? ' Después sigue con la siguiente imagen ('+restantes+' más en espera).' : ''}</div>
  <div id="cropStage" style="position:relative; width:${CROP_PENDING.dispW}px; max-width:100%; margin:0 auto 14px; touch-action:none;">
    <img id="cropImg" src="${CROP_PENDING.dataURL}" style="display:block; width:${CROP_PENDING.dispW}px; max-width:100%; height:auto; border-radius:4px;">
    <div id="cropRect" style="position:absolute; left:${r.x}px; top:${r.y}px; width:${r.w}px; height:${r.h}px; border:2px solid #fff; box-shadow:0 0 0 9999px rgba(0,0,0,.55); cursor:move;">
      <div class="crop-handle" data-corner="tl" style="position:absolute; left:-9px; top:-9px; width:18px; height:18px; background:#fff; border:2px solid var(--brass); border-radius:50%; cursor:nwse-resize;"></div>
      <div class="crop-handle" data-corner="tr" style="position:absolute; right:-9px; top:-9px; width:18px; height:18px; background:#fff; border:2px solid var(--brass); border-radius:50%; cursor:nesw-resize;"></div>
      <div class="crop-handle" data-corner="bl" style="position:absolute; left:-9px; bottom:-9px; width:18px; height:18px; background:#fff; border:2px solid var(--brass); border-radius:50%; cursor:nesw-resize;"></div>
      <div class="crop-handle" data-corner="br" style="position:absolute; right:-9px; bottom:-9px; width:18px; height:18px; background:#fff; border:2px solid var(--brass); border-radius:50%; cursor:nwse-resize;"></div>
    </div>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
    <button class="btn secondary" id="girarIzqBtn" type="button">&#8634; Girar izquierda</button>
    <button class="btn secondary" id="girarDerBtn" type="button">&#8635; Girar derecha</button>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <button class="btn" id="confirmarRecorteBtn">${CROP_PENDING.origen==='archivo' ? 'Usar este recorte y subir' : (CROP_PENDING.origen==='archivo_multi' ? 'Usar este recorte' + (restantes? ' y seguir':'') : 'Usar este recorte')}</button>
    <button class="btn secondary" id="cancelarRecorteBtn">${esArchivo ? 'Cancelar' : 'Descartar foto'}</button>
  </div>
  `;
}

function bindCropEvents(){
  const rectEl = document.getElementById('cropRect');
  if(!rectEl || !CROP_PENDING) return;
  const MIN_SIZE = 30;
  function clamp(v, lo, hi){ return Math.max(lo, Math.min(hi, v)); }
  function applyRectStyle(){
    const r = CROP_PENDING.rect;
    rectEl.style.left = r.x + 'px';
    rectEl.style.top = r.y + 'px';
    rectEl.style.width = r.w + 'px';
    rectEl.style.height = r.h + 'px';
  }
  function startDrag(e, corner){
    e.preventDefault();
    const startX = e.clientX, startY = e.clientY;
    const startRect = Object.assign({}, CROP_PENDING.rect);
    function onMove(ev){
      const dx = ev.clientX - startX, dy = ev.clientY - startY;
      let r = Object.assign({}, startRect);
      if(!corner){
        r.x = clamp(startRect.x + dx, 0, CROP_PENDING.dispW - r.w);
        r.y = clamp(startRect.y + dy, 0, CROP_PENDING.dispH - r.h);
      } else {
        if(corner.includes('l')){ const nx = clamp(startRect.x+dx, 0, startRect.x+startRect.w-MIN_SIZE); r.w = startRect.w - (nx - startRect.x); r.x = nx; }
        if(corner.includes('r')){ r.w = clamp(startRect.w+dx, MIN_SIZE, CROP_PENDING.dispW - startRect.x); }
        if(corner.includes('t')){ const ny = clamp(startRect.y+dy, 0, startRect.y+startRect.h-MIN_SIZE); r.h = startRect.h - (ny - startRect.y); r.y = ny; }
        if(corner.includes('b')){ r.h = clamp(startRect.h+dy, MIN_SIZE, CROP_PENDING.dispH - startRect.y); }
      }
      CROP_PENDING.rect = r;
      applyRectStyle();
    }
    function onUp(){
      document.removeEventListener('pointermove', onMove);
      document.removeEventListener('pointerup', onUp);
    }
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', onUp);
  }
  rectEl.addEventListener('pointerdown', (e)=>{
    if(e.target.classList.contains('crop-handle')) return;
    startDrag(e, null);
  });
  rectEl.querySelectorAll('.crop-handle').forEach(h=>{
    h.addEventListener('pointerdown', (e)=>{ e.stopPropagation(); startDrag(e, h.dataset.corner); });
  });
  const girarIzqBtn = document.getElementById('girarIzqBtn');
  if(girarIzqBtn) girarIzqBtn.addEventListener('click', ()=> rotarCropPendiente(-90));
  const girarDerBtn = document.getElementById('girarDerBtn');
  if(girarDerBtn) girarDerBtn.addEventListener('click', ()=> rotarCropPendiente(90));
  // Si quedan más imágenes en la cola (varios archivos elegidos de una
  // vez), pasa a la siguiente en el visor de recorte; si no, listo.
  function continuarColaArchivos(){
    if(ARCHIVO_CROP_QUEUE.length){
      iniciarCropArchivo(ARCHIVO_CROP_QUEUE.shift(), true);
    } else {
      CROP_PENDING = null;
      renderModal();
    }
  }
  const confirmarRecorteBtn = document.getElementById('confirmarRecorteBtn');
  if(confirmarRecorteBtn){
    confirmarRecorteBtn.addEventListener('click', ()=>{
      const {canvas, scale, rect, origen, nombreOriginal} = CROP_PENDING;
      const sx = rect.x/scale, sy = rect.y/scale, sw = rect.w/scale, sh = rect.h/scale;
      const out = document.createElement('canvas');
      out.width = Math.max(1, Math.round(sw));
      out.height = Math.max(1, Math.round(sh));
      out.getContext('2d').drawImage(canvas, sx, sy, sw, sh, 0, 0, out.width, out.height);
      out.toBlob(blob=>{
        if(!blob){ continuarColaArchivos(); return; }
        // Una sola imagen elegida del disco se sube de inmediato, ya
        // recortada; varias imágenes elegidas juntas (o fotos de cámara)
        // se van juntando en FOTOS_CAPTURADAS para armar un solo PDF,
        // bien acomodado, al terminar.
        if(origen === 'archivo'){
          const categoria = document.getElementById('documentoCategoria').value;
          const nombre = (nombreOriginal || 'documento').replace(/\.[^.]+$/, '') + '.jpg';
          const croppedFile = new File([blob], nombre, {type:'image/jpeg'});
          CROP_PENDING = null;
          renderModal();
          const status = document.getElementById('documentoStatus');
          if(status) status.textContent = 'Subiendo...';
          subirDocumento(ACTIVE_CASE.id, croppedFile, categoria)
            .then(()=> loadDocumentos(ACTIVE_CASE.id))
            .then(()=> renderModal())
            .catch(err=>{ alert('No se pudo subir el documento: ' + err.message); });
        } else {
          FOTOS_CAPTURADAS.push({blob, url: URL.createObjectURL(blob), width: out.width, height: out.height});
          continuarColaArchivos();
        }
      }, 'image/jpeg', 0.85);
    });
  }
  const cancelarRecorteBtn = document.getElementById('cancelarRecorteBtn');
  if(cancelarRecorteBtn){
    cancelarRecorteBtn.addEventListener('click', ()=>{
      // Si es una de varias imágenes en cola, "Cancelar" solo descarta
      // ésta y sigue con la siguiente; no cancela todo el lote.
      if(CROP_PENDING && CROP_PENDING.origen === 'archivo_multi'){ continuarColaArchivos(); return; }
      CROP_PENDING = null;
      renderModal();
    });
  }
}

function modalTabContent(k,p,meta){
  if(MODAL_TAB==='resumen'){
    const etapaAct = etapaActualInfo(k);
    const diasEnEtapa = etapaAct.fecha ? daysBetween(parseDate(etapaAct.fecha), new Date()) : null;
    const est = estancadoInfo(k);
    const det = juicioDetenido(k);
    const pAlerta = isPrescripcionRelevant(k) ? computePrescripcion(k) : null;
    const liqResumen = computeLiquidacion(k);
    return `
    <div class="legal-box" style="margin-bottom:16px;">
      <div style="font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--brass); margin-bottom:4px;">Situación actual del asunto</div>
      <div style="font-family:var(--serif); font-size:17px; font-weight:700; color:var(--ink); margin-bottom:4px;">${escapeHTML(etapaAct.label)}</div>
      <div style="font-size:12.5px; color:#3c4a63;">
        ${etapaAct.fecha ? `Desde el <strong>${fmtDate(etapaAct.fecha)}</strong> (${diasEnEtapa} día(s))` : 'Fecha no determinada aún'}
        &middot; ${escapeHTML(etapaAct.fundamento)} &middot; <span style="color:var(--gray);">fuente: ${escapeHTML(etapaAct.origen)}</span>
      </div>
      ${estaConcluido(k) ? `<div style="margin-top:8px;"><span class="badge closed">Asunto concluido</span></div>` : `<div style="margin-top:8px;"><span class="badge ok">Asunto activo</span></div>`}
    </div>
    ${(()=>{ const aud = proximaAudiencia(k); return aud ? `<div class="legal-box" style="border-left-color:#3c5a86; margin-bottom:14px;"><strong>&#128197; Audiencia agendada:</strong> ${escapeHTML(aud.label)} — ${fmtDate(aud.fecha)}. Esto también le aparece a tu cliente en su portal.</div>` : ''; })()}
    ${(()=>{ const pago = proximoPago(k); return pago ? `<div class="legal-box" style="border-left-color:var(--green); margin-bottom:14px;"><strong>&#128176; Pago programado:</strong> ${fmtDate(pago.fecha)}${pago.monto!=null? ' — '+fmtMoney(pago.monto) : ' — monto sin capturar'}. Esto también le aparece a tu cliente en su portal.</div>` : ''; })()}
    ${det ? `<div class="legal-box" style="border-left-color:var(--amber); margin-bottom:14px;"><strong>&#9888; Sin movimiento en ${det.dias} días.</strong> Última actividad registrada el ${fmtDate(det.ultima)}. Vale la pena revisar en qué quedó este asunto y darle seguimiento.</div>` : ''}
    ${est ? `<div class="legal-box" style="border-left-color:var(--red); margin-bottom:14px;"><strong>&#9888; Posible atraso.</strong> Se esperaba "${escapeHTML(est.siguiente)}" desde el ${fmtDate(dateToISO(est.expected))} (${est.diasAtraso} día(s) de atraso) y no se ha registrado.</div>` : ''}
    ${pAlerta ? `<div class="legal-box" style="border-left-color:${pAlerta.daysLeft<=10?'var(--red)':'var(--amber)'}; margin-bottom:14px;"><strong>Prescripción (${pAlerta.plazo.articulo}):</strong> vence el ${fmtDate(dateToISO(pAlerta.deadline))} — ${pAlerta.daysLeft} día(s) restantes.</div>` : ''}
    <div class="divider"></div>
    <div class="grid2">
      <div class="field"><label>Puesto</label><div class="v">${escapeHTML(k.puesto||'—')}</div></div>
      <div class="field"><label>Socio asignado</label><div class="v">${escapeHTML(assignedLawyer(k))}</div></div>
      <div class="field"><label>Estatus (importado)</label><div class="v">${escapeHTML(k.status||'—')}</div></div>
      <div class="field"><label>Instancia</label><div class="v">${escapeHTML(k.junta || k.instancia || '—')}</div></div>
      <div class="field"><label>Fecha de ingreso</label><div class="v">${fmtDate(k.fecha_ingreso)}</div></div>
      <div class="field"><label>Fecha de baja</label><div class="v">${fmtDate(k.fecha_baja)}</div></div>
      <div class="field"><label>Antigüedad</label><div class="v">${liqResumen? liqResumen.aniosDec.toFixed(2)+' años':'—'}</div></div>
      <div class="field"><label>Salario diario</label><div class="v">${fmtMoney(k.salario_diario)}</div></div>
      <div class="field"><label>Teléfono</label><div class="v">${escapeHTML(k.telefono||'—')}</div></div>
      <div class="field"><label>Correo</label><div class="v">${escapeHTML(k.correo||'—')}</div></div>
    </div>
    <div class="divider"></div>
    <div class="field"><label>Última actuación / nota (importada de la base original)</label><div class="v">${escapeHTML(k.ultima_nota||'—')}</div></div>
    <div class="divider"></div>
    <div style="font-family:var(--serif); font-size:15px; font-weight:700; margin-bottom:10px; color:var(--ink);">Bitácora de notas del despacho</div>
    <div id="notasBitacoraList">
      ${meta.notas_bitacora.length ? [...meta.notas_bitacora].reverse().map(n=>`
        <div style="border-left:3px solid var(--brass); background:var(--parchment); border-radius:0 8px 8px 0; padding:10px 14px; margin-bottom:8px;">
          <div style="font-size:11px; color:var(--gray); margin-bottom:3px;">${new Date(n.fecha).toLocaleString('es-MX', {day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'})} &middot; ${escapeHTML(n.usuario)}</div>
          <div style="font-size:13px; color:var(--ink); white-space:pre-wrap;">${escapeHTML(n.texto)}</div>
        </div>`).join("") : '<div class="empty">Sin notas registradas todavía.</div>'}
    </div>
    <div style="margin-top:10px;">
      <textarea id="nuevaNotaTexto" placeholder="Agregar una nueva nota de seguimiento..." style="width:100%; min-height:70px; border:1px solid var(--border); border-radius:8px; padding:9px 11px; font-size:13px; font-family:var(--sans); background:var(--parchment);"></textarea>
      <button class="btn" id="agregarNotaBtn" style="margin-top:8px;">+ Agregar nota (con fecha automática)</button>
    </div>
  `;
  }
  if(MODAL_TAB==='prescripcion'){
    if(!p) return `<div class="empty">No hay fecha de baja registrada; no es posible calcular el plazo.</div>`;
    const tieneConstancia = !!k.fecha_constancia;
    const enConciliacion = !!(k.fecha_inicio_conciliacion && !k.fecha_constancia);
    return `
    <div class="legal-box" style="margin-bottom:16px; display:flex; align-items:center; gap:10px;">
      <span style="font-size:18px;">${tieneConstancia? '&#9989;' : (enConciliacion? '&#8987;' : '&#10067;')}</span>
      <div>
        <strong>Constancia de no conciliación: ${tieneConstancia? ('Sí, recibida el ' + fmtDate(k.fecha_constancia)) : (enConciliacion? 'Aún no — conciliación en trámite desde el ' + fmtDate(k.fecha_inicio_conciliacion) : 'No registrada')}</strong>
        ${tieneConstancia? `<div style="margin-top:3px;">A partir de esta fecha se reanuda el plazo para presentar la demanda. Término límite: <strong>${fmtDate(dateToISO(p.deadline))}</strong> (${p.daysLeft} día(s) desde hoy).</div>` : ''}
      </div>
    </div>
    <div class="grid2">
      <div class="field"><label>Fecha de separación</label><div class="v">${fmtDate(k.fecha_baja)}</div></div>
      <div class="field"><label>Inicio del cómputo</label><div class="v">${fmtDate(dateToISO(p.start))}</div></div>
      <div class="field"><label>Días de suspensión (conciliación)</label><div class="v">${p.suspensionDays} día(s) ${p.enConciliacion? '— en curso, aún sin constancia':''}</div></div>
      <div class="field"><label>Fecha límite calculada</label><div class="v" style="font-weight:700; color:${p.daysLeft<=10?'var(--red)':p.daysLeft<=30?'var(--amber)':'var(--green)'}">${fmtDate(dateToISO(p.deadline))}</div></div>
      <div class="field"><label>Días restantes hoy</label><div class="v">${p.daysLeft} día(s)</div></div>
      <div class="field"><label>Ajuste por fin de semana</label><div class="v">${p.adjustedForWeekend? 'Sí, se recorrió al siguiente día hábil':'No aplicó'}</div></div>
    </div>
    <div class="legal-box">Cómputo con base en el Art. 518 de la Ley Federal del Trabajo: 60 días naturales contados uno por uno a partir del día siguiente a la separación, suspendiéndose durante el procedimiento de conciliación (desde la solicitud hasta la constancia de no conciliación, ambos días inclusive) y reanudándose al día siguiente de ésta. Verifique el calendario oficial de días inhábiles del Tribunal o Centro de Conciliación correspondiente antes de tomar decisiones procesales.</div>
    `;
  }
  if(MODAL_TAB==='calculo'){
    const liq = computeLiquidacion(k);
    if(!liq){
      return `<div class="empty">Falta fecha de ingreso, fecha de baja o salario diario para poder calcular la liquidación. Captúralos en "Editar datos".</div>`;
    }
    const sc = computeSalariosCaidos(k);
    const totalGeneral90 = liq.total90 + (sc? sc.total : 0);
    return `
    <div class="notice" style="margin-bottom:14px;">Este cálculo está pensado para imprimirse y presentarse como propuesta ante el Centro de Conciliación o el Tribunal — por eso no incluye honorarios del despacho, solo las prestaciones que corresponden a la parte actora. Los montos se calculan automáticamente a partir de fecha de ingreso, fecha de baja, salario y el salario mínimo vigente (ver "Equipo" para actualizarlo cada año).</div>
    <div class="grid2" style="margin-bottom:6px;">
      <div class="field"><label>Salario mensual</label><div class="v">${fmtMoney(k.salario_mensual)}</div></div>
      <div class="field"><label>Salario diario integrado</label><div class="v">${liq.sdiReal? fmtMoney(liq.sdiUsado) : fmtMoney(liq.sdiUsado)+' (sin integrado capturado; se usó el salario diario)'}</div></div>
    </div>

    <div class="csection">Indemnización constitucional — Art. 48 y 50 LFT</div>
    ${!liq.sdiReal ? `<div class="notice" style="border-left:3px solid var(--amber); background:var(--amber-bg);">No hay "salario diario integrado" capturado — este cálculo usa el salario diario simple (${fmtMoney(k.salario_diario)}) como aproximación. Captúralo en "Editar datos" para un resultado más exacto.</div>` : ''}
    ${liq.sdiSospechoso ? `<div class="notice" style="border-left:3px solid var(--red); background:var(--red-bg); color:var(--red);"><strong>&#9888; El "salario diario integrado" capturado (${fmtMoney(k.sdi)}) es muy distinto al salario diario (${fmtMoney(k.salario_diario)})</strong> — parece un dato mal capturado, así que este cálculo usó el salario diario simple en su lugar. Verifícalo en "Editar datos".</div>` : ''}
    ${conceptoRow('Indemnización constitucional (90 días — 3 meses de salario)', 'Art. 48 y 50, fracc. II, LFT', liq.indemnizacion90)}

    ${liq.diasDevengados > 0 ? `
    <div class="csection">Salarios devengados pendientes de pago</div>
    ${liq.devengadosPosibleprescripcion ? `<div class="notice" style="border-left:3px solid var(--amber); background:var(--amber-bg);">Parte de estos salarios devengados podría estar prescrita: el Art. 516 LFT solo permite reclamar el último año.</div>` : ''}
    ${conceptoRow('Salarios devengados pendientes (' + liq.diasDevengados + ' día(s))', 'Art. 82 LFT', liq.salariosDevengadosMonto)}
    ` : ''}

    <div class="csection">Prestaciones proporcionales</div>
    ${conceptoRow('Prima de antigüedad (12 días × ' + liq.aniosDec.toFixed(2) + ' años' + (liq.primaTopada? ', topada a 2× salario mínimo = '+fmtMoney(liq.topePrima):'') + ')', 'Art. 162 LFT', liq.primaAntiguedad)}
    ${liq.vacAntExcedePrescripcion ? `<div class="notice" style="border-left:3px solid var(--amber); background:var(--amber-bg);">Se reclaman ${liq.vacAntPedidos} días de vacaciones de periodos anteriores, pero solo ${liq.vacNoPrescrito.tot} no están prescritos (Art. 516 LFT, criterio SCJN 2a./J. 1/97) — se calculan ${liq.vacNoPrescrito.tot}.</div>` : ''}
    ${conceptoRow('Vacaciones (' + liq.vacacionesDiasProp.toFixed(2) + ' días del periodo en curso' + (liq.vacAntDias>0? ' + '+liq.vacAntDias+' de periodos anteriores':'') + ')', 'Art. 76-79 LFT' + (liq.vacAntDias>0? ' y 516 (criterio SCJN 2a./J. 1/97)':''), liq.vacacionesMonto)}
    <div class="hint" style="font-size:11px; color:var(--gray); margin:-6px 0 10px;">Máximo de días de periodos anteriores que aún no prescriben: <strong>${liq.vacNoPrescrito.tot}</strong>${liq.vacNoPrescrito.per.length? ' (año(s) '+liq.vacNoPrescrito.per.join(', ')+')' : ''}. Captura cuántos reclama en "Editar datos" si aplica.</div>
    ${conceptoRow('Prima vacacional (' + liq.primaVacacionalPct + '% sobre vacaciones' + (liq.primaVacacionalPct>25? ' — pactada por contrato':'') + ')', 'Art. 80 LFT', liq.primaVacacionalMonto)}
    ${conceptoRow('Aguinaldo proporcional (' + liq.aguinaldoDias.toFixed(2) + ' días' + (liq.aguinaldoDiasBase!==15? ', '+liq.aguinaldoDiasBase+' días pactados':'') + ')', 'Art. 87 LFT', liq.aguinaldoMonto)}

    <div class="csection">Prestaciones adicionales (manual)</div>
    <div class="notice" style="margin-bottom:10px;">Agrega aquí cualquier prestación pactada que no forme parte del cálculo estándar de ley (por ejemplo, fondo de ahorro). Se suma al total del cálculo y aparece en el PDF.</div>
    <div id="prestacionesList">
      ${meta.prestaciones_extra.map((pr,i)=>`
        <div style="display:grid; grid-template-columns:2fr 1fr auto; gap:10px; align-items:end; margin-bottom:8px;" data-prestacion-row="${i}">
          <div class="field" style="margin:0;"><label>Concepto</label><input type="text" class="prestacion-concepto" value="${escapeHTML(pr.concepto||'')}" placeholder="Ej. Fondo de ahorro"></div>
          <div class="field" style="margin:0;"><label>Monto</label><input type="text" class="prestacion-monto" value="${escapeHTML(pr.monto!=null?String(pr.monto):'')}" placeholder="$"></div>
          <button class="btn secondary" data-remove-prestacion="${i}" style="padding:8px 10px; font-size:11px;">Quitar</button>
        </div>`).join("")}
    </div>
    <button class="btn secondary" id="addPrestacionBtn" style="margin-bottom:6px;">+ Agregar prestación</button>
    <div style="margin-bottom:14px;"><button class="btn" id="savePrestacionesBtn">Guardar prestaciones adicionales</button> <span id="prestacionesStatus" style="font-size:12px; color:var(--gray); margin-left:8px;"></span></div>
    ${liq.prestacionesExtraTotal>0 ? conceptoRow('Total prestaciones adicionales', 'Capturado manualmente', liq.prestacionesExtraTotal) : ''}

    ${sc ? `
    <div class="csection">Salarios caídos e intereses — Art. 48 LFT (demanda ya presentada)</div>
    <div class="legal-box" style="margin-bottom:14px;">Si no se acredita la causa del despido, se pagan los salarios vencidos desde el día siguiente al despido hasta por un tope de 12 meses. Si al cumplirse ese plazo el juicio sigue sin concluir, se generan además intereses del 2% mensual capitalizable sobre el importe de 15 meses de salario.</div>
    ${conceptoRow('Salarios caídos (' + sc.diasCaidos + ' días desde el despido' + (sc.excedioTope?', tope de 12 meses alcanzado':'') + ')', 'Art. 48 LFT, párr. 2', sc.salariosCaidos)}
    ${sc.excedioTope ? conceptoRow('Intereses (2% mensual capitalizable, ' + sc.mesesIntereses + ' mes(es) sobre 15 meses de salario)', 'Art. 48 LFT, párr. 3', sc.intereses) : `<div class="field" style="margin-top:6px;"><label>Intereses</label><div class="v">No aplican todavía (no se ha superado el tope de 12 meses; vence el ${fmtDate(dateToISO(sc.capDate))})</div></div>`}
    ${conceptoRow('Total salarios caídos + intereses', 'Art. 48 LFT', sc.total, true)}
    <div class="notice">El interés capitalizado se calculó de forma compuesta (2% mensual sobre saldo) por ser la lectura más común del término "capitalizable" en la práctica; confírmalo si el Tribunal aplica un criterio distinto.</div>
    ` : ''}

    <div class="divider"></div>
    <div style="background:var(--ink); border-radius:10px; padding:18px 22px;">
      <div style="font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--brass-light);">Total del cálculo${sc?' (incluye salarios caídos + intereses)':''}</div>
      <div style="font-family:var(--serif); font-size:22px; font-weight:700; color:#fff;">${fmtMoney(totalGeneral90)}</div>
    </div>
    <div style="margin-top:16px;"><button class="btn secondary" id="exportarCalculoBtn">Extraer cálculo (PDF con desglose)</button></div>
    `;
  }
  if(MODAL_TAB==='cobros'){
    const monto = montoConvenio(k);
    let pagos = meta.pagos;
    if(!pagos || !pagos.length){
      const fechas = extractFechasMencionadas(k.ultima_nota);
      pagos = fechas.length ? fechas.map(f=>({fecha:f, monto:'', cobrado:false, fecha_cobro:''})) : [{fecha:'', monto:'', cobrado:false, fecha_cobro:''}];
    }
    return `
    <div class="field" style="margin-bottom:14px;"><label>Monto total del convenio (detectado en la nota)</label><div class="v" style="font-size:16px; font-weight:700;">${fmtMoney(monto)}</div></div>
    <div class="notice" style="margin-bottom:14px;">Nota original: "${escapeHTML(k.ultima_nota||'')}". Si el convenio tiene varias parcialidades, indique el monto exacto de cada una. La "fecha de cobro real" es la que alimenta el resumen de ingresos por periodo — captúrala cuando confirmes que el dinero ya llegó.</div>
    <div id="pagosList">
      ${pagos.map((p,i)=>`
        <div style="border:1px solid var(--border); border-radius:8px; padding:12px; margin-bottom:8px;" data-pago-row="${i}">
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
            <div class="field"><label>Fecha de pago programada</label><input type="date" class="pago-fecha" value="${p.fecha||''}"></div>
            <div class="field"><label>Monto (opcional)</label><input type="text" class="pago-monto" value="${escapeHTML(p.monto||'')}" placeholder="$"></div>
          </div>
          <div style="display:flex; align-items:center; gap:14px; margin-top:8px; flex-wrap:wrap;">
            <label style="display:flex; align-items:center; gap:6px; font-size:12.5px;"><input type="checkbox" class="pago-cobrado" ${p.cobrado?'checked':''}> Cobrado</label>
            <div class="field" style="margin:0;"><label>Fecha de cobro real</label><input type="date" class="pago-fecha-cobro" value="${p.fecha_cobro||''}"></div>
            <button class="btn secondary" data-remove-pago="${i}" style="padding:5px 10px; font-size:11px; margin-left:auto;">Quitar</button>
          </div>
        </div>`).join("")}
    </div>
    <button class="btn secondary" id="addPagoBtn" style="margin-top:6px;">+ Agregar fecha de pago</button>
    <div style="margin-top:18px;"><button class="btn" id="savePagosBtn">Guardar cobros</button></div>
    `;
  }
  if(MODAL_TAB==='amparo'){
    const a = computeAmparoDeadline(meta);
    return `
    <label style="display:flex; align-items:center; gap:8px; margin-bottom:14px; font-size:13px; color:var(--ink);">
      <input type="checkbox" id="amparoActivo" ${meta.amparo_activo?'checked':''}> Este asunto tiene o podría requerir amparo directo
    </label>
    <div class="grid2">
      <div class="field"><label>Fecha de notificación del laudo/sentencia</label><input type="date" id="amparoFecha" value="${meta.amparo_fecha_notif||''}"></div>
      <div class="field"><label>Vencimiento calculado (15 días hábiles)</label>
        <div class="v" style="font-weight:700; color:${a && a.daysLeft<=5? 'var(--red)': a? 'var(--ink)':'var(--gray)'}">${a? fmtDate(dateToISO(a.deadline)) + ' ('+a.daysLeft+' d.)' : '—'}</div>
      </div>
    </div>
    <div class="field" style="margin-top:14px;"><label>Notas sobre el amparo</label><textarea id="amparoNotas" placeholder="Acto reclamado, Tribunal Colegiado, número de expediente...">${escapeHTML(meta.amparo_notas||'')}</textarea></div>
    <div class="legal-box">Art. 17, párrafo primero, de la Ley de Amparo: el plazo general para presentar la demanda de amparo directo es de 15 días, contados por días hábiles a partir del día siguiente a que surta efectos la notificación de la sentencia o laudo definitivo (Art. 22 de la Ley de Amparo). Este cálculo descarta sábados, domingos y los días inhábiles capturados en "Días inhábiles"; aun así, verifica el calendario oficial del Tribunal Colegiado de Circuito correspondiente antes de tomar decisiones procesales.</div>
    <div style="margin-top:14px;"><button class="btn" id="saveAmparoBtn">Guardar datos de amparo</button></div>
    `;
  }
  if(MODAL_TAB==='boletin'){
    const avisos = avisosDeExpediente(k.id);
    return `
    <div class="notice" style="margin-bottom:16px;">Los robots de monitoreo revisan solos el boletín Federal y el de CDMX todos los días; Edomex necesita que alguien resuelva un captcha cuando el robot lo pida (aparece en Agenda). También puedes registrar aquí a mano cualquier revisión que hagas tú mismo.</div>
    <div class="grid2" style="margin-bottom:6px;">
      <div class="field"><label>Fuente revisada</label>
        <select id="avisoFuente" style="width:100%; padding:9px 11px; border:1px solid var(--border); border-radius:8px; background:var(--parchment); font-size:13px;">
          ${Object.keys(FUENTE_BOLETIN_LABEL).map(f=>`<option value="${f}">${escapeHTML(FUENTE_BOLETIN_LABEL[f])}</option>`).join("")}
        </select>
      </div>
      <div class="field"><label>Fecha del boletín revisado</label><input type="date" id="avisoFecha" value="${dateToISO(new Date())}"></div>
    </div>
    <div class="field" style="margin-top:6px;"><label>¿Qué encontraste? (o "sin novedades" si no había nada nuevo)</label><textarea id="avisoResumen" placeholder="Ej. Se dictó auto admitiendo pruebas; próxima audiencia el..."></textarea></div>
    <div class="field" style="margin-top:6px;"><label>Enlace de verificación (opcional)</label><input type="text" id="avisoUrl" placeholder="https://..."></div>
    <div style="margin-top:12px;"><button class="btn" id="addAvisoBtn">Registrar revisión</button></div>
    <div class="divider"></div>
    <div style="font-family:var(--serif); font-size:15px; font-weight:700; margin-bottom:10px; color:var(--ink);">Historial de revisiones (${avisos.length})</div>
    ${avisos.length ? avisos.map(a=>`
      <div class="aviso-card" style="${a.estado!=='nuevo'?'opacity:.75;':''}">
        <div class="aviso-head">
          <div>
            <div class="aviso-fuente">${escapeHTML(FUENTE_BOLETIN_LABEL[a.fuente]||a.fuente)} ${a.origen==='manual'?'&middot; revisión registrada a mano':'&middot; encontrado automáticamente'}</div>
          </div>
          <span class="badge ${a.estado==='nuevo'?'warn':a.estado==='descartado'?'closed':'ok'}">${a.estado}</span>
        </div>
        <div class="aviso-resumen">${escapeHTML(a.resumen)}</div>
        <div class="aviso-meta">${a.fecha_publicacion? 'Fecha del boletín: '+fmtDate(a.fecha_publicacion)+' &middot; ':''}${a.origen==='manual'?'Registrado por '+escapeHTML(a.creado_por_nombre||'—')+' el ':'Encontrado el '}${new Date(a.creado_en).toLocaleDateString('es-MX')}${a.url_verificacion? ` &middot; <a href="${escapeHTML(a.url_verificacion)}" target="_blank" rel="noopener">ver fuente</a>`:''}</div>
        ${a.estado==='nuevo' ? `<div class="aviso-actions">
          <button class="btn secondary" data-aviso-mark="${a.id}" data-estado="revisado" style="padding:6px 12px; font-size:11.5px;">Marcar revisado</button>
          <button class="btn secondary" data-aviso-mark="${a.id}" data-estado="descartado" style="padding:6px 12px; font-size:11.5px;">Descartar</button>
        </div>` : ''}
      </div>`).join("") : '<div class="empty">Sin revisiones registradas todavía.</div>'}
    `;
  }
  if(MODAL_TAB==='editar'){
    return `
    <div class="notice" style="margin-bottom:14px;">Todo lo que captures aquí sobrescribe el dato importado (si lo había) y queda registrado en el Historial de cambios. Déjalo en blanco para conservar el valor original.</div>
    <div class="grid2">
      ${EDITABLE_FIELDS.map(f=>`
        <div class="field">
          <label>${escapeHTML(f.label)}</label>
          ${f.type==='date' ?
            `<input type="date" class="edit-field" data-field="${f.key}" value="${k[f.key]||''}">` :
            f.type==='tipo_asunto' ?
            tipoAsuntoFieldHTML(k.tipo_asunto!=null ? String(k.tipo_asunto) : '') :
            f.type==='select' ?
            (()=>{
              const actual = k[f.key]!=null ? String(k[f.key]) : '';
              // Si el valor guardado no está en la lista cerrada (dato legado), se
              // agrega como primera opción para no perderlo ni cambiarlo solo.
              const extra = actual && !f.options.includes(actual) ? `<option value="${escapeHTML(actual)}" selected>${escapeHTML(actual)} (dato anterior — vuelve a elegir para estandarizar)</option>` : '';
              return `<select class="edit-field" data-field="${f.key}" style="width:100%; padding:9px 11px; border:1px solid var(--border); border-radius:8px; background:var(--parchment); font-size:13px;">
                <option value="">— Sin definir —</option>
                ${extra}
                ${f.options.map(o=>`<option value="${escapeHTML(o)}" ${actual===o?'selected':''}>${escapeHTML(o)}</option>`).join("")}
              </select>`;
            })() :
            f.type==='tribunal' ?
            tribunalFieldHTML(f, k[f.key]!=null ? String(k[f.key]) : '') :
            f.key==='ultima_nota' ?
            `<textarea class="edit-field" data-field="${f.key}">${escapeHTML(k[f.key]||'')}</textarea>` :
            `<input type="text" class="edit-field" data-field="${f.key}" value="${escapeHTML(k[f.key]!=null?k[f.key]:'')}">`
          }
        </div>`).join("")}
    </div>
    <div style="margin-top:18px;"><button class="btn" id="saveCamposBtn">Guardar datos del expediente</button></div>
    `;
  }
  if(MODAL_TAB==='documentos'){
    return `
    <div class="notice" style="margin-bottom:16px;">Sube aquí los documentos de este asunto (demanda, contestación, pruebas, actas de audiencia...). Formatos permitidos: PDF, Word, Excel e imágenes (JPG/PNG), hasta 20 MB por archivo. Si eliges una o varias imágenes, cada una se abre primero en un visor donde puedes ajustar el recuadro a las orillas del documento y girarla si salió de lado o al revés; si eliges varias juntas, al terminar se arman en un solo PDF, ya recortadas y acomodadas en orden.</div>
    <div class="grid2" style="margin-bottom:6px;">
      <div class="field"><label>Categoría</label>
        <select id="documentoCategoria" style="width:100%; padding:9px 11px; border:1px solid var(--border); border-radius:8px; background:var(--parchment); font-size:13px;">
          ${Object.keys(CATEGORIA_DOCUMENTO_LABEL).map(c=>`<option value="${c}">${escapeHTML(CATEGORIA_DOCUMENTO_LABEL[c])}</option>`).join("")}
        </select>
      </div>
      <div class="field"><label>Archivo (puedes elegir varias imágenes a la vez)</label><input type="file" id="documentoArchivo" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" multiple></div>
    </div>
    <div style="margin-top:8px;"><button class="btn" id="subirDocumentoBtn">Subir documento</button> <span id="documentoStatus" style="font-size:12px; color:var(--gray); margin-left:8px;"></span></div>
    <div class="divider"></div>
    <div style="font-family:var(--serif); font-size:15px; font-weight:700; margin-bottom:10px; color:var(--ink);">Tomar fotos con la cámara</div>
    <div class="notice" style="margin-bottom:12px;">Toma todas las fotos que necesites (actas, pruebas, identificaciones...); al terminar se arma un solo PDF y se sube automáticamente al expediente, usando la categoría elegida arriba.</div>
    ${CROP_PENDING ? cropPanelHTML() : `
    <div style="margin-bottom:10px;">
      <video id="camaraVideo" autoplay playsinline muted style="width:100%; max-width:380px; border-radius:8px; background:#000; display:${CAMARA_STREAM ? 'block' : 'none'};"></video>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
      <button class="btn secondary" id="abrirCamaraBtn" style="${CAMARA_STREAM ? 'display:none;' : ''}">📷 Abrir cámara</button>
      <button class="btn" id="capturarFotoBtn" style="${CAMARA_STREAM ? '' : 'display:none;'}">Tomar foto</button>
      <button class="btn secondary" id="cerrarCamaraBtn" style="${CAMARA_STREAM ? '' : 'display:none;'}">Cerrar cámara</button>
    </div>
    `}
    <div id="fotosPanel">${fotosPanelHTML()}</div>
    <div class="divider"></div>
    <div style="font-family:var(--serif); font-size:15px; font-weight:700; margin-bottom:10px; color:var(--ink);">Documentos subidos (${DOCUMENTOS_ACTIVOS.length})</div>
    ${DOCUMENTOS_ACTIVOS.length ? `<table><thead><tr><th>Archivo</th><th>Categoría</th><th>Tamaño</th><th>Subido por</th><th>Fecha</th><th></th></tr></thead><tbody>
      ${DOCUMENTOS_ACTIVOS.map(d=>`
        <tr>
          <td>${escapeHTML(d.nombre_archivo)}</td>
          <td>${escapeHTML(CATEGORIA_DOCUMENTO_LABEL[d.categoria] || d.categoria)}</td>
          <td>${fmtBytes(d.tamano_bytes)}</td>
          <td>${escapeHTML(d.subido_por_nombre || '—')}</td>
          <td style="font-size:11.5px;">${new Date(d.creado_en).toLocaleDateString('es-MX')}</td>
          <td style="white-space:nowrap;">
            ${esVisualizableInline(d.nombre_archivo) ? `<a class="btn secondary" href="${API_BASE}documentos_descargar.php?id=${d.id}&inline=1" target="_blank" rel="noopener" style="padding:5px 10px; font-size:11px; text-decoration:none; display:inline-block;">Ver</a>` : ""}
            <a class="btn secondary" href="${API_BASE}documentos_descargar.php?id=${d.id}" style="padding:5px 10px; font-size:11px; text-decoration:none; display:inline-block;">Descargar</a>
            <button class="btn secondary" data-delete-documento="${d.id}" style="padding:5px 10px; font-size:11px;">Eliminar</button>
          </td>
        </tr>`).join("")}
    </tbody></table>` : '<div class="empty">Sin documentos subidos todavía.</div>'}
    `;
  }
  if(MODAL_TAB==='historial'){
    const hist = [...meta.historial].reverse();
    return `
    <div class="notice" style="margin-bottom:14px;">Visible solo para el Administrador. Registra cada cambio a los datos capturados manualmente de este asunto.</div>
    ${hist.length ? `<table><thead><tr><th>Fecha</th><th>Usuario</th><th>Campo</th><th>Antes</th><th>Después</th></tr></thead><tbody>
      ${hist.map(h=>`<tr><td style="font-size:11px;">${new Date(h.ts).toLocaleString('es-MX')}</td><td>${escapeHTML(h.usuario)}</td><td>${escapeHTML(h.campo)}</td><td style="color:var(--gray); font-size:11.5px;">${escapeHTML(h.antes||'—')}</td><td style="font-size:11.5px;">${escapeHTML(h.despues||'—')}</td></tr>`).join("")}
    </tbody></table>` : '<div class="empty">Sin cambios registrados todavía.</div>'}
    `;
  }
  if(MODAL_TAB==='demanda'){
    const faltantes = ['actor','demandado','giro_empresa','dom_demandado','puesto','fecha_ingreso','fecha_baja','salario_mensual','salario_diario']
      .filter(f=> k[f]===null || k[f]===undefined || k[f]==='');
    return `
    ${faltantes.length ? `<div class="legal-box" style="border-left-color:var(--amber); margin-bottom:14px;"><strong>Faltan datos para un escrito completo:</strong> ${faltantes.map(f=>{const d=EDITABLE_FIELDS.find(x=>x.key===f); return escapeHTML(d?d.label:f);}).join(', ')}. Complétalos en "Editar datos" para un mejor resultado; mientras tanto se dejarán líneas en blanco.</div>` : ''}
    <div class="notice" style="margin-bottom:14px;">Genera el documento Word (.docx) real, con el formato original del despacho, combinando la plantilla que elijas con los datos de este expediente. Administra las plantillas disponibles en "Formato de demanda" (menú, solo Administrador).</div>
    <div class="field" style="margin-bottom:14px; max-width:360px;"><label>Plantilla a usar</label>
      <select id="escritoPlantillaSelect" style="width:100%; padding:9px 11px; border:1px solid var(--border); border-radius:8px; background:var(--parchment); font-size:13px;">
        <option value="demanda">Demanda (plantilla del despacho)</option>
        ${PLANTILLAS_LIB.map(p=>`<option value="${p.id}">${escapeHTML(p.nombre)}</option>`).join("")}
      </select>
    </div>
    <button class="btn" id="genDemandaBtn">Generar documento en Word (.docx)</button>
    <span id="genDemandaStatus" style="margin-left:10px; font-size:12px; color:var(--gray);"></span>
    `;
  }
  if(MODAL_TAB==='hechos') return `
    <div class="grid2">
      <div class="field"><label>Giro de la empresa</label><div class="v">${escapeHTML(k.giro_empresa||'—')}</div></div>
      <div class="field"><label>Hora del despido</label><div class="v">${escapeHTML(k.hora_despido||'—')}</div></div>
      <div class="field"><label>Quién despidió</label><div class="v">${escapeHTML(k.quien_despidio||'—')}</div></div>
      <div class="field"><label>Puesto de quien despidió</label><div class="v">${escapeHTML(k.puesto_despidio||'—')}</div></div>
    </div>
    <div class="divider"></div>
    <div class="field"><label>Testigos</label><div class="v">${escapeHTML(k.testigos||'—')}</div></div>
    <div class="field" style="margin-top:10px;"><label>Domicilios de testigos</label><div class="v">${escapeHTML(k.dom_testigos||'—')}</div></div>
    <div class="field" style="margin-top:10px;"><label>Domicilio del demandado</label><div class="v">${escapeHTML(k.dom_demandado||'—')}</div></div>
  `;
  if(MODAL_TAB==='interno') return `
    <div class="legal-box" style="margin-bottom:18px;">
      <div style="font-family:var(--serif); font-weight:700; font-size:14px; color:var(--ink); margin-bottom:8px;">Registrar convenio o pago</div>
      <div style="font-size:12px; color:#3c4a63; margin-bottom:10px;">Márcalo aquí en cuanto haya un convenio o un pago acordado — se guarda el monto y la fecha, y aparece automáticamente en "Cobros" y en la Agenda para darle seguimiento.</div>
      <label style="display:flex; align-items:center; gap:8px; margin-bottom:10px; font-size:12.5px; color:var(--ink);">
        <input type="checkbox" id="convenioManualActivo" ${meta.convenio_manual.activo?'checked':''}> Hubo convenio o pago acordado en este asunto
      </label>
      <div class="grid2">
        <div class="field"><label>Monto</label><input type="text" id="convenioManualMonto" value="${escapeHTML(meta.convenio_manual.monto||'')}" placeholder="$"></div>
        <div class="field"><label>Fecha a pagar</label><input type="date" id="convenioManualFecha" value="${meta.convenio_manual.fecha_pago||''}"></div>
      </div>
      <div style="margin-top:10px;"><button class="btn" id="saveConvenioManualBtn">Guardar convenio/pago</button> <span id="avisoClienteConvenio"></span></div>
    </div>
    <div class="field" style="margin-bottom:14px;">
      <label>Socio asignado</label>
      <select id="lawyerSelect" style="width:100%; padding:9px 11px; border:1px solid var(--border); border-radius:8px; background:var(--parchment); font-size:13px;">
        <option value="" ${!k.abogado_id?'selected':''}>Sin asignar</option>
        ${EQUIPO.map(u=>`<option value="${u.id}" ${k.abogado_id===u.id?'selected':''}>${escapeHTML(u.name)}</option>`).join("")}
      </select>
    </div>
    <div class="field" style="margin-top:14px;">
      <label>Notas internas del despacho</label>
      <textarea id="notasInternas" placeholder="Notas de seguimiento, acuerdos, pendientes...">${escapeHTML(meta.notas||'')}</textarea>
    </div>
    <label style="display:flex; align-items:center; gap:8px; margin-top:14px; font-size:12.5px; color:var(--ink);">
      <input type="checkbox" id="cobroPendiente" ${meta.cobro_pendiente?'checked':''}> Convenio pagado pendiente de cobro / seguimiento de pago
    </label>
    <label style="display:flex; align-items:center; gap:8px; margin-top:10px; font-size:12.5px; color:var(--ink);">
      <input type="checkbox" id="concluidoManual" ${meta.concluido_manual?'checked':''}> Marcar este asunto como <strong>Concluido</strong> (deja de contar como activo en tableros y filtros)
    </label>
    <div style="margin-top:18px;"><button class="btn" id="saveMetaBtn">Guardar cambios</button></div>
    <div class="divider"></div>
    <div style="font-family:var(--serif); font-weight:700; font-size:15px; color:var(--ink); margin-bottom:6px;">Acceso del cliente al portal</div>
    <div class="notice" style="margin-bottom:10px;">Comparte con tu cliente su apellido y este código, para que consulte su asunto en "Acceder como cliente" — sin este código no puede ver nada.</div>
    <div id="codigoAccesoBox" style="font-family:var(--mono); font-size:13px; color:var(--gray);">Cargando código de acceso…</div>
    <button class="btn secondary" id="regenCodigoBtn" style="margin-top:8px;">Generar código nuevo (invalida el anterior)</button>
  `;
  if(MODAL_TAB==='etapas'){
    const estancado = estancadoInfo(k);
    return `
    ${estancado ? `<div class="legal-box" style="border-left-color:var(--red); margin-bottom:16px;">
      <strong>&#9888; Este asunto podría estar atorado.</strong> Última etapa registrada: "${escapeHTML(estancado.ultima)}". Debió haberse registrado "${escapeHTML(estancado.siguiente)}" antes del ${fmtDate(dateToISO(estancado.expected))} (${estancado.diasAtraso} día(s) de atraso). Verifica el estado real ante el Tribunal.
    </div>` : ''}
    <div class="notice" style="margin-bottom:14px;">Marca cada etapa conforme vaya ocurriendo. Al registrar "Demanda presentada" el sistema deja de contar la prescripción de este asunto automáticamente. En las audiencias puedes capturar la fecha en que están agendadas aunque todavía no se celebren — eso es lo que le aparece a tu cliente como "próxima audiencia".</div>
    ${ETAPAS_DEF.map(def=>{
      const val = k_etapa_valor(meta, def.key);
      const esAudiencia = def.key==='audiencia_preliminar' || def.key==='audiencia_juicio';
      const esSentencia = def.key==='sentencia';
      const programada = (meta.etapas[def.key] && meta.etapas[def.key].fecha_programada) || '';
      const resultado = (meta.etapas[def.key] && meta.etapas[def.key].resultado) || '';
      const hora = (meta.etapas[def.key] && meta.etapas[def.key].hora) || '';
      return `<div style="display:flex; align-items:center; gap:12px; padding:10px 0; border-bottom:1px solid #f0ece1; flex-wrap:wrap;" data-etapa-row="${def.key}">
        <input type="checkbox" class="etapa-check" data-etapa="${def.key}" ${val?'checked':''} style="width:16px;height:16px;">
        <div style="flex:1; min-width:200px;">
          <div style="font-size:13px; font-weight:600; color:var(--ink);">${escapeHTML(def.label)}</div>
          <div style="font-size:11px; color:var(--gray);">${escapeHTML(def.fundamento)}${def.plazoLabel? ' — '+escapeHTML(def.plazoLabel):''}</div>
        </div>
        ${esSentencia ? `<div style="text-align:right;"><label style="font-size:10px; color:var(--gray); display:block;">Resultado</label>
          <select class="etapa-resultado" data-etapa="${def.key}" style="padding:6px 8px; border:1px solid var(--brass-dim); border-radius:6px; font-size:12px;">
            <option value="">Sin registrar</option>
            <option value="favorable" ${resultado==='favorable'?'selected':''}>Favorable</option>
            <option value="parcial" ${resultado==='parcial'?'selected':''}>Parcialmente favorable</option>
            <option value="desfavorable" ${resultado==='desfavorable'?'selected':''}>Desfavorable</option>
          </select></div>` : ''}
        ${esAudiencia ? `<div style="text-align:right;"><label style="font-size:10px; color:var(--gray); display:block;">Fecha agendada</label><input type="date" class="etapa-fecha-programada" data-etapa="${def.key}" value="${programada}" style="padding:6px 8px; border:1px solid var(--brass-dim); border-radius:6px; font-size:12px;"></div>` : ''}
        <div style="text-align:right;"><label style="font-size:10px; color:var(--gray); display:block;">${esAudiencia?'Fecha en que se celebró':''}</label><input type="date" class="etapa-fecha" data-etapa="${def.key}" value="${val||''}" style="padding:6px 8px; border:1px solid var(--border); border-radius:6px; font-size:12px;"></div>
        ${def.conHora ? `<div style="text-align:right;"><label style="font-size:10px; color:var(--gray); display:block;">Hora</label><input type="time" class="etapa-hora" data-etapa="${def.key}" value="${hora}" style="padding:6px 8px; border:1px solid var(--border); border-radius:6px; font-size:12px;"></div>` : ''}
      </div>`;
    }).join("")}
    <div style="margin-top:18px;"><button class="btn" id="saveEtapasBtn">Guardar etapas</button> <span id="avisoClienteEtapas"></span></div>
    `;
  }
  if(MODAL_TAB==='pendientes'){
    const items = meta.pendientes || [];
    return `
    <div class="notice" style="margin-bottom:14px;">Usa esto para cualquier término o actuación que no esté en la lista fija de etapas: audiencias programadas, objeciones, desahogos, vistas, recordatorios, etc.</div>
    <div id="pendientesList">
      ${items.map((it,i)=>`
        <div style="border:1px solid var(--border); border-radius:8px; padding:12px; margin-bottom:10px;" data-pend-row="${i}">
          <div class="field" style="margin-bottom:8px;"><label>Descripción</label><input type="text" class="pend-desc" value="${escapeHTML(it.descripcion||'')}" placeholder="p. ej. Objeciones a pruebas, término de 8 días"></div>
          <div style="display:grid; grid-template-columns:1fr 1fr 1fr auto; gap:8px; align-items:end;">
            <div class="field"><label>Fecha de inicio</label><input type="date" class="pend-inicio" value="${it.fecha_inicio||''}"></div>
            <div class="field"><label>Días</label><input type="text" class="pend-dias" value="${it.dias!=null?it.dias:''}" placeholder="p. ej. 8"></div>
            <div class="field"><label>Tipo</label>
              <select class="pend-tipo" style="width:100%; padding:8px; border:1px solid var(--border); border-radius:6px; background:var(--parchment); font-size:12.5px;">
                <option value="habiles" ${it.tipo==='habiles'?'selected':''}>Hábiles</option>
                <option value="naturales" ${it.tipo==='naturales'?'selected':''}>Naturales</option>
                <option value="fecha_fija" ${it.tipo==='fecha_fija'?'selected':''}>Fecha fija (usar solo inicio)</option>
              </select>
            </div>
            <button class="btn secondary" data-remove-pend="${i}" style="padding:8px 10px; font-size:11px;">Quitar</button>
          </div>
          <label style="display:flex; align-items:center; gap:6px; font-size:12px; margin-top:8px;"><input type="checkbox" class="pend-cumplido" ${it.cumplido?'checked':''}> Cumplido</label>
        </div>`).join("") || '<div class="empty">Sin pendientes registrados.</div>'}
    </div>
    <button class="btn secondary" id="addPendBtn">+ Agregar pendiente</button>
    <div style="margin-top:18px;"><button class="btn" id="savePendBtn">Guardar pendientes</button></div>
    `;
  }
}

function k_etapa_valor(meta, key){
  return meta.etapas[key] && meta.etapas[key].fecha ? meta.etapas[key].fecha : '';
}

function readPagosFromDOM(){
  const rows = document.querySelectorAll('[data-pago-row]');
  const pagos = [];
  rows.forEach(r=>{
    const fecha = r.querySelector('.pago-fecha').value;
    const monto = r.querySelector('.pago-monto').value;
    const cobrado = r.querySelector('.pago-cobrado').checked;
    const fcInput = r.querySelector('.pago-fecha-cobro');
    let fecha_cobro = fcInput ? fcInput.value : '';
    if(cobrado && !fecha_cobro) fecha_cobro = dateToISO(new Date());
    pagos.push({fecha, monto, cobrado, fecha_cobro});
  });
  return pagos;
}

function readPrestacionesFromDOM(){
  const rows = document.querySelectorAll('[data-prestacion-row]');
  const prestaciones = [];
  rows.forEach(r=>{
    const concepto = r.querySelector('.prestacion-concepto').value.trim();
    const monto = r.querySelector('.prestacion-monto').value;
    if(concepto) prestaciones.push({concepto, monto});
  });
  return prestaciones;
}

function readPendientesFromDOM(){
  const rows = document.querySelectorAll('[data-pend-row]');
  const items = [];
  rows.forEach(r=>{
    items.push({
      descripcion: r.querySelector('.pend-desc').value,
      fecha_inicio: r.querySelector('.pend-inicio').value,
      dias: r.querySelector('.pend-dias').value,
      tipo: r.querySelector('.pend-tipo').value,
      cumplido: r.querySelector('.pend-cumplido').checked
    });
  });
  return items;
}

// Carga un archivo de imagen elegido del disco (no de la cámara) en un
// <img> para poder dibujarlo a un canvas — mismo punto de partida que usa
// la captura de cámara para poder reusar el visor de recorte.
function cargarImagenDesdeArchivo(file){
  return new Promise((resolve, reject)=>{
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = ()=>{ URL.revokeObjectURL(url); resolve(img); };
    img.onerror = ()=>{ URL.revokeObjectURL(url); reject(new Error('No se pudo leer la imagen.')); };
    img.src = url;
  });
}

// Abre una imagen elegida del disco en el visor de recorte. esMulti=true
// cuando viene de un lote de varias imágenes elegidas juntas: al confirmar
// se junta con las demás en FOTOS_CAPTURADAS (en vez de subirse sola) para
// armar un solo PDF, bien acomodado, con todas al terminar la cola.
async function iniciarCropArchivo(file, esMulti){
  const status = document.getElementById('documentoStatus');
  try{
    const img = await cargarImagenDesdeArchivo(file);
    const canvas = document.createElement('canvas');
    canvas.width = img.naturalWidth;
    canvas.height = img.naturalHeight;
    canvas.getContext('2d').drawImage(img, 0, 0);
    const rectReal = detectarBordeHoja(canvas);
    const dispW = Math.min(320, canvas.width);
    const scale = dispW / canvas.width;
    const dispH = Math.round(canvas.height * scale);
    CROP_PENDING = {
      canvas, scale, dispW, dispH,
      dataURL: canvas.toDataURL('image/jpeg', 0.9),
      rect: {x: rectReal.x*scale, y: rectReal.y*scale, w: rectReal.w*scale, h: rectReal.h*scale},
      origen: esMulti ? 'archivo_multi' : 'archivo', nombreOriginal: file.name,
    };
    if(status) status.textContent = '';
    renderModal();
  }catch(err){
    if(status) status.textContent = 'No se pudo abrir "' + file.name + '": ' + err.message;
    // si venía de un lote, no se atora ahí — sigue con la siguiente imagen
    if(esMulti && ARCHIVO_CROP_QUEUE.length) await iniciarCropArchivo(ARCHIVO_CROP_QUEUE.shift(), true);
    else renderModal();
  }
}

function bindModalTabEvents(){
  bindTribunalFieldEvents();
  bindTipoAsuntoField();
  const subirDocumentoBtn = document.getElementById('subirDocumentoBtn');
  if(subirDocumentoBtn){
    subirDocumentoBtn.addEventListener('click', async ()=>{
      const input = document.getElementById('documentoArchivo');
      const categoria = document.getElementById('documentoCategoria').value;
      const status = document.getElementById('documentoStatus');
      const files = Array.from(input.files || []);
      if(!files.length){ if(status) status.textContent = 'Elige uno o más archivos primero.'; return; }
      const esImagen = f => ['jpg','jpeg','png'].includes((f.name.split('.').pop() || '').toLowerCase());
      const imagenes = files.filter(esImagen);
      const otros = files.filter(f => !esImagen(f));

      // PDF/Word/Excel se suben tal cual, sin recorte.
      if(otros.length){
        subirDocumentoBtn.disabled = true;
        if(status) status.textContent = 'Subiendo...';
        try{
          for(const f of otros) await subirDocumento(ACTIVE_CASE.id, f, categoria);
          await loadDocumentos(ACTIVE_CASE.id);
        }catch(err){
          if(status) status.textContent = 'Error: ' + err.message;
          subirDocumentoBtn.disabled = false;
          return;
        }
        subirDocumentoBtn.disabled = false;
      }
      // Las imágenes pasan primero por el visor de recorte (con giro),
      // para poder ajustarlas a las orillas del documento antes de
      // subirlas — igual que las fotos tomadas con la cámara. Si se
      // eligió más de una junta, se recortan una por una y se arman en un
      // solo PDF al terminar; si es una sola, se sube directo al confirmar.
      if(imagenes.length > 1){
        ARCHIVO_CROP_QUEUE = imagenes.slice(1);
        await iniciarCropArchivo(imagenes[0], true);
      } else if(imagenes.length === 1){
        ARCHIVO_CROP_QUEUE = [];
        await iniciarCropArchivo(imagenes[0], false);
      } else {
        renderModal();
      }
    });
  }
  // Si el modal se volvió a renderizar (p.ej. al confirmar/cancelar un
  // recorte) y la cámara seguía abierta, el <video> es nuevo y perdió el
  // stream — hay que reconectarlo sin pedir permiso de nuevo.
  const camaraVideoEl = document.getElementById('camaraVideo');
  if(camaraVideoEl && CAMARA_STREAM && !camaraVideoEl.srcObject){
    camaraVideoEl.srcObject = CAMARA_STREAM;
  }
  const abrirCamaraBtn = document.getElementById('abrirCamaraBtn');
  if(abrirCamaraBtn){
    abrirCamaraBtn.addEventListener('click', async ()=>{
      if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
        alert('Este navegador no permite abrir la cámara desde aquí. Actualiza el navegador o usa uno más reciente.');
        return;
      }
      try{
        CAMARA_STREAM = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}, width:{ideal:1600}}, audio:false});
        const video = document.getElementById('camaraVideo');
        video.srcObject = CAMARA_STREAM;
        video.style.display = 'block';
        abrirCamaraBtn.style.display = 'none';
        document.getElementById('capturarFotoBtn').style.display = '';
        document.getElementById('cerrarCamaraBtn').style.display = '';
      }catch(err){
        alert('No se pudo abrir la cámara: ' + err.message + '. Revisa que le hayas dado permiso de cámara a esta página.');
      }
    });
  }
  const capturarFotoBtn = document.getElementById('capturarFotoBtn');
  if(capturarFotoBtn){
    capturarFotoBtn.addEventListener('click', ()=>{
      const video = document.getElementById('camaraVideo');
      if(!video || !video.videoWidth) return;
      const canvas = document.createElement('canvas');
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      canvas.getContext('2d').drawImage(video, 0, 0);
      const rectReal = detectarBordeHoja(canvas);
      const dispW = Math.min(320, canvas.width);
      const scale = dispW / canvas.width;
      const dispH = Math.round(canvas.height * scale);
      CROP_PENDING = {
        canvas, scale, dispW, dispH,
        dataURL: canvas.toDataURL('image/jpeg', 0.85),
        rect: {x: rectReal.x*scale, y: rectReal.y*scale, w: rectReal.w*scale, h: rectReal.h*scale},
        origen: 'camara',
      };
      renderModal();
    });
  }
  const cerrarCamaraBtn = document.getElementById('cerrarCamaraBtn');
  if(cerrarCamaraBtn){
    cerrarCamaraBtn.addEventListener('click', ()=>{ detenerCamara(); renderModal(); });
  }
  bindFotosPanelEvents();
  bindCropEvents();
  document.querySelectorAll('[data-delete-documento]').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const id = parseInt(btn.dataset.deleteDocumento);
      const d = DOCUMENTOS_ACTIVOS.find(x=>x.id===id);
      if(!confirm('¿Eliminar el documento "'+(d?d.nombre_archivo:'')+'"? Esta acción no se puede deshacer.')) return;
      try{
        await api('POST', 'documentos_delete.php', {id});
        await loadDocumentos(ACTIVE_CASE.id);
        renderModal();
      }catch(err){ alert('No se pudo eliminar: ' + err.message); }
    });
  });
  const exportarCalculoBtn = document.getElementById('exportarCalculoBtn');
  if(exportarCalculoBtn){
    exportarCalculoBtn.addEventListener('click', ()=> abrirCalculoPDF(ACTIVE_CASE));
  }
  const genDemandaBtn = document.getElementById('genDemandaBtn');
  if(genDemandaBtn){
    genDemandaBtn.addEventListener('click', async ()=>{
      const status = document.getElementById('genDemandaStatus');
      const select = document.getElementById('escritoPlantillaSelect');
      const elegido = select ? select.value : 'demanda';
      genDemandaBtn.disabled = true;
      if(status) status.textContent = 'Generando...';
      const onDone = ()=>{ genDemandaBtn.disabled = false; if(status) status.textContent = 'Listo ✓ revisa tus descargas.'; };
      const onError = (err)=>{ genDemandaBtn.disabled = false; if(status) status.textContent = 'Error: ' + err.message; };
      if(elegido === 'demanda'){
        generarDemandaDocx(ACTIVE_CASE, onDone, onError);
      } else {
        try{
          const p = await api('GET', 'plantillas_get.php?id=' + elegido);
          generarDocxDesdePlantilla(ACTIVE_CASE, p.base64, p.nombre, onDone, onError);
        }catch(err){ onError(err); }
      }
    });
  }
  const agregarNotaBtn = document.getElementById('agregarNotaBtn');
  if(agregarNotaBtn){
    agregarNotaBtn.addEventListener('click', async ()=>{
      const texto = document.getElementById('nuevaNotaTexto').value.trim();
      if(!texto) return;
      agregarNotaBtn.disabled = true;
      try{
        await api('POST', 'expedientes_add_nota.php', {id: ACTIVE_CASE.id, texto});
        await refreshBootstrap();
        ACTIVE_CASE = findCase(ACTIVE_CASE.id);
        renderModal();
        renderViewBody();
        if(confirm('Nota guardada. ¿Quieres avisarle al cliente el avance por WhatsApp?')){
          abrirWhatsAppCliente(ACTIVE_CASE);
        }
      }catch(err){ alert('No se pudo guardar la nota: ' + err.message); agregarNotaBtn.disabled = false; }
    });
  }
  const saveConvenioManualBtn = document.getElementById('saveConvenioManualBtn');
  if(saveConvenioManualBtn){
    saveConvenioManualBtn.addEventListener('click', async ()=>{
      const activo = document.getElementById('convenioManualActivo').checked;
      const monto = document.getElementById('convenioManualMonto').value;
      const fecha_pago = document.getElementById('convenioManualFecha').value;
      try{
        // El servidor ya refleja el convenio también en la ficha de pagos (Cobros)
        // cuando queda activo, para que alimente la Agenda.
        await api('POST', 'expedientes_update_convenio.php', {id: ACTIVE_CASE.id, convenio_manual: {activo, monto, fecha_pago}});
        saveConvenioManualBtn.textContent = "Guardado ✓ — ya está en Cobros y en la Agenda";
        setTimeout(()=>{ if(saveConvenioManualBtn) saveConvenioManualBtn.textContent = "Guardar convenio/pago"; }, 2200);
        await refreshBootstrap();
        syncGoogleIfConnected();
        ACTIVE_CASE = findCase(ACTIVE_CASE.id);
        const avisoSpanConv = document.getElementById('avisoClienteConvenio');
        if(avisoSpanConv){
          avisoSpanConv.innerHTML = `<button class="btn secondary" id="avisarWhatsAppConvenioBtn" style="margin-left:8px;">📱 Avisar al cliente por WhatsApp</button>`;
          document.getElementById('avisarWhatsAppConvenioBtn').addEventListener('click', ()=> abrirWhatsAppCliente(ACTIVE_CASE));
        }
        renderViewBody();
      }catch(err){ alert('No se pudo guardar: ' + err.message); }
    });
  }
  const saveCamposBtn = document.getElementById('saveCamposBtn');
  if(saveCamposBtn){
    saveCamposBtn.addEventListener('click', async ()=>{
      const campos = {};
      document.querySelectorAll('.edit-field').forEach(inp=>{
        const field = inp.dataset.field;
        const nuevo = inp.value;
        const anterior = ACTIVE_CASE[field] != null ? String(ACTIVE_CASE[field]) : '';
        // Dejar el campo en blanco conserva el valor actual (no lo borra).
        if(nuevo !== anterior && nuevo !== '') campos[field] = nuevo;
      });
      saveCamposBtn.disabled = true;
      try{
        await persistTribunalesCustomNuevos();
        await api('POST', 'expedientes_update_campos.php', {id: ACTIVE_CASE.id, campos});
        saveCamposBtn.textContent = "Guardado ✓";
        setTimeout(()=>{ if(saveCamposBtn) saveCamposBtn.textContent = "Guardar datos del expediente"; }, 1600);
        await refreshBootstrap();
        syncGoogleIfConnected();
        ACTIVE_CASE = findCase(ACTIVE_CASE.id);
        renderModal();
        renderViewBody();
      }catch(err){ alert('No se pudo guardar: ' + err.message); }
      finally{ saveCamposBtn.disabled = false; }
    });
  }
  const saveEtapasBtn = document.getElementById('saveEtapasBtn');
  if(saveEtapasBtn){
    saveEtapasBtn.addEventListener('click', async ()=>{
      const etapas = {};
      document.querySelectorAll('[data-etapa-row]').forEach(row=>{
        const key = row.dataset.etapaRow;
        const checked = row.querySelector('.etapa-check').checked;
        const fecha = row.querySelector('.etapa-fecha').value;
        const progInput = row.querySelector('.etapa-fecha-programada');
        const fecha_programada = progInput ? progInput.value : '';
        const resInput = row.querySelector('.etapa-resultado');
        const resultado = resInput ? resInput.value : '';
        const horaInput = row.querySelector('.etapa-hora');
        const hora = horaInput ? horaInput.value : '';
        if(checked || fecha || fecha_programada || resultado || hora) etapas[key] = {fecha: (checked||fecha) ? (fecha || dateToISO(new Date())) : '', fecha_programada, resultado, hora};
      });
      try{
        // El servidor activa amparo_activo/amparo_presentado automáticamente
        // si se registra fecha en la etapa "Amparo directo presentado".
        await api('POST', 'expedientes_update_etapas.php', {id: ACTIVE_CASE.id, etapas});
        saveEtapasBtn.textContent = "Guardado ✓";
        setTimeout(()=>{ if(saveEtapasBtn) saveEtapasBtn.textContent = "Guardar etapas"; }, 1600);
        await refreshBootstrap();
        syncGoogleIfConnected();
        ACTIVE_CASE = findCase(ACTIVE_CASE.id);
        const avisoSpan = document.getElementById('avisoClienteEtapas');
        if(avisoSpan){
          avisoSpan.innerHTML = `<button class="btn secondary" id="avisarWhatsAppEtapasBtn" style="margin-left:8px;">📱 Avisar al cliente por WhatsApp</button>`;
          document.getElementById('avisarWhatsAppEtapasBtn').addEventListener('click', ()=> abrirWhatsAppCliente(findCase(ACTIVE_CASE.id)));
        }
        renderViewBody();
      }catch(err){ alert('No se pudo guardar: ' + err.message); }
    });
  }
  const addPendBtn = document.getElementById('addPendBtn');
  if(addPendBtn){
    addPendBtn.addEventListener('click', ()=>{
      const list = document.getElementById('pendientesList');
      const empty = list.querySelector('.empty');
      if(empty) empty.remove();
      const div = document.createElement('div');
      div.style.cssText = 'border:1px solid var(--border); border-radius:8px; padding:12px; margin-bottom:10px;';
      div.setAttribute('data-pend-row', '');
      div.innerHTML = `
        <div class="field" style="margin-bottom:8px;"><label>Descripción</label><input type="text" class="pend-desc" placeholder="p. ej. Objeciones a pruebas, término de 8 días"></div>
        <div style="display:grid; grid-template-columns:1fr 1fr 1fr auto; gap:8px; align-items:end;">
          <div class="field"><label>Fecha de inicio</label><input type="date" class="pend-inicio"></div>
          <div class="field"><label>Días</label><input type="text" class="pend-dias" placeholder="p. ej. 8"></div>
          <div class="field"><label>Tipo</label>
            <select class="pend-tipo" style="width:100%; padding:8px; border:1px solid var(--border); border-radius:6px; background:var(--parchment); font-size:12.5px;">
              <option value="habiles">Hábiles</option>
              <option value="naturales">Naturales</option>
              <option value="fecha_fija">Fecha fija (usar solo inicio)</option>
            </select>
          </div>
          <button type="button" class="btn secondary" data-remove-pend-row style="padding:8px 10px; font-size:11px;">Quitar</button>
        </div>
        <label style="display:flex; align-items:center; gap:6px; font-size:12px; margin-top:8px;"><input type="checkbox" class="pend-cumplido"> Cumplido</label>`;
      list.appendChild(div);
      div.querySelector('[data-remove-pend-row]').addEventListener('click', ()=> div.remove());
    });
  }
  document.querySelectorAll('[data-remove-pend]').forEach(btn=>{
    btn.addEventListener('click', ()=>{ btn.closest('[data-pend-row]').remove(); });
  });
  const savePendBtn = document.getElementById('savePendBtn');
  if(savePendBtn){
    savePendBtn.addEventListener('click', async ()=>{
      try{
        await api('POST', 'expedientes_update_pendientes.php', {id: ACTIVE_CASE.id, pendientes: readPendientesFromDOM()});
        savePendBtn.textContent = "Guardado ✓";
        setTimeout(()=>{ if(savePendBtn) savePendBtn.textContent = "Guardar pendientes"; }, 1600);
        await refreshBootstrap();
        ACTIVE_CASE = findCase(ACTIVE_CASE.id);
        renderModal();
        renderViewBody();
      }catch(err){ alert('No se pudo guardar: ' + err.message); }
    });
  }
  const addPagoBtn = document.getElementById('addPagoBtn');
  if(addPagoBtn){
    addPagoBtn.addEventListener('click', ()=>{
      const list = document.getElementById('pagosList');
      const div = document.createElement('div');
      div.style.cssText = 'border:1px solid var(--border); border-radius:8px; padding:12px; margin-bottom:8px;';
      div.setAttribute('data-pago-row', '');
      div.innerHTML = `
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
          <div class="field"><label>Fecha de pago programada</label><input type="date" class="pago-fecha"></div>
          <div class="field"><label>Monto (opcional)</label><input type="text" class="pago-monto" placeholder="$"></div>
        </div>
        <div style="display:flex; align-items:center; gap:14px; margin-top:8px; flex-wrap:wrap;">
          <label style="display:flex; align-items:center; gap:6px; font-size:12.5px;"><input type="checkbox" class="pago-cobrado"> Cobrado</label>
          <div class="field" style="margin:0;"><label>Fecha de cobro real</label><input type="date" class="pago-fecha-cobro"></div>
          <button type="button" class="btn secondary" data-remove-pago-row style="padding:5px 10px; font-size:11px; margin-left:auto;">Quitar</button>
        </div>`;
      list.appendChild(div);
      div.querySelector('[data-remove-pago-row]').addEventListener('click', ()=> div.remove());
    });
  }
  document.querySelectorAll('[data-remove-pago]').forEach(btn=>{
    btn.addEventListener('click', ()=>{ btn.closest('[data-pago-row]').remove(); });
  });
  const savePagosBtn = document.getElementById('savePagosBtn');
  if(savePagosBtn){
    savePagosBtn.addEventListener('click', async ()=>{
      try{
        await api('POST', 'expedientes_update_pagos.php', {id: ACTIVE_CASE.id, pagos: readPagosFromDOM()});
        savePagosBtn.textContent = "Guardado ✓";
        setTimeout(()=>{ if(savePagosBtn) savePagosBtn.textContent = "Guardar cobros"; }, 1600);
        await refreshBootstrap();
        syncGoogleIfConnected();
        ACTIVE_CASE = findCase(ACTIVE_CASE.id);
        renderModal();
        renderViewBody();
      }catch(err){ alert('No se pudo guardar: ' + err.message); }
    });
  }
  const addPrestacionBtn = document.getElementById('addPrestacionBtn');
  if(addPrestacionBtn){
    addPrestacionBtn.addEventListener('click', ()=>{
      const list = document.getElementById('prestacionesList');
      const div = document.createElement('div');
      div.style.cssText = 'display:grid; grid-template-columns:2fr 1fr auto; gap:10px; align-items:end; margin-bottom:8px;';
      div.setAttribute('data-prestacion-row', '');
      div.innerHTML = `
        <div class="field" style="margin:0;"><label>Concepto</label><input type="text" class="prestacion-concepto" placeholder="Ej. Fondo de ahorro"></div>
        <div class="field" style="margin:0;"><label>Monto</label><input type="text" class="prestacion-monto" placeholder="$"></div>
        <button type="button" class="btn secondary" data-remove-prestacion-row style="padding:8px 10px; font-size:11px;">Quitar</button>`;
      list.appendChild(div);
      div.querySelector('[data-remove-prestacion-row]').addEventListener('click', ()=> div.remove());
    });
  }
  document.querySelectorAll('[data-remove-prestacion]').forEach(btn=>{
    btn.addEventListener('click', ()=>{ btn.closest('[data-prestacion-row]').remove(); });
  });
  const savePrestacionesBtn = document.getElementById('savePrestacionesBtn');
  if(savePrestacionesBtn){
    savePrestacionesBtn.addEventListener('click', async ()=>{
      const status = document.getElementById('prestacionesStatus');
      savePrestacionesBtn.disabled = true;
      try{
        await api('POST', 'expedientes_update_prestaciones.php', {id: ACTIVE_CASE.id, prestaciones: readPrestacionesFromDOM()});
        if(status) status.textContent = 'Guardado ✓';
        setTimeout(()=>{ if(status) status.textContent = ''; }, 1600);
        await refreshBootstrap();
        ACTIVE_CASE = findCase(ACTIVE_CASE.id);
        renderModal();
      }catch(err){
        if(status) status.textContent = 'Error: ' + err.message;
      } finally {
        savePrestacionesBtn.disabled = false;
      }
    });
  }
  const saveAmparoBtn = document.getElementById('saveAmparoBtn');
  if(saveAmparoBtn){
    saveAmparoBtn.addEventListener('click', async ()=>{
      try{
        await api('POST', 'expedientes_update_amparo.php', {
          id: ACTIVE_CASE.id,
          amparo_activo: document.getElementById('amparoActivo').checked,
          amparo_fecha_notif: document.getElementById('amparoFecha').value,
          amparo_notas: document.getElementById('amparoNotas').value
        });
        saveAmparoBtn.textContent = "Guardado ✓";
        setTimeout(()=>{ if(saveAmparoBtn) saveAmparoBtn.textContent = "Guardar datos de amparo"; }, 1600);
        await refreshBootstrap();
        syncGoogleIfConnected();
        ACTIVE_CASE = findCase(ACTIVE_CASE.id);
        renderModal();
        renderViewBody();
      }catch(err){ alert('No se pudo guardar: ' + err.message); }
    });
  }
  const addAvisoBtn = document.getElementById('addAvisoBtn');
  if(addAvisoBtn){
    addAvisoBtn.addEventListener('click', async ()=>{
      const resumen = document.getElementById('avisoResumen').value.trim();
      if(!resumen){ alert('Escribe qué encontraste (o "sin novedades").'); return; }
      addAvisoBtn.disabled = true;
      try{
        await api('POST', 'avisos_create.php', {
          expediente_id: ACTIVE_CASE.id,
          fuente: document.getElementById('avisoFuente').value,
          fecha_publicacion: document.getElementById('avisoFecha').value,
          resumen,
          url_verificacion: document.getElementById('avisoUrl').value.trim(),
        });
        await loadAvisosBoletin();
        ACTIVE_CASE = findCase(ACTIVE_CASE.id);
        renderModal();
      }catch(err){ alert('No se pudo registrar: ' + err.message); }
      finally{ addAvisoBtn.disabled = false; }
    });
  }
  const codigoBox = document.getElementById('codigoAccesoBox');
  if(codigoBox){
    api('GET', 'expedientes_access_code.php?id=' + ACTIVE_CASE.id)
      .then(r=>{ if(codigoBox) codigoBox.innerHTML = `<span class="badge codigo" style="font-size:13px; padding:6px 12px;">${escapeHTML(r.codigo_acceso)}</span>`; })
      .catch(err=>{ if(codigoBox) codigoBox.textContent = 'No se pudo cargar: ' + err.message; });
  }
  const regenCodigoBtn = document.getElementById('regenCodigoBtn');
  if(regenCodigoBtn){
    regenCodigoBtn.addEventListener('click', async ()=>{
      if(!confirm('¿Generar un código nuevo? El código anterior dejará de funcionar de inmediato.')) return;
      try{
        const r = await api('POST', 'expedientes_access_code.php', {id: ACTIVE_CASE.id});
        const box = document.getElementById('codigoAccesoBox');
        if(box) box.innerHTML = `<span class="badge codigo" style="font-size:13px; padding:6px 12px;">${escapeHTML(r.codigo_acceso)}</span>`;
      }catch(err){ alert('No se pudo generar el código: ' + err.message); }
    });
  }
  const saveBtn = document.getElementById('saveMetaBtn');
  if(saveBtn){
    saveBtn.addEventListener('click', async ()=>{
      try{
        await api('POST', 'expedientes_update_interno.php', {
          id: ACTIVE_CASE.id,
          notas: document.getElementById('notasInternas').value,
          cobro_pendiente: document.getElementById('cobroPendiente').checked,
          concluido_manual: document.getElementById('concluidoManual').checked,
          abogado_id: document.getElementById('lawyerSelect').value
        });
        saveBtn.textContent = "Guardado ✓";
        setTimeout(()=>{ if(saveBtn) saveBtn.textContent = "Guardar cambios"; }, 1600);
        await refreshBootstrap();
        ACTIVE_CASE = findCase(ACTIVE_CASE.id);
        renderViewBody();
      }catch(err){ alert('No se pudo guardar: ' + err.message); }
    });
  }
}

// ---------------------------------------------------------------
// Vista previa del portal-cliente para un caso (desde "Vista de portal cliente")
// ---------------------------------------------------------------
function openClientPreviewModal(k){
  const overlay = document.getElementById('modalOverlay');
  overlay.innerHTML = `<div class="modal" style="max-width:560px;">
    <div class="modal-head"><button class="close" id="modalClose">&times;</button>
      <h2>Vista previa — portal cliente</h2><div class="sub">Lo que vería ${escapeHTML(k.actor)}</div>
    </div>
    <div class="modal-body">${clientCardHTML(k)}</div>
  </div>`;
  overlay.classList.add('show');
  document.getElementById('modalClose').addEventListener('click', closeModal);
  overlay.addEventListener('click', (e)=>{ if(e.target===overlay) closeModal(); });
}

// ---------------------------------------------------------------
// Portal de cliente real
// ---------------------------------------------------------------
function clientPortalHTML(){
  return `<div id="clientArea">${clientSearchHTML()}</div>`;
}
function clientSearchHTML(){
  return `
  <div class="client-search">
    <h2>Consulta tu asunto</h2>
    <p>Ingresa tu apellido paterno y el código de acceso que te proporcionó el despacho, para ver el estatus.</p>
    <input type="text" id="cApellido" placeholder="Tu apellido paterno">
    <input type="text" id="cCodigo" placeholder="Código de acceso" style="text-transform:uppercase;">
    <button class="btn" id="cSearchBtn" style="width:100%; margin-top:6px;">Consultar</button>
    <div id="cResult"></div>
    <div class="notice" style="text-align:left;">¿No tienes tu código de acceso? Contacta directamente a tu abogado/a en el despacho.</div>
    <div style="margin-top:16px;"><button class="link-btn" id="backToStaffBtn" type="button">&#8592; Soy parte del despacho, iniciar sesión</button></div>
  </div>`;
}
function bindClientPortal(){
  const btn = document.getElementById('cSearchBtn');
  if(!btn) return;
  const back = document.getElementById('backToStaffBtn');
  if(back) back.addEventListener('click', ()=>{ CURRENT_USER = null; VIEW = 'tablero'; render(); });
  btn.addEventListener('click', async ()=>{
    const ape = (document.getElementById('cApellido').value||'').trim();
    const codigo = (document.getElementById('cCodigo').value||'').trim();
    const res = document.getElementById('cResult');
    if(!ape || !codigo){ res.innerHTML = `<div class="notice">Ingresa tu apellido y código de acceso para continuar.</div>`; return; }
    btn.disabled = true; btn.textContent = 'Consultando…';
    try{
      const data = await api('POST', 'portal_cliente.php', {apellido: ape, codigo});
      CLIENT_CASE = data.expediente;
      res.innerHTML = `<div class="client-result">${clientCardHTML(CLIENT_CASE)}</div>`;
    }catch(err){
      res.innerHTML = `<div class="notice">${escapeHTML(err.message)}</div>`;
    }finally{
      btn.disabled = false; btn.textContent = 'Consultar';
    }
  });
}

// ---------------------------------------------------------------
// Traducción de las etapas técnicas del juicio a lenguaje llano para el
// cliente — sin fundamentos legales ni tecnicismos, para que entienda su
// caso sin tener que llamar a preguntar qué significa lo que ve.
// ---------------------------------------------------------------
const ETAPA_CLIENTE_LABEL = {
  conciliacion_prejudicial: 'Tu abogado(a) buscó un arreglo directo con la empresa',
  conciliacion_solicitada: 'Se inició el trámite de conciliación con la empresa',
  conciliacion_primer_citatorio: 'Tienes tu primera cita de conciliación con la empresa',
  conciliacion_segundo_citatorio: 'Tienes tu segunda cita de conciliación con la empresa',
  conciliacion_convenio: 'Se llegó a un convenio con la empresa',
  constancia_no_conciliacion: 'No se llegó a un acuerdo con la empresa; se procede a demandar',
  demanda_presentada: 'Se presentó tu demanda ante el Tribunal',
  prevencion: 'El Tribunal pidió corregir o aclarar algo de la demanda',
  demanda_admitida: 'El Tribunal admitió tu demanda',
  emplazamiento: 'Se notificó oficialmente a la empresa de la demanda',
  contestacion_recibida: 'La empresa ya respondió a la demanda',
  objeciones_replica: 'Se están revisando las pruebas presentadas por la empresa',
  contrarreplica: 'La empresa respondió a nuestras observaciones sobre sus pruebas',
  manifestaciones_3dias: 'Última revisión de pruebas antes de la audiencia',
  audiencia_preliminar: 'Se realizó la audiencia donde se organizó tu caso',
  audiencia_juicio: 'Se realizó la audiencia donde se presentaron las pruebas',
  sentencia: 'El Tribunal ya emitió su resolución',
  amparo_directo: 'Se presentó un amparo para revisar la resolución',
};
const ETAPA_CLIENTE_SIGUIENTE = {
  conciliacion_prejudicial: 'Si no hay arreglo directo, se presentará la solicitud de conciliación ante el Centro correspondiente.',
  conciliacion_solicitada: 'Se espera la audiencia de conciliación con la empresa.',
  conciliacion_primer_citatorio: 'Se espera el resultado de esa cita de conciliación.',
  conciliacion_segundo_citatorio: 'Se espera el resultado de esa cita de conciliación.',
  conciliacion_convenio: 'Tu abogado(a) le dará seguimiento al cumplimiento del convenio.',
  constancia_no_conciliacion: 'Tu abogado(a) está preparando la demanda para presentarla ante el Tribunal.',
  demanda_presentada: 'El Tribunal debe admitir la demanda y ordenar notificar a la empresa.',
  prevencion: 'Tu abogado(a) corregirá o aclarará lo que pidió el Tribunal para que se admita la demanda.',
  demanda_admitida: 'Se notificará formalmente a la empresa demandada.',
  emplazamiento: 'Se espera la respuesta de la empresa a la demanda.',
  contestacion_recibida: 'Se revisarán las pruebas que ofreció la empresa.',
  objeciones_replica: 'La empresa podrá responder a nuestras observaciones sobre sus pruebas.',
  contrarreplica: 'Se preparará la audiencia donde se organizará el caso.',
  manifestaciones_3dias: 'Se preparará la audiencia donde se organizará el caso.',
  audiencia_preliminar: 'Se espera la fecha de la audiencia donde se presentan las pruebas.',
  audiencia_juicio: 'Se espera la resolución del Tribunal.',
  sentencia: 'Tu abogado(a) revisará si conviene alguna acción adicional o se procede a hacerla cumplir.',
  amparo_directo: 'Se espera la resolución del Tribunal Colegiado sobre el amparo.',
};

// Igual que etapaActualInfo, pero en el idioma que le sirve al cliente: sin
// fundamentos legales ni nombres técnicos.
function clienteEstadoInfo(kase){
  const meta = getMeta(kase.id);
  let ultimaIdx = -1;
  for(let i=0;i<ETAPAS_DEF.length;i++){
    if(meta.etapas[ETAPAS_DEF[i].key] && meta.etapas[ETAPAS_DEF[i].key].fecha) ultimaIdx = i;
  }
  if(ultimaIdx >= 0){
    const def = ETAPAS_DEF[ultimaIdx];
    return {
      label: ETAPA_CLIENTE_LABEL[def.key] || def.label,
      fecha: meta.etapas[def.key].fecha,
      hora: def.conHora ? (meta.etapas[def.key].hora || null) : null,
      siguiente: ETAPA_CLIENTE_SIGUIENTE[def.key] || 'Tu abogado(a) te informará del siguiente paso.',
      completado: []
    };
  }
  const stage = caseStage(kase);
  if(stage === 'pagado') return {label:'Tu caso concluyó: el convenio ya fue pagado.', fecha: kase.fecha_baja, siguiente: null};
  if(stage === 'desistio') return {label:'Este caso fue cerrado.', fecha: kase.fecha_baja, siguiente: null};
  if(stage === 'convenio') return {label:'Se llegó a un convenio con la empresa; está pendiente el pago.', fecha: null, siguiente: 'En cuanto se confirme el pago, tu abogado(a) te avisará.'};
  if(stage === 'juicio') return {label:'Tu demanda ya fue presentada; el caso está en trámite ante el Tribunal.', fecha: kase.fecha_baja, siguiente: 'Tu abogado(a) le dará seguimiento a cada paso del juicio.'};
  if(stage === 'constancia_demanda') return {label:'Terminó la conciliación sin acuerdo; tu abogado(a) está preparando la demanda.', fecha: kase.fecha_constancia, siguiente: ETAPA_CLIENTE_SIGUIENTE.constancia_no_conciliacion};
  if(kase.fecha_inicio_conciliacion) return {label:'Se está llevando a cabo el proceso de conciliación con la empresa.', fecha: kase.fecha_inicio_conciliacion, siguiente: ETAPA_CLIENTE_SIGUIENTE.conciliacion_solicitada};
  return {label:'Tu caso quedó registrado; tu abogado(a) está iniciando el trámite de conciliación.', fecha: kase.fecha_baja, siguiente: 'Se presentará la solicitud de conciliación ante el Centro correspondiente.'};
}

function clientCardHTML(k){
  const meta = getMeta(k.id);
  const estado = clienteEstadoInfo(k);
  const dias = estado.fecha ? daysBetween(parseDate(estado.fecha), new Date()) : null;
  const steps = clientTimeline(k);
  const conv = tieneConvenio(k);
  return `
  <div class="panel" style="text-align:left;">
    <div class="panel-body" style="padding:22px 24px;">
      <div style="font-family:var(--serif); font-size:18px; font-weight:700; color:var(--ink);">${escapeHTML(k.actor)}</div>
      <div style="font-size:12px; color:var(--gray); margin-top:2px; margin-bottom:16px;">Expediente ${escapeHTML(k.exp||'—')} &middot; ${escapeHTML(assignedLawyer(k))}</div>

      <div class="legal-box" style="margin-bottom:16px;">
        <div style="font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:var(--brass); margin-bottom:4px;">Cómo va tu caso ahora mismo</div>
        <div style="font-size:15px; font-weight:700; color:var(--ink); line-height:1.4;">${escapeHTML(estado.label)}</div>
        ${estado.fecha ? `<div style="font-size:12px; color:#3c4a63; margin-top:4px;">${estado.hora ? 'El '+fmtDate(estado.fecha)+' a las '+escapeHTML(estado.hora)+' hrs' : 'Desde el '+fmtDate(estado.fecha)+(dias!=null? ' ('+dias+' día(s))':'')}</div>` : ''}
      </div>

      ${estado.siguiente ? `<div style="background:var(--parchment); border-radius:8px; padding:12px 14px; margin-bottom:16px;">
        <div style="font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:var(--gray); margin-bottom:3px;">¿Qué sigue?</div>
        <div style="font-size:13px; color:var(--ink);">${escapeHTML(estado.siguiente)}</div>
      </div>` : ''}

      ${(()=>{ const aud = proximaAudiencia(k); return aud ? `<div style="background:var(--amber-bg); border:1px solid var(--brass-dim); border-radius:8px; padding:12px 14px; margin-bottom:16px;">
        <div style="font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:#6b4d18; margin-bottom:3px;">&#128197; Tienes una audiencia programada</div>
        <div style="font-size:15px; font-weight:700; color:var(--ink);">${fmtDate(aud.fecha)}</div>
        <div style="font-size:12.5px; color:#6b4d18; margin-top:2px;">${escapeHTML(aud.label)}</div>
      </div>` : ''; })()}

      ${(()=>{ const pago = proximoPago(k); return pago ? `<div style="background:var(--green-bg); border:1px solid #bcd9c8; border-radius:8px; padding:12px 14px; margin-bottom:16px;">
        <div style="font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:var(--green); margin-bottom:3px;">&#128176; Tienes un pago programado</div>
        <div style="font-size:15px; font-weight:700; color:var(--ink);">${fmtDate(pago.fecha)}</div>
        <div style="font-size:12.5px; color:var(--green); margin-top:2px;">${pago.monto!=null ? fmtMoney(pago.monto) : 'Monto por confirmar con tu abogado(a)'}</div>
      </div>` : ''; })()}

      ${conv ? (()=>{
        const monto = montoConvenio(k);
        const pagos = meta.pagos || [];
        const pagados = pagos.filter(p=>p.cobrado);
        return `<div style="background:var(--green-bg); border-radius:8px; padding:12px 14px; margin-bottom:16px;">
          <div style="font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:var(--green); margin-bottom:5px;">Convenio — monto total</div>
          ${monto!=null ? `<div style="font-size:14px; color:var(--ink); font-weight:700;">${fmtMoney(monto)}</div>` : ''}
          ${pagados.length ? `<div style="font-size:12.5px; color:var(--ink); margin-top:4px;">✓ Ya se recibió el pago del ${pagados.map(p=>fmtDate(p.fecha_cobro||p.fecha)).join(', ')}.</div>` : ''}
        </div>`;
      })() : ''}

      <div style="font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:var(--gray); margin-bottom:6px;">Lo que ya se hizo en tu caso</div>
      <div class="status-timeline">${steps}</div>
    </div>
  </div>
  <div class="notice" style="text-align:left;">Si tienes alguna duda que no quede clara aquí, comunícate directamente con tu abogado/a del despacho.</div>
  `;
}

function clientTimeline(k){
  const meta = getMeta(k.id);
  const stages = [{label:'Se registró tu despido', done:true, date:k.fecha_baja}];
  let algunaEtapaManual = false;
  ETAPAS_DEF.forEach(def=>{
    if(meta.etapas[def.key] && meta.etapas[def.key].fecha){
      algunaEtapaManual = true;
      stages.push({label: ETAPA_CLIENTE_LABEL[def.key] || def.label, done:true, date: meta.etapas[def.key].fecha, hora: def.conHora ? (meta.etapas[def.key].hora || null) : null});
    }
  });
  if(!algunaEtapaManual){
    // usar lo que ya se pueda inferir de los datos importados
    if(k.fecha_inicio_conciliacion) stages.push({label:'Se inició el trámite de conciliación con la empresa', done:true, date:k.fecha_inicio_conciliacion});
    if(k.fecha_constancia) stages.push({label:'No se llegó a un acuerdo con la empresa; se procede a demandar', done:true, date:k.fecha_constancia});
    if(caseStage(k)==='juicio') stages.push({label:'Se presentó tu demanda ante el Tribunal', done:true, date:null});
    if(caseStage(k)==='pagado') stages.push({label:'Se recibió el pago del convenio', done:true, date:null});
  }
  return stages.map(s=>`
    <div class="tl-item done">
      <div class="tl-dot">&#10003;</div>
      <div class="tl-text"><div class="t">${escapeHTML(s.label)}</div>${s.date?`<div class="d">${fmtDate(s.date)}${s.hora?' a las '+escapeHTML(s.hora)+' hrs':''}</div>`:''}</div>
    </div>`).join("");
}

// ---------------------------------------------------------------
// Inicio
// ---------------------------------------------------------------
async function init(){
  try{
    const data = await api('GET', 'auth_me.php');
    if(data.user){
      CSRF_TOKEN = data.csrf;
      if(data.user.debe_cambiar_password){
        PENDING_USER = {id:data.user.id, name:data.user.nombre, email:data.user.email, role:mapRol(data.user.rol)};
        GATE_MODE = 'change_password';
      } else {
        CURRENT_USER = {id:data.user.id, name:data.user.nombre, email:data.user.email, role:mapRol(data.user.rol)};
        await refreshBootstrap();
        await loadPlantillaDocx();
      }
    }
  }catch(e){ /* no hay sesión activa: se queda en la pantalla de acceso */ }
  render();

  const googleParam = new URLSearchParams(location.search).get('google');
  if(googleParam){
    history.replaceState(null, '', location.pathname);
    if(googleParam === 'ok') alert('Tu cuenta de Google Calendar quedó conectada.');
    else if(googleParam === 'error_denegado') alert('Cancelaste el acceso a Google Calendar — no se conectó nada.');
    else alert('No se pudo conectar con Google Calendar. Intenta de nuevo.');
  }

  // Se abrió la app desde una notificación push (ver sw.js) — abre directo
  // esa conversación en vez de dejar al usuario en la pantalla general.
  const abrirParam = new URLSearchParams(location.search).get('abrir');
  if(abrirParam){
    history.replaceState(null, '', location.pathname);
    abrirConversacionDesdeNotificacion(abrirParam);
  }
}
init();

// La app ya estaba abierta en una pestaña y le dieron clic a una
// notificación push — sw.js le avisa por mensaje para abrir esa
// conversación ahí mismo, sin recargar ni abrir una pestaña nueva.
if('serviceWorker' in navigator){
  navigator.serviceWorker.addEventListener('message', (event)=>{
    if(event.data && event.data.type === 'abrir-conversacion' && event.data.url){
      const telefono = new URL(event.data.url, location.origin).searchParams.get('abrir');
      if(telefono) abrirConversacionDesdeNotificacion(telefono);
    }
  });
}

