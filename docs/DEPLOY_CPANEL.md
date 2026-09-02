# Guía de despliegue — cPanel de DonWeb (expertoslaborales.com)

Esta guía asume el plan **Web Hosting Plan 1 + Correos** de DonWeb, con acceso a **cPanel**. El sistema quedará instalado en:

```
https://expertoslaborales.com/sistema/
```

Sigue los pasos en orden. Ninguno requiere terminal/SSH — todo se hace desde cPanel en el navegador.

---

## 0. Antes de empezar

- Ten a la mano tu usuario y contraseña de cPanel (te los dio DonWeb por correo al contratar el hosting).
- Ten los archivos de este proyecto listos para subir (la carpeta completa del repositorio).
- Reserva unos 30–40 minutos.

---

## 1. Verificar la versión de PHP

1. En cPanel, busca **"MultiPHP Manager"** (sección Software).
2. Busca el dominio `expertoslaborales.com` en la lista.
3. Selecciona la versión de PHP **8.1 o superior** (8.2/8.3 si están disponibles) y guarda.
   - Si el dominio no aparece todavía porque no has subido nada, puedes volver a este paso después del punto 4.

---

## 2. Crear la base de datos MySQL

1. En cPanel, entra a **"Bases de datos MySQL®"**.
2. En **"Crear nueva base de datos"**, escribe un nombre, por ejemplo `expedientes` (cPanel le va a poner un prefijo automático, algo como `usuariocp_expedientes`). Click en **Crear base de datos**.
3. Baja a **"MySQL® Usuarios"** → **"Crear nuevo usuario"**:
   - Usuario: por ejemplo `sistema` (quedará como `usuariocp_sistema`).
   - Contraseña: genera una segura con el botón **"Generar contraseña"** de cPanel y **guárdala** (la vas a necesitar en el paso 6). No la compartas.
   - Click en **Crear usuario**.
4. Baja a **"Agregar usuario a base de datos"**:
   - Usuario: el que acabas de crear.
   - Base de datos: la que acabas de crear.
   - Click en **Agregar**.
   - En la siguiente pantalla ("Privilegios administrativos"), marca **"TODOS LOS PRIVILEGIOS"** (ALL PRIVILEGES) y click en **Hacer cambios**.
5. Anota en un lugar seguro estos 4 datos — los vas a necesitar en el paso 6:
   - **Host de la base de datos**: `localhost` (casi siempre, en DonWeb)
   - **Nombre de la base de datos**: `usuariocp_expedientes` (el nombre completo con prefijo)
   - **Usuario**: `usuariocp_sistema` (el nombre completo con prefijo)
   - **Contraseña**: la que generaste

---

## 3. Importar el esquema de tablas

1. Todavía en cPanel, entra a **"phpMyAdmin"** (sección Bases de datos).
2. En el panel izquierdo, selecciona tu base de datos (`usuariocp_expedientes`).
3. Ve a la pestaña **"Importar"** (Import) en la parte superior.
4. Click en **"Seleccionar archivo"** y elige el archivo `sql/schema.sql` de este proyecto.
5. Baja hasta el final y click en **"Continuar"** / **"Go"**.
6. Deberías ver un mensaje de éxito y, en el panel izquierdo, 10 tablas nuevas (`usuarios`, `expedientes`, `expediente_etapas`, etc.). Si algo falla, revisa que hayas seleccionado la base de datos correcta antes de importar.

---

## 4. Subir los archivos del sistema

1. En cPanel, entra a **"Administrador de archivos"** (File Manager).
2. Entra a la carpeta `public_html`.
3. Crea una carpeta nueva llamada `sistema` (así quedará en `public_html/sistema/`, que corresponde a `https://expertoslaborales.com/sistema/`).
4. Entra a esa carpeta y sube el contenido del proyecto:
   - Lo más fácil: comprime en tu computadora las carpetas `api/`, `assets/`, `data/`, `scripts/`, `sql/` y el archivo `index.html` en un solo `.zip`, súbelo con **"Cargar"** (Upload) dentro de `public_html/sistema/`, y luego usa **"Extraer"** (Extract) sobre ese `.zip` desde el propio Administrador de archivos.
   - Alternativa: si prefieres FTP, usa un cliente como FileZilla con los datos de tu cuenta FTP de cPanel y sube la misma carpeta a `public_html/sistema/`.
5. Verifica que la estructura final en el servidor sea:
   ```
   public_html/sistema/index.html
   public_html/sistema/assets/style.css
   public_html/sistema/assets/app.js
   public_html/sistema/api/... (todos los .php)
   public_html/sistema/data/.htaccess
   public_html/sistema/scripts/import_casos.php
   public_html/sistema/scripts/casos_data.json
   public_html/sistema/sql/schema.sql
   ```

---

## 5. Configurar la conexión a la base de datos

1. En el Administrador de archivos, entra a `public_html/sistema/api/`.
2. Busca el archivo `db_credentials.example.php`, selecciónalo y usa **"Copiar"** para duplicarlo con el nombre **`db_credentials.php`** (en la misma carpeta `api/`).
3. Click derecho sobre `db_credentials.php` → **"Editar"**.
4. Reemplaza los valores con los datos que anotaste en el paso 2.5:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'usuariocp_expedientes');
   define('DB_USER', 'usuariocp_sistema');
   define('DB_PASS', 'la-contraseña-que-generaste');
   define('APP_SECRET', 'una-cadena-aleatoria-larga-y-unica');
   ```
   Para `APP_SECRET`, escribe cualquier texto largo y aleatorio (30+ caracteres, letras y números mezclados) que no uses en ningún otro lado — es solo una llave interna del sistema, no la compartas.
5. Guarda el archivo.
6. **Importante**: nunca subas `db_credentials.php` a un repositorio público ni lo compartas — contiene la contraseña de tu base de datos. El proyecto ya lo excluye de git automáticamente (`.gitignore`).

---

## 6. Activar HTTPS (SSL)

1. En cPanel, busca **"SSL/TLS Status"** o **"AutoSSL"** (a veces aparece como "Seguridad" → "SSL/TLS").
2. Verifica que `expertoslaborales.com` (y `www.expertoslaborales.com` si aplica) tengan un candado verde / certificado activo. Si no, click en **"Ejecutar AutoSSL"** y espera unos minutos.
3. El sistema usa cookies de sesión seguras — funciona mejor (y de forma más segura) sobre `https://`. Si tu dominio no carga aún en https, contacta a soporte de DonWeb para activar el certificado gratuito antes de continuar.

---

## 7. Importar los 49 expedientes reales y crear los usuarios iniciales

Este paso se hace **una sola vez**, ejecutando un script especial desde el navegador.

1. Abre esta URL en tu navegador (reemplaza `TU_APP_SECRET` por el valor exacto que pusiste en `APP_SECRET` en el paso 5):
   ```
   https://expertoslaborales.com/sistema/scripts/import_casos.php?clave=TU_APP_SECRET
   ```
2. Verás un reporte de texto plano. **Cópialo y guárdalo en un lugar seguro (por ejemplo, un documento privado o un gestor de contraseñas)** — muestra:
   - El correo y la **contraseña temporal** del usuario **Administrador**.
   - El correo y la **contraseña temporal** del usuario **Lic. Lindsay Luna Maldonado**.
   - Cuántos expedientes se importaron (debería decir 49).
   - Avisos de algún dato mal capturado en la hoja original (si los hay) que quizás quieras revisar y corregir a mano después, desde "Editar datos" dentro del sistema.
3. **Muy importante — borra el script después de usarlo.** En el Administrador de archivos, dentro de `public_html/sistema/scripts/`, borra (o al menos renombra) los archivos `import_casos.php` y `casos_data.json`. Si los dejas ahí, alguien que adivine tu `APP_SECRET` podría volver a ejecutarlo. El script en sí ya se niega a correr una segunda vez si detecta que ya hay usuarios/expedientes, pero es buena práctica quitarlo del servidor de todas formas.

---

## 8. Primer ingreso al sistema

1. Ve a `https://expertoslaborales.com/sistema/`.
2. Inicia sesión con el correo y la contraseña temporal del **Administrador** que anotaste en el paso 7.
3. El sistema te va a pedir de inmediato crear una contraseña nueva (la temporal ya no sirve después de esto). Elige una contraseña robusta que solo tú conozcas.
4. Ve a la vista **"Equipo"**:
   - Si el correo real de la Lic. Lindsay Luna Maldonado (o el tuyo, como Administrador) es distinto al que se generó automáticamente (`admin@expertoslaborales.com` / `lindsay@expertoslaborales.com`), puedes corregirlo directamente en phpMyAdmin (tabla `usuarios`, columna `email`) — el sistema no tiene botón para cambiar el correo desde la interfaz todavía, solo el nombre.
   - Usa **"Restablecer contraseña"** para generarle una contraseña temporal nueva a la Lic. Lindsay Luna Maldonado y compártesela por un medio seguro (WhatsApp, en persona, etc. — no por correo sin cifrar si puedes evitarlo).
   - Usa **"+ Agregar abogado"** para dar de alta a cualquier otro miembro del equipo. El sistema te mostrará una contraseña temporal una sola vez — cópiala de inmediato y compártela con esa persona.
5. Cada persona, en su primer ingreso, deberá cambiar su propia contraseña temporal por una definitiva.

Con esto, cada abogado/a entra desde su propio dispositivo con su propio correo y contraseña, ve solo los expedientes que tiene asignados (salvo el Administrador, que ve todos), y las contraseñas están cifradas de verdad (bcrypt) en MySQL — ya no es posible que alguien cree una cuenta nueva por su cuenta ni que entre al perfil de otra persona desde otro dispositivo.

---

## 9. Portal de clientes (opcional, por expediente)

Cada expediente tiene ahora un **código de acceso** propio (además del número de expediente y el apellido) para que el cliente pueda consultarlo desde `https://expertoslaborales.com/sistema/` → "Acceder como cliente". Para dárselo a un cliente:

1. Abre el expediente dentro del sistema.
2. Ve a la pestaña donde se gestiona el acceso del cliente (o pídele al Administrador que lo genere) y comparte el código junto con el número de expediente y el apellido registrado, por el medio que prefieras (WhatsApp, en persona).
3. Después de varios intentos fallidos, el acceso a ese expediente se bloquea temporalmente por seguridad.

---

## 10. WhatsApp con IA (asesoría automática + captación de prospectos)

Esto conecta un número de WhatsApp dedicado a un asistente con IA (Claude) que
contesta dudas laborales automáticamente y capta dos tipos de prospecto:

- **Despido en CDMX/Edomex**: cuando alguien relata que lo despidieron y
  radica en Ciudad de México o Estado de México, el bot deja de contestar
  solo y lo registra como posible cliente de litigio.
- **Asesoría personalizada de pago**: a cualquier persona, de cualquier
  estado, el bot le ofrece la asesoría de 1 hora por $399 MXN. Si la
  persona muestra interés, también se registra como prospecto — el bot no
  cobra por sí solo, tú le mandas los datos de pago/agenda a mano desde la
  conversación.

Ambos aparecen en la vista "Prospectos (WhatsApp)" del sistema para que tú
les des seguimiento personalmente.

**Importante**: el número que uses aquí queda dedicado a la API — ya no se
puede abrir en la app normal de WhatsApp del celular. Usa un número nuevo
que no sea tu WhatsApp personal.

**Visibilidad**: la vista "Prospectos (WhatsApp)" solo la ve el
Administrador — un socio no ve estos leads hasta que el Administrador
convierte uno en expediente y se lo asigna; a partir de ahí lo ve como
cualquier otro asunto suyo.

### 10.1 Importar las tablas nuevas

1. En phpMyAdmin, con tu base de datos seleccionada, ve a la pestaña
   **"Importar"** otra vez y sube el archivo `sql/migraciones/012_whatsapp_prospectos.sql`.
   Esto crea las tablas `prospectos` y `whatsapp_conversaciones`.
2. Repite el mismo paso con `sql/migraciones/013_prospectos_asesoria_paga.sql`
   (agrega la columna que distingue un lead de despido de uno de asesoría
   de pago).

### 10.2 Dar de alta la app de WhatsApp en Meta

1. Entra a [developers.facebook.com](https://developers.facebook.com) con
   una cuenta de Facebook (idealmente una cuenta de negocio), crea una app
   tipo **"Negocios"** y agrégale el producto **WhatsApp**.
2. En **WhatsApp → Configuración de la API**, Meta te da un número de
   prueba gratis para probar de inmediato. Cuando quieras usar tu número
   dedicado real, agrégalo ahí mismo (requiere verificarlo por SMS/llamada).
3. Copia el **"Phone number ID"** (no es el número telefónico, es un ID
   largo) — lo vas a necesitar en el paso 10.3.
4. Para producción (que el token no expire cada 24 horas), ve a **Meta
   Business Suite → Usuarios del sistema**, crea un "System User", dale
   permiso sobre tu app de WhatsApp, y genera ahí un **token de acceso
   permanente**.
5. En **Configuración básica** de la app, copia el **"App secret"**.

### 10.3 Configurar las credenciales en el servidor

Hazlo antes de dar de alta el webhook en Meta (paso 10.4): Meta llama al
webhook para verificarlo en cuanto lo guardas, así que las credenciales
—en especial el verify token— ya deben estar puestas.

1. En el Administrador de archivos de cPanel, dentro de `public_html/sistema/api/`,
   copia `whatsapp_credentials.example.php` como `whatsapp_credentials.php` y
   llena `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_ID` y `WHATSAPP_APP_SECRET` con
   los datos del paso 10.2. En `WHATSAPP_VERIFY_TOKEN` inventa tú una
   cadena larga y aleatoria — la vas a volver a pegar, idéntica, en Meta en
   el paso 10.4.
2. Saca una API key en [console.anthropic.com](https://console.anthropic.com)
   (es de pago por uso, sin plan mensual fijo — actívale un método de pago
   ahí mismo). Copia `anthropic_credentials.example.php` como
   `anthropic_credentials.php` y pega tu API key en `ANTHROPIC_API_KEY`.
3. Ninguno de los dos archivos `.php` con credenciales reales debe subirse
   a git (ya están en `.gitignore`) — solo viven en el servidor.

### 10.4 Configurar el webhook en Meta

1. En **WhatsApp → Configuración de la API** (o **Configuración → Webhooks**
   de tu app), da de alta un webhook con:
   - **URL de retorno de llamada**: `https://expertoslaborales.com/sistema/api/whatsapp_webhook.php`
   - **Verify token**: pega exactamente el mismo valor que pusiste en
     `WHATSAPP_VERIFY_TOKEN` en el paso 10.3.
2. Suscríbete al campo **`messages`**.

### 10.5 Probar

1. Manda un WhatsApp de prueba al número dedicado (desde tu celular
   personal, con otro chat). El bot debería contestar en unos segundos.
2. Escribe un mensaje simulando un despido en CDMX o Edomex (p. ej. "me
   despidieron ayer de mi trabajo en la Ciudad de México sin razón"). Debería
   aparecer un prospecto nuevo en la vista **"Prospectos (WhatsApp)"** del
   sistema, con el bot ya pausado para ese número — desde ahí le contestas
   tú directamente, o lo conviertes en expediente con un clic.

### Nota sobre costos y límites de Meta

Cuando el bot solo responde a mensajes que la gente te escribe primero
(que es este caso), Meta no cobra nada — son "conversaciones de servicio",
gratuitas. Meta sí limita cuántas conversaciones nuevas puedes atender por
día hasta que tu número y tu negocio queden verificados en Meta Business
Manager (proceso que puede tardar unos días); una vez verificado, el límite
sube automáticamente.

### 10.6 Puente con Cloudflare Workers (si Meta no puede llegar directo)

Algunos hostings compartidos tienen un firewall/WAF automático que
bloquea las peticiones POST que manda Meta, sin dejar ningún error visible
(la verificación GET funciona bien, pero los mensajes reales nunca llegan).
Si te pasa esto, en vez de mudar todo tu hosting, agregas un "puente"
gratuito con Cloudflare Workers que recibe el mensaje de Meta y se lo
reenvía a tu sistema — a esa conexión sí le va bien, porque ya no viene
directo de Meta.

1. Crea una cuenta gratis en [dash.cloudflare.com/sign-up](https://dash.cloudflare.com/sign-up).
2. En el panel, ve a **Workers y Pages → Crear → Crear Worker**. Ponle un
   nombre (p. ej. `ela-whatsapp-relay`) y despliégalo (te da una plantilla
   de ejemplo, no importa).
3. Dale clic a **"Editar código"**. Borra todo el código de ejemplo y pega
   el contenido completo de `scripts/cloudflare_worker_whatsapp.js` de
   este proyecto. Guarda y despliega.
4. En la configuración del Worker, ve a **Configuración → Variables y
   secretos** y agrega estas 4 (marca "Cifrado/Secret" en las que tienen
   datos sensibles):
   - `VERIFY_TOKEN`: el mismo valor que `WHATSAPP_VERIFY_TOKEN` en tu
     servidor.
   - `APP_SECRET`: el mismo valor que `WHATSAPP_APP_SECRET`.
   - `SISTEMA_RELAY_URL`: `https://sistema.expertoslaborales.com/sistema/api/whatsapp_relay.php`
   - `RELAY_KEY`: una llave nueva que inventes (genera una con
     `php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"` o cualquier
     cadena larga y aleatoria) — la vas a pegar también como
     `WHATSAPP_RELAY_KEY` en tu servidor.
5. Copia la URL pública que te dio Cloudflare para tu Worker (algo como
   `https://ela-whatsapp-relay.tu-usuario.workers.dev`).
6. En `whatsapp_credentials.php` (en tu servidor), agrega la línea:
   ```php
   define('WHATSAPP_RELAY_KEY', 'LA_MISMA_LLAVE_QUE_PUSISTE_EN_RELAY_KEY');
   ```
7. En Meta (developers.facebook.com → tu app → Paso 2: Configuración de
   producción → Configurar webhooks), cambia la **URL de devolución de
   llamada** por la URL de tu Worker de Cloudflare (la del paso 5), dejando
   el mismo Verify token. Dale "Verificar y guardar".
8. Prueba mandando un WhatsApp real — ahora sí debería contestar.

---

## 11. Respaldo periódico

Toda la información ya vive en MySQL, así que el respaldo más confiable es el de la propia base de datos:

- **Rápido, manual**: phpMyAdmin → selecciona la base de datos → pestaña **"Exportar"** → formato SQL → **Continuar**. Descarga y guarda el archivo en un lugar seguro (contiene datos personales sensibles de tus clientes: CURP, teléfonos, salarios — trátalo como confidencial).
- **Automático**: cPanel suele tener una opción **"Copia de seguridad"** (Backup Wizard) que puedes programar o ejecutar periódicamente para respaldar toda la cuenta, incluyendo la base de datos.
- El sistema también tiene, dentro de la vista "Equipo" (solo Administrador), un botón para descargar un respaldo rápido en JSON como referencia adicional — no reemplaza el respaldo real de MySQL.

---

## Resolución de problemas comunes

- **"Falta api/db_credentials.php"**: no completaste el paso 5, o el archivo quedó en otra carpeta. Debe estar exactamente en `public_html/sistema/api/db_credentials.php`.
- **Pantalla en blanco o error 500**: revisa la versión de PHP (paso 1, debe ser 8.1+) y que los 4 datos de `db_credentials.php` sean correctos (usuario/base con el prefijo completo que asigna cPanel).
- **"Correo o contraseña incorrectos" al hacer el primer login**: verifica que copiaste bien la contraseña temporal del reporte del paso 7 (son sensibles a mayúsculas/minúsculas). Si la perdiste, un Administrador puede restablecerla desde phpMyAdmin poniendo `debe_cambiar_password = 1` y una nueva `password_hash` — o, más simple, contáctanos para generar una nueva.
- **El sistema pide iniciar sesión otra vez a cada rato**: confirma que el sitio esté cargando por `https://` (candado verde) — las cookies de sesión están configuradas para requerir conexión segura.
- **El bot de WhatsApp no contesta**: revisa que `api/whatsapp_credentials.php` y `api/anthropic_credentials.php` existan y tengan los datos correctos, que el webhook en Meta muestre estado "Activo" (verde), y que esté suscrito al campo `messages`. Los errores concretos quedan en el registro de errores de PHP de cPanel ("Errores" dentro de "Metrics" o "Registros").
- **Meta rechaza la verificación del webhook**: confirma que `WHATSAPP_VERIFY_TOKEN` en `whatsapp_credentials.php` sea idéntico, carácter por carácter, al que pegaste en el campo "Verify token" de Meta.
