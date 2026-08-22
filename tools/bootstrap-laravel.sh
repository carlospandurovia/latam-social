#!/usr/bin/env bash
# LATAM Social — arranque del proyecto Laravel (Linux / macOS / WSL).
# Equivalente a tools/bootstrap-laravel.ps1. Idempotente.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

paso()  { printf '\n\033[36m>> %s\033[0m\n' "$1"; }
ok()    { printf '   \033[32m%s\033[0m\n' "$1"; }
aviso() { printf '   \033[33m%s\033[0m\n' "$1"; }

paso "Verificando requisitos"
# Mismos escapes que la version de PowerShell:
#   PHP_MINIMO=8.2 ./tools/bootstrap-laravel.sh
#   LARAVEL_VERSION='^12.0' ./tools/bootstrap-laravel.sh
export PHP_MINIMO="${PHP_MINIMO:-8.3}"
command -v php >/dev/null || { echo "Falta PHP ${PHP_MINIMO}+"; exit 1; }
PHPV=$(php -n -r 'echo PHP_VERSION;')
php -n -r 'exit(version_compare(PHP_VERSION, getenv("PHP_MINIMO").".0", ">=") ? 0 : 1);' \
  || { echo "PHP $PHPV es muy antiguo, hace falta ${PHP_MINIMO}+. Para arrancar igualmente: PHP_MINIMO=8.2 $0"; exit 1; }
ok "PHP $PHPV"
command -v composer >/dev/null || { echo "Falta Composer"; exit 1; }
ok "Composer $(composer --version | sed 's/Composer version //; s/ .*//')"
for e in mbstring intl pdo_mysql openssl zip gd bcmath curl fileinfo soap; do
  php -m | grep -qix "$e" || aviso "Extensión ausente: $e"
done

paso "Esqueleto de Laravel"
if [ -f artisan ]; then
  ok "Ya existe: no se toca"
else
  rm -rf .laravel-tmp
  # Version fijada a proposito: sin fijarla, Composer instalaria la ultima rama,
  # que puede exigir un PHP mas nuevo y dejar de coincidir con la documentacion.
  composer create-project "laravel/laravel:${LARAVEL_VERSION:-^12.0}" .laravel-tmp --no-interaction --prefer-dist --quiet
  copiados=0; respetados=0
  while IFS= read -r -d '' f; do
    rel="${f#.laravel-tmp/}"
    case "$rel" in vendor/*|node_modules/*|.git/*) continue;; esac
    if [ -e "$rel" ]; then respetados=$((respetados+1)); continue; fi
    mkdir -p "$(dirname "$rel")"; cp "$f" "$rel"; copiados=$((copiados+1))
  done < <(find .laravel-tmp -type f -print0)
  rm -rf .laravel-tmp
  ok "$copiados archivos copiados, $respetados respetados por ya existir"
fi

paso "Árbol de módulos (docs/03 §1.1)"
mods="Identity Core Creator Crm Client Campaign Matching Content Measurement Finance Communication Intelligence Gamification"
for m in $mods; do
  for c in Domain Application Infrastructure Http Database/Migrations Tests; do
    mkdir -p "app/Modules/$m/$c"; touch "app/Modules/$m/$c/.gitkeep"
  done
done
mkdir -p app/Shared && touch app/Shared/.gitkeep
ok "13 módulos"
aviso "Los README por módulo los genera la versión PowerShell; en Linux créalos si los quieres."

paso "Configurando composer.json"
php tools/patch-composer.php

paso "Instalando dependencias"
composer install --no-interaction --prefer-dist
for p in laravel/pint larastan/larastan qossmic/deptrac pestphp/pest pestphp/pest-plugin-laravel; do
  [ -d "vendor/$p" ] || composer require --dev --no-interaction "$p"
done
ok "Dependencias listas"

paso "Entorno"
[ -f .env ] || { cp .env.example .env; ok ".env creado"; }
php artisan key:generate --ansi
[ -f package.json ] && command -v npm >/dev/null && npm install --silent && ok "npm listo"

printf '\n\033[32m=====================================================\n Proyecto listo.\n=====================================================\033[0m\n'
cat <<'TXT'

Siguiente:
  1. Edita .env con tu MySQL (crea la base 'latam_social').
  2. php artisan migrate
  3. php artisan serve

Calidad (lo mismo que corre CI):
  composer quality      formato + estático + fronteras + pruebas
  composer arch         solo las fronteras entre módulos
TXT
