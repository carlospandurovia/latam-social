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

-- ==================== A donde llama cada proveedor, por entorno (9.17e)
-- Reportado por el negocio: «.por que me pide la URL? si selecciono Pruebas debe
-- ir al URL Beta, si selecciono PRD al de PRD». Tiene razon, y era un defecto de
-- 9.17d: los extremos de SUNAT son FIJOS y PUBLICOS --no son un dato de esta
-- instalacion, son la direccion del servicio-- y pedirselos a una persona es
-- pedirle que teclee una constante. Un caracter de mas produce comprobantes que
-- no llegan, con un error de red que no dice que paso.
--
-- Es DEC-190 del reves: alli el problema era quemar en el codigo lo que es de
-- cada instalacion; aqui era pedirle a cada instalacion lo que es del proveedor.
CREATE TABLE integration_provider_endpoints (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  integration_provider_id BIGINT UNSIGNED NOT NULL,
  environment   VARCHAR(15)   NOT NULL,
  base_url      VARCHAR(255)  NOT NULL,
  -- Como lo llama el proveedor en su documentacion.
  label         VARCHAR(60)   NULL,
  notes         VARCHAR(255)  NULL,
  created_at    DATETIME(3)   NULL,
  updated_at    DATETIME(3)   NULL,
  -- Una direccion por proveedor y entorno: con dos, la mitad de las llamadas
  -- iria a una y la mitad a otra.
  UNIQUE KEY uq_ipend_entorno (integration_provider_id, environment),
  CONSTRAINT fk_ipend_provider FOREIGN KEY (integration_provider_id) REFERENCES integration_providers(id) ON DELETE RESTRICT,
  CONSTRAINT ck_ipend_env CHECK (environment IN ('sandbox','production')),
  CONSTRAINT ck_ipend_url CHECK (base_url LIKE 'https://%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE integration_connections (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36)      NOT NULL,
  integration_provider_id BIGINT UNSIGNED NOT NULL,
  -- 9.17f: copia del proposito del proveedor. Una columna generada solo puede
  -- leer columnas de SU PROPIA fila, y la puerta tiene que ser por PROPOSITO
  -- --no por proveedor--: con dos emisores electronicos dados de alta, los dos
  -- podian estar activos y nadie sabria cual emite. Lo mantiene el mismo
  -- disparador que valida la activacion, y por eso la garantia puede ser un
  -- INDICE UNICO y no un COUNT(*) dentro de un disparador, que no bloquea nada.
  purpose_snapshot VARCHAR(30) NULL,
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
  -- La puerta: UNA sola integracion activa por (PROPOSITO, entorno, sociedad).
  -- Lo que tiene que ser unico es QUIEN HACE ESTE TRABAJO --un emisor
  -- electronico, un servidor de correo-- y no de quien se contrato.
  -- `COALESCE(legal_entity_id, 0)` porque en un indice unico dos NULL NO
  -- colisionan, y sin eso se podrian tener dos de plataforma activas a la vez.
  active_gate   VARCHAR(70) GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN CONCAT(purpose_snapshot, ':', environment, ':', COALESCE(legal_entity_id, 0)) ELSE NULL END) STORED,
  UNIQUE KEY uq_iconn_uuid (uuid),
  UNIQUE KEY uq_iconn_activa (active_gate),
  KEY ix_iconn_provider (integration_provider_id, status),
  KEY ix_iconn_entity (legal_entity_id),
  CONSTRAINT fk_iconn_provider FOREIGN KEY (integration_provider_id) REFERENCES integration_providers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_iconn_entity FOREIGN KEY (legal_entity_id) REFERENCES legal_entities(id) ON DELETE RESTRICT,
  -- La barrera de DEC-029 aplicada a las conexiones: la de pruebas y la real
  -- conviven y no se pueden confundir.
  CONSTRAINT ck_iconn_env CHECK (environment IN ('sandbox','production')),
  -- 9.17e: `ck_iconn_url` se fue de aqui. «.Sabe esta conexion a donde llamar?»
  -- pasa a contestarse mirando OTRA tabla --el extremo que declara el proveedor
  -- para ese entorno-- y un CHECK no puede cruzar tablas. Vive en
  -- `tg_iconn_activa_ins` / `_upd`, que ademas exige lo que faltaba: un emisor
  -- electronico va con una sociedad, porque lleva su RUC.
  CONSTRAINT ck_iconn_status CHECK (status IN ('draft','active','disabled'))
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

-- 9.17e: lo que hace falta para ACTIVAR una conexion, y solo para activarla:
-- un borrador es justamente el sitio donde todavia faltan cosas (DEC-190).
--
--   1. Sabe a donde llamar: la suya, o la que el proveedor declara para ese
--      entorno. Es cruzada, asi que no cabe en un CHECK.
--   2. Un emisor electronico va con una sociedad: lleva su RUC.
CREATE TRIGGER tg_iconn_activa_ins BEFORE INSERT ON integration_connections
FOR EACH ROW
BEGIN
  DECLARE v_delProveedor INT DEFAULT 0;

  -- 9.17f: el proposito se COPIA del proveedor, siempre. No se admite el que
  -- venga en la sentencia: seria un sitio donde alguien podria poner otro y
  -- partir la puerta en dos.
  SET NEW.purpose_snapshot = (
    SELECT purpose FROM integration_providers WHERE id = NEW.integration_provider_id
  );

  IF NEW.status = 'active' THEN
    SELECT COUNT(*) INTO v_delProveedor
      FROM integration_provider_endpoints
     WHERE integration_provider_id = NEW.integration_provider_id
       AND environment = NEW.environment;

    -- 9.17g: partida en las dos cosas que de verdad decia. Escrita como «tiene
    -- una URL https» era mirar solo a SUNAT: un servidor de CORREO no tiene
    -- URL, tiene servidor y puerto, y con la regla anterior una cuenta de
    -- correo no se podia activar.
    IF (NEW.base_url IS NULL OR TRIM(NEW.base_url) = '') AND v_delProveedor = 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Esa conexion no sabe a donde llamar: el proveedor no declara direccion para ese entorno.';
    END IF;

    IF NEW.base_url LIKE 'http://%' THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Una direccion web sin cifrar manda las claves en claro: use https.';
    END IF;

    IF NEW.purpose_snapshot = 'invoicing' AND NEW.legal_entity_id IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Un emisor electronico va con una sociedad: es su RUC el que firma.';
    END IF;
  END IF;
END//

CREATE TRIGGER tg_iconn_activa_upd BEFORE UPDATE ON integration_connections
FOR EACH ROW
BEGIN
  DECLARE v_delProveedor INT DEFAULT 0;

  -- 9.17f: el proposito se COPIA del proveedor, siempre. No se admite el que
  -- venga en la sentencia: seria un sitio donde alguien podria poner otro y
  -- partir la puerta en dos.
  SET NEW.purpose_snapshot = (
    SELECT purpose FROM integration_providers WHERE id = NEW.integration_provider_id
  );

  IF NEW.status = 'active' THEN
    SELECT COUNT(*) INTO v_delProveedor
      FROM integration_provider_endpoints
     WHERE integration_provider_id = NEW.integration_provider_id
       AND environment = NEW.environment;

    -- 9.17g: partida en las dos cosas que de verdad decia. Escrita como «tiene
    -- una URL https» era mirar solo a SUNAT: un servidor de CORREO no tiene
    -- URL, tiene servidor y puerto, y con la regla anterior una cuenta de
    -- correo no se podia activar.
    IF (NEW.base_url IS NULL OR TRIM(NEW.base_url) = '') AND v_delProveedor = 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Esa conexion no sabe a donde llamar: el proveedor no declara direccion para ese entorno.';
    END IF;

    IF NEW.base_url LIKE 'http://%' THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Una direccion web sin cifrar manda las claves en claro: use https.';
    END IF;

    IF NEW.purpose_snapshot = 'invoicing' AND NEW.legal_entity_id IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Un emisor electronico va con una sociedad: es su RUC el que firma.';
    END IF;
  END IF;
END//

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

-- 9.17h -- La fuente de tipos de cambio cuelga de una conexion.
--
-- Diferida hasta aqui, y no dentro de `fx_sources`: esa tabla vive en
-- `cimientos.sql`, que se carga ANTES que este esquema porque
-- `integration_connections` referencia a `users` y a `legal_entities`. Ponerla
-- en linea invertiria el orden y crearia un ciclo.
ALTER TABLE fx_sources
  ADD CONSTRAINT fk_fxs_conn FOREIGN KEY (integration_connection_id)
  REFERENCES integration_connections(id) ON DELETE RESTRICT;
