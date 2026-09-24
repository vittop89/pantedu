#!/usr/bin/env bash
#
# Prepara il repository per lavorare in WSL: dipendenze e build costruite QUI,
# non copiate da Windows.
#
# Si parte da un clone fatto DENTRO WSL, nel filesystem di Linux:
#
#   git config --global credential.helper \
#       "/mnt/c/Program\ Files/Git/mingw64/bin/git-credential-manager.exe"
#   git clone <indirizzo-del-repository> ~/pantedu
#   cd ~/pantedu && bash tools/dev/wsl/prepara.sh
#
# Perché un clone e non una copia della cartella di Windows: su Windows
# `core.autocrlf=true` scrive CRLF nella copia di lavoro, e portata in WSL git
# vede centinaia di file modificati senza che nessuno li abbia toccati (613,
# misurati il 10/9/2026). E `node_modules` contiene binari nativi compilati per
# Windows, che in Linux danno errori che parlano d'altro.
#
# Opzione:
#   --dati-da <cartella>   copia anche i dati d'istanza di sviluppo (oggetti,
#                          blob cifrati, chiavi di sviluppo) da una cartella
#                          `storage/` esistente, per esempio quella di
#                          un'altra copia di sviluppo. Non stanno in git: senza,
#                          il database di sviluppo punta a file che non ci sono.
set -euo pipefail

RADICE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
case "$RADICE" in
    /mnt/*)
        echo "Il repository sta in $RADICE, cioè sul disco di Windows: da lì è da 8 a 19 volte"
        echo "più lento (misurato il 10/9/2026). Clonalo in ~ e rilancia da lì."
        exit 1
        ;;
esac
cd "$RADICE"

DATI_DA=""
if [ "${1:-}" = "--dati-da" ]; then
    DATI_DA="${2:?manca la cartella dei dati}"
    [ -d "$DATI_DA" ] || { echo "la cartella $DATI_DA non esiste"; exit 1; }
fi

t0=$(date +%s)

echo "== marcatore del repository di sviluppo =="
# Ignorato da git, quindi assente in ogni clone nuovo: senza, il sanitizer della
# pubblicazione accetterebbe --apply anche qui (22/9/2026).
bash tools/dev/wsl/marca-sviluppo.sh "$RADICE"

echo "== dipendenze PHP =="
# --no-plugins: su questo progetto un plugin di composer tiene appeso
# `dump-autoload`; le dipendenze non ne hanno bisogno per funzionare.
composer install --no-interaction --no-plugins --prefer-dist

echo
echo "== dipendenze Node =="
# puppeteer, se trova una sua cartella di cache vuota lasciata da un tentativo
# interrotto, rifiuta di riscaricare il browser («the executable is missing»).
# Una volta tolta la cache, riparte pulito.
npm ci --no-audit --no-fund || { rm -rf "$HOME/.cache/puppeteer"; npm ci --no-audit --no-fund; }

echo
echo "== build del front-end =="
npm run build
php tools/build-css-bundle.php

echo
echo "== browser di Playwright =="
# Le librerie di sistema del browser le installa tools/dev/wsl/pacchetti.sh,
# che chiede sudo; qui si scarica solo il browser.
npx playwright install chromium

if [ -n "$DATI_DA" ]; then
    echo
    echo "== dati d'istanza di sviluppo da $DATI_DA =="
    for d in objects verifiche_enc maps_enc risdoc config keys logs; do
        [ -d "$DATI_DA/$d" ] && rsync -a "$DATI_DA/$d/" "storage/$d/" && echo "  $d"
    done
    # In storage/data c'è un file versionato: quello resta com'è nel clone.
    [ -d "$DATI_DA/data" ] && rsync -a --ignore-existing "$DATI_DA/data/" storage/data/ && echo "  data"
fi

echo
echo "fatto in $(( $(date +%s) - t0 ))s. Poi, se non l'hai già fatto:"
echo "  bash tools/dev/wsl/database.sh      il database di sviluppo, in un contenitore"
echo "  bash tools/dev/wsl/server.sh        il server, su http://127.0.0.1:8000"
