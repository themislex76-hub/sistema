# Robot de boletín — Portal Federal (PJF)

Revisa, una vez al día, la cuenta del Portal de Servicios en Línea del
Poder Judicial de la Federación de **cada abogado** que la haya guardado
desde "Mi cuenta" en el sistema, y avisa cuando encuentra un acuerdo
nuevo de un expediente con tribunal federal capturado.

A diferencia de Edomex, este portal no pide captcha y no bloquea IPs de
centros de datos — puede correr en una VPS/servidor, no necesita estar
en la computadora del despacho.

## Instalación (una sola vez)

1. Instala [Node.js](https://nodejs.org/) (versión 18 o más reciente) en
   la máquina donde vaya a correr (VPS o una computadora del despacho,
   cualquiera funciona).
2. En esta carpeta (`robots/federal/`), abre una terminal y corre:
   ```
   npm install
   ```
3. Instala el navegador que usa este robot para leer el portal:
   ```
   npx playwright install chromium
   ```
4. Copia `config.example.js` como `config.js` (misma carpeta) y pon la
   URL real de tu sistema y la misma llave que tiene
   `api/robot_credentials.php` en el servidor (`ROBOT_API_KEY`).
   **`config.js` nunca se sube a git ni se comparte.**
5. Asegúrate de que al menos un abogado haya guardado su usuario y
   contraseña del Portal Federal desde "Mi cuenta" en el sistema — si
   nadie lo ha hecho, el robot no tiene nada que revisar.
6. Pruébalo a mano una vez:
   ```
   node federal.js
   ```
   Debe imprimir cuántas cuentas revisó y cuántos avisos encontró, sin
   errores.

## Dejarlo corriendo solo, todos los días

- **Windows**: usa el "Programador de tareas" (Task Scheduler) — crea
  una tarea que ejecute `node` con este archivo (`federal.js`) como
  argumento, con "Iniciar en" apuntando a esta carpeta.
- **Mac/Linux/VPS**: agrega una línea a tu crontab (`crontab -e`), por
  ejemplo para las 6:00 am:
  ```
  0 6 * * * cd /ruta/completa/a/robots/federal && /usr/local/bin/node federal.js >> robot.log 2>&1
  ```

## Cómo funciona

1. Pide al sistema la lista de expedientes con tribunal federal
   capturado (`expedientes_monitorear.php`), y la lista de cuentas del
   Portal Federal guardadas por los abogados (`pjf_credenciales_listar.php`
   — la contraseña viaja descifrada solo en esta llamada, protegida por
   la misma llave de robot).
2. Para cada cuenta, inicia sesión en el Portal Federal y entra a
   "Consulta de Expediente Electrónico" (navegando el portal con un
   navegador automatizado, porque esa pantalla carga por JavaScript) —
   ahí solo aparecen los expedientes donde ese usuario está autorizado,
   por eso hace falta repetir el proceso cuenta por cuenta.
3. Por cada expediente que también tengas capturado en el sistema, abre
   su "Ver Expediente Electrónico" y revisa su tabla de acuerdos,
   comparando contra el último número de acuerdo ("No.") que ya se había
   visto (guardado en `procesados_federal.json`, en esta misma carpeta).
   Los acuerdos nuevos se reportan al sistema (`avisos_ingest.php`) — el
   aviso aparece en la pestaña "Boletín" de ese expediente y en "Agenda
   general".
