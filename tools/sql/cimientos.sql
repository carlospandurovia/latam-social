-- LATAM Social - Fase 2, iteracion 2.4 - capa de cimientos
-- Subconjunto portable MySQL 8 / MariaDB 10.4+. Sin ENUM, sin utf8mb4_0900_*.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================ D1 Core: catalogos

CREATE TABLE countries (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  iso2                  CHAR(2)      NOT NULL,
  iso3                  CHAR(3)      NOT NULL,
  numeric_code          CHAR(3)      NOT NULL,
  name                  VARCHAR(100) NOT NULL,
  phone_code            VARCHAR(8)   NOT NULL,
  default_currency_code CHAR(3)      NOT NULL,
  timezone              VARCHAR(64)  NOT NULL,
  -- 9.17c: como se llama y que forma tiene el codigo de localidad que exige la
  -- administracion tributaria de este pais. En Peru es el ubigeo del INEI, seis
  -- digitos; en Colombia el codigo DANE, cinco. No se llama `ubigeo` a
  -- proposito: eso seria la regla de un pais escrita en el codigo de todos.
  tax_location_label    VARCHAR(40)  NULL,
  tax_location_pattern  VARCHAR(80)  NULL,
  requires_tax_location TINYINT(1)   NOT NULL DEFAULT 0,
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  created_at            DATETIME(3)  NULL,
  updated_at            DATETIME(3)  NULL,
  UNIQUE KEY uq_countries_iso2 (iso2),
  UNIQUE KEY uq_countries_iso3 (iso3),
  KEY ix_countries_active (is_active, name),
  -- Media configuracion es peor que ninguna: un patron sin etiqueta deja al
  -- formulario pidiendo «codigo de localidad» sin decir cual.
  CONSTRAINT ck_countries_localidad CHECK (tax_location_pattern IS NULL OR tax_location_label IS NOT NULL),
  -- Y exigirlo sin decir que forma tiene es pedir algo que no se puede
  -- comprobar: el aviso saldria en rojo para siempre.
  CONSTRAINT ck_countries_localidad_exigida CHECK (requires_tax_location = 0 OR tax_location_pattern IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE currencies (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          CHAR(3)      NOT NULL,
  name          VARCHAR(60)  NOT NULL,
  symbol        VARCHAR(8)   NOT NULL,
  decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 2,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME(3)  NULL,
  updated_at    DATETIME(3)  NULL,
  UNIQUE KEY uq_currencies_code (code),
  CONSTRAINT ck_currencies_decimals CHECK (decimal_places <= 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El tipo de cambio de una operacion es el de SU fecha (BR-FIN-009).
-- rate_date es DATE: un tipo de cambio es de un dia, no de un instante.
-- 9.1 -- El catalogo de fuentes. Hasta esa iteracion `exchange_rates.source`
-- era texto libre: una tasa podia decir que la publico 'bcrp' sin que nadie
-- hubiera dicho nunca quien es 'bcrp', y de comparar ese texto con
-- `fx_official_sources.source_code` depende que tasa se aplica.
--
-- La clave ajena NO distingue mayusculas: el cotejamiento es
-- `utf8mb4_unicode_ci` y 'SUNAT' entra igual que 'sunat'. Comprobado contra el
-- motor, y afirmado en la suite para que no se suponga lo contrario.
-- 9.2 -- Las columnas de credencial. `api_key_cipher` va CIFRADA con `Crypt`,
-- la misma maquina que guarda las cuentas bancarias desde 3.8, y el entorno
-- (`DECOLECTA_API_KEY`) manda sobre ella cuando existe. `api_key_last4` esta
-- para que la pantalla pueda decir «termina en 8f2a» sin descifrar nada: la
-- clave entera no se ensena nunca, ni al que la escribio un minuto antes.
CREATE TABLE fx_sources (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(40)  NOT NULL,
  name         VARCHAR(80)  NOT NULL,
  description  VARCHAR(255) NULL,
  api_base_url VARCHAR(255) NULL,
  api_key_cipher TEXT       NULL,
  api_key_last4  VARCHAR(4) NULL,
  credential_set_at DATETIME(3) NULL,
  credential_set_by_user_id BIGINT UNSIGNED NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME(3)  NULL,
  updated_at   DATETIME(3)  NULL,
  UNIQUE KEY uq_fxs_code (code),
  KEY ix_fxs_credencial (credential_set_by_user_id),
  CONSTRAINT ck_fxs_last4 CHECK (api_key_last4 IS NULL OR CHAR_LENGTH(api_key_last4) = 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- `side` (9.1): SUNAT publica compra y venta el MISMO dia y no son
-- intercambiables. Con una sola columna `rate` solo cabe una, y elegir cual
-- guardar seria tomar por cuenta propia una decision contable. Entra en la
-- clave para que las dos quepan sin pisarse. Cual aplica a cada operacion es
-- `Q-63`.
CREATE TABLE exchange_rates (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  base_currency_code CHAR(3)        NOT NULL,
  quote_currency_code CHAR(3)       NOT NULL,
  rate_date          DATE           NOT NULL,
  rate               DECIMAL(18,8)  NOT NULL,
  side               VARCHAR(10)    NOT NULL DEFAULT 'mid',
  source             VARCHAR(40)    NOT NULL,
  fetched_at         DATETIME(3)    NOT NULL,
  created_at         DATETIME(3)    NULL,
  updated_at         DATETIME(3)    NULL,
  UNIQUE KEY uq_fx_rate (base_currency_code, quote_currency_code, rate_date, source, side),
  KEY ix_exchange_rates_lookup (base_currency_code, quote_currency_code, rate_date),
  CONSTRAINT fk_fx_source FOREIGN KEY (source) REFERENCES fx_sources(code) ON DELETE RESTRICT,
  CONSTRAINT ck_exchange_rates_positive CHECK (rate > 0),
  CONSTRAINT ck_exchange_rates_distinct CHECK (base_currency_code <> quote_currency_code),
  CONSTRAINT ck_fx_side CHECK (side IN ('buy','sell','mid'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9.1 -- Quien manda para cada par, y desde cuando.
--
-- `uq_exchange_rates` incluia `source` a proposito --dos fuentes pueden
-- discrepar el mismo dia y hay que poder decir de cual salio la que se aplico--
-- pero de ahi no salia CUAL SE APLICA. Es el mismo empate que una vez emitio
-- una factura desde la sociedad equivocada, y la respuesta es la misma que
-- entonces: la columna puerta mas la regla de no solape.
CREATE TABLE fx_official_sources (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  base_currency_code  CHAR(3)     NOT NULL,
  quote_currency_code CHAR(3)     NOT NULL,
  source_code         VARCHAR(40) NOT NULL,
  valid_from          DATE        NOT NULL,
  valid_to            DATE        NULL,
  created_at          DATETIME(3) NULL,
  updated_at          DATETIME(3) NULL,
  current_gate TINYINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN valid_to IS NULL THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_fos_current (current_gate, base_currency_code, quote_currency_code),
  KEY ix_fos_pair (base_currency_code, quote_currency_code),
  KEY ix_fos_source (source_code),
  CONSTRAINT fk_fos_base FOREIGN KEY (base_currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT fk_fos_quote FOREIGN KEY (quote_currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT fk_fos_source FOREIGN KEY (source_code) REFERENCES fx_sources(code) ON DELETE RESTRICT,
  CONSTRAINT ck_fos_distinct CHECK (base_currency_code <> quote_currency_code),
  CONSTRAINT ck_fos_dates CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dos niveles y basta (2.2 P-10). depth se guarda y se restringe.
CREATE TABLE categories (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  parent_id  BIGINT UNSIGNED NULL,
  code       VARCHAR(60)  NOT NULL,
  depth      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  min_age    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME(3)  NULL,
  updated_at DATETIME(3)  NULL,
  UNIQUE KEY uq_categories_code (code),
  KEY ix_categories_parent (parent_id),
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE RESTRICT,
  CONSTRAINT ck_categories_depth CHECK (depth <= 1),
  CONSTRAINT ck_categories_root CHECK ((depth = 0 AND parent_id IS NULL) OR (depth = 1 AND parent_id IS NOT NULL)),
  CONSTRAINT ck_categories_min_age CHECK (min_age <= 21)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE category_translations (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category_id BIGINT UNSIGNED NOT NULL,
  locale      VARCHAR(10)  NOT NULL,
  name        VARCHAR(120) NOT NULL,
  created_at  DATETIME(3)  NULL,
  updated_at  DATETIME(3)  NULL,
  UNIQUE KEY uq_category_translations (category_id, locale),
  CONSTRAINT fk_category_translations_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE platforms (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(30)  NOT NULL,
  name       VARCHAR(60)  NOT NULL,
  url_pattern VARCHAR(255) NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME(3)  NULL,
  updated_at DATETIME(3)  NULL,
  UNIQUE KEY uq_platforms_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE content_formats (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  platform_id             BIGINT UNSIGNED NOT NULL,
  code                    VARCHAR(40) NOT NULL,
  default_permanence_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  is_active               TINYINT(1)  NOT NULL DEFAULT 1,
  created_at              DATETIME(3) NULL,
  updated_at              DATETIME(3) NULL,
  UNIQUE KEY uq_content_formats (platform_id, code),
  CONSTRAINT fk_content_formats_platform FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE content_format_translations (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  content_format_id BIGINT UNSIGNED NOT NULL,
  locale            VARCHAR(10)  NOT NULL,
  name              VARCHAR(120) NOT NULL,
  created_at        DATETIME(3)  NULL,
  updated_at        DATETIME(3)  NULL,
  UNIQUE KEY uq_content_format_translations (content_format_id, locale),
  CONSTRAINT fk_cft_format FOREIGN KEY (content_format_id) REFERENCES content_formats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================== D1 Core: trazabilidad (append-only)

-- El registro de hechos del negocio. Es lo que hace el XP y el Creator Score
-- recalculables hacia atras (2.2 P-12). Solo INSERT.
CREATE TABLE domain_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid         CHAR(36)     NOT NULL,
  event_name   VARCHAR(80)  NOT NULL,
  entity_type  VARCHAR(60)  NOT NULL,
  entity_id    BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  payload      LONGTEXT     NULL,
  occurred_at  DATETIME(3)  NOT NULL,
  UNIQUE KEY uq_domain_events_uuid (uuid),
  KEY ix_domain_events_entity (entity_type, entity_id, occurred_at),
  KEY ix_domain_events_name (event_name, occurred_at),
  CONSTRAINT ck_domain_events_payload CHECK (payload IS NULL OR JSON_VALID(payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El historico de estados. Manda sobre la columna vigente (2.3 N-04).
CREATE TABLE status_transitions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(60)  NOT NULL,
  entity_id   BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(40)  NULL,
  to_status   VARCHAR(40)  NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  reason      VARCHAR(255) NULL,
  occurred_at DATETIME(3)  NOT NULL,
  KEY ix_status_transitions_entity (entity_type, entity_id, occurred_at),
  CONSTRAINT ck_status_transitions_change CHECK (from_status IS NULL OR from_status <> to_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auditoria. El usuario de la aplicacion NO tiene UPDATE ni DELETE aqui.
CREATE TABLE audit_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  actor_label   VARCHAR(120) NULL,
  action        VARCHAR(60)  NOT NULL,
  entity_type   VARCHAR(60)  NOT NULL,
  entity_id     BIGINT UNSIGNED NULL,
  changes       LONGTEXT     NULL,
  ip_address    VARBINARY(16) NULL,
  user_agent    VARCHAR(255) NULL,
  occurred_at   DATETIME(3)  NOT NULL,
  KEY ix_audit_logs_entity (entity_type, entity_id, occurred_at),
  KEY ix_audit_logs_actor (actor_user_id, occurred_at),
  -- El listado por defecto ordena por `id` (la PK ya es monotona con la
  -- insercion y sale gratis). Este indice es para FILTRAR por rango de
  -- fechas, que sin el escanea la tabla entera.
  KEY ix_audit_logs_occurred (occurred_at),
  CONSTRAINT ck_audit_logs_changes CHECK (changes IS NULL OR JSON_VALID(changes))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================ D2 Identity

-- Simula la tabla base de Laravel 12 para poder probar el ALTER.
CREATE TABLE users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(255) NOT NULL,
  email             VARCHAR(255) NOT NULL,
  email_verified_at TIMESTAMP NULL,
  password          VARCHAR(255) NOT NULL,
  remember_token    VARCHAR(100) NULL,
  created_at        TIMESTAMP NULL,
  updated_at        TIMESTAMP NULL,
  UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD COLUMN uuid              CHAR(36)    NULL AFTER id,
  ADD COLUMN user_type         VARCHAR(20) NOT NULL DEFAULT 'internal' AFTER name,
  ADD COLUMN status            VARCHAR(20) NOT NULL DEFAULT 'active'   AFTER user_type,
  ADD COLUMN locale            VARCHAR(10) NOT NULL DEFAULT 'es'       AFTER status,
  ADD COLUMN timezone          VARCHAR(64) NOT NULL DEFAULT 'America/Lima',
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN last_login_at     DATETIME(3) NULL,
  ADD COLUMN deactivated_at    DATETIME(3) NULL,
  ADD CONSTRAINT ck_users_type   CHECK (user_type IN ('internal','client','creator')),
  ADD CONSTRAINT ck_users_status CHECK (status IN ('active','suspended','deactivated'));

-- Nunca se borra un usuario: se desactiva (2.2 §5). Pero el email debe poder
-- reutilizarse tras la desactivacion, asi que la unicidad se aplica solo a los vivos.
-- MySQL/MariaDB no tienen indices parciales: se usa columna generada + NULL no colisiona.
ALTER TABLE users
  DROP INDEX users_email_unique,
  ADD COLUMN email_active_key VARCHAR(255)
    GENERATED ALWAYS AS (CASE WHEN status <> 'deactivated' THEN LOWER(email) ELSE NULL END) STORED,
  ADD UNIQUE KEY uq_users_email_active (email_active_key),
  ADD UNIQUE KEY uq_users_uuid (uuid);

CREATE TABLE roles (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(50)  NOT NULL,
  name        VARCHAR(80)  NOT NULL,
  scope       VARCHAR(20)  NOT NULL DEFAULT 'internal',
  is_system   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME(3)  NULL,
  updated_at  DATETIME(3)  NULL,
  UNIQUE KEY uq_roles_code (code),
  CONSTRAINT ck_roles_scope CHECK (scope IN ('internal','client','creator'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(80)  NOT NULL,
  module      VARCHAR(40)  NOT NULL,
  description VARCHAR(160) NOT NULL,
  created_at  DATETIME(3)  NULL,
  updated_at  DATETIME(3)  NULL,
  UNIQUE KEY uq_permissions_code (code),
  KEY ix_permissions_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permission_role (
  role_id       BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  KEY ix_permission_role_permission (permission_id),
  CONSTRAINT fk_pr_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_pr_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_user (
  user_id     BIGINT UNSIGNED NOT NULL,
  role_id     BIGINT UNSIGNED NOT NULL,
  assigned_at DATETIME(3) NOT NULL,
  assigned_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (user_id, role_id),
  KEY ix_role_user_role (role_id),
  CONSTRAINT fk_ru_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ru_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ru_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9.2 -- Lo que el cron trajo, o no trajo.
--
-- `Cambio::DIAS_ATRAS` detecta que las tasas dejaron de llegar CUANDO ALGUIEN VA
-- A CONVERTIR, o sea el dia de la liquidacion. Esto lo ensena antes. Un proceso
-- automatico que falla en silencio es un proceso que nadie arregla, porque nadie
-- se entera.
--
-- No guarda nada de la credencial, ni enmascarada, y `detail` es texto NUESTRO y
-- no el cuerpo de la respuesta: un log que copia respuestas es un log que un dia
-- copia una cabecera.
CREATE TABLE fx_fetch_runs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_code    VARCHAR(40)  NOT NULL,
  requested_date DATE         NOT NULL,
  ran_at         DATETIME(3)  NOT NULL,
  outcome        VARCHAR(20)  NOT NULL,
  rates_new      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  http_status    SMALLINT UNSIGNED NULL,
  detail         VARCHAR(255) NULL,
  created_at     DATETIME(3)  NULL,
  updated_at     DATETIME(3)  NULL,
  KEY ix_ffr_source (source_code, ran_at),
  KEY ix_ffr_date (requested_date, outcome),
  CONSTRAINT fk_ffr_source FOREIGN KEY (source_code) REFERENCES fx_sources(code) ON DELETE RESTRICT,
  CONSTRAINT ck_ffr_outcome CHECK (outcome IN ('ok','sin_credencial','sin_fuente','error_http','respuesta_rara','error_red')),
  CONSTRAINT ck_ffr_nuevas CHECK (outcome = 'ok' OR rates_new = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FKs de catalogo diferidas hasta aqui para no depender del orden de creacion.
ALTER TABLE fx_sources
  ADD CONSTRAINT fk_fxs_credencial FOREIGN KEY (credential_set_by_user_id)
  REFERENCES users(id) ON DELETE RESTRICT;

ALTER TABLE countries
  ADD CONSTRAINT fk_countries_currency FOREIGN KEY (default_currency_code)
    REFERENCES currencies(code) ON DELETE RESTRICT;
ALTER TABLE exchange_rates
  ADD CONSTRAINT fk_exchange_rates_base  FOREIGN KEY (base_currency_code)  REFERENCES currencies(code) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_exchange_rates_quote FOREIGN KEY (quote_currency_code) REFERENCES currencies(code) ON DELETE RESTRICT;


-- ===========================================================================
-- La bitacora no se edita ni se borra desde la aplicacion.
--
-- Regla del cliente: "el registro de auditoria no debe ser facilmente
-- modificable desde la aplicacion". Hasta ahora eso era una intencion: la tabla
-- admitia UPDATE y DELETE como cualquier otra. Una bitacora que la aplicacion
-- puede reescribir no es evidencia de nada.
--
-- Mismo criterio que ledger_entries (BR-FIN-001/002): prohibir un VERBO no lo
-- puede expresar ningun CHECK, asi que van disparadores, iguales en los dos
-- motores.
-- ===========================================================================

-- ============================ D2 Core: la politica de precios (9.18)
-- La retencion que se le aplica al creador que no emite comprobante (Q-13) y el
-- umbral de rentabilidad aceptable (Q-40). Ninguno de los dos puede estar en el
-- codigo: el 29,5 % cambia por decreto y el 20 % es un juicio comercial.
--
-- `margin_basis` es el tercer numero, y es configuracion y no una pregunta: con
-- 100 de neto y 29,5 % el costo es 141,84; el ejemplo del negocio decia 170,21,
-- que es 141,84 x 1,20 --recargo SOBRE EL COSTO--. Un 20 % de margen sobre el
-- INGRESO habria dado 177,30. Se configura y la pantalla ensena las dos.
--
-- Con VIGENCIA: subir el umbral del 20 al 25 % no puede convertir en mala una
-- participacion que se pacto cuando el umbral era 20.
CREATE TABLE pricing_policies (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid              CHAR(36)      NOT NULL,
  -- Porcentaje, no fraccion: 29.5 y no 0.295. Es como se escribe en un decreto
  -- y como se teclea sin equivocarse.
  withholding_rate  DECIMAL(7,4)  NOT NULL DEFAULT 0,
  min_margin_pct    DECIMAL(7,4)  NOT NULL DEFAULT 0,
  margin_basis      VARCHAR(10)   NOT NULL DEFAULT 'cost',
  -- Por que se puso este numero. Un umbral sin explicacion es un numero que
  -- nadie se atreve a cambiar dentro de un ano.
  note              VARCHAR(255)  NULL,
  valid_from        DATE          NOT NULL,
  valid_to          DATE          NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at        DATETIME(3)   NULL,
  updated_at        DATETIME(3)   NULL,
  -- La puerta: UNA sola politica vigente. Con dos, la mitad de las
  -- participaciones se pactarian con una tasa y la otra mitad con otra sin que
  -- nada fallara.
  current_gate      TINYINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN valid_to IS NULL THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_pp_uuid (uuid),
  UNIQUE KEY uq_pp_current (current_gate),
  KEY ix_pp_desde (valid_from),
  KEY ix_pp_autor (created_by_user_id),
  CONSTRAINT fk_pp_autor FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  -- Cien por cien de retencion deja el bruto en infinito: neto / (1 - 1).
  CONSTRAINT ck_pp_tasa CHECK (withholding_rate >= 0 AND withholding_rate < 100),
  CONSTRAINT ck_pp_umbral CHECK (min_margin_pct >= 0 AND min_margin_pct < 100),
  CONSTRAINT ck_pp_base CHECK (margin_basis IN ('cost','revenue')),
  CONSTRAINT ck_pp_fechas CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER tg_audit_no_update BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'audit_logs es solo-insercion: una bitacora que se puede reescribir no es evidencia.';
END//

CREATE TRIGGER tg_audit_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'audit_logs no admite borrado. La retencion se aplica por proceso, no con un DELETE.';
END//

DELIMITER ;

-- ===========================================================================
-- 3.12 / T-16 -- Lo que es evidencia no se borra
--
-- Nueve tablas ya tenian `no_delete` --`audit_logs`, `invoices`,
-- `ledger_entries`, `payouts`, `payments`, `invoice_lines`, `campaign_costs`,
-- `creator_payment_methods` y `social_account_snapshots`-- y otras nueve
-- guardaban evidencia igual de definitiva sin ninguna proteccion.
--
-- Salio al escribir la suite de 3.11. La asercion que iba a escribir alli
-- habria dicho «el DELETE funciona», o sea habria fijado el hueco como si fuera
-- lo correcto --el mismo error que `PerfilFiscalTest` cometio con `T-12`--.
-- Anular un perfil fiscal existe para NO destruir el historico, y un DELETE se
-- lo llevaba entero.
--
-- El criterio para entrar aqui es uno solo: la fila es EVIDENCIA de algo que
-- paso, y de ella depende dinero o una obligacion legal. Los catalogos, las
-- tablas de union y los datos operativos se siguen pudiendo borrar.
-- ===========================================================================

DELIMITER //

CREATE TRIGGER tg_fx_no_delete BEFORE DELETE ON exchange_rates
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'exchange_rates no admite borrado: es el cambio con el que se convirtio dinero en una fecha.';
END//

-- 9.1 -- Y tampoco se reescribe (`BR-FIN-009`: los historicos no se recalculan).
--
-- `tg_fx_no_delete` existe desde 3.12, pero un UPDATE la reescribia entera. Un
-- asiento guarda su `exchange_rate_snapshot`, asi que reescribir la tasa no
-- cambia lo ya convertido: lo que rompe es poder explicarlo --el asiento diria
-- 3,742 y su fuente diria 3,751--. Se bloquea el UPDATE ENTERO, como
-- `tg_cvw_inmutable`, porque no hay ninguna columna de esta tabla que tenga
-- sentido cambiar despues de publicada.
-- 9.2 -- Poner una credencial deja rastro completo o no lo deja.
--
-- Media firma --cifrado sin autor, o autor sin fecha-- es peor que ninguna,
-- porque parece que la pregunta «quien la puso» tiene respuesta. Y esa pregunta
-- es la primera el dia que aparezca un consumo raro contra el servicio.
CREATE TRIGGER `tg_fxs_credencial_firmada`
BEFORE UPDATE ON `fx_sources`
FOR EACH ROW
BEGIN
    IF NEW.`api_key_cipher` IS NOT NULL
       AND (NEW.`credential_set_at` IS NULL
            OR NEW.`credential_set_by_user_id` IS NULL
            OR NEW.`api_key_last4` IS NULL) THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Una credencial guardada exige quien la puso, cuando, y sus cuatro ultimos.';
    END IF;
END//

CREATE TRIGGER `tg_fx_inmutable`
BEFORE UPDATE ON `exchange_rates`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Un tipo de cambio publicado no se modifica: los historicos no se recalculan.';
END//

-- 9.1 -- Una sola fuente oficial por par Y POR FECHA.
--
-- `uq_fos_current` garantiza una VIGENTE. Convertir un importe del 3 de marzo
-- resuelve por par y por fecha, asi que dos periodos cerrados que se pisen son
-- el mismo empate para una fecha pasada. Generados por
-- App\Shared\Database\Periodo, no escritos a mano.
CREATE TRIGGER `tg_fos_sin_solape_ins`
BEFORE INSERT ON `fx_official_sources`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM `fx_official_sources`
         WHERE `base_currency_code` <=> NEW.`base_currency_code`
           AND `quote_currency_code` <=> NEW.`quote_currency_code`
           AND NEW.`valid_from` <= IFNULL(`valid_to`, '9999-12-31')
           AND `valid_from` <= IFNULL(NEW.`valid_to`, '9999-12-31')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya hay una fuente oficial para ese par en esas fechas: cierre la anterior el dia antes.';
    END IF;
END//

CREATE TRIGGER `tg_fos_sin_solape_upd`
BEFORE UPDATE ON `fx_official_sources`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM `fx_official_sources`
         WHERE `id` <> NEW.`id`
           AND `base_currency_code` <=> NEW.`base_currency_code`
           AND `quote_currency_code` <=> NEW.`quote_currency_code`
           AND NEW.`valid_from` <= IFNULL(`valid_to`, '9999-12-31')
           AND `valid_from` <= IFNULL(NEW.`valid_to`, '9999-12-31')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya hay una fuente oficial para ese par en esas fechas: cierre la anterior el dia antes.';
    END IF;
END//

-- 9.18: sin solape, con la tabla ENTERA como serie. `Periodo::sinSolape()` no
-- sirve aqui y lo dice el mismo: se niega a trabajar con una serie vacia porque
-- «prohibiria dos periodos cualesquiera de la tabla entera». Eso es exactamente
-- lo que hace falta, asi que va a mano y con esta nota para que nadie lo tome
-- por un descuido.
CREATE TRIGGER `tg_pp_sin_solape_ins`
BEFORE INSERT ON `pricing_policies`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM `pricing_policies`
         WHERE NEW.`valid_from` <= IFNULL(`valid_to`, '9999-12-31')
           AND `valid_from` <= IFNULL(NEW.`valid_to`, '9999-12-31')
    ) THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Ya hay una politica de precios en esas fechas: cierre la anterior el dia antes.';
    END IF;
END//

CREATE TRIGGER `tg_pp_sin_solape_upd`
BEFORE UPDATE ON `pricing_policies`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM `pricing_policies`
         WHERE NEW.`valid_from` <= IFNULL(`valid_to`, '9999-12-31')
           AND `valid_from` <= IFNULL(NEW.`valid_to`, '9999-12-31')
           AND `id` <> NEW.`id`
    ) THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Ya hay una politica de precios en esas fechas: cierre la anterior el dia antes.';
    END IF;
END//

-- Una politica CERRADA no se reescribe: es la que explica por que un compromiso
-- de hace tres meses se pacto como se pacto.
CREATE TRIGGER `tg_pp_inmutable`
BEFORE UPDATE ON `pricing_policies`
FOR EACH ROW
BEGIN
    IF OLD.`valid_to` IS NOT NULL THEN
        IF NOT (NEW.`withholding_rate` <=> OLD.`withholding_rate`)
           OR NOT (NEW.`min_margin_pct` <=> OLD.`min_margin_pct`)
           OR NOT (NEW.`margin_basis` <=> OLD.`margin_basis`)
           OR NOT (NEW.`valid_from` <=> OLD.`valid_from`) THEN
            SIGNAL SQLSTATE '45000'
              SET MESSAGE_TEXT = 'Una politica cerrada no se reescribe: publique la siguiente.';
        END IF;
    END IF;
END//

CREATE TRIGGER `tg_pp_no_delete`
BEFORE DELETE ON `pricing_policies`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'pricing_policies no admite borrado: explica como se pacto cada compromiso.';
END//

DELIMITER ;
