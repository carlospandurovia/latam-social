<#
    LATAM Social — diagnóstico de MySQL/MariaDB de XAMPP

    SOLO LEE. No para servicios, no borra archivos, no toca configuración.
    Su trabajo es decirte POR QUE no arranca, para no ir probando a ciegas
    recetas de foro que destruyen datos.

    Uso:  powershell -ExecutionPolicy Bypass -File tools\diagnostico-mysql.ps1
          powershell -ExecutionPolicy Bypass -File tools\diagnostico-mysql.ps1 -Xampp C:\xampp
#>

param(
    [string]$Xampp = '',
    [int]$Puerto = 3306
)

$ErrorActionPreference = 'Continue'

function Paso($t)  { Write-Host "`n>> $t" -ForegroundColor Cyan }
function Ok($t)    { Write-Host "   $t" -ForegroundColor Green }
function Aviso($t) { Write-Host "   $t" -ForegroundColor Yellow }
function Malo($t)  { Write-Host "   $t" -ForegroundColor Red }
function Dato($t)  { Write-Host "   $t" -ForegroundColor Gray }

Write-Host ""
Write-Host "  Diagnostico de MySQL (XAMPP) — solo lectura" -ForegroundColor Cyan

$veredictos = @()

# ------------------------------------------------------- 0. Localizar XAMPP
Paso "Localizando XAMPP"
if (-not $Xampp) {
    foreach ($c in @('D:\xampp','C:\xampp','E:\xampp')) {
        if (Test-Path (Join-Path $c 'mysql\bin\mysqld.exe')) { $Xampp = $c; break }
    }
}
if (-not $Xampp -or -not (Test-Path $Xampp)) {
    Malo "No encuentro XAMPP. Indícalo:  -Xampp D:\xampp"
    return
}
Ok "XAMPP en $Xampp"
$datos = Join-Path $Xampp 'mysql\data'
$log   = Join-Path $datos 'mysql_error.log'

# --------------------------------------------------- 1. ¿Quién ocupa el puerto?
Paso "Puerto $Puerto"
$ocupante = $null
try {
    $con = Get-NetTCPConnection -LocalPort $Puerto -State Listen -ErrorAction Stop
    if ($con) {
        $ocupante = Get-Process -Id ($con | Select-Object -First 1).OwningProcess -ErrorAction SilentlyContinue
    }
} catch {
    $ns = netstat -ano | Select-String ":$Puerto\s.*LISTENING"
    if ($ns) {
        $pid2 = ($ns[0].ToString().Trim() -split '\s+')[-1]
        $ocupante = Get-Process -Id $pid2 -ErrorAction SilentlyContinue
    }
}
if ($ocupante) {
    Malo "El puerto $Puerto ya esta ocupado por: $($ocupante.ProcessName) (PID $($ocupante.Id))"
    Dato "Ruta: $($ocupante.Path)"
    $veredictos += "PUERTO_OCUPADO:$($ocupante.ProcessName):$($ocupante.Id)"
} else {
    Ok "Puerto $Puerto libre"
}

# ------------------------------------------------ 2. ¿Hay mysqld huerfano?
Paso "Procesos mysqld / mariadbd"
$procs = Get-Process -Name mysqld,mariadbd -ErrorAction SilentlyContinue
if ($procs) {
    foreach ($p in $procs) {
        Malo "Vivo: $($p.ProcessName) PID $($p.Id)"
        Dato "  Ruta:   $($p.Path)"
        try { Dato "  Desde:  $($p.StartTime)" } catch {}
    }
    $veredictos += "MYSQLD_HUERFANO"
} else {
    Ok "Ningun mysqld corriendo ahora mismo"
}

# ------------------------------------------- 3. ¿Servicio de MySQL instalado?
Paso "Servicios de Windows"
$svc = Get-Service -ErrorAction SilentlyContinue |
       Where-Object { $_.Name -match 'mysql|mariadb' -or $_.DisplayName -match 'MySQL|MariaDB' }
if ($svc) {
    foreach ($s in $svc) {
        Aviso "Servicio '$($s.Name)' ($($s.DisplayName)) — estado: $($s.Status)"
    }
    $veredictos += "SERVICIO_PRESENTE"
} else {
    Ok "No hay servicio de MySQL/MariaDB registrado"
}

# ---------------------------------------------------- 4. Ficheros de bloqueo
Paso "Archivos de bloqueo y estado de datos"
if (-not (Test-Path $datos)) {
    Malo "No existe $datos"
    $veredictos += "SIN_CARPETA_DATOS"
} else {
    foreach ($f in @('ibdata1','ib_logfile0','aria_log_control','multi-master.info')) {
        $ruta = Join-Path $datos $f
        if (Test-Path $ruta) {
            $i = Get-Item $ruta
            Dato ("{0,-20} {1,12:N0} bytes   modificado {2}" -f $f, $i.Length, $i.LastWriteTime)
        }
    }
    # Un .pid superviviente casi siempre significa apagado sucio.
    $pids = Get-ChildItem $datos -Filter '*.pid' -ErrorAction SilentlyContinue
    if ($pids) {
        Aviso "Quedan archivos .pid de una ejecucion anterior: $(($pids | ForEach-Object { $_.Name }) -join ', ')"
        Aviso "Sintoma clasico de apagado sucio."
        $veredictos += "PID_HUERFANO"
    }
}

# -------------------------------------------------------- 5. El log de error
Paso "Ultimas lineas de mysql_error.log"
if (-not (Test-Path $log)) {
    Aviso "No existe $log"
} else {
    $i = Get-Item $log
    Dato "Archivo modificado por ultima vez: $($i.LastWriteTime)"
    Write-Host ""
    $ultimas = Get-Content $log -Tail 40 -ErrorAction SilentlyContinue
    foreach ($l in $ultimas) {
        $color = 'Gray'
        if ($l -match '\[ERROR\]|Fatal|Can''t|failed|corrupt') { $color = 'Red' }
        elseif ($l -match '\[Warning\]|\[Note\]')              { $color = 'DarkGray' }
        Write-Host "     $l" -ForegroundColor $color
    }
    Write-Host ""

    $texto = $ultimas -join "`n"
    $firmas = @(
        @{ rx = "Unable to lock .*ibdata1|Check that you do not already have another"; id = 'OTRA_INSTANCIA';   msg = 'Otro mysqld ya tiene tomados los archivos de datos.' },
        @{ rx = "Bind on TCP/IP port|Address already in use";                          id = 'PUERTO';           msg = 'El puerto ya esta ocupado.' },
        @{ rx = "Plugin 'InnoDB' (registration as a STORAGE ENGINE )?failed|InnoDB: Corruption|Database page corruption"; id = 'INNODB_ROTO'; msg = 'InnoDB no arranca: datos o logs inconsistentes.' },
        @{ rx = "Table 'mysql\.\w+' doesn't exist|mysql.user table is damaged|Can't open and lock privilege tables"; id = 'TABLAS_SISTEMA'; msg = 'Las tablas de sistema de MySQL estan danadas o ausentes.' },
        @{ rx = "Aria engine: log initialization failed|Aria recovery failed";          id = 'ARIA';             msg = 'El motor Aria no pudo recuperar sus logs.' },
        @{ rx = "Can't create/write to file|Permission denied|Access is denied";        id = 'PERMISOS';         msg = 'Problema de permisos sobre la carpeta de datos.' },
        @{ rx = "Attempting backtrace|mysqld got exception";                            id = 'CRASH';            msg = 'El servidor se cayo con excepcion.' }
    )
    foreach ($f in $firmas) {
        if ($texto -match $f.rx) {
            Malo "Detectado [$($f.id)]: $($f.msg)"
            $veredictos += $f.id
        }
    }
}

# ---------------------- 6. ¿Rompi yo algo con el PATH? (honestidad basica)
Paso "Comprobando que el PATH de maquina sigue sano"
$pathM = [Environment]::GetEnvironmentVariable('Path','Machine')
$criticas = @("$env:SystemRoot\system32", "$env:SystemRoot")
$rotas = @()
foreach ($c in $criticas) {
    if (-not ($pathM -split ';' | Where-Object { $_.TrimEnd('\') -ieq $c.TrimEnd('\') })) { $rotas += $c }
}
if ($rotas) {
    Malo "Faltan entradas criticas en el PATH de maquina: $($rotas -join ', ')"
    Aviso "Hay copia de seguridad en C:\php83\path-respaldo-*.txt"
    $veredictos += 'PATH_ROTO'
} else {
    Ok "PATH de maquina intacto (system32 presente). La instalacion de PHP no es la causa."
}

# --------------------------------------------------------------- Veredicto
Paso "Veredicto"
$veredictos = $veredictos | Sort-Object -Unique

if ($veredictos -match 'PUERTO_OCUPADO|OTRA_INSTANCIA|MYSQLD_HUERFANO|PID_HUERFANO') {
    Write-Host ""
    Write-Host "  CAUSA MAS PROBABLE: ya hay una instancia de MySQL viva o mal cerrada." -ForegroundColor Yellow
    Write-Host "  Es lo mas comun y NO implica perdida de datos." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "  Que hacer, en este orden:" -ForegroundColor Cyan
    Write-Host "    1. Cierra el panel de XAMPP."
    Write-Host "    2. Termina el proceso que quedo vivo:"
    Write-Host "         Get-Process mysqld,mariadbd -ErrorAction SilentlyContinue | Stop-Process -Force"
    Write-Host "    3. Abre XAMPP otra vez y pulsa Start en MySQL."
    Write-Host ""
    Write-Host "  NO borres ibdata1 ni ib_logfile*. Eso destruye todas tus bases." -ForegroundColor Red
}
elseif ($veredictos -contains 'INNODB_ROTO' -or $veredictos -contains 'TABLAS_SISTEMA' -or $veredictos -contains 'ARIA') {
    Write-Host ""
    Write-Host "  CAUSA MAS PROBABLE: la carpeta de datos quedo inconsistente." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "  ANTES DE TOCAR NADA, copia la carpeta de datos entera:" -ForegroundColor Red
    Write-Host "     Copy-Item '$datos' '$datos-copia' -Recurse"
    Write-Host ""
    Write-Host "  Pasame despues las lineas rojas de arriba y te digo la recuperacion" -ForegroundColor Cyan
    Write-Host "  concreta segun la firma. Las recetas de foro que dicen 'renombra data" -ForegroundColor Cyan
    Write-Host "  y usa backup' funcionan pero te dejan sin las bases que tuvieras." -ForegroundColor Cyan
}
elseif ($veredictos -contains 'PERMISOS') {
    Write-Host ""
    Write-Host "  CAUSA MAS PROBABLE: permisos sobre $datos" -ForegroundColor Yellow
    Write-Host "  Prueba a abrir el panel de XAMPP como administrador." -ForegroundColor Cyan
}
elseif (-not $veredictos) {
    Write-Host ""
    Write-Host "  Sin sintomas claros. Pasame las lineas del log de arriba." -ForegroundColor Yellow
}
else {
    Write-Host ""
    Write-Host "  Senales encontradas: $($veredictos -join ', ')" -ForegroundColor Yellow
    Write-Host "  Pasame la salida completa y te digo el siguiente paso." -ForegroundColor Cyan
}

Write-Host ""
Aviso "Recuerda: MySQL solo hace falta para 'php artisan migrate'."
Aviso "El bootstrap de Laravel funciona igual sin base de datos."
Write-Host ""
