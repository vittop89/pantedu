#!/bin/bash
#
# Il sanitizer della copia pubblica lascia indirizzi VALIDI nei file `.env*`
# e nel codice, e il segnaposto `{{OPERATORE_EMAIL}}` nei documenti, nei due
# versi (23/9/2026).
#
# Il difetto che l'ha fatta nascere: nel `.env.example` della copia pubblica
# `APP_MAIL_FROM` e `DPO_EMAIL` diventavano `{{OPERATORE_EMAIL}}`. Il Mailer
# rifiuta un indirizzo di risposta non valido, l'avviso degli incarichi tolti
# non partiva, e quattro prove d'integrazione fallivano sulla copia pubblica.
# Nessuno le faceva girare lì con il database: se ne è accorto l'esame della
# copia prima della ripubblicazione del 23/9.
#
# Il sanitizer gira con --root su una cartella usa e getta e --no-delete: tocca
# solo i file della prova, mai il repository.
#
# Controprova: SANITIZER=<altra versione> bash tests/ops/sanitizer-email-valide.test.sh
# con quella di prima (git show 08b363f1:tools/publish/sanitize-for-publication.php)
# deve fallire sul `.env.example`.
#
# Uso: bash tests/ops/sanitizer-email-valide.test.sh
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
RADICE_REPO=$(cd "$QUI/../.." && pwd)
SANITIZER="${SANITIZER:-$RADICE_REPO/tools/publish/sanitize-for-publication.php}"

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

# Nella copia pubblica il sanitizer non c'è (si cancella da sé): lì non c'è
# niente da provare, e lo si dice.
if [ ! -f "$SANITIZER" ]; then
    echo "sanitizer-email-valide: sanitizer assente (copia pubblica), niente da provare"
    exit 0
fi
if ! command -v php > /dev/null 2>&1; then
    ko "php assente: il sanitizer non è stato provato"
    echo "sanitizer-email-valide: $PASSATE passate, $FALLITE fallite"
    exit 1
fi

# Se mktemp fallisce T resta vuota: ci si ferma.
T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/sanitizer-email.XXXXXX") \
    || { echo "sanitizer-email-valide: mktemp non riuscito, niente da provare"; exit 1; }
[ -n "$T" ] && [ -d "$T" ] || { echo "sanitizer-email-valide: cartella temporanea mancante"; exit 1; }
trap 'rm -rf "${T:?}"' EXIT

mkdir -p "$T/copia/app/Config" "$T/copia/docs"
cat > "$T/copia/.env.example" <<'ENV'
APP_MAIL_FROM={{OPERATORE_EMAIL}}
DPO_EMAIL={{OPERATORE_EMAIL}}
ENV
printf '<?php\nreturn [\x27from\x27 => \{{OPERATORE_EMAIL}}\x27];\n' > "$T/copia/app/Config/mail.php"
printf 'Scrivere a {{OPERATORE_EMAIL}} per le segnalazioni.\n' > "$T/copia/docs/contatti.md"
# Lo stesso indirizzo in maiuscolo (23/9/2026): la sostituzione distingueva le
# maiuscole e la scansione dei residui no, quindi un indirizzo scritto così
# restava nella copia e fermava la pubblicazione (o, peggio, passava se la
# scansione fosse stata come la sostituzione).
printf '<?php\n$a = \{{OPERATORE_EMAIL}}\x27;\n' > "$T/copia/app/Config/maiuscole.php"

php "$SANITIZER" --apply --no-delete --root="$T/copia" > "$T/uscita" 2>&1
esito=$?
# Esce diverso da 0 solo se restano residui: qui non ce ne sono.
[ "$esito" -eq 0 ] && ok || ko "il sanitizer è uscito con $esito: $(tail -3 "$T/uscita")"

# valido FILE CHIAVE: il valore della chiave è un indirizzo email valido.
valido() {
    local v
    v=$(sed -n "s/^$2=//p" "$1")
    php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_EMAIL) === false ? 1 : 0);' "$v"
}

for chiave in APP_MAIL_FROM DPO_EMAIL; do
    valido "$T/copia/.env.example" "$chiave" \
        && ok || ko ".env.example: $chiave non è un indirizzo valido: «$(sed -n "s/^$chiave=//p" "$T/copia/.env.example")»"
done
grep -q 'pantedu\.eu' "$T/copia/.env.example" && ko ".env.example: resta un indirizzo @pantedu.eu" || ok

grep -q "'operatore@example.net'" "$T/copia/app/Config/mail.php" \
    && ok || ko "mail.php: atteso operatore@example.net, c'è «$(cat "$T/copia/app/Config/mail.php" | tail -1)»"

grep -q "'operatore@example.net'" "$T/copia/app/Config/maiuscole.php" \
    && ok || ko "maiuscole.php: atteso operatore@example.net, c'è «$(tail -1 "$T/copia/app/Config/maiuscole.php")»"

# Verso opposto: nei documenti resta il segnaposto da compilare, non un
# indirizzo che sembra vero.
grep -q '{{OPERATORE_EMAIL}}' "$T/copia/docs/contatti.md" \
    && ok || ko "contatti.md: atteso {{OPERATORE_EMAIL}}, c'è «$(cat "$T/copia/docs/contatti.md")»"

echo "sanitizer-email-valide: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
