-- LATAM Social - Fase 2, iteracion 2.12 - Contenido, publicacion y evidencia
SET NAMES utf8mb4;

-- Lo que un creador concreto debe entregar. Cuelga de la participacion
-- (2.2 P-03), no de la campana: dos creadores de la misma campana pueden tener
-- entregables distintos si estan en mercados distintos.
CREATE TABLE deliverables (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                  CHAR(36)      NOT NULL,
  campaign_creator_id   BIGINT UNSIGNED NOT NULL,
  campaign_requirement_id BIGINT UNSIGNED NOT NULL,
  sequence_number       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status                VARCHAR(20)   NOT NULL DEFAULT 'pending',
  due_on                DATE          NOT NULL,
  submitted_at          DATETIME(3)   NULL,
  approved_at           DATETIME(3)   NULL,
  created_at            DATETIME(3)   NULL,
  updated_at            DATETIME(3)   NULL,
  UNIQUE KEY uq_del_uuid (uuid),
  -- Un requisito de 3 reels produce 3 entregables numerados.
  UNIQUE KEY uq_del_sequence (campaign_creator_id, campaign_requirement_id, sequence_number),
  KEY ix_del_participation (campaign_creator_id, status),
  KEY ix_del_requirement (campaign_requirement_id),
  KEY ix_del_due (due_on, status),
  CONSTRAINT fk_del_participation FOREIGN KEY (campaign_creator_id) REFERENCES campaign_creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_del_requirement FOREIGN KEY (campaign_requirement_id) REFERENCES campaign_requirements(id) ON DELETE RESTRICT,
  CONSTRAINT ck_del_status CHECK (status IN ('pending','in_production','submitted','in_review','changes_requested','approved','published','verified','cancelled')),
  CONSTRAINT ck_del_sequence CHECK (sequence_number >= 1),
  CONSTRAINT ck_del_approved CHECK (approved_at IS NULL OR submitted_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only: cada reenvio es una version nueva, nunca una sobrescritura.
-- Es lo que permite responder "cuantas vueltas costo esto".
CREATE TABLE deliverable_versions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36)      NOT NULL,
  deliverable_id BIGINT UNSIGNED NOT NULL,
  version_number SMALLINT UNSIGNED NOT NULL,
  file_id        BIGINT UNSIGNED NULL,
  external_url   VARCHAR(500)  NULL,
  caption        LONGTEXT      NULL,
  creator_notes  VARCHAR(500)  NULL,
  submitted_at   DATETIME(3)   NOT NULL,
  UNIQUE KEY uq_dv_uuid (uuid),
  UNIQUE KEY uq_dv_number (deliverable_id, version_number),
  KEY ix_dv_deliverable (deliverable_id, submitted_at),
  KEY ix_dv_file (file_id),
  CONSTRAINT fk_dv_deliverable FOREIGN KEY (deliverable_id) REFERENCES deliverables(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dv_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT ck_dv_number CHECK (version_number >= 1),
  -- Una version tiene que traer ALGO: archivo o enlace. Las dos vacias, no.
  CONSTRAINT ck_dv_content CHECK (file_id IS NOT NULL OR external_url IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La revision. Append-only: un veredicto no se edita, se emite otro.
CREATE TABLE content_reviews (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                   CHAR(36)      NOT NULL,
  deliverable_version_id BIGINT UNSIGNED NOT NULL,
  reviewer_user_id       BIGINT UNSIGNED NULL,
  -- Quien revisa: nosotros o el cliente. Cambia quien consume ronda.
  reviewer_side          VARCHAR(10)   NOT NULL DEFAULT 'platform',
  outcome                VARCHAR(20)   NOT NULL,
  comments               LONGTEXT      NULL,
  -- Solo las correcciones consumen ronda de las incluidas en el precio.
  consumes_round         TINYINT(1)    NOT NULL DEFAULT 0,
  reviewed_at            DATETIME(3)   NOT NULL,
  UNIQUE KEY uq_cvw_uuid (uuid),
  KEY ix_cvw_version (deliverable_version_id, reviewed_at),
  KEY ix_cvw_reviewer (reviewer_user_id),
  CONSTRAINT fk_cvw_version FOREIGN KEY (deliverable_version_id) REFERENCES deliverable_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cvw_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_cvw_outcome CHECK (outcome IN ('approved','changes_requested','rejected')),
  CONSTRAINT ck_cvw_side CHECK (reviewer_side IN ('platform','client')),
  -- Una aprobacion no gasta ronda. Solo la correccion.
  CONSTRAINT ck_cvw_round CHECK (consumes_round = 0 OR outcome = 'changes_requested')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La publicacion real. El negocio lo pidio explicito: el creador adjunta el
-- enlace publicado y la aplicacion debe poder validar que ese enlace es de la
-- red que dice (platforms.url_pattern, iteracion 2.6).
CREATE TABLE publications (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36)      NOT NULL,
  deliverable_id BIGINT UNSIGNED NOT NULL,
  platform_id    BIGINT UNSIGNED NOT NULL,
  url            VARCHAR(500)  NOT NULL,
  -- URL normalizada (sin parametros de campana ni utm) para detectar que dos
  -- creadores reclaman el MISMO post. Se guarda su hash: la URL cruda puede
  -- pasar de 500 caracteres pero el hash siempre mide lo mismo y se indexa bien.
  url_fingerprint CHAR(64)     NOT NULL,
  external_post_id VARCHAR(120) NULL,
  published_at   DATETIME(3)   NOT NULL,
  -- Se calcula al verificar: published_at + permanence_days del requisito.
  permanence_until DATE        NULL,
  status         VARCHAR(20)   NOT NULL DEFAULT 'reported',
  verified_at    DATETIME(3)   NULL,
  verified_by_user_id BIGINT UNSIGNED NULL,
  removed_at     DATETIME(3)   NULL,
  created_at     DATETIME(3)   NULL,
  updated_at     DATETIME(3)   NULL,
  UNIQUE KEY uq_pub_uuid (uuid),
  -- El mismo post no puede reclamarse dos veces.
  UNIQUE KEY uq_pub_fingerprint (url_fingerprint),
  KEY ix_pub_deliverable (deliverable_id, status),
  KEY ix_pub_platform (platform_id, published_at),
  KEY ix_pub_permanence (permanence_until, status),
  KEY ix_pub_verifier (verified_by_user_id),
  CONSTRAINT fk_pub_deliverable FOREIGN KEY (deliverable_id) REFERENCES deliverables(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pub_platform FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pub_verifier FOREIGN KEY (verified_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_pub_status CHECK (status IN ('reported','verified','rejected','removed','expired')),
  CONSTRAINT ck_pub_verified CHECK (status <> 'verified' OR (verified_at IS NOT NULL AND verified_by_user_id IS NOT NULL)),
  CONSTRAINT ck_pub_removed CHECK (status <> 'removed' OR removed_at IS NOT NULL),
  CONSTRAINT ck_pub_fingerprint CHECK (CHAR_LENGTH(url_fingerprint) = 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La evidencia. Append-only y con checksum: los posts se borran, y esto es lo
-- unico que le queda a la marca para demostrar que la campana se ejecuto.
CREATE TABLE publication_evidence (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36)      NOT NULL,
  publication_id BIGINT UNSIGNED NOT NULL,
  evidence_type  VARCHAR(20)   NOT NULL,
  file_id        BIGINT UNSIGNED NULL,
  http_status    SMALLINT UNSIGNED NULL,
  raw_payload    LONGTEXT      NULL,
  captured_at    DATETIME(3)   NOT NULL,
  captured_by_user_id BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_pev_uuid (uuid),
  KEY ix_pev_publication (publication_id, captured_at),
  KEY ix_pev_file (file_id),
  KEY ix_pev_user (captured_by_user_id),
  CONSTRAINT fk_pev_publication FOREIGN KEY (publication_id) REFERENCES publications(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pev_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pev_user FOREIGN KEY (captured_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_pev_type CHECK (evidence_type IN ('screenshot','api_snapshot','http_check','archive','manual')),
  CONSTRAINT ck_pev_payload CHECK (raw_payload IS NULL OR JSON_VALID(raw_payload)),
  -- Una evidencia sin nada que ensenar no es evidencia.
  CONSTRAINT ck_pev_content CHECK (file_id IS NOT NULL OR raw_payload IS NOT NULL OR http_status IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comprobaciones de permanencia. Append-only. Alimenta el evento
-- PermanenceCheckPassed que 2.2 P-12 marco como pendiente de crear.
CREATE TABLE permanence_checks (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  publication_id BIGINT UNSIGNED NOT NULL,
  checked_at     DATETIME(3)   NOT NULL,
  is_live        TINYINT(1)    NOT NULL,
  http_status    SMALLINT UNSIGNED NULL,
  evidence_id    BIGINT UNSIGNED NULL,
  notes          VARCHAR(255)  NULL,
  KEY ix_pc_publication (publication_id, checked_at),
  KEY ix_pc_live (is_live, checked_at),
  KEY ix_pc_evidence (evidence_id),
  CONSTRAINT fk_pc_publication FOREIGN KEY (publication_id) REFERENCES publications(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pc_evidence FOREIGN KEY (evidence_id) REFERENCES publication_evidence(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TRIGGER tg_pev_no_delete BEFORE DELETE ON publication_evidence
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'publication_evidence no admite borrado: es la prueba de que se publico, y de ella depende que se pague.';
END//

DELIMITER ;
