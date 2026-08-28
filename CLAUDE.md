# Notas operativas — sistema (Expertos Laborales)

## Flujo de despliegue
- No hay acceso SSH ni a servidor en vivo desde estas sesiones.
- Cambios de código: editar aquí → `php -l` / `node --check` → subir el
  cache-bust (`assets/app.js?v=N` en `index.html`) → commit/push →
  `SendUserFile` solo de los archivos modificados → el usuario los sube por
  cPanel → Administrador de archivos.
- Cambios de base de datos: dar el SQL, el usuario lo corre por phpMyAdmin.

## Robots locales (Node + Playwright)
- Corren en la máquina de Windows del usuario (Ruben), **no** en esta sesión
  remota — esta sesión no tiene salida a internet hacia sitios externos
  (confirmado: sin acceso a sjf2.scjn.gob.mx ni similares) ni la llave real
  del robot.
- Carpeta local donde el usuario los guarda: `C:\Users\Ruben\Desktop\ROBOTS\`,
  una subcarpeta por robot (ej. `JURISPRUDENCIA`, `EDOMEX`).
- Entrega de archivos nuevos/actualizados de un robot: `SendUserFile` de los
  `.js`/`.json`/`README.md` sueltos (no zip — falló por confusión de carpeta
  de descargas) para que los guarde directo en la subcarpeta del robot.
- La llave real (`ROBOT_API_KEY`) vive solo en `api/robot_credentials.php`
  del servidor (gitignored, no está en este repo) — la ve el usuario por
  cPanel. Es compartida entre todos los robots (Edomex, boletín CDMX,
  boletín Federal, jurisprudencia).
- Primera corrida de cada robot: recordar `npm install` +
  `npx playwright install chromium` en su carpeta antes de correrlo.

## Robot de jurisprudencia (`robots/jurisprudencia/`)
- Scrapea sjf2.scjn.gob.mx (Semanario Judicial de la Federación), materia
  laboral, y manda tesis nuevas a `api/jurisprudencia_ingest.php`.
- `node jurisprudencia.js` (corridas normales) / `node jurisprudencia.js
  --completo` (fuerza recorrer todo el listado — usar cuando cambie el
  filtro de búsqueda, ej. al agregar épocas viejas).
- Tarea pendiente: depurar selectores en vivo (es muy probable que algo
  truene en la primera corrida real — pedir el error completo de la
  terminal si pasa).

## Costos de IA
- Bitácora de gasto (créditos, gasto del mes, costo por resultado del
  embudo de WhatsApp): https://claude.ai/code/artifact/fdbe25d4-5fba-41f8-a0d3-f389dfb8cb61
  — actualizarla cuando el usuario mande una captura nueva del dashboard de
  Anthropic Console.
