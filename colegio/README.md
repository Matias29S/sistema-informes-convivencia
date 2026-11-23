# Sistema de Gestión de Informes de Convivencia — E.E.T. N° 35

Aplicación web PHP + MySQL para registrar informes de convivencia escolar, gestionar descargos e intervenciones, resolverlos y publicar estadísticas institucionales. Incluye sitio institucional público, panel por rol, notificaciones internas, exportación a PDF/CSV, auditoría y respaldos.

---

## 1. Requisitos

- **XAMPP para Windows** con:
  - **Apache** (servidor web).
  - **PHP 8.1 o superior** (las versiones incluidas en XAMPP 8.1/8.2 son adecuadas; se usan `str_contains`, `str_starts_with`, `match`/arrow functions, tipado de retorno).
  - Extensiones PHP habilitadas: `pdo_mysql`, `mbstring`, `openssl` (para STARTTLS de SMTP), `finfo` (para validar archivos subidos).
  - **MySQL / MariaDB** y **phpMyAdmin**.
- Navegador moderno (Chrome, Edge, Firefox).
- Opcional para producción: certificado SSL/HTTPS y una cuenta SMTP real.

> No se usa Composer ni dependencias externas: todo el código PHP es autocontenido.

---

## 2. Instalación en XAMPP (local)

### Paso 1 — Copiar el proyecto

Copiá **todo** el contenido de esta carpeta (`sistema-informes/colegio`) a:

```
C:\xampp\htdocs\colegio
```

El resultado debe ser, por ejemplo:

```
C:\xampp\htdocs\colegio\index.php
C:\xampp\htdocs\colegio\config\app.php
C:\xampp\htdocs\colegio\database\colegio.sql
```

Es importante que la carpeta se llame **`colegio`**: el sistema define `BASE_URL = '/colegio/'` y `APP_URL = 'http://localhost/colegio/'` en `config/app.php`. Si cambiás el nombre de la carpeta, actualizá esas dos constantes.

### Paso 2 — Iniciar los servicios

Abrí el **XAMPP Control Panel** (`C:\xampp\xampp-control.exe`) y presioná **Start** en:

- **Apache**
- **MySQL** (o MariaDB)

Verificá que ambos figuren con el estado *Running*.

### Paso 3 — Crear la base de datos

1. Entrá a **phpMyAdmin**: <http://localhost/phpmyadmin>
2. En el menú izquierdo, hacé clic en **New** / **Nueva**.
3. Nombre de la base de datos: `colegio` (collation: `utf8mb4_unicode_ci` o `utf8mb4_general_ci`).
4. Presioná **Create**.
5. Con la base `colegio` seleccionada, abrí la pestaña **Import** / **Importar**.
6. Elegí el archivo `C:\xampp\htdocs\colegio\database\colegio.sql` y presioná **Go** / **Continuar**.

> ⚠️ **El script `database/colegio.sql` contiene `DROP TABLE IF EXISTS ...`**: si la base ya tenía datos, se eliminan todas las tablas. Importalo sólo en una base nueva o cuando quieras resetear el sistema a los datos de prueba.

También podés importarlo por línea de comandos:

```bat
"C:\xampp\mysql\bin\mysql.exe" -u root colegio < "C:\xampp\htdocs\colegio\database\colegio.sql"
```

### Paso 4 — Verificar la conexión

Por defecto, `config/database.php` usa:

```php
$dsn = 'mysql:host=localhost;dbname=colegio;charset=utf8mb4';
$dbUser = 'root';
$dbPass = '';
```

Eso coincide con la instalación estándar de XAMPP (usuario `root` sin contraseña). Si configuraste otra contraseña o creaste otro usuario, editá ese archivo.

### Paso 5 — Abrir el sistema

- Sitio público: <http://localhost/colegio/index.php>
- Ingreso al sistema: <http://localhost/colegio/login.php>

Si ves el panel con el menú lateral, la instalación terminó correctamente.

---

## 3. Usuarios de prueba

Todas las cuentas de ejemplo ya están verificadas (`verificado = 1`) y activas.

| Rol | Correo | Contraseña |
|---|---|---|
| Administrador | `admin@et35.edu.ar` | `Admin123!` |
| Directivo | `directivo@et35.edu.ar` | `Directivo123!` |
| Preceptor | `preceptor@et35.edu.ar` | `Preceptor123!` |
| Docente | `docente@et35.edu.ar` | `Docente123!` |
| Alumno (demo) | `alumno.demo@gmail.com` | `Alumno123!` |
| Tutor (demo) | `tutor.demo@gmail.com` | `Tutor123!` |

Podés iniciar sesión con el correo o con el `username` (`admin`, `directivo`, `preceptor`, `docente`, `alumno`, `tutor`).

> **Seguridad:** son credenciales públicas de demostración. Cambialas antes de usar el sistema fuera de tu máquina.

---

## 4. Estructura de carpetas

```
colegio/
├── config/
│   ├── app.php          Arranque: sesión, BASE_URL, APP_URL, zona horaria, includes.
│   ├── database.php     Conexión PDO a MySQL (usuario/contraseña).
│   ├── mail.php         Envío de correos: modo log o SMTP.
│   └── pdf.php          Generador de PDF liviano propio (A4, Helvetica, sin Composer).
├── includes/
│   ├── functions.php    Helper globales: e(), url(), csrf, flash(), notify(), audit(), estados...
│   ├── auth.php         Login, current_user(), require_login(), require_role(), permisos.
│   └── layout.php       Cabecera, menú, mensajes y pie de todas las páginas privadas.
├── admin/               Gestión: usuarios, permisos, configuración, noticias, eventos,
│                        auditoría (auditoria.php) y respaldos (respaldos.php).
├── informes/            Creación (crear.php), revisión (revision.php), listado (listado.php),
│                        detalle (detalle.php), descargo (descargo.php), consejos,
│                        clasificación, resolución, mis informes (mis_informes.php),
│                        seguimiento (seguimiento.php), adjuntos (adjuntos.php /
│                        descargar_adjunto.php), historial y generar_pdf.php.
├── notificaciones/      listado.php (bandeja) y marcar_leida.php (marcado AJAX/POST).
├── tutores/             alumnos.php:portal del tutor (alumnos asociados, informes, notificaciones).
├── database/            colegio.sql: esquema + datos de prueba (¡DROP TABLE!).
├── assets/
│   ├── css/styles.css   Estilos del sitio público y del panel.
│   ├── js/app.js        Menú responsive, confirmaciones y marcado de notificaciones.
│   └── img/             Escudo e imágenes.
├── storage/             Archivos generados en runtime: uploads/, mail.log, respaldos.
│                        Debe tener permisos de escritura para el usuario de Apache.
├── index.php            Sitio institucional público.
├── dashboard.php        Panel de control según el rol.
├── login.php, logout.php, activar.php, recuperar.php, cambiar_clave.php, perfil.php
├── alumnos.php, cursos.php, docentes.php, estadisticas.php
└── README.md            Esta guía.
```

---

## 5. Activación de cuentas y correo

Cuando el administrador crea una cuenta en `admin/usuarios.php`:

1. Se genera un **nombre de usuario** único y la cuenta queda con `verificado = 0`.
2. Se crea un **token temporal de activación** (vigencia de 48 hs) con `crear_token()`.
3. Se envía un correo al usuario con el **usuario** y un enlace del tipo:

   ```
   http://localhost/colegio/activar.php?t=TOKEN_GENERADO
   ```

4. El usuario abre el enlace, ingresa su nueva contraseña dos veces y queda activado. El mismo mecanismo (`recuperar.php`) se usa para restablecer contraseñas olvidadas.

### Modo desarrollo (por defecto)

`configuracion.mail_mode = log` hace que **no se envíe correo real**. Los mensajes se escriben en:

```
C:\xampp\htdocs\colegio\storage\mail.log
```

Para probar la activación: abrí el archivo, copiá el enlace `activar.php?t=...` y pegalo en el navegador. La carpeta `storage/` se crea automáticamente si no existe.

### Modo producción (SMTP real)

En **Admin → Configuración** (`admin/configuracion.php`):

| Clave | Valor de ejemplo |
|---|---|
| `mail_mode` | `smtp` |
| `smtp_host` | `smtp.gmail.com` |
| `smtp_puerto` | `587` |
| `smtp_usuario` | `tu-cuenta@gmail.com` |
| `smtp_password` | **Contraseña de aplicación** de Google (no la contraseña normal) |
| `smtp_desde` | `tu-cuenta@gmail.com` |
| `smtp_seguro` | `tls` (o `ssl` con puerto `465`) |

Gmail requiere activar la verificación en dos pasos y generar una **contraseña de aplicación** en <https://myaccount.google.com/apppasswords>. El cliente SMTP usa `STARTTLS` + `AUTH LOGIN` y requiere la extensión `openssl` habilitada.

---

## 6. Roles y permisos

Los permisos se verifican **siempre en el servidor** (`require_permission()` / `has_permission()`) y se administran en `admin/permisos.php`.

| Rol | Alcance principal |
|---|---|
| **ADMIN** | Acceso completo: cuentas, permisos, configuración, noticias, auditoría, respaldos, estadísticas e informes. |
| **DIRECTIVO** | Supervisa informes, registra resoluciones e intervenciones, consulta estadísticas (`estadisticas.ver`). |
| **PRECEPTOR** | Revisa informes de sus divisiones, seguimiento, cursos y alumnos. No tiene `estadisticas.ver` por defecto: podés otorgárselo desde `admin/permisos.php` si querés acceso de sólo lectura. |
| **DOCENTE** | Crea informes, consulta los suyos y ve sus cursos/alumnos. |
| **ALUMNO** | Consulta sus informes y presenta descargos. |
| **TUTOR** | Consulta únicamente los informes de los alumnos asociados (tabla `alumno_tutor`) y sus notificaciones. |

Cada pantalla aplica además un **filtro de datos** por usuario (por ejemplo, el tutor no puede ver informes de otros alumnos aunque conozca un código).

---

## 7. Flujo de estados de un informe

Los posibles valores de `informes.estado` son 8, definidos en `ESTADOS_INFORME` (`includes/functions.php`):

| # | Estado | Significado / responsable |
|---|---|---|
| 1 | `PENDIENTE_REVISION` | El docente creó el informe; espera revisión del preceptor. |
| 2 | `EN_REVISION` | El preceptor está revisando el informe. |
| 3 | `PENDIENTE_DESCARGO` | Se notifica al alumno para que presente su descargo. |
| 4 | `PENDIENTE_CONSEJO_AULA` | Intervención del Consejo de Aula (se registra en `consejo_aula`). |
| 5 | `PENDIENTE_CONSEJO_CONVIVENCIA` | Intervención del Consejo de Convivencia (`consejo_convivencia`). |
| 6 | `PENDIENTE_RESOLUCION` | El directivo debe dictar la resolución. |
| 7 | `RESUELTO` | Resolución registrada en `resoluciones`; cierra el circuito. |
| 8 | `ANULADO` | Informe anulado por administración/dirección (sólo ADMIN/DIRECTIVO). |

Cada cambio usa `cambiar_estado()` y queda registrado en `historial_estados` con usuario, fecha y observación. Las instancias, descargos y resoluciones se clasifican en `clasificaciones`.

El pasaje de `PENDIENTE_REVISION` → `EN_REVISION` → `PENDIENTE_DESCARGO` se realiza desde **`informes/revision.php`** (PRECEPTOR, DIRECTIVO o ADMIN). Los archivos de prueba se adjuntan desde **`informes/adjuntos.php`**: se validan por MIME real con `finfo`, tamaño máximo de 5 MB y formatos PDF/JPG/PNG/WEBP; la descarga se resuelve siempre desde la base de datos (`descargar_adjunto.php`), nunca desde un nombre de archivo arbitrario.

---

## 8. Exportaciones, respaldos y auditoría

### PDF

En el detalle de un informe (`informes/detalle.php?id=INF-0001`) presioná **Descargar PDF**: se genera el acta completa desde `informes/generar_pdf.php?id=INF-0001`.

### CSV de estadísticas

En `estadisticas.php` podés filtrar por mes, año, curso, división, categoría, estado y docente. El botón **Exportar CSV** (`?export=csv`) genera un archivo con **BOM UTF-8** (compatible con Excel) y campos sanitizados contra inyección de fórmulas. Requiere el permiso `estadisticas.ver`.

### Respaldos

- Desde **Admin → Respaldos** (`admin/respaldos.php`): botón **Descargar respaldo SQL** (vuelca estructura + datos).
- Por línea de comandos:

  ```bat
  "C:\xampp\mysql\bin\mysqldump.exe" -u root -p colegio > C:\xampp\htdocs\colegio\storage\backup.sql
  ```

- Restauración: phpMyAdmin → base `colegio` → pestaña **Import** → elegir el `.sql`.

### Auditoría

- `admin/auditoria.php` consulta `historial_modificaciones` (acciones administrativas y operaciones sensibles, con IP y fecha).
- `historial_estados` guarda la trazabilidad completa de cada informe.

---

## 9. Seguridad

- Cambiá **todas** las contraseñas de prueba antes de poner el sistema en producción.
- No use el usuario `root` de MySQL en producción. Creá un usuario con privilegios limitados a la base `colegio` y cargalo en `config/database.php`:

  ```sql
  CREATE USER 'colegio_user'@'localhost' IDENTIFIED BY 'clave-segura';
  GRANT SELECT, INSERT, UPDATE, DELETE ON colegio.* TO 'colegio_user'@'localhost';
  FLUSH PRIVILEGES;
  ```

- Configurá `APP_URL` en `config/app.php` con la URL real (ej. `https://informes.miescuela.edu.ar/colegio/`) cuando despliegues; se usa en los enlaces de activación.
- Usá **HTTPS** en producción (certificado Let's Encrypt o del proveedor) y redirect de HTTP a HTTPS.
- Mantení `storage/` y `storage/uploads/` con permisos de escritura **sólo** para el usuario de Apache, y evitá servirlos públicamente. Las subidas validan MIME real con `finfo`, tamaño máximo de 5 MB y extensiones PDF/JPEG/PNG/WebP.
- El sistema usa tokens **CSRF** en todos los POST (`csrf_field()` / `csrf_check()`) y contraseñas con **PBKDF2-SHA256** (120.000 iteraciones + salt).
- Hay control de intentos de login por IP y bloqueo temporal de cuentas.
- En `php.ini` de producción: `display_errors = Off`, `log_errors = On`.

---

## 10. Despliegue (importante)

Este proyecto es **PHP + MySQL** y necesita un servidor con esos componentes. **No puede ejecutarse en Netlify** (Netlify sirve sitios estáticos / functions de JavaScript, no PHP ni MySQL).

- Para producción usá un hosting PHP con MySQL (por ejemplo un VPS con Apache, un plan de hosting compartido con PHP 8.1+ o XAMPP en una máquina local de la escuela).
- El sitio anterior de Netlify debe conservarse **sólo como demo visual**; no intentes subir esta carpeta completa allí.
- Si el proyecto se publica en la raíz del dominio, cambiá `BASE_URL` a `'/'` y `APP_URL` a la URL base correspondiente en `config/app.php`, y actualizá los enlaces del menú.

---

## 11. Solución de problemas

| Síntoma | Causa probable y solución |
|---|---|
| **"Error de base de datos"** al abrir cualquier página | MySQL no está iniciado (XAMPP Control Panel → Start en MySQL), la base `colegio` no existe o los credenciales en `config/database.php` son incorrectos. Verificá también que el puerto 3306 no esté ocupado por otro servicio. |
| **Página en blanco** | Error fatal de PHP oculto. Revisá `C:\xampp\php\logs\php_error_log` (o `error_log` del hosting). Para diagnosticar, activá temporalmente `display_errors = On` en `php.ini` y reiniciá Apache. También verificá que la carpeta `storage/` sea escribible. |
| **CSS / imágenes con 404** | La carpeta debe estar en `C:\xampp\htdocs\colegio` y `BASE_URL` debe ser `/colegio/`. Comprobá que existan `assets/css/styles.css` y `assets/img/escudo.png`. Limpiá la caché del navegador (Ctrl + F5). |
| **No llega el correo de activación** | Con `mail_mode = log`, mirá `storage/mail.log`; no se envía correo real. Si configuraste `smtp`, revisá `smtp_usuario`, `smtp_password` (contraseña de aplicación), `smtp_puerto` y que el puerto 587/465 no esté bloqueado por el firewall. |
| **"Token de seguridad inválido"** | La sesión expiró o el formulario se abrió en otra pestaña. Recargá la página y volvé a enviar. |
| **No puedo iniciar sesión con un usuario nuevo** | La cuenta debe estar activada (`verificado = 1`). Buscá el enlace de activación en `storage/mail.log` o generá uno nuevo desde `admin/usuarios.php`. |
| **El enlace de activación dice que expiró** | Los tokens duran 48 horas. Pedí uno nuevo desde la pantalla de recuperación o desde administración. |
| **Error al subir archivos** | Verificá permisos de `storage/uploads/`, que el MIME esté entre PDF/JPEG/PNG/WebP y que el archivo no supere 5 MB. |

---

## 12. Puesta en marcha rápida (resumen)

```bat
1. Copiar proyecto  ->  C:\xampp\htdocs\colegio
2. XAMPP Control Panel  ->  Start Apache + MySQL
3. http://localhost/phpmyadmin  ->  crear BD "colegio"  ->  Importar database/colegio.sql
4. http://localhost/colegio/index.php       (sitio público)
5. http://localhost/colegio/login.php       (admin@et35.edu.ar / Admin123!)
```

© E.E.T. N° 35 D.E. 18 "Ing. Eduardo Latzina" — Sistema de Informes de Convivencia.
