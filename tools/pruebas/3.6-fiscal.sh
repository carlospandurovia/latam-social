#!/bin/bash
# Pruebas de restriccion de la iteracion 3.6: titular del perfil tributario y
# separacion de funciones sin agujeros.
#
# Uso: bash tools/pruebas/3.6-fiscal.sh <base>
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

# Trabaja sobre `anatorres`; la suite 2.13 usa el otro creador y le deja un
# perfil vigente. Si aqui ya hay perfiles, la base viene sucia de otra pasada.
CR="(SELECT id FROM (SELECT id FROM creators WHERE display_name='anatorres') t)"
usados=$($CLIENTE $DB -N -e "SELECT COUNT(*) FROM creator_tax_profiles WHERE creator_id=$CR" 2>/dev/null)
if [ -z "$usados" ]; then
  echo "  No puedo leer $DB. .Esta levantado el motor y creada la base?"; exit 2
fi
if [ "$usados" != "0" ]; then
  echo "  anatorres ya tiene $usados perfiles fiscales: recree la base y cargue la semilla."
  exit 2
fi

PA="(SELECT id FROM (SELECT id FROM countries WHERE iso2='PE') t)"
CO="(SELECT id FROM (SELECT id FROM countries WHERE iso2='CO') t)"
U1="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) t)"
U2="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1 OFFSET 1) t)"
# Un tutor para el creador, que hace falta para probar el titular.
$CLIENTE $DB -e "INSERT INTO creator_guardians (creator_id,full_name,relationship,document_country_code,document_type,document_number,email,authorization_file_id,proof_of_relationship_file_id,status,valid_from,created_at)
 SELECT $CR,'Rosa Torres','mother','PE','DNI','09000001','rosa@ejemplo.test',f.id,f.id,'active','2026-01-01',NOW(3)
 FROM files f WHERE f.purpose='identity_document' LIMIT 1;" 2>/dev/null
TU="(SELECT id FROM (SELECT id FROM creator_guardians WHERE full_name='Rosa Torres') t)"

BASE="creator_id,country_id,tax_regime_code,tax_id_type,tax_id_number,issued_document_type,valid_from,created_by_user_id"

echo ""
echo "--- De quien son los datos fiscales (H-01) ---"
probar "titular por defecto: el creador" \
 "INSERT INTO creator_tax_profiles ($BASE) VALUES ($CR,$PA,'RER','RUC','20600001','recibo_honorarios','2026-01-01',$U1);" OK
probar "titular tutor, diciendo cual" \
 "INSERT INTO creator_tax_profiles ($BASE,holder_type,holder_guardian_id) VALUES ($CR,$CO,'SIMPLE','NIT','900000001','factura','2026-01-01',$U1,'guardian',$TU);" OK
probar "titular tutor SIN decir cual" \
 "INSERT INTO creator_tax_profiles ($BASE,holder_type) VALUES ($CR,$PA,'RER','RUC','20600002','recibo_honorarios','2026-01-01',$U1,'guardian');" RECHAZO
probar "titular creador con un tutor colgando" \
 "INSERT INTO creator_tax_profiles ($BASE,holder_type,holder_guardian_id) VALUES ($CR,$PA,'RER','RUC','20600003','recibo_honorarios','2026-01-01',$U1,'creator',$TU);" RECHAZO
probar "tipo de titular inventado" \
 "INSERT INTO creator_tax_profiles ($BASE,holder_type) VALUES ($CR,$PA,'RER','RUC','20600004','recibo_honorarios','2026-01-01',$U1,'primo');" RECHAZO

echo ""
echo "--- La separacion de funciones ya no se apaga sola (H-03) ---"
probar "perfil sin decir quien lo capturo" \
 "INSERT INTO creator_tax_profiles (creator_id,country_id,tax_regime_code,tax_id_type,tax_id_number,issued_document_type,valid_from) VALUES ($CR,$PA,'RER','RUC','20600005','recibo_honorarios','2026-01-01');" RECHAZO
probar "APROBADO sin decir quien lo capturo (el agujero de H-03)" \
 "INSERT INTO creator_tax_profiles (creator_id,country_id,tax_regime_code,tax_id_type,tax_id_number,issued_document_type,valid_from,withholding_status,status,approved_by_user_id,approved_at) VALUES ($CR,$PA,'RER','RUC','20600006','recibo_honorarios','2026-01-01','not_applicable','approved',$U2,NOW(3));" RECHAZO
probar "aprobado por quien lo capturo" \
 "INSERT INTO creator_tax_profiles ($BASE,withholding_status,status,approved_by_user_id,approved_at) VALUES ($CR,$PA,'RER','RUC','20600007','recibo_honorarios','2026-01-01',$U1,'not_applicable','approved',$U1,NOW(3));" RECHAZO
probar "aprobado por otra persona" \
 "INSERT INTO creator_tax_profiles ($BASE,withholding_status,status,approved_by_user_id,approved_at) VALUES ($CR,$PA,'RER','RUC','20600008','recibo_honorarios','2026-01-01',$U1,'not_applicable','approved',$U2,NOW(3));" OK

echo ""
echo "--- Rechazar no es aprobar (H-04) ---"
probar "captura para retirar" \
 "INSERT INTO creator_tax_profiles ($BASE) VALUES ($CR,$CO,'SIMPLE','NIT','900000777','factura','2026-01-01',$U1);" OK
probar "el capturador la RECHAZA escribiendo 'aprobado por' (lo que hacia la 1a version)" \
 "UPDATE creator_tax_profiles SET status='rejected', rejection_note='Me equivoque', approved_by_user_id=$U1, approved_at=NOW(3) WHERE tax_id_number='900000777';" RECHAZO
probar "el capturador la retira SIN tocar 'aprobado por'" \
 "UPDATE creator_tax_profiles SET status='rejected', rejection_note='Me equivoque de RUC' WHERE tax_id_number='900000777';" OK

echo ""
echo "--- Un solo perfil vigente por creador y pais (BR-CREATOR-007) ---"
probar "segundo perfil vigente en el MISMO pais" \
 "INSERT INTO creator_tax_profiles ($BASE,withholding_status,status,approved_by_user_id,approved_at) VALUES ($CR,$PA,'GENERAL','RUC','20600009','factura','2026-06-01',$U1,'not_applicable','approved',$U2,NOW(3));" RECHAZO
probar "cerrar el anterior y abrir el nuevo" \
 "UPDATE creator_tax_profiles SET status='superseded', valid_to='2026-06-01' WHERE tax_id_number='20600008';" OK
probar "ahora si entra el nuevo vigente" \
 "INSERT INTO creator_tax_profiles ($BASE,withholding_status,status,approved_by_user_id,approved_at) VALUES ($CR,$PA,'GENERAL','RUC','20600009','factura','2026-06-01',$U1,'not_applicable','approved',$U2,NOW(3));" OK
probar "vigencia que termina antes de empezar" \
 "UPDATE creator_tax_profiles SET valid_to='2020-01-01' WHERE tax_id_number='20600009';" RECHAZO

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
