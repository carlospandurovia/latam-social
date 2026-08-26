#!/bin/bash
# Pruebas de restriccion de las iteraciones 5.9 y 4.1: el enlace de contrasena.
#
#   uq_pl_token     dos enlaces no pueden compartir huella
#   uq_pl_vigente   uno VIVO por (usuario, proposito), via columna puerta
#   ck_pl_purpose   solo `initial` o `reset`
#   ck_pl_used      usado exige DESDE DONDE
#   ck_pl_revoked   revocado exige POR QUE
#   ck_pl_terminal  usado y revocado se excluyen
#   fk_pl_usuario   y el usuario no se borra teniendo enlaces
#
# Lo que se comprueba aqui es lo que la BASE garantiza aunque el servicio se
# reescriba entero: que no puedan existir dos llaves vivas de la misma cerradura,
# y que un enlace consumido diga siempre desde donde.
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

ok=0; fail=0
probar() {
  salida=$($CLIENTE $DB -e "$2" 2>&1)
  if [ -z "$salida" ] || ! echo "$salida" | grep -qi "ERROR"; then real="OK"; else real="RECHAZO"; fi
  if [ "$real" == "$3" ]; then printf "  \033[32m✓\033[0m %-70s %s\n" "$1" "$real"; ok=$((ok+1))
  else printf "  \033[31m✗\033[0m %-70s esperaba %s, obtuvo %s\n" "$1" "$3" "$real"; echo "      $(echo "$salida"|grep -i error|head -1)"; fail=$((fail+1)); fi
}
valor() {
  real=$($CLIENTE $DB -N -B -e "$2" 2>&1 | grep -v '^mysql: \[Warning\]' | tr -d '\r')
  if [ "$real" == "$3" ]; then printf "  \033[32m✓\033[0m %-70s %s\n" "$1" "$real"; ok=$((ok+1))
  else printf "  \033[31m✗\033[0m %-70s esperaba '%s', obtuvo '%s'\n" "$1" "$3" "$real"; fail=$((fail+1)); fi
}

echo ""
echo "==================================================================================="
echo "  5.9 + 4.1 - El enlace seguro de contrasena"
echo "==================================================================================="

$CLIENTE $DB -e "DELETE FROM password_links;" 2>/dev/null

valor "la tabla existe" \
  "SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema=DATABASE() AND table_name='password_links';" "1"
valor "y ningun enlace de una pasada anterior" "SELECT COUNT(*) FROM password_links;" "0"

# Un enlace para el primer usuario de la semilla. `$1` es el sufijo de la huella
# --que tiene que medir 64--, `$2` el proposito, `$3` el desplazamiento del
# usuario, `$4` la caducidad.
enlace() {
  echo "INSERT INTO password_links (uuid,user_id,purpose,token_sha256,expires_at,created_at,updated_at)
    VALUES (UUID(),(SELECT id FROM users ORDER BY id LIMIT 1 OFFSET ${3:-0}),'$2',
      LPAD('$1',64,'0'),NOW(3) + INTERVAL ${4:-1} HOUR,NOW(3),NOW(3));"
}

echo ""
echo "--- El proposito es un valor cerrado ---"
probar "un enlace de alta" "$(enlace 'a1' 'initial')" OK
probar "uno de recuperacion, del mismo usuario" "$(enlace 'a2' 'reset')" OK
probar "uno de proposito inventado" "$(enlace 'a3' 'invitacion')" RECHAZO

echo ""
echo "--- Uno VIVO por usuario y proposito ---"
probar "un segundo enlace de alta para el mismo usuario" "$(enlace 'b1' 'initial')" RECHAZO
probar "revocando el primero, el segundo entra" \
  "UPDATE password_links SET revoked_at=NOW(3), revoked_reason='sustituido'
     WHERE token_sha256=LPAD('a1',64,'0');
   $(enlace 'b1' 'initial')" OK
valor "y quedan los dos: revocar no borra, es evidencia" \
  "SELECT COUNT(*) FROM password_links WHERE purpose='initial';" "2"
probar "y otro usuario puede tener el suyo a la vez" "$(enlace 'c1' 'initial' 1)" OK

echo ""
echo "--- La huella es unica ---"
probar "dos enlaces con la misma huella" "$(enlace 'c1' 'reset' 1)" RECHAZO

echo ""
echo "--- Usado exige DESDE DONDE ---"
probar "marcar uno como usado sin IP" \
  "UPDATE password_links SET used_at=NOW(3) WHERE token_sha256=LPAD('b1',64,'0');" RECHAZO
probar "marcarlo usado con su IP" \
  "UPDATE password_links SET used_at=NOW(3), used_ip=INET6_ATON('203.0.113.9')
     WHERE token_sha256=LPAD('b1',64,'0');" OK

echo ""
echo "--- Revocado exige POR QUE ---"
probar "revocar sin motivo" \
  "UPDATE password_links SET revoked_at=NOW(3) WHERE token_sha256=LPAD('a2',64,'0');" RECHAZO
probar "revocar con motivo" \
  "UPDATE password_links SET revoked_at=NOW(3), revoked_reason='password_puesta'
     WHERE token_sha256=LPAD('a2',64,'0');" OK

echo ""
echo "--- Usado y revocado se excluyen ---"
# Si pudieran convivir, `vigente_gate` seguiria valiendo NULL y todo pareceria
# correcto, pero la evidencia diria dos cosas a la vez sobre el mismo enlace.
probar "revocar uno que ya se uso" \
  "UPDATE password_links SET revoked_at=NOW(3), revoked_reason='sustituido'
     WHERE token_sha256=LPAD('b1',64,'0');" RECHAZO
probar "usar uno que ya se revoco" \
  "UPDATE password_links SET used_at=NOW(3), used_ip=INET6_ATON('203.0.113.9')
     WHERE token_sha256=LPAD('a2',64,'0');" RECHAZO

echo ""
echo "--- La puerta cuenta lo que tiene que contar ---"
valor "un solo enlace vivo del usuario uno" \
  "SELECT COUNT(*) FROM password_links
    WHERE vigente_gate=1 AND user_id=(SELECT id FROM users ORDER BY id LIMIT 1);" "0"
probar "y por eso ahora entra uno nuevo de recuperacion" "$(enlace 'd1' 'reset')" OK
valor "que ya es el unico vivo" \
  "SELECT COUNT(*) FROM password_links
    WHERE vigente_gate=1 AND user_id=(SELECT id FROM users ORDER BY id LIMIT 1);" "1"

echo ""
echo "--- El usuario no se borra teniendo enlaces ---"
# `restrictOnDelete`, como todo lo que es evidencia en este esquema. Un usuario
# no se borra: se desactiva.
probar "borrar al usuario que tiene enlaces" \
  "DELETE FROM users WHERE id=(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) x);" RECHAZO

echo ""
echo "--- La caducidad se guarda tal cual, no se deduce ---"
# Contra el TOTAL de vivos, no contra un numero escrito a mano: hay enlaces de
# dos usuarios en juego y «1» era una cuenta que solo cuadraba por casualidad.
valor "ningun enlace vivo esta caducado" \
  "SELECT COUNT(*) FROM password_links WHERE vigente_gate=1 AND expires_at <= NOW(3);" "0"
valor "y caducar NO revoca: la puerta sigue abierta hasta que alguien la cierre" \
  "SELECT vigente_gate FROM (
     SELECT vigente_gate FROM password_links WHERE token_sha256=LPAD('d1',64,'0')
   ) x;" "1"
probar "un enlace caducado sigue existiendo como evidencia" \
  "UPDATE password_links SET expires_at=NOW(3) - INTERVAL 1 HOUR
     WHERE token_sha256=LPAD('d1',64,'0');" OK
valor "y ocupa su hueco: caducar no libera la puerta" \
  "SELECT COUNT(*) FROM password_links
    WHERE vigente_gate=1 AND user_id=(SELECT id FROM users ORDER BY id LIMIT 1);" "1"

$CLIENTE $DB -e "DELETE FROM password_links;" 2>/dev/null

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
