-- LATAM Social - Fase 2, iteracion 2.13 - Finanzas
-- La iteracion con mas reglas que imponer del proyecto.
SET NAMES utf8mb4;

-- ============================ Costos directos de campana (BR-FIN-011)
CREATE TABLE campaign_costs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_id   BIGINT UNSIGNED NOT NULL,
  cost_type     VARCHAR(20)   NOT NULL,
  description   VARCHAR(255)  NOT NULL,
  amount        DECIMAL(18,4) NOT NULL,
  currency_code CHAR(3)       NOT NULL,
  incurred_on   DATE          NOT NULL,
  file_id       BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  -- Un costo mal tecleado se anula, no se borra: afecta al margen (BR-FIN-011)
  -- y el margen de ayer tiene que poder reconstruirse.
  voided_at     DATETIME(3)   NULL,
  voided_by_user_id BIGINT UNSIGNED NULL,
  voided_reason VARCHAR(255)  NULL,
  created_at    DATETIME(3)   NULL,
  updated_at    DATETIME(3)   NULL,
  KEY ix_cco_campaign (campaign_id, cost_type),
  KEY ix_cco_voider (voided_by_user_id),
  KEY ix_cco_currency (currency_code),
  KEY ix_cco_file (file_id),
  KEY ix_cco_user (created_by_user_id),
  CONSTRAINT fk_cco_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cco_currency FOREIGN KEY (currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT fk_cco_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cco_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cco_voider FOREIGN KEY (voided_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_cco_voided CHECK (
    (voided_at IS NULL AND voided_by_user_id IS NULL AND voided_reason IS NULL) OR
    (voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL AND voided_reason IS NOT NULL)
  ),
  CONSTRAINT ck_cco_type CHECK (cost_type IN ('product','shipping','production','media','tool','other')),
  CONSTRAINT ck_cco_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================ Lote de pago (BR-FIN-005: doble aprobacion)
CREATE TABLE payout_batches (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                CHAR(36)      NOT NULL,
  code                VARCHAR(20)   NOT NULL,
  legal_entity_id     BIGINT UNSIGNED NOT NULL,
  currency_code       CHAR(3)       NOT NULL,
  status              VARCHAR(15)   NOT NULL DEFAULT 'draft',
  created_by_user_id  BIGINT UNSIGNED NOT NULL,
  approved_by_user_id BIGINT UNSIGNED NULL,
  approved_at         DATETIME(3)   NULL,
  executed_at         DATETIME(3)   NULL,
  created_at          DATETIME(3)   NULL,
  updated_at          DATETIME(3)   NULL,
  UNIQUE KEY uq_pb_uuid2 (uuid),
  UNIQUE KEY uq_pbatch_code (code),
  KEY ix_pbatch_entity (legal_entity_id, status),
  KEY ix_pbatch_creator (created_by_user_id),
  KEY ix_pbatch_approver (approved_by_user_id),
  KEY ix_pbatch_currency (currency_code),
  CONSTRAINT fk_pbatch_entity FOREIGN KEY (legal_entity_id) REFERENCES legal_entities(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pbatch_currency FOREIGN KEY (currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT fk_pbatch_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pbatch_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_pbatch_status CHECK (status IN ('draft','pending_approval','approved','executing','executed','cancelled')),
  -- BR-FIN-005: quien crea el lote NO puede aprobarlo. Segregacion de funciones
  -- impuesta por la base, no por una pantalla que alguien puede saltarse.
  CONSTRAINT ck_pbatch_segregation CHECK (
    approved_by_user_id IS NULL OR approved_by_user_id <> created_by_user_id
  ),
  CONSTRAINT ck_pbatch_approved CHECK (
    status NOT IN ('approved','executing','executed')
    OR (approved_by_user_id IS NOT NULL AND approved_at IS NOT NULL)
  ),
  CONSTRAINT ck_pbatch_executed CHECK (status <> 'executed' OR executed_at IS NOT NULL),
  CONSTRAINT ck_pbatch_approval_order CHECK (
    executed_at IS NULL OR approved_at IS NULL OR executed_at >= approved_at
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================ El pago: la ejecucion bancaria (2.3 N-05)
CREATE TABLE payouts (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                CHAR(36)      NOT NULL,
  -- NOT NULL a proposito: si un pago pudiera existir sin lote, BR-FIN-005
  -- (doble aprobacion) se saltaria creando pagos sueltos. Un pago unico es un
  -- lote de uno. La segregacion de funciones no admite puerta trasera.
  payout_batch_id     BIGINT UNSIGNED NOT NULL,
  creator_id          BIGINT UNSIGNED NOT NULL,
  -- Copia congelada del beneficiario y su cuenta: hay que poder reconstruir a
  -- donde se envio el dinero aunque el creador cambie la cuenta manana.
  payment_method_id   BIGINT UNSIGNED NOT NULL,
  beneficiary_name_snapshot VARCHAR(160) NOT NULL,
  account_masked_snapshot   VARCHAR(30)  NOT NULL,
  amount              DECIMAL(18,4) NOT NULL,
  currency_code       CHAR(3)       NOT NULL,
  status              VARCHAR(15)   NOT NULL DEFAULT 'pending',
  bank_reference      VARCHAR(80)   NULL,
  -- DATE: la fecha valor es un dia, no un instante (2.3 §8).
  value_date          DATE          NULL,
  sent_at             DATETIME(3)   NULL,
  confirmed_at        DATETIME(3)   NULL,
  returned_at         DATETIME(3)   NULL,
  return_reason       VARCHAR(255)  NULL,
  created_at          DATETIME(3)   NULL,
  updated_at          DATETIME(3)   NULL,
  UNIQUE KEY uq_payout_uuid (uuid),
  KEY ix_payout_batch (payout_batch_id, status),
  KEY ix_payout_creator (creator_id, status),
  KEY ix_payout_method (payment_method_id),
  KEY ix_payout_currency (currency_code),
  KEY ix_payout_value_date (value_date),
  CONSTRAINT fk_payout_batch FOREIGN KEY (payout_batch_id) REFERENCES payout_batches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payout_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payout_method FOREIGN KEY (payment_method_id) REFERENCES creator_payment_methods(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payout_currency FOREIGN KEY (currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT ck_payout_status CHECK (status IN ('pending','sent','confirmed','returned','cancelled')),
  CONSTRAINT ck_payout_amount CHECK (amount > 0),
  CONSTRAINT ck_payout_sent CHECK (status NOT IN ('sent','confirmed') OR sent_at IS NOT NULL),
  CONSTRAINT ck_payout_returned CHECK (status <> 'returned' OR returned_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================ EL LEDGER. Solo insercion, nunca UPDATE ni DELETE.
-- BR-FIN-001: el saldo de un creador NO es una columna. Es la suma de esto.
-- BR-FIN-002: una correccion es un asiento de reversion, jamas una edicion.
CREATE TABLE ledger_entries (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                CHAR(36)      NOT NULL,
  creator_id          BIGINT UNSIGNED NOT NULL,
  entry_type          VARCHAR(20)   NOT NULL,
  -- Positivo suma al saldo del creador, negativo resta. Cero no es un asiento.
  amount              DECIMAL(18,4) NOT NULL,
  currency_code       CHAR(3)       NOT NULL,
  status              VARCHAR(15)   NOT NULL DEFAULT 'accrued',
  campaign_creator_id BIGINT UNSIGNED NULL,
  payout_id           BIGINT UNSIGNED NULL,
  -- BR-FIN-009: la tasa aplicada se congela con su fecha y su fuente. Los
  -- historicos no se recalculan.
  exchange_rate_snapshot   DECIMAL(18,8) NULL,
  exchange_rate_date       DATE          NULL,
  exchange_rate_source     VARCHAR(40)   NULL,
  base_currency_code       CHAR(3)       NULL,
  base_amount              DECIMAL(18,4) NULL,
  -- Q-40: un asiento de retencion congela la tasa aplicada y la norma que la
  -- sustenta. Sin esto, cambiar la tasa manana reescribiria la explicacion de
  -- las retenciones de ayer.
  withholding_rate_snapshot  DECIMAL(7,4) NULL,
  withholding_basis_snapshot VARCHAR(160) NULL,
  -- BR-FIN-002: la reversion apunta al asiento que corrige.
  reverses_entry_id   BIGINT UNSIGNED NULL,
  description         VARCHAR(255)  NOT NULL,
  -- occurred_at es tiempo de negocio (cuando ocurrio el hecho economico).
  -- created_at es tiempo de sistema (cuando lo supimos). Auditoria necesita
  -- los dos: un asiento con fecha de hace tres meses insertado hoy es senal.
  occurred_at         DATETIME(3)   NOT NULL,
  created_at          DATETIME(3)   NOT NULL,
  created_by_user_id  BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_ledger_uuid (uuid),
  -- Un asiento de pago por payout, no dos (BR-FIN-013).
  UNIQUE KEY uq_ledger_payout (payout_id),
  -- Un asiento solo se revierte una vez.
  UNIQUE KEY uq_ledger_reverses (reverses_entry_id),
  KEY ix_ledger_creator (creator_id, status, occurred_at),
  KEY ix_ledger_participation (campaign_creator_id),
  KEY ix_ledger_type (entry_type, occurred_at),
  KEY ix_ledger_currency (currency_code),
  KEY ix_ledger_user (created_by_user_id),
  CONSTRAINT fk_ledger_creator FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ledger_participation FOREIGN KEY (campaign_creator_id) REFERENCES campaign_creators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ledger_payout FOREIGN KEY (payout_id) REFERENCES payouts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ledger_reverses FOREIGN KEY (reverses_entry_id) REFERENCES ledger_entries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ledger_currency FOREIGN KEY (currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT fk_ledger_base_currency FOREIGN KEY (base_currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT fk_ledger_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_ledger_type CHECK (entry_type IN ('earning','payment','payment_reversal','adjustment','bonus','penalty','withholding')),
  CONSTRAINT ck_ledger_status CHECK (status IN ('accrued','payable','paid','on_hold','void')),
  -- Un asiento de importe cero no dice nada y ensucia el saldo.
  CONSTRAINT ck_ledger_amount CHECK (amount <> 0),
  -- Un devengo suma; un pago resta. Al reves seria un error de signo que
  -- descuadra el saldo sin que nadie lo note.
  -- El signo de cada tipo esta determinado salvo en 'adjustment', que existe
  -- justamente para poder ir en cualquier direccion. Un devengo negativo o un
  -- pago positivo son errores que descuadran el saldo sin que nadie lo note.
  CONSTRAINT ck_ledger_sign CHECK (
    (entry_type IN ('earning','bonus','payment_reversal') AND amount > 0) OR
    (entry_type IN ('payment','penalty','withholding')    AND amount < 0) OR
    (entry_type = 'adjustment')
  ),
  -- Si hay conversion, tiene que estar completa: tasa, fecha, fuente y moneda.
  CONSTRAINT ck_ledger_fx CHECK (
    exchange_rate_snapshot IS NULL OR
    (exchange_rate_date IS NOT NULL AND exchange_rate_source IS NOT NULL
     AND base_currency_code IS NOT NULL AND base_amount IS NOT NULL)
  ),
  -- Un asiento de pago necesita su payout, y solo el.
  CONSTRAINT ck_ledger_payout_link CHECK (
    (entry_type = 'payment') = (payout_id IS NOT NULL)
  ),
  CONSTRAINT ck_ledger_reversal CHECK (
    entry_type <> 'payment_reversal' OR reverses_entry_id IS NOT NULL
  ),
  -- Un devengo nace de una participacion en campana. Si no la tiene, es dinero
  -- sin origen trazable. Un bono o un ajuste si pueden no tenerla.
  CONSTRAINT ck_ledger_earning_link CHECK (
    entry_type <> 'earning' OR campaign_creator_id IS NOT NULL
  ),
  -- Una retencion sin tasa ni norma no se puede explicar. Y una tasa colgada de
  -- un asiento que no es retencion es ruido que confunde.
  CONSTRAINT ck_ledger_withholding CHECK (
    (entry_type = 'withholding') =
    (withholding_rate_snapshot IS NOT NULL AND withholding_basis_snapshot IS NOT NULL)
  ),
  CONSTRAINT ck_ledger_withholding_rate CHECK (
    withholding_rate_snapshot IS NULL
    OR (withholding_rate_snapshot > 0 AND withholding_rate_snapshot <= 100)
  ),
  CONSTRAINT ck_ledger_reverses_type CHECK (
    reverses_entry_id IS NULL OR entry_type IN ('payment_reversal','adjustment')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================ Facturas al cliente (BR-LE-005, BR-FIN-010)
CREATE TABLE invoices (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                CHAR(36)      NOT NULL,
  legal_entity_id     BIGINT UNSIGNED NOT NULL,
  client_organization_id BIGINT UNSIGNED NOT NULL,
  client_tax_profile_id  BIGINT UNSIGNED NOT NULL,
  campaign_id         BIGINT UNSIGNED NULL,
  document_type       VARCHAR(20)   NOT NULL DEFAULT 'invoice',
  series              VARCHAR(10)   NOT NULL,
  number              BIGINT UNSIGNED NOT NULL,
  -- DATE, en la zona de la sociedad emisora (2.3 §8).
  issue_date          DATE          NOT NULL,
  due_date            DATE          NOT NULL,
  currency_code       CHAR(3)       NOT NULL,
  -- DEC-047: se factura todo desde Peru. Eso hace que el regimen NO sea
  -- constante: al cliente peruano se le grava con IGV; al cliente del exterior
  -- la operacion califica como exportacion de servicios y va sin IGV. Guardar
  -- solo el importe del impuesto perderia el POR QUE fue ese importe, que es
  -- justo lo que pregunta una fiscalizacion.
  tax_regime          VARCHAR(15)   NOT NULL DEFAULT 'gravado',
  subtotal_amount     DECIMAL(18,4) NOT NULL,
  tax_amount          DECIMAL(18,4) NOT NULL DEFAULT 0,
  total_amount        DECIMAL(18,4) NOT NULL,
  status              VARCHAR(15)   NOT NULL DEFAULT 'draft',
  -- BR-LE-005: copia congelada del emisor. La sociedad cambia de domicilio; la
  -- factura de ayer no. Sin esto habria que reimprimir el pasado.
  issuer_legal_name_snapshot VARCHAR(200) NOT NULL,
  issuer_tax_id_snapshot     VARCHAR(40)  NOT NULL,
  issuer_address_snapshot    VARCHAR(300) NOT NULL,
  -- Y del receptor, por lo mismo.
  receiver_legal_name_snapshot VARCHAR(200) NOT NULL,
  receiver_tax_id_snapshot     VARCHAR(40)  NOT NULL,
  receiver_address_snapshot    VARCHAR(300) NOT NULL,
  -- El pais del receptor tambien se congela: es lo que determina si estaba
  -- domiciliado, y sin el no se puede reconstruir por que la factura fue
  -- gravada o exportacion. El perfil fiscal del cliente puede cambiar de pais.
  receiver_country_snapshot    CHAR(2)      NOT NULL,
  -- DEC-033: con que conexion se emitio, para poder consultar su estado luego.
  integration_connection_snapshot VARCHAR(60) NULL,
  external_status     VARCHAR(30)   NULL,
  file_id             BIGINT UNSIGNED NULL,
  issued_at           DATETIME(3)   NULL,
  voided_at           DATETIME(3)   NULL,
  created_at          DATETIME(3)   NULL,
  updated_at          DATETIME(3)   NULL,
  UNIQUE KEY uq_inv_uuid2 (uuid),
  -- Serie y correlativo unicos por sociedad: es la exigencia de SUNAT.
  UNIQUE KEY uq_invoice_number (legal_entity_id, document_type, series, number),
  KEY ix_invoice_client (client_organization_id, status),
  KEY ix_invoice_campaign (campaign_id),
  KEY ix_invoice_issue (issue_date, status),
  KEY ix_invoice_profile (client_tax_profile_id),
  KEY ix_invoice_currency (currency_code),
  KEY ix_invoice_file (file_id),
  CONSTRAINT fk_invoice_entity FOREIGN KEY (legal_entity_id) REFERENCES legal_entities(id) ON DELETE RESTRICT,
  CONSTRAINT fk_invoice_client FOREIGN KEY (client_organization_id) REFERENCES client_organizations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_invoice_profile FOREIGN KEY (client_tax_profile_id) REFERENCES client_tax_profiles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_invoice_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_invoice_currency FOREIGN KEY (currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT fk_invoice_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT ck_invoice_status CHECK (status IN ('draft','issued','sent','paid','partially_paid','voided','rejected')),
  CONSTRAINT ck_invoice_type CHECK (document_type IN ('invoice','boleta','credit_note','debit_note')),
  CONSTRAINT ck_invoice_amounts CHECK (subtotal_amount >= 0 AND tax_amount >= 0 AND total_amount >= 0),
  -- La aritmetica la comprueba la base, no quien teclea.
  CONSTRAINT ck_invoice_math CHECK (total_amount = subtotal_amount + tax_amount),
  CONSTRAINT ck_invoice_dates CHECK (due_date >= issue_date),
  CONSTRAINT ck_invoice_number CHECK (number >= 1),
  CONSTRAINT ck_invoice_regime CHECK (tax_regime IN ('gravado','exportacion','exonerado','inafecto')),
  -- Una exportacion de servicios no lleva IGV. Si lleva, o no es exportacion o
  -- alguien se equivoco: las dos cosas hay que pararlas aqui.
  CONSTRAINT ck_invoice_regime_tax CHECK (tax_regime = 'gravado' OR tax_amount = 0),
  -- Y no se exporta a un cliente domiciliado en Peru.
  CONSTRAINT ck_invoice_regime_country CHECK (tax_regime <> 'exportacion' OR receiver_country_snapshot <> 'PE'),
  CONSTRAINT ck_invoice_issued CHECK (status = 'draft' OR issued_at IS NOT NULL),
  CONSTRAINT ck_invoice_voided CHECK (status <> 'voided' OR voided_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_lines (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id    BIGINT UNSIGNED NOT NULL,
  line_number   SMALLINT UNSIGNED NOT NULL,
  description   VARCHAR(300)  NOT NULL,
  quantity      DECIMAL(12,4) NOT NULL DEFAULT 1,
  unit_price    DECIMAL(18,4) NOT NULL,
  line_subtotal DECIMAL(18,4) NOT NULL,
  tax_rate      DECIMAL(7,4)  NOT NULL DEFAULT 0,
  line_tax      DECIMAL(18,4) NOT NULL DEFAULT 0,
  line_total    DECIMAL(18,4) NOT NULL,
  UNIQUE KEY uq_iline_number (invoice_id, line_number),
  CONSTRAINT fk_iline_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT ck_iline_quantity CHECK (quantity > 0),
  CONSTRAINT ck_iline_amounts CHECK (unit_price >= 0 AND line_subtotal >= 0 AND line_tax >= 0 AND line_total >= 0),
  CONSTRAINT ck_iline_math CHECK (line_total = line_subtotal + line_tax)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================ Cobros del cliente
CREATE TABLE payments (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36)      NOT NULL,
  invoice_id    BIGINT UNSIGNED NOT NULL,
  amount        DECIMAL(18,4) NOT NULL,
  currency_code CHAR(3)       NOT NULL,
  method        VARCHAR(20)   NOT NULL DEFAULT 'transfer',
  reference     VARCHAR(80)   NULL,
  received_on   DATE          NOT NULL,
  file_id       BIGINT UNSIGNED NULL,
  registered_by_user_id BIGINT UNSIGNED NULL,
  created_at    DATETIME(3)   NULL,
  UNIQUE KEY uq_payment_uuid (uuid),
  KEY ix_payment_invoice (invoice_id, received_on),
  KEY ix_payment_currency (currency_code),
  KEY ix_payment_file (file_id),
  KEY ix_payment_user (registered_by_user_id),
  CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payment_currency FOREIGN KEY (currency_code) REFERENCES currencies(code) ON DELETE RESTRICT,
  CONSTRAINT fk_payment_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payment_user FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT ck_payment_amount CHECK (amount > 0),
  CONSTRAINT ck_payment_method CHECK (method IN ('transfer','deposit','check','card','other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- INMUTABILIDAD. Estas guardas NO son restricciones declarativas del
-- compilador: son disparadores en los dos motores por igual, porque expresan
-- algo que ningun CHECK puede expresar (prohibir un verbo, no un valor).
--
-- Regla del cliente, textual: "la informacion financiera nunca se elimina
-- fisicamente". Aqui deja de ser una promesa de la capa de aplicacion -- que
-- cualquiera con la contrasena de BD se salta -- y pasa a ser fisica.
-- ===========================================================================

DELIMITER //

-- El libro mayor es solo-insercion. Lo unico que evoluciona es el estado.
CREATE TRIGGER tg_ledger_no_update BEFORE UPDATE ON ledger_entries
FOR EACH ROW
BEGIN
  IF NOT (NEW.uuid <=> OLD.uuid)
     OR NOT (NEW.creator_id <=> OLD.creator_id)
     OR NOT (NEW.entry_type <=> OLD.entry_type)
     OR NOT (NEW.amount <=> OLD.amount)
     OR NOT (NEW.currency_code <=> OLD.currency_code)
     OR NOT (NEW.campaign_creator_id <=> OLD.campaign_creator_id)
     OR NOT (NEW.payout_id <=> OLD.payout_id)
     OR NOT (NEW.exchange_rate_snapshot <=> OLD.exchange_rate_snapshot)
     OR NOT (NEW.exchange_rate_date <=> OLD.exchange_rate_date)
     OR NOT (NEW.exchange_rate_source <=> OLD.exchange_rate_source)
     OR NOT (NEW.base_currency_code <=> OLD.base_currency_code)
     OR NOT (NEW.base_amount <=> OLD.base_amount)
     OR NOT (NEW.withholding_rate_snapshot <=> OLD.withholding_rate_snapshot)
     OR NOT (NEW.withholding_basis_snapshot <=> OLD.withholding_basis_snapshot)
     OR NOT (NEW.reverses_entry_id <=> OLD.reverses_entry_id)
     OR NOT (NEW.description <=> OLD.description)
     OR NOT (NEW.occurred_at <=> OLD.occurred_at)
     OR NOT (NEW.created_at <=> OLD.created_at)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ledger_entries es solo-insercion: corrija con un asiento de reversion (BR-FIN-002). Solo status es mutable.';
  END IF;
END//

CREATE TRIGGER tg_ledger_no_delete BEFORE DELETE ON ledger_entries
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ledger_entries no admite borrado fisico (BR-FIN-001).';
END//

-- Una factura emitida ya salio al mundo (y a la administracion tributaria).
-- Un borrador todavia no existe para nadie: ese si puede desaparecer.
CREATE TRIGGER tg_invoice_no_delete BEFORE DELETE ON invoices
FOR EACH ROW
BEGIN
  IF OLD.status <> 'draft' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Una factura emitida no se borra: se anula (status=voided).';
  END IF;
END//

CREATE TRIGGER tg_iline_no_delete BEFORE DELETE ON invoice_lines
FOR EACH ROW
BEGIN
  IF (SELECT status FROM invoices WHERE id = OLD.invoice_id) <> 'draft' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'No se alteran las lineas de una factura ya emitida.';
  END IF;
END//

-- Un pago que ya salio al banco no se borra aunque vuelva devuelto.
CREATE TRIGGER tg_payout_no_delete BEFORE DELETE ON payouts
FOR EACH ROW
BEGIN
  IF OLD.status <> 'pending' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Un pago ya enviado al banco no se borra: se marca devuelto o cancelado.';
  END IF;
END//

CREATE TRIGGER tg_payment_no_delete BEFORE DELETE ON payments
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Un cobro registrado no se borra: registre el extorno correspondiente.';
END//

CREATE TRIGGER tg_cco_no_delete BEFORE DELETE ON campaign_costs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Un costo de campana no se borra: se anula (voided_at), para poder reconstruir el margen historico.';
END//

DELIMITER ;
