-- ============================================================
-- Sistema de Gestión de Informes de Convivencia - E.E.T. N° 35
-- Base de datos MySQL 8 / MariaDB 10.4+
-- Estructura + datos de prueba (contraseñas con PBKDF2-SHA256)
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS historial_modificaciones, intentos_login, tokens, configuracion,
  archivos_adjuntos, eventos, noticias, notificaciones, historial_estados,
  clasificaciones, resoluciones, consejo_convivencia, consejo_aula, descargos,
  informes, asignaciones_docentes, materias, alumno_tutor, division_preceptor,
  divisiones, cursos, alumnos, docentes, tutores, usuarios, rol_permiso, permisos, roles, contadores;

CREATE TABLE roles (
  id VARCHAR(20) NOT NULL PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL,
  descripcion TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE permisos (
  id VARCHAR(60) NOT NULL PRIMARY KEY,
  modulo VARCHAR(40) NOT NULL,
  descripcion VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rol_permiso (
  rol_id VARCHAR(20) NOT NULL,
  permiso_id VARCHAR(60) NOT NULL,
  PRIMARY KEY (rol_id, permiso_id),
  FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE usuarios (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(220) NOT NULL,
  rol_id VARCHAR(20) NOT NULL,
  nombre VARCHAR(80) NOT NULL,
  apellido VARCHAR(80) NOT NULL,
  dni VARCHAR(20) UNIQUE,
  fecha_nacimiento DATE NULL,
  telefono VARCHAR(30) NULL,
  direccion VARCHAR(180) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  verificado TINYINT(1) NOT NULL DEFAULT 0,
  intentos INT NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME NULL,
  ultimo_acceso DATETIME NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (rol_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cursos (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  anio TINYINT NOT NULL,
  nombre VARCHAR(20) NOT NULL,
  especialidad VARCHAR(60) NULL,
  UNIQUE KEY uq_curso (anio, especialidad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE divisiones (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  curso_id INT NOT NULL,
  nombre VARCHAR(10) NOT NULL,
  UNIQUE KEY uq_division (curso_id, nombre),
  FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE alumnos (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL UNIQUE,
  legajo VARCHAR(20) NOT NULL UNIQUE,
  division_id INT NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  FOREIGN KEY (division_id) REFERENCES divisiones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tutores (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL UNIQUE,
  telefono VARCHAR(30) NULL,
  direccion VARCHAR(180) NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE alumno_tutor (
  alumno_id INT NOT NULL,
  tutor_id INT NOT NULL,
  relacion VARCHAR(40) DEFAULT 'Padre/Madre/Tutor',
  PRIMARY KEY (alumno_id, tutor_id),
  FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE CASCADE,
  FOREIGN KEY (tutor_id) REFERENCES tutores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE docentes (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL UNIQUE,
  legajo VARCHAR(20) NOT NULL UNIQUE,
  cargo VARCHAR(80) NOT NULL DEFAULT 'Docente',
  titulo VARCHAR(100) NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE division_preceptor (
  division_id INT NOT NULL,
  preceptor_id INT NOT NULL,
  PRIMARY KEY (division_id, preceptor_id),
  FOREIGN KEY (division_id) REFERENCES divisiones(id) ON DELETE CASCADE,
  FOREIGN KEY (preceptor_id) REFERENCES docentes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE materias (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE asignaciones_docentes (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  docente_id INT NOT NULL,
  materia_id INT NOT NULL,
  division_id INT NOT NULL,
  UNIQUE KEY uq_asig (docente_id, materia_id, division_id),
  FOREIGN KEY (docente_id) REFERENCES docentes(id) ON DELETE CASCADE,
  FOREIGN KEY (materia_id) REFERENCES materias(id),
  FOREIGN KEY (division_id) REFERENCES divisiones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE informes (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(20) NOT NULL UNIQUE,
  alumno_id INT NOT NULL,
  docente_id INT NOT NULL,
  division_id INT NOT NULL,
  fecha_incidente DATETIME NOT NULL,
  descripcion TEXT NOT NULL,
  normas TEXT NULL,
  solicitud_sancion TEXT NULL,
  cargo VARCHAR(100) NOT NULL,
  fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  firma_digital VARCHAR(180) NOT NULL,
  estado VARCHAR(35) NOT NULL DEFAULT 'PENDIENTE_REVISION',
  creado_por INT NOT NULL,
  actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (alumno_id) REFERENCES alumnos(id),
  FOREIGN KEY (docente_id) REFERENCES docentes(id),
  FOREIGN KEY (division_id) REFERENCES divisiones(id),
  FOREIGN KEY (creado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE descargos (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  informe_id INT NOT NULL UNIQUE,
  alumno_id INT NOT NULL,
  descripcion TEXT NOT NULL,
  observaciones TEXT NULL,
  fecha_presentacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  firmado TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (informe_id) REFERENCES informes(id) ON DELETE CASCADE,
  FOREIGN KEY (alumno_id) REFERENCES alumnos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE consejo_aula (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  informe_id INT NOT NULL UNIQUE,
  observaciones TEXT NOT NULL,
  evaluacion TEXT NULL,
  recomendaciones TEXT NULL,
  fecha_intervencion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responsable_id INT NOT NULL,
  FOREIGN KEY (informe_id) REFERENCES informes(id) ON DELETE CASCADE,
  FOREIGN KEY (responsable_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE consejo_convivencia (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  informe_id INT NOT NULL UNIQUE,
  observaciones TEXT NOT NULL,
  analisis TEXT NULL,
  recomendaciones TEXT NULL,
  intervencion TEXT NULL,
  fecha_intervencion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responsable_id INT NOT NULL,
  FOREIGN KEY (informe_id) REFERENCES informes(id) ON DELETE CASCADE,
  FOREIGN KEY (responsable_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clasificaciones (
  informe_id INT NOT NULL PRIMARY KEY,
  instancia VARCHAR(20) NOT NULL,
  otra_consideracion TEXT NULL,
  responsable_id INT NOT NULL,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (informe_id) REFERENCES informes(id) ON DELETE CASCADE,
  FOREIGN KEY (responsable_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE resoluciones (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  informe_id INT NOT NULL UNIQUE,
  decision TEXT NOT NULL,
  medida TEXT NULL,
  fundamentacion TEXT NOT NULL,
  responsable_id INT NOT NULL,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  observaciones TEXT NULL,
  FOREIGN KEY (informe_id) REFERENCES informes(id) ON DELETE CASCADE,
  FOREIGN KEY (responsable_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE historial_estados (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  informe_id INT NOT NULL,
  estado VARCHAR(35) NOT NULL,
  usuario_id INT NOT NULL,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  observacion TEXT NULL,
  FOREIGN KEY (informe_id) REFERENCES informes(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notificaciones (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  titulo VARCHAR(150) NOT NULL,
  descripcion TEXT NOT NULL,
  tipo VARCHAR(40) NOT NULL DEFAULT 'general',
  enlace VARCHAR(220) NULL,
  leida TINYINT(1) NOT NULL DEFAULT 0,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE noticias (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(180) NOT NULL,
  resumen TEXT NOT NULL,
  contenido TEXT NOT NULL,
  imagen VARCHAR(220) NULL,
  publicada TINYINT(1) NOT NULL DEFAULT 1,
  autor_id INT NOT NULL,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (autor_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE eventos (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(180) NOT NULL,
  descripcion TEXT NULL,
  fecha_inicio DATETIME NOT NULL,
  fecha_fin DATETIME NULL,
  tipo VARCHAR(40) DEFAULT 'actividad',
  visible TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE archivos_adjuntos (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  informe_id INT NULL,
  nombre_original VARCHAR(220) NOT NULL,
  nombre_guardado VARCHAR(220) NOT NULL,
  mime VARCHAR(120) NOT NULL,
  tamano INT NOT NULL,
  subido_por INT NOT NULL,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (informe_id) REFERENCES informes(id) ON DELETE CASCADE,
  FOREIGN KEY (subido_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tokens (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  tipo VARCHAR(20) NOT NULL,
  token_hash VARCHAR(64) NOT NULL UNIQUE,
  expira DATETIME NOT NULL,
  usado TINYINT(1) NOT NULL DEFAULT 0,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE intentos_login (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(150) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  exitoso TINYINT(1) NOT NULL,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE historial_modificaciones (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NULL,
  accion VARCHAR(80) NOT NULL,
  tabla_afectada VARCHAR(60) NULL,
  registro_id VARCHAR(40) NULL,
  detalle TEXT NULL,
  ip VARCHAR(45) NULL,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE configuracion (
  clave VARCHAR(50) NOT NULL PRIMARY KEY,
  valor TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE contadores (
  nombre VARCHAR(30) NOT NULL PRIMARY KEY,
  valor INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Catálogos ----------
INSERT INTO roles (id,nombre,descripcion) VALUES
 ('ADMIN','Administrador','Acceso completo al sistema'),
 ('DIRECTIVO','Directivo','Supervisión de informes y resoluciones'),
 ('PRECEPTOR','Preceptor','Gestión de cursos y seguimiento'),
 ('DOCENTE','Docente','Creación de informes de convivencia'),
 ('ALUMNO','Alumno','Consulta de informes y descargos'),
 ('TUTOR','Padre, madre o tutor','Consulta autorizada de informes');

INSERT INTO permisos (id,modulo,descripcion) VALUES
 ('usuarios.ver','usuarios','Ver cuentas'),('usuarios.crear','usuarios','Crear cuentas'),
 ('usuarios.editar','usuarios','Editar cuentas'),('usuarios.desactivar','usuarios','Desactivar cuentas'),
 ('alumnos.ver','alumnos','Ver alumnos'),('alumnos.editar','alumnos','Editar alumnos'),
 ('cursos.ver','cursos','Ver cursos'),('cursos.editar','cursos','Editar cursos'),
 ('informes.crear','informes','Crear informes'),('informes.ver','informes','Ver informes'),
 ('informes.revisar','informes','Revisar informes'),('informes.resolver','informes','Registrar resoluciones'),
 ('descargo.crear','informes','Presentar descargo'),('consejos.registrar','informes','Registrar intervenciones de consejos'),
 ('estadisticas.ver','estadisticas','Ver estadísticas'),('noticias.editar','contenido','Publicar noticias'),
 ('notificaciones.ver','notificaciones','Ver notificaciones'),('config.editar','configuración','Editar configuración');

INSERT INTO rol_permiso (rol_id,permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p
WHERE (r.id='ADMIN')
   OR (r.id='DIRECTIVO' AND p.id IN ('informes.ver','informes.revisar','informes.resolver','consejos.registrar','estadisticas.ver','notificaciones.ver','alumnos.ver','cursos.ver'))
   OR (r.id='PRECEPTOR' AND p.id IN ('informes.ver','informes.revisar','consejos.registrar','alumnos.ver','alumnos.editar','cursos.ver','cursos.editar','notificaciones.ver'))
   OR (r.id='DOCENTE' AND p.id IN ('informes.crear','informes.ver','alumnos.ver','cursos.ver','notificaciones.ver'))
   OR (r.id='ALUMNO' AND p.id IN ('informes.ver','descargo.crear','notificaciones.ver'))
   OR (r.id='TUTOR' AND p.id IN ('informes.ver','notificaciones.ver'));

INSERT INTO cursos (id,anio,nombre,especialidad) VALUES
 (1,1,'1ro',NULL),(2,2,'2do',NULL),(3,3,'3ro',NULL),
 (4,4,'4to','Computación'),(5,4,'4to','Automotores'),
 (6,5,'5to','Computación'),(7,5,'5to','Automotores'),
 (8,6,'6to','Computación'),(9,6,'6to','Automotores');

INSERT INTO divisiones (id,curso_id,nombre) VALUES
 (1,1,'1ra'),(2,1,'2da'),(3,1,'3ra'),(4,1,'4ta'),
 (5,2,'1ra'),(6,2,'2da'),(7,2,'3ra'),(8,2,'4ta'),
 (9,3,'1ra'),(10,3,'2da'),(11,3,'3ra'),(12,3,'4ta'),
 (13,4,'1ra'),(14,4,'2da'),(15,5,'1ra'),(16,5,'2da'),
 (17,6,'1ra'),(18,6,'2da'),(19,7,'1ra'),(20,7,'2da'),
 (21,8,'1ra'),(22,8,'2da'),(23,9,'1ra'),(24,9,'2da');

INSERT INTO materias (id,nombre) VALUES
 (1,'Matemática'),(2,'Lengua y Literatura'),(3,'Inglés'),(4,'Física'),(5,'Química'),
 (6,'Historia'),(7,'Geografía'),(8,'Educación Física'),(9,'Taller'),(10,'Programación'),
 (11,'Automotores'),(12,'Dibujo Técnico'),(13,'Formación Ética y Ciudadana');

-- ---------- Usuarios de prueba ----------
-- Contraseñas: Admin123! / Directivo123! / Preceptor123! / Docente123! / Alumno123! / Tutor123!
INSERT INTO usuarios (id,username,email,password_hash,rol_id,nombre,apellido,dni,fecha_nacimiento,telefono,verificado,ultimo_acceso) VALUES
 (1,'admin','admin@et35.edu.ar','pbkdf2_sha256$120000$a1b2c3d4e5f60718293a4b5c6d7e8f90$3c5cd790b072ac7b81ca19a69063a559769ba26150ba1662e924854b819daaf55d7aa615ba9865d710263fec5ed81998b62028e27d4d020bef2eb4cd35413c66','ADMIN','Ana','Administradora','20000001','1980-01-01',NULL,1,NULL),
 (2,'directivo','directivo@et35.edu.ar','pbkdf2_sha256$120000$b2c3d4e5f60718293a4b5c6d7e8f901$de21a4763a017ee1b87e6939227c5625b0e0162b657f6ffe6c697bba42a0a0c101b85d183539c1a77085998fe8d52c1ec4bf0abd85dee2cc348f4c93db027836','DIRECTIVO','Laura','Regente','20000002','1975-02-02',NULL,1,NULL),
 (3,'preceptor','preceptor@et35.edu.ar','pbkdf2_sha256$120000$c3d4e5f60718293a4b5c6d7e8f9012$9cd39023c7fe50054f0ed64ffdf89511778d12a048694acc73492ec0429408c3da0d85a36360b5e30ab7f12ee920d4158de2cb3c20e6db699c650305fbcdb7df','PRECEPTOR','María','Delgado','20000003','1982-03-03',NULL,1,NULL),
 (4,'docente','docente@et35.edu.ar','pbkdf2_sha256$120000$d4e5f60718293a4b5c6d7e8f90123$66d8d88e7bd0f65a8274c22dc97070415f89f57ca4a5c6ec798dfdac26c1dc650ab1c679fca047045e97c6831b2022855eca5dc11620c4bc1259cc73c53a9509','DOCENTE','Carlos','Ramírez','20000004','1979-04-04',NULL,1,NULL),
 (5,'alumno','alumno.demo@gmail.com','pbkdf2_sha256$120000$e5f60718293a4b5c6d7e8f901234$9f3f1f4ed182d97feff4f2d2ca27738d23c6f885914b7da84bc5bde882c7b74b8b8ae876524c5bee9b8869a0a858ac066ab77498e8f0ea3cd6b02e4dd543d354','ALUMNO','Joaquín','González','45000001','2009-05-05',NULL,1,NULL),
 (6,'tutor','tutor.demo@gmail.com','pbkdf2_sha256$120000$f60718293a4b5c6d7e8f9012345$2976fc68015fdd07ef5ce1ad8d8f4236ed0e366b3b586d526f55fcb5302ce04efbc898744049dfadf4dfdce78759a5a29f3d0b9e60768d75f6db43f081b38099','TUTOR','Rosa','González','20000006','1970-06-06','11-4444-5555',1,NULL);

INSERT INTO alumnos (id,usuario_id,legajo,division_id) VALUES (1,5,'LEG-2026-001',9);
INSERT INTO tutores (id,usuario_id,telefono) VALUES (1,6,'11-4444-5555');
INSERT INTO alumno_tutor (alumno_id,tutor_id,relacion) VALUES (1,1,'Madre');
INSERT INTO docentes (id,usuario_id,legajo,cargo,titulo) VALUES
 (1,4,'DOC-001','Docente','Prof. en Matemática'),
 (2,3,'PRE-001','Preceptor','Preceptor');
INSERT INTO division_preceptor (division_id,preceptor_id) VALUES (9,2);
INSERT INTO asignaciones_docentes (docente_id,materia_id,division_id) VALUES (1,1,9),(1,4,9);

INSERT INTO contadores (nombre,valor) VALUES ('informe',0);

-- ---------- Contenido público ----------
INSERT INTO noticias (id,titulo,resumen,contenido,autor_id,fecha) VALUES
 (1,'Inicio del ciclo lectivo 2026','Les damos la bienvenida a las familias a un nuevo año escolar.','Les damos la bienvenida a la Escuela Técnica N° 35 D.E. 18 Ing. Eduardo Latzina al ciclo lectivo 2026. El primer día de clases será el lunes 2 de marzo.',2,'2026-02-20 09:00:00'),
 (2,'Jornada de orientación vocacional','Actividad para los estudiantes de 6.º año.','El miércoles 15 de abril realizaremos la jornada de orientación vocacional con la participación de instituciones terciarias y universidades.',2,'2026-04-10 10:30:00'),
 (3,'Reunión de familias','Convocatoria a madres, padres y tutores.','Se invita a las familias a la reunión general institucional a realizarse en el salón de actos.',2,'2026-05-02 11:00:00');

INSERT INTO eventos (id,titulo,descripcion,fecha_inicio,fecha_fin,tipo) VALUES
 (1,'Inicio de clases','Primer día del ciclo lectivo 2026','2026-03-02 08:00:00',NULL,'academico'),
 (2,'Exámenes finales','Período de exámenes finales','2026-07-06 08:00:00','2026-07-24 12:00:00','examen'),
 (3,'Acto del 25 de Mayo','Acto escolar conmemorativo','2026-05-25 09:00:00',NULL,'acto'),
 (4,'Reunión de familias','Encuentro institucional','2026-05-15 17:00:00',NULL,'reunion');

INSERT INTO configuracion (clave,valor) VALUES
 ('app_nombre','Sistema de Informes de Convivencia'),
 ('escuela_nombre','E.E.T. N° 35 D.E. 18 "Ing. Eduardo Latzina"'),
 ('escuela_direccion','Av. Lope de Vega 2150, CABA'),
 ('escuela_telefono','4567-5838 / 1441'),
 ('escuela_email','det_35_de18@bue.edu.ar'),
 ('smtp_host','smtp.gmail.com'),('smtp_puerto','587'),('smtp_usuario',''),('smtp_password',''),
 ('smtp_desde','det_35_de18@bue.edu.ar'),('smtp_seguro','tls'),
 ('mail_mode','log'),
 ('codigo_institucional','escuela2026');

SET FOREIGN_KEY_CHECKS = 1;
