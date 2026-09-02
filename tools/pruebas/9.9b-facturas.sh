#!/bin/bash
# Pruebas de restriccion de la iteracion 9.9b: la factura.
#
#   ck_invoice_borrador_sin_numero  un borrador NO gasta correlativo
#   ck_invoice_numerada             y una emitida lo lleva, con el del libro
#   ck_invoice_number               el correlativo empieza en 1 --si lo hay--
#   ck_invoice_regime_country       no se exporta a quien vive donde se emite
#   ck_invoice_emisor_pais          una emitida dice desde que pais salio
#   ck_invoice_gravado_con_impuesto gravado con cero es la factura sin IGV
#   ck_invoice_gravado_con_tasa     y dice CON QUE tasa se calculo
#   ck_invoice_void_reason          anular exige decir por que
#   ck_countries_sales_tax          el codigo del impuesto de venta, en mayusculas
#   uq_invoice_dnumber              un numero reservado se gasta UNA vez
#   tg_invoice_emision              las lineas suman, y despues nada se toca
#   tg_iline_solo_borrador          no se anaden lineas a lo ya emitido
#   tg_iline_no_update              ni se cambian
#
# La que mas importa es `tg_invoice_emision`. Las otras doce impiden estados
# imposibles; esa impide **reescribir un documento que ya vio la administracion
# tributaria**, que es la unica de la lista que alguien haria a proposito.
#
# Uso: bash tools/pruebas/9.9b-facturas.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.9b - La factura que sale de una campana"
echo "==================================================================================="

LE=$($CLIENTE $DB -sN -e "SELECT id FROM legal_entities WHERE code='CTS-PE';" 2>/dev/null | tr -d '\r')
CO=$($CLIENTE $DB -sN -e "SELECT id FROM client_organizations WHERE client_code='CLI-0001';" 2>/dev/null | tr -d '\r')
TP=$($CLIENTE $DB -sN -e "SELECT id FROM client_tax_profiles WHERE client_organization_id=$CO LIMIT 1;" 2>/dev/null | tr -d '\r')
SE=$($CLIENTE $DB -sN -e "SELECT id FROM document_series WHERE series='F001' LIMIT 1;" 2>/dev/null | tr -d '\r')

if [ -z "${LE:-}" ] || [ -z "${CO:-}" ] || [ -z "${TP:-}" ] || [ -z "${SE:-}" ]; then
  echo "  La premisa no se cumple: faltan sociedad, cliente, perfil fiscal o serie."
  exit 1
fi

valor "no quedan facturas de una corrida anterior" \
  "SELECT CASE WHEN COUNT(*) = 0 THEN 'limpio' ELSE 'rehaga la base' END
     FROM invoices WHERE uuid LIKE 'f99b%';" "limpio"

# Un borrador de verdad, con el que trabajan casi todas las aserciones.
$CLIENTE $DB -e "
INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,
  document_type,issue_date,due_date,currency_code,tax_regime,tax_rate_snapshot,
  subtotal_amount,tax_amount,total_amount,status,
  issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,issuer_country_snapshot,
  receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,
  created_at)
VALUES ('f99b0000-0000-4000-8000-000000000001',$LE,$CO,$TP,
  'invoice','2026-09-01','2026-10-01','PEN','gravado',18.0000,
  900.0000,162.0000,1062.0000,'draft',
  'Emisor','20603203896','Lima','PE',
  'Receptor','20123456789','Lima','PE',NOW(3));" 2>/dev/null

BORRADOR=$($CLIENTE $DB -sN -e "SELECT id FROM invoices WHERE uuid='f99b0000-0000-4000-8000-000000000001';" | tr -d '\r')

# Con su linea desde el principio, y cuadrando. Es deliberado: la leccion de
# `9.21c` es que una fila que rompe DOS reglas a la vez se para en la que cada
# motor evalue primero, y la asercion pasa por un motivo que no es el suyo.
$CLIENTE $DB -e "
INSERT INTO invoice_lines (invoice_id,line_number,description,quantity,unit_price,
  line_subtotal,tax_rate,line_tax,line_total)
VALUES ($BORRADOR,1,'Servicios de prueba',1,900.0000,900.0000,18.0000,162.0000,1062.0000);" 2>/dev/null

echo ""
echo "-- El borrador no gasta numero --"

porque "un borrador con serie y numero" \
  "UPDATE invoices SET series='F001', number=1 WHERE id=$BORRADOR;" \
  "ck_invoice_borrador_sin_numero|todavia no gasta"

porque "una emitida sin serie ni numero" \
  "UPDATE invoices SET status='issued', issued_at=NOW(3) WHERE id=$BORRADOR;" \
  "ck_invoice_numerada|serie y correlativo"

# Las dos siguientes llevan numero del libro A PROPOSITO: sin el romperian
# TAMBIEN `ck_invoice_numerada`, y cada motor pararia en la que evalue primero
# --que es como una asercion se pone verde por un motivo que no es el suyo--.
$CLIENTE $DB -e "
INSERT INTO document_numbers (document_series_id,number,full_number,status,reserved_at,created_at)
VALUES ($SE,9902,'F001-00009902','reserved',NOW(3),NOW(3)),
       ($SE,9903,'F001-00009903','reserved',NOW(3),NOW(3));" 2>/dev/null
N902=$($CLIENTE $DB -sN -e "SELECT id FROM document_numbers WHERE full_number='F001-00009902';" | tr -d '\r')
N903=$($CLIENTE $DB -sN -e "SELECT id FROM document_numbers WHERE full_number='F001-00009903';" | tr -d '\r')

porque "una emitida sin decir desde que pais" \
  "UPDATE invoices SET status='issued', issued_at=NOW(3), issuer_country_snapshot=NULL,
     series='F001', number=9902, document_number_id=$N902 WHERE id=$BORRADOR;" \
  "ck_invoice_emisor_pais|desde que pais"

porque "una gravada emitida que no dice con que tasa" \
  "UPDATE invoices SET status='issued', issued_at=NOW(3), tax_rate_snapshot=NULL,
     series='F001', number=9903, document_number_id=$N903 WHERE id=$BORRADOR;" \
  "ck_invoice_gravado_con_tasa|con que tasa"

# Emitida y con su numero del libro A PROPOSITO: en borrador romperia TAMBIEN
# `ck_invoice_borrador_sin_numero`, y MySQL 8 paraba en esa --la asercion estaba
# verde por un motivo que no era el suyo--.
$CLIENTE $DB -e "
INSERT INTO document_numbers (document_series_id,number,full_number,status,reserved_at,created_at)
VALUES ($SE,9899,'F001-00009899','reserved',NOW(3),NOW(3));" 2>/dev/null
N899=$($CLIENTE $DB -sN -e "SELECT id FROM document_numbers WHERE full_number='F001-00009899';" | tr -d '\r')

porque "un correlativo cero" \
  "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,
     document_type,series,number,document_number_id,issue_date,due_date,currency_code,tax_regime,
     subtotal_amount,tax_amount,total_amount,status,issued_at,
     issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,issuer_country_snapshot,
     receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,
     receiver_country_snapshot,created_at)
   VALUES ('f99b0000-0000-4000-8000-000000000002',$LE,$CO,$TP,
     'invoice','F001',0,$N899,'2026-09-01','2026-10-01','PEN','inafecto',
     100.0000,0,100.0000,'issued',NOW(3),
     'E','20603203896','Lima','PE','R','20123456789','Lima','PE',NOW(3));" \
  "ck_invoice_number|empieza en 1"

echo ""
echo "-- El regimen, sin ningun pais escrito dentro --"

porque "exportar a un cliente domiciliado donde se emite" \
  "UPDATE invoices SET tax_regime='exportacion', tax_amount=0, total_amount=900.0000
     WHERE id=$BORRADOR;" \
  "ck_invoice_regime_country|domiciliado donde se emite"

# La misma regla, ahora con un pais distinto: tiene que DEJAR pasar. Sin esta
# mitad, un CHECK que rechazara todo tambien pasaria la de arriba.
probar "exportar a un cliente de fuera si se puede" \
  "UPDATE invoices SET tax_regime='exportacion', tax_amount=0, total_amount=900.0000,
     receiver_country_snapshot='CO' WHERE id=$BORRADOR;" "OK"

$CLIENTE $DB -e "UPDATE invoices SET tax_regime='gravado', tax_amount=162.0000,
  total_amount=1062.0000, receiver_country_snapshot='PE' WHERE id=$BORRADOR;" 2>/dev/null

echo ""
echo "-- Gravado quiere decir CON impuesto --"

porque "una operacion gravada con impuesto cero" \
  "UPDATE invoices SET tax_amount=0, total_amount=900.0000 WHERE id=$BORRADOR;" \
  "ck_invoice_gravado_con_impuesto|falta la tasa"

porque "el codigo del impuesto de venta en minusculas" \
  "UPDATE countries SET sales_tax_code='igv' WHERE iso2='PE';" \
  "ck_countries_sales_tax|mayusculas"

echo ""
echo "-- La emision: las lineas tienen que sumar --"

# Un segundo borrador, SIN lineas, para poder afirmar esa regla sola.
$CLIENTE $DB -e "
INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,
  document_type,issue_date,due_date,currency_code,tax_regime,tax_rate_snapshot,
  subtotal_amount,tax_amount,total_amount,status,
  issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,issuer_country_snapshot,
  receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,
  created_at)
VALUES ('f99b0000-0000-4000-8000-000000000004',$LE,$CO,$TP,
  'invoice','2026-09-01','2026-10-01','PEN','gravado',18.0000,
  0,0,0,'draft',
  'Emisor','20603203896','Lima','PE',
  'Receptor','20123456789','Lima','PE',NOW(3));" 2>/dev/null
VACIA=$($CLIENTE $DB -sN -e "SELECT id FROM invoices WHERE uuid='f99b0000-0000-4000-8000-000000000004';" | tr -d '\r')

$CLIENTE $DB -e "
INSERT INTO document_numbers (document_series_id,number,full_number,status,reserved_at,created_at)
VALUES ($SE,9900,'F001-00009900','reserved',NOW(3),NOW(3));" 2>/dev/null
NUM0=$($CLIENTE $DB -sN -e "SELECT id FROM document_numbers WHERE full_number='F001-00009900';" | tr -d '\r')

porque "emitir una factura sin ninguna linea" \
  "UPDATE invoices SET status='issued', issued_at=NOW(3), series='F001', number=9900,
     document_number_id=$NUM0 WHERE id=$VACIA;" \
  "sin lineas"

# Se reserva un numero de verdad, como haria `Correlativos::reservar`.
$CLIENTE $DB -e "
INSERT INTO document_numbers (document_series_id,number,full_number,status,reserved_at,created_at)
VALUES ($SE,9901,'F001-00009901','reserved',NOW(3),NOW(3));" 2>/dev/null
NUM=$($CLIENTE $DB -sN -e "SELECT id FROM document_numbers WHERE full_number='F001-00009901';" | tr -d '\r')

$CLIENTE $DB -e "UPDATE invoices SET subtotal_amount=1000.0000, tax_amount=180.0000,
  total_amount=1180.0000 WHERE id=$BORRADOR;" 2>/dev/null

porque "emitir cuando las lineas no suman la cabecera" \
  "UPDATE invoices SET status='issued', issued_at=NOW(3), series='F001', number=9901,
     document_number_id=$NUM WHERE id=$BORRADOR;" \
  "no suman el total|no cuadra"

$CLIENTE $DB -e "UPDATE invoices SET subtotal_amount=900.0000, tax_amount=162.0000,
  total_amount=1062.0000 WHERE id=$BORRADOR;" 2>/dev/null

probar "emitir cuando todo cuadra" \
  "UPDATE invoices SET status='issued', issued_at=NOW(3), series='F001', number=9901,
     document_number_id=$NUM WHERE id=$BORRADOR;" "OK"

echo ""
echo "-- Y a partir de ahi, el documento no se toca --"

porque "cambiarle el importe a una factura emitida" \
  "UPDATE invoices SET subtotal_amount=1.0000, total_amount=1.0000, tax_amount=0
     WHERE id=$BORRADOR;" \
  "no se corrige"

porque "cambiarle el numero a una factura emitida" \
  "UPDATE invoices SET number=9999 WHERE id=$BORRADOR;" \
  "no se corrige"

porque "cambiarle el domicilio del receptor a una factura emitida" \
  "UPDATE invoices SET receiver_address_snapshot='Otra direccion' WHERE id=$BORRADOR;" \
  "no se corrige"

# Lo que SI puede seguir moviendose: el estado y el sello de la administracion.
# Sin esta asercion, un disparador que congelara la fila entera tambien pasaria
# las tres de arriba --y dejaria el sistema sin poder registrar un cobro--.
probar "marcarla como enviada si se puede" \
  "UPDATE invoices SET status='sent', external_status='aceptada' WHERE id=$BORRADOR;" "OK"

porque "anadir una linea a una factura ya emitida" \
  "INSERT INTO invoice_lines (invoice_id,line_number,description,quantity,unit_price,
     line_subtotal,tax_rate,line_tax,line_total)
   VALUES ($BORRADOR,2,'Colada despues',1,10.0000,10.0000,0,0,10.0000);" \
  "No se anaden lineas"

porque "cambiar una linea de una factura ya emitida" \
  "UPDATE invoice_lines SET unit_price=1.0000 WHERE invoice_id=$BORRADOR;" \
  "No se alteran las lineas"

porque "borrar una factura emitida" \
  "DELETE FROM invoices WHERE id=$BORRADOR;" \
  "no se borra"

echo ""
echo "-- Anular exige decir por que --"

porque "anular sin motivo" \
  "UPDATE invoices SET status='voided', voided_at=NOW(3) WHERE id=$BORRADOR;" \
  "ck_invoice_void_reason|decir por que"

porque "anular con un motivo de tres letras" \
  "UPDATE invoices SET status='voided', voided_at=NOW(3), void_reason='ups'
     WHERE id=$BORRADOR;" \
  "ck_invoice_void_reason|decir por que"

probar "anular con el motivo escrito" \
  "UPDATE invoices SET status='voided', voided_at=NOW(3),
     void_reason='El cliente rechazo el alcance despues de emitida.' WHERE id=$BORRADOR;" "OK"

echo ""
echo "-- Un numero se gasta una sola vez --"

porque "dos facturas apuntando al mismo numero reservado" \
  "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,
     document_type,series,number,document_number_id,issue_date,due_date,currency_code,
     tax_regime,tax_rate_snapshot,subtotal_amount,tax_amount,total_amount,status,issued_at,
     issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,issuer_country_snapshot,
     receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,
     receiver_country_snapshot,created_at)
   VALUES ('f99b0000-0000-4000-8000-000000000003',$LE,$CO,$TP,
     'invoice','F001',9901,$NUM,'2026-09-01','2026-10-01','PEN',
     'gravado',18.0000,900.0000,162.0000,1062.0000,'issued',NOW(3),
     'E','20603203896','Lima','PE','R','20123456789','Lima','PE',NOW(3));" \
  "uq_invoice_dnumber|Duplicate"

echo ""
echo "-- 9.9f: el tipo lo declara el catalogo del pais, no una lista (T-79) --"

# Hasta 9.9f esto lo guardaba `ck_invoice_type` con los cuatro tipos de Peru
# escritos dentro. Ahora la pregunta es CRUZADA --.existe este tipo en el pais
# de ESTE emisor?-- y la contesta `document_types`, que es donde `DEC-190` dice
# que viven los valores.
porque "un tipo que el catalogo del pais no declara" \
  "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,
     document_type,series,number,document_number_id,issue_date,due_date,currency_code,
     tax_regime,tax_rate_snapshot,subtotal_amount,tax_amount,total_amount,status,issued_at,
     issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,issuer_country_snapshot,
     receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,
     receiver_country_snapshot,created_at)
   VALUES ('f99f0000-0000-4000-8000-000000000001',$LE,$CO,$TP,
     'factura_cfdi','F001',9902,NULL,'2026-09-01','2026-10-01','PEN',
     'gravado',18.0000,900.0000,162.0000,1062.0000,'issued',NOW(3),
     'E','20603203896','Lima','PE','R','20123456789','Lima','PE',NOW(3));" \
  "tg_invoice_tipo_ins|no existe en el catalogo"

# Y un BORRADOR si puede tener cualquier cosa: todavia no ha elegido serie, asi
# que su `document_type` es el valor por defecto y no significa nada.
probar "pero un borrador con ese mismo tipo si entra" \
  "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,
     document_type,issue_date,due_date,currency_code,tax_regime,tax_rate_snapshot,
     subtotal_amount,tax_amount,total_amount,status,
     issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,issuer_country_snapshot,
     receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,
     receiver_country_snapshot,created_at)
   VALUES ('f99f0000-0000-4000-8000-000000000002',$LE,$CO,$TP,
     'factura_cfdi','2026-09-01','2026-10-01','PEN','gravado',18.0000,
     900.0000,162.0000,1062.0000,'draft',
     'E','20603203896','Lima','PE','R','20123456789','Lima','PE',NOW(3));" OK

# Con su LINEA: sin ella el rechazo lo daria `tg_invoice_emision` --«una factura
# sin lineas no dice que se cobra»-- y la asercion pasaria por el motivo
# equivocado, que es como se cuelan las reglas que no funcionan.
probar "con su linea, que suma la cabecera" \
  "INSERT INTO invoice_lines (invoice_id,line_number,description,quantity,unit_price,
     line_subtotal,tax_rate,line_tax,line_total)
   SELECT id,1,'Servicio',1,900.0000,900.0000,18.0000,162.0000,1062.0000
     FROM invoices WHERE uuid='f99f0000-0000-4000-8000-000000000002';" OK

porque "y al emitirlo se le exige el catalogo" \
  "UPDATE invoices SET status='issued', issued_at=NOW(3)
    WHERE uuid='f99f0000-0000-4000-8000-000000000002';" \
  "tg_invoice_tipo_upd|no existe en el catalogo"

probar "se limpia el borrador de esta seccion" \
  "DELETE FROM invoices WHERE uuid='f99f0000-0000-4000-8000-000000000002';" OK

resumen
