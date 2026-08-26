-- LATAM Social - Fase 4, iteracion 4.9 - Correo: plantillas y registro de envios.
SET NAMES utf8mb4;

-- Versionadas y con vigencia, igual que `terms_versions`. Lo que se le envio a
-- alguien tiene que poder demostrarse anos despues, y una plantilla editable
-- convierte «esto es lo que le mandamos» en «esto es lo que le mandariamos hoy».
CREATE TABLE email_templates (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid               CHAR(36)      NOT NULL,
  -- El nombre estable del aviso: `creator.tax_profile_changed`. Lo usa el
  -- codigo; el idioma y la version los resuelve el servicio.
  code               VARCHAR(60)   NOT NULL,
  locale             VARCHAR(10)   NOT NULL,
  version            VARCHAR(20)   NOT NULL,
  subject            VARCHAR(200)  NOT NULL,
  body               LONGTEXT      NOT NULL,
  -- Las variables que la plantilla espera. Se deducen del texto al publicar:
  -- pedirlas aparte garantiza que un dia la lista y el texto digan cosas
  -- distintas.
  variables          JSON          NULL,
  -- Huella de asunto + cuerpo. Si alguien edita una version ya usada, deja de
  -- cuadrar con la que guardo el registro de envio.
  content_sha256     CHAR(64)      NOT NULL,
  effective_from     DATE          NOT NULL,
  -- NULL = es la ultima publicada. La VIGENTE se resuelve por periodo, no por
  -- esta columna: una version programada para dentro de un mes cierra a la
  -- anterior y todavia no empieza, y mirar solo la puerta dejaria un mes sin
  -- ninguna vigente.
  effective_to       DATE          NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at         DATETIME(3)   NULL,
  updated_at         DATETIME(3)   NULL,
  current_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN effective_to IS NULL THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_et_uuid (uuid),
  UNIQUE KEY uq_et_version (code, locale, version),
  -- Una sola version ABIERTA por codigo e idioma.
  UNIQUE KEY uq_et_vigente (current_gate, code, locale),
  KEY ix_et_vigencia (code, locale, effective_from),
  KEY ix_et_autor (created_by_user_id),
  CONSTRAINT fk_et_autor FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_et_dates CHECK (effective_to IS NULL OR effective_to >= effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Que se envio, a quien y cuando. El CUERPO no esta aqui a proposito: lleva el
-- nombre de la persona, a veces importes y a veces datos fiscales, y guardarlo
-- convierte esta tabla en una segunda copia de la ficha del creador. Queda su
-- huella, y con la version --que es inmutable-- basta para demostrar que texto
-- salio.
CREATE TABLE email_log (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid               CHAR(36)      NOT NULL,
  email_template_id  BIGINT UNSIGNED NULL,
  -- Se COPIAN. `BR-LE-001` aplicado al correo: dentro de dos anos, «que texto
  -- se le envio» lo responde esta fila, no una consulta a la plantilla de
  -- entonces.
  template_code      VARCHAR(60)   NOT NULL,
  template_version   VARCHAR(20)   NOT NULL,
  template_locale    VARCHAR(10)   NOT NULL,
  -- El idioma que se PIDIO. Distinto del anterior cuando hubo caida: de la
  -- diferencia sale la lista de plantillas que faltan por traducir.
  locale_requested   VARCHAR(10)   NOT NULL,
  to_email           VARCHAR(255)  NOT NULL,
  subject            VARCHAR(200)  NOT NULL,
  body_sha256        CHAR(64)      NOT NULL,
  status             VARCHAR(15)   NOT NULL DEFAULT 'queued',
  attempts           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error         VARCHAR(500)  NULL,
  related_type       VARCHAR(30)   NULL,
  related_id         BIGINT UNSIGNED NULL,
  queued_at          DATETIME(3)   NOT NULL,
  sent_at            DATETIME(3)   NULL,
  failed_at          DATETIME(3)   NULL,
  created_at         DATETIME(3)   NULL,
  updated_at         DATETIME(3)   NULL,
  UNIQUE KEY uq_el_uuid (uuid),
  KEY ix_el_estado (status, queued_at),
  KEY ix_el_destinatario (to_email, queued_at),
  KEY ix_el_relacionado (related_type, related_id),
  KEY ix_el_plantilla (email_template_id),
  CONSTRAINT fk_el_plantilla FOREIGN KEY (email_template_id) REFERENCES email_templates(id) ON DELETE RESTRICT,
  CONSTRAINT ck_el_status CHECK (status IN ('queued','sent','failed','cancelled')),
  CONSTRAINT ck_el_sent CHECK (status <> 'sent' OR sent_at IS NOT NULL),
  -- Un `failed` sin motivo obliga a mirar el log del servidor, que es
  -- exactamente lo que esta tabla existe para evitar.
  CONSTRAINT ck_el_failed CHECK (status <> 'failed' OR (failed_at IS NOT NULL AND last_error IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 4.9: dos versiones de la misma plantilla no pueden cubrir un mismo dia.
--
-- `uq_et_vigente` garantiza una sola ABIERTA; esto garantiza que tampoco se
-- solapen en el pasado. Sin la segunda mitad, cerrar la 1.0 el mismo dia en que
-- empieza la 2.0 --`effective_to` es INCLUSIVO-- dejaria las dos vigentes ese
-- dia, y «que texto se envio el 1 de junio» tendria dos respuestas.
--
-- Es el error de un dia, con once apariciones en este proyecto. Lo compila
-- `Periodo::sinSolape`, igual que las otras ocho reglas de periodo del esquema:
-- escribirlo a mano es exactamente donde se cuela.
-- ===========================================================================

DELIMITER //

CREATE TRIGGER `tg_et_sin_solape_ins`
BEFORE INSERT ON `email_templates`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM `email_templates`
         WHERE `code` <=> NEW.`code`
           AND `locale` <=> NEW.`locale`
           AND NEW.`effective_from` <= IFNULL(`effective_to`, '9999-12-31')
           AND `effective_from` <= IFNULL(NEW.`effective_to`, '9999-12-31')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya hay una version de esa plantilla vigente en esas fechas: cierre la anterior el dia antes.';
    END IF;
END//

CREATE TRIGGER `tg_et_sin_solape_upd`
BEFORE UPDATE ON `email_templates`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM `email_templates`
         WHERE `id` <> NEW.`id`
           AND `code` <=> NEW.`code`
           AND `locale` <=> NEW.`locale`
           AND NEW.`effective_from` <= IFNULL(`effective_to`, '9999-12-31')
           AND `effective_from` <= IFNULL(NEW.`effective_to`, '9999-12-31')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya hay una version de esa plantilla vigente en esas fechas: cierre la anterior el dia antes.';
    END IF;
END//

DELIMITER ;
