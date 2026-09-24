#!/bin/bash
#
# Prove di `tools/dev/wsl/marca-sviluppo.sh`, nei due versi (22/9/2026).
#
# Il difetto che l'ha fatta nascere: il marcatore `.pantedu-dev-repo` è
# ignorato da git, e la copia di sviluppo in WSL ne è rimasta senza per dodici
# giorni. Senza marcatore, `sanitize-for-publication.php --apply` lanciato nel
# repository di sviluppo lo riscrive.
#
# Si prova su cartelle usa e getta, MAI sul repository vero:
#   - lo script scrive il marcatore dove manca, e non lo tocca dove c'è;
#   - con il marcatore il sanitizer rifiuta --apply (esce 2, «RIFIUTO»);
#   - senza, non rifiuta: altrimenti la prova sopra passerebbe anche con un
#     sanitizer che rifiuta sempre.
#
# Nella copia pubblica il sanitizer non c'è (si cancella da sé): lì si prova
# solo il marcatore, e lo si dice.
#
# Uso: bash tests/ops/marca-sviluppo.test.sh
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
RADICE_REPO=$(cd "$QUI/../.." && pwd)
SCRIPT="${SCRIPT:-$RADICE_REPO/tools/dev/wsl/marca-sviluppo.sh}"
SANITIZER="$RADICE_REPO/tools/publish/sanitize-for-publication.php"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

# 1. Dove manca, lo scrive.
mkdir -p "$T/nuovo"
bash "$SCRIPT" "$T/nuovo" > /dev/null
if [ -f "$T/nuovo/.pantedu-dev-repo" ] && grep -q "repository di SVILUPPO" "$T/nuovo/.pantedu-dev-repo"; then
    ok
else
    ko "il marcatore non è stato scritto in una cartella che non l'aveva"
fi

# 2. Dove c'è, non lo tocca.
mkdir -p "$T/esistente"
echo "contenuto mio" > "$T/esistente/.pantedu-dev-repo"
bash "$SCRIPT" "$T/esistente" > /dev/null
if [ "$(cat "$T/esistente/.pantedu-dev-repo")" = "contenuto mio" ]; then
    ok
else
    ko "il marcatore esistente è stato riscritto"
fi

# 3. Una cartella che non esiste è un errore, non un successo silenzioso.
if bash "$SCRIPT" "$T/non-esiste" > /dev/null 2>&1; then
    ko "una cartella inesistente è stata accettata"
else
    ok
fi

# 4 e 5. Il sanitizer: rifiuta con il marcatore, non rifiuta senza.
if [ -f "$SANITIZER" ] && command -v php > /dev/null; then
    mkdir -p "$T/con/docs" "$T/senza/docs"
    echo "prova" > "$T/con/docs/x.md"
    echo "prova" > "$T/senza/docs/x.md"
    bash "$SCRIPT" "$T/con" > /dev/null

    php "$SANITIZER" --apply --root="$T/con" > "$T/con.log" 2>&1
    esito=$?
    if [ "$esito" -eq 2 ] && grep -q "RIFIUTO" "$T/con.log"; then
        ok
    else
        ko "con il marcatore il sanitizer non ha rifiutato (esito $esito)"
    fi

    php "$SANITIZER" --apply --root="$T/senza" > "$T/senza.log" 2>&1
    esito=$?
    if grep -q "RIFIUTO" "$T/senza.log"; then
        ko "senza marcatore il sanitizer ha rifiutato lo stesso: la prova sopra non misura niente"
    else
        ok
    fi
elif [ -f "$SANITIZER" ]; then
    # Il sanitizer c'è ma php no: la guardia non si è provata. Non è un
    # successo.
    ko "php assente: il rifiuto del sanitizer non è stato provato"
else
    echo "  (sanitizer assente, come nella copia pubblica: provato solo il marcatore)"
fi

echo "marca-sviluppo: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
