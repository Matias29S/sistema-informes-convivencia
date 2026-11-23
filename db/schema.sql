-- Esquema de base de datos para el Sistema de Informes de Conducta Escolar
-- Escuela Técnica N° 35 D.E. 18 "Ing. Eduardo Latzina"
-- Motor objetivo: PostgreSQL 13+ (compatible con MySQL 8+ con ajustes menores).
-- Nota: la versión web publicada usa localStorage (clave sgic2) como almacenamiento;
-- este esquema define la estructura para persistencia en servidor/base de datos real.

CREATE TABLE usuarios (
  id            VARCHAR(32) PRIMARY KEY,
  nombre        VARCHAR(120) NOT NULL,
  email         VARCHAR(120) NOT NULL UNIQUE,
  rol           VARCHAR(20)  NOT NULL CHECK (rol IN ('ALUMNO','PROFESOR','PRECEPTOR','REGENTE','DIRECTOR')),
  password_hash VARCHAR(255) NOT NULL,
  sal           VARCHAR(32)  NOT NULL,
  curso         VARCHAR(40),          -- solo ALUMNO
  dni           VARCHAR(20),          -- solo ALUMNO (opcional)
  creado_en     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE profesor_asignaciones (
  id         BIGSERIAL PRIMARY KEY,
  usuario_id VARCHAR(32) NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  materia    VARCHAR(80) NOT NULL,
  curso      VARCHAR(40) NOT NULL
);

CREATE TABLE informes (
  id           VARCHAR(20) PRIMARY KEY, -- ej. INF-0001
  alumno_id    VARCHAR(32) NOT NULL REFERENCES usuarios(id) ON DELETE RESTRICT,
  alumno_nombre VARCHAR(120) NOT NULL,
  alumno_curso  VARCHAR(40)  NOT NULL,
  autor_id     VARCHAR(32) NOT NULL REFERENCES usuarios(id) ON DELETE RESTRICT,
  autor_nombre VARCHAR(120) NOT NULL,
  autor_rol    VARCHAR(20)  NOT NULL,
  categoria    VARCHAR(30)  NOT NULL CHECK (categoria IN ('Disciplina','Vestimenta','Rendimiento','Salud','Sociofamiliar')),
  hecho        TEXT NOT NULL,
  estado       VARCHAR(20) NOT NULL DEFAULT 'ACTIVO' CHECK (estado IN ('ACTIVO','EN_SUSPENSO','RESUELTO')),
  creado_en    TIMESTAMPTZ NOT NULL DEFAULT now(),
  leido_en     TIMESTAMPTZ
);

CREATE TABLE intervenciones (
  id         BIGSERIAL PRIMARY KEY,
  informe_id VARCHAR(20) NOT NULL REFERENCES informes(id) ON DELETE CASCADE,
  rol        VARCHAR(20) NOT NULL CHECK (rol IN ('AUTOR','REGENTE')),
  texto      TEXT NOT NULL DEFAULT '',
  actualizado_en TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (informe_id, rol)
);

-- Cuentas preestablecidas (contraseña por defecto: Escuela35)
-- La contraseña debe almacenarse hasheada (PBKDF2-SHA256) al insertar.
-- Ejemplos de cuentas iniciales:
-- INSERT INTO usuarios (id,nombre,email,rol,password_hash,sal,creado_en) VALUES
-- ('dir001','Director ET35','director@et35.edu.ar','DIRECTOR','<hash>','<sal>',now()),
-- ('reg001','Regencia ET35','regencia@et35.edu.ar','REGENTE','<hash>','<sal>',now()),
-- ('pre001','Preceptor ET35','preceptor@et35.edu.ar','PRECEPTOR','<hash>','<sal>',now());

CREATE INDEX ix_usuarios_rol ON usuarios(rol);
CREATE INDEX ix_usuarios_curso ON usuarios(curso);
CREATE INDEX ix_informes_alumno ON informes(alumno_id, creado_en DESC);
CREATE INDEX ix_informes_estado ON informes(estado);
