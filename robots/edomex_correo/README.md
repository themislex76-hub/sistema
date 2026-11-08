# Robot de correo de notificaciones — Estado de México (Tlalnepantla)

Revisa el correo institucional de notificaciones electrónicas
(mail.pjedomex.gob.mx) de **cada abogado** que lo haya guardado desde "Mi
cuenta" en el sistema, y reporta los acuerdos nuevos de los expedientes
con tribunal local de Edomex (Tlalnepantla) capturado.

A diferencia del robot viejo (`robots/edomex/`), que leía el boletín
público con captcha y tenía que "adivinar" si un caso coincidía por nombre
de las partes, aquí cada acuerdo llega por correo, dirigido personalmente
al abogado, con el expediente/partes/juzgado/texto ya estructurados y una
liga directa (con token) para bajar el PDF del acuerdo — no hace falta
captcha ni el filtro de seguridad por nombres.

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

Igual que los otros robots de boletín — Task Scheduler (Windows) o
crontab (Mac/Linux/VPS), una vez al día. Ver el README de `robots/federal/`
para el ejemplo exacto de cómo configurarlo.

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
   expediente está entre los monitoreados, baja el PDF con esa liga, lo
   sube al sistema (pestaña "Documentos" del expediente) y reporta el
   aviso (`avisos_ingest.php`).

**Primera corrida real:** esto se construyó viendo capturas de pantalla
del correo, nunca se probó en vivo. Es muy probable que algo truene en la
primera corrida — manda el error completo de la terminal y la captura
`debug_*.png` que se guarda junto al robot si pasa, para ajustarlo sin
adivinar.
