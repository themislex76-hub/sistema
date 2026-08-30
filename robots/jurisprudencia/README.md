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

- **Windows**: en esta carpeta ya está `run_semanal.bat` (entra a la
  carpeta y corre `node jurisprudencia.js`, guardando la salida en
  `robot.log`). Para programarlo sin tener que navegar el Programador de
  tareas a mano, abre PowerShell **como administrador** y pega este
  comando (ajusta la ruta si tu carpeta no es exactamente esta):
  ```
  schtasks /create /tn "Jurisprudencia Expertos Laborales" /tr "C:\Users\Ruben\Desktop\ROBOTS\JURISPRUDENCIA\run_semanal.bat" /sc weekly /d MON /st 07:00 /rl LIMITED
  ```
  Esto crea una tarea que corre cada lunes a las 7:00 am, aunque no hayas
  iniciado sesión con la ventana abierta (mientras la computadora esté
  prendida). Para comprobar que quedó programada:
  ```
  schtasks /query /tn "Jurisprudencia Expertos Laborales"
  ```
  Y para quitarla si algún día ya no la quieres:
  ```
  schtasks /delete /tn "Jurisprudencia Expertos Laborales" /f
  ```
- **Mac/Linux**: crontab (`crontab -e`):
  ```
  0 7 * * 1 cd /ruta/completa/a/robots/jurisprudencia && /usr/local/bin/node jurisprudencia.js >> robot.log 2>&1
  ```

## Mantener actualizadas AMBAS bibliotecas de jurisprudencia (sistema + multidespacho)

El robot ya sabe mandar cada tesis nueva a los dos sistemas en la misma
corrida -- no hace falta correrlo dos veces ni exportar/importar nada a
mano. Para activarlo, en `config.js` (la copia local, no la que está en
git) agrega/revisa el bloque `multidespacho` con la URL real de
controldeexpedientes.mx y la misma llave que tenga configurada
`api/jurisprudencia_sync_credentials.php` allá -- ver el ejemplo comentado
en `config.example.js`. Si ese bloque falta o está mal, el robot sigue
guardando bien en el sistema original, solo que no sincroniza nada más
(avisa por consola, no se detiene por eso).

## Cómo funciona

1. Entra al buscador de tesis del Semanario Judicial, filtra por materia
   "Laboral" y ordena por fecha de publicación más reciente.
2. Recorre el listado **página por página** (no solo la primera), hasta
   agotarlo. Para cada tesis que no haya visto antes (guarda un registro
   en `procesados_jurisprudencia.json`, en esta misma carpeta, para no
   repetir trabajo), entra a su ficha completa y saca el texto íntegro.
3. Manda las tesis nuevas al sistema (`jurisprudencia_ingest.php`) en
   lotes de 25, guardando el avance sobre la marcha — si algo se
   interrumpe a medio camino, no se pierde lo ya procesado.

### ⏱️ La primera corrida puede tardar horas — es normal

Como la biblioteca empieza vacía, la primera vez que corras el robot va a
recorrer **todo el historial** de tesis en materia laboral (miles), no
solo las más recientes — si no, el buscador con IA no tendría con qué
trabajar. Con miles de fichas que visitar una por una, esto puede tardar
varias horas. Déjalo correr sin cerrar la ventana; la consola va
mostrando el progreso página por página y tesis por tesis.

Las corridas siguientes (una vez a la semana) sí son rápidas: en cuanto
el robot llega a una página del listado donde ya conoce todas las tesis,
se detiene ahí — no vuelve a recorrer el historial completo cada semana.

### `node jurisprudencia.js --completo`

El listado del Semanario Judicial trae por default marcadas solo las
épocas más recientes (12a a 9a); el robot marca también las más viejas
(8a a 5a) antes de buscar, para no perderse ese historial.

El atajo de "parar en cuanto una página ya sea toda conocida" asume que
las tesis nuevas siempre aparecen primero (orden por fecha reciente) — lo
cual deja de ser cierto justo después de agregar un filtro nuevo (como
cuando se agregaron las épocas viejas): las tesis "nuevas para el filtro"
quedan mezcladas más atrás en el listado, detrás de miles de tesis
recientes que el robot ya conoce, y el atajo pararía antes de llegar a
ellas.

Por eso, cada vez que se cambie qué se busca (se agregue una época,
instancia, etc. al filtro), hay que correr **una vez** con:

```
node jurisprudencia.js --completo
```

Esto desactiva el atajo y fuerza una recorrida de todo el listado
(tardará como la primera corrida). Una vez que termine, las corridas
normales (`node jurisprudencia.js`, sin el argumento) vuelven a ser
rápidas.
