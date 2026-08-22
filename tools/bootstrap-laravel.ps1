<#
    LATAM Social — arranque del proyecto Laravel (Windows / PowerShell)

    Qué hace:
      1. Verifica PHP >= 8.3, Composer y Node.
      2. Crea el esqueleto de Laravel 12 SIN pisar nada de lo que ya existe
         (docs/, marca/, design/, tools/, README.md y toda la configuración
         que ya está versionada mandan sobre el esqueleto).
      3. Crea el árbol de módulos de docs/03 §1.1 con su README por módulo.
      4. Parchea composer.json: autoload PSR-4 de los módulos y atajos.
      5. Instala las herramientas de calidad: Pint, PHPStan/Larastan, Deptrac, Pest.
      6. Genera .env y la clave de aplicación.

    Es idempotente: se puede volver a ejecutar sin romper nada.

    Uso:   cd D:\Proyectos\Influencers\ManageCampaingInfluencer
           powershell -ExecutionPolicy Bypass -File tools\bootstrap-laravel.ps1
#>

param(
    # Laravel 12 arranca con 8.2, pero el proyecto se define sobre 8.3 (DEC-001)
    # y PHP 8.2 pierde soporte de seguridad en diciembre de 2026 — antes de que
    # este MVP esté en producción. Se puede rebajar a conciencia:
    #     .\tools\bootstrap-laravel.ps1 -PhpMinimo 8.2
    [string]$PhpMinimo = '8.3',

    # Rama de Laravel a instalar. Fijada para que el esqueleto sea reproducible
    # y no cambie solo porque salio una version nueva entre dos ejecuciones.
    [string]$LaravelVersion = '^12.0'
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

function Paso($t) { Write-Host "`n>> $t" -ForegroundColor Cyan }
function Ok($t)   { Write-Host "   $t" -ForegroundColor Green }
function Aviso($t){ Write-Host "   $t" -ForegroundColor Yellow }
function Malo($t) { Write-Host "   $t" -ForegroundColor Red }

# ---------------------------------------------------------------- 1. Requisitos
Paso "Verificando requisitos"

# PHP escribe sus advertencias de arranque en la misma salida, así que nunca
# damos por hecho que estos comandos devuelven una sola línea limpia.
function Salida([string]$exe, [string[]]$argumentos) {
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try   { $out = & $exe @argumentos 2>&1 | ForEach-Object { "$_" } }
    finally { $ErrorActionPreference = $prev }
    return ($out -join "`n")
}

# Composer y npm escriben su progreso en stderr. Con ErrorActionPreference='Stop'
# eso bastaría para abortar el script aunque el comando termine bien, así que
# cada llamada nativa se aísla y se juzga por su código de salida.
function Ejecutar([string]$exe, [string[]]$argumentos, [string]$queHace) {
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try   { & $exe @argumentos }
    finally { $ErrorActionPreference = $prev }
    if ($LASTEXITCODE -ne 0) { throw "$queHace falló (código de salida $LASTEXITCODE)." }
}

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw "No encuentro PHP. Instala PHP 8.3+ (Laragon, XAMPP o php.net) y añádelo al PATH."
}

# -n ignora php.ini: evita las advertencias de extensiones duplicadas al leer la versión.
$phpRaw = Salida php @('-n','-r','echo PHP_VERSION;')
if ($phpRaw -notmatch '(\d+)\.(\d+)\.(\d+)') {
    throw "No pude leer la versión de PHP. Salida recibida:`n$phpRaw"
}
$v = [version]"$($Matches[1]).$($Matches[2]).$($Matches[3])"
$minimo = [version]("$PhpMinimo" + ('.0' * (2 - ("$PhpMinimo".Split('.').Count - 1))))
if ($v -lt $minimo) {
    Write-Host ""
    Write-Host "  PHP $v es demasiado antiguo. El proyecto se define sobre PHP $PhpMinimo (DEC-001)." -ForegroundColor Red
    Write-Host ""
    Write-Host "  Por qué importa: PHP 8.2 pierde soporte de seguridad en diciembre de 2026," -ForegroundColor Yellow
    Write-Host "  es decir antes de que este MVP llegue a producción. Arrancar un proyecto de" -ForegroundColor Yellow
    Write-Host "  varios años sobre una version que caduca en meses no compensa." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "  Cómo actualizar en Windows (unos 10 minutos):" -ForegroundColor Cyan
    Write-Host "    1. Descarga PHP 8.3 o 8.4 x64 **Non Thread Safe** de https://windows.php.net/download/"
    Write-Host "    2. Descomprime en C:\php84"
    Write-Host "    3. Copia php.ini-development a php.ini y descomenta:"
    Write-Host "       extension_dir = \"ext\""
    Write-Host "       extension=mbstring  intl  pdo_mysql  openssl  zip  gd  bcmath  curl  fileinfo  soap"
    Write-Host "    4. Pon C:\php84 **antes** que XAMPP en la variable PATH"
    Write-Host "    5. Abre una consola nueva y comprueba:  php -v"
    Write-Host ""
    Write-Host "  Alternativa: Laragon, que permite cambiar de version de PHP con un clic."
    Write-Host ""
    Write-Host "  Si prefieres arrancar hoy con 8.2 y actualizar despues:" -ForegroundColor DarkGray
    Write-Host "    .\tools\bootstrap-laravel.ps1 -PhpMinimo 8.2" -ForegroundColor DarkGray
    Write-Host "  Funciona, pero produccion tiene que ir en 8.3 o superior." -ForegroundColor DarkGray
    Write-Host ""
    throw "PHP $v < $PhpMinimo"
}
Ok "PHP $v"

if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
    Write-Host ""
    Malo "No encuentro Composer."
    Write-Host "  Instálalo con el script del proyecto (verifica la firma oficial):" -ForegroundColor Cyan
    Write-Host "    powershell -ExecutionPolicy Bypass -File tools\instalar-composer.ps1"
    Write-Host ""
    throw "Composer ausente."
}
$compRaw = Salida composer @('--version')
if ($compRaw -match 'Composer\s+version\s+(\S+)') { Ok "Composer $($Matches[1])" } else { Ok "Composer detectado" }

# Composer puede llevar su propio PHP clavado en composer.bat. Si ese PHP no es el
# mismo que el de la consola, resolvería dependencias contra la versión equivocada
# y dejaría un composer.lock atado a una plataforma que no es la nuestra. Esto es
# justo lo que pasa al instalar PHP nuevo junto a un XAMPP preexistente.
if ($compRaw -match 'PHP\s+version\s+(\d+\.\d+\.\d+)(?:\s+\(([^)]+)\))?') {
    $phpDeComposer = [version]$Matches[1]
    $rutaComposer  = $Matches[2]
    if ($phpDeComposer -ne $v) {
        Write-Host ""
        Malo "Composer NO usa el mismo PHP que esta consola."
        Malo "  consola : $v"
        Malo "  composer: $phpDeComposer $(if ($rutaComposer) { "($rutaComposer)" })"
        Write-Host ""
        Write-Host "  Composer resolvería las dependencias contra la versión equivocada." -ForegroundColor Yellow
        Write-Host "  Abre el composer.bat que esté en tu PATH y comprueba si tiene una ruta" -ForegroundColor Yellow
        Write-Host "  de php.exe escrita a mano; debe invocar 'php' del PATH:" -ForegroundColor Yellow
        Write-Host "    where.exe composer" -ForegroundColor DarkGray
        Write-Host ""
        Write-Host "  O reinstálalo apuntando al PHP correcto:" -ForegroundColor Yellow
        Write-Host "    powershell -ExecutionPolicy Bypass -File tools\instalar-composer.ps1" -ForegroundColor DarkGray
        Write-Host ""
        throw "Composer corre sobre PHP $phpDeComposer, no sobre $v."
    }
    Ok "Composer usa PHP $phpDeComposer (el mismo de la consola)"
} else {
    Aviso "No pude leer sobre qué PHP corre Composer. Compruébalo con: composer -V"
}

# Aquí SÍ hace falta php.ini, porque es lo que carga las extensiones.
$modulosPhp = (Salida php @('-m')) -split "`n" | ForEach-Object { $_.Trim().ToLower() }
$duplicadas = $modulosPhp | Where-Object { $_ -match 'is already loaded' }
if ($duplicadas) {
    Aviso "Tu php.ini carga alguna extensión dos veces (p. ej. openssl). No bloquea nada,"
    Aviso "pero conviene comentar la línea repetida en php.ini para limpiar la salida."
}
$extensiones = @('mbstring','intl','pdo_mysql','openssl','zip','gd','bcmath','curl','fileinfo','soap')
$faltan = $extensiones | Where-Object { $modulosPhp -notcontains $_ }
if ($faltan) { Aviso "Extensiones PHP ausentes: $($faltan -join ', '). 'soap' hace falta para SUNAT." }
else { Ok "Extensiones PHP completas" }

if ($modulosPhp -notcontains 'redis') {
    Aviso "Sin extension redis (en Windows es una DLL de PECL aparte). Para desarrollo local"
    Aviso "puedes empezar con CACHE_STORE=file y QUEUE_CONNECTION=database en tu .env,"
    Aviso "o instalar predis/predis, que es PHP puro. En produccion sí conviene la extension."
}

if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
    Aviso "Node no encontrado: el front no compilará hasta instalarlo."
} else {
    Ok "Node $((Salida node @('-v')).Trim())"
}

# ------------------------------------------------- 2. Esqueleto de Laravel
Paso "Esqueleto de Laravel"

if (Test-Path (Join-Path $Root 'artisan')) {
    Ok "Ya existe: no se toca"
} else {
    $tmp = Join-Path $Root '.laravel-tmp'
    if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
    Write-Host "   Descargando Laravel 12 (esto tarda un poco)..."
    # Se fija la version mayor a proposito. Sin fijarla, Composer instala la ultima
    # rama de Laravel, que puede exigir un PHP mas nuevo que el que este script acepta
    # y dejaria de coincidir con lo documentado en docs/03-ARCHITECTURE.md.
    Ejecutar composer @('create-project',"laravel/laravel:$LaravelVersion",$tmp,'--no-interaction','--prefer-dist') 'composer create-project'

    # Copia SOLO lo que no exista: nuestros archivos ganan siempre.
    $copiados = 0; $respetados = 0
    Get-ChildItem $tmp -Recurse -File -Force | ForEach-Object {
        $rel = $_.FullName.Substring($tmp.Length + 1)
        if ($rel -like 'vendor\*' -or $rel -like 'node_modules\*' -or $rel -like '.git\*') { return }
        # composer create-project ejecuta el post-install de Laravel, que genera un
        # .env propio (DB_CONNECTION=sqlite) dentro del temporal. Si se copiase,
        # ganaria al .env.example del proyecto y 'artisan migrate' iria a SQLite.
        if ($rel -ieq '.env') { return }
        $dst = Join-Path $Root $rel
        if (Test-Path $dst) { $respetados++; return }
        $dir = Split-Path $dst -Parent
        if (-not (Test-Path $dir)) { New-Item $dir -ItemType Directory -Force | Out-Null }
        Copy-Item $_.FullName $dst
        $copiados++
    }
    Remove-Item $tmp -Recurse -Force
    Ok "$copiados archivos del esqueleto copiados, $respetados respetados por ya existir"
}

# -------------------------------------------------- 3. Árbol de módulos
Paso "Árbol de módulos (docs/03 §1.1)"

$modulos = [ordered]@{
  'Identity'      = @('D1',  'Usuarios, autenticacion, sesiones, roles, permisos y ambito de acceso externo.', 'Shared, Core')
  'Core'          = @('D2',  'Configuracion jerarquica, marcas de plataforma, entidades legales, catalogos maestros, archivos, auditoria e integraciones.', 'Shared')
  'Creator'       = @('D3',  'Ciclo de vida del creador: solicitud, perfil, redes, audiencia, tarifas y estados.', 'Shared, Core, Identity')
  'Crm'           = @('D4',  'Leads, pipeline, actividades y conversion a cliente.', 'Shared, Core, Identity')
  'Client'        = @('D5',  'Organizaciones cliente, marcas del cliente, contactos y perfiles fiscales.', 'Shared, Core, Identity')
  'Campaign'      = @('D6',  'Campana, mercados, brief, requisitos, participaciones, invitaciones y logistica.', 'Shared, Core, Identity, Creator, Client')
  'Matching'      = @('D7',  'Busqueda, filtros, shortlist y deteccion de conflictos de marca.', 'Shared, Core, Creator, Campaign')
  'Content'       = @('D8',  'Entregables, versiones, revision, publicacion, evidencia y derechos de uso.', 'Shared, Core, Identity, Creator, Campaign')
  'Measurement'   = @('D9',  'Metricas por publicacion, enlaces de seguimiento y reportes de campana.', 'Shared, Core, Campaign, Content')
  'Finance'       = @('D10', 'Ledger del creador, liquidaciones, pagos, facturacion, series, impuestos y rentabilidad.', 'Shared, Core, Identity, Creator, Client, Campaign, Content')
  'Communication' = @('D11', 'Plantillas de correo, envios, notificaciones y mensajeria. Reacciona a eventos: NO conoce el negocio.', 'Shared, Core, Identity')
  'Intelligence'  = @('D12', 'Creator Score, evaluaciones y senales. Consumidor de eventos: nadie depende de el.', 'Shared, Core, Creator, Campaign, Content, Measurement')
  'Gamification'  = @('D13', 'XP, niveles, insignias, ligas, retos y referidos. Consumidor de eventos: nadie depende de el.', 'Shared, Core, Creator, Campaign, Content, Measurement')
}
$capas = @('Domain','Application','Infrastructure','Http','Database\Migrations','Tests')
$nuevos = 0

foreach ($m in $modulos.Keys) {
    $base = Join-Path $Root "app\Modules\$m"
    foreach ($c in $capas) {
        $d = Join-Path $base $c
        if (-not (Test-Path $d)) { New-Item $d -ItemType Directory -Force | Out-Null; $nuevos++ }
        $keep = Join-Path $d '.gitkeep'
        if (-not (Test-Path $keep)) { New-Item $keep -ItemType File -Force | Out-Null }
    }
    $readme = Join-Path $base 'README.md'
    if (-not (Test-Path $readme)) {
        $info = $modulos[$m]
        @"
# $m ($($info[0]))

$($info[1])

## Dependencias permitidas

``$($info[2])``

Cualquier otra importación **hace fallar CI**. La regla vive en ``deptrac.yaml`` y
la justificación en ``docs/00-EXECUTIVE-PRODUCT-DEFINITION.md §D.1``.

## Estructura

| Carpeta | Qué va aquí |
|---|---|
| ``Domain/`` | Entidades, enums de estado, reglas invariantes y eventos. Sin dependencias del framework donde sea razonable. |
| ``Application/`` | Casos de uso, DTOs y políticas. **Toda la lógica de negocio vive aquí**, no en los controladores. |
| ``Infrastructure/`` | Repositorios, adaptadores e integraciones externas. |
| ``Http/`` | Controladores, requests y resources — **uno por audiencia**: interno, marca, creador (``BR-SEC-001``). |
| ``Database/Migrations/`` | Migraciones propias del módulo. |
| ``Tests/`` | Pruebas del módulo, incluidos los tests de autorización negativos. |
"@ | Set-Content $readme -Encoding UTF8
    }
}
if (-not (Test-Path (Join-Path $Root 'app\Shared'))) {
    New-Item (Join-Path $Root 'app\Shared') -ItemType Directory -Force | Out-Null
    New-Item (Join-Path $Root 'app\Shared\.gitkeep') -ItemType File -Force | Out-Null
}
Ok "13 módulos y $nuevos carpetas nuevas"

# ------------------------------------------------- 4. Parchear composer.json
Paso "Configurando composer.json"
Ejecutar php @((Join-Path $Root 'tools\patch-composer.php')) 'patch-composer.php'

# ------------------------------------------------ 5. Herramientas de calidad
Paso "Instalando dependencias"
Ejecutar composer @('install','--no-interaction','--prefer-dist') 'composer install' 

$dev = @('laravel/pint','larastan/larastan','qossmic/deptrac','pestphp/pest','pestphp/pest-plugin-laravel')
$faltantes = @()
foreach ($p in $dev) { if (-not (Test-Path (Join-Path $Root "vendor\$($p -replace '/','\')"))) { $faltantes += $p } }
if ($faltantes) {
    Write-Host "   Añadiendo: $($faltantes -join ', ')"
    Ejecutar composer (@('require','--dev','--no-interaction') + $faltantes) 'composer require' 
}
Ok "Dependencias listas"

# ------------------------------------------------------------ 6. Entorno
Paso "Entorno"
$envPath = Join-Path $Root '.env'
if (-not (Test-Path $envPath)) {
    Copy-Item (Join-Path $Root '.env.example') $envPath
    Ok ".env creado desde .env.example del proyecto"
} else {
    Ok ".env ya existía: no se toca"
    # Aviso, no correccion automatica: el .env es del desarrollador y puede
    # tener secretos. Pero un DB_CONNECTION=sqlite aqui significa que artisan
    # migrate no vera MySQL, y el error que da no lo explica.
    $envActual = Get-Content $envPath -Raw
    if ($envActual -match '(?m)^DB_CONNECTION=sqlite') {
        Aviso "OJO: tu .env tiene DB_CONNECTION=sqlite. El proyecto usa MySQL."
        Aviso "Cambialo a 'mysql' y descomenta DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME,"
        Aviso "o compara con .env.example, que ya trae los valores correctos."
    }
}
Ejecutar php @('artisan','key:generate','--ansi') 'php artisan key:generate'

if ((Test-Path (Join-Path $Root 'package.json')) -and (Get-Command npm -ErrorAction SilentlyContinue)) {
    Paso "Front"
    Ejecutar npm @('install','--silent') 'npm install'
    Ok "npm listo"
}

Write-Host "`n=====================================================" -ForegroundColor Green
Write-Host " Proyecto listo." -ForegroundColor Green
Write-Host "=====================================================" -ForegroundColor Green
Write-Host @"

Siguiente:
  1. Edita .env con tu base de datos MySQL (y crea la base 'latam_social').
  2. php artisan migrate
  3. php artisan serve

Comprobaciones de calidad (las mismas que corre CI):
  composer quality      formato + estático + fronteras + pruebas
  composer arch         solo las fronteras entre módulos

Lo que NO hay que hacer todavía: escribir tablas. El modelo de datos va por la
iteración 2.2 de 11 -- ver docs/15-ARRANQUE-MVP.md para saber qué iteración de
diseño bloquea cada fase de construcción.
"@
