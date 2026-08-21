# Robot de jurisprudencia laboral (SCJN)

Revisa una vez a la semana el Semanario Judicial de la Federación
(sjf2.scjn.gob.mx, portal público, sin usuario ni contraseña) buscando
tesis y jurisprudencia **nueva en materia laboral**, y las va guardando
con su texto completo en una biblioteca local — la base tanto para poder
avisar "salió jurisprudencia nueva" como para el buscador de
jurisprudencia con IA (que solo va a poder contestar con tesis reales
guardadas aquí, nunca inventadas).

## ⚠️ Primera vez: es normal que necesite ajustes

Este robot se escribió revisando capturas de pantalla del sitio, no
probándolo en vivo — es muy probable que algún selector no funcione
exactamente a la primera corrida. Si truena, copia el mensaje de error
completo (la terminal lo muestra) y compártelo para ajustarlo.

## Instalación (una sola vez)

1. Instala [Node.js](https://nodejs.org/) (versión 18 o más reciente).
2. En esta carpeta (`robots/jurisprudencia/`), abre una terminal y corre:
   ```
   npm install
   npx playwright install chromium
   ```
3. Copia `config.example.js` como `config.js` (misma carpeta) y pon la
   URL real de tu sistema y la misma llave que tiene
   `api/robot_credentials.php` en el servidor (`ROBOT_API_KEY`).
   **`config.js` nunca se sube a git ni se comparte.**
4. Pruébalo a mano una vez:
   ```
   node jurisprudencia.js
   ```
   Debe imprimir cuántas tesis revisó y cuántas guardó como nuevas, sin
   errores.

## Dejarlo corriendo solo, una vez por semana

- **Windows**: Programador de tareas — tarea que ejecute `node` con
  `jurisprudencia.js` como argumento, "Iniciar en" apuntando a esta
  carpeta, programada una vez por semana (ej. lunes 7:00 am).
- **Mac/Linux**: crontab (`crontab -e`):
  ```
  0 7 * * 1 cd /ruta/completa/a/robots/jurisprudencia && /usr/local/bin/node jurisprudencia.js >> robot.log 2>&1
  ```

## Cómo funciona

1. Entra al buscador de tesis del Semanario Judicial, filtra por materia
   "Laboral" y ordena por fecha de publicación más reciente.
2. Para cada tesis que no haya visto antes (guarda un registro en
   `procesados_jurisprudencia.json`, en esta misma carpeta, para no
   repetir trabajo), entra a su ficha completa y saca el texto íntegro.
3. Manda todas las tesis nuevas al sistema (`jurisprudencia_ingest.php`),
   que las guarda en una tabla nueva (`jurisprudencia_tesis`).
