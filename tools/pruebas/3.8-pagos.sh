#!/bin/bash
# Pruebas de restriccion de la iteracion 3.8: medios de pago.
#
# Cubre los siete hallazgos que se reprodujeron ANTES de arreglarlos:
#   H-02  verificado sin fecha de elegibilidad
#   H-09  un pago contra un medio sin verificar
#   H-10  la mascara admitia el numero de cuenta entero en claro
#   H-11  quien captura la cuenta podia verificarla el mismo
#   H-12  se podia cambiar la cuenta de un medio ya verificado
#   H-13  se podia borrar un medio de pago
#   H-14  el predeterminado podia estar rechazado
#
# Uso: bash tools/pruebas/3.8-pagos.sh <base>
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

# Para lo que no se comprueba aceptando o rechazando, sino leyendo el valor que
# quedo. Las marcas de DEC-065 las pone un disparador: preguntar si el INSERT
# "paso" no dice nada; hay que mirar QUE escribio.
valor() {
  real=$($CLIENTE $DB -N -B -e "$2" 2>&1 | tr -d '\r')
  if [ "$real" == "$3" ]; then printf "  \033[32m✓\033[0m %-70s %s\n" "$1" "$real"; ok=$((ok+1))
  else printf "  \033[31m✗\033[0m %-70s esperaba '%s', obtuvo '%s'\n" "$1" "$3" "$real"; fail=$((fail+1)); fi
}

CR="(SELECT id FROM (SELECT id FROM creators WHERE display_name='anatorres') t)"
CR2="(SELECT id FROM (SELECT id FROM creators WHERE display_name='luisvega') t)"
PA="(SELECT id FROM (SELECT id FROM countries WHERE iso2='PE') t)"
U1="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) t)"
U2="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1 OFFSET 1) t)"

# La semilla deja UN medio a nombre de anatorres. Si hay mas, la base viene
# sucia de otra pasada y las huellas de estas pruebas van a chocar entre si.
usados=$($CLIENTE $DB -N -e "SELECT COUNT(*) FROM creator_payment_methods" 2>/dev/null)
if [ -z "$usados" ]; then
  echo "  No puedo leer $DB. .Esta levantado el motor y creada la base?"; exit 2
fi
if [ "$usados" != "1" ]; then
  echo "  Hay $usados medios de pago y la semilla deja 1: recree la base y cargue la semilla."
  exit 2
fi

# Columnas obligatorias de cualquier alta. `H(n)` fabrica huellas distintas de
# 64 caracteres sin repetir texto en cada prueba.
COLS="uuid,creator_id,method_type,country_id,currency_code,account_number_encrypted,account_number_masked,account_number_fingerprint,holder_name,holder_document_type,holder_document_number,created_by_user_id"
# Ojo con las comillas al llamarla: $CR y compania son subconsultas con
# espacios dentro. Sin comillas, bash las parte y `alta` recibe "id" como
# creador; la primera version de este archivo fallaba asi en seis pruebas.
alta() { # $1 huella  $2 mascara  $3 creador  $4 capturador
  echo "INSERT INTO creator_payment_methods ($COLS) VALUES (UUID(),$3,'bank_account',$PA,'PEN','enc','$2',RPAD('$1',64,'x'),'Ana Torres','DNI','40000001',$4);"
}

echo ""
echo "--- H-02: verificado quiere decir «desde cuando se le puede pagar» ---"
probar "verificado con fecha de elegibilidad" \
 "INSERT INTO creator_payment_methods ($COLS,status,verified_at,verified_by_user_id,eligible_from) VALUES (UUID(),$CR,'bank_account',$PA,'PEN','enc','****0001',RPAD('a1',64,'x'),'Ana Torres','DNI','40000001',$U1,'verified',NOW(3),$U2,NOW(3));" OK
probar "verificado SIN fecha de elegibilidad" \
 "INSERT INTO creator_payment_methods ($COLS,status,verified_at,verified_by_user_id) VALUES (UUID(),$CR,'bank_account',$PA,'PEN','enc','****0002',RPAD('a2',64,'x'),'Ana Torres','DNI','40000001',$U1,'verified',NOW(3),$U2);" RECHAZO
probar "elegible ANTES de estar verificado (enfriamiento negativo)" \
 "INSERT INTO creator_payment_methods ($COLS,status,verified_at,verified_by_user_id,eligible_from) VALUES (UUID(),$CR,'bank_account',$PA,'PEN','enc','****0003',RPAD('a3',64,'x'),'Ana Torres','DNI','40000001',$U1,'verified',NOW(3),$U2,NOW(3) - INTERVAL 1 DAY);" RECHAZO
probar "pendiente sin fecha de elegibilidad (todavia no aplica)" \
 "$(alta a4 '****0004' "$CR" "$U1")" OK

echo ""
echo "--- H-11: quien captura la cuenta no la verifica ---"
probar "capturador y verificador distintos" \
 "INSERT INTO creator_payment_methods ($COLS,status,verified_at,verified_by_user_id,eligible_from) VALUES (UUID(),$CR,'bank_account',$PA,'PEN','enc','****0005',RPAD('a5',64,'x'),'Ana Torres','DNI','40000001',$U1,'verified',NOW(3),$U2,NOW(3));" OK
probar "el mismo usuario captura y verifica" \
 "INSERT INTO creator_payment_methods ($COLS,status,verified_at,verified_by_user_id,eligible_from) VALUES (UUID(),$CR,'bank_account',$PA,'PEN','enc','****0006',RPAD('a6',64,'x'),'Ana Torres','DNI','40000001',$U1,'verified',NOW(3),$U1,NOW(3));" RECHAZO
probar "alta sin decir quien la capturo" \
 "INSERT INTO creator_payment_methods (uuid,creator_id,method_type,country_id,currency_code,account_number_encrypted,account_number_masked,account_number_fingerprint,holder_name,holder_document_type,holder_document_number) VALUES (UUID(),$CR,'bank_account',$PA,'PEN','enc','****0007',RPAD('a7',64,'x'),'Ana Torres','DNI','40000001');" RECHAZO

echo ""
echo "--- H-10: la mascara es una mascara, no la cuenta ---"
probar "mascara con los cuatro ultimos digitos" \
 "$(alta b1 '****4321' "$CR" "$U1")" OK
probar "mascara sin ningun digito" \
 "$(alta b2 'BCP ****' "$CR" "$U1")" OK
probar "mascara con el numero de cuenta ENTERO en claro" \
 "$(alta b3 '00212345678901234567' "$CR" "$U1")" RECHAZO
probar "mascara con cinco digitos" \
 "$(alta b4 '***54321' "$CR" "$U1")" RECHAZO

echo ""
echo "--- H-14: el predeterminado tiene que servir para algo ---"
probar "predeterminado verificado y elegible" \
 "INSERT INTO creator_payment_methods ($COLS,status,verified_at,verified_by_user_id,eligible_from,is_default) VALUES (UUID(),$CR2,'bank_account',$PA,'PEN','enc','****0008',RPAD('c1',64,'x'),'Luis Vega','DNI','40000002',$U1,'verified',NOW(3),$U2,NOW(3),1);" OK
probar "predeterminado en estado pendiente" \
 "INSERT INTO creator_payment_methods ($COLS,is_default) VALUES (UUID(),$CR,'bank_account',$PA,'PEN','enc','****0009',RPAD('c2',64,'x'),'Ana Torres','DNI','40000001',$U1,1);" RECHAZO
probar "predeterminado rechazado" \
 "INSERT INTO creator_payment_methods ($COLS,status,closed_at,closed_by_user_id,is_default) VALUES (UUID(),$CR,'bank_account',$PA,'PEN','enc','****0010',RPAD('c3',64,'x'),'Ana Torres','DNI','40000001',$U1,'rejected',NOW(3),$U2,1);" RECHAZO

echo ""
echo "--- Rechazar no es verificar, y retirar deja rastro ---"
probar "rechazado, con quien y cuando" \
 "INSERT INTO creator_payment_methods ($COLS,status,closed_at,closed_by_user_id) VALUES (UUID(),$CR,'bank_account',$PA,'PEN','enc','****0011',RPAD('d1',64,'x'),'Ana Torres','DNI','40000001',$U1,'rejected',NOW(3),$U2);" OK
probar "rechazado con verificador escrito" \
 "INSERT INTO creator_payment_methods ($COLS,status,verified_at,verified_by_user_id,closed_at,closed_by_user_id) VALUES (UUID(),$CR,'bank_account',$PA,'PEN','enc','****0012',RPAD('d2',64,'x'),'Ana Torres','DNI','40000001',$U1,'rejected',NOW(3),$U2,NOW(3),$U2);" RECHAZO
probar "rechazado sin decir cuando" \
 "INSERT INTO creator_payment_methods ($COLS,status,closed_by_user_id) VALUES (UUID(),$CR,'bank_account',$PA,'PEN','enc','****0013',RPAD('d3',64,'x'),'Ana Torres','DNI','40000001',$U1,'rejected',$U2);" RECHAZO
probar "desactivado sin decir quien" \
 "INSERT INTO creator_payment_methods ($COLS,status,closed_at) VALUES (UUID(),$CR,'bank_account',$PA,'PEN','enc','****0014',RPAD('d4',64,'x'),'Ana Torres','DNI','40000001',$U1,'disabled',NOW(3));" RECHAZO

echo ""
echo "--- H-12: la cuenta es inmutable, se sustituye ---"
M="(SELECT id FROM (SELECT id FROM creator_payment_methods WHERE account_number_fingerprint=RPAD('a1',64,'x')) t)"
probar "cambiar el estado (eso si se puede)" \
 "UPDATE creator_payment_methods SET is_default=0 WHERE id=$M;" OK
probar "cambiar el numero de cuenta de un medio verificado" \
 "UPDATE creator_payment_methods SET account_number_encrypted='otra', account_number_fingerprint=RPAD('zz',64,'x') WHERE id=$M;" RECHAZO
probar "cambiar el titular" \
 "UPDATE creator_payment_methods SET holder_name='Otro Nombre' WHERE id=$M;" RECHAZO
probar "cambiar la mascara" \
 "UPDATE creator_payment_methods SET account_number_masked='****9999' WHERE id=$M;" RECHAZO
probar "reescribir quien lo verifico" \
 "UPDATE creator_payment_methods SET verified_by_user_id=$U1 WHERE id=$M;" RECHAZO
probar "acortar el enfriamiento" \
 "UPDATE creator_payment_methods SET eligible_from=NOW(3) - INTERVAL 5 DAY WHERE id=$M;" RECHAZO
P="(SELECT id FROM (SELECT id FROM creator_payment_methods WHERE account_number_fingerprint=RPAD('a4',64,'x')) t)"
probar "fijar la elegibilidad por primera vez" \
 "UPDATE creator_payment_methods SET status='verified', verified_at=NOW(3), verified_by_user_id=$U2, eligible_from=NOW(3) WHERE id=$P;" OK

echo ""
echo "--- H-13: un medio de pago no se borra (BR-FIN-008) ---"
probar "DELETE sobre un medio de pago" \
 "DELETE FROM creator_payment_methods WHERE id=$M;" RECHAZO

echo ""
echo "--- DEC-065: la misma cuenta en dos creadores se marca, no se rechaza ---"
probar "cuenta nueva, de nadie mas" \
 "$(alta e1 '****0015' "$CR" "$U1")" OK
valor "  y queda marcada como unica" \
 "SELECT shared_account_status FROM creator_payment_methods WHERE account_number_fingerprint=RPAD('e1',64,'x');" "unique"
probar "la MISMA cuenta en otro creador: se admite" \
 "$(alta e1 '****0015' "$CR2" "$U1")" OK
valor "  y queda marcada para revision" \
 "SELECT shared_account_status FROM creator_payment_methods WHERE account_number_fingerprint=RPAD('e1',64,'x') AND creator_id=$CR2;" "pending_review"
probar "un humano la da por buena" \
 "UPDATE creator_payment_methods SET shared_account_status='cleared' WHERE account_number_fingerprint=RPAD('e1',64,'x') AND creator_id=$CR2;" OK
probar "veredicto inventado" \
 "UPDATE creator_payment_methods SET shared_account_status='revisada' WHERE account_number_fingerprint=RPAD('e1',64,'x') AND creator_id=$CR2;" RECHAZO

echo ""
echo "--- La misma cuenta dos veces en el MISMO creador es ruido ---"
probar "primera alta" \
 "$(alta f1 '****0016' "$CR" "$U1")" OK
probar "la misma cuenta otra vez, con la primera abierta" \
 "$(alta f1 '****0016' "$CR" "$U1")" RECHAZO
probar "se retira la primera" \
 "UPDATE creator_payment_methods SET status='disabled', closed_at=NOW(3), closed_by_user_id=$U2 WHERE account_number_fingerprint=RPAD('f1',64,'x') AND creator_id=$CR;" OK
probar "y ahora si se puede volver a dar de alta" \
 "$(alta f1 '****0016' "$CR" "$U1")" OK

echo ""
echo "--- H-09: no se paga a una cuenta que nadie ha verificado ---"
LE="(SELECT id FROM (SELECT id FROM legal_entities LIMIT 1) t)"
$CLIENTE $DB -e "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,created_at) VALUES (UUID(),'LOTE-38-1',$LE,'PEN','draft',$U1,NOW(3));" 2>/dev/null
B="(SELECT id FROM (SELECT id FROM payout_batches WHERE code='LOTE-38-1') t)"
BUENO="(SELECT id FROM (SELECT id FROM creator_payment_methods WHERE account_number_fingerprint=REPEAT('b',64)) t)"
MALO="(SELECT id FROM (SELECT id FROM creator_payment_methods WHERE account_number_fingerprint=RPAD('f1',64,'x') AND status='pending' LIMIT 1) t)"
# Un medio verificado HOY y elegible MANANA: eso es estar en enfriamiento. La
# primera version usaba una fila cuya elegibilidad ya habia pasado, asi que la
# asercion pasaba sin comprobar nada. Un test que miente, y miente hacia el lado
# comodo.
$CLIENTE $DB -e "INSERT INTO creator_payment_methods ($COLS,status,verified_at,verified_by_user_id,eligible_from) VALUES (UUID(),$CR,'bank_account',$PA,'PEN','enc','****0017',RPAD('g1',64,'x'),'Ana Torres','DNI','40000001',$U1,'verified',NOW(3),$U2,NOW(3) + INTERVAL 1 DAY);" 2>/dev/null
FRIO="(SELECT id FROM (SELECT id FROM creator_payment_methods WHERE account_number_fingerprint=RPAD('g1',64,'x')) t)"
AJENO="(SELECT id FROM (SELECT id FROM creator_payment_methods WHERE account_number_fingerprint=RPAD('c1',64,'x')) t)"
PAGO="uuid,payout_batch_id,creator_id,payment_method_id,beneficiary_name_snapshot,account_masked_snapshot,amount,currency_code,created_at"
probar "pago a un medio verificado y ya elegible" \
 "INSERT INTO payouts ($PAGO) VALUES (UUID(),$B,$CR,$BUENO,'Ana Torres','****4321',1500.0000,'PEN',NOW(3));" OK
probar "pago a un medio en estado pendiente" \
 "INSERT INTO payouts ($PAGO) VALUES (UUID(),$B,$CR,$MALO,'Ana Torres','****0016',1500.0000,'PEN',NOW(3));" RECHAZO
probar "pago a un medio todavia en enfriamiento" \
 "INSERT INTO payouts ($PAGO) VALUES (UUID(),$B,$CR,$FRIO,'Ana Torres','****0017',1500.0000,'PEN',NOW(3));" RECHAZO
probar "pago a la cuenta de OTRO creador" \
 "INSERT INTO payouts ($PAGO) VALUES (UUID(),$B,$CR,$AJENO,'Ana Torres','****0008',1500.0000,'PEN',NOW(3));" RECHAZO
probar "cambiar el destino de un pago ya emitido" \
 "UPDATE payouts SET payment_method_id=$AJENO WHERE payout_batch_id=$B;" RECHAZO

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
