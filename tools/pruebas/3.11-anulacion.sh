#!/bin/bash
# Pruebas de restriccion de la iteracion 3.11: anular un perfil fiscal (T-15).
#
# El estado del perfil sabia decir dos cosas y le faltaba una tercera:
#
#   rejected     no paso la revision      -> nunca llego a aprobarse
#   superseded   otro tomo su lugar       -> SI estuvo vigente
#   annulled     se aprobo y no debio     -> no valio ni un dia
#
# La diferencia entre las dos ultimas se paga en dinero: de un `superseded`
# salio la retencion de sus meses; de un anulado, ninguna.
#
# Uso: bash tools/pruebas/3.11-anulacion.sh <base>
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

ok=0; fail=0
probar() {
  salida=$($CLIENTE $DB -e "$2" 2>&1)
  if echo "$salida" | grep -qiE "ERROR (2002|2003|2005|1045|1049)|Can't connect|Unknown database|Access denied"; then
    printf "  \033[31m!\033[0m %-70s LA BASE NO RESPONDE\n" "$1"
    echo "      $(echo "$salida" | grep -i error | head -1)"
    fail=$((fail+1)); return
  fi
  if [ -z "$salida" ] || ! echo "$salida" | grep -qi "ERROR"; then real="OK"; else real="RECHAZO"; fi
  if [ "$real" == "$3" ]; then printf "  \033[32m✓\033[0m %-70s %s\n" "$1" "$real"; ok=$((ok+1))
  else printf "  \033[31m✗\033[0m %-70s esperaba %s, obtuvo %s\n" "$1" "$3" "$real"; echo "      $(echo "$salida"|grep -i error|head -1)"; fail=$((fail+1)); fi
}

valor() {
  real=$($CLIENTE $DB -N -B -e "$2" 2>&1 | grep -v '^mysql: \[Warning\]' | tr -d '\r')
  if [ "$real" == "$3" ]; then printf "  \033[32m✓\033[0m %-70s %s\n" "$1" "$real"; ok=$((ok+1))
  else printf "  \033[31m✗\033[0m %-70s esperaba '%s', obtuvo '%s'\n" "$1" "$3" "$real"; fail=$((fail+1)); fi
}

PA="(SELECT id FROM (SELECT id FROM countries WHERE iso2='PE') t)"
U1="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) t)"
U2="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1 OFFSET 1) t)"

# Creador propio: `3.6-fiscal` y `3.10-periodos` escriben en esta misma tabla, y
# correr-todo.sh corre las suites seguidas sobre la MISMA base.
$CLIENTE $DB -e "INSERT INTO creators (uuid,first_name,last_name,display_name,birth_date,email,
   country_id,document_country_code,document_type,document_number,status,payment_term_days,
   preferred_currency_code,created_at,updated_at)
 SELECT UUID(),'Elsa','Rios','elsarios311','1993-11-20','elsa311@ejemplo.test',c.id,'PE','DNI','43100312',
   'pending',30,'PEN',NOW(3),NOW(3) FROM countries c WHERE c.iso2='PE'
 ON DUPLICATE KEY UPDATE display_name=display_name;" 2>/dev/null

# Y se comprueba que existe. La primera version le puso a este creador el MISMO
# documento que al de 3.10, asi que `uq_creators_identity` lo rechazaba y el
# `ON DUPLICATE KEY` lo convertia en un no-op silencioso: el creador no se
# creaba, `$CR` era NULL, y las aserciones de RECHAZO pasaban todas --por 1048,
# no por la regla-- mientras las de OK caian. Otra vez la misma leccion: es la
# asercion de que algo esta PERMITIDO la que destapa un montaje que miente.
if [ -z "$($CLIENTE $DB -N -B -e "SELECT id FROM creators WHERE display_name='elsarios311'" 2>/dev/null | grep -v Warning)" ]; then
  echo "  No pude crear el creador de esta suite. .Choca su documento con el de otra?"; exit 2
fi

CR="(SELECT id FROM (SELECT id FROM creators WHERE display_name='elsarios311') t)"
usados=$($CLIENTE $DB -N -e "SELECT COUNT(*) FROM creator_tax_profiles WHERE creator_id=$CR" 2>/dev/null)
if [ -z "$usados" ]; then
  echo "  No puedo leer $DB. .Esta levantado el motor y creada la base?"; exit 2
fi
if [ "$usados" != "0" ]; then
  echo "  elsarios311 ya tiene $usados perfiles fiscales: recree la base y cargue la semilla."
  exit 2
fi

BASE="creator_id,country_id,tax_regime_code,tax_id_type,tax_id_number,issued_document_type"
APR="withholding_status,status,created_by_user_id,approved_by_user_id,approved_at"
APRV="'not_applicable','approved',$U1,$U2,NOW(3)"
ANU="annulled_at=NOW(3), annulled_by_user_id=$U1, annulment_reason='A nombre del menor, no del tutor'"

echo ""
echo "--- Anulado es un estado, y exige decir quien, cuando y por que ---"
probar "el perfil vigente" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,$APR) VALUES ($CR,$PA,'RER','RUC','20710001','recibo_honorarios','2026-01-01',$APRV);" OK
probar "anular sin ningun dato: media anulacion" \
  "UPDATE creator_tax_profiles SET status='annulled' WHERE tax_id_number='20710001';" RECHAZO
probar "anular con fecha pero sin motivo" \
  "UPDATE creator_tax_profiles SET status='annulled', annulled_at=NOW(3) WHERE tax_id_number='20710001';" RECHAZO
probar "anular con fecha y motivo pero sin quien" \
  "UPDATE creator_tax_profiles SET status='annulled', annulled_at=NOW(3), annulment_reason='pues eso' WHERE tax_id_number='20710001';" RECHAZO
probar "anular diciendo las tres cosas" \
  "UPDATE creator_tax_profiles SET status='annulled', $ANU WHERE tax_id_number='20710001';" OK

echo ""
echo "--- Y al reves: los datos de anulacion no valen sin el estado ---"
# Sin esta mitad, un `annulled_at` suelto en una fila aprobada seria una
# anulacion a medias que nadie sabria leer.
probar "otro perfil vigente" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,$APR) VALUES ($CR,$PA,'RG','RUC','20710002','factura','2026-06-01',$APRV);" OK
probar "ponerle fecha de anulacion sin anularlo" \
  "UPDATE creator_tax_profiles SET annulled_at=NOW(3) WHERE tax_id_number='20710002';" RECHAZO
probar "ponerle motivo sin anularlo" \
  "UPDATE creator_tax_profiles SET annulment_reason='sin anular, pero con motivo' WHERE tax_id_number='20710002';" RECHAZO

echo ""
echo "--- Solo se anula el VIGENTE ---"
probar "un perfil ya reemplazado" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,valid_to,$APR) VALUES ($CR,$PA,'RER','RUC','20710003','recibo_honorarios','2024-01-01','2024-12-31','not_applicable','superseded',$U1,$U2,NOW(3));" OK
probar "anularlo seria reescribir un periodo que ya paso" \
  "UPDATE creator_tax_profiles SET status='annulled', $ANU WHERE tax_id_number='20710003';" RECHAZO
probar "un perfil PENDIENTE" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,created_by_user_id) VALUES ($CR,$PA,'RER','RUC','20710004','recibo_honorarios','2027-01-01',$U1);" OK
probar "un pendiente se retira o se rechaza, no se anula" \
  "UPDATE creator_tax_profiles SET status='annulled', $ANU WHERE tax_id_number='20710004';" RECHAZO
probar "y uno ya anulado se congela: no se le reescribe el motivo" \
  "UPDATE creator_tax_profiles SET annulment_reason='otra vez, por si acaso' WHERE tax_id_number='20710001';" RECHAZO
probar "ni se le cambia nada mas" \
  "UPDATE creator_tax_profiles SET tax_regime_code='RG' WHERE tax_id_number='20710001';" RECHAZO

echo ""
echo "--- Un anulado no ocupa periodo ni cuenta como vigente ---"
# Es lo que lo distingue de `superseded`, y por eso el filtro de la regla de
# periodos (3.10) es `status IN ('approved','superseded')` y no incluye este.
valor "el 2026-02-15 el creador no tenia ningun regimen aplicable" \
  "SELECT COUNT(*) FROM creator_tax_profiles WHERE creator_id=$CR AND country_id=$PA AND status IN ('approved','superseded') AND valid_from<='2026-02-15' AND IFNULL(valid_to,'9999-12-31')>='2026-02-15';" "0"
probar "y por eso otro perfil puede cubrir esas mismas fechas" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,valid_to,$APR) VALUES ($CR,$PA,'RG','RUC','20710005','factura','2026-01-01','2026-05-31',$APRV);" OK
valor "ahora si hay uno, y solo uno" \
  "SELECT COUNT(*) FROM creator_tax_profiles WHERE creator_id=$CR AND country_id=$PA AND status IN ('approved','superseded') AND valid_from<='2026-02-15' AND IFNULL(valid_to,'9999-12-31')>='2026-02-15';" "1"

echo ""
echo "--- Y no desaparece: sigue en el expediente con su motivo ---"
valor "el anulado sigue ahi" \
  "SELECT CONCAT(status,'|',annulment_reason) FROM creator_tax_profiles WHERE tax_id_number='20710001';" "annulled|A nombre del menor, no del tutor"
# Aqui NO hay asercion de borrado a proposito. `creator_tax_profiles` no lleva
# disparador `no_delete` --si lo llevan `payouts`, `invoices`, `ledger_entries` y
# los medios de pago--, asi que hoy una fila anulada se puede borrar con un
# DELETE y toda esta evidencia se va con ella. Escribir una asercion que diga
# "el DELETE funciona" seria fijar el hueco como si fuera lo correcto, que es el
# error que ya cometio `PerfilFiscalTest` con `T-12`. Queda como `T-16`.

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
