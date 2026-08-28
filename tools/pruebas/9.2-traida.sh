#!/bin/bash
# Pruebas de restriccion de la iteracion 9.2: la traida automatica.
#
#   ck_ffr_outcome            cada final tiene su nombre, y solo esos
#   ck_ffr_nuevas             una corrida fallida no pudo anotar ninguna tasa
#   ck_fxs_last4              la pista de la credencial son cuatro caracteres
#   tg_fxs_credencial_firmada media firma no vale: cifrado sin autor no entra
#   fk_ffr_source             una corrida es de una fuente que existe
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
echo "-- La credencial --"

porque "una credencial cifrada sin autor no entra" \
  "UPDATE fx_sources SET api_key_cipher='loquesea', api_key_last4='8f2a',
      credential_set_at=NOW(3), credential_set_by_user_id=NULL WHERE code='sunat';" "exige quien la puso"

porque "ni sin fecha" \
  "UPDATE fx_sources SET api_key_cipher='loquesea', api_key_last4='8f2a',
      credential_set_at=NULL, credential_set_by_user_id=$USR WHERE code='sunat';" "exige quien la puso"

porque "ni sin sus cuatro ultimos" \
  "UPDATE fx_sources SET api_key_cipher='loquesea', api_key_last4=NULL,
      credential_set_at=NOW(3), credential_set_by_user_id=$USR WHERE code='sunat';" "exige quien la puso"

porque "una pista de tres caracteres no es una pista" \
  "UPDATE fx_sources SET api_key_last4='8f2' WHERE code='sunat';" "ck_fxs_last4|exactamente cuatro"

probar "con las tres cosas si entra" \
  "UPDATE fx_sources SET api_key_cipher='loquesea', api_key_last4='8f2a',
      credential_set_at=NOW(3), credential_set_by_user_id=$USR WHERE code='sunat';" OK

# Y borrarla tiene que poder hacerse: dejar de tener credencial es un estado
# legitimo, y una regla que solo mira `api_key_cipher IS NOT NULL` lo permite.
probar "y borrarla entera tambien" \
  "UPDATE fx_sources SET api_key_cipher=NULL, api_key_last4=NULL,
      credential_set_at=NULL, credential_set_by_user_id=NULL WHERE code='sunat';" OK

resumen
