<#
    LATAM Social — instalador de Composer para Windows

    Por qué existe: el instalador oficial .exe de Composer graba en composer.bat
    la ruta del php.exe que encontró EN SU MOMENTO. Si se instaló con XAMPP
    presente, Composer seguirá usando PHP 8.2 aunque la consola ya use 8.3, y
    resolvería las dependencias contra la plataforma equivocada.

    Este script instala composer.phar DENTRO de la carpeta de PHP y crea un
    composer.bat que invoca 'php' del PATH. Así Composer siempre usa el mismo
    PHP que la consola, hoy y cuando cambies de versión.

    Verifica la firma SHA-384 que publica el proyecto Composer antes de ejecutar
    nada, siguiendo el procedimiento oficial.

    Uso:  powershell -ExecutionPolicy Bypass -File tools\instalar-composer.ps1
#>

param(
    # Dónde dejar composer.phar y composer.bat. Por defecto, junto a php.exe:
    # esa carpeta ya está en el PATH, así que no hace falta tocarlo otra vez.
    [string]$Destino = '',
    [switch]$Forzar
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

function Paso($t)  { Write-Host "`n>> $t" -ForegroundColor Cyan }
function Ok($t)    { Write-Host "   $t" -ForegroundColor Green }
function Aviso($t) { Write-Host "   $t" -ForegroundColor Yellow }
function Malo($t)  { Write-Host "   $t" -ForegroundColor Red }

function Salida([string]$exe, [string[]]$argumentos) {
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try   { $out = & $exe @argumentos 2>&1 | ForEach-Object { "$_" } }
    finally { $ErrorActionPreference = $prev }
    return ($out -join "`n")
}

Write-Host ""
Write-Host "  Instalador de Composer para LATAM Social" -ForegroundColor Cyan

# ------------------------------------------------------------------ 1. PHP
Paso "Comprobando PHP"

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Malo "No encuentro PHP en el PATH."
    Aviso "Lanza antes:  powershell -ExecutionPolicy Bypass -File tools\instalar-php83.ps1"
    throw "PHP ausente."
}
$phpExe = $php.Source
$verPhp = (Salida $phpExe @('-n','-r','echo PHP_VERSION;')).Trim()
Ok "PHP $verPhp  ($phpExe)"

if (-not $Destino) { $Destino = Split-Path -Parent $phpExe }
Ok "Destino: $Destino"

# Composer necesita openssl para hablar con los repositorios por HTTPS.
$mods = (Salida $phpExe @('-m')) -split "`r?`n" | ForEach-Object { $_.Trim().ToLower() }
foreach ($e in @('openssl','zip','mbstring')) {
    if ($mods -notcontains $e) { Aviso "Falta la extensión '$e'; Composer puede fallar." }
}

# ------------------------------------------------- 2. ¿Ya hay otro Composer?
Paso "Buscando instalaciones previas"

$previo = Get-Command composer -ErrorAction SilentlyContinue
if ($previo) {
    $infoPrevio = Salida composer @('--version')
    Aviso "Ya hay un Composer en: $($previo.Source)"
    if ($infoPrevio -match 'PHP\s+version\s+(\d+\.\d+\.\d+)') {
        $phpPrevio = $Matches[1]
        if ($phpPrevio -ne $verPhp) {
            Malo "Ese Composer corre sobre PHP $phpPrevio, no sobre $verPhp. Es justo el problema a resolver."
        } else {
            Ok "Y ya usa PHP $phpPrevio, el correcto."
            if (-not $Forzar) {
                Aviso "No hace falta reinstalar. Para hacerlo igualmente:  -Forzar"
                return
            }
        }
    }
}

# ------------------------------------------------ 3. Descargar y verificar
Paso "Descargando el instalador oficial"

$tmp   = Join-Path $env:TEMP "composer-setup-$(Get-Date -Format 'yyyyMMddHHmmss').php"
$prog  = $ProgressPreference; $ProgressPreference = 'SilentlyContinue'
try {
    Invoke-WebRequest -Uri 'https://getcomposer.org/installer' -OutFile $tmp -UseBasicParsing -TimeoutSec 120
    $firmaEsperada = (Invoke-WebRequest -Uri 'https://composer.github.io/installer.sig' -UseBasicParsing -TimeoutSec 60).Content.Trim().ToLower()
} finally {
    $ProgressPreference = $prog
}

# El procedimiento oficial de Composer usa SHA-384 sobre el instalador.
$firmaLocal = (Get-FileHash -Path $tmp -Algorithm SHA384).Hash.ToLower()
if ($firmaLocal -ne $firmaEsperada) {
    Remove-Item $tmp -Force -ErrorAction SilentlyContinue
    Malo "La firma SHA-384 del instalador NO coincide con la publicada."
    Malo "  esperada: $firmaEsperada"
    Malo "  obtenida: $firmaLocal"
    throw "Instalador corrupto o manipulado. No se ejecuta nada."
}
Ok "Firma SHA-384 verificada"

# -------------------------------------------------------------- 4. Instalar
Paso "Instalando composer.phar"

if (-not (Test-Path $Destino)) { New-Item -ItemType Directory -Path $Destino -Force | Out-Null }

$salidaInst = Salida $phpExe @($tmp, "--install-dir=$Destino", '--filename=composer.phar')
Remove-Item $tmp -Force -ErrorAction SilentlyContinue

$phar = Join-Path $Destino 'composer.phar'
if (-not (Test-Path $phar)) {
    Malo "El instalador no dejó composer.phar. Salida:"
    Write-Host $salidaInst -ForegroundColor DarkGray
    throw "Instalación fallida."
}
Ok "composer.phar en $phar"

# El .bat NO clava ninguna ruta de php.exe: invoca 'php' del PATH. Esa es toda
# la diferencia con el instalador oficial, y es lo que evita que Composer se
# quede atado a una versión de PHP vieja.
$bat = Join-Path $Destino 'composer.bat'
@(
    '@echo off'
    'REM Generado por tools/instalar-composer.ps1 (LATAM Social).'
    'REM Usa deliberadamente el php del PATH, no una ruta fija.'
    'php "%~dp0composer.phar" %*'
) | Set-Content -Path $bat -Encoding ASCII
Ok "composer.bat creado (usa el php del PATH)"

# ----------------------------------------------------------- 5. Verificación
Paso "Verificando"

$env:Path = $Destino + ';' + (($env:Path -split ';' | Where-Object { $_ -and ($_.TrimEnd('\') -ne $Destino.TrimEnd('\')) }) -join ';')

$info = Salida $bat @('--version')
if ($info -match 'Composer\s+version\s+(\S+)') { Ok "Composer $($Matches[1])" }
else { Malo "No pude leer la versión. Salida:`n$info" }

if ($info -match 'PHP\s+version\s+(\d+\.\d+\.\d+)') {
    if ($Matches[1] -eq $verPhp) { Ok "Composer usa PHP $($Matches[1]) — el mismo de la consola" }
    else { Malo "Composer usa PHP $($Matches[1]) y la consola $verPhp. Revisa 'where.exe composer'." }
}

$resuelto = Get-Command composer -ErrorAction SilentlyContinue
if ($resuelto -and $resuelto.Source.TrimEnd('\').StartsWith($Destino.TrimEnd('\'))) {
    Ok "'composer' resuelve a $($resuelto.Source)"
} elseif ($resuelto) {
    Aviso "Ojo: 'composer' aún resuelve a $($resuelto.Source)"
    Aviso "Hay otro composer antes en el PATH. Renómbralo o quita su carpeta del PATH."
}

Write-Host ""
Write-Host "  Listo. En una consola NUEVA:" -ForegroundColor Green
Write-Host "    composer -V"
Write-Host "    powershell -ExecutionPolicy Bypass -File tools\bootstrap-laravel.ps1"
Write-Host ""
