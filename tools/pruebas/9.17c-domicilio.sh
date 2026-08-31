#!/bin/bash
# Pruebas de restriccion de la iteracion 9.17c: el domicilio fiscal por pais.
#
#   ck_countries_localidad          un patron sin etiqueta es media configuracion
#   ck_countries_localidad_exigida  exigirlo sin decir que forma tiene no se puede comprobar
#   ck_le_localidad                 la forma GENERAL, la que vale en los seis paises
#   ck_le_establecimiento           letras y numeros
#   ck_le_distrito                  o se pone o se deja vacio, pero no en blanco
#   tg_le_localidad_ins             la forma de CADA pais, al dar de alta
#   tg_le_localidad_upd             y tambien al editar, que es donde se olvida
#
# La que importa es la ultima. El comprobante peruano lleva el ubigeo del INEI
# --seis digitos-- y el colombiano el codigo DANE --cinco--. Un CHECK de seis
# digitos en `legal_entities` seria la regla de un pais escrita en el codigo de
# todos, y rechazaria un codigo colombiano correcto. La forma la declara el pais
# y la aplica un disparador cruzado (DEC-209).
#
# Uso: bash tools/pruebas/9.17c-domicilio.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.17c - El domicilio fiscal, con la forma que exige cada pais"
echo "==================================================================================="

# La suite empieza limpiando lo suyo: si una corrida anterior murio a medias,
# `uq_le_code` haria fallar el primer INSERT por un motivo que no se esta
# probando. Y construye SU premisa --dos paises con patrones distintos-- en vez
# de suponerla, que es la leccion de 9.14 y de 9.17.
$CLIENTE $DB -e "DELETE FROM legal_entities WHERE code LIKE 'E917C%';
                 DELETE FROM countries WHERE iso2 IN ('ZP','ZQ','ZR');" >/dev/null 2>&1

# La moneda sale del catalogo en vez de escribirse: `fk_countries_currency` la
# exige, y cual haya sembrada no es asunto de esta suite.
MONEDA="(SELECT code FROM (SELECT code FROM currencies ORDER BY code LIMIT 1) c)"
MARCA="(SELECT id FROM (SELECT id FROM platform_brands ORDER BY id LIMIT 1) m)"
PZP="(SELECT id FROM (SELECT id FROM countries WHERE iso2='ZP') p)"
PZQ="(SELECT id FROM (SELECT id FROM countries WHERE iso2='ZQ') p)"
PZR="(SELECT id FROM (SELECT id FROM countries WHERE iso2='ZR') p)"

# Las premisas se comprueban ANTES de usarlas: sin monedas ni marca, la mitad de
# las aserciones fallarian acusando a una regla que no es la que fallo. Es la
# leccion de 9.14 y ya van tres suites que la aplican.
valor "hay monedas en el catalogo" \
  "SELECT CASE WHEN COUNT(*) > 0 THEN 'si' ELSE 'no' END FROM currencies;" "si"

valor "hay una marca de plataforma a la que colgar las sociedades" \
  "SELECT CASE WHEN COUNT(*) > 0 THEN 'si' ELSE 'no' END FROM platform_brands;" "si"

echo ""
echo "-- Media configuracion es peor que ninguna --"

porque "un patron sin decir como se llama" \
  "INSERT INTO countries (iso2,iso3,numeric_code,name,phone_code,default_currency_code,timezone,
      tax_location_pattern,created_at)
   VALUES ('ZP','ZPA','901','Pais de prueba P','+99',$MONEDA,'UTC','^[0-9]{6}\$',NOW(3));" \
  "ck_countries_localidad|como se llama"

porque "y exigirlo sin decir que forma tiene" \
  "INSERT INTO countries (iso2,iso3,numeric_code,name,phone_code,default_currency_code,timezone,
      requires_tax_location,created_at)
   VALUES ('ZP','ZPA','901','Pais de prueba P','+99',$MONEDA,'UTC',1,NOW(3));" \
  "ck_countries_localidad_exigida|que forma tiene"

echo ""
echo "-- La premisa: dos paises con formas DISTINTAS --"

probar "un pais que pide seis digitos" \
  "INSERT INTO countries (iso2,iso3,numeric_code,name,phone_code,default_currency_code,timezone,
      tax_location_label,tax_location_pattern,requires_tax_location,created_at)
   VALUES ('ZP','ZPA','901','Pais de prueba P','+99',$MONEDA,'UTC','Ubigeo','^[0-9]{6}\$',1,NOW(3));" OK

probar "y otro que pide cinco" \
  "INSERT INTO countries (iso2,iso3,numeric_code,name,phone_code,default_currency_code,timezone,
      tax_location_label,tax_location_pattern,created_at)
   VALUES ('ZQ','ZQA','902','Pais de prueba Q','+99',$MONEDA,'UTC','Codigo DANE','^[0-9]{5}\$',NOW(3));" OK

probar "y un tercero que no declara ninguna" \
  "INSERT INTO countries (iso2,iso3,numeric_code,name,phone_code,default_currency_code,timezone,created_at)
   VALUES ('ZR','ZRA','903','Pais de prueba R','+99',$MONEDA,'UTC',NOW(3));" OK

echo ""
echo "-- La forma la declara el PAIS, no el codigo --"

# Seis digitos es correcto en ZP y NO en ZQ: es la misma columna, la misma
# restriccion general, y dos respuestas distintas. Eso es lo que un CHECK no
# puede hacer y por lo que hay un disparador cruzado.
probar "seis digitos en el pais que pide seis" \
  "INSERT INTO legal_entities (uuid,platform_brand_id,code,legal_name,country_id,tax_id_type,
      tax_id_number,address_line1,city,default_currency_code,timezone,tax_location_code,created_at)
   VALUES (UUID(),$MARCA,'E917C_P','Sociedad P',$PZP,'RUC','20000000001','Av. Prueba 1','Lima',
      $MONEDA,'UTC','150101',NOW(3));" OK

# Se nombra el disparador ENTERO --`tg_le_localidad_ins`-- y no su prefijo:
# `verificar-cobertura-sql.py` busca el nombre literal, y con el prefijo daba por
# preguntada una regla y por muda la otra, que tienen el mismo mensaje. Una
# regla que parece cubierta y no lo esta es peor que una que se sabe muda.
porque "los mismos seis digitos en el pais que pide cinco" \
  "INSERT INTO legal_entities (uuid,platform_brand_id,code,legal_name,country_id,tax_id_type,
      tax_id_number,address_line1,city,default_currency_code,timezone,tax_location_code,created_at)
   VALUES (UUID(),$MARCA,'E917C_Q','Sociedad Q',$PZQ,'NIT','900000001','Cra Prueba 1','Bogota',
      $MONEDA,'UTC','150101',NOW(3));" \
  "tg_le_localidad_ins|forma que exige ese pais"

probar "y cinco digitos si que entran en ese pais" \
  "INSERT INTO legal_entities (uuid,platform_brand_id,code,legal_name,country_id,tax_id_type,
      tax_id_number,address_line1,city,default_currency_code,timezone,tax_location_code,created_at)
   VALUES (UUID(),$MARCA,'E917C_Q','Sociedad Q',$PZQ,'NIT','900000001','Cra Prueba 1','Bogota',
      $MONEDA,'UTC','11001',NOW(3));" OK

# Un pais que no ha declarado su forma no puede impedir dar de alta una
# sociedad: es DEC-190. Se admite lo que sea, con la forma general.
probar "un pais sin patron admite cualquier codigo" \
  "INSERT INTO legal_entities (uuid,platform_brand_id,code,legal_name,country_id,tax_id_type,
      tax_id_number,address_line1,city,default_currency_code,timezone,tax_location_code,created_at)
   VALUES (UUID(),$MARCA,'E917C_R','Sociedad R',$PZR,'TAX','900000002','Calle 1','Ciudad',
      $MONEDA,'UTC','AB1234',NOW(3));" OK

echo ""
echo "-- La forma general, la que vale en los seis paises --"

porque "un codigo con un guion" \
  "INSERT INTO legal_entities (uuid,platform_brand_id,code,legal_name,country_id,tax_id_type,
      tax_id_number,address_line1,city,default_currency_code,timezone,tax_location_code,created_at)
   VALUES (UUID(),$MARCA,'E917C_X','Sociedad X',$PZR,'TAX','900000003','Calle 1','Ciudad',
      $MONEDA,'UTC','15-01-01',NOW(3));" \
  "ck_le_localidad|letras y numeros"

porque "un establecimiento con espacios" \
  "INSERT INTO legal_entities (uuid,platform_brand_id,code,legal_name,country_id,tax_id_type,
      tax_id_number,address_line1,city,default_currency_code,timezone,establishment_code,created_at)
   VALUES (UUID(),$MARCA,'E917C_X','Sociedad X',$PZR,'TAX','900000003','Calle 1','Ciudad',
      $MONEDA,'UTC','00 01',NOW(3));" \
  "ck_le_establecimiento|letras y numeros"

porque "un distrito en blanco, que no es lo mismo que sin distrito" \
  "INSERT INTO legal_entities (uuid,platform_brand_id,code,legal_name,country_id,tax_id_type,
      tax_id_number,address_line1,city,district,default_currency_code,timezone,created_at)
   VALUES (UUID(),$MARCA,'E917C_X','Sociedad X',$PZR,'TAX','900000003','Calle 1','Ciudad','   ',
      $MONEDA,'UTC',NOW(3));" \
  "ck_le_distrito|no en blanco"

echo ""
echo "-- Y al editar tambien, no solo al dar de alta --"

porque "cambiar el codigo por uno de otra forma" \
  "UPDATE legal_entities SET tax_location_code='11001' WHERE code='E917C_P';" \
  "tg_le_localidad_upd|forma que exige ese pais"

valor "el establecimiento nace en 0000 sin que nadie lo escriba" \
  "SELECT establishment_code FROM legal_entities WHERE code='E917C_P';" "0000"

echo ""
echo "-- Se limpia lo suyo --"

probar "las sociedades de la suite se borran" \
  "DELETE FROM legal_entities WHERE code LIKE 'E917C%';" OK

probar "y los paises de prueba tambien" \
  "DELETE FROM countries WHERE iso2 IN ('ZP','ZQ','ZR');" OK

valor "no quedo nada" \
  "SELECT COUNT(*) FROM countries WHERE iso2 IN ('ZP','ZQ','ZR');" "0"

resumen
