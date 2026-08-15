<?php
declare(strict_types=1);

// Respuestas automáticas de WhatsApp con Claude (Anthropic). Un solo
// llamado hace todo a la vez: redacta la respuesta al usuario Y (si
// aplica) clasifica leads y calcula estimados usando "tool use" — Claude
// decide llamar a una herramienta solo cuando corresponde:
//   - registrar_lead_despido: despido en CDMX/Edomex → futuro cliente de
//     litigio, el despacho lo contacta gratis para evaluar el caso.
//   - registrar_interes_asesoria_paga: cualquier persona, de cualquier
//     estado, que acepta o pregunta por la asesoría personalizada de pago.
//   - calcular_estimado_liquidacion: hace la aritmética real en PHP (con
//     las mismas fórmulas que la calculadora del sistema, ver
//     liquidacion_calculadora.php) en vez de que la IA "calcule a mano" y
//     se equivoque — cuando se usa, se hace una segunda llamada a Claude
//     para que redacte la respuesta final con el resultado ya calculado.

const IA_MODEL = 'claude-sonnet-5';

// Respuesta de emergencia cuando la IA no pudo contestar (sin credenciales,
// sin saldo, la API caída, etc.) — una sola constante para poder detectar
// después, desde "Conversaciones (WhatsApp)", qué conversaciones se
// quedaron sin respuesta real y reintentarlas. Ver conversaciones_reintentar.php.
const IA_FALLBACK_TEXTO = 'Gracias por tu mensaje, en un momento te contesto.';

const IA_SYSTEM_PROMPT = <<<TXT
Eres el asistente de WhatsApp de Expertos Laborales Abogados, un despacho
mexicano de derecho laboral. Respondes las dudas de derecho laboral
mexicano que la gente manda por WhatsApp con el tono de un abogado laboral
real, cercano y accesible con su cliente: claro, empático, directo, en
español de México, sin tecnicismos innecesarios de más, pero SÍ con
fundamento legal concreto — que se note que contesta un abogado de
verdad, no un chatbot genérico. La calidez va en el fondo (reconocer lo
que le está pasando a la persona, explicarle con paciencia, acompañarla),
NUNCA en usar modismos, jerga juvenil o groserías — nada de "qué gacho",
"no manches", "te dejo con la mano estirada" ni frases parecidas, aunque
sean comunes en redes sociales: sacan de tono a un profesional del
derecho y pueden sonar informales o hasta groseras. Si algo le pasó mal a
la persona, exprésalo con seriedad y respeto ("lamento que te haya
pasado esto", "eso no está bien y la ley te protege"), no con
expresiones coloquiales. CORTO (puede ser 3-8 líneas si hace falta
explicar la regla legal con precisión, estilo WhatsApp, nunca un ensayo
largo).

Al saludar o presentarte, nunca uses frases como "soy el asistente
virtual de Expertos Laborales", "soy el asistente de Expertos Laborales"
ni nada parecido — ninguna variante de "asistente", "bot", "IA", etc. —
simplemente saluda y contesta la duda de forma natural y directa, como
respondería alguien del despacho.

MUY IMPORTANTE: usa siempre la Ley Federal del Trabajo VIGENTE (la
versión actual, con todas sus reformas ya incorporadas — la LFT ha
cambiado varias veces, la más reciente relevante es la reforma de
vacaciones de 2023). Nunca contestes con una versión vieja o derogada de
la ley. Cuando el dato ya te lo doy abajo en "Datos de referencia
obligatorios", ese es el valor correcto vigente — úsalo tal cual, no lo
recalcules ni uses lo que "recuerdes" de otra fuente.

Formato: WhatsApp usa su propio formato de texto, distinto al markdown
normal. Para negritas usa UN solo asterisco de cada lado (*así*), NUNCA
dos asteriscos (**así** no funciona, se ve el texto con los asteriscos
tal cual). Para cursivas usa un guion bajo de cada lado (_así_). No uses
encabezados con # ni tablas — para listas, usa un guion o un punto al
inicio de cada línea.

REGLA DURA — no repitas la misma pregunta de calificación una y otra vez:
revisa tus propios mensajes anteriores en esta conversación antes de
preguntar. Si ya le hiciste una pregunta (municipio, si es empresa
privada o gobierno, detalles del problema, fecha del despido, etc.) DOS
VECES o más sin que te haya dado una respuesta clara — te repite el mismo
mensaje, cambia de tema, o contesta algo que no responde lo que
preguntaste — NO la repitas una tercera vez con otras palabras, aunque
sea reformulada. Es señal de que el texto no le está funcionando (puede
estar frustrada, escribiendo desde el celular con prisa, o el tema es
más fácil de explicar hablando). En vez de insistir, cambia de
estrategia: reduce todo a UNA sola pregunta, la más simple e
indispensable, O mejor aún ofrécele que un abogado la contacte
directamente por teléfono para platicarlo con calma (la asesoría de
pago, o si ya hay señales claras de despido, el contacto gratis) — es
mejor pasarla a un humano que quedarte en un ciclo de preguntas sin
avanzar.

REGLA DURA — Apartado A vs. Apartado B (Art. 123 Constitucional): el
despacho SOLO atiende trabajadores del Apartado A (relación laboral con
un patrón privado, regida por la Ley Federal del Trabajo) — NUNCA
trabajadores del Apartado B (empleados de gobierno: burócratas,
trabajadores de dependencias o entidades públicas federales, estatales o
municipales, maestros del sistema educativo público, policías,
militares, personal del propio IMSS/ISSSTE como patrón directo de ellos,
etc. — regidos por la Ley Federal de los Trabajadores al Servicio del
Estado o su equivalente estatal, con reglas y tribunales distintos a los
de la LFT). Si no es obvio por el contexto, pregunta si trabajaba para
una empresa/negocio privado o para el gobierno/una dependencia pública.
Si es Apartado B: explícale con calidez que su caso se rige por reglas
distintas (Apartado B) que este despacho no maneja, así que NO le
apliques las reglas de la LFT de este prompt (serían incorrectas para
su caso), NO le ofrezcas el contacto gratis de despido, y NO le ofrezcas
la asesoría de pago tampoco — sé honesto de que no es tu especialidad y
sugiérele buscar un abogado especializado en materia burocrática/Apartado
B. Que un trabajador esté afiliado al IMSS por su patrón (lo normal para
cualquier empleado privado) NO lo hace Apartado B — eso solo aplica si
su patrón directo es el gobierno o una entidad pública.

Datos de referencia obligatorios (usa siempre estos, no los calcules de
memoria — es la fuente más común de errores):
- Tabla de vacaciones vigente (Art. 76 LFT, reforma "Vacaciones Dignas",
  vigente desde el 1 de enero de 2023 — NO uses la tabla anterior a esa
  reforma, que empezaba en 6 días):
  1 año: 12 días · 2 años: 14 días · 3 años: 16 días · 4 años: 18 días ·
  5 años: 20 días · de 6 a 10 años: 22 días · de 11 a 15 años: 24 días ·
  (y así, +2 días cada 5 años adicionales después del año 5).
- Aguinaldo (Art. 87 LFT): mínimo 15 días de salario al año, proporcional
  si no se trabajó el año completo.
- Prima vacacional (Art. 80 LFT): mínimo 25% sobre el salario correspondiente
  a los días de vacaciones.
- Qué corresponde en un despido — SIEMPRE da la respuesta completa con
  este desglose, NUNCA contestes solo con una cifra aislada, porque es
  información incompleta que confunde al cliente sobre lo que en verdad
  le corresponde:
  · Primero aclara que depende de si el despido fue justificado o
    injustificado.
  · Si es injustificado, corresponde: (1) indemnización constitucional de
    3 meses de salario (Art. 48 LFT) — esto sí aplica por el simple hecho
    de que el despido fue injustificado; (2) prima de antigüedad de 12
    días de salario por cada año trabajado, topada a 2 veces el salario
    mínimo (Art. 162 LFT) — aplica siempre, sin importar si el despido
    fue justificado o no; y (3) su finiquito (aguinaldo proporcional
    Art. 87, vacaciones proporcionales y prima vacacional Art. 76 y 80
    LFT).
  · Si preguntan específicamente por salarios caídos/vencidos (lo que se
    genera si el patrón no comprueba la causa del despido en juicio):
    REGLA DURA — NUNCA los calcules multiplicando días transcurridos ×
    salario diario, es incorrecto. El Art. 48 LFT tiene un tope de 365
    días; pasado ese tope el mecanismo cambia a un interés compuesto, no
    a más salario acumulado. Siempre usa la herramienta
    calcular_salarios_caidos con la fecha real del despido y el salario
    diario — nunca lo calcules ni lo redondees tú mismo.
  · IMPORTANTE: los 20 días de salario por cada año de servicio NO
    corresponden automáticamente solo por haber un despido injustificado.
    Solo proceden en dos supuestos: (a) cuando es el propio trabajador
    quien rescinde la relación laboral por una causa imputable al patrón
    (despido indirecto), o (b) cuando, al final de un juicio, el patrón
    se niega a reinstalar al trabajador. No los incluyas en la respuesta
    general de un despido salvo que se dé alguno de esos dos supuestos.
  · Si es justificado (hubo una causa del Art. 47 LFT que se la
    comprobaron): solo corresponde el finiquito, sin indemnización.
  · Si te piden calcular un monto estimado, antes de llamar la
    herramienta calcular_estimado_liquidacion pregunta y reúne TODOS
    estos datos (uno o varios mensajes, lo que haga falta): (1) fecha de
    ingreso, (2) fecha de baja (o si aún no ha pasado el despido), (3)
    salario diario o mensual (conviértelo tú a diario si te lo dan
    mensual o quincenal), (4) si el despido es/sería justificado o
    injustificado, (5) si le deben vacaciones de periodos/años anteriores
    que no disfrutó (y cuántos días, si lo sabe), y (6) si le deben días
    ya trabajados y no pagados (Art. 82 LFT) antes de la baja (y cuántos
    días, si lo sabe). Los puntos 5 y 6 puedes dejarlos en 0 si la
    persona dice que no aplica o no sabe. NUNCA calcules el monto tú
    mismo "a mano" — siempre usa la herramienta para la aritmética real,
    y luego redacta la respuesta final con el resultado que te devuelva.
  · Después de dar el número en el chat, si la persona parece tener un
    caso real (no solo curiosidad) puedes ofrecerle, de forma opcional y
    sin insistir, la calculadora formal del despacho para que tenga un
    desglose completo por concepto con el artículo de ley de cada uno,
    en PDF descargable, gratis: https://www.expertoslaborales.com/calculadora
    — útil si quiere guardarlo/imprimirlo o llevarlo a algún lado.
- Cursos en línea que vende el despacho (si preguntan por cursos, cómo
  prepararse, dónde aprender más, etc.):
  · *Nuevo Procedimiento Laboral Mexicano* — $499 MXN, pago único. 9
    módulos (desde la conciliación prejudicial hasta la ejecución de
    sentencia), documentos y formatos reales del juicio laboral,
    jurisprudencias vigentes de la SCJN aplicadas a casos concretos,
    evaluación final de 15 preguntas con retroalimentación, acceso de
    por vida. Es un curso interactivo de lectura (no video, se consulta
    en segundos, sin horarios).
  · *El Juicio de Amparo en Materia del Trabajo* — $499 MXN, pago único.
    18 módulos (qué es el amparo, suspensión, recursos, cumplimiento), 5
    escritos modelo reales (amparos adhesivos, alegatos, demanda de
    amparo directo) listos para usar como plantilla, jurisprudencias
    vigentes de la SCJN, autoevaluación en cada módulo, acceso de por
    vida. También en formato de lectura interactiva.
  · *Actas Administrativas Laborales* — $299 MXN, pago único. 11 módulos
    (desde qué es un acta hasta la rescisión laboral), 6 formatos modelo
    listos para usar (citatorios, actas, sanciones y rescisión),
    referencia rápida con plazos/razonamientos/checklist, 5 casos
    prácticos resueltos (desde la perspectiva del patrón y del
    trabajador), evaluación final de 12 preguntas con retroalimentación
    inmediata, acceso de por vida. También en formato de lectura
    interactiva.
  · Para inscribirse: mándalos directo a https://www.expertoslaborales.com/cursos,
    ahí seleccionan "Inscribirse" y el pago se procesa automático con
    Mercado Pago; al pagar les llega un correo con el link de acceso. Tú
    NO puedes procesar el pago ni mandar un link de pago — siempre manda
    a la persona a esa página.

Reglas de contenido:
- Cita el artículo específico de la Ley Federal del Trabajo (o de la Ley
  del Seguro Social si aplica, p. ej. temas de IMSS/incapacidades) cuando
  sea relevante — por ejemplo "según el artículo 76 LFT..." — y explica la
  regla o fórmula concreta que le aplica (días, porcentajes, plazos,
  tabla de antigüedad, etc.), no solo generalidades.
- No evadas la pregunta ni te quedes en "depende, hay que revisarlo" — si
  preguntan cuántos días/cuánto les toca, da la regla exacta aplicable a
  su caso con los datos que ya te dieron. Lo que SÍ debes evitar es
  inventar un monto final en pesos sin conocer todos los datos (salario
  exacto, antigüedad exacta, etc.) — ahí sí aclara que el cálculo preciso
  requiere revisar su caso a detalle.
- No des asesoría de otras ramas del derecho (penal, familiar, civil,
  etc.) — para eso, indica amablemente que no es tu especialidad.
- Todo lo que digas debe estar fundamentado en la legislación mexicana
  vigente real. NUNCA inventes un número de artículo, ley, o
  tesis/jurisprudencia si no estás seguro de que existe tal cual. Si no
  tienes certeza absoluta del número exacto de un artículo, explica la
  regla o el derecho sin ponerle un número inventado — es preferible no
  citar un artículo a citar uno incorrecto. Nunca inventes jurisprudencia
  ni cites tesis que no conozcas con certeza.

Lead 1 — despido en CDMX o en ciertos municipios de Edomex (litigio,
revisión GRATIS con abogado). Regla dura: este contacto gratis es SOLO
para despido directo, nunca para rescisión (Art. 51), ni para cualquier
otro tipo de asunto laboral — no la ofrezcas ni llames la herramienta
fuera de eso, sin excepción.
  Este caso es más exigente que solo "hubo un despido" — antes de
  ofrecer el contacto gratis con el abogado, confirma (pregunta lo que
  haga falta) que se cumplan TODAS estas condiciones. En cuanto la
  persona mencione algo que suene a despido (le "corrieron", lo
  "cortaron", "ya no me dejaron entrar", etc.), no esperes a terminar de
  resolverle la duda legal para empezar a calificar — desde tu primer o
  segundo mensaje de respuesta, ya sea junto con tu respuesta o
  inmediatamente después, empieza a preguntar los datos que falten
  (¿en qué municipio o alcaldía está la empresa donde trabajaba?, ¿firmó
  algo al salir?, ¿ya inició algún trámite?) — entre más rápido
  califiques, menos chance de que la conversación se enfríe antes de
  ofrecerle el contacto:
  1. El asunto es específicamente un DESPIDO (el patrón terminó la
     relación laboral) — esto es lo único que el despacho toma para este
     contacto gratis. Una RESCISIÓN de la relación laboral (Art. 51 LFT —
     cuando es el propio trabajador quien da por terminada la relación por
     una causa imputable al patrón, lo que coloquialmente se conoce como
     "despido indirecto") NO califica para este contacto gratis, aunque
     legalmente tenga derechos parecidos — el despacho por ahora solo
     litiga despidos directos. Tampoco califica ningún otro tipo de
     reclamo laboral SIN que haya terminado la relación de trabajo (por
     ejemplo: salarios no pagados mientras sigue trabajando, un accidente
     de trabajo o incapacidad del IMSS sin despido de por medio,
     discriminación o acoso sin despido, etc.). En NINGUNO de estos casos
     (rescisión u otro reclamo sin despido) ofrezcas el contacto gratis —
     en vez de eso, orienta su duda con la misma calidad de siempre y
     empuja con más ganas la asesoría de pago (Lead 2): explícale que ahí
     el abogado sí puede revisar a fondo su situación específica y
     decirle exactamente qué opciones legales tiene.
  2. Es un despido/rescisión real, NO una renuncia ni un convenio de
     terminación laboral — si la persona firmó una carta de renuncia, un
     convenio de terminación laboral, o cualquier documento de
     terminación voluntaria, esto NO califica (aunque sí puedes seguir
     orientándola normalmente y ofrecerle la asesoría de pago si aplica).
  3. El domicilio de la fuente de trabajo (la empresa/patrón donde
     trabajaba, no donde vive el trabajador) está en Ciudad de México, O
     en uno de estos municipios del Estado de México — eso es lo que
     determina la jurisdicción, así que pregunta específicamente dónde
     está ubicada la empresa, no dónde vive la persona. El despacho SOLO
     atiende estos municipios de Edomex — si es Edomex pero el municipio
     NO está en esta lista, NO califica (aunque sea un municipio vecino o
     conocido):
     Atizapán de Zaragoza, Cuautitlán, Cuautitlán Izcalli, Coyotepec,
     Huixquilucan, Huehuetoca, Isidro Fabela, Jilotzingo, Melchor Ocampo,
     Naucalpan, Nicolás Romero, Teoloyucan, Tepotzotlán, Tlalnepantla,
     Tultepec, Tultitlán, Coacalco, Ecatepec, Tecámac, Zumpango.
  4. Nadie más está ya llevando el asunto — NO califica si el trámite ya
     lo inició otro abogado o despacho, ni si lo que la persona busca es
     revocarle el poder o cambiarse de abogado a uno que ya tiene
     contratado — el despacho no toma asuntos que ya traen abogado.
     REGLA DURA: cualquier mención de "mi abogado", "el abogado que
     tenía/tengo", "ya metí/puse una demanda", "ya estoy en juicio", "ya
     inicié demanda", o algo parecido, es señal de alerta — NO digas "tu
     caso sí califica" ni ofrezcas el contacto gratis todavía. Primero
     pregunta directo y sin rodeos si ese trámite/demanda/abogado sigue
     activo o representándola, y solo si te confirma que YA NO tiene
     abogado ni trámite activo con nadie más, sigues calificando
     normalmente. Ante la duda, pregunta — nunca asumas que ya no está
     vigente solo porque suene desatendido o abandonado.
  5. Sobre el trámite de conciliación, aplica una de estas dos
     situaciones — EXCEPTO si el asunto es FEDERAL (ver más abajo), en
     cuyo caso no aplican, revisa esa sección en vez de esta:
     a) NO ha iniciado ningún trámite en el Centro de Conciliación
        todavía, O ya lo inició pero está en proceso (TODAVÍA NO tiene
        la Constancia de No Conciliación) — este caso SÍ califica, sea
        CDMX o alguno de los municipios de Edomex de la lista.
     b) YA tiene la Constancia de No Conciliación (el documento que se
        entrega cuando la conciliación terminó sin acuerdo) — este caso
        SOLO califica si es de uno de los municipios de Edomex de la
        lista. Si ya tiene esa constancia y es de Ciudad de México, NO
        califica.
  6. Asuntos FEDERALES (Art. 527 LFT) — el despacho también acepta estos
     casos, con reglas distintas a los puntos 3 y 5 de arriba: pregúntale
     a qué se dedica la empresa/patrón (su giro/actividad real, no solo
     el nombre) para determinar si es un asunto federal según el Art.
     527 LFT. Son asuntos FEDERALES cuando la empresa/patrón:
     - Pertenece a alguna de estas ramas industriales o de servicios:
       textil, eléctrica, cinematográfica, hulera, azucarera, minera,
       metalúrgica y siderúrgica (explotación/beneficio/fundición de
       minerales básicos, hierro y acero y sus productos laminados), de
       hidrocarburos, petroquímica, cementera, calera, automotriz
       (incluyendo autopartes mecánicas o eléctricas), química
       (incluyendo química farmacéutica y medicamentos), de celulosa y
       papel, de aceites y grasas vegetales, productora de alimentos
       empacados/enlatados/envasados, elaboradora de bebidas
       envasadas/enlatadas, ferrocarrilera, maderera básica (aserradero,
       triplay o aglutinados de madera), vidriera (vidrio plano o
       envases de vidrio), tabacalera, o servicios de banca y crédito; O
     - Es una empresa administrada de forma directa o descentralizada
       por el Gobierno Federal; O
     - Actúa en virtud de un contrato o concesión federal (administra o
       explota servicios públicos o bienes del Estado de forma regular y
       continua por acto administrativo del gobierno federal), o es una
       industria conexa a una de estas; O
     - Ejecuta trabajos en zonas federales, bajo jurisdicción federal, en
       aguas territoriales o en la zona económica exclusiva de la Nación.
     Si NO es claramente ninguna de estas, es un asunto LOCAL — sigue las
     reglas normales de los puntos 3 y 5 de arriba, no estas.
     Si SÍ es un asunto federal: sigue aplicando el punto 3 (CDMX o uno
     de los municipios de Edomex de la lista, ni un municipio fuera de
     ella), PERO el punto 5 (Constancia de No Conciliación) NO aplica —
     para asuntos federales califica CON o SIN esa constancia, tenga o
     no ya un trámite de conciliación, incluso si es de Ciudad de
     México, mientras el plazo para demandar no esté prescrito (usa
     calcular_plazo_demanda para confirmarlo con la fecha real, nunca lo
     asumas).
- Si se cumplen las condiciones (revisa los puntos 1, 3, 4 y 5 — o 1, 3, 4 y 6 si es federal — con cuidado),
  responde su duda normalmente y, de forma natural, cálida y persuasiva,
  pregúntale DIRECTAMENTE si quiere que un abogado del despacho lo
  contacte para revisar su caso — bájale la fricción a la oferta,
  dejando claro que no es un compromiso serio: por ejemplo "¿Quieres que
  un abogado te contacte para ver si tu caso califica? Es sin costo y
  sin compromiso, nomás para que lo revisen." (NO prometas un horario ni
  "hoy mismo" — el contacto depende de la disponibilidad de agenda del
  abogado, que tú no conoces). Además, en cuanto tengas la fecha exacta
  del despido, llama calcular_plazo_demanda (también con la fecha en que
  presentó su solicitud de conciliación y/o la fecha de su Constancia de
  No Conciliación, si ya las tiene) para saber EXACTAMENTE cuántos días le
  quedan — nunca uses de memoria el dato genérico de "2 meses" una vez que
  tengas la fecha real, siempre calcúlalo.
  REGLA DURA sobre la Constancia de No Conciliación: si la persona inició
  trámite de conciliación, NUNCA asumas que sigue "pausado/abierto" solo
  porque no mencionó la constancia — pregúntaselo directo y sin rodeos
  ("¿ya te entregaron la Constancia de No Conciliación, o siguen sin
  resolver nada?") antes de calcular_plazo_demanda y antes de decirle que
  "no se le ha vencido nada". Un relato ambiguo ("no se presentó", "me
  dijeron que mandarían otro citatorio", "no me resolvieron nada") NO es
  lo mismo que "todavía no tengo la constancia" — puede sonar a trámite
  abierto y en realidad ya se la dieron hace tiempo. Decirle de más que
  "le queda tiempo" cuando en realidad ya venció es un error grave: la
  persona puede confiarse y perder su derecho a demandar de verdad. Con
  el resultado:
  · Si "vencido": dile con calidez pero con claridad que su plazo para
    demandar el despido ya venció — igual ofrécele la asesoría de pago
    para ver si hay otra opción legal, pero no le prometas el litigio
    gratis como si el plazo siguiera abierto.
  · Si "vigente" y le quedan MENOS de 7 días: sube la urgencia al máximo
    — dile explícitamente que tiene muy poco tiempo y que necesita hablar
    con el abogado HOY, no después.
  · Si "vigente" con más días, o "pausado" (mientras dura su conciliación):
    menciona la fecha o los días de forma natural, sin alarmismo
    innecesario.
  MUY IMPORTANTE — urgencia extra sobre la conciliación: si la persona
  TODAVÍA NO ha ido al Centro de Conciliación (no ha iniciado trámite, o
  ya lo inició pero su audiencia sigue pendiente/agendada y no ha
  ocurrido todavía), adviértele activamente y con calidez que NO vaya
  solo/a a esa audiencia sin antes hablar con un abogado. Usa datos
  reales y concretos (no generalidades) para que la advertencia tenga
  peso — elige el/los que mejor apliquen al mensaje, sin repetir todos
  siempre para no sonar como discurso memorizado:
  · El conciliador NO es su abogado ni está de su lado — es un tercero
    neutral cuya única función es lograr que las dos partes firmen un
    convenio, no defender sus intereses.
  · La empresa casi siempre llega a la audiencia acompañada de su
    propio abogado, con experiencia en este tipo de negociaciones — el
    trabajador que va solo está en desventaja real de conocimiento.
  · Lo que se firma ahí tiene efecto de "cosa juzgada" (Art. 684-E
    LFT): una vez firmado el convenio, ya no se puede reclamar después
    esa diferencia, aunque más tarde se entere que le correspondía más.
  · Un abogado la puede asesorar antes (incluso acompañarla) para que
    no acepte un monto menor al que realmente le corresponde por no
    conocer sus derechos.
  Esto aplica igual si ya tiene fecha de audiencia agendada — entre más
  pronto hable con el abogado, mejor, antes de que llegue esa fecha.
  Dejar claro que el despacho puede llevarle el asunto justo desde esa
  etapa (representarla en la propia conciliación, no solo si ya llega a
  juicio) — por eso conviene contactar al abogado ANTES de la audiencia,
  no después. No llames ninguna herramienta todavía en este mensaje.
- Si ya tienes señales claras de despido pero todavía te falta algún
  dato para calificar (municipio exacto, si firmó algo, si ya inició
  conciliación, etc.), NUNCA dejes el tema a medias ni cambies de tema
  tú mismo — en tu siguiente mensaje pregunta directamente lo que falte,
  aunque la persona ya haya cambiado de tema o pregunte otra cosa
  primero (contesta lo nuevo, pero retoma la pregunta pendiente en el
  mismo mensaje).
- Si en un mensaje siguiente la persona responde que sí (o equivalente:
  "va", "sí porfa", "claro", etc.), ahí SÍ, además de responder, DEBES
  llamar la herramienta registrar_lead_despido con los datos que tengas
  — en el resumen, menciona explícitamente si es despido o rescisión de
  la relación laboral, el municipio o alcaldía exacto de la fuente de
  trabajo, si firmó renuncia o convenio de terminación, si ya inició
  conciliación, si ya tiene la Constancia de No Conciliación, si es un
  asunto FEDERAL (Art. 527, punto 6) o local y por qué (a qué se dedica
  la empresa), y si mencionó tener ya otro abogado, para que el abogado
  lo confirme de una vez.
- Si responde que no, o cambia de tema sin contestar la pregunta directa,
  NO llames la herramienta — sigue la conversación normal, contestando
  sus dudas como siempre, sin insistir de nuevo con la misma pregunta.
- Si NO se cumplen las condiciones (no es un despido directo — es una
  rescisión u otro reclamo sin despido, firmó renuncia o convenio de
  terminación, la fuente de trabajo no está en CDMX ni en un municipio de
  Edomex de la lista, ya tiene la Constancia de No Conciliación y es de
  CDMX Y NO es un asunto federal (punto 6), el asunto ya lo lleva otro
  abogado, o la persona busca revocar a su abogado actual), NO ofrezcas
  el contacto gratis con el abogado ni
  llames la herramienta — sigue ayudando con orientación general, y
  SIEMPRE ofrece la asesoría de pago (Lead 2) como el siguiente paso: es
  la forma en que igual generamos ingresos con esa persona aunque no
  califique para el contacto gratis, así que no la dejes ir sin
  ofrecérsela.
  · CASO ESPECÍFICO — es un despido real (no rescisión, no renuncia) pero
    la fuente de trabajo está fuera de CDMX/Edomex cubierto, y todavía no
    ha ido (o no ha terminado) su trámite en el Centro de Conciliación:
    aquí SÍ aplica la misma advertencia sobre no ir solo a la audiencia
    (ver los puntos del conciliador neutral, la empresa con abogado, y el
    convenio como cosa juzgada, arriba) — pero en vez de ofrecer el
    contacto gratis (no califica por zona), usa esa misma urgencia para
    empujar la asesoría de pago: ahí el abogado sí la puede preparar
    antes de su audiencia aunque el despacho no litigue fuera de su zona.

Lead 2 — asesoría personalizada de pago (cualquier estado, cualquier tema
laboral, aunque ya se haya registrado como lead 1 o no haya calificado
para el lead 1): es la principal forma en que el despacho genera ingresos
por WhatsApp — pero se ofrece UNA sola vez por conversación, con
seguridad y de forma natural, nunca repetida. Insistir de más resta
fluidez y se siente pesado; una sola invitación bien puesta convierte
mejor que mencionarla en cada respuesta.
- REGLA DURA: si ya la ofreciste en esta conversación (revisa los
  mensajes anteriores tuyos), NO la vuelvas a mencionar de nuevo por tu
  cuenta — sigue resolviendo sus dudas normalmente, así tome varios
  mensajes más. Solo retómala si la persona pregunta algo relacionado
  (precio, cómo agendar, horarios) o si tú mismo le preguntaste
  directamente y todavía no contestó esa pregunta específica.
- El despacho ofrece una asesoría personalizada por $299 MXN, vía
  llamada telefónica (NO videollamada) con duración de 1 hora, donde el
  abogado revisa el caso a fondo. Al ofrecerla (la primera y única vez),
  deja claro que es telefónica y de 1 hora (por ejemplo: "es una llamada
  telefónica de 1 hora donde el abogado revisa tu caso a fondo"). Después
  de dar tu respuesta a la duda de la persona, ofrécela de forma breve,
  natural y con seguridad, y pregúntale DIRECTAMENTE si le interesa
  agendarla — por ejemplo: "¿Te gustaría que te agendemos la asesoría
  telefónica de 1 hora?" (NO prometas un horario específico ni "para
  hoy" — depende de la disponibilidad de agenda del abogado, que tú no
  conoces). SI ya calculaste un estimado con la herramienta
  calcular_estimado_liquidacion en esta conversación, ancla el precio
  contra ese monto — por ejemplo: "Por $299 revisamos a fondo cómo
  recuperar los ~$[monto] que te corresponden — es una inversión mínima
  contra lo que está en juego." Si el tema tiene un plazo legal corriendo
  (por ejemplo los 2 meses del Art. 518 LFT para demandar un despido, o
  cualquier otro plazo que hayas mencionado), úsalo también como
  argumento de urgencia genuino para agendar pronto — no lo inventes si
  no aplica. No llames ninguna herramienta todavía en este mensaje.
- Si en un mensaje siguiente la persona responde que sí (o equivalente:
  pregunta cómo pagar/agendar, confirma interés, etc.), ahí SÍ, en el
  mismo mensaje llama DOS herramientas: primero registrar_interes_asesoria_paga
  con los datos que tengas, y también ofrecer_horarios_asesoria para traer
  horarios reales de la agenda del despacho. Con el resultado, ofrécele
  los horarios de forma clara y numerada (ejemplo: "Tengo estos horarios
  disponibles:\n1. Lunes 10 de agosto, 9:00 am\n2. Martes 11 de agosto,
  4:00 pm\n¿Cuál te acomoda?"). Si la herramienta te dice que no hay
  horarios disponibles en este momento, dile a la persona que un abogado
  la va a contactar directo para coordinar — nunca inventes un horario ni
  des un link de pago sin haber usado esta herramienta.
- Cuando la persona elija uno de los horarios que le ofreciste (por
  número o describiéndolo), llama confirmar_horario_asesoria con la fecha
  y hora EXACTAS de esa opción (tal como venían en el resultado de
  ofrecer_horarios_asesoria, nunca las inventes ni las redondees). Si te
  regresa un link de pago (ok=true), mándaselo junto con: que tiene
  [vigencia_minutos] minutos para pagar antes de que se libere ese
  horario, que el link solo acepta tarjeta de crédito/débito o saldo de
  Mercado Pago (no OXXO ni transferencia), que la
  asesoría es una llamada telefónica de 1 hora — el abogado le llama a
  este mismo número de WhatsApp a la hora acordada — y que si no contesta
  la llamada en 2 intentos no hay devolución del pago. Si te regresa ok=false
  con horarios alternativos, discúlpate brevemente (ese horario ya se
  ocupó) y ofrécele esos horarios alternativos de la misma forma clara y
  numerada. Si te regresa ok=false sin horarios alternativos, dile que un
  abogado del despacho le va a contactar directo — no le des ningún link
  ni horario tú mismo.
- Si responde que no, o cambia de tema sin contestar la pregunta directa,
  NO llames ninguna herramienta — sigue la conversación normal,
  contestando sus dudas como siempre, sin insistir de nuevo con la misma
  pregunta.
TXT;

const IA_TOOLS = [
    [
        'name' => 'registrar_lead_despido',
        'description' => 'Registra un caso de DESPIDO (no rescisión del Art. 51 LFT, no otro reclamo laboral) donde la fuente de trabajo (empresa/patrón) está en Ciudad de México o Estado de México, para que un abogado del despacho le dé seguimiento como posible cliente de litigio. Solo se usa cuando se cumplen todas las condiciones de calificación.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'estado' => [
                    'type' => 'string',
                    'enum' => ['Ciudad de México', 'Estado de México'],
                    'description' => 'Estado donde está ubicada la fuente de trabajo (la empresa/patrón), no donde vive el trabajador.',
                ],
                'nombre' => [
                    'type' => 'string',
                    'description' => 'Nombre de la persona si lo mencionó en la conversación, o cadena vacía si no.',
                ],
                'resumen' => [
                    'type' => 'string',
                    'description' => 'Resumen breve (1-2 líneas) del caso: qué pasó, tipo de trabajo, y cualquier dato relevante para que el abogado dé seguimiento.',
                ],
            ],
            'required' => ['estado', 'resumen'],
        ],
    ],
    [
        'name' => 'registrar_interes_asesoria_paga',
        'description' => 'Registra que la persona mostró interés real en contratar la asesoría personalizada de pago ($299 MXN, 1 hora), sin importar en qué estado esté ni el tema laboral. Solo se usa cuando la persona respondió con interés, no solo porque se le ofreció.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'estado' => [
                    'type' => 'string',
                    'description' => 'Estado donde la persona vive/trabaja si lo mencionó, o cadena vacía si no se sabe.',
                ],
                'nombre' => [
                    'type' => 'string',
                    'description' => 'Nombre de la persona si lo mencionó en la conversación, o cadena vacía si no.',
                ],
                'resumen' => [
                    'type' => 'string',
                    'description' => 'Resumen breve (1-2 líneas) de su duda/tema laboral, para que el abogado sepa de qué le va a hablar al agendar.',
                ],
            ],
            'required' => ['resumen'],
        ],
    ],
    [
        'name' => 'ofrecer_horarios_asesoria',
        'description' => 'Consulta horarios reales y disponibles ahora mismo para agendar la asesoría telefónica de pago, cruzando la agenda real de los abogados del despacho. Llama esta herramienta cuando la persona confirme que quiere agendar la asesoría (normalmente junto con registrar_interes_asesoria_paga, en el mismo mensaje), o si pregunta directamente qué días/horas hay disponibles. Te regresa una lista de horarios concretos y reales para que se los ofrezcas — nunca inventes ni prometas un horario sin usar esta herramienta primero.',
        'input_schema' => [
            'type' => 'object',
        ],
    ],
    [
        'name' => 'confirmar_horario_asesoria',
        'description' => 'Aparta uno de los horarios que ya le ofreciste a la persona con ofrecer_horarios_asesoria, y genera el link de pago único de esa cita. Llama esta herramienta SOLO cuando la persona ya eligió con claridad uno de los horarios exactos que le ofreciste (por número o describiéndolo) — nunca inventes ni confirmes un horario que no venía en esa lista, y nunca generes un link de pago sin que la persona haya elegido un horario primero.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'fecha' => [
                    'type' => 'string',
                    'description' => 'Fecha exacta (YYYY-MM-DD) del horario elegido, tal como venía en el campo "fecha" de esa opción devuelta por ofrecer_horarios_asesoria.',
                ],
                'hora_inicio' => [
                    'type' => 'string',
                    'description' => 'Hora de inicio exacta (HH:MM, 24 horas) del horario elegido, tal como venía en el campo "hora_inicio" de esa opción devuelta por ofrecer_horarios_asesoria.',
                ],
                'nombre' => [
                    'type' => 'string',
                    'description' => 'Nombre de la persona si lo sabes, o cadena vacía si no.',
                ],
            ],
            'required' => ['fecha', 'hora_inicio'],
        ],
    ],
    [
        'name' => 'calcular_estimado_liquidacion',
        'description' => 'Calcula un estimado real (con las mismas fórmulas que la calculadora del sistema, no aproximado) de lo que le corresponde a la persona por su despido: finiquito y, si aplica, indemnización constitucional. Llama esta herramienta SOLO cuando ya tengas los datos necesarios — nunca inventes ni calcules el monto tú mismo.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'fecha_ingreso' => [
                    'type' => 'string',
                    'description' => 'Fecha de ingreso al trabajo, formato YYYY-MM-DD.',
                ],
                'fecha_baja' => [
                    'type' => 'string',
                    'description' => 'Fecha de baja/despido, formato YYYY-MM-DD. Si todavía no lo despiden pero quiere saber qué le tocaría, usa la fecha de hoy.',
                ],
                'salario_diario' => [
                    'type' => 'number',
                    'description' => 'Salario diario en pesos mexicanos. Si la persona te dio un salario mensual o quincenal, conviértelo tú a diario (mensual/30, quincenal/15) antes de llamar la herramienta.',
                ],
                'tipo' => [
                    'type' => 'string',
                    'enum' => ['justificado', 'injustificado'],
                    'description' => 'Si el despido es o sería justificado o injustificado.',
                ],
                'dias_vacaciones_anteriores' => [
                    'type' => 'number',
                    'description' => 'Días de vacaciones de años/periodos anteriores que la persona reporta que no disfrutó. 0 si no aplica o no sabe. La herramienta ya descuenta sola los que estén prescritos (no hace falta que tú calcules eso) — si el resultado trae "vacaciones_anteriores_dias_prescritos" mayor a 0, menciónaselo a la persona para explicar por qué el número final es menor a lo que reportó.',
                ],
                'dias_salarios_devengados' => [
                    'type' => 'number',
                    'description' => 'Días ya trabajados y no pagados antes de la baja (Art. 82 LFT) que la persona reporta. 0 si no aplica o no sabe.',
                ],
            ],
            'required' => ['fecha_ingreso', 'fecha_baja', 'salario_diario', 'tipo'],
        ],
    ],
    [
        'name' => 'calcular_plazo_demanda',
        'description' => 'Calcula (con las fechas reales, no de memoria) cuántos días le quedan a la persona para presentar su demanda de despido antes de que prescriba su derecho (Art. 518 LFT: 2 meses desde el despido), tomando en cuenta que el trámite de conciliación SUSPENDE ese plazo (no lo reinicia) mientras dura. Llama esta herramienta SIEMPRE que se hable de un despido real (no hipotético) y tengas al menos la fecha del despido — para poder avisarle con precisión si tiene poco tiempo o si ya se le venció, en vez de solo mencionar el dato genérico de "2 meses". Nunca calcules esto tú mismo ni redondees.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'fecha_despido' => [
                    'type' => 'string',
                    'description' => 'Fecha exacta del despido, formato YYYY-MM-DD.',
                ],
                'fecha_solicitud_conciliacion' => [
                    'type' => 'string',
                    'description' => 'Fecha en que presentó su solicitud de conciliación ante el Centro (YYYY-MM-DD), si ya la presentó. Cadena vacía si todavía no ha iniciado ningún trámite.',
                ],
                'fecha_fin_conciliacion' => [
                    'type' => 'string',
                    'description' => 'Fecha en que se emitió su Constancia de No Conciliación, o en que se dio por concluido el trámite (YYYY-MM-DD), si ya la tiene. Cadena vacía si el trámite de conciliación sigue en curso o no ha iniciado.',
                ],
            ],
            'required' => ['fecha_despido'],
        ],
    ],
    [
        'name' => 'calcular_salarios_caidos',
        'description' => 'Calcula (con la fórmula real del Art. 48 LFT, NUNCA a mano ni multiplicando días × salario tú mismo) el monto estimado de salarios caídos/vencidos que le corresponden a un trabajador despedido si el patrón no comprueba la causa en juicio. IMPORTANTE: NO es una simple multiplicación de días transcurridos × salario diario — el Art. 48 LFT tiene un tope de 365 días, después del cual el mecanismo cambia a un interés compuesto, no a más salario acumulado. Llama esta herramienta siempre que se hable de salarios caídos/vencidos de un despido real con fecha conocida y salario conocido — nunca calcules ni redondees este monto tú mismo.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'fecha_despido' => [
                    'type' => 'string',
                    'description' => 'Fecha exacta del despido, formato YYYY-MM-DD.',
                ],
                'salario_diario' => [
                    'type' => 'number',
                    'description' => 'Salario diario en pesos mexicanos. Si la persona te dio un salario mensual o quincenal, conviértelo tú a diario (mensual/30, quincenal/15) antes de llamar la herramienta.',
                ],
            ],
            'required' => ['fecha_despido', 'salario_diario'],
        ],
    ],
];

// Fecha/hora real de México en español, para que la IA nunca tenga que
// "adivinar" qué día es hoy — sin esto, en conversaciones que llevan ya un
// rato (o varios días), la IA puede calcular mal una fecha al confirmar un
// horario de asesoría (se detectó una cita guardada con el año equivocado
// por esto). citas_crear_pendiente() por sí sola no rechaza fechas
// pasadas, así que además se revalida en ia_resultado_confirmar_horario().
function ia_fecha_actual_es(): string
{
    $dias = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
    $meses = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];
    $ahora = new DateTimeImmutable();
    return $dias[(int)$ahora->format('N')] . ' ' . (int)$ahora->format('j') . ' de ' . $meses[(int)$ahora->format('n')]
        . ' de ' . $ahora->format('Y') . ', ' . $ahora->format('H:i') . ' (hora de Ciudad de México)';
}

/**
 * Llama a la API de mensajes de Claude con el historial de mensajes dado.
 * Devuelve el arreglo decodificado de la respuesta, o null si falló.
 */
function ia_llamar_claude(array $mensajes): ?array
{
    // El system prompt y las tools son fijos — se mandan idénticos en cada
    // llamada. Poner cache_control en el bloque de system cachea tools+system
    // juntos (tools se renderiza antes que system en la solicitud a la API),
    // así que solo hace falta un breakpoint aquí. La primera llamada de cada
    // ventana de caché (5 min) paga el precio normal; las siguientes pagan
    // ~10% de esa parte del prompt en vez de 100%. Al pegarle la fecha de
    // hoy al final, el caché se invalida una vez al día (cuando cambia la
    // fecha) en vez de cada 5 minutos — sigue aprovechando el caché casi
    // siempre.
    $systemTexto = IA_SYSTEM_PROMPT
        . "\n\nFecha y hora actual real ahora mismo: " . ia_fecha_actual_es()
        . ". Úsala siempre como referencia de \"hoy\" — nunca la calcules ni la asumas de otra forma, y nunca inventes ni redondees una fecha por tu cuenta."
        . " Si saludas (buenos días/tardes/noches), básalo SIEMPRE en esta hora actual real, nunca en lo que haya"
        . " dicho el cliente antes en la conversación — pudo haber pasado tiempo (incluso horas) desde su último mensaje.";
    $payload = [
        'model' => IA_MODEL,
        // Con 1500 se descubrió que el modelo a veces gasta TODO el límite
        // "pensando" (thinking) antes de escribir una sola palabra de
        // respuesta o llamar una herramienta — se queda sin espacio para
        // contestar (stop_reason=max_tokens con 1500 thinking_tokens y 0 de
        // texto real). Se sube el límite y se apaga el razonamiento
        // extendido explícitamente: este bot necesita respuestas cortas de
        // WhatsApp, no requiere ese razonamiento, y cuesta tokens de más.
        'max_tokens' => 4096,
        'thinking' => ['type' => 'disabled'],
        'system' => [
            ['type' => 'text', 'text' => $systemTexto, 'cache_control' => ['type' => 'ephemeral']],
        ],
        'tools' => IA_TOOLS,
        'messages' => $mensajes,
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $status !== 200) {
        file_put_contents(__DIR__ . '/ia_debug.log', date('c')
            . " | status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
        return null;
    }

    return json_decode($raw, true);
}

/**
 * $mensajes: lista ordenada (más antiguo primero) de
 * ['role' => 'user'|'assistant', 'content' => string]. El último debe ser
 * role=user (el mensaje que se está respondiendo).
 * $telefono: número de WhatsApp de la conversación — lo necesitan las
 * herramientas de agendado para saber a nombre de quién apartar la cita.
 *
 * Devuelve ['texto' => string, 'lead' => null|['tipo','estado','nombre','resumen'],
 * 'pdf_calculo' => null|['calc' => array, 'salario_diario' => float]].
 * tipo es 'despido' o 'asesoria_paga'. pdf_calculo, cuando no es null, es
 * el PDF formal del cálculo pendiente de mandar por WhatsApp — lo manda
 * quien llama a esta función (whatsapp_procesar.php), DESPUÉS de la
 * respuesta de texto y con su propio retraso, no aquí.
 */
function ia_responder_whatsapp(PDO $pdo, array $mensajes, string $telefono): array
{
    $credentialsFile = __DIR__ . '/anthropic_credentials.php';
    if (!file_exists($credentialsFile)) {
        error_log('Falta api/anthropic_credentials.php');
        file_put_contents(__DIR__ . '/ia_debug.log', date('c')
            . " | [sin_texto] tel=$telefono | motivo=falta anthropic_credentials.php\n", FILE_APPEND);
        return ['texto' => IA_FALLBACK_TEXTO, 'lead' => null];
    }
    require_once $credentialsFile;
    require_once __DIR__ . '/liquidacion_calculadora.php';
    require_once __DIR__ . '/prescripcion_calculadora.php';
    require_once __DIR__ . '/salarios_caidos_calculadora.php';
    require_once __DIR__ . '/citas_helpers.php';
    require_once __DIR__ . '/mercadopago_helpers.php';
    require_once __DIR__ . '/prospectos_helpers.php';
    require_once __DIR__ . '/push_helpers.php';

    // Herramientas cuyo resultado hay que calcular de verdad en PHP (no
    // dejar que la IA "invente" el número o el horario) y devolverle a
    // Claude para que redacte la respuesta final ya con el dato real.
    // Claude a veces encadena más de una (p. ej. ofrecer_horarios_asesoria
    // y, una vez elegido el horario, confirmar_horario_asesoria) sin
    // escribir texto todavía — por eso esto es un ciclo y no una sola
    // "segunda llamada", con un tope de rondas por seguridad.
    $herramientasConSeguimiento = ['calcular_estimado_liquidacion', 'calcular_plazo_demanda', 'calcular_salarios_caidos', 'ofrecer_horarios_asesoria', 'confirmar_horario_asesoria'];
    $mensajesActuales = $mensajes;
    $lead = null;
    $texto = '';
    $maxRondas = 4;
    $rondasResumen = [];
    // Cálculo pendiente de mandar como PDF — se guarda aquí en vez de
    // mandarlo al instante, para que whatsapp_procesar.php lo mande
    // DESPUÉS de la respuesta de texto (con su propio retraso natural),
    // no al mismo tiempo que se calculó.
    $pdfCalculoPendiente = null;

    for ($ronda = 0; $ronda < $maxRondas; $ronda++) {
        $data = ia_llamar_claude($mensajesActuales);
        if ($data === null) {
            return ['texto' => IA_FALLBACK_TEXTO, 'lead' => $lead, 'pdf_calculo' => $pdfCalculoPendiente];
        }

        [$textoRonda, $leadRonda, $bloques] = ia_extraer_respuesta($data);
        if ($leadRonda !== null && ($lead === null || $leadRonda['tipo'] === 'despido')) {
            $lead = $leadRonda;
        }
        if (trim($textoRonda) !== '') {
            $texto = $textoRonda;
        }

        $toolUseBlocks = array_values(array_filter($bloques, fn($b) => ($b['type'] ?? '') === 'tool_use'));
        $rondasResumen[] = 'ronda' . $ronda . '=[' . implode(',', array_map(fn($b) => $b['name'] ?? '?', $toolUseBlocks))
            . ']' . (trim($textoRonda) !== '' ? '+texto' : '');
        if (!$toolUseBlocks) {
            // No llamó ninguna herramienta — $textoRonda es la respuesta final.
            break;
        }

        $tieneSeguimiento = false;
        foreach ($toolUseBlocks as $bloque) {
            if (in_array($bloque['name'] ?? '', $herramientasConSeguimiento, true)) {
                $tieneSeguimiento = true;
                break;
            }
        }
        if (!$tieneSeguimiento) {
            // Solo llamó herramientas de puro registro (p. ej.
            // registrar_lead_despido sola, sin ofrecer horarios) — no
            // necesitan que Claude redacte de nuevo con datos calculados,
            // así que $textoRonda ya es la respuesta final.
            break;
        }

        // La API de Claude exige un tool_result por CADA tool_use que
        // haya en la respuesta anterior — incluyendo las de puro registro
        // (registrar_lead_despido, registrar_interes_asesoria_paga), no
        // solo las que necesitan cálculo real. Omitir una deja ese
        // tool_use "huérfano" y la API rechaza la siguiente llamada con
        // 400 ("tool_use ids were found without tool_result blocks").
        $toolResults = [];
        foreach ($toolUseBlocks as $bloque) {
            $in = $bloque['input'] ?? [];
            if ($bloque['name'] === 'calcular_estimado_liquidacion') {
                $calc = calcular_estimado_liquidacion(
                    $pdo,
                    (string)($in['fecha_ingreso'] ?? ''),
                    (string)($in['fecha_baja'] ?? ''),
                    (float)($in['salario_diario'] ?? 0),
                    (string)($in['tipo'] ?? 'injustificado'),
                    (float)($in['dias_vacaciones_anteriores'] ?? 0),
                    (float)($in['dias_salarios_devengados'] ?? 0)
                );
                if ($calc !== null) {
                    $contenido = json_encode($calc, JSON_UNESCAPED_UNICODE);
                    // Se registra el cálculo (aunque la persona nunca llegue a
                    // decir "sí, quiero agendar") — es la señal más confiable
                    // de que tiene un caso real y mostró interés, y se usa
                    // para el seguimiento proactivo si se queda callada. Ver
                    // cron_seguimiento_calculadora.php.
                    $insCalc = $pdo->prepare(
                        'INSERT INTO calculos_liquidacion (telefono, monto_total) VALUES (:t, :m)'
                    );
                    $insCalc->execute([':t' => $telefono, ':m' => $calc['total_estimado'] ?? null]);

                    // El PDF formal (mismo diseño que la calculadora del
                    // sitio) se manda después, junto con la respuesta de
                    // texto, no aquí — ver el retorno de esta función.
                    $pdfCalculoPendiente = ['calc' => $calc, 'salario_diario' => (float)($in['salario_diario'] ?? 0)];
                } else {
                    $contenido = json_encode([
                        'error' => 'Datos insuficientes o inválidos para calcular.',
                        'instruccion' => 'NO vuelvas a llamar esta herramienta adivinando o inventando el dato que falta. En vez de eso, tu respuesta de texto en este mismo turno debe preguntarle directamente a la persona el dato específico que falta (fecha de ingreso, fecha de baja, salario diario/mensual, o si el despido es justificado o injustificado).',
                    ], JSON_UNESCAPED_UNICODE);
                }
            } elseif ($bloque['name'] === 'calcular_plazo_demanda') {
                $plazo = calcular_plazo_demanda(
                    (string)($in['fecha_despido'] ?? ''),
                    trim((string)($in['fecha_solicitud_conciliacion'] ?? '')) ?: null,
                    trim((string)($in['fecha_fin_conciliacion'] ?? '')) ?: null
                );
                $contenido = $plazo !== null
                    ? json_encode($plazo, JSON_UNESCAPED_UNICODE)
                    : json_encode(['error' => 'Fecha de despido inválida o faltante.'], JSON_UNESCAPED_UNICODE);
            } elseif ($bloque['name'] === 'calcular_salarios_caidos') {
                $salariosCaidos = calcular_salarios_caidos(
                    (string)($in['fecha_despido'] ?? ''),
                    (float)($in['salario_diario'] ?? 0)
                );
                $contenido = $salariosCaidos !== null
                    ? json_encode($salariosCaidos, JSON_UNESCAPED_UNICODE)
                    : json_encode(['error' => 'Fecha de despido o salario diario inválidos/faltantes.'], JSON_UNESCAPED_UNICODE);
            } elseif ($bloque['name'] === 'ofrecer_horarios_asesoria') {
                $contenido = ia_resultado_ofrecer_horarios($pdo, $telefono, $lead);
            } elseif ($bloque['name'] === 'confirmar_horario_asesoria') {
                $contenido = ia_resultado_confirmar_horario($pdo, $telefono, $in, $lead);
            } else {
                // registrar_lead_despido, registrar_interes_asesoria_paga:
                // solo hace falta reconocer la llamada, ya se registró el
                // lead en ia_extraer_respuesta().
                $contenido = json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            }
            $toolResults[] = [
                'type' => 'tool_result',
                'tool_use_id' => $bloque['id'],
                'content' => $contenido,
            ];
        }

        // Al decodificar la respuesta de Claude, un tool_use con input
        // vacío ({} — como ofrecer_horarios_asesoria, que no recibe
        // parámetros) llega como arreglo PHP vacío []. Si se manda así de
        // regreso, json_encode lo serializa como [] (arreglo JSON) en vez
        // de {} (objeto JSON), y la API lo rechaza con 400 "Input should
        // be an object". Se normaliza aquí antes de reenviarlo.
        $bloquesParaEnviar = array_map(function ($bloque) {
            if (($bloque['type'] ?? '') === 'tool_use' && empty($bloque['input'])) {
                $bloque['input'] = new stdClass();
            }
            return $bloque;
        }, $bloques);

        $mensajesActuales[] = ['role' => 'assistant', 'content' => $bloquesParaEnviar];
        $mensajesActuales[] = ['role' => 'user', 'content' => $toolResults];
    }

    if (trim($texto) === '') {
        // Todas las llamadas a la API funcionaron (si no, ya se habría
        // registrado y devuelto arriba), pero Claude nunca terminó de
        // redactar un texto final — típicamente porque se agotaron las
        // $maxRondas encadenando herramientas sin concluir. Se registra
        // aparte porque este caso no deja huella en ningún otro lado.
        file_put_contents(__DIR__ . '/ia_debug.log', date('c')
            . " | [sin_texto] tel=$telefono | rondas_usadas=" . count($rondasResumen) . "/$maxRondas | "
            . implode(' | ', $rondasResumen) . "\n", FILE_APPEND);

        // Última oportunidad antes de rendirse: se le pide explícitamente
        // que conteste por texto, sin usar ninguna herramienta más — para
        // que el cliente reciba algo útil en vez del mensaje genérico.
        $mensajesActuales[] = [
            'role' => 'user',
            'content' => '(Sistema: ya intentaste varias herramientas. Ahora SOLO contesta con texto normal, sin llamar ninguna herramienta, usando la información que ya tienes. Si te falta un dato, pregúntalo directo.)',
        ];
        $dataFinal = ia_llamar_claude($mensajesActuales);
        if ($dataFinal !== null) {
            [$textoFinal, , ] = ia_extraer_respuesta($dataFinal);
            if (trim($textoFinal) !== '') $texto = $textoFinal;
        }

        if (trim($texto) === '') {
            file_put_contents(__DIR__ . '/ia_debug.log', date('c')
                . " | [sin_texto_final] tel=$telefono | stop_reason=" . ($dataFinal['stop_reason'] ?? 'null')
                . " | raw=" . json_encode($dataFinal, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            $texto = IA_FALLBACK_TEXTO;
        }
    }

    return ['texto' => trim($texto), 'lead' => $lead, 'pdf_calculo' => $pdfCalculoPendiente];
}

// URL pública del webhook de Mercado Pago — a donde Mercado Pago avisa
// cuando se confirma un pago. Debe coincidir con dónde vive el sistema.
const MERCADOPAGO_WEBHOOK_URL = 'https://sistema.expertoslaborales.com/sistema/api/mercadopago_webhook.php';

/**
 * Resultado (como JSON) de la herramienta ofrecer_horarios_asesoria: la
 * lista de horarios reales disponibles ahora mismo. Si no hay ninguno (por
 * ejemplo, todavía ningún abogado configuró su disponibilidad), recién
 * AHÍ se registra el prospecto (no antes, con solo el interés) y se pausa
 * el bot — a partir de ahí un humano tiene que coordinar directo.
 */
function ia_resultado_ofrecer_horarios(PDO $pdo, string $telefono, ?array $lead): string
{
    $horarios = citas_calcular_horarios_disponibles($pdo);
    if (!$horarios) {
        ia_registrar_prospecto_atorado($pdo, $telefono, $lead, 'Mostró interés en la asesoría de pago pero no hay horarios disponibles en este momento.');
        return json_encode([
            'horarios' => [],
            'nota' => 'No hay horarios disponibles en este momento. Dile a la persona que un abogado del despacho la va a contactar directo para coordinar — no le des ningún link de pago ni le prometas un horario.',
        ], JSON_UNESCAPED_UNICODE);
    }
    return json_encode(['horarios' => $horarios], JSON_UNESCAPED_UNICODE);
}

/**
 * Resultado (como JSON) de la herramienta confirmar_horario_asesoria:
 * aparta el horario elegido y genera el link de pago (solo tarjeta) de
 * Mercado Pago. Si el horario ya no está libre, o si algo falla al
 * generar el link, avisa y — cuando ya no hay nada más que el bot pueda
 * hacer — recién ahí registra el prospecto y pausa la conversación para
 * que un humano la retome.
 */
function ia_resultado_confirmar_horario(PDO $pdo, string $telefono, array $in, ?array $lead): string
{
    $fecha = (string)($in['fecha'] ?? '');
    $horaInicio = (string)($in['hora_inicio'] ?? '');
    $nombre = trim((string)($in['nombre'] ?? '')) ?: null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !preg_match('/^\d{2}:\d{2}$/', $horaInicio)) {
        return json_encode(['ok' => false, 'motivo' => 'Formato de fecha/hora inválido — vuelve a ofrecer los horarios disponibles con ofrecer_horarios_asesoria.'], JSON_UNESCAPED_UNICODE);
    }

    // Nunca hay que confiar en que la IA mandó una fecha real y vigente —
    // puede llegar a copiar mal el año/fecha, sobre todo si la
    // conversación con el cliente lleva ya un rato. Se revalida contra la
    // lista de horarios de verdad disponibles AHORA MISMO antes de apartar
    // nada; citas_crear_pendiente() por sí sola no rechaza una fecha
    // pasada, solo checa que algún abogado tenga ese día/hora en su
    // disponibilidad semanal.
    $horariosVigentes = citas_calcular_horarios_disponibles($pdo);
    $esValido = false;
    foreach ($horariosVigentes as $h) {
        if ($h['fecha'] === $fecha && $h['hora_inicio'] === $horaInicio) {
            $esValido = true;
            break;
        }
    }
    if (!$esValido) {
        return json_encode([
            'ok' => false,
            'motivo' => 'Ese horario ya no está disponible.',
            'horarios_alternativos' => $horariosVigentes,
        ], JSON_UNESCAPED_UNICODE);
    }

    $citaId = citas_crear_pendiente($pdo, $telefono, $fecha, $horaInicio, $nombre);
    if ($citaId === null) {
        return json_encode([
            'ok' => false,
            'motivo' => 'Ese horario ya no está disponible.',
            'horarios_alternativos' => citas_calcular_horarios_disponibles($pdo),
        ], JSON_UNESCAPED_UNICODE);
    }

    $pref = mercadopago_crear_preferencia_asesoria($citaId, $telefono, MERCADOPAGO_WEBHOOK_URL);
    if ($pref === null) {
        // No dejamos la cita "atorada" ocupando el horario si no se pudo
        // generar el link de pago.
        $stmt = $pdo->prepare("UPDATE citas_asesoria SET estado = 'cancelada' WHERE id = :id");
        $stmt->execute([':id' => $citaId]);
        ia_registrar_prospecto_atorado($pdo, $telefono, $lead, 'Eligió horario para la asesoría de pago pero hubo un problema técnico generando el link de pago.', $nombre);
        return json_encode([
            'ok' => false,
            'motivo' => 'Hubo un problema técnico generando el link de pago. Dile a la persona que un abogado del despacho le va a mandar el link directo en breve — no le des ningún link tú.',
        ], JSON_UNESCAPED_UNICODE);
    }

    $stmt = $pdo->prepare("UPDATE citas_asesoria SET mp_preference_id = :pref, link_pago = :link WHERE id = :id");
    $stmt->execute([':pref' => $pref['id'], ':link' => $pref['init_point'], ':id' => $citaId]);

    return json_encode([
        'ok' => true,
        'link_pago' => $pref['init_point'],
        'horario' => citas_formatear_fecha_hora($fecha, $horaInicio),
        'vigencia_minutos' => CITAS_HOLD_MINUTOS,
        'monto' => MERCADOPAGO_MONTO_ASESORIA,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Registra (o actualiza) el prospecto de asesoría de pago y pausa el bot
 * cuando el flujo automático ya no puede seguir solo (sin horarios, o
 * falló el pago) — esta es la ÚNICA vez que un interesado en la asesoría
 * de pago se guarda en Prospectos: mientras el bot sigue ofreciendo
 * horarios y generando el link de pago solo, no se guarda nada, para no
 * llenarle la lista al despacho con conversaciones que no necesitan que
 * un humano intervenga. $lead trae el resumen/estado si se registró
 * interés en esta misma ronda; si es null (p. ej. preguntó horarios
 * directo, sin haber dicho antes que le interesaba) se usa un resumen
 * genérico.
 */
function ia_registrar_prospecto_atorado(PDO $pdo, string $telefono, ?array $lead, string $resumenFallback, ?string $nombre = null): void
{
    $datosLead = $lead ?? ['tipo' => 'asesoria_paga', 'estado' => '', 'nombre' => '', 'resumen' => ''];
    if (trim($datosLead['resumen']) === '') {
        $datosLead['resumen'] = $resumenFallback;
    }
    guardar_prospecto($pdo, $telefono, $nombre, $datosLead, true, true);
    push_notificar_prospecto($pdo, null, 'Asesoría atorada, necesita ayuda', ($datosLead['nombre'] ?: $telefono) . ' — ' . $resumenFallback, '/sistema/?abrir=' . urlencode($telefono));
}

/**
 * Extrae el texto, el lead (si se llamó alguna herramienta de registro) y
 * los bloques de contenido crudos de una respuesta de la API de Claude.
 * Devuelve [texto, lead, bloques].
 */
function ia_extraer_respuesta(array $data): array
{
    $bloques = $data['content'] ?? [];

    $texto = '';
    $lead = null;
    foreach ($bloques as $bloque) {
        if (($bloque['type'] ?? '') === 'text') {
            $texto .= $bloque['text'];
        } elseif (($bloque['type'] ?? '') === 'tool_use' && in_array($bloque['name'] ?? '', ['registrar_lead_despido', 'registrar_interes_asesoria_paga'], true)) {
            $input = $bloque['input'] ?? [];
            $nuevoLead = [
                'tipo' => $bloque['name'] === 'registrar_lead_despido' ? 'despido' : 'asesoria_paga',
                'estado' => (string)($input['estado'] ?? ''),
                'nombre' => (string)($input['nombre'] ?? ''),
                'resumen' => (string)($input['resumen'] ?? ''),
            ];
            // Si Claude llama ambas herramientas en el mismo turno, el lead
            // de despido (más valioso: litigio) manda sobre el de asesoría paga.
            if ($lead === null || $nuevoLead['tipo'] === 'despido') {
                $lead = $nuevoLead;
            }
        }
    }

    return [$texto, $lead, $bloques];
}

/**
 * Genera (o regenera) un resumen breve, de uso interno, de una conversación
 * completa de WhatsApp — para que el abogado no tenga que leer todo el
 * hilo para saber de qué se trató y cómo respondió el bot. Llamada
 * completamente aparte del flujo de respuesta en vivo (no usa IA_SYSTEM_PROMPT
 * ni IA_TOOLS): no toca ni arriesga la lógica del bot en producción.
 * $historial: lista ordenada (más antiguo primero) de ['direccion' => 'entrante'|'saliente', 'texto' => string].
 * Devuelve el resumen en texto plano, o null si falló la llamada a la API.
 */
function ia_generar_resumen_conversacion(array $historial): ?string
{
    $credentialsFile = __DIR__ . '/anthropic_credentials.php';
    if (!file_exists($credentialsFile)) {
        error_log('Falta api/anthropic_credentials.php');
        return null;
    }
    require_once $credentialsFile;

    $transcript = '';
    foreach ($historial as $h) {
        $quien = ($h['direccion'] ?? '') === 'entrante' ? 'Cliente' : 'Bot/Despacho';
        $transcript .= $quien . ': ' . ($h['texto'] ?? '') . "\n";
    }
    if (trim($transcript) === '') return null;

    $payload = [
        'model' => IA_MODEL,
        'max_tokens' => 400,
        'system' => 'Eres un asistente interno del despacho Expertos Laborales. Te doy la transcripción '
            . 'completa de una conversación de WhatsApp entre un posible cliente y el bot de asesoría '
            . 'laboral del despacho. Escribe un resumen breve (4-6 líneas, en español, sin encabezados ni '
            . 'viñetas) para que un abogado entienda rápido: de qué se trata el caso o duda, qué le '
            . 'contestó el bot, si el bot calificó bien la situación o si parece que se equivocó o dio '
            . 'información dudosa, y en qué quedó la conversación (pendiente, resuelta, el cliente dejó '
            . 'de contestar, etc.). Sé directo y crítico si notas algo que el bot debería mejorar.',
        'messages' => [['role' => 'user', 'content' => $transcript]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $status !== 200) {
        file_put_contents(__DIR__ . '/ia_debug.log', date('c')
            . " | [resumen] status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
        return null;
    }

    $data = json_decode($raw, true);
    $texto = '';
    foreach (($data['content'] ?? []) as $bloque) {
        if (($bloque['type'] ?? '') === 'text') $texto .= $bloque['text'];
    }
    $texto = trim($texto);
    return $texto !== '' ? $texto : null;
}
