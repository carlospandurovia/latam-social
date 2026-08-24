#!/bin/bash
# Pruebas de restriccion de la iteracion 3.12: lo que es evidencia no se borra.
#
# Nueve tablas ya tenian `no_delete` y otras nueve guardaban evidencia igual de
# definitiva sin ninguna proteccion. El criterio para entrar en la lista es uno
# solo: la fila es EVIDENCIA de algo que paso, y de ella depende dinero o una
# obligacion legal.
#
# La segunda mitad de esta suite es tan importante como la primera: comprueba
# que lo que NO es evidencia se sigue pudiendo borrar. Una regla que se aplica a
# todo no protege nada, solo estorba.
#
# Uso: bash tools/pruebas/3.12-no-borrar.sh <base>
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

# Se borra por una condicion que NO casa con ninguna fila. Da igual: el
# disparador `BEFORE DELETE` de MySQL no se dispara si no hay filas que borrar,
# asi que hay que apuntar a filas de verdad. La semilla las trae.
comprobar_hay() {
  n=$($CLIENTE $DB -N -B -e "SELECT COUNT(*) FROM $1" 2>/dev/null | grep -v Warning | tr -d '\r')
  if [ "${n:-0}" -lt 1 ]; then
    echo "  (aviso) $1 esta vacia: el DELETE no dispararia nada y la asercion no probaria nada."
    return 1
  fi
  return 0
}

echo ""
echo "--- Evidencia: el borrado se rechaza ---"
for t in creator_tax_profiles creator_tax_documents client_tax_profiles \
         terms_acceptances terms_versions creator_guardians \
         exchange_rates legal_entity_countries publication_evidence \
         audit_logs invoices ledger_entries payouts payments \
         invoice_lines campaign_costs creator_payment_methods social_account_snapshots; do
  if comprobar_hay "$t"; then
    probar "$t" "DELETE FROM $t LIMIT 1;" RECHAZO
  fi
done

echo ""
echo "--- Y lo que NO es evidencia se sigue pudiendo borrar ---"
# Sin esta mitad, la regla podria haberse aplicado a media base sin que nadie lo
# notara. Un bloqueo de agenda apuntado por error se borra y no pasa nada:
# no es prueba de nada.
CR="(SELECT id FROM (SELECT id FROM creators WHERE display_name='anatorres') t)"
U1="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) t)"
probar "un bloqueo de agenda se apunta" \
  "INSERT INTO creator_blackouts (creator_id,starts_on,ends_on,reason) VALUES ($CR,'2027-01-01','2027-01-05','vacaciones');" OK
probar "y se borra si fue un error" \
  "DELETE FROM creator_blackouts WHERE reason='vacaciones';" OK
probar "una categoria de catalogo tambien" \
  "INSERT INTO categories (code) VALUES ('zz_prueba_312');" OK
probar "y se puede quitar del catalogo" \
  "DELETE FROM categories WHERE code='zz_prueba_312';" OK

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
