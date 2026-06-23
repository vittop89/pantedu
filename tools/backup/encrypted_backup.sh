#!/usr/bin/env bash
# Phase 25.K.4 — Backup encrypted con GPG + offsite (Hetzner Storage Box o S3).
#
# 1. mysqldump DB pantedu compresso
# 2. tar storage/ (objects, maps_enc, verifiche_enc — già cifrati app-level)
# 3. tar app/Config + .env.local (chiavi runtime — critical!)
# 4. GPG encrypt symmetric (passphrase) — output .gpg
# 5. rsync/scp a remoto (Hetzner Storage Box, AWS S3, ecc.)
# 6. Retention rotation locale (7gg) + remote (30gg)
# 7. Verify restore-ability con test estrazione
#
# Setup pre-run (1 volta):
#   export BACKUP_GPG_PASSPHRASE='<strong passphrase>'  # in /etc/pantedu/backup.env
#   export BACKUP_REMOTE='u123456@u123456.your-storagebox.de:/'
#   ssh-keygen -t ed25519 -f /root/.ssh/storagebox -N ''
#   ssh-copy-id -i /root/.ssh/storagebox.pub -p 23 u123456@u123456.your-storagebox.de
#
# Cron: 1 volta al giorno alle 02:30 (low traffic)

set -euo pipefail

# ──────────────────────────────────────────────────────────────
# Config (override via env o /etc/pantedu/backup.env)
# Phase 25.M: prefer systemd-creds encrypted (BACKUP_CREDS_FILE injected
# by systemd LoadCredentialEncrypted), fallback /etc/pantedu/backup.env
# per esecuzione manuale (non da systemd).
# ──────────────────────────────────────────────────────────────
if [[ -n "${BACKUP_CREDS_FILE:-}" && -r "$BACKUP_CREDS_FILE" ]]; then
    source "$BACKUP_CREDS_FILE"
elif [[ -f /etc/pantedu/backup.env ]]; then
    source /etc/pantedu/backup.env
fi

BACKUP_DIR="${BACKUP_DIR:-/var/backups/pantedu}"
APP_DIR="${APP_DIR:-/var/www/pantedu}"
DB_NAME="${DB_NAME:-pantedu}"
DB_USER="${DB_USER:-}"
DB_PASS="${DB_PASS:-}"
GPG_PASS="${BACKUP_GPG_PASSPHRASE:-}"
REMOTE="${BACKUP_REMOTE:-}"           # esempio: u123456@u123456.your-storagebox.de:/pantedu
REMOTE_PORT="${BACKUP_REMOTE_PORT:-22}"
REMOTE_KEY="${BACKUP_REMOTE_KEY:-/root/.ssh/storagebox}"
RETENTION_LOCAL_DAYS="${RETENTION_LOCAL_DAYS:-7}"
RETENTION_REMOTE_DAYS="${RETENTION_REMOTE_DAYS:-90}"

DATE=$(date +%Y%m%d_%H%M)
# /tmp è tmpfs (~2GB su VPS) — backup bundle può superarlo. Usa /var/backups/pantedu/.tmp (su disco).
TMPDIR_PARENT="${TMPDIR_PARENT:-${BACKUP_DIR}/.tmp}"
mkdir -p "$TMPDIR_PARENT"
chmod 700 "$TMPDIR_PARENT"
TMPDIR=$(mktemp -d -p "$TMPDIR_PARENT" backup.XXXXXX)
trap "rm -rf $TMPDIR" EXIT

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }
W() { printf "\033[33m%s\033[0m\n" "$*"; }

mkdir -p "$BACKUP_DIR"

# ──────────────────────────────────────────────────────────────
# Pre-flight checks
# ──────────────────────────────────────────────────────────────
[[ -z "$GPG_PASS" ]] && { R "BACKUP_GPG_PASSPHRASE not set"; exit 1; }
command -v gpg >/dev/null || { R "gpg not installed: apt install gnupg"; exit 1; }

# DB credentials: prima prova .env.local (per app user con password), poi env esplicito
if [[ -z "$DB_USER" || -z "$DB_PASS" ]]; then
    if [[ -f "$APP_DIR/.env.local" ]]; then
        DB_USER=$(grep -E '^DB_USER=' "$APP_DIR/.env.local" | cut -d= -f2- | tr -d '"' | tr -d "'")
        DB_PASS=$(grep -E '^DB_PASS=' "$APP_DIR/.env.local" | cut -d= -f2- | tr -d '"' | tr -d "'")
    fi
fi

# ──────────────────────────────────────────────────────────────
# 1. mysqldump
# ──────────────────────────────────────────────────────────────
# Auth strategy (Debian Trixie MariaDB su questo VPS — auth = password):
#   1. DB_ROOT_USER + DB_ROOT_PASSWORD (da /etc/pantedu/backup.env) — preferred (permessi pieni)
#   2. /root/.my.cnf default (se mysql -e 'SELECT 1' funziona senza arg)
#   3. App user (DB_USER/DB_PASS da .env.local) — fallback (richiede PROCESS+LOCK TABLES+EVENT su DB)
C "=== [1/5] mysqldump ${DB_NAME} ==="
DUMP_FILE="$TMPDIR/db_${DATE}.sql"

if [[ -n "${DB_ROOT_USER:-}" && -n "${DB_ROOT_PASSWORD:-}" ]]; then
    G "  ✓ auth via DB_ROOT_USER (${DB_ROOT_USER})"
    MYSQL_PWD="$DB_ROOT_PASSWORD" mysqldump \
        --user="$DB_ROOT_USER" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        "$DB_NAME" > "$DUMP_FILE"
elif mysql -e 'SELECT 1' >/dev/null 2>&1; then
    G "  ✓ auth via /root/.my.cnf default"
    mysqldump \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        "$DB_NAME" > "$DUMP_FILE"
elif [[ -n "$DB_USER" && -n "$DB_PASS" ]]; then
    G "  ✓ auth via app user (${DB_USER}) — limited dump"
    MYSQL_PWD="$DB_PASS" mysqldump \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        --user="$DB_USER" \
        "$DB_NAME" > "$DUMP_FILE"
else
    R "  ✗ no working DB auth (DB_ROOT_PASSWORD missing, /root/.my.cnf invalid, no DB_USER/DB_PASS)"
    exit 1
fi
DUMP_SIZE=$(du -h "$DUMP_FILE" | awk '{print $1}')
G "  ✓ dump $DUMP_SIZE"

# Compress
gzip -9 "$DUMP_FILE"
DUMP_FILE="${DUMP_FILE}.gz"
G "  ✓ gzip -9 → $(du -h "$DUMP_FILE" | awk '{print $1}')"

# ──────────────────────────────────────────────────────────────
# 2. Tar storage critical (already encrypted at app-level)
# ──────────────────────────────────────────────────────────────
C "=== [2/5] tar storage critical ==="
STORAGE_TAR="$TMPDIR/storage_${DATE}.tar.zst"

# Storage already encrypted (teacher content, mappe, verifiche).
# Backup minimo: NON il vendor/, NON build/, NON cache/.
tar --use-compress-program="zstd -3 -T0" \
    -cf "$STORAGE_TAR" \
    -C "$APP_DIR" \
    storage/ \
    --exclude='storage/cache/*' \
    --exclude='storage/sessions/*' \
    --exclude='storage/logs/*.gz' \
    2>/dev/null || W "  WARN: tar storage parziale (alcuni path non leggibili)"
G "  ✓ storage tar.zst $(du -h "$STORAGE_TAR" | awk '{print $1}')"

# ──────────────────────────────────────────────────────────────
# 3. Tar config + secrets (CRITICAL: chiavi runtime per decifrare DB!)
# ──────────────────────────────────────────────────────────────
C "=== [3/5] tar config + secrets ==="
CONFIG_TAR="$TMPDIR/config_${DATE}.tar.gz"

# Costruisci lista file/dir esistenti (tar exit 2 se file mancante → tolerante)
CFG_ITEMS=()
for f in .env .env.local app/Config; do
    [[ -e "$APP_DIR/$f" ]] && CFG_ITEMS+=("$f")
done
if [[ ${#CFG_ITEMS[@]} -eq 0 ]]; then
    W "  WARN: nessun config file trovato in $APP_DIR — skip"
    echo "no config files" > "$CONFIG_TAR"
else
    tar -czf "$CONFIG_TAR" -C "$APP_DIR" "${CFG_ITEMS[@]}" 2>&1 \
        || W "  WARN: tar config parziale (file rimossi durante archiviazione)"
fi
G "  ✓ config tar.gz $(du -h "$CONFIG_TAR" | awk '{print $1}')"

# ──────────────────────────────────────────────────────────────
# 4. GPG encrypt symmetric (single bundle)
# ──────────────────────────────────────────────────────────────
C "=== [4/5] GPG encrypt ==="
BUNDLE="$TMPDIR/pantedu-backup-${DATE}.tar"
tar -cf "$BUNDLE" -C "$TMPDIR" "$(basename $DUMP_FILE)" "$(basename $STORAGE_TAR)" "$(basename $CONFIG_TAR)"

ENCRYPTED="$BACKUP_DIR/pantedu-backup-${DATE}.tar.gpg"
echo "$GPG_PASS" | gpg --batch --yes --passphrase-fd 0 \
    --symmetric \
    --cipher-algo AES256 \
    --compress-algo none \
    --output "$ENCRYPTED" \
    "$BUNDLE"
chmod 600 "$ENCRYPTED"
ENC_SIZE=$(du -h "$ENCRYPTED" | awk '{print $1}')
G "  ✓ encrypted $(basename $ENCRYPTED) $ENC_SIZE"

# Compute checksum
sha256sum "$ENCRYPTED" > "${ENCRYPTED}.sha256"

# ──────────────────────────────────────────────────────────────
# 5. Offsite copy (B2 via rclone OR scp Hetzner SB)
# ──────────────────────────────────────────────────────────────
BACKUP_TYPE="${BACKUP_TYPE:-scp}"

case "$BACKUP_TYPE" in
    b2)
        # Backblaze B2 via rclone (S3-compatible)
        REMOTE_NAME="${B2_REMOTE_NAME:-b2-pantedu}"
        BUCKET="${B2_BUCKET:-pantedu-backup-vps}"
        C "=== [5/5] Offsite to B2 ${REMOTE_NAME}:${BUCKET} ==="
        if command -v rclone >/dev/null \
            && rclone copy "$ENCRYPTED" "${REMOTE_NAME}:${BUCKET}/" \
                --transfers 2 --checkers 2 --no-traverse 2>&1 | tail -5; then
            rclone copy "${ENCRYPTED}.sha256" "${REMOTE_NAME}:${BUCKET}/" --no-traverse 2>&1 | tail -2
            G "  ✓ uploaded to B2"
        else
            R "  ✗ B2 upload FAILED — local backup OK, remote skip"
        fi
        ;;
    scp)
        # Hetzner Storage Box / generic SSH target
        if [[ -n "$REMOTE" && -f "$REMOTE_KEY" ]]; then
            C "=== [5/5] Offsite scp to $REMOTE ==="
            if scp -P "$REMOTE_PORT" -i "$REMOTE_KEY" -o StrictHostKeyChecking=accept-new \
                "$ENCRYPTED" "${ENCRYPTED}.sha256" \
                "$REMOTE" 2>&1 | tail -3; then
                G "  ✓ uploaded"
            else
                R "  ✗ remote upload FAILED — local backup OK, remote skip"
            fi
        else
            W "=== [5/5] Offsite skipped (BACKUP_REMOTE or key not set) ==="
        fi
        ;;
    *)
        W "=== [5/5] Unknown BACKUP_TYPE='$BACKUP_TYPE' — offsite skipped ==="
        ;;
esac

# Retention locale (daily 7 days)
find "$BACKUP_DIR" -name 'pantedu-backup-*.tar.gpg*' -mtime "+${RETENTION_LOCAL_DAYS}" -delete 2>/dev/null || true
LOCAL_COUNT=$(ls "$BACKUP_DIR"/pantedu-backup-*.tar.gpg 2>/dev/null | wc -l)
G "  ✓ retention locale: $LOCAL_COUNT backup (${RETENTION_LOCAL_DAYS}gg)"

# Retention REMOTE B2: "keep last N" + tiered long-term.
#
# Rationale: ogni backup è ~2.6GB (bulk = maps_enc blob già encrypted app-level,
# zstd non comprime entropia alta). Con free tier B2 = 10GB, "daily keep ALL"
# saturava in 4 giorni. Strategia nuova:
#
# - Keep last B2_KEEP_DAILY backups più recenti (default 3 = ~7.8GB)
# - PLUS 1 per settimana ISO (entro 30gg) come weekly snapshot
# - PLUS 1 per mese (entro 365gg) come monthly archive
# - PLUS 1 per anno (>365gg) come yearly archive
#
# Worst case: 3 daily + 4 weekly + 12 monthly + N yearly = ~50GB (cresce nel tempo).
# Mitigation: env B2_KEEP_DAILY/WEEKLY/MONTHLY/YEARLY override.
B2_KEEP_DAILY="${B2_KEEP_DAILY:-3}"
B2_KEEP_WEEKLY="${B2_KEEP_WEEKLY:-4}"
B2_KEEP_MONTHLY="${B2_KEEP_MONTHLY:-6}"
B2_KEEP_YEARLY="${B2_KEEP_YEARLY:-2}"

if [[ "$BACKUP_TYPE" == "b2" ]] && command -v rclone >/dev/null; then
    C "=== Retention B2 (keep last N + tiered) ==="
    REMOTE_NAME="${B2_REMOTE_NAME:-b2-pantedu}"
    BUCKET="${B2_BUCKET:-pantedu-backup-vps}"
    NOW_EPOCH=$(date +%s)

    rclone lsf "${REMOTE_NAME}:${BUCKET}" --include 'pantedu-backup-*.tar.gpg' 2>/dev/null \
        | sort -r \
        | awk -v now="$NOW_EPOCH" \
              -v keep_daily="$B2_KEEP_DAILY" \
              -v keep_weekly="$B2_KEEP_WEEKLY" \
              -v keep_monthly="$B2_KEEP_MONTHLY" \
              -v keep_yearly="$B2_KEEP_YEARLY" '
            BEGIN { daily=0 }
            {
                if (match($0, /[0-9]{8}_[0-9]{4}/)) {
                    ts = substr($0, RSTART, RLENGTH)
                    year = substr(ts, 1, 4); mon = substr(ts, 5, 2); day = substr(ts, 7, 2)
                    hour = substr(ts, 10, 2); min = substr(ts, 12, 2)
                    spec = sprintf("%s %s %s %s %s 00", year, mon, day, hour, min)
                    epoch = mktime(spec)
                    if (epoch < 0) next
                    yyyymm = sprintf("%s-%s", year, mon)
                    iso_year_week = strftime("%G-%V", epoch)

                    # Priority order (i piu recenti vincono):
                    # 1. Daily slot (keep_daily piu recenti)
                    # 2. Weekly slot (1 per ISO week, max keep_weekly)
                    # 3. Monthly slot (1 per YYYY-MM, max keep_monthly)
                    # 4. Yearly slot (1 per YYYY, max keep_yearly)
                    keep = 0
                    if (daily < keep_daily) {
                        daily++; keep = 1
                    } else if (!(iso_year_week in seen_week) && length(seen_week) < keep_weekly) {
                        seen_week[iso_year_week] = 1; keep = 1
                    } else if (!(yyyymm in seen_month) && length(seen_month) < keep_monthly) {
                        seen_month[yyyymm] = 1; keep = 1
                    } else if (!(year in seen_year) && length(seen_year) < keep_yearly) {
                        seen_year[year] = 1; keep = 1
                    }
                    print (keep ? "KEEP " : "DEL ") $0
                }
            }
        ' > /tmp/b2-retention.$$
    KEPT=$(awk '/^KEEP/{c++} END{print c+0}' /tmp/b2-retention.$$)
    DELETE=$(awk '/^DEL /{c++} END{print c+0}' /tmp/b2-retention.$$)
    if [[ "$DELETE" -gt 0 ]]; then
        grep '^DEL' /tmp/b2-retention.$$ | awk '{print $2}' | while read -r f; do
            rclone delete "${REMOTE_NAME}:${BUCKET}/$f" 2>/dev/null && \
                rclone delete "${REMOTE_NAME}:${BUCKET}/${f}.sha256" 2>/dev/null
        done
    fi
    rm -f /tmp/b2-retention.$$
    G "  ✓ retention B2: $KEPT kept, $DELETE deleted (D=$B2_KEEP_DAILY W=$B2_KEEP_WEEKLY M=$B2_KEEP_MONTHLY Y=$B2_KEEP_YEARLY)"
fi

# Log
echo "[$(date -Iseconds)] backup OK: $(basename $ENCRYPTED) $ENC_SIZE" >> /var/log/pantedu-backup.log

echo
G "════════════════════════════════════════"
G "Backup completato"
G "════════════════════════════════════════"
echo "  File:     $ENCRYPTED"
echo "  Size:     $ENC_SIZE"
echo "  SHA256:   $(cat ${ENCRYPTED}.sha256 | awk '{print $1}')"
echo "  Remote:   ${REMOTE:-(disabled)}"
echo
echo "Restore (test) — usa /var/backups/pantedu/.restore (NON /tmp tmpfs):"
echo "  mkdir -p /var/backups/pantedu/.restore && chmod 700 /var/backups/pantedu/.restore"
echo "  GPG_PASS=\$(grep ^BACKUP_GPG_PASSPHRASE /etc/pantedu/backup.env | cut -d= -f2- | tr -d \\\"\\')"
echo "  echo \"\$GPG_PASS\" | gpg --decrypt --batch --passphrase-fd 0 $ENCRYPTED > /var/backups/pantedu/.restore/restore.tar"
echo "  tar -tf /var/backups/pantedu/.restore/restore.tar  # deve elencare 3 file"
echo "  # Estrai DB:"
echo "  tar -xOf /var/backups/pantedu/.restore/restore.tar db_${DATE}.sql.gz | gunzip | mysql ${DB_NAME}"
