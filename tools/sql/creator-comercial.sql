-- LATAM Social - Fase 2, iteracion 2.7 - Creador: perfil comercial
SET NAMES utf8mb4;

-- ============================================== D1 Core: idiomas
CREATE TABLE languages (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(10)  NOT NULL,
  name       VARCHAR(60)  NOT NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME(3)  NULL,
  updated_at DATETIME(3)  NULL,
  UNIQUE KEY uq_languages_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================== D3 Creator: nichos que trabaja
CREATE TABLE creator_categories (
  creator_id  BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  is_primary  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME(3)  NULL,
  PRIMARY KEY (creator_id, category_id),
  KEY ix_creator_categories_category (category_id),
  -- Un solo nicho principal por creador: es el que manda al ordenar resultados.
  primary_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN is_primary = 1 THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_creator_categories_primary (primary_gate, creator_id),
  CONSTRAINT fk_cc_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cc_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================ D3 Creator: nichos que NO acepta
-- No es lo contrario de la tabla anterior: aqui hay una razon y una fecha, y
-- alimenta el filtro que evita invitar a alguien a una campana que rechazaria.
CREATE TABLE creator_restrictions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  creator_id  BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  reason      VARCHAR(255)  NULL,
  created_at  DATETIME(3)   NULL,
  updated_at  DATETIME(3)   NULL,
  UNIQUE KEY uq_creator_restrictions (creator_id, category_id),
  KEY ix_creator_restrictions_category (category_id),
  CONSTRAINT fk_cr_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cr_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================== D3 Creator: formatos que ofrece
CREATE TABLE creator_formats (
  creator_id        BIGINT UNSIGNED NOT NULL,
  content_format_id BIGINT UNSIGNED NOT NULL,
  experience_level  VARCHAR(15)  NOT NULL DEFAULT 'intermediate',
  is_offered        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        DATETIME(3)  NULL,
  updated_at        DATETIME(3)  NULL,
  PRIMARY KEY (creator_id, content_format_id),
  KEY ix_creator_formats_format (content_format_id),
  CONSTRAINT fk_cf_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cf_format FOREIGN KEY (content_format_id) REFERENCES content_formats(id) ON DELETE RESTRICT,
  CONSTRAINT ck_creator_formats_level CHECK (experience_level IN ('beginner','intermediate','expert'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================== D3 Creator: idiomas en que crea
CREATE TABLE creator_languages (
  creator_id  BIGINT UNSIGNED NOT NULL,
  language_id BIGINT UNSIGNED NOT NULL,
  proficiency VARCHAR(15)  NOT NULL DEFAULT 'fluent',
  created_at  DATETIME(3)  NULL,
  PRIMARY KEY (creator_id, language_id),
  KEY ix_creator_languages_language (language_id),
  CONSTRAINT fk_cl_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cl_language FOREIGN KEY (language_id) REFERENCES languages(id) ON DELETE RESTRICT,
  CONSTRAINT ck_creator_languages_proficiency CHECK (proficiency IN ('native','fluent','intermediate','basic'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================== D3 Creator: tarifa declarada
-- BR-CREATOR-008: es una REFERENCIA, no un compromiso. Lo vinculante es el
-- monto congelado en la participacion de campana.
--
-- NO lleva platform_id: el formato ya pertenece a una red (content_formats.platform_id),
-- asi que repetirlo aqui seria una dependencia transitiva. Ver 2.7 §2.1.
CREATE TABLE creator_rates (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  creator_id        BIGINT UNSIGNED NOT NULL,
  content_format_id BIGINT UNSIGNED NOT NULL,
  currency_code     CHAR(3)        NOT NULL,
  amount            DECIMAL(18,4)  NOT NULL,
  -- H-17: `DEFAULT 'self_declared'` afirmaba de donde salia el precio cuando
  -- nadie lo habia dicho. «El creador lo declaro» y «se lo estimamos nosotros»
  -- no pueden ser el mismo valor por omision: la diferencia es si el creador
  -- sostiene ese numero o nos lo inventamos. Sin DEFAULT, en modo estricto una
  -- insercion que no lo diga falla, que es exactamente lo que se quiere.
  -- Es DEC-048 aplicado al precio.
  source            VARCHAR(15)    NOT NULL,
  -- Cero es un precio valido --canje por producto, primera colaboracion-- pero
  -- tiene que declararse. Sin esto, «trabaja gratis» y «nadie le pregunto su
  -- tarifa» eran el mismo cero, y no se responden igual delante de un cliente.
  is_gratis         TINYINT(1)     NOT NULL DEFAULT 0,
  -- H-18: quien fijo ese precio. `campaign_creators.agreed_amount` congela el
  -- compromiso, si, pero la tarifa es DE DONDE SALE ese numero: si nadie firma
  -- la referencia, no hay a quien preguntarle por que subio.
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  valid_from        DATE           NOT NULL,
  valid_to          DATE           NULL,
  created_at        DATETIME(3)    NULL,
  updated_at        DATETIME(3)    NULL,
  -- Una sola tarifa VIGENTE por formato y moneda. Las cerradas se acumulan como
  -- historico: sirven para ver como fue subiendo el precio de alguien.
  current_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN valid_to IS NULL THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_creator_rates_current (current_gate, creator_id, content_format_id, currency_code),
  KEY ix_creator_rates_creator (creator_id, content_format_id),
  KEY ix_creator_rates_format (content_format_id, currency_code),
  KEY ix_creator_rates_currency (currency_code),
  KEY ix_creator_rates_author (created_by_user_id),
  CONSTRAINT fk_crate_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_crate_author FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_crate_format FOREIGN KEY (content_format_id) REFERENCES content_formats(id) ON DELETE RESTRICT,
  CONSTRAINT fk_crate_currency FOREIGN KEY (currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  -- Cero si y solo si esta declarado como gratuito. Las dos direcciones, no
  -- solo una: un `is_gratis = 1` con importe tampoco tiene sentido.
  CONSTRAINT ck_creator_rates_amount CHECK (
    (is_gratis = 1 AND amount = 0) OR (is_gratis = 0 AND amount > 0)
  ),
  CONSTRAINT ck_creator_rates_source CHECK (source IN ('self_declared','negotiated','estimated')),
  CONSTRAINT ck_creator_rates_dates CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================== D3 Creator: disponibilidad
CREATE TABLE creator_availability (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  creator_id             BIGINT UNSIGNED NOT NULL,
  accepts_travel         TINYINT(1)   NOT NULL DEFAULT 0,
  travel_scope           VARCHAR(15)  NULL,
  accepts_in_person      TINYINT(1)   NOT NULL DEFAULT 1,
  accepts_product_only   TINYINT(1)   NOT NULL DEFAULT 0,
  max_campaigns_per_month SMALLINT UNSIGNED NULL,
  min_lead_time_days     SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  notes                  VARCHAR(255) NULL,
  valid_from             DATE         NOT NULL,
  valid_to               DATE         NULL,
  created_at             DATETIME(3)  NULL,
  updated_at             DATETIME(3)  NULL,
  current_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN valid_to IS NULL THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_creator_availability_current (current_gate, creator_id),
  KEY ix_creator_availability_creator (creator_id),
  CONSTRAINT fk_cav_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  -- Se parte en dos a proposito. La version de una sola linea era:
  --     accepts_travel = 0 OR travel_scope IN ('local','national','international')
  -- y NO funcionaba: con travel_scope NULL, `NULL IN (...)` vale NULL, asi que la
  -- expresion entera vale NULL, y un CHECK deja pasar lo que evalua a NULL (solo
  -- rechaza lo que evalua a FALSE). Resultado: "viaja, alcance desconocido" entraba.
  -- Logica de tres valores: hay que preguntar por NULL explicitamente.
  CONSTRAINT ck_creator_availability_scope_values CHECK (
    travel_scope IS NULL OR travel_scope IN ('local','national','international')
  ),
  CONSTRAINT ck_creator_availability_scope_required CHECK (
    accepts_travel = 0 OR travel_scope IS NOT NULL
  ),
  CONSTRAINT ck_creator_availability_dates CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================================== D3 Creator: periodos de no disponibilidad
CREATE TABLE creator_blackouts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  creator_id  BIGINT UNSIGNED NOT NULL,
  starts_on   DATE          NOT NULL,
  ends_on     DATE          NOT NULL,
  reason      VARCHAR(120)  NULL,
  created_at  DATETIME(3)   NULL,
  updated_at  DATETIME(3)   NULL,
  KEY ix_creator_blackouts_creator (creator_id, starts_on, ends_on),
  CONSTRAINT fk_cb_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT ck_creator_blackouts_dates CHECK (ends_on >= starts_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- Historial sin solapes (iteracion 3.9)
--
-- `uq_creator_rates_current` garantiza UNA tarifa vigente por creador, formato
-- y moneda. No garantiza que el historial sea coherente: se comprobo que dos
-- tarifas cerradas con periodos solapados entraban sin protestar, y entonces
-- «cuanto costaba el 1 de mayo» tiene DOS respuestas:
--
--     ACEPTADO. el 2026-05-01 la tarifa era: 1000.0000, 2500.0000
--
-- Un historial de precios con dos respuestas para la misma fecha no sirve para
-- lo unico para lo que existe, que es explicar por que se pago lo que se pago.
--
-- `valid_to` es INCLUSIVO --lo dice `ck_creator_rates_dates`, que admite
-- `valid_to = valid_from`--, asi que cerrar la anterior es ponerle el dia
-- ANTERIOR al inicio de la nueva, no el mismo dia. De eso se encarga el
-- controlador; aqui solo se impide el solape.
--
-- Va en disparadores porque mira OTRAS FILAS, y eso ningun CHECK lo puede hacer.
--
-- T-14: escritos a mano en 3.9 porque `App\Shared\Database\Periodo` aun no
-- existia --nacio en 3.10, al ver que el mismo defecto seguia abierto en otras
-- cinco tablas--. Ahora los genera esa clase, con los mismos nombres. Tener dos
-- formas de imponer la misma regla en el mismo repositorio significa que un
-- arreglo futuro hay que aplicarlo en dos sitios, y el segundo es el que se
-- olvida.
-- ===========================================================================

DELIMITER //

CREATE TRIGGER `tg_crate_sin_solape_ins`
BEFORE INSERT ON `creator_rates`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM `creator_rates`
         WHERE `creator_id` <=> NEW.`creator_id`
           AND `content_format_id` <=> NEW.`content_format_id`
           AND `currency_code` <=> NEW.`currency_code`
           AND NEW.`valid_from` <= IFNULL(`valid_to`, '9999-12-31')
           AND `valid_from` <= IFNULL(NEW.`valid_to`, '9999-12-31')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ese periodo se solapa con otra tarifa del mismo formato y moneda: cierre la anterior el dia antes.';
    END IF;
END//

CREATE TRIGGER `tg_crate_sin_solape_upd`
BEFORE UPDATE ON `creator_rates`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM `creator_rates`
         WHERE `id` <> NEW.`id`
           AND `creator_id` <=> NEW.`creator_id`
           AND `content_format_id` <=> NEW.`content_format_id`
           AND `currency_code` <=> NEW.`currency_code`
           AND NEW.`valid_from` <= IFNULL(`valid_to`, '9999-12-31')
           AND `valid_from` <= IFNULL(NEW.`valid_to`, '9999-12-31')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ese periodo se solapa con otra tarifa del mismo formato y moneda: cierre la anterior el dia antes.';
    END IF;
END//

CREATE TRIGGER `tg_cav_sin_solape_ins`
BEFORE INSERT ON `creator_availability`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM `creator_availability`
         WHERE `creator_id` <=> NEW.`creator_id`
           AND NEW.`valid_from` <= IFNULL(`valid_to`, '9999-12-31')
           AND `valid_from` <= IFNULL(NEW.`valid_to`, '9999-12-31')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ese periodo se solapa con otra disponibilidad declarada: cierre la anterior el dia antes.';
    END IF;
END//

CREATE TRIGGER `tg_cav_sin_solape_upd`
BEFORE UPDATE ON `creator_availability`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM `creator_availability`
         WHERE `id` <> NEW.`id`
           AND `creator_id` <=> NEW.`creator_id`
           AND NEW.`valid_from` <= IFNULL(`valid_to`, '9999-12-31')
           AND `valid_from` <= IFNULL(NEW.`valid_to`, '9999-12-31')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ese periodo se solapa con otra disponibilidad declarada: cierre la anterior el dia antes.';
    END IF;
END//

DELIMITER ;
