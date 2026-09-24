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
#   DELETE                 sulle tabelle di tools/audit/tabelle_da_purgare.php (la
#                          lista che purge_old_logs usa, letta da li': una sola
#                          fonte)
# Nient'altro: non puo' leggere chiavi ne' scrivere contenuti.
#
# 2026-09-24 — tolto il DELETE su `registrations`: anonymize_expired la
# svuotava, ma nessun codice la scrive (le domande di iscrizione stanno in un
# file, e il lavoro adesso pulisce quello). Chi ha gia' il permesso lo tiene
# finche' non lo si revoca a mano: su una tabella vuota non serve a niente.
#
# 2026-09-24 — non ha piu' UPDATE su users. L'anonimizzazione degli account
# inattivi era un UPDATE su users fatto con questa utenza; adesso e' la
# cancellazione completa dell'art. 17 (App\Services\Gdpr\CancellazioneDellAccount),
# che cancella contenuti e scrive nel verbale dei Termini, e anonymize_expired
# la fa con la connessione dell'applicazione. Lo script concede soltanto: sulle
# utenze gia' create il permesso vecchio resta finche' non lo si revoca a mano
# (REVOKE UPDATE ON <db>.users FROM l'utenza di manutenzione).
#
# 2026-09-15 — i DELETE erano scritti qui a mano, cinque tabelle, mentre la
# lista della purga ne aveva otto: audit_activity_log, teacher_recovery_audit e
# consent_audit non si sarebbero mai purgate, e lo script di purga non lo diceva.
#
# Idempotente: rilanciarlo rigenera la password e riallinea .env.local.
# La password non viene mai stampata. Con --solo-permessi riallinea solo i
# permessi, senza toccare password e .env.local.
#
# Mostra i permessi senza la riga `GRANT USAGE … IDENTIFIED BY PASSWORD`: è
# l'hash della password dell'utenza, e il 15/9/2026 finiva nel terminale di chi
# lanciava --solo-permessi.
#
# Uso (come root):
#   sudo bash /var/www/pantedu/tools/security/create_maintenance_db_user.sh
#   sudo bash /var/www/pantedu/tools/security/create_maintenance_db_user.sh --solo-permessi

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/pantedu}"
ENV_FILE="$APP_DIR/.env.local"
DB_NAME="${DB_NAME:-pantedu}"
MAINT_USER="pantedu_maint"
SOLO_PERMESSI=0
# shellcheck source=opzioni-mysql.sh
. "$(dirname "${BASH_SOURCE[0]}")/opzioni-mysql.sh"
[[ "${1:-}" == "--solo-permessi" ]] && SOLO_PERMESSI=1

# Le tabelle da purgare, dalla stessa lista che usa purge_old_logs.php.
# `php -n`: senza configurazione, e senza leggere .env.local.
TABELLE_DA_PURGARE="$(php -n -r 'foreach (require $argv[1] as $t => $c) { if (preg_match("/^[a-z_]+$/", $t)) echo $t, "\n"; }' "$APP_DIR/tools/audit/tabelle_da_purgare.php")"
if [[ -z "$TABELLE_DA_PURGARE" ]]; then
    echo "Nessuna tabella letta da tools/audit/tabelle_da_purgare.php: mi fermo." >&2
    exit 1
fi
# I permessi dell'utenza, senza l'hash della password.
mostra_permessi() {
    mysql -N -e "SHOW GRANTS FOR '${MAINT_USER}'@'localhost';" \
        | { grep -v 'IDENTIFIED BY PASSWORD' || true; } \
        | sed 's/^/  /'
}

GRANT_DELETE=""
for t in $TABELLE_DA_PURGARE; do
    GRANT_DELETE+="GRANT DELETE ON \`${DB_NAME}\`.\`${t}\` TO '${MAINT_USER}'@'localhost';"$'\n'
done

if [[ "$SOLO_PERMESSI" -eq 1 ]]; then
    if [[ $EUID -ne 0 ]]; then
        echo "Serve root (per mysql): usa sudo." >&2
        exit 1
    fi
    if ! mysql -N -e "SELECT 1 FROM mysql.user WHERE user='${MAINT_USER}' AND host='localhost'" | grep -q 1; then
        echo "L'utente ${MAINT_USER} non esiste: lancia lo script senza --solo-permessi." >&2
        exit 1
    fi
    printf '%s\nFLUSH PRIVILEGES;\n' "$GRANT_DELETE" | mysql
    echo "Permessi di ${MAINT_USER}:"
    mostra_permessi
    exit 0
fi

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

-- Le sole tabelle che i due job pianificati purgano (vedi TABELLE_DA_PURGARE).
${GRANT_DELETE}

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
mostra_permessi
echo
echo "Prova di connessione col nuovo utente:"
# 23/9/2026 (A-86) — la password passa da un file di opzioni temporaneo, non
# da `-p` sulla riga di comando; e con --defaults-file, perché da root
# /root/.my.cnf avrebbe sostituito le credenziali e la prova si sarebbe
# collegata come root (vedi opzioni-mysql.sh).
if con_credenziali_mysql "$MAINT_USER" "$PW" mysql -N -e "SELECT 'ok' FROM DUAL;" "$DB_NAME" >/dev/null 2>&1; then
    echo "  connessione riuscita"
else
    echo "  ATTENZIONE: connessione fallita" >&2
    exit 1
fi
echo
echo "Passo successivo (applica i trigger append-only):"
echo "  sudo -u pantedu php $APP_DIR/tools/security/apply_audit_append_only.php --apply"
