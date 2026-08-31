#!/bin/bash
# Pruebas de restriccion de la iteracion 9.17: la identidad de la plataforma.
#
#   ck_pb_nombre           una marca sin nombre no es «sin configurar»: es un hueco
#   ck_pb_color2           el color secundario, hexadecimal
#   ck_pb_barra            y el de la barra
#   ck_pb_tipografia       letras, numeros y espacios: acaba en una URL y en CSS
#   ck_pb_correo           el correo de soporte parece un correo
#   ck_pb_web              la web lleva esquema, o el enlace no lleva a ningun sitio
#   ck_pb_defecto_activa   la marca por defecto no puede estar desactivada
#   uq_pb_default          UNA sola marca por defecto (columna puerta)
#   tg_pb_code             el codigo no se cambia: es la llave del sembrador
#   fk_pb_favicon          el favicon apunta a un archivo que existe
#
# Por que esto es una iteracion y no un formulario: «LATAM Social» estaba escrito
# en el `<title>`, en la barra lateral y en la pantalla de acceso, y el favicon
# era un archivo del repositorio. Para poner otro nombre habia que editar Blade y
# desplegar, que es lo contrario de una plataforma white label (DEC-190).
#
# La marca de esta suite se llama `p917` y se limpia al final: `platform_brands`
# SI admite borrado --no es evidencia de nada-- mientras no cuelgue de ella
# ninguna sociedad.
#
# Uso: bash tools/pruebas/9.17-marca.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.17 - La identidad de la plataforma"
echo "==================================================================================="

# La suite empieza por su propia limpieza y no por la del final: si una corrida
# anterior murio a medias, la fila de `p917` seguiria ahi y `uq_pb_code` haria
# fallar el primer INSERT por un motivo que no es el que se esta probando.
$CLIENTE $DB -e "DELETE FROM platform_brands WHERE code IN ('p917','p917b');" >/dev/null 2>&1

echo ""
echo "-- Una marca tiene que llamarse de alguna manera --"

porque "una marca con el nombre en blanco" \
  "INSERT INTO platform_brands (uuid,code,name,created_at) VALUES (UUID(),'p917','   ',NOW(3));" \
  "ck_pb_nombre|de alguna manera"

echo ""
echo "-- Los colores acaban en una hoja de estilo --"

porque "un color secundario que no es un color" \
  "INSERT INTO platform_brands (uuid,code,name,secondary_color,created_at)
   VALUES (UUID(),'p917','P','azulito',NOW(3));" \
  "ck_pb_color2|hexadecimal"

porque "y el de la barra tampoco" \
  "INSERT INTO platform_brands (uuid,code,name,sidebar_color,created_at)
   VALUES (UUID(),'p917','P','#12345',NOW(3));" \
  "ck_pb_barra|hexadecimal"

# La que mas importa de las tres. Lo que se guarda aqui se escribe DENTRO de una
# regla CSS y DENTRO de una URL, en todas las pantallas a la vez. Un nombre con
# comillas o con `;` no es una errata: cierra la regla y escribe estilo ajeno.
porque "una tipografia con comillas y llaves" \
  "INSERT INTO platform_brands (uuid,code,name,font_family,created_at)
   VALUES (UUID(),'p917','P','Arial\\'; } body { display:none } .x{',NOW(3));" \
  "ck_pb_tipografia|letras, numeros y espacios"

echo ""
echo "-- El correo y la web son enlaces que alguien va a pulsar --"

porque "un correo de soporte que no es un correo" \
  "INSERT INTO platform_brands (uuid,code,name,support_email,created_at)
   VALUES (UUID(),'p917','P','soporte',NOW(3));" \
  "ck_pb_correo|no parece un correo"

porque "una web sin esquema" \
  "INSERT INTO platform_brands (uuid,code,name,website,created_at)
   VALUES (UUID(),'p917','P','creators.mx',NOW(3));" \
  "ck_pb_web|http"

echo ""
echo "-- El favicon apunta a un archivo que existe --"

porque "un favicon que no esta en files" \
  "INSERT INTO platform_brands (uuid,code,name,favicon_file_id,created_at)
   VALUES (UUID(),'p917','P',999999999,NOW(3));" \
  "fk_pb_favicon|foreign key|FOREIGN KEY"

echo ""
echo "-- Ahora si: la marca buena --"

probar "una marca completa entra" \
  "INSERT INTO platform_brands (uuid,code,name,tagline,legal_footer,primary_color,
      secondary_color,sidebar_color,font_family,website,support_email,is_active,created_at)
   VALUES (UUID(),'p917','Creators MX','Marketing de creadores','Otra Sociedad S.A.',
      '#FF0066','#22D3EE','#101010','Inter','https://creators.mx','soporte@creators.mx',1,NOW(3));" OK

echo ""
echo "-- UNA sola marca por defecto: la columna puerta --"

# La premisa se CONSTRUYE, no se supone. La primera version de esta suite daba
# por hecho que ya habia una marca por defecto sembrada, y contra la base de
# referencia --que esta vacia-- no la habia: el UPDATE que debia chocar contra
# `uq_pb_default` pasaba tranquilamente porque el sitio estaba libre, y el
# siguiente rechazo se apuntaba a otra regla. Dos aserciones midiendo algo que
# no era lo que decian. Es el mismo defecto de 9.14, y por eso aqui se deja
# escrito.
#
# Se aparta la que hubiera --si la hay-- y se restituye al final.
ANTES=$($CLIENTE $DB -sN -e "SELECT COALESCE(MAX(code),'') FROM platform_brands WHERE default_gate = 1;" 2>/dev/null)
$CLIENTE $DB -e "UPDATE platform_brands SET is_default = 0 WHERE default_gate = 1;" >/dev/null 2>&1

probar "p917 pasa a ser la marca por defecto" \
  "UPDATE platform_brands SET is_default = 1 WHERE code = 'p917';" OK

# `default_gate` vale 1 cuando la marca es la de por defecto y NULL cuando no.
# `uq_pb_default` es unica sobre esa columna, y una fila con NULL no colisiona:
# la tecnica de las otras veintisiete puertas del modelo.
valor "y es la unica" \
  "SELECT CASE WHEN COUNT(*) = 1 THEN 'una' ELSE 'no' END FROM platform_brands WHERE default_gate = 1;" "una"

probar "entra una segunda marca, sin ser la de por defecto" \
  "INSERT INTO platform_brands (uuid,code,name,is_active,created_at)
   VALUES (UUID(),'p917b','Otra',1,NOW(3));" OK

porque "pero no puede ser tambien la de por defecto" \
  "UPDATE platform_brands SET is_default = 1 WHERE code = 'p917b';" \
  "uq_pb_default|Duplicate"

# El mismo agujero por el otro lado: `uq_pb_default` impide DOS, y esto impide
# que la unica este desactivada. Sin las dos, el panel se queda sin marca sin
# que nadie haya borrado nada.
porque "ni se puede desactivar la que lo es" \
  "UPDATE platform_brands SET is_active = 0 WHERE code = 'p917';" \
  "ck_pb_defecto_activa|no puede estar desactivada"

echo ""
echo "-- El codigo es la llave del sembrador y no se toca --"

# Si cambia, el siguiente `db:seed` no encuentra la marca y crea otra: el sistema
# amanece con dos, `uq_pb_default` deja a la nueva sin ser la de por defecto, y
# las pantallas siguen ensenando la vieja mientras alguien edita la nueva.
porque "cambiar el codigo de una marca" \
  "UPDATE platform_brands SET code = 'p917b' WHERE code = 'p917';" \
  "tg_pb_code|no se cambia"

probar "pero el nombre visible se cambia cuando se quiera" \
  "UPDATE platform_brands SET name = 'Creators LATAM' WHERE code = 'p917';" OK

valor "y el codigo sigue siendo el mismo" \
  "SELECT CONCAT(code,':',name) FROM platform_brands WHERE code = 'p917';" "p917:Creators LATAM"

echo ""
echo "-- Se limpia lo suyo --"

probar "las marcas de la suite se borran" \
  "DELETE FROM platform_brands WHERE code LIKE 'p917%';" OK

valor "no quedo nada de p917" \
  "SELECT COUNT(*) FROM platform_brands WHERE code LIKE 'p917%';" "0"

# Y se devuelve el sitio a quien lo tenia. Si la base venia vacia --la de
# referencia-- `ANTES` esta vacio y no se toca nada.
[ -n "$ANTES" ] && $CLIENTE $DB -e "UPDATE platform_brands SET is_default = 1 WHERE code = '$ANTES';" >/dev/null 2>&1

valor "la marca por defecto que habia sigue estandolo" \
  "SELECT CASE WHEN '$ANTES' = '' THEN 'ok'
      WHEN EXISTS (SELECT 1 FROM platform_brands WHERE code = '$ANTES' AND default_gate = 1)
      THEN 'ok' ELSE 'no' END;" "ok"

resumen
