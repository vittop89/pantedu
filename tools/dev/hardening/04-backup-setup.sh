#!/usr/bin/env bash
# Phase 25.K.4 — Setup backup encrypted infrastructure.
# Crea config dir, installa script + systemd timer.
# NB: BACKUP_GPG_PASSPHRASE deve essere settato manualmente in
#     /etc/pantedu/backup.env DOPO il primo run di questo script.

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }
W() { printf "\033[33m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

REPO_DIR="/var/www/pantedu"

# Install deps
C "=== [1/4] Install deps (gnupg, zstd, mysql-client) ==="
apt-get install -qy gnupg zstd default-mysql-client 2>&1 | tail -3

# Install script
C "=== [2/4] Install backup script ==="
install -m 755 -o root -g root "${REPO_DIR}/tools/backup/encrypted_backup.sh" /usr/local/bin/pantedu-backup-encrypted.sh
G "  ✓ /usr/local/bin/pantedu-backup-encrypted.sh"

# Install systemd units
C "=== [3/4] Install systemd timer ==="
install -m 644 "${REPO_DIR}/tools/systemd/pantedu-backup-encrypted.service" /etc/systemd/system/
install -m 644 "${REPO_DIR}/tools/systemd/pantedu-backup-encrypted.timer"   /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now pantedu-backup-encrypted.timer
G "  ✓ timer enabled (daily 02:30 ±10min)"

# Setup env config file (skeleton)
C "=== [4/4] Setup config ==="
mkdir -p /etc/pantedu
# NB: 755 (NON 750) — www-data deve poter leggere webhook.env per
# eseguire deploy hook. File singoli protetti via permissions individuali.
chmod 755 /etc/pantedu
if [[ ! -f /etc/pantedu/backup.env ]]; then
    cat > /etc/pantedu/backup.env <<'EOF'
# Phase 25.K.4 — Backup encrypted config.
# !!! EDIT QUI: settare passphrase + remote prima del primo run automatico !!!

# Passphrase GPG (32+ char random). Genera con: openssl rand -base64 32
BACKUP_GPG_PASSPHRASE=""

# Remote rsync target (Hetzner Storage Box format):
#   u123456@u123456.your-storagebox.de:/pantedu
# Per S3 alternative: usa rclone (vedi tools/backup/README.md)
BACKUP_REMOTE=""
BACKUP_REMOTE_PORT="22"      # 22 standard SSH; 23 per Hetzner Storage Box
BACKUP_REMOTE_KEY="/root/.ssh/storagebox"

# Retention
RETENTION_LOCAL_DAYS="7"
RETENTION_REMOTE_DAYS="90"
EOF
    chmod 600 /etc/pantedu/backup.env
    chown root:root /etc/pantedu/backup.env
    W "  ⚠ /etc/pantedu/backup.env creato VUOTO — editare prima del run!"
else
    G "  ✓ /etc/pantedu/backup.env già esistente"
fi

echo
G "════════════════════════════════════════"
G "Phase 25.K.4 — Backup infra installato"
G "════════════════════════════════════════"
echo
echo "PROSSIMI STEP MANUALI (1 volta):"
echo
echo "1. Genera GPG passphrase:"
echo "   openssl rand -base64 32"
echo
echo "2. Edita /etc/pantedu/backup.env e setta:"
echo "   BACKUP_GPG_PASSPHRASE='<passphrase generata>'"
echo "   BACKUP_REMOTE='u123456@u123456.your-storagebox.de:/pantedu'"
echo
echo "3. Genera SSH key + setup Hetzner Storage Box:"
echo "   ssh-keygen -t ed25519 -f /root/.ssh/storagebox -N ''"
echo "   ssh-copy-id -p 23 -i /root/.ssh/storagebox.pub u123456@u123456.your-storagebox.de"
echo "   (oppure copia key.pub via Hetzner Robot panel)"
echo
echo "4. Test manuale:"
echo "   systemctl start pantedu-backup-encrypted.service"
echo "   tail /var/log/pantedu-backup.log"
echo
echo "5. Test restore (CRITICAL — NON saltare):"
echo "   BACKUP=\$(ls -t /var/backups/pantedu/*.tar.gpg | head -1)"
echo "   gpg --decrypt \$BACKUP > /tmp/restore.tar && tar -tf /tmp/restore.tar"
echo
echo "ATTIVAZIONE TIMER: già fatta. Backup auto ogni notte 02:30."
echo "Backup MANCANTI fino a config completa = no offsite copy, ma backup locali OK."
