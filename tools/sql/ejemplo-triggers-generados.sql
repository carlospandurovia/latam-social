-- Generado por App\Shared\Database\Restriccion (mecanismo: trigger)
DELIMITER $$
CREATE TRIGGER `tg_ck_currencies_decimals_ins`
BEFORE INSERT ON `currencies`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`decimal_places` <= 4) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una moneda no puede tener mas de 4 decimales.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_currencies_decimals_upd`
BEFORE UPDATE ON `currencies`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`decimal_places` <= 4) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una moneda no puede tener mas de 4 decimales.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_exchange_rates_positive_ins`
BEFORE INSERT ON `exchange_rates`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`rate` > 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El tipo de cambio debe ser mayor que cero.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_exchange_rates_positive_upd`
BEFORE UPDATE ON `exchange_rates`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`rate` > 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El tipo de cambio debe ser mayor que cero.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_exchange_rates_distinct_ins`
BEFORE INSERT ON `exchange_rates`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`base_currency_code` <> NEW.`quote_currency_code`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El tipo de cambio necesita dos monedas distintas.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_exchange_rates_distinct_upd`
BEFORE UPDATE ON `exchange_rates`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`base_currency_code` <> NEW.`quote_currency_code`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El tipo de cambio necesita dos monedas distintas.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_categories_depth_ins`
BEFORE INSERT ON `categories`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`depth` <= 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se admiten dos niveles de categoria.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_categories_depth_upd`
BEFORE UPDATE ON `categories`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`depth` <= 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se admiten dos niveles de categoria.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_categories_root_ins`
BEFORE INSERT ON `categories`
FOR EACH ROW
BEGIN
    IF NOT ((NEW.`depth` = 0 AND NEW.`parent_id` IS NULL) OR (NEW.`depth` = 1 AND NEW.`parent_id` IS NOT NULL)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una categoria raiz no tiene padre; un subnicho si.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_categories_root_upd`
BEFORE UPDATE ON `categories`
FOR EACH ROW
BEGIN
    IF NOT ((NEW.`depth` = 0 AND NEW.`parent_id` IS NULL) OR (NEW.`depth` = 1 AND NEW.`parent_id` IS NOT NULL)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una categoria raiz no tiene padre; un subnicho si.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_categories_min_age_ins`
BEFORE INSERT ON `categories`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`min_age` <= 21) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La edad minima de una categoria no puede superar 21.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_categories_min_age_upd`
BEFORE UPDATE ON `categories`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`min_age` <= 21) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La edad minima de una categoria no puede superar 21.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_domain_events_payload_ins`
BEFORE INSERT ON `domain_events`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`payload` IS NULL OR JSON_VALID(NEW.`payload`)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El payload del evento debe ser JSON valido.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_domain_events_payload_upd`
BEFORE UPDATE ON `domain_events`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`payload` IS NULL OR JSON_VALID(NEW.`payload`)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El payload del evento debe ser JSON valido.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_status_transitions_change_ins`
BEFORE INSERT ON `status_transitions`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`from_status` IS NULL OR NEW.`from_status` <> NEW.`to_status`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una transicion de estado a si mismo no es una transicion.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_status_transitions_change_upd`
BEFORE UPDATE ON `status_transitions`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`from_status` IS NULL OR NEW.`from_status` <> NEW.`to_status`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una transicion de estado a si mismo no es una transicion.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_users_type_ins`
BEFORE INSERT ON `users`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`user_type` IN ('internal','client','creator')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tipo de usuario no valido.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_users_type_upd`
BEFORE UPDATE ON `users`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`user_type` IN ('internal','client','creator')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tipo de usuario no valido.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_users_status_ins`
BEFORE INSERT ON `users`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`status` IN ('active','suspended','deactivated')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Estado de usuario no valido.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_users_status_upd`
BEFORE UPDATE ON `users`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`status` IN ('active','suspended','deactivated')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Estado de usuario no valido.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_roles_scope_ins`
BEFORE INSERT ON `roles`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`scope` IN ('internal','client','creator')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ambito de rol no valido.';
    END IF;
END$$
CREATE TRIGGER `tg_ck_roles_scope_upd`
BEFORE UPDATE ON `roles`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`scope` IN ('internal','client','creator')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ambito de rol no valido.';
    END IF;
END$$
DELIMITER ;
