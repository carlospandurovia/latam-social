#!/bin/bash
# Pruebas de restriccion de la iteracion 4.5: sociedades del grupo y cobertura.
#
# De `legal_entities` sale el EMISOR de cada factura (`BR-LE-005`), su numeracion
# (`BR-LE-007`) y sus cuentas de cobro (`BR-LE-006`). De `legal_entity_countries`
# sale QUIEN de ellas puede facturar a un pais y desde cuando (`BR-LE-003`).
#
#   uq_le_code            dos sociedades con el mismo codigo
#   uq_le_taxid           la misma empresa dada de alta dos veces
#   ck_le_status          estado de sociedad no valido
#   ck_le_dates           disuelta antes de constituirse
#   ck_le_dissolved       disuelta sin decir cuando
#   uq_lec_country        dos sociedades cubriendo un pais a la vez
#   tg_lec_sin_solape_*   dos coberturas del mismo pais solapadas en el tiempo
#   ck_lec_basis          motivo de cobertura no valido
#   ck_lec_dates          cobertura que termina antes de empezar
#   no_delete             borrar una cobertura
#
# La seccion «el pais incomunicado» documenta un bloqueo REAL del esquema, no un
# fallo hipotetico: `uq_lec_country` ocupa el sitio del pais mire o no el estado
# de la sociedad, pero quien resuelve la facturacion solo cuenta las ACTIVAS.
# Desactivar sin cerrar la cobertura deja el pais sin cubrir Y sin poder
# cubrirse. La suite fija que la base se comporta asi; que la aplicacion no deje
# llegar ahi es `DEC-081` y se prueba en PHPUnit.
#
# Uso: bash tools/pruebas/4.5-entidades.sh <base>
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

# Los cuatro ayudantes viven en UN sitio desde 8.11: estaban copiados en las
# treinta suites y habian derivado en seis variantes, y nueve de ellas se
# habrian puesto verdes con el motor apagado. Ver `tools/pruebas/comun.sh`.
source "$(dirname "$0")/comun.sh"
# Sociedades propias. La semilla trae CTS-PE y su cobertura de Peru; esta suite
# no la toca, para no repetir el fallo de 4.4 —una suite que solo pasa si es la
# primera— y para dejar Peru en paz por si otra suite depende de el.
$CLIENTE $DB -e "INSERT INTO legal_entities
  (uuid,platform_brand_id,code,legal_name,country_id,tax_id_type,tax_id_number,
   address_line1,city,default_currency_code,timezone,status,created_at)
  SELECT UUID(),platform_brand_id,'E45-A','Sociedad 4.5 A',country_id,'RUC','20450000001',
         'Av 1','Lima',default_currency_code,timezone,'active',NOW(3)
  FROM legal_entities WHERE code='CTS-PE';
  INSERT INTO legal_entities
  (uuid,platform_brand_id,code,legal_name,country_id,tax_id_type,tax_id_number,
   address_line1,city,default_currency_code,timezone,status,created_at)
  SELECT UUID(),platform_brand_id,'E45-B','Sociedad 4.5 B',country_id,'RUC','20450000002',
         'Av 2','Lima',default_currency_code,timezone,'active',NOW(3)
  FROM legal_entities WHERE code='CTS-PE';" 2>/dev/null

A="(SELECT id FROM (SELECT id FROM legal_entities WHERE code='E45-A') t)"
B="(SELECT id FROM (SELECT id FROM legal_entities WHERE code='E45-B') t)"
# DOS PAISES PROPIOS, no los de la semilla.
#
# Esta suite se equivoco dos veces con esto y las dos merecen estar escritas:
#
#  1. Uso CL y MX, que NO existen en la semilla. `country_id` salio NULL, los
#     INSERT fallaron por eso, y TRES aserciones de rechazo se pusieron verdes
#     por el motivo equivocado. Las que lo destaparon fueron las de PERMITIR.
#  2. Cambio a CO y US, que si existen. Paso en solitario y fallo en la bateria:
#     `3.10-periodos` deja Colombia cubierta antes de que llegue el turno.
#
# La leccion es la misma que en `4.4` y en `ClientesTest`: una suite que depende
# de lo que otra dejo no esta comprobando lo que dice. Asi que se crea sus
# propios paises. `countries` es catalogo y admite altas.
$CLIENTE $DB -e "INSERT INTO countries (iso2,iso3,numeric_code,name,phone_code,default_currency_code,timezone,is_active,created_at)
  SELECT 'Z1','ZZ1','901','Pais de prueba 4.5 A','+901',default_currency_code,timezone,0,NOW(3) FROM countries WHERE iso2='PE';
  INSERT INTO countries (iso2,iso3,numeric_code,name,phone_code,default_currency_code,timezone,is_active,created_at)
  SELECT 'Z2','ZZ2','902','Pais de prueba 4.5 B','+902',default_currency_code,timezone,0,NOW(3) FROM countries WHERE iso2='PE';" 2>/dev/null

CO="(SELECT id FROM (SELECT id FROM countries WHERE iso2='Z1') t)"
US="(SELECT id FROM (SELECT id FROM countries WHERE iso2='Z2') t)"

# Y aun asi comprueba su premisa: si algo los cubriera, las aserciones de
# PERMITIR fallarian y la culpa pareceria del esquema.
for iso in Z1 Z2; do
  n=$($CLIENTE $DB -N -B -e "SELECT COUNT(*) FROM legal_entity_countries lec
    JOIN countries c ON c.id=lec.country_id
    WHERE c.iso2='$iso' AND lec.valid_to IS NULL" 2>/dev/null | grep -v Warning | tr -d '\r')
  [ "${n:-0}" -ne 0 ] && echo "  (aviso) $iso ya tiene cobertura abierta antes de empezar; rehaz la base."
done

cobertura() { # entidad pais motivo desde hasta
  echo "INSERT INTO legal_entity_countries (legal_entity_id,country_id,coverage_basis,valid_from,valid_to,created_at)
        VALUES ($1,$2,'$3','$4',$5,NOW(3));"
}

echo ""
echo "--- La sociedad: identidad unica ---"
probar "dos sociedades con el mismo codigo" \
  "INSERT INTO legal_entities (uuid,platform_brand_id,code,legal_name,country_id,tax_id_type,tax_id_number,address_line1,city,default_currency_code,timezone,created_at)
   SELECT UUID(),platform_brand_id,'E45-A','Clon',country_id,'RUC','20450009999','Av 3','Lima',default_currency_code,timezone,NOW(3) FROM legal_entities WHERE code='CTS-PE';" RECHAZO
probar "la misma empresa (pais + tipo + numero) dos veces" \
  "INSERT INTO legal_entities (uuid,platform_brand_id,code,legal_name,country_id,tax_id_type,tax_id_number,address_line1,city,default_currency_code,timezone,created_at)
   SELECT UUID(),platform_brand_id,'E45-C','Clon fiscal',country_id,'RUC','20450000001','Av 3','Lima',default_currency_code,timezone,NOW(3) FROM legal_entities WHERE code='E45-A';" RECHAZO
probar "un estado que no esta en la lista" \
  "UPDATE legal_entities SET status='cerrada' WHERE code='E45-B';" RECHAZO
probar "disuelta sin decir cuando" \
  "UPDATE legal_entities SET status='dissolved' WHERE code='E45-B';" RECHAZO
probar "disuelta diciendo cuando, si" \
  "UPDATE legal_entities SET status='dissolved', dissolved_on='2026-12-31' WHERE code='E45-B';" OK
probar "disuelta antes de constituirse" \
  "UPDATE legal_entities SET incorporated_on='2027-01-01' WHERE code='E45-B';" RECHAZO
probar "y se devuelve a activa para lo que viene" \
  "UPDATE legal_entities SET status='active', dissolved_on=NULL WHERE code='E45-B';" OK

echo ""
echo "--- La cobertura: un pais, una sociedad ---"
probar "E45-A cubre el pais Z1 desde 2026-01-01" \
  "$(cobertura "$A" "$CO" service_export '2026-01-01' NULL)" OK
probar "E45-B tambien quiere cubrir el pais Z1" \
  "$(cobertura "$B" "$CO" service_export '2026-06-01' NULL)" RECHAZO
probar "un motivo que no esta en la lista" \
  "$(cobertura "$A" "$US" invento '2026-01-01' NULL)" RECHAZO
probar "cobertura que termina antes de empezar" \
  "$(cobertura "$A" "$US" branch '2026-06-01' "'2026-01-01'")" RECHAZO

echo ""
echo "--- Lo que la regla NO debe impedir ---"
# Sin estas, `uq_lec_country` podria estar puesta sobre menos columnas y nadie
# lo notaria: las de rechazo seguirian todas verdes.
probar "la MISMA sociedad cubre ademas otro pais" \
  "$(cobertura "$A" "$US" local_entity '2026-01-01' NULL)" OK
probar "una cobertura historica que no solapa a nadie" \
  "$(cobertura "$B" "$CO" branch '2024-01-01' "'2024-12-31'")" OK

echo ""
echo "--- El relevo: valid_to es INCLUSIVO ---"
probar "se cierra la de E45-A en Z1 el 31/05" \
  "UPDATE legal_entity_countries SET valid_to='2026-05-31'
   WHERE legal_entity_id=$A AND country_id=$CO AND valid_to IS NULL;" OK
probar "y E45-B entra el 01/06" \
  "$(cobertura "$B" "$CO" service_export '2026-06-01' NULL)" OK
probar "reabrir la de E45-A hasta el 01/06 —el MISMO dia— se rechaza" \
  "UPDATE legal_entity_countries SET valid_to='2026-06-01'
   WHERE legal_entity_id=$A AND country_id=$CO;" RECHAZO

echo ""
echo "--- El pais incomunicado (el agujero que arregla DEC-081) ---"
# Z2 lo cubre E45-A y solo E45-A. Se desactiva E45-A SIN cerrar la
# cobertura, que es lo que hacia el sistema antes de esta iteracion.
probar "se desactiva E45-A sin cerrar su cobertura de Z2" \
  "UPDATE legal_entities SET status='inactive' WHERE code='E45-A';" OK
echo -n "  "
n=$($CLIENTE $DB -N -B -e "SELECT COUNT(*) FROM legal_entity_countries lec
  JOIN legal_entities le ON le.id=lec.legal_entity_id
  WHERE lec.country_id=$US AND lec.valid_from<='2026-07-01'
    AND (lec.valid_to IS NULL OR lec.valid_to>='2026-07-01') AND le.status='active';" 2>/dev/null | grep -v Warning | tr -d '\r')
if [ "${n:-9}" -eq 0 ]; then
  printf "\033[32m✓\033[0m %-70s %s\n" "ninguna sociedad ACTIVA cubre ya Z2" "0"; ok=$((ok+1))
else
  printf "\033[31m✗\033[0m %-70s esperaba 0, obtuvo %s\n" "ninguna sociedad ACTIVA cubre ya Z2" "$n"; fail=$((fail+1))
fi
probar "y E45-B TAMPOCO puede tomarlo: la fila abierta sigue ocupando el sitio" \
  "$(cobertura "$B" "$US" service_export '2026-07-01' NULL)" RECHAZO
# La salida es cerrar la cobertura de la inactiva. Eso es exactamente lo que
# hace `desactivar()` en la misma transaccion que la baja.
probar "cerrando la cobertura de la inactiva, el pais se libera" \
  "UPDATE legal_entity_countries SET valid_to='2026-06-30'
   WHERE legal_entity_id=$A AND country_id=$US AND valid_to IS NULL;" OK
probar "y ahora E45-B si puede cubrir Z2" \
  "$(cobertura "$B" "$US" service_export '2026-07-01' NULL)" OK

echo ""
echo "--- Y lo que es evidencia no se borra ---"
# Apunta a una fila que EXISTE. Un `DELETE` que no casa con ninguna no dispara
# el `BEFORE DELETE` y sale verde sin haber probado nada: es la trampa que
# `3.12` documento con `comprobar_hay`, y en la que esta suite cayo primero.
hay=$($CLIENTE $DB -N -B -e "SELECT COUNT(*) FROM legal_entity_countries WHERE legal_entity_id=$B" 2>/dev/null | grep -v Warning | tr -d '\r')
if [ "${hay:-0}" -lt 1 ]; then
  printf "  \033[31m!\033[0m %-70s SIN FILAS QUE BORRAR\n" "una cobertura no se borra"
  fail=$((fail+1))
else
  probar "una cobertura no se borra" \
    "DELETE FROM legal_entity_countries WHERE legal_entity_id=$B LIMIT 1;" RECHAZO
fi

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
