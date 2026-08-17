// Robot de monitoreo del Portal de Servicios en Línea del Poder Judicial de
// la Federación (Tribunales Laborales). Revisa la cuenta de CADA abogado
// del despacho que haya guardado su usuario/contraseña del portal desde
// "Mi cuenta" (ver api/pjf_credenciales_*.php) -- el portal solo muestra
// los acuerdos de los expedientes donde el usuario logueado aparece como
// autorizado, por eso hace falta revisar una cuenta a la vez. Reporta al
// sistema los acuerdos que correspondan a expedientes con tribunal federal
// capturado. Pensado para correr una vez al día vía cron -- ver README.md.
const axios = require('axios');
const { wrapper } = require('axios-cookiejar-support');
const { CookieJar } = require('tough-cookie');
const cheerio = require('cheerio');
const config = require('./config');

const PJF_BASE = 'https://www.serviciosenlinea.pjf.gob.mx/juicioenlinea';

function nuevoClientePJF() {
  const jar = new CookieJar();
  return wrapper(axios.create({ jar, timeout: 20000 }));
}

async function loginPJF(cliente, usuario, password) {
  const params = new URLSearchParams();
  params.append('EntidadExterna', 'Laboral');
  params.append('UserName', usuario);
  params.append('UserPassword', password);
  params.append('tipo', '3');
  const res = await cliente.post(`${PJF_BASE}/juicioenlinea/Home/Login`, params.toString(), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  const data = typeof res.data === 'string' ? JSON.parse(res.data) : res.data;
  if (!data.success) throw new Error('Login PJF falló: ' + data.error);
}

// Las tablas de "Acuerdos Recientes"/"Acuerdos Pendientes" vienen ya
// renderizadas por el servidor (sin llamada AJAX aparte) -- si no hay
// novedades, simplemente no tienen <tbody>, lo cual es normal.
async function fetchAcuerdos(cliente, vista) {
  const url = `${PJF_BASE}/juicioenlinea/OrganoJurisdiccional/${vista}?Principal=True`;
  const res = await cliente.get(url);
  const $ = cheerio.load(res.data);
  const rows = [];
  $('#tableOficialia tbody tr').each((_, tr) => {
    const cells = $(tr).find('td').map((__, td) => $(td).text().trim()).get();
    if (cells.length < 7) return;
    const [expediente, tipoAsunto, materia, tribunal, parte, fecha, sintesis] = cells;
    rows.push({ expediente, tipoAsunto, materia, tribunal, parte, fecha, sintesis });
  });
  return rows;
}

function normalizeExp(s) {
  return (s || '').replace(/\s+/g, '').toUpperCase();
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

async function revisarCuenta(cuenta, porNumero) {
  console.log(`--- Cuenta: ${cuenta.nombre} (${cuenta.pjf_usuario}) ---`);
  const cliente = nuevoClientePJF();
  await loginPJF(cliente, cuenta.pjf_usuario, cuenta.pjf_password);
  console.log('  Login PJF OK.');

  const vistas = ['VerAcuerdosRecientes', 'VerAcuerdosPendientes'];
  let reportados = 0;
  for (const vista of vistas) {
    const filas = await fetchAcuerdos(cliente, vista);
    console.log(`  ${vista}: ${filas.length} fila(s) en el portal.`);
    for (const f of filas) {
      const match = porNumero.get(normalizeExp(f.expediente));
      if (!match) continue; // no es un expediente que monitoreamos
      const resumen = `${f.tipoAsunto} — ${f.materia} — ${f.tribunal} — Parte: ${f.parte}. ${f.sintesis}`;
      await reportarAviso(match.id, resumen, parseFechaMX(f.fecha));
      reportados++;
      console.log(`    Aviso reportado: expediente ${f.expediente}`);
    }
  }
  console.log(`  ${reportados} aviso(s) nuevo(s) para esta cuenta.`);
  return reportados;
}

async function main() {
  console.log(new Date().toISOString(), 'Iniciando revisión Federal...');

  const expedientes = await obtenerExpedientesMonitoreados();
  const porNumero = new Map();
  for (const e of expedientes) {
    const candidatos = [e.junta, e.tribunal].filter(Boolean);
    const esFederal = candidatos.some(c => /FEDERAL/i.test(c));
    if (esFederal && e.exp) porNumero.set(normalizeExp(e.exp), e);
  }
  console.log(`${porNumero.size} expediente(s) con tribunal federal capturado.`);

  const cuentas = await obtenerCuentasPJF();
  console.log(`${cuentas.length} cuenta(s) del Portal Federal guardada(s).`);
  if (!cuentas.length) {
    console.log('Nadie ha guardado su cuenta del Portal Federal en "Mi cuenta" todavía.');
    return;
  }

  let totalReportados = 0;
  for (const cuenta of cuentas) {
    try {
      totalReportados += await revisarCuenta(cuenta, porNumero);
    } catch (e) {
      console.log(`  Error con la cuenta de ${cuenta.nombre}: ${e.message}`);
    }
  }
  console.log(`Listo. ${totalReportados} aviso(s) nuevo(s) reportado(s) en total.`);
}

main().catch(err => {
  console.error('Error en checker Federal:', err.message);
  process.exit(1);
});
