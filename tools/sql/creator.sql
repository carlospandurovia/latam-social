-- LATAM Social - Fase 2, iteracion 2.6 - Creador: identidad y perfil
SET NAMES utf8mb4;

-- ====================================================== D1 Core: archivos
-- Se adelanta aqui porque la autorizacion del tutor y el documento de identidad
-- son archivos, y sin esta tabla habria que dejar claves foraneas colgando.
CREATE TABLE files (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid               CHAR(36)      NOT NULL,
  disk               VARCHAR(30)   NOT NULL DEFAULT 's3',
  path               VARCHAR(500)  NOT NULL,
  original_name      VARCHAR(255)  NOT NULL,
  mime_type          VARCHAR(120)  NOT NULL,
  size_bytes         BIGINT UNSIGNED NOT NULL,
  -- Permite detectar duplicados y probar que el archivo no se altero.
  checksum_sha256    CHAR(64)      NOT NULL,
  visibility         VARCHAR(10)   NOT NULL DEFAULT 'private',
  purpose            VARCHAR(40)   NOT NULL,
  uploaded_by_user_id BIGINT UNSIGNED NULL,
  purged_at          DATETIME(3)   NULL,
  created_at         DATETIME(3)   NULL,
  updated_at         DATETIME(3)   NULL,
  UNIQUE KEY uq_files_uuid (uuid),
  KEY ix_files_checksum (checksum_sha256),
  KEY ix_files_purpose (purpose, created_at),
  CONSTRAINT fk_files_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_files_visibility CHECK (visibility IN ('private','public')),
  CONSTRAINT ck_files_size CHECK (size_bytes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================== D3 Creator: la solicitud
-- Efimera, repetible y rechazable. Es la puerta, no el creador (2.1).
CREATE TABLE creator_applications (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid               CHAR(36)      NOT NULL,
  full_name          VARCHAR(160)  NOT NULL,
  email              VARCHAR(255)  NOT NULL,
  phone              VARCHAR(30)   NULL,
  country_id         BIGINT UNSIGNED NOT NULL,
  source             VARCHAR(20)   NOT NULL DEFAULT 'landing',
  referral_code      VARCHAR(30)   NULL,
  status             VARCHAR(20)   NOT NULL DEFAULT 'submitted',
  reviewed_by_user_id BIGINT UNSIGNED NULL,
  reviewed_at        DATETIME(3)   NULL,
  rejection_note     VARCHAR(255)  NULL,
  creator_id         BIGINT UNSIGNED NULL,
  submitted_at       DATETIME(3)   NOT NULL,
  created_at         DATETIME(3)   NULL,
  updated_at         DATETIME(3)   NULL,
  -- Se puede volver a postular, pero no tener dos solicitudes abiertas a la vez.
  open_email_key VARCHAR(255)
    GENERATED ALWAYS AS (
      CASE WHEN status IN ('submitted','in_review') THEN LOWER(email) ELSE NULL END
    ) STORED,
  UNIQUE KEY uq_creator_applications_uuid (uuid),
  UNIQUE KEY uq_creator_applications_open (open_email_key),
  KEY ix_creator_applications_status (status, submitted_at),
  KEY ix_creator_applications_country (country_id),
  KEY ix_creator_applications_referral (referral_code),
  KEY ix_creator_applications_reviewer (reviewed_by_user_id),
  CONSTRAINT fk_creator_applications_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_creator_applications_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_creator_applications_status CHECK (status IN ('submitted','in_review','approved','rejected','duplicate')),
  CONSTRAINT ck_creator_applications_source CHECK (source IN ('landing','referral','import','manual','event'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================================================== D3 Creator: el creador
CREATE TABLE creators (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid               CHAR(36)      NOT NULL,
  -- 1:1 opcional (2.2 P-01): el creador existe antes que su cuenta.
  user_id            BIGINT UNSIGNED NULL,
  application_id     BIGINT UNSIGNED NULL,
  first_name         VARCHAR(80)   NOT NULL,
  last_name          VARCHAR(80)   NOT NULL,
  display_name       VARCHAR(120)  NOT NULL,
  -- Obligatoria (2.3 §3): sin ella no se sabe si hace falta tutor.
  birth_date         DATE          NOT NULL,
  email              VARCHAR(255)  NOT NULL,
  phone              VARCHAR(30)   NULL,
  country_id         BIGINT UNSIGNED NOT NULL,
  city               VARCHAR(100)  NULL,
  document_country_code CHAR(2)    NOT NULL,
  document_type      VARCHAR(20)   NOT NULL,
  document_number    VARCHAR(40)   NOT NULL,
  status             VARCHAR(20)   NOT NULL DEFAULT 'pending',
  -- BR-FIN-012: 30 dias por defecto, configurable por creador.
  payment_term_days  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  preferred_currency_code CHAR(3)  NOT NULL,
  locale             VARCHAR(10)   NOT NULL DEFAULT 'es',
  timezone           VARCHAR(64)   NOT NULL DEFAULT 'America/Lima',
  activated_at       DATETIME(3)   NULL,
  -- BR-CREATOR-009: se anonimiza, no se borra. Los datos financieros quedan.
  anonymized_at      DATETIME(3)   NULL,
  created_at         DATETIME(3)   NULL,
  updated_at         DATETIME(3)   NULL,
  -- BR-CREATOR-003: no dos creadores con el mismo documento ni el mismo email.
  -- La clave se anula al anonimizar, que es cuando el dato deja de existir.
  -- Columna PUERTA en vez de clave concatenada. Vale 1 mientras la fila cuenta
  -- y NULL cuando deja de contar; como un indice unico ignora las filas con
  -- cualquier parte NULL, la unicidad se apaga sola al anonimizar.
  --
  -- Se evita CONCAT a proposito: MariaDB rechaza CONCAT con literales en una
  -- columna generada persistente (el charset del literal la hace no determinista),
  -- y ademas esto indexa numeros y columnas reales en vez de una cadena inventada.
  identity_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN anonymized_at IS NULL THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_creators_uuid (uuid),
  UNIQUE KEY uq_creators_user (user_id),
  -- La intercalacion utf8mb4_unicode_ci ya es insensible a mayusculas,
  -- asi que 'ANA@' y 'ana@' colisionan sin necesidad de LOWER().
  UNIQUE KEY uq_creators_identity (identity_gate, document_country_code, document_type, document_number),
  UNIQUE KEY uq_creators_email (identity_gate, email),
  KEY ix_creators_status (status, created_at),
  KEY ix_creators_country (country_id, status),
  KEY ix_creators_application (application_id),
  KEY ix_creators_currency (preferred_currency_code),
  KEY ix_creators_birth (birth_date),
  CONSTRAINT fk_creators_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_creators_application FOREIGN KEY (application_id) REFERENCES creator_applications(id) ON DELETE RESTRICT,
  CONSTRAINT fk_creators_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_creators_currency FOREIGN KEY (preferred_currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT ck_creators_status CHECK (status IN ('pending','active','suspended','rejected','blacklisted','inactive')),
  CONSTRAINT ck_creators_payment_term CHECK (payment_term_days BETWEEN 0 AND 180),
  CONSTRAINT ck_creators_document_type CHECK (document_type IN ('DNI','CE','RUC','PASSPORT','CC','NIT','CURP','RFC','RUT','SSN','NIE','NIF','OTHER')),
  CONSTRAINT ck_creators_birth_date CHECK (birth_date > '1920-01-01')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE creator_applications
  ADD CONSTRAINT fk_creator_applications_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT;

-- ================================================== D3 Creator: el tutor
-- BR-CREATOR-010 y 2.3 §3: el beneficiario del pago puede no ser el creador.
CREATE TABLE creator_guardians (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  creator_id         BIGINT UNSIGNED NOT NULL,
  full_name          VARCHAR(160)  NOT NULL,
  relationship       VARCHAR(20)   NOT NULL,
  document_country_code CHAR(2)    NOT NULL,
  document_type      VARCHAR(20)   NOT NULL,
  document_number    VARCHAR(40)   NOT NULL,
  email              VARCHAR(255)  NOT NULL,
  phone              VARCHAR(30)   NULL,
  -- Los dos documentos que exigio el negocio: autorizacion firmada y prueba
  -- del parentesco. Sin ellos la tutela no puede estar activa.
  authorization_file_id BIGINT UNSIGNED NULL,
  proof_of_relationship_file_id BIGINT UNSIGNED NULL,
  status             VARCHAR(20)   NOT NULL DEFAULT 'pending',
  valid_from         DATE          NOT NULL,
  -- Se rellena con la fecha en que el creador cumple 18 (2.3 §3).
  valid_to           DATE          NULL,
  created_at         DATETIME(3)   NULL,
  updated_at         DATETIME(3)   NULL,
  -- Un creador no puede tener dos tutelas activas a la vez.
  active_creator_key BIGINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN creator_id ELSE NULL END) STORED,
  UNIQUE KEY uq_creator_guardians_active (active_creator_key),
  KEY ix_creator_guardians_creator (creator_id, status),
  KEY ix_creator_guardians_auth_file (authorization_file_id),
  KEY ix_creator_guardians_proof_file (proof_of_relationship_file_id),
  CONSTRAINT fk_creator_guardians_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_creator_guardians_auth FOREIGN KEY (authorization_file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT fk_creator_guardians_proof FOREIGN KEY (proof_of_relationship_file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT ck_creator_guardians_status CHECK (status IN ('pending','active','closed','revoked')),
  CONSTRAINT ck_creator_guardians_relationship CHECK (relationship IN ('father','mother','legal_guardian')),
  CONSTRAINT ck_creator_guardians_dates CHECK (valid_to IS NULL OR valid_to >= valid_from),
  -- Una tutela activa exige los dos documentos. Esta es la regla que impide
  -- pagar a un tutor cuya autorizacion nadie subio.
  CONSTRAINT ck_creator_guardians_docs CHECK (
    status <> 'active' OR (authorization_file_id IS NOT NULL AND proof_of_relationship_file_id IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================== D3 Creator: direcciones
-- 2.1: la de envio no es la fiscal, y tienen vigencia propia.
CREATE TABLE creator_addresses (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  creator_id         BIGINT UNSIGNED NOT NULL,
  address_type       VARCHAR(15)   NOT NULL,
  line1              VARCHAR(180)  NOT NULL,
  line2              VARCHAR(180)  NULL,
  city               VARCHAR(100)  NOT NULL,
  region             VARCHAR(100)  NULL,
  postal_code        VARCHAR(20)   NULL,
  country_id         BIGINT UNSIGNED NOT NULL,
  reference_notes    VARCHAR(255)  NULL,
  is_default         TINYINT(1)    NOT NULL DEFAULT 0,
  valid_from         DATE          NOT NULL,
  valid_to           DATE          NULL,
  created_at         DATETIME(3)   NULL,
  updated_at         DATETIME(3)   NULL,
  -- Una sola direccion por defecto de cada tipo. NULL no colisiona.
  default_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN is_default = 1 THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_creator_addresses_default (default_gate, creator_id, address_type),
  KEY ix_creator_addresses_creator (creator_id, address_type),
  KEY ix_creator_addresses_country (country_id),
  CONSTRAINT fk_creator_addresses_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_creator_addresses_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE RESTRICT,
  CONSTRAINT ck_creator_addresses_type CHECK (address_type IN ('shipping','tax','billing')),
  CONSTRAINT ck_creator_addresses_dates CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================== D3 Creator: cuentas sociales
CREATE TABLE social_accounts (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid               CHAR(36)      NOT NULL,
  creator_id         BIGINT UNSIGNED NOT NULL,
  platform_id        BIGINT UNSIGNED NOT NULL,
  handle             VARCHAR(120)  NOT NULL,
  profile_url        VARCHAR(500)  NOT NULL,
  external_id        VARCHAR(120)  NULL,
  verification_status VARCHAR(15)  NOT NULL DEFAULT 'unverified',
  verification_method VARCHAR(20)  NULL,
  verified_at        DATETIME(3)   NULL,
  is_primary         TINYINT(1)    NOT NULL DEFAULT 0,
  is_active          TINYINT(1)    NOT NULL DEFAULT 1,
  created_at         DATETIME(3)   NULL,
  updated_at         DATETIME(3)   NULL,
  -- BR-CREATOR-003: la misma cuenta verificada no puede pertenecer a dos creadores.
  verified_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN verification_status = 'verified' THEN 1 ELSE NULL END) STORED,
  -- Una sola cuenta principal por red y creador.
  primary_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN is_primary = 1 THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_social_accounts_uuid (uuid),
  UNIQUE KEY uq_social_accounts_verified (verified_gate, platform_id, handle),
  UNIQUE KEY uq_social_accounts_primary (primary_gate, creator_id, platform_id),
  UNIQUE KEY uq_social_accounts_creator_handle (creator_id, platform_id, handle),
  KEY ix_social_accounts_platform (platform_id, verification_status),
  CONSTRAINT fk_social_accounts_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_social_accounts_platform FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE RESTRICT,
  CONSTRAINT ck_social_accounts_verification CHECK (verification_status IN ('unverified','pending','verified','failed')),
  CONSTRAINT ck_social_accounts_verified_at CHECK (verification_status <> 'verified' OR verified_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================ D3 Creator: historico de metricas (append-only)
-- BR-CREATOR-005: un valor nuevo NUNCA sobrescribe al anterior.
CREATE TABLE social_account_snapshots (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  social_account_id  BIGINT UNSIGNED NOT NULL,
  captured_at        DATETIME(3)   NOT NULL,
  source             VARCHAR(20)   NOT NULL,
  followers          BIGINT UNSIGNED NULL,
  following          BIGINT UNSIGNED NULL,
  posts_count        BIGINT UNSIGNED NULL,
  avg_views          BIGINT UNSIGNED NULL,
  avg_likes          BIGINT UNSIGNED NULL,
  avg_comments       BIGINT UNSIGNED NULL,
  engagement_rate    DECIMAL(7,4)  NULL,
  -- 2.2 P-09: columnas para lo que se agrega, JSON para lo especifico de cada red.
  extra              LONGTEXT      NULL,
  -- BR-CREATOR-004: lo anomalo se marca para revision humana, no se rechaza solo.
  is_anomalous       TINYINT(1)    NOT NULL DEFAULT 0,
  anomaly_note       VARCHAR(255)  NULL,
  KEY ix_sas_account (social_account_id, captured_at),
  KEY ix_sas_anomaly (is_anomalous, captured_at),
  CONSTRAINT fk_sas_account FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE RESTRICT,
  CONSTRAINT ck_sas_source CHECK (source IN ('self_declared','api','manual_review','import')),
  CONSTRAINT ck_sas_engagement CHECK (engagement_rate IS NULL OR (engagement_rate >= 0 AND engagement_rate <= 100)),
  CONSTRAINT ck_sas_extra CHECK (extra IS NULL OR JSON_VALID(extra))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
