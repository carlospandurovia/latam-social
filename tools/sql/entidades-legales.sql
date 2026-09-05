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
  -- L-1: la PRIMERA parada del degradado. El de marca tiene tres --naranja,
  -- magenta y morado-- y el esquema solo guardaba dos colores, asi que pintarlo
  -- se saltaba el magenta, que es el tercio central. Una columna y no tres: el
  -- degradado termina en `primary_color`, que es tambien el unico color de marca
  -- plano de la interfaz (`docs/14 §3`). NULL = degradado de dos colores.
  gradient_from CHAR(7)       NULL,
  -- 45 es el canonico de `docs/14 §6`: primer color abajo-izquierda. Estaba
  -- escrito `135deg` en una plantilla, que es un valor de marca en un archivo
  -- de codigo.
  gradient_angle SMALLINT UNSIGNED NOT NULL DEFAULT 45,
  -- 9.17: el azul de la barra lateral es el color que mas superficie ocupa en
  -- toda la aplicacion y no era configurable en absoluto.
  sidebar_color CHAR(7)       NULL,
  -- 9.17: la tipografia estaba escrita en la plantilla, con su enlace al
  -- servidor de fuentes. Quien pone su marca pone su letra.
  font_family   VARCHAR(80)   NULL,
  -- L-1: la de TITULARES. `docs/14 §5` separa display de interfaz --una letra
  -- con caracter en un titular no es la que mejor se lee a 13 px en una tabla--
  -- y una sola familia no puede expresar eso.
  display_font_family VARCHAR(80) NULL,
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
  CONSTRAINT ck_pb_degradado CHECK (gradient_from IS NULL OR gradient_from REGEXP '^#[0-9A-Fa-f]{6}$'),
  -- Un campo que admite 3600 admite tambien que alguien tecleo el año.
  CONSTRAINT ck_pb_angulo CHECK (gradient_angle < 360),
  -- La misma regla que `ck_pb_tipografia`: esto se convierte en una URL y en una
  -- regla CSS, asi que una comilla o un `;` escriben CSS ajeno en TODAS las
  -- pantallas. Es una inyeccion, no una errata.
  CONSTRAINT ck_pb_tipografia_titulos CHECK (display_font_family IS NULL OR display_font_family REGEXP '^[A-Za-z0-9 ]{2,80}$'),
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
-- ==================== El certificado con el que firma cada sociedad (9.9c)
-- No cabe en `integration_credentials` (9.17d): aquello son credenciales DE UNA
-- CONEXION, y un certificado de firma es la identidad de la SOCIEDAD --el mismo
-- firma tanto si el comprobante sale directo a SUNAT como si sale por un
-- proveedor, y sigue explicando la firma de lo ya emitido cuando la conexion se
-- cambia entera--. Y tiene algo que ninguna credencial tiene: vigencia propia,
-- escrita dentro del archivo, que nadie de aqui decide.
--
-- Lo que se guarda es PEM y no el .pfx: es lo que consume quien firma, y asi la
-- contrasena del .pfx NO se guarda --se usa una vez, al subirlo, y se olvida--.
CREATE TABLE signing_certificates (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                CHAR(36)      NOT NULL,
  legal_entity_id     BIGINT UNSIGNED NOT NULL,
  -- DEC-029: el de pruebas y el real conviven y no se pueden confundir.
  environment         VARCHAR(15)   NOT NULL DEFAULT 'sandbox',
  -- Lo que dice el propio certificado. NO se teclea: se lee.
  subject_name        VARCHAR(255)  NOT NULL,
  issuer_name         VARCHAR(255)  NOT NULL,
  serial_number       VARCHAR(80)   NOT NULL,
  -- El RUC que lleva DENTRO, para poder contestar «.es de esta sociedad?».
  tax_id_number       VARCHAR(40)   NOT NULL,
  valid_from          DATETIME(3)   NOT NULL,
  valid_to            DATETIME(3)   NOT NULL,
  fingerprint_sha256  CHAR(64)      NOT NULL,
  -- El certificado y su clave privada, en PEM y cifrados con la clave de la
  -- aplicacion. Lo unico que sale en claro lo pide quien firma, nadie mas.
  pem_cipher          LONGTEXT      NOT NULL,
  source              VARCHAR(10)   NOT NULL DEFAULT 'pkcs12',
  status              VARCHAR(15)   NOT NULL DEFAULT 'active',
  uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
  uploaded_at         DATETIME(3)   NOT NULL,
  replaced_at         DATETIME(3)   NULL,
  revoked_at          DATETIME(3)   NULL,
  revoked_reason      VARCHAR(255)  NULL,
  created_at          DATETIME(3)   NULL,
  updated_at          DATETIME(3)   NULL,
  -- La 34.a columna puerta: UN solo certificado activo por sociedad y entorno.
  -- Con dos, la mitad de los comprobantes iria firmado con uno y la mitad con
  -- otro, y nadie sabria cual hasta que la administracion rechazara.
  activo_gate   VARCHAR(45) GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN CONCAT(legal_entity_id, ':', environment) ELSE NULL END) STORED,
  UNIQUE KEY uq_cert_uuid (uuid),
  -- Con el entorno dentro: nada impide usar el mismo en beta y en produccion.
  UNIQUE KEY uq_cert_huella (fingerprint_sha256, environment),
  UNIQUE KEY uq_cert_activo (activo_gate),
  KEY ix_cert_sociedad (legal_entity_id, environment, status),
  KEY ix_cert_vence (valid_to),
  KEY ix_cert_autor (uploaded_by_user_id),
  CONSTRAINT fk_cert_entity FOREIGN KEY (legal_entity_id) REFERENCES legal_entities(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cert_autor FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_cert_env CHECK (environment IN ('sandbox','production')),
  CONSTRAINT ck_cert_status CHECK (status IN ('active','replaced','revoked')),
  CONSTRAINT ck_cert_dates CHECK (valid_to > valid_from),
  -- Un cifrado vacio es un certificado que no existe disfrazado de uno que si.
  CONSTRAINT ck_cert_pem CHECK (TRIM(pem_cipher) <> ''),
  CONSTRAINT ck_cert_huella CHECK (CHAR_LENGTH(fingerprint_sha256) = 64),
  CONSTRAINT ck_cert_ruc CHECK (TRIM(tax_id_number) <> ''),
  CONSTRAINT ck_cert_source CHECK (source IN ('pkcs12','pem')),
  -- `revoked_reason IS NOT NULL` ANTES del largo: CHAR_LENGTH(TRIM(NULL)) es
  -- NULL, la conjuncion entera es NULL y un CHECK solo rechaza cuando es FALSO.
  CONSTRAINT ck_cert_revocado CHECK (status <> 'revoked' OR (revoked_at IS NOT NULL AND revoked_reason IS NOT NULL AND CHAR_LENGTH(TRIM(revoked_reason)) >= 10)),
  CONSTRAINT ck_cert_reemplazado CHECK (status <> 'replaced' OR replaced_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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


-- ============================ D2 Core: la portada publica (9.21b)
-- El contenido de la landing es un DATO, no una plantilla: esto es white label
-- (`DEC-190`), y un titular escrito en un .blade.php es «LATAM Social» escrito
-- en tres plantillas otra vez, pero peor: aqui lo ve quien todavia no es
-- cliente. Cuelga de la marca porque el dia que haya una segunda instalacion su
-- portada no puede ser la misma.
CREATE TABLE landing_pages (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  platform_brand_id BIGINT UNSIGNED NOT NULL,
  code              VARCHAR(20)   NOT NULL,
  headline          VARCHAR(160)  NOT NULL,
  subheadline       VARCHAR(320)  NULL,
  cta_label         VARCHAR(60)   NOT NULL,
  cta_url           VARCHAR(255)  NULL,
  -- L-4 (`C-3`): el cierre deja de repetir el boton. El formulario es CODIGO
  -- --validacion, campo trampa, throttle-- pero sus palabras no lo son, y hasta
  -- hoy el titulo de la seccion ERA cta_label: la misma frase tres veces.
  form_heading      VARCHAR(120)  NULL,
  form_intro        VARCHAR(320)  NULL,
  hero_image_file_id BIGINT UNSIGNED NULL,
  meta_title        VARCHAR(70)   NULL,
  meta_description  VARCHAR(180)  NULL,
  is_published      TINYINT(1)    NOT NULL DEFAULT 1,
  created_at        DATETIME(3)   NULL,
  updated_at        DATETIME(3)   NULL,
  UNIQUE KEY uq_lp_code (platform_brand_id, code),
  CONSTRAINT fk_lp_brand FOREIGN KEY (platform_brand_id) REFERENCES platform_brands(id) ON DELETE RESTRICT,
  CONSTRAINT fk_lp_hero FOREIGN KEY (hero_image_file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT ck_lp_code CHECK (code IN ('marcas','creadores')),
  CONSTRAINT ck_lp_titular CHECK (CHAR_LENGTH(TRIM(headline)) >= 10),
  CONSTRAINT ck_lp_boton CHECK (CHAR_LENGTH(TRIM(cta_label)) >= 2),
  CONSTRAINT ck_lp_url CHECK (cta_url IS NULL OR cta_url LIKE 'https://%' OR cta_url LIKE '/%'),
  CONSTRAINT ck_lp_form CHECK (form_heading IS NULL OR CHAR_LENGTH(TRIM(form_heading)) >= 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Las franjas de la portada (L-3). Antes la plantilla decidia cuantas habia, en
-- que orden salian y como se llamaban --«Como funciona» y «Preguntas» estaban
-- escritos en el .blade.php--. Eso es `DEC-190` roto en el sitio mas visible del
-- producto: lo primero que ve alguien que todavia no es cliente. Y no era solo
-- un texto: el ORDEN era codigo, asi que en /creadores las preguntas salian
-- detras del formulario y arreglarlo pedia un despliegue.
CREATE TABLE landing_sections (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  landing_page_id  BIGINT UNSIGNED NOT NULL,
  code             VARCHAR(40)   NOT NULL,
  layout           VARCHAR(20)   NOT NULL DEFAULT 'cards',
  eyebrow          VARCHAR(60)   NULL,
  title            VARCHAR(120)  NULL,
  subtitle         VARCHAR(320)  NULL,
  cta_label        VARCHAR(60)   NULL,
  cta_url          VARCHAR(255)  NULL,
  sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  is_visible       TINYINT(1)    NOT NULL DEFAULT 1,
  show_in_nav      TINYINT(1)    NOT NULL DEFAULT 0,
  created_at       DATETIME(3)   NULL,
  updated_at       DATETIME(3)   NULL,
  UNIQUE KEY uq_ls_code (landing_page_id, code),
  KEY ix_ls_pagina (landing_page_id, is_visible, sort_order),
  CONSTRAINT fk_ls_page FOREIGN KEY (landing_page_id) REFERENCES landing_pages(id) ON DELETE RESTRICT,
  -- COLLATE utf8mb4_bin y no a secas: el cotejo por defecto es
  -- CASE-INSENSITIVE, asi que ^[a-z0-9-]+$ acepta `Como-Funciona`. Se aprendio
  -- en L-2a con ck_sl_red, y ninguna prueba de PHP lo ve.
  CONSTRAINT ck_ls_code CHECK (code COLLATE utf8mb4_bin REGEXP '^[a-z0-9][a-z0-9-]*$'),
  CONSTRAINT ck_ls_layout CHECK (layout IN ('cards','steps','faq','claim','plain')),
  CONSTRAINT ck_ls_menu CHECK (show_in_nav = 0 OR (title IS NOT NULL AND CHAR_LENGTH(TRIM(title)) >= 2)),
  CONSTRAINT ck_ls_url CHECK (cta_url IS NULL OR cta_url LIKE 'https://%' OR cta_url LIKE '/%' OR cta_url LIKE '#%'),
  CONSTRAINT ck_ls_cta CHECK (cta_url IS NULL OR (cta_label IS NOT NULL AND CHAR_LENGTH(TRIM(cta_label)) >= 2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los bloques, DENTRO de una franja. Una tabla y no seis columnas porque el
-- numero de bloques es del contenido, no del programa.
--
-- Desde L-3 no hay `kind`: la forma la decide la franja (`layout`). Si la
-- decidieran los dos, serian dos fuentes para la misma verdad y un dia se
-- contradicen. Y tampoco hay `landing_page_id`: la pagina se sabe subiendo por
-- la franja, y guardarlo dos veces es como una fila acaba colgando de una
-- pagina y de una franja de otra.
CREATE TABLE landing_blocks (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  landing_section_id BIGINT UNSIGNED NOT NULL,
  heading            VARCHAR(120)  NOT NULL,
  body               VARCHAR(600)  NULL,
  icon               VARCHAR(40)   NULL,
  image_file_id      BIGINT UNSIGNED NULL,
  cta_label          VARCHAR(60)   NULL,
  cta_url            VARCHAR(255)  NULL,
  sort_order         SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  is_visible         TINYINT(1)    NOT NULL DEFAULT 1,
  created_at         DATETIME(3)   NULL,
  updated_at         DATETIME(3)   NULL,
  KEY ix_lb_seccion (landing_section_id, is_visible, sort_order),
  CONSTRAINT fk_lb_section FOREIGN KEY (landing_section_id) REFERENCES landing_sections(id) ON DELETE RESTRICT,
  CONSTRAINT fk_lb_image FOREIGN KEY (image_file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT ck_lb_heading CHECK (CHAR_LENGTH(TRIM(heading)) >= 3),
  -- El icono NO se encierra en un IN (...): un nombre desconocido pinta el
  -- generico y no rompe nada, como las redes del pie. Encerrarlos convertiria
  -- anadir un icono en una migracion.
  CONSTRAINT ck_lb_icono CHECK (icon IS NULL OR icon COLLATE utf8mb4_bin REGEXP '^[a-z0-9][a-z0-9-]*$'),
  CONSTRAINT ck_lb_url CHECK (cta_url IS NULL OR cta_url LIKE 'https://%' OR cta_url LIKE '/%' OR cta_url LIKE '#%'),
  CONSTRAINT ck_lb_cta CHECK (cta_url IS NULL OR (cta_label IS NOT NULL AND CHAR_LENGTH(TRIM(cta_label)) >= 2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================ D2 Core: las paginas del sitio (L-2b)
-- Aqui van la politica de privacidad, el aviso legal, «sobre nosotros». NO el
-- contrato del creador: ese vive en `terms_versions` desde `9.16`, con su
-- aceptacion registrada apuntando a una version concreta. Duplicarlo seria tener
-- dos verdades sobre lo mismo.
CREATE TABLE content_pages (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid              CHAR(36)      NOT NULL,
  platform_brand_id BIGINT UNSIGNED NOT NULL,
  -- La URL. `politica-de-privacidad`, no `p/17`: se enlaza desde correos,
  -- contratos y formularios de terceros, y un numero ahi no dice nada.
  slug              VARCHAR(60)   NOT NULL,
  title             VARCHAR(160)  NOT NULL,
  meta_title        VARCHAR(70)   NULL,
  meta_description  VARCHAR(180)  NULL,
  show_in_footer    TINYINT(1)    NOT NULL DEFAULT 1,
  sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  -- Las que el sistema necesita no se borran. Su texto se cambia entero; lo que
  -- no se puede es dejar el pie sin politica de privacidad por un clic.
  is_system         TINYINT(1)    NOT NULL DEFAULT 0,
  created_at        DATETIME(3)   NULL,
  updated_at        DATETIME(3)   NULL,
  UNIQUE KEY uq_cp_uuid (uuid),
  UNIQUE KEY uq_cp_slug (platform_brand_id, slug),
  KEY ix_cp_pie (platform_brand_id, show_in_footer, sort_order),
  CONSTRAINT fk_cp_brand FOREIGN KEY (platform_brand_id) REFERENCES platform_brands(id) ON DELETE RESTRICT,
  -- `COLLATE utf8mb4_bin` porque `REGEXP` compara con la colacion de la columna
  -- y la de este proyecto es `_ci`: sin el, `Politica` pasaria (leccion de L-2a).
  CONSTRAINT ck_cp_slug CHECK (slug COLLATE utf8mb4_bin REGEXP '^[a-z0-9]([a-z0-9-]{0,58}[a-z0-9])?$'),
  CONSTRAINT ck_cp_titulo CHECK (CHAR_LENGTH(TRIM(title)) >= 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El texto, versionado. De una politica de privacidad hay que poder contestar
-- «.cual estaba vigente el dia que esta persona nos dio sus datos?», y esa
-- pregunta no se contesta con «la de ahora».
--
-- El cuerpo es MARKDOWN y no HTML: HTML crudo editable desde el panel es XSS
-- almacenado en la pagina mas publica del sitio.
CREATE TABLE content_page_versions (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid              CHAR(36)      NOT NULL,
  content_page_id   BIGINT UNSIGNED NOT NULL,
  version           VARCHAR(20)   NOT NULL,
  body_markdown     LONGTEXT      NOT NULL,
  content_sha256    CHAR(64)      NOT NULL,
  effective_from    DATE          NOT NULL,
  -- NULL = es la vigente. Publicar la siguiente cierra esta.
  effective_to      DATE          NULL,
  -- NULL = borrador. Un borrador se edita; una publicada se congela.
  published_at      DATETIME(3)   NULL,
  published_by_user_id BIGINT UNSIGNED NULL,
  -- §56: un supuesto legal se identifica EXPLICITAMENTE para revision juridica.
  -- Es un DATO, no una puerta: no impide publicar, lo dice.
  review_status     VARCHAR(20)   NOT NULL DEFAULT 'sin_revisar',
  review_note       VARCHAR(255)  NULL,
  created_at        DATETIME(3)   NULL,
  updated_at        DATETIME(3)   NULL,
  -- Columna puerta 36: UNA vigente por pagina. Dos publicadas y abiertas a la
  -- vez es un empate, y un empate aqui significa que «.que politica rige?» no
  -- tiene respuesta. Indice unico y no un COUNT(*) sin bloqueo.
  vigente_gate BIGINT UNSIGNED
    GENERATED ALWAYS AS (
      CASE WHEN published_at IS NOT NULL AND effective_to IS NULL
           THEN content_page_id ELSE NULL END
    ) STORED,
  UNIQUE KEY uq_cpv_uuid (uuid),
  UNIQUE KEY uq_cpv_version (content_page_id, version),
  UNIQUE KEY uq_cpv_vigente (vigente_gate),
  KEY ix_cpv_pagina (content_page_id, effective_from),
  KEY ix_cpv_publicador (published_by_user_id),
  CONSTRAINT fk_cpv_page FOREIGN KEY (content_page_id) REFERENCES content_pages(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cpv_publicador FOREIGN KEY (published_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_cpv_cuerpo CHECK (CHAR_LENGTH(TRIM(body_markdown)) >= 20),
  CONSTRAINT ck_cpv_huella CHECK (CHAR_LENGTH(content_sha256) = 64),
  CONSTRAINT ck_cpv_fechas CHECK (effective_to IS NULL OR effective_to >= effective_from),
  -- Publicar es un acto con responsable.
  CONSTRAINT ck_cpv_publicada CHECK (published_at IS NULL OR published_by_user_id IS NOT NULL),
  -- Un borrador no se cierra: nunca estuvo vigente.
  CONSTRAINT ck_cpv_borrador_abierto CHECK (published_at IS NOT NULL OR effective_to IS NULL),
  CONSTRAINT ck_cpv_revision CHECK (review_status IN ('sin_revisar','en_revision','revisado'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- L-2b: el historico no se solapa (`cpv_sin_solape`, generado por
-- App\Shared\Database\Periodo, no escrito a mano).
--
-- `uq_cpv_vigente` garantiza una sola version ABIERTA. Lo que no garantiza es que
-- el historico tenga una sola respuesta para una fecha PASADA: v1.0 del 1 de
-- enero al 30 de junio y v1.1 desde el 1 de marzo son dos versiones tapando
-- marzo. Y aqui esa pregunta es justo la que importa: «.que politica de
-- privacidad regia el dia que esta persona nos dio sus datos?». Dos respuestas
-- es no tener ninguna.
--
-- Solo entre versiones PUBLICADAS: un borrador no rige nada.
-- Va en disparador porque la regla mira OTRAS FILAS, y eso ningun CHECK lo
-- admite --tampoco en MySQL 8--.

DELIMITER //

CREATE TRIGGER `tg_cpv_sin_solape_ins`
BEFORE INSERT ON `content_page_versions`
FOR EACH ROW
BEGIN
    IF (NEW.`published_at` IS NOT NULL)
       AND EXISTS (
        SELECT 1 FROM `content_page_versions`
         WHERE `content_page_id` <=> NEW.`content_page_id`
           AND NEW.`effective_from` <= IFNULL(`effective_to`, '9999-12-31')
           AND `effective_from` <= IFNULL(NEW.`effective_to`, '9999-12-31')
           AND (published_at IS NOT NULL)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya hay una version de esa pagina vigente en esas fechas.';
    END IF;
END//

CREATE TRIGGER `tg_cpv_sin_solape_upd`
BEFORE UPDATE ON `content_page_versions`
FOR EACH ROW
BEGIN
    IF (NEW.`published_at` IS NOT NULL)
       AND EXISTS (
        SELECT 1 FROM `content_page_versions`
         WHERE `id` <> NEW.`id`
           AND `content_page_id` <=> NEW.`content_page_id`
           AND NEW.`effective_from` <= IFNULL(`effective_to`, '9999-12-31')
           AND `effective_from` <= IFNULL(NEW.`effective_to`, '9999-12-31')
           AND (published_at IS NOT NULL)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya hay una version de esa pagina vigente en esas fechas.';
    END IF;
END//

DELIMITER ;

-- ============================ D2 Core: los datos de la calle (L-2a)
-- Tabla propia y no mas columnas en `platform_brands`: aquella es IDENTIDAD
-- --como nos llamamos, de que color somos-- y esto es COMO NOS CONTACTAN.
-- Mismo reparto que `mail_settings` colgando de `integration_connections`.
CREATE TABLE site_settings (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  platform_brand_id BIGINT UNSIGNED NOT NULL,
  -- De donde salen razon social, RUC y domicilio en los textos legales. Se
  -- DECLARA: una politica de privacidad que nombra a la sociedad equivocada es
  -- un documento sin valor, y adivinarla es como se llega ahi.
  operator_legal_entity_id BIGINT UNSIGNED NULL,
  -- L-5 (`C-2`): el pais que sale MARCADO en los formularios de la calle. NULL
  -- significa «el de la sociedad operadora», que es un dato que ya existe y ya
  -- esta bien: la reserva no es una constante escrita en el codigo. Antes no
  -- habia ninguno y el navegador elegia el primero por orden alfabetico --Chile--
  -- asi que un negocio que arranca en Peru etiquetaba mal sus propios leads.
  default_country_id BIGINT UNSIGNED NULL,
  -- En E.164 porque viaja DENTRO de una URL `https://wa.me/...`: un espacio o
  -- un parentesis la rompen sin dar ningun error.
  whatsapp_phone    VARCHAR(20)   NULL,
  whatsapp_message  VARCHAR(300)  NULL,
  -- Distinto de `platform_brands.support_email`: aquel es a donde escribe quien
  -- YA es cliente; esto es lo que se pinta en la calle.
  contact_email     VARCHAR(255)  NULL,
  contact_phone     VARCHAR(30)   NULL,
  public_address    VARCHAR(255)  NULL,
  -- L-5 (§21): por donde sale la medicion de visitas. Enum de CODIGO --cada uno
  -- tiene su fragmento en `parciales/analitica`-- y el identificador entra
  -- DENTRO de un `<script>` de todas las paginas publicas, asi que se comprueba
  -- con COLLATE utf8mb4_bin: ahi una comilla no es una errata, es una inyeccion.
  analytics_provider VARCHAR(20)  NULL,
  analytics_id      VARCHAR(40)   NULL,
  created_at        DATETIME(3)   NULL,
  updated_at        DATETIME(3)   NULL,
  UNIQUE KEY uq_ss_marca (platform_brand_id),
  KEY ix_ss_operadora (operator_legal_entity_id),
  CONSTRAINT fk_ss_brand FOREIGN KEY (platform_brand_id) REFERENCES platform_brands(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ss_operadora FOREIGN KEY (operator_legal_entity_id) REFERENCES legal_entities(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ss_pais FOREIGN KEY (default_country_id) REFERENCES countries(id) ON DELETE RESTRICT,
  CONSTRAINT ck_ss_whatsapp CHECK (whatsapp_phone IS NULL OR whatsapp_phone REGEXP '^\\+[0-9]{8,15}$'),
  CONSTRAINT ck_ss_correo CHECK (contact_email IS NULL OR contact_email LIKE '%_@_%.__%'),
  CONSTRAINT ck_ss_mensaje CHECK (whatsapp_message IS NULL OR CHAR_LENGTH(TRIM(whatsapp_message)) >= 10),
  CONSTRAINT ck_ss_medidor CHECK (analytics_provider IS NULL OR analytics_provider IN ('ga4','gtm','meta','plausible')),
  CONSTRAINT ck_ss_medidor_id CHECK (analytics_id IS NULL OR analytics_id COLLATE utf8mb4_bin REGEXP '^[A-Za-z0-9._-]+$'),
  CONSTRAINT ck_ss_medidor_par CHECK ((analytics_provider IS NULL) = (analytics_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Las redes son FILAS y no columnas: el dia que exista una red nueva, seis
-- columnas `instagram_url`, `tiktok_url`... exigirian una migracion y un
-- despliegue para algo que es puro contenido (`DEC-190`).
CREATE TABLE social_links (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  platform_brand_id BIGINT UNSIGNED NOT NULL,
  -- Texto libre en minusculas. La plantilla dibuja el icono que conozca y, si
  -- no lo conoce, uno de enlace: una red nueva funciona el mismo dia.
  network           VARCHAR(30)   NOT NULL,
  label             VARCHAR(60)   NOT NULL,
  url               VARCHAR(255)  NOT NULL,
  sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  is_visible        TINYINT(1)    NOT NULL DEFAULT 1,
  created_at        DATETIME(3)   NULL,
  updated_at        DATETIME(3)   NULL,
  UNIQUE KEY uq_sl_red (platform_brand_id, network),
  KEY ix_sl_marca (platform_brand_id, is_visible, sort_order),
  CONSTRAINT fk_sl_brand FOREIGN KEY (platform_brand_id) REFERENCES platform_brands(id) ON DELETE RESTRICT,
  CONSTRAINT ck_sl_url CHECK (url LIKE 'https://%'),
  -- `COLLATE utf8mb4_bin` NO sobra: `REGEXP` compara con la colacion de la
  -- columna, y `utf8mb4_unicode_ci` es INSENSIBLE A MAYUSCULAS. Sin el,
  -- 'TikTok' casaba contra '^[a-z0-9_-]+$' y la regla decia una cosa y hacia
  -- otra. Lo cazo la suite de SQL al primer intento; ninguna prueba de PHP
  -- habria podido verlo.
  CONSTRAINT ck_sl_red CHECK (network COLLATE utf8mb4_bin REGEXP '^[a-z0-9_-]{2,30}$'),
  CONSTRAINT ck_sl_etiqueta CHECK (CHAR_LENGTH(TRIM(label)) >= 2)
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

-- 9.9c: un certificado caducado o reemplazado explica la firma de las facturas
-- de entonces. Borrarlo deja esas firmas sin poder explicarse --el mismo
-- argumento que tg_tax_no_delete en 9.9a--.
CREATE TRIGGER tg_cert_no_delete BEFORE DELETE ON signing_certificates
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Un certificado no se borra: explica la firma de lo ya emitido.';
END//

-- Y lo que dice el propio certificado no lo cambia nadie desde aqui: si el
-- material cambiara, la huella dejaria de corresponder con lo guardado y no
-- habria forma de saber CON QUE se firmo.
CREATE TRIGGER tg_cert_inmutable BEFORE UPDATE ON signing_certificates
FOR EACH ROW
BEGIN
  IF NOT (NEW.uuid <=> OLD.uuid)
     OR NOT (NEW.legal_entity_id <=> OLD.legal_entity_id)
     OR NOT (NEW.environment <=> OLD.environment)
     OR NOT (NEW.subject_name <=> OLD.subject_name)
     OR NOT (NEW.issuer_name <=> OLD.issuer_name)
     OR NOT (NEW.serial_number <=> OLD.serial_number)
     OR NOT (NEW.tax_id_number <=> OLD.tax_id_number)
     OR NOT (NEW.valid_from <=> OLD.valid_from)
     OR NOT (NEW.valid_to <=> OLD.valid_to)
     OR NOT (NEW.fingerprint_sha256 <=> OLD.fingerprint_sha256)
     OR NOT (NEW.pem_cipher <=> OLD.pem_cipher)
     OR NOT (NEW.source <=> OLD.source)
     OR NOT (NEW.uploaded_by_user_id <=> OLD.uploaded_by_user_id)
     OR NOT (NEW.uploaded_at <=> OLD.uploaded_at)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Un certificado no se reescribe: cargue el siguiente o revoquelo.';
  END IF;
END//

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

DELIMITER //

-- L-2b: una version PUBLICADA no se reescribe. Es el texto que alguien pudo
-- haber leido el dia que nos dio sus datos; corregirlo por debajo seria cambiar
-- la respuesta a una pregunta ya hecha. Se publica la siguiente.
CREATE TRIGGER `tg_cpv_inmutable`
BEFORE UPDATE ON `content_page_versions`
FOR EACH ROW
BEGIN
    IF OLD.`published_at` IS NOT NULL THEN
        IF NOT (NEW.`body_markdown` <=> OLD.`body_markdown`)
           OR NOT (NEW.`content_sha256` <=> OLD.`content_sha256`)
           OR NOT (NEW.`version` <=> OLD.`version`)
           OR NOT (NEW.`effective_from` <=> OLD.`effective_from`) THEN
            SIGNAL SQLSTATE '45000'
              SET MESSAGE_TEXT = 'Una version publicada no se reescribe: publique la siguiente.';
        END IF;
    END IF;
END//

DELIMITER ;
