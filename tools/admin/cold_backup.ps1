<#
.SYNOPSIS
    Phase 25.R follow-up — Cold backup HDD esterno (super_admin only).

.DESCRIPTION
    Esegue una copia cold (air-gapped) dei backup B2 + .env.local VPS su
    HDD esterno scelto via folder picker nativo Windows.

    Layers raccolti:
      1. Ultimi backup Backblaze B2 (cifrati GPG, restano cifrati)
      2. Snapshot fresco di /var/www/pantedu/.env.local del VPS (via SSH)
      3. Config /etc/pantedu/*.env (B2 + Hetzner API)
      4. Manifest SHA-256 di tutti i file + commit HEAD corrente del repo

    Output: cartella <HDD>\pantedu-backups\YYYY-MM\ con sottocartelle:
      ├── b2-backup\    (sync da Backblaze B2)
      ├── env\          (env-local-vps.txt + etc-pantedu.tar.gz)
      ├── manifest.txt  (SHA-256 + git HEAD + timestamp)

.NOTES
    Prerequisiti sul laptop dev:
      - PowerShell 5.1+ o pwsh 7+
      - rclone configurato col remote 'b2-pantedu'
      - SSH config alias 'pantedu-vps'
      - Repo pantedu clonato (per leggere git HEAD)

    Uso tipico (1x/mese):
      cd C:\Users\vitto\progetti_vscode\pantedu
      powershell -ExecutionPolicy Bypass -File tools\admin\cold_backup.ps1

.EXAMPLE
    .\tools\admin\cold_backup.ps1
#>

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

function Write-Step {
    param([string]$Message)
    Write-Host ""
    Write-Host "==> $Message" -ForegroundColor Cyan
}
function Write-Ok {
    param([string]$Message)
    Write-Host "    [OK] $Message" -ForegroundColor Green
}
function Write-Warn {
    param([string]$Message)
    Write-Host "    [WARN] $Message" -ForegroundColor Yellow
}
function Write-Err {
    param([string]$Message)
    Write-Host "    [ERR] $Message" -ForegroundColor Red
}

# ============================================================
# 0. Prerequisites
# ============================================================
Write-Step "Phase 25.R cold backup HDD"
Write-Host "    Verifica prerequisiti..."

$prereq = @{
    'rclone' = 'rclone'
    'ssh'    = 'OpenSSH client'
    'git'    = 'git CLI'
}
foreach ($cmd in $prereq.Keys) {
    if (-not (Get-Command $cmd -ErrorAction SilentlyContinue)) {
        Write-Err "$cmd ($($prereq[$cmd])) non trovato nel PATH. Installa e riprova."
        exit 1
    }
}
Write-Ok "Tutti i prerequisiti OK"

# ============================================================
# 1. Folder picker — scegli destinazione HDD
# ============================================================
Write-Step "Scegli la cartella di destinazione sul HDD esterno"
Write-Host "    (Esplora Risorse si aprirà tra 2 secondi)"

Add-Type -AssemblyName System.Windows.Forms
$folderBrowser = New-Object System.Windows.Forms.FolderBrowserDialog
$folderBrowser.Description = "Seleziona la cartella radice sul HDD esterno (es. E:\)"
$folderBrowser.ShowNewFolderButton = $true
# Default: D:\ (tipica lettera HDD esterno)
if (Test-Path 'E:\') { $folderBrowser.SelectedPath = 'E:\' }
elseif (Test-Path 'D:\') { $folderBrowser.SelectedPath = 'D:\' }

$dialogResult = $folderBrowser.ShowDialog()
if ($dialogResult -ne [System.Windows.Forms.DialogResult]::OK) {
    Write-Warn "Operazione annullata dall'utente."
    exit 0
}

$baseDir = $folderBrowser.SelectedPath
Write-Ok "Destinazione: $baseDir"

# Verifica spazio libero (almeno 10 GB)
$drive = (Get-Item $baseDir).PSDrive
if ($drive -and $drive.Free) {
    $freeGB = [math]::Round($drive.Free / 1GB, 1)
    Write-Host "    Spazio libero su $($drive.Name): $freeGB GB"
    if ($freeGB -lt 10) {
        Write-Warn "Meno di 10 GB liberi. Continuo comunque (Ctrl+C per annullare)."
        Start-Sleep -Seconds 3
    }
}

# ============================================================
# 2. Crea cartelle target
# ============================================================
Write-Step "Crea struttura cartelle"

$monthTag   = Get-Date -Format 'yyyy-MM'
$ts         = Get-Date -Format 'yyyyMMdd_HHmmss'
$targetRoot = Join-Path $baseDir "pantedu-backups\$monthTag"
$b2Dir      = Join-Path $targetRoot 'b2-backup'
$envDir     = Join-Path $targetRoot 'env'

foreach ($d in @($targetRoot, $b2Dir, $envDir)) {
    if (-not (Test-Path $d)) {
        New-Item -ItemType Directory -Path $d -Force | Out-Null
    }
}
Write-Ok "Target root: $targetRoot"

# ============================================================
# 3. Git HEAD corrente (per riferimento)
# ============================================================
Write-Step "Recupera git HEAD locale"
$repoDir = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Push-Location $repoDir
try {
    $gitHead   = (git rev-parse HEAD 2>$null).Trim()
    $gitBranch = (git rev-parse --abbrev-ref HEAD 2>$null).Trim()
    Write-Ok "Repo: $repoDir"
    Write-Ok "Branch: $gitBranch — HEAD: $gitHead"
} catch {
    $gitHead = '(unknown)'
    $gitBranch = '(unknown)'
    Write-Warn "git rev-parse fallito — continuo senza"
}
Pop-Location

# ============================================================
# 4. rclone sync da B2 (NON cancella, solo aggiunge nuovi file)
# ============================================================
Write-Step "Download backup da Backblaze B2 (può richiedere 5-20 min)"
Write-Host "    Source: b2-pantedu:pantedu-backup-vps/"
Write-Host "    Target: $b2Dir"
Write-Host ""

$rcloneArgs = @(
    'copy',
    'b2-pantedu:pantedu-backup-vps/',
    $b2Dir,
    '--progress',
    '--transfers=4',
    '--checkers=8'
)
$rcloneOk = $true
try {
    & rclone @rcloneArgs
    if ($LASTEXITCODE -ne 0) { $rcloneOk = $false }
} catch {
    Write-Err "rclone errore: $_"
    $rcloneOk = $false
}

if ($rcloneOk) {
    $b2Files = (Get-ChildItem $b2Dir -Recurse -File -ErrorAction SilentlyContinue | Measure-Object -Property Length -Sum)
    $b2SizeMB = if ($b2Files.Sum) { [math]::Round($b2Files.Sum / 1MB, 1) } else { 0 }
    Write-Ok "B2 sync completata: $($b2Files.Count) file, $b2SizeMB MB"
} else {
    Write-Warn "rclone B2 sync ha riportato errori — continuo con altri step"
}

# ============================================================
# 5. SSH scarica .env.local VPS (snapshot fresco)
# ============================================================
Write-Step "Scarica .env.local e /etc/pantedu/ dal VPS via SSH"

$envLocalFile = Join-Path $envDir 'env-local-vps.txt'
$etcTarFile   = Join-Path $envDir 'etc-pantedu.tar.gz'

$sshOk = $true
try {
    # Scarica .env.local del VPS
    & ssh pantedu-vps 'cat /var/www/pantedu/.env.local' > $envLocalFile 2>$null
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path $envLocalFile) -or (Get-Item $envLocalFile).Length -eq 0) {
        Write-Warn ".env.local download fallito o vuoto"
        $sshOk = $false
    } else {
        Write-Ok ".env.local scaricato in $envLocalFile ($([math]::Round((Get-Item $envLocalFile).Length / 1KB, 1)) KB)"
    }

    # Tar di /etc/pantedu/
    & ssh pantedu-vps 'sudo tar czf - -C /etc pantedu 2>/dev/null' > $etcTarFile 2>$null
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path $etcTarFile) -or (Get-Item $etcTarFile).Length -eq 0) {
        Write-Warn "/etc/pantedu tar fallito (forse manca sudo nopasswd)"
    } else {
        Write-Ok "/etc/pantedu.tar.gz: $([math]::Round((Get-Item $etcTarFile).Length / 1KB, 1)) KB"
    }
} catch {
    Write-Err "SSH errore: $_"
    $sshOk = $false
}

# ============================================================
# 6. Manifest SHA-256
# ============================================================
Write-Step "Genera manifest SHA-256"

$manifestFile = Join-Path $targetRoot 'manifest.txt'
$manifest = @()
$manifest += "# Cold Backup Manifest — pantedu"
$manifest += "# Generato:   $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss zzz')"
$manifest += "# Generato da: $env:USERNAME @ $env:COMPUTERNAME"
$manifest += "# Git repo:   $repoDir"
$manifest += "# Git branch: $gitBranch"
$manifest += "# Git HEAD:   $gitHead"
$manifest += "# Target:     $targetRoot"
$manifest += ""
$manifest += "## SHA-256 dei file backup"

$allFiles = Get-ChildItem $targetRoot -Recurse -File | Where-Object { $_.Name -ne 'manifest.txt' }
foreach ($f in $allFiles) {
    try {
        $hash = (Get-FileHash -Path $f.FullName -Algorithm SHA256).Hash.ToLower()
        $relPath = $f.FullName.Substring($targetRoot.Length + 1)
        $sizeKb = [math]::Round($f.Length / 1KB, 1)
        $manifest += "$hash  $relPath  ($sizeKb KB)"
    } catch {
        $manifest += "ERROR-HASH                                                       $($f.FullName)"
    }
}

# Fingerprint KMS_MASTER (verifica integrità rapida)
$manifest += ""
$manifest += "## Fingerprint KMS_MASTER_KEY (per verifica integrità copia)"
if (Test-Path $envLocalFile) {
    $kmsLine = (Get-Content $envLocalFile -Raw) -split "`n" | Where-Object { $_ -match '^KMS_MASTER_KEY=' } | Select-Object -First 1
    if ($kmsLine) {
        $kmsValue = ($kmsLine -split '=', 2)[1].Trim()
        $kmsBytes = [System.Text.Encoding]::UTF8.GetBytes($kmsValue)
        $hasher = [System.Security.Cryptography.SHA256]::Create()
        $kmsHash = [System.BitConverter]::ToString($hasher.ComputeHash($kmsBytes)).Replace('-', '').ToLower()
        $manifest += "SHA-256(KMS_MASTER_KEY value, no NL): $kmsHash"
        $manifest += "(deve coincidere con la fingerprint registrata in /admin/crypto-status)"
    } else {
        $manifest += "KMS_MASTER_KEY non trovata in env-local-vps.txt"
    }
} else {
    $manifest += "env-local-vps.txt non disponibile — skip fingerprint"
}

$manifestText = ($manifest -join "`r`n")
Set-Content -Path $manifestFile -Value $manifestText -Encoding UTF8
$manifestHash = (Get-FileHash -Path $manifestFile -Algorithm SHA256).Hash.ToLower()
Write-Ok "Manifest: $manifestFile"
Write-Ok "Manifest SHA-256: $manifestHash"

# ============================================================
# 7. Summary
# ============================================================
Write-Step "Riepilogo finale"

$totalFiles = (Get-ChildItem $targetRoot -Recurse -File).Count
$totalSize  = (Get-ChildItem $targetRoot -Recurse -File | Measure-Object -Property Length -Sum).Sum
$totalMB    = [math]::Round($totalSize / 1MB, 1)

Write-Host ""
Write-Host "  Cartella backup:    $targetRoot" -ForegroundColor White
Write-Host "  File totali:        $totalFiles" -ForegroundColor White
Write-Host "  Size totale:        $totalMB MB" -ForegroundColor White
Write-Host "  Manifest SHA-256:   $manifestHash" -ForegroundColor White
Write-Host ""
Write-Host "  PROSSIMI STEP:" -ForegroundColor Cyan
Write-Host "    1. SCOLLEGA fisicamente l'HDD"
Write-Host "    2. Riponi in cassetto sicuro (o cassetta sicurezza banca)"
Write-Host "    3. Vai su https://beta.pantedu.eu/admin/backup"
Write-Host "    4. Sezione 'Cold backup HDD esterno' -> 'Conferma backup completato':"
Write-Host "       - Etichetta HDD:   (es. WD My Passport 1TB PANTEDU-COLD-01)"
Write-Host "       - Size totale (MB): $totalMB"
Write-Host "       - SHA-256 del manifest: $manifestHash"
Write-Host ""
Write-Host "  Backup completato $(Get-Date -Format 'yyyy-MM-dd HH:mm')" -ForegroundColor Green
Write-Host ""
