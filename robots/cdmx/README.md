# Robot de boletín — Ciudad de México

Revisa una vez al día el Boletín Judicial local de la Ciudad de México
(portal público, sin usuario ni contraseña), y avisa al sistema cuando
encuentra un expediente que tengas capturado con tribunal de CDMX.

## Instalación (una sola vez)

1. Instala [Node.js](https://nodejs.org/) (versión 18 o más reciente) en la
   computadora donde va a correr el robot.
2. Instala `pdftotext` — viene del paquete **poppler-utils**:
   - Windows: instala [Poppler para Windows](https://github.com/oschwartz10612/poppler-windows/releases/) y agrega su carpeta `bin` al PATH.
   - Mac: `brew install poppler`
   - Linux: `sudo apt install poppler-utils`

   Para confirmar que quedó bien instalado, abre una terminal y corre
   `pdftotext -v` — debe mostrar la versión, no un error de "comando no
   encontrado".
3. En esta carpeta (`robots/cdmx/`), abre una terminal y corre:
   ```
   npm install
   ```
4. Copia `config.example.js` como `config.js` (mismo carpeta) y pon la
   URL real de tu sistema y la misma llave que tiene
   `api/robot_credentials.php` en el servidor (`ROBOT_API_KEY`).
   **`config.js` nunca se sube a git ni se comparte.**
5. Pruébalo a mano una vez:
   ```
   node cdmx.js
   ```
   Debe imprimir cuántos expedientes de CDMX tienes capturados y cuántos
   boletines revisó, sin errores.

## Dejarlo corriendo solo, todos los días

Una vez que la prueba manual funciona, prográmalo para que corra solo
una vez al día (por ejemplo, temprano en la mañana):

- **Windows**: usa el "Programador de tareas" (Task Scheduler) — crea una
  tarea que ejecute `node` con este archivo (`cdmx.js`) como argumento,
  con "Iniciar en" apuntando a esta carpeta.
- **Mac/Linux**: agrega una línea a tu crontab (`crontab -e`), por
  ejemplo para las 6:30 am:
  ```
  30 6 * * * cd /ruta/completa/a/robots/cdmx && /usr/local/bin/node cdmx.js >> robot.log 2>&1
  ```

## Cómo funciona

1. Pide al sistema la lista de expedientes con tribunal de CDMX
   capturado (`expedientes_monitorear.php`).
2. Busca en el portal público del Boletín Judicial CDMX los boletines
   publicados en los últimos 21 días.
3. Descarga el PDF de cada boletín que no haya revisado antes (guarda
   un registro en `procesados_cdmx.json`, en esta misma carpeta, para no
   repetir trabajo) y extrae el texto de la sección "Tribunales en
   materia laboral".
4. Si encuentra el número de expediente de alguno de tus casos, le
   avisa al sistema (`avisos_ingest.php`) — el aviso aparece en la
   pestaña "Boletín" de ese expediente y en el resumen de "Agenda
   general", listo para que alguien del despacho lo revise.

No requiere resolver ningún captcha — el portal de CDMX es de acceso
público directo.
