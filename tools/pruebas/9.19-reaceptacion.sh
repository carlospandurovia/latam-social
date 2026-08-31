#!/bin/bash
# Pruebas de restriccion de la iteracion 9.19: los plazos de reaceptacion.
#
#   ck_terms_plazo       cero dias para aceptar es publicar y bloquear a la vez
#   ck_terms_lectura     los dias de solo lectura no pueden ser negativos
#   tg_terms_inmutable   y los plazos NO se cambian despues de publicar
#
# La tercera es la que importa. «Tienes 15 dias» dicho en enero no puede
# convertirse en «tenias 10» porque en marzo alguien retoco un numero: el plazo
# es parte de lo que se le comunico a la gente, no un ajuste. Por eso los dos
# campos entran en la inmutabilidad de 9.16 en vez de quedarse fuera, que es lo
# que habria pasado si nadie lo hubiera pensado.
#
# Uso: bash tools/pruebas/9.19-reaceptacion.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.19 - Los plazos para volver a aceptar"
echo "==================================================================================="

H="'0000000000000000000000000000000000000000000000000000000000000000'"
USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"

valor "hay un usuario en la semilla" \
  "SELECT CASE WHEN COUNT(*) > 0 THEN 'si' ELSE 'no' END FROM users;" "si"

# La suite escribe versiones de un documento PROPIO (`t919`) para no tocar los
# terminos de creador que usan las demas suites. No se limpia: `terms_versions`
# no admite borrado desde 3.12.
echo ""
echo "-- Los dos limites --"

porque "cero dias para aceptar" \
  "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,
      effective_from,acceptance_days,created_at)
   VALUES (UUID(),'creator','t919','1.0','T','Texto.',$H,'2031-01-01',0,NOW(3));" \
  "ck_terms_plazo|un dia como minimo"

# Cero dias de SOLO LECTURA si vale: significa «pasado el plazo, a aceptar». Se
# afirma que ENTRA, porque un limite que rechaza lo que deberia admitir es tan
# defecto como uno que admite lo que deberia rechazar.
probar "cero dias de solo lectura si valen: es una eleccion" \
  "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,
      effective_from,acceptance_days,readonly_days,published_at,published_by_user_id,created_at)
   VALUES (UUID(),'creator','t919','1.0','T','Texto.',$H,'2031-01-01',15,0,NOW(3),$USR,NOW(3));" OK

valor "y quedan guardados los dos plazos" \
  "SELECT CONCAT(acceptance_days,'/',readonly_days) FROM terms_versions
    WHERE code='t919' AND version='1.0';" "15/0"

echo ""
echo "-- Publicada, los plazos ya no se tocan --"

# Es la asercion de la iteracion. Sin ella, los dos campos habrian quedado
# FUERA de `tg_terms_inmutable` --el disparador de 9.16 no los conocia-- y se
# habrian podido cambiar despues de comunicarlos.
porque "acortar el plazo despues de publicar" \
  "UPDATE terms_versions SET acceptance_days=5 WHERE code='t919' AND version='1.0';" \
  "tg_terms_inmutable|no se reescribe"

porque "y alargar el de solo lectura tampoco" \
  "UPDATE terms_versions SET readonly_days=90 WHERE code='t919' AND version='1.0';" \
  "tg_terms_inmutable|no se reescribe"

# Lo que SI se puede seguir tocando de una publicada, para que quede claro que
# la inmutabilidad no se ha comido lo que 9.16 dejaba abierto a proposito.
probar "el estado de revision legal se sigue pudiendo marcar" \
  "UPDATE terms_versions SET review_status='revisado' WHERE code='t919' AND version='1.0';" OK

echo ""
echo "-- Un borrador si se edita: todavia no se le ha dicho a nadie --"

probar "un borrador con plazos propios entra" \
  "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,
      effective_from,acceptance_days,readonly_days,created_at)
   VALUES (UUID(),'creator','t919','2.0','T','Texto.',$H,'2031-06-01',7,45,NOW(3));" OK

probar "y se le cambian los plazos mientras siga sin publicar" \
  "UPDATE terms_versions SET acceptance_days=20, readonly_days=10
    WHERE code='t919' AND version='2.0' AND published_at IS NULL;" OK

valor "el borrador quedo con los plazos nuevos" \
  "SELECT CONCAT(acceptance_days,'/',readonly_days) FROM terms_versions
    WHERE code='t919' AND version='2.0';" "20/10"

valor "y la publicada sigue con los suyos" \
  "SELECT CONCAT(acceptance_days,'/',readonly_days) FROM terms_versions
    WHERE code='t919' AND version='1.0';" "15/0"

resumen
