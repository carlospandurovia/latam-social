-- LATAM Social - Fase 2, iteracion 2.8 - Creador: fiscal y medios de pago
SET NAMES utf8mb4;

-- ============================================ D3/D6: perfil tributario
-- Un creador puede tener regimen en mas de un pais (el peruano con RUC que
-- ademas factura desde Espana). Por eso cuelga del pais, no del creador a secas.
CREATE TABLE creator_tax_profiles (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  creator_id          BIGINT UNSIGNED NOT NULL,
  -- 3.6 / H-01: DE QUIEN son estos datos fiscales.
  -- `creator_payment_methods` ya distinguia si la cuenta es del creador o del
  -- tutor, y `creator_tax_documents` ya sabia quien emite el comprobante. Este
  -- perfil era el unico que no lo decia: para un menor, el `tax_id_number` que
  -- hay aqui es el del TUTOR (BR-CREATOR-013) y nada en la fila lo indicaba.
  -- Un numero de RUC sin titular es una ambiguedad en un dato fiscal, y esas
  -- se pagan en la primera declaracion.
  holder_type         VARCHAR(10)   NOT NULL DEFAULT 'creator',
  holder_guardian_id  BIGINT UNSIGNED NULL,
  country_id          BIGINT UNSIGNED NOT NULL,
  -- Codigo del regimen tal como lo llama cada pais (RUS, RER, GENERAL, AUTONOMO...).
  -- Texto libre controlado y no catalogo: cada pais trae los suyos y anadirlos
  -- ocurre al abrir mercado, que ya es un despliegue.
  tax_regime_code     VARCHAR(30)   NOT NULL,
  tax_id_type         VARCHAR(20)   NOT NULL,
  tax_id_number       VARCHAR(40)   NULL,
  -- Que documento entrega el creador cuando cobra.
  issued_document_type VARCHAR(30)  NOT NULL,
  -- Q-40 / DEC-048. La version anterior era `withholding_applies TINYINT DEFAULT 0`,
  -- y ahi estaba el fallo: "no se retiene" y "nadie lo ha mirado todavia" eran
  -- el MISMO valor, cero. Un perfil se aprobaba con el defecto puesto, el pago
  -- salia sin retencion, y no habia forma de distinguir la decision del olvido.
  -- Tres estados, y 'pending_review' es el de partida: obliga a que alguien mire.
  withholding_status  VARCHAR(20)   NOT NULL DEFAULT 'pending_review',
  withholding_rate    DECIMAL(7,4)  NOT NULL DEFAULT 0,
  -- La norma que sustenta la tasa. Sin esto la tasa es un numero sin padre, y
  -- dentro de tres anos nadie sabra si el 30 % salio de la ley o de una
  -- suposicion de alguien que ya no trabaja aqui.
  withholding_basis   VARCHAR(160)  NULL,
  -- 3.6 / H-03: NOT NULL, como en `payout_batches` (DEC-044). Siendo NULL,
  -- `ck_ctp_segregation` se apagaba sola: bastaba aprobar un perfil sin decir
  -- quien lo habia capturado para saltarse la separacion de funciones. Es el
  -- mismo patron que DEC-048 -- un NULL que desactiva un control -- y se
  -- comprobo que funcionaba antes de cerrarlo.
  created_by_user_id  BIGINT UNSIGNED NOT NULL,
  -- BR-CREATOR-007: cambiar datos fiscales exige aprobacion interna.
  status              VARCHAR(15)   NOT NULL DEFAULT 'pending',
  approved_by_user_id BIGINT UNSIGNED NULL,
  approved_at         DATETIME(3)   NULL,
  rejection_note      VARCHAR(255)  NULL,
  -- 3.11 / T-15: ANULAR no es reemplazar.
  --
  -- `superseded` dice «este perfil dejo de aplicar y otro tomo su lugar»: estuvo
  -- vigente. `rejected` dice «no paso la revision»: nunca llego a aprobarse.
  -- Faltaba poder decir la tercera cosa: «se aprobo y no debio aprobarse
  -- nunca». El caso que lo destapo fue un perfil fiscal a nombre de un MENOR,
  -- que no fue valido ni un dia.
  --
  -- Quien y por que son obligatorios: anular reescribe el historico del que sale
  -- la retencion practicada, y un historico que se puede cambiar sin dejar
  -- rastro no es un historico.
  annulled_at         DATETIME(3)   NULL,
  annulled_by_user_id BIGINT UNSIGNED NULL,
  annulment_reason    VARCHAR(255)  NULL,
  valid_from          DATE          NOT NULL,
  valid_to            DATE          NULL,
  created_at          DATETIME(3)   NULL,
  updated_at          DATETIME(3)   NULL,
  -- Un solo perfil vigente y aprobado por creador y pais.
  current_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN valid_to IS NULL AND status = 'approved' THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_ctp_current (current_gate, creator_id, country_id),
  KEY ix_ctp_creator (creator_id, status),
  KEY ix_ctp_holder (holder_guardian_id),
  KEY ix_ctp_country (country_id),
  KEY ix_ctp_approver (approved_by_user_id),
  KEY ix_ctp_creator_user (created_by_user_id),
  KEY ix_ctp_withholding (withholding_status),
  KEY ix_ctp_taxid (tax_id_type, tax_id_number),
  CONSTRAINT fk_ctp_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ctp_holder FOREIGN KEY (holder_guardian_id) REFERENCES creator_guardians(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ctp_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ctp_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ctp_creator_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ctp_annuller FOREIGN KEY (annulled_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_ctp_status CHECK (status IN ('pending','approved','rejected','superseded','annulled')),
  -- Anulado exige los tres datos, y no anulado exige que no haya ninguno. Sin
  -- la segunda mitad, un `annulled_at` suelto en una fila aprobada seria una
  -- anulacion a medias que nadie sabria leer.
  CONSTRAINT ck_ctp_annulled CHECK (
    (status =  'annulled' AND annulled_at IS NOT NULL AND annulled_by_user_id IS NOT NULL AND annulment_reason IS NOT NULL) OR
    (status <> 'annulled' AND annulled_at IS     NULL AND annulled_by_user_id IS     NULL AND annulment_reason IS     NULL)
  ),
  -- Calcada de `ck_cpm_owner`: o es del creador y no hay tutor, o es del tutor
  -- y hay que decir cual. No existe el titular a medias.
  CONSTRAINT ck_ctp_holder CHECK (
    (holder_type = 'creator'  AND holder_guardian_id IS NULL) OR
    (holder_type = 'guardian' AND holder_guardian_id IS NOT NULL)
  ),
  CONSTRAINT ck_ctp_doc CHECK (issued_document_type IN ('recibo_honorarios','factura','invoice','none')),
  CONSTRAINT ck_ctp_rate CHECK (withholding_rate >= 0 AND withholding_rate <= 100),
  CONSTRAINT ck_ctp_withholding_status CHECK (
    withholding_status IN ('pending_review','not_applicable','applies')
  ),
  -- Si se retiene, hay tasa Y hay norma que la sustente.
  CONSTRAINT ck_ctp_rate_required CHECK (
    withholding_status <> 'applies' OR (withholding_rate > 0 AND withholding_basis IS NOT NULL)
  ),
  -- Y si se decidio que no se retiene, la tasa es cero. No puede quedar un
  -- numero suelto de un borrador anterior.
  CONSTRAINT ck_ctp_rate_zero CHECK (
    withholding_status <> 'not_applicable' OR withholding_rate = 0
  ),
  -- LA IMPORTANTE: un perfil no se aprueba con la retencion sin decidir. Es lo
  -- que convierte el olvido en un bloqueo visible en vez de un pago silencioso.
  CONSTRAINT ck_ctp_withholding_decided CHECK (
    status <> 'approved' OR withholding_status <> 'pending_review'
  ),
  -- Segregacion de funciones, igual que DEC-044: quien captura el dato fiscal
  -- no es quien lo aprueba.
  -- La rama `created_by_user_id IS NULL` desaparecio con H-03: ahora la columna
  -- es NOT NULL, asi que la restriccion se simplifica *porque* el modelo se
  -- volvio mas estricto. Queda igual que `ck_pbatch_segregation`.
  CONSTRAINT ck_ctp_segregation CHECK (
    approved_by_user_id IS NULL OR approved_by_user_id <> created_by_user_id
  ),
  -- Aprobado exige quien y cuando.
  CONSTRAINT ck_ctp_approval CHECK (
    status <> 'approved' OR (approved_by_user_id IS NOT NULL AND approved_at IS NOT NULL)
  ),
  CONSTRAINT ck_ctp_dates CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================== D6: medios de pago del creador
-- NUNCA se guarda el numero de cuenta en claro. Tres columnas y ninguna legible:
--   _encrypted   -> el valor cifrado por la aplicacion (clave fuera de la BD)
--   _masked      -> lo unico que se muestra en pantalla ("****4321")
--   _fingerprint -> HMAC-SHA256, para detectar que dos creadores comparten cuenta
--                   SIN descifrar nada. Senal de fraude, no error: por eso indice
--                   normal y no unico.
CREATE TABLE creator_payment_methods (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                  CHAR(36)      NOT NULL,
  creator_id            BIGINT UNSIGNED NOT NULL,
  -- 2.3 §10: si el beneficiario es el tutor, la cuenta es del tutor. Sin esto
  -- BR-FIN-003 validaba el medio de pago de la persona equivocada.
  owner_type            VARCHAR(10)   NOT NULL DEFAULT 'creator',
  owner_guardian_id     BIGINT UNSIGNED NULL,
  method_type           VARCHAR(20)   NOT NULL,
  country_id            BIGINT UNSIGNED NOT NULL,
  currency_code         CHAR(3)       NOT NULL,
  bank_name             VARCHAR(80)   NULL,
  account_type          VARCHAR(15)   NULL,
  account_number_encrypted  TEXT      NOT NULL,
  account_number_masked     VARCHAR(30) NOT NULL,
  account_number_fingerprint CHAR(64) NOT NULL,
  holder_name           VARCHAR(160)  NOT NULL,
  holder_document_type  VARCHAR(20)   NOT NULL,
  holder_document_number VARCHAR(40)  NOT NULL,
  -- H-11: quien captura la cuenta no puede ser quien la verifica. Faltaba la
  -- mitad del par: solo existia `verified_by_user_id`, asi que el mismo
  -- operador podia dar de alta una cuenta bancaria y validarla el mismo. Es el
  -- mismo hueco que H-03 en el perfil fiscal, y aqui es donde va el dinero.
  -- NOT NULL desde el primer dia, no NULL "de momento": esa es la leccion.
  created_by_user_id    BIGINT UNSIGNED NOT NULL,
  status                VARCHAR(15)   NOT NULL DEFAULT 'pending',
  verified_at           DATETIME(3)   NULL,
  verified_by_user_id   BIGINT UNSIGNED NULL,
  -- Rechazar y desactivar tambien son actos de alguien. Como `H-13` prohibe el
  -- DELETE, desactivar es la unica forma de retirar una cuenta: sin fecha y
  -- autor, retirar una cuenta seria la unica operacion sin rastro de la tabla.
  closed_at             DATETIME(3)   NULL,
  closed_by_user_id     BIGINT UNSIGNED NULL,
  -- La misma cuenta en dos creadores distintos. Hay casos legitimos --dos
  -- hermanos menores con la cuenta del mismo tutor-- y hay un caso de fraude
  -- clasico. No se rechaza: se marca y lo mira un humano (DEC-065), igual que
  -- DEC-063 con las metricas. El valor por defecto es "nadie lo ha mirado",
  -- NUNCA "unique": la leccion de H-06 es que un defecto no puede parecer una
  -- respuesta. Lo pone un disparador, no la aplicacion, para que no pueda
  -- mentir.
  shared_account_status VARCHAR(15)   NOT NULL DEFAULT 'pending_review',
  -- BR-FIN-006: periodo de enfriamiento. Un medio nuevo o modificado no es
  -- elegible para pagos hasta esta fecha, aunque ya este verificado.
  eligible_from         DATETIME(3)   NULL,
  is_default            TINYINT(1)    NOT NULL DEFAULT 0,
  created_at            DATETIME(3)   NULL,
  updated_at            DATETIME(3)   NULL,
  default_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN is_default = 1 THEN 1 ELSE NULL END) STORED,
  -- La misma cuenta registrada dos veces en el MISMO creador no es una senal de
  -- nada: es ruido, y multiplica las filas que hay que verificar. Solo cuenta
  -- entre las que siguen abiertas, para que "cambio de cuenta y volvio a la
  -- anterior" siga siendo posible.
  --
  -- La primera version devolvia la huella (`... THEN account_number_fingerprint
  -- ELSE NULL`) y **MariaDB la rechaza**: ERROR 1901 en cuanto una columna
  -- generada STORED produce una CADENA a partir de un CASE. MySQL 8 la acepta
  -- sin decir nada. Aislado en una tabla de prueba: con resultado entero pasa,
  -- con resultado CHAR no; VIRTUAL pasa, STORED no. Otra divergencia de la
  -- familia de H-08, cazada esta vez aqui y no en su maquina, porque el entorno
  -- de trabajo ya tiene los dos motores.
  --
  -- La salida es volver al patron de puerta que ya usa todo el esquema: la
  -- columna generada vale 1 o NULL, y la huella entra en el indice como una
  -- columna normal. Igual que `uq_ctp_current`.
  open_gate TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN status = 'pending' OR status = 'verified'
                              THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_cpm_uuid (uuid),
  UNIQUE KEY uq_cpm_default (default_gate, creator_id),
  UNIQUE KEY uq_cpm_open_account (open_gate, creator_id, account_number_fingerprint),
  KEY ix_cpm_capturer (created_by_user_id),
  KEY ix_cpm_closer (closed_by_user_id),
  KEY ix_cpm_shared (shared_account_status),
  KEY ix_cpm_creator (creator_id, status),
  KEY ix_cpm_fingerprint (account_number_fingerprint),
  KEY ix_cpm_guardian (owner_guardian_id),
  KEY ix_cpm_country (country_id),
  KEY ix_cpm_currency (currency_code),
  KEY ix_cpm_verifier (verified_by_user_id),
  CONSTRAINT fk_cpm_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cpm_guardian FOREIGN KEY (owner_guardian_id) REFERENCES creator_guardians(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cpm_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cpm_currency FOREIGN KEY (currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT fk_cpm_verifier FOREIGN KEY (verified_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cpm_capturer FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cpm_closer FOREIGN KEY (closed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_cpm_status CHECK (status IN ('pending','verified','rejected','disabled')),
  CONSTRAINT ck_cpm_method CHECK (method_type IN ('bank_account','wallet','paypal','other')),
  CONSTRAINT ck_cpm_owner CHECK (
    (owner_type = 'creator'  AND owner_guardian_id IS NULL) OR
    (owner_type = 'guardian' AND owner_guardian_id IS NOT NULL)
  ),
  CONSTRAINT ck_cpm_verified CHECK (
    status <> 'verified' OR (verified_at IS NOT NULL AND verified_by_user_id IS NOT NULL)
  ),
  -- H-10. Este comentario decia "la mascara nunca puede contener mas de 4
  -- digitos" y debajo habia un `CHAR_LENGTH(...) <= 30`, que es el largo de la
  -- columna. Se comprobo: `00212345678901234567` --el numero de cuenta entero,
  -- en claro-- entraba sin protestar. Una restriccion con forma de control que
  -- no controlaba nada, y encima con un comentario que aseguraba lo contrario;
  -- quien lo leyera se quedaba tranquilo. El largo se queda (no esta mal, solo
  -- era insuficiente) y los digitos los cuenta la restriccion de al lado.
  CONSTRAINT ck_cpm_masked CHECK (CHAR_LENGTH(account_number_masked) <= 30),
  -- "No mas de cuatro digitos" dicho de la unica forma que entienden los tres
  -- motores: que NO exista en el texto una quinta cifra. `REGEXP_REPLACE`
  -- habria sido mas legible y no existe en Percona 5.7, que es produccion.
  CONSTRAINT ck_cpm_masked_digits CHECK (
    account_number_masked NOT REGEXP '[0-9].*[0-9].*[0-9].*[0-9].*[0-9]'
  ),
  CONSTRAINT ck_cpm_fingerprint CHECK (CHAR_LENGTH(account_number_fingerprint) = 64),
  -- H-02. `status='verified'` con `eligible_from` NULL: verificado, y nadie
  -- dijo desde cuando se le puede pagar. `CompletitudOperativa` ya se defendia
  -- con un `whereNotNull`, pero eso es una defensa en UNA consulta: la de
  -- payouts no la tenia (H-09). La regla vive aqui o no vive.
  CONSTRAINT ck_cpm_eligible CHECK (status <> 'verified' OR eligible_from IS NOT NULL),
  -- El enfriamiento de BR-FIN-006 empieza al verificar. Una fecha de
  -- elegibilidad anterior a la verificacion es un enfriamiento negativo.
  CONSTRAINT ck_cpm_eligible_after CHECK (
    eligible_from IS NULL OR verified_at IS NULL OR eligible_from >= verified_at
  ),
  -- H-11, calcada de `ck_ctp_segregation`. Sin la rama del NULL: aqui la
  -- columna nace NOT NULL, que es lo que H-03 enseno a hacer desde el principio.
  CONSTRAINT ck_cpm_segregation CHECK (
    verified_by_user_id IS NULL OR verified_by_user_id <> created_by_user_id
  ),
  -- H-04, una tabla mas alla: rechazar no es verificar. Un medio rechazado que
  -- llevara verificador escrito diria que alguien lo dio por bueno.
  CONSTRAINT ck_cpm_rejected_clean CHECK (
    status <> 'rejected' OR (verified_at IS NULL AND verified_by_user_id IS NULL)
  ),
  CONSTRAINT ck_cpm_closed CHECK (
    status NOT IN ('rejected','disabled') OR (closed_at IS NOT NULL AND closed_by_user_id IS NOT NULL)
  ),
  -- H-14. `default_gate` garantizaba que hubiera UN predeterminado, no que
  -- sirviera: se comprobo que un medio `rejected` podia ser el predeterminado.
  CONSTRAINT ck_cpm_default_usable CHECK (is_default = 0 OR status = 'verified'),
  CONSTRAINT ck_cpm_shared_status CHECK (
    shared_account_status IN ('unique','pending_review','cleared')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================ D6: comprobante que entrega el creador
CREATE TABLE creator_tax_documents (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                CHAR(36)      NOT NULL,
  creator_id          BIGINT UNSIGNED NOT NULL,
  -- BR-CREATOR-010: si el creador es menor, el documento lo emite el tutor.
  issued_by_guardian_id BIGINT UNSIGNED NULL,
  document_type       VARCHAR(30)   NOT NULL,
  series              VARCHAR(10)   NOT NULL,
  number              VARCHAR(20)   NOT NULL,
  -- DATE y no DATETIME: la fecha de emision es un DIA en el pais del emisor
  -- (docs 2.3 §8). En UTC, un comprobante de fin de mes cae en el periodo malo.
  issue_date          DATE          NOT NULL,
  currency_code       CHAR(3)       NOT NULL,
  gross_amount        DECIMAL(18,4) NOT NULL,
  withholding_amount  DECIMAL(18,4) NOT NULL DEFAULT 0,
  net_amount          DECIMAL(18,4) NOT NULL,
  file_id             BIGINT UNSIGNED NULL,
  status              VARCHAR(15)   NOT NULL DEFAULT 'received',
  validated_by_user_id BIGINT UNSIGNED NULL,
  validated_at        DATETIME(3)   NULL,
  rejection_note      VARCHAR(255)  NULL,
  created_at          DATETIME(3)   NULL,
  updated_at          DATETIME(3)   NULL,
  UNIQUE KEY uq_ctd_uuid (uuid),
  -- El mismo emisor no puede entregar dos veces la misma serie y numero.
  UNIQUE KEY uq_ctd_number (creator_id, document_type, series, number),
  KEY ix_ctd_creator (creator_id, status),
  KEY ix_ctd_issue_date (issue_date),
  KEY ix_ctd_guardian (issued_by_guardian_id),
  KEY ix_ctd_file (file_id),
  KEY ix_ctd_currency (currency_code),
  KEY ix_ctd_validator (validated_by_user_id),
  CONSTRAINT fk_ctd_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ctd_guardian FOREIGN KEY (issued_by_guardian_id) REFERENCES creator_guardians(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ctd_currency FOREIGN KEY (currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT fk_ctd_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ctd_validator FOREIGN KEY (validated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_ctd_status CHECK (status IN ('received','validated','rejected')),
  CONSTRAINT ck_ctd_type CHECK (document_type IN ('recibo_honorarios','factura','invoice','other')),
  CONSTRAINT ck_ctd_amounts CHECK (gross_amount >= 0 AND withholding_amount >= 0 AND net_amount >= 0),
  -- La aritmetica del comprobante la comprueba la base, no el que lo teclea.
  CONSTRAINT ck_ctd_math CHECK (net_amount = gross_amount - withholding_amount),
  CONSTRAINT ck_ctd_validated CHECK (
    status <> 'validated' OR (validated_by_user_id IS NOT NULL AND validated_at IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- Disparadores de `creator_payment_methods` (iteracion 3.8)
--
-- Tres cosas que ningun CHECK puede expresar, porque no hablan de los valores
-- de UNA fila sino de VERBOS y de OTRAS filas:
--
--   1. `tg_cpm_no_delete`  -- BR-FIN-008: ningun registro financiero se borra.
--   2. `tg_cpm_inmutable`  -- H-12: la cuenta no se edita, se sustituye.
--   3. `tg_cpm_compartida` -- DEC-065: marcar la cuenta repetida entre creadores.
--
-- Van iguales en los dos motores, fuera del compilador de restricciones, como
-- `tg_sas_no_update` y los de `ledger_entries`.
-- ===========================================================================

DELIMITER //

CREATE TRIGGER tg_cpm_no_delete BEFORE DELETE ON creator_payment_methods
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'creator_payment_methods no admite borrado (BR-FIN-008): una cuenta se desactiva, y queda quien y cuando.';
END//

-- H-12. Se comprobo primero: un UPDATE cambiaba el numero de cuenta de un medio
-- YA verificado y la fila seguia diciendo `verified`, apuntando a otro sitio.
-- Eso vacia BR-FIN-006 entero, porque el enfriamiento existe justamente para
-- las modificaciones. Ahora la cuenta es inmutable: cambiar de cuenta es dar de
-- alta otra y desactivar esta (DEC-066). Asi queda ademas el rastro de todas
-- las cuentas que existieron, que es lo que hace falta para reconstruir a donde
-- se envio el dinero.
--
-- Lo que SI puede cambiar: el estado y sus fechas, la elegibilidad mientras no
-- este puesta, el predeterminado, y el veredicto humano sobre la cuenta
-- compartida.
CREATE TRIGGER tg_cpm_inmutable BEFORE UPDATE ON creator_payment_methods
FOR EACH ROW
BEGIN
  IF NEW.creator_id <> OLD.creator_id
     OR NEW.uuid <> OLD.uuid
     OR NEW.method_type <> OLD.method_type
     OR NEW.country_id <> OLD.country_id
     OR NEW.currency_code <> OLD.currency_code
     OR NEW.owner_type <> OLD.owner_type
     OR NOT (NEW.owner_guardian_id <=> OLD.owner_guardian_id)
     OR NEW.account_number_encrypted <> OLD.account_number_encrypted
     OR NEW.account_number_masked <> OLD.account_number_masked
     OR NEW.account_number_fingerprint <> OLD.account_number_fingerprint
     OR NEW.holder_name <> OLD.holder_name
     OR NEW.holder_document_type <> OLD.holder_document_type
     OR NEW.holder_document_number <> OLD.holder_document_number
     OR NEW.created_by_user_id <> OLD.created_by_user_id
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'La cuenta de un medio de pago es inmutable (H-12): de alta una nueva y desactive esta.';
  END IF;

  -- Una verificacion no se reescribe. Si se pudiera, "verificado por Ana el
  -- martes" seria un dato editable, y es la prueba de que alguien miro.
  IF OLD.verified_at IS NOT NULL
     AND (NOT (NEW.verified_at <=> OLD.verified_at)
          OR NOT (NEW.verified_by_user_id <=> OLD.verified_by_user_id))
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'La verificacion de un medio de pago no se reescribe.';
  END IF;

  -- Y el enfriamiento no se acorta a posteriori. Sin esto, BR-FIN-006 seria un
  -- UPDATE de distancia.
  IF OLD.eligible_from IS NOT NULL AND NOT (NEW.eligible_from <=> OLD.eligible_from) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'La fecha de elegibilidad no se cambia una vez fijada (BR-FIN-006).';
  END IF;
END//

-- DEC-065. Lo pone la BASE, no la aplicacion: si lo escribiera la aplicacion,
-- una insercion podria afirmar `unique` sin haber mirado nada, que es
-- exactamente el fallo de H-06 y de DEC-048.
CREATE TRIGGER tg_cpm_compartida BEFORE INSERT ON creator_payment_methods
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1 FROM creator_payment_methods
     WHERE account_number_fingerprint = NEW.account_number_fingerprint
       AND creator_id <> NEW.creator_id
  ) THEN
    SET NEW.shared_account_status = 'pending_review';
  ELSE
    SET NEW.shared_account_status = 'unique';
  END IF;
END//

DELIMITER ;

-- ===========================================================================
-- 3.11 / T-15 -- Solo se anula el VIGENTE
--
-- La decision fue que un perfil ya reemplazado no se anula: durante su ventana
-- fue el que habia en el expediente, y sobre esa ventana puede haberse
-- liquidado dinero con esa retencion. Deshacerlo no es corregir un error, es
-- reescribir un periodo que ya paso.
--
-- Asi que anular solo vale saliendo de `approved` con `valid_to` abierto. La
-- comprobacion mira OLD, que es lo unico que un CHECK no puede hacer.
-- ===========================================================================

DELIMITER //

CREATE TRIGGER `tg_ctp_solo_el_vigente_se_anula`
BEFORE UPDATE ON `creator_tax_profiles`
FOR EACH ROW
BEGIN
    -- Una vez anulado, la fila se congela.
    --
    -- La primera version solo miraba la ENTRADA en `annulled`
    -- (`OLD.status <> 'annulled'`), y eso dejaba reescribir el motivo de una
    -- anulacion ya hecha tantas veces como se quisiera. Lo destapo una asercion
    -- de la suite que leia el motivo y encontraba el ultimo, no el primero.
    --
    -- Anular existe justamente para no destruir el historico. Un motivo que se
    -- puede cambiar despues no es evidencia de nada.
    IF OLD.status = 'annulled' THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Un perfil fiscal anulado ya no se toca: el motivo y quien lo anulo son evidencia.';
    END IF;

    IF NEW.status = 'annulled'
       AND NOT (OLD.status = 'approved' AND OLD.valid_to IS NULL) THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Solo se puede anular el perfil fiscal vigente: uno ya reemplazado se queda como esta.';
    END IF;
END//

DELIMITER ;

-- ===========================================================================
-- 3.10 -- El historico no se solapa
--
-- `uq_ctp_current` ya garantizaba una sola fila VIGENTE. Lo que no garantizaba era que
-- el historico tuviera una sola respuesta para una fecha PASADA:
--
--     .cual es el regimen fiscal de HOY?          -> una sola, garantizado
--     .cual era el 1 de mayo?          -> podian ser dos
--
-- En un historial fiscal esa ambiguedad se paga en una declaracion: `T-12`.
--
-- Ocupan periodo `approved` Y `superseded`. La primera version filtraba solo
-- por `approved` y NO habria cazado el defecto que viene a arreglar: el
-- controlador marca el anterior como `superseded` en la misma transaccion en
-- que aprueba el nuevo, asi que nunca hay dos `approved` a la vez y la regla no
-- se disparaba jamas.
--
-- Ademas de funcionar, es lo correcto: `superseded` quiere decir REEMPLAZADO,
-- no anulado. Ese perfil estuvo vigente su ventana, y de el salio la retencion
-- que se practico esos meses.
--
-- `pending` y `rejected` no ocupan: nunca estuvieron vigentes, y si estorbaran,
-- un error de captura bloquearia el historico del creador para siempre.
--
-- Generados por App\Shared\Database\Periodo, no escritos a mano: la migracion
-- usa esa misma clase, asi que esquema de referencia y produccion no pueden
-- divergir. Van en disparadores porque la regla mira OTRAS FILAS, y eso ningun
-- CHECK lo admite --tampoco en MySQL 8--.
-- ===========================================================================

DELIMITER //

CREATE TRIGGER `tg_ctp_sin_solape_ins`
BEFORE INSERT ON `creator_tax_profiles`
FOR EACH ROW
BEGIN
    IF (NEW.`status` IN ('approved', 'superseded'))
       AND EXISTS (
        SELECT 1 FROM `creator_tax_profiles`
         WHERE `creator_id` <=> NEW.`creator_id`
           AND `country_id` <=> NEW.`country_id`
           AND NEW.`valid_from` <= IFNULL(`valid_to`, '9999-12-31')
           AND `valid_from` <= IFNULL(NEW.`valid_to`, '9999-12-31')
           AND (status IN ('approved', 'superseded'))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya hay un perfil fiscal aprobado para ese pais en esas fechas: cierre el anterior el dia antes.';
    END IF;
END//

CREATE TRIGGER `tg_ctp_sin_solape_upd`
BEFORE UPDATE ON `creator_tax_profiles`
FOR EACH ROW
BEGIN
    IF (NEW.`status` IN ('approved', 'superseded'))
       AND EXISTS (
        SELECT 1 FROM `creator_tax_profiles`
         WHERE `id` <> NEW.`id`
           AND `creator_id` <=> NEW.`creator_id`
           AND `country_id` <=> NEW.`country_id`
           AND NEW.`valid_from` <= IFNULL(`valid_to`, '9999-12-31')
           AND `valid_from` <= IFNULL(NEW.`valid_to`, '9999-12-31')
           AND (status IN ('approved', 'superseded'))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya hay un perfil fiscal aprobado para ese pais en esas fechas: cierre el anterior el dia antes.';
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

CREATE TRIGGER tg_ctp_no_delete BEFORE DELETE ON creator_tax_profiles
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'creator_tax_profiles no admite borrado: de aqui sale la retencion que se le practico al creador.';
END//

CREATE TRIGGER tg_ctd_no_delete BEFORE DELETE ON creator_tax_documents
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'creator_tax_documents no admite borrado: son los documentos que respaldan un pago.';
END//

DELIMITER ;
