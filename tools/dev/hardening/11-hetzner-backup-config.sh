#!/usr/bin/env bash
# Phase 25.K.11 — Hetzner Storage Box configuration helper.
#
# La parte AUTOMATICA: genera passphrase GPG random + SSH key dedicata
# + popola /etc/pantedu/backup.env con tutto eccetto Hetzner endpoint.
#
# La parte MANUALE: l'admin DEVE:
# 1. Creare Storage Box su Hetzner Robot panel (https://robot.hetzner.com)
# 2. Copiare il public key SSH (output di questo script) nel pannello SB
# 3. Inserire l'endpoint Hetzner (u123456@u123456.your-storagebox.de) in env
# 4. Far girare manualmente il primo backup per test
#
# Tempo manuale stimato: 5-10 minuti.

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }
W() { printf "\033[33m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

CONF_DIR="/etc/pantedu"
ENV_FILE="$CONF_DIR/backup.env"
SSH_KEY="/root/.ssh/storagebox"

mkdir -p "$CONF_DIR"
# 755 — webhook.env deve essere leggibile da www-data per deploy hook
chmod 755 "$CONF_DIR"

# ──────────────────────────────────────────────────────────────
# 1. Generate GPG passphrase (32-char base64 random)
# ──────────────────────────────────────────────────────────────
C "=== [1/3] Generate GPG passphrase ==="

if [[ -f "$ENV_FILE" ]] && grep -q "^BACKUP_GPG_PASSPHRASE=.\+" "$ENV_FILE"; then
    G "  ✓ BACKUP_GPG_PASSPHRASE già impostata in $ENV_FILE"
    PASSPHRASE_EXISTS=1
else
    GPG_PASSPHRASE=$(openssl rand -base64 32)
    G "  ✓ Generata passphrase 32-char (256 bit entropy)"
    PASSPHRASE_EXISTS=0
fi

# ──────────────────────────────────────────────────────────────
# 2. Generate SSH key dedicata Storage Box
# ──────────────────────────────────────────────────────────────
C "=== [2/3] Generate SSH key for Storage Box ==="

if [[ -f "$SSH_KEY" ]]; then
    G "  ✓ SSH key già esistente: $SSH_KEY"
else
    ssh-keygen -t ed25519 -f "$SSH_KEY" -N "" -C "pantedu-backup-$(hostname)" -q
    chmod 600 "$SSH_KEY"
    chmod 644 "${SSH_KEY}.pub"
    G "  ✓ SSH key generata: $SSH_KEY"
fi

# ──────────────────────────────────────────────────────────────
# 3. Popola /etc/pantedu/backup.env
# ──────────────────────────────────────────────────────────────
C "=== [3/3] Configure /etc/pantedu/backup.env ==="

# Costruisci env (preserva BACKUP_REMOTE se già settato manualmente)
EXISTING_REMOTE=""
if [[ -f "$ENV_FILE" ]]; then
    EXISTING_REMOTE=$(grep "^BACKUP_REMOTE=" "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
fi

if [[ "$PASSPHRASE_EXISTS" -eq 0 ]]; then
    cat > "$ENV_FILE" <<EOF
# Phase 25.K.11 — pantedu backup encrypted config (auto-generato $(date -I))
# !!! NON COMMITTARE IN GIT — contiene secret !!!

# GPG symmetric passphrase (32-char base64 random)
BACKUP_GPG_PASSPHRASE="${GPG_PASSPHRASE}"

# Hetzner Storage Box endpoint
# Format: u123456@u123456.your-storagebox.de:/percorso
# Setup: https://robot.hetzner.com → Storage Boxes → create
BACKUP_REMOTE="${EXISTING_REMOTE}"

# SSH key dedicata (auto-generata)
BACKUP_REMOTE_KEY="${SSH_KEY}"
BACKUP_REMOTE_PORT="23"          # Hetzner Storage Box usa porta 23 (NON 22)

# Retention
RETENTION_LOCAL_DAYS="7"
RETENTION_REMOTE_DAYS="90"
EOF
    chmod 600 "$ENV_FILE"
    chown root:root "$ENV_FILE"
    G "  ✓ $ENV_FILE creato (perms 600 root:root)"
fi

# ──────────────────────────────────────────────────────────────
# Output public key + istruzioni manuali
# ──────────────────────────────────────────────────────────────
echo
G "════════════════════════════════════════════════════"
G "Phase 25.K.11 — Hetzner Storage Box CONFIG"
G "════════════════════════════════════════════════════"
echo
C "STEP MANUALI (TU devi farli ora):"
echo
echo "1. Vai su https://robot.hetzner.com → Storage Boxes"
echo "   - Crea uno Storage Box (es. BX10 5€/mese 1TB)"
echo "   - Annota username (es. u123456) + hostname (u123456.your-storagebox.de)"
echo
echo "2. Aggiungi questa SSH PUBLIC KEY al Storage Box (Hetzner panel → Sub-account → SSH key):"
echo
W "════════════════════════════════════════════════════"
cat "${SSH_KEY}.pub"
W "════════════════════════════════════════════════════"
echo
echo "3. Imposta BACKUP_REMOTE in $ENV_FILE:"
echo "   sudo nano $ENV_FILE"
echo "   BACKUP_REMOTE=\"u123456@u123456.your-storagebox.de:/pantedu\""
echo "   (replace u123456 con il tuo username)"
echo
echo "4. Test connessione SSH:"
echo "   sudo ssh -i $SSH_KEY -p 23 u123456@u123456.your-storagebox.de"
echo "   (al primo accesso accetta host key)"
echo
echo "5. Manual test backup (PRIMA volta):"
echo "   sudo systemctl start pantedu-backup-encrypted.service"
echo "   sudo journalctl -u pantedu-backup-encrypted.service -f"
echo
echo "6. Test restore (CRITICAL):"
echo "   BACKUP=\$(ls -t /var/backups/pantedu/*.tar.gpg | head -1)"
echo "   sudo gpg --decrypt --batch --passphrase \"\$(grep ^BACKUP_GPG_PASSPHRASE $ENV_FILE | cut -d= -f2-)\" \"\$BACKUP\" > /tmp/restore.tar"
echo "   tar -tf /tmp/restore.tar  # verifica content"
echo
echo "Cron timer attivo: backup auto ogni notte 02:30 (vedi systemctl list-timers)"
echo
echo "BACKUP della passphrase (CRITICO — senza è impossibile decifrare):"
W "════════════════════════════════════════════════════"
if [[ "$PASSPHRASE_EXISTS" -eq 0 ]]; then
    echo "Passphrase GPG: ${GPG_PASSPHRASE}"
else
    echo "(passphrase già esistente in $ENV_FILE, leggi con: sudo grep BACKUP_GPG_PASSPHRASE $ENV_FILE)"
fi
W "════════════════════════════════════════════════════"
echo
echo "SALVA QUESTA PASSPHRASE OFFLINE (password manager, paper backup)."
echo "Se perdi VPS + perdi passphrase = backup IRRECUPERABILI."
