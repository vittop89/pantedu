#!/usr/bin/env bash
# Phase 25.M — Encrypt /etc/pantedu/backup.env at-rest via systemd-creds.
#
# Defense-in-depth: anche se attaccante diventa root, NON può leggere i secret
# del backup (BACKUP_GPG_PASSPHRASE, DB_ROOT_PASSWORD, B2_APP_KEY) senza la
# chiave host /var/lib/systemd/credential.secret legata all'identità sistema.
#
# Architettura:
#   1. /etc/pantedu/backup.env (chmod 600 root:root) — plaintext fallback
#      per esecuzione manuale (es. test, debug).
#   2. /etc/credstore.encrypted/backup-env (chmod 640) — copia cifrata,
#      usata da systemd via LoadCredentialEncrypted alla porzione service.
#   3. Backup script preferisce $BACKUP_CREDS_FILE (decrypted runtime) →
#      fallback /etc/pantedu/backup.env.
#
# Trade-off: il plaintext fallback rimane su disco (chmod 600).
# Pure systemd-creds-only setup richiederebbe rimozione del file plaintext,
# ma blocca esecuzione manuale (`bash encrypted_backup.sh` senza systemd).

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

ENV_FILE=/etc/pantedu/backup.env
CRED_DIR=/etc/credstore.encrypted
CRED_FILE=$CRED_DIR/backup-env
UNIT=/etc/systemd/system/pantedu-backup-encrypted.service

[[ ! -f "$ENV_FILE" ]] && { R "$ENV_FILE missing — run 11-hetzner-backup-config.sh + 12-b2-setup.sh first"; exit 2; }
[[ ! -f "$UNIT" ]] && { R "$UNIT missing — run 04-backup-setup.sh first"; exit 3; }

C "=== [1/3] Setup systemd-creds host key ==="
if [[ ! -f /var/lib/systemd/credential.secret ]]; then
    systemd-creds setup
fi
G "  ✓ Host key /var/lib/systemd/credential.secret presente"

C "=== [2/3] Encrypt backup.env → $CRED_FILE ==="
mkdir -p "$CRED_DIR"
chmod 700 "$CRED_DIR"
systemd-creds encrypt --name=backup-env "$ENV_FILE" "$CRED_FILE"
chmod 640 "$CRED_FILE"
G "  ✓ $CRED_FILE creato (size $(stat -c%s "$CRED_FILE") bytes)"

# Round-trip test
DECRYPTED=$(systemd-creds decrypt --name=backup-env "$CRED_FILE" - 2>&1 | head -3)
if echo "$DECRYPTED" | grep -q '^BACKUP_GPG_PASSPHRASE\|^# Phase'; then
    G "  ✓ Decrypt test PASS"
else
    R "  ✗ Decrypt test FAIL — abort"
    exit 4
fi

C "=== [3/3] Update systemd unit ==="
cp -a "$UNIT" "${UNIT}.bak.$(date +%s)"
cat > "$UNIT" <<'UEOF'
[Unit]
Description=pantedu — daily encrypted backup (DB + storage + config) to remote
After=network-online.target mysql.service
Wants=network-online.target

[Service]
Type=oneshot
User=root
# Phase 25.M — secrets via systemd-creds (encrypted at rest, decrypt solo a runtime)
LoadCredentialEncrypted=backup-env:/etc/credstore.encrypted/backup-env
# Backward compat: fallback su file plaintext per esecuzione manuale
EnvironmentFile=-/etc/pantedu/backup.env
# Esporta path della credential decrypted (runtime dir tmpfs) per lo script
Environment=BACKUP_CREDS_FILE=%d/backup-env
ExecStart=/usr/local/bin/pantedu-backup-encrypted.sh
TimeoutStartSec=3600

[Install]
WantedBy=multi-user.target
UEOF

systemctl daemon-reload
G "  ✓ Systemd unit aggiornato + daemon-reload"

echo
G "════════════════════════════════════════"
G "Phase 25.M.16 — systemd-creds backup OK"
G "════════════════════════════════════════"
echo
echo "Test esecuzione (con creds encrypted):"
echo "  sudo systemctl start pantedu-backup-encrypted.service"
echo "  sudo journalctl -u pantedu-backup-encrypted.service -n 30"
echo
echo "Verify cifratura at-rest:"
echo "  sudo file $CRED_FILE   # mostra binary"
echo "  sudo hexdump -C $CRED_FILE | head -2  # primi byte cifrati"
echo
echo "Per eseguire backup manuale (no systemd) — usa fallback plaintext:"
echo "  sudo /usr/local/bin/pantedu-backup-encrypted.sh"
echo
echo "Ruota credenziali:"
echo "  1. sudo nano /etc/pantedu/backup.env  (modifica valori)"
echo "  2. sudo bash tools/dev/hardening/16-systemd-creds-backup.sh  (re-encrypt)"
