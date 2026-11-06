# Robot de boletín — Estado de México (Tlalnepantla)

Revisa el Boletín Judicial local del Estado de México (Tribunales
Laborales, región Tlalnepantla) y avisa al sistema cuando encuentra un
expediente que tengas capturado con ese tribunal. A diferencia de CDMX y
Federal, este portal pide un captcha en cada consulta y bloquea las IPs
de centros de datos — por eso **tiene que correr en una computadora del
despacho**, nunca en un servidor/VPS.

## Cómo funciona el captcha

El robot no resuelve el captcha solo: toma una foto de la imagen y se la
manda al sistema (pantalla "Agenda general"), donde alguien del despacho
lo resuelve escribiendo lo que ve. El robot espera hasta 20 minutos la
respuesta antes de rendirse con ese intento.

**Por eso es importante programarlo para correr en horario de oficina**
(por ejemplo 9:30–10:00 am), cuando alguien vaya a estar viendo el
sistema poco después — si lo programas de madrugada, antes de que
cualquiera llegue, se le va a agotar el tiempo esperando el captcha.

## Instalación (una sola vez)

1. Instala [Node.js](https://nodejs.org/) (versión 18 o más reciente).
2. En esta carpeta (`robots/edomex/`), abre una terminal y corre:
   ```
   npm install
   npx playwright install chromium
   ```
   (el segundo comando descarga el navegador que Playwright usa por
   dentro — es una descarga de varios cientos de MB, solo se hace una
   vez).
3. Copia `config.example.js` como `config.js` (misma carpeta) y pon la
   URL real de tu sistema y la misma llave que tiene
   `api/robot_credentials.php` en el servidor (`ROBOT_API_KEY`).
   **`config.js` nunca se sube a git ni se comparte.**
4. Pruébalo a mano una vez, en horario en que puedas entrar al sistema
   (pestaña "Agenda general") a resolver el captcha cuando aparezca:
   ```
   node edomex.js
   ```
   Va a ir revisando los 3 tribunales de Tlalnepantla uno por uno,
   publicando un captcha por cada intento de fecha — resuélvelos desde
   el sistema conforme vayan apareciendo.

## Dejarlo corriendo solo, todos los días

Una vez que la prueba manual funciona, prográmalo para que corra solo
una vez al día, en horario de oficina:

- **Windows**: usa el "Programador de tareas" (Task Scheduler) — crea una
  tarea que ejecute `node` con este archivo (`edomex.js`) como
  argumento, con "Iniciar en" apuntando a esta carpeta, programada para
  media mañana (ej. 9:45 am).
- **Mac/Linux**: agrega una línea a tu crontab (`crontab -e`):
  ```
  45 9 * * * cd /ruta/completa/a/robots/edomex && /usr/local/bin/node edomex.js >> robot.log 2>&1
  ```

## Cómo funciona

1. Pide al sistema la lista de expedientes con tribunal local de Edomex
   (Tlalnepantla) capturado (`expedientes_monitorear.php`) — descarta
   los que ya están marcados como federales.
2. Abre el portal de consulta del Boletín Judicial de Edomex con un
   navegador automatizado (headless, sin ventana visible) y, para cada
   uno de los 3 Tribunales Laborales de Tlalnepantla, prueba el boletín
   del día más reciente hacia atrás.
3. Si el portal dice que no hay boletín publicado ese día, prueba el
   día anterior sin gastar un captcha. Si sí hay boletín pendiente de
   mostrar, publica la imagen del captcha en el sistema
   (`edomex_captcha_publicar.php`) y espera a que alguien lo resuelva
   desde "Agenda general" (`edomex_captcha_resultado.php`).
4. Con el captcha resuelto, descarga el PDF del boletín y busca ahí los
   números de expediente que coincidan con los tuyos. Si encuentra
   alguno, le avisa al sistema (`avisos_ingest.php`) — el aviso aparece
   en la pestaña "Boletín" de ese expediente y en "Agenda general".
