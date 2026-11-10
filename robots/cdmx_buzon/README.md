# Robot del Buzón Electrónico Laboral — Ciudad de México

Revisa el Buzón Electrónico Laboral del PJCDMX
(`buzonelectronicolaboral.poderjudicialcdmx.gob.mx:2083`) de **cada
abogado** que haya guardado su cuenta desde "Mi cuenta" en el sistema, y
reporta los acuerdos nuevos de los expedientes monitoreados (por número de
expediente, sin importar si el Tribunal está capturado o no).

Complementa al robot viejo de boletín público (`robots/cdmx/cdmx.js`, que
sigue corriendo igual) con un mecanismo mucho más confiable: cada abogado
ve directo, dentro de su propia cuenta, la lista de expedientes donde está
dado de alta como apoderado, con los acuerdos y el PDF de cada uno listo
para descargar — sin tener que "adivinar" si un caso del boletín
corresponde al expediente correcto por el nombre de las partes.

**Primera versión, nunca se ha corrido en vivo** — se construyó a partir
de capturas de pantalla reales del portal (login, lista de expedientes,
lista de acuerdos, PDF de un acuerdo real). Es muy probable que algo
truene a la primera corrida, sobre todo:
- La **paginación** de "LISTA DE BUZONES" (los botones son iconos, sin
  texto — se localizan por posición, lo más frágil de este robot).
- La **descarga del PDF** al darle clic a "Ver acuerdo" (puede abrir una
  pestaña nueva o disparar una descarga directa — el código escucha los
  dos casos, pero no se ha confirmado cuál usa el portal de verdad).

Si algo falla, manda el **mensaje de error completo de la terminal** y el
archivo `debug_error_<cuenta>.png` que se guarda en esta misma carpeta —
con eso se ajusta el selector exacto, no se puede adivinar sin eso.

## Instalación (una sola vez)

1. Instala [Node.js](https://nodejs.org/) (versión 18 o más reciente).
2. En esta carpeta (`robots/cdmx_buzon/`), corre:
   ```
   npm install
   npx playwright install chromium
   ```
3. Copia `config.example.js` como `config.js` y pon la URL real de tu
   sistema y la misma llave que tiene `api/robot_credentials.php` en el
   servidor (`ROBOT_API_KEY`). **`config.js` nunca se sube a git.**
4. Asegúrate de que al menos un abogado haya guardado su correo y
   contraseña del Buzón Electrónico Laboral desde "Mi cuenta" en el
   sistema.
5. Pruébalo a mano:
   ```
   node cdmx_buzon.js
   ```

## Dejarlo corriendo solo

Igual que los demás robots (ver `CLAUDE.md` del repo principal, sección
"Robots locales"): crea una tarea en el Programador de tareas de Windows
que corra `node cdmx_buzon.js` en esta carpeta, diario, a la hora que
prefieras (después de que corran Federal/CDMX/Edomex es buena idea, para
no saturar la conexión a la vez).

## Cómo decide qué expediente revisar

El Buzón muestra el número de expediente tal cual lo tiene el Tribunal
(ej. `982/2025`), que puede no coincidir exactamente con el formato
capturado en el sistema (ej. `CDMX/CI/163249/2026`). El robot compara solo
el **último tramo `NNN/AAAA`** de ambos lados (sin ceros a la izquierda),
así que no hace falta que el Tribunal esté capturado ni que el prefijo
coincida — solo que el número de expediente (`exp`) esté guardado en el
sistema.
