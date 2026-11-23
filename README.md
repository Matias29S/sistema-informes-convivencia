# Sistema de Informes de Convivencia — E.E.T. N° 35 D.E. 18 “Ing. Eduardo Latzina”

Repositorio con **la versión definitiva PHP + MySQL** del sistema y, además, la **demo estática** que se usó como maqueta visual.

| Carpeta | Contenido | Estado |
|---|---|---|
| [`colegio/`](colegio/README.md) | Aplicación real PHP/MySQL: roles, informes, descargos, consejos, clasificación, resolución, PDF, notificaciones, SMTP, auditoría y respaldos. | **Versión final** |
| [`frontend/`](frontend/) | Demo estática (HTML/CSS/JS) publicada en Netlify. Es sólo visual: guarda datos en `localStorage`, **no reemplaza al sistema real**. | Demo / maqueta |
| `backend/`, `db/`, `docs/` | Prototipos descartados (Spring Boot / PostgreSQL y documento original). Se conservan como referencia, pero **no se usan**. | Legacy |

## Sistema online (real)

<https://informeset35.kesug.com>

## Demo online (solo visual)

<https://funny-belekoy-45b467.netlify.app>

> Netlify **no ejecuta PHP ni MySQL**. Para el sistema completo hay que usar XAMPP u otro hosting con PHP + MySQL.

## Instalación rápida del sistema final

1. Copiá `colegio/` a `C:\xampp\htdocs\colegio`.
2. Iniciá **Apache** y **MySQL** en XAMPP.
3. Creá la base `colegio` e importá `colegio/database/colegio.sql` (¡contiene `DROP TABLE`!).
4. Abrí <http://localhost/colegio/index.php> y <http://localhost/colegio/login.php>.

La guía completa está en [`colegio/README.md`](colegio/README.md).

### Cuentas de prueba

| Rol | Correo | Contraseña |
|---|---|---|
| ADMIN | `admin@et35.edu.ar` | `Admin123!` |
| DIRECTIVO | `directivo@et35.edu.ar` | `Directivo123!` |
| PRECEPTOR | `preceptor@et35.edu.ar` | `Preceptor123!` |
| DOCENTE | `docente@et35.edu.ar` | `Docente123!` |
| ALUMNO | `alumno.demo@gmail.com` | `Alumno123!` |
| TUTOR | `tutor.demo@gmail.com` | `Tutor123!` |

## Flujo implementado

`PENDIENTE_REVISION` → `EN_REVISION` → `PENDIENTE_DESCARGO` → `PENDIENTE_CONSEJO_AULA` → `PENDIENTE_CONSEJO_CONVIVENCIA` → `PENDIENTE_RESOLUCION` → `RESUELTO` (+ `ANULADO`).

Incluye clasificación LEVE / GRAVE / MUY GRAVE / OTRA, notificaciones internas y por correo, historial de estados, exportación PDF y adjuntos validados.

## Seguridad

- Consultas preparadas (PDO), CSRF en todos los formularios, salida escapada con `e()`.
- Contraseñas PBKDF2-SHA256 con sal; alta con usuario y contraseña temporal aleatoria enviada por correo, cambiable desde Mi perfil.
- Permisos verificados en servidor y filtrado de datos por rol (un tutor o alumno no puede ver informes ajenos).
- Control de intentos de login, auditoría, cambios de estado trazables y respaldos.

## Correo

Por defecto `mail_mode = log`: los correos con usuario y contraseña temporal se escriben en `colegio/storage/mail.log` para probar sin credenciales reales. Para producción, cargar SMTP desde **Admin → Configuración**. Ver [`colegio/README.md`](colegio/README.md#5-altas-credenciales-y-correo).

## Publicación online (InfinityFree)

La aplicación real se publica en un hosting con **PHP + MySQL**, no en Netlify. Paso a paso:

1. Creá cuenta gratis en <https://www.infinityfree.com/> y un **subdominio** (ej. `informes-et35.infinityfreeapp.com`).
2. En el panel, creá una base MySQL y un usuario con contraseña; anotá el nombre de la base, el usuario, la contraseña y el host MySQL que indique InfinityFree.
3. Importá `colegio/database/colegio.sql` desde phpMyAdmin (el script contiene `DROP TABLE`: usá una base vacía).
4. Subí por FTP **el contenido de `colegio/` a la raíz pública** (`htdocs/`). No subas la carpeta `colegio` como subcarpeta si `BASE_URL` es `/`.
5. En `config/app.php` dejá `BASE_URL = '/'`, `APP_URL = 'https://TU-SUBDOMINIO.infinityfreeapp.com/'`.
6. En `config/database.php` cargá host, usuario, contraseña y base del paso 2.
7. Hacé escribibles `storage/` y `storage/uploads/` y probá el alta de un alumno.
8. Para correo real, cargá SMTP en **Admin → Configuración**. Si el hosting gratis bloquea SMTP saliente, el sistema igual registra todo en `storage/mail.log`.

La demo de Netlify queda como maqueta visual; la URL del sistema real será el subdominio de InfinityFree.
