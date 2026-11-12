# Notas operativas — sistema (Expertos Laborales)

## Flujo de despliegue
- No hay acceso SSH ni a servidor en vivo desde estas sesiones.
- Cambios de código: editar aquí → `php -l` / `node --check` → subir el
  cache-bust (`assets/app.js?v=N` en `index.html`) → commit/push →
  `SendUserFile` solo de los archivos modificados → el usuario los sube por
  cPanel → Administrador de archivos.
- Cambios de base de datos: dar el SQL, el usuario lo corre por phpMyAdmin.
- Si tocaste `mergeTags()`, `calcularJornada()`, las prestaciones opcionales
  de Rescisión Art. 51, o cualquier cosa relacionada con las {{tags}} de
  los formatos de demanda: corre `node scripts/verificar_tags.js` antes de
  mandar los archivos. Revisa en segundos que ninguna tag quede
  "undefined"/"NaN", que las letras/numeración de los bloques dinámicos
  salgan bien, y avisa si agregaste una tag nueva sin documentarla en la
  leyenda de "Campos disponibles" de Formato de demanda. No sustituye
  generar un documento de prueba real, pero atrapa errores de lógica antes
  de que lleguen ahí.

## Varias sesiones trabajando sobre el mismo repo (IMPORTANTE)
El usuario tiene 3 conversaciones de Claude activas sobre este proyecto
("sistema y bot completo", "Bot", "chats"), cada una con su propio
contexto -- no se comparten entre sí automáticamente. Este archivo
(`CLAUDE.md`) es el mecanismo para que cada sesión nueva se entere de lo
que hicieron las otras: se lee solo al empezar, así que **toca
actualizarlo tú mismo cada vez que termines algo importante**, no
solo confiar en que el usuario lo recuerde.

**Cada conversación corre en SU PROPIA rama de git** (no hay una rama
`main` tradicional -- la rama por defecto del repo es literalmente
`claude/hola-i5mb6q`, la de esta conversación). Un `git pull` normal
(de tu propia rama) **NO** te trae los commits de las otras 2
conversaciones -- viven en ramas aparte y solo se juntan cuando alguien
las fusiona a mano. Confirmado el 9 nov 2026: la rama de "Bot"
(`claude/labor-advisory-bot-tiktok-wqsmim`) llevaba 2 días con arreglos
reales (SDI real del recibo de nómina, reutilizar horario ya apartado)
que esta rama no tenía -- se fusionaron sin conflicto porque tocaban
archivos distintos, pero de haber seguido divergiendo en el MISMO
archivo (ej. las dos tocando `api/ia_helpers.php`), la siguiente entrega
de cualquiera de las dos sesiones pudo haber borrado silenciosamente el
arreglo de la otra.

Por eso, **antes de empezar trabajo nuevo**: además de tu propio
`git pull`, revisa si hay otras ramas con commits nuevos que tu rama no
tenga y fusiónalas si no hay conflicto (avisa al usuario si sí lo hay,
no elijas una versión a ciegas):
```
git branch -r                                   # ver qué otras ramas existen
git fetch origin <otra-rama>
git merge --no-commit --no-ff FETCH_HEAD         # revisa el resultado antes de confirmar
```
Al terminar: commit/push a TU rama + agrega aquí un resumen breve de qué
cambiaste y qué migración SQL es la más reciente que el usuario ya corrió
(para que la siguiente sesión -- la tuya o cualquiera de las otras 2 --
sepa desde dónde continuar sin repetir ni pisar nada). Esto solo
funciona si de verdad se fusiona periódicamente; si una rama se queda
semanas sin fusionarse, vuelve a haber riesgo de perder trabajo.

**Última migración aplicada por el usuario: `067_google_calendario_lectura.sql`, confirmada corrida en la base correcta (`c1361311_exp`) el 11 nov 2026** (064, 065, 066 también están corridas -- confirmado por el usuario el 10-11 nov 2026).

## Supervisor: "Preguntas al abogado" ampliado (9 nov 2026, `app.js?v=122`, sin migración nueva)
Confirmado en vivo el mismo día que se corrió la migración 061: el cron
`cron_generar_preguntas_supervisor.php` corrió limpio (15 atrasos
revisados, 15 preguntas nuevas generadas, 0 fallidas). Después de verlo
funcionando, el usuario pidió 3 ajustes sobre la pieza 3 del plan grande
de autonomía (ver sección de arriba):
- **Renombrado** de "🧑‍⚖️ Preguntas del supervisor" a **"Preguntas al
  abogado"** en la UI (solo el texto visible -- nombres internos de
  variables/tabla/endpoints se dejaron igual, `supervisor_preguntas` etc.,
  para no arriesgar nada).
- **Pestaña propia** dentro de Agenda general (antes era solo un panel
  dentro de "General", mezclado con lo demás). Se agregó una 4ª pestaña
  `AGENDA_TAB='preguntas'` junto a General/Asesorías/Seguimiento IA, con
  su propio badge de pendientes. Muestra las pendientes arriba (con el
  formulario de respuesta) y un historial abajo (respondidas/aplicadas,
  de solo lectura). El panel viejo dentro de "General" se quitó.
- **Adjuntar foto/documento al responder**: `supervisor_pregunta_responder.php`
  pasó de recibir JSON a `multipart/form-data` (como `documentos_upload.php`)
  -- acepta un archivo opcional (`pdf`/`jpg`/`jpeg`/`png`, máx. 20 MB) además
  del texto. Si llega archivo, se guarda igual que cualquier otro documento
  del expediente (`expediente_documentos`, categoría `otro`) y
  `ia_interpretar_respuesta_supervisor()` ahora puede recibir esa
  foto/PDF como bloque `document`/`image` nativo de la API de Anthropic
  (mismo patrón que `ia_analizar_acuerdo_boletin()`) -- si el documento
  confirma una fecha, llena la etapa sola igual que antes (nunca
  sobrescribe una que ya tenga fecha). Usa el modelo grande
  (`IA_MODELO_ANALIZAR_ACUERDO`) solo cuando hay archivo adjunto; si es
  solo texto sigue usando Haiku como antes, para no encarecer el caso
  común.
- **Más contexto al redactar la pregunta**: pedido explícito del usuario
  ("la ia tiene que revisar toda la info y documentos que hay en sistema
  para generar estas preguntas"). `ia_generar_pregunta_supervisor()` ahora
  también recibe los últimos documentos guardados del expediente (nombre +
  categoría + fecha) y los últimos resúmenes de IA de boletín
  (`ia_resumen` de `avisos_boletin`) para que la pregunta sea más
  específica y no repita algo que el expediente ya trae documentado. El
  cron (`cron_generar_preguntas_supervisor.php`) hace 2 SELECTs nuevos por
  expediente para juntar ese contexto antes de llamar a la IA.
- Pendiente explícito (NO se tocó todavía, es un alcance más grande): "la
  IA debe actualizar el sistema procesal... para hacerlo lo más autónomo
  que se pueda" en un sentido más amplio -- hoy la actualización automática
  de `expediente_etapas` solo pasa en 2 lugares puntuales (acuerdos de
  boletín leídos por IA, y esta respuesta del abogado al supervisor con o
  sin adjunto). La pieza 4 del plan grande (que la IA lea CUALQUIER
  documento/foto que el abogado suba desde la pestaña "Documentos", no
  solo cuando contesta una pregunta del supervisor) sigue sin construirse
  -- ver tarea pendiente en la sesión.
- **Corrección sobre lo anterior, mismo día**: el usuario primero pidió
  esto como pestaña dentro de Agenda general (como General/Asesorías/
  Seguimiento IA) -- se construyó así en el primer intento (`app.js?v=122`),
  pero luego pidió explícitamente "protagonismo" y que no quedara
  "revuelta". Se promovió a **su propia sección en el menú lateral**
  (`VIEW='preguntas_abogado'`), junto a "Alertas de prescripción", con su
  propio contador de pendientes -- ya NO vive dentro de Agenda general ni
  como sub-pestaña. `app.js?v=123`.
- Mientras se diagnosticaba por qué el usuario seguía viendo la versión
  vieja después de subir archivos, se detectó que `sw.js` (Service
  Worker, app instalable) traía hardcodeado `app.js?v=106` /
  `style.css?v=5` en su lista de precache (`SHELL_FILES`) -- quedó
  desactualizado desde hace varias entregas. La estrategia de fetch ya es
  "red primero, caché solo de respaldo sin conexión" así que esto no
  debería causar que se sirva una versión vieja mientras haya internet,
  pero de todos modos se actualizó (`CACHE_NAME` a `v42`, referencias a
  `v=123`/`v=6`) -- si en el futuro algo similar vuelve a pasar (usuario
  sube archivos nuevos y sigue viendo lo viejo), revisar primero si de
  verdad subió TODOS los archivos (`index.html` incluido, para que el
  cache-bust cambie) antes de sospechar del Service Worker.
- **Sin probar en vivo todavía** esta versión (`v=123`, la de sección
  propia en el menú) -- recién subido. Confirmar con el usuario: que
  aparezca "Preguntas al abogado" como botón propio del menú lateral
  (junto a Alertas de prescripción), que subir una foto al responder
  funcione y llene la etapa si la foto trae una fecha clara, y que las
  preguntas nuevas que genere el cron se noten más específicas con el
  contexto de documentos/boletín.

## Simplificación: un solo campo de Tribunal + Amparo recortado (9 nov 2026)
El usuario notó que el sistema pedía ciudad/tribunal/expediente en 3
lugares distintos (campos `junta` y `tribunal` en "Editar datos", más el
selector de Tribunal Colegiado en la pestaña "Amparo") y pidió
consolidar.
- **Se quitó el campo `junta`** de "Editar datos" -- las Juntas de
  Conciliación y Arbitraje ya no reciben asuntos nuevos desde la reforma
  laboral, así que ya no hace falta capturarlo por separado. Ahora solo
  queda: "Centro de Conciliación" (`instancia`, SE QUEDA -- sigue
  haciendo falta para los asuntos que aún no pasan a Tribunal, pedido
  explícito del usuario), "Tribunal" (`tribunal`, el único selector
  pesado de ciudad+catálogo que queda) y "Número de expediente" (`exp`).
  La columna `junta` de la base de datos **NO se borró** (datos viejos
  siguen ahí) -- todos los lugares que leen el tribunal (`mergeTags()`,
  la tabla de expedientes, "Informe del juicio", etc.) ahora priorizan
  `tribunal` y caen a `junta` solo como respaldo para expedientes
  capturados antes de este cambio; el formulario de "Editar datos"
  también usa `junta` como valor de arranque si `tribunal` todavía está
  vacío, para que un dato viejo no se vea en blanco.
- **Pestaña "Amparo" recortada, pero el selector de Tribunal Colegiado SE
  QUEDA** (corrección sobre el primer intento de este mismo día: al
  principio se había quitado el selector de Tribunal Colegiado también,
  pero el usuario aclaró "en el amparo se usa numero de expediente y
  tribunal" + "tiene que ser con selector" -- se restauró igual que
  estaba). Queda: checkbox de activo + fecha de notificación del laudo/
  plazo de 15 días calculado (Art. 17 Ley de Amparo) + selector de
  Tribunal Colegiado (circuito + catálogo + "otro", `amparoCircuitoSel`/
  `amparoTribunalSel`/`amparoTribunalOtroWrap`) + número de expediente
  del amparo. Lo ÚNICO que sí se quitó de verdad fue el campo de **notas**
  (`amparo_notas`) -- no lo usa nada automatizado. Si un expediente viejo
  ya tenía notas capturadas, el botón "Guardar datos de amparo" las manda
  tal cual sin cambios (ya no hay forma de editarlas desde la UI, pero
  tampoco se borran solas al guardar). Confirmado con el usuario: aunque
  el robot Federal solo usa `amparo_expediente` para el match (nunca lee
  `amparo_tribunal`, ver `federal.js`), el tribunal SÍ es un dato que el
  despacho necesita capturar y consultar, así que se queda con su propio
  selector aunque no lo use ningún robot.
- `app.js?v=119`. Sin cambios de base de datos. `verificar_tags.js`
  corrido y pasó limpio (se tocó `mergeTags()` -- el fallback de
  `instancia`/`ciudad_firma` ahora usa `tribunal` en vez de `junta`).

## Visión grande: sistema lo más autónomo posible, "supervisor" de los
## abogados (9 nov 2026) -- EN CURSO, leer antes de tocar nada de agenda/IA
El usuario quiere que el sistema se alimente solo lo más posible (de los
boletines, de fotos/documentos que suba el abogado, de preguntas que le
haga el sistema) y actúe como un supervisor con criterio legal -- no un
simple extractor de fechas -- que no deje que un asunto se atore, y que
le pregunte al abogado lo justo y necesario (lo que de verdad no se puede
sacar solo) en vez de hacerle llenar el sistema a mano. Se definieron 4
piezas (ver tareas en la sesión que esto escribió, o recréalas si hace
falta seguimiento):
1. **Arreglar el hueco del push diario de atrasos -- HECHO (9 nov 2026),
   migración 059 pendiente de correr.** Causa raíz real encontrada: el
   sistema YA calculaba bien los "posibles atrasos" (`estancadoInfo()`)
   y la IA YA generaba una acción sugerida por expediente
   (`ia_generar_resumen_expediente()`, campo `accion_sugerida_ia`) --
   pero `syncAvisosAbogado()` en app.js solo mandaba al caché
   (`agenda_avisos_cache`) lo que tuviera fecha entre hoy y los próximos
   3 días, y la fecha de un atraso es la fecha en que YA DEBIÓ pasar
   algo -- una vez atrasado de verdad esa fecha queda en el pasado para
   siempre y nunca vuelve a caer en esa ventana. Tampoco lo hubiera
   agarrado el cron (`cron_recordatorio_abogado.php`) aunque llegara al
   caché: exigía `fecha = CURDATE() + 1`. Confirmado en vivo con el
   usuario: 40 asuntos atorados que el sistema "sabía" pero nunca avisó
   a nadie. Arreglado: `atraso`/`accion_ia` ahora se mandan al caché
   sin importar qué tan atrás quedó su fecha (mientras sigan sin
   resolverse) y el cron los agarra con `fecha <= hoy` en vez de
   `fecha = mañana`. Además, nuevo campo `dias_atraso` en
   `agenda_avisos_cache` (migración 059) -- si un abogado lleva 7+ días
   ignorando uno de estos, TAMBIÉN se le avisa al Administrador (tabla
   nueva `agenda_escalaciones_enviadas`, dedupe aparte de
   `agenda_avisos_enviados` para que no choque si el admin también es
   abogado con sus propios pendientes). `app.js?v=117`.
   - **Bug real encontrado y corregido (10 nov 2026)**: el push nunca
     llegaba, sin error visible para nadie. Causa real (encontrada vía
     un log de diagnóstico nuevo, `api/push_debug.log`, agregado porque
     el servidor no tiene un `error_log` fácil de encontrar en cPanel):
     "Size of payload must not be greater than 4078 octets" -- el
     protocolo de notificaciones push tiene un límite de tamaño, y con
     muchos asuntos atorados juntos en un solo mensaje (59 en la
     escalación al Administrador, confirmado real) se rebasaba por
     mucho; la excepción se atrapaba y se perdía en silencio. Nueva
     función `push_armar_cuerpo()` (`api/push_helpers.php`) arma el
     mensaje línea por línea hasta acercarse al límite y corta el resto
     con "…y N más — entra al sistema para verlos todos" en vez de
     intentar mandarlo completo. Confirmado que es el único lugar del
     sistema con este patrón de mensaje-lista-larga.
   - **Confirmado funcionando de punta a punta en vivo (10 nov 2026)**:
     tras el arreglo, el usuario recibió el push real en su celular.
2. **IA lee el contenido real de los acuerdos de boletín -- HECHO (9 nov
   2026), migración 060 y cron ya corridos/dados de alta (confirmado por
   el usuario el 10 nov 2026).** Antes el resumen guardado era genérico ("Acuerdo No. X del
   expediente Y, de fecha Z"), sin que nadie (ni la IA) supiera qué decía
   el acuerdo de verdad. Ahora `ia_analizar_acuerdo_boletin()`
   (`api/ia_helpers.php`) le manda a Claude el PDF real (como documento
   adjunto nativo de la API, no solo texto -- o el `.txt` tal cual para
   los avisos de CDMX, que no traen PDF) junto con el contexto del
   expediente y su bitácora de etapas, y pide: (1) un resumen de 1-2
   líneas de qué dice el acuerdo en concreto, (2) una etapa sugerida SOLO
   si calza con claridad con el vocabulario de `ETAPA_KEYS` (nunca
   forzada), (3) una fecha SOLO si el documento la menciona literalmente
   (nunca calculada -- mismo principio que ya regía en
   `ia_generar_resumen_expediente()`).
   - Nuevo cron **`api/cron_analizar_acuerdos_ia.php`** (correr cada
     15-30 min, NO en tiempo real junto con `avisos_ingest.php` -- leer
     un PDF tarda varios segundos y no debía arriesgar ni hacer más
     lenta la corrida de los robots). Busca avisos con documento adjunto
     sin analizar todavía (`ia_analizado_en IS NULL`), hasta 15 por
     corrida. **Pedido explícito del usuario: que la IA USE lo que lee
     para llenar el sistema sola, no solo mostrarlo pasivo** (si solo se
     mostraba, se repetía el mismo problema que ya resolvimos con el
     punto 1 -- información correcta que nadie actúa). Por eso, si la
     etapa sugerida TODAVÍA no tiene fecha capturada en
     `expediente_etapas` (nunca sobrescribe una que el abogado ya
     registró a mano), la llena sola con la fecha mencionada en el
     documento o, si no hay, la fecha de publicación del boletín -- y
     queda registrado en `log_historial` como "llenado automático por
     IA" para que se pueda auditar/corregir si se equivocó.
   - El resumen de la IA (`ia_resumen`) se muestra debajo del resumen
     genérico en la tarjeta de cada aviso (Agenda general y pestaña
     Boletín del expediente), y si llenó una etapa sola aparece una
     insignia "✓ etapa actualizada sola: [nombre de la etapa]" --
     `app.js?v=118`.
   - Migración y cron confirmados corridos/dados de alta por el usuario
     el 10 nov 2026, junto con los de las piezas 3 y 4 -- si alguna vez
     se ve un aviso con documento adjunto sin `ia_resumen` después de
     20-30 min, revisar primero que el Cron Job siga activo en cPanel
     antes de asumir que el código falló.
   - **Confirmado funcionando con casos reales (10 nov 2026)**: revisando
     `avisos_boletin` se encontraron 10 acuerdos ya analizados con
     resúmenes específicos de verdad (no genéricos) -- los 9 acuerdos de
     amparo de Emmanuel Rodríguez Ojeda (Federal, ej. "El Tribunal
     Colegiado negó el amparo...", "El Tribunal Colegiado admitió la
     demanda de amparo...") y el acuerdo de Zeltzin Monserrat Flores Cruz
     (robot del Buzón CDMX nuevo de hoy, "El Tribunal admite a trámite la
     demanda laboral en..."). Confirma que `ia_analizar_acuerdo_boletin()`
     lee el PDF real y lo resume bien, sin necesidad de forzar una
     corrida de prueba.
3. **Supervisor que pregunta al abogado por asuntos atorados -- HECHO (9
   nov 2026), migración 061 y cron ya corridos/dados de alta (confirmado
   el mismo 9 nov 2026 -- ver nota al principio del archivo, el cron
   corrió limpio: 15 atrasos revisados, 15 preguntas generadas, 0
   fallidas).** Pensado originalmente como "por WhatsApp" (ver el
   ejemplo real del usuario abajo), pero se construyó como push + tarjeta
   DENTRO del sistema -- el usuario lo pidió así explícitamente ("haber
   hazlo sin el pussh" más tarde se aclaró en "sin push" del todo, ni
   siquiera push de aviso de que hay pregunta nueva, solo aparece al
   entrar al sistema) una vez que se le explicó que WhatsApp de verdad
   habría necesitado agregar un campo de celular a `usuarios` (no existe
   hoy) + una plantilla nueva aprobada por Meta (días de espera) + enseñar
   al bot de WhatsApp (hoy solo sabe hablar con prospectos/clientes) a
   reconocer que un mensaje entrante es de un abogado contestándole al
   supervisor -- mucho más trabajo que reutilizar lo ya construido.
   - **Pieza clave de diseño**: en vez de reimplementar "qué está
     atorado" (eso ya lo resuelve `estancadoInfo()` en el navegador,
     sincronizado a `agenda_avisos_cache` desde la pieza 1 de este mismo
     plan), este supervisor PARTE de esos mismos "atraso" ya detectados.
     Se le agregó a `estancadoInfo()` el campo `siguienteKey` (la CLAVE
     de la etapa esperada, ej. `contestacion_recibida`, no solo su
     nombre humano) y `buildAgendaEntries()` ahora manda `actionIdx:
     est.siguienteKey` en la entrada tipo 'atraso' -- eso hace que
     `syncAvisosAbogado()` guarde la clave como `atraso:<etapa_key>` en
     vez de solo `atraso` (antes un expediente solo podía tener un
     atraso a la vez, así que la clave genérica bastaba para
     deduplicar, pero no servía para saber DESPUÉS a qué etapa
     corresponde).
   - Nuevo cron **`api/cron_generar_preguntas_supervisor.php`** (correr
     una vez al día): por cada fila `agenda_avisos_cache` tipo='atraso'
     sin pregunta generada todavía (`supervisor_preguntas`, tabla nueva),
     le pide a `ia_generar_pregunta_supervisor()` una pregunta corta y
     concreta (nunca clases de derecho, va directo al grano como un
     supervisor real) y la guarda. SIN push -- solo aparece en un panel
     nuevo "🧑‍⚖️ Preguntas del supervisor" en Agenda general (mismo
     estilo que el de avisos de boletín) cuando el abogado entra.
   - El abogado contesta en texto libre ahí mismo (textarea + botón
     "Responder", `api/supervisor_pregunta_responder.php`) --
     `ia_interpretar_respuesta_supervisor()` lee la respuesta y SOLO si
     menciona una fecha de forma literal (puede ser lenguaje natural,
     "el jueves pasado" -- la IA la convierte a ISO usando la fecha de
     hoy como referencia, pero NUNCA calcula un plazo ni inventa una
     fecha que no esté dicha) la usa para llenar sola la etapa
     correspondiente en `expediente_etapas` (mismo principio de nunca
     sobrescribir una etapa que ya tiene fecha, y queda en
     `log_historial`). Si la respuesta es vaga o no da fecha, se guarda
     igual (queda registrada, útil como bitácora) pero no llena nada
     solo.
   - El ejemplo real del usuario (demanda admitida → se ordena emplazar
     → el sistema pregunta cuándo se notificó → el abogado contesta la
     fecha → el sistema llena sola la etapa `emplazamiento` → en cuanto
     pasen los 15 días hábiles sin que se registre
     `contestacion_recibida`, `estancadoInfo()` lo vuelve a detectar como
     atraso automáticamente, sin tener que programar nada especial para
     "avisar que toca promover rebeldía" -- eso ya sale solo del ciclo
     normal: nuevo atraso → nueva pregunta del supervisor) queda cubierto
     de punta a punta con este diseño, reutilizando toda la maquinaria de
     plazos que ya existía.
   - **Confirmado funcionando en vivo (9 nov 2026)**: el cron generó 15
     preguntas reales sin fallar. Pendiente real todavía (no se ha
     verificado por separado): confirmar que `ia_interpretar_respuesta_
     supervisor()` extraiga bien una fecha de una respuesta real en
     lenguaje natural cuando un abogado conteste una de esas preguntas.
   - `app.js?v=121` (ver más abajo: promovido a sección propia del menú
     en `app.js?v=123`).
   - **Cambio de opinión del usuario (10 nov 2026)**: revirtió la
     decisión original de "sin push del todo" -- ahora cada pregunta
     nueva manda un push al abogado a cargo con link directo a
     contestarla (`?pregunta=<id>` -> `abrirPreguntaDesdeNotificacion()`
     en assets/app.js, mismo patrón ya usado para abrir una conversación
     de WhatsApp desde notificación). También se agregó un botón
     "🎤 Dictar" junto al textarea de respuesta (Web Speech API nativa del
     navegador, gratis, sin mandar audio a ningún servidor -- se omite
     solo si el navegador no lo soporta, ej. Safari/iPhone). `app.js?v=128`.
     **Dictado por voz confirmado funcionando en vivo (10 nov 2026)**.
     El push con link directo (`?pregunta=<id>`) todavía sin confirmar --
     el cron (`cron_generar_preguntas_supervisor.php`, ya reemplazado en
     el servidor) solo manda push cuando INSERTA una pregunta nueva, y al
     correrlo a mano el mismo día no encontró ningún atraso sin pregunta
     todavía (las 20 pendientes visibles ya eran de antes de este
     cambio) -- queda pendiente de confirmar la próxima vez que surja un
     atraso genuinamente nuevo (corrida diaria programada, o una corrida
     a mano futura que si encuentre uno).
   - **Push confirmado saliendo bien del servidor, pendiente de llegar al
     celular (10 nov 2026)**: se forzó una prueba real borrando una
     `supervisor_preguntas` ya generada y volviendo a correr el cron a
     mano -- regeneró la pregunta y `api/push_debug.log` confirma
     `OK -- https://web.push.apple.com/...` (abogada con iPhone). No le
     llegó al celular. El código del lado del servidor queda descartado
     como causa (mandó bien, Apple lo aceptó) -- pendiente de que ella
     pruebe el botón "Probar notificación" de "Mi cuenta" para aislar si
     es configuración de su iPhone (típicamente: el sistema no agregado a
     la pantalla de inicio, o notificaciones desactivadas para esa "app"
     en Ajustes) o algo más puntual de esta pregunta.
   - **Dictado por voz generalizado a TODO el sistema (11 nov 2026)**,
     pedido explícito del usuario tras confirmar que el de "Preguntas al
     abogado" funcionaba bien: se sacó la lógica de Web Speech API de ahí
     (antes vivía solo en el handler de ese botón) a un helper reutilizable
     -- `dictadoBotonHTML(targetId)` pinta el botón "🎤 Dictar" junto a
     cualquier `<textarea>` (se omite solo si el navegador no lo soporta),
     y un único listener delegado al final de `assets/app.js`
     (`document.addEventListener('click', ...)` sobre `.btn-dictar`)
     maneja el reconocimiento de voz sin tener que re-enganchar nada cada
     vez que un modal se vuelve a renderizar. Conectado en los 6 lugares
     del sistema donde se escribe texto libre: respuesta al supervisor
     (ya existía, solo se migró al helper), nota de seguimiento del
     expediente (`nuevaNotaTexto`), "¿Qué encontraste?" al registrar una
     revisión de boletín (`avisoResumen`), notas internas del despacho
     (`notasInternas`), descripción de hechos en "Consultar
     jurisprudencia" (`jurisprudenciaPreguntaInput`), y el campo
     `textarea` genérico de "Editar datos" (cubre `hecho_causa_narrativa`
     de Rescisión Art. 51, `ultima_nota`, y cualquier campo futuro de
     `EDITABLE_FIELDS` marcado `type:'textarea'`, sin tener que tocar
     nada cuando se agregue uno nuevo). `app.js?v=129`. Sin cambios de
     base de datos. No se tocó `mergeTags()`/`calcularJornada()`/tags de
     formato de demanda -- no aplica `verificar_tags.js`. **Sin probar en
     vivo todavía** ninguno de estos 5 lugares nuevos (el de supervisor ya
     estaba confirmado desde antes de generalizarse).
4. **IA lee documentos/fotos que sube el abogado -- HECHO (9 nov 2026),
   migración 062 y cron ya corridos/dados de alta (confirmado por el
   usuario el 10 nov 2026).** Pedido explícito del usuario: "sería excelente que en documentos se
   agregue un resumen del documento a un lado para ver de qué se trata".
   Mismo principio que el punto 2, pero para CUALQUIER documento que el
   abogado suba a mano en la pestaña "Documentos" (constancias, convenios,
   pruebas, cédulas de notificación, fotos...), no solo los acuerdos de
   boletín que suben los robots.
   - `ia_analizar_acuerdo_boletin()` (`api/ia_helpers.php`) se generalizó
     para esto: ahora acepta también imágenes (JPG/PNG, bloque `image`
     nativo de la API, igual que ya hacía con `document` para PDF) y dos
     parámetros nuevos opcionales (`$nombreArchivo`, `$categoria`) para
     dar más contexto; el texto de las instrucciones se generalizó de
     "acuerdo" a "documento" (sigue sirviendo igual para los acuerdos de
     boletín, que es como ya se usaba). El nombre de la función se dejó
     igual a propósito (ya la llama `cron_analizar_acuerdos_ia.php`) para
     no arriesgar nada ahí.
   - Nuevo cron **`api/cron_analizar_documentos_ia.php`** (correr cada
     15-30 min, junto con `cron_analizar_acuerdos_ia.php`): busca en
     `expediente_documentos` (migración 062: mismas 5 columnas `ia_*` que
     ya tiene `avisos_boletin` desde la 060) los que no se han analizado.
     Para no pagar dos veces por el mismo archivo, si el documento ya
     viene de un robot de boletín (tiene un aviso ligado en
     `avisos_boletin` que YA se analizó ahí), simplemente COPIA ese
     resultado en vez de volver a mandarlo a la IA. Solo lee PDF/JPG/PNG
     -- Word/Excel/txt se marcan como analizados sin resumen (no se
     puede leer ese tipo de archivo con este mecanismo). Mismo
     llenado-solo de etapa que el punto 2, con una diferencia a propósito:
     aquí NUNCA se usa la fecha de SUBIDA como respaldo si el documento no
     menciona una fecha literal (a diferencia del boletín, un documento se
     puede subir mucho después de lo que acredita) -- sin fecha explícita
     en el documento, el resumen se muestra pero no se aplica nada solo.
   - El resumen (`documentoIaHTML()`) se muestra debajo del nombre del
     archivo en la tabla de la pestaña "Documentos" (mismo estilo que ya
     usan los avisos de boletín), con la misma insignia "✓ etapa
     actualizada sola" cuando aplica. `app.js?v=124`.
   - **Confirmado funcionando en vivo (10 nov 2026)**: el cron corrió
     limpio sobre un lote real ("15 documento(s) en el lote, 15
     analizado(s) con IA... 1 etapa(s) llenada(s) sola(s)") -- confirma
     tanto el mecanismo de PDF como el de imagen/foto.
5. **Auditoría integral del expediente -- HECHO (10 nov 2026), migración
   064 y cron ya corridos/dados de alta (confirmado por el usuario el 10
   nov 2026, cron a las 8:00 am diario, una hora después del de
   "Preguntas al abogado").** Pedido
   explícito del usuario tras ver un caso real (informe ejecutivo de
   Margarita Reyna Ramírez Mercado desactualizado -- ver pieza aparte más
   abajo sobre ese bug puntual, ya corregido): "la ia tiene que leer toda
   la info del expediente y modificar todo... acuerdate que la intencion
   es que el sistema sea lo mas autosuficiente posible". Las piezas 2-4 ya
   llenan una etapa sola, pero cada una mira SOLO la pieza de información
   que acaba de llegar (un acuerdo, un documento, una respuesta) -- nadie
   hacía una pasada que viera el expediente COMPLETO junto, para detectar
   algo que cruzando varias fuentes sí se puede concluir pero que ningún
   disparador puntual alcanza a ver por sí solo.
   - Nueva función `ia_auditar_expediente()` (`api/ia_helpers.php`):
     recibe TODO el contexto ya guardado de un expediente (bitácora de
     etapas completa, documentos+resúmenes, avisos de boletín+resúmenes,
     preguntas del supervisor ya respondidas) y, SOLO para las etapas que
     siguen sin fecha, busca si esa información ya junta menciona
     LITERALMENTE cuándo pasó algo (mismo principio de siempre: nunca
     calcula ni infiere un plazo). Devuelve una lista de sugerencias con
     motivo citando de dónde sale cada fecha. Usa Haiku (mismo modelo que
     el resumen ejecutivo y las preguntas del supervisor) -- es pura
     síntesis de texto ya resumido, no lee PDF/imagen directo aquí.
   - Nuevo cron **`api/cron_auditoria_expediente_ia.php`** (correr una
     vez al día, puede ir junto con
     `cron_generar_preguntas_supervisor.php`): candidatos = expedientes
     activos con al menos una etapa ya registrada (si no tiene ninguna
     sigue siendo pre-demanda, nada que auditar) que cambiaron desde la
     última auditoría. Columna nueva `expedientes.ultima_auditoria_ia`
     (migración 064) -- mismo patrón de "no re-pagar si no cambió nada"
     que ya usa `resumen_ejecutivo_generado_en`; se marca al mismo tiempo
     que `actualizado_en` cuando sí se llena algo (mismo `NOW()` en la
     misma sentencia, para que la comparación de la siguiente corrida dé
     exactamente "no stale"). Cada fill llena la etapa (nunca sobrescribe
     una que ya tiene fecha, doble-checado contra la base justo antes de
     escribir, no solo confiando en lo que diga la IA) y queda en
     `log_historial` como "IA (auditoría integral)" con el motivo citado.
   - **Efecto en cadena gratis, sin tocar nada más**: al llenar una etapa
     esto ya bumpea `actualizado_en`, que es justo lo que el Informe
     Ejecutivo revisa (ver el arreglo del mismo día, un poco más abajo)
     para regenerarse solo la próxima vez que se abra -- y Agenda general
     ya lee las fechas de `expediente_etapas` directo, así que una etapa
     con fecha nueva aparece en la agenda sin tener que "agendar" nada
     aparte.
   - **Confirmado funcionando en vivo (10 nov 2026)**: el usuario corrió
     el cron a mano abriendo la URL directo en el navegador (mismo patrón
     que `reporte_monitoreo_faltante.php` -- el script no exige sesión).
     Primera corrida real: "20 expediente(s) candidato(s), 20
     auditado(s) con IA, 2 etapa(s) llenada(s) sola(s), 0 fallido(s)" --
     ambas llenadas en el mismo expediente (id 13, Mir Castro Ma Claudia
     vs Servicio Postal Mexicano, Exp. 242/2026): `conciliacion_prejudicial`
     (2026-05-18) y `objeciones_replica` (2026-06-12).
   - **Bug real encontrado por el usuario al revisar esos 2 llenados**: la
     fecha de `objeciones_replica` (2026-06-12) es ANTERIOR a
     `contestacion_recibida` (2026-07-26) -- imposible por ley (Art.
     873-B LFT, las objeciones/réplica solo pueden pasar DESPUÉS de que
     se reciba la contestación). La IA sí vio una fecha real en el
     contexto del expediente, pero la atribuyó a la etapa equivocada.
     `conciliacion_prejudicial` (2026-05-18) sí es consistente (es
     anterior a todo lo demás, que tiene fecha 2026-07-26 en bloque --
     parece una importación masiva el mismo día).
   - **Corregido (10 nov 2026)**: nueva función
     `etapa_fecha_es_consistente()` (`api/expediente_helpers.php`) usa el
     orden real de `ETAPA_KEYS` para rechazar cualquier fecha nueva que
     rompa la secuencia cronológica contra las etapas YA registradas de
     ese expediente. Se conectó en los 4 mecanismos que llenan una etapa
     sola (no solo la auditoría -- el mismo riesgo existía desde las
     piezas 2-4): `cron_auditoria_expediente_ia.php`,
     `cron_analizar_acuerdos_ia.php`, `cron_analizar_documentos_ia.php` y
     `supervisor_pregunta_responder.php`. Los rechazos quedan en
     `log_historial` (`auditoria_ia_rechazo_<etapa>`, sin el prefijo
     `etapa_` para no contarse como avance real en el resumen semanal).
   - **Corregido a mano por el usuario** -- la fecha equivocada de
     `objeciones_replica` del expediente 13 ya se borró desde "Etapas del
     juicio".
   - **Candado confirmado funcionando en vivo el mismo día**: en la
     siguiente corrida del cron (10 candidatos, 10 auditados, 5 etapas
     llenadas, 0 fallidos) se vio un rechazo real:
     `auditoria_ia_rechazo_amparo_directo` en el expediente 12 -- la IA
     quiso llenar esa etapa con una fecha que rompía el orden
     cronológico, y el candado lo bloqueó solo en vez de aplicarlo a
     ciegas. Las otras 5 etapas que sí llenó esa corrida (expedientes 16:
     `conciliacion_prejudicial`+`emplazamiento`, expediente 2:
     `emplazamiento`+`prevencion`, expediente 11:
     `contestacion_recibida`) no violaban el orden, así que se aplicaron
     normal -- confirma que el candado bloquea lo malo sin bloquear lo
     bueno.

## "Editar datos" filtra campos por tipo de demanda + Rescisión Art. 51 simplificada (11 nov 2026, `app.js?v=131`, migración 066)
Pedido explícito del usuario: que "Editar datos" muestre solo los campos
que corresponden al formato de demanda que se va a presentar, ocultando
los de las otras acciones (hoy el tab mezclaba TODOS los campos de
Demanda normal + Rescisión Art. 51 + Prima de antigüedad de un jalón).
- **Campo nuevo `tipo_demanda`** ("Tipo de demanda a presentar": Demanda
  (despido/prestaciones) / Rescisión Art. 51 / Prima de antigüedad),
  guardado en el expediente (migración **066_tipo_demanda.sql**).
  **Deliberadamente SEPARADO** del campo ya existente `tipo_asunto`
  (Despido/Prestaciones/Otro) -- ese solo calcula el plazo de
  prescripción, son conceptos legales distintos y reutilizarlo hubiera
  arriesgado romper ese cálculo. El usuario lo confirmó explícitamente
  tras preguntarle si reutilizaba el campo existente o hacía uno nuevo.
- Cada campo de `EDITABLE_FIELDS` que ya traía el prefijo "Rescisión —"
  o "Prima antigüedad —" en su label se etiquetó con `scope` (el valor
  exacto de `tipo_demanda` al que pertenece); los campos sin `scope`
  (actor, fechas, salario, etc.) son comunes a cualquier demanda y
  siempre se muestran. **Nunca se quitan del DOM** -- se renderizan
  TODOS pero los que no aplican quedan con `display:none`, y un listener
  en el `<select>` de `tipo_demanda` alterna esa visibilidad al vuelo
  (`[data-tipo-demanda-scope]`) sin re-renderizar el tab completo -- a
  propósito, para no arriesgar perder algo que el abogado ya haya escrito
  sin guardar en otro campo visible.
- **Simplificación de Rescisión Art. 51** (3 pedidos explícitos del
  usuario en el mismo mensaje):
  1. **Se quitaron los 6 campos de cuentas bancarias** (`banco1/2/3_
     {nombre,cuenta}`) de "Editar datos" y de `EDITABLE_CAMPOS` (whitelist
     del servidor) -- las columnas de la base NO se borraron (mismo
     criterio de siempre con datos viejos), solo dejaron de ser editables.
  2. **Se quitó la prueba de "informe bancario"** que `construirPruebas
     AdicionalesTexto()` armaba por cada banco capturado (la que el
     usuario describe como la del "informe de la Comisión Nacional
     Bancaria y de Valores" -- no existe ningún otro texto de CNBV en el
     código, esta es la única prueba relacionada con bancos que había).
     La numeración de pruebas adicionales ahora arranca fija en 5
     (Inspección Judicial) / 6 (Testimonial), sin depender de bancos.
  3. **El "hecho" de la causal ahora lo redacta el abogado por completo**,
     a mano, en el mismo campo `hecho_causa_narrativa` (antes era un
     "detalle adicional" opcional que se agregaba DESPUÉS de un párrafo
     que el sistema armaba solo según la fracción elegida). Se quitó
     `construirHechoCausaAuto()` y el catálogo de 9 frases automáticas por
     fracción (`FRACCION_51[...].inicio()`, que ya no se invoca en
     ningún lado, queda como dato muerto sin usar). El selector de
     fracción (`rescision_fraccion`) se queda igual -- sigue alimentando
     `rescision_motivo_breve` (prestación A) -- pero ya no dispara ningún
     texto automático del hecho. `tags.hecho_causa_auto` se deja vacío a
     propósito (por si la plantilla .docx subida todavía tiene ambos tags
     uno junto al otro, para no imprimir "undefined" ni duplicar nada).
- `node scripts/verificar_tags.js` corrido y actualizado (las pruebas
  viejas que esperaban `hecho_causa_auto` con texto y numeración variable
  por bancos ya no aplicaban con el diseño nuevo -- se reescribieron para
  reflejar el comportamiento correcto) -- pasa limpio.
- **Bug real encontrado por el usuario al probar en vivo (11 nov 2026)**:
  al elegir "Prima de antigüedad" seguían apareciendo "Nombre de quien
  despidió" / "Puesto de quien despidió" / "Hora del despido" -- esos 3
  campos no tenían `scope` (se habían dejado como "comunes" por error, ya
  que no llevaban el prefijo "Rescisión —"/"Prima antigüedad —" en su
  label). Son específicos de una demanda por despido clásico (no aplican
  a Rescisión -- ahí es el trabajador quien se va -- ni a Prima de
  antigüedad, que no tiene despido de por medio). Corregido: ahora llevan
  `scope:'Demanda (despido / prestaciones)'`. `app.js?v=132`.
  **Quedan pendientes de revisar** otros campos "comunes" que podrían ser
  igual de específicos a un solo tipo (ej. los de aguinaldo/salarios
  devengados/vacaciones anteriores) -- no se tocaron todavía porque
  Rescisión Art. 51 SÍ los reutiliza en su propio panel de prestaciones
  opcionales (`PRESTACIONES_RESCISION_OPCIONES`), así que esconderlos a
  ciegas podría romper eso. Pedirle al usuario que confirme cuál de los 3
  tipos los necesita antes de tocarlos, no adivinar.
- **Revisión completa campo por campo (11 nov 2026, pedido explícito del
  usuario: "revisa bien", no solo los 3 que él encontró)**. `scope` ahora
  también acepta un arreglo (antes solo un string) para campos que
  aplican a 2 de los 3 tipos pero no a los 3 -- el filtro y el listener de
  `tipo_demanda` lo soportan (`data-tipo-demanda-scope` guarda la lista
  separada por `|`). Decisiones, confirmadas con el usuario cuando no
  había evidencia de código suficiente para decidir solo:
  - **Horario/jornada** (hora_entrada/salida, comida, días laborados,
    tipo_jornada): `scope:['Demanda (despido / prestaciones)','Rescisión
    Art. 51']` -- Rescisión los reutiliza de verdad (checkbox "Horas
    extras" de `PRESTACIONES_RESCISION_OPCIONES`), Prima de antigüedad no
    tiene ninguna referencia a jornada/horas extra en ningún lado.
  - **Testigos y sus domicilios**: mismo scope -- confirmado con el
    usuario, Prima de antigüedad usa pericial caligráfica + verificación
    de CFDI como pruebas, no testigos.
  - **Fecha de baja/despido**: se queda COMÚN a los 3 (confirmado con el
    usuario) -- la usa el Cálculo de liquidación independientemente del
    tipo de demanda elegido, así que ocultarla en Prima de antigüedad
    hubiera dejado esa otra pestaña sin el dato.
  - **Aguinaldo pactado / salarios devengados / vacaciones anteriores
    reclamadas / prima vacacional pactada**: también se quedan COMUNES a
    los 3 (confirmado con el usuario), mismo motivo -- alimentan el
    Cálculo de liquidación aparte del tipo de demanda, y Rescisión además
    los reutiliza en su panel de prestaciones opcionales.
  - El resto (actor, demandado, domicilios, fechas de ingreso, salarios,
    instancia/tribunal/exp/status, notas) se quedó común a propósito --
    son datos de identificación/trámite que cualquier demanda necesita,
    sin evidencia de código que sugiera lo contrario.
  `app.js?v=133`.
- **Bug real encontrado y corregido (12 nov 2026)**: la abogada reportó
  que `tipo_demanda` no se guardaba al elegirlo (sin ningún error visible)
  y que, por eso, en cualquier dispositivo los campos de testigos/horas
  extra seguían escondidos (dependen de que `tipo_demanda` tenga valor).
  Causa real: `api/expediente_helpers.php` en el SERVIDOR tenía una
  versión vieja de `EDITABLE_CAMPOS` sin `tipo_demanda` en la lista --
  el servidor lo rechazaba en silencio (`in_array($key, EDITABLE_CAMPOS,
  true)` devuelve `false` y simplemente lo salta, sin lanzar error) cada
  vez que alguien intentaba guardarlo. El archivo en el repo SÍ lo tenía
  bien desde que se creó el campo -- nunca se volvió a subir ese archivo
  específico al servidor después de ese cambio (riesgo ya documentado en
  la sección de "varias sesiones" de este mismo archivo: una entrega que
  no incluye TODOS los archivos tocados puede dejar al servidor con una
  mezcla de versiones). **Confirmado arreglado tras resubir el archivo.**
  Lección para la próxima vez que algo se "guarda sin error pero no
  queda": sospechar primero de un archivo PHP desactualizado en el
  servidor (sobre todo la whitelist `EDITABLE_CAMPOS` de
  `expediente_helpers.php`) antes de asumir un bug de lógica nuevo --
  pedir reenviar/resubir el archivo es la prueba más barata.
- **Sin probar en vivo todavía** generar un documento de Rescisión Art. 51
  real para ver cómo sale en Word sin las cuentas bancarias y con el
  hecho manual.

## Google Calendar → sistema: lectura de audiencias agendadas por los abogados (11 nov 2026, `app.js?v=135`, migración 067)
Pedido explícito del usuario, descubierto mientras se investigaba el bug de
la audiencia de conciliación de Verónica Gabriela: cada abogado agenda sus
audiencias/citatorios directo en su propio sub-calendario de Google (ej.
"Audiencias Lindsay", visibles todos desde UNA sola cuenta de Google -- la
del Administrador, que ya la conectó antes para el sync de salida que ya
existía), en vez de capturarlas en el sistema. Esto agrega la lectura en
sentido CONTRARIO al sync que ya había (que solo mandaba del sistema hacia
Google, nunca al revés).
- **Dato técnico bueno confirmado**: el scope de OAuth que ya se pidió
  (`calendar.events`) alcanza para LEER eventos también, no sólo
  escribirlos -- no hizo falta pedirle a nadie que reautorice nada.
- **Diseño acordado con el usuario** (2 preguntas antes de construir):
  (1) una sola cuenta de Google (la del Administrador) con un sub-
  calendario por abogado, en vez de que cada quien conecte la suya; (2) el
  texto de un evento es libre ("Conciliación Verónica Gabriela", puede
  haber tocayos/apellidos distintos/errores de dedo) -- la IA SUGIERE a
  qué expediente/etapa corresponde, nunca lo aplica sola; el abogado
  confirma con un tap (mismo principio de todo el sistema).
- **Migración 067**: `google_calendarios_vigilados` (qué sub-calendario
  corresponde a qué abogado, y qué usuario tiene la cuenta de Google
  conectada con visibilidad sobre él) + `google_calendario_sugerencias`
  (un renglón por evento ya revisado -- la clave única
  `(calendar_id, google_event_id)` es el candado contra duplicados que
  pidió el usuario explícitamente: un evento ya visto, en cualquier
  estado, nunca se vuelve a procesar ni a re-sugerir).
- **`api/google_calendar_helpers.php`** (ya existía para el sync de
  salida): se agregaron `google_listar_calendarios()` y
  `google_listar_eventos()` (próximos 60 días, `singleEvents=true` para
  que una recurrencia llegue como instancias concretas, no como un bloque
  RRULE que habría que interpretar aparte).
- **`ia_matchear_evento_calendario()`** (`api/ia_helpers.php`, Haiku,
  texto puro): recibe el evento (título/descripción/fecha) + la lista de
  expedientes ACTIVOS de ese mismo abogado, y responde (1) a qué
  expediente corresponde -- SOLO si el nombre calza con claridad, nunca
  adivina entre candidatos ambiguos -- y (2) qué etapa representa, de un
  vocabulario cerrado a propósito a solo 4 claves con concepto de "fecha
  agendada" (`conciliacion_primer_citatorio`, `conciliacion_segundo_citatorio`,
  `audiencia_preliminar`, `audiencia_juicio`) -- "audiencia" a secas sin
  decir si es preliminar o de juicio responde (ninguna), nunca adivina
  cuál de las dos.
- **Nuevo cron `api/cron_leer_google_calendar.php`** (correr 1-2 veces al
  día): por cada calendario vigilado, lee sus eventos próximos, descarta
  los que ya se procesaron antes (candado de duplicados), y para los
  nuevos llama a la IA. Si hay match de expediente Y etapa → queda
  `estado='pendiente'` y manda push al abogado con link directo
  (`?pregunta_calendario=1` → `VIEW='preguntas_abogado'`, mismo patrón que
  `?pregunta=<id>` de la pieza 3). Si no hay match claro → queda
  `estado='sin_match'`, registrado pero sin molestar a nadie (**pendiente
  real**: hoy esos "sin_match" no se ven en ningún lado de la UI -- si
  resultan ser muchos en la práctica, falta una pantalla para revisarlos a
  mano en vez de que se pierdan silenciosamente).
- **Confirmar/descartar** (`api/google_calendario_sugerencia_resolver.php`):
  nuevo panel "📅 Eventos de tu Google Calendar por confirmar" arriba de
  "Pendientes" en la vista "Preguntas al abogado" -- un tap en "Sí, es
  esta" llena `fecha_programada`/`hora` de la etapa correspondiente (nunca
  toca `fecha`/`resultado` si ya existían), registra en `log_historial`, y
  bumpea `actualizado_en` (para que el Informe Ejecutivo se refresque
  solo, mismo patrón que el resto del sistema). "No es esta" solo descarta
  -- la clave única ya evita que ese mismo evento se vuelva a sugerir.
- **Configuración nueva, solo Administrador** ("Equipo" → panel
  "Calendarios de Google vigilados"): botón "Cargar calendarios
  disponibles" (llama a Google en vivo con la cuenta ya conectada del
  Administrador) + un selector de abogado por calendario + "Guardar"/
  "Quitar".
- **Acción pendiente del usuario para probarlo**: entrar a "Equipo",
  cargar la lista de calendarios, y mapear cada "Audiencias X" al abogado
  correspondiente (ej. "Audiencias linsday" → Lic. Lindsay Luna Maldonado)
  -- sin esto el cron no tiene nada que vigilar todavía. Después correr
  `cron_leer_google_calendar.php` a mano (mismo patrón que los demás
  crons, abrir la URL directo) para la primera prueba real.
- **2 bugs reales encontrados y corregidos al probar en vivo por primera
  vez (11 nov 2026)**:
  1. **El scope de OAuth pedido (`calendar.events`) no alcanzaba para
     listar los calendarios** -- `calendarList.list` de la API de Google
     es un endpoint aparte que `calendar.events` no cubre (solo cubre
     eventos, no la lista de calendarios); por eso "Cargar calendarios
     disponibles" en Equipo devolvía "Sin calendarios encontrados" aunque
     sí había varios. Corregido agregando
     `calendar.calendarlist.readonly` al scope en
     `api/google_oauth_start.php` (ahora pide los 2 scopes juntos,
     separados por espacio). **Requiere reconectar**: el Administrador
     tuvo que volver a autorizar desde el botón "Conectar con Google
     Calendar" (en Agenda general, no en "Mi cuenta" -- ojo, ahí es donde
     vive este botón) para que el permiso nuevo quedara en su
     refresh_token -- como ya tenía `prompt=consent`, no hizo falta
     desconectar primero (eso habría borrado los eventos que el sync de
     salida ya había creado en su Google Calendar).
  2. **`SQLSTATE[HY093]: Invalid parameter number`** en
     `cron_leer_google_calendar.php` -- la consulta de expedientes activos
     repetía el parámetro con nombre `:aid` dos veces
     (`abogado_id = :aid OR abogado_id_2 = :aid`); con
     `PDO::ATTR_EMULATE_PREPARES => false` (como usa este sistema, ver
     `api/db.php`), MySQL con prepares nativos NO permite reutilizar el
     mismo placeholder con nombre dos veces en una sola consulta --
     causaba el Error 500 real. Corregido separando en `:aid1`/`:aid2`
     (mismo valor, dos placeholders). Si en el futuro aparece este mismo
     error en cualquier cron/endpoint nuevo, revisar primero si algún
     placeholder con nombre se repite en la misma consulta -- no es la
     primera vez que se puede caer en esto al copiar un patrón de SQL con
     `OR` sobre 2 columnas.
  - **Confirmado funcionando de punta a punta en vivo (11 nov 2026,
     cuenta `themislex76@gmail.com`, calendario "Audiencias linsday"
     mapeado a Lic. Lindsay Luna Maldonado)**: primera corrida real del
     cron reportó "14 evento(s) revisado(s), 5 sugerencia(s) nueva(s) con
     match, 9 sin match claro, 0 fallido(s)". Confirma lectura de Google
     Calendar + match de la IA + candado de duplicados funcionando juntos
     por primera vez.
  - **Cambio a autónomo (11 nov 2026, mismo día, pedido explícito del
    usuario tras ver el panel de confirmación)**: "no quiero que tenga que
    estar confirmando... que esta sea una entrada de información más para
    que la IA actualice el sistema". Se quitó el tap de confirmación para
    el caso normal -- ahora, si la IA da un match claro (expediente+etapa,
    nunca adivina entre candidatos ambiguos) y esa etapa no tenía ya una
    fecha agendada distinta, `cron_leer_google_calendar.php` llena sola
    `fecha_programada`/`hora` directo, igual que ya hacían
    `cron_analizar_acuerdos_ia.php`/`cron_analizar_documentos_ia.php`/
    `cron_auditoria_expediente_ia.php` -- queda en `log_historial` como
    "llenado automático desde Google Calendar" (auditable/corregible a
    mano) y pasa por el mismo candado de orden cronológico
    (`etapa_fecha_es_consistente()`): si lo rompe, se rechaza solo y
    queda registrado como `calendario_ia_rechazo_<etapa>` (estado nuevo
    `rechazada` en el ENUM, migración **068_google_calendario_estado_
    rechazada.sql**).
    El único caso que SIGUE pidiendo un tap humano (panel renombrado a
    "📅 Conflictos de fecha en tu Google Calendar" en "Preguntas al
    abogado", estado `pendiente`): la etapa ya tenía una fecha agendada
    DISTINTA a la del evento -- ahí no se sabe si es un reagendamiento
    real o un match equivocado, así que no se sobrescribe en silencio
    (mismo principio de "nunca sobrescribe una fecha que ya tenía" que
    usa el resto del sistema). `google_calendario_sugerencias_list.php`
    ahora también manda `fecha_actual_programada` (vía LEFT JOIN a
    `expediente_etapas`) para que la tarjeta de conflicto muestre las 2
    fechas en pugna. `app.js?v=136`.
  - **Nota sobre las 5 sugerencias de la primera corrida**: esas 5 ya
    quedaron guardadas como `pendiente` con el código VIEJO (antes de
    quitar el tap) -- el candado de duplicados hace que no se
    re-procesen solas con la lógica nueva. Van a seguir apareciendo en
    "Conflictos de fecha" aunque la mayoría probablemente no sea un
    conflicto real (solo eran la primera vez que se veía esa etapa). Más
    simple resolver esas 5 a mano una sola vez (tap en cualquiera de los
    2 botones) que escribir una migración de limpieza para un lote
    tan chico -- de ahí en adelante, con eventos genuinamente nuevos, ya
    corre completo el flujo automático.
  - **Confirmado funcionando en vivo (11 nov 2026)**: se corrió
    `api/_una_vez_reprocesar_calendario_legacy.php` (migración 068 ya
    aplicada primero) y resolvió las 5 sugerencias legacy -- "5
    sugerencia(s) aplicada(s) sola(s), 0 rechazada(s) por orden
    cronológico, 0 dejada(s) pendiente(s) por conflicto real". Confirma
    que ninguna de las 5 era un conflicto de verdad (como se esperaba) y
    que el candado de orden cronológico no bloqueó nada indebido. El
    script ya cumplió su función -- el usuario puede borrarlo del
    servidor.
  - **Pendiente de confirmar todavía**: revisar en algún expediente real
    (ej. Verónica Gabriela) que "Fecha agendada" en Etapas del juicio
    haya quedado bien llenada, y que el panel "Conflictos de fecha en tu
    Google Calendar" ya esté vacío. También correr el cron de nuevo más
    adelante con eventos GENUINAMENTE nuevos (no reprocesa los ya
    vistos) para confirmar el flujo automático de punta a punta en un
    caso que no sea legacy. Falta mapear los demás calendarios vistos
    (Audiencias Mariana, Audiencias Christian, Audiencias Rubén,
    Audiencias Junta Federal) al abogado que corresponda a cada uno --
    por ahora solo "Audiencias linsday" está vigilado. Pendiente también
    la respuesta del usuario sobre si el criterio de "nunca auto-elegir
    la fecha más reciente en un conflicto real, siempre pedir un tap" le
    parece bien (se lo pregunté explícitamente, sin respuesta todavía).

## Calendario: bug de citatorios de conciliación + alcance limitado a fechas puntuales (11 nov 2026, `app.js?v=134`)
Pedido/bug real del usuario al probar el calendario nuevo: "la abogada tiene un pago de Francisco agendado y una audiencia de conciliación de Veronica Gabriela" que no aparecían.
- **Bug real encontrado**: un citatorio de conciliación agendado (`conciliacion_primer_citatorio`/
  `conciliacion_segundo_citatorio` en `ETAPAS_DEF`) **nunca aparecía en NINGÚN lado de Agenda** --
  no era un bug solo del calendario nuevo, es un hueco que existía desde antes en toda la Agenda
  (lista "General" incluida). Causa real: `proximaAudiencia()` solo revisaba
  `audiencia_preliminar`/`audiencia_juicio`, y "Etapas del juicio" ni siquiera ofrecía un campo de
  "Fecha agendada" para los citatorios de conciliación (`esAudiencia` los excluía) -- solo tenían
  un único campo de fecha ambiguo que servía lo mismo para "ya se celebró" que para "está agendado
  para". Corregido: los citatorios de conciliación ahora tienen su propio campo "Fecha agendada"
  (igual que una audiencia) y `proximaAudiencia()` los incluye.
  - **Acción pendiente del usuario**: cualquier expediente que YA tenía un citatorio de conciliación
    capturado (como el de Veronica Gabriela) lo tiene guardado en el campo viejo "Fecha en que se
    celebró" -- si todavía no se celebra, hay que pasarlo a mano al nuevo campo "Fecha agendada"
    desde "Etapas del juicio" para que empiece a aparecer en Agenda/Calendario.
- **Alcance del calendario limitado a fechas puntuales** (pedido explícito): solo muestra
  audiencias/citatorios agendados, pagos de convenio, y vencimientos de prescripción/amparo --
  pendientes manuales y "posibles atrasos"/sugerencias de IA se quedan solo en la lista "General"
  (ahí sí hace falta el botón de resolver acción). Se incluyó amparo junto con prescripción por
  ser el mismo tipo de plazo legal -- no se preguntó explícitamente, avisar si no era lo que
  quería.
- **Aviso "vence mañana"**: prescripción y amparo ahora también se marcan un día antes del
  vencimiento real (con ⏰, borde punteado), además del día exacto -- mismo principio que ya usa
  el push diario (`cron_recordatorio_abogado.php` avisa justo 1 día antes), ahora también visible
  en el calendario.
- **Sin probar en vivo todavía** ninguna de las 3 piezas (citatorio de conciliación, alcance
  limitado, aviso de un día antes).

## Calendario visual en Agenda general + IA sugiere categoría de documento (11 nov 2026, `app.js?v=130`)
Pedido del usuario tras una lluvia de ideas sobre autonomía del sistema
("que mas se te ocurre"): de 4 propuestas, aceptó 1 (categoría sugerida),
rechazó 2 (tribunal automático por prefijo -- aclaró que esos prefijos son
de Centros de Conciliación, no de Tribunal; y avisos anticipados antes del
vencimiento), y redirigió la 4ª (checklist de choques de horario, que
**ya existía** -- `marcarChoquesDeAgenda()`, sin que lo supiera) hacia algo
distinto: un calendario visual en vez de solo lista.
- **Pestaña "Calendario" nueva dentro de Agenda general** (`AGENDA_TAB=
  'calendario'`, junto a General/Asesorías/Seguimiento IA): cuadrícula de
  mes (`calendarioMesHTML()`, lunes-primero) con navegación ‹ mes actual
  › y botón "Hoy". Reutiliza `buildAgendaEntries()` +
  `marcarChoquesDeAgenda()` tal cual (mismo filtro de socio para el
  Administrador, `AGENDA_SOCIO`, que ya traían las demás pestañas -- cada
  abogado ya veía solo lo suyo automáticamente vía `visibleCases()`, eso
  NO era nuevo, solo le faltaba la vista de calendario). Cada pendiente es
  un bloque clicable con el color de `AGENDA_TIPO` que abre el expediente
  directo (mismo patrón `[data-id]` que ya usa toda la app); ⚠ marca
  choque de horario. Variable de estado nueva `AGENDA_CAL_MES` (null hasta
  el primer render, se inicializa al mes actual).
- **IA sugiere la categoría de un documento subido como "Otro"**: extiende
  `ia_analizar_acuerdo_boletin()` (ya analiza cualquier documento desde la
  pieza 4) con una 4ª parte en el prompt -- si el contenido deja clarísimo
  que el documento es, por ejemplo, un Convenio o una Contestación, lo
  dice; si la categoría actual ya es razonable o no hay nada obvio,
  responde `(ninguna)`. **A propósito SOLO se usa cuando el documento se
  guardó bajo el cajón genérico 'otro'** -- si el abogado ya eligió algo
  específico a mano, no se le molesta con una sugerencia.
  - **Diferencia de diseño importante frente a las etapas**: una fecha de
    etapa llenada sola se puede borrar después desde "Etapas del juicio"
    si la IA se equivocó -- hoy NO existe ninguna forma de editar la
    categoría de un documento ya subido salvo borrándolo entero. Por eso
    esto NUNCA se aplica solo: la sugerencia se guarda en la columna nueva
    `ia_categoria_sugerida` (migración **065_ia_sugiere_categoria_documento.sql**)
    y se muestra en la pestaña "Documentos" como "📁 ¿Categoría: X? [Usar]"
    -- el abogado confirma con un tap (`api/documentos_recategorizar.php`,
    nuevo endpoint, valida que la categoría sugerida siga siendo válida,
    actualiza `categoria`, limpia la sugerencia, y lo deja en
    `log_historial` como `documento_categoria`).
  - `cron_analizar_documentos_ia.php` ahora guarda esa sugerencia junto con
    el resto del análisis (solo cuando `categoria === 'otro'`) -- no corre
    más seguido, mismo cron de cada 15-30 min de la pieza 4. Los acuerdos
    de boletín (vía `cron_analizar_acuerdos_ia.php`) NO se tocan por esto
    -- ya llegan con categoría correcta (`acuerdo`/`acuerdo_amparo`) puesta
    por el robot, nunca bajo "Otro", así que la sugerencia no les aplica.
- Sin cambios a `mergeTags()`/`calcularJornada()`/tags de demanda -- no
  aplica `verificar_tags.js`. **Sin probar en vivo todavía** ninguna de las
  dos piezas (ni el calendario ni la sugerencia de categoría).

## Calendario: tarjetas más pulidas + detalle flotante (11 nov 2026, `app.js?v=137`)
Pedido del usuario viendo el calendario visual en vivo: "¿crees que se
pueda dar el mismo efecto visual que Google Calendar?" -- se le preguntó
qué parte específica (tarjetas pulidas, ver detalle sin salir, vista
semanal con horas, o "+N más" clicable) y eligió las primeras 2.
- **Tarjetas pulidas**: cada pendiente del calendario ahora es una
  "pill" con barra de color a la izquierda (en vez de fondo plano de
  color), texto en negro para mejor contraste, la hora en negrita, y una
  sombra sutil -- mismo look de chip que usa Google Calendar.
- **Detalle sin salir del calendario**: dar clic en un pendiente YA NO
  salta directo al expediente -- abre una ventana flotante (anclada junto
  al chip, se ajusta sola si no cabe en la pantalla) con tipo, fecha en
  formato legible ("lunes 9 de noviembre"), hora, actor vs demandado y
  número de expediente, más un botón "Abrir expediente" para quien sí
  quiera entrar. Se cierra sola al dar clic fuera o al usar ese botón.
  Nuevas funciones `abrirCalPopover()`/`cerrarCalPopover()`; el estado
  `CAL_POPOVER_ITEMS` guarda el detalle completo de cada chip del render
  actual (el HTML del chip solo lleva un índice, no todo el texto, para
  no complicar el escapado de comillas/caracteres).
- Sin cambios de base de datos. No se tocó `mergeTags()`/tags de
  demanda -- no aplica `verificar_tags.js`.
- **Bug real encontrado por el usuario al probar en vivo (11 nov 2026)**:
  el popover se veía "transparente", con texto traslapado e ilegible.
  Causa real: tanto el popover como el fondo de las celdas del mes usaban
  `var(--card)` -- una variable CSS que **nunca existió** en
  `style.css` (la correcta es `--paper`). Un `background:var(--card)`
  sin valor de respaldo es inválido y el navegador lo ignora por
  completo, dejando el fondo transparente -- por eso se veía el texto de
  las celdas de atrás encimado con el del popover. Corregido en los 2
  lugares (celdas del mes y popover) + aumentada la opacidad del tinte de
  color de cada chip (`${t.color}14` → `${t.color}26`) + el popover ahora
  lleva una franja superior sólida del color del tipo (en vez de solo un
  puntito) para que se vea "más bonito, con color" como pidió el usuario.
- **Vistas Semana y Día agregadas (11 nov 2026, mismo día, pedido
  explícito del usuario: "que se pueda ver por semana y dia")** -- antes
  solo existía la vista de Mes. Pestañas nuevas arriba del calendario
  (Mes/Semana/Día, `AGENDA_CAL_VISTA`). Semana: 7 columnas (lunes-primero)
  con los pendientes de cada día apilados (no se implementó un grid de
  horas con posición absoluta por minuto -- con pocos pendientes por día
  una lista simple ordenada por hora se lee mejor y es más robusta). Día:
  una sola columna en grande con el nombre completo sin truncar. Las 3
  vistas comparten el mismo popover de detalle al dar clic
  (`calCeldaChip()`, helper nuevo que reemplaza el chip inline que antes
  solo vivía dentro de `calendarioMesHTML`). Semana/Día comparten una
  ancla de fecha nueva (`AGENDA_CAL_DIA`, independiente de `AGENDA_CAL_MES`
  que sigue siendo mes/año para la vista Mes) -- los botones ‹/›/Hoy
  mueven 7/1 días en Semana/Día, o el mes completo en Mes, según la
  pestaña activa. `app.js?v=138`.
- **Bug real encontrado por el usuario al probar en vivo (11 nov 2026,
  mismo día)**: la audiencia de conciliación de Verónica Gabriela
  (agendada hoy 11 nov, confirmada visible en Google Calendar) NO
  aparecía en el calendario del sistema. Causa real confirmada revisando
  "Etapas del juicio": la etapa "Conciliación prejudicial (Segundo
  citatorio)" ya tenía "Fecha en que se celebró" = 11/11/2026 (dato viejo
  de antes de que se separaran los 2 campos esta mañana), además de
  "Fecha agendada" = 11/11/2026 12:30pm (la que acababa de llenar sola el
  Google Calendar). `proximaAudiencia()` -- la función que alimenta la
  lista "General" y, hasta ahora, también el calendario -- excluye a
  propósito cualquier etapa que ya tenga algo en "Fecha en que se
  celebró" (la trata como "ya pasó, nada que agendar"), así que nunca
  llegaba a mostrarse aunque la fecha agendada fuera correcta.
  - **Decisión de diseño explícita del usuario**: "aunque ya se haya
    celebrado quiero que se muestren en agenda" -- el calendario debe
    comportarse como Google Calendar (un registro de lo agendado, pasado
    o futuro), no solo mostrar "la próxima pendiente" como sí debe seguir
    haciendo la lista "General" (ahí si importa solo lo pendiente, para
    no inflar el conteo de cosas por resolver) y los mensajes de WhatsApp
    al cliente (ahí decirle "tu próxima audiencia es..." de algo que ya
    pasó sería confuso). Por eso NO se tocó `proximaAudiencia()` --
    sigue igual, la usan esos 2 lugares sin cambio.
  - **Arreglado con una función nueva, exclusiva del calendario**:
    `audienciasParaCalendario(kase)` -- mismas 4 claves que
    `proximaAudiencia()` (preliminar/juicio/conciliación 1º/2º
    citatorio), pero devuelve TODAS las que tengan "Fecha agendada" sin
    excluir las que ya tengan también "Fecha en que se celebró", y sin
    quedarse solo con la más próxima (devuelve el arreglo completo, no
    un solo candidato). `calendarioItemsPorDia()` ahora arma sus propias
    entradas tipo 'audiencia' a partir de esta función (con el mismo
    filtro de socio/concluidos que ya aplicaba el resto de Agenda
    general) en vez de tomarlas de `all` (que solo trae lo que
    `buildAgendaEntries()` ya filtró vía `proximaAudiencia()`). `pago`/
    `prescripcion`/`amparo` del calendario siguen saliendo de `all` sin
    cambio. `app.js?v=139`.
- **Sin probar en vivo todavía**: ni las vistas Semana/Día (el arreglo del
  popover transparente sí quedó confirmado con capturas reales), ni este
  último arreglo de audiencias ya celebradas -- confirmar que la
  conciliación de Verónica Gabriela ya aparezca en el calendario de hoy.

## Panel "Actividad de la IA" para monitorear llenados/rechazos automáticos (11 nov 2026, `app.js?v=140`)
Pedido explícito del usuario: "¿crees que debemos poner una sección donde
podamos ver los cambios que ha hecho la IA para monitorear lo que está
haciendo?" -- con 5 mecanismos ya llenando etapas solas (acuerdos de
boletín, documentos subidos, auditoría integral, Google Calendar, y el
script de reprocesamiento legacy), la única forma de revisarlo era entrar
al "Historial de cambios" de cada expediente uno por uno.
- **Nuevo endpoint `api/ia_actividad_list.php`** (solo Administrador):
  junta en una sola lista, ordenada por más reciente (últimos 30 días por
  default, máx 300 filas), todas las entradas de `expediente_historial`
  donde `usuario_id IS NULL` -- ese es justo el criterio que ya usan
  `log_historial()` cuando la llama uno de los 5 mecanismos automáticos
  (`['id'=>null, 'nombre'=>'IA (...)']`), a diferencia de las acciones que
  SÍ pasan por un tap de confirmación humana primero (responder al
  supervisor, confirmar un evento de Google Calendar, recategorizar un
  documento), que quedan con el usuario_id real de quien confirmó y por
  eso no entran aquí -- esas ya las revisó un humano antes de aplicarse,
  no es la misma urgencia de supervisión.
- Panel nuevo en **"Equipo"** (entre "Calendarios de Google vigilados" y
  "Respaldo de toda la información"): tabla con fecha, expediente
  (clicable, abre el expediente directo) y qué hizo -- con una insignia
  "✓ aplicado" o "✗ rechazado" (detecta rechazos por el patrón
  `_rechazo_` en el nombre del campo, ej. `calendario_ia_rechazo_<etapa>`/
  `auditoria_ia_rechazo_<etapa>`).
- Sin cambios de base de datos (solo lee `expediente_historial`, que ya
  existía). No se tocó `mergeTags()`/tags de demanda -- no aplica
  `verificar_tags.js`. **Sin probar en vivo todavía**.

## Informe ejecutivo desactualizado: se revisaba solo una vez, nunca de nuevo (10 nov 2026)
El usuario detectó en vivo (expediente de Margarita Reyna Ramírez
Mercado) que el Informe Ejecutivo con IA seguía diciendo "aún no se ha
registrado el resultado de la conciliación", generado el 19 oct, aunque
el abogado ya había marcado "Constancia de no conciliación recibida" el
9 nov -- el informe nunca se enteró. El backend
(`expediente_resumen_ejecutivo.php`) ya comparaba `actualizado_en` contra
`resumen_ejecutivo_generado_en` para decidir si regenerar, y
`expedientes_update_etapas.php` ya actualizaba `actualizado_en` al
guardar una etapa (a propósito, para esto) -- pero el frontend solo
llamaba al endpoint si `!k.resumen_ejecutivo`, así que un informe ya
generado una vez nunca volvía a pedirle al backend que revisara si
seguía vigente. Se quitó esa condición en los 2 lugares que abren la
pestaña "Informe del juicio" (`abrirCaso()` y el click handler de
`.modal-tabs`); para no tapar el texto ya visible con el indicador de
carga cada vez que se abre, la revisión es silenciosa cuando ya había un
resumen mostrado (solo se ve "cargando" si no hay nada todavía o si se
fuerza con el botón "Actualizar"). `app.js?v=127`. Sin cambios de base de
datos.

Principio de diseño acordado con el usuario para las piezas 2-5: los
DATOS DUROS (fechas, etapas) siguen siendo estructurados -- de ahí sale
el cálculo automático de plazos que ya existe y funciona (prescripción,
amparo). Lo que se vuelve IA es el CRITERIO de qué preguntar/proponer y
cuándo alertar, no el cálculo legal en sí. Para que un abogado pueda
confiar en una propuesta de la IA sin revisarla a ciegas, siempre debe
explicar en una línea por qué está preguntando/alertando. Documentos
oficiales inequívocos → automático; algo ambiguo o texto libre (una
respuesta de WhatsApp) → confirmación de un tap antes de escribir nada
al expediente.

Resumen de lo agregado en la sesión del 6-8 nov 2026 ("sistema y bot
completo"):
- Asignación de expedientes a 2 abogados a la vez (`abogado_id_2`).
- Borrar expediente (`api/expedientes_delete.php`, solo Administrador).
- Jornada laboral / horas extra dinámicas en la demanda: el abogado
  captura horario de entrada/salida/comida/días en "Editar datos" y el
  Word calcula solo las horas extra y adecúa el texto (`calcularJornada()`,
  `mergeTags()` en app.js). Jornada máxima legal (Art. 61 LFT) configurable
  en Equipo (pensado para el cambio de ley de 2027).
- Dos escritos nuevos generables desde "Generar escrito" → "Otras
  plantillas de escritos" (hay que subir el .docx ahí, no viven en el
  repo, igual que la plantilla de demanda real):
  - **Rescisión Art. 51**: la causa se elige de una lista (fracciones I-IX
    del art. 51) y arma solo el hecho narrativo; prestaciones opcionales
    con checkboxes (letras se recalculan solas); reutiliza el horario/horas
    extra ya existente.
  - **Prima de Antigüedad (jubilación)**: estilo "Mir Castro" -- trámite
    interno con funcionario de RH/folio/oficio, pericial caligráfica
    completa, verificación de CFDI ante el SAT.
- Pendiente / sin hacer todavía: la plantilla real `data/plantilla_demanda.docx`
  en el servidor en vivo ya se actualizó con los tags de jornada (el
  usuario la subió), pero confirma con el usuario antes de asumir que algo
  de esta lista ya se usó en un caso real.

## Robots locales (Node + Playwright)
- Corren en la máquina de Windows del usuario (Ruben), **no** en esta sesión
  remota — esta sesión no tiene salida a internet hacia sitios externos
  (confirmado: sin acceso a sjf2.scjn.gob.mx ni similares) ni la llave real
  del robot.
- Carpeta local donde el usuario los guarda: `C:\Users\Ruben\Desktop\ROBOTS\`,
  una subcarpeta por robot (`JURISPRUDENCIA`, `FEDERAL`, `CDMX`,
  `edomex_correo` -- la carpeta vieja `EDOMEX`, del robot ya retirado, se
  borró el 8 nov 2026).
- Entrega de archivos nuevos/actualizados de un robot: `SendUserFile` de los
  `.js`/`.json`/`README.md` sueltos (no zip — falló por confusión de carpeta
  de descargas) para que los guarde directo en la subcarpeta del robot.
- La llave real (`ROBOT_API_KEY`) vive solo en `api/robot_credentials.php`
  del servidor (gitignored, no está en este repo) — la ve el usuario por
  cPanel. Es compartida entre todos los robots (Edomex, boletín CDMX,
  boletín Federal, jurisprudencia).
- **Los 4 robots ya están automatizados con el Programador de tareas de
  Windows (confirmado 8-9 nov 2026)**, cada uno con su propio
  `run_diario.bat`/`run_semanal.bat` (hace `cd` a su propia carpeta y
  corre el robot, guardando la salida en `robot.log`):
  - `Jurisprudencia Expertos Laborales` -- lunes 7:00 am
  - `Robot CDMX Expertos Laborales` -- diario 6:00 am
  - `Robot Federal Expertos Laborales` -- diario 6:40 am
  - `Robot Edomex Expertos Laborales` -- diario 9:30 am (el nombre de la
    tarea dice "Edomex" a secas por el nombre con el que se creó
    originalmente, pero corre `edomex_correo\run_diario.bat`, el robot
    nuevo -- quedó mal apuntada una temporada al robot viejo ya borrado,
    corregido borrando y recreando la tarea)
  - Para ver/tocar estas tareas: `schtasks /query /fo table | findstr
    "Expertos"` lista las 4; `schtasks /query /tn "<nombre>" /v /fo list`
    da el detalle de una. **Ojo con `schtasks /change`** -- si la tarea se
    creó con credenciales guardadas (para correr "tanto si el usuario
    inició sesión como si no"), pide la contraseña de Windows del usuario
    cada vez que se modifica con `/change`, y ni el usuario la tenía a la
    mano -- más simple borrar (`/delete /f`) y recrear con `/create`
    (sin `/ru`, corre solo cuando la sesión de Windows está iniciada, no
    pide contraseña, mismo patrón que ya usaba Jurisprudencia). **`schtasks`
    necesita PowerShell como administrador** (clic derecho → "Ejecutar
    como administrador") -- sin eso da "Acceso denegado" en vez de pedir
    permisos; y pega los comandos de uno en uno, nunca varios juntos,
    porque si alguno dispara una pregunta interactiva (contraseña, "¿ya
    existe, reemplazar S/N?"), las líneas siguientes del pegado se las
    come como respuesta y se hace bola.
- Primera corrida de cada robot: recordar `npm install` +
  `npx playwright install chromium` en su carpeta antes de correrlo.

## Robot nuevo: Buzón Electrónico Laboral CDMX (`robots/cdmx_buzon/`, 9-10 nov 2026)
Pedido del usuario después de notar "muchos asuntos... que evidentemente
tienen acuerdos en los tribunales federales y en el estado de méxico" que
el sistema no reportaba -- se construyó `api/reporte_monitoreo_faltante.php`
(script de diagnóstico, abrir directo en el navegador logueado como
Administrador) que reusa la MISMA lógica de reconocimiento de los robots
(`/FEDERAL/i`, `/ciudad de méxico|cdmx/i`, `/tlalnepantla/i`) para listar,
por categoría, qué expedientes activos quedan fuera y por qué. Confirmado
con datos reales del despacho: la causa dominante (35 de 37 casos) es
simplemente **Tribunal sin capturar** -- no un bug de reconocimiento. Sí se
encontraron 2 casos reales con el Tribunal capturado SIN la palabra
"Federal" (ej. "Noveno Tribunal Laboral de Asuntos Individuales con sede en
la Ciudad de México" en vez de "...Laboral **Federal** de Asuntos
Individuales..."), causando que el sistema los trate como CDMX local en vez
de Federal -- hay que volver a capturar su Tribunal desde el catálogo
(nunca a mano) para que diga "Federal". **Pendiente**: armar algo que
sugiera el Tribunal a partir del prefijo del número de expediente (`CDMX`,
`ECA`, `Tlalne1`, `MEXOAE`...) para los 35 casos sin nada capturado, una vez
que el usuario confirme si esos prefijos sí indican la jurisdicción de
forma consistente.

Mientras se investigaba esto, el usuario mandó la liga de un portal que no
se conocía antes: **Buzón Electrónico Laboral del PJCDMX**
(`buzonelectronicolaboral.poderjudicialcdmx.gob.mx:2083`) -- confirmado
real con capturas de pantalla en vivo: cada cuenta (correo + contraseña,
**una por abogado**, confirmado explícitamente por el usuario) ve en
"Consulta de expedientes" → "LISTA DE BUZONES" TODOS los expedientes donde
ese abogado está dado de alta como apoderado, con un botón "Ver Acuerdos"
por expediente que abre la lista completa (fecha, número de acuerdo tipo
"774/2025-3", liga "Ver acuerdo" con el PDF real) -- mucho más confiable
que el boletín público viejo, mismo nivel que el correo de Edomex o el
portal del PJF. Se construyó TODA la infraestructura, mismo patrón
exacto que ya existía para Edomex:
- Migración **`sql/migraciones/063_cdmx_buzon_credenciales.sql`**
  (confirmada corrida por el usuario el 10 nov 2026) -- agrega
  `cdmx_buzon_usuario`/`cdmx_buzon_password_enc`/
  `cdmx_buzon_actualizado_en` a `usuarios`.
- Endpoints `api/cdmx_buzon_credenciales_{set,get,listar}.php` (copia
  exacta del patrón de `edomex_correo_credenciales_*.php`, contraseña
  cifrada con `pjf_encrypt()`/`pjf_decrypt()`).
- Sección nueva en "Mi cuenta" (`cdmxBuzonCredencialesHTML()`,
  `app.js?v=125`) para que cada abogado guarde su correo/contraseña del
  Buzón.
- Robot nuevo **`robots/cdmx_buzon/cdmx_buzon.js`** -- login por cuenta,
  pagina "LISTA DE BUZONES" (paginación por iconos -- ver más abajo cómo
  se resolvió de verdad, costó varias vueltas), por cada expediente que
  haga match (comparando solo el último tramo `NNN/AAAA` del número de
  expediente -- NO hace falta que el Tribunal esté capturado, a
  diferencia de los otros 2 robots de CDMX/Edomex) abre "Ver Acuerdos" y
  descarga el PDF de cada acuerdo nuevo (`descargarPdfDesdeLink()`,
  escucha pestaña nueva O descarga directa -- confirmado real que
  funciona de punta a punta, sin apuntar cuál de las 2 rutas fue la que
  disparó en la corrida real). Sube todo por la MISMA infraestructura ya existente
  (`documentos_subir_robot.php`, `avisos_ingest.php` con
  `fuente:'cdmx_local'`) -- así que automáticamente hereda el resumen de
  IA (`cron_analizar_acuerdos_ia.php`), el push al abogado/Administrador,
  y el llenado automático de etapas, sin tocar nada de eso.
- **Complementa, NO reemplaza** al robot de boletín viejo
  (`robots/cdmx/cdmx.js`) -- pedido explícito del usuario ("el que ya
  tenemos se queda"), corren los dos en paralelo.
- **Primeras corridas reales (10 nov 2026)** -- 2 bugs reales encontrados
  y corregidos antes de llegar a ver un acuerdo de verdad:
  1. `expedientes_monitorear.php` excluía cualquier expediente sin
     Tribunal/Junta/amparo capturado -- ni siquiera le llegaba al robot
     intentar el match aunque tuviera `exp`. Corregido ampliando el WHERE.
  2. `page.goto()` directo a "Consulta de expedientes" después del login
     perdía la sesión de forma intermitente ("Error 102 Por favor inicie
     sesión", confirmado real con captura) -- la app parece guardar la
     sesión en memoria del navegador, y una navegación completa la
     reinicia. Corregido: ahora navega dándole clic al menú, y espera a
     que el número de filas de "LISTA DE BUZONES" se estabilice antes de
     leerla (había un error de JS real de la librería de la tabla al
     leerla demasiado pronto).
  - **Match ahora exige Tribunal + expediente, no solo expediente** --
    pedido explícito del usuario: el número de expediente solo es único
    DENTRO de cada tribunal, no en todo CDMX, así que dos tribunales
    distintos podrían compartir número por coincidencia.
    `extraerOrdinal()` saca el número ordinal del Tribunal de ambos lados
    (del Buzón y del capturado en el sistema, acepta dígito o palabra
    "Sexto"/"SEXTO") y exige que coincidan; si no, se omite y se explica
    por qué en el log (no se adivina ni se aplica un match dudoso). El
    usuario se encarga de que los expedientes tengan Tribunal y número
    bien capturados.
  - **Hueco real destapado de paso**: `TRIBUNALES_LOCALES_MX` no tenía
    NINGUNA entrada para "Ciudad de México" (sí tenía los tribunales
    "...Federal..." en `TRIBUNALES_FEDERALES_MX`, que es un sistema
    aparte) -- por eso el selector de Tribunal solo ofrecía "Otro" para
    los Tribunales Laborales LOCALES de la CDMX, lo que explica los 2
    casos mal capturados (sin la palabra "Federal") del reporte de ayer.
    Se agregó con el formato confirmado real por captura del Buzón ("4/6/
    7/8 LABORAL DE ASUNTOS INDIVIDUALES DE LA CDMX"); el 1/2/3/5/9 se
    completaron por la numeración secuencial oficial (9 Tribunales
    Laborales de Asuntos Individuales, organigrama TSJCDMX 2024) **sin
    captura real de esos 5** -- si alguno no coincide exacto con lo que
    se ve en el Buzón, avisar para corregirlo. `app.js?v=126`.
  - **Sesión activa bloqueando el login (10 nov 2026, encontrado y
    arreglado en vivo esa misma noche)**: tras varias corridas seguidas
    en poco tiempo, el login empezó a fallar siempre ("no se encontró el
    menú 'Consulta de expedientes'"). Confirmado con captura real: el
    portal muestra un aviso rojo "No puede ingresar por que cuenta con
    una sesion activa..." con un link **"Romper sesión"** -- aparece YA
    en la carga inicial de la página (depende de la cookie/sesión que
    quedó viva de la corrida anterior, NO de enviar el formulario de
    login), y cerrar el navegador del lado de Playwright no cierra esa
    sesión del lado del servidor. El robot ahora revisa esto justo al
    entrar a la página (antes de llenar usuario/contraseña), le da clic
    solo si aparece, recarga, confirma que ya no sigue bloqueado, y
    reintenta el login completo hasta 5 veces con pausas de por medio.
    Si el usuario ve este mismo aviso corriendo el robot varias veces
    seguidas en poco tiempo (normal en pruebas), es justo este caso --
    si el arreglo automático no alcanza (puede pasar si se acumulan
    varias sesiones "zombie" de muchas corridas de prueba seguidas),
    entrar manual con esa cuenta en un navegador normal, romper sesión
    ahí, y cerrar sesión bien (buscar un botón "Cerrar sesión"/"Salir")
    antes de volver a correr el robot.
  - **Paginación: confirmado con F12 que los iconos de paginación
    («/‹/›/») son enlaces `<a class="page-link">`, NO `<button>`** --
    con nombre de accesibilidad en INGLÉS aunque el resto de la pantalla
    esté en español ("Next" para ›, "Last" para »), por eso las
    versiones que subían por los contenedores padres buscando `button`
    siempre encontraban 0. Además hay **2 elementos** con ese mismo
    nombre de accesibilidad en el DOM (confirmado real) -- el primero
    visible no necesariamente es el que mueve la tabla (clic sin error,
    pero sin efecto real); el robot ahora prueba cada candidato visible
    en orden hasta que uno sí cambie el texto "Página X de Y".
  - **Confirmado funcionando de punta a punta por primera vez (10 nov
    2026, cuenta `maluli200499@hotmail.com`)**: con los 2 arreglos de
    arriba, una corrida real avanzó de la página 1 a la página 2 de
    "LISTA DE BUZONES", encontró ahí el expediente de prueba confirmado
    (**Zeltzin Monserrat Flores Cruz vs Institufacil, S.A. de C.V., exp.
    1139/2026, Tribunal "5 LABORAL DE ASUNTOS INDIVIDUALES DE LA CDMX"**),
    hizo match por Tribunal+expediente, y abrió "Ver Acuerdos" sin error
    -- esa primera corrida reportó 0 avisos porque el conteo de filas de
    acuerdos también se leía demasiado pronto (ver siguiente punto).
  - **El modal "LISTA DE ACUERDOS" tiene la misma carrera de carga que la
    lista principal**: el usuario confirmó con captura real que el
    expediente 1139/2026 SÍ tenía un acuerdo (1139/2026-1, 2026-05-20),
    pero el robot contaba los links "Ver acuerdo" antes de que la tabla
    terminara de cargar y veía 0. Se agregó la misma espera de
    estabilización que ya tenía la lista principal.
  - **Causa real de que el login fallara intermitentemente** (la pieza
    que más costó encontrar): "Romper sesión" dispara un `confirm()`
    nativo del navegador ("¿Esta seguro de romper la sesión?") y, al
    aceptarlo, un `alert()` de confirmación -- confirmado en vivo por el
    usuario con capturas reales de ambos cuadros. Playwright **cancela
    estos diálogos solos** si nadie los atiende (un `confirm()` sin
    manejador devuelve `false`), así que el robot "clicaba" el link sin
    error pero nunca rompía la sesión de verdad -- por eso parecía fallar
    al azar. Se agregó un listener `page.on('dialog', ...)` que los
    acepta automáticamente. Además, el aviso de "sesión activa" no
    aparece al instante tras cargar la página (la app revisa la sesión
    del lado del cliente y redirige a una URL con `?error=1001...`
    después) -- se agregó un margen fijo de 2.5s antes de revisarlo.
  - **Confirmado funcionando de punta a punta por primera vez de verdad
    (10 nov 2026)**: con todos los arreglos de arriba, una corrida real
    completa reportó **"1 aviso(s) nuevo(s)"** para el expediente
    1139/2026 -- el usuario confirmó en el sistema que el PDF real
    (`Acuerdo_1139-2026-1.pdf`, 646 KB, 8 páginas, "AUTO DE RADICACIÓN"
    del Quinto Tribunal Laboral de Asuntos Individuales de la CDMX) se
    subió correctamente a la pestaña Documentos del expediente. Con esto
    el mecanismo completo (login, romper sesión, paginación, match,
    lista de acuerdos, descarga, subida, aviso) queda confirmado real de
    punta a punta por primera vez.
  - **Programado en el Task Scheduler de Windows (10 nov 2026)**:
    `robots/cdmx_buzon/run_diario.bat` (mismo patrón que los otros 4
    robots) a las **6:00 am todos los días** -- hora pedida explícitamente
    por el usuario para que no choque con la abogada usando su propia
    cuenta del Buzón en horario de oficina (el portal solo permite una
    sesión activa por cuenta).

## Robot de boletín CDMX (`robots/cdmx/cdmx.js`)
- Bug real detectado y corregido (8 nov 2026): cuando el número de
  expediente del boletín llevaba un sufijo corto antes del punto final
  ("Incompetencia.", "Amparo Directo Demandado.", "-IV.", "-VI." -- se
  confirmó con boletines reales), el separador de casos no reconocía el
  final de ese caso y pegaba su texto con el/los siguiente(s), reportando
  avisos con el resumen de 2-3 casos distintos mezclados. Corregido en
  `extraerCasosDeTramo()` con `SUFIJO_EXP_RE`. Si vuelve a pasar algo
  similar (resumen de un aviso que no corresponde al expediente), pide el
  boletín real (captura de pantalla con zoom basta) para ver el patrón
  exacto antes de tocar el regex -- no se puede adivinar el formato.

## Robot Federal (`robots/federal/federal.js`)
- Reescrito por completo (8 nov 2026): el Portal del PJF quitó la pantalla
  vieja de "Acuerdos Recientes/Pendientes" que usaba (confirmado con una
  corrida real: login exitoso, 0 filas siempre). Ahora usa Playwright para
  entrar a "Consulta de Expediente Electrónico" → "Vista Global" y abrir
  cada expediente (pantalla moderna, carga por JavaScript). El login
  contra la API sigue funcionando igual que antes -- solo se reescribió
  la parte de leer acuerdos. Seguimiento por número de acuerdo ("No.") en
  `procesados_federal.json`.
- Construido a partir de capturas de pantalla del portal e iterado en vivo
  con el usuario (sin internet desde esta sesión) durante varias corridas
  reales el 8 nov 2026. Bugs reales ya corregidos en ese proceso:
  - Modal "Seleccione un perfil" (Persona Física / representante...) al
    entrar: se cierra dándole clic a "Persona Física"; había una condición
    de carrera con la animación "fade" de Bootstrap (a veces cerraba, a
    veces no, con el mismo código) -- se espera a que termine antes de
    hacer clic, y si el clic normal no cierra el modal se reintenta con
    clic forzado + se limpia a mano el `.modal-backdrop` que a veces queda
    vivo y bloquea el siguiente clic en la página aunque el modal ya no se
    vea.
  - El filtro de seguridad `pareceElMismoCaso()` (copiado de CDMX, exigía
    que el resumen mencionara a las partes) rechazaba TODOS los acuerdos
    siempre -- el resumen que arma este robot (No./fecha de acuerdo/fecha
    de publicación) nunca trae nombres de partes. Se quitó: aquí no hace
    falta, ya se entra al detalle exacto del expediente desde la cuenta
    autorizada y el número ya viene cotejado contra los expedientes
    monitoreados del sistema.
  - `maximizarEntradasPorPagina()` buscaba el select "Mostrar X entradas"
    por texto sin acotar a la tabla correcta y a veces agarraba el de otra
    tabla oculta de la página (ej. "tableOficialia"), quedándose 30s
    esperando en vano. Ahora se acota por `aria-controls` contra el id de
    la tabla que de verdad se está leyendo.
  - **Confirmado funcionando (8 nov 2026, commit `92a58e7`)**: corrida
    real limpia con las 2 cuentas guardadas (buerhend y maluli20) -- cerró
    el modal sin tronar en ninguna de las 2, no se quedó esperando en el
    "Mostrar entradas", y reportó los 4 acuerdos nuevos reales del
    expediente 114/2026 (No. 11-14) sin repetir los que ya tenía vistos
    (1-10). Si en una corrida futura algo truena, seguir pidiendo el
    error completo de la terminal + la captura `debug_error_*.png` o
    `debug_modal_*.png` -- no adivinar el selector sin eso. Falta
    programarlo para correr solo todos los días (Task Scheduler en la
    máquina del usuario, ver README.md de esta carpeta) -- por ahora solo
    se ha corrido a mano.
- **Amparos directos (8 nov 2026)**: el robot ahora también vigila los
  amparos directos/indirectos contra la sentencia laboral -- el Portal de
  Servicios en Línea tiene DOS entidades separadas detrás de la misma
  pantalla de login ("Tribunales Laborales" de siempre, y "Juzgados de
  Distrito y Tribunales de Circuito", donde se resuelven los amparos),
  cada una una sesión/cookie distinta que solo ve su propia "Vista
  Global" -- confirmado capturando la petición real de login del
  navegador con las herramientas de desarrollador: cambia
  `EntidadExterna` ("Laboral" vs "SinEntidad") y `tipo` ("3" vs "1"). Cada
  cuenta ahora se revisa dos veces (array `SESIONES`), reutilizando toda
  la lógica de Vista Global / tabla de acuerdos que ya existía (mismas
  columnas). **Confirmado funcionando de punta a punta (8 nov 2026)**:
  corrida real con el amparo 269/2025 de Claudia Ruiz (expediente
  242/2024, capturado en la pestaña "Amparo" del expediente) -- reportó 8
  acuerdos reales con sus PDF. El intermitente ya conocido del clic a
  "Consulta de Expediente Electrónico" (ver abajo) también se portó aquí
  (antes solo estaba en la versión de `control-de-expedientes`).
  - Los documentos de amparo se suben con categoría nueva
    `acuerdo_amparo` (antes cualquier acuerdo, de juicio o de amparo,
    caía en la misma categoría genérica `acuerdo` y los abogados se
    confundían viéndolos revueltos en la pestaña "Documentos"). Migración
    `sql/migraciones/058_categoria_documento_acuerdo_amparo.sql` --
    **confirmada corrida por el usuario**. OJO con un bug real que esto
    destapó: el usuario corrió esta migración primero en la base de
    Control de Expedientes por error (cuenta equivocada, confusión ya
    documentada varias veces en este archivo) y luego, al intentar el
    `UPDATE` de recategorización en la base correcta ANTES de correr el
    ALTER TABLE ahí, MySQL aceptó el UPDATE pero con warnings "Datos
    truncados para columna 'categoria'" -- guardó un valor de ENUM
    inválido en vez de fallar limpio, dejando esas filas con una
    categoría corrupta (ya no 'acuerdo', tampoco 'acuerdo_amparo'). Se
    corrigió repitiendo el UPDATE filtrando por `nombre_archivo LIKE
    '...'` en vez de por `categoria = 'acuerdo'` (que ya no las
    encontraba). Lección: si un UPDATE a una columna ENUM sale con ese
    warning, el ALTER TABLE que agrega el valor nuevo no se aplicó de
    verdad en esa base -- correrlo primero y confirmar sin warnings antes
    de repetir el UPDATE.
  - **Pestaña "Documentos" dividida en dos subpestañas (9 nov 2026)**:
    "Juicio principal" y "Amparo" (`DOCUMENTOS_SUBTAB`, botones
    `data-docsubtab`, clase CSS `doc-subtabs` -- a propósito NO
    `modal-tabs`, para que el click global que mueve `MODAL_TAB` no las
    agarre también sin querer). Pedido explícito del usuario pensando en
    que con el tiempo cada expediente va a acumular bastantes documentos
    de cada lado. El desplegable de categoría al subir un documento
    también se filtra según la subpestaña activa (en Amparo solo ofrece
    "Acuerdo (amparo)"). `app.js?v=115`.
  - Pendiente: portar lo mismo (dos sesiones por cuenta, amparos) a
    `control-de-expedientes/robots/federal-multidespacho/federal.js` --
    el usuario ya pidió que se haga en cuanto esto quedara confirmado
    aquí.
- **Panel viejo de "Captcha de Edomex pendiente" retirado del todo (9 nov
  2026)**: era de `robots/edomex/edomex.js`, el robot viejo con captcha
  ya reemplazado por `edomex_correo.js` el 8 nov -- el usuario lo vio
  aparecer en Agenda general con un captcha huérfano (de antes de que se
  retirara el robot) y preguntó si ya tocaba quitarlo. Se quitó de
  `app.js` (variable `EDOMEX_CAPTCHA_PENDIENTE`, el panel en Agenda
  general, el botón "Enviar" y su manejador, el +1 del badge de Agenda) y
  se borraron del repo los 4 endpoints que ya no llamaba nadie:
  `api/edomex_captcha_{pendiente,publicar,resolver,resultado}.php`.
  **Pendiente**: esos 4 archivos .php siguen en el servidor en vivo
  (subida manual por cPanel, no se borran solos) -- no hace daño
  dejarlos huérfanos ahí (nada les manda tráfico), pero si el usuario
  quiere limpiarlos del todo hay que decirle que los borre a mano desde
  el Administrador de archivos. La tabla `edomex_captcha_pendientes` en
  la base de datos tampoco se tocó (mismo razonamiento, inofensiva sin
  uso) -- no se le ofreció un DROP TABLE porque no se pidió. `app.js?v=116`.

## Avisos de boletín con documento adjunto (8 nov 2026)
- Pedido del usuario: que al reportarse un acuerdo/aviso nuevo, se pueda
  ver (o de plano se descargue) el documento sin tener que entrar a mano al
  portal de origen.
- **Migración `sql/migraciones/056_avisos_documento_robot.sql` ya aplicada
  por el usuario (8 nov 2026)** (agrega `documento_id` a `avisos_boletin` +
  categoría `'acuerdo'` en `expediente_documentos.categoria`, y de paso
  agrega `'constancia_no_conciliacion'` a ese mismo ENUM -- el código ya la
  esperaba como categoría válida pero nunca se había agregado en la base
  de datos).
- Diseño: se reutiliza el almacén de documentos que ya tiene cada
  expediente (pestaña "Documentos", `api/documentos_descargar.php`, mismo
  control de acceso de siempre) en vez de inventar uno nuevo. Nuevo
  endpoint `api/documentos_subir_robot.php` (protegido con la llave de
  robot) para que CUALQUIER robot pueda subir un archivo a un expediente.
  El aviso (`avisos_boletin.documento_id`) queda ligado a ese documento; el
  link "ver fuente" que ya existía en la tarjeta de aviso ahora abre ese
  documento directo (`documentos_descargar.php?id=X&inline=1`), y se
  agregó un botón nuevo "Borrar documento" junto a "Marcar revisado" /
  "Descartar" (en Agenda general y en la pestaña Boletín del expediente)
  para cuando el robot haya subido el PDF equivocado -- borra el documento
  y descarta el aviso. `app.js?v=112`.
- **Federal** (`federal.js`): cuando encuentra un acuerdo nuevo, antes de
  reportarlo intenta descargar su PDF con Playwright
  (`descargarPdfAcuerdo()`). **Confirmado funcionando de punta a punta (8
  nov 2026)**: PDFs reales de 500-635 KB subidos y abren bien desde "Ver"
  en la pestaña Documentos. Cómo quedó al final (después de muchas
  vueltas en vivo el mismo día, ver commits de esa fecha si hace falta el
  detalle de cada bug):
  - La celda "Documento Acuerdo" de la fila abre un modal ("Acuerdo",
    `id="DocumentosModal"`, `data-keyboard="false"`) que mete un
    `<iframe src=".../cjf-downloadfileweb/files?param1=...">`-- ese
    iframe NO es la URL directa del PDF, es el cascarón de una mini app
    Angular ("cjf-lista-archivos") que arma su propia tabla con un ícono
    `<i class="fas fa-download iconDescarga">` -- VIVEN DENTRO DEL
    DOCUMENTO PROPIO DEL IFRAME (hay que entrar con
    `iframe.elementHandle().contentFrame()`, buscarlos en la página de
    afuera nunca funciona).
  - `intentarCapturarPdf()` arma sus escuchas (descarga/respuesta
    PDF-u-octet-stream/pestaña nueva) ANTES del clic que abre el modal,
    no después -- la mini app del iframe actúa muy rápido.
  - `pdfEsValido()` exige que el contenido empiece con "%PDF" antes de
    subirlo (atrapó varias veces el cascarón HTML de ~1000 bytes que no
    era el archivo real).
  - De paso se corrigieron 2 bugs de fondo en `clickTextoVisible()`
    (usada en todo el robot, no solo aquí) que causaban que el clic al
    menú "Consulta de Expediente Electrónico" fallara de forma
    intermitente: (a) la espera de visibilidad por candidato estaba fija
    en 3000ms sin importar el timeout que se le pidiera, y (b)
    `candidatos.count()` era una sola foto del DOM que podía dar 0 si la
    página apenas estaba renderizando (ej. justo tras una recarga),
    haciendo que el ciclo nunca se ejecutara. Si este clic vuelve a
    fallar, ya no debería ser por esto.
  - Pendiente / sin confirmar todavía: el clic a "Ver Expediente
    Electrónico" en Vista Global tronó una vez con el expediente 242/2026
    específicamente (`locator.click: Timeout 30000ms exceeded`) -- no se
    volvió a reproducir, puede ser la misma familia de bug (conteo
    instantáneo) sin corregir ahí todavía. Si pasa seguido, aplicar el
    mismo arreglo de `clickTextoVisible()` a ese clic directo.
  - Limpieza pendiente: expediente 242/2026 puede tener documentos viejos
    de ~1000 bytes subidos ANTES de que existiera `pdfEsValido()` -- si
    siguen ahí, borrarlos a mano desde la pestaña Documentos (botón
    "Eliminar").
- **CDMX** (`cdmx.js`): no hay PDF que descargar ahí (el boletín ya se lee
  como texto), así que sube el texto del caso ya recortado como un `.txt`
  (`subirTextoBoletinAlSistema()`). **Todavía sin confirmar en una corrida
  real** -- más simple y de menor riesgo que lo de Federal, pero nadie lo
  ha corrido en vivo todavía; confirmar antes de asumir que funciona.

## Robot nuevo: correo de notificaciones Edomex (`robots/edomex_correo/`, 8 nov 2026)
- Descubrimiento del usuario: el Poder Judicial del Estado de México
  (Tlalnepantla) notifica los acuerdos por un correo institucional
  (mail.pjedomex.gob.mx, webmail Carbonio -- **sin acceso IMAP**, solo por
  la página con usuario y contraseña) con un correo "Notificacion
  Electronica Laboral" por cada acuerdo, YA estructurado: Destinatario/
  EXPEDIENTE/Juicio/Juzgado/Actor/Demandado + el texto de la notificación +
  una liga directa (con token JWT) a `frmGetDocument.php` para bajar el PDF
  -- sin necesitar sesión, el token es la autenticación.
- Esto reemplaza al robot viejo de boletín con captcha
  (`robots/edomex/edomex.js`) -- mucho más confiable: sin captcha, sin el
  filtro de seguridad por nombres (la notificación ya viene dirigida
  personalmente y con sello digital). **Retirado del repo el 8 nov 2026**
  (el usuario confirmó y ya borró también su carpeta local
  `ROBOTS\EDOMEX\`) una vez que `edomex_correo.js` quedó confirmado
  funcionando de punta a punta -- si en claude.ai/code aparece referenciado
  en commits viejos, ya no existe.
- Nueva infraestructura de credenciales (mismo patrón que PJF): columnas
  `edomex_correo_usuario`/`edomex_correo_password_enc`/
  `edomex_correo_actualizado_en` en `usuarios` (migración 057, ya
  aplicada), endpoints `api/edomex_correo_credenciales_{set,get,listar}.php`,
  sección nueva en "Mi cuenta" (`app.js?v=113`) para que cada abogado
  guarde su propio usuario/contraseña de ese correo.
- El robot (`edomex_correo.js`) reutiliza TODA la infraestructura de
  documentos construida hoy para Federal: `documentos_subir_robot.php`,
  categoría `'acuerdo'`, `documento_id` en el aviso.
- **Confirmado funcionando de punta a punta (8 nov 2026)**: corrida real
  con la cuenta 4455282@pjedomex.gob.mx -- login, lectura de la bandeja,
  apertura de un correo real, extracción de EXPEDIENTE/Actor/Demandado/
  Juzgado, match contra el expediente 479/2025 monitoreado, descarga y
  subida del PDF real (238.7 KB, verificado visualmente: "ACUERDO",
  Segundo Tribunal Laboral de Tlalnepantla, 3 páginas, abre bien desde
  "Ver" en la pestaña Documentos). Bugs reales encontrados y corregidos
  ese mismo día (si algo similar vuelve a fallar, empezar por ahí, no
  adivinar sin pedir el error completo de la terminal):
  - **La bandeja de Carbonio tarda en cargar la lista de correos** de
    forma intermitente (el resto de la página -- carpetas, "Inbox 100" --
    ya está listo, pero la lista de filas tarda más). Hasta 2 reloads
    fallaron seguidos con 45s de espera cada uno. Ahora
    `procesarCuenta()` reintenta hasta 3 veces (45s/60s/90s, con un
    `page.reload()` entre cada intento) antes de darse por vencido.
  - **Clic en la fila**: el texto del asunto es un `<span>` suelto, el
    clic real que abre el correo es en el contenedor de la fila completa
    -- se prueba el elemento y hasta 4 niveles de padres (`locator('..')`)
    hasta que uno sí cambia la URL a `.../conversation/-<id>`.
  - **Carrera al leer el contenido**: justo después del clic, el iframe
    del correo a veces no ha terminado de cargar -- `leerTextoCompleto()`
    ahora reintenta hasta 8 veces (700ms entre cada una) antes de darse
    por vencido, en vez de checar una sola vez (antes esto causaba que el
    primer correo, el más importante, se reportara como "no se encontró
    EXPEDIENTE" y se omitiera).
  - **El token de la liga del PDF venía envuelto entre comillas simples**
    en el texto del correo (`frmGetDocument.php?token='eyJ...A'`) -- el
    regex las incluía como si fueran parte del token, invalidándolo
    siempre (el servidor nunca regresaba el PDF). `extraerLigaDocumento()`
    ahora las quita.
  - **La liga no entrega el PDF directo -- entrega un `.zip`** (cabecera
    "PK", confirmado real con el expediente 479/2025: 253 KB,
    `content-type: application/zip`) con el/los documento(s) del acuerdo
    adentro. Se agregó la dependencia `adm-zip` (`npm install adm-zip`
    necesario en las instalaciones ya hechas del robot) y
    `extraerPdfDeZip()` abre el zip y sube el primer PDF real que
    encuentra adentro (valida la cabecera "%PDF" de cada entrada, no solo
    el nombre).
  - **El progreso se guarda por cada correo procesado**, no solo al final
    del lote -- si la página truena a medio lote (pasó varias veces:
    "Page crashed" / "Target page, context or browser has been closed"
    después de procesar varios correos seguidos), los avisos ya
    reportados en ese lote ya no se repiten/duplican en la siguiente
    corrida.
  - Pendiente: falta programarlo para correr solo todos los días (Task
    Scheduler en la máquina del usuario, igual que Federal) -- por ahora
    solo se ha corrido a mano.

## Robot de jurisprudencia (`robots/jurisprudencia/`)
- Scrapea sjf2.scjn.gob.mx (Semanario Judicial de la Federación), materia
  laboral, y manda tesis nuevas a `api/jurisprudencia_ingest.php`.
- `node jurisprudencia.js` (corridas normales) / `node jurisprudencia.js
  --completo` (fuerza recorrer todo el listado — usar cuando cambie el
  filtro de búsqueda, ej. al agregar épocas viejas).
- Tarea pendiente: depurar selectores en vivo (es muy probable que algo
  truene en la primera corrida real — pedir el error completo de la
  terminal si pasa).

## Plantillas de WhatsApp aprobadas por Meta
- Se mandan con `whatsapp_enviar_plantilla()` (api/whatsapp_helpers.php) --
  a diferencia de `whatsapp_enviar()`, SÍ llegan aunque ya se haya cerrado
  la ventana de 24h desde el último mensaje del cliente. Se administran en
  business.facebook.com/wa/manage/message-templates/ (ahí se ve el nombre
  técnico exacto que exige la API, el texto aprobado y el orden de las
  variables {{1}}, {{2}}...).
- `recordatorio_1_hora` — única integrada en el código hasta ahora (ver
  api/cron_recordatorio_asesoria.php). Avisa 1h antes de la llamada:
  "Hola {{1}}, tu asesoría con el Lic. Rubén Buerhend es en 1 hora, a las
  {{2}} — te va a llamar del número 55 7991 3025...". Params: nombre, hora.
- `sin_respuesta_llamada` — plantilla para llamada que no se pudo
  realizar. OJO: está categorizada como **Marketing** en Meta (no
  Utilidad/Servicio) -- eso la hace bastante más cara por mensaje que el
  resto de los envíos del sistema (~$0.73 MXN vs. ~$0.16 MXN de un
  mensaje de servicio normal, con el precio de oct-2026). Texto: "Hola
  {{1}}, tu asesoría legal agendada para hoy a las {{2}} no se pudo
  realizar porque no logramos comunicarnos contigo por teléfono. Vamos a
  intentar llamarte de nuevo en los próximos minutos a este mismo
  número." Params: nombre, hora. Todavía NO está integrada a ningún cron
  ni botón del panel -- se manda a mano con api/debug_probar_plantilla.php
  (`?telefono=...&plantilla=sin_respuesta_llamada&p1=NOMBRE&p2=HORA`).
  El costo real por categoría se revisa en Meta: Administrador de
  WhatsApp → Herramientas → Estadísticas → pestaña "Precio de los
  mensajes".

## Avisos de boletín: notificación push al Administrador y abogado(s) (9 nov 2026)
- Pedido del usuario: que al encontrar algo un robot, se avise al celular
  del Administrador y del/los abogado(s) asignados al expediente.
- Se usó push (no WhatsApp) -- ya existía infraestructura madura
  (`api/push_helpers.php`, usada para la agenda diaria de abogados y otras
  alertas internas) y funciona igual que WhatsApp en el celular (si tienen
  el botón "Activar notificaciones" de "Mi cuenta" prendido), sin tener
  que esperar la aprobación de una plantilla nueva en Meta ni agregar una
  columna de celular (no existe hoy en `usuarios`).
- `api/avisos_ingest.php`: después de insertar el aviso (y solo si no era
  duplicado), junta en un solo set para no repetir: todos los
  `usuarios.rol='administrador' AND activo=1`, más `abogado_id` y
  `abogado_id_2` del expediente si están asignados, y les manda push a
  cada uno (`push_enviar_a_usuario()`). Cubre los 3 robots (Federal, CDMX,
  edomex_correo) automáticamente porque todos pasan por este mismo
  endpoint -- no hizo falta tocar ningún robot.
- Sin probar en vivo todavía (nadie ha recibido un push real de un aviso
  nuevo) -- la próxima vez que un robot reporte algo real debería llegar
  solo. Si no llega, lo primero a revisar es que el usuario de verdad le
  haya dado "Activar notificaciones" en "Mi cuenta" desde ese celular.

## Costos de IA
- Bitácora de gasto (créditos, gasto del mes, costo por resultado del
  embudo de WhatsApp): https://claude.ai/code/artifact/fdbe25d4-5fba-41f8-a0d3-f389dfb8cb61
  — actualizarla cuando el usuario mande una captura nueva del dashboard de
  Anthropic Console.
