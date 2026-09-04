#!/usr/bin/env bash
# Genera i PDF dei documenti legali/privacy da .md, con pandoc + xelatex.
#
#   ./tools/legal/build_pdf.sh docs/privacy/dpia.md [altri.md ...]
#   ./tools/legal/build_pdf.sh --all
#
# PERCHE' QUESTO SCRIPT ESISTE (2026-09-01)
#
# Il comando pandoc documentato in docs/legal/README.md ha due trappole, e
# nessuna delle due si manifesta come errore: il PDF viene prodotto lo stesso.
#
#   1. Caratteri di disegno (─ │ ├ └ ▼) usati nei diagrammi ASCII. Il font
#      monospace predefinito non li ha; senza `-V monofont=Consolas` xelatex li
#      scarta e il diagramma dell'architettura della DPIA esce a pezzi.
#
#   2. Emoji (✅ ⚠️ ❌ ⬜ 🚨). Non esistono in Calibri e xelatex compone al loro
#      posto un RETTANGOLO SEGNAPOSTO: in un documento consegnato al DPO sembra
#      un difetto di produzione.
#
# La (1) si risolve col font giusto. La (2) no: xelatex non supporta i font di
# ripiego (servirebbe lualatex + mainfontfallback). Qui le emoji vengono quindi
# rimosse PRIMA della conversione, su una copia temporanea — il markdown resta
# intatto, e sul web (TrustPagesController) continua a mostrarle.
#
# Rimuoverle e' sicuro perche' sono decorative: "**BASSO** ✅" resta "BASSO",
# "✅ DPA di Hetzner" resta "DPA di Hetzner", e gli "❌" stanno sotto un titolo
# che dice gia' "Trattamenti esplicitamente esclusi".
#
# Il PDF del pacchetto DPO usa un'altra pipeline (Edge headless, CSS proprio):
# vedi docs/dpo/pacchetto-scuola/_gen_pdf.py. Li' le emoji si vedono.

set -euo pipefail

# Git Bash lanciata da PowerShell puo' avere $USER vuoto e un PATH senza le
# voci aggiunte dagli installer dopo l'apertura della finestra (2026-09-03:
# "pandoc non trovato" con pandoc regolarmente installato). Si cerca quindi
# nelle posizioni note, per pandoc e per xelatex.
WINUSER="${USER:-${USERNAME:-}}"
LOCALAPP="$( [[ -n "${LOCALAPPDATA:-}" ]] && cygpath -u "$LOCALAPPDATA" 2>/dev/null || echo "/c/Users/$WINUSER/AppData/Local" )"

PANDOC="${PANDOC:-pandoc}"
if ! command -v "$PANDOC" >/dev/null 2>&1; then
    for cand in "$LOCALAPP/Pandoc/pandoc.exe" "/c/Program Files/Pandoc/pandoc.exe"; do
        [[ -x "$cand" ]] && { PANDOC="$cand"; break; }
    done
fi
command -v "$PANDOC" >/dev/null 2>&1 || { echo "pandoc non trovato: winget install --id JohnMacFarlane.Pandoc" >&2; exit 1; }

if ! command -v xelatex >/dev/null 2>&1; then
    for cand in "$LOCALAPP/Programs/MiKTeX/miktex/bin/x64" "/c/Program Files/MiKTeX/miktex/bin/x64" /c/texlive/*/bin/windows; do
        [[ -x "$cand/xelatex.exe" ]] && { export PATH="$cand:$PATH"; break; }
    done
fi
command -v xelatex >/dev/null 2>&1 || { echo "xelatex non trovato: serve MiKTeX (winget install --id MiKTeX.MiKTeX) o TeX Live" >&2; exit 1; }

ALL_DOCS=(
    docs/legal/tos_docente.md
    docs/legal/aup.md
    docs/legal/takedown_procedure.md
    docs/legal/dpa_template.md
    docs/privacy/informativa.md
    docs/privacy/dpia.md
    docs/privacy/registro-trattamenti.md
)

if [[ "${1:-}" == "--all" ]]; then
    set -- "${ALL_DOCS[@]}"
fi
[[ $# -gt 0 ]] || { echo "uso: $0 <file.md ...> | --all" >&2; exit 2; }

# U+2705 ✅ · U+26A0 ⚠ · U+FE0F selettore variazione · U+274C ❌
# U+2B1C ⬜ · U+1F6A8 🚨 — decorative, vedi nota in testa.
STRIP_EMOJI='s/[\x{2705}\x{26A0}\x{FE0F}\x{274C}\x{2B1C}\x{1F6A8}]//g'

rc=0
for md in "$@"; do
    [[ -f "$md" ]] || { echo "  MANCA  $md" >&2; rc=1; continue; }
    dir=$(dirname "$md"); base=$(basename "$md" .md)
    tmp=$(mktemp "${TMPDIR:-/tmp}/pdfbuild.XXXXXX.md")
    # 1. via le emoji  2. colonne riproporzionate al contenuto (vedi _fit_tables.py)
    perl -CSD -pe "$STRIP_EMOJI" "$md" | python "$(dirname "$0")/_fit_tables.py" > "$tmp"

    # L'uscita di pandoc si raccoglie in una variabile, NON si incanala in una
    # pipe: dentro `$(... | grep -c ...)` l'esito del comando e' quello di grep,
    # e un fallimento di pandoc passava inosservato. Lo script annunciava "ok"
    # contando le pagine del PDF vecchio, rimasto sul disco.
    if ! out=$("$PANDOC" "$tmp" --pdf-engine=xelatex \
        -V documentclass=article -V geometry:margin=2cm -V fontsize=10pt \
        -V mainfont="Calibri" -V monofont="Consolas" -V lang=it \
        -H "$(dirname "$0")/preamble.tex" \
        --resource-path="$dir" -o "$dir/$base.pdf" 2>&1)
    then
        printf "  %-34s  FALLITO — sul disco resta il PDF precedente\n" "$base.pdf"
        printf '%s\n' "$out" | sed 's/^/      /' >&2
        rm -f "$tmp"; rc=1; continue
    fi
    warn=$(printf '%s\n' "$out" | grep -c "Missing character" || true)
    rm -f "$tmp"

    pages=$(pdfinfo "$dir/$base.pdf" 2>/dev/null | awk '/^Pages/{print $2}')
    if [[ "$warn" -gt 0 ]]; then
        printf "  %-34s %3s pag  ATTENZIONE: %s glifi mancanti\n" "$base.pdf" "${pages:-?}" "$warn"
        rc=1
    else
        printf "  %-34s %3s pag  ok\n" "$base.pdf" "${pages:-?}"
    fi
done
exit $rc
