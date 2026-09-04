#!/usr/bin/env bash
# Crea l'utente di database di sola manutenzione.
#
# PERCHE' (2026-09-02)
#
# Le tabelle di audit devono essere append-only: se chi entra nel sito puo'
# anche riscrivere il registro che racconta cosa ha fatto, quel registro non
# prova piu' niente. La misura era dichiarata in informativa, registro art. 30
# e DPIA come `REVOKE UPDATE, DELETE` sull'utente applicativo, ma non era
# applicabile cosi': la concessione di `pantedu_app` e' a livello di database,
# e MySQL/MariaDB non permette di sottrarre un permesso su singole tabelle da
# una concessione piu' ampia. Si usano quindi dei trigger
# (tools/security/apply_audit_append_only.php).
#
# I trigger pero' bloccherebbero anche la purga dei log scaduti, che il GDPR
# impone (art. 5(1)(e)). Da qui questo secondo utente: e' l'unico che i
# trigger lasciano passare in cancellazione, e lo usano SOLO i job pianificati
# (Database::maintenanceConnection legge DB_MAINT_USER / DB_MAINT_PASS).
#
# PERMESSI CONCESSI — solo cio' che i due job usano davvero:
#   SELECT su tutto        il dry-run conta le righe che tratterebbe
#   DELETE su 5 tabelle    le sole che purge_old_logs e anonymize_expired purgano
#   UPDATE su users        l'anonimizzazione degli account inattivi
# Nient'altro: non puo' leggere chiavi ne' scrivere contenuti.
#
# Idempotente: rilanciarlo rigenera la password e riallinea .env.local.
# La password non viene mai stampata.
#
# Uso (come root):
#   sudo bash /var/www/pantedu/tools/security/create_maintenance_db_user.sh

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/pantedu}"
ENV_FILE="$APP_DIR/.env.local"
DB_NAME="${DB_NAME:-pantedu}"
MAINT_USER="pantedu_maint"

if [[ $EUID -ne 0 ]]; then
    echo "Serve root (per mysql e per scrivere .env.local): usa sudo." >&2
    exit 1
fi
if [[ ! -f "$ENV_FILE" ]]; then
    echo "File non trovato: $ENV_FILE" >&2
    exit 1
fi

# Password robusta, mai stampata a video.
# .env.local puo' essere marcato immutabile (chattr +i): e' una convenzione
# di questo progetto, e il deploy stesso si rifiuta di toccarlo quando lo e'.
# Qui va tolto per il tempo della scrittura e RIMESSO subito, anche se
# qualcosa fallisce nel mezzo — di qui il trap.
ENV_WAS_IMMUTABLE=0
restore_immutable() {
    if [[ "$ENV_WAS_IMMUTABLE" -eq 1 ]]; then
        chattr +i "$ENV_FILE" 2>/dev/null || true
    fi
}
trap restore_immutable EXIT

if lsattr "$ENV_FILE" 2>/dev/null | awk '{print $1}' | grep -q i; then
    ENV_WAS_IMMUTABLE=1
    chattr -i "$ENV_FILE"
fi

PW="$(openssl rand -base64 30 | tr -dc 'A-Za-z0-9' | head -c 32)"

echo "Creo/aggiorno l'utente $MAINT_USER sul database $DB_NAME…"

mysql <<SQL
CREATE USER IF NOT EXISTS '${MAINT_USER}'@'localhost' IDENTIFIED BY '${PW}';
ALTER USER '${MAINT_USER}'@'localhost' IDENTIFIED BY '${PW}';

-- Il dry-run conta prima di cancellare: serve la lettura.
GRANT SELECT ON \`${DB_NAME}\`.* TO '${MAINT_USER}'@'localhost';

-- Le sole tabelle che i due job pianificati purgano.
GRANT DELETE ON \`${DB_NAME}\`.privileged_access_log TO '${MAINT_USER}'@'localhost';
GRANT DELETE ON \`${DB_NAME}\`.crypto_access_log     TO '${MAINT_USER}'@'localhost';
GRANT DELETE ON \`${DB_NAME}\`.content_action_log    TO '${MAINT_USER}'@'localhost';
GRANT DELETE ON \`${DB_NAME}\`.waf_logs              TO '${MAINT_USER}'@'localhost';
GRANT DELETE ON \`${DB_NAME}\`.registrations         TO '${MAINT_USER}'@'localhost';

-- Anonimizzazione degli account inattivi oltre la soglia.
GRANT UPDATE ON \`${DB_NAME}\`.users TO '${MAINT_USER}'@'localhost';

FLUSH PRIVILEGES;
SQL

# Credenziali in .env.local: si riscrivono le righe esistenti invece di
# accodarne di nuove, altrimenti a ogni rilancio il file crescerebbe e
# l'ultima riga vincerebbe in modo poco evidente.
tmp="$(mktemp "$(dirname "$ENV_FILE")/.env.local.XXXXXX")"
grep -v -E '^(DB_MAINT_USER|DB_MAINT_PASS)=' "$ENV_FILE" > "$tmp" || true
{
    printf '\n# 2026-09-02 — utente di sola manutenzione (purga log + anonimizzazione).\n'
    printf '# Le tabelle di audit sono append-only via trigger; questo e l unico\n'
    printf '# utente ammesso a cancellarne le righe scadute (art. 5(1)(e) GDPR).\n'
    printf 'DB_MAINT_USER=%s\n' "$MAINT_USER"
    printf 'DB_MAINT_PASS=%s\n' "$PW"
} >> "$tmp"
mv "$tmp" "$ENV_FILE"   # stessa directory: rename atomico
chown pantedu:www-data "$ENV_FILE"
chmod 640 "$ENV_FILE"

echo "Credenziali scritte in $ENV_FILE (password non mostrata)."
echo
echo "Permessi concessi:"
mysql -N -e "SHOW GRANTS FOR '${MAINT_USER}'@'localhost';" | sed 's/^/  /'
echo
echo "Prova di connessione col nuovo utente:"
if mysql -u "$MAINT_USER" -p"$PW" -N -e "SELECT 'ok' FROM DUAL;" "$DB_NAME" >/dev/null 2>&1; then
    echo "  connessione riuscita"
else
    echo "  ATTENZIONE: connessione fallita" >&2
    exit 1
fi
echo
echo "Passo successivo (applica i trigger append-only):"
echo "  sudo -u pantedu php $APP_DIR/tools/security/apply_audit_append_only.php --apply"
