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
  -- 9.17: la frase corta bajo el nombre. La pantalla de acceso la tenia escrita.
  tagline       VARCHAR(160)  NULL,
  legal_footer  VARCHAR(255)  NULL,
  logo_file_id  BIGINT UNSIGNED NULL,
  -- 9.17: el icono de la pestana es 32x32 y el logotipo no lo es. Escalar un
  -- logotipo apaisado a un cuadrado da un borron, asi que son dos archivos.
  favicon_file_id BIGINT UNSIGNED NULL,
  primary_color CHAR(7)       NULL,
  -- 9.17: el degradado de la marca usa dos colores y solo uno era configurable.
  secondary_color CHAR(7)     NULL,
  -- 9.17: el azul de la barra lateral es el color que mas superficie ocupa en
  -- toda la aplicacion y no era configurable en absoluto.
  sidebar_color CHAR(7)       NULL,
  -- 9.17: la tipografia estaba escrita en la plantilla, con su enlace al
  -- servidor de fuentes. Quien pone su marca pone su letra.
  font_family   VARCHAR(80)   NULL,
  website       VARCHAR(255)  NULL,
  support_email VARCHAR(255)  NULL,
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  -- 9.17: cual es LA marca de la plataforma. Sin esto habia que adivinarla con
  -- el id mas bajo, que es lo que hacia el alta de sociedades.
  is_default    TINYINT(1)    NOT NULL DEFAULT 0,
  created_at    DATETIME(3)   NULL,
  updated_at    DATETIME(3)   NULL,
  -- 9.17: la puerta. Vale 1 cuando es la de por defecto y NULL cuando no; el
  -- unico de abajo deja pasar una sola. Dos marcas por defecto no es un estado
  -- raro que se detecte tarde: es media aplicacion ensenando otro nombre.
  default_gate  TINYINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN is_default = 1 THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_pb_uuid (uuid),
  UNIQUE KEY uq_pb_code (code),
  UNIQUE KEY uq_pb_default (default_gate),
  KEY ix_pb_logo (logo_file_id),
  KEY ix_pb_favicon (favicon_file_id),
  CONSTRAINT fk_pb_logo FOREIGN KEY (logo_file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pb_favicon FOREIGN KEY (favicon_file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT ck_pb_color CHECK (primary_color IS NULL OR primary_color REGEXP '^#[0-9A-Fa-f]{6}$'),
  CONSTRAINT ck_pb_color2 CHECK (secondary_color IS NULL OR secondary_color REGEXP '^#[0-9A-Fa-f]{6}$'),
  CONSTRAINT ck_pb_barra CHECK (sidebar_color IS NULL OR sidebar_color REGEXP '^#[0-9A-Fa-f]{6}$'),
  -- La tipografia se convierte en una URL y en una regla CSS. Un nombre con
  -- comillas o con `;` escribe CSS ajeno en TODAS las pantallas: es una
  -- inyeccion, no una errata.
  CONSTRAINT ck_pb_tipografia CHECK (font_family IS NULL OR font_family REGEXP '^[A-Za-z0-9 ]{2,80}$'),
  CONSTRAINT ck_pb_nombre CHECK (TRIM(name) <> ''),
  CONSTRAINT ck_pb_correo CHECK (support_email IS NULL OR support_email LIKE '%_@_%.__%'),
  CONSTRAINT ck_pb_web CHECK (website IS NULL OR website LIKE 'http://%' OR website LIKE 'https://%'),
  -- El unico de arriba impide DOS por defecto; esto impide que la unica este
  -- desactivada, que es el mismo agujero por el otro lado.
  CONSTRAINT ck_pb_defecto_activa CHECK (is_default = 0 OR is_active = 1)
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
  -- 9.17c: el comprobante electronico peruano lo lleva, y no estaba.
  district          VARCHAR(100)  NULL,
  region            VARCHAR(100)  NULL,
  postal_code       VARCHAR(20)   NULL,
  -- 9.17c: el ubigeo en Peru, el codigo DANE en Colombia. La FORMA la declara
  -- el pais (`countries.tax_location_pattern`) y la impone `tg_le_localidad_*`;
  -- aqui solo esta la forma general que vale en todos.
  tax_location_code VARCHAR(12)   NULL,
  -- 9.17c: «0000» es el domicilio fiscal en SUNAT. Con valor por defecto y no
  -- nulo: un comprobante SIEMPRE lleva uno, y dejarlo nulo obligaria a
  -- decidirlo al emitir, que es tarde.
  establishment_code VARCHAR(10)  NOT NULL DEFAULT '0000',
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
  CONSTRAINT ck_le_dissolved CHECK (status <> 'dissolved' OR dissolved_on IS NOT NULL),
  -- 9.17c: la forma GENERAL, la que vale en todos los paises. La de CADA pais
  -- la impone `tg_le_localidad_*` leyendo el patron de `countries`: es cruzada
  -- y por eso no cabe en un CHECK.
  CONSTRAINT ck_le_localidad CHECK (tax_location_code IS NULL OR tax_location_code REGEXP '^[0-9A-Za-z]{2,12}$'),
  CONSTRAINT ck_le_establecimiento CHECK (establishment_code REGEXP '^[0-9A-Za-z]{1,10}$'),
  -- Un distrito en blanco no es «sin distrito»: es un comprobante con el campo
  -- vacio. Sin distrito se deja NULL, que si significa eso.
  CONSTRAINT ck_le_distrito CHECK (district IS NULL OR TRIM(district) <> '')
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

-- ============================ D2 Core: los tipos de comprobante, por pais
-- 9.12. Antes eran cinco palabras peruanas en un CHECK del codigo. Cada pais
-- declara los suyos: su codigo oficial ('01' factura, '03' boleta en SUNAT), la
-- forma de la serie y cuantos digitos tiene el correlativo. Mismo patron que el
-- codigo de localidad de 9.17c: la regla la pone el pais, no el codigo.
CREATE TABLE document_types (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  country_id    BIGINT UNSIGNED NOT NULL,
  code          VARCHAR(30)   NOT NULL,
  name          VARCHAR(80)   NOT NULL,
  official_code VARCHAR(5)    NULL,
  series_pattern VARCHAR(120) NULL,
  series_label  VARCHAR(60)   NULL,
  number_length TINYINT UNSIGNED NOT NULL DEFAULT 8,
  requires_customer_tax_id TINYINT(1) NOT NULL DEFAULT 0,
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at    DATETIME(3)   NULL,
  updated_at    DATETIME(3)   NULL,
  UNIQUE KEY uq_dtype_code (country_id, code),
  KEY ix_dtype_pais (country_id, is_active, sort_order),
  CONSTRAINT fk_dtype_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE RESTRICT,
  CONSTRAINT ck_dtype_code CHECK (code REGEXP '^[a-z][a-z0-9_]{1,29}$' AND code COLLATE utf8mb4_bin = LOWER(code)),
  CONSTRAINT ck_dtype_largo CHECK (number_length BETWEEN 1 AND 12),
  CONSTRAINT ck_dtype_patron CHECK (series_pattern IS NULL OR series_label IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================ D2 Core: series y correlativos de comprobante
-- SUNAT exige serie + correlativo sin huecos por tipo de documento. El numero
-- se reserva bajo bloqueo de esta fila (9.12), no se calcula con MAX(): dos
-- peticiones simultaneas darian el mismo correlativo, que es un problema
-- tributario y no un bug cualquiera.
CREATE TABLE document_series (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  legal_entity_id  BIGINT UNSIGNED NOT NULL,
  document_type_id BIGINT UNSIGNED NOT NULL,
  series           VARCHAR(10)   NOT NULL,
  next_number      BIGINT UNSIGNED NOT NULL DEFAULT 1,
  environment      VARCHAR(15)   NOT NULL DEFAULT 'production',
  is_active        TINYINT(1)    NOT NULL DEFAULT 1,
  is_default       TINYINT(1)    NOT NULL DEFAULT 0,
  notes            VARCHAR(255)  NULL,
  created_at       DATETIME(3)   NULL,
  updated_at       DATETIME(3)   NULL,
  -- 9.12: una sola serie POR DEFECTO por (sociedad, tipo, entorno). El empate
  -- se rechaza al configurar y no al emitir.
  default_gate     VARCHAR(60)   GENERATED ALWAYS AS (CASE WHEN is_active = 1 AND is_default = 1
                     THEN CONCAT(legal_entity_id, ':', document_type_id, ':', environment) ELSE NULL END) STORED,
  UNIQUE KEY uq_ds_series (legal_entity_id, document_type_id, series, environment),
  UNIQUE KEY uq_ds_default (default_gate),
  KEY ix_ds_entity (legal_entity_id, is_active),
  KEY ix_ds_tipo (document_type_id),
  CONSTRAINT fk_ds_entity FOREIGN KEY (legal_entity_id) REFERENCES legal_entities(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ds_tipo FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE RESTRICT,
  CONSTRAINT ck_ds_env CHECK (environment IN ('sandbox','production')),
  CONSTRAINT ck_ds_number CHECK (next_number >= 1),
  CONSTRAINT ck_ds_serie CHECK (series REGEXP '^[A-Z0-9]{1,10}$' AND series COLLATE utf8mb4_bin = UPPER(series)),
  CONSTRAINT ck_ds_defecto CHECK (is_default = 0 OR is_active = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================ D2 Core: el libro de los numeros que salieron
-- 9.12. Sin esta tabla «sin huecos» es indemostrable: el contador solo dice por
-- donde va. Un hueco puede existir --se reservo y no se emitio-- pero queda
-- ESCRITO y con motivo. Ni se borra ni se reescribe.
CREATE TABLE document_numbers (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  document_series_id BIGINT UNSIGNED NOT NULL,
  number             BIGINT UNSIGNED NOT NULL,
  full_number        VARCHAR(40)   NOT NULL,
  status             VARCHAR(15)   NOT NULL DEFAULT 'reserved',
  reserved_at        DATETIME(3)   NOT NULL,
  reserved_by_user_id BIGINT UNSIGNED NULL,
  used_at            DATETIME(3)   NULL,
  entity_type        VARCHAR(40)   NULL,
  entity_id          BIGINT UNSIGNED NULL,
  voided_at          DATETIME(3)   NULL,
  void_reason        VARCHAR(255)  NULL,
  created_at         DATETIME(3)   NULL,
  updated_at         DATETIME(3)   NULL,
  UNIQUE KEY uq_dn_number (document_series_id, number),
  KEY ix_dn_estado (status, reserved_at),
  KEY ix_dn_entidad (entity_type, entity_id),
  CONSTRAINT fk_dn_serie FOREIGN KEY (document_series_id) REFERENCES document_series(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dn_autor FOREIGN KEY (reserved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_dn_status CHECK (status IN ('reserved','used','voided')),
  CONSTRAINT ck_dn_numero CHECK (number >= 1),
  CONSTRAINT ck_dn_usado CHECK (status <> 'used' OR (used_at IS NOT NULL AND entity_type IS NOT NULL AND entity_id IS NOT NULL)),
  CONSTRAINT ck_dn_anulado CHECK (status <> 'voided' OR (voided_at IS NOT NULL AND void_reason IS NOT NULL AND CHAR_LENGTH(void_reason) >= 10)),
  CONSTRAINT ck_dn_reservado CHECK (status <> 'reserved' OR (used_at IS NULL AND voided_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 3.10 -- El historico no se solapa
--
-- `uq_lec_country` ya garantizaba una sola fila VIGENTE. Lo que no garantizaba era que
-- el historico tuviera una sola respuesta para una fecha PASADA:
--
--     .cual es la sociedad que cubre el pais de HOY?          -> una sola, garantizado
--     .cual era el 1 de mayo?          -> podian ser dos
--
-- Y aqui es lo mas caro de los tres. El resolver de facturacion elige sociedad
-- por pais Y POR FECHA. Dos filas vigentes para una misma fecha pasada es un
-- empate, y un empate ahi es una factura emitida por la sociedad equivocada.
--
-- Generados por App\Shared\Database\Periodo, no escritos a mano: la migracion
-- usa esa misma clase, asi que esquema de referencia y produccion no pueden
-- divergir. Van en disparadores porque la regla mira OTRAS FILAS, y eso ningun
-- CHECK lo admite --tampoco en MySQL 8--.
-- ===========================================================================

DELIMITER //

CREATE TRIGGER `tg_lec_sin_solape_ins`
BEFORE INSERT ON `legal_entity_countries`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM `legal_entity_countries`
         WHERE `country_id` <=> NEW.`country_id`
           AND NEW.`valid_from` <= IFNULL(`valid_to`, '9999-12-31')
           AND `valid_from` <= IFNULL(NEW.`valid_to`, '9999-12-31')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya hay una sociedad cubriendo ese pais en esas fechas: cierre la anterior el dia antes.';
    END IF;
END//

CREATE TRIGGER `tg_lec_sin_solape_upd`
BEFORE UPDATE ON `legal_entity_countries`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM `legal_entity_countries`
         WHERE `id` <> NEW.`id`
           AND `country_id` <=> NEW.`country_id`
           AND NEW.`valid_from` <= IFNULL(`valid_to`, '9999-12-31')
           AND `valid_from` <= IFNULL(NEW.`valid_to`, '9999-12-31')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya hay una sociedad cubriendo ese pais en esas fechas: cierre la anterior el dia antes.';
    END IF;
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

CREATE TRIGGER tg_lec_no_delete BEFORE DELETE ON legal_entity_countries
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'legal_entity_countries no admite borrado: dice que sociedad facturo cada pais y desde cuando.';
END//

-- 9.17: el `code` es la llave con la que el sembrador encuentra la marca. Si
-- cambia, el siguiente sembrado no la encuentra y crea otra: el sistema amanece
-- con dos, `uq_pb_default` deja a la nueva sin ser la de por defecto, y las
-- pantallas siguen ensenando la vieja mientras alguien edita la nueva. Un fallo
-- silencioso perfecto. El nombre visible se cambia cuanto se quiera.
-- 9.17c: la FORMA del codigo de localidad la declara el pais, y comprobarla es
-- leer otra tabla: eso no cabe en un CHECK. Si el pais no declara patron no se
-- comprueba nada --un pais sin configurar no puede impedir dar de alta una
-- sociedad (DEC-190)--.
CREATE TRIGGER `tg_le_localidad_ins`
BEFORE INSERT ON `legal_entities`
FOR EACH ROW
BEGIN
    -- CON COLACION EXPLICITA: sin ella la variable toma la del servidor y la
    -- columna la de la tabla, y el REGEXP entre las dos da «Illegal mix of
    -- collations», un 1267 en CADA alta de sociedad que traiga codigo.
    DECLARE v_patron VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

    IF NEW.`tax_location_code` IS NOT NULL THEN
        SELECT `tax_location_pattern` INTO v_patron
          FROM `countries` WHERE `id` = NEW.`country_id`;

        IF v_patron IS NOT NULL AND NEW.`tax_location_code` NOT REGEXP v_patron THEN
            SIGNAL SQLSTATE '45000'
              SET MESSAGE_TEXT = 'El codigo de localidad no tiene la forma que exige ese pais.';
        END IF;
    END IF;
END//

CREATE TRIGGER `tg_le_localidad_upd`
BEFORE UPDATE ON `legal_entities`
FOR EACH ROW
BEGIN
    -- CON COLACION EXPLICITA: sin ella la variable toma la del servidor y la
    -- columna la de la tabla, y el REGEXP entre las dos da «Illegal mix of
    -- collations», un 1267 en CADA alta de sociedad que traiga codigo.
    DECLARE v_patron VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

    IF NEW.`tax_location_code` IS NOT NULL THEN
        SELECT `tax_location_pattern` INTO v_patron
          FROM `countries` WHERE `id` = NEW.`country_id`;

        IF v_patron IS NOT NULL AND NEW.`tax_location_code` NOT REGEXP v_patron THEN
            SIGNAL SQLSTATE '45000'
              SET MESSAGE_TEXT = 'El codigo de localidad no tiene la forma que exige ese pais.';
        END IF;
    END IF;
END//

CREATE TRIGGER `tg_pb_code`
BEFORE UPDATE ON `platform_brands`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`code` <=> OLD.`code`) THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'El codigo de la marca no se cambia: cambie el nombre.';
    END IF;
END//

-- 9.12 -- La forma de la serie la declara el TIPO, y el tipo es de un pais.
-- Regla entre tablas: ningun CHECK la admite, tampoco en MySQL 8. La colacion
-- de la variable se declara a mano por la leccion de 9.17c (un 1267 en cada
-- alta, invisible mientras el campo viniera nulo).

CREATE TRIGGER `tg_ds_forma_ins`
BEFORE INSERT ON `document_series`
FOR EACH ROW
BEGIN
    DECLARE v_patron VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_largo TINYINT UNSIGNED;
    DECLARE v_pais_tipo BIGINT UNSIGNED;
    DECLARE v_pais_soc  BIGINT UNSIGNED;

    SELECT `series_pattern`, `number_length`, `country_id`
      INTO v_patron, v_largo, v_pais_tipo
      FROM `document_types` WHERE `id` = NEW.`document_type_id`;

    SELECT `country_id` INTO v_pais_soc
      FROM `legal_entities` WHERE `id` = NEW.`legal_entity_id`;

    IF v_pais_tipo <> v_pais_soc THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Ese tipo de comprobante es de otro pais: la serie es de la sociedad que lo emite.';
    END IF;

    IF v_patron IS NOT NULL AND NEW.`series` NOT REGEXP v_patron THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'La serie no tiene la forma que exige ese tipo de comprobante.';
    END IF;

    IF NEW.`next_number` > POW(10, v_largo) - 1 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'La serie se agoto: el correlativo ya no cabe en su longitud.';
    END IF;
END//

CREATE TRIGGER `tg_ds_forma_upd`
BEFORE UPDATE ON `document_series`
FOR EACH ROW
BEGIN
    DECLARE v_patron VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_largo TINYINT UNSIGNED;
    DECLARE v_pais_tipo BIGINT UNSIGNED;
    DECLARE v_pais_soc  BIGINT UNSIGNED;

    SELECT `series_pattern`, `number_length`, `country_id`
      INTO v_patron, v_largo, v_pais_tipo
      FROM `document_types` WHERE `id` = NEW.`document_type_id`;

    SELECT `country_id` INTO v_pais_soc
      FROM `legal_entities` WHERE `id` = NEW.`legal_entity_id`;

    IF v_pais_tipo <> v_pais_soc THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Ese tipo de comprobante es de otro pais: la serie es de la sociedad que lo emite.';
    END IF;

    IF v_patron IS NOT NULL AND NEW.`series` NOT REGEXP v_patron THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'La serie no tiene la forma que exige ese tipo de comprobante.';
    END IF;

    IF NEW.`next_number` > POW(10, v_largo) - 1 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'La serie se agoto: el correlativo ya no cabe en su longitud.';
    END IF;
END//

-- 9.12 -- Informacion fiscal: ni se borra ni se reescribe.

CREATE TRIGGER `tg_dn_no_delete`
BEFORE DELETE ON `document_numbers`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Un numero emitido no se borra: anulelo con su motivo.';
END//

CREATE TRIGGER `tg_dn_inmutable`
BEFORE UPDATE ON `document_numbers`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`number` <=> OLD.`number`)
       OR NOT (NEW.`document_series_id` <=> OLD.`document_series_id`)
       OR NOT (NEW.`full_number` <=> OLD.`full_number`)
       OR NOT (NEW.`reserved_at` <=> OLD.`reserved_at`) THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Un numero no se reescribe: es lo que hace demostrable que no hay huecos.';
    END IF;

    IF OLD.`status` <> 'reserved' AND NEW.`status` <> OLD.`status` THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Solo un numero reservado cambia de estado: usado y anulado son finales.';
    END IF;
END//

DELIMITER ;
