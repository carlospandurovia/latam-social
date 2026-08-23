#!/bin/bash
# Pruebas de restriccion de la iteracion 3.7: evidencia de verificacion de una
# cuenta social y coherencia de las metricas.
#
# Uso: bash tools/pruebas/3.7-redes.sh <base>
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

usadas=$($CLIENTE $DB -N -e "SELECT COUNT(*) FROM social_accounts" 2>/dev/null)
if [ -z "$usadas" ]; then
  echo "  No puedo leer $DB. .Esta levantado el motor y creada la base?"; exit 2
fi
if [ "$usadas" != "0" ]; then
  echo "  $DB ya tiene $usadas cuentas sociales: recree la base y cargue la semilla."
  exit 2
fi

CR="(SELECT id FROM (SELECT id FROM creators WHERE display_name='anatorres') t)"
CR2="(SELECT id FROM (SELECT id FROM creators WHERE display_name='luisvega') t)"
PL="(SELECT id FROM (SELECT id FROM platforms ORDER BY id LIMIT 1) t)"
U1="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) t)"
B="uuid,creator_id,platform_id,handle,profile_url,created_at"
V="UUID(),$CR,$PL"

echo ""
echo "--- Verificar una cuenta exige decir COMO y QUIEN (H-05) ---"
probar "cuenta sin verificar" \
 "INSERT INTO social_accounts ($B) VALUES ($V,'sinverificar','https://x.test/a',NOW(3));" OK
probar "verificada con solo la fecha (lo que admitia antes)" \
 "INSERT INTO social_accounts ($B,verification_status,verified_at) VALUES ($V,'solofecha','https://x.test/b',NOW(3),'verified',NOW(3));" RECHAZO
probar "verificada con fecha y metodo manual, sin decir quien" \
 "INSERT INTO social_accounts ($B,verification_status,verified_at,verification_method) VALUES ($V,'sinquien','https://x.test/c',NOW(3),'verified',NOW(3),'bio_code');" RECHAZO
probar "verificada con fecha, metodo y persona" \
 "INSERT INTO social_accounts ($B,verification_status,verified_at,verification_method,verified_by_user_id) VALUES ($V,'completa','https://x.test/d',NOW(3),'verified',NOW(3),'bio_code',$U1);" OK
probar "verificada por oauth, sin persona (la plataforma verifica)" \
 "INSERT INTO social_accounts ($B,verification_status,verified_at,verification_method) VALUES ($V,'poroauth','https://x.test/e',NOW(3),'verified',NOW(3),'oauth');" OK
probar "metodo de verificacion inventado" \
 "INSERT INTO social_accounts ($B,verification_status,verified_at,verification_method,verified_by_user_id) VALUES ($V,'inventado','https://x.test/f',NOW(3),'verified',NOW(3),'me_fie',$U1);" RECHAZO

echo ""
echo "--- La misma cuenta verificada no es de dos creadores (BR-CREATOR-003) ---"
probar "otro creador reclama el mismo handle SIN verificar" \
 "INSERT INTO social_accounts ($B) VALUES (UUID(),$CR2,$PL,'completa','https://x.test/d',NOW(3));" OK
probar "y lo verifica: choca con el verificado del primero" \
 "UPDATE social_accounts SET verification_status='verified', verified_at=NOW(3), verification_method='bio_code', verified_by_user_id=$U1 WHERE handle='completa' AND creator_id=$CR2;" RECHAZO

echo ""
echo "--- Coherencia de las metricas: el cero ya no miente (H-06) ---"
SA="(SELECT id FROM (SELECT id FROM social_accounts WHERE handle='completa' AND creator_id=$CR) t)"
probar "snapshot nuevo: nace SIN revisar, no 'limpio'" \
 "INSERT INTO social_account_snapshots (social_account_id,captured_at,source,followers,engagement_rate) VALUES ($SA,NOW(3),'self_declared',12000,3.4);" OK
probar "y lo confirma" \
 "SELECT 1 FROM social_account_snapshots WHERE social_account_id=$SA AND coherence_status='pending_review' HAVING COUNT(*)=1;" OK
probar "marcado como anomalo SIN decir por que" \
 "INSERT INTO social_account_snapshots (social_account_id,captured_at,source,followers,coherence_status) VALUES ($SA,NOW(3),'self_declared',999999,'anomalous');" RECHAZO
probar "marcado como anomalo con su motivo" \
 "INSERT INTO social_account_snapshots (social_account_id,captured_at,source,followers,coherence_status,anomaly_note) VALUES ($SA,NOW(3),'self_declared',999999,'anomalous','Salto de seguidores del 8233% en 1 dia');" OK
probar "estado de coherencia inventado" \
 "INSERT INTO social_account_snapshots (social_account_id,captured_at,source,followers,coherence_status) VALUES ($SA,NOW(3),'self_declared',1000,'raro');" RECHAZO
probar "engagement imposible (110%)" \
 "INSERT INTO social_account_snapshots (social_account_id,captured_at,source,engagement_rate) VALUES ($SA,NOW(3),'self_declared',110.0);" RECHAZO
probar "borrado de un snapshot (solo insercion, BR-CREATOR-005)" \
 "DELETE FROM social_account_snapshots WHERE social_account_id=$SA;" RECHAZO

echo ""
echo -n "  social_account_snapshots con updated_at (solo insercion, debe ser 0): "
$CLIENTE $DB -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND COLUMN_NAME='updated_at' AND TABLE_NAME='social_account_snapshots';" -B -N 2>/dev/null

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
