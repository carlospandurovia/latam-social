<#
    LATAM Social — instalador de PHP 8.3 para Windows

    Por qué existe: XAMPP trae PHP 8.2, que pierde soporte de seguridad en
    diciembre de 2026. El proyecto se define sobre PHP 8.3 (DEC-001).

    Qué hace:
      1. Descubre la última 8.3 NTS x64 publicada en windows.php.net.
      2. La descarga y VERIFICA el SHA-256 contra el que publica php.net.
      3. La descomprime en C:\php83.
      4. Genera un php.ini con las extensiones que el proyecto necesita
         (soap para SUNAT, intl para i18n, openssl, pdo_mysql...).
      5. Pone C:\php83 delante de XAMPP en el PATH, en el ámbito correcto.
      6. Comprueba que quedó bien.

    NO toca XAMPP. Apache seguirá usando su PHP interno; solo cambia qué PHP
    encuentra la consola cuando escribes "php".

    Es idempotente: se puede volver a ejecutar.

    Uso:
        powershell -ExecutionPolicy Bypass -File tools\instalar-php83.ps1
        powershell -ExecutionPolicy Bypass -File tools\instalar-php83.ps1 -Rama 8.4
#>

param(
    # Rama de PHP a instalar. 8.3 es la que asume el proyecto.
    [string]$Rama = '8.3',

    # Carpeta destino. Se crea si no existe.
    [string]$Destino = '',

    # Reinstala aunque ya exista la carpeta destino.
    [switch]$Forzar
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

function Paso($t)  { Write-Host "`n>> $t" -ForegroundColor Cyan }
function Ok($t)    { Write-Host "   $t" -ForegroundColor Green }
function Aviso($t) { Write-Host "   $t" -ForegroundColor Yellow }
function Malo($t)  { Write-Host "   $t" -ForegroundColor Red }

# PHP escribe sus advertencias de arranque en stderr. Con ErrorActionPreference='Stop',
# eso basta para abortar el script aunque el comando termine bien. Cada llamada nativa
# se aísla y se lee como texto plano.
function Salida([string]$exe, [string[]]$argumentos) {
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try   { $out = & $exe @argumentos 2>&1 | ForEach-Object { "$_" } }
    finally { $ErrorActionPreference = $prev }
    return ($out -join "`n")
}

if (-not $Destino) { $Destino = "C:\php" + ($Rama -replace '\.','') }

Write-Host ""
Write-Host "  Instalador de PHP $Rama para LATAM Social" -ForegroundColor Cyan
Write-Host "  Destino: $Destino"

# --------------------------------------------------------- 0. Estado actual
Paso "Situación actual"
$phpActual = Get-Command php -ErrorAction SilentlyContinue
if ($phpActual) {
    $verActual = (Salida $phpActual.Source @('-n','-r','echo PHP_VERSION;')).Trim()
    Ok "Ahora 'php' resuelve a: $($phpActual.Source)  (versión $verActual)"
} else {
    Aviso "Ahora no hay ningún 'php' en el PATH."
}

# ------------------------------------------------- 1. Descubrir la versión
Paso "Buscando la última PHP $Rama NTS x64"

$listaUrl = 'https://windows.php.net/downloads/releases/sha256sum.txt'
try {
    $lista = (Invoke-WebRequest -Uri $listaUrl -UseBasicParsing -TimeoutSec 60).Content
} catch {
    Malo "No pude leer $listaUrl"
    Malo $_.Exception.Message
    throw "Sin conexión a windows.php.net. Comprueba tu red o proxy."
}

# El formato exacto de sha256sum.txt no es estable: unas veces es
# "<sha>  <archivo>", otras "<sha> *<archivo>" (modo binario de sha256sum) y
# otras el archivo va primero. Así que no se asume separador: de cada línea se
# extraen por separado el nombre del zip y el hash de 64 hex que la acompañe.
$rxArchivo = 'php-(?<ver>\d+\.\d+\.\d+)-nts-Win32-vs\d+-x64\.zip'
$rxSha     = '[0-9a-fA-F]{64}'

$todas = @()
foreach ($linea in ($lista -split "`r?`n")) {
    if ($linea -notmatch $rxArchivo) { continue }
    $ver     = $Matches['ver']
    $archivo = $Matches[0]
    $sha     = ''
    if ($linea -match $rxSha) { $sha = $Matches[0].ToLower() }
    $todas += [pscustomobject]@{
        Sha     = $sha
        Archivo = $archivo
        Version = [version]$ver
        Linea   = $linea
    }
}

$ramaV = [version]("$Rama.0")
$candidatos = $todas | Where-Object {
    $_.Version.Major -eq $ramaV.Major -and $_.Version.Minor -eq $ramaV.Minor
}

if (-not $candidatos) {
    Malo "No encontré ninguna build NTS x64 de la rama $Rama en la lista de php.net."
    if ($todas) {
        Aviso "Ramas con build NTS x64 disponibles ahora mismo:"
        foreach ($r in ($todas | ForEach-Object { "$($_.Version.Major).$($_.Version.Minor)" } | Sort-Object -Unique)) {
            Write-Host "     $r"
        }
        Aviso "Vuelve a lanzar con:  .\tools\instalar-php83.ps1 -Rama <rama>"
    } else {
        Aviso "Tampoco reconocí NINGUNA build en el archivo: cambió el formato."
        Aviso "Primeras líneas que mencionan 'nts-Win32', para poder arreglarlo:"
        $muestra = $lista -split "`r?`n" | Where-Object { $_ -match 'nts-Win32' } | Select-Object -First 5
        foreach ($m in $muestra) { Write-Host "     $m" -ForegroundColor DarkGray }
        if (-not $muestra) { Write-Host "     (ninguna línea contiene 'nts-Win32')" -ForegroundColor DarkGray }
    }
    throw "Rama $Rama no disponible."
}

# Sin hash publicado no se instala: este binario va a firmar comprobantes de SUNAT.
$conSha = $candidatos | Where-Object { $_.Sha }
if (-not $conSha) {
    Malo "Encontré la build pero ningún SHA-256 junto a ella, así que no puedo verificarla."
    Aviso "Línea tal cual: $(($candidatos | Select-Object -First 1).Linea)"
    throw "Sin hash publicado no se instala nada."
}
$candidatos = $conSha

$elegido = $candidatos | Sort-Object Version -Descending | Select-Object -First 1
Ok "PHP $($elegido.Version)  ->  $($elegido.Archivo)"

# ------------------------------------------------------------ 2. Descargar
Paso "Descargando y verificando"

$saltarInstalacion = $false

if ((Test-Path $Destino) -and -not $Forzar) {
    $exe = Join-Path $Destino 'php.exe'
    if (Test-Path $exe) {
        $yaHay = (Salida $exe @('-n','-r','echo PHP_VERSION;')).Trim()
        Ok "Ya existe $Destino con PHP $yaHay. No se vuelve a descargar."
        Aviso "Para reinstalar de cero:  .\tools\instalar-php83.ps1 -Forzar"
        $saltarInstalacion = $true
    }
}

if (-not $saltarInstalacion) {
    $zip = Join-Path $env:TEMP $elegido.Archivo
    $url = "https://windows.php.net/downloads/releases/$($elegido.Archivo)"

    Write-Host "   $url"
    $progresoPrevio = $ProgressPreference
    $ProgressPreference = 'SilentlyContinue'   # acelera mucho Invoke-WebRequest
    try {
        Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing -TimeoutSec 600
    } finally {
        $ProgressPreference = $progresoPrevio
    }

    $shaLocal = (Get-FileHash -Path $zip -Algorithm SHA256).Hash.ToLower()
    if ($shaLocal -ne $elegido.Sha) {
        Remove-Item $zip -Force -ErrorAction SilentlyContinue
        Malo "El SHA-256 del archivo descargado NO coincide con el publicado por php.net."
        Malo "  esperado: $($elegido.Sha)"
        Malo "  obtenido: $shaLocal"
        throw "Descarga corrupta o manipulada. No se instala nada."
    }
    Ok "SHA-256 verificado"

    if (Test-Path $Destino) {
        $respaldo = "$Destino.anterior-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
        Move-Item $Destino $respaldo
        Aviso "La carpeta anterior se guardó en $respaldo"
    }
    New-Item -ItemType Directory -Path $Destino -Force | Out-Null
    Expand-Archive -Path $zip -DestinationPath $Destino -Force
    Remove-Item $zip -Force -ErrorAction SilentlyContinue
    Ok "Descomprimido en $Destino"
}

$phpExe = Join-Path $Destino 'php.exe'
if (-not (Test-Path $phpExe)) { throw "Algo salió mal: no existe $phpExe" }

# -------------------------------------------------------------- 3. php.ini
Paso "Configurando php.ini"

$ini      = Join-Path $Destino 'php.ini'
$plantilla= Join-Path $Destino 'php.ini-development'
if (-not (Test-Path $ini)) {
    if (-not (Test-Path $plantilla)) { throw "No encuentro php.ini-development en $Destino" }
    Copy-Item $plantilla $ini
    Ok "php.ini creado desde php.ini-development"
} else {
    Ok "php.ini ya existía: se conserva y solo se ajusta el bloque del proyecto"
}

$deseadas = @('mbstring','intl','pdo_mysql','mysqli','openssl','zip','gd','bcmath',
              'curl','fileinfo','soap','sodium','exif','opcache')

# No se declara a ciegas. En Windows varias extensiones (bcmath, ctype, json...) van
# compiladas DENTRO de php.exe y no tienen DLL: declararlas provoca el warning
# "Unable to load dynamic library". Así que se pregunta a este PHP concreto qué trae
# ya dentro, y se mira qué DLLs hay de verdad en ext\.
$compiladas = (Salida $phpExe @('-n','-m')) -split "`r?`n" |
              ForEach-Object { $_.Trim().ToLower() } |
              Where-Object { $_ -and $_ -notmatch '^\[' }

$extDir = Join-Path $Destino 'ext'
$dlls = @{}
if (Test-Path $extDir) {
    Get-ChildItem $extDir -Filter 'php_*.dll' -File -ErrorAction SilentlyContinue | ForEach-Object {
        $dlls[($_.BaseName -replace '^php_','').ToLower()] = $true
    }
}

$aCargar = @(); $yaDentro = @(); $ausentes = @()
foreach ($e in $deseadas) {
    $k = $e.ToLower()
    if     ($compiladas -contains $k)            { $yaDentro += $e }
    elseif ($dlls.ContainsKey($k))               { $aCargar  += $e }
    else                                         { $ausentes += $e }
}

if ($yaDentro) { Ok "Ya compiladas en php.exe (no hace falta declararlas): $($yaDentro -join ', ')" }
if ($aCargar)  { Ok "Se activarán desde ext\: $($aCargar -join ', ')" }
if ($ausentes) { Aviso "Sin DLL ni compiladas, se omiten: $($ausentes -join ', ')" }

$extensiones = $deseadas   # se usa más abajo para comentar declaraciones sueltas

# Los marcadores van en ASCII puro A PROPOSITO: el php.ini se escribe con
# -Encoding ASCII, y un guion largo o una tilde aqui se convertiria en "?" al
# guardar. Luego el regex buscaria el caracter original, no lo encontraria, y el
# bloque anterior no se borraria: cada extension acabaria declarada dos veces.
$INICIO = '; ===== INICIO bloque LATAM Social - no editar a mano ====='
$FIN    = '; ===== FIN bloque LATAM Social ====='

$bloque = @()
$bloque += $INICIO
$bloque += "; Generado por tools/instalar-php83.ps1 el $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
$bloque += "extension_dir = `"$(Join-Path $Destino 'ext')`""
foreach ($e in $aCargar) {
    if ($e -eq 'opcache') { $bloque += 'zend_extension=opcache' }
    else                  { $bloque += "extension=$e" }
}
$bloque += 'date.timezone = America/Lima'
$bloque += 'memory_limit = 512M'
$bloque += 'upload_max_filesize = 64M'
$bloque += 'post_max_size = 64M'
$bloque += $FIN

$contenido = Get-Content $ini -Raw

# Borrado tolerante: no se exige el texto exacto del marcador, solo su forma.
# Asi tambien limpia bloques escritos por versiones anteriores de este script,
# incluidos los que quedaron con el guion largo destrozado por el encoding.
# Regex.Replace sustituye TODAS las coincidencias, asi que si hay varios los quita todos.
$rxBloque = '(?s);\s*=+\s*INICIO bloque LATAM Social.*?;\s*=+\s*FIN bloque LATAM Social\s*=+'
$previos  = ([regex]::Matches($contenido, $rxBloque)).Count
if ($previos -gt 0) {
    $contenido = [regex]::Replace($contenido, $rxBloque, '')
    Ok "Bloques anteriores retirados: $previos"
}

# Comenta cualquier extension= suelta de nuestra lista que ya estuviera activa,
# para no provocar el aviso "Module is already loaded".
# El \r? antes del $ es imprescindible: el archivo usa saltos CRLF y, sin el,
# ^...$ en modo multilinea nunca casa porque el \r se queda fuera de la clase.
foreach ($e in $extensiones) {
    $contenido = [regex]::Replace(
        $contenido,
        "(?m)^[ \t]*(zend_)?extension[ \t]*=[ \t]*(php_)?$([regex]::Escape($e))(\.dll)?[ \t]*\r?$",
        '; $0   ; desactivada: se declara en el bloque LATAM Social'
    )
}

$contenido = $contenido.TrimEnd() + "`r`n`r`n" + ($bloque -join "`r`n") + "`r`n"
Set-Content -Path $ini -Value $contenido -Encoding ASCII
Ok "Bloque del proyecto escrito en $ini"

# Comprobacion inmediata: ninguna extension puede quedar declarada dos veces.
# Esta es exactamente la clase de fallo que produjo "Module is already loaded".
$activas = @{}
foreach ($l in (Get-Content $ini)) {
    if ($l -match '^[ \t]*(zend_)?extension[ \t]*=[ \t]*(php_)?(?<n>[A-Za-z0-9_]+)') {
        $n = $Matches['n'].ToLower()
        $activas[$n] = 1 + $(if ($activas.ContainsKey($n)) { $activas[$n] } else { 0 })
    }
}
$repes = $activas.GetEnumerator() | Where-Object { $_.Value -gt 1 }
if ($repes) {
    Malo "El php.ini quedo con extensiones repetidas: $(($repes | ForEach-Object { "$($_.Key) x$($_.Value)" }) -join ', ')"
    Aviso "Borra $ini y vuelve a lanzar el script para regenerarlo limpio."
} else {
    Ok "php.ini sin declaraciones repetidas ($($activas.Count) extensiones activas)"
}


# ------------------------------------------------------------------ 4. PATH
Paso "Ajustando el PATH"

function LimpiaPath([string]$p, [string]$quitar) {
    ($p -split ';' | Where-Object {
        $_ -and ($_.TrimEnd('\') -ne $quitar.TrimEnd('\'))
    }) -join ';'
}

# IMPORTANTE: el PATH se lee y se escribe DIRECTAMENTE del registro, sin expandir.
# [Environment]::GetEnvironmentVariable(...,'Machine') devuelve el valor ya expandido,
# y volver a guardarlo convertiría %SystemRoot% en C:\Windows de forma permanente,
# rompiendo el PATH de la máquina. Esto lo evita.
$CLAVES = @{
    'Machine' = @{ Raiz = [Microsoft.Win32.Registry]::LocalMachine
                   Ruta = 'SYSTEM\CurrentControlSet\Control\Session Manager\Environment' }
    'User'    = @{ Raiz = [Microsoft.Win32.Registry]::CurrentUser
                   Ruta = 'Environment' }
}

function LeerPathCrudo([string]$ambito) {
    $k = $CLAVES[$ambito].Raiz.OpenSubKey($CLAVES[$ambito].Ruta, $false)
    if (-not $k) { return '' }
    try {
        $v = $k.GetValue('Path', '', [Microsoft.Win32.RegistryValueOptions]::DoNotExpandEnvironmentNames)
        return [string]$v
    } finally { $k.Close() }
}

function EscribirPathCrudo([string]$ambito, [string]$valor) {
    $k = $CLAVES[$ambito].Raiz.OpenSubKey($CLAVES[$ambito].Ruta, $true)
    if (-not $k) { throw "No pude abrir el registro para el ámbito $ambito." }
    try {
        $k.SetValue('Path', $valor, [Microsoft.Win32.RegistryValueKind]::ExpandString)
    } finally { $k.Close() }
}

# Copia de seguridad del PATH antes de tocar nada. Cuesta nada y salva un mal día.
$respaldoPath = Join-Path $Destino "path-respaldo-$(Get-Date -Format 'yyyyMMdd-HHmmss').txt"
@(
    "Respaldo del PATH previo a instalar PHP $Rama"
    "Fecha: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
    ''
    '[Machine]'
    (LeerPathCrudo 'Machine')
    ''
    '[User]'
    (LeerPathCrudo 'User')
) | Set-Content -Path $respaldoPath -Encoding UTF8
Ok "PATH respaldado en $respaldoPath"

$pathMaquinaExp = [Environment]::GetEnvironmentVariable('Path','Machine')

# ¿Hay algún php.exe en el PATH de máquina? (XAMPP suele instalarse ahí).
# Windows resuelve el PATH de máquina ANTES que el de usuario, así que si el
# competidor está en el de máquina, añadirnos al de usuario no serviría de nada.
$competidorMaquina = $pathMaquinaExp -split ';' | Where-Object {
    $_ -and ($_.TrimEnd('\') -ne $Destino.TrimEnd('\')) -and
    (Test-Path (Join-Path $_ 'php.exe') -ErrorAction SilentlyContinue)
} | Select-Object -First 1

$esAdmin = ([Security.Principal.WindowsPrincipal] `
            [Security.Principal.WindowsIdentity]::GetCurrent()
           ).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if ($competidorMaquina) {
    Aviso "Hay otro PHP en el PATH de máquina: $competidorMaquina"
    if ($esAdmin) {
        $crudo = LeerPathCrudo 'Machine'
        EscribirPathCrudo 'Machine' ($Destino + ';' + (LimpiaPath $crudo $Destino))
        Ok "$Destino puesto por delante en el PATH de máquina"
    } else {
        Malo "Para ganarle hace falta modificar el PATH de máquina, y eso pide permisos de administrador."
        Write-Host ""
        Write-Host "  Cierra esta ventana y abre PowerShell COMO ADMINISTRADOR:" -ForegroundColor Cyan
        Write-Host "    (botón derecho en el botón de Inicio -> 'Terminal (Administrador)')"
        Write-Host "    cd $PWD"
        Write-Host "    powershell -ExecutionPolicy Bypass -File tools\instalar-php83.ps1"
        Write-Host ""
        Aviso "PHP ya quedó instalado en $Destino; solo falta este paso del PATH."
        throw "Hace falta ejecutar como administrador para reordenar el PATH."
    }
} else {
    $crudo = LeerPathCrudo 'User'
    EscribirPathCrudo 'User' ($Destino + ';' + (LimpiaPath $crudo $Destino))
    Ok "$Destino puesto por delante en el PATH de usuario"
}

# Para que esta misma sesión ya lo vea, sin reabrir la consola.
$env:Path = $Destino + ';' + (LimpiaPath $env:Path $Destino)

# ----------------------------------------------------------- 5. Verificación
Paso "Verificando"

$salidaVer = Salida $phpExe @('-r','echo PHP_VERSION;')
if ($salidaVer -match '(\d+\.\d+\.\d+)') { Ok "php.exe reporta: $($Matches[1])" }
else { Malo "No pude leer la versión. Salida:`n$salidaVer" }

$avisosArranque = ($salidaVer -split "`n") | Where-Object { $_ -match 'Warning|Fatal' }
if ($avisosArranque) {
    Malo "PHP arranca con avisos:"
    foreach ($a in $avisosArranque) { Write-Host "     $a" -ForegroundColor Red }
    Aviso "Revisa el bloque LATAM Social al final de $ini"
}

$modulos = (Salida $phpExe @('-m')) -split "`r?`n" | ForEach-Object { $_.Trim().ToLower() }
$dupes   = $modulos | Where-Object { $_ -match 'already loaded' }
if ($dupes) { Aviso "Hay extensiones declaradas dos veces en php.ini. Revísalo." }

$requeridas = @('mbstring','intl','pdo_mysql','openssl','zip','gd','bcmath','curl','fileinfo','soap')
$faltan = $requeridas | Where-Object { $modulos -notcontains $_ }
if ($faltan) {
    Malo "Faltan extensiones: $($faltan -join ', ')"
    Malo "'soap' es imprescindible para facturar en SUNAT."
} else {
    Ok "Extensiones requeridas: todas presentes"
}

$resuelto = (Get-Command php -ErrorAction SilentlyContinue)
if ($resuelto -and $resuelto.Source.TrimEnd('\').StartsWith($Destino.TrimEnd('\'))) {
    Ok "En esta sesión, 'php' ya resuelve a $($resuelto.Source)"
} else {
    Aviso "En esta sesión 'php' aún apunta a otro sitio. Cierra la consola y abre una nueva."
}

Write-Host ""
Write-Host "  Listo. Ahora, en una consola NUEVA:" -ForegroundColor Green
Write-Host "    php -v"
Write-Host "    cd $PWD"
Write-Host "    powershell -ExecutionPolicy Bypass -File tools\bootstrap-laravel.ps1"
Write-Host ""
Aviso "XAMPP no se ha tocado: Apache sigue con su PHP interno y MySQL sigue en 3306."
