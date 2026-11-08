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
solo confiar en que el usuario lo recuerde. Antes de empezar trabajo
nuevo: `git pull` (puede haber commits de otra sesión). Al terminar:
commit/push + agrega aquí un resumen breve de qué cambiaste y qué
migración SQL es la más reciente que el usuario ya corrió (para que la
siguiente sesión sepa desde dónde continuar sin repetir ni pisar nada).

**Última migración aplicada por el usuario: `057_edomex_correo_credenciales.sql`.**

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
  una subcarpeta por robot (ej. `JURISPRUDENCIA`, `EDOMEX`).
- Entrega de archivos nuevos/actualizados de un robot: `SendUserFile` de los
  `.js`/`.json`/`README.md` sueltos (no zip — falló por confusión de carpeta
  de descargas) para que los guarde directo en la subcarpeta del robot.
- La llave real (`ROBOT_API_KEY`) vive solo en `api/robot_credentials.php`
  del servidor (gitignored, no está en este repo) — la ve el usuario por
  cPanel. Es compartida entre todos los robots (Edomex, boletín CDMX,
  boletín Federal, jurisprudencia).
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
  (`robots/edomex/edomex.js`, que sigue ahí sin tocarse por ahora) --
  mucho más confiable: sin captcha, sin el filtro de seguridad por nombres
  (la notificación ya viene dirigida personalmente y con sello digital).
- Nueva infraestructura de credenciales (mismo patrón que PJF): columnas
  `edomex_correo_usuario`/`edomex_correo_password_enc`/
  `edomex_correo_actualizado_en` en `usuarios` (migración 057, ya
  aplicada), endpoints `api/edomex_correo_credenciales_{set,get,listar}.php`,
  sección nueva en "Mi cuenta" (`app.js?v=113`) para que cada abogado
  guarde su propio usuario/contraseña de ese correo.
- El robot (`edomex_correo.js`) reutiliza TODA la infraestructura de
  documentos construida hoy para Federal: `documentos_subir_robot.php`,
  categoría `'acuerdo'`, `documento_id` en el aviso.
- **Sin probar en vivo todavía** -- construido viendo 3 capturas de
  pantalla (login, bandeja de entrada, contenido de un correo real), nunca
  ejecutado de verdad. Supuestos que pueden fallar y necesitar ajuste con
  la primera corrida real:
  - Selectores del login (se intenta por `input[type=text]`/`[type=password]`
    genéricos + el texto "INICIAR SESIÓN", no se conocen los ids/names reales).
  - Que el contenido del correo (con "EXPEDIENTE:", "Actor:", etc.) se
    pueda leer del `innerText()` de la página o de alguno de sus frames
    (`leerTextoCompleto()`) -- puede venir en un iframe con otra estructura.
  - Que la lista de correos esté ordenada del más nuevo al más viejo (se
    usa el id numérico de `.../conversation/-<id>` de la URL para no
    reprocesar, comparando contra el último visto en
    `procesados_edomex_correo.json`).
  - Que la liga `frmGetDocument.php?token=...` funcione con un
    `context.request.get()` simple (sin sesión) -- se valida con
    `pdfEsValido()` (debe empezar con "%PDF") antes de subirlo, igual que
    Federal.
  - Si algo truena, pedir el error completo de la terminal + la captura
    `debug_*.png` que se guarda junto al robot -- no adivinar el selector
    sin eso (mismo patrón de todo este día con Federal).

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

## Costos de IA
- Bitácora de gasto (créditos, gasto del mes, costo por resultado del
  embudo de WhatsApp): https://claude.ai/code/artifact/fdbe25d4-5fba-41f8-a0d3-f389dfb8cb61
  — actualizarla cuando el usuario mande una captura nueva del dashboard de
  Anthropic Console.
