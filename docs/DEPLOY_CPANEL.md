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

## 10. Respaldo periódico

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
