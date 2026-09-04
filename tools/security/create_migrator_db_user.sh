#!/usr/bin/env bash
# Crea l'utente di database dedicato alle migration, e toglie all'utente del
# sito il privilegio TRIGGER.
#
# PERCHE' (2026-09-02)
#
# Le tabelle di audit sono append-only grazie a dei trigger
# (tools/security/apply_audit_append_only.php). Quei trigger pero' non
# proteggono se stessi: `pantedu_app` — l'utente con cui il sito parla al
# database — aveva fra i suoi privilegi anche TRIGGER, quindi chi ne avesse
# ottenuto le credenziali avrebbe potuto cancellarli e poi alterare il
# registro.
#
# Il privilegio non era li' per svista: le migration ne creano (la 038), e il
# migratore girava con le credenziali dell'applicativo. Da qui il terzo utente:
#
#   pantedu_app        legge e scrive i DATI. Nessun potere sulla struttura.
#   pantedu_migrator   ha i diritti DDL. Lo usa solo tools/migrate.php, che
#                      gira dal deploy e non e' raggiungibile dal web.
#   pantedu_maint      purga le righe scadute dai log. Solo job pianificati.
#
# Dopo questo script, chi ottenesse le credenziali del sito non puo' piu'
# rimuovere le protezioni sui log. Resta chi ha accesso amministrativo al
# server: quello e' un confine irriducibile, ed e' dichiarato nei documenti.
#
# Idempotente: rilanciarlo rigenera la password e riallinea .env.local.
# La password non viene mai stampata.
#
# Uso (come root):
#   sudo bash /var/www/pantedu/tools/security/create_migrator_db_user.sh

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/pantedu}"
ENV_FILE="$APP_DIR/.env.local"
DB_NAME="${DB_NAME:-pantedu}"
MIG_USER="pantedu_migrator"
APP_USER="pantedu_app"

if [[ $EUID -ne 0 ]]; then
    echo "Serve root (per mysql e per scrivere .env.local): usa sudo." >&2
    exit 1
fi
[[ -f "$ENV_FILE" ]] || { echo "File non trovato: $ENV_FILE" >&2; exit 1; }

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

echo "1. Creo/aggiorno l'utente $MIG_USER…"
mysql <<SQL
CREATE USER IF NOT EXISTS '${MIG_USER}'@'localhost' IDENTIFIED BY '${PW}';
ALTER USER '${MIG_USER}'@'localhost' IDENTIFIED BY '${PW}';

-- Diritti sulla STRUTTURA: le migration creano tabelle, indici, viste,
-- trigger e talvolta riempiono dati.
GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, ALTER, DROP, INDEX, REFERENCES,
      CREATE VIEW, SHOW VIEW, TRIGGER,
      CREATE ROUTINE, ALTER ROUTINE, EXECUTE,
      CREATE TEMPORARY TABLES, LOCK TABLES
  ON \`${DB_NAME}\`.* TO '${MIG_USER}'@'localhost';

FLUSH PRIVILEGES;
SQL

echo "2. Verifico che il migratore riesca a connettersi…"
mysql -u "$MIG_USER" -p"$PW" -N -e "SELECT 1;" "$DB_NAME" >/dev/null 2>&1 \
    || { echo "  connessione fallita: NON tolgo nulla a $APP_USER." >&2; exit 1; }
echo "  ok"

echo "3. Scrivo le credenziali in $ENV_FILE…"
tmp="$(mktemp "$(dirname "$ENV_FILE")/.env.local.XXXXXX")"
grep -v -E '^(DB_MIGRATOR_USER|DB_MIGRATOR_PASS)=' "$ENV_FILE" > "$tmp" || true
{
    printf '\n# 2026-09-02 — utente per le sole migration (DDL).\n'
    printf '# Esiste perche a pantedu_app e stato tolto il privilegio TRIGGER: senza,\n'
    printf '# chi ottenesse le credenziali del sito potrebbe rimuovere le protezioni\n'
    printf '# append-only sui log di audit e poi alterarli.\n'
    printf 'DB_MIGRATOR_USER=%s\n' "$MIG_USER"
    printf 'DB_MIGRATOR_PASS=%s\n' "$PW"
} >> "$tmp"
mv "$tmp" "$ENV_FILE"   # stessa directory: rename atomico
chown pantedu:www-data "$ENV_FILE"
chmod 640 "$ENV_FILE"
echo "  scritte (password non mostrata)"

echo "4. Provo una migration con le nuove credenziali PRIMA di togliere nulla…"
if sudo -u pantedu php "$APP_DIR/tools/migrate.php" --status >/dev/null 2>&1; then
    echo "  il migratore funziona"
else
    echo "  migrate.php --status fallisce: NON tolgo nulla a $APP_USER." >&2
    exit 1
fi

echo "5. Tolgo il privilegio TRIGGER a $APP_USER…"
mysql -e "REVOKE TRIGGER ON \`${DB_NAME}\`.* FROM '${APP_USER}'@'localhost'; FLUSH PRIVILEGES;"
echo "  fatto"

echo
echo "Privilegi residui di $APP_USER:"
mysql -N -e "SELECT GROUP_CONCAT(PRIVILEGE_TYPE ORDER BY PRIVILEGE_TYPE SEPARATOR ', ')
             FROM information_schema.SCHEMA_PRIVILEGES
             WHERE GRANTEE = \"'${APP_USER}'@'localhost'\";" | fold -w 92 -s | sed 's/^/  /'
echo
echo "TRIGGER ancora presente? (atteso: 0)"
mysql -N -e "SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES
             WHERE GRANTEE = \"'${APP_USER}'@'localhost'\" AND PRIVILEGE_TYPE='TRIGGER';" | sed 's/^/  /'
