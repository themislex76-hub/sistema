// Script de diagnóstico temporal (no es parte del robot normal) -- entra
// directo al detalle de una tesis específica en sjf2.scjn.gob.mx y
// muestra su clasificación real de Materia(s), para confirmar por qué el
// filtro "Materia = Laboral" del robot la está incluyendo o excluyendo.
// Uso: node diagnostico_materia.js <registro_digital>
// (correr en la misma máquina/carpeta donde ya corre jurisprudencia.js,
// con "npm install" hecho ahí una vez).
const { chromium } = require('playwright');

const registro = process.argv[2];
if (!registro) {
  console.error('Uso: node diagnostico_materia.js <registro_digital>');
  process.exit(1);
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  await page.goto('https://sjf2.scjn.gob.mx/detalle/tesis/' + registro, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);
  const texto = await page.locator('body').innerText();

  const instancia = /Instancia:\s*([^\n]+)/.exec(texto)?.[1]?.trim() || '(no encontrado)';
  const epoca = /(\S+\s+[EÉ]poca)/.exec(texto)?.[1]?.trim() || '(no encontrado)';
  const materias = /Materia\(s\):\s*([^\n]+)/.exec(texto)?.[1]?.trim() || '(no encontrado)';

  console.log('=== Registro ' + registro + ' ===');
  console.log('Instancia: ' + instancia);
  console.log('Época: ' + epoca);
  console.log('Materia(s) según SCJN: ' + materias);
  console.log('\n--- Primeras 500 caracteres del texto de la página (por si los campos de arriba no se detectaron bien) ---');
  console.log(texto.slice(0, 500));

  await browser.close();
})().catch(e => { console.error('Error:', e.message); process.exit(1); });
