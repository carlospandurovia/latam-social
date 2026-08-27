#!/bin/bash
# Los cuatro ayudantes que usan TODAS las suites de restriccion.
#
# ### Por que existe este archivo (8.11)
#
# Estaban copiados en las treinta suites. El QA de la Fase 8 los midio y salieron
# SEIS variantes distintas de lo mismo, y la diferencia no era cosmetica:
#
#   * **Nueve suites no tenian la guarda de conexion.** Sin ella, un motor caido
#     hace que todas las aserciones de rechazo "pasen" --porque no poder conectar
#     tambien es fallar-- y el informe sale verde contra un socket muerto. Eso ya
#     habia pasado de verdad --2.13 lo tiene escrito: «25 aserciones en verde
#     contra un socket muerto»-- y nueve suites escritas DESPUES de esa leccion
#     nunca la recibieron, porque la leccion se arreglo en un archivo y habia
#     treinta.
#   * `porque()` --que afirma POR QUE se rechaza, y no solo que se rechazo--
#     nacio en 8.1 y solo llego a once de las treinta.
#   * Tres anchuras de `printf` distintas, que es lo de menos y es el sintoma.
#
# Una leccion de seguridad repetida treinta veces es una leccion que un dia se
# aprende en un sitio. Es literalmente lo que paso con `SUITES` en 3.12, y por
# eso aquello vive tambien en un archivo unico.
#
# Se usa asi, despues de fijar `DB` y `CLIENTE`:
#
#   source "$(dirname "$0")/comun.sh"

ok=0; fail=0

# .Fallo la conexion? Un motor caido no es un rechazo.
#
# Sin esta distincion, cada `probar ... RECHAZO` se pone verde con la base
# apagada: no poder conectar tambien produce la palabra ERROR. La suite entera
# diria que todo se rechaza correctamente sin haber preguntado nada.
_sin_base() {
  echo "$1" | grep -qiE "ERROR (2002|2003|2005|1045|1049)|Can't connect|Unknown database|Access denied"
}

_bien() { printf "  \033[32m✓\033[0m %-70s %s\n" "$1" "$2"; ok=$((ok+1)); }
_mal()  { printf "  \033[31m✗\033[0m %-70s %s\n" "$1" "$2"; fail=$((fail+1)); }

# probar <titulo> <sql> <OK|RECHAZO>
#
# Cuando se espera RECHAZO y se cumple, se enseña ADEMAS por que se rechazo, en
# gris. No es adorno: un rechazo solo prueba algo si rechaza por SU motivo, y
# `porque()` es la forma de afirmarlo. Mientras una asercion siga siendo un
# `probar ... RECHAZO` a secas, esto deja el motivo a la vista de quien lea la
# salida --que es como se cazaron `T-48`, `T-50`, `T-51` y `T-54`--.
probar() {
  salida=$($CLIENTE $DB -e "$2" 2>&1)

  if _sin_base "$salida"; then
    printf "  \033[31m!\033[0m %-70s LA BASE NO RESPONDE\n" "$1"
    echo "      $(echo "$salida" | grep -i error | head -1)"
    fail=$((fail+1)); return
  fi

  if [ -z "$salida" ] || ! echo "$salida" | grep -qi "ERROR"; then real="OK"; else real="RECHAZO"; fi

  if [ "$real" == "$3" ]; then
    if [ "$real" == "RECHAZO" ]; then
      motivo=$(echo "$salida" | grep -oE '(ck|uq|fk|tg)_[a-z0-9_]+' | head -1)
      _bien "$1" "RECHAZO$([ -n "$motivo" ] && printf ' \033[90m%s\033[0m' "$motivo")"
    else
      _bien "$1" "$real"
    fi
  else
    _mal "$1" "esperaba $3, obtuvo $real"
    echo "      $(echo "$salida" | grep -i error | head -1)"
  fi
}

# valor <titulo> <sql> <esperado>
valor() {
  real=$($CLIENTE $DB -N -B -e "$2" 2>&1 | grep -v '^mysql: \[Warning\]' | tr -d '\r')

  if _sin_base "$real"; then
    printf "  \033[31m!\033[0m %-70s LA BASE NO RESPONDE\n" "$1"
    fail=$((fail+1)); return
  fi

  if [ "$real" == "$3" ]; then _bien "$1" "$real"
  else _mal "$1" "esperaba '$3', obtuvo '$real'"; fi
}

# porque <titulo> <sql> <fragmento del motivo>
#
# La forma buena de afirmar un rechazo: no vale con que falle, tiene que fallar
# POR ESO. Nacio en 8.1 despues de que cinco aserciones de aquella suite
# estuvieran verdes por un `1451` que no tenia nada que ver con lo que probaban.
porque() {
  salida=$($CLIENTE $DB -e "$2" 2>&1)

  if _sin_base "$salida"; then
    printf "  \033[31m!\033[0m %-70s LA BASE NO RESPONDE\n" "$1"
    fail=$((fail+1)); return
  fi

  if echo "$salida" | grep -q "$3"; then _bien "$1" "$3"
  else _mal "$1" "esperaba rechazo por '$3'"
       echo "      $(echo "$salida" | grep -i error | head -1)"; fi
}

# El resumen del final, igual para todas.
resumen() {
  echo ""
  echo "==================================================================================="
  printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
  echo "==================================================================================="
  [ $fail -eq 0 ]
}
