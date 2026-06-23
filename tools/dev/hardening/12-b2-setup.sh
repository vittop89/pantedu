#!/usr/bin/env bash
# Phase 25.K.12 — Backblaze B2 backend setup + tiered retention.
#
# Setup:
# 1. Install rclone (Debian package o curl install)
# 2. Configura remote rclone "b2-pantedu" con S3-compatible endpoint
# 3. Aggiorna /etc/pantedu/backup.env con B2 settings
# 4. NO commit credenziali in git: lette da env vars o input interattivo
#
# Usage (env vars):
#   B2_KEY_ID=00308fc... B2_APP_KEY=K003... B2_BUCKET=pantedu-backup-vps \
#   B2_ENDPOINT=s3.eu-central-003.backblazeb2.com \
#   sudo -E bash tools/dev/hardening/12-b2-setup.sh

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }
W() { printf "\033[33m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

# ──────────────────────────────────────────────────────────────
# 1. Install rclone
# ──────────────────────────────────────────────────────────────
C "=== [1/4] Install rclone ==="

if ! command -v rclone >/dev/null; then
    DEBIAN_FRONTEND=noninteractive apt-get install -qy rclone 2>&1 | tail -3
fi
G "  ✓ rclone: $(rclone version 2>&1 | head -1)"

# ──────────────────────────────────────────────────────────────
# 2. Validate input
# ──────────────────────────────────────────────────────────────
C "=== [2/4] Validate config inputs ==="

if [[ -z "${B2_KEY_ID:-}" || -z "${B2_APP_KEY:-}" ]]; then
    R "  Missing env vars B2_KEY_ID and/or B2_APP_KEY"
    echo
    echo "Usage:"
    echo "  B2_KEY_ID=<keyID> B2_APP_KEY=<applicationKey> \\"
    echo "  B2_BUCKET=<bucket-name> B2_ENDPOINT=<endpoint> \\"
    echo "  sudo -E bash $0"
    exit 1
fi

B2_BUCKET="${B2_BUCKET:-pantedu-backup-vps}"
B2_ENDPOINT="${B2_ENDPOINT:-s3.eu-central-003.backblazeb2.com}"
RCLONE_REMOTE_NAME="b2-pantedu"

G "  ✓ Bucket: $B2_BUCKET"
G "  ✓ Endpoint: $B2_ENDPOINT"

# ──────────────────────────────────────────────────────────────
# 3. Configure rclone remote (idempotent via config files)
# ──────────────────────────────────────────────────────────────
C "=== [3/4] Configure rclone remote ==="

RCLONE_CONFIG_DIR="/root/.config/rclone"
mkdir -p "$RCLONE_CONFIG_DIR"
chmod 700 "$RCLONE_CONFIG_DIR"

# Crea config rclone (S3-compatible, perfetto per B2 con endpoint)
cat > "$RCLONE_CONFIG_DIR/rclone.conf" <<EOF
[${RCLONE_REMOTE_NAME}]
type = s3
provider = Other
access_key_id = ${B2_KEY_ID}
secret_access_key = ${B2_APP_KEY}
endpoint = https://${B2_ENDPOINT}
acl = private
chunk_size = 16M
upload_cutoff = 100M
EOF
chmod 600 "$RCLONE_CONFIG_DIR/rclone.conf"
chown -R root:root "$RCLONE_CONFIG_DIR"
G "  ✓ rclone config $RCLONE_CONFIG_DIR/rclone.conf"

# Test connectivity
C "  Testing connectivity (lsf bucket)…"
if rclone lsf "${RCLONE_REMOTE_NAME}:${B2_BUCKET}" --max-depth 1 >/dev/null 2>&1; then
    G "  ✓ B2 bucket reachable + credentials valid"
else
    R "  ✗ B2 connection failed"
    rclone lsf "${RCLONE_REMOTE_NAME}:${B2_BUCKET}" --max-depth 1 2>&1 | head -5
    R "  Check credentials in /root/.config/rclone/rclone.conf"
    exit 2
fi

# ──────────────────────────────────────────────────────────────
# 4. Update /etc/pantedu/backup.env
# ──────────────────────────────────────────────────────────────
C "=== [4/4] Update backup.env ==="

ENV_FILE="/etc/pantedu/backup.env"
if [[ ! -f "$ENV_FILE" ]]; then
    R "  $ENV_FILE missing. Run 11-hetzner-backup-config.sh prima."
    exit 3
fi

# Backup
cp -a "$ENV_FILE" "${ENV_FILE}.bak.pantedu-$(date +%s)"

# Aggiungi/aggiorna B2 settings (in coda)
# Rimuovi righe vecchie B2 se esistono
sed -i '/^BACKUP_TYPE=/d; /^B2_REMOTE_NAME=/d; /^B2_BUCKET=/d; /^B2_ENDPOINT=/d' "$ENV_FILE"

cat >> "$ENV_FILE" <<EOF

# Phase 25.K.12 — Backblaze B2 backend
BACKUP_TYPE="b2"
B2_REMOTE_NAME="${RCLONE_REMOTE_NAME}"
B2_BUCKET="${B2_BUCKET}"
B2_ENDPOINT="${B2_ENDPOINT}"
EOF

chmod 600 "$ENV_FILE"
G "  ✓ $ENV_FILE updated"

# ──────────────────────────────────────────────────────────────
echo
G "════════════════════════════════════════"
G "Phase 25.K.12 — B2 setup OK"
G "════════════════════════════════════════"
echo
echo "Config:"
echo "  Bucket:   ${B2_BUCKET}"
echo "  Endpoint: https://${B2_ENDPOINT}"
echo "  rclone:   ${RCLONE_REMOTE_NAME}:"
echo
echo "Test list bucket:"
echo "  sudo rclone lsf b2-pantedu:pantedu-backup-vps"
echo
echo "Test backup:"
echo "  sudo systemctl start pantedu-backup-encrypted.service"
echo "  sudo journalctl -u pantedu-backup-encrypted.service -f"
echo
echo "PROSSIMO STEP: aggiornare encrypted_backup.sh per backend B2 + tiered retention"
