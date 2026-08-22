<#
    LATAM Social — arranque manual de MySQL (XAMPP), fuera del panel

    Por que existe: cuando el panel de XAMPP dice "MySQL shutdown unexpectedly"
    sin mas, no distingue entre "MariaDB fallo" y "el panel lo mato". Este script
    arranca mysqld directamente con --console y ensena la salida real.

    Que hace:
      1. Aparta los .pid huerfanos (los RENOMBRA, no los borra).
      2. Lanza mysqld con --console capturando su salida.
      3. Espera hasta 40 s a que el puerto 3306 escuche.
      4. Dice si arranco, y si no, ensena el error de verdad.

    NO toca ibdata1, ni ib_logfile*, ni ninguna base de datos.

    Uso:  powershell -ExecutionPolicy Bypass -File tools\arrancar-mysql.ps1
          powershell -ExecutionPolicy Bypass -File tools\arrancar-mysql.ps1 -Detener
#>

param(
    [string]$Xampp = '',
    [int]$Puerto = 3306,
    [int]$EsperaSegundos = 40,
    # Para el mysqld que haya arrancado este script.
    [switch]$Detener
)

$ErrorActionPreference = 'Continue'

function Paso($t)  { Write-Host "`n>> $t" -ForegroundColor Cyan }
function Ok($t)    { Write-Host "   $t" -ForegroundColor Green }
function Aviso($t) { Write-Host "   $t" -ForegroundColor Yellow }
function Malo($t)  { Write-Host "   $t" -ForegroundColor Red }
function Dato($t)  { Write-Host "   $t" -ForegroundColor Gray }

if (-not $Xampp) {
    foreach ($c in @('D:\xampp','C:\xampp','E:\xampp')) {
        if (Test-Path (Join-Path $c 'mysql\bin\mysqld.exe')) { $Xampp = $c; break }
    }
}
if (-not $Xampp -or -not (Test-Path $Xampp)) { Malo "No encuentro XAMPP. Usa -Xampp D:\xampp"; return }

$bin    = Join-Path $Xampp 'mysql\bin\mysqld.exe'
$myini  = Join-Path $Xampp 'mysql\bin\my.ini'
$datos  = Join-Path $Xampp 'mysql\data'

if ($Detener) {
    Paso "Deteniendo mysqld"
    $p = Get-Process mysqld,mariadbd -ErrorAction SilentlyContinue
    if (-not $p) { Ok "No habia ninguno corriendo"; return }
    $admin = Join-Path $Xampp 'mysql\bin\mysqladmin.exe'
    if (Test-Path $admin) {
        # Apagado limpio: evita otra recuperacion de fallo en el proximo arranque.
        & $admin -u root shutdown 2>&1 | ForEach-Object { Dato "$_" }
        Start-Sleep -Seconds 3
    }
    $p = Get-Process mysqld,mariadbd -ErrorAction SilentlyContinue
    if ($p) { Aviso "Sigue vivo; forzando."; $p | Stop-Process -Force }
    Ok "Detenido"
    return
}

Write-Host ""
Write-Host "  Arranque manual de MySQL — XAMPP en $Xampp" -ForegroundColor Cyan

# ------------------------------------------------------ 1. Comprobaciones
Paso "Comprobaciones previas"
foreach ($f in @($bin,$myini,$datos)) {
    if (-not (Test-Path $f)) { Malo "No existe: $f"; return }
}
Ok "mysqld.exe, my.ini y carpeta de datos presentes"

$yaVivo = Get-Process mysqld,mariadbd -ErrorAction SilentlyContinue
if ($yaVivo) {
    Malo "Ya hay un mysqld vivo (PID $(($yaVivo | ForEach-Object { $_.Id }) -join ', '))."
    Aviso "Detenlo antes:  .\tools\arrancar-mysql.ps1 -Detener"
    return
}
Ok "Ningun mysqld corriendo"

# ------------------------------------------- 2. Apartar los .pid huerfanos
Paso "Archivos .pid de ejecuciones anteriores"
$sello = Get-Date -Format 'yyyyMMdd-HHmmss'
$pids = Get-ChildItem $datos -Filter '*.pid' -ErrorAction SilentlyContinue
if ($pids) {
    foreach ($f in $pids) {
        $destino = "$($f.Name).viejo-$sello"
        Rename-Item $f.FullName $destino
        Ok "Apartado: $($f.Name) -> $destino  (renombrado, no borrado)"
    }
} else {
    Ok "No habia ninguno"
}

# ------------------------------------------------------------ 3. Arrancar
Paso "Lanzando mysqld --console"
$salOut = Join-Path $env:TEMP "mysqld-out-$sello.log"
$salErr = Join-Path $env:TEMP "mysqld-err-$sello.log"

$proc = Start-Process -FilePath $bin `
    -ArgumentList @("--defaults-file=$myini", '--console') `
    -NoNewWindow -PassThru `
    -RedirectStandardOutput $salOut -RedirectStandardError $salErr

Dato "PID $($proc.Id). Esperando hasta $EsperaSegundos s a que escuche el puerto $Puerto..."

$escucha = $false
for ($i = 0; $i -lt $EsperaSegundos; $i++) {
    Start-Sleep -Seconds 1
    if ($proc.HasExited) { break }
    $c = Get-NetTCPConnection -LocalPort $Puerto -State Listen -ErrorAction SilentlyContinue
    if ($c) { $escucha = $true; break }
}

# ---------------------------------------------------------- 4. Veredicto
Paso "Resultado"

$textoOut = if (Test-Path $salOut) { Get-Content $salOut -Raw } else { '' }
$textoErr = if (Test-Path $salErr) { Get-Content $salErr -Raw } else { '' }
$todo = ($textoErr + "`n" + $textoOut).Trim()

if ($escucha -and -not $proc.HasExited) {
    Ok "MySQL ARRANCO y escucha en el puerto $Puerto (PID $($proc.Id))."
    Write-Host ""
    Write-Host "  Conclusion: MariaDB esta perfectamente. El problema es el panel de XAMPP." -ForegroundColor Green
    Write-Host ""
    Write-Host "  Ya puedes crear la base y migrar:" -ForegroundColor Cyan
    Write-Host "    $Xampp\mysql\bin\mysql.exe -u root -e `"CREATE DATABASE IF NOT EXISTS latam_social CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`""
    Write-Host ""
    Aviso "Este mysqld seguira vivo aunque cierres esta ventana."
    Aviso "Para pararlo limpiamente:  .\tools\arrancar-mysql.ps1 -Detener"
    Aviso "NO uses el boton Start del panel mientras este corriendo."
    if ($todo) {
        Write-Host ""
        Dato "Salida del arranque:"
        foreach ($l in ($todo -split "`r?`n" | Select-Object -Last 15)) { Dato "     $l" }
    }
}
elseif ($proc.HasExited) {
    Malo "mysqld termino solo (codigo $($proc.ExitCode)). Este es el error real:"
    Write-Host ""
    if ($todo) {
        foreach ($l in ($todo -split "`r?`n")) {
            $col = if ($l -match '\[ERROR\]|Fatal|Aborting|failed|corrupt') { 'Red' } else { 'Gray' }
            Write-Host "     $l" -ForegroundColor $col
        }
    } else {
        Aviso "No dejo salida. Mira las ultimas lineas de $datos\mysql_error.log"
    }
    Write-Host ""
    Aviso "Pasame estas lineas y te digo la recuperacion concreta."
    Aviso "No borres nada de $datos mientras tanto."
}
else {
    Aviso "mysqld sigue vivo (PID $($proc.Id)) pero aun no escucha en $Puerto tras $EsperaSegundos s."
    Aviso "Puede estar recuperando InnoDB. Espera un poco y comprueba:"
    Dato  "   Get-NetTCPConnection -LocalPort $Puerto -State Listen"
    if ($todo) {
        Write-Host ""
        foreach ($l in ($todo -split "`r?`n" | Select-Object -Last 20)) { Dato "     $l" }
    }
}

Write-Host ""
Dato "Salida completa en:"
Dato "   $salErr"
Dato "   $salOut"
Write-Host ""
