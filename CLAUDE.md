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

**Última migración aplicada por el usuario: `061_supervisor_preguntas.sql`, confirmada corrida en la base correcta (`c1361311_exp`) el 9 nov 2026.**

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
- **Sin probar en vivo todavía** esta ronda de cambios (ni la pestaña
  nueva, ni subir un adjunto al responder, ni el contexto ampliado de la
  pregunta) -- recién subido. Confirmar con el usuario: que la pestaña
  "Preguntas al abogado" se vea bien, que subir una foto al responder
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
   abogado con sus propios pendientes). `app.js?v=117`. **Sin probar en
   vivo todavía** (nadie ha visto llegar el push de un atraso real ni la
   escalación al admin) -- confirmar en cuanto el cron corra de nuevo
   con asuntos atorados reales.
2. **IA lee el contenido real de los acuerdos de boletín -- HECHO (9 nov
   2026), migración 060 pendiente de correr, y FALTA DAR DE ALTA EL CRON
   NUEVO.** Antes el resumen guardado era genérico ("Acuerdo No. X del
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
   - **Sin probar en vivo todavía** (nunca se ha corrido contra un PDF
     real desde que se escribió esto -- ni el formato exacto de
     respuesta de la IA, ni el `document` content block de la API de
     Anthropic con PDF real, se han confirmado funcionando aquí). Probar
     primero con uno de los 8 acuerdos de amparo reales ya subidos
     (expediente de prueba Emmanuel Rodriguez Ojeda, Exp. 269-2025) antes
     de asumir que funciona con cualquier PDF.
   - **Falta dar de alta el Cron Job en cPanel**: comando
     `php /ruta/completa/a/api/cron_analizar_acuerdos_ia.php`, frecuencia
     cada 15-30 minutos (igual patrón que los demás `cron_*.php`, ver
     sección "Robots locales" más abajo para cómo se programan estas
     tareas, aunque esta corre en el servidor, no en la máquina del
     usuario).
3. **Supervisor que pregunta al abogado por asuntos atorados -- HECHO (9
   nov 2026), migración 061 pendiente de correr, y FALTA DAR DE ALTA EL
   CRON NUEVO.** Pensado originalmente como "por WhatsApp" (ver el
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
   - **Sin probar en vivo todavía** -- nunca se ha corrido el cron
     nuevo, ni se ha confirmado que la IA redacte preguntas útiles con un
     caso real, ni que `ia_interpretar_respuesta_supervisor()` extraiga
     bien una fecha de una respuesta real en lenguaje natural. Probar
     primero con un expediente real que ya tenga un "posible atraso"
     visible en el Tablero.
   - **Falta dar de alta el Cron Job en cPanel**: comando
     `php /ruta/completa/a/api/cron_generar_preguntas_supervisor.php`,
     frecuencia una vez al día (puede ir junto con
     `cron_recordatorio_abogado.php`).
   - `app.js?v=121`.
4. **IA lee documentos/fotos que sube el abogado** (pendiente) -- mismo
   principio que el punto 2 pero con lo que el abogado sube a mano
   (conciliación, constancia de no conciliación, cédulas de
   notificación). Automática si es un documento oficial inequívoco (ej.
   constancia con fecha clara → dispara el cálculo de prescripción ya
   existente); con confirmación de un tap si es más ambiguo (ej. una
   fecha reagendada).

Principio de diseño acordado con el usuario para las piezas 2-4: los
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
