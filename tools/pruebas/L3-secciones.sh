#!/bin/bash
# Pruebas de restriccion de la iteracion L-3: las franjas de la portada.
#
#   ck_ls_code      el ancla va en minusculas, sin espacios ni acentos
#   ck_ls_layout    la forma de pintar es una de las que tienen parcial
#   ck_ls_menu      para salir en el menu, la franja necesita encabezado
#   ck_ls_url       el enlace del boton va con https, ruta propia o ancla
#   ck_ls_cta       un boton que lleva a algun sitio necesita rotulo
#   uq_ls_code      la misma ancla no entra dos veces en la misma pagina
#   ck_lb_icono     el nombre del icono va en minusculas
#   ck_lb_url       el enlace de un bloque, lo mismo
#   ck_lb_cta       y su rotulo, lo mismo
#   fk_lb_section   un bloque sin franja no existe: la columna es NOT NULL
#   ck_lp_form      un encabezado de dos letras sobre el formulario no es un titulo (L-4)
#
# La que mas importa es `ck_ls_code`, y por lo mismo que `ck_ss_whatsapp` en la
# `L-2a`: ese valor viaja DENTRO de una URL --`/#como-funciona`--. Un ancla con
# una mayuscula o un espacio no da ningun error: el enlace del menu simplemente
# no hace nada al pulsarlo, y quien lo pulsa cree que la pagina esta rota.
#
# Y `COLLATE utf8mb4_bin` no es pulcritud: el cotejo por defecto es
# CASE-INSENSITIVE, asi que `^[a-z0-9-]+$` acepta alegremente `Como-Funciona`.
# Se aprendio en la `L-2a` con `ck_sl_red`, y ninguna prueba de PHP lo ve.
#
# Uso: bash tools/pruebas/L3-secciones.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  L-3 - Las franjas de la portada"
echo "==================================================================================="

# Se limpia lo de una corrida anterior. Una franja de prueba SI se borra: es
# texto de marketing y no sostiene ninguna cifra ni ninguna firma.
$CLIENTE $DB -e "DELETE lb FROM landing_blocks lb
                 JOIN landing_sections ls ON ls.id=lb.landing_section_id
                  WHERE ls.code LIKE 'xl3%';
                 DELETE FROM landing_sections WHERE code LIKE 'xl3%';" >/dev/null 2>&1

PAGINA=$($CLIENTE $DB -sN -e "SELECT id FROM landing_pages WHERE code='marcas' LIMIT 1;" 2>/dev/null | tr -d '\r')

echo ""
echo "-- El ancla: va dentro de una URL --"

probar "un ancla en minusculas y con guiones entra" \
  "INSERT INTO landing_sections (landing_page_id,code,layout,title,sort_order,is_visible,created_at)
   VALUES ($PAGINA,'xl3-como-funciona','cards','Como funciona',910,1,NOW(3));" OK

porque "pero con una mayuscula no: el cotejo por defecto no distingue, por eso va COLLATE utf8mb4_bin" \
  "INSERT INTO landing_sections (landing_page_id,code,layout,sort_order,is_visible,created_at)
   VALUES ($PAGINA,'XL3-Como','cards',911,1,NOW(3));" \
  "ck_ls_code|minusculas"

porque "ni con un espacio" \
  "INSERT INTO landing_sections (landing_page_id,code,layout,sort_order,is_visible,created_at)
   VALUES ($PAGINA,'xl3 como','cards',912,1,NOW(3));" \
  "ck_ls_code|minusculas"

porque "ni con un acento: el navegador lo escapa y el ancla deja de coincidir" \
  "INSERT INTO landing_sections (landing_page_id,code,layout,sort_order,is_visible,created_at)
   VALUES ($PAGINA,'xl3-como-está','cards',913,1,NOW(3));" \
  "ck_ls_code|minusculas"

porque "ni empezando por guion" \
  "INSERT INTO landing_sections (landing_page_id,code,layout,sort_order,is_visible,created_at)
   VALUES ($PAGINA,'-xl3','cards',914,1,NOW(3));" \
  "ck_ls_code|minusculas"

porque "y la misma ancla no entra dos veces en la misma pagina" \
  "INSERT INTO landing_sections (landing_page_id,code,layout,sort_order,is_visible,created_at)
   VALUES ($PAGINA,'xl3-como-funciona','cards',915,1,NOW(3));" \
  "uq_ls_code"

echo ""
echo "-- La forma de pintar: cada valor tiene su parcial --"

porque "una forma inventada seria una fila valida que ninguna plantilla sabe dibujar" \
  "UPDATE landing_sections SET layout='carrusel' WHERE code='xl3-como-funciona';" \
  "ck_ls_layout"

for L in cards steps faq claim plain; do
  probar "la forma «$L» entra" \
    "UPDATE landing_sections SET layout='$L' WHERE code='xl3-como-funciona';" OK
done

echo ""
echo "-- El menu: una entrada sin nombre es un enlace en blanco --"

porque "en el menu y sin encabezado" \
  "UPDATE landing_sections SET title=NULL, show_in_nav=1 WHERE code='xl3-como-funciona';" \
  "ck_ls_menu|encabezado"

probar "fuera del menu si puede no tener encabezado: hay franjas que no llevan" \
  "UPDATE landing_sections SET title=NULL, show_in_nav=0 WHERE code='xl3-como-funciona';" OK

probar "y con encabezado vuelve al menu" \
  "UPDATE landing_sections SET title='Como funciona', show_in_nav=1 WHERE code='xl3-como-funciona';" OK

echo ""
echo "-- El boton de la franja --"

porque "un http:// en la portada es un aviso del navegador delante de quien todavia no es cliente" \
  "UPDATE landing_sections SET cta_label='Hablemos', cta_url='http://ejemplo.com'
   WHERE code='xl3-como-funciona';" \
  "ck_ls_url"

probar "https si" \
  "UPDATE landing_sections SET cta_label='Hablemos', cta_url='https://ejemplo.com'
   WHERE code='xl3-como-funciona';" OK

probar "una ruta propia tambien" \
  "UPDATE landing_sections SET cta_url='/creadores' WHERE code='xl3-como-funciona';" OK

probar "y un ancla, que es el CTA intermedio" \
  "UPDATE landing_sections SET cta_url='#empezar' WHERE code='xl3-como-funciona';" OK

porque "pero un boton que lleva a algun sitio y no dice nada, no" \
  "UPDATE landing_sections SET cta_label=NULL, cta_url='#empezar' WHERE code='xl3-como-funciona';" \
  "ck_ls_cta|rotulo"

probar "sin enlace si puede no tener rotulo: es una franja sin boton" \
  "UPDATE landing_sections SET cta_label=NULL, cta_url=NULL WHERE code='xl3-como-funciona';" OK

echo ""
echo "-- El bloque, dentro de su franja --"

SECCION=$($CLIENTE $DB -sN -e "SELECT id FROM landing_sections WHERE code='xl3-como-funciona' LIMIT 1;" 2>/dev/null | tr -d '\r')

probar "un bloque con icono conocido entra" \
  "INSERT INTO landing_blocks (landing_section_id,heading,body,icon,sort_order,is_visible,created_at)
   VALUES ($SECCION,'XL3 bloque','Con su texto.','verificado',900,1,NOW(3));" OK

# fixture-invalido-a-proposito
porque "un icono con mayusculas no: el nombre acaba en un @switch que compara en minusculas" \
  "UPDATE landing_blocks SET icon='Verificado' WHERE heading='XL3 bloque';" \
  "ck_lb_icono|minusculas"

probar "sin icono si: no todos los bloques llevan" \
  "UPDATE landing_blocks SET icon=NULL WHERE heading='XL3 bloque';" OK

porque "y el enlace del bloque sigue la misma regla que el de la franja" \
  "UPDATE landing_blocks SET cta_label='Ver', cta_url='http://ejemplo.com' WHERE heading='XL3 bloque';" \
  "ck_lb_url"

porque "y su rotulo tambien" \
  "UPDATE landing_blocks SET cta_label=NULL, cta_url='#empezar' WHERE heading='XL3 bloque';" \
  "ck_lb_cta|rotulo"

# fixture-invalido-a-proposito
porque "un bloque sin franja no existe: se perderia sin que nadie lo eche de menos" \
  "INSERT INTO landing_blocks (landing_section_id,heading,created_at)
   VALUES (NULL,'XL3 huerfano',NOW(3));" \
  "landing_section_id|null|NULL"

porque "ni colgando de una franja que no existe" \
  "INSERT INTO landing_blocks (landing_section_id,heading,created_at)
   VALUES (999999,'XL3 huerfano',NOW(3));" \
  "fk_lb_section|foreign key|FOREIGN KEY"

echo ""
echo "-- La franja se puede quitar, que es la diferencia con todo lo demas --"

probar "primero sus bloques" \
  "DELETE FROM landing_blocks WHERE landing_section_id=$SECCION;" OK

probar "y luego ella" \
  "DELETE FROM landing_sections WHERE id=$SECCION;" OK

echo ""
echo "-- L-4: el encabezado del cierre --"

porque "un encabezado de dos letras sobre el formulario es un hueco con aspecto de titulo" \
  "UPDATE landing_pages SET form_heading='Ok' WHERE code='marcas';" \
  "ck_lp_form|tiene que decir algo"

probar "vacio si: entonces no se pinta encabezado y se usa el texto del boton" \
  "UPDATE landing_pages SET form_heading=NULL WHERE code='marcas';" OK

probar "y uno de verdad entra" \
  "UPDATE landing_pages SET form_heading='Hablemos de tu proxima campana.' WHERE code='marcas';" OK

resumen
