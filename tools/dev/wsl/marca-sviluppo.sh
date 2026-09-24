#!/usr/bin/env bash
#
# Scrive il marcatore `.pantedu-dev-repo` nella radice del repository di
# sviluppo, se non c'è già.
#
# PERCHÉ (22/9/2026)
#   `tools/publish/sanitize-for-publication.php --apply` riscrive e cancella i
#   file che trova, e si rifiuta di girare solo dove trova questo marcatore. Il
#   marcatore però è in `.gitignore`: un clone nuovo non ce l'ha. La copia in
#   WSL, clonata il 10/9, ne è rimasta senza per dodici giorni, e nessuno se ne
#   era accorto — la guardia c'era solo nella vecchia copia di Windows.
#   Scriverlo qui, da `prepara.sh`, toglie il passo da ricordare a mano.
#
# Uso: bash tools/dev/wsl/marca-sviluppo.sh [radice]
#      (senza argomento: la radice del repository che contiene lo script)
# Prova: tests/ops/marca-sviluppo.test.sh
set -euo pipefail

RADICE="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
[ -d "$RADICE" ] || { echo "la cartella $RADICE non esiste" >&2; exit 1; }
MARCATORE="$RADICE/.pantedu-dev-repo"

if [ -f "$MARCATORE" ]; then
    echo "marcatore già presente: $MARCATORE"
    exit 0
fi

cat > "$MARCATORE" <<'TESTO'
Questo file marca il repository di SVILUPPO di Pantedu, che contiene dati reali
(seed, nomi, istituto).

tools/publish/sanitize-for-publication.php --apply si rifiuta di girare dove lo
trova: qui riscriverebbe e cancellerebbe i file di sviluppo. Per pubblicare si
usa tools/publish/release.sh, che lavora su un clone in una cartella temporanea.

Il file è in .gitignore: non si committa, e un clone nuovo non ce l'ha. Lo
scrive tools/dev/wsl/marca-sviluppo.sh, chiamato da tools/dev/wsl/prepara.sh.
TESTO
echo "marcatore scritto: $MARCATORE"
