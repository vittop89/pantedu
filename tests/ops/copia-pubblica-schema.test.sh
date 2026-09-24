#!/bin/bash
#
# Lo schema del database entra nel commit della copia pubblica, i dump e le
# schede del pentest no, nei due versi (23/9/2026).
#
# Il difetto che l'ha fatta nascere: `tools/publish/release.sh` ricrea `.git`
# nella copia e fa `git add -A`, che rispetta `.gitignore`. Lì c'è `*.sql`, e
# `database/schema.sql` — tracciato nel repository di sviluppo, aggiunto a
# forza — restava fuori dal repository pubblico: chi lo clonava non poteva
# creare il database. Trovato confrontando un clone del pubblico con la copia
# verificata, dopo la pubblicazione del 23/9.
#
# La prova rifà quel passo su una cartella usa e getta, con il `.gitignore` del
# repository: `git init` + `git add -A`, e guarda che cosa entra nell'indice.
# Nel verso opposto controlla che la barriera resti: un dump `.sql` e le schede
# `findings/` del pentest (full-disclosure involontario) restano fuori.
#
# Controprova: GITIGNORE=<altro file> bash tests/ops/copia-pubblica-schema.test.sh
# con il .gitignore di prima (git show 08b363f1:.gitignore) deve fallire.
#
# Uso: bash tests/ops/copia-pubblica-schema.test.sh
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
RADICE_REPO=$(cd "$QUI/../.." && pwd)
GITIGNORE="${GITIGNORE:-$RADICE_REPO/.gitignore}"

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

if ! command -v git > /dev/null 2>&1; then
    ko "git assente: niente è stato provato"
    echo "copia-pubblica-schema: $PASSATE passate, $FALLITE fallite"
    exit 1
fi

T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/copia-schema.XXXXXX") \
    || { echo "copia-pubblica-schema: mktemp non riuscito, niente da provare"; exit 1; }
[ -n "$T" ] && [ -d "$T" ] || { echo "copia-pubblica-schema: cartella temporanea mancante"; exit 1; }
trap 'rm -rf "${T:?}"' EXIT

C="$T/copia"
mkdir -p "$C/database" "$C/docs/security/pentest/2026-04-29/findings" "$C/app"
cp "$GITIGNORE" "$C/.gitignore"
echo "CREATE TABLE prova (id INT);" > "$C/database/schema.sql"
echo "INSERT INTO utenti VALUES (1);" > "$C/database/dump.sql"
echo "INSERT INTO utenti VALUES (1);" > "$C/dump-di-prova.sql"
echo "# vulnerabilità non ancora corretta" > "$C/docs/security/pentest/2026-04-29/findings/X-001.md"
echo "<?php" > "$C/app/Qualcosa.php"

# Il passo 5 di release.sh, com'è.
git -C "$C" init -q -b main
git -C "$C" add -A
indice=$(git -C "$C" ls-files)

nell_indice() { printf '%s\n' "$indice" | grep -qx "$1"; }

# Controllo positivo: se nemmeno un file qualunque entra, la prova non misura.
nell_indice "app/Qualcosa.php" && ok || ko "neanche app/Qualcosa.php è entrato: la prova non misura niente"

nell_indice "database/schema.sql" && ok || ko "database/schema.sql non entra nel commit pubblico"

nell_indice "database/dump.sql" && ko "un dump (database/dump.sql) entra nel commit pubblico" || ok
nell_indice "dump-di-prova.sql" && ko "un dump alla radice entra nel commit pubblico" || ok
nell_indice "docs/security/pentest/2026-04-29/findings/X-001.md" \
    && ko "le schede findings/ del pentest entrano nel commit pubblico" || ok

echo "copia-pubblica-schema: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
