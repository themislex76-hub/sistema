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

**Confirmado funcionando de punta a punta (10 nov 2026)**: login, romper
sesión activa si hace falta, paginación de "LISTA DE BUZONES", match por
Tribunal+expediente, lista de acuerdos, descarga y subida del PDF real, y
aviso reportado en el sistema — todo probado con un caso real (expediente
1139/2026, Quinto Tribunal Laboral de Asuntos Individuales de la CDMX).

Detalles que costó encontrar, por si algo similar vuelve a fallar:
- El portal solo permite **una sesión activa por cuenta**. Si la cuenta ya
  tiene una sesión abierta (de una corrida anterior, o de la abogada
  usándola a la vez), muestra un aviso con un link "Romper sesión" que el
  robot detecta y acepta solo — pero ese link dispara dos diálogos nativos
  del navegador (confirm/alert) que Playwright cancela solos si nadie los
  atiende; el robot ya los acepta automáticamente. El aviso tampoco
  aparece al instante tras cargar la página (hay un margen fijo de espera
  antes de revisarlo).
- La **paginación** son iconos `<a class="page-link">` (no botones), con
  nombre de accesibilidad en inglés ("Next"/"Last") aunque el resto de la
  pantalla esté en español, y puede haber más de uno con el mismo nombre
  en el DOM (el robot prueba todos hasta que uno sí mueva la tabla).
- Tanto la lista principal de expedientes como la lista de acuerdos de
  cada expediente tardan un momento en terminar de cargar sus filas
  después de que el texto del modal/tabla ya es visible — el robot espera
  a que el conteo se estabilice antes de confiar en él.

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
que corra `run_diario.bat` (ya incluido en esta carpeta) todos los días.

Recordatorio importante (pedido explícito del usuario, 10 nov 2026):
como el portal solo permite una sesión activa por cuenta, prográmalo
**temprano en la mañana (ej. 6:00 am)**, antes de que la abogada dueña de
la cuenta empiece a usarla ella misma — así se evita el choque de
sesiones (el robot lo recupera solo si pasa, pero es mejor evitarlo).

Desde PowerShell **como administrador** (clic derecho → "Ejecutar como
administrador"), pega este comando (ajusta la ruta si tu carpeta es
distinta):
```
schtasks /create /tn "Robot Buzon CDMX Expertos Laborales" /tr "C:\Users\Ruben\Desktop\ROBOTS\cdmx_buzon\run_diario.bat" /sc daily /st 06:00
```
Para confirmar que quedó bien:
```
schtasks /query /tn "Robot Buzon CDMX Expertos Laborales" /v /fo list
```

## Cómo decide qué expediente revisar

El Buzón muestra el número de expediente tal cual lo tiene el Tribunal
(ej. `982/2025`), que puede no coincidir exactamente con el formato
capturado en el sistema (ej. `CDMX/CI/163249/2026`). El robot compara solo
el **último tramo `NNN/AAAA`** de ambos lados (sin ceros a la izquierda),
así que no hace falta que el Tribunal esté capturado ni que el prefijo
coincida — solo que el número de expediente (`exp`) esté guardado en el
sistema.
