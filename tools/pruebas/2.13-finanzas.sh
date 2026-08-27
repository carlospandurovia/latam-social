#!/bin/bash
# Pruebas de restriccion de la iteracion 2.13 (finanzas: costos, lotes, pagos,
# libro mayor, facturas, cobros).
# Uso: bash tools/pruebas/2.13-finanzas.sh <base>
DB=${1:-latam_fin}
# El cliente y sus credenciales salen del entorno: en local es `mariadb` sin
# nada, y en CI es `mysql -h127.0.0.1 -uroot -proot`. Estaba fijo a `mariadb`,
# lo que habria hecho fallar el CI entero en el primer INSERT.
CLIENTE=${MYSQL_CMD:-mariadb}

# Los cuatro ayudantes viven en UN sitio desde 8.11: estaban copiados en las
# treinta suites y habian derivado en seis variantes, y nueve de ellas se
# habrian puesto verdes con el motor apagado. Ver `tools/pruebas/comun.sh`.
source "$(dirname "$0")/comun.sh"
# Un fallo de conexion NO es un rechazo. Sin esta distincion, una base caida
# hace que todas las pruebas de rechazo "pasen" y el informe salga verde con el
# motor apagado. Paso de verdad: 25 aserciones en verde contra un socket muerto.
# La suite NO es idempotente: inserta correlativos y codigos de lote fijos. Sobre
# una base ya usada, la segunda pasada choca con sus propias filas y el informe
# sale rojo por el motivo equivocado. Mejor parar y decirlo.
usadas=$($CLIENTE $DB -N -e "SELECT COUNT(*) FROM ledger_entries" 2>/dev/null)
if [ -z "$usadas" ]; then
  echo "  No puedo leer $DB. .Esta levantado el motor y creada la base?"; exit 2
fi
if [ "$usadas" != "0" ]; then
  echo "  $DB ya tiene $usadas asientos: recree la base y cargue tools/pruebas/semilla.sql antes de ejecutar."
  exit 2
fi

CA="(SELECT id FROM campaigns ORDER BY id LIMIT 1)"
LE="(SELECT id FROM legal_entities ORDER BY id LIMIT 1)"
U1="(SELECT id FROM users ORDER BY id LIMIT 1)"
U2="(SELECT id FROM users ORDER BY id LIMIT 1 OFFSET 1)"
CR="(SELECT id FROM creators ORDER BY id LIMIT 1)"
PM="(SELECT id FROM creator_payment_methods ORDER BY id LIMIT 1)"
PC="(SELECT id FROM campaign_creators ORDER BY id LIMIT 1)"
CO="(SELECT id FROM client_organizations ORDER BY id LIMIT 1)"
TP="(SELECT id FROM client_tax_profiles ORDER BY id LIMIT 1)"
FI="(SELECT id FROM files ORDER BY id LIMIT 1)"
SNAP="'CTS SAC','20603203896','Lima','Marca Demo','20123456789','Av. Demo 100','PE'"

echo ""
echo "--- Costos de campana (margen reconstruible) ---"
probar "costo: producto enviado al creador" \
 "INSERT INTO campaign_costs (campaign_id,cost_type,description,amount,currency_code,incurred_on,created_by_user_id,created_at) VALUES ($CA,'product','Kit de producto',250.0000,'PEN','2026-09-02',$U1,NOW(3));" OK
probar "costo: importe negativo" \
 "INSERT INTO campaign_costs (campaign_id,cost_type,description,amount,currency_code,incurred_on,created_at) VALUES ($CA,'shipping','Envio',-10.0000,'PEN','2026-09-02',NOW(3));" RECHAZO
probar "costo: tipo inventado" \
 "INSERT INTO campaign_costs (campaign_id,cost_type,description,amount,currency_code,incurred_on,created_at) VALUES ($CA,'varios','Algo',10.0000,'PEN','2026-09-02',NOW(3));" RECHAZO
probar "costo: anulacion completa (fecha + quien + motivo)" \
 "INSERT INTO campaign_costs (campaign_id,cost_type,description,amount,currency_code,incurred_on,voided_at,voided_by_user_id,voided_reason,created_at) VALUES ($CA,'media','Pauta duplicada',100.0000,'PEN','2026-09-03',NOW(3),$U1,'Cargado dos veces',NOW(3));" OK
probar "costo: anulado sin decir quien ni por que" \
 "INSERT INTO campaign_costs (campaign_id,cost_type,description,amount,currency_code,incurred_on,voided_at,created_at) VALUES ($CA,'media','Pauta',100.0000,'PEN','2026-09-03',NOW(3),NOW(3));" RECHAZO
probar "costo: borrado fisico (prohibido, se anula)" \
 "DELETE FROM campaign_costs WHERE id=(SELECT id FROM (SELECT MIN(id) id FROM campaign_costs) x);" RECHAZO

echo ""
echo "--- Lotes de pago: segregacion de funciones (BR-FIN-005) ---"
probar "lote: borrador" \
 "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,created_at) VALUES (UUID(),'LOTE-0001',$LE,'PEN','draft',$U1,NOW(3));" OK
probar "lote: APROBADO POR SU PROPIO CREADOR" \
 "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,approved_by_user_id,approved_at,created_at) VALUES (UUID(),'LOTE-0002',$LE,'PEN','approved',$U1,$U1,NOW(3),NOW(3));" RECHAZO
probar "lote: aprobado por una segunda persona" \
 "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,approved_by_user_id,approved_at,created_at) VALUES (UUID(),'LOTE-0003',$LE,'PEN','approved',$U1,$U2,NOW(3),NOW(3));" OK
probar "lote: pasar a aprobado sin aprobador (UPDATE)" \
 "UPDATE payout_batches SET status='approved' WHERE code='LOTE-0001';" RECHAZO
probar "lote: aprobado sin sello de tiempo" \
 "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,approved_by_user_id,created_at) VALUES (UUID(),'LOTE-0004',$LE,'PEN','approved',$U1,$U2,NOW(3));" RECHAZO
probar "lote: ejecutado sin fecha de ejecucion" \
 "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,approved_by_user_id,approved_at,created_at) VALUES (UUID(),'LOTE-0005',$LE,'PEN','executed',$U1,$U2,NOW(3),NOW(3));" RECHAZO
probar "lote: ejecutado ANTES de haber sido aprobado" \
 "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,approved_by_user_id,approved_at,executed_at,created_at) VALUES (UUID(),'LOTE-0006',$LE,'PEN','executed',$U1,$U2,'2026-09-10 10:00:00.000','2026-09-09 10:00:00.000',NOW(3));" RECHAZO
probar "lote: codigo repetido" \
 "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,created_at) VALUES (UUID(),'LOTE-0001',$LE,'PEN','draft',$U1,NOW(3));" RECHAZO
probar "lote: estado inventado" \
 "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,created_at) VALUES (UUID(),'LOTE-0007',$LE,'PEN','listo',$U1,NOW(3));" RECHAZO

LB="(SELECT id FROM payout_batches WHERE code='LOTE-0003')"
echo ""
echo "--- Pagos al creador ---"
probar "pago: pendiente dentro de un lote aprobado" \
 "INSERT INTO payouts (uuid,payout_batch_id,creator_id,payment_method_id,beneficiary_name_snapshot,account_masked_snapshot,amount,currency_code,status,created_at) VALUES (UUID(),$LB,$CR,$PM,'Ana Torres','****4321',1500.0000,'PEN','pending',NOW(3));" OK
probar "pago: SIN LOTE (puerta trasera a la doble aprobacion)" \
 "INSERT INTO payouts (uuid,creator_id,payment_method_id,beneficiary_name_snapshot,account_masked_snapshot,amount,currency_code,status,created_at) VALUES (UUID(),$CR,$PM,'Ana Torres','****4321',500.0000,'PEN','pending',NOW(3));" RECHAZO
probar "pago: importe cero" \
 "INSERT INTO payouts (uuid,payout_batch_id,creator_id,payment_method_id,beneficiary_name_snapshot,account_masked_snapshot,amount,currency_code,created_at) VALUES (UUID(),$LB,$CR,$PM,'Ana Torres','****4321',0,'PEN',NOW(3));" RECHAZO
probar "pago: importe negativo" \
 "INSERT INTO payouts (uuid,payout_batch_id,creator_id,payment_method_id,beneficiary_name_snapshot,account_masked_snapshot,amount,currency_code,created_at) VALUES (UUID(),$LB,$CR,$PM,'Ana Torres','****4321',-100.0000,'PEN',NOW(3));" RECHAZO
probar "pago: enviado sin fecha de envio" \
 "INSERT INTO payouts (uuid,payout_batch_id,creator_id,payment_method_id,beneficiary_name_snapshot,account_masked_snapshot,amount,currency_code,status,created_at) VALUES (UUID(),$LB,$CR,$PM,'Ana Torres','****4321',100.0000,'PEN','sent',NOW(3));" RECHAZO
probar "pago: devuelto sin fecha de devolucion" \
 "INSERT INTO payouts (uuid,payout_batch_id,creator_id,payment_method_id,beneficiary_name_snapshot,account_masked_snapshot,amount,currency_code,status,sent_at,created_at) VALUES (UUID(),$LB,$CR,$PM,'Ana Torres','****4321',100.0000,'PEN','returned',NOW(3),NOW(3));" RECHAZO
probar "pago: segundo pago, este si enviado al banco" \
 "INSERT INTO payouts (uuid,payout_batch_id,creator_id,payment_method_id,beneficiary_name_snapshot,account_masked_snapshot,amount,currency_code,status,sent_at,value_date,bank_reference,created_at) VALUES (UUID(),$LB,$CR,$PM,'Ana Torres','****4321',900.0000,'PEN','sent',NOW(3),'2026-09-15','TRF-88231',NOW(3));" OK
probar "pago: borrar uno que ya salio al banco" \
 "DELETE FROM payouts WHERE status='sent';" RECHAZO

PO="(SELECT id FROM payouts WHERE status='pending' ORDER BY id LIMIT 1)"
PO2="(SELECT id FROM payouts WHERE status='sent' ORDER BY id LIMIT 1)"
echo ""
echo "--- Libro mayor: signos ---"
probar "asiento: devengo positivo con su participacion" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,campaign_creator_id,description,occurred_at,created_at) VALUES (UUID(),$CR,'earning',1500.0000,'PEN',$PC,'Fee campana demo',NOW(3),NOW(3));" OK
probar "asiento: devengo NEGATIVO (signo invertido)" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,campaign_creator_id,description,occurred_at,created_at) VALUES (UUID(),$CR,'earning',-1500.0000,'PEN',$PC,'Fee',NOW(3),NOW(3));" RECHAZO
probar "asiento: devengo sin participacion (dinero sin origen)" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,description,occurred_at,created_at) VALUES (UUID(),$CR,'earning',500.0000,'PEN','De donde sale',NOW(3),NOW(3));" RECHAZO
probar "asiento: importe cero" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,campaign_creator_id,description,occurred_at,created_at) VALUES (UUID(),$CR,'earning',0,'PEN',$PC,'Nada',NOW(3),NOW(3));" RECHAZO
probar "asiento: pago negativo ligado a su payout" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,payout_id,description,occurred_at,created_at) VALUES (UUID(),$CR,'payment',-1500.0000,'PEN',$PO,'Pago lote 3',NOW(3),NOW(3));" OK
probar "asiento: pago POSITIVO" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,payout_id,description,occurred_at,created_at) VALUES (UUID(),$CR,'payment',900.0000,'PEN',$PO2,'Pago',NOW(3),NOW(3));" RECHAZO
probar "asiento: pago sin payout" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,description,occurred_at,created_at) VALUES (UUID(),$CR,'payment',-100.0000,'PEN','Pago fantasma',NOW(3),NOW(3));" RECHAZO
probar "asiento: un NO-pago con payout colgado" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,payout_id,campaign_creator_id,description,occurred_at,created_at) VALUES (UUID(),$CR,'earning',100.0000,'PEN',$PO2,$PC,'Confuso',NOW(3),NOW(3));" RECHAZO
probar "asiento: DOS pagos para el mismo payout (BR-FIN-013)" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,payout_id,description,occurred_at,created_at) VALUES (UUID(),$CR,'payment',-1500.0000,'PEN',$PO,'Duplicado',NOW(3),NOW(3));" RECHAZO
probar "asiento: penalidad positiva" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,description,occurred_at,created_at) VALUES (UUID(),$CR,'penalty',50.0000,'PEN','Multa',NOW(3),NOW(3));" RECHAZO
probar "asiento: penalidad negativa" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,description,occurred_at,created_at) VALUES (UUID(),$CR,'penalty',-50.0000,'PEN','Entrega tardia',NOW(3),NOW(3));" OK
probar "asiento: retencion positiva" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,description,occurred_at,created_at) VALUES (UUID(),$CR,'withholding',30.0000,'PEN','Retencion',NOW(3),NOW(3));" RECHAZO
probar "asiento: bono positivo sin campana" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,description,occurred_at,created_at) VALUES (UUID(),$CR,'bonus',200.0000,'PEN','Referido',NOW(3),NOW(3));" OK
probar "asiento: bono negativo" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,description,occurred_at,created_at) VALUES (UUID(),$CR,'bonus',-200.0000,'PEN','Referido',NOW(3),NOW(3));" RECHAZO
probar "asiento: ajuste positivo (unico tipo de doble signo)" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,description,occurred_at,created_at) VALUES (UUID(),$CR,'adjustment',10.0000,'PEN','Redondeo',NOW(3),NOW(3));" OK
probar "asiento: ajuste negativo" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,description,occurred_at,created_at) VALUES (UUID(),$CR,'adjustment',-10.0000,'PEN','Redondeo',NOW(3),NOW(3));" OK
probar "asiento: tipo inventado" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,description,occurred_at,created_at) VALUES (UUID(),$CR,'propina',10.0000,'PEN','X',NOW(3),NOW(3));" RECHAZO
probar "asiento: estado inventado" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,status,description,occurred_at,created_at) VALUES (UUID(),$CR,'adjustment',10.0000,'PEN','cobrado','X',NOW(3),NOW(3));" RECHAZO

# NOTA DE PORTABILIDAD: la subconsulta va envuelta en una tabla derivada
# `(SELECT x FROM (SELECT ...) t)` a proposito. MySQL 8 rechaza con el error 1093
# ("You can't specify target table ... in FROM clause") toda subconsulta que lea
# la MISMA tabla que la sentencia esta modificando. MariaDB lo permite, asi que
# la version directa pasaba en local y fallaba en CI. La tabla derivada se
# materializa antes y funciona en los dos motores. No la "simplifique".
PAGO="(SELECT id FROM (SELECT id FROM ledger_entries WHERE entry_type='payment' ORDER BY id LIMIT 1) t)"
echo ""
echo "--- Libro mayor: reversiones y conversion ---"
probar "reversion: positiva y apuntando al asiento que corrige" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,reverses_entry_id,description,occurred_at,created_at) VALUES (UUID(),$CR,'payment_reversal',1500.0000,'PEN',$PAGO,'Banco devolvio',NOW(3),NOW(3));" OK
probar "reversion: revertir DOS VECES el mismo asiento" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,reverses_entry_id,description,occurred_at,created_at) VALUES (UUID(),$CR,'payment_reversal',1500.0000,'PEN',$PAGO,'Otra vez',NOW(3),NOW(3));" RECHAZO
probar "reversion: sin decir que revierte" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,description,occurred_at,created_at) VALUES (UUID(),$CR,'payment_reversal',100.0000,'PEN','Revierto algo',NOW(3),NOW(3));" RECHAZO
probar "reversion: negativa" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,reverses_entry_id,description,occurred_at,created_at) VALUES (UUID(),$CR,'payment_reversal',-100.0000,'PEN',NULL,'X',NOW(3),NOW(3));" RECHAZO
probar "reversion: un devengo que dice revertir algo" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,campaign_creator_id,reverses_entry_id,description,occurred_at,created_at) VALUES (UUID(),$CR,'earning',100.0000,'PEN',$PC,(SELECT id FROM (SELECT MAX(id) id FROM ledger_entries) x),'X',NOW(3),NOW(3));" RECHAZO
probar "conversion: completa (tasa + fecha + fuente + moneda + base)" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,exchange_rate_snapshot,exchange_rate_date,exchange_rate_source,base_currency_code,base_amount,description,occurred_at,created_at) VALUES (UUID(),$CR,'bonus',100.0000,'USD',3.75000000,'2026-09-10','SUNAT','PEN',375.0000,'Bono en dolares',NOW(3),NOW(3));" OK
probar "conversion: tasa sin fecha ni fuente" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,exchange_rate_snapshot,description,occurred_at,created_at) VALUES (UUID(),$CR,'bonus',100.0000,'USD',3.75000000,'Bono',NOW(3),NOW(3));" RECHAZO
probar "conversion: tasa y fecha pero sin importe base" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,exchange_rate_snapshot,exchange_rate_date,exchange_rate_source,base_currency_code,description,occurred_at,created_at) VALUES (UUID(),$CR,'bonus',100.0000,'USD',3.75000000,'2026-09-10','SUNAT','PEN','Bono',NOW(3),NOW(3));" RECHAZO

echo ""
echo "--- Libro mayor: solo insercion (BR-FIN-001 / BR-FIN-002) ---"
probar "mayor: cambiar el estado de un asiento" \
 "UPDATE ledger_entries SET status='payable' WHERE entry_type='earning';" OK
probar "mayor: cambiar el IMPORTE de un asiento" \
 "UPDATE ledger_entries SET amount=99.0000 WHERE entry_type='earning';" RECHAZO
probar "mayor: cambiar la fecha del hecho economico" \
 "UPDATE ledger_entries SET occurred_at='2020-01-01 00:00:00.000' WHERE entry_type='earning';" RECHAZO
probar "mayor: cambiar el creador al que pertenece" \
 "UPDATE ledger_entries SET creator_id=(SELECT id FROM creators ORDER BY id LIMIT 1 OFFSET 1) WHERE entry_type='earning';" RECHAZO
probar "mayor: reescribir la descripcion" \
 "UPDATE ledger_entries SET description='Otra cosa' WHERE entry_type='earning';" RECHAZO
probar "mayor: BORRAR un asiento" \
 "DELETE FROM ledger_entries WHERE entry_type='adjustment' LIMIT 1;" RECHAZO

echo ""
echo "--- Retencion: la decision no puede confundirse con el olvido (Q-40) ---"
CRP="(SELECT id FROM creators ORDER BY id LIMIT 1 OFFSET 1)"
PA="(SELECT id FROM countries WHERE iso2='PE')"
CTP="creator_id,country_id,tax_regime_code,tax_id_type,tax_id_number,issued_document_type,valid_from,created_by_user_id"
probar "perfil: nace con la retencion SIN decidir" \
 "INSERT INTO creator_tax_profiles ($CTP) VALUES ($CRP,$PA,'RER','RUC','10400000021','recibo_honorarios','2026-01-01',$U1);" OK
probar "perfil: APROBARLO sin decidir la retencion" \
 "UPDATE creator_tax_profiles SET status='approved', approved_by_user_id=$U2, approved_at=NOW(3) WHERE tax_id_number='10400000021';" RECHAZO
probar "perfil: decidir que NO se retiene, y aprobarlo" \
 "UPDATE creator_tax_profiles SET withholding_status='not_applicable', status='approved', approved_by_user_id=$U2, approved_at=NOW(3) WHERE tax_id_number='10400000021';" OK
probar "perfil: 'no se retiene' con una tasa suelta de un borrador" \
 "INSERT INTO creator_tax_profiles ($CTP,withholding_status,withholding_rate) VALUES ($CRP,$PA,'RER','RUC','10400000022','recibo_honorarios','2026-01-01',$U1,'not_applicable',30.0);" RECHAZO
probar "perfil: 'se retiene' sin decir con que tasa" \
 "INSERT INTO creator_tax_profiles ($CTP,withholding_status) VALUES ($CRP,$PA,'RER','RUC','10400000023','recibo_honorarios','2026-01-01',$U1,'applies');" RECHAZO
probar "perfil: 'se retiene' al 30% SIN NORMA que lo sustente" \
 "INSERT INTO creator_tax_profiles ($CTP,withholding_status,withholding_rate) VALUES ($CRP,$PA,'RER','RUC','10400000024','recibo_honorarios','2026-01-01',$U1,'applies',30.0);" RECHAZO
probar "perfil: 'se retiene' al 30% citando la norma" \
 "INSERT INTO creator_tax_profiles ($CTP,withholding_status,withholding_rate,withholding_basis) VALUES ($CRP,$PA,'RER','RUC','10400000025','recibo_honorarios','2026-01-01',$U1,'applies',30.0,'LIR art. 54 inc. f - por confirmar con contador');" OK
probar "perfil: quien lo captura tambien lo aprueba" \
 "INSERT INTO creator_tax_profiles ($CTP,withholding_status,status,approved_by_user_id,approved_at) VALUES ($CRP,$PA,'RER','RUC','10400000026','recibo_honorarios','2026-01-01',$U1,'not_applicable','approved',$U1,NOW(3));" RECHAZO
probar "perfil: estado de retencion inventado" \
 "INSERT INTO creator_tax_profiles ($CTP,withholding_status) VALUES ($CRP,$PA,'RER','RUC','10400000027','recibo_honorarios','2026-01-01',$U1,'creo_que_si');" RECHAZO

echo ""
echo "--- Retencion congelada en el libro mayor ---"
probar "asiento: retencion con tasa y norma congeladas" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,withholding_rate_snapshot,withholding_basis_snapshot,description,occurred_at,created_at) VALUES (UUID(),$CR,'withholding',-450.0000,'PEN',30.0,'LIR art. 54 inc. f','Retencion no domiciliado',NOW(3),NOW(3));" OK
probar "asiento: retencion SIN tasa ni norma" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,description,occurred_at,created_at) VALUES (UUID(),$CR,'withholding',-450.0000,'PEN','Retencion a ojo',NOW(3),NOW(3));" RECHAZO
probar "asiento: retencion con tasa pero sin norma" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,withholding_rate_snapshot,description,occurred_at,created_at) VALUES (UUID(),$CR,'withholding',-450.0000,'PEN',30.0,'Retencion',NOW(3),NOW(3));" RECHAZO
probar "asiento: un bono con tasa de retencion colgada" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,withholding_rate_snapshot,withholding_basis_snapshot,description,occurred_at,created_at) VALUES (UUID(),$CR,'bonus',100.0000,'PEN',30.0,'LIR art. 54','Bono',NOW(3),NOW(3));" RECHAZO
probar "asiento: retencion con tasa imposible (120%)" \
 "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,withholding_rate_snapshot,withholding_basis_snapshot,description,occurred_at,created_at) VALUES (UUID(),$CR,'withholding',-450.0000,'PEN',120.0,'LIR art. 54','Retencion',NOW(3),NOW(3));" RECHAZO
probar "mayor: reescribir la tasa de una retencion ya asentada" \
 "UPDATE ledger_entries SET withholding_rate_snapshot=10.0 WHERE entry_type='withholding';" RECHAZO

echo ""
echo "--- Facturas ---"
probar "factura: borrador coherente" \
 "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,campaign_id,document_type,series,number,issue_date,due_date,currency_code,subtotal_amount,tax_amount,total_amount,status,issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,created_at) VALUES (UUID(),$LE,$CO,$TP,$CA,'invoice','F001',1,'2026-09-30','2026-10-30','PEN',10000.0000,1800.0000,11800.0000,'draft',$SNAP,NOW(3));" OK
probar "factura: total distinto de subtotal + impuesto" \
 "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,document_type,series,number,issue_date,due_date,currency_code,subtotal_amount,tax_amount,total_amount,status,issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,created_at) VALUES (UUID(),$LE,$CO,$TP,'invoice','F001',2,'2026-09-30','2026-10-30','PEN',10000.0000,1800.0000,11000.0000,'draft',$SNAP,NOW(3));" RECHAZO
probar "factura: vence antes de emitirse" \
 "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,document_type,series,number,issue_date,due_date,currency_code,subtotal_amount,tax_amount,total_amount,status,issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,created_at) VALUES (UUID(),$LE,$CO,$TP,'invoice','F001',3,'2026-09-30','2026-09-01','PEN',100.0000,0,100.0000,'draft',$SNAP,NOW(3));" RECHAZO
probar "factura: correlativo cero" \
 "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,document_type,series,number,issue_date,due_date,currency_code,subtotal_amount,tax_amount,total_amount,status,issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,created_at) VALUES (UUID(),$LE,$CO,$TP,'invoice','F001',0,'2026-09-30','2026-10-30','PEN',100.0000,0,100.0000,'draft',$SNAP,NOW(3));" RECHAZO
probar "factura: serie y correlativo repetidos en la misma sociedad" \
 "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,document_type,series,number,issue_date,due_date,currency_code,subtotal_amount,tax_amount,total_amount,status,issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,created_at) VALUES (UUID(),$LE,$CO,$TP,'invoice','F001',1,'2026-09-30','2026-10-30','PEN',100.0000,0,100.0000,'draft',$SNAP,NOW(3));" RECHAZO
probar "factura: mismo correlativo en otro tipo de documento" \
 "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,document_type,series,number,issue_date,due_date,currency_code,subtotal_amount,tax_amount,total_amount,status,issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,created_at) VALUES (UUID(),$LE,$CO,$TP,'credit_note','F001',1,'2026-09-30','2026-10-30','PEN',100.0000,0,100.0000,'draft',$SNAP,NOW(3));" OK
probar "factura: emitida sin sello de emision" \
 "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,document_type,series,number,issue_date,due_date,currency_code,subtotal_amount,tax_amount,total_amount,status,issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,created_at) VALUES (UUID(),$LE,$CO,$TP,'invoice','F001',4,'2026-09-30','2026-10-30','PEN',100.0000,0,100.0000,'issued',$SNAP,NOW(3));" RECHAZO
probar "factura: anulada sin fecha de anulacion" \
 "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,document_type,series,number,issue_date,due_date,currency_code,subtotal_amount,tax_amount,total_amount,status,issued_at,issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,created_at) VALUES (UUID(),$LE,$CO,$TP,'invoice','F001',5,'2026-09-30','2026-10-30','PEN',100.0000,0,100.0000,'voided',NOW(3),$SNAP,NOW(3));" RECHAZO
probar "factura: importes negativos" \
 "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,document_type,series,number,issue_date,due_date,currency_code,subtotal_amount,tax_amount,total_amount,status,issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,created_at) VALUES (UUID(),$LE,$CO,$TP,'invoice','F001',6,'2026-09-30','2026-10-30','PEN',-100.0000,0,-100.0000,'draft',$SNAP,NOW(3));" RECHAZO

# NOTA DE PORTABILIDAD: la subconsulta va envuelta en una tabla derivada
# `(SELECT x FROM (SELECT ...) t)` a proposito. MySQL 8 rechaza con el error 1093
# ("You can't specify target table ... in FROM clause") toda subconsulta que lea
# la MISMA tabla que la sentencia esta modificando. MariaDB lo permite, asi que
# la version directa pasaba en local y fallaba en CI. La tabla derivada se
# materializa antes y funciona en los dos motores. No la "simplifique".
INV="(SELECT id FROM (SELECT id FROM invoices WHERE document_type='invoice' AND number=1) t)"
echo ""
echo "--- Regimen tributario: se factura todo desde Peru (DEC-047) ---"
probar "regimen: cliente peruano, gravado con IGV 18%" \
 "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,document_type,series,number,issue_date,due_date,currency_code,subtotal_amount,tax_amount,total_amount,status,tax_regime,issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,created_at) VALUES (UUID(),$LE,$CO,$TP,'invoice','F001',20,'2026-09-30','2026-10-30','PEN',1000.0000,180.0000,1180.0000,'draft','gravado',$SNAP,NOW(3));" OK
probar "regimen: cliente del exterior, exportacion sin IGV" \
 "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,document_type,series,number,issue_date,due_date,currency_code,subtotal_amount,tax_amount,total_amount,status,tax_regime,issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,created_at) VALUES (UUID(),$LE,$CO,$TP,'invoice','F001',21,'2026-09-30','2026-10-30','USD',1000.0000,0,1000.0000,'draft','exportacion','CTS SAC','20603203896','Lima','Cliente Bogota','900123456','Cra 7 #1','CO',NOW(3));" OK
probar "regimen: EXPORTACION CON IGV (o no es exportacion, o hay error)" \
 "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,document_type,series,number,issue_date,due_date,currency_code,subtotal_amount,tax_amount,total_amount,status,tax_regime,issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,created_at) VALUES (UUID(),$LE,$CO,$TP,'invoice','F001',22,'2026-09-30','2026-10-30','USD',1000.0000,180.0000,1180.0000,'draft','exportacion','CTS SAC','20603203896','Lima','Cliente Bogota','900123456','Cra 7 #1','CO',NOW(3));" RECHAZO
probar "regimen: exportacion a un cliente domiciliado en Peru" \
 "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,document_type,series,number,issue_date,due_date,currency_code,subtotal_amount,tax_amount,total_amount,status,tax_regime,issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,created_at) VALUES (UUID(),$LE,$CO,$TP,'invoice','F001',23,'2026-09-30','2026-10-30','USD',1000.0000,0,1000.0000,'draft','exportacion',$SNAP,NOW(3));" RECHAZO
probar "regimen: exonerado con impuesto" \
 "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,document_type,series,number,issue_date,due_date,currency_code,subtotal_amount,tax_amount,total_amount,status,tax_regime,issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,created_at) VALUES (UUID(),$LE,$CO,$TP,'invoice','F001',24,'2026-09-30','2026-10-30','PEN',1000.0000,180.0000,1180.0000,'draft','exonerado',$SNAP,NOW(3));" RECHAZO
probar "regimen: inventado" \
 "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,document_type,series,number,issue_date,due_date,currency_code,subtotal_amount,tax_amount,total_amount,status,tax_regime,issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,created_at) VALUES (UUID(),$LE,$CO,$TP,'invoice','F001',25,'2026-09-30','2026-10-30','PEN',1000.0000,0,1000.0000,'draft','sin_igv',$SNAP,NOW(3));" RECHAZO
probar "regimen: gravado sin impuesto (valido: base exonerada por linea)" \
 "INSERT INTO invoices (uuid,legal_entity_id,client_organization_id,client_tax_profile_id,document_type,series,number,issue_date,due_date,currency_code,subtotal_amount,tax_amount,total_amount,status,tax_regime,issuer_legal_name_snapshot,issuer_tax_id_snapshot,issuer_address_snapshot,receiver_legal_name_snapshot,receiver_tax_id_snapshot,receiver_address_snapshot,receiver_country_snapshot,created_at) VALUES (UUID(),$LE,$CO,$TP,'invoice','F001',26,'2026-09-30','2026-10-30','PEN',1000.0000,0,1000.0000,'draft','gravado',$SNAP,NOW(3));" OK

echo ""
echo "--- Lineas de factura ---"
probar "linea: coherente" \
 "INSERT INTO invoice_lines (invoice_id,line_number,description,quantity,unit_price,line_subtotal,tax_rate,line_tax,line_total) VALUES ($INV,1,'Servicio de campana',1,10000.0000,10000.0000,0.1800,1800.0000,11800.0000);" OK
probar "linea: total distinto de subtotal + impuesto" \
 "INSERT INTO invoice_lines (invoice_id,line_number,description,quantity,unit_price,line_subtotal,tax_rate,line_tax,line_total) VALUES ($INV,2,'Otro',1,100.0000,100.0000,0.1800,18.0000,100.0000);" RECHAZO
probar "linea: cantidad cero" \
 "INSERT INTO invoice_lines (invoice_id,line_number,description,quantity,unit_price,line_subtotal,tax_rate,line_tax,line_total) VALUES ($INV,3,'Nada',0,100.0000,0,0,0,0);" RECHAZO
probar "linea: numero de linea repetido" \
 "INSERT INTO invoice_lines (invoice_id,line_number,description,quantity,unit_price,line_subtotal,tax_rate,line_tax,line_total) VALUES ($INV,1,'Duplicada',1,10.0000,10.0000,0,0,10.0000);" RECHAZO
probar "linea: borrar de una factura en borrador" \
 "INSERT INTO invoice_lines (invoice_id,line_number,description,quantity,unit_price,line_subtotal,tax_rate,line_tax,line_total) VALUES ($INV,9,'Temporal',1,1.0000,1.0000,0,0,1.0000); DELETE FROM invoice_lines WHERE line_number=9;" OK

echo ""
echo "--- Cobros del cliente ---"
# Preparacion, no asercion: pero si esto falla en silencio, la prueba que viene
# mide una factura que sigue en 'draft' y reporta lo contrario de la verdad.
# Paso exactamente eso: el UPDATE moria con el error 1093 y nadie se enteraba.
preparar=$($CLIENTE $DB -e "UPDATE invoices SET status='issued', issued_at=NOW(3) WHERE id=$INV;" 2>&1)
if echo "$preparar" | grep -qi "error"; then
  printf "  \033[31m!\033[0m %-64s FALLO LA PREPARACION\n" "emitir la factura para las pruebas de borrado"
  echo "      $(echo "$preparar" | grep -i error | head -1)"
  fail=$((fail+1))
fi
probar "linea: borrar de una factura ya emitida" \
 "DELETE FROM invoice_lines WHERE invoice_id=$INV AND line_number=1;" RECHAZO
probar "factura emitida: borrado fisico" \
 "DELETE FROM invoices WHERE id=$INV;" RECHAZO
probar "factura borrador: borrado fisico permitido" \
 "DELETE FROM invoices WHERE document_type='credit_note' AND number=1;" OK
probar "cobro: parcial" \
 "INSERT INTO payments (uuid,invoice_id,amount,currency_code,method,reference,received_on,created_at) VALUES (UUID(),$INV,5000.0000,'PEN','transfer','ABO-1',' 2026-10-05',NOW(3));" OK
probar "cobro: segundo parcial" \
 "INSERT INTO payments (uuid,invoice_id,amount,currency_code,method,reference,received_on,created_at) VALUES (UUID(),$INV,6800.0000,'PEN','transfer','ABO-2','2026-10-20',NOW(3));" OK
probar "cobro: importe cero" \
 "INSERT INTO payments (uuid,invoice_id,amount,currency_code,received_on,created_at) VALUES (UUID(),$INV,0,'PEN','2026-10-20',NOW(3));" RECHAZO
probar "cobro: medio inventado" \
 "INSERT INTO payments (uuid,invoice_id,amount,currency_code,method,received_on,created_at) VALUES (UUID(),$INV,10.0000,'PEN','trueque','2026-10-20',NOW(3));" RECHAZO
probar "cobro: borrado fisico" \
 "DELETE FROM payments WHERE reference='ABO-1';" RECHAZO

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
