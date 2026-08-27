#!/bin/bash
# Pruebas de restriccion de la iteracion 9.1: tipos de cambio.
#
#   ck_fx_side        el lado es compra, venta o medio
#   uq_fx_rate        la misma fuente publica compra y venta el mismo dia, y
#                     no publica dos veces el mismo lado
#   fk_fx_source      la fuente deja de ser texto libre
#   tg_fx_inmutable   una tasa publicada no se reescribe (`BR-FIN-009`)
#   tg_fx_no_delete   ni se borra (viene de 3.12)
#   uq_fos_current    una sola fuente oficial VIGENTE por par
#   fos_sin_solape    y una sola por FECHA, que es la mitad que se olvida
#   ck_fos_distinct   una fuente oficial necesita dos monedas distintas
#   ck_fos_dates      y no puede terminar antes de empezar
#
# Toda esta suite escribe a MANO. `Cambio` nunca deja pasar nada de esto, y esa
# es justamente la razon: lo que solo protege el servicio no protege al proximo
# que escriba en la tabla --y aqui el proximo va a ser un cron--.
#
# Uso: bash tools/pruebas/9.1-cambio.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.1 - Tipos de cambio"
echo "==================================================================================="

# La premisa. Sin las dos monedas y las dos fuentes, media suite saldria verde
# por el motivo equivocado --rechazada por una clave ajena que no es la que se
# esta probando--.
valor "USD y PEN estan en el catalogo" \
  "SELECT COUNT(*) FROM currencies WHERE code IN ('USD','PEN');" "2"
valor "las fuentes sunat y manual existen" \
  "SELECT COUNT(*) FROM fx_sources WHERE code IN ('sunat','manual');" "2"

$CLIENTE $DB -e "DELETE FROM fx_official_sources WHERE base_currency_code='USD' AND quote_currency_code='PEN';" 2>&1 | grep -i error

# `exchange_rates` NO se puede limpiar: entro en la lista de 3.12. Asi que la
# premisa se afirma en vez de forzarse --si esta sucia, media suite fallaria por
# un 1062 que no tiene nada que ver con lo que prueba, que es exactamente el
# fallo que `porque()` existe para no repetir--.
valor "ninguna tasa mia de una pasada anterior" \
  "SELECT COUNT(*) FROM exchange_rates WHERE rate_date BETWEEN '2026-08-14' AND '2026-08-15';" "0"

echo ""
echo "-- La tasa: lado, fuente y unicidad --"

probar "una tasa de venta entra" \
  "INSERT INTO exchange_rates
     (base_currency_code,quote_currency_code,rate_date,rate,side,source,fetched_at,created_at)
   VALUES ('USD','PEN','2026-08-14',3.74200000,'sell','sunat',NOW(3),NOW(3));" OK

probar "y la de compra del MISMO dia tambien: son dos filas" \
  "INSERT INTO exchange_rates
     (base_currency_code,quote_currency_code,rate_date,rate,side,source,fetched_at,created_at)
   VALUES ('USD','PEN','2026-08-14',3.73500000,'buy','sunat',NOW(3),NOW(3));" OK

porque "el mismo lado dos veces no" \
  "INSERT INTO exchange_rates
     (base_currency_code,quote_currency_code,rate_date,rate,side,source,fetched_at,created_at)
   VALUES ('USD','PEN','2026-08-14',3.99000000,'sell','sunat',NOW(3),NOW(3));" "uq_fx_rate"

porque "un lado inventado no entra" \
  "INSERT INTO exchange_rates
     (base_currency_code,quote_currency_code,rate_date,rate,side,source,fetched_at,created_at)
   VALUES ('USD','PEN','2026-08-15',3.74000000,'promedio','sunat',NOW(3),NOW(3));" "ck_fx_side|Lado del tipo de cambio"

porque "ni una fuente que no esta en el catalogo" \
  "INSERT INTO exchange_rates
     (base_currency_code,quote_currency_code,rate_date,rate,side,source,fetched_at,created_at)
   VALUES ('USD','PEN','2026-08-15',3.74000000,'sell','bcrp',NOW(3),NOW(3));" "fk_fx_source"

# Y lo que la clave ajena NO hace, dicho aqui para que nadie lo suponga: el
# cotejamiento de la columna es `utf8mb4_unicode_ci`, o sea que 'SUNAT' y
# 'sunat' son el MISMO valor para la clave ajena. Entra, y se guarda tal cual se
# escribio. Si algun dia hace falta que no, es un `COLLATE ... _bin`, no una
# clave ajena.
probar "y 'SUNAT' en mayusculas entra: el cotejamiento no distingue" \
  "INSERT INTO exchange_rates
     (base_currency_code,quote_currency_code,rate_date,rate,side,source,fetched_at,created_at)
   VALUES ('USD','PEN','2026-08-15',3.74000000,'sell','SUNAT',NOW(3),NOW(3));" OK

porque "una tasa cero no es una tasa" \
  "INSERT INTO exchange_rates
     (base_currency_code,quote_currency_code,rate_date,rate,side,source,fetched_at,created_at)
   VALUES ('USD','PEN','2026-08-15',0,'sell','sunat',NOW(3),NOW(3));" "ck_exchange_rates_positive|mayor que cero"

porque "ni convertir una moneda a si misma" \
  "INSERT INTO exchange_rates
     (base_currency_code,quote_currency_code,rate_date,rate,side,source,fetched_at,created_at)
   VALUES ('PEN','PEN','2026-08-15',1,'sell','sunat',NOW(3),NOW(3));" "ck_exchange_rates_distinct|dos monedas distintas"

echo ""
echo "-- Publicada es publicada --"

porque "una tasa publicada no se reescribe" \
  "UPDATE exchange_rates SET rate=9.99999999
    WHERE base_currency_code='USD' AND rate_date='2026-08-14' AND side='sell';" "no se modifica"

porque "ni se le mueve la fecha" \
  "UPDATE exchange_rates SET rate_date='2026-08-13'
    WHERE base_currency_code='USD' AND rate_date='2026-08-14' AND side='sell';" "no se modifica"

porque "ni se borra" \
  "DELETE FROM exchange_rates WHERE base_currency_code='USD' AND rate_date='2026-08-14';" "no admite borrado"

echo ""
echo "-- Quien manda: una por par, y una por FECHA --"

probar "se declara sunat oficial para USD-PEN desde enero" \
  "INSERT INTO fx_official_sources
     (base_currency_code,quote_currency_code,source_code,valid_from,created_at)
   VALUES ('USD','PEN','sunat','2026-01-01',NOW(3));" OK

# Contesta el disparador de solape, no `uq_fos_current`, y se afirma lo que
# contesta de verdad. Dos filas ABIERTAS siempre se solapan --las dos llegan
# hasta el infinito-- asi que la regla de periodos llega primero y no hay forma
# de dejar mal solo la clave unica. `uq_fos_current` sigue debajo, y es la que
# responderia el dia que alguien se lleve el disparador por delante.
porque "una segunda fuente abierta para el mismo par no cabe" \
  "INSERT INTO fx_official_sources
     (base_currency_code,quote_currency_code,source_code,valid_from,created_at)
   VALUES ('USD','PEN','manual','2026-03-01',NOW(3));" "Ya hay una fuente oficial"

# Y la mitad que se olvida. Se cierra la de sunat en mayo y se intenta meter una
# CERRADA que se pisa con ella: `uq_fos_current` no la ve --ninguna de las dos
# queda abierta-- y el resolver elige por par Y por fecha.
$CLIENTE $DB -e "UPDATE fx_official_sources SET valid_to='2026-05-31'
   WHERE base_currency_code='USD' AND quote_currency_code='PEN' AND valid_to IS NULL;" 2>&1 | grep -i error

porque "ni una CERRADA que se pise con la anterior" \
  "INSERT INTO fx_official_sources
     (base_currency_code,quote_currency_code,source_code,valid_from,valid_to,created_at)
   VALUES ('USD','PEN','manual','2026-03-01','2026-08-31',NOW(3));" "Ya hay una fuente oficial"

probar "la que empieza el dia siguiente si" \
  "INSERT INTO fx_official_sources
     (base_currency_code,quote_currency_code,source_code,valid_from,created_at)
   VALUES ('USD','PEN','manual','2026-06-01',NOW(3));" OK

valor "y para el 31 de mayo sigue mandando sunat" \
  "SELECT source_code FROM fx_official_sources
    WHERE base_currency_code='USD' AND quote_currency_code='PEN'
      AND valid_from<='2026-05-31' AND (valid_to IS NULL OR valid_to>='2026-05-31');" "sunat"

porque "una fuente oficial no termina antes de empezar" \
  "INSERT INTO fx_official_sources
     (base_currency_code,quote_currency_code,source_code,valid_from,valid_to,created_at)
   VALUES ('USD','COP','sunat','2026-06-01','2026-01-01',NOW(3));" "ck_fos_dates|antes de empezar"

porque "ni manda sobre una moneda y ella misma" \
  "INSERT INTO fx_official_sources
     (base_currency_code,quote_currency_code,source_code,valid_from,created_at)
   VALUES ('PEN','PEN','sunat','2026-01-01',NOW(3));" "ck_fos_distinct|dos monedas distintas"

resumen
