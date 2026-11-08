# Robot de correo de notificaciones — Estado de México (Tlalnepantla)

Revisa el correo institucional de notificaciones electrónicas
(mail.pjedomex.gob.mx) de **cada abogado** que lo haya guardado desde "Mi
cuenta" en el sistema, y reporta los acuerdos nuevos de los expedientes
con tribunal local de Edomex (Tlalnepantla) capturado.

A diferencia del robot viejo que leía el boletín público con captcha (ya
retirado) y tenía que "adivinar" si un caso coincidía por nombre de las
partes, aquí cada acuerdo llega por correo, dirigido personalmente al
abogado, con el expediente/partes/juzgado/texto ya estructurados y una
liga directa (con token) para bajar el documento del acuerdo — no hace
falta captcha ni el filtro de seguridad por nombres.

**Este correo NO tiene acceso IMAP** — solo se consulta entrando a
mail.pjedomex.gob.mx con usuario y contraseña, por eso el robot usa un
navegador automatizado (Playwright) para entrar y leer la bandeja, igual
que el robot Federal.

## Instalación (una sola vez)

1. Instala [Node.js](https://nodejs.org/) (versión 18 o más reciente).
2. En esta carpeta (`robots/edomex_correo/`), corre:
   ```
   npm install
   npx playwright install chromium
   ```
3. Copia `config.example.js` como `config.js` y pon la URL real de tu
   sistema y la misma llave que tiene `api/robot_credentials.php` en el
   servidor (`ROBOT_API_KEY`). **`config.js` nunca se sube a git.**
4. Asegúrate de que al menos un abogado haya guardado su usuario y
   contraseña del correo de notificaciones desde "Mi cuenta" en el
   sistema.
5. Pruébalo a mano:
   ```
   node edomex_correo.js
   ```

## Dejarlo corriendo solo

- **Windows**: ya incluye `run_diario.bat` (entra a esta carpeta y corre
  `node edomex_correo.js`, guardando la salida en `robot.log`). Para
  programarlo sin tener que navegar el Programador de tareas a mano, abre
  PowerShell **como administrador** y pega este comando (ajusta la ruta si
  tu carpeta no es exactamente esta):
  ```
  schtasks /create /tn "Edomex Correo Expertos Laborales" /tr "C:\Users\Ruben\Desktop\ROBOTS\edomex_correo\run_diario.bat" /sc daily /st 06:40 /rl LIMITED
  ```
  Esto crea una tarea que corre todos los días a las 6:40 am (después de
  Federal y CDMX, para no empalmarse), aunque no hayas iniciado sesión con
  la ventana abierta (mientras la computadora esté prendida). Para
  comprobar que quedó programada:
  ```
  schtasks /query /tn "Edomex Correo Expertos Laborales"
  ```
  Y para quitarla si algún día ya no la quieres:
  ```
  schtasks /delete /tn "Edomex Correo Expertos Laborales" /f
  ```
- **Mac/Linux/VPS**: agrega una línea a tu crontab (`crontab -e`), por
  ejemplo para las 6:40 am:
  ```
  40 6 * * * cd /ruta/completa/a/robots/edomex_correo && /usr/local/bin/node edomex_correo.js >> robot.log 2>&1
  ```

## Cómo funciona

1. Pide al sistema la lista de expedientes con tribunal local de Edomex
   (Tlalnepantla) capturado, y la lista de cuentas de correo guardadas por
   los abogados.
2. Para cada cuenta, entra a mail.pjedomex.gob.mx y revisa la bandeja de
   entrada buscando correos de "Notificacion Electronica Laboral".
   Seguimiento por el id numérico de cada conversación (en
   `procesados_edomex_correo.json`, en esta misma carpeta) para no
   reprocesar lo ya visto.
3. Por cada correo nuevo, lee el expediente/actor/demandado/juzgado y la
   liga del documento (con token) directo del cuerpo del correo. Si el
   expediente está entre los monitoreados, baja el documento con esa liga
   (normalmente viene comprimido en un `.zip`; el robot lo abre y extrae el
   PDF real que trae adentro), lo sube al sistema (pestaña "Documentos" del
   expediente) y reporta el aviso (`avisos_ingest.php`).

**Confirmado funcionando de punta a punta (8 nov 2026)** con una corrida
real: login, lectura de la bandeja, apertura de un correo real, match
contra un expediente monitoreado, descarga y subida del PDF real. Si algo
truena en una corrida futura, manda el error completo de la terminal y la
captura `debug_*.png` que se guarda junto al robot — no adivinar el
selector sin eso.
