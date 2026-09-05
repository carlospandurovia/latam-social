#!/bin/bash
# Pruebas de restriccion de la iteracion L-2a: los datos que se pintan en la calle.
#
#   ck_ss_whatsapp    el numero va en E.164, sin espacios
#   ck_ss_correo      el correo de contacto tiene forma de correo
#   ck_ss_mensaje     un mensaje de WhatsApp de dos letras no es un mensaje
#   uq_ss_marca       una sola fila de ajustes por marca
#   ck_sl_url         un enlace publico va cifrado
#   ck_sl_red         el codigo de red va en minusculas y sin espacios
#   ck_sl_etiqueta    la red necesita nombre para pintarlo y para leerlo en voz alta
#   uq_sl_red         la misma red no entra dos veces
#   fk_ss_pais        el pais por defecto existe (L-5)
#   ck_ss_medidor     el medidor de visitas tiene fragmento que emitir (L-5)
#   ck_ss_medidor_id  su identificador entra DENTRO de un <script> (L-5)
#   ck_ss_medidor_par proveedor e identificador van juntos o no van (L-5)
#
# La que mas importa es `ck_ss_whatsapp`, y no por pulcritud: ese valor viaja
# DENTRO de una URL `https://wa.me/...`. Un espacio o un parentesis la rompen
# **sin dar ningun error** --el enlace no abre nada, o abre WhatsApp sin
# destinatario-- y quien lo pulsa se queda creyendo que ha escrito.
#
# Uso: bash tools/pruebas/L2a-sitio.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  L-2a - Los datos que se pintan en la calle"
echo "==================================================================================="

MARCA="(SELECT id FROM (SELECT id FROM platform_brands ORDER BY id LIMIT 1) m)"

# La suite se limpia sola ANTES de empezar, y no solo al final.
#
# No lo hacia, y se vio corriendola dos veces seguidas sobre la misma base: la
# segunda pasada fallaba con `uq_sl_red` --«una red bien puesta entra» ya no
# entraba, porque estaba de la vez anterior--. En la pasada completa no se nota
# porque `correr-todo.sh` rehace las bases primero, asi que era una suite que
# solo funcionaba dentro de su rutina. Una prueba que no se puede repetir a mano
# es una prueba que nadie repite a mano cuando la necesita.
$CLIENTE $DB -e "DELETE FROM social_links; DELETE FROM site_settings;" >/dev/null 2>&1
SOC="(SELECT id FROM (SELECT id FROM legal_entities ORDER BY id LIMIT 1) s)"

echo ""
echo "-- El WhatsApp: va dentro de una URL --"

probar "se limpia lo que hubiera sembrado" \
  "DELETE FROM site_settings;" OK

probar "un numero en E.164 entra" \
  "INSERT INTO site_settings (platform_brand_id,operator_legal_entity_id,whatsapp_phone,
     whatsapp_message,contact_email,created_at,updated_at)
   VALUES ($MARCA,$SOC,'+51987654321','Hola, quiero hacer una campana con creadores.',
     'hola@latamsocial.com',NOW(3),NOW(3));" OK

porque "pero con espacios no: romperia el enlace sin dar error" \
  "UPDATE site_settings SET whatsapp_phone='+51 987 654 321';" \
  "ck_ss_whatsapp"

porque "ni con guiones" \
  "UPDATE site_settings SET whatsapp_phone='+51-987-654-321';" \
  "ck_ss_whatsapp"

porque "ni sin el prefijo internacional" \
  "UPDATE site_settings SET whatsapp_phone='987654321';" \
  "ck_ss_whatsapp"

probar "y NULL si: no tener WhatsApp es un estado legitimo" \
  "UPDATE site_settings SET whatsapp_phone=NULL;" OK

porque "un mensaje de dos letras no es un mensaje: es un campo a medias" \
  "UPDATE site_settings SET whatsapp_message='ok';" \
  "ck_ss_mensaje"

echo ""
echo "-- El correo publico --"

porque "un correo sin forma de correo no entra" \
  "UPDATE site_settings SET contact_email='hola-arroba-latamsocial';" \
  "ck_ss_correo"

probar "y NULL si, con su aviso rojo en el panel" \
  "UPDATE site_settings SET contact_email=NULL;" OK

porque "dos filas de ajustes para la misma marca serian dos verdades" \
  "INSERT INTO site_settings (platform_brand_id,created_at,updated_at)
   VALUES ($MARCA,NOW(3),NOW(3));" \
  "uq_ss_marca"

echo ""
echo "-- Las redes --"

probar "una red bien puesta entra" \
  "INSERT INTO social_links (platform_brand_id,network,label,url,sort_order,is_visible,created_at,updated_at)
   VALUES ($MARCA,'instagram','Instagram','https://instagram.com/latamsocial',10,1,NOW(3),NOW(3));" OK

porque "un enlace publico sin cifrar no: es la advertencia de 9.17e en otro sitio" \
  "INSERT INTO social_links (platform_brand_id,network,label,url,created_at,updated_at)
   VALUES ($MARCA,'tiktok','TikTok','http://tiktok.com/@latamsocial',NOW(3),NOW(3));" \
  "ck_sl_url"

porque "un codigo de red con mayusculas tampoco: de ahi sale el nombre del icono" \
  "INSERT INTO social_links (platform_brand_id,network,label,url,created_at,updated_at)
   VALUES ($MARCA,'TikTok','TikTok','https://tiktok.com/@latamsocial',NOW(3),NOW(3));" \
  "ck_sl_red"

porque "ni una red sin nombre: un lector de pantalla leeria la URL entera" \
  "INSERT INTO social_links (platform_brand_id,network,label,url,created_at,updated_at)
   VALUES ($MARCA,'tiktok','T','https://tiktok.com/@latamsocial',NOW(3),NOW(3));" \
  "ck_sl_etiqueta"

porque "y la misma red dos veces son dos iconos iguales sin saber cual vale" \
  "INSERT INTO social_links (platform_brand_id,network,label,url,created_at,updated_at)
   VALUES ($MARCA,'instagram','Instagram viejo','https://instagram.com/otro',NOW(3),NOW(3));" \
  "uq_sl_red"

# Un codigo DESCONOCIDO si entra, y es lo que justifica que sea texto libre:
# una red nueva tiene que funcionar el mismo dia, sin migracion y sin
# despliegue. La plantilla le pone un icono de enlace en vez de romperse.
probar "una red que todavia no existe entra igual: el icono sera un eslabon" \
  "INSERT INTO social_links (platform_brand_id,network,label,url,created_at,updated_at)
   VALUES ($MARCA,'red-del-futuro','Red del futuro','https://ejemplo.test/latamsocial',NOW(3),NOW(3));" OK

echo ""
echo "-- L-5: el pais por defecto y la medicion --"

PAIS=$($CLIENTE $DB -sN -e "SELECT id FROM countries LIMIT 1;" 2>/dev/null | tr -d '\r')

probar "un pais por defecto entra" \
  "UPDATE site_settings SET default_country_id=$PAIS;" OK

porque "pero uno que no existe no: el formulario marcaria una opcion inexistente" \
  "UPDATE site_settings SET default_country_id=999999;" \
  "fk_ss_pais|foreign key|FOREIGN KEY"

probar "vacio si: entonces rige el de la sociedad operadora" \
  "UPDATE site_settings SET default_country_id=NULL;" OK

probar "un medidor con su identificador entra" \
  "UPDATE site_settings SET analytics_provider='ga4', analytics_id='G-ABC123';" OK

porque "un medidor que no existe no tiene fragmento que emitir" \
  "UPDATE site_settings SET analytics_provider='mimedidor', analytics_id='X1';" \
  "ck_ss_medidor|no existe"

# fixture-invalido-a-proposito
porque "un identificador con comilla es una INYECCION: ese valor entra dentro de un <script>" \
  "UPDATE site_settings SET analytics_provider='ga4', analytics_id=\"G-1';alert(1)\";" \
  "ck_ss_medidor_id|letras, numeros"

porque "ni con un espacio" \
  "UPDATE site_settings SET analytics_provider='ga4', analytics_id='G ABC';" \
  "ck_ss_medidor_id|letras, numeros"

porque "un proveedor sin identificador no mide nada" \
  "UPDATE site_settings SET analytics_provider='ga4', analytics_id=NULL;" \
  "ck_ss_medidor_par|uno solo no mide"

porque "y un identificador sin proveedor no lo lee nadie" \
  "UPDATE site_settings SET analytics_provider=NULL, analytics_id='G-ABC123';" \
  "ck_ss_medidor_par|uno solo no mide"

probar "los dos vacios si: es «sin medicion»" \
  "UPDATE site_settings SET analytics_provider=NULL, analytics_id=NULL;" OK

resumen
