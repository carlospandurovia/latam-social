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
  -- 7.2 / BR-CAMPAIGN-004: el mismo cero responde a dos preguntas distintas
  -- --«esta campana se regala» y «nadie le ha puesto precio»-- y de ahi sale el
  -- margen. `ck_camp_revenue_declarado` obliga a elegir una, fuera de borrador.
  is_gratis            TINYINT(1)    NOT NULL DEFAULT 0,
  -- 7.6: cuantas horas tiene un creador para contestar una invitacion. El
  -- plazo vive en la CAMPANA y no en cada invitacion: un solo numero que
  -- explicar y un solo sitio donde cambiarlo.
  invitation_hours     SMALLINT UNSIGNED NOT NULL DEFAULT 72,
  -- 7.5 / BR-CAMPAIGN-005: el OTRO lado del margen. La regla nombraba «el
  -- presupuesto de creadores de la campana» y esta columna no existia: no es que
  -- nadie comprobara la regla, es que el dato que nombra no estaba en el modelo.
  creator_budget_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
  -- La autorizacion de finanzas para pasarse. «Que queda auditada», dice la
  -- regla, asi que es un dato de la fila: quien, cuando y por que.
  budget_override_by_user_id BIGINT UNSIGNED NULL,
  budget_override_at   DATETIME(3)   NULL,
  budget_override_reason VARCHAR(255) NULL,
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
  KEY fk_camp_budget_override (budget_override_by_user_id),
  CONSTRAINT fk_camp_client FOREIGN KEY (client_organization_id) REFERENCES client_organizations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_camp_brand FOREIGN KEY (client_brand_id) REFERENCES client_brands(id) ON DELETE RESTRICT,
  CONSTRAINT fk_camp_legal_entity FOREIGN KEY (billing_legal_entity_id) REFERENCES legal_entities(id) ON DELETE RESTRICT,
  CONSTRAINT fk_camp_currency FOREIGN KEY (currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT fk_camp_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_camp_file FOREIGN KEY (briefing_file_id) REFERENCES files(id) ON DELETE RESTRICT,
  -- Va con las demas foraneas y NO entre los CHECK: `generar-triggers.py` quita
  -- las clausulas CHECK para simular Percona 5.7, y una foranea intercalada
  -- entre ellas se quedaba huerfana de coma. El sintoma fue un 1064 al cargar la
  -- base sin-CHECK, no en la de desarrollo.
  CONSTRAINT fk_camp_budget_override FOREIGN KEY (budget_override_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_camp_status CHECK (status IN ('draft','pending_approval','approved','recruiting','in_progress','in_review','completed','cancelled')),
  CONSTRAINT ck_camp_objective CHECK (objective IN ('awareness','consideration','conversion','ugc','launch','event')),
  CONSTRAINT ck_camp_dates CHECK (ends_on >= starts_on),
  CONSTRAINT ck_camp_revenue CHECK (revenue_amount >= 0),
  CONSTRAINT ck_camp_rounds CHECK (included_revision_rounds BETWEEN 0 AND 10),
  -- Confirmada exige fecha: es el instante a partir del cual ya no se puede borrar.
  CONSTRAINT ck_camp_confirmed CHECK (status IN ('draft','pending_approval','cancelled') OR confirmed_at IS NOT NULL),
  CONSTRAINT ck_camp_billing_entity CHECK (status IN ('draft','pending_approval','cancelled') OR billing_legal_entity_id IS NOT NULL),
  -- Fuera de borrador el cero se declara: o hay importe, o alguien dijo que se
  -- regala. `ck_camp_revenue` (>= 0) se queda: esta es la otra mitad, no su
  -- sustituta.
  CONSTRAINT ck_camp_revenue_declarado CHECK (status IN ('draft','pending_approval','cancelled') OR (is_gratis = 1 AND revenue_amount = 0) OR (is_gratis = 0 AND revenue_amount > 0)),
  CONSTRAINT ck_camp_creator_budget CHECK (creator_budget_amount >= 0),
  -- De una hora a treinta dias. El limite de abajo evita una invitacion que
  -- nace caducada; el de arriba, el teclazo que deja un compromiso economico
  -- abierto tres anos.
  CONSTRAINT ck_camp_invitation_hours CHECK (invitation_hours BETWEEN 1 AND 720),
  -- Las tres columnas de la autorizacion van juntas o no van. Una firma sin
  -- explicacion no responde «por que esta campana se paso» dentro de un ano.
  -- Misma forma que `ck_inv_responded`.
  CONSTRAINT ck_camp_budget_override CHECK ((budget_override_at IS NULL AND budget_override_by_user_id IS NULL AND budget_override_reason IS NULL) OR (budget_override_at IS NOT NULL AND budget_override_by_user_id IS NOT NULL AND budget_override_reason IS NOT NULL))
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
  -- 7.3: redundante como clave --`id` ya es PRIMARY-- y necesaria como DESTINO.
  -- MySQL exige que las columnas referidas por una foranea sean prefijo de
  -- algun indice, y de aqui cuelgan las foraneas COMPUESTAS que impiden que un
  -- requisito o una participacion apunten al mercado de otra campana.
  UNIQUE KEY uq_cm_id_campaign (id, campaign_id),
  KEY ix_cm_country (country_id),
  CONSTRAINT fk_cm_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cm_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE RESTRICT,
  -- 7.3: NULL es «sin cupo fijado» y es legitimo. Cero no dice nada: «corre en
  -- Colombia con cero creadores» no es un objetivo, es un mercado de mas.
  CONSTRAINT ck_cm_target CHECK (target_creators IS NULL OR target_creators >= 1)
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
  -- 8.1: lo que el caption tiene que llevar. 7.2 los dejo fuera a proposito
  -- --«sin mercados un requisito no se puede partir por pais»-- y 7.3 trajo
  -- los mercados, asi que ya se puede pedir un hashtag distinto por pais.
  -- Texto separado por espacios y no tabla aparte: nadie va a consultar
  -- «campanas que usaron #verano» desde aqui.
  hashtags           VARCHAR(255)  NULL,
  mentions           VARCHAR(255)  NULL,
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
  -- 7.3: COMPUESTA. Una foranea a `campaign_markets(id)` a secas solo comprueba
  -- que el mercado exista, no que sea de ESTA campana: un requisito de la
  -- campana A podia colgar del mercado «Mexico» de la campana B. Y el NULL con
  -- significado sobrevive: en MySQL una foranea compuesta con un componente
  -- NULL no se comprueba, asi que «todos los mercados» pasa igual que antes.
  CONSTRAINT fk_creq_market_campaign FOREIGN KEY (campaign_market_id, campaign_id) REFERENCES campaign_markets(id, campaign_id) ON DELETE RESTRICT,
  CONSTRAINT fk_creq_format FOREIGN KEY (content_format_id) REFERENCES content_formats(id) ON DELETE RESTRICT,
  CONSTRAINT ck_creq_quantity CHECK (quantity >= 1),
  -- 7.3 / T-33: hasta aqui los acotaba SOLO el formulario, y una regla que solo
  -- vive en la pantalla se la salta cualquier importacion. `permanence_days` es
  -- lo que se le exige al creador: un 100.000 son 273 anos.
  CONSTRAINT ck_creq_deadline CHECK (deadline_offset_days BETWEEN 0 AND 365),
  CONSTRAINT ck_creq_permanence CHECK (permanence_days BETWEEN 0 AND 3650)
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
  -- 9.18: 'gross' = lo pactado es el costo (lo de siempre); 'net' = lo pactado
  -- es lo que RECIBE el creador y `agreed_amount` es el bruto calculado. Las
  -- filas anteriores son 'gross' porque nadie dijo lo contrario.
  agreed_basis       VARCHAR(10)   NOT NULL DEFAULT 'gross',
  agreed_net_amount  DECIMAL(18,4) NULL,
  -- Copias de la politica vigente EL DIA EN QUE SE PACTO. Mismo criterio que
  -- `payment_term_days_snapshot` (BR-FIN-012): subir manana el umbral no puede
  -- convertir en mala una participacion que se juzgo buena con el de hoy.
  withholding_rate_snapshot DECIMAL(7,4) NULL,
  min_margin_pct_snapshot   DECIMAL(7,4) NULL,
  margin_basis_snapshot     VARCHAR(10)  NULL,
  currency_code      CHAR(3)       NOT NULL,
  -- 2.3 §3: el beneficiario se congela AL ACEPTAR, no al pagar. Si el creador
  -- cumple 18 a mitad de campana, cobra quien firmo.
  payee_type         VARCHAR(10)   NOT NULL DEFAULT 'creator',
  payee_guardian_id  BIGINT UNSIGNED NULL,
  -- BR-FIN-012: el plazo se congela tambien, para que cambiarlo despues no
  -- altere lo que se prometio a quien ya acepto.
  payment_term_days_snapshot SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  -- 8.3: `revision_rounds_used` se fue de aqui. Las rondas incluidas en el
  -- precio son POR ENTREGABLE --dos correcciones sobre un reel no pueden
  -- dejar sin ninguna a las otras cuatro piezas-- asi que el contador vive
  -- en `deliverables`. La suma por creador, la que alimenta el Creator
  -- Score, sale de `content_reviews` con un SUM() que nunca se desvia.
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
  -- 7.3: COMPUESTA, por lo mismo que en `campaign_requirements`. Aqui pesa mas:
  -- un creador aceptado apuntando al mercado de otra campana es un pago que se
  -- atribuye al pais equivocado.
  CONSTRAINT fk_ccr_market_campaign FOREIGN KEY (campaign_market_id, campaign_id) REFERENCES campaign_markets(id, campaign_id) ON DELETE RESTRICT,
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
  CONSTRAINT ck_cc_declined CHECK (status <> 'declined' OR declined_at IS NOT NULL),
  -- 9.18: se pacta el costo o se pacta el neto del creador, no una tercera cosa.
  CONSTRAINT ck_ccr_base CHECK (agreed_basis IN ('gross','net')),
  -- El neto NUNCA pasa del costo: la retencion no puede ser negativa.
  CONSTRAINT ck_ccr_neto CHECK (agreed_net_amount IS NULL OR (agreed_net_amount >= 0 AND agreed_net_amount <= agreed_amount)),
  -- Media pactacion no vale: sin la tasa nadie puede rehacer la cuenta.
  CONSTRAINT ck_ccr_neto_completo CHECK (agreed_basis <> 'net' OR (agreed_net_amount IS NOT NULL AND withholding_rate_snapshot IS NOT NULL)),
  -- La resta, rehecha por el motor. Un centimo de tolerancia porque
  -- neto = bruto x (100 - tasa) / 100 no cae exacto en DECIMAL(18,4).
  CONSTRAINT ck_ccr_neto_cuadra CHECK (agreed_basis <> 'net' OR ABS(agreed_amount * (100 - withholding_rate_snapshot) / 100 - agreed_net_amount) <= 0.01)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.2 P-04: la invitacion es entidad propia. Se envia, expira, se reenvia por
-- otro canal. Cuantas veces hubo que insistir alimenta el Creator Score.
CREATE TABLE invitations (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                CHAR(36)      NOT NULL,
  campaign_creator_id BIGINT UNSIGNED NOT NULL,
  -- 7.6: quien invito. Una invitacion es un compromiso economico con una
  -- persona; «alguien la mando» no es una respuesta.
  invited_by_user_id  BIGINT UNSIGNED NULL,
  channel             VARCHAR(15)   NOT NULL DEFAULT 'email',
  -- Token de acceso al enlace firmado. Se guarda su HASH, nunca el token.
  token_hash          CHAR(64)      NOT NULL,
  sent_at             DATETIME(3)   NOT NULL,
  expires_at          DATETIME(3)   NOT NULL,
  -- 7.6: el importe CON EL QUE SALIO la invitacion.
  --
  -- BR-CREATOR-008 congela el precio al ACEPTAR (tg_ccr_compromiso, 7.5), y
  -- entre el envio y la respuesta `agreed_amount` se podia mover: al creador le
  -- llegaba «te pagamos 1.500», alguien lo bajaba a 900, y el creador aceptaba
  -- 900 sin haberlo visto nunca. Aqui queda lo que se le ofrecio, y
  -- `tg_ccr_monto_con_invitacion` impide moverlo mientras la invitacion viva.
  amount_snapshot     DECIMAL(18,4) NOT NULL DEFAULT 0,
  currency_snapshot   CHAR(3)       NULL,
  opened_at           DATETIME(3)   NULL,
  responded_at        DATETIME(3)   NULL,
  response            VARCHAR(10)   NULL,
  -- Lista cerrada para poder contestar «.por que nos dicen que no?». No decide
  -- quien se puede reinvitar: eso se descarto expresamente.
  decline_reason      VARCHAR(20)   NULL,
  decline_note        VARCHAR(255)  NULL,
  responded_ip        VARBINARY(16) NULL,
  -- Anulada: la sustituyo otra, o el comando de caducidad la cerro. NO es lo
  -- mismo que contestada, y por eso son dos columnas (misma leccion que
  -- `password_links` en 5.9).
  revoked_at          DATETIME(3)   NULL,
  revoked_reason      VARCHAR(40)   NULL,
  created_at          DATETIME(3)   NULL,
  updated_at          DATETIME(3)   NULL,
  -- La decimocuarta puerta: una invitacion VIVA por participacion.
  viva_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN responded_at IS NULL AND revoked_at IS NULL THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_inv_uuid (uuid),
  UNIQUE KEY uq_inv_token (token_hash),
  KEY ix_inv_participation (campaign_creator_id, sent_at),
  KEY ix_inv_expires (expires_at, responded_at),
  KEY ix_inv_invitador (invited_by_user_id),
  UNIQUE KEY uq_inv_viva (viva_gate, campaign_creator_id),
  CONSTRAINT fk_inv_participation FOREIGN KEY (campaign_creator_id) REFERENCES campaign_creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_inv_invitador FOREIGN KEY (invited_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_inv_channel CHECK (channel IN ('email','whatsapp','sms','in_app','manual')),
  CONSTRAINT ck_inv_response CHECK (response IS NULL OR response IN ('accepted','declined')),
  CONSTRAINT ck_inv_dates CHECK (expires_at > sent_at),
  CONSTRAINT ck_inv_responded CHECK ((response IS NULL) = (responded_at IS NULL)),
  CONSTRAINT ck_inv_decline CHECK ((response = 'declined' AND decline_reason IS NOT NULL) OR (response <> 'declined' AND decline_reason IS NULL) OR (response IS NULL AND decline_reason IS NULL)),
  CONSTRAINT ck_inv_reason_valido CHECK (decline_reason IS NULL OR decline_reason IN ('amount','dates','brand','workload','other')),
  CONSTRAINT ck_inv_responded_ip CHECK (responded_at IS NULL OR responded_ip IS NOT NULL),
  CONSTRAINT ck_inv_revoked CHECK (revoked_at IS NULL OR revoked_reason IS NOT NULL),
  CONSTRAINT ck_inv_terminal CHECK (responded_at IS NULL OR revoked_at IS NULL),
  CONSTRAINT ck_inv_amount CHECK (amount_snapshot >= 0)
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

-- ===========================================================================
-- 7.3 / BR-CAMPAIGN-003: de una campana confirmada se ANADE un mercado, no se
-- quita.
--
-- Ampliar a un pais nuevo es una decision comercial normal y no rompe nada de
-- lo prometido. Quitar si: puede dejar fuera a creadores ya invitados o
-- aceptados, y eso exige una enmienda aceptada por las dos partes.
--
-- Mira `campaigns` y no una columna propia porque el congelado de un mercado no
-- es un hecho del mercado: es un hecho de la campana a la que pertenece.
-- ===========================================================================

CREATE TRIGGER `tg_cm_no_quitar_confirmada`
BEFORE DELETE ON `campaign_markets`
FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM `campaigns`
              WHERE `id` = OLD.`campaign_id` AND `confirmed_at` IS NOT NULL)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'De una campana confirmada no se quita un mercado (BR-CAMPAIGN-003): deja fuera a creadores ya invitados. Anadir si se puede.';
  END IF;
END//

-- ===========================================================================
-- 7.4: nadie entra en una campana CERRADA, y una participacion que ya estaba
-- solo se puede cancelar.
--
-- `campaign_creators` existe desde la Fase 2 y hasta 7.4 nadie habia escrito una
-- fila. Una participacion en una campana terminada devenga en el ledger contra
-- un periodo ya liquidado, sale en el reporte «reproducible» del cliente y
-- cuenta en el Creator Score por un trabajo que nunca existio.
--
-- Disparador y no CHECK porque la condicion esta en OTRA tabla (`campaigns`).
-- Y tambien en UPDATE porque cerrar la campana y mover al creador son dos
-- operaciones distintas, y la segunda puede llegar despues.
-- ===========================================================================

CREATE TRIGGER `tg_ccr_campana_cerrada_ins`
BEFORE INSERT ON `campaign_creators`
FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM `campaigns`
              WHERE `id` = NEW.`campaign_id` AND `closed_at` IS NOT NULL)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'No se anaden creadores a una campana cerrada: lo que se entrego ahi ya se conto. Sumar a alguien es una campana nueva.';
  END IF;
END//

CREATE TRIGGER `tg_ccr_campana_cerrada_upd`
BEFORE UPDATE ON `campaign_creators`
FOR EACH ROW
BEGIN
  IF NEW.`status` <> 'cancelled'
     AND NOT (NEW.`status` <=> OLD.`status`)
     AND EXISTS (SELECT 1 FROM `campaigns`
                  WHERE `id` = NEW.`campaign_id` AND `closed_at` IS NOT NULL)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'La participacion de una campana cerrada solo se puede cancelar, no avanzar.';
  END IF;
END//

-- ===========================================================================
-- 7.5 / BR-CREATOR-008: el monto acordado se fija al invitar y se congela al
-- aceptar.
--
-- La tarifa declarada por el creador es una REFERENCIA; el precio vinculante es
-- este numero. De el sale lo que se le paga a una persona, asi que tiene que
-- sobrevivir a un mantenimiento y a la proxima pantalla que alguien escriba.
--
-- Disparador y no CHECK por dos razones: el congelado compara OLD con NEW, que
-- un CHECK no ve; y la segunda regla mira `campaigns.is_gratis`, porque una
-- campana declarada gratuita (7.2) puede llevar creadores por canje y exigirles
-- importe seria contradecir aquella decision.
-- ===========================================================================

CREATE TRIGGER `tg_ccr_monto_con_invitacion`
BEFORE UPDATE ON `campaign_creators`
FOR EACH ROW
BEGIN
  IF NOT (NEW.`agreed_amount` <=> OLD.`agreed_amount`)
     AND EXISTS (SELECT 1 FROM `invitations`
                  WHERE `campaign_creator_id` = OLD.`id` AND `viva_gate` = 1)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'No se cambia el monto con una invitacion viva (BR-CREATOR-008): el creador mira la cifra anterior. Anule esa invitacion.';
  END IF;
END//
CREATE TRIGGER `tg_ccr_compromiso`
BEFORE UPDATE ON `campaign_creators`
FOR EACH ROW
BEGIN
  IF OLD.`accepted_at` IS NOT NULL
     AND NOT (NEW.`agreed_amount` <=> OLD.`agreed_amount`)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'El monto acordado de una participacion aceptada no se cambia (BR-CREATOR-008): exige una enmienda aceptada por las dos partes.';
  END IF;

  IF NEW.`status` NOT IN ('shortlisted', 'cancelled')
     AND NEW.`agreed_amount` <= 0
     AND NOT EXISTS (SELECT 1 FROM `campaigns`
                      WHERE `id` = NEW.`campaign_id` AND `is_gratis` = 1)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'No se invita a un creador sin decirle cuanto se le paga (BR-CREATOR-008). Si la campana es un canje, marquela como gratuita.';
  END IF;
END//

DELIMITER ;

-- T-38: las preguntas del creador antes de contestar una invitacion.
--
-- Sin un sitio donde preguntar, una DUDA se convierte en un RECHAZO, y ese
-- rechazo entra en `invitations.decline_reason` como si fuera una opinion sobre
-- la oferta. La estadistica que 7.6 existe para producir quedaria contaminada
-- por gente que no tenia a quien preguntar.
--
-- NO hay respuesta aqui, y es deliberado: el equipo contesta por correo, que es
-- donde el creador ya esta. Un hilo de ida y vuelta dentro de la aplicacion es
-- un modulo de mensajeria y no cabe en esta iteracion.
CREATE TABLE invitation_questions (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36)        NOT NULL,
  invitation_id   BIGINT UNSIGNED NOT NULL,
  body            VARCHAR(1000)   NOT NULL,
  asked_at        DATETIME(3)     NOT NULL,
  asked_ip        VARBINARY(16)   NULL,
  -- «Alguien del equipo se hizo cargo». No es una respuesta.
  seen_at         DATETIME(3)     NULL,
  seen_by_user_id BIGINT UNSIGNED NULL,
  created_at      DATETIME(3)     NULL,
  updated_at      DATETIME(3)     NULL,
  UNIQUE KEY uq_iq_uuid (uuid),
  KEY ix_iq_invitacion (invitation_id, asked_at),
  KEY ix_iq_pendientes (seen_at),
  KEY ix_iq_visto_por (seen_by_user_id),
  CONSTRAINT fk_iq_invitacion FOREIGN KEY (invitation_id) REFERENCES invitations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_iq_visto_por FOREIGN KEY (seen_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_iq_body CHECK (CHAR_LENGTH(TRIM(body)) >= 3),
  CONSTRAINT ck_iq_seen CHECK ((seen_at IS NULL AND seen_by_user_id IS NULL) OR (seen_at IS NOT NULL AND seen_by_user_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
