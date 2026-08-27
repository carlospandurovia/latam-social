#!/bin/bash
# Pruebas de restriccion de la iteracion 4.4: la identidad fiscal del cliente.
#
# De `client_tax_profiles` salen `receiver_legal_name_snapshot` y
# `receiver_tax_id_snapshot` de `invoices`. O sea: el nombre y el RUC que se
# imprimen en una factura. Cuatro reglas la protegen y esta suite las separa.
#
#   tg_ctxp_sin_solape_*  dos identidades del mismo cliente y pais solapadas
#   uq_ctxp_current       dos vigentes del mismo cliente y pais
#   uq_ctxp_taxid         el mismo documento vigente en DOS clientes del mismo pais
#   ck_ctxp_dates         valid_to anterior a valid_from
#   ck_ctxp_term          plazo de pago fuera de 0..180
#   no_delete             borrar un perfil
#
# La seccion que mas importa es «el cierre»: `valid_to` es INCLUSIVO, y cerrar el
# anterior con el `valid_from` del siguiente los deja solapados UN DIA. Ese fallo
# ha aparecido en SEIS sitios de este proyecto. Aqui se fija en los dos sentidos:
# cerrar el dia antes se acepta, cerrar el mismo dia se rechaza.
#
# Y como siempre: las aserciones que descubren si las de rechazo mienten son las
# de PERMITIR. Sin ellas, una unica sobre `(country_id, tax_id_type,
# tax_id_number)` sin `current_gate` pasaria por buena, y estaria prohibiendo que
# una empresa vuelva a usar su propio RUC despues de cerrar un periodo.
#
# Uso: bash tools/pruebas/4.4-fiscal-cliente.sh <base>
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

# Los cuatro ayudantes viven en UN sitio desde 8.11: estaban copiados en las
# treinta suites y habian derivado en seis variantes, y nueve de ellas se
# habrian puesto verdes con el motor apagado. Ver `tools/pruebas/comun.sh`.
source "$(dirname "$0")/comun.sh"
# DOS clientes propios, no el de la semilla.
#
# La primera version de esta suite usaba `CLI-0001` y la fila de la semilla como
# «el vigente», y pasaba en solitario y fallaba en la bateria: para cuando le
# toca el turno, 2.13 y 3.6 ya le han dejado a ese cliente perfiles fiscales en
# PE y en CO. Tres aserciones acusaban a `uq_ctxp_taxid` de algo que no hizo.
#
# Una suite que solo pasa si es la primera no esta comprobando lo que dice.
$CLIENTE $DB -e "INSERT INTO client_organizations (uuid,commercial_name,client_code,country_id,status,created_at)
  SELECT UUID(),'Cliente 4.4 A','CLI-44A',country_id,'active',NOW(3) FROM client_organizations WHERE client_code='CLI-0001';
  INSERT INTO client_organizations (uuid,commercial_name,client_code,country_id,status,created_at)
  SELECT UUID(),'Cliente 4.4 B','CLI-44B',country_id,'active',NOW(3) FROM client_organizations WHERE client_code='CLI-0001';" 2>/dev/null

A="(SELECT id FROM (SELECT id FROM client_organizations WHERE client_code='CLI-44A') t)"
B="(SELECT id FROM (SELECT id FROM client_organizations WHERE client_code='CLI-44B') t)"
PE="(SELECT id FROM (SELECT id FROM countries WHERE iso2='PE') t)"
CO="(SELECT id FROM (SELECT id FROM countries WHERE iso2='CO') t)"

# La suite comprueba su propia premisa. Si los clientes vinieran con perfiles,
# las aserciones de PERMITIR fallarian y la culpa pareceria del esquema.
previos=$($CLIENTE $DB -N -B -e "SELECT COUNT(*) FROM client_tax_profiles ctp
  JOIN client_organizations co ON co.id=ctp.client_organization_id
  WHERE co.client_code IN ('CLI-44A','CLI-44B')" 2>/dev/null | grep -v Warning | tr -d '\r')
if [ "${previos:-0}" -ne 0 ]; then
  echo "  (aviso) los clientes de esta suite ya traen ${previos} perfiles; rehaz la base."
fi

perfil() { # cliente pais razon tipo numero desde hasta
  echo "INSERT INTO client_tax_profiles
        (client_organization_id,country_id,legal_name,tax_id_type,tax_id_number,
         address_line1,city,valid_from,valid_to,created_at)
        VALUES ($1,$2,'$3','$4','$5','Av Siempre Viva 100','Lima','$6',$7,NOW(3));"
}

echo ""
echo "--- Una sola identidad vigente por cliente y pais ---"
probar "la primera identidad del cliente A en PE entra" \
  "$(perfil "$A" "$PE" 'ACME SAC' 'RUC' '20440000001' '2026-01-01' NULL)" OK
probar "un segundo vigente del mismo cliente y pais se rechaza" \
  "$(perfil "$A" "$PE" 'ACME bis' 'RUC' '20440000002' '2026-06-01' NULL)" RECHAZO

echo ""
echo "--- Lo que la regla NO debe impedir ---"
# Sin estas, una unica de mas pasaria por buena y nadie se enteraria.
probar "el MISMO cliente en OTRO pais si tiene su identidad" \
  "$(perfil "$A" "$CO" 'ACME Colombia' 'NIT' '900440001' '2026-01-01' NULL)" OK
probar "OTRO cliente en el mismo pais, con documento distinto" \
  "$(perfil "$B" "$PE" 'Otra SAC' 'RUC' '20440000003' '2026-01-01' NULL)" OK
probar "un periodo historico que no solapa a nadie" \
  "$(perfil "$A" "$PE" 'ACME antigua' 'RUC' '20440000004' '2024-01-01' "'2024-12-31'")" OK

echo ""
echo "--- El mismo documento no es la identidad vigente de dos clientes ---"
# `uq_ctxp_taxid` lleva `current_gate`: mira solo los vigentes. Se comprueba
# tambien que la parte de `tax_id_type` cuenta, o el indice podria estar puesto
# sobre menos columnas de las que dice.
probar "el NIT vigente del cliente A no se lo puede poner el cliente B" \
  "$(perfil "$B" "$CO" 'Clon Colombia' 'NIT' '900440001' '2026-01-01' NULL)" RECHAZO
probar "pero el mismo numero con OTRO tipo de documento si vale" \
  "$(perfil "$B" "$CO" 'Clon Colombia' 'CC' '900440001' '2026-01-01' NULL)" OK

echo ""
echo "--- El cierre: valid_to es INCLUSIVO (el fallo de seis sitios) ---"
# Esta es la seccion por la que existe la suite. Primero el camino correcto.
probar "se cierra el vigente de PE el 31/05" \
  "UPDATE client_tax_profiles SET valid_to='2026-05-31'
   WHERE client_organization_id=$A AND country_id=$PE AND valid_to IS NULL;" OK
probar "y la identidad nueva entra el 01/06" \
  "$(perfil "$A" "$PE" 'ACME SAC nueva' 'RUC' '20440000005' '2026-06-01' NULL)" OK
probar "reabrir el anterior hasta el 01/06 —el MISMO dia— se rechaza" \
  "UPDATE client_tax_profiles SET valid_to='2026-06-01'
   WHERE client_organization_id=$A AND country_id=$PE AND tax_id_number='20440000001';" RECHAZO
probar "y hasta el 31/05 vuelve a valer" \
  "UPDATE client_tax_profiles SET valid_to='2026-05-31'
   WHERE client_organization_id=$A AND country_id=$PE AND tax_id_number='20440000001';" OK
probar "un periodo historico que se mete entre dos existentes se rechaza" \
  "$(perfil "$A" "$PE" 'Solapada' 'RUC' '20440000006' '2026-03-01' "'2026-04-01'")" RECHAZO

echo ""
echo "--- Y el resto de restricciones declaradas ---"
probar "valid_to anterior a valid_from" \
  "$(perfil "$B" "$PE" 'Al reves' 'RUC' '20440000007' '2026-06-01' "'2026-01-01'")" RECHAZO
probar "un periodo de un solo dia si vale (valid_to = valid_from)" \
  "$(perfil "$B" "$CO" 'Un dia' 'RUC' '20440000008' '2020-06-01' "'2020-06-01'")" OK
probar "un plazo de pago de 200 dias se rechaza" \
  "UPDATE client_tax_profiles SET payment_term_days=200 WHERE client_organization_id=$A LIMIT 1;" RECHAZO
probar "un plazo de 180 dias vale" \
  "UPDATE client_tax_profiles SET payment_term_days=180 WHERE client_organization_id=$A AND valid_to IS NULL;" OK
probar "el perfil apunta a un pais que existe" \
  "$(perfil "$B" 999999 'Sin pais' 'RUC' '20440000009' '2026-01-01' NULL)" RECHAZO
probar "un perfil fiscal de cliente no se borra" \
  "DELETE FROM client_tax_profiles WHERE client_organization_id=$A LIMIT 1;" RECHAZO

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
