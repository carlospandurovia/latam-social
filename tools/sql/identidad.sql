-- LATAM Social - Fase 5/4, iteraciones 5.9 y 4.1 - El enlace seguro de contrasena.
SET NAMES utf8mb4;

-- La tabla de sesiones del esqueleto de Laravel, copiada aqui tal cual.
--
-- No es adorno: desde `4.1`, poner una contrasena nueva BORRA las filas de esta
-- tabla del usuario. Con `SESSION_DRIVER=file` eso seria imposible --no hay
-- forma de saber que archivo es de quien-- y una contrasena nueva convivira con
-- la sesion abierta de quien entro con la vieja, que es no haber hecho nada.
--
-- O sea que `SESSION_DRIVER=database` es un requisito de seguridad y no una
-- preferencia, y por eso la tabla aparece en el esquema de referencia: para que
-- se vea que alguien depende de ella. El runbook lo fija en `docs/18`.
CREATE TABLE sessions (
  id            VARCHAR(255)    NOT NULL PRIMARY KEY,
  user_id       BIGINT UNSIGNED NULL,
  ip_address    VARCHAR(45)     NULL,
  user_agent    TEXT            NULL,
  payload       LONGTEXT        NOT NULL,
  last_activity INT             NOT NULL,
  KEY sessions_user_id_index (user_id),
  KEY sessions_last_activity_index (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sustituye a `password_reset_tokens` del esqueleto de Laravel, que tiene tres
-- columnas --correo, token, fecha-- y no sirve: no distingue el proposito, no
-- puede caducar por enlace, no marca el uso y guarda el token en claro.
--
-- Lo que hay aqui es la HUELLA del token, nunca el token. Un volcado de esta
-- base no abre ninguna cuenta.
CREATE TABLE password_links (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                 CHAR(36)        NOT NULL,
  user_id              BIGINT UNSIGNED NOT NULL,
  -- `initial` (alta, 72 h) o `reset` (recuperacion, 1 h). No es cosmetico: cada
  -- uno tiene su reloj, y mezclarlos haria que la ventana larga del alta
  -- valiera tambien para recuperar.
  purpose              VARCHAR(20)     NOT NULL,
  token_sha256         CHAR(64)        NOT NULL,
  -- La caducidad es del ENLACE, no una constante global: se guarda con el.
  expires_at           DATETIME(3)     NOT NULL,
  -- Usado de verdad, por su dueno.
  used_at              DATETIME(3)     NULL,
  -- Sustituido por otro. Es OTRA columna a proposito: `used_at` tiene que
  -- responder «.lo llego a usar?» sin ambiguedad, y un enlace que sustituyes no
  -- tiene IP que apuntar. Caducar no se escribe: se deduce de `expires_at`.
  revoked_at           DATETIME(3)     NULL,
  revoked_reason       VARCHAR(40)     NULL,
  -- Quien lo pidio (NULL si lo pidio el propio interesado sin sesion) y desde
  -- donde se uso. Un enlace de contrasena es evidencia de seguridad.
  requested_by_user_id BIGINT UNSIGNED NULL,
  used_ip              VARBINARY(16)   NULL,
  created_at           DATETIME(3)     NULL,
  updated_at           DATETIME(3)     NULL,
  -- Vale 1 mientras el enlace esta VIVO. Es la puerta de siempre: NULL no
  -- colisiona en un indice unico, asi que el unico de abajo deja uno vivo por
  -- (usuario, proposito) y todos los muertos que hagan falta.
  vigente_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN used_at IS NULL AND revoked_at IS NULL THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_pl_uuid (uuid),
  UNIQUE KEY uq_pl_token (token_sha256),
  -- «Un enlace nuevo invalida el anterior», garantizado por la base.
  UNIQUE KEY uq_pl_vigente (vigente_gate, user_id, purpose),
  KEY ix_pl_usuario (user_id, purpose),
  KEY ix_pl_caducidad (expires_at),
  KEY ix_pl_solicitante (requested_by_user_id),
  CONSTRAINT fk_pl_usuario FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pl_solicitante FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_pl_purpose CHECK (purpose IN ('initial', 'reset')),
  -- Usado exige DESDE DONDE: es la fila que se mira cuando alguien dice «yo no
  -- entre».
  CONSTRAINT ck_pl_used CHECK (used_at IS NULL OR used_ip IS NOT NULL),
  -- Revocado exige POR QUE.
  CONSTRAINT ck_pl_revoked CHECK (revoked_at IS NULL OR revoked_reason IS NOT NULL),
  -- Y las dos muertes se excluyen: si convivieran, la puerta seguiria diciendo
  -- «muerto» y la evidencia diria dos cosas a la vez.
  CONSTRAINT ck_pl_terminal CHECK (used_at IS NULL OR revoked_at IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
