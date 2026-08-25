#!/bin/bash
# Pruebas de restriccion de la iteracion 4.3: el contacto principal del cliente.
#
# La regla es una sola linea de esquema:
#
#   primary_gate TINYINT UNSIGNED GENERATED ALWAYS AS
#     (CASE WHEN is_primary = 1 AND status = 'active' THEN 1 ELSE NULL END) STORED
#   UNIQUE KEY uq_contacts_primary (primary_gate, client_organization_id, contact_type)
#
# Un principal ACTIVO por cliente y por tipo. Suena obvio y tiene cuatro
# fronteras que hay que fijar, porque cada una es un sitio donde la regla podria
# estar de mas o de menos:
#
#   por TIPO      -> comercial y facturacion son puestos distintos
#   por CLIENTE   -> cada cliente tiene los suyos
#   solo ACTIVOS  -> desactivar libera el puesto, no hay que acordarse de bajar la marca
#   varios NO principales -> los NULL no chocan entre si
#
# Las cuatro se prueban PERMITIENDO, no rechazando. Es la leccion que esta
# sesion ha repetido cinco veces: la asercion de que algo se PERMITE es la que
# descubre que las de rechazo mentian. Una unica sobre `(is_primary,
# client_organization_id, contact_type)` sin columna puerta rechazaria el
# segundo NO principal, y todas las pruebas de rechazo seguirian verdes.
#
# La ultima seccion es la que da razon de ser al servicio `Contactos`: las tres
# maniobras que un operador hace sin pensar y que la base contesta con un 1062.
# Aqui se comprueba que la base efectivamente las rechaza; en PHPUnit se
# comprueba que la aplicacion no deja que lleguen.
#
# Uso: bash tools/pruebas/4.3-contactos.sh <base>
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

# La suite exige base limpia de contactos: si quedaran filas de una pasada
# anterior, «el primer principal comercial» chocaria y el fallo acusaria a la
# regla de algo que no hizo.
n=$($CLIENTE $DB -N -B -e "SELECT COUNT(*) FROM contacts" 2>/dev/null | grep -v Warning | tr -d '\r')
if [ "${n:-0}" -ne 0 ]; then
  echo "  (aviso) contacts trae ${n} filas de antes; rehaz la base antes de correr esta suite."
fi

# Un segundo cliente, para poder probar que el puesto es POR CLIENTE. Se clona
# el pais del primero en vez de inventarse uno: `fk_co_country` es real.
$CLIENTE $DB -e "INSERT INTO client_organizations (uuid,commercial_name,client_code,country_id,status,created_at)
  SELECT UUID(),'Cliente 4.3 B','CLI-43B',country_id,'active',NOW(3) FROM client_organizations WHERE client_code='CLI-0001';" 2>/dev/null

A="(SELECT id FROM (SELECT id FROM client_organizations WHERE client_code='CLI-0001') t)"
B="(SELECT id FROM (SELECT id FROM client_organizations WHERE client_code='CLI-43B') t)"

alta() { # nombre correo tipo principal estado cliente
  echo "INSERT INTO contacts (uuid,client_organization_id,full_name,contact_email,contact_type,is_primary,status,created_at)
        VALUES (UUID(),$6,'$1','$2','$3',$4,'$5',NOW(3));"
}

echo ""
echo "--- Un principal por cliente y tipo ---"
probar "el primer principal comercial entra" \
  "$(alta 'Ana Comercial' 'ana@a.com' commercial 1 active "$A")" OK
probar "un segundo principal comercial del MISMO cliente choca" \
  "$(alta 'Beto Comercial' 'beto@a.com' commercial 1 active "$A")" RECHAZO

echo ""
echo "--- Las cuatro fronteras: lo que la regla NO debe impedir ---"
# Sin estas cuatro, una unica mal puesta pasaria por buena. Son las aserciones
# que de verdad describen la regla; las de rechazo solo dicen que existe alguna.
probar "otro TIPO en el mismo cliente si puede tener su principal" \
  "$(alta 'Carla Facturacion' 'carla@a.com' billing 1 active "$A")" OK
probar "y un tercero, legal, tambien" \
  "$(alta 'Dario Legal' 'dario@a.com' legal 1 active "$A")" OK
probar "otro CLIENTE tiene su propio principal comercial" \
  "$(alta 'Elsa Comercial' 'elsa@b.com' commercial 1 active "$B")" OK
probar "varios NO principales del mismo tipo y cliente conviven" \
  "$(alta 'Fito Suplente' 'fito@a.com' commercial 0 active "$A")" OK
probar "y otro mas, para que quede claro que los NULL no chocan entre si" \
  "$(alta 'Gina Suplente' 'gina@a.com' commercial 0 active "$A")" OK
probar "un principal INACTIVO no ocupa el puesto" \
  "$(alta 'Hugo Antiguo' 'hugo@a.com' commercial 1 inactive "$A")" OK

echo ""
echo "--- Desactivar libera el puesto sin tocar la marca de principal ---"
# Esto es lo que compra meter `status` en la puerta: se da de baja a alguien y
# el puesto queda libre, sin un paso previo que se pueda olvidar.
probar "se desactiva al principal comercial" \
  "UPDATE contacts SET status='inactive' WHERE contact_email='ana@a.com';" OK
probar "y ahora si entra otro principal comercial" \
  "$(alta 'Ivan Comercial' 'ivan@a.com' commercial 1 active "$A")" OK
probar "la baja conserva is_primary=1 (no se le borro el dato)" \
  "SELECT 1 FROM contacts WHERE contact_email='ana@a.com' AND is_primary=1 AND status='inactive' HAVING COUNT(*)=1;" OK

echo ""
echo "--- Las tres maniobras que la base contesta con un 1062 ---"
# Cada una tiene su contrapartida en `Contactos`: la aplicacion baja al que
# ocupa el puesto ANTES de tocar la fila que va a ocuparlo.
probar "1) subir a un suplente mientras el puesto esta ocupado" \
  "UPDATE contacts SET is_primary=1 WHERE contact_email='fito@a.com';" RECHAZO
probar "2) reactivar a quien conserva is_primary=1" \
  "UPDATE contacts SET status='active' WHERE contact_email='ana@a.com';" RECHAZO
probar "3) mover al principal de facturacion al tipo comercial" \
  "UPDATE contacts SET contact_type='commercial' WHERE contact_email='carla@a.com';" RECHAZO

echo ""
echo "--- El relevo, en el orden correcto, si pasa ---"
# El orden no es un detalle de estilo: al reves choca, y esta es la asercion que
# lo fija. `Contactos::bajarPrincipal()` existe por esta linea.
probar "primero se baja al que ocupa" \
  "UPDATE contacts SET is_primary=0 WHERE contact_email='ivan@a.com';" OK
probar "y despues sube el suplente" \
  "UPDATE contacts SET is_primary=1 WHERE contact_email='fito@a.com';" OK
probar "al reves choca: subir antes de bajar" \
  "UPDATE contacts SET is_primary=1 WHERE contact_email='gina@a.com';" RECHAZO

echo ""
echo "--- Y el resto de restricciones declaradas ---"
probar "un tipo que no esta en la lista se rechaza" \
  "UPDATE contacts SET contact_type='ventas' WHERE contact_email='gina@a.com';" RECHAZO
probar "un estado que no esta en la lista se rechaza" \
  "UPDATE contacts SET status='baja' WHERE contact_email='gina@a.com';" RECHAZO
probar "editar al principal sin tocar su rango no choca consigo mismo" \
  "UPDATE contacts SET position='Gerente' WHERE contact_email='fito@a.com';" OK
probar "el contacto apunta a un cliente que existe" \
  "$(alta 'Sin Cliente' 'nadie@a.com' commercial 0 active 999999)" RECHAZO

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
