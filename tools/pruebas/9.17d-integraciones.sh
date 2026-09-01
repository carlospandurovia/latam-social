#!/bin/bash
# Pruebas de restriccion de la iteracion 9.17d: las credenciales de cada API.
#
#   ck_iprov_purpose      el catalogo de propositos
#   ck_iconn_env          sandbox o production: la barrera de DEC-029
#   ck_iconn_status       borrador, activa o desactivada
#   ck_iconn_url          una conexion ACTIVA necesita una URL https
#   uq_iconn_active       UNA sola activa por (proveedor, entorno, sociedad)
#   ck_icred_kind         la clase de credencial
#   ck_icred_cipher       un cifrado vacio no es una credencial
#   ck_icred_revocada     media revocacion no vale
#   uq_icred_vigente      UNA sola credencial viva por (conexion, clase)
#   tg_icred_inmutable    una credencial no se reescribe: se revoca
#
# Las dos que importan son las puertas. Con dos conexiones activas del mismo
# proveedor, resolver «con que se factura» tendria un empate; con dos
# credenciales vivas de la misma clase, la mitad de las llamadas iria con una y
# la otra mitad con otra, y el sintoma seria un proveedor rechazando una de cada
# dos peticiones sin patron aparente.
#
# Uso: bash tools/pruebas/9.17d-integraciones.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.17d - Las credenciales de cada API"
echo "==================================================================================="

$CLIENTE $DB -e "DELETE FROM integration_credentials WHERE integration_connection_id IN
                   (SELECT id FROM (SELECT id FROM integration_connections WHERE name LIKE 'X917D%') t);
                 DELETE FROM integration_connections WHERE name LIKE 'X917D%';
                 DELETE FROM integration_providers WHERE code LIKE 'x917d%';" >/dev/null 2>&1

USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"

valor "hay un usuario en la semilla" \
  "SELECT CASE WHEN COUNT(*) > 0 THEN 'si' ELSE 'no' END FROM users;" "si"

echo ""
echo "-- El catalogo de proveedores --"

porque "un proposito inventado" \
  "INSERT INTO integration_providers (code,name,purpose,created_at)
   VALUES ('x917d','Proveedor de prueba','contabilidad',NOW(3));" \
  "ck_iprov_purpose|Proposito de integracion"

# 9.17e: el proveedor de esta suite pasa a ser de CORREO, y es a proposito.
# Desde `tg_iconn_activa_*`, un proveedor de facturacion activo exige sociedad
# --lleva su RUC-- y las aserciones de aqui abajo necesitan justamente el caso
# contrario: una conexion de TODA LA PLATAFORMA, que es lo que prueba que
# `uq_iconn_active` usa `COALESCE(legal_entity_id, 0)`. La regla del emisor
# electronico tiene su propia suite en `9.17e`.
probar "un proveedor de correo entra" \
  "INSERT INTO integration_providers (code,name,purpose,created_at)
   VALUES ('x917d','Proveedor de prueba','email',NOW(3));" OK

PROV="(SELECT id FROM (SELECT id FROM integration_providers WHERE code='x917d') p)"

echo ""
echo "-- La conexion --"

porque "un entorno que no es ni pruebas ni produccion" \
  "INSERT INTO integration_connections (uuid,integration_provider_id,name,environment,created_at)
   VALUES (UUID(),$PROV,'X917D-A','preproduccion',NOW(3));" \
  "ck_iconn_env|sandbox o production"

porque "un estado inventado" \
  "INSERT INTO integration_connections (uuid,integration_provider_id,name,status,created_at)
   VALUES (UUID(),$PROV,'X917D-A','encendida',NOW(3));" \
  "ck_iconn_status|Estado de conexion"

# Un borrador SI puede estar sin URL: un borrador es justamente el sitio donde
# todavia faltan cosas. Se afirma que entra, porque un limite que rechaza lo que
# deberia admitir es tan defecto como uno que admite lo que deberia rechazar.
probar "un borrador sin URL entra: para eso es un borrador" \
  "INSERT INTO integration_connections (uuid,integration_provider_id,name,environment,status,created_at)
   VALUES (UUID(),$PROV,'X917D-A','production','draft',NOW(3));" OK

porque "pero activarla sin URL, no" \
  "UPDATE integration_connections SET status='active' WHERE name='X917D-A';" \
  "tg_iconn_activa_upd|no sabe a donde llamar"

porque "ni con una URL sin cifrar" \
  "UPDATE integration_connections SET status='active', base_url='http://api.ejemplo.com'
    WHERE name='X917D-A';" \
  "tg_iconn_activa_upd|no sabe a donde llamar"

probar "con https si" \
  "UPDATE integration_connections SET status='active', base_url='https://api.ejemplo.com'
    WHERE name='X917D-A';" OK

echo ""
echo "-- UNA sola activa por proveedor, entorno y sociedad --"

# La puerta usa COALESCE(legal_entity_id, 0) porque en un indice unico dos NULL
# NO colisionan: sin eso se podrian tener dos conexiones de plataforma activas
# del mismo proveedor, que es justo lo que se quiere impedir.
porque "una segunda activa del mismo proveedor y entorno" \
  "INSERT INTO integration_connections (uuid,integration_provider_id,name,environment,status,base_url,created_at)
   VALUES (UUID(),$PROV,'X917D-B','production','active','https://otra.ejemplo.com',NOW(3));" \
  "uq_iconn_active|Duplicate"

probar "pero en el entorno de pruebas si" \
  "INSERT INTO integration_connections (uuid,integration_provider_id,name,environment,status,base_url,created_at)
   VALUES (UUID(),$PROV,'X917D-B','sandbox','active','https://pruebas.ejemplo.com',NOW(3));" OK

CONN="(SELECT id FROM (SELECT id FROM integration_connections WHERE name='X917D-A') c)"

echo ""
echo "-- La credencial: se escribe y no se reescribe --"

porque "una clase de credencial inventada" \
  "INSERT INTO integration_credentials (integration_connection_id,kind,secret_cipher,set_by_user_id,set_at,created_at)
   VALUES ($CONN,'huella','cifrado',$USR,NOW(3),NOW(3));" \
  "ck_icred_kind|Clase de credencial"

porque "un cifrado en blanco" \
  "INSERT INTO integration_credentials (integration_connection_id,kind,secret_cipher,set_by_user_id,set_at,created_at)
   VALUES ($CONN,'api_key','   ',$USR,NOW(3),NOW(3));" \
  "ck_icred_cipher|vacia no es una credencial"

probar "la credencial buena entra" \
  "INSERT INTO integration_credentials (integration_connection_id,kind,secret_cipher,last4,version,set_by_user_id,set_at,created_at)
   VALUES ($CONN,'api_key','cifrado-v1','3456',1,$USR,NOW(3),NOW(3));" OK

porque "una segunda viva de la misma clase" \
  "INSERT INTO integration_credentials (integration_connection_id,kind,secret_cipher,last4,version,set_by_user_id,set_at,created_at)
   VALUES ($CONN,'api_key','cifrado-v2','7890',2,$USR,NOW(3),NOW(3));" \
  "uq_icred_vigente|Duplicate"

# Es la asercion de la iteracion. Sin ella, «rotar» podria ser un UPDATE sobre
# el cifrado, y entonces «.cuando cambio y quien la puso?» deja de tener
# respuesta, que es la mitad del motivo de que esta tabla exista.
porque "reescribir el cifrado en vez de rotar" \
  "UPDATE integration_credentials SET secret_cipher='cifrado-v2'
    WHERE integration_connection_id=$CONN AND kind='api_key';" \
  "tg_icred_inmutable|no se reescribe"

porque "revocarla sin decir por que" \
  "UPDATE integration_credentials SET revoked_at=NOW(3)
    WHERE integration_connection_id=$CONN AND kind='api_key';" \
  "ck_icred_revocada|decir por que"

probar "revocarla como se debe" \
  "UPDATE integration_credentials SET revoked_at=NOW(3), revoked_reason='Rotacion de la suite.'
    WHERE integration_connection_id=$CONN AND kind='api_key';" OK

probar "y ahora si entra la siguiente version" \
  "INSERT INTO integration_credentials (integration_connection_id,kind,secret_cipher,last4,version,set_by_user_id,set_at,created_at)
   VALUES ($CONN,'api_key','cifrado-v2','7890',2,$USR,NOW(3),NOW(3));" OK

porque "y una revocada no vuelve a estar viva" \
  "UPDATE integration_credentials SET revoked_at=NULL
    WHERE integration_connection_id=$CONN AND kind='api_key' AND version=1;" \
  "tg_icred_inmutable|no vuelve a estar viva"

valor "quedan dos versiones y solo una viva" \
  "SELECT CONCAT(COUNT(*),'/',SUM(CASE WHEN revoked_at IS NULL THEN 1 ELSE 0 END))
     FROM integration_credentials WHERE integration_connection_id=$CONN;" "2/1"

echo ""
echo "-- Se limpia lo suyo --"

probar "las credenciales de la suite se borran" \
  "DELETE FROM integration_credentials WHERE integration_connection_id=$CONN;" OK

probar "y sus conexiones y su proveedor" \
  "DELETE FROM integration_connections WHERE name LIKE 'X917D%';
   DELETE FROM integration_providers WHERE code='x917d';" OK

valor "no quedo nada" \
  "SELECT COUNT(*) FROM integration_providers WHERE code='x917d';" "0"

resumen
