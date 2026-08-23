-- LATAM Social - Fase 2, iteracion 2.8 - Creador: fiscal y medios de pago
SET NAMES utf8mb4;

-- ============================================ D3/D6: perfil tributario
-- Un creador puede tener regimen en mas de un pais (el peruano con RUC que
-- ademas factura desde Espana). Por eso cuelga del pais, no del creador a secas.
CREATE TABLE creator_tax_profiles (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  creator_id          BIGINT UNSIGNED NOT NULL,
  -- 3.6 / H-01: DE QUIEN son estos datos fiscales.
  -- `creator_payment_methods` ya distinguia si la cuenta es del creador o del
  -- tutor, y `creator_tax_documents` ya sabia quien emite el comprobante. Este
  -- perfil era el unico que no lo decia: para un menor, el `tax_id_number` que
  -- hay aqui es el del TUTOR (BR-CREATOR-013) y nada en la fila lo indicaba.
  -- Un numero de RUC sin titular es una ambiguedad en un dato fiscal, y esas
  -- se pagan en la primera declaracion.
  holder_type         VARCHAR(10)   NOT NULL DEFAULT 'creator',
  holder_guardian_id  BIGINT UNSIGNED NULL,
  country_id          BIGINT UNSIGNED NOT NULL,
  -- Codigo del regimen tal como lo llama cada pais (RUS, RER, GENERAL, AUTONOMO...).
  -- Texto libre controlado y no catalogo: cada pais trae los suyos y anadirlos
  -- ocurre al abrir mercado, que ya es un despliegue.
  tax_regime_code     VARCHAR(30)   NOT NULL,
  tax_id_type         VARCHAR(20)   NOT NULL,
  tax_id_number       VARCHAR(40)   NULL,
  -- Que documento entrega el creador cuando cobra.
  issued_document_type VARCHAR(30)  NOT NULL,
  -- Q-40 / DEC-048. La version anterior era `withholding_applies TINYINT DEFAULT 0`,
  -- y ahi estaba el fallo: "no se retiene" y "nadie lo ha mirado todavia" eran
  -- el MISMO valor, cero. Un perfil se aprobaba con el defecto puesto, el pago
  -- salia sin retencion, y no habia forma de distinguir la decision del olvido.
  -- Tres estados, y 'pending_review' es el de partida: obliga a que alguien mire.
  withholding_status  VARCHAR(20)   NOT NULL DEFAULT 'pending_review',
  withholding_rate    DECIMAL(7,4)  NOT NULL DEFAULT 0,
  -- La norma que sustenta la tasa. Sin esto la tasa es un numero sin padre, y
  -- dentro de tres anos nadie sabra si el 30 % salio de la ley o de una
  -- suposicion de alguien que ya no trabaja aqui.
  withholding_basis   VARCHAR(160)  NULL,
  -- 3.6 / H-03: NOT NULL, como en `payout_batches` (DEC-044). Siendo NULL,
  -- `ck_ctp_segregation` se apagaba sola: bastaba aprobar un perfil sin decir
  -- quien lo habia capturado para saltarse la separacion de funciones. Es el
  -- mismo patron que DEC-048 -- un NULL que desactiva un control -- y se
  -- comprobo que funcionaba antes de cerrarlo.
  created_by_user_id  BIGINT UNSIGNED NOT NULL,
  -- BR-CREATOR-007: cambiar datos fiscales exige aprobacion interna.
  status              VARCHAR(15)   NOT NULL DEFAULT 'pending',
  approved_by_user_id BIGINT UNSIGNED NULL,
  approved_at         DATETIME(3)   NULL,
  rejection_note      VARCHAR(255)  NULL,
  valid_from          DATE          NOT NULL,
  valid_to            DATE          NULL,
  created_at          DATETIME(3)   NULL,
  updated_at          DATETIME(3)   NULL,
  -- Un solo perfil vigente y aprobado por creador y pais.
  current_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN valid_to IS NULL AND status = 'approved' THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_ctp_current (current_gate, creator_id, country_id),
  KEY ix_ctp_creator (creator_id, status),
  KEY ix_ctp_holder (holder_guardian_id),
  KEY ix_ctp_country (country_id),
  KEY ix_ctp_approver (approved_by_user_id),
  KEY ix_ctp_creator_user (created_by_user_id),
  KEY ix_ctp_withholding (withholding_status),
  KEY ix_ctp_taxid (tax_id_type, tax_id_number),
  CONSTRAINT fk_ctp_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ctp_holder FOREIGN KEY (holder_guardian_id) REFERENCES creator_guardians(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ctp_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ctp_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ctp_creator_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_ctp_status CHECK (status IN ('pending','approved','rejected','superseded')),
  -- Calcada de `ck_cpm_owner`: o es del creador y no hay tutor, o es del tutor
  -- y hay que decir cual. No existe el titular a medias.
  CONSTRAINT ck_ctp_holder CHECK (
    (holder_type = 'creator'  AND holder_guardian_id IS NULL) OR
    (holder_type = 'guardian' AND holder_guardian_id IS NOT NULL)
  ),
  CONSTRAINT ck_ctp_doc CHECK (issued_document_type IN ('recibo_honorarios','factura','invoice','none')),
  CONSTRAINT ck_ctp_rate CHECK (withholding_rate >= 0 AND withholding_rate <= 100),
  CONSTRAINT ck_ctp_withholding_status CHECK (
    withholding_status IN ('pending_review','not_applicable','applies')
  ),
  -- Si se retiene, hay tasa Y hay norma que la sustente.
  CONSTRAINT ck_ctp_rate_required CHECK (
    withholding_status <> 'applies' OR (withholding_rate > 0 AND withholding_basis IS NOT NULL)
  ),
  -- Y si se decidio que no se retiene, la tasa es cero. No puede quedar un
  -- numero suelto de un borrador anterior.
  CONSTRAINT ck_ctp_rate_zero CHECK (
    withholding_status <> 'not_applicable' OR withholding_rate = 0
  ),
  -- LA IMPORTANTE: un perfil no se aprueba con la retencion sin decidir. Es lo
  -- que convierte el olvido en un bloqueo visible en vez de un pago silencioso.
  CONSTRAINT ck_ctp_withholding_decided CHECK (
    status <> 'approved' OR withholding_status <> 'pending_review'
  ),
  -- Segregacion de funciones, igual que DEC-044: quien captura el dato fiscal
  -- no es quien lo aprueba.
  -- La rama `created_by_user_id IS NULL` desaparecio con H-03: ahora la columna
  -- es NOT NULL, asi que la restriccion se simplifica *porque* el modelo se
  -- volvio mas estricto. Queda igual que `ck_pbatch_segregation`.
  CONSTRAINT ck_ctp_segregation CHECK (
    approved_by_user_id IS NULL OR approved_by_user_id <> created_by_user_id
  ),
  -- Aprobado exige quien y cuando.
  CONSTRAINT ck_ctp_approval CHECK (
    status <> 'approved' OR (approved_by_user_id IS NOT NULL AND approved_at IS NOT NULL)
  ),
  CONSTRAINT ck_ctp_dates CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================== D6: medios de pago del creador
-- NUNCA se guarda el numero de cuenta en claro. Tres columnas y ninguna legible:
--   _encrypted   -> el valor cifrado por la aplicacion (clave fuera de la BD)
--   _masked      -> lo unico que se muestra en pantalla ("****4321")
--   _fingerprint -> HMAC-SHA256, para detectar que dos creadores comparten cuenta
--                   SIN descifrar nada. Senal de fraude, no error: por eso indice
--                   normal y no unico.
CREATE TABLE creator_payment_methods (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                  CHAR(36)      NOT NULL,
  creator_id            BIGINT UNSIGNED NOT NULL,
  -- 2.3 §10: si el beneficiario es el tutor, la cuenta es del tutor. Sin esto
  -- BR-FIN-003 validaba el medio de pago de la persona equivocada.
  owner_type            VARCHAR(10)   NOT NULL DEFAULT 'creator',
  owner_guardian_id     BIGINT UNSIGNED NULL,
  method_type           VARCHAR(20)   NOT NULL,
  country_id            BIGINT UNSIGNED NOT NULL,
  currency_code         CHAR(3)       NOT NULL,
  bank_name             VARCHAR(80)   NULL,
  account_type          VARCHAR(15)   NULL,
  account_number_encrypted  TEXT      NOT NULL,
  account_number_masked     VARCHAR(30) NOT NULL,
  account_number_fingerprint CHAR(64) NOT NULL,
  holder_name           VARCHAR(160)  NOT NULL,
  holder_document_type  VARCHAR(20)   NOT NULL,
  holder_document_number VARCHAR(40)  NOT NULL,
  status                VARCHAR(15)   NOT NULL DEFAULT 'pending',
  verified_at           DATETIME(3)   NULL,
  verified_by_user_id   BIGINT UNSIGNED NULL,
  -- BR-FIN-006: periodo de enfriamiento. Un medio nuevo o modificado no es
  -- elegible para pagos hasta esta fecha, aunque ya este verificado.
  eligible_from         DATETIME(3)   NULL,
  is_default            TINYINT(1)    NOT NULL DEFAULT 0,
  created_at            DATETIME(3)   NULL,
  updated_at            DATETIME(3)   NULL,
  default_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN is_default = 1 THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_cpm_uuid (uuid),
  UNIQUE KEY uq_cpm_default (default_gate, creator_id),
  KEY ix_cpm_creator (creator_id, status),
  KEY ix_cpm_fingerprint (account_number_fingerprint),
  KEY ix_cpm_guardian (owner_guardian_id),
  KEY ix_cpm_country (country_id),
  KEY ix_cpm_currency (currency_code),
  KEY ix_cpm_verifier (verified_by_user_id),
  CONSTRAINT fk_cpm_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cpm_guardian FOREIGN KEY (owner_guardian_id) REFERENCES creator_guardians(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cpm_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cpm_currency FOREIGN KEY (currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT fk_cpm_verifier FOREIGN KEY (verified_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_cpm_status CHECK (status IN ('pending','verified','rejected','disabled')),
  CONSTRAINT ck_cpm_method CHECK (method_type IN ('bank_account','wallet','paypal','other')),
  CONSTRAINT ck_cpm_owner CHECK (
    (owner_type = 'creator'  AND owner_guardian_id IS NULL) OR
    (owner_type = 'guardian' AND owner_guardian_id IS NOT NULL)
  ),
  CONSTRAINT ck_cpm_verified CHECK (
    status <> 'verified' OR (verified_at IS NOT NULL AND verified_by_user_id IS NOT NULL)
  ),
  -- Un medio en claro no pasa: la mascara nunca puede contener mas de 4 digitos.
  CONSTRAINT ck_cpm_masked CHECK (CHAR_LENGTH(account_number_masked) <= 30),
  CONSTRAINT ck_cpm_fingerprint CHECK (CHAR_LENGTH(account_number_fingerprint) = 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================ D6: comprobante que entrega el creador
CREATE TABLE creator_tax_documents (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                CHAR(36)      NOT NULL,
  creator_id          BIGINT UNSIGNED NOT NULL,
  -- BR-CREATOR-010: si el creador es menor, el documento lo emite el tutor.
  issued_by_guardian_id BIGINT UNSIGNED NULL,
  document_type       VARCHAR(30)   NOT NULL,
  series              VARCHAR(10)   NOT NULL,
  number              VARCHAR(20)   NOT NULL,
  -- DATE y no DATETIME: la fecha de emision es un DIA en el pais del emisor
  -- (docs 2.3 §8). En UTC, un comprobante de fin de mes cae en el periodo malo.
  issue_date          DATE          NOT NULL,
  currency_code       CHAR(3)       NOT NULL,
  gross_amount        DECIMAL(18,4) NOT NULL,
  withholding_amount  DECIMAL(18,4) NOT NULL DEFAULT 0,
  net_amount          DECIMAL(18,4) NOT NULL,
  file_id             BIGINT UNSIGNED NULL,
  status              VARCHAR(15)   NOT NULL DEFAULT 'received',
  validated_by_user_id BIGINT UNSIGNED NULL,
  validated_at        DATETIME(3)   NULL,
  rejection_note      VARCHAR(255)  NULL,
  created_at          DATETIME(3)   NULL,
  updated_at          DATETIME(3)   NULL,
  UNIQUE KEY uq_ctd_uuid (uuid),
  -- El mismo emisor no puede entregar dos veces la misma serie y numero.
  UNIQUE KEY uq_ctd_number (creator_id, document_type, series, number),
  KEY ix_ctd_creator (creator_id, status),
  KEY ix_ctd_issue_date (issue_date),
  KEY ix_ctd_guardian (issued_by_guardian_id),
  KEY ix_ctd_file (file_id),
  KEY ix_ctd_currency (currency_code),
  KEY ix_ctd_validator (validated_by_user_id),
  CONSTRAINT fk_ctd_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ctd_guardian FOREIGN KEY (issued_by_guardian_id) REFERENCES creator_guardians(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ctd_currency FOREIGN KEY (currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT fk_ctd_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ctd_validator FOREIGN KEY (validated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_ctd_status CHECK (status IN ('received','validated','rejected')),
  CONSTRAINT ck_ctd_type CHECK (document_type IN ('recibo_honorarios','factura','invoice','other')),
  CONSTRAINT ck_ctd_amounts CHECK (gross_amount >= 0 AND withholding_amount >= 0 AND net_amount >= 0),
  -- La aritmetica del comprobante la comprueba la base, no el que lo teclea.
  CONSTRAINT ck_ctd_math CHECK (net_amount = gross_amount - withholding_amount),
  CONSTRAINT ck_ctd_validated CHECK (
    status <> 'validated' OR (validated_by_user_id IS NOT NULL AND validated_at IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
