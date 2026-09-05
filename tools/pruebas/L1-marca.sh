#!/bin/bash
# Pruebas de restriccion de la iteracion L-1: la marca de verdad.
#
#   ck_pb_degradado           el primer color del degradado va en #RRGGBB
#   ck_pb_angulo              el angulo va entre 0 y 359
#   ck_pb_tipografia_titulos  la tipografia de titulares no admite comillas
#
# La ultima no es de forma: `display_font_family` acaba DENTRO de un `<style>`
# --`--font-display: 'X', ...`-- y dentro de una URL al servidor de fuentes. Un
# nombre con una comilla o un `;` escribe CSS ajeno en TODAS las pantallas del
# sistema, la de acceso incluida. Es una inyeccion, no una errata.
#
# Uso: bash tools/pruebas/L1-marca.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  L-1 - La marca de verdad"
echo "==================================================================================="

echo ""
echo "-- El degradado --"

probar "los tres colores aprobados entran" \
  "UPDATE platform_brands SET gradient_from='#FF7447', secondary_color='#D73382',
     primary_color='#6635D8', gradient_angle=45;" OK

porque "un color que no es hexadecimal no entra" \
  "UPDATE platform_brands SET gradient_from='naranja';" \
  "ck_pb_degradado"

probar "y NULL si: un degradado de dos colores sigue siendo legitimo" \
  "UPDATE platform_brands SET gradient_from=NULL;" OK

porque "un angulo de 400 grados no existe" \
  "UPDATE platform_brands SET gradient_angle=400;" \
  "ck_pb_angulo"

probar "359 si, que es el ultimo" \
  "UPDATE platform_brands SET gradient_angle=359;" OK

echo ""
echo "-- La tipografia de titulares acaba dentro de un <style> --"

probar "un nombre de familia normal entra" \
  "UPDATE platform_brands SET display_font_family='Sora';" OK

porque "uno con comilla no: escribiria CSS ajeno en todas las pantallas" \
  "UPDATE platform_brands SET display_font_family='Sora\'; color:red; --';" \
  "ck_pb_tipografia_titulos"

porque "ni uno con punto y coma" \
  "UPDATE platform_brands SET display_font_family='Sora;';" \
  "ck_pb_tipografia_titulos"

probar "y NULL si: entonces manda la tipografia de la interfaz" \
  "UPDATE platform_brands SET display_font_family=NULL;" OK

# Se deja como estaba, que es lo aprobado: las suites de despues leen esta fila.
probar "se restaura la marca aprobada" \
  "UPDATE platform_brands SET gradient_from='#FF7447', secondary_color='#D73382',
     primary_color='#6635D8', gradient_angle=45, display_font_family='Sora';" OK

resumen
