-- LATAM Social - Fase 2, iteracion 2.9 - Cliente
-- DEC-016: nunca "Brand" a secas ni "Organization" a secas. Los cuatro conceptos
-- organizacionales no se mezclan: PlatformBrand / LegalEntity / ClientOrganization / ClientBrand.
SET NAMES utf8mb4;

-- ==================================== D4 Client: el grupo cliente
CREATE TABLE client_organizations (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid              CHAR(36)      NOT NULL,
  -- Nombre con el que se le conoce; la razon social vive en el perfil fiscal,
  -- que es por pais (2.2 P-02) y puede ser distinta en cada uno.
  commercial_name   VARCHAR(160)  NOT NULL,
  client_code       VARCHAR(20)   NOT NULL,
  country_id        BIGINT UNSIGNED NOT NULL,
  website           VARCHAR(255)  NULL,
  industry_category_id BIGINT UNSIGNED NULL,
  status            VARCHAR(15)   NOT NULL DEFAULT 'prospect',
  -- Ejecutivo responsable. RESTRICT: un usuario no se borra, se desactiva.
  owner_user_id     BIGINT UNSIGNED NULL,
  created_at        DATETIME(3)   NULL,
  updated_at        DATETIME(3)   NULL,
  UNIQUE KEY uq_co_uuid (uuid),
  UNIQUE KEY uq_co_code (client_code),
  KEY ix_co_status (status, commercial_name),
  KEY ix_co_country (country_id),
  KEY ix_co_owner (owner_user_id),
  KEY ix_co_industry (industry_category_id),
  CONSTRAINT fk_co_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_co_industry FOREIGN KEY (industry_category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_co_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_co_status CHECK (status IN ('prospect','active','inactive','blacklisted'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================ D4 Client: perfil fiscal por pais (2.2 P-02)
-- El Grupo ABC factura desde su filial peruana y desde la mexicana. Sin esto
-- habria que crear dos clientes distintos y el historico quedaria partido.
CREATE TABLE client_tax_profiles (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_organization_id BIGINT UNSIGNED NOT NULL,
  country_id        BIGINT UNSIGNED NOT NULL,
  legal_name        VARCHAR(200)  NOT NULL,
  tax_id_type       VARCHAR(20)   NOT NULL,
  tax_id_number     VARCHAR(40)   NOT NULL,
  address_line1     VARCHAR(180)  NOT NULL,
  address_line2     VARCHAR(180)  NULL,
  city              VARCHAR(100)  NOT NULL,
  region            VARCHAR(100)  NULL,
  postal_code       VARCHAR(20)   NULL,
  billing_email     VARCHAR(255)  NULL,
  payment_term_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  valid_from        DATE          NOT NULL,
  valid_to          DATE          NULL,
  created_at        DATETIME(3)   NULL,
  updated_at        DATETIME(3)   NULL,
  current_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN valid_to IS NULL THEN 1 ELSE NULL END) STORED,
  -- Un solo perfil vigente por cliente y pais.
  UNIQUE KEY uq_ctxp_current (current_gate, client_organization_id, country_id),
  -- Y el mismo identificador fiscal no puede estar vigente en dos clientes:
  -- es lo que impide duplicar un cliente por descuido comercial.
  UNIQUE KEY uq_ctxp_taxid (current_gate, country_id, tax_id_type, tax_id_number),
  KEY ix_ctxp_client (client_organization_id),
  KEY ix_ctxp_country (country_id),
  CONSTRAINT fk_ctxp_client FOREIGN KEY (client_organization_id) REFERENCES client_organizations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ctxp_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE RESTRICT,
  CONSTRAINT ck_ctxp_dates CHECK (valid_to IS NULL OR valid_to >= valid_from),
  CONSTRAINT ck_ctxp_term CHECK (payment_term_days BETWEEN 0 AND 180)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================================== D4 Client: la marca del cliente
CREATE TABLE client_brands (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid              CHAR(36)      NOT NULL,
  client_organization_id BIGINT UNSIGNED NOT NULL,
  name              VARCHAR(120)  NOT NULL,
  slug              VARCHAR(140)  NOT NULL,
  logo_file_id      BIGINT UNSIGNED NULL,
  website           VARCHAR(255)  NULL,
  brand_guidelines_file_id BIGINT UNSIGNED NULL,
  status            VARCHAR(15)   NOT NULL DEFAULT 'active',
  created_at        DATETIME(3)   NULL,
  updated_at        DATETIME(3)   NULL,
  UNIQUE KEY uq_cb_uuid (uuid),
  UNIQUE KEY uq_cb_slug (slug),
  UNIQUE KEY uq_cb_name (client_organization_id, name),
  KEY ix_cb_client (client_organization_id, status),
  KEY ix_cb_logo (logo_file_id),
  KEY ix_cb_guidelines (brand_guidelines_file_id),
  CONSTRAINT fk_cb_client FOREIGN KEY (client_organization_id) REFERENCES client_organizations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cb_logo FOREIGN KEY (logo_file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cb_guidelines FOREIGN KEY (brand_guidelines_file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT ck_cb_status CHECK (status IN ('active','paused','archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== D4 Client: nichos de la marca (para el matching)
CREATE TABLE client_brand_categories (
  client_brand_id BIGINT UNSIGNED NOT NULL,
  category_id     BIGINT UNSIGNED NOT NULL,
  created_at      DATETIME(3) NULL,
  PRIMARY KEY (client_brand_id, category_id),
  KEY ix_cbc_category (category_id),
  CONSTRAINT fk_cbc_brand FOREIGN KEY (client_brand_id) REFERENCES client_brands(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cbc_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================== D4 Client: las personas (2.3 N-01)
-- Contact = una PERSONA en el cliente. User = unas CREDENCIALES.
-- No hay ClientUser: un usuario del cliente es Contact + User enlazados.
CREATE TABLE contacts (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid              CHAR(36)      NOT NULL,
  client_organization_id BIGINT UNSIGNED NOT NULL,
  -- 1:0..1 con users. Nulo mientras la persona no necesite entrar al sistema,
  -- que es la mayoria de los casos.
  user_id           BIGINT UNSIGNED NULL,
  full_name         VARCHAR(160)  NOT NULL,
  -- OJO: no es lo mismo que users.email. Este es el canal comercial y puede ser
  -- compartido ("facturacion@cliente.com"); aquel es la identidad de acceso.
  contact_email     VARCHAR(255)  NOT NULL,
  phone             VARCHAR(30)   NULL,
  position          VARCHAR(120)  NULL,
  contact_type      VARCHAR(15)   NOT NULL DEFAULT 'commercial',
  is_primary        TINYINT(1)    NOT NULL DEFAULT 0,
  status            VARCHAR(15)   NOT NULL DEFAULT 'active',
  created_at        DATETIME(3)   NULL,
  updated_at        DATETIME(3)   NULL,
  -- Un solo contacto principal por cliente y tipo: a quien se le escribe.
  primary_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN is_primary = 1 AND status = 'active' THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_contacts_uuid (uuid),
  UNIQUE KEY uq_contacts_user (user_id),
  UNIQUE KEY uq_contacts_primary (primary_gate, client_organization_id, contact_type),
  KEY ix_contacts_client (client_organization_id, status),
  KEY ix_contacts_email (contact_email),
  CONSTRAINT fk_contacts_client FOREIGN KEY (client_organization_id) REFERENCES client_organizations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_contacts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_contacts_type CHECK (contact_type IN ('commercial','billing','legal','operations')),
  CONSTRAINT ck_contacts_status CHECK (status IN ('active','inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
