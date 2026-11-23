# Sistema de Informes de Convivencia — E.E.T. N° 35 D.E. 18 “Ing. Eduardo Latzina”

Repositorio con **la versión definitiva PHP + MySQL** del sistema y, además, la **demo estática** que se usó como maqueta visual.

| Carpeta | Contenido | Estado |
|---|---|---|
| [`colegio/`](colegio/README.md) | Aplicación real PHP/MySQL: roles, informes, descargos, consejos, clasificación, resolución, PDF, notificaciones, SMTP, auditoría y respaldos. | **Versión final** |
| [`frontend/`](frontend/) | Demo estática (HTML/CSS/JS) publicada en Netlify. Es sólo visual: guarda datos en `localStorage`, **no reemplaza al sistema real**. | Demo / maqueta |
| `backend/`, `db/`, `docs/` | Prototipos descartados (Spring Boot / PostgreSQL y documento original). Se conservan como referencia, pero **no se usan**. | Legacy |

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
- Contraseñas PBKDF2-SHA256 con sal; activación por token enviado por correo.
- Permisos verificados en servidor y filtrado de datos por rol (un tutor o alumno no puede ver informes ajenos).
- Control de intentos de login, auditoría, cambios de estado trazables y respaldos.

## Correo

Por defecto `mail_mode = log`: los correos se escriben en `colegio/storage/mail.log` para poder probar activación y recuperación sin credenciales reales. Para producción, cargar SMTP desde **Admin → Configuración**. Ver [`colegio/README.md`](colegio/README.md#5-activación-de-cuentas-y-correo).
