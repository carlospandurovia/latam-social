#!/bin/bash
# Pruebas de restriccion de la iteracion 9.21b: la portada publica.
#
#   ck_lp_code       solo hay dos portadas, y las dos las sirve una ruta
#   ck_lp_titular    una portada publicada sin titular es una pagina en blanco
#   ck_lp_boton      el boton necesita un texto
#   ck_lp_url        https o ruta propia: un http en la portada es un aviso del
#                    navegador delante de alguien que todavia no es cliente
#   uq_lp_code       una sola portada de cada clase por marca
#   ck_lb_kind       tres formas de bloque, no treinta: cada una es codigo
#   ck_lb_heading    un bloque sin titulo no se puede leer
#
# Uso: bash tools/pruebas/9.21b-landing.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.21b - La portada publica"
echo "==================================================================================="

MARCA=$($CLIENTE $DB -sN -e "SELECT id FROM platform_brands ORDER BY id LIMIT 1;" 2>/dev/null | tr -d '\r')

if [ -z "${MARCA:-}" ]; then
  echo "  La premisa no se cumple: no hay marca de plataforma en la semilla."
  exit 1
fi

# La suite se limpia sola: una portada de prueba SI se puede borrar --es texto de
# marketing, no sostiene ninguna cifra-- y por eso aqui no hay `no_delete`.
$CLIENTE $DB -e "DELETE lb FROM landing_blocks lb JOIN landing_pages lp ON lp.id=lb.landing_page_id
                  WHERE lp.headline LIKE 'X921B%';
                 DELETE FROM landing_pages WHERE headline LIKE 'X921B%';" >/dev/null 2>&1

echo ""
echo "-- La portada --"

porque "una tercera clase de portada" \
  "INSERT INTO landing_pages (platform_brand_id,code,headline,cta_label,created_at)
   VALUES ($MARCA,'inversores','X921B titular suficientemente largo','Vale',NOW(3));" \
  "ck_lp_code|dos portadas"

porque "un titular de tres letras" \
  "INSERT INTO landing_pages (platform_brand_id,code,headline,cta_label,created_at)
   VALUES ($MARCA,'marcas','X921B','Vale',NOW(3));" \
  "ck_lp_titular|decir algo"

porque "un boton sin texto" \
  "INSERT INTO landing_pages (platform_brand_id,code,headline,cta_label,created_at)
   VALUES ($MARCA,'marcas','X921B titular suficientemente largo',' ',NOW(3));" \
  "ck_lp_boton|texto"

porque "un enlace por http" \
  "INSERT INTO landing_pages (platform_brand_id,code,headline,cta_label,cta_url,created_at)
   VALUES ($MARCA,'marcas','X921B titular suficientemente largo','Vale','http://ejemplo.pe',NOW(3));" \
  "ck_lp_url|https"

porque "la portada de marcas, dos veces para la misma marca" \
  "INSERT INTO landing_pages (platform_brand_id,code,headline,cta_label,created_at)
   VALUES ($MARCA,'marcas','X921B titular suficientemente largo','Vale',NOW(3));" \
  "uq_lp_code"

# La de creadores ya existe por la semilla, asi que para probar los bloques hace
# falta una portada propia. Se usa la sembrada: es lo que hay en produccion.
PAGINA=$($CLIENTE $DB -sN -e "SELECT id FROM landing_pages WHERE code='creadores' LIMIT 1;" 2>/dev/null | tr -d '\r')

echo ""
echo "-- Los bloques --"

porque "un tipo de bloque que no existe" \
  "INSERT INTO landing_blocks (landing_page_id,kind,heading,created_at)
   VALUES ($PAGINA,'carrusel','X921B bloque',NOW(3));" \
  "ck_lb_kind"

porque "un bloque sin titulo" \
  "INSERT INTO landing_blocks (landing_page_id,kind,heading,created_at)
   VALUES ($PAGINA,'feature','  ',NOW(3));" \
  "ck_lb_heading|no se puede leer"

probar "y uno bien formado entra" \
  "INSERT INTO landing_blocks (landing_page_id,kind,heading,body,sort_order,created_at)
   VALUES ($PAGINA,'faq','X921B pregunta de prueba','Con su respuesta.',900,NOW(3));" OK

# Se borra: es texto, no evidencia. La diferencia esta escrita en el servicio.
probar "y se puede quitar, que es la diferencia con todo lo demas" \
  "DELETE FROM landing_blocks WHERE heading='X921B pregunta de prueba';" OK

resumen
