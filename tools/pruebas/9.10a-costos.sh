#!/bin/bash
# Pruebas de restriccion de la iteracion 9.10a: el gasto de una campana.
#
#   ck_cco_descripcion   una cifra sin descripcion no se puede auditar
#   ck_cco_amount        un costo no es negativo (Fase 2)
#   ck_cco_type          y su tipo es uno de los seis (Fase 2)
#   tg_cco_fecha         un gasto no se incurre en el futuro
#   tg_cco_inmutable     un costo no se reescribe: se anula y se vuelve a anotar
#   tg_cco_inmutable     y un anulado no se desanula
#   ck_cco_voided        anular exige fecha, responsable y motivo (Fase 2)
#   tg_cco_no_delete     un costo no se borra (Fase 2)
#
# `campaign_costs` llevaba desde la Fase 2 con sus restricciones puestas y CERO
# filas, asi que hasta hoy ninguna de las de la Fase 2 habia contestado nunca a
# nadie. Esta suite es la primera vez que se les pregunta.
#
# Uso: bash tools/pruebas/9.10a-costos.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.10a - El gasto de una campana"
echo "==================================================================================="

USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"
MON="(SELECT code FROM (SELECT code FROM currencies ORDER BY code LIMIT 1) m)"
CAM="(SELECT id FROM (SELECT id FROM campaigns ORDER BY id LIMIT 1) c)"

valor "hay una campana en la semilla" \
  "SELECT CASE WHEN COUNT(*) > 0 THEN 'si' ELSE 'no' END FROM campaigns;" "si"

# La suite limpia lo suyo al EMPEZAR y no al terminar: `tg_cco_no_delete`
# prohibe borrar un costo, que es una de las cosas que aqui se prueban. Lo que
# se puede hacer es no dejar que la pasada anterior estorbe... y tampoco: el
# disparador vale para todos. Asi que se usan descripciones propias y se afirman
# CONTANDO las suyas, no todas.
echo ""
echo "-- Lo que la Fase 2 dejo escrito, y hoy se pregunta por primera vez --"

porque "un costo sin descripcion" \
  "INSERT INTO campaign_costs (campaign_id,cost_type,description,amount,currency_code,incurred_on,created_by_user_id,created_at)
   VALUES ($CAM,'product','',100,$MON,CURDATE(),$USR,NOW(3));" \
  "ck_cco_descripcion|sin descripcion"

porque "ni una descripcion de puros espacios" \
  "INSERT INTO campaign_costs (campaign_id,cost_type,description,amount,currency_code,incurred_on,created_by_user_id,created_at)
   VALUES ($CAM,'product','   ',100,$MON,CURDATE(),$USR,NOW(3));" \
  "ck_cco_descripcion|sin descripcion"

porque "un importe negativo" \
  "INSERT INTO campaign_costs (campaign_id,cost_type,description,amount,currency_code,incurred_on,created_by_user_id,created_at)
   VALUES ($CAM,'product','9.10a negativo',-1,$MON,CURDATE(),$USR,NOW(3));" \
  "ck_cco_amount|no puede ser negativo"

porque "un tipo que no existe" \
  "INSERT INTO campaign_costs (campaign_id,cost_type,description,amount,currency_code,incurred_on,created_by_user_id,created_at)
   VALUES ($CAM,'catering','9.10a tipo raro',100,$MON,CURDATE(),$USR,NOW(3));" \
  "ck_cco_type|Tipo de costo no valido"

echo ""
echo "-- Un gasto se incurre en el pasado --"

porque "dentro de un mes, no" \
  "INSERT INTO campaign_costs (campaign_id,cost_type,description,amount,currency_code,incurred_on,created_by_user_id,created_at)
   VALUES ($CAM,'shipping','9.10a futuro',100,$MON,DATE_ADD(CURDATE(), INTERVAL 30 DAY),$USR,NOW(3));" \
  "no se incurre en el futuro"

# El dia de margen es deliberado: la maquina y el usuario pueden estar en husos
# distintos, y rechazar el gasto de hoy porque en UTC ya es manana seria un
# error que nadie sabria explicar.
probar "manana si, por el huso horario" \
  "INSERT INTO campaign_costs (campaign_id,cost_type,description,amount,currency_code,incurred_on,created_by_user_id,created_at)
   VALUES ($CAM,'shipping','9.10a manana',1,$MON,DATE_ADD(CURDATE(), INTERVAL 1 DAY),$USR,NOW(3));" OK

probar "y el gasto normal, con su fecha de ayer" \
  "INSERT INTO campaign_costs (campaign_id,cost_type,description,amount,currency_code,incurred_on,created_by_user_id,created_at)
   VALUES ($CAM,'product','9.10a producto',250.5000,$MON,DATE_SUB(CURDATE(), INTERVAL 1 DAY),$USR,NOW(3));" OK

COSTO="(SELECT id FROM (SELECT id FROM campaign_costs WHERE description='9.10a producto' ORDER BY id DESC LIMIT 1) x)"

echo ""
echo "-- Un costo no se reescribe --"

porque "cambiarle el importe" \
  "UPDATE campaign_costs SET amount=25 WHERE id=$COSTO;" "no se reescribe"

porque "ni la fecha" \
  "UPDATE campaign_costs SET incurred_on=CURDATE() WHERE id=$COSTO;" "no se reescribe"

porque "ni la descripcion" \
  "UPDATE campaign_costs SET description='9.10a otra cosa' WHERE id=$COSTO;" "no se reescribe"

porque "ni la campana a la que carga" \
  "UPDATE campaign_costs SET campaign_id=campaign_id+1 WHERE id=$COSTO;" "no se reescribe"

echo ""
echo "-- Anular: con las tres cosas --"

porque "anular sin decir quien ni por que" \
  "UPDATE campaign_costs SET voided_at=NOW(3) WHERE id=$COSTO;" \
  "ck_cco_voided|exige fecha, responsable y motivo"

porque "y sin el motivo, tampoco" \
  "UPDATE campaign_costs SET voided_at=NOW(3), voided_by_user_id=$USR WHERE id=$COSTO;" \
  "ck_cco_voided|exige fecha, responsable y motivo"

probar "con las tres, se anula" \
  "UPDATE campaign_costs SET voided_at=NOW(3), voided_by_user_id=$USR,
      voided_reason='Estaba cargado a la campana equivocada.' WHERE id=$COSTO;" OK

porque "y un costo anulado no se desanula" \
  "UPDATE campaign_costs SET voided_at=NULL, voided_by_user_id=NULL, voided_reason=NULL
    WHERE id=$COSTO;" "no se desanula"

porque "borrarlo, nunca" \
  "DELETE FROM campaign_costs WHERE id=$COSTO;" "no se borra"

valor "el costo sigue ahi, anulado y con su motivo" \
  "SELECT CASE WHEN voided_reason IS NOT NULL THEN 'si' ELSE 'no' END
     FROM campaign_costs WHERE id=$COSTO;" "si"

# Que un gasto se pueda anotar en CUALQUIER estado de la campana --el producto
# se compra antes de confirmar, y una campana cancelada puede tener gastos de
# verdad-- se afirma en PHPUnit y no aqui: la semilla tiene una sola campana y
# crear otra en borrador exige sociedad, cobertura y media docena de reglas de
# `7.1`. Alli hay fixturas que ya saben construirla; aqui seria copiarlas.

resumen
