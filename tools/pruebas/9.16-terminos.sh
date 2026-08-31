#!/bin/bash
# Pruebas de restriccion de la iteracion 9.16: los terminos, desde el admin.
#
#   ck_terms_publicada         publicar exige decir quien publico
#   ck_terms_borrador_abierto  un borrador no se cierra: nunca estuvo vigente
#   ck_terms_review            estados de revision legal
#   ck_terms_change_type       de fondo o menor, no hay una tercera cosa
#   tg_terms_inmutable         una version publicada no se reescribe
#   tg_terms_inmutable         ni vuelve a ser borrador
#   tg_tver_sin_solape_*       la regla de no solape deja fuera a los borradores
#   uq_terms_versions_current  una sola PUBLICADA vigente por documento
#
# `3.5` decidio que los terminos se publicaran por consola y que sin texto
# publicado no se activara ningun creador. Eso convertia una configuracion en un
# bloqueo. `9.16` los trae al admin, y con ellos la nocion de BORRADOR --que la
# regla de no solape de `3.13` no conocia: al guardar el segundo borrador la base
# contestaba «cierre la anterior el dia antes» sobre algo que ni estaba
# publicado--.
#
# Uso: bash tools/pruebas/9.16-terminos.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.16 - Los terminos, desde el admin"
echo "==================================================================================="

USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"
H="'0000000000000000000000000000000000000000000000000000000000000000'"

valor "hay un usuario en la semilla" \
  "SELECT CASE WHEN COUNT(*) > 0 THEN 'si' ELSE 'no' END FROM users;" "si"

echo ""
echo "-- Publicar es un acto con responsable --"

porque "publicada sin decir quien la publico" \
  "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,
      effective_from,published_at,created_at)
   VALUES (UUID(),'creator','t916','1.0','T','Texto.',$H,'2030-01-01',NOW(3),NOW(3));" \
  "ck_terms_publicada|exige decir quien"

echo ""
echo "-- Un borrador no se cierra: nunca estuvo vigente --"

porque "borrador con fecha de cierre" \
  "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,
      effective_from,effective_to,created_at)
   VALUES (UUID(),'creator','t916','1.0','T','Texto.',$H,'2030-01-01','2030-12-31',NOW(3));" \
  "ck_terms_borrador_abierto|nunca llego a estar vigente"

echo ""
echo "-- Los dos catalogos --"

porque "un estado de revision inventado" \
  "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,
      effective_from,review_status,created_at)
   VALUES (UUID(),'creator','t916','1.0','T','Texto.',$H,'2030-01-01','mas_o_menos',NOW(3));" \
  "ck_terms_review"

porque "y una tercera clase de cambio" \
  "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,
      effective_from,change_type,created_at)
   VALUES (UUID(),'creator','t916','1.0','T','Texto.',$H,'2030-01-01','regular',NOW(3));" \
  "ck_terms_change_type"

echo ""
echo "-- Dos borradores del mismo documento SI caben --"

# Esto es lo que la regla de no solape rechazaba antes de 9.16, y no tenia
# sentido: un borrador no rige, asi que no puede solaparse con nada.
probar "el primer borrador" \
  "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,
      effective_from,created_at)
   VALUES (UUID(),'creator','t916','1.0','T','Texto uno.',$H,'2030-01-01',NOW(3));" OK

probar "y el segundo, con las mismas fechas" \
  "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,
      effective_from,created_at)
   VALUES (UUID(),'creator','t916','1.1','T','Texto dos.',$H,'2030-01-01',NOW(3));" OK

echo ""
echo "-- Publicadas, en cambio, una sola --"

probar "se publica la primera" \
  "UPDATE terms_versions SET published_at=NOW(3), published_by_user_id=$USR
    WHERE code='t916' AND version='1.0';" OK

porque "y la segunda con las mismas fechas, no" \
  "UPDATE terms_versions SET published_at=NOW(3), published_by_user_id=$USR
    WHERE code='t916' AND version='1.1';" \
  "vigente en esas fechas|uq_terms_versions_current"

echo ""
echo "-- Una publicada no se reescribe --"

porque "cambiarle el texto" \
  "UPDATE terms_versions SET body='Otra cosa.' WHERE code='t916' AND version='1.0';" \
  "no se reescribe"

porque "ni la huella" \
  "UPDATE terms_versions SET content_sha256=REPEAT('a',64) WHERE code='t916' AND version='1.0';" \
  "no se reescribe"

porque "ni la fecha de entrada en vigor" \
  "UPDATE terms_versions SET effective_from='2030-02-01' WHERE code='t916' AND version='1.0';" \
  "no se reescribe"

porque "ni volver a ser borrador" \
  "UPDATE terms_versions SET published_at=NULL WHERE code='t916' AND version='1.0';" \
  "no vuelve a ser borrador"

echo ""
echo "-- Lo que SI se puede tocar en una publicada --"

# El estado de revision legal es informacion SOBRE el texto, no el texto: por eso
# se puede marcar despues de publicar, que es como funciona de verdad --primero
# se opera, y el abogado contesta cuando contesta--.
probar "marcarla como revisada por un abogado" \
  "UPDATE terms_versions SET review_status='revisado',
      review_note='Revisado por el estudio X.' WHERE code='t916' AND version='1.0';" OK

probar "y cerrarla cuando llegue la siguiente" \
  "UPDATE terms_versions SET effective_to='2030-06-30' WHERE code='t916' AND version='1.0';" OK

valor "quedo cerrada y revisada" \
  "SELECT CONCAT(review_status,':',effective_to) FROM terms_versions
    WHERE code='t916' AND version='1.0';" "revisado:2030-06-30"

resumen
