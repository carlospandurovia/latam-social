-- LATAM Social - sonda de capacidades del motor de PRODUCCION
--
-- Que hace: crea UNA tabla temporal llamada zz_sonda_latam, la interroga y la borra.
-- No lee ni modifica ninguna tabla existente. Se puede pegar entera en phpMyAdmin.
--
-- Que responde: si este servidor aplica de verdad las restricciones que el
-- esquema declara, o si las acepta y las ignora en silencio (DEC-042).

SELECT VERSION() AS version_servidor,
       @@character_set_database AS charset_base,
       @@collation_database     AS collation_base,
       @@sql_mode               AS sql_mode;

-- Aparte a proposito: innodb_large_prefix existe en 5.7 y fue ELIMINADA en
-- MySQL 8. Si va en el SELECT de arriba, tumba toda la consulta en MySQL 8.
-- Si esta linea da error de variable desconocida, es buena noticia: estas en 8+.
SELECT @@innodb_large_prefix AS large_prefix_solo_en_5_7;

-- ---------------------------------------------------------------- 1. CHECK
DROP TABLE IF EXISTS zz_sonda_latam;
CREATE TABLE zz_sonda_latam (
  n INT,
  CONSTRAINT ck_sonda CHECK (n > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Si el motor aplica el CHECK, esta linea da error y no inserta nada.
-- Si NO lo aplica, la acepta sin decir nada. Ese es el problema.
INSERT INTO zz_sonda_latam (n) VALUES (-1);

SELECT CASE WHEN COUNT(*) = 0
            THEN 'BIEN: el motor RECHAZO el valor prohibido. Los CHECK se aplican.'
            ELSE 'PROBLEMA: el motor ACEPTO -1 pese al CHECK (n > 0). Las restricciones del esquema NO se aplican aqui.'
       END AS resultado_check
FROM zz_sonda_latam;

DROP TABLE zz_sonda_latam;

-- ------------------------------------------ 2. utf8mb4 de verdad (emoji)
DROP TABLE IF EXISTS zz_sonda_latam;
CREATE TABLE zz_sonda_latam (t VARCHAR(20)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO zz_sonda_latam (t) VALUES ('emoji: 🎬');
SELECT CASE WHEN t LIKE '%🎬%'
            THEN 'BIEN: almacena emoji (4 bytes).'
            ELSE 'PROBLEMA: el emoji no sobrevivio. Contenido de redes sociales se corrompera.'
       END AS resultado_utf8mb4
FROM zz_sonda_latam;
DROP TABLE zz_sonda_latam;

-- ------------------------------------------------- 3. CTE (WITH) y ventanas
-- Si alguna de estas dos da error de sintaxis, este motor es anterior a MySQL 8
-- y D12 Intelligence no puede escribirse como dice docs/03.
WITH x AS (SELECT 1 AS a) SELECT a AS resultado_cte FROM x;

SELECT ROW_NUMBER() OVER (ORDER BY 1) AS resultado_funcion_ventana;
