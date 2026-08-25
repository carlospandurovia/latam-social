-- LATAM Social - Fase 2, iteracion 2.11 - Campana. El corazon del sistema.
SET NAMES utf8mb4;

CREATE TABLE campaigns (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                 CHAR(36)      NOT NULL,
  code                 VARCHAR(20)   NOT NULL,
  name                 VARCHAR(180)  NOT NULL,
  client_organization_id BIGINT UNSIGNED NOT NULL,
  client_brand_id      BIGINT UNSIGNED NOT NULL,
  -- 7.1 / BR-LE-001: la campana DICE quien la factura, no se deduce de la
  -- cobertura vigente al consultar. NULL-able porque un borrador puede estar
  -- todavia escribiendose; `ck_camp_billing_entity` impide que salga de ahi asi.
  billing_legal_entity_id BIGINT UNSIGNED NULL,
  objective            VARCHAR(30)   NOT NULL DEFAULT 'awareness',
  briefing             LONGTEXT      NULL,
  briefing_file_id     BIGINT UNSIGNED NULL,
  status               VARCHAR(20)   NOT NULL DEFAULT 'draft',
  -- Lo que se le cobra al cliente. El costo de creadores es SUMA de las
  -- participaciones y el margen es cache reconstruible (2.3 §5): aqui no van.
  revenue_amount       DECIMAL(18,4) NOT NULL DEFAULT 0,
  currency_code        CHAR(3)       NOT NULL,
  -- El negocio lo fijo: 2 rondas de correccion incluidas en el precio.
  included_revision_rounds TINYINT UNSIGNED NOT NULL DEFAULT 2,
  -- Edad minima efectiva. Por defecto se hereda de las categorias del brief
  -- (BR-CREATOR-012), pero se puede endurecer por campana.
  min_creator_age      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  starts_on            DATE          NOT NULL,
  ends_on              DATE          NOT NULL,
  publication_deadline DATE          NULL,
  confirmed_at         DATETIME(3)   NULL,
  closed_at            DATETIME(3)   NULL,
  created_by_user_id   BIGINT UNSIGNED NULL,
  created_at           DATETIME(3)   NULL,
  updated_at           DATETIME(3)   NULL,
  UNIQUE KEY uq_camp_uuid (uuid),
  UNIQUE KEY uq_camp_code (code),
  KEY ix_camp_client (client_organization_id, status),
  KEY ix_camp_brand (client_brand_id, status),
  KEY ix_camp_legal_entity (billing_legal_entity_id),
  KEY ix_camp_status (status, starts_on),
  KEY ix_camp_currency (currency_code),
  KEY ix_camp_creator_user (created_by_user_id),
  KEY ix_camp_file (briefing_file_id),
  CONSTRAINT fk_camp_client FOREIGN KEY (client_organization_id) REFERENCES client_organizations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_camp_brand FOREIGN KEY (client_brand_id) REFERENCES client_brands(id) ON DELETE RESTRICT,
  CONSTRAINT fk_camp_legal_entity FOREIGN KEY (billing_legal_entity_id) REFERENCES legal_entities(id) ON DELETE RESTRICT,
  CONSTRAINT fk_camp_currency FOREIGN KEY (currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT fk_camp_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_camp_file FOREIGN KEY (briefing_file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT ck_camp_status CHECK (status IN ('draft','pending_approval','approved','recruiting','in_progress','in_review','completed','cancelled')),
  CONSTRAINT ck_camp_objective CHECK (objective IN ('awareness','consideration','conversion','ugc','launch','event')),
  CONSTRAINT ck_camp_dates CHECK (ends_on >= starts_on),
  CONSTRAINT ck_camp_revenue CHECK (revenue_amount >= 0),
  CONSTRAINT ck_camp_rounds CHECK (included_revision_rounds BETWEEN 0 AND 10),
  -- Confirmada exige fecha: es el instante a partir del cual ya no se puede borrar.
  CONSTRAINT ck_camp_confirmed CHECK (status IN ('draft','pending_approval','cancelled') OR confirmed_at IS NOT NULL),
  CONSTRAINT ck_camp_billing_entity CHECK (status IN ('draft','pending_approval','cancelled') OR billing_legal_entity_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los mercados de la campana. Una campana LATAM tiene varios.
CREATE TABLE campaign_markets (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_id  BIGINT UNSIGNED NOT NULL,
  country_id   BIGINT UNSIGNED NOT NULL,
  target_creators SMALLINT UNSIGNED NULL,
  created_at   DATETIME(3)   NULL,
  updated_at   DATETIME(3)   NULL,
  UNIQUE KEY uq_cm_campaign_country (campaign_id, country_id),
  KEY ix_cm_country (country_id),
  CONSTRAINT fk_cm_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cm_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.3 N-03: campaign_market_id NULL = todos los mercados. Si existe alguno
-- para un mercado, REEMPLAZA al general para ese mercado, no se mezcla.
CREATE TABLE campaign_requirements (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_id        BIGINT UNSIGNED NOT NULL,
  campaign_market_id BIGINT UNSIGNED NULL,
  content_format_id  BIGINT UNSIGNED NOT NULL,
  quantity           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  deadline_offset_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  -- Cuanto debe seguir publicado. El negocio lo pidio por campana y por red.
  permanence_days    SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  notes              VARCHAR(255)  NULL,
  created_at         DATETIME(3)   NULL,
  updated_at         DATETIME(3)   NULL,
  -- DOS indices unicos, y hacen falta los dos.
  --
  -- El primero cubre los requisitos DE MERCADO. No cubre los generales: con
  -- campaign_market_id NULL el indice unico no se aplica, porque NULL no
  -- colisiona con NULL. Es el mismo comportamiento que aprovecho a proposito en
  -- las columnas puerta, y aqui juega en contra: este es el unico sitio del
  -- modelo donde NULL SIGNIFICA algo ("todos los mercados", 2.3 §9) en vez de
  -- "no aplica". Ese es justo el precio de aquella excepcion.
  UNIQUE KEY uq_creq_market (campaign_id, campaign_market_id, content_format_id),
  -- El segundo cubre los generales, invirtiendo la puerta: vale 1 cuando NO hay
  -- mercado, que es cuando el indice de arriba se desentiende.
  general_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN campaign_market_id IS NULL THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_creq_general (general_gate, campaign_id, content_format_id),
  KEY ix_creq_market (campaign_market_id),
  KEY ix_creq_format (content_format_id),
  CONSTRAINT fk_creq_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_creq_market FOREIGN KEY (campaign_market_id) REFERENCES campaign_markets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_creq_format FOREIGN KEY (content_format_id) REFERENCES content_formats(id) ON DELETE RESTRICT,
  CONSTRAINT ck_creq_quantity CHECK (quantity >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La participacion: donde vive el compromiso economico CONGELADO.
CREATE TABLE campaign_creators (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid               CHAR(36)      NOT NULL,
  campaign_id        BIGINT UNSIGNED NOT NULL,
  creator_id         BIGINT UNSIGNED NOT NULL,
  campaign_market_id BIGINT UNSIGNED NULL,
  status             VARCHAR(20)   NOT NULL DEFAULT 'shortlisted',
  -- BR-CREATOR-008: la tarifa declarada es referencia; ESTO es el compromiso.
  agreed_amount      DECIMAL(18,4) NOT NULL DEFAULT 0,
  currency_code      CHAR(3)       NOT NULL,
  -- 2.3 §3: el beneficiario se congela AL ACEPTAR, no al pagar. Si el creador
  -- cumple 18 a mitad de campana, cobra quien firmo.
  payee_type         VARCHAR(10)   NOT NULL DEFAULT 'creator',
  payee_guardian_id  BIGINT UNSIGNED NULL,
  -- BR-FIN-012: el plazo se congela tambien, para que cambiarlo despues no
  -- altere lo que se prometio a quien ya acepto.
  payment_term_days_snapshot SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  revision_rounds_used TINYINT UNSIGNED NOT NULL DEFAULT 0,
  invited_at         DATETIME(3)   NULL,
  accepted_at        DATETIME(3)   NULL,
  declined_at        DATETIME(3)   NULL,
  completed_at       DATETIME(3)   NULL,
  created_at         DATETIME(3)   NULL,
  updated_at         DATETIME(3)   NULL,
  UNIQUE KEY uq_ccr_uuid (uuid),
  -- Un creador participa UNA vez en una campana.
  UNIQUE KEY uq_ccr_campaign_creator (campaign_id, creator_id),
  KEY ix_ccr_campaign_status (campaign_id, status),
  KEY ix_ccr_creator (creator_id, status),
  KEY ix_ccr_market (campaign_market_id),
  KEY ix_ccr_guardian (payee_guardian_id),
  KEY ix_ccr_currency (currency_code),
  CONSTRAINT fk_ccr_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ccr_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ccr_market FOREIGN KEY (campaign_market_id) REFERENCES campaign_markets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ccr_guardian FOREIGN KEY (payee_guardian_id) REFERENCES creator_guardians(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ccr_currency FOREIGN KEY (currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT ck_cc_status CHECK (status IN ('shortlisted','invited','accepted','declined','expired','in_production','delivered','approved','published','verified','completed','cancelled')),
  CONSTRAINT ck_cc_amount CHECK (agreed_amount >= 0),
  -- El mismo par que en los medios de pago, y por la misma razon.
  CONSTRAINT ck_cc_payee CHECK (
    (payee_type = 'creator'  AND payee_guardian_id IS NULL) OR
    (payee_type = 'guardian' AND payee_guardian_id IS NOT NULL)
  ),
  -- Aceptada exige fecha de aceptacion: es el instante que congela el acuerdo.
  CONSTRAINT ck_cc_accepted CHECK (
    status IN ('shortlisted','invited','declined','expired','cancelled') OR accepted_at IS NOT NULL
  ),
  CONSTRAINT ck_cc_declined CHECK (status <> 'declined' OR declined_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.2 P-04: la invitacion es entidad propia. Se envia, expira, se reenvia por
-- otro canal. Cuantas veces hubo que insistir alimenta el Creator Score.
CREATE TABLE invitations (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                CHAR(36)      NOT NULL,
  campaign_creator_id BIGINT UNSIGNED NOT NULL,
  channel             VARCHAR(15)   NOT NULL DEFAULT 'email',
  -- Token de acceso al enlace firmado. Se guarda su HASH, nunca el token.
  token_hash          CHAR(64)      NOT NULL,
  sent_at             DATETIME(3)   NOT NULL,
  expires_at          DATETIME(3)   NOT NULL,
  opened_at           DATETIME(3)   NULL,
  responded_at        DATETIME(3)   NULL,
  response            VARCHAR(10)   NULL,
  created_at          DATETIME(3)   NULL,
  UNIQUE KEY uq_inv_uuid (uuid),
  UNIQUE KEY uq_inv_token (token_hash),
  KEY ix_inv_participation (campaign_creator_id, sent_at),
  KEY ix_inv_expires (expires_at, responded_at),
  CONSTRAINT fk_inv_participation FOREIGN KEY (campaign_creator_id) REFERENCES campaign_creators(id) ON DELETE RESTRICT,
  CONSTRAINT ck_inv_channel CHECK (channel IN ('email','whatsapp','sms','in_app','manual')),
  CONSTRAINT ck_inv_response CHECK (response IS NULL OR response IN ('accepted','declined')),
  CONSTRAINT ck_inv_dates CHECK (expires_at > sent_at),
  CONSTRAINT ck_inv_responded CHECK ((response IS NULL) = (responded_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- BR-CAMPAIGN-003: cambiar monto, entregables o fechas es una ENMIENDA que las
-- dos partes aceptan. Append-only: el valor vigente vive en la participacion,
-- el porque vive aqui (2.2 P-08).
CREATE TABLE agreement_amendments (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                CHAR(36)      NOT NULL,
  campaign_creator_id BIGINT UNSIGNED NOT NULL,
  field               VARCHAR(40)   NOT NULL,
  old_value           VARCHAR(255)  NULL,
  new_value           VARCHAR(255)  NOT NULL,
  reason              VARCHAR(255)  NULL,
  proposed_by         VARCHAR(10)   NOT NULL,
  proposed_by_user_id BIGINT UNSIGNED NULL,
  proposed_at         DATETIME(3)   NOT NULL,
  accepted_at         DATETIME(3)   NULL,
  accepted_by_user_id BIGINT UNSIGNED NULL,
  rejected_at         DATETIME(3)   NULL,
  UNIQUE KEY uq_aa_uuid (uuid),
  KEY ix_aa_participation (campaign_creator_id, proposed_at),
  KEY ix_aa_proposer (proposed_by_user_id),
  KEY ix_aa_accepter (accepted_by_user_id),
  CONSTRAINT fk_aa_participation FOREIGN KEY (campaign_creator_id) REFERENCES campaign_creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_aa_proposer FOREIGN KEY (proposed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_aa_accepter FOREIGN KEY (accepted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_aa_field CHECK (field IN ('agreed_amount','deliverables','deadline','permanence','other')),
  CONSTRAINT ck_aa_proposer CHECK (proposed_by IN ('platform','creator','client')),
  -- Aceptada y rechazada a la vez, no.
  CONSTRAINT ck_aa_outcome CHECK (accepted_at IS NULL OR rejected_at IS NULL),
  CONSTRAINT ck_aa_accepted CHECK (accepted_at IS NULL OR accepted_by_user_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 7.1 - La sociedad que factura una campana confirmada no se cambia.
--
-- `BR-LE-002`: la entidad legal de un documento es inmutable una vez emitido.
-- Para una campana, «emitido» se decidio que es CONFIRMADA (decision de
-- negocio, 2026-08-25): mientras es borrador se puede corregir un dedazo, y en
-- cuanto tiene `confirmed_at` se cierra.
--
-- Es un disparador y no un CHECK porque un CHECK no puede mirar el valor
-- ANTERIOR de la fila: «no cambiar esto» es una regla sobre la transicion, no
-- sobre el estado.
--
-- Y no vale dejarlo en el controlador. De este dato depende que una factura de
-- dentro de dos anos siga sabiendo quien la emitio: tiene que sobrevivir a un
-- UPDATE de mantenimiento, a una importacion y a la proxima pantalla que
-- alguien escriba sin acordarse. Mismo criterio que `tg_cpm_inmutable`.
--
-- `<=>` y no `<>`: con `<>`, pasar de una sociedad a NULL da NULL --que no es
-- cierto-- y el disparador dejaria pasar justo el caso de borrar el dato.
-- ===========================================================================

DELIMITER //

CREATE TRIGGER `tg_camp_entidad_congelada`
BEFORE UPDATE ON `campaigns`
FOR EACH ROW
BEGIN
  IF OLD.confirmed_at IS NOT NULL
     AND NOT (NEW.billing_legal_entity_id <=> OLD.billing_legal_entity_id)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'La sociedad que factura una campana confirmada no se cambia (BR-LE-002): anule la campana y cree otra.';
  END IF;
END//

DELIMITER ;
