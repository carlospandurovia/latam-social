# LATAM Social - subir un commit ya hecho, con las cuatro puertas por delante.
#
#   .\tools\subir.ps1              # puertas -> push -> espera a CI -> informe
#   .\tools\subir.ps1 -SaltarCI    # puertas -> push, sin esperar a CI
#   .\tools\subir.ps1 -SoloCI      # ni puertas ni push: solo consultar CI
#
# Por que existe: el push lo hace usted, y esta bien que sea asi, pero teclear
# cuatro comandos en orden invita a saltarse el primero. Aqui el orden esta
# fijado: si alguna puerta falla, NO empuja. `H-08` entro por saltarse CI tres
# iteraciones seguidas.
#
# Ninguna salida se redirige con `>`. PowerShell escribiria UTF-16 con BOM y
# convertiria stderr en objetos de error; los informes los escriben en UTF-8 los
# propios scripts de PHP.

param(
    [switch] $SaltarCI,
    [switch] $SoloCI,
    [int]    $EsperaMaximaSegundos = 900
)

$ErrorActionPreference = 'Continue'
Set-Location (Split-Path -Parent $PSScriptRoot)

function Titulo($texto) {
    Write-Host ''
    Write-Host ('=' * 78) -ForegroundColor DarkGray
    Write-Host "  $texto" -ForegroundColor Cyan
    Write-Host ('=' * 78) -ForegroundColor DarkGray
}

function Abortar($texto) {
    Write-Host ''
    Write-Host "  $texto" -ForegroundColor Red
    Write-Host ''
    exit 1
}

if (-not $SoloCI) {

    Titulo 'Estado del arbol de trabajo'

    $sucio = git --no-optional-locks status --porcelain
    if ($sucio) {
        Write-Host '  Hay cambios sin confirmar:' -ForegroundColor Yellow
        $sucio | ForEach-Object { Write-Host "    $_" }
        Abortar 'Confirmelos (o descartelos) antes de empujar. Este script no hace commits.'
    }
    Write-Host '  Limpio.' -ForegroundColor Green

    # '@{u}' entre comillas simples a proposito: sin ellas PowerShell lo lee
    # como una tabla hash literal y le pasa a git cualquier cosa menos el
    # nombre de la rama de seguimiento.
    $pendientes = git --no-optional-locks rev-list --count '@{u}..HEAD'
    if ($LASTEXITCODE -ne 0) {
        Abortar 'No pude comparar con la rama remota. .Tiene upstream configurado esta rama?'
    }
    if ([int]$pendientes -eq 0) {
        Write-Host '  Nada que empujar: HEAD ya esta en la rama remota.' -ForegroundColor Yellow
        Write-Host '  Paso directo a consultar CI.' -ForegroundColor DarkGray
        $SaltarPush = $true
    } else {
        Write-Host "  $pendientes commit(s) por empujar:" -ForegroundColor Green
        git --no-optional-locks log --oneline '@{u}..HEAD'
        $SaltarPush = $false
    }

    if (-not $SaltarPush) {

        Titulo 'Las cuatro puertas (Pint, PHPStan, Deptrac, PHPUnit)'

        php tools\diagnostico.php
        if ($LASTEXITCODE -ne 0) {
            Abortar 'Alguna puerta fallo. NO se ha empujado nada. Detalle en tools\diagnostico.txt'
        }

        Titulo 'Empujando'

        git push
        if ($LASTEXITCODE -ne 0) {
            Abortar 'El push fallo. Revise el mensaje de git de aqui arriba.'
        }
        Write-Host '  Empujado.' -ForegroundColor Green
    }
}

if ($SaltarCI) {
    Write-Host ''
    Write-Host '  -SaltarCI: no espero a GitHub. Consulte luego con: php tools\ci-github.php' -ForegroundColor DarkGray
    Write-Host ''
    exit 0
}

Titulo 'Esperando a CI'

# GitHub tarda unos segundos en registrar la ejecucion. Preguntar de inmediato
# devuelve la ANTERIOR, que casi siempre esta en verde: el peor falso positivo
# posible aqui, porque diria "todo bien" del commit equivocado.
Write-Host '  Dando 15 s a GitHub para que registre la ejecucion...' -ForegroundColor DarkGray
Start-Sleep -Seconds 15

$inicio = Get-Date
while ($true) {
    php tools\ci-github.php
    $codigo = $LASTEXITCODE

    $texto = ''
    if (Test-Path 'tools\ci-github.txt') {
        $texto = Get-Content 'tools\ci-github.txt' -Raw
    }

    if ($codigo -eq 0) {
        Write-Host ''
        Write-Host '  CI en verde.' -ForegroundColor Green
        Write-Host ''
        exit 0
    }

    if ($texto -notmatch 'El flujo sigue corriendo') {
        Write-Host ''
        Write-Host '  CI en rojo. El log del paso que fallo esta en tools\ci-github.txt' -ForegroundColor Red
        Write-Host ''
        exit 1
    }

    $transcurrido = ((Get-Date) - $inicio).TotalSeconds
    if ($transcurrido -gt $EsperaMaximaSegundos) {
        Write-Host ''
        Write-Host "  Sigue corriendo tras $EsperaMaximaSegundos s. Lo dejo aqui." -ForegroundColor Yellow
        Write-Host '  Consulte luego con: php tools\ci-github.php' -ForegroundColor DarkGray
        Write-Host ''
        exit 2
    }

    Write-Host '  Sigue corriendo. Reintento en 30 s...' -ForegroundColor DarkGray
    Start-Sleep -Seconds 30
}
