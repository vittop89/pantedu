#!/bin/bash
#
# La password del database non passa sulla riga di comando, nei due versi
# (23/9/2026, A-86).
#
# Il salvataggio notturno delle chiavi dei docenti chiamava
# `mysqldump -p"$DB_PASS"`, e i due script che creano le utenze di
# manutenzione e di migrazione facevano lo stesso con `mysql` per la prova di
# connessione: per il tempo del comando la password stava negli argomenti del
# processo, leggibile con `ps`. Adesso passa da un file di opzioni temporaneo
# (tools/security/opzioni-mysql.sh).
#
# Qui si esegue lo script VERO del salvataggio, copiato in un albero finto con
# un .env.local finto, e un `mysqldump` finto che registra argomenti, ambiente
# e il file di opzioni che riceve. La password è una sentinella con virgolette,
# `#`, barra rovescia e spazi: deve arrivare intera nel file di opzioni (se non
# arrivasse, «non compare fra gli argomenti» sarebbe vero per il motivo
# sbagliato), non deve comparire fra gli argomenti né nell'ambiente, e il file
# non deve restare, nemmeno quando il comando fallisce. Altre due sentinelle,
# con il `#` dopo uno spazio o una lettera e spazi ai bordi, passano dalla
# funzione da sola: sono quelle che un file senza virgolette troncherebbe.
#
# Dei due script delle utenze si guarda il testo: vogliono root e un database
# vero, e qui non ci sono. La regola che li legge è provata nei due versi.
#
# Uso: bash tests/ops/credenziali-mysql.test.sh
# Variabili: SCRIPT_BACKUP (lo script del salvataggio), AIUTO_MYSQL (il file
# delle funzioni), SCRIPT_UTENZE (gli script delle utenze, separati da spazi),
# LETTORE_OPZIONI=inversa (legge il file di opzioni senza my_print_defaults).
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
RADICE=$(cd "$QUI/../.." && pwd)
SCRIPT_BACKUP="${SCRIPT_BACKUP:-$RADICE/tools/crypto/backup_teacher_keys.sh}"
AIUTO_MYSQL="${AIUTO_MYSQL:-$RADICE/tools/security/opzioni-mysql.sh}"
SCRIPT_UTENZE="${SCRIPT_UTENZE:-$RADICE/tools/security/create_maintenance_db_user.sh $RADICE/tools/security/create_migrator_db_user.sh}"
T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/credenziali-mysql.XXXXXX")
trap 'rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

for f in "$SCRIPT_BACKUP" "$AIUTO_MYSQL"; do
    [ -f "$f" ] || { echo "manca $f"; exit 1; }
done

# La sentinella. Nel .env.local finto sta fra apici singoli: lo script lo
# legge con `source`, e così arriva letterale.
SENTINELLA='Sentinella "doppia" # non-un-commento \ barra 7Q'
UTENTE='utente_prova_a86'

# Legge dal file di opzioni l'ultimo valore di una chiave, come fa il client:
# con my_print_defaults quando c'è (è il lettore vero di MariaDB/MySQL),
# altrimenti con la regola inversa di _valore_opzione_mysql.
LETTORE="regola inversa"
command -v my_print_defaults >/dev/null 2>&1 && LETTORE="my_print_defaults"
# LETTORE_OPZIONI=inversa forza la regola inversa, per provarla dove
# my_print_defaults c'è.
[ "${LETTORE_OPZIONI:-}" = inversa ] && LETTORE="regola inversa"
valore_letto() {  # file chiave
    if [ "$LETTORE" = my_print_defaults ]; then
        my_print_defaults --defaults-file="$1" client mysqldump \
            | sed -n "s/^--$2=//p" | tail -n 1
    else
        sed -n "s/^$2=\"\(.*\)\"$/\1/p" "$1" | tail -n 1 \
            | sed -e 's/\\"/"/g' -e 's/\\\\/\\/g'
    fi
}

# ── L'albero finto ────────────────────────────────────────────────────────
REPO="$T/repo"
mkdir -p "$REPO/tools/crypto" "$REPO/tools/security" "$T/bin" "$T/tmp" "$T/reg"
cp "$SCRIPT_BACKUP" "$REPO/tools/crypto/backup_teacher_keys.sh"
cp "$AIUTO_MYSQL" "$REPO/tools/security/opzioni-mysql.sh"
cat > "$REPO/.env.local" <<EOF
DB_HOST=127.0.0.1
DB_NAME=pantedu_prova
DB_USER=$UTENTE
DB_PASS='$SENTINELLA'
EOF

# Una configurazione di sistema finta, con credenziali sue: quelle dello
# script vengono dopo e devono vincere.
SISTEMA="$T/sistema.cnf"
printf '[client]\nuser=utente_di_sistema\npassword=password_di_sistema\n' > "$SISTEMA"

# Un ~/.my.cnf con credenziali sue, come quello di root in produzione: il
# client lo legge DOPO un --defaults-extra-file, e l'ultimo valore vince.
mkdir -p "$T/casa"
printf '[client]\nuser=utente_di_casa\npassword=password_di_casa\n' > "$T/casa/.my.cnf"

# Il mysqldump finto: registra tutto e produce una riga di dump.
cat > "$T/bin/mysqldump" <<'EOF'
#!/bin/bash
printf '%s\n' "$@" > "$REGISTRO/argomenti"
env > "$REGISTRO/ambiente"
f=""
case "${1:-}" in
    --defaults-file=* | --defaults-extra-file=*)
        printf '%s\n' "${1%%=*}" > "$REGISTRO/opzione"
        f=${1#*=}
        ;;
esac
printf '%s\n' "$f" > "$REGISTRO/percorso"
if [ -n "$f" ] && [ -f "$f" ]; then
    stat -c %a "$f" > "$REGISTRO/permessi"
    cp "$f" "$REGISTRO/opzioni"
fi
[ "${FALLISCI:-0}" = 1 ] && exit 3
echo 'INSERT INTO `teacher_keys` VALUES (1,0x00);'
EOF
chmod +x "$T/bin/mysqldump"

esegui() {  # [FALLISCI=1]
    rm -f "$T/reg/"*
    env -i PATH="$T/bin:/usr/bin:/bin" HOME="$T/casa" TMPDIR="$T/tmp" \
        REGISTRO="$T/reg" OPZIONI_MYSQL_SISTEMA="$SISTEMA" "$@" \
        bash "$REPO/tools/crypto/backup_teacher_keys.sh" > "$T/uscita" 2>&1
}

# ── Verso 1: il salvataggio riesce, e la password non è fra gli argomenti ──
esegui
esito=$?
[ "$esito" -eq 0 ] && ok || { ko "il salvataggio doveva riuscire (esito $esito)"; sed 's/^/    /' "$T/uscita"; }

[ -f "$T/reg/argomenti" ] && ok || ko "mysqldump non è stato chiamato"
if grep -qF -- "$SENTINELLA" "$T/reg/argomenti" 2>/dev/null || grep -qF -- "Sentinella" "$T/reg/argomenti" 2>/dev/null; then
    ko "la password compare fra gli argomenti di mysqldump"
else
    ok
fi
grep -qE -- '^(-p.+|--password(=.*)?)$' "$T/reg/argomenti" 2>/dev/null \
    && ko "fra gli argomenti c'è ancora un'opzione di password" || ok
if grep -qF -- "Sentinella" "$T/reg/ambiente" 2>/dev/null; then
    ko "la password è nell'ambiente di mysqldump (va letta, non esportata)"
else
    ok
fi

# Il file di opzioni: primo argomento, 0600, dentro TMPDIR, con la password.
percorso=$(cat "$T/reg/percorso" 2>/dev/null)
opzione=$(cat "$T/reg/opzione" 2>/dev/null)
case "$opzione:$percorso" in
    "--defaults-file:$T/tmp/"*) ok ;;
    *) ko "mysqldump doveva ricevere --defaults-file=<file in TMPDIR> come primo argomento (ricevuto: «$(head -n 1 "$T/reg/argomenti" 2>/dev/null)»)" ;;
esac
[ "$(cat "$T/reg/permessi" 2>/dev/null)" = 600 ] && ok \
    || ko "il file di opzioni doveva essere 0600 (era «$(cat "$T/reg/permessi" 2>/dev/null)»)"
if [ -f "$T/reg/opzioni" ]; then
    letta=$(valore_letto "$T/reg/opzioni" password)
    [ "$letta" = "$SENTINELLA" ] && ok \
        || ko "la password letta dal file ($LETTORE) non è quella di .env.local"
    [ "$(valore_letto "$T/reg/opzioni" user)" = "$UTENTE" ] && ok \
        || ko "l'utente letto dal file ($LETTORE) non è quello di .env.local"
    grep -qxF "!include $SISTEMA" "$T/reg/opzioni" && ok \
        || ko "il file di opzioni doveva includere la configurazione di sistema"
    # Che cosa legge davvero il client con l'opzione che ha ricevuto, e con
    # un ~/.my.cnf che porta un'altra password: deve vincere quella dello
    # script. Con --defaults-extra-file vincerebbe ~/.my.cnf.
    if [ "$LETTORE" = my_print_defaults ] && [ -n "$opzione" ]; then
        ultima=$(HOME="$T/casa" my_print_defaults "$opzione=$T/reg/opzioni" client mysqldump \
            | sed -n 's/^--password=//p' | tail -n 1)
        [ "$ultima" = "$SENTINELLA" ] && ok \
            || ko "con $opzione il client non userebbe la password dello script (un ~/.my.cnf letto dopo, o un valore troncato)"
    fi
else
    ko "nessun file di opzioni ricevuto da mysqldump"
fi

# Il file non resta.
[ -n "$percorso" ] && [ ! -e "$percorso" ] && ok || ko "il file di opzioni è rimasto: $percorso"
[ -z "$(ls -A "$T/tmp")" ] && ok || ko "in TMPDIR è rimasto qualcosa: $(ls -A "$T/tmp" | tr '\n' ' ')"

# Il salvataggio c'è davvero (il controllo di sanità dello script lo esige).
ls "$REPO/storage/backups/teacher_keys/"teacher_keys_*.sql.gz >/dev/null 2>&1 && ok \
    || ko "il file del salvataggio non c'è"

# ── Verso 2: mysqldump fallisce, lo script fallisce e il file non resta ───
esegui FALLISCI=1
esito=$?
[ "$esito" -ne 0 ] && ok || ko "con mysqldump che fallisce lo script doveva fallire"
percorso=$(cat "$T/reg/percorso" 2>/dev/null)
[ -n "$percorso" ] && [ ! -e "$percorso" ] && ok \
    || ko "dopo un fallimento il file di opzioni è rimasto: $percorso"
[ -z "$(ls -A "$T/tmp")" ] && ok || ko "dopo un fallimento in TMPDIR è rimasto qualcosa"

# ── La funzione da sola: esito del comando, e un a capo rifiutato ─────────
# Un comando finto che segna di essere partito ed esce con $CODICE.
cat > "$T/bin/comando-finto" <<'EOF'
#!/bin/bash
touch "$SEGNO"
exit "${CODICE:-0}"
EOF
chmod +x "$T/bin/comando-finto"
# shellcheck source=/dev/null
. "$AIUTO_MYSQL"
SEGNO="$T/partito" CODICE=7 TMPDIR="$T/tmp" OPZIONI_MYSQL_SISTEMA=/non/esiste \
    con_credenziali_mysql u p "$T/bin/comando-finto" 2>/dev/null
esito=$?
[ "$esito" -eq 7 ] && [ -e "$T/partito" ] && ok \
    || ko "con_credenziali_mysql deve lanciare il comando e restituirne l'esito (esito $esito)"
SEGNO="$T/non-doveva-partire" TMPDIR="$T/tmp" \
    con_credenziali_mysql u $'riga\naltra' "$T/bin/comando-finto" 2>/dev/null
esito=$?
[ "$esito" -ne 0 ] && [ ! -e "$T/non-doveva-partire" ] && ok \
    || ko "una password con un a capo va rifiutata prima di lanciare il comando"
[ -z "$(ls -A "$T/tmp")" ] && ok || ko "la funzione ha lasciato file in TMPDIR"

# ── La funzione da sola: password che senza virgolette si troncano ────────
# La sentinella di sopra ha un `\"` subito prima del `#`, e così MariaDB non
# la tronca nemmeno se il file la scrive senza virgolette esterne: da sola non
# vedrebbe quel difetto. Queste hanno il `#` dopo uno spazio o dopo una
# lettera, e spazi ai bordi: senza virgolette il client leggerebbe «Seconda
# sentinella» e «Terza», o toglierebbe gli spazi (misurato con
# my_print_defaults di MariaDB il 23/9/2026).
cat > "$T/bin/copia-opzioni" <<'EOF'
#!/bin/bash
cp "${1#--defaults-file=}" "$COPIA"
EOF
chmod +x "$T/bin/copia-opzioni"
for s in ' Seconda sentinella #dopo il cancelletto 8R ' 'Terza#sentinella'; do
    rm -f "$T/opzioni-copia"
    COPIA="$T/opzioni-copia" TMPDIR="$T/tmp" OPZIONI_MYSQL_SISTEMA=/non/esiste \
        con_credenziali_mysql "$UTENTE" "$s" "$T/bin/copia-opzioni"
    if [ -f "$T/opzioni-copia" ] && [ "$(valore_letto "$T/opzioni-copia" password)" = "$s" ]; then
        ok
    else
        ko "la password «$s» non arriva intera al client ($LETTORE): letta «$(valore_letto "$T/opzioni-copia" password 2>/dev/null)»"
    fi
done

# ── Gli script delle utenze, dal testo ────────────────────────────────────
# Una riga che chiama mysql/mysqldump con -p<valore> o --password=<valore>.
password_in_riga() { grep -nE -- '(mysql|mysqldump|mariadb|mariadb-dump)\b.*[[:space:]](-p[^[:space:]]+|--password=)' "$@"; }

# La regola nei due versi, su righe finte.
printf 'mysql -u "$U" -p"$PW" -N -e "SELECT 1;" db\n' > "$T/riga-cattiva"
printf 'con_credenziali_mysql "$U" "$PW" mysql -N -e "SELECT 1;" db\nmysql -N -e "SHOW GRANTS"\n' > "$T/riga-buona"
password_in_riga "$T/riga-cattiva" >/dev/null && ok || ko "la regola non vede -p\"\$PW\""
password_in_riga "$T/riga-buona" >/dev/null && ko "la regola scatta su righe senza password" || ok

for s in $SCRIPT_UTENZE "$SCRIPT_BACKUP"; do
    [ -f "$s" ] || { ko "manca $s"; continue; }
    if trovate=$(password_in_riga "$s"); then
        ko "$(basename "$s") passa ancora una password sulla riga di comando: $trovate"
    else
        ok
    fi
    grep -q 'con_credenziali_mysql' "$s" && ok \
        || ko "$(basename "$s") non usa con_credenziali_mysql"
done

echo "credenziali del database fuori dalla riga di comando ($LETTORE): $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
