-- =============================================================================
-- LATAM Social — ¿este MySQL sirve? (docs/18 §0.3)
-- Ejecutar CON EL USUARIO DE MIGRACIONES, sobre la base ya creada.
-- Sólo crea y borra una tabla de sonda. No toca nada más.
-- =============================================================================

-- 1) Qué motor es, y en qué modo está
SELECT VERSION()                    AS version,
       @@sql_mode                   AS modo_sql,
       @@character_set_database     AS charset,
       @@collation_database         AS cotejamiento,
       @@log_bin                    AS binlog_activo,
       @@log_bin_trust_function_creators AS confia_en_creadores;

-- 2) Con qué usuario estoy y qué privilegios tengo
SELECT CURRENT_USER() AS usuario_real, USER() AS usuario_conectado;
SHOW GRANTS FOR CURRENT_USER();

-- 3) LA PRUEBA QUE DECIDE. Si la línea del TRIGGER falla, este hosting
--    no puede alojar el proyecto hasta que el proveedor lo arregle.
DROP TABLE IF EXISTS zz_prueba;
CREATE TABLE zz_prueba (n INT, CONSTRAINT ck_zz CHECK (n > 0));

-- 3a) ¿Aplica CHECK de verdad? En 5.7 esta fila ENTRA (el CHECK se ignora).
--     Que entre es normal y esperado: por eso el proyecto usa disparadores.
INSERT INTO zz_prueba (n) VALUES (-1);
SELECT COUNT(*) AS filas_que_no_deberian_estar FROM zz_prueba;

-- 3b) ¿Puedo crear disparadores? ESTO es lo que no puede fallar.
CREATE TRIGGER zz_prueba_bi BEFORE INSERT ON zz_prueba
FOR EACH ROW SET NEW.n = NEW.n;

-- 4) Limpieza
DROP TABLE zz_prueba;

SELECT 'Si has llegado hasta aqui sin error, el motor sirve.' AS resultado;
