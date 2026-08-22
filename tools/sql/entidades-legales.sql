-- LATAM Social - Fase 2, iteracion 2.10 - Marca de plataforma y entidades legales
-- La otra mitad de DEC-016. Lo que decide QUE SOCIEDAD emite cada factura.
SET NAMES utf8mb4;

-- ============================ D2 Core: la marca de plataforma
-- LATAM Social. No es una sociedad: es como nos llamamos de cara al mercado.
CREATE TABLE platform_brands (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36)      NOT NULL,
  code          VARCHAR(30)   NOT NULL,
  name          VARCHAR(120)  NOT NULL,
  legal_footer  VARCHAR(255)  NULL,
  logo_file_id  BIGINT UNSIGNED NULL,
  primary_color CHAR(7)       NULL,
  website       VARCHAR(255)  NULL,
  support_email VARCHAR(255)  NULL,
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  created_at    DATETIME(3)   NULL,
  updated_at    DATETIME(3)   NULL,
  UNIQUE KEY uq_pb_uuid (uuid),
  UNIQUE KEY uq_pb_code (code),
  KEY ix_pb_logo (logo_file_id),
  CONSTRAINT fk_pb_logo FOREIGN KEY (logo_file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT ck_pb_color CHECK (primary_color IS NULL OR primary_color REGEXP '^#[0-9A-Fa-f]{6}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================ D2 Core: la sociedad que factura
-- CTS Peru, CTS Colombia. Es lo que aparece como emisor en el comprobante, y
-- lo que la factura CONGELA (BR-LE-005): la sociedad cambia de domicilio, la
-- factura de ayer no.
--
-- NO lleva ruta de certificado ni credenciales de SUNAT: eso es una conexion de
-- integracion (docs/12, DEC-033). Fue una autocorreccion de la Fase 0.
CREATE TABLE legal_entities (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid              CHAR(36)      NOT NULL,
  platform_brand_id BIGINT UNSIGNED NOT NULL,
  code              VARCHAR(30)   NOT NULL,
  legal_name        VARCHAR(200)  NOT NULL,
  trade_name        VARCHAR(160)  NULL,
  country_id        BIGINT UNSIGNED NOT NULL,
  tax_id_type       VARCHAR(20)   NOT NULL,
  tax_id_number     VARCHAR(40)   NOT NULL,
  address_line1     VARCHAR(180)  NOT NULL,
  address_line2     VARCHAR(180)  NULL,
  city              VARCHAR(100)  NOT NULL,
  region            VARCHAR(100)  NULL,
  postal_code       VARCHAR(20)   NULL,
  default_currency_code CHAR(3)   NOT NULL,
  -- Convierte un instante UTC en "el dia" que exige el comprobante (2.3 §8).
  timezone          VARCHAR(64)   NOT NULL,
  legal_representative VARCHAR(160) NULL,
  status            VARCHAR(15)   NOT NULL DEFAULT 'active',
  incorporated_on   DATE          NULL,
  dissolved_on      DATE          NULL,
  created_at        DATETIME(3)   NULL,
  updated_at        DATETIME(3)   NULL,
  UNIQUE KEY uq_le_uuid (uuid),
  UNIQUE KEY uq_le_code (code),
  -- Dos sociedades no pueden compartir identificador fiscal en el mismo pais.
  UNIQUE KEY uq_le_taxid (country_id, tax_id_type, tax_id_number),
  KEY ix_le_brand (platform_brand_id, status),
  KEY ix_le_currency (default_currency_code),
  CONSTRAINT fk_le_brand FOREIGN KEY (platform_brand_id) REFERENCES platform_brands(id) ON DELETE RESTRICT,
  CONSTRAINT fk_le_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_le_currency FOREIGN KEY (default_currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT ck_le_status CHECK (status IN ('active','inactive','dissolved')),
  CONSTRAINT ck_le_dates CHECK (dissolved_on IS NULL OR incorporated_on IS NULL OR dissolved_on >= incorporated_on),
  -- Una sociedad disuelta tiene que decir cuando. Sigue existiendo en el
  -- historico: BR-LE-011 prohibe borrarla mientras tenga comprobantes emitidos.
  CONSTRAINT ck_le_dissolved CHECK (status <> 'dissolved' OR dissolved_on IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== D2 Core: que sociedad factura en que pais (docs/11)
-- N:M con VIGENCIA, no un booleano: la cobertura cambia y el historico manda.
-- CTS Peru factura PE, EC, CL, MX y US. CTS Colombia factura CO.
CREATE TABLE legal_entity_countries (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  legal_entity_id   BIGINT UNSIGNED NOT NULL,
  country_id        BIGINT UNSIGNED NOT NULL,
  -- Nota de por que esta sociedad cubre este pais (exportacion de servicios,
  -- sociedad local...). Es lo que un auditor pregunta primero.
  coverage_basis    VARCHAR(40)   NOT NULL DEFAULT 'service_export',
  valid_from        DATE          NOT NULL,
  valid_to          DATE          NULL,
  created_at        DATETIME(3)   NULL,
  updated_at        DATETIME(3)   NULL,
  current_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN valid_to IS NULL THEN 1 ELSE NULL END) STORED,
  -- UNA sola sociedad vigente por pais. Sin esto el resolver tendria empate, y
  -- 2.2 ya decidio que los empates se rechazan al guardar, no al facturar.
  UNIQUE KEY uq_lec_country (current_gate, country_id),
  KEY ix_lec_entity (legal_entity_id, country_id),
  CONSTRAINT fk_lec_entity FOREIGN KEY (legal_entity_id) REFERENCES legal_entities(id) ON DELETE RESTRICT,
  CONSTRAINT fk_lec_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE RESTRICT,
  CONSTRAINT ck_lec_basis CHECK (coverage_basis IN ('local_entity','service_export','branch','other')),
  CONSTRAINT ck_lec_dates CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================ D2 Core: series y correlativos de comprobante
-- SUNAT exige serie + correlativo sin huecos por tipo de documento. El numero
-- se reserva aqui, no se calcula con MAX(): dos peticiones simultaneas darian
-- el mismo correlativo, que es un problema tributario, no un bug cualquiera.
CREATE TABLE document_series (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  legal_entity_id  BIGINT UNSIGNED NOT NULL,
  document_type    VARCHAR(30)   NOT NULL,
  series           VARCHAR(10)   NOT NULL,
  next_number      BIGINT UNSIGNED NOT NULL DEFAULT 1,
  environment      VARCHAR(15)   NOT NULL DEFAULT 'production',
  is_active        TINYINT(1)    NOT NULL DEFAULT 1,
  created_at       DATETIME(3)   NULL,
  updated_at       DATETIME(3)   NULL,
  UNIQUE KEY uq_ds_series (legal_entity_id, document_type, series, environment),
  KEY ix_ds_entity (legal_entity_id, is_active),
  CONSTRAINT fk_ds_entity FOREIGN KEY (legal_entity_id) REFERENCES legal_entities(id) ON DELETE RESTRICT,
  CONSTRAINT ck_ds_type CHECK (document_type IN ('invoice','boleta','credit_note','debit_note','other')),
  CONSTRAINT ck_ds_env CHECK (environment IN ('sandbox','production')),
  CONSTRAINT ck_ds_number CHECK (next_number >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
