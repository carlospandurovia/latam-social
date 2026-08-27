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
  -- 8.3: las rondas del CLIENTE gastadas en ESTA pieza. Estaban en
  -- `campaign_creators` --dos por creador-- y eso dejaba sin ninguna a las
  -- piezas siguientes en cuanto una se llevaba las dos. Las internas no cuentan:
  -- no se le cobran a nadie.
  revision_rounds_used  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  due_on                DATE          NOT NULL,
  submitted_at          DATETIME(3)   NULL,
  approved_at           DATETIME(3)   NULL,
  -- `approved_at` decia CUANDO y no QUIEN, y de esta firma cuelga que el
  -- contenido salga hacia el cliente.
  approved_by_user_id   BIGINT UNSIGNED NULL,
  -- 8.2: QUE version se aprobo. «La ultima» sale de un MAX() y guardarla seria
  -- una copia que se puede desviar; la aprobada no es derivable sin recorrer
  -- `content_reviews`, y es el dato del que cuelgan 8.6 --se publica lo
  -- aprobado-- y 8.7 --se archiva evidencia de eso--.
  approved_version_id   BIGINT UNSIGNED NULL,
  created_at            DATETIME(3)   NULL,
  updated_at            DATETIME(3)   NULL,
  UNIQUE KEY uq_del_uuid (uuid),
  -- Un requisito de 3 reels produce 3 entregables numerados.
  UNIQUE KEY uq_del_sequence (campaign_creator_id, campaign_requirement_id, sequence_number),
  KEY ix_del_participation (campaign_creator_id, status),
  KEY ix_del_requirement (campaign_requirement_id),
  KEY ix_del_due (due_on, status),
  KEY ix_del_aprobador (approved_by_user_id),
  KEY ix_del_approved_version (approved_version_id),
  CONSTRAINT fk_del_participation FOREIGN KEY (campaign_creator_id) REFERENCES campaign_creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_del_requirement FOREIGN KEY (campaign_requirement_id) REFERENCES campaign_requirements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_del_aprobador FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_del_status CHECK (status IN ('pending','in_production','submitted','in_review','changes_requested','approved','published','verified','cancelled')),
  CONSTRAINT ck_del_sequence CHECK (sequence_number >= 1),
  CONSTRAINT ck_del_approved CHECK (approved_at IS NULL OR submitted_at IS NOT NULL),
  -- 8.1: la otra mitad. `ck_del_approved` exigia que aprobado implicara
  -- entregado; faltaba que ENTREGADO implicara decir cuando.
  CONSTRAINT ck_del_submitted CHECK (status NOT IN ('submitted','in_review','changes_requested','approved','published','verified') OR submitted_at IS NOT NULL),
  -- Un plazo en el pasado nace vencido y no es un plazo: es un error de calculo
  -- que nadie mira hasta que la lista entera sale en rojo.
  CONSTRAINT ck_del_due_futuro CHECK (due_on >= DATE(created_at) OR created_at IS NULL),
  -- 8.3: aprobado dice tambien QUIEN.
  CONSTRAINT ck_del_aprobador CHECK (approved_at IS NULL OR approved_by_user_id IS NOT NULL),
  -- 8.2: aprobado y puntero van juntos o no van. Un dato que a veces esta es
  -- igual de util que no tenerlo.
  CONSTRAINT ck_del_approved_version CHECK (
    (approved_at IS NULL AND approved_version_id IS NULL)
    OR (approved_at IS NOT NULL AND approved_version_id IS NOT NULL))
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
  -- 8.1: quien la mando. El creador se deduce de la participacion, pero una
  -- version la puede subir el equipo en su nombre --pasa-- y entonces «.quien
  -- mando esto?» tiene que responderlo la fila.
  submitted_by_user_id BIGINT UNSIGNED NULL,
  submitted_ip   VARBINARY(16) NULL,
  created_at     DATETIME(3)   NULL,
  UNIQUE KEY uq_dv_uuid (uuid),
  KEY ix_dv_autor (submitted_by_user_id),
  UNIQUE KEY uq_dv_number (deliverable_id, version_number),
  -- 8.2: lo que hace posible la clave ajena COMPUESTA de `deliverables`. InnoDB
  -- necesita un indice que empiece por las columnas en el orden de la referencia.
  UNIQUE KEY uq_dv_id_deliverable (id, deliverable_id),
  KEY ix_dv_deliverable (deliverable_id, submitted_at),
  KEY ix_dv_file (file_id),
  CONSTRAINT fk_dv_deliverable FOREIGN KEY (deliverable_id) REFERENCES deliverables(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dv_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE RESTRICT,
  -- CON LAS DEMAS FORANEAS, no entre CHECKs. `generar-triggers.py` quita los
  -- CHECK para hacer la copia que imita a Percona 5.7, y una foranea que quede
  -- entre dos CHECKs se queda sin la coma de delante: 1064. Segunda vez.
  CONSTRAINT fk_dv_autor FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_dv_number CHECK (version_number >= 1),
  -- Una version tiene que traer ALGO: archivo o enlace. Las dos vacias, no.
  CONSTRAINT ck_dv_content CHECK (file_id IS NOT NULL OR external_url IS NOT NULL),
  -- 8.1: por HTTPS. Un `http://` en un correo es una invitacion a que alguien
  -- lo intercepte, y `javascript:` o `data:` quedan fuera por el mismo filtro
  -- --esos si son un problema: la URL se pinta donde alguien la va a pulsar--.
  CONSTRAINT ck_dv_url_https CHECK (external_url IS NULL OR external_url LIKE 'https://%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8.2: el puntero a la version aprobada, en un ALTER y no dentro del CREATE.
-- Las dos tablas se apuntan la una a la otra y una de las dos referencias tiene
-- que ir despues; aqui `deliverables` se crea primero porque `deliverable_versions`
-- cuelga de ella.
--
-- COMPUESTA a proposito. Una clave simple garantizaria que la version existe;
-- esta garantiza que es DE ESTE entregable. Sin eso, un UPDATE mal escrito deja
-- el entregable de Ana apuntando a la version aprobada del de Luis, y la fila
-- sigue siendo valida para la base. Mismo patron que `fk_ccr_market_campaign` (7.3).
ALTER TABLE deliverables
  ADD CONSTRAINT fk_del_approved_version FOREIGN KEY (approved_version_id, id)
  REFERENCES deliverable_versions(id, deliverable_id) ON DELETE RESTRICT;

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
  -- 8.3: esta ronda va POR ENCIMA de las incluidas en el precio, y entonces hay
  -- que DECIDIR y FIRMAR. Sin esto, «se paso de rondas» es una nota que nadie
  -- factura. El cargo NO va a `campaign_costs`: eso es lo que gastamos nosotros
  -- y resta del margen; una ronda de mas facturada al cliente es ingreso.
  over_included          TINYINT(1)    NOT NULL DEFAULT 0,
  billing_decision       VARCHAR(10)   NULL,
  authorized_by_user_id  BIGINT UNSIGNED NULL,
  reviewed_at            DATETIME(3)   NOT NULL,
  reviewed_ip            VARBINARY(16) NULL,
  -- Sin `updated_at` a proposito: append-only. `created_at` si, porque
  -- `reviewed_at` es el momento del veredicto --puede venir de fuera-- y este es
  -- el momento en que entro la fila.
  created_at             DATETIME(3)   NULL,
  UNIQUE KEY uq_cvw_uuid (uuid),
  KEY ix_cvw_version (deliverable_version_id, reviewed_at),
  KEY ix_cvw_reviewer (reviewer_user_id),
  KEY ix_cvw_autorizador (authorized_by_user_id),
  CONSTRAINT fk_cvw_version FOREIGN KEY (deliverable_version_id) REFERENCES deliverable_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cvw_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cvw_autorizador FOREIGN KEY (authorized_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  -- 8.2: `reopened`. Reabrir no deshace la aprobacion: la deja donde estaba y
  -- anade una fila que dice por que se volvio atras. Misma forma que la
  -- anulacion de un perfil fiscal en 3.11.
  CONSTRAINT ck_cvw_outcome CHECK (outcome IN ('approved','changes_requested','rejected','reopened')),
  CONSTRAINT ck_cvw_side CHECK (reviewer_side IN ('platform','client')),
  -- Una aprobacion no gasta ronda. Solo la correccion.
  CONSTRAINT ck_cvw_round CHECK (consumes_round = 0 OR outcome = 'changes_requested'),
  -- 8.3. Pedir cambios exige DECIR CUALES: una correccion sin texto le llega al
  -- creador como «hazlo otra vez» y garantiza una vuelta mas, justo lo que las
  -- rondas cuentan.
  CONSTRAINT ck_cvw_comments CHECK (outcome NOT IN ('changes_requested','reopened')
      OR CHAR_LENGTH(TRIM(COALESCE(comments,''))) >= 10),
  CONSTRAINT ck_cvw_over CHECK (over_included = 0
      OR (billing_decision IS NOT NULL AND authorized_by_user_id IS NOT NULL)),
  CONSTRAINT ck_cvw_billing CHECK (over_included = 1
      OR (billing_decision IS NULL AND authorized_by_user_id IS NULL)),
  CONSTRAINT ck_cvw_billing_valor CHECK (billing_decision IS NULL
      OR billing_decision IN ('charge','absorb')),
  -- Lo que se pasa de lo incluido es siempre una ronda del cliente: las internas
  -- no cuentan contra el precio, asi que no pueden pasarse de el.
  CONSTRAINT ck_cvw_over_es_ronda CHECK (over_included = 0 OR consumes_round = 1),
  -- Una revision NUESTRA la firma alguien. La del cliente puede no tener
  -- usuario: en 8.5 la escribe un enlace firmado, sin cuenta detras.
  CONSTRAINT ck_cvw_firma CHECK (reviewer_side <> 'platform' OR reviewer_user_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La publicacion real. El negocio lo pidio explicito: el creador adjunta el
-- enlace publicado y la aplicacion debe poder validar que ese enlace es de la
-- red que dice (platforms.url_pattern, iteracion 2.6).
CREATE TABLE publications (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36)      NOT NULL,
  deliverable_id BIGINT UNSIGNED NOT NULL,
  -- 8.6: QUE version se publico. Es un SNAPSHOT, igual que `amount_snapshot` en
  -- 7.6: registra lo que se publico ENTONCES y sobrevive a que el entregable se
  -- reabra y se apruebe otra version despues. `deliverable_id` solo no contesta
  -- la pregunta que importa cuando el cliente reclama.
  deliverable_version_id BIGINT UNSIGNED NOT NULL,
  platform_id    BIGINT UNSIGNED NOT NULL,
  url            VARCHAR(500)  NOT NULL,
  -- URL normalizada (sin parametros de campana ni utm) para detectar que dos
  -- creadores reclaman el MISMO post. Se guarda su hash: la URL cruda puede
  -- pasar de 500 caracteres pero el hash siempre mide lo mismo y se indexa bien.
  url_fingerprint CHAR(64)     NOT NULL,
  external_post_id VARCHAR(120) NULL,
  published_at   DATETIME(3)   NOT NULL,
  -- Quien lo reporto: el creador desde su portal, o alguien del equipo por el.
  reported_by_user_id BIGINT UNSIGNED NULL,
  reported_ip    VARBINARY(16) NULL,
  -- Se calcula al verificar: published_at + permanence_days del requisito.
  permanence_until DATE        NULL,
  status         VARCHAR(20)   NOT NULL DEFAULT 'reported',
  verified_at    DATETIME(3)   NULL,
  verified_by_user_id BIGINT UNSIGNED NULL,
  -- 8.7: POR QUE se rechazo. `ck_pub_rejected` (8.6) solo exigia el CUANDO, y
  -- el por que es lo que el creador necesita para arreglarlo.
  rejected_reason VARCHAR(255) NULL,
  removed_at     DATETIME(3)   NULL,
  created_at     DATETIME(3)   NULL,
  updated_at     DATETIME(3)   NULL,
  viva_gate      TINYINT UNSIGNED GENERATED ALWAYS AS
                   (CASE WHEN status <> 'rejected' THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_pub_uuid (uuid),
  -- El mismo post no puede reclamarse dos veces --pero solo cuentan las VIVAS--.
  --
  -- 8.7: la unicidad era GLOBAL y tenia un agujero que aparecio al conectar el
  -- rechazo. Si una publicacion se rechaza porque el enlace no lleva a ningun
  -- post, el creador arregla el post y vuelve a registrar el MISMO enlace, que
  -- es lo que se le pide, y se estrellaba contra la clave con un 1062. Una fila
  -- rechazada no reclama nada: se miro y no valia.
  --
  -- Decimoquinta columna puerta del esquema.
  UNIQUE KEY uq_pub_fingerprint (viva_gate, url_fingerprint),
  KEY ix_pub_deliverable (deliverable_id, status),
  KEY ix_pub_platform (platform_id, published_at),
  KEY ix_pub_permanence (permanence_until, status),
  KEY ix_pub_verifier (verified_by_user_id),
  KEY ix_pub_version (deliverable_version_id),
  KEY ix_pub_reporter (reported_by_user_id),
  CONSTRAINT fk_pub_deliverable FOREIGN KEY (deliverable_id) REFERENCES deliverables(id) ON DELETE RESTRICT,
  -- COMPUESTA, como el puntero de 8.2 y por lo mismo: una simple diria que la
  -- version existe; esta dice que es DEL ENTREGABLE que se publica.
  CONSTRAINT fk_pub_version FOREIGN KEY (deliverable_version_id, deliverable_id)
    REFERENCES deliverable_versions(id, deliverable_id) ON DELETE RESTRICT,
  CONSTRAINT fk_pub_reporter FOREIGN KEY (reported_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pub_platform FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pub_verifier FOREIGN KEY (verified_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_pub_status CHECK (status IN ('reported','verified','rejected','removed','expired')),
  CONSTRAINT ck_pub_verified CHECK (status <> 'verified' OR (verified_at IS NOT NULL AND verified_by_user_id IS NOT NULL)),
  CONSTRAINT ck_pub_removed CHECK (status <> 'removed' OR removed_at IS NOT NULL),
  CONSTRAINT ck_pub_fingerprint CHECK (CHAR_LENGTH(url_fingerprint) = 64),
  -- 8.6. Un post «publicado manana» no existe. `NOW()` no se puede usar en un
  -- CHECK --no es determinista-- asi que se compara contra el momento en que
  -- entro la fila; quien la escribe usa EL MISMO instante para las dos, que es
  -- la leccion de `T-39`.
  CONSTRAINT ck_pub_published_no_futuro CHECK (created_at IS NULL OR published_at <= created_at),
  CONSTRAINT ck_pub_rejected CHECK (status <> 'rejected'
      OR (verified_at IS NOT NULL AND CHAR_LENGTH(TRIM(COALESCE(rejected_reason,''))) >= 5)),
  -- 8.7: `permanence_until` es `published_at + permanence_days` y se calcula AL
  -- VERIFICAR: hasta que alguien mira no se sabe si hay post que permanezca.
  CONSTRAINT ck_pub_permanence CHECK (permanence_until IS NULL
      OR status IN ('verified','removed','expired'))
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
  -- `captured_at` es cuando se hizo la captura --puede venir de fuera-- y esto
  -- es cuando entro la fila. Sin `updated_at`: solo insercion (2.12).
  created_at     DATETIME(3)   NULL,
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
  CONSTRAINT ck_pev_content CHECK (file_id IS NOT NULL OR raw_payload IS NOT NULL OR http_status IS NOT NULL),
  -- 8.7. Una CAPTURA sin archivo no es una captura. `ck_pev_content` solo pedia
  -- «algo», asi que una fila que dice `screenshot` y trae un 200 pelado se leia
  -- como una captura que nadie hizo --y esa es justo la que va a mirar quien
  -- discuta el pago--.
  CONSTRAINT ck_pev_screenshot CHECK (evidence_type <> 'screenshot' OR file_id IS NOT NULL),
  CONSTRAINT ck_pev_http CHECK (evidence_type <> 'http_check' OR http_status IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comprobaciones de permanencia. Append-only. Alimenta el evento
-- PermanenceCheckPassed que 2.2 P-12 marco como pendiente de crear.
CREATE TABLE permanence_checks (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36)      NOT NULL,
  publication_id BIGINT UNSIGNED NOT NULL,
  checked_at     DATETIME(3)   NOT NULL,
  is_live        TINYINT(1)    NOT NULL,
  http_status    SMALLINT UNSIGNED NULL,
  evidence_id    BIGINT UNSIGNED NULL,
  notes          VARCHAR(255)  NULL,
  created_at     DATETIME(3)   NULL,
  UNIQUE KEY uq_pc_uuid (uuid),
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

DELIMITER //
CREATE TRIGGER `tg_del_participacion_aceptada`
BEFORE INSERT ON `deliverables`
FOR EACH ROW
BEGIN
  IF NOT EXISTS (SELECT 1 FROM `campaign_creators`
                  WHERE `id` = NEW.`campaign_creator_id` AND `accepted_at` IS NOT NULL)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'No se crean entregables de una participacion sin aceptar: lo que hay que entregar sale del compromiso, y no lo hay.';
  END IF;
END//
CREATE TRIGGER `tg_dv_participacion_viva`
BEFORE INSERT ON `deliverable_versions`
FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM `deliverables` d
               JOIN `campaign_creators` cc ON cc.`id` = d.`campaign_creator_id`
              WHERE d.`id` = NEW.`deliverable_id`
                AND cc.`status` IN ('declined','expired','cancelled'))
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Esa participacion ya no esta viva: no se le pueden anadir entregas. Si el creador vuelve, es una participacion nueva.';
  END IF;
END//

-- 8.3 ------------------------------------------------------------------------
-- Los dos son CROSS-TABLE --miran `deliverable_versions` y `deliverables`-- asi
-- que un CHECK no sirve: solo ve su propia fila.
CREATE TRIGGER `tg_cvw_ultima_version`
BEFORE INSERT ON `content_reviews`
FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM `deliverable_versions` v
              WHERE v.`deliverable_id` = (SELECT `deliverable_id` FROM `deliverable_versions`
                                           WHERE `id` = NEW.`deliverable_version_id`)
                AND v.`version_number` > (SELECT `version_number` FROM `deliverable_versions`
                                           WHERE `id` = NEW.`deliverable_version_id`))
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'No se revisa una version que ya no es la ultima: el creador mando otra. Revise la mas reciente.';
  END IF;
END//
-- 8.2: la reapertura es justo el veredicto que TIENE que poder entrar sobre un
-- entregable aprobado, asi que este disparador la deja pasar y sigue cerrando
-- todo lo demas.
CREATE TRIGGER `tg_cvw_entregable_abierto`
BEFORE INSERT ON `content_reviews`
FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM `deliverable_versions` v
               JOIN `deliverables` d ON d.`id` = v.`deliverable_id`
              WHERE v.`id` = NEW.`deliverable_version_id`
                AND (d.`status` IN ('published','verified','cancelled')
                     OR (d.`status` = 'approved' AND NEW.`outcome` <> 'reopened')))
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Ese entregable no admite mas veredictos. Si hay que volver atras, reabralo diciendo por que.';
  END IF;
END//
-- 8.2: la mitad que faltaba de `Entregables::vetoParaEntregar()`. Ese veto vive
-- en el servicio desde 8.1 y NADA lo respaldaba en la base: un comando, un
-- import o la pantalla de manana podian meter una version encima de algo ya
-- aprobado, y el entregable pasaria a tener aprobado un contenido que nadie
-- aprobo.
-- 8.6: solo se publica lo aprobado, y la version aprobada. `BR-CONTENT-002` dice
-- que nada llega al cliente sin aprobacion interna, y registrar la publicacion de
-- algo no aprobado es darlo por bueno a posteriori, con la firma de nadie. Va en
-- la base y no solo en la pantalla porque de esta fila cuelga el pago: 8.7 la
-- verifica y 8.8 cuenta su permanencia.
-- 8.7: no se da por verificada una publicacion sin una captura archivada.
--
-- BEFORE UPDATE y no INSERT: una publicacion nace `reported` y la evidencia se
-- archiva DESPUES, asi que el momento de exigirla es cuando alguien la marca
-- verificada. Y se exige `screenshot` con archivo, no «alguna evidencia»: una
-- comprobacion HTTP contra Instagram devuelve 200 con un muro de login o 403 a
-- todo lo que no sea un navegador, o sea que NO distingue «el post existe» de
-- «nos bloquearon». De `verified` cuelga el pago (BR-CONTENT-004, rojo).
CREATE TRIGGER `tg_pub_verificada_con_evidencia`
BEFORE UPDATE ON `publications`
FOR EACH ROW
BEGIN
  IF NEW.`status` = 'verified' AND OLD.`status` <> 'verified'
     AND NOT EXISTS (SELECT 1 FROM `publication_evidence` e
                      WHERE e.`publication_id` = OLD.`id`
                        AND e.`evidence_type` = 'screenshot'
                        AND e.`file_id` IS NOT NULL)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'No se da por verificada una publicacion sin una captura archivada. Un estado HTTP no prueba que el post exista.';
  END IF;
END//
CREATE TRIGGER `tg_pub_version_aprobada`
BEFORE INSERT ON `publications`
FOR EACH ROW
BEGIN
  IF NOT EXISTS (SELECT 1 FROM `deliverables` d
                  WHERE d.`id` = NEW.`deliverable_id`
                    AND d.`approved_at` IS NOT NULL
                    AND d.`approved_version_id` = NEW.`deliverable_version_id`)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Solo se publica lo aprobado, y la version aprobada. Apruebe el entregable antes de registrar el post.';
  END IF;
END//
CREATE TRIGGER `tg_dv_entregable_abierto`
BEFORE INSERT ON `deliverable_versions`
FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM `deliverables` d
              WHERE d.`id` = NEW.`deliverable_id`
                AND d.`status` IN ('approved','published','verified','cancelled'))
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'No se entrega sobre un entregable cerrado. Si hay que cambiarlo, alguien tiene que reabrirlo.';
  END IF;
END//
-- «Append-only: un veredicto no se edita, se emite otro» lo dice el documento de
-- 2.12 desde el primer dia, y no lo impedia nada. Un veredicto justifica una
-- ronda cobrada: si se puede reescribir, reconstruir por que se facturo algo
-- depende de que nadie lo tocara. Se bloquea el UPDATE entero porque no hay
-- ninguna columna de esta tabla que tenga sentido cambiar despues.
CREATE TRIGGER `tg_cvw_inmutable`
BEFORE UPDATE ON `content_reviews`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Un veredicto no se edita: se emite otro. content_reviews solo admite insercion.';
END//
DELIMITER ;
