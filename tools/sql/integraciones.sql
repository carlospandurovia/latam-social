-- LATAM Social - Fase 9, iteracion 9.17d - Las credenciales de cada API
--
-- `Q-03` y `DEC-033`/docs-12: la URL, el usuario y las claves secundarias de
-- SUNAT no viven en `legal_entities`. Una sociedad es QUIEN factura; una
-- conexion es CON QUE se conecta uno a un proveedor, y cambia mucho mas a menudo.
--
-- Esquema PROPIO y no dentro de `cimientos`: estas tablas apuntan a
-- `legal_entities` y a `users`, que viven en otros dos esquemas, y meterlas en
-- cimientos creaba un ciclo --cimientos -> entidades-legales -> cimientos-- que
-- el cargador de `rehacer-referencia.sh` detecto al primer intento. Un esquema
-- propio ademas dice lo que son: un sitio, no un apendice de otro.
SET NAMES utf8mb4;

-- ==================== D2 Core: las credenciales de cada API (9.17d)
-- `Q-03` y `DEC-033`/docs-12: la URL, el usuario y las claves secundarias de
-- SUNAT no viven en `legal_entities`. Una sociedad es QUIEN factura; una
-- conexion es CON QUE se conecta uno a un proveedor, y cambia mucho mas a menudo.
--
-- De las cinco tablas de docs/12 van tres. Las ASIGNACIONES --el eje (marca,
-- sociedad, pais) con vigencia-- resuelven un problema que todavia no existe:
-- hoy una conexion sirve a una sociedad o a la plataforma entera, y eso cabe en
-- una columna nullable. Cuando haya dos proveedores de facturacion conviviendo,
-- esa tabla se hace y esta columna se migra a ella.
CREATE TABLE integration_providers (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(40)   NOT NULL,
  name          VARCHAR(120)  NOT NULL,
  -- Para que sirve: agrupa la pantalla y permitira preguntar «quien factura en PE».
  purpose       VARCHAR(30)   NOT NULL,
  doc_url       VARCHAR(255)  NULL,
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  created_at    DATETIME(3)   NULL,
  updated_at    DATETIME(3)   NULL,
  UNIQUE KEY uq_iprov_code (code),
  KEY ix_iprov_purpose (purpose, is_active),
  CONSTRAINT ck_iprov_purpose CHECK (purpose IN ('invoicing','fx','email','payment','identity','other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE integration_connections (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36)      NOT NULL,
  integration_provider_id BIGINT UNSIGNED NOT NULL,
  -- NULL = de la plataforma entera. Con sociedad = de esa sociedad, que es el
  -- caso del emisor electronico: va con el RUC y el certificado de quien factura.
  legal_entity_id BIGINT UNSIGNED NULL,
  name          VARCHAR(120)  NOT NULL,
  environment   VARCHAR(15)   NOT NULL DEFAULT 'sandbox',
  base_url      VARCHAR(255)  NULL,
  -- El usuario secundario de SUNAT y equivalentes: NO es un secreto --se ensena
  -- entero-- y por eso no esta en `integration_credentials`.
  username      VARCHAR(120)  NULL,
  status        VARCHAR(15)   NOT NULL DEFAULT 'draft',
  -- Lo que contesta «.funciona?» sin tener que probarlo. Sin esto, el estado de
  -- una integracion es una conjetura hasta que algo falla de cara al cliente.
  last_verified_at DATETIME(3) NULL,
  last_success_at  DATETIME(3) NULL,
  last_error_at    DATETIME(3) NULL,
  last_error_message VARCHAR(255) NULL,
  created_at    DATETIME(3)   NULL,
  updated_at    DATETIME(3)   NULL,
  -- La puerta: UNA sola conexion activa por (proveedor, entorno, sociedad).
  -- `COALESCE(legal_entity_id, 0)` porque en un indice unico dos NULL NO
  -- colisionan, y sin eso se podrian tener dos conexiones de plataforma activas
  -- del mismo proveedor, que es justo lo que se quiere impedir.
  active_gate   VARCHAR(70) GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN CONCAT(integration_provider_id, ':', environment, ':', COALESCE(legal_entity_id, 0)) ELSE NULL END) STORED,
  UNIQUE KEY uq_iconn_uuid (uuid),
  UNIQUE KEY uq_iconn_active (active_gate),
  KEY ix_iconn_provider (integration_provider_id, status),
  KEY ix_iconn_entity (legal_entity_id),
  CONSTRAINT fk_iconn_provider FOREIGN KEY (integration_provider_id) REFERENCES integration_providers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_iconn_entity FOREIGN KEY (legal_entity_id) REFERENCES legal_entities(id) ON DELETE RESTRICT,
  -- La barrera de DEC-029 aplicada a las conexiones: la de pruebas y la real
  -- conviven y no se pueden confundir.
  CONSTRAINT ck_iconn_env CHECK (environment IN ('sandbox','production')),
  CONSTRAINT ck_iconn_status CHECK (status IN ('draft','active','disabled')),
  -- Una conexion ACTIVA tiene que saber a donde llamar. En borrador no: un
  -- borrador es justamente el sitio donde todavia faltan cosas.
  CONSTRAINT ck_iconn_url CHECK (status <> 'active' OR (base_url IS NOT NULL AND base_url LIKE 'https://%'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El secreto se escribe y no se vuelve a leer. Rotar CREA una version nueva y
-- cierra la anterior; no la sobrescribe (docs/12 3.2). Asi se puede volver atras
-- si la nueva es incorrecta, y se puede contestar «.cuando cambio?».
CREATE TABLE integration_credentials (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  integration_connection_id BIGINT UNSIGNED NOT NULL,
  kind          VARCHAR(30)   NOT NULL,
  secret_cipher TEXT          NOT NULL,
  -- Lo UNICO que vuelve a una pantalla.
  last4         VARCHAR(8)    NULL,
  version       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  set_by_user_id BIGINT UNSIGNED NOT NULL,
  set_at        DATETIME(3)   NOT NULL,
  revoked_at    DATETIME(3)   NULL,
  revoked_reason VARCHAR(255) NULL,
  created_at    DATETIME(3)   NULL,
  updated_at    DATETIME(3)   NULL,
  -- La 29.a columna puerta: una sola credencial VIVA por (conexion, clase). Con
  -- dos, la mitad de las llamadas iria con una y la otra mitad con otra.
  vigente_gate  VARCHAR(45) GENERATED ALWAYS AS (CASE WHEN revoked_at IS NULL THEN CONCAT(integration_connection_id, ':', kind) ELSE NULL END) STORED,
  UNIQUE KEY uq_icred_vigente (vigente_gate),
  KEY ix_icred_conn (integration_connection_id, kind),
  KEY ix_icred_autor (set_by_user_id),
  -- RESTRICT y no CASCADE: MySQL 8 rechaza con un 1215 anadir una columna
  -- generada sobre una columna que participa en una foranea con accion en
  -- cascada, y `vigente_gate` se construye justo sobre esta. MariaDB lo admite
  -- --este esquema cargo sin quejarse-- y MySQL 8 no: los dos motores hacen
  -- falta para verlo. Y ademas es lo correcto: aqui nada se borra en cascada.
  CONSTRAINT fk_icred_conn FOREIGN KEY (integration_connection_id) REFERENCES integration_connections(id) ON DELETE RESTRICT,
  CONSTRAINT fk_icred_autor FOREIGN KEY (set_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_icred_kind CHECK (kind IN ('api_key','password','token','webhook_secret','client_secret')),
  -- Un cifrado vacio es una credencial que no existe disfrazada de credencial
  -- que si: la pantalla diria «configurada» y la llamada saldria sin clave.
  CONSTRAINT ck_icred_cipher CHECK (TRIM(secret_cipher) <> ''),
  CONSTRAINT ck_icred_revocada CHECK (revoked_at IS NULL OR revoked_reason IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

-- 9.17d: una credencial no se reescribe: se revoca y se crea la siguiente. Sin
-- esto, «rotar» podria ser un UPDATE sobre el cifrado, y entonces la pregunta
-- «.cuando cambio y quien la puso?» deja de tener respuesta, que es la mitad
-- del motivo de que esta tabla exista.
CREATE TRIGGER `tg_icred_inmutable`
BEFORE UPDATE ON `integration_credentials`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`secret_cipher` <=> OLD.`secret_cipher`)
       OR NOT (NEW.`kind` <=> OLD.`kind`)
       OR NOT (NEW.`integration_connection_id` <=> OLD.`integration_connection_id`)
       OR NOT (NEW.`set_by_user_id` <=> OLD.`set_by_user_id`)
       OR NOT (NEW.`set_at` <=> OLD.`set_at`) THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Una credencial no se reescribe: revoquela y guarde la siguiente.';
    END IF;

    IF OLD.`revoked_at` IS NOT NULL AND NEW.`revoked_at` IS NULL THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Una credencial revocada no vuelve a estar viva.';
    END IF;
END//

DELIMITER ;
