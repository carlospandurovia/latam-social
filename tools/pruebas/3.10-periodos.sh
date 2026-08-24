#!/bin/bash
# Pruebas de restriccion de la iteracion 3.10: periodos que no se solapan.
#
# El esquema tiene siete tablas con `valid_from` / `valid_to`. Todas garantizan
# que hay una sola fila VIGENTE, con la columna generada `current_gate` dentro
# de un UNIQUE. Ninguna garantizaba que el HISTORICO fuera coherente:
#
#     .cual es el perfil de HOY?         -> una sola respuesta, garantizado
#     .cual era el perfil el 1 de mayo?  -> podian ser dos
#
# `H-16` cerro eso en tarifas (3.9). Aqui se cierra en las tres tablas donde el
# mismo agujero tiene consecuencias: el historico fiscal del creador (`T-12`),
# el del cliente, y que sociedad cubre cada pais --que es de donde sale la
# factura--.
#
# Uso: bash tools/pruebas/3.10-periodos.sh <base>
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

# Creador PROPIO, no `anatorres`.
#
# Las demas suites comparten a `anatorres` y se piden la base recien sembrada.
# Aqui no vale: `3.6-fiscal` escribe en `creator_tax_profiles`, que es
# justamente la tabla de esta iteracion, y `correr-todo.sh` corre las siete
# suites seguidas sobre la MISMA base. Con el creador compartido, 3.10 se
# encontraba cinco perfiles ya puestos y abortaba --y el orden alfabetico habria
# decidido cual de las dos suites funcionaba--.
#
# Con creador propio esta suite no depende de lo que hayan hecho las anteriores.
$CLIENTE $DB -e "INSERT INTO creators (uuid,first_name,last_name,display_name,birth_date,email,
   country_id,document_country_code,document_type,document_number,status,payment_term_days,
   preferred_currency_code,created_at,updated_at)
 SELECT UUID(),'Nora','Vega','noravega310','1995-03-08','nora310@ejemplo.test',c.id,'PE','DNI','43100310',
   'pending',30,'PEN',NOW(3),NOW(3) FROM countries c WHERE c.iso2='PE'
 ON DUPLICATE KEY UPDATE display_name=display_name;" 2>/dev/null

CR="(SELECT id FROM (SELECT id FROM creators WHERE display_name='noravega310') t)"
usados=$($CLIENTE $DB -N -e "SELECT COUNT(*) FROM creator_tax_profiles WHERE creator_id=$CR" 2>/dev/null)
if [ -z "$usados" ]; then
  echo "  No puedo leer $DB. .Esta levantado el motor y creada la base?"; exit 2
fi
if [ "$usados" != "0" ]; then
  echo "  noravega310 ya tiene $usados perfiles fiscales: recree la base y cargue la semilla."
  exit 2
fi
CO="(SELECT id FROM (SELECT id FROM countries WHERE iso2='CO') t)"
U1="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) t)"
U2="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1 OFFSET 1) t)"

# Un perfil APROBADO necesita quien lo capturo y quien lo aprobo, y no pueden
# ser el mismo (H-03). Se arma la columna una vez.
APR="withholding_status,status,created_by_user_id,approved_by_user_id,approved_at"
APRV="'not_applicable','approved',$U1,$U2,NOW(3)"
BASE="creator_id,country_id,tax_regime_code,tax_id_type,tax_id_number,issued_document_type"

echo ""
echo "--- El historico fiscal del creador ya tiene UNA respuesta por fecha (T-12) ---"
probar "primer perfil aprobado, de enero a marzo" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,valid_to,$APR) VALUES ($CR,$PA,'RER','RUC','20610001','recibo_honorarios','2026-01-01','2026-03-31',$APRV);" OK
probar "el siguiente empieza el dia despues: encaja" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,valid_to,$APR) VALUES ($CR,$PA,'RG','RUC','20610002','factura','2026-04-01','2026-06-30',$APRV);" OK
probar "uno que empieza el MISMO dia que acaba el anterior (el defecto T-12)" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,valid_to,$APR) VALUES ($CR,$PA,'RER','RUC','20610003','recibo_honorarios','2026-06-30','2026-08-31',$APRV);" RECHAZO
probar "uno que envuelve por completo a los dos anteriores" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,valid_to,$APR) VALUES ($CR,$PA,'RER','RUC','20610004','recibo_honorarios','2025-01-01','2027-01-01',$APRV);" RECHAZO
probar "uno abierto que empieza dentro del ultimo cerrado" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,$APR) VALUES ($CR,$PA,'RER','RUC','20610005','recibo_honorarios','2026-05-01',$APRV);" RECHAZO
probar "uno abierto que empieza despues de todo: es el vigente" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,$APR) VALUES ($CR,$PA,'RG','RUC','20610006','factura','2026-07-01',$APRV);" OK
probar "un segundo abierto: chocaria contra el vigente para siempre" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,$APR) VALUES ($CR,$PA,'RER','RUC','20610007','recibo_honorarios','2026-09-01',$APRV);" RECHAZO

echo ""
echo "--- Otro pais es otra serie: no compiten entre si ---"
probar "mismas fechas exactas, pero en Colombia" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,valid_to,$APR) VALUES ($CR,$CO,'SIMPLE','NIT','900010001','factura','2026-01-01','2026-03-31',$APRV);" OK

echo ""
echo "--- Lo que NO estuvo vigente no ocupa periodo ---"
# Es la razon de que la regla lleve filtro. Un perfil rechazado nunca aplico;
# si estorbara, un error de captura bloquearia el historico para siempre.
probar "un perfil PENDIENTE encima de un periodo aprobado" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,valid_to,created_by_user_id) VALUES ($CR,$PA,'RER','RUC','20610008','recibo_honorarios','2026-02-01','2026-02-28',$U1);" OK
probar "un perfil RECHAZADO encima de un periodo aprobado" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,valid_to,status,created_by_user_id,rejection_note) VALUES ($CR,$PA,'RER','RUC','20610009','recibo_honorarios','2026-02-01','2026-02-28','rejected',$U1,'RUC que no existe');" OK
probar "aprobar DESPUES uno que se solapa: ahi si estorba" \
  "UPDATE creator_tax_profiles SET status='approved', withholding_status='not_applicable', approved_by_user_id=$U2, approved_at=NOW(3) WHERE tax_id_number='20610008';" RECHAZO

echo ""
echo "--- El relevo TAL COMO LO HACE EL CONTROLADOR (esto es T-12 de verdad) ---"
# Las aserciones de arriba meten dos perfiles `approved` a la vez, y eso el
# controlador NO lo hace nunca: al aprobar, marca el anterior como `superseded`
# en la MISMA transaccion. Con el filtro estrecho de la primera version --solo
# `approved`-- no habia dos aprobados jamas, la regla no se disparaba, y el
# defecto que esta iteracion viene a cerrar entraba igual.
#
# Estas cuatro reproducen la secuencia exacta del controlador.
$CLIENTE $DB -e "INSERT INTO creators (uuid,first_name,last_name,display_name,birth_date,email,
   country_id,document_country_code,document_type,document_number,status,payment_term_days,
   preferred_currency_code,created_at,updated_at)
 SELECT UUID(),'Iris','Paz','irispaz310','1994-07-02','iris310@ejemplo.test',c.id,'PE','DNI','43100311',
   'pending',30,'PEN',NOW(3),NOW(3) FROM countries c WHERE c.iso2='PE'
 ON DUPLICATE KEY UPDATE display_name=display_name;" 2>/dev/null
IR="(SELECT id FROM (SELECT id FROM creators WHERE display_name='irispaz310') t)"

probar "vigente: aprobado y abierto desde enero" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,$APR) VALUES ($IR,$PA,'RER','RUC','20630001','recibo_honorarios','2026-01-01',$APRV);" OK
probar "se captura el siguiente, en pendiente, desde abril" \
  "INSERT INTO creator_tax_profiles ($BASE,valid_from,created_by_user_id) VALUES ($IR,$PA,'RG','RUC','20630002','factura','2026-04-01',$U1);" OK
probar "el controlador cierra el anterior EL MISMO DIA (como hace hoy)" \
  "UPDATE creator_tax_profiles SET status='superseded', valid_to='2026-04-01' WHERE tax_id_number='20630001';" OK
probar "...y al aprobar el nuevo, la base lo para: ese dia hay DOS regimenes" \
  "UPDATE creator_tax_profiles SET status='approved', withholding_status='not_applicable', approved_by_user_id=$U2, approved_at=NOW(3) WHERE tax_id_number='20630002';" RECHAZO
probar "cerrandolo el dia ANTES, el relevo pasa" \
  "UPDATE creator_tax_profiles SET valid_to='2026-03-31' WHERE tax_id_number='20630001';" OK
probar "y ahora si se aprueba" \
  "UPDATE creator_tax_profiles SET status='approved', withholding_status='not_applicable', approved_by_user_id=$U2, approved_at=NOW(3) WHERE tax_id_number='20630002';" OK
valor "el 2026-04-01 Iris tenia un solo regimen" \
  "SELECT COUNT(*) FROM creator_tax_profiles WHERE creator_id=$IR AND country_id=$PA AND status IN ('approved','superseded') AND valid_from<='2026-04-01' AND IFNULL(valid_to,'9999-12-31')>='2026-04-01';" "1"

echo ""
echo "--- Un UPDATE tampoco puede abrir un solape ---"
probar "estirar el primer periodo hasta pisar al segundo" \
  "UPDATE creator_tax_profiles SET valid_to='2026-05-01' WHERE tax_id_number='20610001';" RECHAZO
probar "una fila puede modificarse sin chocar consigo misma" \
  "UPDATE creator_tax_profiles SET tax_regime_code='RMT' WHERE tax_id_number='20610001';" OK
probar "encogerla si que se puede" \
  "UPDATE creator_tax_profiles SET valid_to='2026-02-28' WHERE tax_id_number='20610001';" OK

echo ""
echo "--- El perfil fiscal del CLIENTE, mismo agujero ---"
CL="(SELECT id FROM (SELECT id FROM client_organizations ORDER BY id LIMIT 1) t)"
tiene=$($CLIENTE $DB -N -e "SELECT COUNT(*) FROM client_organizations" 2>/dev/null | tr -d '\r')
if [ "${tiene:-0}" = "0" ]; then
  $CLIENTE $DB -e "INSERT INTO client_organizations (uuid,client_code,commercial_name,country_id,created_at,updated_at)
    VALUES (UUID(),'CLI-P310','Cliente 3.10',$PA,NOW(3),NOW(3));" 2>/dev/null
fi
# En COLOMBIA a proposito, no en Peru: la semilla ya trae un perfil ABIERTO de
# ese cliente para Peru, asi que alli la regla --con razon-- no deja meter nada.
# Lo descubrio la asercion de que algo esta PERMITIDO, que es la tercera vez en
# esta fase que una asercion OK destapa un montaje que mentia.
CB="client_organization_id,country_id,tax_id_type,tax_id_number,legal_name,address_line1,city,valid_from"
probar "primer perfil de cliente en CO, de enero a marzo" \
  "INSERT INTO client_tax_profiles ($CB,valid_to) VALUES ($CL,$CO,'NIT','900620001','Cliente SAS','Calle 1','Bogota','2026-01-01','2026-03-31');" OK
probar "el siguiente empieza el mismo dia que acaba el anterior" \
  "INSERT INTO client_tax_profiles ($CB,valid_to) VALUES ($CL,$CO,'NIT','900620002','Cliente SAS','Calle 1','Bogota','2026-03-31','2026-06-30');" RECHAZO
probar "empezando el dia despues, encaja" \
  "INSERT INTO client_tax_profiles ($CB,valid_to) VALUES ($CL,$CO,'NIT','900620003','Cliente SAS','Calle 1','Bogota','2026-04-01','2026-06-30');" OK
# Ojo con las fechas de esta: la primera version puso 2020 y esperaba RECHAZO.
# El perfil de la semilla empieza en 2026 y esta abierto, asi que un periodo de
# 2020 acaba ANTES de que ese empiece y no se solapa. La regla tenia razon y la
# asercion no. Se deja documentado porque la tentacion al ver un rojo es tocar
# la regla.
probar "y en Peru la semilla ya tiene uno abierto desde 2026: no cabe encima" \
  "INSERT INTO client_tax_profiles ($CB,valid_to) VALUES ($CL,$PA,'RUC','20620009','Cliente SAC','Av 1','Lima','2026-06-01','2026-12-31');" RECHAZO
probar "pero ANTES de que ese empiece si cabe: no se solapan" \
  "INSERT INTO client_tax_profiles ($CB,valid_to) VALUES ($CL,$PA,'RUC','20620010','Cliente SAC','Av 1','Lima','2020-01-01','2020-12-31');" OK

echo ""
echo "--- Que sociedad cubre cada pais: de aqui sale la factura ---"
# `uq_lec_country` ya impedia DOS vigentes a la vez. Lo que no impedia era que
# el historico tuviera dos para una fecha pasada, y el resolver de facturacion
# elige por pais y fecha: un empate ahi es una factura emitida por la sociedad
# equivocada.
LE1="(SELECT id FROM (SELECT id FROM legal_entities ORDER BY id LIMIT 1) t)"
LE2="(SELECT id FROM (SELECT id FROM legal_entities ORDER BY id LIMIT 1 OFFSET 1) t)"
cuantas=$($CLIENTE $DB -N -e "SELECT COUNT(*) FROM legal_entities" 2>/dev/null | tr -d '\r')
if [ "${cuantas:-0}" -lt 2 ]; then
  # La semilla trae una sola sociedad. Hace falta una segunda para que el empate
  # sea posible: con una sola, la regla no se podria probar y quedaria verde por
  # no haber preguntado nada.
  $CLIENTE $DB -e "INSERT INTO legal_entities (uuid,platform_brand_id,code,legal_name,country_id,tax_id_type,tax_id_number,address_line1,city,default_currency_code,timezone,created_at,updated_at)
    SELECT UUID(),pb.id,'LE310','Sociedad de prueba 3.10',$CO,'NIT','900310001','Calle 1','Bogota','COP','America/Bogota',NOW(3),NOW(3)
    FROM platform_brands pb ORDER BY pb.id LIMIT 1;" 2>/dev/null
  cuantas=$($CLIENTE $DB -N -e "SELECT COUNT(*) FROM legal_entities" 2>/dev/null | tr -d '\r')
fi
if [ "${cuantas:-0}" -lt 2 ]; then
  echo "  (omitido: no pude crear una segunda sociedad)"
else
  # Ya no se limpia con DELETE: desde `T-16` esta tabla no admite borrado --dice
  # que sociedad facturo cada pais y desde cuando--. La suite usa Colombia, que
  # la semilla no cubre, asi que no hacia falta.
  :
  probar "una sociedad cubre Colombia de enero a marzo" \
    "INSERT INTO legal_entity_countries (legal_entity_id,country_id,valid_from,valid_to) VALUES ($LE1,$CO,'2026-01-01','2026-03-31');" OK
  probar "OTRA sociedad para el mismo pais y fechas: empate en el resolver" \
    "INSERT INTO legal_entity_countries (legal_entity_id,country_id,valid_from,valid_to) VALUES ($LE2,$CO,'2026-02-01','2026-05-31');" RECHAZO
  probar "el relevo limpio, el dia despues" \
    "INSERT INTO legal_entity_countries (legal_entity_id,country_id,valid_from,valid_to) VALUES ($LE2,$CO,'2026-04-01','2026-06-30');" OK
fi

echo ""
echo "--- 3.13: y los TERMINOS, que se escaparon del barrido ---"
# Cuarta vez el mismo defecto, y en la tabla que peor lo lleva: aqui esta el
# texto legal que el creador acepto. Se escapo de 3.10 por una razon tonta:
# aquella busqueda miro columnas `valid_from`, y estas se llaman
# `effective_from`. El gate ahora busca por FORMA.
probar "una version de terminos de prueba, cerrada en marzo" \
  "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,effective_from,effective_to,created_at) VALUES (UUID(),'creator','zz_prueba_313','v1','T1','texto uno',REPEAT('a',64),'2026-01-01','2026-03-31',NOW(3));" OK
probar "la siguiente empieza el MISMO dia en que acaba la anterior" \
  "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,effective_from,created_at) VALUES (UUID(),'creator','zz_prueba_313','v2','T2','texto dos',REPEAT('b',64),'2026-03-31',NOW(3));" RECHAZO
probar "empezando el dia despues, encaja" \
  "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,effective_from,created_at) VALUES (UUID(),'creator','zz_prueba_313','v2','T2','texto dos',REPEAT('b',64),'2026-04-01',NOW(3));" OK
probar "otro codigo de terminos es otra serie" \
  "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,effective_from,effective_to,created_at) VALUES (UUID(),'client','zz_prueba_313b','v1','T1','texto tres',REPEAT('c',64),'2026-01-01','2026-12-31',NOW(3));" OK
valor "el 2026-03-31 regia UN solo texto" \
  "SELECT COUNT(*) FROM terms_versions WHERE code='zz_prueba_313' AND effective_from<='2026-03-31' AND IFNULL(effective_to,'9999-12-31')>='2026-03-31';" "1"

echo ""
echo "--- Lo que quedo en la base es un historico sin ambiguedad ---"
valor "el 2026-02-15 el creador tenia un solo regimen en PE" \
  "SELECT COUNT(*) FROM creator_tax_profiles WHERE creator_id=$CR AND country_id=$PA AND status IN ('approved','superseded') AND valid_from<='2026-02-15' AND IFNULL(valid_to,'9999-12-31')>='2026-02-15';" "1"
valor "y el 2026-05-15 tambien uno solo" \
  "SELECT COUNT(*) FROM creator_tax_profiles WHERE creator_id=$CR AND country_id=$PA AND status IN ('approved','superseded') AND valid_from<='2026-05-15' AND IFNULL(valid_to,'9999-12-31')>='2026-05-15';" "1"

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
