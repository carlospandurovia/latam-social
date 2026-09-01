#!/bin/bash
# Pruebas de restriccion de la iteracion 9.2: la traida automatica.
#
#   ck_ffr_outcome            cada final tiene su nombre, y solo esos
#   ck_ffr_nuevas             una corrida fallida no pudo anotar ninguna tasa
#   fk_ffr_source             una corrida es de una fuente que existe
#   fk_fxs_conn               la fuente cuelga de una conexion que existe (9.17h)
#
# `ck_fxs_last4` y `tg_fxs_credencial_firmada` YA NO ESTAN, y no porque hayan
# dejado de importar: la clave se mudo en 9.17h a `integration_credentials`,
# donde `set_by_user_id` y `set_at` son NOT NULL. La regla no se ha perdido, se
# ha convertido en dos columnas obligatorias, que es mas barato de sostener que
# un disparador. Lo que la afirma ahora esta en `9.17d-integraciones.sh`.
#
# La que importa es `ck_ffr_nuevas`. Sin ella, un registro puede decir que una
# corrida fallida trajo tres tasas --y ese registro es lo unico que contesta
# «.sigue vivo el cron?»--.
#
# Uso: bash tools/pruebas/9.2-traida.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.2 - La traida automatica"
echo "==================================================================================="

USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"

valor "ninguna corrida de una pasada anterior" \
  "SELECT COUNT(*) FROM fx_fetch_runs;" "0"
valor "la fuente sunat existe" \
  "SELECT COUNT(*) FROM fx_sources WHERE code='sunat';" "1"

echo ""
echo "-- El registro de corridas --"

probar "una corrida buena con dos tasas entra" \
  "INSERT INTO fx_fetch_runs (source_code,requested_date,ran_at,outcome,rates_new,http_status,created_at)
   VALUES ('sunat','2026-08-14',NOW(3),'ok',2,200,NOW(3));" OK

probar "y una fallida que no trajo nada, tambien" \
  "INSERT INTO fx_fetch_runs (source_code,requested_date,ran_at,outcome,rates_new,http_status,created_at)
   VALUES ('sunat','2026-08-15',NOW(3),'error_http',0,500,NOW(3));" OK

porque "una fallida NO puede decir que trajo tasas" \
  "INSERT INTO fx_fetch_runs (source_code,requested_date,ran_at,outcome,rates_new,created_at)
   VALUES ('sunat','2026-08-16',NOW(3),'error_http',3,NOW(3));" "ck_ffr_nuevas|no pudo anotar"

porque "ni existe un final que no esta en la lista" \
  "INSERT INTO fx_fetch_runs (source_code,requested_date,ran_at,outcome,rates_new,created_at)
   VALUES ('sunat','2026-08-16',NOW(3),'raro',0,NOW(3));" "ck_ffr_outcome|Resultado de la traida"

porque "ni una corrida de una fuente que nadie declaro" \
  "INSERT INTO fx_fetch_runs (source_code,requested_date,ran_at,outcome,rates_new,created_at)
   VALUES ('bcrp','2026-08-16',NOW(3),'ok',0,NOW(3));" "fk_ffr_source"

echo ""
echo "-- La fuente y su conexion (9.17h) --"

# La caja fuerte propia de `fx_sources` desaparecio. Lo que queda que afirmar
# aqui es el vinculo: de que conexion cuelga cada fuente, y que no se comparte.
#
# El proveedor lo crea la suite: `semilla.sql` no siembra el catalogo de
# integraciones --lo hace `CimientosSeeder`, que aqui no corre-- y una suite que
# depende de una fila que otro puso es una suite que un dia falla sin culpa.
valor "la fuente ya no guarda claves" \
  "SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fx_sources'
      AND COLUMN_NAME IN ('api_key_cipher','api_key_last4','credential_set_at',
                          'credential_set_by_user_id','api_base_url');" "0"

porque "una fuente no puede colgar de una conexion que no existe" \
  "UPDATE fx_sources SET integration_connection_id=999999 WHERE code='sunat';" "fk_fxs_conn"

probar "se da de alta un proveedor de tipos de cambio" \
  "INSERT INTO integration_providers (code,name,purpose,is_active,created_at,updated_at)
   VALUES ('x92_fx','Pasarela de la suite 9.2','fx',1,NOW(3),NOW(3));" OK

PROV="(SELECT id FROM (SELECT id FROM integration_providers WHERE code='x92_fx') p)"

# Sin extremo declarado la conexion no se puede activar (`DEC-255`), y eso es
# justo lo que 9.17h vino a arreglar para Decolecta: la direccion la declara el
# catalogo, no la teclea nadie.
porque "sin direccion declarada, la conexion no se puede activar" \
  "INSERT INTO integration_connections
     (uuid,integration_provider_id,name,environment,status,created_at,updated_at)
   VALUES (UUID(),$PROV,'x92 sin extremo','production','active',NOW(3),NOW(3));" \
  "tg_iconn_activa_ins|no sabe a donde llamar"

probar "se declara donde vive" \
  "INSERT INTO integration_provider_endpoints
     (integration_provider_id,environment,base_url,label,created_at,updated_at)
   VALUES ($PROV,'production','https://api.x92.test','API de la suite',NOW(3),NOW(3));" OK

probar "y ahora la conexion entra activa sin teclear ninguna URL" \
  "INSERT INTO integration_connections
     (uuid,integration_provider_id,name,environment,status,created_at,updated_at)
   VALUES (UUID(),$PROV,'x92 conexion','production','active',NOW(3),NOW(3));" OK

CONN="(SELECT id FROM (SELECT id FROM integration_connections WHERE name='x92 conexion') c)"

probar "la fuente cuelga de ella" \
  "UPDATE fx_sources SET integration_connection_id=$CONN WHERE code='sunat';" OK

# Dos fuentes con la misma conexion dejaria sin respuesta «.de quien es esta
# clave?», que es justo lo que hay que poder contestar de una credencial.
porque "pero dos fuentes no pueden compartir la misma conexion" \
  "UPDATE fx_sources SET integration_connection_id=$CONN WHERE code='manual';" "uq_fxs_conexion"

# Y la conexion no se puede borrar mientras una fuente cuelgue de ella: RESTRICT
# y no CASCADE, porque llevarse por delante la fuente al borrar una conexion es
# perder de que fuente venia cada tasa ya anotada.
porque "ni se borra la conexion mientras la fuente cuelgue de ella" \
  "DELETE FROM integration_connections WHERE name='x92 conexion';" "fk_fxs_conn"

echo ""
echo "-- Y la suite deja la base como la encontro --"

probar "se suelta la fuente" \
  "UPDATE fx_sources SET integration_connection_id=NULL WHERE code='sunat';" OK
probar "se borra la conexion" \
  "DELETE FROM integration_connections WHERE name='x92 conexion';" OK
probar "el extremo declarado" \
  "DELETE FROM integration_provider_endpoints WHERE base_url='https://api.x92.test';" OK
probar "y el proveedor de la suite" \
  "DELETE FROM integration_providers WHERE code='x92_fx';" OK

resumen
