#!/bin/bash
# Pruebas de restriccion de la iteracion 3.5: identidad verificada, activacion
# y terminos versionados.
#
# Estas comprobaciones viven en la BASE, no en PHP, a proposito: la puerta de
# activacion la impone `CompletitudOperativa`, pero si alguien escribe por SQL
# --una correccion a mano, una importacion, un script de migracion-- ninguna
# clase de PHP se entera. Lo que se prueba aqui es lo que sigue siendo cierto
# cuando la aplicacion no esta en medio.
#
# Uso: bash tools/pruebas/3.5-activacion.sh <base>
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

# Los cuatro ayudantes viven en UN sitio desde 8.11: estaban copiados en las
# treinta suites y habian derivado en seis variantes, y nueve de ellas se
# habrian puesto verdes con el motor apagado. Ver `tools/pruebas/comun.sh`.
source "$(dirname "$0")/comun.sh"
# Un fallo de conexion NO es un rechazo: sin esta distincion, una base caida
# hace que todas las pruebas de rechazo "pasen" y el informe salga verde con el
# motor apagado.
# Igual que la suite de finanzas: no es idempotente. Inserta creadores con
# documento fijo y una version de terminos con codigo fijo.
usadas=$($CLIENTE $DB -N -e "SELECT COUNT(*) FROM terms_acceptances" 2>/dev/null)
if [ -z "$usadas" ]; then
  echo "  No puedo leer $DB. .Esta levantado el motor y creada la base?"; exit 2
fi
if [ "$usadas" != "2" ]; then
  echo "  $DB tiene $usadas aceptaciones de terminos y la semilla deja 2:"
  echo "  recree la base y cargue tools/pruebas/semilla.sql antes de ejecutar."
  exit 2
fi

PA="(SELECT id FROM (SELECT id FROM countries WHERE iso2='PE') t)"
U1="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) t)"
FI="(SELECT id FROM (SELECT id FROM files WHERE purpose='identity_document' LIMIT 1) t)"
TV="(SELECT id FROM (SELECT id FROM terms_versions WHERE code='creator_terms' AND effective_to IS NULL) t)"
CR="(SELECT id FROM (SELECT id FROM creators WHERE display_name='anatorres') t)"
# NOTA DE PORTABILIDAD: todas las subconsultas van envueltas en una tabla
# derivada. MySQL 8 rechaza con el error 1093 leer la misma tabla que se esta
# modificando; MariaDB lo permite. Sin la envoltura, la mitad de estas
# aserciones fallarian solo en CI (DEC-052).

BASE="uuid,first_name,last_name,display_name,birth_date,email,country_id,document_country_code,document_type,document_number,preferred_currency_code,created_at"
VAL="UUID(),'Test','Uno','test1','1995-01-01',"

echo ""
echo "--- Identidad verificada: las tres columnas o ninguna (DEC-058) ---"
probar "creador pendiente sin nada de identidad" \
 "INSERT INTO creators ($BASE,status) VALUES ($VAL't1@ejemplo.test',$PA,'PE','DNI','50000001','PEN',NOW(3),'pending');" OK
probar "identidad completa: fecha + revisor + documento" \
 "INSERT INTO creators ($BASE,status,identity_verified_at,identity_verified_by_user_id,identity_document_file_id) VALUES (UUID(),'Test','Dos','test2','1995-01-01','t2@ejemplo.test',$PA,'PE','DNI','50000002','PEN',NOW(3),'pending',NOW(3),$U1,$FI);" OK
probar "identidad marcada SIN documento adjunto" \
 "INSERT INTO creators ($BASE,status,identity_verified_at,identity_verified_by_user_id) VALUES (UUID(),'Test','Tres','test3','1995-01-01','t3@ejemplo.test',$PA,'PE','DNI','50000003','PEN',NOW(3),'pending',NOW(3),$U1);" RECHAZO
probar "identidad marcada SIN decir quien la verifico" \
 "INSERT INTO creators ($BASE,status,identity_verified_at,identity_document_file_id) VALUES (UUID(),'Test','Cuatro','test4','1995-01-01','t4@ejemplo.test',$PA,'PE','DNI','50000004','PEN',NOW(3),'pending',NOW(3),$FI);" RECHAZO
probar "documento adjunto sin fecha de verificacion" \
 "INSERT INTO creators ($BASE,status,identity_verified_by_user_id,identity_document_file_id) VALUES (UUID(),'Test','Cinco','test5','1995-01-01','t5@ejemplo.test',$PA,'PE','DNI','50000005','PEN',NOW(3),'pending',$U1,$FI);" RECHAZO

echo ""
echo "--- La activacion no se declara: se sostiene (BR-CREATOR-006) ---"
probar "activo sin fecha de activacion" \
 "INSERT INTO creators ($BASE,status,identity_verified_at,identity_verified_by_user_id,identity_document_file_id) VALUES (UUID(),'Test','Seis','test6','1995-01-01','t6@ejemplo.test',$PA,'PE','DNI','50000006','PEN',NOW(3),'active',NOW(3),$U1,$FI);" RECHAZO
probar "activo sin identidad verificada" \
 "INSERT INTO creators ($BASE,status,activated_at) VALUES (UUID(),'Test','Siete','test7','1995-01-01','t7@ejemplo.test',$PA,'PE','DNI','50000007','PEN',NOW(3),'active',NOW(3));" RECHAZO
probar "activo con fecha e identidad" \
 "INSERT INTO creators ($BASE,status,activated_at,identity_verified_at,identity_verified_by_user_id,identity_document_file_id) VALUES (UUID(),'Test','Ocho','test8','1995-01-01','t8@ejemplo.test',$PA,'PE','DNI','50000008','PEN',NOW(3),'active',NOW(3),NOW(3),$U1,$FI);" OK
probar "UPDATE a activo saltandose la aplicacion" \
 "UPDATE creators SET status='active', activated_at=NOW(3) WHERE display_name='test1';" RECHAZO

echo ""
echo "--- Terminos versionados (DEC-059) ---"
probar "segunda version vigente del mismo documento" \
 "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,effective_from,published_at,published_by_user_id,created_at) VALUES (UUID(),'creator','creator_terms','2026.2','Otros terminos','Texto',REPEAT('e',64),'2026-06-01',NOW(3),(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) uu),NOW(3));" RECHAZO
# EL DIA ANTES, no el mismo dia. Esta linea decia `2026-06-01` --el mismo dia en
# que empieza la 2026.2-- y con eso esta suite daba por buena la ambiguedad: ese
# dia habia DOS textos vigentes, y es el texto que el creador acepta.
#
# Quinta vez el mismo defecto escrito como si fuera lo correcto: el controlador
# fiscal, PerfilFiscalTest, 3.6-fiscal.sh, PublicarTerminosCommand y esta.
probar "version cerrada EL DIA ANTES + version nueva vigente" \
 "UPDATE terms_versions SET effective_to='2026-05-31' WHERE code='creator_terms' AND effective_to IS NULL;" OK
probar "ahora si entra la 2026.2" \
 "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,effective_from,published_at,published_by_user_id,created_at) VALUES (UUID(),'creator','creator_terms','2026.2','Otros terminos','Texto',REPEAT('e',64),'2026-06-01',NOW(3),(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) uu),NOW(3));" OK
probar "misma version dos veces" \
 "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,effective_from,published_at,published_by_user_id,created_at) VALUES (UUID(),'creator','creator_terms','2026.2','Duplicada','Texto',REPEAT('f',64),'2026-07-01',NOW(3),(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) uu),NOW(3));" RECHAZO
probar "terminos sin texto ni documento" \
 "INSERT INTO terms_versions (uuid,audience,code,version,title,content_sha256,effective_from,published_at,published_by_user_id,created_at) VALUES (UUID(),'creator','otros_terminos','1.0','Vacios',REPEAT('e',64),'2026-01-01',NOW(3),(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) uu),NOW(3));" RECHAZO
probar "huella que no es un sha256" \
 "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,effective_from,published_at,published_by_user_id,created_at) VALUES (UUID(),'creator','otros_terminos','1.0','Cortos','Texto','abc','2026-01-01',NOW(3),(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) uu),NOW(3));" RECHAZO
probar "vigencia que termina antes de empezar" \
 "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,effective_from,effective_to,published_at,published_by_user_id,created_at) VALUES (UUID(),'creator','otros_terminos','1.0','Imposibles','Texto',REPEAT('e',64),'2026-05-01','2026-01-01',NOW(3),(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) uu),NOW(3));" RECHAZO
probar "publico inventado" \
 "INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,effective_from,published_at,published_by_user_id,created_at) VALUES (UUID(),'proveedor','proveedor_terminos','1.0','Ajenos','Texto',REPEAT('e',64),'2026-01-01',NOW(3),(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) uu),NOW(3));" RECHAZO

echo ""
echo "--- Aceptaciones: 'acepto' no es la palabra de quien teclea ---"
probar "aceptacion registrada por un revisor con evidencia" \
 "INSERT INTO terms_acceptances (uuid,terms_version_id,subject_type,subject_id,channel,recorded_by_user_id,evidence_file_id,accepted_at,created_at) VALUES (UUID(),$TV,'creator',$CR,'email',$U1,$FI,NOW(3),NOW(3));" OK
probar "aceptacion por correo SIN evidencia adjunta" \
 "INSERT INTO terms_acceptances (uuid,terms_version_id,subject_type,subject_id,channel,recorded_by_user_id,accepted_at,created_at) VALUES (UUID(),$TV,'creator',(SELECT id FROM (SELECT id FROM creators WHERE display_name='luisvega') t),'whatsapp',$U1,NOW(3),NOW(3));" RECHAZO
probar "aceptacion por correo SIN decir quien la registro" \
 "INSERT INTO terms_acceptances (uuid,terms_version_id,subject_type,subject_id,channel,evidence_file_id,accepted_at,created_at) VALUES (UUID(),$TV,'creator',(SELECT id FROM (SELECT id FROM creators WHERE display_name='luisvega') t),'paper',$FI,NOW(3),NOW(3));" RECHAZO
probar "aceptacion del portal: sin evidencia, porque la dio el interesado" \
 "INSERT INTO terms_acceptances (uuid,terms_version_id,subject_type,subject_id,channel,accepted_at,created_at) VALUES (UUID(),$TV,'creator',(SELECT id FROM (SELECT id FROM creators WHERE display_name='luisvega') t),'portal',NOW(3),NOW(3));" OK
probar "aceptacion del portal en nombre de otro" \
 "INSERT INTO terms_acceptances (uuid,terms_version_id,subject_type,subject_id,channel,recorded_by_user_id,evidence_file_id,accepted_at,created_at) VALUES (UUID(),$TV,'creator',(SELECT id FROM (SELECT id FROM creators WHERE display_name='test1') t),'portal',$U1,$FI,NOW(3),NOW(3));" RECHAZO
probar "la misma persona acepta dos veces la misma version" \
 "INSERT INTO terms_acceptances (uuid,terms_version_id,subject_type,subject_id,channel,recorded_by_user_id,evidence_file_id,accepted_at,created_at) VALUES (UUID(),$TV,'creator',$CR,'phone',$U1,$FI,NOW(3),NOW(3));" RECHAZO
probar "canal inventado" \
 "INSERT INTO terms_acceptances (uuid,terms_version_id,subject_type,subject_id,channel,recorded_by_user_id,evidence_file_id,accepted_at,created_at) VALUES (UUID(),$TV,'creator',(SELECT id FROM (SELECT id FROM creators WHERE display_name='test5') t),'paloma',$U1,$FI,NOW(3),NOW(3));" RECHAZO
probar "sujeto de un tipo que no existe" \
 "INSERT INTO terms_acceptances (uuid,terms_version_id,subject_type,subject_id,channel,recorded_by_user_id,evidence_file_id,accepted_at,created_at) VALUES (UUID(),$TV,'proveedor',1,'email',$U1,$FI,NOW(3),NOW(3));" RECHAZO
probar "aceptacion de una version que no existe" \
 "INSERT INTO terms_acceptances (uuid,terms_version_id,subject_type,subject_id,channel,recorded_by_user_id,evidence_file_id,accepted_at,created_at) VALUES (UUID(),999999,'creator',$CR,'email',$U1,$FI,NOW(3),NOW(3));" RECHAZO

echo ""
echo "--- Historico de estados (docs/02 N-04) ---"
probar "transicion pendiente a activo" \
 "INSERT INTO status_transitions (entity_type,entity_id,from_status,to_status,actor_user_id,reason,occurred_at) VALUES ('creator',$CR,'pending','active',$U1,'Completitud verificada',NOW(3));" OK
probar "transicion de un estado a si mismo" \
 "INSERT INTO status_transitions (entity_type,entity_id,from_status,to_status,occurred_at) VALUES ('creator',$CR,'active','active',NOW(3));" RECHAZO
probar "nacimiento de la entidad (sin estado previo)" \
 "INSERT INTO status_transitions (entity_type,entity_id,from_status,to_status,occurred_at) VALUES ('creator',$CR,NULL,'pending',NOW(3));" OK

echo ""
echo -n "  terms_acceptances con updated_at (solo insercion, debe ser 0): "
$CLIENTE $DB -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND COLUMN_NAME='updated_at' AND TABLE_NAME='terms_acceptances';" -B -N 2>/dev/null

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
