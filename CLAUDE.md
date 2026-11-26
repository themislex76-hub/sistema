# Notas operativas — sistema (Expertos Laborales)

## 2 crons construidos hace semanas nunca se dieron de alta en Ferozo -- por eso "Actividad de la IA" se veía muerta (25 nov 2026, conversación "Bot")
Pregunta real del usuario viendo el panel "Actividad de la IA" sin nada reciente y el caso de
Yuliana (Exp. 388/2026, con actividad real del robot ese mismo día) sin reflejarse ahí: "porque no
hay actividad registrada de la ia". Se revisó en vivo con el usuario, viendo juntos el panel de
Ferozo (`ferozo.host/#/tools/scheduledtasks`) -- el usuario pegó la lista COMPLETA de las 13 tareas
programadas que de verdad existían en el servidor en vivo.
- **Causa real confirmada, no un bug de código**: `cron_leer_google_calendar.php` (construido y
  probado el 11-nov-2026) y `cron_qa_irregularidades.php` (construido y probado el 12-nov-2026) --
  ambos documentados en este mismo archivo como "ya corridos/dados de alta" -- en realidad **nunca
  se habían agregado de verdad** al Cron Jobs de Ferozo. Quedaron solo como pruebas manuales
  (abrir la URL directo) el día que se construyeron, y nadie volvió a programarlos después.
  Explica tanto la falta de actividad reciente de Google Calendar como por qué nunca se vio una
  irregularidad nueva detectada por la QA semanal.
- **Corregido en vivo, uno por uno en la UI de Ferozo (25 nov 2026)**: se agregaron las 3 tareas
  que faltaban:
  - `cron_leer_google_calendar.php` -- 2 corridas diarias, 7:00am y 3:00pm (`0 7 * * *` y
    `0 15 * * *`).
  - `cron_qa_irregularidades.php` -- semanal, lunes 9:00am (`0 9 * * 1`).
- **Lección para la próxima vez que un cron "parezca no hacer nada"**: antes de sospechar un bug
  de código, confirmar con el usuario que la tarea de verdad está en la lista viva de Ferozo
  (pedirle que pegue la lista completa, como se hizo aquí) -- "lo construí y lo probé a mano" no
  es lo mismo que "está dado de alta para correr solo".
- **Pendiente**: confirmar en unos días que el panel "Actividad de la IA" vuelva a mostrar
  entradas nuevas de Google Calendar, y que el panel de "Posibles irregularidades" (dentro de
  "Preguntas al abogado") muestre algo tras la primera corrida real del lunes. También queda
  pendiente que el usuario confirme si `cron_analizar_acuerdos_ia.php`,
  `cron_analizar_documentos_ia.php` y `cron_auditoria_expediente_ia.php` (los 3 que SÍ estaban en
  la lista desde antes) corren sin error silencioso -- se le pidió abrir
  `api/cron_analizar_acuerdos_ia.php` directo en el navegador para revisar, sin confirmación
  todavía.

## Argumento de venta: la "propuesta de 45 días" del Centro de Conciliación + se quita "25 años de experiencia" del bot (25 nov 2026, conversación "Bot")
Pedido explícito del usuario, experiencia profesional directa (25 años litigando, lo dice en sus
lives): "en el centro de conciliación normalmente no se apoya a los trabajadores... te bajan de
90 a 45 días tu indemnización constitucional, te dicen que la prima de antigüedad no te toca
porque no tienes 15 años de servicios, el conciliador no te va a defender". Se investigó primero
(ver fuentes en el historial de esta conversación): la "propuesta de 45 días" SÍ es un mecanismo
real y documentado -- el propio portal del Centro de Conciliación de la CDMX confirma que esa
propuesta es la MITAD de la indemnización constitucional + mitad (o 100% con 15+ años) de la
prima de antigüedad + 100% de prestaciones devengadas, nunca el monto completo. El dato del
requisito de 15 años también es correcto como corrección legal: la prima de antigüedad SIEMPRE
se paga desde el primer año en un DESPIDO (Art. 162 LFT) -- el requisito de 15 años del Art. 162
solo aplica a la RENUNCIA voluntaria, nunca al despido, así que decirle a alguien despedido que
"no le toca por los años" es un error real, no una opinión.
- **Nueva REGLA DURA en el prompt** (reemplaza la que ya existía sobre "no ir solo a
  conciliación", que decía "el conciliador es neutral" -- corregido con el criterio directo del
  usuario: "no es neutral de verdad, parecería que ayuda más al patrón"): se dispara SIEMPRE que
  se calcule un estimado de DESPIDO (no en renuncia/Rescisión Art. 51/Prima de Antigüedad por
  jubilación), justo después de dar el número, en lenguaje muy sencillo (le habla a un
  trabajador, no a un abogado). Reglas de diseño explícitas del usuario: nunca mencionar
  salarios caídos ahí (no inflar expectativas); el cierre recomienda ir acompañado de un
  abogado particular, o al menos ir asesorado si se hace solo; se menciona UNA sola vez por
  conversación, no en cada recálculo.
- **Varias rondas de pulido del texto con el usuario** (tono coloquial, "para que lo entienda
  un trabajador", oraciones cortas) antes de llegar a la versión final -- ver el prompt mismo
  para el texto exacto.
- **Se quitó "25 años de experiencia"/"reconocido en el ámbito laboral" del bot PARA SIEMPRE**
  (decisión explícita del usuario, revirtiendo lo agregado el 24-nov-2026 el mismo día): "a la
  gente no le gusta que le vendan" -- sonaba a que se estaba vendiendo justo después de un
  párrafo serio de advertencia. La REGLA DURA de "a quién nombrar" se queda igual en todo lo
  demás (siempre nombrar "el Lic. Rubén Buerhend", nunca "un abogado" genérico) -- solo se quitó
  la mención de años/reconocimiento. La plantilla de WhatsApp del 3er seguimiento
  (`cron_seguimiento_calculadora_plantilla.php`) ya no la mencionaba desde el ajuste de "hazlo
  más natural" del 24-nov, así que no necesitó cambio.
- 122 casos en `scripts/pruebas_bot.php` (5 nuevos), `php -l` limpio. Sin cambios de base de
  datos, sin tocar `assets/app.js` -- no aplica cache-bust (no es lógica de interfaz, solo el
  prompt del bot de WhatsApp). **Sin probar en vivo todavía** -- falta que el usuario suba
  `api/ia_helpers.php` y se confirme con un caso real (alguien que pida un cálculo por despido)
  que el párrafo nuevo aparece bien y que ya no se menciona "25 años" en ningún lado.

## Mismo arreglo de reintentos llevado a Buzón CDMX y Correo Edomex (24 nov 2026, conversación "Bot")
Pedido explícito del usuario tras ver el arreglo de Federal: "¿crees que debamos hacer lo mismo
con los otros robots?". Revisados los 4 restantes:
- **CDMX (boletín)** y **Jurisprudencia**: ninguno descarga PDF (el primero sube el texto del
  boletín tal cual, el segundo lee tesis como texto) -- no aplica, sin cambios.
- **Buzón CDMX** (`robots/cdmx_buzon/cdmx_buzon.js`) y **Correo Edomex**
  (`robots/edomex_correo/edomex_correo.js`): sí descargan PDF con un solo intento y sin ninguna
  recuperación en corridas futuras si fallaba -- mismo hueco que tenía Federal. Mismas 2 piezas en
  ambos (la 3ª, `avisos_ingest.php` adjuntando el documento si llega después, ya quedó lista para
  los 3 robots a la vez desde el arreglo de Federal, porque no depende de cuál robot sea):
  1. **5 intentos por PDF** -- `descargarPdfConReintentos()` en Buzón CDMX (envuelve
     `descargarPdfDesdeLink()`), `obtenerPdfDeLigaConReintentos()` en Correo Edomex (envuelve el
     `context.request.get()` de la liga del correo, incluyendo el caso de que venga en un `.zip`).
  2. **Lista de "pendientes de PDF"** que se reintenta sola en corridas futuras, sin límite de
     tiempo -- en ambos, `estado[cuenta]` pasó de un formato plano (`{acuerdoId: true}` en Buzón
     CDMX, un arreglo de ids de correo en Edomex) a `{vistos, pendientesPdf}` (compatible con el
     formato viejo). En Correo Edomex, como el correo sigue en la bandeja, no hace falta guardar
     nada más que el id de la conversación -- reabrirla en una corrida futura vuelve a dar el
     mismo texto/liga de siempre, así que todo se deriva fresco en el momento.
- **A propósito NO se agregó un candado de "PDF idéntico entre 2 acuerdos"** -- se consideró
  (por un caso real del Exp. 388/2026 donde 2 acuerdos distintos pesaban igual) pero el usuario
  aclaró que el Tribunal sí puede publicar el mismo acuerdo dos veces de verdad (duplicado
  administrativo del boletín) -- un candado así habría rechazado por error un caso legítimo, así
  que se descartó antes de dejarlo puesto.
- **2ª corrida diaria, SOLO en Correo Edomex** (pedido explícito del usuario, confirmado "sí, otra
  hora del día"): el correo no tiene restricción de sesión única, a diferencia del Buzón CDMX
  (que SÍ solo permite una sesión activa por cuenta -- agregar una 2ª corrida ahí podría chocar
  con la abogada usando su cuenta en horario de oficina, por eso se dejó fuera a propósito, con
  la lista de pendientes bastando para recuperarse sola al día siguiente). Pendiente del usuario,
  mismo patrón que Federal (PowerShell como administrador, un comando a la vez):
  ```
  schtasks /create /tn "Robot Edomex Expertos Laborales 2" /tr "C:\Users\Ruben\Desktop\ROBOTS\edomex_correo\run_diario.bat" /sc daily /st 15:00
  ```
  (ajustar la ruta si la carpeta real es distinta -- esta tarea existente se llama "Edomex" a
  secas por una confusión de nombre ya documentada abajo, pero corre `edomex_correo\run_diario.bat`
  desde hace tiempo).
- `node --check` limpio en los 2 archivos. Sin cambios de base de datos, sin tocar `assets/app.js`
  -- no aplica cache-bust ni `verificar_tags.js`/`pruebas_bot.php` (son robots de Node, no el bot
  de WhatsApp). **Sin probar en vivo todavía** -- falta que el usuario reemplace los 2 archivos
  (en `.zip`, ver "Flujo de despliegue" más abajo) y confirme con una corrida real que, si algún
  PDF falla, aparece "AVISO: se agotaron los 5 intentos..." y que una corrida posterior sí lo
  recupera (buscar "PDF recuperado en esta corrida...").

## Robot Federal: 5 reintentos por PDF + recupera solo lo que falló antes, sin esperar a que el abogado lo suba a mano (24 nov 2026, conversación "Bot")
Pedido explícito del usuario, mismo caso del Exp. 388/2026 de arriba: "sería bueno que el robot
haga más intentos para descargar los documentos y no trasladarle la responsabilidad al abogado"
+ "sería bueno que los robots no corran solo una vez sino que corran dos veces al día y que si en
la primera corrida identificaron acuerdos pero no pudieron bajar los pdf en la segunda corrida sí
se pueda [reintentar]".
- **Por qué correr 2 veces al día NO bastaba por sí solo**: `procesados_federal.json` guardaba
  solo el número de acuerdo más alto ya "visto" por expediente (`estado[key] = maxNo`) -- ese
  número avanzaba aunque el PDF fallara, así que en CUALQUIER corrida futura (esa misma tarde o
  días después) el acuerdo ya quedaba "visto" y el robot nunca lo volvía a mirar. Y aunque lo
  hubiera vuelto a intentar, `avisos_ingest.php` trataba cualquier aviso con el mismo resumen como
  duplicado y no hacía nada -- ni adjuntaba el documento si llegaba después. 3 piezas, confirmadas
  con el usuario antes de construirlas:
  1. **5 intentos por PDF, no 1** (`robots/federal/federal.js`): nueva función
     `descargarPdfAcuerdoConReintentos()` envuelve `descargarPdfAcuerdo()` en hasta 5 intentos
     completos, cerrando cualquier modal atorado entre cada uno y con espera creciente (2s, 4s,
     6s...) por si el portal necesita "respirar". A propósito NO recarga la página completa del
     expediente entre intentos (eso obligaría a volver a localizar la fila/celda desde cero y
     arriesgaría más de lo que resuelve) -- si el problema es de la página completa, el acuerdo
     queda pendiente para la siguiente corrida (punto 2).
  2. **Lista de "pendientes de PDF" que se reintenta en corridas futuras, SIN límite de tiempo**:
     `estado[key]` en `procesados_federal.json` pasó de ser un número suelto a un objeto
     `{maxNo, pendientesPdf}` (compatible con el formato viejo -- un número suelto se interpreta
     como `maxNo` con `pendientesPdf` vacío). `procesarExpediente()` ahora, ANTES de procesar
     acuerdos nuevos, reintenta cada pendiente contra la misma tabla ya leída en esa corrida; si
     por fin consigue el PDF, llama `reportarAviso()` con el mismo resumen de siempre + el
     documento nuevo. Costo de revisar un pendiente en cada corrida es bajo (segundos) -- nunca
     se cierra la puerta a que se recupere solo, por más días que pase.
  3. **`api/avisos_ingest.php` ya no descarta el documento si llega después**: si el aviso ya
     existe (mismo expediente+fuente+resumen, candado de duplicados de siempre) y sigue sin
     documento, y esta llamada sí trae uno, se le adjunta (`UPDATE avisos_boletin SET
     documento_id`) y se bumpea `actualizado_en` -- antes esto se perdía igual, aunque el robot sí
     hubiera logrado el PDF en una corrida posterior. Con esto adjuntado, `cron_analizar_acuerdos_
     ia.php` ya lo toma normal en su siguiente corrida (lee `documento_id IS NOT NULL`).
- **El aviso en rojo de la sección de arriba (`avisoIaHTML()`) se queda igual, a propósito** --
  pedido explícito del usuario ("que al final ponga el aviso en rojo de que no se pudo
  descargar"): es la red de seguridad para cuando el robot agote TODOS sus reintentos, en TODAS
  las corridas, y de verdad no pueda (ej. el portal cayó por completo esa semana) -- sin eso,
  volveríamos al problema original de algo perdido en silencio.
- **Pendiente del usuario (acción manual, no de código)**: dar de alta una 2a corrida diaria del
  robot Federal en el Programador de tareas de Windows (hoy solo corre a las 6:40am) -- mismo
  `run_diario.bat` de siempre, otra hora del día (ej. 1:00pm), como PowerShell administrador:
  ```
  schtasks /create /tn "Robot Federal Expertos Laborales 2" /tr "C:\Users\Ruben\Desktop\ROBOTS\FEDERAL\run_diario.bat" /sc daily /st 13:00
  ```
  Ajustar la ruta si la carpeta real es distinta, y la hora si se prefiere otra. Con las 3 piezas
  de arriba, esta 2a corrida (o cualquier corrida de cualquier día futuro) sí recupera lo que la
  primera no pudo, en vez de ser una repetición sin efecto.
- `node --check` limpio en `federal.js`, `php -l` limpio en `avisos_ingest.php`. Sin cambios de
  base de datos (ni de `assets/app.js` -- no aplica cache-bust ni `verificar_tags.js`/
  `pruebas_bot.php`, es un robot de Node + un endpoint PHP que no toca el bot de WhatsApp).
  **Sin probar en vivo todavía** -- falta que el usuario suba `api/avisos_ingest.php`, reemplace
  `federal.js`, dé de alta la 2a corrida diaria, y confirme con el Exp. 388/2026 (Acuerdo No. 7 y
  8, siguen sin documento) que una corrida futura del robot los recupera solo -- si para entonces
  ya los subió a mano, forzar la prueba con otro expediente real que falle, o pedir el log
  completo de la siguiente corrida real buscando "PDF recuperado en esta corrida...".

## Avisos sin documento adjunto quedaban invisibles -- "REVISADO" no significaba "resuelto" (24 nov 2026, conversación "Bot", `app.js?v=163`)
Reportado por el usuario viendo el expediente **Escalona Jimenez Yuliana Yadira vs New Dynacast
de México, S.A. de C.V. y Automotive Quality Shield, S.A. de C.V., Exp. 388/2026**: "el bot
encontro acuerdos de ayer y creo que del 18 de noviembre los señalo para que los revisara y los
revise pero no adjunto el documento ni actualizo el sistema ni agendo a consecuencia". Capturas
reales confirmaron: Acuerdo No. 7 y No. 8 (ambos del Tribunal Federal, 19/11/2026, encontrados
24/11/2026) con badge **REVISADO** en el Boletín -- pero la pestaña Documentos solo tiene 5
archivos, el más reciente "Acuerdo_No_5...", sin ningún PDF para el 7 ni el 8.
- **Causa real, confirmada leyendo el código (no la base de datos -- esta sesión no tiene acceso
  en vivo)**: "REVISADO" es un estado que solo pone un HUMANO con el botón "Marcar revisado"
  (`api/avisos_mark.php`) -- nunca lo pone la IA sola. El robot Federal sí reportó el aviso (vía
  `api/avisos_ingest.php`, que siempre inserta el registro aunque no traiga PDF -- "no se pierde
  el aviso, solo el PDF", comportamiento ya documentado/aceptado desde el 8 nov 2026), pero sin
  lograr adjuntar el PDF real (`documento_id` queda `NULL`) -- mismo patrón de fondo, ya repetido
  varias veces en este archivo, de fallas de descarga de PDF del Portal Federal
  (`net::ERR_ABORTED`, spinner que nunca resuelve, etc.). Como `cron_analizar_acuerdos_ia.php`
  solo toma avisos con `documento_id IS NOT NULL`, un aviso sin documento **nunca se analiza, nunca
  llena ninguna etapa, nunca agenda nada** -- se queda huérfano para siempre.
  - **El hueco real que dejó esto invisible**: `avisoIaHTML()` (`assets/app.js`) regresaba
    `''` (nada) cuando no había `ia_resumen` todavía -- la tarjeta de un aviso SIN documento se
    veía EXACTAMENTE igual que una recién creada esperando su análisis normal (que tarda minutos).
    Un abogado le daba "Marcar revisado" sin ninguna señal de que el robot nunca pudo adjuntar el
    documento real -- por eso "REVISADO" se veía, pero no se había hecho nada de fondo.
- **Corregido**: `avisoIaHTML()` ahora distingue este caso -- si el aviso es automático
  (`origen==='automatico'`, viene de un robot) y nunca tuvo documento (`documento_id` nulo), se
  muestra un aviso en rojo: "⚠ El robot no pudo adjuntar el documento de este acuerdo -- consíguelo
  a mano en el portal de origen y súbelo en 'Documentos' (categoría 'Acuerdo (boletín)') para que
  la IA lo lea y actualice el expediente." -- en vez de quedar en silencio. Esta función la
  comparten los 2 lugares donde se ve un aviso (Agenda general y la pestaña Boletín del
  expediente), así que el arreglo cubre ambos sin tocar nada más.
- **Camino de recuperación ya existente, sin necesidad de código nuevo**: `cron_analizar_
  documentos_ia.php` lee con IA CUALQUIER documento subido a mano en "Documentos" (no exige que
  venga ligado a un aviso de boletín) -- si el abogado consigue el PDF real del Acuerdo No. 7/8
  en el Portal Federal y lo sube ahí (categoría "Acuerdo (boletín)"), el sistema SÍ lo analiza y
  puede llenar una etapa sola, igual que si lo hubiera subido el robot.
- **Pendiente real, sin poder diagnosticar más desde esta sesión** (sin acceso al log del robot,
  que corre en la máquina Windows del usuario): por qué `federal.js` no pudo descargar el PDF de
  estos 2 acuerdos específicos -- si se repite, pedir el log completo de esa corrida (buscar
  "no se pudo descargar el PDF"/"ERR_ABORTED") antes de tocar el robot a ciegas.
  **Acción inmediata del usuario para este caso concreto**: conseguir a mano los PDF de Acuerdo
  No. 7 y No. 8 del expediente 388/2026 en el Portal Federal y subirlos en "Documentos".
- Sin cambios de base de datos, sin tocar `mergeTags()`/tags de demanda -- no aplica
  `verificar_tags.js`/`pruebas_bot.php` (no es lógica del bot de WhatsApp). `node --check` limpio
  en `assets/app.js`/`sw.js`. `app.js?v=163`, `sw.js v87`. **Sin probar en vivo todavía** -- falta
  que el usuario suba `assets/app.js`, `index.html` y `sw.js`, y confirme que la advertencia
  nueva aparece en los Acuerdos No. 7/8 de ese expediente.

## Prueba social real (25 años + reconocido en el ámbito) + plantilla de 3er seguimiento (24 nov 2026, conversación "Bot", migración 075)
Respuesta del usuario a la auditoría de neuromarketing de arriba: "tengo 25 años litigando... además
soy un abogado reconocido en el ámbito" + "vale la pena hacer una plantilla de seguimiento".
- **Prueba social agregada al prompt** (dato real, no inventado): al ofrecer la asesoría la
  PRIMERA vez en una conversación, el bot ahora menciona "Lic. Rubén Buerhend, abogado con 25
  años de experiencia en derecho laboral" y que es reconocido en el ámbito -- solo esa primera
  vez, nunca se repite después aunque se vuelva a mencionar la asesoría (para no sonar a
  discurso memorizado).
- **Migración 075**: `calculos_liquidacion.tercer_seguimiento_en` -- nueva columna para el
  tercer y último recordatorio.
- **Nuevo `api/cron_seguimiento_calculadora_plantilla.php`** (correr 1 vez al día): a quien
  calculó su liquidación hace 1-7 días y sigue sin agendar/declinar, le manda UN último
  recordatorio por PLANTILLA aprobada de Meta (las plantillas sí llegan fuera de la ventana de
  24h de WhatsApp, a diferencia de un mensaje normal) -- mismos candados de siempre (nunca si ya
  lo tomó un humano, ya pagó/agendó, ya declinó, o el bot ya dijo que no se le puede atender).
  Esto cierra el hueco real encontrado hoy: antes, pasadas las 24h, NADIE volvía a contactar a
  esos leads nunca.
- **✅ Plantilla APROBADA por Meta con el nombre técnico `despues_de_24_h` (confirmado 25 nov
  2026, captura real: "Activa: calidad pendiente", Marketing, Spanish (MEX), identificador
  4913108052309567)** -- "calidad pendiente" solo significa que Meta aún no acumula suficientes
  envíos para calificarla, no bloquea el uso. **OJO: el nombre NO quedó como se había propuesto
  originalmente** (`seguimiento_calculo_laboral`) -- quedó como `despues_de_24_h`, probablemente
  por cómo Meta/el usuario lo capturó al crear la plantilla en su panel. `api/cron_seguimiento_
  calculadora_plantilla.php` ya se actualizó con el nombre correcto en la constante
  `PLANTILLA_SEGUIMIENTO_CALCULO`. Costo: categoría Marketing (no Utilidad -- mismo costo más
  alto que `sin_respuesta_llamada`, ~$0.73 MXN/mensaje en vez de ~$0.16). Texto final, tras una
  segunda ronda de ajuste de tono el 25-nov (el usuario no quiso "contéstame este mensaje" --
  "no lo siento amable" -- se cambió por algo más cálido, sin sonar a instrucción):
  > "Hola, ¿cómo estás? Quería darte seguimiento a tu caso laboral — según lo que platicamos,
  > tu estimado ronda los ${{1}}. Si en algún momento quieres que el Lic. Rubén Buerhend lo
  > revise contigo a fondo por teléfono, nada más escríbeme y con gusto te paso los horarios
  > que tiene disponibles."

  **Migración 075 (`tercer_seguimiento_en`) confirmada corrida en `c1361311_exp` el 25 nov
  2026** -- faltaba (el error `#1054` del mismo día lo confirmó en vivo antes de correrla).
  134 casos en `scripts/pruebas_bot.php`, `php -l` limpio. **Bug real encontrado y corregido de
  paso (25 nov 2026)**: el historial de conversación (`whatsapp_conversaciones`) seguía
  guardando el texto VIEJO ("Sigo al pendiente de tu caso... contéstame este mensaje") en vez
  del texto real que Meta manda con la plantilla aprobada -- corregido para que coincida exacto,
  y se agregó un prefijo nuevo a `ia_es_mensaje_automatico()` para que el revisor diario del bot
  lo siga tratando como mensaje de sistema (mismo patrón que los 2 recordatorios normales de
  `cron_seguimiento_calculadora.php`).
  **✅ Cron Job dado de alta en Ferozo (25 nov 2026)** -- 1 vez al día, 11:00am, comando
  `php /home/c1361311/public_html/sistema/api/cron_seguimiento_calculadora_plantilla.php`.
  Los 2 archivos (`api/ia_helpers.php`, `api/cron_seguimiento_calculadora_plantilla.php`) ya
  subidos. **Sin confirmar en vivo todavía** -- falta ver la primera corrida real (forzable
  abriendo la URL directo en el navegador logueado, sin esperar a las 11am) y confirmar que
  encuentra candidatos y manda al menos un mensaje sin error.
  **Estimado de costo mensual (25 nov 2026, con datos reales)**: 947 números únicos calcularon su
  liquidación en los últimos 30 días, 35 ya pagaron asesoría -- techo de 912 números/mes
  candidatos al 3er recordatorio, ~$666 MXN/mes (probablemente algo menos, $500-650, una vez
  descontados declinados/pausados) -- bajo comparado con el gasto total de IA del despacho
  (~$7,000-8,000 MXN/mes).
- **Expectativa honesta sobre si esto "cierra ventas"**: es una extensión razonable de un
  mecanismo que YA funciona (los 2 recordatorios dentro de 24h ya convierten) hacia gente que
  antes se perdía por completo -- debería ayudar, pero no hay forma de prometer cuánto sin
  verlo funcionar con datos reales. Medir comparando asesorías pagadas de quienes SÍ recibieron
  este 3er recordatorio contra quienes no, una vez que lleve 1-2 semanas corriendo.
- 117 casos en `scripts/pruebas_bot.php`, `php -l` limpio en los 2 archivos. Sin tocar `app.js`.
  **Sin probar en vivo todavía** -- falta: correr la migración 075, someter la plantilla a Meta,
  subir `api/ia_helpers.php` y `api/cron_seguimiento_calculadora_plantilla.php`, y dar de alta
  el Cron Job SOLO después de que la plantilla esté aprobada.

## Auditoría de neuromarketing del bot + cierre reforzado con riesgo real (24 nov 2026, conversación "Bot")
Pedido explícito del usuario tras el arreglo de la caída de ventas: "todo esto es para vender,
revisa qué más podemos hacer y cómo andamos con el tema del neuromarketing".
- **Ya estaba bien usado en el prompt** (confirmado revisando `api/ia_helpers.php` completo):
  anclaje de precio (el cierre ya compara los $499 contra el monto real calculado), autoridad
  (nombrar siempre "Lic. Rubén Buerhend" en vez de "un abogado", decisión ya tomada porque la
  gente llega de su TikTok buscándolo a él), reciprocidad (el cálculo siempre es gratis, la
  asesoría se ofrece DESPUÉS de dar valor real), urgencia genuina (plazos legales reales, nunca
  inventados), y una REGLA DURA explícita que PROHÍBE escasez/urgencia falsa ("quedan pocos
  lugares", "el precio sube pronto") -- el despacho ya tenía un criterio ético correcto de
  fondo, no hacía falta corregir nada ahí.
- **Hueco real encontrado**: el cierre de la asesoría nunca mencionaba ningún riesgo concreto de
  NO tenerla -- solo "¿te interesa?" genérico. **Corregido**: nueva guía en el prompt -- al
  cerrar, mencionar de pasada el riesgo REAL derivado de lo que la persona ya contó (plazo
  corriendo, audiencia próxima sin preparación, cálculo con varias prestaciones donde un error
  cuesta dinero real) -- nunca un riesgo inventado ni genérico, y si no hay ninguno real que
  mencionar, no se fuerza nada.
- **Cero prueba social en todo el prompt** -- ningún años de experiencia, número de casos
  atendidos, etc. **No se agregó nada** porque no hay ninguna cifra real confirmada para usar
  (inventar un número sería mentir, y ya existe la regla dura de nunca prometer con cifras
  infladas). **Pendiente de decisión del usuario**: si quiere dar una cifra real (años
  litigando, número aproximado de casos atendidos, o algo similar) para agregarla al cierre como
  prueba social verificable.
- **Hueco real sin resolver, más grande, pendiente de decisión**: el seguimiento automático de
  ventas (`cron_seguimiento_calculadora.php`) solo manda 2 recordatorios dentro de las primeras
  24h de silencio -- después de eso, la ventana de WhatsApp se cierra y NADIE vuelve a
  contactar a quien no contestó, sin importar cuántos días pasen. Esto es una pérdida real de
  ventas (alguien que calculó su liquidación y se quedó pensando varios días sin que nadie le
  vuelva a escribir). Arreglarlo de fondo necesita una plantilla nueva aprobada por Meta (como
  ya existe `recordatorio_1_hora`) para poder escribir fuera de la ventana de 24h -- días de
  espera de aprobación, no es inmediato. **Sin construir todavía, pendiente de que el usuario
  decida si quiere que se arme.**
- **El contenido del propio live de TikTok** (guion, ganchos, cómo cerrar en vivo hacia el
  WhatsApp) es territorio de la conversación "negocio" (miniaturas, métricas, embudo) -- no se
  tocó nada de eso aquí, se le puede avisar a esa sesión si el usuario quiere una revisión de
  neuromarketing del contenido del live en sí, aparte del bot de WhatsApp.
- 115 casos en `scripts/pruebas_bot.php` siguen pasando. `php -l` limpio. Sin cambios de base de
  datos, sin tocar `app.js`. **Sin probar en vivo todavía** -- falta que el usuario suba
  `api/ia_helpers.php` y se confirme con casos reales si el cierre con riesgo concreto ayuda a
  cerrar más.

## Investigación: caída real de ventas de asesoría desde el 13-nov (24 nov 2026, conversación "Bot")
Pedido explícito del usuario: "revisa porfa si hay algún tema por el cual el bot esté vendiendo
menos asesorías, llevamos días bajos". Sin acceso a la base en vivo desde esta sesión -- se le
pidió al usuario correr SQL en phpMyAdmin con los resultados reales.
- **Datos reales (asesorías confirmadas, noviembre 2026)**: 1-12 nov = 46 asesorías en 12 días
  (**3.83/día** promedio, varios días con 5-8 ventas) → 13-24 nov = 16 asesorías en 12 días
  (**1.33/día**, caída de ~65%). El corte es casi exacto el 12/13-nov, no gradual, y **sigue bajo
  hasta el 22-24-nov** -- más allá de cuando ya había pasado el puente/Buen Fin (el usuario
  sugirió esa explicación primero; los datos no la sostienen por sí sola, aunque pudo sumar en
  los primeros días de la caída).
- **Causa real más probable, encontrada revisando el código y CLAUDE.md fecha por fecha**: el
  corte coincide con 2 cambios reales del 12-13 nov en la conversación "chats" -- (1) la
  corrección del bug de "trabajador de confianza" (20 días sumados de más en un despido, caso
  real de Norma: el estimado pasó de ~$571,486 a ~$262,161 real) y (2), como consecuencia directa
  de ESE bug, `cron_seguimiento_calculadora.php` **dejó de mencionar el monto del cálculo** en
  los 2 recordatorios automáticos que se le mandan a quien calculó su liquidación y se quedó
  callado sin agendar -- el bug estaba en que el monto guardado (`monto_total`) salía INFLADO,
  no en mencionarlo; al quitar la cifra por completo (en vez de solo corregir el cálculo de
  origen) se le restó el principal gancho de urgencia a ese seguimiento automático, justo el
  mismo día que arrancó la caída.
- **Corregido (24 nov 2026)**: `api/cron_seguimiento_calculadora.php` vuelve a incluir el monto
  (`calculos_liquidacion.monto_total`) en los 2 recordatorios -- ahora es seguro, porque el bug de
  origen (el CÁLCULO, no la mención) ya está corregido desde el 12-nov, mismo criterio que ya usa
  el cierre en vivo del bot (la REGLA DURA de "Lead 2" en `ia_helpers.php` ya ancla el precio de
  $499 contra el monto real calculado -- eso nunca se tocó, solo el seguimiento automático se
  había quedado sin esa cifra). Los 2 mensajes siguen empezando con el mismo prefijo literal
  exacto ("¡Hola! Sigo al pendiente de tu caso" / "¡Hola de nuevo! Sigo por aquí") que
  `ia_es_mensaje_automatico()` necesita para que el revisor diario del bot no los evalúe como una
  respuesta nueva de la IA (confirmado con una prueba manual en PHP, no solo leído). `php -l`
  limpio, `php scripts/pruebas_bot.php` sigue con sus 98 casos pasando (no se tocó ninguna otra
  regla del prompt, el `ia_montos_sin_respaldo()` nuevo de "chats" no aplica aquí -- ese candado
  solo revisa texto generado por la IA en vivo, no estos 2 mensajes fijos de PHP).
- **A propósito NO se tocó nada de la "Precisión del bot" (criterios más estrictos, tope de
  prima de antigüedad con el salario mínimo de la fecha del despido, etc.)** -- son correcciones
  legales deliberadas del despacho, no bugs; revertirlas solo para subir ventas sería dar
  información incorrecta a los clientes. El único cambio fue restaurar un gancho de venta que se
  quitó como efecto secundario de corregir un bug distinto, ahora que ese bug ya no existe.
- **Sin probar en vivo todavía** -- falta que el usuario suba `api/cron_seguimiento_calculadora.php`
  y se confirme en unos días (comparando contra la tendencia de estos últimos 12 días) si las
  asesorías vuelven a subir. Si no sube, revisar también el cambio de modelo a Sonnet 5.5 con
  razonamiento bajo (17-nov, nunca confirmado en vivo) como siguiente sospechoso.

## Dominio del sistema en vivo
`https://sistema.expertoslaborales.com/sistema/` -- confirmado por el usuario (24 nov 2026).
Ninguna sesión de Claude tiene credenciales de Administrador para entrar ahí -- para cualquier
dato en vivo (paneles, reportes), pedirle al usuario que entre él mismo y comparta lo que ve
(captura o copiar/pegar), o darle SQL para correr en phpMyAdmin.

## Documentos/avisos de boletín nuevos no bumpeaban `actualizado_en` -- el Informe Ejecutivo nunca se enteraba (20 nov 2026, conversación "Bot")
Reportado por el usuario viendo el expediente **Anel Yadira Hernández Zaragoza vs Banco Nacional
de México, Exp. 463/2026**: sus 4 PDFs de acuerdo (subidos el 19-nov por el robot Federal,
confirmados reales y legibles ese mismo día) no mostraban ningún resumen de IA en la pestaña
"Documentos" ni se reflejaban en el "Informe del juicio". Pregunta explícita: "porque la ia no ha
actualizado el contenido de los documentos y porque no los ha mostrado en el resumen ejecutivo".
- **Causa real, mismo patrón ya repetido varias veces en este archivo** (notas de seguimiento el
  13-nov, botón "Guardar pendientes" el 12-nov): **ningún camino que crea un documento o aviso
  nuevo bumpeaba `expedientes.actualizado_en`** -- ni la subida manual del abogado
  (`documentos_upload.php`), ni la subida de un robot (`documentos_subir_robot.php`), ni la
  creación del aviso (`avisos_ingest.php`). El Informe Ejecutivo (`expediente_resumen_
  ejecutivo.php`) solo se regenera si `actualizado_en` es más reciente que
  `resumen_ejecutivo_generado_en` -- sin este bump, por más documentos/avisos nuevos que llegaran,
  el informe seguía mostrando el mismo texto viejo para siempre.
- **Segundo hueco encontrado en los 2 crons que SÍ leen el contenido con IA**
  (`cron_analizar_acuerdos_ia.php`/`cron_analizar_documentos_ia.php`): solo bumpeaban
  `actualizado_en` cuando el resultado llenaba una etapa SOLA (`$aplicado = true`) -- pero la
  mayoría de los acuerdos (ej. una réplica de contestación, un acuerdo de trámite sin fecha
  nueva que capturar) no llenan ninguna etapa, así que aunque la IA sí generaba su resumen
  (`ia_resumen`, visible en la tarjeta del aviso), ese resumen tampoco avisaba al Informe
  Ejecutivo que había algo nuevo que leer -- exactamente el caso real del expediente 463/2026:
  sus 4 acuerdos SÍ tenían PDF válido y probablemente ya fueron analizados por el cron, pero sin
  ninguna etapa nueva que llenar el bump nunca ocurrió.
- **Corregido, 5 archivos**: `documentos_upload.php` y `documentos_subir_robot.php` ahora
  bumpean `actualizado_en` justo después de insertar el documento; `avisos_ingest.php` lo
  bumpea tras insertar un aviso nuevo (no duplicado); `cron_analizar_acuerdos_ia.php` y
  `cron_analizar_documentos_ia.php` ahora bumpean también cuando `$aplicado` es `false` (el
  resumen de la IA en sí ya es información nueva, no solo cuando se llena una etapa) -- incluida
  la rama de "copiado" (cuando un documento ya viene de un robot de boletín y solo se copia el
  resultado en vez de volver a mandarlo a la IA).
- `php -l` limpio en los 5 archivos. Sin cambios de base de datos, sin tocar `app.js`/
  `mergeTags()` -- no aplica `verificar_tags.js` ni `pruebas_bot.php` (no es lógica del bot de
  WhatsApp). Se revisaron las otras 2 ramas (`claude/hola-i5mb6q`, `claude/magical-albattani-
  ephif7`) antes de tocar estos archivos -- ninguna tenía commits sin fusionar sobre ellos.
- **Causa real de fondo encontrada al probar en vivo (20 nov 2026)**: al correr
  `cron_analizar_documentos_ia.php` a mano (`documentos` tab de 463/2026 seguía sin ningún
  resumen), tronó con `HTTP ERROR 500` sin ningún `error_log` visible en el hosting -- se
  activó `display_errors` temporalmente en el script para ver la causa real, confirmando
  `PDOException: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'ia_categoria_sugerida'`.
  **La migración 065 (`065_ia_sugiere_categoria_documento.sql`) nunca llegó de verdad a la base
  de datos en vivo** (`c1361311_exp`), aunque este mismo archivo decía desde el 10-11 nov 2026
  que ya estaba corrida -- mismo patrón de confusión ya documentado varias veces aquí (base
  equivocada / entrega incompleta). Como esta columna la espera el `UPDATE` de CADA documento
  que el cron analiza (no solo los que llenan una etapa), el cron llevaba probablemente DÍAS o
  semanas tronando en silencio en cada corrida programada -- nadie lo notó porque el Cron Job
  no manda ningún aviso de error, solo deja de avanzar.
  - **Corregido**: el usuario corrió el `ALTER TABLE expediente_documentos ADD COLUMN
    ia_categoria_sugerida VARCHAR(40) NULL AFTER ia_analizado_en;` directo en phpMyAdmin,
    confirmado en la base correcta. Se quitó el diagnóstico temporal de `display_errors` del
    script (ya cumplió su función).
  - **Confirmado funcionando en vivo tras la corrección**: primera corrida real reportó "15
    documento(s) en el lote, 15 analizado(s) con IA... 0 fallido(s)" -- sin tronar. Como el cron
    llevaba tiempo sin poder completar ni un solo documento, hay un backlog acumulado en TODO el
    sistema (no solo en 463/2026) -- hace falta correrlo varias veces más (o esperar a que el
    Cron Job programado lo vaya vaciando solo) hasta que diga "Sin documentos pendientes de
    analizar", y ahí sí confirmar que el expediente 463/2026 ya muestra el resumen de IA debajo
    de sus 4 PDFs en "Documentos" y que "Informe del juicio" → "Actualizar" ya refleja su
    contenido real. **Pendiente de esa confirmación final del usuario.**
  - Lección para la próxima vez que un cron de este sistema parezca "no hacer nada" en corridas
    sucesivas sin ningún error visible: sospechar primero de una migración que el archivo dice
    "ya corrida" pero nunca llegó a la base correcta -- el patrón de `display_errors` temporal
    (activarlo solo en el script sospechoso, nunca dejarlo puesto) es la forma más rápida de
    confirmarlo cuando el hosting no deja ver un `error_log` físico.
  - **Pendiente nuevo encontrado al confirmar el arreglo de arriba (20 nov 2026)**: con
    `actualizado_en` ya corrigiéndose, el Informe Ejecutivo de 463/2026 sí leyó el contenido real
    de los 4 acuerdos y narró bien la secuencia (emplazamiento, contestación, réplica, vista para
    contrarréplica) -- pero "Etapas del juicio" seguía con `Emplazamiento`/`Contestación de
    demanda recibida`/`Objeciones y réplica` en blanco. Se forzó una corrida a mano de
    `cron_auditoria_expediente_ia.php` (el que SÍ llena etapas) -- "1 candidato(s), 1
    auditado(s), 0 etapa(s) llenada(s)". Revisado "Actividad de la IA" (últimos 30 días): **este
    expediente no aparece NI UNA vez** -- ni aplicado ni rechazado por ningún candado. No es un
    candado bloqueando algo detectado (como sí pasó con Isabel Torres Arizmendi/Alejandro
    Hernández en esa misma lista); la IA simplemente nunca reconoció la confirmación al leer esos
    2 documentos específicos (Acuerdo_No_2 y Acuerdo_No_4, Exp. 463/2026).
  - **Causa probable (sin confirmar con certeza, pendiente de investigar más si se repite)**:
    Acuerdo_No_2/No_4 no son un "ACUERDO" clásico del Tribunal (los que sí reconoce bien, ej. "el
    Tribunal admite...") -- son **constancias/certificaciones del Secretario Instructor** sobre
    el transcurso de un plazo ("CERTIFICO: a... b. El escrito de contestación... se presentó
    el..."), con tabla de cómputo de días inhábiles, y terminan con un bloque largo (~60 líneas)
    de metadatos de firma electrónica/certificado criptográfico que puede estar diluyendo la
    atención del modelo sobre la fecha real, enterrada en medio de un párrafo de certificación en
    vez de un encabezado claro. El texto del PDF SÍ trae las 3 fechas literales e inequívocas
    (confirmado leyendo el documento completo): Emplazamiento = notificación **personal el 27 de
    agosto de 2026** (ahí "surtió efectos"); Contestación recibida = escrito presentado el **22
    de septiembre de 2026** ("en tiempo"); Objeciones y réplica = firma electrónica del **8 de
    octubre de 2026**. Las 3 las capturó el usuario a mano en "Etapas del juicio" para desbloquear
    el expediente de inmediato.
  - **Corregido (20 nov 2026), pedido explícito del usuario: "necesito que arregles que si lo
    lea y que lo llene solo para que no ocurra nuevamente... el sistema debe ser autonomo"**.
    Primer intento: reforzar el prompt de `ia_analizar_acuerdo_boletin()` con el patrón de la
    constancia ("CERTIFICO: a)... b) El escrito de contestación/réplica... se presentó el
    [fecha]...", ignorar el bloque de metadatos de firma electrónica al final). **Confirmado en
    vivo que SÍ funcionó para "Contestación recibida" (22-sept) y "Objeciones y réplica"
    (8-oct)** -- resetear `ia_analizado_en` de esos 2 documentos en phpMyAdmin y volver a correr
    `cron_analizar_documentos_ia.php` dio "2 etapa(s) llenada(s) sola(s)", ambas correctas.
  - **Pero faltó "Emplazamiento"** -- causa real encontrada revisando el dato crudo en
    phpMyAdmin (`SELECT ... FROM expediente_documentos`): el Acuerdo_No_2 (constancia) en
    realidad confirma DOS eventos distintos con fecha propia en el MISMO texto -- parte (a)
    certifica el emplazamiento a Banamex (notificación personal 27-ago-2026) y parte (b)
    certifica la contestación (presentada 22-sept-2026) -- pero `ia_analizar_acuerdo_boletin()`
    (y los 2 crons que la llaman) solo estaban diseñados para devolver/aplicar UNA sola
    etapa+fecha por documento, así que se quedó solo con la de "Contestación" (el título del
    documento) e ignoró la del emplazamiento, aunque estuviera en el mismo texto.
  - **Corregido de fondo (mismo día, pedido explícito "resuelvelo")**: `ia_analizar_acuerdo_
    boletin()` ahora puede devolver VARIOS eventos (etapa+fecha) por documento -- el prompt pide
    una lista repetible ("EVENTO: <etapa> | FECHA: YYYY-MM-DD o (ninguna)") en vez de un solo
    par, y el parseo arma un arreglo `eventos_adicionales`. Nueva función compartida
    `ia_aplicar_etapa_sugerida()` (`api/expediente_helpers.php`) saca a un solo lugar la lógica
    de candados que antes se repetía completa en los 2 crons (nunca sobrescribe fecha ya
    capturada, nunca aplica fecha futura como "ya celebrada" -- redirige a "fecha agendada" o
    rechaza --, nunca rompe el orden cronológico, registra en `log_historial`, bumpea
    `actualizado_en`) -- así un mismo documento puede aplicar varias etapas sin duplicar ese
    bloque por cada una. `cron_analizar_acuerdos_ia.php`/`cron_analizar_documentos_ia.php`
    reescritos para iterar sobre TODOS los eventos detectados (el principal + los adicionales),
    conservando su diferencia de siempre (el de boletín cae a la fecha de publicación como
    respaldo si no hay fecha literal; el de documentos nunca usa respaldo).
  - **3 vueltas más hasta cerrarlo de verdad, con log de diagnóstico temporal
    (`ia_analizar_debug.log`, ya quitado) para ver la respuesta cruda de la IA en cada intento**:
    1. Primera corrida del arreglo de "varios eventos" dio 0 etapas llenadas -- el log mostró que
       la IA respondió `EVENTO: contestacion | FECHA: 2026-09-22` (sin el sufijo `_recibida`) --
       mi validación exacta (`in_array` contra el vocabulario) descartaba en silencio cualquier
       clave que no calzara letra por letra, así que ni siquiera la contestación (que ya tenía
       fecha capturada, de cualquier forma) se reconocía, y el emplazamiento ni se mencionó.
       **Corregido**: nueva normalización en el parseo -- si la clave que da la IA es un prefijo
       único de EXACTAMENTE una clave real del vocabulario (nunca si hay 2+ candidatas, ej.
       "conciliacion"/"demanda"/"audiencia", ahí se sigue descartando), se corrige sola. Reforzado
       también el prompt para pedir la clave exacta sin abreviar.
    2. Con eso ya detectó bien `emplazamiento` como etapa, pero con `FECHA: (ninguna)` -- el
       documento SÍ trae la fecha (27-ago-2026), pero en una TABLA de cómputo de plazo
       ("Notificación Personal 27 de agosto de 2026..."), no en una oración narrativa. Reforzado
       el prompt para buscar la fecha de cada evento por separado y reconocer una tabla como
       fuente igual de literal que una oración.
    3. Seguía en `(ninguna)` -- causa real: el documento menciona el emplazamiento DOS VECES
       distintas (la tabla CON fecha, y al final "se agrega la constancia de emplazamiento con
       folio SIGE 7325" SIN fecha) -- la IA se quedaba con la segunda mención. Reforzado el
       prompt explícitamente: un número de folio nunca es una fecha, usar siempre la mención que
       sí trae fecha exacta si el mismo evento aparece más de una vez.
  - **✅ CONFIRMADO FUNCIONANDO DE PUNTA A PUNTA (20 nov 2026)**: tras el 3er refuerzo, la corrida
    dio **"1 etapa(s) llenada(s) sola(s)"** -- "Emplazamiento a la demandada realizado" quedó con
    `27/08/2026`, confirmado por el usuario viendo "Etapas del juicio". Con esto, las 3 etapas que
    motivaron toda esta investigación (emplazamiento, contestación, objeciones y réplica) del
    Exp. 463/2026 quedan confirmadas llenándose solas a partir de un único documento con eventos
    múltiples -- el pedido explícito del usuario ("el sistema debe ser autosuficiente") se da por
    cumplido para este caso real. Se quitó el log de diagnóstico temporal una vez confirmado.
  - `php -l` limpio en los 4 archivos tocados (`api/ia_helpers.php`, `api/expediente_helpers.php`,
    `api/cron_analizar_acuerdos_ia.php`, `api/cron_analizar_documentos_ia.php`). Sin cambios de
    base de datos, sin tocar `app.js` -- no aplica cache-bust ni `verificar_tags.js`. No aplica
    `pruebas_bot.php` tampoco -- esa suite cubre el bot de WhatsApp, no estas funciones.
  - **Pendiente**: que el usuario suba la versión final de `api/ia_helpers.php` (sin el log de
    diagnóstico) -- las últimas 2-3 entregas que mandé mientras se ajustaba el prompt en vivo ya
    traían el log puesto; esta última ya no.

## Robot Federal: clic a "Vista Global" sin reintentos tiraba la sesión completa (19 nov 2026, conversación "Bot")
Reportado por el usuario: el expediente **Anel Yadira Hernández Zaragoza vs Banco Nacional de
México, Exp. 463/2026** (Tribunal ya bien capturado -- Decimoprimer Tribunal Laboral Federal --
asignado a Lic. Lindsay Luna Maldonado) tiene acuerdos reales en el Portal Federal, pero el robot
no lo reportó en 2 corridas seguidas.
- **Causa real**: en la 1ª corrida, la cuenta del Administrador (buerhend) falló por errores de
  red (`Timeout 30000ms exceeded` navegando, `Client network socket disconnected` en TLS) antes de
  enumerar expedientes -- pero el 463/2026 está asignado a Lindsay, no al Administrador. En la 2ª
  corrida, la cuenta de Lindsay (maluli20) SÍ llegó a la sesión "laboral" (la que ve expedientes
  normales, no amparos), pero el clic a **"Vista Global"** (`federal.js`, dentro de
  `procesarCuenta()`) tronó con el mismo error de siempre en este portal (`locator.click: Timeout
  30000ms exceeded`, visible/habilitado/estable pero sin completarse -- probablemente un overlay
  que reaparece justo al hacer clic) -- y, a diferencia del clic de arriba ("Consulta de
  Expediente Electrónico", que SÍ reintenta 3 veces con limpieza de modales + recarga de último
  recurso), este clic no tenía NINGÚN reintento: un solo tropiezo tiraba toda la sesión sin
  revisar ni un expediente, así que el 463/2026 nunca llegó a buscarse.
- **Corregido**: el clic a "Vista Global" ahora usa el mismo patrón de 3 intentos que ya tenía el
  de arriba (timeout corto + limpieza de modal/backdrop atorado, y de último recurso recargar
  desde cero y volver a entrar al submenú completo antes de darse por vencido).
- Sin cambios de base de datos, sin tocar `app.js` -- no aplica `verificar_tags.js` ni
  `pruebas_bot.php` (es un robot de Node, no el bot de WhatsApp). `node --check` limpio.
  **Sin confirmar en vivo todavía** -- falta que el usuario reemplace `federal.js` y corra el
  robot de nuevo para confirmar que la sesión "laboral" de Lindsay ya no truena en "Vista Global"
  y que el 463/2026 aparece con sus acuerdos.
- **Confirmado PARCIAL en vivo (19 nov 2026)**: nueva corrida -- la sesión "laboral" de Lindsay ya
  NO tronó en "Vista Global" (arreglo confirmado funcionando), llegó a enumerar sus 10 expedientes
  y terminó limpio con "0 aviso(s) nuevo(s)". Pero no se pudo confirmar si el 463/2026 de verdad
  estaba entre esos 10 o si el robot lo vio y no encontró acuerdos nuevos -- el log nunca imprime
  qué números de expediente salieron en la página cuando ninguno hace match (`continue` silencioso
  en el bucle de `procesarCuenta()`). **Agregado un log de diagnóstico** justo después de "N
  expediente(s) visibles..." que lista los números de expediente vistos en esa página, para la
  próxima corrida. `node --check` limpio. **Pendiente**: que el usuario corra el robot una vez más
  con este archivo y confirme si "463/2026" aparece en esa lista -- si SÍ aparece pero sigue en "0
  avisos", el problema está en otro lado (ej. `procesados_federal.json` ya tiene un número de
  acuerdo guardado igual o mayor al real, o el expediente no tiene ningún acuerdo nuevo de verdad);
  si NO aparece, el expediente no está en la "LISTA DE BUZONES"/Vista Global de esa cuenta en el
  portal (revisar con el usuario si Lindsay de verdad ve ese expediente ahí).
- **Diagnóstico confirmado con el log completo de la corrida (19 nov 2026)**: con el log nuevo
  ya no hay NINGÚN error -- las 4 sesiones (buerhend laboral/amparo, Lindsay laboral/amparo)
  enumeraron limpio. Pero el **463/2026 no aparece en NINGUNA de las 4 listas**:
  - buerhend laboral: 242/2024, 371/2024, 125/2026
  - buerhend amparo: 269/2025, 1300/2025, 1301/2025, 1292/2025
  - Lindsay laboral: 57/2025, 193/2025, 320/2025, 319/2025, 347/2025, 378/2025, 397/2025,
    451/2025, 114/2026, 242/2026
  - Lindsay amparo: 1018/2025, 797/2026, 1028/2026, 3695/2026
  - **Esto ya no es un bug del robot** -- confirma que NINGUNA de las 2 cuentas del Portal
    Federal guardadas en el sistema (buerhend y maluli20/Lindsay) ve ese expediente en su
    "Vista Global", aunque el sistema lo tenga asignado a Lindsay. Significa que, del lado del
    PJF, ninguna de esas 2 cuentas está dada de alta como apoderado en el 463/2026 todavía.
  - **Pendiente del usuario**: entrar a mano al Portal del PJF con la cuenta de Lindsay y
    confirmar si el 463/2026 aparece ahí en "Vista Global" -- si no aparece tampoco a mano, el
    problema es que su cuenta del portal no está registrada como apoderada en ese expediente
    (hay que presentar/confirmar la promoción de personalidad ante el Tribunal), no algo que el
    robot pueda corregir.
- **Causa real de verdad encontrada (19 nov 2026)**: el usuario mandó una captura de la cuenta de
  Lindsay en el Portal Federal -- el 463/2026 (Anel Yadira Hernández Zaragoza) SÍ está visible en
  la lista real de "Vista Global", justo debajo de la página que el robot leyó (se ve también
  "501/2026" más abajo) -- confirma que la tabla SÍ pagina de verdad, con más expedientes de los
  10 que el robot reportó. **Bug real encontrado**: `leerFilasVistaGlobal()` (la función que lee
  la tabla principal de "Vista Global" con TODOS los expedientes de la cuenta) nunca llamaba a
  `maximizarEntradasPorPagina()` -- a diferencia de la tabla de acuerdos DENTRO de cada
  expediente (`procesarExpediente()`), que sí la llama antes de leer. Por eso solo leía la
  primera página (el default de DataTables, 10 filas), y cualquier expediente que quedara más
  abajo en la lista de la cuenta -- como el 463/2026 -- nunca se llegaba a ver, sin importar que
  el clic a "Vista Global" y el resto del flujo funcionaran perfecto.
- **Corregido**: `leerFilasVistaGlobal()` ahora llama a `maximizarEntradasPorPagina()` antes de
  leer la tabla, mismo patrón que ya usaba la tabla de acuerdos. `node --check` limpio.
  **Sin confirmar en vivo todavía** -- falta que el usuario reemplace `federal.js` y corra el
  robot de nuevo para confirmar que esta vez el 463/2026 sí sale en la lista de "Números vistos
  en esta página" de la sesión "laboral" de Lindsay (y, si tiene acuerdos nuevos, que se
  reporten).
- **Pedido explícito del usuario tras ese mismo arreglo: "t asegurate de que lea todo"** --
  `maximizarEntradasPorPagina()` sola no basta si el máximo disponible en el selector (ej. 100)
  sigue siendo menor que el total de expedientes/acuerdos reales. Nueva función
  `leerTodasLasFilasDataTable()` -- maximiza Y además pasa de página en página con el botón
  "Siguiente" de DataTables (detectado por su id fijo `<tablaId>_next`) hasta que se acabe o se
  deshabilite -- aplicada tanto en `leerFilasVistaGlobal()` (lista de expedientes de la cuenta)
  como dentro de `procesarExpediente()` (tabla de acuerdos de cada expediente, que antes solo
  maximizaba sin paginar). `node --check` limpio.
  - **Confirmado funcionando en vivo (19 nov 2026)**: nueva corrida -- la sesión "laboral" de
    Lindsay ya enumeró **19 expedientes** (antes 10) y **el 463/2026 SÍ aparece** en la lista de
    "Números vistos en esta página". El bug de paginación de la lista de expedientes queda
    confirmado resuelto.
  - **2 bugs NUEVOS encontrados al procesar los expedientes que solo se veían gracias a este
    arreglo (más allá de los primeros 10)**:
    1. **Descarga de PDF fallida para los 9 acuerdos del expediente 300/2026** -- el modal del
       documento sí abrió, pero la petición real de descarga
       (`serviciossige.oaj.gob.mx/wsFileManagerDownLoad/api/files/Download/...`) falló con
       `net::ERR_ABORTED` (acompañada de un error de JS del propio portal, `table.autoFill is not
       a function`, sin confirmar si está relacionado o es ruido aparte). Los 9 avisos SÍ se
       reportaron igual (mismo respaldo que ya existía desde el 8 nov 2026: sin PDF válido, el
       aviso se manda sin documento adjunto) -- no se pierde el aviso, solo el PDF. **Sin
       diagnosticar ni corregir todavía** -- no se tocó nada de `descargarPdfAcuerdo()` esta vez,
       falta decidir si vale la pena perseguir esto o si (como pasó antes con otros expedientes)
       es un endpoint nuevo del portal que necesita su propio camino de descarga. Si vuelve a
       pasar, pedir el volcado de "HTML real DENTRO del iframe" que ya imprime el código cuando
       esto falla (no se alcanzó a revisar ese bloque en este log).
    2. **Causa real encontrada y corregida -- "locator.click: Timeout 30000ms exceeded" en los
       expedientes 296/2026 y 388/2026** (los 2 siguientes después de 300/2026 en la lista):
       `leerTablaPorEncabezados()` regresa, para cada fila, un locator de Playwright por
       POSICIÓN (`tbody tr`.nth(i)) -- estos locators NUNCA quedan "congelados" en el momento en
       que se leyó la tabla, se vuelven a resolver contra el DOM EN VIVO justo cuando de verdad
       se usan (el clic). El bucle de `procesarCuenta()` armaba la lista completa de expedientes
       UNA sola vez al principio (leyendo varias páginas de Vista Global), pero cada vez que
       terminaba de revisar un expediente y regresaba ("Regresar" o recargar desde cero), la
       tabla de Vista Global volvía a su estado por default (10 filas, página 1) -- así que para
       cuando el bucle llegaba a la fila 11 (296/2026, que venía de la página 2) esa posición ya
       no existía en la página 1 recién reseteada, y el clic se quedaba esperando en vano una
       fila que nunca iba a aparecer ahí. Mismo patrón de fondo que el bug de paginación de
       arriba, pero del lado de los LOCATORS en vez de del lado de la LECTURA.
    - **Corregido**: nueva función `buscarFilaPorExpediente()` -- en vez de reusar los locators
      capturados antes del bucle, cada expediente se vuelve a buscar EN VIVO (maximizando y
      paginando de nuevo si hace falta) justo antes de darle clic a su botón "Ver Expediente
      Electrónico". Si no se vuelve a encontrar, se omite esa vez con un aviso en el log en vez
      de tronar. `node --check` limpio. **Sin confirmar en vivo todavía** -- falta que el usuario
      reemplace `federal.js` y corra el robot de nuevo para confirmar que 296/2026, 388/2026 y
      sobre todo el propio 463/2026 (que viene justo después de 388/2026 en la lista de Lindsay)
      ya se abren sin tronar y, si tienen acuerdos nuevos, se reportan.
    - **Confirmado con el log completo de esa misma corrida (código viejo, antes de este
      arreglo)**: el expediente **463/2026 mismo** (`nth(14)`) y **501/2026** (`nth(15)`) también
      tronaron con el idéntico `locator.click: Timeout 30000ms exceeded` justo después de
      296/2026 y 388/2026 -- confirma la causa real exacta: 463/2026, el expediente que motivó
      toda esta investigación, SÍ estaba siendo encontrado por fin (gracias al arreglo de
      paginación de arriba) pero nunca se llegaba a ABRIR por este bug de locators. Con el
      arreglo de `buscarFilaPorExpediente()` ya en el commit, falta la siguiente corrida real
      para confirmarlo.
    - **Confirmado PARCIAL en vivo (19 nov 2026)**: nueva corrida con el arreglo de
      `buscarFilaPorExpediente()` ya puesto -- esta vez **296/2026 SÍ se abrió bien** (sin
      tronar, 4 acuerdos nuevos encontrados y reportados -- primera vez que se llega a ver
      este expediente de punta a punta). Pero **388/2026, 463/2026 y 501/2026** (los 3
      siguientes en la lista, 463/2026 entre ellos otra vez) salieron con un mensaje nuevo:
      "AVISO: no se volvió a encontrar el expediente ... en Vista Global al intentar abrirlo
      -- se omite esta vez" -- `buscarFilaPorExpediente()` ya no tronaba con timeout, pero
      tampoco encontraba la fila, aunque sigue apareciendo en "Números vistos en esta
      página".
      - **Causa real encontrada**: después de procesar los 4 acuerdos de 296/2026 (cada uno
        con un intento de descarga de PDF fallido, dejando "table.autoFill is not a function"
        en la consola las 4 veces -- ver el bug de PDF de arriba), el clic en "Regresar" ya
        NO tronaba, pero la tabla de Vista Global quedaba en un estado roto del lado del
        portal -- probablemente relacionado con el mismo error de JS repetido. El código
        solo revisaba que el clic en "Regresar" no lanzara una excepción, nunca confirmaba
        que la tabla de verdad hubiera quedado bien después.
      - **Corregido**: nueva función `regresarAVistaGlobal()` -- después del clic en
        "Regresar", confirma que la tabla de Vista Global de verdad aparece (timeout corto);
        si no, cae al mismo respaldo de recargar desde cero y volver a entrar al submenú
        completo que ya existía (antes solo se usaba si "Regresar" mismo fallaba, nunca si
        "funcionaba" pero dejaba la tabla rota). `node --check` limpio. **Sin confirmar en
        vivo todavía** -- falta que el usuario reemplace `federal.js` y corra el robot de
        nuevo para confirmar que 388/2026, 463/2026 y 501/2026 ya se abren bien después de
        296/2026.
    - **Aparte, sigue sin corregirse** (ver más abajo el bug de PDF de 300/2026): los 4
      acuerdos de 296/2026 tampoco lograron descargar su PDF (mismo patrón exacto:
      `net::ERR_ABORTED` en `serviciossige.oaj.gob.mx`, spinner que nunca resuelve) -- los 4
      avisos sí se reportaron igual, sin el PDF adjunto.
    - **Nueva corrida (19 nov 2026, con `regresarAVistaGlobal()` ya puesto)**: esta vez NO se
      llegó a probar nada de lo anterior -- la sesión "laboral" de Lindsay (la que ve el
      463/2026) murió de entrada con `page.goto: Timeout 30000ms exceeded` navegando a
      `OpcionesFirel`, justo DESPUÉS del login exitoso -- mismo tropiezo de red genérico ya
      documentado varias veces en este portal (sin relación con nada de lo arreglado hoy),
      pero esta era la ÚNICA navegación de toda la sesión SIN ningún reintento -- un solo
      tropiezo tiraba la sesión completa antes de leer ni un expediente. **Corregido**: esa
      navegación ahora reintenta hasta 3 veces (3s de espera entre cada una) antes de darse
      por vencido. `node --check` limpio. **Sin confirmar en vivo todavía** -- falta que el
      usuario reemplace `federal.js` y corra el robot de nuevo (puede que necesite más de 1
      corrida si el portal sigue inestable en red esta noche).
    - **✅ CONFIRMADO FUNCIONANDO DE PUNTA A PUNTA (19 nov 2026) -- se da por resuelto el
      pendiente original de esta sección**: nueva corrida con los 2 arreglos de arriba
      puestos (`regresarAVistaGlobal()` + reintento de la primera navegación) -- la sesión
      "laboral" de Lindsay enumeró los 19 expedientes de siempre y esta vez **388/2026 (6
      acuerdos), 463/2026 (4 acuerdos) y 501/2026 (1 acuerdo) se abrieron y procesaron SIN
      tronar, los 3 uno tras otro** -- 11 avisos nuevos en total en esa sesión. El 463/2026
      (Anel Yadira Hernández Zaragoza vs Banco Nacional de México), que llevaba 2 corridas
      sin reportarse al inicio de esta investigación, YA aparece con sus 4 acuerdos reales
      (incluyendo "Admision D - BANCO banamex -reinstalacion" y 2 réplicas de la contestación
      de Banamex) dados de alta como avisos en el sistema.
    - **Pedido explícito del usuario, no se deja como pendiente aparte: "sin el pdf no sirve
      este rollo"** -- la descarga de PDF seguía fallando el 100% de las veces para TODOS los
      acuerdos nuevos de la corrida de arriba (296, 300, 388, 463, 501/2026 -- ~20 documentos
      distintos, mismo patrón exacto siempre: `net::ERR_ABORTED` en
      `serviciossige.oaj.gob.mx/wsFileManagerDownLoad/api/files/Download/<id>`, el modal se
      queda con el ícono de "cargando" -- `fa-spinner fa-pulse` -- en vez de mostrar el botón
      de descarga). Como esto es sistemático, en varios expedientes y 2 sesiones distintas, y
      nunca se vio ni un código de error HTTP (ningún `respuesta con error`, solo
      cancelaciones), parece un bug del propio JS del portal que cancela la petición antes de
      que llegue respuesta -- no necesariamente un rechazo real del servidor.
    - **Intentado (19 nov 2026)**: nuevo respaldo en `descargarPdfAcuerdo()`, dentro del
      camino del iframe -- se guarda la URL exacta de descarga que el clic dispara (se
      reconoce por el patrón `wsFileManagerDownLoad/api/files/Download/`), y si el camino
      normal (`intentarCapturarPdf()`) no trae nada, se vuelve a pedir esa MISMA URL directo
      con `context.request.get()` (mismas cookies de la sesión del navegador) -- sin pasar
      por el JS roto que la cancela. Si el servidor sí estaba dispuesto a responder bien (solo
      el navegador la canceló de su lado), este segundo intento la recupera. `node --check`
      limpio. **Sin confirmar en vivo todavía** -- falta que el usuario reemplace `federal.js`
      y corra el robot de nuevo; si el servidor SÍ necesitaba una cookie propia de ese dominio
      (`serviciossige.oaj.gob.mx`) que nunca se llegó a recibir por las cancelaciones previas,
      este intento también puede fallar -- en ese caso, pedir el log completo otra vez
      (buscar la línea nueva "PDF recuperado reintentando..." para confirmar si funcionó, o
      seguir viendo "no se pudo descargar el PDF" si no).
    - **Bug real encontrado en vivo (19 nov 2026): el robot se quedó COLGADO para siempre**
      -- confirmado por el usuario ("aqui se quedo"), el log se detuvo a medio expediente
      114/2026 (sesión "laboral" de Lindsay) sin ningún error, sin avanzar más. Causa real:
      el `context.request.get(urlDescargaIntentada)` agregado en el intento de arriba NO
      tenía ningún timeout explícito -- si el servidor no rechaza la petición (como sí hace
      el navegador con `net::ERR_ABORTED`) sino que simplemente nunca contesta nada, la
      llamada se queda esperando indefinidamente. **Corregido**: se le agregó
      `{ timeout: 15000 }`, mismo límite que usa el resto de los intentos de este archivo.
      `node --check` limpio. **Sin confirmar en vivo todavía** -- falta que el usuario
      reemplace `federal.js` una vez más y corra el robot de nuevo; si vuelve a colgarse sin
      ningún mensaje de error, es una señal de que hay OTRO punto sin timeout en algún lado,
      pedir en qué expediente/acuerdo se quedó exactamente para localizarlo.
    - **Confirmado funcionando en vivo (19 nov 2026): el timeout sí evitó el colgado** --
      nueva corrida con el archivo corregido, avanzó de 114/2026 hasta 242/2026 sin volver a
      trabarse. Pero el patrón de la falla de PDF cambió: ahora que el ícono de descarga
      carga completo (ya no se queda en el spinner de antes), el log YA NO muestra ningún
      "net::ERR_ABORTED" en absoluto -- ni un solo "[petición fallida]" en ~16 intentos
      seguidos, aunque sigue fallando el 100% de las veces ("no se pudo descargar el PDF").
      Esto significa que el clic al ícono completo ya NO dispara el mismo tipo de petición
      de red que antes (o no dispara ninguna detectable) -- hace falta más diagnóstico antes
      de intentar otro arreglo a ciegas. **Agregados 3 logs de diagnóstico nuevos** justo
      antes/durante el reintento directo: (1) si se detectó o no alguna petición de descarga
      tras el clic (y cuál URL, si la hubo), (2) si el reintento directo con
      `context.request.get()` mismo respondió algo (status/bytes/content-type) o si tronó con
      qué error exacto. `node --check` limpio. **Sin confirmar en vivo todavía** -- falta que
      el usuario reemplace `federal.js` una vez más (puede dejar correr la corrida actual
      hasta el final primero, esto no afecta nada de lo ya confirmado) y mande el log
      completo de la siguiente corrida para ver qué dicen estos 3 diagnósticos nuevos.
    - **✅ Corrida real (19 nov 2026) sin NI UN solo "no se pudo descargar el PDF"**: con el
      archivo de diagnóstico puesto, la sesión "laboral" de Lindsay procesó los 7
      expedientes pendientes (114/2026, 242/2026, 300/2026, 296/2026, 388/2026, 463/2026,
      501/2026 -- **42 avisos nuevos en total**) sin que apareciera NINGUNA de las 3 líneas
      de diagnóstico nuevas (ni "no se detectó ninguna petición de descarga", ni "reintento
      directo..."), lo que significa que `intentarCapturarPdf()` tuvo éxito por el camino
      NORMAL en los 42 acuerdos -- el reintento nuevo ni hizo falta. No se sabe con certeza
      por qué el patrón cambió otra vez (el portal pudo haberse estabilizado del lado del
      JS roto, o fue timing/suerte) -- no se tocó nada de código para esto, solo se
      confirmó con el log. **✅ CONFIRMADO POR EL USUARIO CON CAPTURA REAL (19 nov 2026) --
      sección 100% cerrada**: 463/2026 muestra sus 4 PDFs reales en la pestaña Documentos
      (`Acuerdo_No_1..4_Exp_463-2026.pdf`, 576.4-633.0 KB cada uno) y 114/2026 muestra 47
      documentos subidos con tamaños reales (500-666 KB cada uno, no archivos vacíos ni
      corruptos). Con esto se da por resuelta de punta a punta toda esta investigación: el
      463/2026 (Anel Yadira Hernández Zaragoza vs Banco Nacional de México) se encuentra, se
      abre, se le leen sus acuerdos, y sus PDFs se descargan y suben bien al expediente. No
      se identificó con certeza por qué las descargas de PDF fallaban 100% de las veces en
      corridas anteriores y luego dejaron de fallar sin cambio de código -- si vuelve a
      fallar en el futuro, el reintento directo + los 3 logs de diagnóstico ya agregados
      quedan listos para usarse de nuevo.

## Robot Buzón CDMX: la lista de acuerdos DENTRO de cada expediente nunca paginaba (19 nov 2026, conversación "Bot")
Pedido explícito del usuario tras cerrarse la investigación del robot Federal: "debes revisar que
el robot de cdmx este viendo todos los asuntos y todas las paginas y el del edomex tambien".
- **Edomex correo revisado -- ya está cubierto, sin cambios**: hace scroll hasta el final de la
  bandeja en CADA corrida (no solo la primera vez), nunca se corta al encontrar un correo ya
  visto (usa `continue`, no `break`, desde el arreglo del 19 nov documentado arriba) y guarda el
  conjunto completo de ids ya procesados -- ya confirmado funcionando en vivo ("0 aviso(s)
  nuevo(s)" en ambas cuentas tras la corrida de transición). No se encontró ningún hueco.
- **CDMX Buzón: hueco real encontrado, mismo patrón exacto que el bug de Federal** --
  `leerFilasBuzones()`/`avanzarPagina()` (lista "LISTA DE BUZONES", los expedientes de la
  cuenta) SÍ paginaba bien de punta a punta. Pero la lista de acuerdos DENTRO de cada
  expediente (modal "LISTA DE ACUERDOS") nunca llamaba a `avanzarPagina()` -- solo leía lo que
  estuviera visible al abrir el modal, sin pasar de página. Nunca se notó en vivo porque los
  expedientes revisados hasta hoy solo tenían pocos acuerdos (ej. 5 en 1139/2026) -- si algún
  expediente del Buzón tiene más acuerdos de los que caben en una sola página del modal, el
  robot se los perdería en silencio, igual que pasó con Vista Global en Federal antes del
  arreglo del 19 nov.
- **Corregido**: la lectura de acuerdos ahora corre en un `while` que pagina con
  `avanzarPagina()` hasta agotar las páginas del modal, igual que ya hacía la lista de arriba.
  Para no confundir la paginación del modal con la de "LISTA DE BUZONES" que sigue en el DOM
  detrás (el modal se abre ENCIMA, no reemplaza la página), `avanzarPagina()` ahora acepta un
  `scope` opcional (Locator acotado en vez de la página completa) y nueva función
  `localizarModalAcuerdos()` intenta encontrar el contenedor real del modal (clase `.modal`/rol
  `dialog`, típico de Bootstrap, o subiendo por los ancestros del título "LISTA DE ACUERDOS"
  hasta dar con uno que también contenga un "Ver acuerdo") -- si no lo encuentra, cae de
  regreso a buscar en toda la página (mismo comportamiento de antes, nunca peor). `node --check`
  limpio. **Sin confirmar en vivo todavía** -- no se ha visto un expediente real con más de una
  página de acuerdos en el Buzón, así que esta corrección es preventiva (mismo criterio que ya
  se aplicó con éxito en Federal, donde la paginación de Vista Global sí resultó estar truncando
  expedientes reales como el 463/2026). Falta que el usuario reemplace `cdmx_buzon.js` y corra
  el robot de nuevo -- si en el log aparece alguna vez "Página 2 de acuerdos" o más, confirma que
  el hueco era real y que la corrección ya lo cubre; si nunca aparece, no hay forma de confirmar
  que de verdad funciona hasta que un expediente real tenga suficientes acuerdos.
- **Confirmado SIN ROMPER NADA en vivo (19 nov 2026)**: primera corrida real con el arreglo
  puesto -- expediente 1139/2026 (5 acuerdos, ya vistos de antes) detectó correctamente "Página
  1 de acuerdos: 5 fila(s)" y el log mostró "[paginación] ya está en la última página (1 de 1)"
  para el MODAL de acuerdos, un número DISTINTO al "(2 de 2)" que mostró la lista de atrás
  ("LISTA DE BUZONES") en el mismo momento -- confirma que `localizarModalAcuerdos()` sí acotó
  bien el contenedor del modal, sin confundirse con la paginación de la lista detrás. 0 avisos
  nuevos (esperado, ya los tenía todos). **Sigue pendiente de confirmar el caso real** (un
  expediente con más acuerdos de los que caben en una página del modal) -- no se ha dado
  todavía, así que falta ver el log pasar de verdad a "Página 2 de acuerdos" para cerrar esta
  sección al 100%. Esta corrida solo tenía 1 cuenta del Buzón CDMX guardada (Administrador) --
  si otros abogados también deben tener la suya, revisar el panel "Credenciales de portales por
  abogado" en Equipo.

## Robot Buzón CDMX: ahora cierra sesión al terminar (19 nov 2026, conversación "Bot")
Reportado por el usuario con captura real: tras correr el robot, el Buzón de Lic. Lindsay Luna
Maldonado se quedaba con la sesión abierta -- "cuando abre el sistema de la CDMX se queda
abierta y Lindsay no puede entrar desde otros dispositivos". El portal solo permite UNA sesión
activa por cuenta (ya documentado desde el 10 nov 2026 en la sección del robot).
- **Causa real**: `procesarCuenta()` (`robots/cdmx_buzon/cdmx_buzon.js`) solo hacía
  `context.close()` al terminar -- eso cierra el NAVEGADOR de Playwright, pero no la sesión del
  lado del SERVIDOR del portal (mismo motivo de fondo ya documentado para "Romper sesión" al
  INICIO del login: la app guarda la sesión activa en el servidor, no solo en una cookie que
  desaparece al cerrar el navegador).
- **Corregido**: en el `finally` de `procesarCuenta()`, antes de `context.close()`, se intenta
  cerrar sesión con el link "Salir" (confirmado real por la captura del usuario -- visible en el
  menú de la cuenta, junto a "Cambiar contraseña"). Si "Salir" no aparece directo, primero
  intenta abrir ese menú dándole clic al correo de la cuenta. Best-effort: si no lo encuentra,
  no se trata como error (el navegador se cierra igual, deja una captura
  `debug_sesion_abierta_<cuenta>.png` para diagnosticar). El listener de diálogos del navegador
  que ya existía (acepta automáticamente cualquier `confirm()`/`alert()`, agregado para "Romper
  sesión") cubre también a "Salir" sin cambios, por si dispara uno igual.
- Sin cambios de base de datos, sin tocar `app.js` -- no aplica `verificar_tags.js` ni
  `pruebas_bot.php` (es un robot de Node, no el bot de WhatsApp). `node --check` limpio.
- **Primera corrida real tras el arreglo (19 nov 2026)**: el robot NUNCA llegó a cerrar sesión
  porque nunca llegó a abrirla -- el login mismo falló las 5 veces (`AVISO: no se encontró el
  menú "Consulta de expedientes"...`). Captura real del usuario confirma la causa: el aviso rojo
  "No puede ingresar por que cuenta con una sesion activa..." SÍ estaba bloqueando, pero apareció
  DESPUÉS de llenar usuario/contraseña y darle "ENTRAR" -- el diagnóstico que ya existía (texto
  "sesión activa" + conteo de "Romper sesión") solo revisaba esto ANTES de llenar el formulario
  (con un margen fijo de 2.5s), y esta vez el aviso tardó más que eso en aparecer, así que las 5
  veces dio "false"/"0" y nunca le dio clic a "Romper sesión" -- la cuenta se quedó bloqueada las
  5 veces sin que el robot lo notara a tiempo.
  - **Corregido**: se agregó la misma revisión ("Romper sesión" visible) también justo DESPUÉS de
    enviar el login (antes de darse por vencido buscando el menú) -- si aparece ahí, le da clic,
    recarga, y reintenta en vez de agotar el intento esperando en vano un menú que ya no va a
    salir. `node --check` limpio. **Sin confirmar en vivo todavía** -- falta que el usuario
    reemplace el archivo y corra el robot de nuevo para confirmar que esta vez sí entra al
    "LISTA DE BUZONES" y, al terminar, cierra sesión con "Salir" como ya se esperaba.
  - **Confirmado funcionando en vivo (19 nov 2026)**: nueva corrida real -- el aviso de sesión
    activa apareció otra vez justo después de enviar el login, el candado nuevo lo detectó, le dio
    clic a "Romper sesión" (diálogo nativo aceptado: "Se rompió la sesion exitosamente"), recargó,
    reintentó y esta vez SÍ entró -- paginó "LISTA DE BUZONES" (2 páginas), encontró el expediente
    1139/2026, revisó sus 5 acuerdos (0 nuevos, ya los tenía todos vistos de corridas anteriores) y
    terminó con **"Sesión cerrada en el portal al terminar -- la cuenta queda libre para otros
    dispositivos."** -- confirma que los 2 arreglos del día (detectar la sesión activa tardía +
    cerrar sesión con "Salir" al terminar) ya funcionan de punta a punta. **Pendiente**: que el
    usuario confirme que Lic. Lindsay Luna Maldonado ya puede entrar a su cuenta del Buzón desde
    otro dispositivo justo después de una corrida del robot.
  - **Confirmado por el usuario (19 nov 2026): "listo ya quedo"** -- Lindsay ya puede entrar a su
    cuenta del Buzón desde otro dispositivo después de correr el robot. Cerrado.

## Una fecha FUTURA en un acuerdo ya no se marca como "celebrada" (19 nov 2026, conversación "Bot")
Reportado por el usuario: un robot bajó un acuerdo que señalaba la audiencia preliminar para el
11 de enero de 2027 (fecha futura), y el llenado automático de etapas guardó esa fecha en
"Fecha en que se celebró" -- imposible por definición, una fecha futura nunca es una
confirmación de que algo YA pasó. Pedido explícito: "cuando haya un acuerdo que señale fechas
de alguna audiencia o diligencia que no las marque como celebradas o atendidas que las deje
libres para que queden como pendientes".
- **Causa real**: los 4 mecanismos que llenan una etapa SOLA a partir de IA
  (`cron_analizar_acuerdos_ia.php`, `cron_analizar_documentos_ia.php`,
  `cron_auditoria_expediente_ia.php`, `supervisor_pregunta_responder.php`) escriben la fecha que
  la IA menciona directo en `fecha` (celebrada), sin revisar nunca si esa fecha ya pasó o no --
  a diferencia de `cron_leer_google_calendar.php`, que SIEMPRE escribe en `fecha_programada`
  (agendada) porque un evento de Google Calendar es por naturaleza algo programado, nunca una
  confirmación de que ya ocurrió.
- **Corregido**: nueva función `etapa_fecha_es_futura()` (`api/expediente_helpers.php`), candado
  nuevo en los 4 mecanismos de arriba -- si la fecha que se va a aplicar es futura: (1) si la
  etapa soporta "Fecha agendada" (`ETAPA_CALENDARIO_KEYS` en `api/ia_helpers.php`: los 4
  citatorios/audiencias), se redirige a `fecha_programada` en vez de `fecha` -- mismo candado de
  "no sobrescribir una fecha agendada distinta ya capturada" que ya usa
  `cron_leer_google_calendar.php` (si hay conflicto, se rechaza y queda para revisión humana,
  nunca se sobrescribe en silencio); (2) si la etapa NO soporta fecha agendada (es un trámite
  binario -- ya pasó o no, como "Contestación recibida"), la fecha futura no tiene dónde
  aplicarse con sentido -- se rechaza por completo y la etapa se queda libre/pendiente. El texto
  del historial usa el prefijo exacto `"fecha agendada: ..."` (mismo que ya usa el sync de
  Google Calendar) para que `cron_qa_irregularidades.php` siga excluyendo estos llenados del
  chequeo de orden cronológico, como ya hace con los de calendario.
- Sin cambios de base de datos, sin tocar `app.js`/`mergeTags()` -- no aplica
  `verificar_tags.js` ni `pruebas_bot.php` (es lógica de llenado de etapas, no del bot de
  WhatsApp). `php -l` limpio en los 5 archivos tocados. **Sin probar en vivo todavía** -- falta
  que el usuario suba `api/expediente_helpers.php`, `api/cron_analizar_acuerdos_ia.php`,
  `api/cron_analizar_documentos_ia.php`, `api/cron_auditoria_expediente_ia.php` y
  `api/supervisor_pregunta_responder.php`, y se vea un caso real (ej. un acuerdo nuevo con una
  fecha futura) donde quede como "Fecha agendada" en vez de "Fecha en que se celebró".

## ⚠️ Aviso cruzado para "chats"/"Bot"/"negocio" (26 nov 2026)
Hoy se tocaron 3 archivos que varias sesiones comparten:
**`assets/app.js`** (todas las sesiones lo editan tarde o temprano),
**`api/expediente_helpers.php`** y **`api/google_calendar_helpers.php`**.
Antes de seguir editando cualquiera de los 3, haz `git fetch origin
claude/hola-i5mb6q` y fusiónalo -- si no, corres el riesgo de pisar sin
querer: (1) el candado nuevo de `etapa_fecha_es_consistente()`/
`detectar_irregularidades_orden_cronologico()` (ahora también revisa
`fecha_programada`, no solo `fecha`), (2) que "Guardar etapas"/Cobros ya
no inventan la fecha de hoy con un checkbox vacío, y (3) la marca
invisible `extendedProperties.private.generado_por_sistema` en los
eventos de Google Calendar (para que `google_listar_eventos()` nunca
vuelva a leerse a sí mismo). Ver los 2 resúmenes completos justo abajo.
Intentado avisar en vivo por `SendMessage`/`ListAgents` -- ninguna otra
sesión está corriendo ahora mismo, así que este archivo es el único
canal; la próxima sesión que abra cualquiera de los 3 archivos debería
ver esto primero (queda al principio del archivo a propósito).

## Google Calendar: cerrado el riesgo de que el sistema lea sus propias sugerencias (26 nov 2026, `app.js?v=164`)
Pedido explícito del usuario, mismo día de los 2 bugs de fechas de abajo:
"que no mande a google calendar las sugerencias porque ese puede ser un
generador de problemas" -- al investigar, se confirmó que su instinto
tenía una base real más seria de lo que parecía a primera vista.
- **Lo pedido**: `accion_ia` ("Sugerido por IA" -- una sugerencia de
  urgencia CALCULADA, no un compromiso real) se quitó de
  `GOOGLE_TIPOS_SINCRONIZABLES` -- ya no se manda al Google Calendar del
  abogado. La próxima vez que alguien sincronice (botón "Sincronizar
  ahora" o automático al guardar algo), el mecanismo de limpieza que YA
  existía (compara contra lo sincronizado la vez anterior,
  `google_sync_state.php`) va a BORRAR solo los "[Sugerido por IA]" que
  ya estuvieran puestos -- no hizo falta ningún script aparte.
- **Riesgo real de fondo encontrado al investigar (más grave que solo
  "se ve raro en el calendario")**: `cron_leer_google_calendar.php` lee
  TODOS los eventos de los calendarios "vigilados" sin ninguna forma de
  distinguir un evento real del abogado de uno que el PROPIO sistema
  acababa de poner ahí (`sincronizarGoogleCalendar()` en app.js,
  `google_sincronizar_cita_pagada()` en el backend) -- si un calendario
  vigilado también recibía eventos de salida (ej. del mismo sub-calendario
  de audiencias), el sistema podía leer su propia sugerencia/cálculo de
  vuelta y la IA de emparejamiento podía "confirmarla" como si fuera una
  fecha agendada real, fabricando un dato de la nada -- el mismo patrón de
  fondo de los 2 bugs de abajo, pero por un canal distinto.
- **Corregido de raíz, no solo para `accion_ia`**: cada evento que el
  sistema manda a Google Calendar (cualquier tipo, más las citas de
  asesoría pagada) ahora lleva una marca invisible
  (`extendedProperties.private.generado_por_sistema`, nunca visible para
  el abogado) y `google_listar_eventos()` descarta cualquier evento que
  la traiga ANTES de pasárselo a la IA que empareja eventos -- así que
  aunque se vuelva a sincronizar algo hacia el mismo calendario que se
  vigila, nunca puede releerse a sí mismo, sea cual sea el tipo.
  `api/google_calendar_helpers.php`.
- Sin cambios de base de datos. No se tocó `mergeTags()`/tags de demanda
  -- no aplica `verificar_tags.js`. **Pendiente del usuario**: subir
  `assets/app.js`, `sw.js`, `index.html` y `api/google_calendar_helpers.php`,
  y confirmar que tras la siguiente sincronización los "[Sugerido por
  IA]" ya desaparecieron de los calendarios de Google de los abogados.

## 2 bugs reales: citatorios agendados fuera de orden + "Fecha en que se celebró" inventada con la fecha de hoy (26 nov 2026, `app.js?v=162`)
Reportado por el usuario con capturas reales de 2 expedientes: **Jose Martin
Saucedo Bautista vs Inmobiliaria Pabruem/Grupo Gabssa** (Conciliación
prejudicial Primer citatorio con "Fecha agendada" = 04/12/2026, pero el
Segundo citatorio ya estaba agendado para el 02/12/2026 -- ANTES que el
primero, imposible) y **Orlando Herrera Cruz vs Trabal** (Primer citatorio
con "Fecha en que se celebró" = 26/11/2026, exactamente HOY). "La ia agendo
fechas inexistentes... revisa porque hay varios expedientes asi".
- **Bug 1 (el de Jose Martin)**: `etapa_fecha_es_consistente()`
  (`api/expediente_helpers.php`), el candado que debe impedir que un
  mecanismo automático meta una fecha fuera de orden, solo comparaba
  contra el campo `fecha` (celebrada) de las demás etapas -- nunca contra
  `fecha_programada` (agendada). `cron_leer_google_calendar.php` es el
  ÚNICO de los 5 mecanismos que llena `fecha_programada` (nunca `fecha`),
  así que podía agendar un citatorio DESPUÉS del que por ley debe
  seguirlo sin que nada lo atrapara. **Corregido**: si una etapa no tiene
  celebrada, su agendada sirve como su mejor fecha conocida para esta
  comparación de orden (la celebrada, hecho confirmado, sigue ganando si
  hay ambas).
- **Detector retroactivo del panel "Posibles irregularidades" ampliado
  igual** (`detectar_irregularidades_orden_cronologico()`, usado por
  `api/cron_qa_irregularidades.php`, corre 1 vez por semana) -- para
  encontrar casos YA guardados como el de Jose Martin se necesitaron 3
  comparaciones por separado (probado con casos reales antes de subir):
  celebradas entre sí (la de siempre), agendadas entre sí (la que
  faltaba -- si "primer citatorio" ya tiene celebrada, una agendada
  vieja/huérfana que le quedó se esconde si solo se compara "la mejor
  fecha conocida" de cada etapa), y combinada (celebrada si existe, si no
  agendada, para el caso cruzado: una etapa con SOLO agendada contra otra
  con SOLO celebrada). Sin duplicar texto cuando las 3 coinciden.
  `api/cron_qa_irregularidades.php` ahora también selecciona
  `fecha_programada` de `expediente_etapas`.
- **Bug 2 (el de Orlando Herrera), el más probable para "varios
  expedientes" con fecha de HOY**: el atajo ya documentado el 12 nov 2026
  ("checkbox marcado + fecha vacía = márcala hecha HOY sin fecha exacta",
  pensado para etapas SIN concepto de "Fecha agendada" propia, como
  `emplazamiento`) se aplicaba también a audiencias/citatorios de
  conciliación -- que SÍ tienen su propio campo de fecha agendada y donde
  la fecha exacta sí importa. Si el checkbox de una de estas filas
  quedaba marcado (por ejemplo al revisar el expediente) sin que nadie
  escribiera la fecha exacta de celebración, el botón "Guardar etapas"
  (que guarda TODAS las filas del formulario, no solo la que se estaba
  editando) inventaba "hoy" como si el citatorio se hubiera celebrado ese
  día -- coincide exacto con el caso de Orlando Herrera Cruz. **Corregido**
  en `assets/app.js`: para `audiencia_preliminar`, `audiencia_juicio`,
  `conciliacion_primer_citatorio` y `conciliacion_segundo_citatorio`, la
  fecha celebrada solo se guarda si de verdad se escribió -- marcar el
  checkbox sin fecha ya no fabrica ninguna.
- **Principio reafirmado por el usuario el mismo día, tras ver la causa**:
  "ninguna fecha debe marcarse sola o la mete el abogado o la mete la
  ia" -- se generalizó el arreglo del bug 2 a TODAS las etapas (no solo
  audiencias/citatorios) y se corrigió el MISMO patrón en "Cobros"
  (`readPagosFromDOM()`): marcar el checkbox "Cobrado" sin escribir la
  fecha de cobro real también rellenaba la fecha de hoy sola -- ya no.
  Consecuencia esperada: un pago ya marcado "Cobrado" sin fecha de cobro
  escrita deja de contarse en "Ingresos por periodo" (que agrupa por
  fecha de cobro) hasta que alguien escriba la fecha real -- correcto,
  mejor que seguir contando una fecha inventada.
  **Revisados y dejados igual a propósito** (son una acción explícita de
  un tap, no un efecto colateral de guardar otra cosa -- el abogado SÍ la
  está metiendo, en el momento en que da clic): los botones "✓" de
  Agenda general (`action==='pago'`/`'demanda'`, en `assets/app.js`)
  siguen poniendo la fecha de hoy al marcar un pago cobrado o una demanda
  presentada desde ahí -- si el usuario también los quiere sin ningún
  valor por defecto (forzando escribir la fecha exacta siempre), avisar
  para quitarlos también.
  `app.js?v=163`.
- **Limpieza retroactiva sin revisar expediente por expediente, pedida
  explícita del usuario ("como corregimos lo que metio mal... eso me
  llevaria mucho tiempo")**: nuevo reporte de diagnóstico
  `api/reporte_fechas_celebradas_sospechosas.php` (abrir directo en el
  navegador, logueado como Administrador, mismo patrón que
  `reporte_monitoreo_faltante.php`) -- busca la FIRMA exacta del bug 2 en
  `expediente_historial`: una fecha celebrada guardada por un humano (no
  por un mecanismo de IA, esos dejan `usuario_id NULL` y siempre citan
  evidencia literal) que coincide EXACTO con el día en que se guardó, y
  que SIGUE teniendo esa misma fecha ahora mismo (si ya se corrigió, no
  sale). Marca "sospecha ALTA" cuando en ese mismo guardado se tocaron 2+
  etapas a la vez (la firma de "Guardar etapas" guardando el formulario
  completo por otra razón) -- "sospecha media" cuando fue solo esa etapa
  (puede ser legítima, el abogado confirmándola el mismo día que pasó de
  verdad). Nunca corrige nada solo.
  - **El bug 1 (citatorios fuera de orden) ya tiene su propia limpieza
    masiva lista**: correr `api/cron_qa_irregularidades.php` una vez
    (mismo patrón, abrir la URL) -- con el candado ya ampliado, va a
    listar TODOS los expedientes con este problema en el panel "Posibles
    irregularidades" (Preguntas al abogado), cada uno con un tap
    "Corregir"/"Está bien no es error", sin tener que revisar los demás
    uno por uno.
  - **El bug de Cobros (fecha_cobro = hoy) NO tiene limpieza retroactiva
    posible** -- `expediente_pagos` se borra y reinserta completo en cada
    guardado (sin fecha de creación por fila) y su historial solo guarda
    un resumen ("N pagos, M cobrados, total $X"), sin el detalle de qué
    pago ni qué fecha -- no hay con qué reconstruir cuáles se vieron
    afectados. De aquí en adelante ya no puede volver a pasar (ver
    arriba), pero lo ya guardado con fecha de cobro = hoy sin que el
    usuario recuerde haberlo confirmado ese día hay que revisarlo a mano,
    si le preocupa, en la pestaña "Cobros" de cada expediente.
  Archivo nuevo a subir: `api/reporte_fechas_celebradas_sospechosas.php`.
  **Primera corrida real (26 nov 2026)**: encontró **45 etapas
  sospechosas (12 de sospecha ALTA)**, casi todas de la Lic. Lindsay Luna
  Maldonado -- confirma que el bug 2 era un hábito de trabajo real (marcar
  el check de una etapa que sí pasó pero sin escribir su fecha exacta, y
  guardar el formulario completo) repetido en MUCHOS expedientes durante
  semanas, no solo los 2 casos que el usuario reportó primero. Reporte
  mejorado el mismo día para agrupar por expediente (antes salía
  ordenado por nivel de sospecha nada más, saltando de expediente en
  expediente) -- ahora se abre cada expediente una sola vez y se revisan
  ahí mismo todas sus filas sospechosas juntas; los expedientes con al
  menos 1 fila ALTA salen primero.
  - **Resuelto**: confirmado subido `api/expediente_helpers.php` y
    corrida de nuevo -- siguió en "0 novedades nuevas". Conclusión: el
    usuario ya había corregido a mano la fecha del caso original (Jose
    Martin Saucedo Bautista) mientras lo revisaba, y no hay NINGÚN otro
    expediente activo con citatorios/audiencias agendados fuera de orden
    ahora mismo -- el candado funciona, simplemente no había más casos de
    ESE tipo específico que limpiar.
  - **Limpieza de las 12 de sospecha ALTA, pedida explícita del usuario
    ("si")**: script de un solo uso
    `api/_una_vez_limpiar_fechas_inventadas_26nov.php` (mismo patrón que
    `_una_vez_reprocesar_calendario_legacy.php` del 11 nov -- se corre una
    vez abriendo la URL, se puede borrar después). Repite la MISMA
    detección del reporte pero solo para `tamano_lote >= 2`, y en vez de
    solo mostrarlas, **borra la fecha celebrada** de esas etapas (deja la
    etapa en blanco -- nunca pone una fecha nueva, nadie sabe cuál es la
    real) -- si la fila queda completamente vacía se borra entera, igual
    que hace `expedientes_update_etapas.php`. Vuelve a comparar contra el
    valor actual antes de tocar nada (si ya cambió desde que se generó el
    reporte, no lo toca). Queda registrado en Historial de cambios de
    cada expediente como "Corrección automática (fecha probablemente
    inventada, revisión 26 nov 2026)" -- auditable, y si alguien sabe la
    fecha real después, se recaptura desde "Etapas del juicio" sin
    problema. **Riesgo residual explicado al usuario antes de correrlo**:
    en teoría podría borrar una fecha que sí era real si por coincidencia
    el abogado de verdad confirmó 2+ trámites genuinos el mismo día real
    -- posible pero más raro que el patrón del bug. Las 33 de sospecha
    media NO se tocan aquí, quedan para revisión manual del usuario.
    **Confirmado corrido por el usuario (26 nov 2026)**: "12 etapa(s) de
    sospecha ALTA encontrada(s), 12 limpiada(s) de verdad" -- coincide
    exacto con las 12 del reporte, las 12 quedaron en blanco en sus
    expedientes. El script ya cumplió su función, el usuario lo puede
    borrar del servidor. **Pendiente**: las 33 de sospecha media del
    reporte siguen sin revisar -- quedan para que el usuario las repase
    con calma (o para una sesión futura si pide ayuda con eso).
- Sin cambios de base de datos. No se tocó `mergeTags()`/tags de demanda
  -- no aplica `verificar_tags.js`. **Pendiente del usuario**: subir
  `api/reporte_fechas_celebradas_sospechosas.php` y abrirlo en el
  navegador para la lista de fechas celebradas sospechosas; correr
  `api/cron_qa_irregularidades.php` una vez para la lista de citatorios
  fuera de orden; y revisar a mano "Etapas del juicio" de los 2
  expedientes reportados originalmente (decidir qué fecha es real en
  cada citatorio).

## Conversación nueva "negocio" (16 nov 2026)
El usuario abrió una 4ª conversación, **"negocio"**, para hacer crecer el
negocio (lives de TikTok → bot de WhatsApp → asesoría pagada → juicio). Su
primer proyecto es un **embudo con estadísticas por etapa**: conectados en el
live → conversaciones nuevas de WhatsApp → pagaron (asesoría $499 /
documento $49 / curso $299) → se volvieron juicio.
- Ya existe un embudo parcial: `api/whatsapp_embudo.php` + su tabla en
  `assets/app.js` (mensajes/números por día → prospectos → contactados →
  convertidos). Le falta: estadísticas del live (TikTok no las da por API,
  hay que capturarlas a mano), pagos y el paso de asesoría a juicio.
- El paso de asesoría a juicio no está ligado directo: `expedientes.telefono` es
  texto libre; hay que cruzarlo por los últimos 10 dígitos contra
  `citas_asesoria.telefono` (o usar `prospectos.expediente_id` cuando se
  convirtió desde Prospectos).
- **Construido (16 nov 2026, `app.js?v=157`, migración 073)**: pantalla
  nueva **"Embudo del negocio"** en el menú (solo Administrador,
  `VIEW='negocio'`, `negocioEmbudoHTML()`/`bindNegocioEmbudo()`).
  Endpoint `api/negocio_embudo.php` (periodo desde/hasta): espectadores
  únicos del live → conversaciones NUEVAS de WhatsApp (primer mensaje
  histórico de ese número) → personas que pagaron asesoría
  (`citas_asesoria` confirmadas por `pagado_en`) → juicios (expediente con
  el mismo teléfono, últimos 10 dígitos, dado de alta el día del pago o
  después; también cuenta `prospectos.expediente_id`). Además, documentos
  $49 y cursos como ingreso. Las estadísticas del live se capturan a mano
  por día (tabla `tiktok_lives`, migración **073_tiktok_lives_estadisticas.sql**,
  `api/tiktok_lives_guardar.php` upsert/borrar), con los mismos campos que
  TikTok Live Center muestra por día. Confirmado por el usuario: TODOS los
  que escriben por WhatsApp vienen del live (no filtrar ni preguntar el
  origen) y en el sistema siempre se captura el teléfono del cliente en el
  expediente. Bug real atrapado en la prueba: una clave de arreglo PHP con
  solo dígitos se vuelve int -- por eso la clave de cruce es 't'+10 dígitos.
  **Ajuste el mismo día (`app.js?v=158`), pedido del usuario** ("algunos
  clientes solicitan asesorías varias veces"): la etapa 2 ahora cuenta a
  TODAS las personas que escribieron en el periodo (con desglose nuevas /
  ya habían escrito) y la etapa 3 cuenta CADA asesoría pagada (`pagos`), con
  desglose de personas, cuántas ya habían pagado antes del periodo y cuántas
  pagaron 2+ veces en él. Los porcentajes de conversión comparan persona
  contra persona. El usuario es el único Administrador; la página se queda
  con `require_admin()`.
  **Jornada del live (16 nov 2026, `app.js?v=159`), pedido del usuario**: el
  live empieza ~8 pm, la gente escribe durante el live y al día siguiente (el
  bot contesta fuera de horario desde las 8 am) y paga al día siguiente. Por
  eso WhatsApp y pagos se asignan a la jornada de 20:00 del día D a 19:59 del
  día D+1 (`NEGOCIO_HORA_CORTE_LIVE`, `negocio_jornada()`) y se comparan con el
  live del día D. OJO: por eso los totales del embudo NO coinciden exacto con
  "Ingresos por periodo" (que corta a medianoche).
  **Arrastre de fin de semana (`app.js?v=160`), pedido del usuario**: "los
  lives del viernes generan conversiones en sábado y domingo". Regla
  general: todo lo que llega entre un live y el siguiente es del live
  anterior -- si la jornada D no tiene live capturado, se le cuenta al
  último live capturado hasta `NEGOCIO_DIAS_ARRASTRE` (3) días atrás
  (`negocio_dia_live()`); más allá, se queda en su día. La búsqueda se
  extiende 3 días después de 'hasta' (`$rangoBusqueda`). Consecuencia:
  un live NO capturado se suma al anterior -- hay que capturarlos todos.
  **Canal de llegada (`app.js?v=161`, YA HECHO pero NO ENTREGADO al usuario
  todavía -- pidió dejarlo para cuando tenga más canales; por ahora solo usa
  TikTok)**: `negocio_canal()` clasifica el PRIMER mensaje de cada número
  (MIN(id)) según el texto prellenado del link de WhatsApp de cada canal;
  panel "¿De dónde llegan?" (solo filas con gente). Número del bot:
  **56 5273 6263**. Links acordados: `https://wa.me/525652736263?text=` +
  "Hola, quiero orientación gratis" (perfil TikTok/lives) / "Hola, vi su
  video" (videos FB/IG/YT) / "Hola, vengo de su página web" / "Hola, los
  encontré en Google". La página expertoslaborales.com mostraba 55 4170 8184;
  el usuario la va a cambiar para que todo mande al WhatsApp del bot.
  Revisión de normas de TikTok (versión vigente 13-sep-2025): no prohíben el
  teléfono del propio negocio; SÍ exigen marcar "contenido comercial → Tu
  marca" al promocionar el propio servicio (si no, sale del "Para ti"); se le
  sugirió decir "orientación laboral GRATIS" (la asesoría telefónica cuesta
  $499, evitar que se reporte como engañoso).
  Confirmado con SQL (16 nov 2026): hay UN solo usuario con rol
  administrador (id 1, "Administrador", admin@expertoslaborales.com) -- el
  usuario. Por eso `require_admin()` basta para "solo yo lo veo".
  **Migración 073 ya corrida por el usuario en `c1361311_exp` (16 nov 2026).** Captura del live **confirmada funcionando en vivo** (16 nov 2026; al principio falló porque `api/tiktok_lives_guardar.php` no se había subido -- "File not found"). Falta revisar con el usuario que los números del embudo (sobre todo "se volvieron juicio", que salió 0 en noviembre) cuadren con la realidad. Siguiente migración libre: 074.
- **Material de marketing (16 nov 2026)**: fotos del Lic. Rubén para
  miniaturas/promoción en `marketing/fotos/` (`ruben_traje_negro.jpg`,
  `ruben_traje_azul.jpg`, `ruben_selfie_cerca.jpg` -- selfie en espejo; usar la versión volteada `ruben_selfie_cerca_derecha.jpg`, y su recorte en la miniatura deja fuera las fotos familiares de la pared --, pedido explícito del usuario: "guárdalas para
  usarlas"). Plantilla de miniatura de YouTube 1280x720 en
  `marketing/miniaturas/plantilla_te_despidieron.html` (se renderiza con
  Playwright a PNG; fuente Inter Display Black instalada en el contenedor;
  logo sacado de `LOGO_DESPACHO_B64` de app.js). El usuario está subiendo
  sus lives completos a YouTube y probando OpusClip (opus.pro, 1 crédito =
  1 min de video) para sacar clips. No se pudo instalar `rembg` para
  recortar el fondo de la foto (lo bloqueó el permiso automático), se usó
  la foto con su fondo de oficina desvanecido. Miniaturas por tema
  (aguinaldo, cuánto me toca, renuncia, corrieron de palabra) en
  `generar_miniaturas.js` -- para CLIPS cortos de un solo tema. Para los
  lives completos (el usuario contesta preguntas de todo, sin tema único)
  se usa la serie numerada "LIVE #N" (`generar_serie_lives.js DESDE HASTA`,
  ya generadas #1-#6) con título "Asesoría laboral GRATIS en vivo #N |
  Respondo tus preguntas: despido, liquidación, aguinaldo y más".
  **REGISTRO de miniaturas (pedido del usuario, 16 nov 2026)**:
  `marketing/miniaturas/registro.json` (+ `registro.md` legible) guarda por
  cada número de LIVE su combinación (color, frase, juego de preguntas,
  foto) y el video de YouTube donde se usó. El generador elige la siguiente
  combinación NO usada, distinta del live anterior en color/foto/frase/
  preguntas; un número ya registrado se redibuja idéntico. **Flujo acordado:
  el usuario avisa cada vez que sube un video -> correr
  `cd marketing/miniaturas && node generar_serie_lives.js "$(pwd)" N`,
  mandarle `live_N.png`, y anotar el video en `registro.json` ("video").**
  Entregadas #1-#15 (el usuario tenía ~15 lives listos para subir). El generador elige los componentes MENOS usados y evita repetir color/foto/frase/preguntas con los 2 lives anteriores (la 1a versión solo alternaba azul/rojo, corregido).
- **Métricas de redes** (YouTube/TikTok): `marketing/metricas_redes.md`,
  punto de partida 16 nov 2026 (YouTube @rubenbuerhend: 24 suscriptores, 3
  videos; TikTok: 104.1K seguidores). Agregar fila con cada captura nueva
  que mande el usuario, para comparar contra ese día.
- Si otra conversación toca `whatsapp_embudo.php`, el prompt del bot para
  saber de dónde llega cada cliente, o la tabla del embudo en app.js, avisar
  a "negocio" (session_01C3deJav44xscTHSyeunJhm).

## Botón "Letra más grande" en el sidebar (15 nov 2026, `app.js?v=156`)
Pedido explícito del usuario: usa lentes y las letras de la app en el
celular se le ven muy chicas.
- **Por qué no se reescribieron los tamaños de letra existentes**: la app
  tiene cerca de 380 tamaños de letra fijos en px (80 en `style.css`, ~300
  como estilo inline dentro de los template strings de `app.js` -- botones,
  tarjetas, badges, etc.). Reescribirlos uno por uno habría sido un cambio
  enorme y arriesgado para una sola sesión, y los inline ganan siempre a
  cualquier regla nueva de `style.css` por especificidad, así que agrandar
  solo la hoja de estilos no habría movido la mayoría del texto real.
- **Solución: un toggle que aplica `zoom` (no `transform:scale()`) sobre
  `<body>`** -- a diferencia de `transform:scale()`, que rompe los
  elementos `position:fixed` (modales, overlay) al no reposicionarlos
  correctamente, `zoom` hace que el navegador recalcule TODO el layout
  como si cambiara la densidad de pixeles -- agranda cualquier tamaño de
  letra (fijo en px o no, inline o de la hoja de estilos) sin tocar
  ninguno de los ~380 existentes. Soportado en Safari/Chrome/Edge desde
  hace años (el público de esta app, celulares) y en Firefox desde 2024.
- Botón nuevo "🔎 Letra más grande" en el pie del menú lateral (junto a
  "Activar notificaciones"/"Cambiar de usuario", solo visible para
  abogados/Administrador -- la vista de cliente no tiene ese menú).
  Preferencia por DISPOSITIVO, no por cuenta -- se guarda en
  `localStorage` (`letraGrande`), nunca en el servidor; se aplica de
  inmediato al cargar la app (`aplicarLetraGrande()`, variable
  `LETRA_GRANDE`) y al darle clic al botón.
- `assets/style.css`: regla `body.letra-grande{zoom:1.3;}` (subido de
  1.18 a 1.3 el mismo día, pedido explícito del usuario: "si puede ser
  más grande la letra sería genial").
- **Trade-off avisado, no oculto**: con el zoom activado, tablas con
  muchas columnas pueden necesitar scroll horizontal (se agranda todo por
  igual, incluyendo el ancho real que ocupa cada cosa) -- aceptable a
  cambio de letra más grande, pero si molesta mucho avisar para ajustar
  el nivel de zoom (fácil de subir/bajar en un solo lugar).
- **Bug real, 2 intentos hasta encontrar la causa exacta**: el menú
  lateral en celular es `position:fixed` con `height:100vh` (ver CSS de
  `.sidebar` dentro del `@media (max-width:900px)`) -- el `zoom` del body
  también agranda ese alto fijo, así que la caja del menú termina siendo
  más alta que la pantalla real, y la parte de abajo (los botones del
  pie, incluyendo el propio botón de "Letra más grande") queda fuera de
  la vista.
  1. Primer intento: `overflow-y:auto` en `.sidebar` -- **no corrigió
     nada, confirmado real por el usuario** (seguía sin ver los botones
     del pie, se veía hasta "Días inhábiles" -- el último botón de
     `.nav` -- y ya no el pie). Causa de que no funcionara: el contenido
     del menú (brand+nav+pie) ya cabe EXACTO dentro de su propia caja de
     100vh -- no hay ningún "overflow" real de contenido que
     `overflow-y:auto` pueda detectar; es la CAJA COMPLETA la que el
     `zoom` deja más grande que la pantalla física, no su contenido
     respecto a ella misma. `overflow-y:auto` solo reacciona a contenido
     que rebasa su propia caja, nunca a que la caja misma rebase el
     viewport.
  2. **Corregido de verdad**: el menú lateral nunca se agranda -- se le
     aplica el zoom inverso exacto (`1 / 1.3`, variable CSS `--letra-zoom`
     para que ambos valores no se desalineen nunca) para que, multiplicado
     por el 1.3 heredado del body, el resultado neto sea 1 (tamaño normal
     de siempre). El menú se queda con su letra de tamaño normal (nombres
     de pantallas cortos, no es el texto que le cuesta leer al usuario) y
     así nunca se topa con el problema del alto fijo -- todo lo demás
     (tablas, tarjetas, modales, formularios) sí se agranda igual.
- Sin cambios de base de datos. No se tocó `mergeTags()`/tags de demanda
  -- no aplica `verificar_tags.js`. **Confirmado en vivo (15 nov 2026)**:
  el usuario probó esta versión y confirmó "quedó muy bien" -- el menú ya
  se veía completo y el resto de la app más grande.
- **Mismo día, 2 ajustes más pedidos explícitamente por el usuario**: (1)
  "¿se puede hacer también la letra del menú más grande sin que se
  esconda nada?" y (2) "si puedes subir un poco más el tamaño general,
  mejor". Resueltos juntos:
  - Zoom general subido de 1.3 a 1.4 (variable `--letra-zoom`, un solo
    lugar).
  - El menú lateral YA estaba fuera del `zoom` (ver arriba, para no
    reventar su alto fijo) -- se agranda aparte con `font-size` real en
    los ~8 selectores que lo componen (`body.letra-grande .nav button`,
    `.sidebar-foot`, `.switch-user`, etc.). A diferencia de `zoom`,
    `font-size` NO afecta el `height:100vh` fijo de `.sidebar` -- así que
    nunca puede reproducir el mismo bug: si el contenido crece de más,
    `.nav` (que ya tenía su propio scroll interno desde siempre) se
    encoge y se puede deslizar, y `.sidebar-foot` (el pie, fuera de ese
    scroll, sin `min-height:0`) siempre termina de verse completo abajo
    -- comportamiento normal de flexbox, sin el truco de `zoom` de por
    medio. Solo hicieron falta esos ~8 selectores porque el menú, a
    diferencia del contenido principal (los ~300 inline en app.js), no
    tiene NINGÚN tamaño de letra puesto inline -- todo vive en
    `style.css`.
  - Sin cambios de base de datos, sin tocar `app.js`/`mergeTags()` -- no
    aplica `verificar_tags.js`. **Sin probar en vivo todavía** -- falta
    que el usuario suba `assets/style.css` (`v=10`), `index.html` y
    `sw.js` (`v78`), y confirme que el menú ahora SÍ se vea con letra más
    grande sin esconder ningún botón, y que el 1.4 general se sienta
    mejor que el 1.3 anterior.
- **Mismo día, 3er ajuste pedido explícito del usuario**: "quiero la
  letra mas grande y que el tamaño del texto y del menu sea del mismo
  tamaño". Zoom general subido de 1.4 a 1.5 (mismo `--letra-zoom`, un
  solo lugar). Los 8 valores fijos del menú (19px/20px/13px/16px...) se
  habían puesto a ojo, aproximando el tamaño del resto de la app sin ser
  el mismo cálculo exacto -- ahora cada uno se deriva con
  `calc(<tamaño_normal_del_selector> * var(--letra-zoom))`, EXACTAMENTE
  el mismo multiplicador que aplica el `zoom` sobre el resto de la app,
  así quedan matemáticamente iguales en vez de solo parecidos. Ventaja de
  paso: si se vuelve a pedir subir/bajar el zoom general, el menú se
  reescala solo, sin recalcular estos 8 valores a mano otra vez.
- Sin cambios de base de datos, sin tocar `app.js`/`mergeTags()` -- no
  aplica `verificar_tags.js`. **Sin probar en vivo todavía** -- falta que
  el usuario suba `assets/style.css` (`v=11`), `index.html` y `sw.js`
  (`v79`), y confirme que el menú y el contenido principal ya se vean del
  mismo tamaño, y que el 1.5 general se sienta bien.
- **Subido a 1.6 el mismo día** (pedido explícito, "subelo a 1.6") --
  gracias al `calc()` de arriba, solo hizo falta cambiar `--letra-zoom`
  en un solo lugar; el menú se reescala solo con el mismo valor. `v=12`
  / `sw.js v80`. **Sin probar en vivo todavía**.

## Eventos de Google Calendar "sin identificar" ya se muestran (15 nov 2026, `app.js?v=155`)
Pedido explícito del usuario, mismo tema de "que nada se quede callado sin
que alguien lo note" -- `cron_leer_google_calendar.php` ya guardaba en
`google_calendario_sugerencias` cualquier evento que la IA NO pudiera
matchear con claridad a un expediente/etapa (`estado='sin_match'`, ver
`ia_matchear_evento_calendario()`), pero esto quedaba ahí sin que nadie lo
viera nunca -- ya estaba anotado como pendiente desde el 11 nov 2026.
- **`api/google_calendario_sugerencias_list.php`**: el `WHERE` pasó de
  `estado = 'pendiente'` a `estado IN ('pendiente', 'sin_match')`, y se
  agregó el nombre del abogado dueño del calendario (`au.nombre`, JOIN a
  `usuarios` vía `google_calendarios_vigilados.abogado_id`) -- para
  `sin_match` no hay expediente que mostrar, así que se identifica por
  abogado+calendario en su lugar.
- **`api/google_calendario_sugerencia_resolver.php`**: el chequeo de
  "¿ya se resolvió?" ahora acepta también `estado='sin_match'` (antes solo
  `pendiente`) -- siempre con `aplicar=false` (nunca hay expediente/etapa
  que aplicar en un `sin_match`, el candado que ya existía para eso lo
  sigue exigiendo sin cambios).
- **`assets/app.js`**: `googleCalendarioSugerenciaCardHTML()` ahora
  distingue por `estado` -- un `sin_match` muestra abogado+calendario+
  título del evento con solo el botón "Descartar" (sin "Usar la fecha de
  Google Calendar", porque no hay etapa sugerida). `preguntasAbogadoHTML()`
  separa los dos estados en 2 paneles distintos ("📅 Conflictos de fecha"
  y "🤔 Eventos de Google Calendar sin identificar") dentro de la misma
  vista "Preguntas al abogado" -- visible al abogado dueño de ese
  calendario (no solo al Administrador), porque es quien mejor puede
  reconocer si es una cita personal a ignorar o un expediente mal escrito.
  El badge de "Preguntas al abogado" ya sumaba `GOOGLE_CALENDARIO_
  SUGERENCIAS.length` completo, así que ya cuenta los `sin_match` sin
  tocar nada ahí.
- Sin cambios de base de datos (`estado='sin_match'` ya existía en el ENUM
  desde la migración 067). No se tocó `mergeTags()`/tags de demanda -- no
  aplica `verificar_tags.js`. **Sin probar en vivo todavía** -- falta que
  el usuario suba `api/google_calendario_sugerencias_list.php`,
  `api/google_calendario_sugerencia_resolver.php`, `assets/app.js`,
  `index.html` y `sw.js`, y se confirme si ya había algún `sin_match`
  acumulado desde el 11 nov esperando mostrarse.

## Push diario ya no deja de avisar prescripción/amparo/pago vencidos (15 nov 2026, `app.js?v=154`)
Mismo tema de "hacer el sistema más inteligente/autosuficiente" -- al
revisar `AVISOS_ABOGADO_TIPOS_ABIERTOS` (la lista agregada el 9 nov 2026
para que `atraso`/`accion_ia` se siguieran avisando aunque llevaran días
vencidos) se confirmó que el MISMO bug exacto nunca se corrigió para
`prescripcion`, `amparo` y `pago`: esos 3 exigían `fecha >= hoy` para
seguir sincronizándose al caché de push
(`api/agenda_sync_avisos.php`/`syncAvisosAbogado()`), así que un plazo de
prescripción/amparo de verdad vencido (demanda/amparo nunca presentado a
tiempo) o un pago de convenio no cobrado dejaban de avisar por push al
día siguiente de vencer -- justo el momento en que más urge que alguien
lo note -- aunque siguieran viéndose como vencidos en la Agenda general
en pantalla. Pedido explícito del usuario: agregar estos 3 (dejó
`pendiente` fuera a propósito).
- `assets/app.js`: `prescripcion`, `amparo` y `pago` agregados a
  `AVISOS_ABOGADO_TIPOS_ABIERTOS`.
- `api/cron_recordatorio_abogado.php`: mismos 3 tipos agregados a
  `TIPOS_ABIERTOS` (push diario + escalación al Administrador tras 7+
  días sin resolverse). La lista de tipos abiertos en el SQL ahora se
  arma directo de la constante `TIPOS_ABIERTOS` (`$pdo->quote()` por
  cada uno) en vez de repetirse literal en el WHERE -- evita que las 2
  copias se desalineen de nuevo por descuido, justo lo que pasó con este
  bug (la constante PHP y el SQL llevaban 6 días desalineados sin que
  nadie lo notara).
- Sin cambios de base de datos. No se tocó `mergeTags()`/
  `calcularJornada()`/tags de demanda -- no aplica `verificar_tags.js`.
  **Sin probar en vivo todavía** -- falta que el usuario suba
  `assets/app.js`, `index.html`, `sw.js` y
  `api/cron_recordatorio_abogado.php`, y se confirme con un caso real
  (una prescripción/amparo/pago ya vencido hace más de 1 día) que el push
  diario lo sigue avisando en vez de quedarse callado.

## Nuevo detector: cita/citatorio agendado cuya fecha ya pasó sin resultado (15 nov 2026, `app.js?v=153`)
Pedido explícito del usuario ("es que debemos de hacer muy inteligente y
autosuficiente el sistema que sugieres") tras los 2 arreglos de hoy sobre
la fecha agendada -- revisando el código se confirmó un hueco real de
fondo: `estancadoInfo()`, el ÚNICO detector de "atraso" que existía en
toda la Agenda (alimenta el push diario, la pregunta del supervisor, y el
Informe Ejecutivo indirectamente), **nunca mira `fecha_programada`** --
solo calcula si ya venció el plazo legal completo de la *siguiente* etapa
sin fecha, contado desde la última etapa YA celebrada. Peor aún: varias
etapas agendables (`conciliacion_primer_citatorio`,
`conciliacion_segundo_citatorio`) **ni siquiera tienen un `plazoDias`
propio en `ETAPAS_DEF`** -- son eventos agendados, no plazos legales --
así que para el caso real de Lizbeth Diaz Almazo (citatorio agendado para
el 12 nov, ya vencido 3 días) el sistema NO iba a generar ningún
atraso/push/pregunta del supervisor hasta que venciera el plazo completo
de 45 días naturales de la conciliación (30 nov) -- 2 semanas después de
que alguien debía haber confirmado qué pasó en esa cita. Solo se notó hoy
porque el usuario abrió el Informe Ejecutivo a mano.
- **Nueva función `citasAgendadasVencidasInfo()`** (`assets/app.js`):
  revisa las 4 etapas que soportan "Fecha agendada"
  (`conciliacion_primer_citatorio`, `conciliacion_segundo_citatorio`,
  `audiencia_preliminar`, `audiencia_juicio`) y devuelve las que ya
  pasaron su fecha agendada sin fecha celebrada ni fecha agendada más
  reciente -- nunca corrige nada solo, solo lo señala (mismo principio de
  siempre).
- `buildAgendaEntries()` genera una entrada tipo nuevo **`vencida`** por
  cada una encontrada -- clave `vencida:<etapa_key>`, DISTINTA de
  `atraso:<etapa_key>` a propósito (son señales independientes; un mismo
  expediente podría tener ambas a la vez sin pisarse). `vencida` se
  agregó a `AGENDA_TIPO` (mismo color rojo que "Posible atraso"),
  `AVISOS_ABOGADO_TIPOS`/`AVISOS_ABOGADO_TIPOS_ABIERTOS` (se sigue
  avisando mientras no se resuelva, igual que `atraso`/`accion_ia`, sin
  importar cuántos días lleve atrás) y `GOOGLE_TIPOS_SINCRONIZABLES`.
- `api/cron_recordatorio_abogado.php`: `vencida` se trata igual que
  `atraso`/`accion_ia` en el push diario (se avisa `fecha <= hoy`, no
  solo "mañana") y en la escalación al Administrador tras 7+ días sin
  resolverse (`TIPOS_ABIERTOS`).
- `api/cron_generar_preguntas_supervisor.php`: ahora también recoge
  entradas tipo `vencida` (antes solo `atraso`) -- el parseo de la clave
  se generalizó de `<etapa_key> = substr($clave, strlen('atraso:'))` a
  `substr($clave, strlen($tipo) + 1)` para que funcione con cualquier
  prefijo. Reutiliza sin cambios el aviso de "fecha agendada" agregado
  hoy mismo en `ia_generar_pregunta_supervisor()` (ya recibía
  `$etapaKeyAtraso` del arreglo anterior) -- así que para una entrada
  `vencida` la pregunta generada automáticamente se refiere a la fecha
  concreta ("¿qué pasó en la cita del 12 de noviembre?").
- **Sin migración** -- `agenda_avisos_cache.tipo` es `VARCHAR(30)` sin
  ENUM, y la clave más larga (`vencida:conciliacion_segundo_citatorio`,
  38 caracteres) cabe en el `VARCHAR(40)` de `clave` sin truncarse.
- No se tocó `mergeTags()`/`calcularJornada()`/tags de demanda -- no
  aplica `verificar_tags.js`. **Sin probar en vivo todavía** -- falta que
  el usuario suba `assets/app.js`, `index.html`, `sw.js`,
  `api/cron_recordatorio_abogado.php` y
  `api/cron_generar_preguntas_supervisor.php`, y se confirme en un caso
  real (ej. el propio expediente de Lizbeth Diaz Almazo, una vez que
  alguien registre qué pasó en el citatorio del 12 nov y se pruebe con
  otro expediente que SÍ siga con una cita vencida) que aparece como
  "Cita agendada vencida" en Agenda general y genera su propia pregunta
  del supervisor.

## Informe Ejecutivo no se enteraba de que la siguiente etapa YA tenía fecha agendada (15 nov 2026)
Reportado por el usuario viendo el expediente de **Lizbeth Diaz Almazo vs
Servicio Postal Mexicano**: el Informe Ejecutivo (generado 14 nov,
08:36pm) decía "han transcurrido ya 30 días sin que se registre el
primer citatorio de conciliación... requiere verificación inmediata del
estatus ante el Centro" -- pero la Agenda del propio sistema, visible en
el mismo expediente justo debajo, ya mostraba "📅 Audiencia agendada:
Conciliación prejudicial (primer citatorio) -- 12 nov 2026", agendada 2
días antes de generarse el informe.
- **Causa real**: `ia_siguiente_etapa_esperada_texto()`
  (`api/ia_helpers.php`, compartida por el resumen ejecutivo y el
  análisis de acuerdos/documentos) solo miraba el campo `fecha`
  (celebrada) de la siguiente etapa -- nunca `fecha_programada`
  (agendada) -- así que no tenía forma de saber que ya existía una fecha
  capturada para esa etapa, y redactaba como si nunca se hubiera
  agendado nada.
- **Corregido**: si la siguiente etapa ya tiene `fecha_programada`, se le
  avisa explícitamente a la IA -- si esa fecha ya pasó, la próxima acción
  es confirmar qué pasó en ella (se celebró, se reagendó, no se presentó
  la contraparte, etc.), nunca "verificar el estatus desde cero"; si
  todavía no llega, no se trata como atraso. `api/expediente_resumen_
  ejecutivo.php` ya seleccionaba `fecha_programada` desde que se
  construyó -- no hizo falta tocar ningún endpoint, el dato ya estaba
  disponible, solo no se usaba.
- Sin cambios de base de datos, sin tocar `app.js` -- no aplica
  cache-bust ni `verificar_tags.js`.
- **Confirmado funcionando en vivo (15 nov 2026)**: el Informe Ejecutivo
  de Lizbeth Diaz Almazo ya no pide "verificar el estatus desde cero" --
  ahora dice "se agendó el primer citatorio para el 12 de noviembre de
  2026, fecha que ya transcurrió hace 3 días sin que se haya registrado
  el resultado... es necesario confirmar qué ocurrió en esa audiencia",
  con próxima acción "Confirmar y registrar qué sucedió en el citatorio
  del 12 de noviembre (se celebró, se reagendó, o no se presentó la
  contraparte)".
- **Extendido el mismo día (15 nov 2026), pedido explícito del usuario**:
  revisando si el mismo hueco se repetía en otras funciones, se confirmó
  que SÍ -- `ia_auditar_expediente()` (auditoría integral, la única de
  las 4 funciones de contexto completo que SÍ escribe sola en
  `expediente_etapas`) y `ia_generar_pregunta_supervisor()` (la que
  redacta la pregunta cuando algo se ve atorado) tampoco sabían nada de
  `fecha_programada` -- mismo patrón de hueco disperso que ya se había
  repetido varias veces esta semana con notas/documentos/pendientes.
  Corregido en las 2: la auditoría ahora marca explícitamente cada etapa
  "sin fecha todavía" que ya tiene Fecha agendada (para no tratarla como
  completamente huérfana); la pregunta del supervisor recibe un
  parámetro nuevo `$etapaKeyAtraso` y, si esa etapa ya tiene Fecha
  agendada, se le indica a la IA que pregunte por ESA fecha concreta
  ("¿qué pasó en la cita del 12 nov?"), nunca como si no hubiera nada
  agendado. `api/cron_auditoria_expediente_ia.php` y `api/cron_generar_
  preguntas_supervisor.php` ahora seleccionan `fecha_programada` (ya
  existía en la tabla, solo no se leía). Archivos a subir además de
  `api/ia_helpers.php`: `api/cron_auditoria_expediente_ia.php`,
  `api/cron_generar_preguntas_supervisor.php`. **Sin probar en vivo
  todavía**.

## "Mi disponibilidad para asesorías" rediseñada: cuadrícula de casillas en vez de capturar rangos a mano (14 nov 2026, `app.js?v=148`)
Pedido explícito del usuario, viendo su propio horario capturado (bloques
de 11:00-17:00 y 17:00-18:00 por día, sin ningún hueco entre asesorías):
"hay que hacer más cómodo el selector de los horarios disponibles para
activar por horas y así darme espacio entre asesorías... un selector de
lunes a domingo y luego un selector por horas de 9 am a 7 pm".
- **Causa de fondo del problema que describía**: `citas_calcular_horarios_
  disponibles()` (`api/citas_helpers.php`) parte cada bloque guardado en
  citas de 1h pegadas una tras otra, empezando exacto en `hora_inicio` --
  un bloque "11:00-17:00" da 11,12,13,14,15,16 sin ningún espacio. La
  única forma de dejarse un hueco con la UI vieja ("Día" + "Desde"/"Hasta"
  + "Agregar horario", una fila a la vez) era capturar bloques sueltos a
  mano (11:00-12:00, luego 13:00-14:00...) -- tedioso e indirecto.
- **UI nueva**: cuadrícula de casillas, columnas = días (Lun-Dom), filas =
  horas de 9am a 7pm (`DISPONIBILIDAD_HORAS`, bloques fijos de 1h). Marcar
  una casilla = esa hora disponible; dejar una sin marcar entre 2 marcadas
  ya deja el espacio libre solo, sin tener que calcular ni capturar ningún
  rango. Un solo botón "Guardar horarios" al final, no una llamada por
  cada hora.
- **Nuevo endpoint `api/disponibilidad_guardar.php`**: reemplazo completo
  del horario semanal del usuario en sesión en una sola llamada (DELETE +
  re-INSERT, mismo patrón que `expedientes_update_pagos.php`/
  `expedientes_update_pendientes.php`) -- cada casilla marcada se guarda
  como su propio bloque `hora_inicio`/`hora_fin` de exactamente 1h. Los
  endpoints viejos `api/disponibilidad_agregar.php`/`disponibilidad_
  eliminar.php` ya NO los llama el frontend (quedan huérfanos en el
  servidor, mismo criterio que el panel viejo de captcha de Edomex --
  inofensivos sin uso, el usuario los puede borrar a mano si quiere).
- `disponibilidadHoraMarcada()` en `assets/app.js` calcula qué casillas
  mostrar marcadas al cargar la página -- también cubre bloques VIEJOS de
  varias horas seguidas que ya existieran antes de este rediseño (ej. un
  "11:00-17:00" guardado desde la UI vieja marca solo las 6 casillas que
  cubre, 11 a 16), así que el horario que el abogado ya tenía capturado se
  ve bien de entrada, sin perder nada. En cuanto le dé "Guardar horarios"
  una vez, ese bloque viejo se reemplaza por bloques limpios de 1h cada
  uno (igual de funcional para `citas_calcular_horarios_disponibles()`,
  que de cualquier forma ya cortaba todo en horas sueltas).
- Sin cambios de base de datos (`disponibilidad_asesorias` ya existía,
  incluso ya soportaba bloques de cualquier tamaño). No se tocó
  `mergeTags()`/tags de demanda -- no aplica `verificar_tags.js`.
  **Sin probar en vivo todavía** -- falta que el usuario suba
  `api/disponibilidad_guardar.php`, `assets/app.js`, `index.html` y
  `sw.js`, y confirme que su horario actual se vea bien marcado en la
  cuadrícula antes de guardar nada nuevo.

## Panel nuevo: Credenciales de portales por abogado (13 nov 2026, `app.js?v=147`)
Pregunta explícita del usuario revisando un caso real (**Escalona Jimenez
Yuliana Yadira vs New Dynacast de México y Automotive Quality Shield, Exp.
388/2026**, Tribunal Federal bien capturado -- "CUARTO TRIBUNAL LABORAL
FEDERAL DE ASUNTOS INDIVIDUALES..." en Naucalpan, Edomex): "por qué hay
asuntos en los que ya se guardó bien el tribunal y expediente... que aún
no baja el robot". Causa real encontrada: no existía ninguna forma de que
el Administrador viera, sin preguntarle a cada abogado uno por uno, quién
SÍ tiene guardada su cuenta de cada portal (PJF/Buzón CDMX/correo Edomex)
en "Mi cuenta" -- `pjf_credenciales_get.php`/`set.php` etc. solo dejaban
que cada quien viera/editara la SUYA, nada admin-visible agregado. Si el
abogado asignado a un expediente nunca guardó su usuario/contraseña de
ese portal, el robot correspondiente no tiene con qué entrar a revisar
NINGUNO de sus expedientes -- aunque el Tribunal/número estén perfectos.
- **Nuevo endpoint `api/credenciales_portales_listar.php`** (solo
  Administrador): junta de `usuarios` (activos, rol abogado/administrador)
  si cada uno tiene o no `pjf_usuario`/`edomex_correo_usuario`/
  `cdmx_buzon_usuario` capturado, más la fecha de la última vez que lo
  actualizó -- nunca la contraseña, solo el booleano + fecha.
- Panel nuevo en **"Equipo"**, entre "Actividad de la IA" y el panel de
  Validación de Tribunal: tabla con una fila por abogado y 3 columnas
  (Federal/Buzón CDMX/Correo Edomex), insignia "✓ guardada" (con la fecha)
  o "✗ falta".
- Sin cambios de base de datos (solo lee columnas que ya existían). No se
  tocó `mergeTags()`/tags de demanda -- no aplica `verificar_tags.js`.
  **Sin probar en vivo todavía** -- falta que el usuario suba
  `api/credenciales_portales_listar.php`, `assets/app.js` e `index.html`,
  y confirme si el caso de Escalona Jimenez de verdad era por esto (quién
  es su abogado asignado y si le falta alguna de las 3 credenciales).

## Cómo capturar acuerdos ANTERIORES que los robots todavía no tienen + candado de duplicados ampliado (13 nov 2026)
Pregunta explícita del usuario: "como le hacemos para que el robot revise
si hay acuerdos anteriores publicados en los diferentes sistemas de los
tribunales que aun no tengamos en los expedientes". Se revisó cómo cada
robot decide qué es "nuevo" -- 3 comportamientos distintos:
- **`robots/cdmx_buzon/cdmx_buzon.js`**: NO tiene este hueco. Cada corrida
  vuelve a abrir "Ver Acuerdos" y relee la lista COMPLETA de cada
  expediente que hace match (Tribunal+número), saltando solo los
  `acuerdoId` que ya guardó en `vistos{}` (`procesados_cdmx_buzon.json`).
  Cualquier acuerdo viejo todavía no capturado se va a reportar solo en la
  siguiente corrida diaria (6am), sin tocar nada -- mientras el Tribunal/
  número del expediente estén bien capturados (panel de validación de
  hoy mismo) y la cuenta del abogado en el Buzón sí vea ese expediente en
  su "LISTA DE BUZONES" (eso depende del Poder Judicial, no del robot).
- **`robots/federal/federal.js`** y **`robots/edomex_correo/edomex_correo.js`**:
  SÍ tienen el hueco. Ambos guardan un contador máximo por expediente/cuenta
  (`estado[key] = maxNo` en Federal -- número de acuerdo; `ultimoVisto` en
  Edomex -- id de conversación de correo) y en cada corrida SOLO revisan lo
  que quedó por encima de ese máximo. La PRIMERA vez que ven un expediente/
  cuenta (sin entrada todavía en `procesados_federal.json`/
  `procesados_edomex_correo.json`) sí barren TODO el historial disponible
  en ese momento -- pero una vez que ya quedó un máximo guardado, un
  acuerdo viejo que se haya escapado esa primera vez (por un error, o por
  haberse agregado el expediente al monitoreo después de que ya existía
  ese acuerdo) nunca se vuelve a revisar solo.
- **Candado de duplicados ampliado primero, antes de tocar nada de
  `procesados_*.json`** (`api/avisos_ingest.php`): el candado de "no
  insertar el mismo aviso 2 veces" solo comparaba contra avisos con
  `estado = 'nuevo'` (sin revisar todavía) -- si se forzaba un re-barrido
  histórico y un robot volvía a reportar un acuerdo que YA se había
  revisado/aplicado/descartado, se creaba un aviso DUPLICADO en vez de
  detectarse como ya existente. Se quitó esa condición -- ahora cualquier
  aviso ya existente (mismo expediente+fuente+resumen, sin importar su
  estado) siempre cuenta como duplicado. Esto hace SEGURO forzar un
  re-barrido histórico sin ensuciar el sistema de avisos repetidos.
- **Plan para forzar el re-barrido histórico de Federal/Edomex** (acción
  del usuario, en su máquina de Windows, no algo que se corrija con
  código): primero correr `api/reporte_monitoreo_faltante.php` (ya
  existe, abrir directo en el navegador logueado) para confirmar qué
  expedientes activos ningún robot está alcanzando todavía (normalmente
  Tribunal mal/sin capturado -- esa es la causa más común de huecos, hay
  que arreglarla primero). Después, para cada robot que se quiera forzar
  a re-barrer: borrar (o renombrar como respaldo) su archivo
  `procesados_federal.json`/`procesados_edomex_correo.json` en la carpeta
  del robot y correrlo una vez a mano -- con el candado ya ampliado, solo
  va a crear avisos para lo que de verdad falte, no para lo ya revisado.
  **Pendiente**: que el usuario suba `api/avisos_ingest.php` y confirme
  si quiere hacer ese re-barrido ahora o esperar a que primero se corrija
  la captura de Tribunal de los expedientes que salgan en el reporte de
  faltantes.
- Sin cambios de base de datos, sin tocar `app.js` -- no aplica cache-bust
  ni `verificar_tags.js`. **Sin probar en vivo todavía**.

## Auditoría de cobertura completa: las 3 funciones de IA que sintetizan "todo el expediente" ya leen pendientes y pagos de convenio, y la pregunta del supervisor ya lee notas (13 nov 2026)
Pedido explícito del usuario, generalizando el mismo arreglo de hoy (ver las
2 secciones de abajo, "Informe ejecutivo..." y "Auditoría integral..."):
"revisa que la ia revise absolutamente toda la info que este en el sistema
incluyendo notas, agenda, pendientes, documentos, boletines etc". En vez de
esperar a que apareciera un caso real afectado, se audutaron directamente las
3 funciones de `api/ia_helpers.php` que arman el contexto de "todo el
expediente" (`ia_generar_resumen_expediente()`, `ia_auditar_expediente()`,
`ia_generar_pregunta_supervisor()`) contra las fuentes de datos reales del
sistema (`expediente_notas`, `expediente_pendientes`, `expediente_pagos`,
`expediente_documentos`, `avisos_boletin`).
- **Hueco real encontrado**: ninguna de las 3 leía `expediente_pendientes`
  (pestaña "Pendientes", to-dos manuales) ni `expediente_pagos` (pestaña
  "Cobros", parcialidades de convenio) -- ambas quedaban fuera aunque son
  justo lo que el usuario nombró explícitamente ("agenda, pendientes").
  Además, `ia_generar_pregunta_supervisor()` (la que redacta la pregunta del
  supervisor para un atraso) todavía NO leía `expediente_notas` -- mismo
  hueco que ya se había cerrado hoy en las otras 2 funciones, aquí seguía
  abierto: podía redactar una pregunta sobre un dato que el abogado ya había
  contestado a mano en su bitácora de notas.
- **Corregido, mismo patrón ya usado todo el día**: las 3 funciones aceptan
  ahora 2 parámetros nuevos opcionales, `$pendientesAbiertos`/`$pagosConvenio`
  (`ia_generar_pregunta_supervisor()` además recibe `$notasBitacora`, que ya
  tenían las otras 2). Los 3 crons/endpoints que las llaman
  (`expediente_resumen_ejecutivo.php`, `cron_auditoria_expediente_ia.php`,
  `cron_generar_preguntas_supervisor.php`) ahora hacen 2 SELECTs más cada
  uno: `expediente_pendientes` (solo `cumplido = 0`, los que siguen
  abiertos) y `expediente_pagos` (últimos 10, con su estado de cobro).
  Mismo candado de siempre explícito en el prompt de `ia_auditar_expediente()`
  (la única que escribe sola en `expediente_etapas`): un pendiente sin
  cumplir o un pago sin cobrar NO confirma por sí solo que una etapa ya
  ocurrió, solo es contexto adicional que puede mencionar de pasada una
  fecha real en su descripción.
- Sin cambios de base de datos (`expediente_pendientes`/`expediente_pagos`
  ya existían). No se tocó `app.js` -- no aplica cache-bust ni
  `verificar_tags.js`. **Sin probar en vivo todavía** -- falta que el
  usuario suba `api/ia_helpers.php`, `api/expediente_resumen_ejecutivo.php`,
  `api/cron_auditoria_expediente_ia.php` y
  `api/cron_generar_preguntas_supervisor.php`, y se vea un caso real donde
  un pendiente/pago/nota haga la diferencia en alguno de los 3 textos.

## Informe ejecutivo ahora también lee documentos y avisos de boletín, no solo etapas/notas (13 nov 2026)
Pedido explícito del usuario ("la ia tiene que revisar todas las fuentes de
información para lograr exactitud y autonomía") tras ver el expediente de
**Mir Castro Ma Claudia vs Servicio Postal Mexicano, Exp. 242/2026**: el
Informe Ejecutivo decía "URGENTE: Presentar objeciones y réplica... que ya
debería estar presentada desde hace más de 110 días" -- pero el documento
más reciente del expediente (**Acuerdo No. 4**, ya con su resumen de IA
guardado) decía que el Tribunal había levantado DE OFICIO un incidente de
competencia y declarado los autos "en estado de resolución de dicho
incidente" -- el trámite está PAUSADO por eso, no atrasado por descuido.
- **Causa real**: `ia_generar_resumen_expediente()` (el Informe Ejecutivo)
  nunca recibía documentos ni avisos de boletín como contexto -- a
  diferencia de `ia_auditar_expediente()` (auditoría integral), que sí los
  lee desde que se construyó. Por eso podía insistir en una "próxima
  acción" urgente sin saber que un documento real ya explicaba por qué esa
  etapa sigue sin registrarse.
- **Corregido**: `ia_generar_resumen_expediente()` acepta ahora 2
  parámetros más, `$documentosRecientes`/`$avisosRecientes` (mismas
  consultas que ya usa `cron_auditoria_expediente_ia.php`, incluyendo
  excluir avisos `estado='descartado'` -- mismo candado del bug de Isabel
  Torres Arizmendi), con una regla dura nueva: si un documento/aviso
  menciona literalmente un incidente/recurso/suspensión/excusa/
  acumulación que pausa o altera la secuencia normal, la próxima acción
  debe ser dar seguimiento a ESE incidente, nunca insistir en el trámite
  de la etapa normal como si el abogado lo estuviera descuidando.
  `api/expediente_resumen_ejecutivo.php` junta y manda ambas listas.
- **Modelo subido a Sonnet SOLO en esta función** (`IA_MODELO_ANALIZAR_
  ACUERDO` en vez de la constante `IA_MODELO_RESUMEN_EXPEDIENTE`/Haiku que
  siguen usando las otras 2 llamadas que comparten esa constante) -- mismo
  criterio ya aplicado el mismo día a la auditoría integral: ahora
  sintetiza varias fuentes que pueden contradecirse, más que extracción
  simple.
- Sin cambios de base de datos, sin tocar `app.js` -- no aplica cache-bust
  ni `verificar_tags.js`.
- **Confirmado funcionando en vivo (13 nov 2026)**: el informe de Mir
  Castro Ma Claudia ya no dice "urgente, presenta objeciones y réplica" --
  ahora explica que la demandada promovió un incidente de competencia, el
  Tribunal suspendió la vista para réplica hasta resolverlo, y que por eso
  esa etapa sigue sin registrarse ("consistente con la pausa procesal, no
  un descuido"). Próxima acción cambió a "dar seguimiento a la resolución
  del incidente" con urgencia MEDIA (antes URGENTE/falso positivo).

## Auditoría integral también lee la bitácora de notas del abogado (13 nov 2026)
Pedido explícito del usuario ("adelante") tras extender este mismo arreglo
al Informe Ejecutivo -- `ia_auditar_expediente()` (la auditoría integral
diaria, `cron_auditoria_expediente_ia.php`) tenía el mismo hueco:
tampoco leía `expediente_notas` (la bitácora real que el abogado llena
con "Agregar nota de seguimiento"), solo veía etapas/documentos/avisos/
preguntas del supervisor -- podía perderse una fecha literal que el
abogado ya hubiera escrito a mano ahí.
- **Más cuidado que en el Informe Ejecutivo a propósito**: esta función SÍ
  escribe sola en `expediente_etapas` (el Informe Ejecutivo solo redacta
  texto informativo) -- el texto nuevo del prompt reitera la misma regla
  dura ya existente (nunca calcular/inferir, solo fecha LITERAL de que
  ESA etapa específica ya ocurrió).
- `ia_auditar_expediente()` ahora acepta un 6º parámetro opcional
  `$notasBitacora`; `cron_auditoria_expediente_ia.php` junta las últimas 8
  notas (`expediente_notas`, más reciente primero) y las manda, mismo
  patrón que `expediente_resumen_ejecutivo.php`.
- Sin cambios de base de datos, sin tocar `app.js` -- no aplica cache-bust
  ni `verificar_tags.js`. **Sin probar en vivo todavía** -- falta que el
  usuario suba `api/ia_helpers.php` y `api/cron_auditoria_expediente_ia.php`
  y se vea un caso real donde esto haga la diferencia.

## Panel nuevo: Validación de Tribunal / número de expediente (13 nov 2026, `app.js?v=146`)
Pedido explícito del usuario tras el caso de Isabel Torres Arizmendi
(Exp. 2235/2026 compartido por coincidencia con otro tribunal, ver
sección de abajo): "sería bueno que revisara que esté correcto el número
de tribunal, la entidad y el número de expediente" -- el objetivo
explícito es atrapar errores de captura ANTES de que un robot
simplemente no encuentre nada (o, peor, encuentre el caso equivocado por
coincidencia de número, como ya pasó).
- **3 chequeos, todos client-side, sin endpoint nuevo** (`CASES_DATA` ya
  trae `tribunal`/`exp` de todos los expedientes visibles, y los
  catálogos de Tribunal -- `TRIBUNALES_LOCALES_MX`/`TRIBUNALES_FEDERALES_
  MX`/`TRIBUNALES_CUSTOM` + `findTribunalEstado()` -- ya estaban cargados
  para el selector de "Editar datos"):
  1. **Formato del número de expediente**: no sigue el patrón `NNN/AAAA`.
  2. **Tribunal capturado no coincide con el catálogo del selector**
     (pedido explícito: "los tribunales deben de coincidir con los que
     estan en el selector") -- usa `findTribunalEstado()`, que ya existía
     para detectar "dato legacy sin estandarizar" en el campo de Editar
     datos; si devuelve vacío, el texto no está en ningún catálogo
     (local/federal/agregado a mano), probablemente mal escrito a mano en
     vez de elegido de la lista.
  3. **Mismo Tribunal + mismo número de expediente en 2+ expedientes
     DISTINTOS del sistema** -- el número de expediente solo es único
     DENTRO de cada tribunal, no en todo el país, así que esto es casi
     seguro un error de captura en uno de los 2 (exactamente el patrón
     que causó el bug de Isabel Torres Arizmendi).
- Nuevas funciones `validarTribunalesExpedientes()` (la lógica) y
  `tribunalValidacionHTML()` (el panel) en `assets/app.js`, solo sobre
  expedientes ACTIVOS (`!estaConcluido(k)`) con Tribunal capturado. Panel
  nuevo en "Equipo", entre "Actividad de la IA" y "Respaldo de toda la
  información" -- misma tabla clicable (`[data-id]`) que abre el
  expediente directo, nunca corrige nada solo.
- Sin cambios de base de datos, sin tocar `mergeTags()`/tags de demanda --
  no aplica `verificar_tags.js`.
- **Confirmado funcionando en vivo (13 nov 2026)**: primera revisión real
  encontró 2 casos genuinos, ambos confirmados reales por el usuario (no
  falsos positivos): **Eduardo Ramírez Gaspar vs Comercializadora
  Pepsic...** (número `MEXOAE/2026/006400`, no sigue `NNN/AAAA` -- a
  pesar de que `MEXOAE` es un prefijo real de Edomex, en este caso SÍ es
  un error de captura real, no el formato legítimo) y **Anel Yadira
  Hernández Zaragoza vs Banco Nacional de México** (Tribunal capturado
  como "TRIBUNAL LABORAL FEDERAL DE ASUNTOS INDIVIDUALES CON SEDE EN LA
  CIUDAD DE MÉXICO", sin el número ordinal que llevan TODOS los
  tribunales federales del catálogo -- hay que volver a capturarlo desde
  el selector con el número correcto). **Pendiente**: que el usuario
  corrija ambos expedientes (número de Eduardo Ramírez, Tribunal con
  número de Anel Yadira).

## Informe ejecutivo ciego a la bitácora de notas del abogado -- usaba un campo congelado importado del sistema viejo (13 nov 2026)
Reportado por el usuario viendo el expediente de **Alan Jonathan Campuzano
Brito vs Protectora Buro de Seguridad Privada y Logística, Exp. 780/2026**:
el Informe Ejecutivo (generado 12 nov, 08:27pm) seguía diciendo que faltaba
"presentar la promoción aclaratoria del domicilio... para que el Tribunal
proceda al emplazamiento" -- pero la "Bitácora de notas del despacho" de
ese mismo expediente ya tenía 2 notas MÁS RECIENTES confirmando que esa
promoción ya se presentó dos veces (04/06/2026, y de nuevo 28/10/2026
pidiendo notificar vía actuario), la última apenas 2 minutos ANTES de que
se generara el informe.
- **Causa real**: `ia_generar_resumen_expediente()` (`api/ia_helpers.php`)
  nunca leía la tabla `expediente_notas` (la bitácora real que el abogado
  llena con "Agregar nota de seguimiento") -- solo leía 2 campos sueltos de
  la tabla `expedientes`: `notas_internas` y `ultima_nota`. Este 2º campo
  se muestra en la UI como "Última actuación / nota **(importada de la
  base original)**" -- es un dato CONGELADO que se cargó una sola vez al
  migrar del sistema viejo del despacho y nadie lo vuelve a tocar después
  (`api/expedientes_add_nota.php` inserta en `expediente_notas`, nunca en
  `expedientes.ultima_nota`). La IA tomó ese texto viejo como si fuera el
  estado actual, sin enterarse de que la bitácora real ya lo había
  superado por completo.
- **Corregido**: `api/expediente_resumen_ejecutivo.php` ahora también
  manda las últimas 8 notas de `expediente_notas` (más reciente primero) a
  `ia_generar_resumen_expediente()` (nuevo parámetro `$notasBitacora`),
  etiquetadas en el prompt como "la fuente MÁS confiable y actual" -- por
  encima del campo congelado si se contradicen -- con regla dura explícita:
  si una nota reciente dice que algo ya se presentó/resolvió, nunca
  redactar que todavía falta.
- **Bug secundario encontrado de paso**: agregar una nota de seguimiento
  tampoco bumpeaba `expedientes.actualizado_en` (a diferencia de Etapas/
  Editar datos/Cobros/Pendientes, que sí lo hacen desde el arreglo del 10
  nov) -- así que el informe ejecutivo no se enteraba sola de una nota
  nueva, dependía de que alguien le diera "Actualizar" a mano o tocara otra
  cosa primero. Corregido en `api/expedientes_add_nota.php` (mismo UPDATE
  que ya usan los demás).
- **Alcance NO ampliado todavía**: `ia_auditar_expediente()` (auditoría
  integral diaria) tampoco lee `expediente_notas` -- mismo hueco en
  potencia (podría estar perdiéndose fechas literales que el abogado ya
  escribió a mano en sus notas), pero no se tocó esta vez porque el
  usuario no lo pidió ni se confirmó un caso real afectado ahí. Si aparece
  un caso real de auditoría integral que debería haber visto algo de la
  bitácora de notas y no lo vio, aplicar el mismo arreglo ahí.
- Sin cambios de base de datos (la tabla `expediente_notas` ya existía).
  No se tocó `app.js` -- no aplica cache-bust ni `verificar_tags.js`.
  **Pendiente**: pedirle al usuario que entre al expediente de Alan
  Campuzano y le dé "Actualizar" en "Informe del juicio" para confirmar
  que el texto nuevo ya refleja las 2 promociones presentadas en vez de
  seguir pidiendo la primera.

## Bug real: auditoría integral/preguntas del supervisor usaban avisos de boletín "descartados" como si fueran del expediente (13 nov 2026)
Encontrado probando en vivo el panel "Posibles irregularidades" -- la
irregularidad de **Isabel Torres Arizmendi vs Impulsora Ideal, Exp.
2235/2026** decía que el llenado de "Prevención" venía de un aviso de
boletín que en realidad es de OTRO caso (Rosas Rivas Anahi vs. Edxsmart...).
El usuario confirmó con el único documento real del expediente (una
Constancia de no conciliación de agosto, sin relación con "Prevención") y
con el historial de cambios: **`cron_auditoria_expediente_ia.php` SÍ llenó
sola `etapa_prevencion` (fecha 2026-09-28) y `etapa_demanda_admitida`
(fecha 2026-09-28) el 11 nov 2026**, citando como motivo avisos de boletín
que mencionan "Prevencion"/"Admision" para el expediente 2235/2026 -- pero
ese número de expediente se repite en VARIOS tribunales/casos distintos de
la CDMX (coincidencia, no es raro), y esos avisos en particular son de
**Ferrusca Padilla Jaime vs Total Safety** y **Rosas Rivas Anahi vs
Edxsmart...**, no del caso de Isabel Torres Arizmendi.
- **Causa real**: esos avisos SÍ están correctamente marcados
  `estado='descartado'` en `avisos_boletin` (confirmado visible en la
  pestaña "Boletín" del expediente, "Historial de revisiones") -- el
  sistema ya sabía que no correspondían a este caso. El problema es que
  **3 lugares distintos leían `avisos_boletin` por `expediente_id` sin
  excluir `estado = 'descartado'`**, así que para efectos de darle
  contexto a la IA, esos avisos se trataban igual que uno real:
  `ia_auditar_expediente()` (vía `cron_auditoria_expediente_ia.php`, el que
  de verdad llenó las 2 etapas mal), la "segunda opinión" del panel de
  irregularidades (`cron_qa_irregularidades.php`, por eso detectó el
  problema -- usando el mismo contexto contaminado, pero esta vez para bien,
  dio una explicación certera) y `ia_generar_pregunta_supervisor()` (vía
  `cron_generar_preguntas_supervisor.php`, no se confirmó que haya causado
  un error real ahí, pero mismo riesgo).
- **Corregido**: las 3 consultas ahora agregan `AND estado != 'descartado'`.
  El robot de boletín de CDMX (`robots/cdmx/cdmx.js`) YA tiene su propio
  candado de Tribunal+partes antes de reportar un aviso nuevo (agregado
  después del 28 sep 2026, fecha de estos avisos en particular) -- por eso
  esto no debería repetirse con avisos NUEVOS, pero no protegía contra
  avisos VIEJOS que ya estaban en la base de antes de ese candado y que un
  humano marcó "descartado" después de verlos por error.
- **Pendiente -- acción del usuario**: en el expediente de Isabel Torres
  Arizmendi, revisar/borrar "Prevención" y "Demanda admitida" en "Etapas
  del juicio" (no hay ninguna fecha real confirmada para ninguna de las 2
  todavía -- quedan en blanco hasta que se revise el expediente real del
  Tribunal). Además, correr este SQL de diagnóstico en phpMyAdmin para ver
  si hay OTROS expedientes con el mismo riesgo (llenado de auditoría
  integral citando un aviso de boletín, en un expediente que también tiene
  algún aviso descartado) -- revisar cada fila a mano, no corregir en
  automático:
  ```sql
  SELECT h.expediente_id, e.actor, e.demandado, e.exp, h.campo, h.despues, h.creado_en
  FROM expediente_historial h
  JOIN expedientes e ON e.id = h.expediente_id
  WHERE h.usuario_id IS NULL
    AND h.campo LIKE 'etapa_%'
    AND h.despues LIKE '%auditoría integral%Aviso de boletín%'
    AND EXISTS (
      SELECT 1 FROM avisos_boletin a
      WHERE a.expediente_id = h.expediente_id AND a.estado = 'descartado'
    )
  ORDER BY h.creado_en DESC;
  ```
- Sin cambios de base de datos, sin tocar `app.js` -- no aplica cache-bust
  ni `verificar_tags.js`. Archivos a subir: `api/cron_auditoria_expediente_ia.php`,
  `api/cron_qa_irregularidades.php`, `api/cron_generar_preguntas_supervisor.php`.

## Causa raíz real de "primer/segundo citatorio confundidos" en la QA semanal (12 nov 2026)
Encontrada revisando el `expediente_historial` real de los 3 casos que el
usuario reportó (Luis Enrique Aguilar Mateos y otros 2 iguales) --
**no era un error de llenado, era un falso positivo de la QA nueva**.
- Lo que pasó en cada caso: el abogado capturó a mano (meses atrás) que un
  citatorio de conciliación SE CELEBRÓ en fecha X (campo `fecha`).
  Después, `cron_leer_google_calendar.php` llenó solo, para el SIGUIENTE
  citatorio de la misma pareja (primer/segundo), su **"Fecha agendada"**
  (campo `fecha_programada`, una fecha FUTURA) -- un campo totalmente
  distinto, sin tocar `fecha`. `cron_leer_google_calendar.php` también
  deja `usuario_id NULL` en `expediente_historial` (igual que los otros 4
  mecanismos automáticos), pero con texto "fecha agendada: ..." en vez de
  "fecha: ...".
- `ia_verificar_fecha_etapa_llenada_sola()` (la "segunda opinión" de la QA
  semanal) no distinguía esto -- comparaba el motivo de ESE llenado de
  Google Calendar (que habla de una cita agendada a futuro) contra el
  campo `fecha` (la fecha YA celebrada, puesta por el abogado para la
  ocasión ANTERIOR) -- como nunca van a coincidir (uno es pasado, otro es
  futuro), siempre salía "sospechoso", un falso positivo sistemático.
- **Corregido (commit `bb1ce74`)**: `cron_qa_irregularidades.php` ahora
  excluye del chequeo cualquier fila de historial cuyo texto empiece con
  "fecha agendada:" -- esas nunca deberían pasar por esta verificación
  (no son una confirmación de "ya ocurrió", son una fecha programada a
  futuro; ese tipo de llenado ya tiene su propio candado de orden
  cronológico y el criterio conservador de `ia_matchear_evento_
  calendario()` para evitar matches ambiguos).
- **No hubo nada que revertir**: el dato real (`fecha_programada` de
  Google Calendar) siempre estuvo bien -- el error estaba solo en el
  chequeo nuevo, no en el llenado original.
- **Pendiente**: limpiar a mano (o con SQL) las irregularidades
  `fecha_sin_respaldo` ya creadas por este falso positivo específico --
  dar "Está bien, no es error" en cada una, o correr este UPDATE una vez
  en phpMyAdmin (revisar el resultado antes de confiarlo a ciegas):
  ```sql
  UPDATE expediente_irregularidades i
  JOIN expediente_historial h
    ON h.expediente_id = i.expediente_id
   AND h.campo = CONCAT('etapa_', i.etapa_key)
   AND h.usuario_id IS NULL
   AND h.despues LIKE 'fecha agendada:%'
  SET i.estado = 'descartada', i.resuelto_en = NOW()
  WHERE i.tipo = 'fecha_sin_respaldo' AND i.estado = 'pendiente';
  ```
- Sin cambios de base de datos nuevos (la tabla ya existía desde la
  migración 069). No se tocó `assets/app.js` -- no aplica cache-bust ni
  `verificar_tags.js`.

## Irregularidades "fecha_sin_respaldo" no se cerraban solas al corregirse (12 nov 2026)
Reportado por el usuario: corrigió a mano la fecha de Luis Enrique Aguilar
Mateos (el primer caso real que el panel detectó) y la irregularidad
seguía apareciendo en "Preguntas al abogado". Hueco real: las de tipo
`orden_cronologico` ya se recalculaban y auto-cerraban solas en la corrida
semanal del cron, pero las de `fecha_sin_respaldo` NO tenían ningún
chequeo automático -- solo se cerraban con un tap explícito en "Está
bien, no es error", nunca solas, aunque el dato ya estuviera corregido.
- **Corregido**: `api/expedientes_update_etapas.php` ahora revisa, cada
  vez que se guarda "Etapas del juicio", si hay irregularidades
  `pendiente` de ese expediente cuya condición ya no aplica (la fecha ya
  no coincide con la que causó la alerta, o la violación de orden
  cronológico ya no existe) y las cierra solas como `corregida` -- de
  inmediato, sin esperar la corrida semanal.
- **Acción inmediata para el caso de Luis Enrique** (ya corregido ANTES de
  subir este arreglo, así que el candado nuevo no lo agarró):  descartar a
  mano con "Está bien, no es error" en el panel, o volver a darle
  "Guardar etapas" en ese expediente (sin cambiar nada) una vez subido
  `expedientes_update_etapas.php` -- cualquiera de las 2 lo quita.
- Sin cambios de base de datos. No se tocó `assets/app.js` -- no aplica
  cache-bust ni `verificar_tags.js`.

## Etapas del juicio: borrar una fecha revertía sola a hoy (12 nov 2026, `app.js?v=145`)
Reportado por el usuario al intentar corregir una irregularidad detectada
por el panel nuevo: "corrijo la fecha del 12/11/26 lo dejo libre porque
todavía no se da y no lo guarda". No era que no guardara -- guardaba, pero
de vuelta la fecha de HOY en silencio.
- **Causa real**: `.etapa-check` (el checkbox de cada fila) y `.etapa-fecha`
  (el campo "Fecha en que se celebró") son 2 controles de DOM sin relación
  entre sí. El checkbox arranca marcado si la etapa ya tenía fecha, pero
  nada lo desmarcaba si el abogado borraba SOLO el campo de fecha. El
  guardado (`saveEtapasBtn`) interpretaba "checkbox marcado + fecha vacía"
  como "márcala hecha HOY sin fecha exacta" (pensado para etapas sin
  "Fecha agendada" propia, donde tiene sentido como atajo) y rellenaba la
  fecha de hoy sola -- deshaciendo el borrado sin ningún error visible.
- **Corregido**: nuevo listener en cada `.etapa-fecha` que sincroniza el
  checkbox de su misma fila al vuelo (checkbox = true solo si el campo
  tiene valor) -- borrar la fecha ahora desmarca el checkbox solo, así el
  guardado ya no la rellena con la fecha de hoy.
- Sin cambios de base de datos. No se tocó `mergeTags()`/tags de demanda --
  no aplica `verificar_tags.js`. **Pendiente de confirmar en vivo**: que
  ahora SÍ se pueda dejar en blanco "Fecha en que se celebró" de la
  conciliación (segundo citatorio) del expediente de Luis Enrique Aguilar
  Mateos.

## Panel "Posibles irregularidades": primera corrida real (12 nov 2026)
Primera corrida real de `cron_qa_irregularidades.php` tras subir todo:
**"46 expediente(s) activo(s) revisado(s) por orden cronológico, 14
llenado(s) automático(s) verificado(s) con IA (11 sospechoso(s), 0
fallido(s)), 10 expediente(s) con novedades nuevas"**. Tasa de sospecha
alta (11/14) para un chequeo pensado como conservador -- revisando 3
ejemplos reales que mandó el usuario, **se ven genuinos, no falsos
positivos**: un patrón nuevo no cubierto antes -- "la fecha del motivo
pertenece al citatorio equivocado" (ej. una fecha que es del SEGUNDO
citatorio aplicada al PRIMERO) y "el motivo solo confirma que hay una cita
AGENDADA, no que la conciliación ya se celebró" (mismo patrón de fondo que
el bug de Alan Campuzano -- confundir "programado" con "ya ocurrido", pero
esta vez entre 2 citatorios de la misma conciliación en vez de entre
etapas distintas). Parece ser un hueco real en el mecanismo que llena
`fecha` (celebrada) de los citatorios de conciliación a partir de eventos
de Google Calendar o boletín -- **pendiente de identificar la causa raíz
exacta** (cuál de los mecanismos está confundiendo primer/segundo
citatorio) antes de dar por buena la corrida y programarla semanal en
cPanel. Mientras tanto, el panel ya está cumpliendo su función: detectó
10 expedientes con datos mal puestos que nadie había notado.

## Panel "Posibles irregularidades": QA semanal sobre etapas ya registradas (12 nov 2026, `app.js?v=144`, migración 069)
Pedido explícito del usuario tras el bug de Alan Campuzano de hoy mismo:
"tenemos que hacer que la ia sea muy efectiva revisando los juicios para
detectar irregularidades, atrasos, términos, llenar de manera correcta el
sistema". A diferencia de los mecanismos que ya existen (llenan huecos
SIN fecha), este nuevo cron semanal revisa lo que YA está registrado,
buscando contradicciones -- **nunca corrige nada solo**.
- **Requisito explícito del usuario, decisivo para el diseño**: "tiene
  que ser muy práctico para el abogado... no queremos quitarles mucho
  tiempo corrigiendo o llenando el sistema". Por eso: se integra DENTRO
  del panel ya existente "Preguntas al abogado" (no una pantalla nueva que
  aprender/revisar aparte), silencioso cuando no hay nada, y cada alerta
  es UN tap, nunca un formulario.
- **2 chequeos, deliberadamente baratos y conservadores**:
  1. **Orden cronológico roto** entre etapas YA registradas (sin IA,
     gratis) -- `detectar_irregularidades_orden_cronologico()`
     (`api/expediente_helpers.php`) aplica retroactivamente sobre captura
     MANUAL del abogado el mismo candado que ya existía (`etapa_fecha_es_
     consistente()`) pero que solo corría cuando la IA llenaba algo sola
     -- si el abogado captura a mano una fecha fuera de orden, antes de
     hoy nadie lo detectaba nunca.
  2. **"Segunda opinión" de IA (Sonnet)** SOLO sobre etapas que un
     mecanismo automático llenó solo (`expediente_historial.usuario_id IS
     NULL`, nunca sobre captura manual del abogado -- ahí se confía en su
     criterio) -- `ia_verificar_fecha_etapa_llenada_sola()`
     (`api/ia_helpers.php`), mismo prompt reforzado hoy tras el bug real
     de Alan Campuzano (nunca marca sospechoso solo por falta de
     "documento de respaldo" -- muchas etapas se capturan a mano desde el
     expediente físico sin subir nada, eso no es un error).
- **Nuevo cron `api/cron_qa_irregularidades.php`** -- correr **1 vez a la
  semana** (no diario como el resto, esto revisa lo ya registrado, no hay
  prisa). Candidatos: expedientes activos con al menos 1 etapa. El
  chequeo de IA (parte 2) se limita a 25 llenados-automáticos-sin-
  verificar por corrida (`qa_verificado_en` marca cada fila de historial
  ya revisada, para no re-pagar la misma).
- **Autocierre inteligente, sin que el abogado tenga que volver**: las de
  orden cronológico se recalculan completas cada corrida -- si el abogado
  ya corrigió la fecha, la irregularidad se cierra sola como `corregida`.
  Si el abogado dio "Está bien, no es error" (`descartada`) y la situación
  no cambió, no se le vuelve a mostrar; si algo nuevo pasa (el motivo
  cambia), se reabre.
- **UI** (`preguntasAbogadoHTML()`): panel "⚠️ Posibles irregularidades"
  arriba de los demás -- cada tarjeta tiene 2 botones: "Corregir" (reusa
  el `[data-id]` genérico, abre el expediente directo en "Etapas del
  juicio") y "Está bien, no es error" (`api/qa_irregularidad_resolver.php`,
  descarta). Badge de "Preguntas al abogado" ampliado
  (`preguntasAbogadoBadgeCount()`) para sumar también estas + las
  sugerencias de Google Calendar (antes el badge solo contaba preguntas
  del supervisor, un hueco que ya existía). Push solo si hay novedades
  nuevas (`?irregularidad=1`, mismo patrón que `?pregunta=`/`?pregunta_
  calendario=`).
- Sin tocar `mergeTags()`/tags de demanda -- no aplica `verificar_tags.js`.
  **Sin probar en vivo todavía** -- falta correr el cron por primera vez
  (abrir la URL directo, mismo patrón que los demás) una vez que el
  usuario suba los 5 archivos nuevos/tocados y corra la migración 069.
  Pendiente de confirmar que detecte algo real (ej. forzando a mano una
  fecha fuera de orden en un expediente de prueba) antes de programarlo en
  el Cron Job de cPanel.

## Informe ejecutivo: Junta/Tribunal invertido + "Prevención" tratada como paso seguro (12 nov 2026, commit `279ff23`)
Reportado por el usuario viendo el Informe del juicio de **Yazmin González
Román vs Servicio Automotriz Xola**: decía "la demanda se presentó ante la
**Junta 8** Laboral..." (debía decir Tribunal) y "el asunto está en etapa de
**prevención**... 27 días sin registro de la resolución" cuando en realidad
el Tribunal todavía no dictaba NINGÚN acuerdo (ni prevención ni admisión) --
"estamos en espera del acuerdo que dicte el juez ya sea previniendo la
demanda o admitiéndola", como aclaró el usuario.
- **Bug 1 (dato, no alucinación de la IA)**: `ia_generar_resumen_expediente()`
  en `api/ia_helpers.php` leía `$expediente['junta'] ?? $expediente['tribunal']`
  -- orden INVERTIDO respecto a la decisión del 9 nov 2026 de que `tribunal`
  es el campo vigente y `junta` solo respaldo de datos viejos (`mergeTags()`
  en app.js ya tenía el orden correcto, este quedó sin corregir en esa
  ocasión). Si un expediente tiene AMBOS campos capturados (dato viejo en
  `junta` + el nuevo en `tribunal`), este `??` siempre ganaba el viejo.
  Corregido: ahora `tribunal` primero.
- **Bug 2 (lógica del prompt)**: la línea "Siguiente etapa esperada,
  TODAVÍA SIN REGISTRAR: Prevención" se construía solo con el orden lineal
  de `ETAPA_KEYS`, sin avisarle a la IA que "Prevención" y "Demanda
  admitida" son 2 resultados ALTERNATIVOS del mismo primer acuerdo del
  Tribunal (Art. 873 LFT) -- no un paso seguro tras "Demanda presentada".
  Nueva función compartida `ia_siguiente_etapa_esperada_texto()`
  (`api/ia_helpers.php`) centraliza este cálculo (ya existía una excepción
  parecida para Convenio/Constancia de no conciliación, también
  alternativos) y se usa en los 2 lugares que antes duplicaban la lógica
  sin esta protección (resumen ejecutivo Y análisis de acuerdos/documentos
  -- el 2º ni siquiera tenía la excepción de Convenio, hueco latente
  cerrado de paso).
- Sin cambios de base de datos. No se tocó `mergeTags()`/tags de demanda --
  no aplica `verificar_tags.js`. **Pendiente**: pedirle al usuario que
  entre al expediente de Yazmin González y le dé "Actualizar" en "Informe
  del juicio" para confirmar que el texto nuevo ya no dice "Junta" ni
  asume que estamos en prevención.

## ⚠️ Bug real de integridad de datos: auditoría integral IA calculaba plazos (12 nov 2026, URGENTE)
Reportado por Lic. Lindsay Luna Maldonado vía WhatsApp: el expediente **Alan
Jonathan Campuzano Brito vs Protectora Buro de Seguridad Privada y
Logística, Exp. 780/2026** tenía "Emplazamiento" (15/06/2026) y
"Contestación de demanda recibida" (30/06/2026) marcados con fecha, pero
NINGUNA corresponde a una actuación real del expediente digital del
Tribunal (última actuación real: 25/06/2026, sin relación a contestación).
Confirmado vía el panel "Actividad de la IA" (su primer uso real, útil para
esto): los 2 (y un 3º, conciliación prejudicial) los llenó
`cron_auditoria_expediente_ia.php` el 10-11 nov 2026, pese a que el prompt
de `ia_auditar_expediente()` YA prohibía explícitamente "calcular un
plazo". Dos patrones de error reales, con Haiku:
1. Un documento decía "se concede 15 días para contestar a partir del
   emplazamiento del 2026-06-15" -- la IA **calculó** que eso "vence" el
   2026-06-30 y lo reportó como si fuera la fecha en que la contestación
   de verdad se recibió (nadie confirmó que se recibió).
2. La demanda mencionaba que "el despido injustificado ocurrió el
   30/01/2026" -- la IA usó esa fecha para "Conciliación prejudicial" (un
   evento distinto al despido).
El candado de orden cronológico (`etapa_fecha_es_consistente()`) no atrapó
ninguno de los 2 porque ambas fechas quedaban en orden válido -- no es un
problema de secuencia, es que la fecha no corresponde al evento real de esa
etapa.
- **Corregido (commit `acfd047`)**: prompt de `ia_auditar_expediente()`
  reforzado con estos 2 patrones como ejemplos negativos explícitos +
  modelo cambiado de Haiku a Sonnet (`IA_MODELO_ANALIZAR_ACUERDO`, mismo
  criterio que ya se usó con `IA_MODELO_BUSQUEDA` cuando Haiku demostró no
  seguir bien una regla dura) + candado heurístico nuevo
  `motivo_sugiere_fecha_calculada()` (`api/expediente_helpers.php`) que
  rechaza por texto del motivo ("vence", "concediéndole", etc.), conectado
  en `cron_auditoria_expediente_ia.php` junto al candado de orden
  cronológico ya existente.
- **YA RESUELTO (12 nov 2026, mismo día)**: usuario subió los 3 archivos
  (`api/ia_helpers.php`, `api/expediente_helpers.php`,
  `api/cron_auditoria_expediente_ia.php`) y corrigió a mano en "Etapas del
  juicio" los 3 campos malos del expediente de Alan Campuzano
  (`emplazamiento`, `contestacion_recibida`, `conciliacion_prejudicial` --
  `prevencion` se dejó igual, es legítima). Se revisó TODO el historial de
  "Actividad de la IA" filtrado por "IA (auditoría integral)" desde que el
  cron se dio de alta (10 nov 2026) hasta hoy -- se encontró un 4º caso real
  con el mismo patrón ("orden" confundida con "ya se hizo"):
  **Zeltzin Monserrat Flores Cruz vs Institufacil** (10 nov, `emplazamiento`,
  20/05/2026) -- el motivo decía "el acuerdo de admisión **ordena** el
  emplazamiento" (una orden, no una confirmación de que ya se hizo). **Ya
  corregido a mano por el usuario también.**
  - El resto de entradas de "auditoría integral" revisadas (Isabel Torres
    Arizmendi, Zeltzin -conciliación prejudicial-, Alejandro Hernández
    -rechazada sola por el candado de orden cronológico-, Oscar Manuel
    Trejo, Mir Castro) se ven bien -- confirmaciones literales genuinas, no
    cálculos ni eventos ajenos. Con esto se da por cerrada la revisión
    retroactiva; de aquí en adelante el prompt reforzado + Sonnet + el
    candado `motivo_sugiere_fecha_calculada()` deben prevenir que se
    repita.
- Este archivo de reprocesamiento legacy que YA CORRIÓ
  (`_una_vez_reprocesar_calendario_legacy.php`, de la pieza de Google
  Calendar) NO se tocó -- es un bug distinto (ese es sobre eventos de
  calendario, no sobre esta auditoría), no hace falta re-ejecutarlo.
- Sin cambios de base de datos. No se tocó `mergeTags()`/tags de demanda --
  no aplica `verificar_tags.js`.

## Flujo de despliegue
- No hay acceso SSH ni a servidor en vivo desde estas sesiones.
- Cambios de código: editar aquí → `php -l` / `node --check` → subir el
  cache-bust (`assets/app.js?v=N` en `index.html`) → commit/push →
  `SendUserFile` solo de los archivos modificados → el usuario los sube por
  cPanel → Administrador de archivos.
- Cambios de base de datos: dar el SQL, el usuario lo corre por phpMyAdmin.
- **Cualquier archivo `.js` (`assets/app.js`, `sw.js`, y también los robots --
  confirmado real el 24 nov 2026 con `robots/federal/federal.js`) siempre
  en un `.zip`** -- no los puede descargar si se mandan sueltos con
  `SendUserFile`. Empaquetar conservando la ruta relativa (`assets/app.js`,
  no solo `app.js`) antes de mandarlos.
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

**070/070b (LFT), 071 (bot_revisiones) y 072 (transitorios de jornada) ya corridas por el usuario el 14 nov 2026. Ese mismo día el usuario confirmó subidos TODOS los archivos de la sesión "chats" hasta el commit `0482e7e` (`app.js?v=152`, ia_helpers, liquidacion_calculadora, configuracion_set, whatsapp_procesar, lft_helpers, revisión diaria del bot con su Cron Job). 073 (negocio, 16 nov), 074 (robot_heartbeats, 20 nov) y 075 (calculos_liquidacion.tercer_seguimiento_en, 25 nov 2026) también ya corridas en `c1361311_exp` -- confirmadas por el usuario. Siguiente migración libre: 076.** **Última migración aplicada por el usuario: `075_calculos_liquidacion_tercer_seguimiento.sql`, confirmada corrida el 25 nov 2026.**

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

## Calendario: pendientes manuales también visibles (12 nov 2026, `app.js?v=141`)
Pedido explícito del usuario tras notar que un pendiente manual ("Firma
de la demanda en Tribunal Cuautitlán", expediente de Sharon Echeverria
Mayen) aparecía en Agenda general → General pero no en la pestaña
Calendario. El alcance limitado del Calendario había sido una decisión
explícita de ayer (solo audiencia/pago/prescripción/amparo) -- el usuario
decidió ampliarlo: "eso también debe estar en calendario".
- `calendarioItemsPorDia()` ahora también incluye el tipo `pendiente`
  (los que `buildAgendaEntries()` ya arma a partir de la pestaña
  "Pendientes" de cada expediente, con fecha fija o calculada a días
  hábiles/naturales). Mismo chip/popover que audiencias/pagos --
  informativo + botón "Abrir expediente", sin el botón "Marcar cumplido"
  dentro del popover (eso se sigue haciendo desde "General" o la pestaña
  "Pendientes" del expediente).
- **A propósito NO se incluyeron** los tipos `atraso`/`accion_ia`
  ("posibles atrasos" y sugerencias de IA) -- esos no tienen una fecha
  "agendada" propia (son un límite calculado o una sugerencia de
  urgencia, no algo que el abogado programó), y siguen necesitando el
  botón de acción que el calendario no tiene espacio para mostrar bien.
  Si el usuario pide que también se vean ahí, replantear el diseño del
  chip para esos 2 tipos en vez de solo agregarlos a la lista de tipos
  incluidos.
- Sin cambios de base de datos. No se tocó `mergeTags()`/tags de
  demanda -- no aplica `verificar_tags.js`.
- **Ampliado el mismo día, pedido explícito del usuario**: "todo lo que
  esta en nuestra agenda general se vaya a google calendar". Se
  encontraron y corrigieron 2 cosas reales en el sync de salida
  (sistema → Google Calendar, el que ya existía):
  1. `GOOGLE_TIPOS_SINCRONIZABLES` solo tenía
     `['audiencia','pago','prescripcion','amparo']` -- se amplió a los 7
     tipos que arma `buildAgendaEntries()`: se agregaron `pendiente`,
     `atraso` y `accion_ia`. Como `sincronizarGoogleCalendar()` ya
     arma el `summary`/`description` de forma genérica a partir de
     `AGENDA_TIPO[e.tipo].label` y `e.label`, no hizo falta tocar nada
     más ahí -- cualquier tipo nuevo que se agregue a Agenda general en
     el futuro se sincroniza solo con solo agregarlo a esta lista.
  2. **Bug real encontrado**: el botón "Guardar pendientes" (pestaña
     Pendientes del expediente) nunca llamaba a `syncGoogleIfConnected()`
     -- a diferencia de TODOS los demás botones de guardar (Etapas,
     Editar datos, Cobros, Amparo), así que un pendiente manual nunca se
     sincronizaba aunque ya estuviera en la lista de tipos. Corregido.
  - Nota de diseño: `atraso`/`accion_ia` son fechas CALCULADAS (un límite
    vencido, una sugerencia de urgencia) y no tienen un botón de "guardar"
    propio -- se sincronizan solos la próxima vez que cualquier OTRO
    guardado dispare `syncGoogleIfConnected()` (Etapas, Editar datos,
    Cobros, Pendientes, Amparo), o con el botón "Sincronizar ahora" en
    Agenda general → General. Si en la práctica esto llena el Google
    Calendar de el usuario con demasiado ruido (son fechas que ya
    vencieron, no compromisos futuros), avisar para quitarlos de la
    lista otra vez.
  `app.js?v=142`.
  - **Confirmado funcionando en vivo (12 nov 2026)**: tras darle clic a
    "Sincronizar ahora", apareció en Google Calendar "[Pendiente] SHARON
    ECHEVERRIA MAYEN vs GRUPO MOSALDI..." con la descripción "FIRMA DE LA
    DEMANDA EN TRIBUNAL CUAUTITLAN", y de paso un "[Sugerido por IA]"
    también presente -- confirma que los 3 tipos nuevos sí se sincronizan.
  - **Bug de diseño real encontrado por el usuario al revisar ese mismo
    evento**: cayó en el calendario PRINCIPAL de quien dio clic en
    "Sincronizar ahora" (el Administrador, cuenta `themislex76@gmail.com`,
    calendario "Rubén Buerhend"), no en el sub-calendario del abogado
    ASIGNADO al caso ("Audiencias linsday", de Lic. Lindsay Luna
    Maldonado) -- "se debe ir a la agenda del abogado asignado... a la
    agenda compartida". Corregido el mismo día: el sync de salida ahora
    primero intenta la cuenta de Google COMPARTIDA (la misma ya conectada
    para "Calendarios de Google vigilados" en Equipo) en vez de la cuenta
    propia de quien dispara el sync, y para cada evento busca el
    sub-calendario mapeado al `abogado_id`/`abogado_id_2` del caso --
    nuevos endpoints `api/google_access_token_compartido.php` (cualquier
    usuario puede pedir el token de la cuenta compartida) y
    `api/google_calendarios_mapeo_por_abogado.php` (mapa simple
    abogado_id → calendar_id, sin restringir a Administrador). Si el
    abogado del caso no tiene un sub-calendario mapeado todavía (como
    Mariana, Christian, Rubén, Junta Federal -- solo "Audiencias linsday"
    está vigilado hoy), cae de regreso al calendario principal de la
    cuenta compartida (sigue siendo mejor que antes, al menos todo
    termina en UN solo lugar central en vez de dispersarse según quién
    dio clic). Si no hay ninguna cuenta compartida configurada en
    absoluto (instalación nueva sin nada mapeado en Equipo), cae de
    regreso al comportamiento viejo (calendario propio de quien
    sincroniza). `app.js?v=143`.
  - **Sin probar en vivo todavía** este último cambio de ruteo por
    abogado -- falta confirmar que un evento nuevo (ej. otro pendiente de
    Sharon, o la próxima audiencia de Lindsay) sí caiga en "Audiencias
    linsday" y no en el calendario principal.

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

## Precio de la asesoría: SIEMPRE el vigente, ya no se respetan precios viejos (11 nov 2026, conversación "chats")
- Decisión del despacho: el bot maneja siempre el precio actual ($499).
  Si el cliente dice que antes le ofrecieron un precio menor, se le
  explica que la asesoría subió en octubre de 2026 -- no se le respeta el
  precio viejo.
- Bug que lo detonó: a un cliente cotizado en $299 en agosto, el bot le
  volvió a escribir "la asesoría de $299" el 10-nov (copiado del
  historial), y `mercadopago_monto_asesoria_a_respetar()` lo tomó como
  cotización vigente (ventana de 14 días) y cobró $299. A ESE cliente sí
  se le respetó (ya había pagado).
- Arreglo: `mercadopago_monto_asesoria_a_respetar()` (`api/mercadopago_helpers.php`,
  se dejó el nombre por sus 5 llamadores) ahora siempre regresa
  `MERCADOPAGO_MONTO_ASESORIA`; si en el chat se mencionó $299/$399,
  regresa `aviso_cambio_precio` para que el bot explique el aumento. Se
  quitaron `MERCADOPAGO_DIAS_VIGENCIA_COTIZACION` y la ventana de 14 días.
  Prompt (`api/ia_helpers.php`): regla dura de nunca copiar precios del
  historial y de explicar el aumento en vez de prometer el precio viejo.
  Probado con SQLite (5 casos). Sin migración, sin cambio de `app.js`.

## Despido de trabajador de confianza: ya NO se suman 20 días por año (12 nov 2026, conversación "chats")
- Corrección jurídica del despacho ("es un grave error... genera una
  falsa expectativa en la gente"): en un DESPIDO los 20 días por año
  (Art. 50-II) nunca se suman al estimado, ni siquiera si es de
  confianza. La ley no le niega la reinstalación al trabajador de
  confianza; es el patrón quien puede eximirse de reinstalarlo pagando lo
  del Art. 50 (Art. 49-III), y eso solo se sabe en juicio. Solo se suman
  en RESCISIÓN (Art. 52), que no cambió.
- Caso real: Norma (coordinadora de producción, 20 años, $705 diarios):
  el bot le dijo "muy probable que seas de confianza... automáticamente
  te tocan los 20 días" y un seguimiento automático le prometió
  "~$571,486"; la empresa la despidió y le ofreció mucho menos.
- Cambios: `api/liquidacion_calculadora.php` -- con
  `trabajador_confianza=true` el monto de 20 días ya no entra a
  `total_estimado`; va aparte en
  `indemnizacion_20_dias_si_patron_no_reinstala` (el PDF no lo muestra).
  Mismo caso: antes $571,643, ahora $262,161. `api/ia_helpers.php`:
  REGLA DURA reescrita (nunca ofrecer los 20 días en despido; nunca
  presentar "ser de confianza" como ventaja -- riesgo de despido por
  pérdida de la confianza, Art. 185) + descripción del parámetro
  `trabajador_confianza` de la herramienta. Sin migración, sin `app.js`.
- `assets/app.js` (formato de demanda de Rescisión Art. 51, prestación
  `veinte_dias`) NO se tocó: ahí sí corresponden.

## Bot ya no revive citas viejas como "tu asesoría de hoy" (13 nov 2026, conversación "chats")
- Bug real (12-nov): clienta de Oxxo con asesoría YA REALIZADA el 30-oct
  escribió con una duda nueva; el bot, sin llamar `consultar_cita_pago`
  (que sí habría dicho "asesoria_anterior_ya_realizada"), dedujo del
  historial "tu asesoría de hoy a las 5:00 pm", le negó reprogramar, le
  ofreció pagar otros $499, y ella esperó una llamada inexistente.
- Arreglo en `api/ia_helpers.php`: (1) REGLA DURA en el prompt: antes de
  mencionar la cita del cliente, llamar `consultar_cita_pago` en ese mismo
  turno y comparar la fecha con hoy; nunca deducirla del historial.
  (2) Candado de salida nuevo `ia_texto_menciona_cita_hoy_sin_evidencia()`
  (mismo patrón que `ia_texto_confirma_pago_sin_evidencia()`): si el texto
  da por hecho una cita/llamada "de hoy" y el teléfono no tiene cita
  confirmada/pendiente con `fecha = CURDATE()`, se sustituye por un
  mensaje neutro y se escala (`ia_registrar_prospecto_atorado`, log
  `[cita_hoy_bloqueada]` en `ia_debug.log`). Probado con 11 casos (los 4
  mensajes reales equivocados se bloquean; ofrecer horarios de hoy, una
  cita real de hoy o una cita de otro día no). (3) REGLA DURA: nunca
  afirmar que un acta administrativa "pierde validez" por cómo se levantó.
  Sin migración, sin `app.js`.

## Candado de pagos ya reconoce cursos pagados (13 nov 2026, conversación "chats")
- Bug real (12-nov): cliente pagó el curso "Nuevo Procedimiento Laboral
  Mexicano" ($299), el webhook le mandó su acceso, y al mandar su
  comprobante el bot le contestó "déjame verificar tu pago... un abogado
  te contacta" -- `ia_texto_confirma_pago_sin_evidencia()` solo revisaba
  `citas_asesoria` y `compras_documento_calculo`, no `compras_curso`, así
  que bloqueó una confirmación correcta y lo escaló sin necesidad (mismo
  hueco que ya había pasado con el documento de $49). Ahora también
  revisa `compras_curso.estado='confirmada'`. Probado con 3 casos. Si se
  agrega otro producto pagado, agregarlo también a este candado.

## Precisión del bot, parte 1: criterios del despacho, Apartado A/B, vacaciones y ventas (14 nov 2026, conversación "chats")
Pedido del usuario: "el bot tiene que dar la asesoría completa, pero más
precisa para que no se equivoque". Plan acordado en 6 piezas (1 criterios
del despacho, 2 LFT consultable artículo por artículo, 3 jurisprudencia
SCJN consultable, 4 calculadora más precisa, 5 revisión automática diaria
de conversaciones, 6 casos de prueba de regresión). Hecho en esta parte:
- **Apartado A/B** (`api/ia_helpers.php`): se quitó "personal del propio
  IMSS/ISSSTE" de la lista de rechazo (bug real: trabajadora del IMSS
  rechazada como Apartado B). Criterio dado por el Lic. Rubén, en 3 reglas
  numeradas en el prompt: (1) si el decreto de creación dice Apartado A,
  es A; (2) si dice B pero hay contrato colectivo vigente que dice A, es A
  (jurisprudencia más reciente); (3) solo es B si el decreto dice B y no
  hay contrato colectivo vigente que diga A. El bot nunca
  rechaza a una entidad pública por intuición; si hay duda, escala.
- **Bloque "CRITERIOS DEL DESPACHO PARA ORIENTAR CON PRECISIÓN"** en el
  prompt: citar artículos solo si es seguro; suspensión disciplinaria
  (Art. 423-X, máx. 8 días, derecho a ser oído; no es Art. 47); salarios
  caídos siempre con tope de 12 meses; no afirmar montos exentos de ISR;
  "prescritas" solo si la herramienta lo dice; neto vs bruto; no afirmar
  en absoluto lo que depende de pruebas; no cerrar cada mensaje con la
  misma pregunta de agendar; consistencia si ya se rechazó el caso.
- **Calculadora** (`api/liquidacion_calculadora.php`): nuevo campo
  `vacaciones_anteriores_dias_excedentes` separado de
  `..._dias_prescritos` (antes los días reportados por encima de lo que
  da la ley se llamaban "prescritos"). Nota sin cita de jurisprudencia no
  verificada: ahora fundamenta en Arts. 76, 81 y 516.
- **Seguimiento de ventas** (`api/cron_seguimiento_calculadora.php`): sin
  montos ni "sin compromiso"; no le escribe a quien el bot ya le dijo que
  no se le puede atender.
- **Pendiente (partes 2-6)**: herramienta `consultar_articulo_lft` (falta
  el texto oficial de la LFT: el PDF de diputados.gob.mx supera el límite
  de 100 páginas para subirlo al chat), herramienta de jurisprudencia
  para el bot, revisión diaria de conversaciones y casos de prueba.

## Precisión del bot, partes 2 y 3: LFT oficial y jurisprudencia consultables (14 nov 2026, conversación "chats", migración 070/070b)
- **LFT vigente en la base**: tabla `lft_articulos` (migración
  `070_lft_articulos.sql`) + datos `070b_lft_articulos_datos.sql` (1,266
  artículos, 1.3 MB, importar por phpMyAdmin → Importar, DESPUÉS de la
  070). Sacada del PDF oficial de la Cámara de Diputados, "Última reforma
  publicada DOF 14-05-2026" (subido por el usuario en 5 partes); solo
  articulado, sin transitorios. Clave `numero` = "47", "39-A", "3 Bis",
  "512-D Ter". Cuando haya reforma nueva: volver a generar la 070b
  completa (el script de separación vive en el scratchpad de esa sesión,
  no en el repo -- regex por línea `^Artículo N...`).
- **`api/lft_helpers.php`** (nuevo): `lft_normalizar_numero()`,
  `lft_consultar_articulos()` (máx. 5, recorta a 6,000 caracteres -- el
  Art. 513, tablas, mide 313k), `lft_buscar_texto()` (FULLTEXT sobre
  `texto`) y `jurisprudencia_buscar_para_bot()` (FULLTEXT sobre `rubro`
  de `jurisprudencia_tesis`, mismo criterio que el buscador del panel,
  sin sus pasos de IA). Si la tabla no existe todavía, regresan un aviso
  ("no cites número de artículo") en vez de tronar.
- **3 herramientas nuevas del bot** en `IA_TOOLS`: `consultar_articulo_lft`,
  `buscar_en_lft`, `buscar_jurisprudencia`. Regla del prompt (bloque de
  CRITERIOS DEL DESPACHO): antes de citar cualquier número de artículo,
  consultarlo en ese turno; jurisprudencia solo si la regresó la
  herramienta, nunca de memoria.
- 070 + 070b ya corridas en vivo (14 nov 2026, 1,266 filas). Bug real al
  importar: 353-N y 353-Ñ chocaban en la PRIMARY KEY (la collation por
  defecto trata N = Ñ) -- `numero` ahora es `utf8mb4_bin`.
- Probado con SQLite la normalización y la consulta por número (FULLTEXT
  no se puede probar sin MySQL). **Pendiente**: que el usuario suba `api/lft_helpers.php` + `api/ia_helpers.php`; partes
  4 (tope prima de antigüedad, falta criterio), 5 (revisión diaria de
  conversaciones) y 6 (casos de prueba).

## Precisión del bot, partes 5 y 6: revisión diaria de conversaciones + casos de prueba (14 nov 2026, conversación "chats", `app.js?v=150`, migración 071)
- **Revisión diaria** (`api/cron_revisar_conversaciones_bot.php`, correr 1
  vez al día en la madrugada; `?fecha=AAAA-MM-DD` opcional, default
  ayer): toma cada teléfono donde el cliente escribió y el bot contestó
  ese día (máx. 40 por corrida) y llama `ia_revisar_conversacion_bot()`
  (`api/ia_helpers.php`, Sonnet): le pasa la conversación del día + 12
  mensajes previos de contexto + el TEXTO OFICIAL de cada artículo de la
  LFT que el bot citó (`ia_articulos_citados()` + `lft_consultar_articulos()`)
  + el bloque CRITERIOS DEL DESPACHO del prompt del bot
  (`ia_criterios_despacho_texto()`, se extrae solo del prompt, así que
  cualquier criterio nuevo que se agregue ahí lo revisa también). Guarda
  en `bot_revisiones` (migración 071, único por teléfono+día, no re-paga);
  push al Administrador solo si hay gravedad "alta" (`?revision_bot=1`
  lleva a Equipo). Nunca le escribe al cliente.
- **Panel** "Conversaciones del bot a revisar" en Equipo (entre
  "Actividad de la IA" y "Credenciales de portales"): hallazgos con la
  frase del bot, el problema y qué debió decir; botones "Ver
  conversación" (reusa `abrirConversacionDesdeNotificacion()`), "Ya lo
  atendí" y "No es error" (`api/bot_revision_resolver.php`). Lista:
  `api/bot_revisiones_list.php` (solo Administrador).
- **Casos de prueba**: `php scripts/pruebas_bot.php` -- 37 casos
  sacados de errores reales (precio $299, cita "de hoy" de la clienta de
  Oxxo, curso pagado, Norma/20 días de confianza, vacaciones excedentes
  vs prescritas, normalización de artículos LFT, reglas del prompt que
  no se deben perder, herramientas del bot). SQLite en memoria, sin
  costo. **Correrlo antes de mandar cualquier cambio del bot.**
- Criterio nuevo en el prompt: domingos -- Art. 71 (prima dominical
  "por lo menos" 25%) vs Art. 73 (domingo que es su descanso: salario
  doble), preguntar cuál aplica.
- 071 corrida y archivos subidos (14 nov 2026). Cron Job dado de alta en Ferozo → Tareas programadas (minuto 0, horas 1-5, todos los días): `php /home/c1361311/public_html/sistema/api/cron_revisar_conversaciones_bot.php`. **Pendiente**: confirmar la primera corrida automática y
  Cron Job y correrlo una vez a mano. Tope de prima de antigüedad (Art.
  486): hoy usa el salario mínimo vigente; falta criterio del Lic. sobre
  fecha de despido vs fecha de pago. Respuestas duplicadas del bot
  cuando el cliente manda 2 mensajes seguidos: sin tocar todavía.

## Primera corrida real de la revisión diaria del bot + criterios nuevos (14 nov 2026, conversación "chats")
- **Costo y plan acordado (14-nov-2026)**: revisar todas (~170/día) cuesta
  ~$3-5 USD/día (~$1,700-2,900 MXN/mes); una muestra de 40/día ~$550 MXN/
  mes. Acordado con el usuario: revisar TODAS ~2 semanas y después bajar a
  muestra cambiando solo la tarea de Ferozo de horas "1-5" a "1" (sin
  tocar código). Recordatorio programado en la sesión "chats" para el 28
  nov 2026.
- Corrida real (13-nov): 174 conversaciones del día, 78 revisadas en 2
  corridas, 12 marcadas. Confirmados con el texto oficial de la LFT como
  errores REALES del bot: cierre de empresa tratado como "despido
  injustificado" con Art. 47 (correcto: Arts. 434-436), Art. 132 fracc.
  XXVII Bis citado para embarazo (es paternidad; lo correcto es Art. 166 /
  170-I), una baja convertida en "rescisión Art. 51" para sumar 20 días,
  "2 días prescritos" (ya corregido antes, la conversación era previa) y
  un seguimiento que ofreció "agendar" a quien ya tenía horario apartado.
  Falsas alarmas del revisor: montos dichos en condicional, prima de
  antigüedad "con 15 años" en despido (no aplica), "no consta que usó la
  herramienta", y el mensaje automático de fuera de horario.
- Arreglos: 4 criterios nuevos en el prompt del bot (cierre de empresa,
  despido vs rescisión, embarazo, montos condicionados + riesgo de causal
  de despido); calibración del revisor (lista de "NO marques" + descarta
  hallazgos con corrección "N/A"); `cron_seguimiento_calculadora.php` ya
  no escribe si el cliente apartó cita después del cálculo. 41 casos en
  `scripts/pruebas_bot.php`.
- **Pendiente**: caso 5212221603610 -- el bot dio prima de antigüedad de
  $64,361 con salario $680 sin el tope del Art. 486 (la calculadora sí
  topa a 2x `configuracion.salario_minimo_diario`); falta ver si el valor
  guardado en `configuracion` es otro o si el bot calculó a mano. Caso
  5215626719440 (link de pago reenviado): sin confirmar si fue error.
  Pendiente decidir si el despacho atiende asesorías a EMPRESAS (el bot
  dijo que no y después, por instrucción del abogado, sí cobró una).

## Reforma de jornada (DOF 01-05-2026) es GRADUAL + empresas + montos solo de la calculadora (14 nov 2026, conversación "chats", migración 072)
- **OJO, afecta todo lo que hable de jornada/horas extra**: la LFT vigente
  dice Art. 59 = 40 h/semana y Art. 66 = hasta 12 h extra/semana al
  doble, Art. 68 = excedente (hasta 4 h más) al triple, y el Art. 67 YA
  NO regula horas extra (solo las de siniestro del Art. 65). Pero los
  transitorios del decreto (Segundo y Cuarto) lo hacen GRADUAL desde el 1
  de enero de cada año: 2026 = 48 h y 9 extra; 2027 = 46/9; 2028 =
  44/10; 2029 = 42/11; 2030 = 40/12. La 070b solo traía articulado (sin
  transitorios), así que el bot habría leído "40 horas" como vigente en
  2026. Migración **072_lft_transitorios_jornada.sql** agrega esa nota al
  final del texto de los Arts. 59 y 66 (070b ya regenerada con la nota
  incluida, por si se recarga).
- Prompt del bot (CRITERIOS DEL DESPACHO): jornada/horas extra con la
  tabla gradual; montos SOLO de `calcular_estimado_liquidacion` (caso
  real 5212221603610: prima de antigüedad $64,361 calculada a mano sobre
  $680 sin el tope del Art. 486 -- `configuracion.salario_minimo_diario`
  confirmado en 315.04, la calculadora sí topa); y el despacho SÍ asesora
  empresas/patrones (confirmado por el usuario 14-nov-2026), misma
  asesoría telefónica. Revisor: ya no marca aguinaldo proporcional, el
  aviso de límite de mensajes, seguimientos automáticos ni asesoría a
  empresas. 44 casos en `scripts/pruebas_bot.php`.
- **Pendiente para la sesión "sistema y bot completo"**: la plantilla
  Word de la demanda (`data/plantilla_demanda.docx`, no está en el repo)
  y Rescisión Art. 51 pueden citar el Art. 67/68 con la regla vieja de 9
  horas al doble -- revisar el texto contra la LFT vigente (Art. 66 doble,
  Art. 68 triple, tabla gradual) antes de la siguiente demanda con horas
  extra. Tope de prima de antigüedad en zona libre de la frontera norte:
  la calculadora solo usa el salario mínimo general (Art. 486 dice el del
  área geográfica del lugar de trabajo).

## Tope de prima de antigüedad con el salario mínimo de la FECHA DEL DESPIDO (14 nov 2026, conversación "chats", `app.js?v=151`)
- Criterio del Lic. Rubén Buerhend: el tope de la prima de antigüedad
  (doble del salario mínimo, Arts. 162-II y 486 LFT) usa el salario mínimo
  vigente a la fecha del despido, no el de la fecha de pago.
- `salario_minimo_diario_en_fecha()` (`api/liquidacion_calculadora.php`) y
  `salarioMinimoEnFecha()` (`assets/app.js`, `computeLiquidacion()`), con
  la misma tabla `SALARIOS_MINIMOS_HISTORICOS` (zona general CONASAMI
  2016-2025). Año en curso o posterior = valor de Equipo
  (`configuracion.salario_minimo_diario`). **Cada enero, al capturar el
  salario mínimo nuevo en Equipo, agregar el del año que termina a las 2
  tablas** (PHP y JS). Zona libre de la frontera norte sigue sin
  manejarse. 2 casos nuevos en `scripts/pruebas_bot.php` (46 en total).

## Respuestas DUPLICADAS del bot: causa real y arreglo (14 nov 2026, conversación "chats")
- Diagnóstico con SQL real (50 casos en 7 días, 2 respuestas casi
  idénticas con 0-130 s de diferencia, sin mensaje del cliente en medio):
  casi TODOS a los :00/:15/:30/:45. Causa: `cron_reanudar_horario.php`
  (cada 15 min, en horario de atención) contestaba cualquier conversación
  cuyo último mensaje fuera del cliente -- incluso las que el flujo
  normal del webhook estaba contestando en ese momento (espera de 30 s +
  IA + retraso natural de hasta ~2 min). Arreglo:
  `reanudar_conversacion_fuera_horario()` (`api/whatsapp_procesar.php`)
  ya no toca conversaciones cuyo último mensaje entrante tenga menos de
  10 min (medido con `NOW()` de MySQL, no `time()` de PHP).
- 2ª causa: el aviso de fuera de horario salía 2-3 veces en el mismo
  segundo cuando el cliente mandaba varias burbujas seguidas (carrera
  entre webhooks). `whatsapp_avisar_fuera_horario_si_aplica()` ahora usa
  `GET_LOCK()` por teléfono y registra el aviso antes de mandarlo.
- **El MySQL del servidor NO soporta funciones de ventana** (`LAG() OVER`,
  error #1064) -- para SQL de diagnóstico usar subconsultas/JOIN.
- Queda sin tocar (no es duplicado del bot): recordatorio de pago de 10
  min que salió después de que el bot dijo que ese horario ya se ocupó
  (caso 5215626719440, 13-nov).
- Verificar con el mismo SQL de diagnóstico unos días después de subir
  `api/whatsapp_procesar.php` (debería bajar a ~0 los casos a los :00/:15).
- **Confirmado arreglado (17 nov 2026)**: el SQL de diagnóstico desde el 15-nov dio 0 duplicados
  (antes ~50/semana); los 6 pares que salieron son mensajes legítimos (pago confirmado del
  webhook, recordatorio de pago, 2 PDF mandados a mano). El revisor ahora también trata como
  automáticos el recordatorio de pago y el aviso "PDF de cálculo enviado".

## Zona Libre de la Frontera Norte en el tope de la prima de antigüedad (14 nov 2026, conversación "chats", `app.js?v=152`)
- Art. 486 LFT: el tope es el doble del salario mínimo del área geográfica
  del LUGAR DONDE SE PRESTA EL TRABAJO. Idea del usuario: usar la lada del
  teléfono como pista para que el bot PREGUNTE (no para decidir solo).
- `api/liquidacion_calculadora.php`: `SALARIOS_MINIMOS_FRONTERA_HISTORICOS`
  (CONASAMI ZLFN 2019-2025), `salario_minimo_frontera_actual()` (config
  `salario_minimo_diario_frontera`, respaldo 440.87 = 2026),
  `LADAS_FRONTERA_NORTE` + `telefono_ciudad_frontera()`, parámetro nuevo
  `$zonaFronteraNorte` (último) en `calcular_estimado_liquidacion()`; el
  resultado ahora trae `prima_antiguedad_tope_salario_minimo` y
  `..._zona_salario_minimo`.
- Bot: parámetro `zona_libre_frontera_norte` en la herramienta; si la
  lada es fronteriza y no lo marcó, el resultado trae `aviso_zona_frontera`
  para que pregunte dónde TRABAJABA y recalcule; `consultar_salario_
  minimo_vigente` devuelve ambas zonas; criterio nuevo en el prompt.
- Equipo → "Parámetros del cálculo de liquidación": campo nuevo "Zona
  libre de la frontera norte" (`configuracion_set.php` lo acepta).
  **Cada enero actualizar ambos valores y agregar el año que termina a
  las tablas históricas (general y frontera).**
- La pestaña "Cálculo de liquidación" de cada expediente
  (`computeLiquidacion()` en app.js) usa solo la zona general -- A
  PROPÓSITO: decisión del usuario (14-nov-2026), el despacho no lleva
  juicios en la frontera; la zona frontera solo aplica al bot de WhatsApp.
- 52 casos en `scripts/pruebas_bot.php`.

## Primera revisión AUTOMÁTICA del bot (madrugada 15-nov-2026) + arreglos (conversación "chats")
- Corrió sola con el Cron Job: 62 conversaciones marcadas (13 y 14 nov).
  Verificadas contra el texto oficial: MUCHAS eran falsas alarmas por un
  error del revisor -- recortaba cada artículo a 3,000 caracteres, así que
  no veía el final del Art. 47 (aviso escrito, que sí existe) ni la fracc.
  XIII del 684-E (cosa juzgada, que sí existe), y "685 Ter" se leía como
  "685". También marcaba mal el Art. 48 para los 3 meses (es correcto), el
  documento membretado (producto real, el usuario lo confirmó) y "no
  consta que usó la calculadora".
- Errores REALES confirmados: Art. 47-II para embriaguez (es XIII), Art.
  159 para sustitución patronal (es 41), Art. 110-VI para descuentos (es
  110-I), Art. 56 para cambio de condiciones, Art. 67 para horas extra,
  artículos de la LSS citados de memoria (54, 15-I, 42 Bis), cálculos de
  finiquito a mano, rescisiones calculadas con hechos viejos o futuros
  (sin hecho concreto dentro de 30 días, Art. 52), "para hoy miércoles 14
  de octubre" dicho el 13 de noviembre, rechazo de CFE y de un patrón
  (conversaciones previas a los arreglos), seguimientos que insistían a
  quien dijo "ahorita no tengo dinero".
- Arreglos (`api/ia_helpers.php`, `api/lft_helpers.php`,
  `api/cron_revisar_conversaciones_bot.php`, `api/whatsapp_helpers.php`):
  revisor con el texto COMPLETO de cada artículo (hasta 12) y aviso de que
  solo tiene la LFT; `ia_articulos_citados()` reconoce Ter/Quáter; "47-X"
  se trata como fracción X; el revisor recibe los cálculos reales de
  `calculos_liquidacion` de ese día para saber si las cifras salieron de
  la herramienta; más "NO marques". Bot: guía de artículos VERIFICADOS
  para los temas más citados, prohibido citar números de otras leyes
  (LSS, LISR, Constitución), regla de rescisión (hecho concreto, dentro de
  30 días, que encaje en el Art. 51; nada de "espera a que el patrón lo
  haga"). Candado de cita: bloquea "hoy <fecha que no es hoy>" cuando
  habla de cita/asesoría agendada. `whatsapp_texto_parece_declinar()`
  también reconoce "ahorita no / no tengo dinero / cuando me paguen"
  (lo usan los seguimientos de calculadora y cursos). 58 casos en
  `scripts/pruebas_bot.php`.
- Archivos subidos por el usuario y panel limpiado (98 filas del 13-14 nov
  marcadas 'atendida') el 15-nov-2026.
- Precio del documento membretado confirmado por el usuario: **$49**
  (igual que `DOCUMENTO_CALCULO_PRECIO`, sin cambios).
- **Pendiente de decisión del usuario**: el prompt le dice al bot que el documento
  hace que RH "hasta le ofrezca una cantidad mayor" -- el revisor lo marca
  como falsa expectativa; propuesta de suavizarlo sin cambiar la venta.

## Revisión forzada del 14-nov-2026: calibración 2 del revisor (15 nov 2026, conversación "chats")
- Re-corrida del 14-nov (39 revisadas, 22 marcadas). Verificado contra la LFT oficial:
  ~13 errores REALES del bot, casi todos de antes de los arreglos del 15-nov (rescisiones
  con "mejor me salí"/hechos viejos, "espera a que el patrón lo haga", cierre de empresa
  como despido, rechazo de un patrón, Art. 110-VI, Arts. 173-178/42-II/123 Constitucional,
  LSS 54, salarios caídos a mano). ~9 falsas alarmas: seguimientos automáticos y avisos del
  webhook de pago (se guardan con respondido_por='ia' igual que el bot), "pago confirmado"
  (el candado ya lo bloquea si no existe), vacaciones prescritas a 18 meses (es el criterio
  del sistema), advertencia de cosa juzgada antes de firmar, detalles de tono.
- Arreglos: `ia_es_mensaje_automatico()` etiqueta como SISTEMA los mensajes automáticos para
  que el revisor no los revise; 4 "NO marques" nuevos. Hueco REAL nuevo en el bot: decía "tu
  plazo ya venció" sin preguntar si presentó solicitud de conciliación (Art. 518 la
  suspende) -- criterio nuevo en CRITERIOS DEL DESPACHO. `whatsapp_texto_parece_declinar()`
  ahora reconoce "no tengo economía" / "no me alcanza". 61 casos en `scripts/pruebas_bot.php`.
- **Pendiente del usuario**: revisar a mano la conversación 5215615230823 (Leonora B PR): el
  revisor dice que el cliente señaló una "cuenta falsa" y el abogado contestó "sí, exacto esa
  cuenta es falsa" -- probablemente alguien suplantando al despacho en redes, no el link de
  Mercado Pago del sistema (ese sí es real); confirmar.

## Revisión del 14-nov, 2ª tanda (40 conversaciones más, 15 nov 2026, conversación "chats")
- 21 marcadas. Errores REALES nuevos (no cubiertos antes): Art. 51 fracc. II citada para
  falta de pago (es V) / reducción (es IV); paro técnico "deben pagarte salario normal"
  (Arts. 427/430: la indemnización la fija el Tribunal, máx. 1 mes); "28 días para demandar"
  a quien ya dijo que fue a conciliación; el bot insistió 3 veces en que el cliente se
  equivocaba con su salario y el error era del bot; cambio de horario tratado como causa
  expresa del Art. 51 (a lo mucho fracc. X). Criterios nuevos para todo eso en CRITERIOS DEL
  DESPACHO + tabla del Art. 76 escrita completa. El resto: patrones ya corregidos antes
  (LSS 15-I, 110-VI, cierre de empresa, rescisiones sin hecho reciente) y duplicados a las
  :15/:30 (de antes del arreglo de duplicados; se verifica el 17-nov).
- Falsa alarma del revisor corregida: decía que con 6 años son 20 días de vacaciones; el
  Art. 76 vigente da 22 (6 a 10 años). Se le dio la tabla en "NO marques".
- **Duda para el Lic. Rubén (no afirmada)**: INCan rechazado como Apartado B. El revisor dice
  que "típicamente es A"; no está verificado -- la ley orgánica de los Institutos Nacionales
  de Salud podría remitir a la LFTSE. Lo verificable: el bot no debe rechazar sin preguntar
  ISSSTE/IMSS y escalar si hay duda (criterio que ya existe). 65 casos en pruebas_bot.php.

## Gerente de $65,000/mes: SDI dividido entre 15 + promesa de abogado sin escalar (15 nov 2026, conversación "chats")
- Caso real (cliente de TikTok, gerente regional, ingreso 09-jun-2025): el bot tomó del recibo
  quincenal el salario diario ($2,166.67) y el SDI ($2,349.11) y los DIVIDIÓ entre 15 -- dio
  $20,648; la calculadora con los datos reales da $283,252.94 (finiquito $71,833.04 + 3 meses
  $211,419.90, baja hipotética 14-nov-2026). Insistió 3 veces en que el cliente se equivocaba.
  REGLA DURA nueva en el prompt (junto a la del SDI del recibo): esos datos ya son diarios.
- Además escribió "voy a pedir que un abogado revise tu caso" SIN llamar escalar_a_humano: nadie
  fue avisado, el bot no se pausó y al día siguiente el seguimiento automático le ofreció la
  asesoría/documento. Candado nuevo en código: `ia_texto_promete_abogado()` -- si el texto final
  promete que un abogado lo contactará y el prospecto no está pausado, se escala solo
  (`ia_registrar_prospecto_atorado`, log `[promesa_abogado_escalada]`). 68 casos en pruebas_bot.php.

## Revisión del 15-nov (12 conversaciones, 5 marcadas) (16 nov 2026, conversación "chats")
- Reales: plazo de 56 días a una clienta que mencionó un citatorio (posible conciliación) sin
  preguntar; "demanda ante el Centro de Conciliación" (es solicitud; la demanda va al Tribunal);
  "tienes todo el derecho de no presentarte" en día de descanso obligatorio (Art. 75: los
  designados quedan obligados a trabajarlo). Criterios nuevos en el prompt.
- Falsas alarmas calibradas en el revisor: el triple por día de descanso obligatorio SÍ es
  correcto (Art. 75, "independientemente del salario... un salario doble"); salario diario
  distinto del SDI de la calculadora no es error; no exigir pregunta de conciliación si el
  despido fue ese día. Sin confirmar: plazo de "3 días" para audiencia presencial (caso
  5215630024082). `debug_enviar_pdf_calculo.php` ahora es vista previa; manda solo con &enviar=1.
  70 casos en pruebas_bot.php.

## Revisión del 16-nov (36 conversaciones, 8 marcadas = 22%, bajó de 55% y 42%) (17 nov 2026, conversación "chats")
- 2 candados nuevos en código (`api/ia_helpers.php`), por errores que se repitieron pese a
  la regla del prompt: (1) `ia_texto_anuncia_sin_hacer()` -- si el texto final solo anuncia
  "déjame calcular/confirmar" sin llamar herramientas, se le pide una vez que lo haga en ese
  mismo turno (log `[anuncio_sin_hacer]`; 2 casos reales: cálculo nunca entregado y
  fundamento de sustitución patronal nunca explicado). (2) `ia_conversacion_menciona_conciliacion()`
  -- si el cliente mencionó conciliación/citatorio/audiencia y `calcular_plazo_demanda` se
  llamó sin fecha de solicitud, el resultado trae `aviso_conciliacion` (caso real: "ya fui a
  conciliación, audiencia el 2 de diciembre" y el bot le dijo que aún debía presentar su
  solicitud). Prompt: el Art. 56 no es para cambios de horario/condiciones (error repetido).
- Otros reales ya cubiertos por reglas del 15-16 nov (rescisión por cambio de jornada, Art. 73
  en vez de 75 para día festivo, horas extra calculadas a mano). 73 casos en pruebas_bot.php.

## El bot de WhatsApp pasa a Sonnet 5.5 con razonamiento bajo (17 nov 2026, conversación "chats")
- Decisión del usuario tras preguntar por qué el bot se equivoca tanto: el bot contestaba con
  `claude-sonnet-5` y el razonamiento APAGADO. Ahora `ia_llamar_claude()` usa
  `IA_MODELO_BOT = 'claude-sonnet-5-5'` con `thinking: adaptive` + `output_config.effort = 'low'`
  (`IA_MODELO_BOT_ESFUERZO`), `max_tokens` 8000 y timeout 60 s. Solo el bot: el resto del
  sistema sigue con `IA_MODEL` (Sonnet 5).
- Costo estimado (con la bitácora real, ~$12-14 USD/día de toda la IA): Sonnet 5.5 cobra igual
  salvo la lectura de caché, a la mitad ($0.10 vs $0.20 por millón) -- ese ahorro (~$1,900
  MXN/mes) paga casi todo el razonamiento. Esperado: de $0 a ~$1,000 MXN/mes más. Medir con
  `ia_cache_stats.log` (ahora trae `model=`) y la consola; si la revisión diaria no baja del 22%
  de conversaciones marcadas (16-nov), siguiente opción a probar: Opus 5.5.
- Los bloques `thinking` se reenvían SIN TOCAR dentro del ciclo de herramientas (la API lo
  exige); `ia_extraer_respuesta()` solo lee `text`/`tool_use`. Si Sonnet 5.5 responde con error
  4xx o `stop_reason=refusal`, se reintenta una vez con `IA_MODELO_BOT_RESPALDO`
  (`claude-sonnet-5`, sin razonamiento) y queda en `ia_debug.log`. Para regresar del todo:
  `IA_MODELO_BOT = 'claude-sonnet-5'` y `IA_MODELO_BOT_ESFUERZO = null`.
- **Sin probar en vivo** (esta sesión no tiene la llave de la API): falta que el usuario suba
  `api/ia_helpers.php` y confirmar en `ia_cache_stats.log` que salen líneas `model=claude-sonnet-5-5`
  y en `ia_debug.log` que no hay `status=400`.

## Municipios donde el despacho SÍ lleva juicios (17 nov 2026, conversación "chats")
- Lista dada por el usuario (Excel "competencia"), ya en el prompt del bot (REGLA DURA junto a la
  de "cualquier estado"): CDMX + Edomex región Tlalnepantla (Tlalnepantla, Atizapán de Zaragoza,
  Cuautitlán, Cuautitlán Izcalli, Coyotepec, Huixquilucan, Huehuetoca, Isidro Fabela, Jilotzingo,
  Melchor Ocampo, Naucalpan, Nicolás Romero, Teoloyucan, Tepotzotlán, Tultepec, Tultitlán) +
  región Ecatepec (Ecatepec, Apaxco, Coacalco, Hueypoxtla, Jaltenco, Nextlalpan, Tecámac,
  Tequixquiac, Tonanitla, Zumpango). Cuenta el municipio del CENTRO DE TRABAJO, nunca el
  domicilio del trabajador (aclaración explícita del usuario). Fuera de la lista: se orienta y se
  ofrece la asesoría de $499 normal; el bot NO menciona la zona por su cuenta (ajuste del
  usuario el mismo día: "si no perdemos asesorías") -- solo si la persona pregunta si el
  despacho puede llevar/representar su caso aclara que allá no se litiga; nunca promete
  representación fuera de la lista. 2 casos nuevos en `scripts/pruebas_bot.php` (76).
- CDMX se tomó completa (todas las alcaldías) porque el usuario dijo "los municipios que ya
  conoces" y el bot ya decía CDMX; si alguna alcaldía no se lleva, corregir la lista.

## Coach de tenis (Club Alpino): 4 arreglos del bot (17 nov 2026, conversación "chats")
- Conversación real 13-nov (5215544741530): despido 14-ago-2026, ingreso feb-2020, ~$80k/mes
  variable pagado por los socios. Errores del bot y arreglos:
  1. Dijo que el plazo de 2 meses (Art. 518) venció y aun así sumó la indemnización de 3 meses a
     "casi $367,000 en juego". Candado en código: `ia_bot_dijo_plazo_despido_vencido()` -- si un
     mensaje anterior del bot ya dijo eso, el resultado de `calcular_estimado_liquidacion` trae
     `aviso_plazo_vencido` (separar lo vigente; no presentar la indemnización como cobrable).
  2. Citó el Art. 289 (agentes de comercio) para salario variable. La REGLA DURA de ingreso
     variable ahora usa el Art. 89 (promedio de los 30 días efectivamente trabajados). Ya no se
     cita el 289 en la herramienta `calcular_sdi_con_variable` (su cálculo a 365 días NO se tocó:
     sigue pendiente la decisión del usuario sobre bonos anuales).
  3. No sumó vacaciones de años anteriores aunque el cliente dijo que nunca las tomó: criterio
     nuevo en CRITERIOS DEL DESPACHO (pasarlas en `dias_vacaciones_anteriores`).
  4. Afirmó que "sin duda" es trabajador aunque le pagaban los socios: criterio nuevo (Arts. 82
     y 21, no afirmarlo, lo valora el abogado). 80 casos en `scripts/pruebas_bot.php`.

## Revisión del 17-nov (104 conversaciones, 13 marcadas = 12.5%, bajó de 22%) (18 nov 2026, conversación "chats")
- Día mixto: la versión con Sonnet 5.5 se subió a media tarde del 17-nov.
- Reales y arreglados en CRITERIOS DEL DESPACHO: cierre de empresa llamado otra vez "despido
  injustificado" (ahora prohibida la frase para cierres; extra de contrato colectivo no "se suma");
  "la mitad = 45 días = $14,934" validado con cifras (no hay proporción legal para negociar);
  estimado de rescisión con 20 días a quien dejó de ir por su cuenta "para ponerlo como despido";
  solo el aguinaldo con monto teniendo el desglose completo de la calculadora; "tu asesoría de hoy
  ya quedó realizada" + ofrecer otra de $499 sin preguntar cómo le fue; Art. 20 por "presunción"
  (es el 21) y Art. 289 para comisiones (es el 84) -- agregados a la guía de artículos verificados.
- Falsas alarmas calibradas en el revisor: decir que el despacho no lleva juicios en Boca del Río
  (correcto, lista de municipios); conversación sin respuesta del bot; Art. 133-XII para
  hostigamiento (su texto dice "hostigamiento y/o acoso sexual" y el 3 Bis define hostigamiento
  general -- cita defendible); vacaciones que "pueden estar" prescritas en condicional.
- Sin cambio (mejoras, no errores): no analizar a fondo la rescisión de un caso ya en conciliación,
  no recalcular con aguinaldo/prima de contrato colectivo, "estoy cubierto en vacaciones". 84 casos.

## Revisión del 18-nov: CORRECCIÓN -- fueron 35 de 146 marcadas, no 0 (19 nov 2026, conversación "Bot")
**Entrada anterior de esta misma sección quedó MAL** -- "0 revisada(s) con IA, 0 con algo que
revisar" de la segunda corrida manual solo significaba que ESA corrida puntual no encontró nada
NUEVO que revisar (el 100% de las 146 ya se había revisado en la primera corrida, la que mostró
HTTP 500 en el navegador del usuario pero sí terminó del lado del servidor) -- no que el día
completo saliera limpio. El usuario pegó el panel real: **"Conversaciones del bot a revisar 35
... Última revisión: 18 nov 2026 -- 146 conversación(es) revisada(s), 35 con algo que revisar."**
Tendencia real: 55%/42% → 22% (16-nov) → 12.5% (17-nov) → **24% (18-nov, 35/146)** -- SUBIÓ, no
bajó. El cambio a Sonnet 5.5 (subido a media tarde del 17-nov) **NO** parece haber ayudado tanto
como se pensó -- o el 18-nov tuvo un lote de casos genuinamente más difícil. Pendiente seguir
viendo el 19-nov antes de sacar conclusiones firmes sobre el modelo.
- **Revisión de las 35 marcadas (verificadas por categoría, no caso por caso) -- patrones reales
  que se repiten varias veces, candidatos a candado de CÓDIGO (no solo prompt, ya que el prompt
  ya los prohibía y el bot los sigue violando)**:
  1. **"Vacaciones anteriores" no disfrutadas -- el criterio del 17-nov (caso "coach de tenis")
     sigue sin aplicarse de forma consistente**: al menos 4-5 de las 35 marcadas son el cliente
     diciendo "nunca tomé vacaciones" / "solo tomé N días" y el bot sacando solo el proporcional
     del periodo en curso en vez de pasar los días de años YA CUMPLIDOS a
     `dias_vacaciones_anteriores`. El patrón más repetido de los 35.
  2. **Plazo de demanda calculado A MANO (sin `calcular_plazo_demanda`) con cifras a veces
     CONTRADICTORIAS en el mismo mensaje** (un caso dio "37 días" y "15 días" en el mismo turno;
     otro reconvirtió "2 meses" en "60 días" explícitamente, violando una regla YA escrita en el
     prompt desde el 18-nov por el caso del documento de $49).
  3. **Montos calculados A MANO en vez de con `calcular_estimado_liquidacion`**: horas extra con
     2 divisores distintos dando 2 totales muy distintos ($10,400 vs $15,600 en el mismo
     mensaje), salarios caídos a mano, validar cifras del patrón sin correr la calculadora.
  4. **Cita de jurisprudencia con número de registro inventado** sin haber llamado
     `buscar_jurisprudencia` ese turno -- regla ya en el prompt, violada igual.
  5. **Montos de exención de ISR afirmados de memoria** (cifras de "veces UMA") -- regla ya en
     el prompt desde el 14-nov, violada igual.
  6. Oferta de la asesoría de $499 repetida 2-3 veces en la misma conversación aunque el cliente
     ya contestó o no la mencionó -- patrón ya señalado el 17-18 nov, sigue repitiéndose.
  7. Varios casos de juicio legal más fino (rescisión vs abandono, despido disfrazado de
     renuncia, riesgo de trabajo/incapacidad) donde el bot no advirtió con suficiente fuerza la
     ambigüedad de los hechos -- más difíciles de resolver con un candado determinista, mejor
     reforzar el prompt caso por caso según seAtrapado en la próxima revisión.
- **Candados de código construidos (19 nov 2026, hecho desde "Bot" -- pedido explícito del
  usuario: "si hay que hacer lo necesario para que no cometa esos errores y avisa a las demas
  conversaciones")**. Antes de tocar `api/ia_helpers.php`/`scripts/pruebas_bot.php` (normalmente
  territorio de la conversación "chats") se revisó `git log HEAD..origin/<otra-rama>` contra las
  2 otras ramas (`claude/hola-i5mb6q`, `claude/magical-albattani-ephif7`) -- ninguna tenía commits
  sin fusionar sobre estos 2 archivos, así que se editó directo sin riesgo de pisar nada
  concurrente. Mismo patrón ya usado varias veces en este archivo (funciones `ia_texto_*()` +
  aviso inyectado en el resultado de la herramienta + refuerzo del prompt). Cubre los patrones
  1-5 de la lista de arriba (el 6 y 7 quedaron solo como refuerzo de prompt, ver abajo):
  1. **Vacaciones anteriores no disfrutadas**: `ia_cliente_dijo_no_tomo_vacaciones()` detecta en
     el historial si el cliente dijo "nunca tomé vacaciones"/"no las disfruté"; si es así y el
     cálculo no trae `dias_vacaciones_anteriores > 0`, se inyecta `aviso_vacaciones_anteriores`
     en el resultado de `calcular_estimado_liquidacion` pidiendo recalcular con esos días (o, si
     lleva menos de 1 año, aclarar explícitamente que el proporcional ya cubre todo). Reforzado
     también en el prompt (bullet de "Vacaciones nunca disfrutadas").
  2. **Plazo en días calculado a mano**: 2 funciones nuevas, ambas recortan solo la oración
     problemática con el helper compartido `ia_texto_quitar_oraciones()` (nunca reemplazan todo
     el mensaje, a diferencia de los candados de pago/cita de hace unos días -- aquí el resto del
     mensaje suele estar bien, es una sola frase la que sobra):
     - `ia_texto_convierte_meses_en_dias()` -- detecta la oración "Son N días." (propia, tras un
       punto) junto con contexto de demanda/plazo/Tribunal en el mismo mensaje (no exige que la
       palabra "meses" aparezca en ese mismo mensaje -- en el caso real el plazo de "2 meses" se
       había mencionado en un turno anterior).
     - `ia_texto_da_plazo_en_dias_sin_herramienta()` -- detecta "quedan"/"quedarán"/"plazo" + un
       número de días a hasta 25 caracteres de distancia (cubre variantes como "el plazo es de 24
       días", no solo "plazo de N días"), solo cuando `calcular_plazo_demanda`/
       `calcular_plazo_prestaciones` NO se llamaron en esa respuesta (nuevos flags
       `$usoCalculoPlazo`).
  3. **Montos calculados a mano**: sin candado de código nuevo (difícil de detectar por texto sin
     falsos positivos) -- solo refuerzo de prompt (bullet "Montos": prohíbe 2 totales distintos de
     horas extra con divisores distintos, y validar cifras del patrón/informales sin correr la
     calculadora).
  4. **Jurisprudencia citada de memoria**: `ia_texto_cita_jurisprudencia_concreta()` detecta
     número de registro/"tesis aislada"/afirmaciones concretas de criterio judicial; si
     `buscar_jurisprudencia` NO se llamó en la respuesta (`$usoBuscarJurisprudencia`), se recorta
     la oración.
  5. **Exención de ISR inventada**: `ia_texto_afirma_exencion_isr()` detecta cifras de "veces
     UMA"/montos exentos afirmados como dato -- se recorta la oración (ya existía la regla en el
     prompt desde el 14-nov, el bot la seguía violando).
  6. **Oferta repetida de $499**: solo refuerzo de prompt (bullet "No suenes a robot": prohíbe
     repetir el precio en mensajes consecutivos).
  7. **Casos de juicio legal fino** (rescisión vs abandono, despido disfrazado de renuncia,
     riesgo de trabajo): sin tocar -- más difíciles de resolver con un candado determinista,
     reforzar caso por caso en la próxima revisión si se repiten.
  - 11 casos nuevos en `scripts/pruebas_bot.php` (sección "16) Revisión del 18-nov..."), **98 de
    98 casos pasan** (incluyendo los 87 ya existentes). `php -l` limpio en `api/ia_helpers.php`.
  - Sin cambios de base de datos, sin tocar `app.js` -- no aplica cache-bust ni
    `verificar_tags.js` (no toca `mergeTags()`/tags de demanda). **Sin probar en vivo todavía** --
    esta sesión no tiene acceso a la base de datos ni a la llave de la API, así que no se puede
    confirmar con un caso real. Falta que el usuario suba `api/ia_helpers.php` y que la próxima
    revisión diaria automática (madrugada del 20-nov, sobre las conversaciones del 19-nov) se vea
    si estos 5 patrones (sobre todo el 1 y el 2, los más repetidos) bajan de los ~9-10 casos del
    18-nov. Si alguno de los 2 candados que recortan oración (`ia_texto_convierte_meses_en_dias`/
    `ia_texto_da_plazo_en_dias_sin_herramienta`) corta de más y deja un mensaje confuso en un caso
    real, avisar para ajustar la ventana de `[^.\n]{0,25}` o el patrón exacto.

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

## Documento de $49: un link nuevo escondía un pago ya confirmado (18 nov 2026, conversación "chats")
- Caso real 17-nov (cajero de Los Mochis): pagó el 1er link, el webhook le mandó el PDF (1:13 pm),
  el bot recalculó (corrigió días devengados) y generó un 2º link; al decir "ya pagué en el primer
  link", `ia_resultado_consultar_compra_documento_calculo()` solo miraba la compra más reciente (la
  pendiente) y el bot dijo que no aparecía el pago. Ahora busca primero la CONFIRMADA más reciente,
  y `ia_resultado_confirmar_compra_documento_calculo()` no genera otro cobro si ya pagó en los
  últimos 30 días (`ia_compra_documento_confirmada_reciente()`): si los datos cambiaron, escala para
  que el abogado mande el documento corregido. Prompt: no convertir "2 meses" en "60 días".
  87 casos en `scripts/pruebas_bot.php`.

## Robot Edomex correo: "primera barrida" nunca cargaba el historial completo de la bandeja (18 nov 2026, conversación "Bot")
Reportado por el usuario: el expediente **Melesio Aguilar Romero vs Grupo Tensa, S.A. de C.V., Exp.
1056/2026** (dado de alta en el sistema desde el 2 de junio 2026) trajo bien el acuerdo de HOY
(18-nov) al correr el robot a mano, pero nunca había reportado un acuerdo real del 28-oct-2026
(Acuerdo 17552/2026, confirmado con captura real de la notificación -- mismo expediente, mismo
número, sin error de captura).
- **Causa real, encontrada revisando el código** (no era credenciales -- ya confirmadas guardadas
  desde el 8-nov en el panel "Credenciales de portales por abogado" -- ni el expediente dado de
  alta tarde): `procesarCuenta()` solo contaba (`filas.count()`) las filas YA renderizadas de la
  bandeja de Carbonio al cargar la página -- nunca hacía scroll para que cargara más correos
  viejos. El usuario confirmó viendo la bandeja a mano: no hay botón de "Cargar más", la lista
  solo crece con scroll infinito hacia abajo. Por eso el "barrer todo el historial" de la primera
  vez que se revisa una cuenta (`ultimoVisto === null`, ver sección "Cómo capturar acuerdos
  ANTERIORES" más abajo) en realidad solo veía el primer lote ya cargado -- cualquier acuerdo más
  viejo que ya hubiera quedado "enterrado" bajo notificaciones más recientes de otros expedientes
  se perdía para siempre, sin importar desde cuándo estuviera monitoreado el expediente.
- **Corregido**: nuevo bloque en `procesarCuenta()` (`robots/edomex_correo/edomex_correo.js`) --
  SOLO cuando `ultimoVisto === null` (primera vez para esa cuenta, o después de forzar un
  re-barrido borrando `procesados_edomex_correo.json`), hace scroll al último correo visible
  repetidamente (hasta 80 vueltas, 1.2s de espera cada una) hasta que el conteo de filas deja de
  crecer 3 veces seguidas -- recién ahí cuenta y procesa. Las corridas normales (`ultimoVisto` ya
  tiene un valor) NO necesitan este scroll: la bandeja ya viene ordenada del más nuevo al más
  viejo, así que cualquier correo nuevo real queda arriba, visible sin bajar nada.
- **Acción del usuario para este caso concreto**: ya había renombrado
  `procesados_edomex_correo.json` como respaldo (mismo patrón de "forzar re-barrido" documentado
  abajo) -- falta correr el robot una vez más con el código corregido para que esta vez sí baje
  hasta el final y encuentre el acuerdo del 28-oct. El candado de duplicados (ver sección de abajo)
  hace esto seguro: no va a duplicar nada de lo que ya tiene cargado.
- Sin cambios de base de datos, sin tocar `app.js`/`ia_helpers.php` -- no aplica
  `verificar_tags.js` ni `pruebas_bot.php` (es un robot de Node, no el bot de WhatsApp). `node
  --check` limpio.
- **Confirmado funcionando en vivo (18 nov 2026)**: el usuario corrió el robot con el archivo
  corregido -- sí reportó el acuerdo real del 28-oct-2026 para el expediente 1056/2026 (Melesio
  Aguilar Romero vs Grupo Tensa, correo `-3240`, PDF `16281236ACUD.pdf`), confirmando que el
  scroll-hasta-el-final de verdad lo encontró.
- **2º bug real encontrado de paso, mismo día**: en las 2 corridas reales, CADA correo se
  procesaba 2 VECES seguidas (mismo id de conversación, mismo PDF extraído 2 veces, "Aviso
  reportado" 2 veces) -- el locator `filas` (`text=/Notificacion Electronica Laboral/i`) hace
  match más de una vez por renglón real en el DOM de Carbonio (motivo exacto en el DOM sin
  confirmar). Esto subía el PDF 2 VECES por cada acuerdo nuevo al expediente (documento
  duplicado) -- el aviso en sí probablemente se dedupeaba solo en el servidor (candado de
  `avisos_ingest.php` ampliado el 13 nov), pero `documentos_subir_robot.php` no tiene ningún
  candado de duplicados, así que el documento sí quedaba repetido. **Corregido** con un
  candado nuevo, independiente de encontrar la causa exacta en el DOM: un `Set` de ids de
  conversación ya procesados EN ESA MISMA CORRIDA (`vistosEstaCorrida`) -- si un id ya se
  procesó, se omite sin volver a subir el PDF ni reportar el aviso otra vez, sin importar
  cuántas veces aparezca en `filas`. `node --check` limpio. **Sin confirmar en vivo
  todavía** -- falta que el usuario corra el robot de nuevo con este 2º arreglo y confirme que
  cada acuerdo ya solo sale UNA vez en el log y en la pestaña Documentos del expediente.
- **Limpieza pendiente del usuario**: los documentos duplicados que ya se subieron en las 2
  corridas de prueba de hoy (antes de este 2º arreglo) -- por ejemplo el expediente 1056/2026
  probablemente tiene 2 copias idénticas de `Acuerdo_Exp_1056-2026_-3240.pdf` -- borrar la
  copia de más a mano desde la pestaña Documentos de cada expediente afectado (780/2026,
  747/2026, 628/2026, 1056/2026, 841/2026).
- **3er ajuste, mismo día, pedido explícito del usuario tras ver que un mismo id de
  conversación reaparecía en posiciones MUY distintas del listado** (ej. expediente 1576/2025
  visto cerca del principio de la bandeja de Lindsay y de nuevo varias filas más abajo, sin
  ser un duplicado consecutivo de los que ya arregla el candado de arriba) -- "tiene que
  escrolear la lista completa y buscar en todos los acuerdos". Esto confirma que Carbonio NO
  ordena la bandeja estrictamente del más nuevo al más viejo por id de conversación (parece
  ordenar por actividad de la conversación) -- así que el atajo de "se deja de revisar en
  cuanto se encuentra uno ya visto" (`break`) ya NO es seguro: un acuerdo real podría quedar
  más abajo de uno viejo en el listado y nunca llegar a revisarse.
  - El scroll-hasta-el-final (antes solo la primera vez por cuenta) ahora se hace SIEMPRE, en
    cada corrida -- no cuesta más con el tamaño real de estas bandejas (20-22 correos en
    total por cuenta).
  - El `break` al encontrar un id ya visto se cambió a `continue` -- ya no se corta el ciclo,
    se sigue revisando la lista COMPLETA cada vez (el candado de duplicados dentro de la misma
    corrida y el de "ya visto en una corrida anterior" siguen evitando reprocesar lo mismo,
    solo que ahora sin asumir nada sobre el orden).
  - `node --check` limpio.
- **4º ajuste, mismo día, encontrado al confirmar el 3er arreglo**: en la corrida de prueba, el
  expediente 841/2026 se volvió a reportar como aviso NUEVO aunque ya se había reportado en la
  corrida inmediata anterior -- debía haberse omitido por "ya visto". Causa real: el candado de
  "ya visto" guardaba solo el id MÁS GRANDE encontrado (una "marca de agua"), asumiendo que un
  id mayor siempre es más nuevo -- la misma suposición de orden que ya se había descartado en
  el 3er ajuste (el orden de la bandeja no es confiable) también invalida esto, porque no hay
  garantía de que "mayor" signifique "más nuevo". **Corregido de raíz**: ya no se guarda un
  solo id-marca por cuenta -- se guarda el conjunto COMPLETO de ids de conversación ya
  procesados, para siempre (`estado[cuenta] = Array.from(yaProcesados)`, un arreglo en el json
  en vez de un solo valor), y se compara por pertenencia (`yaProcesados.has(id)`) en vez de por
  comparación numérica -- sin asumir nada sobre el orden ni sobre qué id es "mayor". Esto
  también reemplaza y absorbe el candado de duplicados-en-la-misma-corrida del 2º ajuste (ya
  no hace falta un Set aparte, es el mismo mecanismo). `node --check` limpio.
  - **Costo de la migración, avisado**: el archivo `procesados_edomex_correo.json` ya existente
    tiene el formato VIEJO (un solo valor por cuenta, no un arreglo) -- al cargarlo con el
    código nuevo, se interpreta como "sin historial" (arreglo vacío) y se reconstruye solo con
    lo que se vaya encontrando de aquí en adelante. Esto puede hacer que, en la PRÓXIMA corrida
    únicamente, se vuelvan a reportar como "nuevos" 1-2 avisos que ya se habían visto antes
    (el aviso en sí debería quedar bloqueado solo por el candado de duplicados de
    `avisos_ingest.php`, pero el documento sí podría subir una copia de más) -- después de esa
    corrida de transición, el archivo ya queda en el formato nuevo y no debería repetirse.
  - **Confirmado funcionando en vivo (19 nov 2026)**: la corrida de transición (formato viejo
    -> nuevo) repitió 841/2026 una vez más, como se esperaba -- la SIGUIENTE corrida (ya con el
    archivo en el formato nuevo) reportó "0 aviso(s) nuevo(s)" en ambas cuentas, sin ni un solo
    correo procesado de nuevo (ni siquiera aparecen en el log -- se omiten todos en silencio por
    `yaProcesados.has()`). Confirma que el candado de duplicados entre corridas ya funciona de
    punta a punta. **Pendiente del usuario**: borrar a mano los documentos duplicados que
    quedaron de las corridas de prueba (780/2026, 747/2026, 628/2026, 1056/2026, 841/2026 -- este
    último con una copia extra de más por la corrida de transición).

## 3 de los 5 robots llevaban sin correr solos: les faltaba su `run_diario.bat` en la máquina del usuario (20 nov 2026, conversación "Bot")
Pregunta del usuario: "como podemos saber si los robots si estan corriendo diario". Se revisó en
vivo con `schtasks` (PowerShell como administrador) en su máquina de Windows.
- Los 5 robots se veían "Listo" en la lista general (`schtasks /query /fo table`), pero el detalle
  de cada tarea (`schtasks /query /tn "<nombre>" /v /fo list`) mostró **"Último resultado: 1"**
  (código de error -- 0 es éxito) en la corrida automática del día anterior (19-nov) para 3 de los
  5: **Robot Federal** (6:40am), **Robot CDMX** (6:00am) y **Robot Edomex** (9:30am). Los otros 2
  (Buzón CDMX, Jurisprudencia) sí mostraban "0" (corrida exitosa).
- **Causa real**: a los 3 les faltaba FÍSICAMENTE su archivo lanzador `run_diario.bat` en la
  carpeta local del robot (`C:\Users\Ruben\Desktop\ROBOTS\<robot>\`) -- confirmado con `dir`, el
  archivo simplemente no estaba ahí, así que Windows ni siquiera podía encontrar qué ejecutar.
  Este archivo SÍ está versionado en este repo (`robots/federal/run_diario.bat`,
  `robots/cdmx/run_diario.bat`, `robots/edomex_correo/run_diario.bat`) -- se perdió de la carpeta
  local del usuario en algún momento sin que nadie lo notara (el flujo normal de entrega de este
  proyecto solo manda por `SendUserFile` los `.js`/`.json`/`README.md` de cada robot, nunca el
  `.bat` -- ver "Robots locales" más abajo -- así que una vez perdido de la carpeta local, ninguna
  entrega posterior lo iba a restaurar solo).
- **No se pudo precisar desde cuándo** llevaban sin correr -- `schtasks` solo guarda el resultado
  de la ÚLTIMA corrida, no el historial completo; no se revisó el Visor de eventos de Windows (que
  sí guarda el historial día por día) para acotar la fecha exacta en que desapareció cada archivo.
- **Corregido en vivo**: se recrearon los 3 archivos directo en la carpeta del usuario (con
  Notepad, abierto desde PowerShell) con el mismo contenido funcional que ya tiene este repo
  (`@echo off` + entrar a la carpeta del robot + `node <robot>.js >> robot.log 2>&1`) -- con una
  diferencia cosmética sin impacto práctico: se escribió la ruta completa a mano
  (`cd /d "C:\Users\Ruben\Desktop\ROBOTS\FEDERAL"`) en vez de `cd /d "%~dp0"` (la ruta del propio
  `.bat`) que usa la versión de este repo; funcionan igual mientras la carpeta no cambie de lugar,
  no se corrigió este detalle para no arriesgar otra ronda de Notepad por algo cosmético.
- **Confirmado funcionando de punta a punta los 3** con una corrida manual
  (`node federal.js`/`cdmx.js`/`edomex_correo.js` directo en PowerShell, sin pasar por el `.bat`):
  Federal encontró 1 aviso nuevo real (expediente 501/2026, acuerdo No. 2); CDMX y Edomex correo no
  encontraron ninguno nuevo (sin evidencia de un backlog grande acumulado, pero tampoco se puede
  descartar del todo sin saber desde cuándo faltaba el archivo).
- Sin cambios de código en este repo -- los `.bat` ya estaban bien ahí desde siempre, el bug vivía
  solo en la carpeta local de la máquina del usuario (archivo borrado/perdido fuera de git). No
  aplica `php -l`/`node --check`/`verificar_tags.js`/`pruebas_bot.php`.
- **✅ CONFIRMADO FUNCIONANDO DE PUNTA A PUNTA EN AUTOMÁTICO (20 nov 2026)**: tras pasar las 3
  horas programadas de hoy (6:00/6:40/9:30 am), el usuario revisó de nuevo con `schtasks` y los 3
  mostraron **"Último resultado: 0"** con fecha de hoy -- las corridas AUTOMÁTICAS (no manuales)
  del Programador de tareas de Windows ya funcionan solas los 3. Se da por cerrada esta
  investigación. Si en el futuro un robot "deja de avisar" sin ningún error de código visible,
  revisar PRIMERO si su `run_diario.bat` sigue existiendo en la carpeta local antes de sospechar
  de un bug nuevo -- mismo patrón de diagnóstico barato ya documentado para otros huecos de este
  archivo (migración no corrida, archivo no resubido).

## Nuevo: "Robots de monitoreo" dentro del sistema -- heartbeat + alerta automática (20 nov 2026, `app.js?v=162`, migración 074)
Pedido explícito del usuario tras la investigación de arriba: "podemos poner algo en el sistema
que nos avise cuando fue la última vez que corrieron los robots" -- hasta hoy, la única forma de
saberlo era entrar a la computadora del despacho y revisar `schtasks` a mano.
- **Migración 074**: tabla nueva `robot_heartbeats` (una fila por robot --
  `federal`/`cdmx`/`cdmx_buzon`/`edomex_correo`/`jurisprudencia` -- con su última corrida, si
  terminó bien o con error, y un resumen corto).
- **`api/robot_heartbeat.php`** (nuevo, protegido con la llave de robot): cada uno de los 5
  robots lo llama al terminar, con éxito o con error -- nunca debe tronar el robot si esto
  falla (ej. sin internet justo en ese momento), se atrapa y se sigue de largo.
- **`api/robot_helpers.php`** (nuevo, compartido): `ROBOTS_DEF` define cuánto se espera que tarde
  cada robot en volver a correr (30h para los 4 diarios, con margen; 192h/8 días para
  Jurisprudencia, que es semanal) y `robots_estado()` calcula si alguno ya se pasó de ese margen
  ("vencido").
- **`api/robot_heartbeats_list.php`** (nuevo, solo Administrador) + panel nuevo **"Robots de
  monitoreo"** en "Equipo" (entre "Credenciales de portales por abogado" y "Validación de
  Tribunal"): tabla con cada robot, su última corrida, y una insignia "✓ al día" / "⚠ corrió con
  error" / "✗ atrasado".
- **`api/cron_verificar_robots.php`** (nuevo, correr 1 vez al día a media mañana, ej. 11:00 am --
  después de que los 5 robots ya debieron correr): si alguno está "vencido", manda push al
  Administrador con la lista (reusa `push_armar_cuerpo()`, ya existente) -- así no hace falta
  entrar al panel ni a `schtasks` para enterarse, el sistema mismo avisa solo.
- **Los 5 robots** (`robots/federal/federal.js`, `robots/cdmx/cdmx.js`,
  `robots/cdmx_buzon/cdmx_buzon.js`, `robots/edomex_correo/edomex_correo.js`,
  `robots/jurisprudencia/jurisprudencia.js`) ahora reportan su heartbeat al terminar --
  `main().then(resumen => reportarHeartbeat(true, resumen)).catch(async err => {...; await
  reportarHeartbeat(false, ...); process.exit(1);})`, así se reporta tanto éxito como error (un
  robot que corre pero truena a medias ya no se queda en silencio, se ve "corrió con error" en
  vez de simplemente no aparecer).
- `php -l`/`node --check` limpios en los 4 archivos PHP nuevos, los 5 robots y `assets/app.js`.
  Sin tocar `mergeTags()`/tags de demanda -- no aplica `verificar_tags.js`/`pruebas_bot.php`.
- **Confirmado (20 nov 2026)**: migración 074 corrida en la base correcta (`c1361311_exp`,
  confirmado con captura real de phpMyAdmin -- tabla `robot_heartbeats` creada limpio), los 4
  archivos PHP nuevos + los 5 robots (esta vez entregados en un solo `.zip` a petición explícita
  del usuario, excepción puntual a la convención de mandarlos sueltos) + `assets/app.js`/
  `index.html`/`sw.js` ya subidos. **`api/cron_verificar_robots.php` confirmado dado de alta como
  Cron Job en Ferozo (captura real: minuto 0, hora 11, resto `*`, comando
  `php /home/c1361311/public_html/sistema/api/cron_verificar_robots.php`)** -- el usuario
  inicialmente reportó esto como ya hecho sin serlo todavía (confusión aclarada el mismo día,
  ahora sí confirmado con captura). **Pendiente real todavía**: confirmar en vivo que el panel
  "Robots de monitoreo" en Equipo se llene solo tras la siguiente corrida programada de cada
  robot (CDMX/Buzón CDMX 6am, Federal 6:40am, Edomex 9:30am, Jurisprudencia lunes 7am), y que el
  cron de verificación mande push si alguno se atrasa (forzar una prueba sería borrar o adelantar
  a mano la fecha de un heartbeat en la base y correr el cron, para ver la alerta sin esperar
  días).

## Documento de $49: regla de 30 días gratis confirmada + revisiones del 19-23 nov (conversación "chats")
- **Decisión del usuario (19 nov 2026)**: se queda la regla de 30 días -- quien pagó el documento
  de $49 en los últimos 30 días recibe correcciones GRATIS, aunque sea él quien cambie sus datos
  ("para que la gente no se enoje"). Hoy el bot no la manda solo: escala y el abogado la manda con
  `debug_enviar_pdf_calculo.php` (un PDF por modo/tipo; despido justificado = `tipo=justificado`).

## Revisión del 19-nov (120 conversaciones, 27 marcadas = 22.5%) (20 nov 2026, conversación "chats")
- Subió de 12.5% porque 12 de las 27 eran de VACACIONES DE AÑOS ANTERIORES: el revisor aplicaba el
  criterio del coach de tenis a ciegas (no veía qué días pasó el bot a la calculadora). Reales: el
  bot solo preguntaba por el último aniversario. Arreglos: criterio del bot -- preguntar por TODOS
  los años y pasar la SUMA (la calculadora descarta prescritos); antes del 1er año decir que el
  proporcional cubre todo; cifras EXACTAS de la herramienta; si solo preguntó por aguinaldo y sigue
  trabajando, dar aguinaldo + mencionar vacaciones/prima con monto; accidente de trabajo:
  orientación mínima (riesgo de trabajo, Art. 685 Ter fracc. III, sin conciliación previa).
  Revisor: el cron ahora le pasa antigüedad, días de vacaciones contados y prescritos/excedentes
  de cada cálculo; 5 "NO marques" nuevos; tabla del Art. 76 hasta 30 años. 94 casos.
- Otros reales sin regla nueva (ya cubiertos): SDI sustituido a mano por el salario mínimo
  (5212741129456), horas extra a mano (5215527036503), comparación de aguinaldo a mano
  (5217712452069), "no hay problema con tu plazo" con la conciliación en trámite (5219619968095).

## Revisión del 19-22 nov (54 marcadas; 22-nov: 3 de 16) (23 nov 2026, conversación "chats")
- Error más repetido: cifras con centavos hechas a mano (día festivo, horas extra, quincenas
  adeudadas, salarios caídos, aguinaldo "duplicado"). Candado nuevo en código:
  `ia_montos_sin_respaldo()` -- si el texto final trae montos con centavos que no están en la
  conversación, en `calculos_liquidacion` (30 días) ni en los resultados de herramientas del
  turno, se le pide UNA vez que los calcule con la herramienta o los dé redondeados como
  aproximación (log `[montos_a_mano]`).
- Criterios nuevos: reestructura/recorte/"se acabó el proyecto" sin cierre (dos escenarios,
  Arts. 434-436 vs despido; no prometer salarios caídos; preguntar si era contrato por obra o
  tiempo determinado); trabajo del hogar (Arts. 331-343, la calculadora usa reglas generales).
- `cron_seguimiento_cursos.php`: ya no le vende el curso a quien dijo que pagó / mandó
  comprobante ni a quien tiene otro prospecto pausado (caso real ChuyNg 21-nov).
- Revisor: no marca plazos en días (salen de calcular_plazo_demanda), Apartado B en el TFCA,
  ni "reestructura no es causa del Art. 47". 101 casos en `scripts/pruebas_bot.php`.
- **Pregunta abierta al Lic. Rubén**: el Art. 521 fracc. III vigente dice que la solicitud de
  conciliación INTERRUMPE la prescripción y que la interrupción cesa al día siguiente de la
  constancia; `calcular_plazo_demanda()` y el prompt la tratan como SUSPENSIÓN (pausa con los
  días que quedaban). No se cambió nada sin su criterio.

## Revisión del 23-nov (111 conversaciones, 9 marcadas = 8.1%) (24 nov 2026, conversación "chats")
- Primer día completo con el candado de montos. Reales: salario semanal $3,400 ÷ 5 = $680 (ver
  pregunta abajo), vacaciones descontadas por paros técnicos sin preguntar años anteriores,
  plazo dado sin preguntar la fecha de la solicitud de conciliación (tenía cita el 4-dic),
  recorte tratado como despido (posible conversación previa a la subida del 23-nov).
  Falsas alarmas: plazo "a mano" (sale de calcular_plazo_demanda), conversación sin respuesta del
  bot, PTU Art. 127-VII. Revisor calibrado en esos 2 primeros puntos (sin subir todavía).
- **Decidido por el Lic. Rubén (24-nov)**: salario SEMANAL ÷ 7 SIEMPRE (Art. 89 LFT: "se dividirá
  entre siete o entre treinta") cuando trabaja 4 días o más a la semana; EXCEPCIÓN (mismo día):
  si trabaja MENOS de 4 días a la semana, se usa su salario por día trabajado. Se reescribió la REGLA DURA que
  decía lo contrario (÷ días trabajados), la descripción de `salario_diario` en las 2
  herramientas y un "NO marques" del revisor. 103 casos en `scripts/pruebas_bot.php`.
- **Decidido por el Lic. Rubén (24-nov)**: la solicitud de conciliación PAUSA el plazo y, con la
  constancia, sigue corriendo con los días que quedaban (no empieza de nuevo completo). Es lo que
  ya hacen `calcular_plazo_demanda()` y el prompt -- no se cambió nada. Cierra la pregunta del
  Art. 521 fracc. III de la sección anterior.

## Revisión del 24-nov (120 conversaciones, 8 marcadas = 6.7%, bajó de 8.1%) (25 nov 2026, conversación "chats")
- Reales, ya arreglados en CRITERIOS DEL DESPACHO:
  - Art. 110 fracc. I: el bot omitió el límite del 30% del excedente del salario mínimo por cada descuento. Verificado en el texto oficial; ya está en la guía de artículos.
  - Vacaciones y aguinaldo pagados INCOMPLETOS: pasar a la calculadora la diferencia de días de vacaciones. La diferencia de aguinaldo se da aparte, redondeada, porque la calculadora no calcula aguinaldos de años anteriores.
  - Prescripción del aguinaldo (Art. 516): el del año pasado se puede reclamar hasta el 20-dic de este año; los anteriores ya prescribieron.
  - No decir que un finiquito "cuadra" sin la respuesta de todos los años.
  - Antigüedad discutible (trabajo por temporadas): presentarla como escenario junto a las cifras.
  - Rescisión hipotética: contestar en condicional.
- Falsas alarmas calibradas en el revisor:
  - Plazo 9-ene para un despido del 10-nov: `calcular_plazo_demanda` usa la fecha que vence primero entre 60 días y 2 meses.
  - Salarios devengados: la calculadora sí los incluye; el revisor ahora recibe `salarios_devengados_monto`.
  - Decir que el aguinaldo de 2024 ya prescribió es correcto.
  - Horas extra mencionadas sin monto.
  - Advertir sobre el Art. 185 (trabajador de confianza).
- 111 casos en `scripts/pruebas_bot.php`.

## Conversación del 25-nov (clienta de Querétaro) (25 nov 2026, conversación "chats")
- **Bug mío**: el candado del 18-nov ("ya pagó en 30 días, no generes otro link") le negó el documento de $49 de una COMPAÑERA (otra persona, otros datos) y la escaló. Ahora `confirmar_compra_documento_calculo` tiene el parámetro `para_otra_persona`: si es true, genera una compra nueva. Las correcciones gratis siguen siendo solo del cálculo de la misma persona.
- Prompt:
  - Aunque la persona diga que su centro de trabajo está en otro estado, el bot no comenta que allá el despacho no lleva juicios (lo hizo por su cuenta).
  - Papeles o pagaré firmados en blanco: Art. 48 Bis fracc. I inciso c; no decir que el pagaré "no obliga a nada".
  - Contratos seguidos y sin cortes: la antigüedad no se presenta como dudosa.
- Para la conversación "Bot": el argumento de que el conciliador "suele ayudar más a la empresa" es arriesgado; no se tocó.
- 116 casos en `scripts/pruebas_bot.php`.

## Curso pagado en la página de Netlify no entregaba el acceso (25 nov 2026, conversación "chats")
- Caso real, clienta 5215619392363. El bot le generó su link de Mercado Pago del curso (compras_curso id 80, quedó en `pendiente_pago`). Al día siguiente, `cron_seguimiento_cursos.php` le mandó la página de Netlify. Pagó ahí; el pago no quedó ligado a su WhatsApp y el acceso no le llegó solo. Se le mandó la llave a mano.
- Arreglo:
  - Nueva función `curso_link_pago_ligado()` en `api/mercadopago_helpers.php`: reusa el link pendiente o crea una compra nueva.
  - El recordatorio de cursos ya no manda la página de Netlify. Si Mercado Pago falla, le pide que conteste para mandarle el link.
  - `confirmar_compra_curso` ya no manda la página como respaldo si falla Mercado Pago.
- Archivos subidos por el usuario (25-nov). **Decisión del usuario (25-nov): la página de Netlify se queda como está**, cobrando por su cuenta. Un pago hecho ahí no entrega el acceso solo por WhatsApp; si alguien manda su comprobante, el bot escala y el acceso se manda a mano.
- Además, el cálculo de esa clienta del 24-nov usó salario ÷ 6 (antes de que se subiera la regla de ÷ 7). Le dio $137,826.10; lo correcto es $118,136.66.
- 119 casos en `scripts/pruebas_bot.php`.

## El soporte de Control de Expedientes le contestó a un cliente del curso (26 nov 2026, conversación "chats")
- Caso real, Rogelio. Ya había pagado el curso procesal el 20-nov. Escribió "creo que el link no me deja entrar a los formatos" y la regex de `soporte_parece_pregunta_tecnica()` ("no me deja entrar") lo mandó al bot de soporte de Control de Expedientes. Ese bot le dijo que había escrito a otro número y le explicó el sistema para despachos.
- Arreglo:
  - Nueva `soporte_debe_atender()`: un número que ya es cliente o prospecto laboral (compras_curso, citas_asesoria, compras_documento_calculo, calculos_liquidacion, prospectos que no sean control_expedientes) nunca va al soporte.
  - La regex de "no me deja entrar" ahora exige "al sistema" o "a control de expedientes".
  - Regla nueva en `SOPORTE_SYSTEM_PROMPT`: nunca decir "escribió a otro número"; si habla de un curso, un pago o un caso, escalar.
- Archivos: `api/soporte_tecnico_helpers.php`, `api/whatsapp_procesar.php`. 124 casos en `scripts/pruebas_bot.php`.
- Pendiente del usuario: su problema real (no le abren los formatos del curso en la página de Netlify) sigue sin resolver.

## Revisión del 25-nov (120 conversaciones, 16 marcadas = 13%) + 2a pasada del 24-nov (17 más) (26 nov 2026, conversación "chats")
- Los 17 del 24-nov son anteriores a los arreglos que se subieron el 25-nov (÷7, vacaciones de todos los años, aguinaldo incompleto, antigüedad discutible, rescisión hipotética).
- Candados nuevos en código (`api/ia_helpers.php`):
  - `ia_vacaciones_anteriores_previas()`: al recalcular con 0 días de vacaciones anteriores cuando antes sí se pasaron, la herramienta trae `aviso_vacaciones_anteriores`. El cálculo ahora guarda `dias_vacaciones_anteriores_pasados`.
  - `ia_conversacion_menciona_ciudad_frontera()`: si el cliente menciona una ciudad de la zona libre de la frontera norte, la herramienta trae `aviso_zona_frontera_texto` (caso Tijuana).
- Criterios nuevos en el prompt, verificados en el texto oficial:
  - Incapacidad o invalidez: Art. 53 fracc. IV y Art. 54 (1 mes + 12 días por año), presentado primero.
  - Descanso semanal: Arts. 69 y 73 (salario doble).
  - Faltar a propósito para que lo corran: Art. 47 fracc. X, desaconsejarlo.
  - Periodo de prueba: Art. 39-A.
  - Orientación mínima antes de escalar algo urgente.
  - Al recalcular, conservar los datos.
  - Guía de artículos: Art. 435.
- Revisor:
  - `ia_parsear_revision_bot()` descarta hallazgos con "no aplica", "no requiere corrección" o "no es un error".
  - "NO marques" nuevos: plazo pausado por conciliación, Art. 517-II, conteo de aniversarios de vacaciones.
- **Preguntas abiertas al Lic. Rubén (no se tocó nada)**:
  - A) Salario reportado menor al mínimo: ¿se calcula con el mínimo (el Art. 485 está en riesgos de trabajo) o con el real, y la diferencia aparte?
  - B) Vacaciones de años anteriores: ¿se pagan con el salario de cada año o con el actual?
  - C) La frase de venta "en el Centro casi siempre ofrecen la mitad, 45 días cuando son 90" (conversación "Bot").
- 133 casos en `scripts/pruebas_bot.php`.

## Criterios del Lic. Rubén (26 nov 2026) + rama "Bot" fusionada (conversación "chats")
- **Error de coordinación encontrado y corregido**: la rama de "Bot" (`claude/labor-advisory-bot-tiktok-wqsmim`) ya traía mis cambios, pero mi rama no traía los suyos en `api/ia_helpers.php` (el argumento de venta de la "propuesta de 45 días", entre otros). Los zips de "chats" del 25 y 26-nov pudieron dejar el servidor sin esos cambios. Se fusionó la rama "Bot" (fast-forward, sin conflictos) y se volvió a mandar `ia_helpers.php` completo. **Antes de mandar `ia_helpers.php`, fusionar siempre la rama "Bot".**
- A) Salario reportado menor al mínimo: el cálculo se hace con el mínimo de la zona. La herramienta lo ajusta sola y trae `aviso_salario_minimo`; la diferencia que le pagaron de menos se reclama aparte.
- B) Vacaciones de años anteriores: se pagan con el salario ACTUAL.
- C) Frase de venta de la conciliación SUAVIZADA (aprobada por el usuario el 26-nov): "es común que le ofrezcan menos de lo que marca la ley (ej. 45 días cuando son 90), la ley no fija proporción"; "eso no es correcto" en vez de "es MENTIRA"; se quitó que el conciliador ayuda al patrón.
- 159 casos en `scripts/pruebas_bot.php`.
- **Bonos anuales (26-nov, Lic. Rubén)**: se dividen entre 365 y se suman al salario diario. `calcular_sdi_con_variable()` ya lo hacía así; solo se agregaron 2 pruebas para fijar el criterio (162 casos).
