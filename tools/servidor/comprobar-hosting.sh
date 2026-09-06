#!/bin/bash
# =============================================================================
# LATAM Social — ¿este hosting sirve? (docs/18 §0.2)
# Sólo LEE. No instala, no borra, no modifica nada excepto crear la carpeta
# de destino si no existe.
#
#   bash comprobar-hosting.sh                 # usa ~/apps/latamsocial
#   bash comprobar-hosting.sh /ruta/elegida   # usa esa carpeta
# =============================================================================
DESTINO="${1:-$HOME/apps/latamsocial}"
FALLOS=0; AVISOS=0
ok()    { printf '  \033[32mOK\033[0m    %s\n' "$1"; }
falta() { printf '  \033[31mFALTA\033[0m %s\n' "$1"; FALLOS=$((FALLOS+1)); }
aviso() { printf '  \033[33mAVISO\033[0m %s\n' "$1"; AVISOS=$((AVISOS+1)); }
tit()   { printf '\n\033[1m== %s ==\033[0m\n' "$1"; }

tit "0. Dónde estoy"
echo "  usuario : $(whoami)@$(hostname)"
echo "  home    : $HOME"
echo "  shell   : $SHELL"
echo "  disco   : $(df -Ph "$HOME" 2>/dev/null | tail -1)"

tit "1. Binarios de PHP que hay en la máquina"
which -a php php8.3 php83 php-cli 2>/dev/null | sed 's/^/  /'
ls -1 /opt/cpanel/ea-php83/root/usr/bin/php \
      /opt/cpanel/ea-php84/root/usr/bin/php \
      /opt/plesk/php/8.3/bin/php \
      /opt/plesk/php/8.4/bin/php \
      /usr/local/php83/bin/php \
      /usr/local/bin/ea-php83 \
      /usr/bin/php8.3 /usr/bin/php8.4 2>/dev/null | sed 's/^/  /'

PHP=""
for c in /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php \
         /opt/plesk/php/8.3/bin/php /opt/plesk/php/8.4/bin/php \
         /usr/local/php83/bin/php /usr/local/bin/ea-php83 /usr/bin/php8.3 /usr/bin/php8.4 \
         $(which -a php83 php8.3 php 2>/dev/null); do
  [ -x "$c" ] || continue
  v=$("$c" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)
  case "$v" in 8.3|8.4|8.5) PHP="$c"; break;; esac
done

tit "2. PHP que se usará"
if [ -z "$PHP" ]; then
  PHP="$(command -v php)"
  falta "no hay ningún PHP 8.3+ — composer.json exige ^8.3. Cámbialo en el panel."
  echo "  (sigo con $PHP sólo para informar: $("$PHP" -v 2>/dev/null | head -1))"
else
  ok "$PHP  →  $("$PHP" -v | head -1)"
  echo "  >>> ÉSTA es la ruta que va en el cron y en cada comando artisan."
fi

tit "3. Extensiones EXIGIDAS por composer.lock"
for e in ctype curl dom fileinfo filter hash iconv json libxml mbstring \
         openssl pcre session soap tokenizer zlib pdo pdo_mysql; do
  if "$PHP" -m 2>/dev/null | grep -qix "$e"; then ok "$e"; else falta "$e"; fi
done
echo "  --- opcionales (no bloquean, útiles más adelante) ---"
for e in gd intl zip bcmath exif; do
  "$PHP" -m 2>/dev/null | grep -qix "$e" && ok "$e (opcional)" || aviso "$e no está (opcional)"
done

tit "4. Lo que el hosting suele capar"
DIS=$("$PHP" -r 'echo ini_get("disable_functions");' 2>/dev/null)
echo "  disable_functions = ${DIS:-(vacío)}"
for f in proc_open proc_get_status symlink putenv exec shell_exec; do
  if echo ",$DIS," | tr -d ' ' | grep -q ",$f,"; then
    case "$f" in
      proc_open|proc_get_status) falta "$f deshabilitada → Composer NO puede correr aquí (docs/18 §0.5 opción B)";;
      symlink)                   aviso "$f deshabilitada → storage:link fallará (§0.9)";;
      *)                         aviso "$f deshabilitada";;
    esac
  else ok "$f disponible"; fi
done
ML=$("$PHP" -r 'echo ini_get("memory_limit");' 2>/dev/null)
echo "  memory_limit        = $ML"
case "$ML" in -1|1G|2G|[5-9][0-9][0-9]M|[2-9][0-9][0-9][0-9]M) ok "memoria suficiente";;
  *) aviso "memory_limit=$ML — usa: $PHP -d memory_limit=-1 composer.phar install";; esac
echo "  max_execution_time  = $("$PHP" -r 'echo ini_get("max_execution_time");' 2>/dev/null)"
echo "  upload_max_filesize = $("$PHP" -r 'echo ini_get("upload_max_filesize");' 2>/dev/null)"
echo "  post_max_size       = $("$PHP" -r 'echo ini_get("post_max_size");' 2>/dev/null)"

tit "5. Herramientas"
for t in git unzip tar curl; do
  command -v "$t" >/dev/null && ok "$t → $(command -v $t)" || falta "$t no está"
done
if command -v composer >/dev/null; then ok "composer → $(composer --version 2>/dev/null | head -1)"
else aviso "composer no está en el PATH (se instala con composer.phar, §0.5)"; fi
if command -v node >/dev/null; then
  NV=$(node -v); MAJ=${NV#v}; MAJ=${MAJ%%.*}
  if [ "${MAJ:-0}" -ge 20 ] 2>/dev/null; then ok "node $NV (sirve para compilar assets aquí)"
  else aviso "node $NV es < 20 → compila los assets en tu Windows (§0.6 opción B)"; fi
else aviso "no hay node → compila los assets en tu Windows y sube public/build/ (§0.6 opción B)"; fi
command -v mysql >/dev/null && ok "cliente mysql → $(mysql --version)" \
  || aviso "no hay cliente mysql por consola (usarás phpMyAdmin del panel)"

tit "6. La carpeta de destino"
mkdir -p "$DESTINO" 2>/dev/null
if [ -d "$DESTINO" ] && [ -w "$DESTINO" ]; then ok "existe y es escribible: $DESTINO"
else falta "no puedo crear o escribir en $DESTINO"; fi
case "$DESTINO" in
  *public_html*|*httpdocs*|*www*) falta "$DESTINO está DENTRO de la raíz web: el .env sería descargable (§0.4)";;
  *) ok "está fuera de la raíz web (bien)";;
esac
echo "  raíces web que veo en tu home:"
RAICES=$(ls -ld "$HOME"/public_html "$HOME"/httpdocs "$HOME"/www "$HOME"/domains/*/public_html 2>/dev/null)
[ -n "$RAICES" ] && echo "$RAICES" | sed 's/^/    /' || echo "    (ninguna — dime cómo se llama la del panel)"

tit "7. Salida a internet desde el servidor"
probar() { code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 12 "$1" 2>/dev/null)
  [ "$code" != "000" ] && [ -n "$code" ] && ok "$2 ($code)" || aviso "$2 SIN SALIDA"; }
probar https://repo.packagist.org/packages.json "packagist (composer install)"
probar https://github.com                        "github (git clone)"
probar https://registry.npmjs.org/               "npm registry"
probar https://fonts.bunny.net/css?family=inter  "fonts.bunny.net (tipografías de la portada)"
probar https://api.decolecta.com                 "decolecta (tipos de cambio)"
probar https://e-beta.sunat.gob.pe               "SUNAT beta"

tit "8. Resumen"
echo "  Fallos que bloquean: $FALLOS"
echo "  Avisos (se pueden rodear): $AVISOS"
[ "$FALLOS" -eq 0 ] && echo "  → Requisitos de PHP cubiertos. Falta la prueba de MySQL 5.7 (docs/18 §0.3)." \
                    || echo "  → Hay que resolver los FALTA antes de subir código."
echo
echo "  Pega TODA esta salida en el chat."
