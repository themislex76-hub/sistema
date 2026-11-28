# Notas operativas — sistema (Expertos Laborales)

Versión compacta (27 nov 2026). El historial completo, con cada bug y cada vuelta de diagnóstico,
está en `docs/CLAUDE_historial.md`. Ese archivo NO se carga solo: búscalo con `grep` cuando
necesites saber por qué se hizo algo. **Agrega aquí solo lo vigente: decisiones, pendientes y reglas.
La historia detallada va al final de `docs/CLAUDE_historial.md`.**

## 1. Cómo trabajar con el usuario (Lic. Rubén Buerhend) — LEER PRIMERO
- **Explica primero, cambia después.** Antes de tocar código, di qué encontraste, qué propones y
  espera su OK. Se molesta si se cambia código sin preguntar.
- **La prioridad del bot es VENDER** (asesoría $499), sin dar información legal incorrecta.
- Todo con la **LFT vigente** a la fecha (texto oficial en la tabla `lft_articulos`, DOF 14-05-2026).
  Jurisprudencia solo de la SCJN. Nunca inventar; si algo no se entiende, preguntar.
- Habla en español, sencillo y corto. Él no es programador.
- Si un caso real deja un criterio jurídico nuevo, pregúntale al Lic. antes de fijarlo.
- Para revisar una conversación del bot: identifica el error o la venta perdida, propón el arreglo
  (prompt o candado en código), espera el OK, implementa, corre las pruebas, commit, anota aquí y
  manda el zip.

## 2. Flujo de despliegue
- No hay SSH ni acceso al servidor en vivo. Sistema en vivo: `https://sistema.expertoslaborales.com/sistema/`
  (base `c1361311_exp`, hosting Ferozo/cPanel). Ninguna sesión tiene credenciales de Administrador:
  para datos en vivo, darle SQL para phpMyAdmin o pedirle que copie/pegue lo que ve.
- Código: editar → `php -l` / `node --check` → pruebas → commit/push a TU rama → mandar SOLO los
  archivos tocados en un `.zip` con la ruta relativa (`api/ia_helpers.php`, `assets/app.js`) →
  él los sube por el Administrador de archivos de cPanel. Borra el zip/carpeta de entrega y no lo
  commitees. Los `.js` (app.js, sw.js, robots) SIEMPRE en zip.
- Si tocas `assets/app.js`: sube `app.js?v=N` en `index.html` y `CACHE_NAME`/`app.js?v=N` en `sw.js`
  (hoy `app.js?v=165`, `sw.js ela-shell-v89`). Manda los 3 archivos.
- Base de datos: dar el SQL; él lo corre en phpMyAdmin. **Última migración corrida: 075
  (`calculos_liquidacion.tercer_seguimiento_en`, 25 nov 2026). Siguiente libre: 076.**
- Pruebas: `php scripts/pruebas_bot.php` antes de mandar CUALQUIER cambio del bot (187 casos hoy).
  `node scripts/verificar_tags.js` si tocas `mergeTags()`, `calcularJornada()`, Rescisión Art. 51
  o tags de demanda.
- Crons: viven en Ferozo → Tareas programadas. "Lo probé a mano" NO es "está dado de alta"; si un
  cron nuevo hace falta, pídele que lo agregue y confirma con la lista completa.

## 3. Varias conversaciones, varias ramas (IMPORTANTE)
- Ramas: `claude/hola-i5mb6q` ("sistema y bot completo", rama por defecto), `claude/labor-advisory-bot-tiktok-wqsmim`
  ("Bot"), `claude/magical-albattani-ephif7` ("chats"), `claude/vibrant-lamport-cn8qp1` ("negocio").
  Una conversación nueva tendrá su propia rama.
- Antes de empezar y **siempre antes de mandar `api/ia_helpers.php` o `assets/app.js`**:
  `git fetch origin` y fusiona las otras ramas (`git merge --no-commit --no-ff origin/<rama>`,
  revisa, confirma). Si hay conflicto real, avísale; no elijas a ciegas. Ya pasó que un zip dejó
  el servidor sin el trabajo de otra rama.
- Al terminar: commit/push a tu rama y actualiza este archivo (corto).

## 4. Diagnósticos baratos (revisar ANTES de sospechar un bug nuevo)
- Algo "se guarda sin error pero no queda" → archivo PHP viejo en el servidor (ej. `EDITABLE_CAMPOS`
  de `expediente_helpers.php`). Pedir que lo vuelva a subir.
- Un cron "no hace nada" → migración que dice "corrida" pero no llegó a la base, o el cron nunca se
  dio de alta. Para ver el error real: `display_errors` temporal solo en ese script.
- Un robot dejó de avisar → revisar que exista su `run_diario.bat` en la carpeta local y
  `schtasks /query /tn "<nombre>" /v /fo list` ("Último resultado" 0 = bien).
- MySQL del servidor NO soporta funciones de ventana (`LAG() OVER`).
- Con `PDO::ATTR_EMULATE_PREPARES=false` no se puede repetir el mismo placeholder (`:aid` dos veces).
- UPDATE a un ENUM con warning "Datos truncados" = falta el ALTER que agrega el valor.
- El usuario a veces corre SQL en la base equivocada (hay otra de Control de Expedientes): confirmar `c1361311_exp`.

## 5. Bot de WhatsApp
**Archivos:** `api/ia_helpers.php` (prompt `IA_SYSTEM_PROMPT`, herramientas `IA_TOOLS`, ciclo
`ia_responder_whatsapp()`, candados), `api/whatsapp_procesar.php`, `api/whatsapp_helpers.php`,
`api/liquidacion_calculadora.php`, `api/mercadopago_helpers.php`, `api/citas_helpers.php`,
`api/lft_helpers.php`.
**Modelo:** `IA_MODELO_BOT = 'claude-sonnet-5'` sin razonamiento (`IA_MODELO_BOT_ESFUERZO = null`).
Del 17 al 26-nov se probó Sonnet 5.5 con razonamiento y coincidió con la caída de ventas; se regresó.

**Productos y precios**
- Asesoría telefónica 1 h con el Lic. Rubén Buerhend: **$499**, siempre el precio vigente (nunca respetar
  $299/$399 viejos; explicar que subió en oct-2026). Disponibilidad L-V 11:00-18:00. Link de Mercado
  Pago con 30 min para pagar. Solo tarjeta o saldo MP.
- Documento membretado del cálculo: **$49**. Correcciones gratis 30 días a la misma persona
  (el bot escala y el abogado lo manda con `api/debug_enviar_pdf_calculo.php`; sin `&enviar=1` es
  vista previa; `&aguinaldo_dias=30` para aguinaldo pactado). Para OTRA persona, `para_otra_persona=true`.
  Frase de venta: sirve para saber exactamente cuánto le toca ("más difícil que le ofrezcan menos").
- Cursos: el bot NUNCA manda la página de Netlify; el único link es el de pago de
  `confirmar_compra_curso`. Procesal y Amparo NO traen formatos; solo Actas Administrativas (6 formatos).
  Quien paga en Netlify no recibe acceso automático: se manda a mano
  (`https://thriving-madeleine-5fe918.netlify.app/?llave=<id del pago>&pago=ok`).
- **Promoción de cursos (desde 28-nov-2026, decisión del Lic.)**: compra 1 ($299) y se le regalan los
  otros 2. NO retroactiva. Los regalos son accesos fijos en `api/cursos_regalo_accesos.php` (SOLO en el
  servidor, en .gitignore; el repo es público). Si falta ese archivo, el cliente recibe su curso y al
  abogado le llega push URGENTE para mandarle los 2 a mano. Apagar: `CURSOS_PROMO_REGALO_ACTIVA = false`
  en `mercadopago_helpers.php`. Prueba de 3 semanas: si pagan menos de 6 de cada 10 que reciben link,
  cambiar al paquete de los 3 a $399 (idea del Lic., commit 9c76331 como borrador).
- El despacho SÍ asesora empresas/patrones.

**Criterios del despacho ya decididos (no reabrir sin el Lic.)**
- Solo Apartado A / LFT. Entidades públicas: A si el decreto dice A, o si dice B pero hay contrato
  colectivo vigente que dice A. Gobiernos estatales/municipales: no se atienden.
- Despido: los 20 días por año NUNCA se suman (ni de confianza); solo en rescisión Art. 52.
  Ser "de confianza" no es ventaja (riesgo Art. 185).
- Salario semanal ÷ 7 si trabaja 4+ días; menos de 4 días, salario por día trabajado (Art. 89).
- Salario menor al mínimo: se calcula con el mínimo de la zona; la diferencia se reclama aparte.
- Vacaciones de años anteriores: preguntar por TODOS los años, pasar la SUMA (la calculadora quita
  prescritos); se pagan con el salario ACTUAL. Prima de antigüedad: tope con el salario mínimo de la
  FECHA DEL DESPIDO (Arts. 162 y 486); zona libre frontera norte solo en el bot.
- Bonos anuales ÷ 365 al salario diario. Ingreso variable: Art. 89.
- Conciliación PAUSA el plazo; tras la constancia corre lo que quedaba. Plazo de despido: 2 meses
  (Art. 518), siempre con `calcular_plazo_demanda`, nunca "60 días" a mano.
- Jornada (reforma DOF 01-05-2026) gradual: 2026=48 h y 9 extra; 2027=46/9; 2028=44/10; 2029=42/11;
  2030=40/12. Horas extra Art. 66 (doble) y 68 (triple). El Art. 67 ya no regula horas extra.
- Juicios solo en CDMX y Edomex regiones Tlalnepantla y Ecatepec (lista de municipios en el prompt;
  cuenta el centro de trabajo). Fuera de ahí se vende la asesoría igual y no se menciona la zona salvo
  que pregunten si se lleva su caso.
- Frase de conciliación (suavizada 26-nov): "es común que ofrezcan menos de lo que marca la ley
  (ej. 45 días cuando son 90)". Nada de "el conciliador ayuda al patrón". Sin "25 años de experiencia".

**Candados en código** (`ia_responder_whatsapp()`; cada uno deja log en `api/ia_debug.log`): pago
confirmado sin evidencia, cita "de hoy" sin evidencia, niega cálculo gratis, meses→días, plazo en
días sin herramienta, jurisprudencia sin herramienta, exención ISR, promete abogado sin escalar
(escala solo), anuncia sin hacer, montos con centavos a mano, **link de pago faltante (se agrega
solo)**. Avisos inyectados en resultados de herramientas: vacaciones anteriores, plazo vencido,
conciliación, zona frontera, salario mínimo. Mensajes al bot que empiezan con
"[Aviso interno del sistema" son del sistema: obedecer y nunca mencionarlos al cliente.

**Horarios:** `ia_horarios_para_ofrecer()` reparte (máx. 2 por día, 3+ días) y `ofrecer_horarios_asesoria`
acepta `fecha_preferida` cuando la persona pide un día.

**Seguimientos automáticos:** `cron_seguimiento_calculadora.php` (2 recordatorios en 24 h, con monto),
`cron_seguimiento_calculadora_plantilla.php` (plantilla Meta `despues_de_24_h`, 11:00, una vez por
persona), `cron_seguimiento_cursos.php`, `cron_recordatorio_asesoria.php` (plantilla `recordatorio_1_hora`).
Plantilla `sin_respuesta_llamada` existe pero se manda a mano. Nuevo mensaje automático → agregar su
prefijo a `ia_es_mensaje_automatico()`.

**Revisión diaria** (`cron_revisar_conversaciones_bot.php`, Ferozo horas 1-5): revisa cada conversación
contra la LFT y los criterios; desde el 28-nov también marca **ventas perdidas** (`tipo: venta`).
Panel en Equipo; lista JSON en `api/bot_revisiones_list.php` (el usuario la pega). Acordado bajar a
muestra (solo hora 1) tras ~2 semanas.

**Ventas (para comparar):** apartaron / vieron $499 ≈ 10% del 10 al 14-nov; 3-5% del 17 al 26-nov;
pagan ~50% de los que apartan. Apartados por día: 11-15 (10-13 nov), 1-7 (14-24 nov), 8/4/6 (25-27 nov).
El link de pago sí llegaba en casi todos (solo 1 caso sin link en 17 días). Causas probables abiertas:
estimados más bajos (corrección de confianza), tono más cauteloso desde los criterios del 14-nov,
cierre menos insistente. Documento $49 por semana: 11, 9, 3, 5 (2-26 nov).
SQL de ventas por día:
```sql
SELECT d.dia,
  (SELECT COUNT(DISTINCT telefono) FROM whatsapp_conversaciones WHERE direccion='saliente' AND texto LIKE '%499%' AND DATE(creado_en)=d.dia) AS vieron_499,
  (SELECT COUNT(DISTINCT telefono) FROM citas_asesoria WHERE DATE(creado_en)=d.dia) AS apartaron,
  (SELECT COUNT(*) FROM citas_asesoria WHERE estado='confirmada' AND DATE(pagado_en)=d.dia) AS pagaron
FROM (SELECT DISTINCT DATE(creado_en) AS dia FROM whatsapp_conversaciones WHERE creado_en >= '2026-11-10') d ORDER BY d.dia;
```

**Soporte de Control de Expedientes** (`api/soporte_tecnico_helpers.php`): nunca atiende a un número que ya es
cliente o prospecto laboral (`soporte_debe_atender()`).

**Cada enero:** actualizar salario mínimo general y frontera en Equipo y agregar el año que termina a
`SALARIOS_MINIMOS_HISTORICOS` / `..._FRONTERA_...` (PHP y JS).

## 6. Sistema de expedientes (IA autónoma)
Principio: la IA llena etapas sola SOLO con fechas literales de documentos; nunca calcula plazos,
nunca sobrescribe una fecha ya capturada, una fecha futura va a "Fecha agendada" (o se rechaza),
nunca rompe el orden cronológico (`etapa_fecha_es_consistente()` revisa `fecha` y `fecha_programada`),
y todo queda en `expediente_historial` con `usuario_id NULL` (panel "Actividad de la IA" en Equipo).
Ninguna fecha se marca sola desde la UI: o la mete el abogado o la mete la IA con evidencia.
Avisos de boletín `descartado` nunca se usan como contexto.

Crons en Ferozo: `cron_analizar_acuerdos_ia.php` y `cron_analizar_documentos_ia.php` (cada 15-30 min),
`cron_auditoria_expediente_ia.php` (8:00), `cron_generar_preguntas_supervisor.php` (7:00),
`cron_leer_google_calendar.php` (7:00 y 15:00), `cron_qa_irregularidades.php` (lunes 9:00),
`cron_recordatorio_abogado.php`, `cron_verificar_robots.php` (11:00). Cualquier cambio que agregue
info nueva a un expediente debe bumpear `expedientes.actualizado_en` (así se regenera el Informe Ejecutivo).

Pantallas clave: "Preguntas al abogado" (preguntas del supervisor, irregularidades, Google Calendar
en conflicto o sin identificar), Agenda general con Calendario (mes/semana/día), Equipo (actividad IA,
revisión del bot, credenciales de portales por abogado, robots de monitoreo, validación de tribunal,
calendarios de Google vigilados), "Embudo del negocio" (solo admin).
Campo `tribunal` es el vigente (`junta` solo respaldo de datos viejos). `tipo_demanda` (Demanda /
Rescisión Art. 51 / Prima de antigüedad) filtra los campos de "Editar datos".
Google Calendar: una cuenta compartida (themislex76) con un sub-calendario por abogado; solo
"Audiencias linsday" está mapeado. Los eventos que crea el sistema llevan la marca
`generado_por_sistema` y nunca se releen. `accion_ia` no se sincroniza.

## 7. Robots (Node + Playwright, en la PC Windows del usuario)
- Carpeta `C:\Users\Ruben\Desktop\ROBOTS\<robot>\`, cada uno con su `run_diario.bat`. Programador
  de tareas: CDMX boletín 6:00, Buzón CDMX 6:00, Federal 6:40, Edomex correo 9:30, Jurisprudencia
  lunes 7:00. `schtasks` necesita PowerShell como administrador, un comando a la vez; para cambiar una
  tarea, borrar (`/delete /f`) y recrear sin `/ru`.
- Llave compartida `ROBOT_API_KEY` en `api/robot_credentials.php` (solo en el servidor).
- Todos reportan heartbeat a `api/robot_heartbeat.php`; panel "Robots de monitoreo" y push si se atrasan.
- Federal: 2 sesiones por cuenta (laboral y amparo), lee todas las páginas de Vista Global, 5 reintentos
  por PDF y lista de pendientes de PDF que se reintenta en corridas futuras.
- Buzón CDMX: match Tribunal + expediente; rompe sesión activa (acepta diálogos) y cierra con "Salir"
  al terminar (el portal permite una sola sesión por cuenta; por eso solo corre 6:00).
- Edomex correo (Carbonio, sin IMAP): scroll completo de la bandeja en cada corrida, guarda todos los
  ids vistos, el PDF llega en `.zip` (`adm-zip`).
- Un aviso sin documento se marca en rojo en la tarjeta para que el abogado suba el PDF a mano.
- **Avisos sin PDF (28-nov):** Federal, Buzón CDMX y Edomex correo piden al arrancar
  `api/robot_avisos_sin_pdf.php` (avisos `automatico` sin `documento_id`, no descartados) y los reintentan,
  incluidos los anteriores al arreglo del 24-nov. Ese endpoint también pega solo el PDF que ya estaba en
  Documentos con el nombre del robot (Federal/Buzón). Log: "Avisos sin PDF según el sistema: N".
- Pendiente del usuario: dar de alta 2a corrida de Federal (13:00) y Edomex (15:00) en schtasks.

## 8. Negocio y marketing (conversación "negocio")
- Embudo en `api/negocio_embudo.php` + pantalla "Embudo del negocio"; lives de TikTok se capturan a
  mano (`tiktok_lives`). La jornada del live va de 20:00 a 19:59 del día siguiente, con arrastre de 3 días.
  Número del bot: 56 5273 6263.
- Miniaturas de YouTube: `cd marketing/miniaturas && node generar_serie_lives.js "$(pwd)" N`, anotar el
  video en `registro.json`. Mostrarle hojas de opciones (frases/colores) en vez de adivinar.
  Colaboraciones: `generar_colaboracion.js`. Fotos en `marketing/fotos/`.
  Nombre: "Mtro. Rubén Buerhend" (sin t final; desde el LIVE #18, confirmado 27-nov). Las #1-#16 ya
  subidas dicen "Lic.": redibujar una de esas ya no sale idéntica.
- Bitácora de gasto de IA: https://claude.ai/code/artifact/fdbe25d4-5fba-41f8-a0d3-f389dfb8cb61

## 9. Pendientes abiertos
- IA de acuerdos/documentos (28-nov): no leía nada desde el 19-nov (HTTP 500: `ia_aplicar_etapa_sugerida()` no existía
  en el servidor; `expediente_helpers.php` viejo). Se le mandó el actual; confirmar que `acuerdos_en_fila` baje y
  que borró `api/diag_cron_acuerdos.php` del servidor.
- Robots (28-nov): ya subidos (incluye `federal.js` corregido contra PDF repetidos por varias cuentas). Federal quedó en 0 avisos sin PDF (antes 15: 114/2026 No. 1-14 y 501/2026 No. 2).
  Falta: Edomex 780/2026 (1 aviso, se recupera en la corrida de Edomex) y el aviso de Boletín CDMX del
  114/2026 ("Sindicato de Bomberos...", 05-ago), que es texto y no tiene PDF: el Lic. decide si lo descarta.
- Cursos (28-nov): medir la promoción cada semana con el SQL de cursos (link_generado vs pagaron; antes
  ~4 de cada 10, 66 interesados y 25 ventas en 60 días). El problema principal es que llega poca gente
  (~7 por semana), no el precio. Prioridad baja (todo se vende por WhatsApp; las páginas de Netlify no están
  publicadas en ningún lado, confirmado 28-nov): el correo que manda la página de Procedimiento dice "Documentos
  reales del juicio laboral" y el de Amparo manda a otro WhatsApp (55 7991 3025).
- Ventas: medir la recuperación con el SQL diario y los hallazgos "💰 Venta perdida" (28-nov en adelante).
  Si no sube, revisar el tono cauteloso y el cierre del prompt (con OK del usuario).
- 28-nov: decidir si la revisión diaria baja a muestra. ~14-dic: comparar ventas del documento $49.
- Confirmar en vivo: link de pago agregado solo (`[link_pago_agregado]` en ia_debug.log), plantilla
  `despues_de_24_h` mandando bien, panel de robots llenándose, "Actividad de la IA" con entradas de Google Calendar.
- Sin resolver: INCan Apartado A/B; caso "cuenta falsa" (5215615230823); `producto_externo` del curso
  procesal (quizá `curso-laboral`); Rogelio no puede abrir los formatos en Netlify.
- Sistema: 33 fechas "sospecha media" del reporte `api/reporte_fechas_celebradas_sospechosas.php` sin revisar;
  mapear los demás calendarios de Google a su abogado; corregir el expediente de Eduardo Ramírez (número) y
  el de Anel Yadira (tribunal con número); revisar la plantilla Word de demanda contra la reforma de jornada.
