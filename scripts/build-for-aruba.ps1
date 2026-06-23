<#
.SYNOPSIS
    Build deploy bundle per Aruba Linux basic (equivalente PowerShell di build-for-aruba.sh).

.DESCRIPTION
    Esegue composer install no-dev + vite build + opzionale PT seed, quindi
    struttura dist/httpdocs/ (webroot) + dist/private/ (sibling) pronti
    per FTP upload su hosting shared tipo Aruba.

.PARAMETER WithSeed
    Se presente, esegue `php bin/risdoc-pt-seed.php --all --auto-annotate --apply`
    prima di copiare gli schemi (popola default PT AST nei schemi risdoc).

.EXAMPLE
    .\scripts\build-for-aruba.ps1
    .\scripts\build-for-aruba.ps1 -WithSeed
#>

param(
    [switch]$WithSeed
)

$ErrorActionPreference = "Stop"
$Root = Resolve-Path (Join-Path $PSScriptRoot "..")
$Dist = Join-Path $Root "dist"

Write-Host "==> Build for Aruba Linux basic hosting"
Write-Host "    root: $Root"
Write-Host "    dist: $Dist"

# 1. Clean dist
if (Test-Path $Dist) { Remove-Item $Dist -Recurse -Force }
New-Item -ItemType Directory -Path "$Dist/httpdocs" -Force | Out-Null
New-Item -ItemType Directory -Path "$Dist/private"  -Force | Out-Null

# 2. Composer production install
Write-Host "==> composer install --no-dev --optimize-autoloader"
Push-Location $Root
& composer install --no-dev --optimize-autoloader --quiet
if ($LASTEXITCODE -ne 0) { throw "composer install failed" }

# 3. Vite build
Write-Host "==> npm ci + npm run build"
& npm ci --silent
if ($LASTEXITCODE -ne 0) { throw "npm ci failed" }
& npm run build
if ($LASTEXITCODE -ne 0) { throw "npm run build failed" }

# 4. (Opt) seed
if ($WithSeed) {
    Write-Host "==> php bin/risdoc-pt-seed.php --all --auto-annotate --apply"
    & php bin/risdoc-pt-seed.php --all --auto-annotate --apply
}
Pop-Location

# 5. Copy httpdocs
Write-Host "==> Copying public/ → dist/httpdocs/"
Copy-Item -Path (Join-Path $Root "public/*") -Destination "$Dist/httpdocs" -Recurse -Force

# 6. Copy private payload
Write-Host "==> Copying private payload → dist/private/"
foreach ($d in @("app","vendor","routes","schemas","views","bin")) {
    $src = Join-Path $Root $d
    if (Test-Path $src) {
        Copy-Item -Path $src -Destination "$Dist/private/" -Recurse -Force
    }
}
New-Item -ItemType Directory -Path "$Dist/private/storage/logs"        -Force | Out-Null
New-Item -ItemType Directory -Path "$Dist/private/storage/sessions"    -Force | Out-Null
New-Item -ItemType Directory -Path "$Dist/private/storage/risdoc-tmp"  -Force | Out-Null
foreach ($s in @("templates","data","overrides")) {
    $src = Join-Path $Root "storage/$s"
    if (Test-Path $src) {
        Copy-Item -Path $src -Destination "$Dist/private/storage/" -Recurse -Force
    }
}

# composer.json/.lock per eventuali update server-side
Copy-Item -Path (Join-Path $Root "composer.json") -Destination "$Dist/private/" -ErrorAction SilentlyContinue
Copy-Item -Path (Join-Path $Root "composer.lock") -Destination "$Dist/private/" -ErrorAction SilentlyContinue

# 7. .env.example
$envTemplate = @'
# Copia questo file in `private/.env` e compila con credenziali Aruba
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tuo-dominio.it
APP_TIMEZONE=Europe/Rome

DB_ENABLED=true
DB_HOST=sql.tuohost.aruba.it
DB_PORT=3306
DB_NAME=Sqltuonome
DB_USER=Sqltuonome
DB_PASS=
DB_CHARSET=utf8mb4

SESSION_DRIVER=file
SESSION_LIFETIME=120
CSRF_SECRET=generate_random_32_chars_here
'@
Set-Content -Path "$Dist/private/.env.example" -Value $envTemplate -Encoding utf8

# 8. README
$readme = @'
Pantedu — deploy bundle for Aruba Linux basic hosting
========================================================

Struttura:
  dist/httpdocs/  → upload via FTP in /home/USER/httpdocs/ (webroot)
  dist/private/   → upload via FTP in /home/USER/private/ (sibling dir)

Step post-upload:
  1. chmod 775 private/storage/logs/ private/storage/sessions/ private/storage/risdoc-tmp/
  2. Copia private/.env.example → private/.env e compila credenziali DB
  3. Importa DB schema via phpMyAdmin (database/schema.sql dal repo)
  4. Apri https://TUO-DOMINIO.it/ — verifica home carica senza errori
  5. Login admin + verifica /risdoc/view/{id} renderizza

Detection automatica del layout:
  public/index.php cerca private/app/bootstrap.php. Se presente → usa
  private/ come app root. Altrimenti → fallback dev layout.

Per dettagli: wiki/deployment/aruba-linux-basic.md nel repo.
'@
Set-Content -Path "$Dist/README.txt" -Value $readme -Encoding utf8

$httpdocsSize = (Get-ChildItem -Path "$Dist/httpdocs" -Recurse | Measure-Object -Property Length -Sum).Sum
$privateSize  = (Get-ChildItem -Path "$Dist/private"  -Recurse | Measure-Object -Property Length -Sum).Sum

Write-Host ""
Write-Host "============================================================"
Write-Host " Bundle creato in $Dist/"
Write-Host "   httpdocs/  ({0:N2} MB)" -f ($httpdocsSize / 1MB)
Write-Host "   private/   ({0:N2} MB)" -f ($privateSize  / 1MB)
Write-Host ""
Write-Host " Prossimi step:"
Write-Host "   1. FTP upload dist/httpdocs/* → /home/USER/httpdocs/"
Write-Host "   2. FTP upload dist/private/   → /home/USER/private/"
Write-Host "   3. chmod 775 private/storage/{logs,sessions,risdoc-tmp}/"
Write-Host "   4. Crea private/.env da .env.example (credenziali DB Aruba)"
Write-Host "============================================================"
