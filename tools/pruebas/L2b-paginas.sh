#!/bin/bash
# Pruebas de restriccion de la iteracion L-2b: las paginas del sitio.
#
#   ck_cp_slug              la direccion va en minusculas, con guiones
#   ck_cp_titulo            una pagina necesita titulo
#   uq_cp_slug              dos paginas no pueden ocupar la misma direccion
#   ck_cpv_cuerpo           un documento legal vacio no es un documento legal
#   ck_cpv_huella           la huella del texto mide 64
#   ck_cpv_publicada        publicar es un acto CON RESPONSABLE
#   ck_cpv_borrador_abierto un borrador no se cierra: nunca estuvo vigente
#   ck_cpv_fechas           una version no se cierra antes de empezar
#   ck_cpv_revision         sin_revisar | en_revision | revisado
#   uq_cpv_vigente          UNA vigente por pagina (columna puerta 36)
#   cpv_sin_solape          y el historico no se solapa: dos respuestas es ninguna
#   uq_cpv_version          no dos veces la misma version
#   tg_cpv_inmutable        una version publicada no se reescribe
#
# Las dos que mas pesan son `uq_cpv_vigente` y `tg_cpv_inmutable`. La primera
# porque dos versiones publicadas y abiertas a la vez dejan sin respuesta la
# pregunta «.que politica de privacidad rige?»; la segunda porque el texto
# publicado es el que alguien pudo haber leido el dia que nos dio sus datos, y
# corregirlo por debajo seria cambiar la respuesta a una pregunta ya hecha.
#
# Uso: bash tools/pruebas/L2b-paginas.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  L-2b - Las paginas del sitio"
echo "==================================================================================="

MARCA="(SELECT id FROM (SELECT id FROM platform_brands ORDER BY id LIMIT 1) m)"
USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"
H1="'$(printf 'a%.0s' {1..64})'"
H2="'$(printf 'b%.0s' {1..64})'"
CUERPO="'Un texto suficientemente largo para ser un documento.'"

echo ""
echo "-- La direccion --"

probar "una pagina bien puesta entra" \
  "INSERT INTO content_pages (uuid,platform_brand_id,slug,title,show_in_footer,sort_order,is_system,created_at,updated_at)
   VALUES (UUID(),$MARCA,'aviso-legal','Aviso legal',1,30,0,NOW(3),NOW(3));" OK

porque "una direccion con mayusculas no: la URL saldria con mayuscula" \
  "INSERT INTO content_pages (uuid,platform_brand_id,slug,title,created_at,updated_at)
   VALUES (UUID(),$MARCA,'Aviso-Legal','Otro',NOW(3),NOW(3));" \
  "ck_cp_slug"

porque "ni con espacios" \
  "INSERT INTO content_pages (uuid,platform_brand_id,slug,title,created_at,updated_at)
   VALUES (UUID(),$MARCA,'aviso legal','Otro',NOW(3),NOW(3));" \
  "ck_cp_slug"

porque "ni una pagina sin titulo" \
  "INSERT INTO content_pages (uuid,platform_brand_id,slug,title,created_at,updated_at)
   VALUES (UUID(),$MARCA,'otra','A',NOW(3),NOW(3));" \
  "ck_cp_titulo"

porque "y dos paginas no pueden ocupar la misma direccion" \
  "INSERT INTO content_pages (uuid,platform_brand_id,slug,title,created_at,updated_at)
   VALUES (UUID(),$MARCA,'aviso-legal','Repetida',NOW(3),NOW(3));" \
  "uq_cp_slug"

PAG="(SELECT id FROM (SELECT id FROM content_pages WHERE slug='aviso-legal') p)"

echo ""
echo "-- Publicar es un acto con responsable --"

probar "un borrador entra sin publicar ni responsable" \
  "INSERT INTO content_page_versions (uuid,content_page_id,version,body_markdown,content_sha256,
     effective_from,review_status,created_at,updated_at)
   VALUES (UUID(),$PAG,'1.0',$CUERPO,$H1,CURDATE(),'sin_revisar',NOW(3),NOW(3));" OK

porque "un cuerpo practicamente vacio no es un documento legal" \
  "INSERT INTO content_page_versions (uuid,content_page_id,version,body_markdown,content_sha256,
     effective_from,created_at,updated_at)
   VALUES (UUID(),$PAG,'9.9','corto',$H2,CURDATE(),NOW(3),NOW(3));" \
  "ck_cpv_cuerpo"

porque "una huella que no mide 64 no es un SHA-256" \
  "INSERT INTO content_page_versions (uuid,content_page_id,version,body_markdown,content_sha256,
     effective_from,created_at,updated_at)
   VALUES (UUID(),$PAG,'8.8',$CUERPO,'corta',CURDATE(),NOW(3),NOW(3));" \
  "ck_cpv_huella"

porque "publicar SIN decir quien no se puede" \
  "UPDATE content_page_versions SET published_at=NOW(3) WHERE content_page_id=$PAG;" \
  "ck_cpv_publicada"

porque "y un borrador no se cierra: nunca estuvo vigente" \
  "UPDATE content_page_versions SET effective_to=CURDATE() WHERE content_page_id=$PAG;" \
  "ck_cpv_borrador_abierto"

probar "publicar con responsable si" \
  "UPDATE content_page_versions SET published_at=NOW(3), published_by_user_id=$USR
   WHERE content_page_id=$PAG;" OK

porque "una version no se cierra antes de empezar" \
  "UPDATE content_page_versions SET effective_to=DATE_SUB(CURDATE(), INTERVAL 5 DAY)
   WHERE content_page_id=$PAG;" \
  "ck_cpv_fechas"

echo ""
echo "-- Una sola vigente, y el texto publicado no se toca --"

# Aqui saltan DOS reglas y contesta la primera: `cpv_sin_solape` mira las fechas
# --y dos versiones abiertas se solapan siempre, porque abierta llega hasta
# 9999-12-31-- asi que su disparador dispara antes de que el indice unico llegue
# a mirar. Se afirma el mensaje que REALMENTE sale, que es el que va a leer una
# persona.
#
# `uq_cpv_vigente` no sobra por eso: un disparador LEE OTRAS FILAS, y dos
# transacciones simultaneas pueden leer las dos antes de que ninguna escriba. El
# indice unico es lo unico que aguanta esa carrera. Es el mismo criterio de las
# 35 columnas puerta anteriores.
porque "una segunda version publicada y abierta dejaria sin respuesta «.cual rige?»" \
  "INSERT INTO content_page_versions (uuid,content_page_id,version,body_markdown,content_sha256,
     effective_from,published_at,published_by_user_id,created_at,updated_at)
   VALUES (UUID(),$PAG,'1.1',$CUERPO,$H2,CURDATE(),NOW(3),$USR,NOW(3),NOW(3));" \
  "Ya hay una version de esa pagina vigente en esas fechas"

probar "se cierra la primera y entonces si entra la siguiente" \
  "UPDATE content_page_versions SET effective_to=CURDATE() WHERE content_page_id=$PAG;" OK

probar "la segunda version, ya vigente" \
  "INSERT INTO content_page_versions (uuid,content_page_id,version,body_markdown,content_sha256,
     effective_from,published_at,published_by_user_id,created_at,updated_at)
   VALUES (UUID(),$PAG,'1.1',$CUERPO,$H2,DATE_ADD(CURDATE(), INTERVAL 1 DAY),NOW(3),$USR,NOW(3),NOW(3));" OK

# El disparador de solape tiene DOS mitades --alta y modificacion-- y las dos
# hacen falta: sin la de modificacion, dos versiones que entran sin solaparse se
# pueden solapar despues moviendoles la fecha, que es lo que hace quien corrige
# un dato a mano.
porque "y mover la fecha de una publicada para taparse con otra, tampoco" \
  "UPDATE content_page_versions SET effective_from=CURDATE()
   WHERE content_page_id=$PAG AND version='1.1';" \
  "Ya hay una version de esa pagina vigente en esas fechas"

porque "y no dos veces la misma version" \
  "INSERT INTO content_page_versions (uuid,content_page_id,version,body_markdown,content_sha256,
     effective_from,created_at,updated_at)
   VALUES (UUID(),$PAG,'1.1',$CUERPO,$H1,CURDATE(),NOW(3),NOW(3));" \
  "uq_cpv_version"

porque "reescribir el texto de una publicada, no: es lo que alguien pudo leer" \
  "UPDATE content_page_versions SET body_markdown='Otra cosa completamente distinta.'
   WHERE content_page_id=$PAG AND published_at IS NOT NULL;" \
  "Una version publicada no se reescribe"

# La revision juridica SI se anota sobre una publicada: el disparador protege el
# TEXTO, no el estado de la revision. Al reves seria absurdo --un abogado revisa
# justamente lo que ya esta publicado--.
probar "pero la revision juridica si se anota sobre una publicada" \
  "UPDATE content_page_versions SET review_status='revisado'
   WHERE content_page_id=$PAG AND published_at IS NOT NULL;" OK

porque "con un estado de revision que exista" \
  "UPDATE content_page_versions SET review_status='mas_o_menos' WHERE content_page_id=$PAG;" \
  "ck_cpv_revision"

probar "se limpia lo de esta suite" \
  "DELETE FROM content_page_versions WHERE content_page_id=$PAG;" OK

probar "y la pagina" \
  "DELETE FROM content_pages WHERE slug='aviso-legal';" OK

resumen
