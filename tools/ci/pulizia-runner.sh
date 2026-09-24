#!/usr/bin/env bash
#
# Pulizia mensile della macchina che ospita i runner.
#
# Perché serve. Su un runner in casa niente si butta via da solo: le immagini
# di Docker, la cache dei build e i container fermi si accumulano finché il
# disco non finisce. Sul VPS lo stesso problema, misurato l'8 settembre 2026,
# ha mangiato **12 GB in un pomeriggio** — di cui 10 di sola cache. Là la
# potatura la fa il rilascio; qui non c'è nessuno che la faccia, quindi la fa
# un timer.
#
# Perché è prudente. Non usa `docker image prune -a`, che porterebbe via anche
# le immagini di base (php, node, mariadb, composer): sono vecchie per
# definizione, e riscaricarle a ogni giro è tempo buttato — sono proprio loro
# a far stare i job sotto il minuto. Toglie:
#
#   - i container fermi, che non servono a nessuno;
#   - le immagini senza nome (i livelli orfani dei build);
#   - le immagini di pantedu oltre le ultime dieci;
#   - la cache dei build oltre un tetto.
#
# Si esegue come l'utente dei runner, non come root: tocca solo Docker.

set -euo pipefail

TETTO_CACHE="${TETTO_CACHE:-10GB}"
QUANTE_IMMAGINI="${QUANTE_IMMAGINI:-10}"

nota() { printf '[pulizia] %s\n' "$*"; }

if ! docker info >/dev/null 2>&1; then
    nota "Docker non risponde: non c'è niente da pulire."
    exit 0
fi

prima_disco=$(df -BG --output=avail / | tail -1 | tr -dc '0-9')
nota "prima: ${prima_disco} GB liberi"

nota "container fermi"
docker container prune -f >/dev/null 2>&1 || true

nota "volumi anonimi"
# Li crea Docker per i container di servizio: ogni giro di CI con un MariaDB
# ne lascia uno indietro. Dopo una sera sola erano quattordici, 2,4 GB.
#
# `docker volume prune` senza `--all` tocca **solo gli anonimi**, cioe' quelli
# che nessuno ha battezzato di proposito. Un volume con un nome se lo e'
# scelto qualcuno, e non e' compito di questa pulizia decidere che non serve
# piu'.
docker volume prune -f >/dev/null 2>&1 || true

nota "immagini senza nome (livelli orfani dei build)"
docker image prune -f >/dev/null 2>&1 || true

nota "immagini di pantedu oltre le ultime $QUANTE_IMMAGINI"
# `docker images` elenca già dalla più recente: niente `sort`, che ordinando
# per data — identica per tutte quelle dello stesso giorno — scombinerebbe
# l'ordine. È l'errore fatto sul VPS l'8 settembre, dove dopo la potatura ne
# restavano sette invece di cinque.
for repo in ghcr.io/vittop89/pantedu pantedu pantedu-app; do
    docker images "$repo" --format '{{.ID}}' 2>/dev/null \
        | tail -n "+$((QUANTE_IMMAGINI + 1))" \
        | xargs -r docker rmi >/dev/null 2>&1 || true
done

nota "cache dei build oltre $TETTO_CACHE"
# `--keep-storage` è stato rinominato `--max-used-space` in Docker 29: si prova
# il nome nuovo e si ripiega sul vecchio, così lo script non dipende dalla
# versione installata.
docker builder prune -f --max-used-space "$TETTO_CACHE" >/dev/null 2>&1 \
    || docker builder prune -f --keep-storage "$TETTO_CACHE" >/dev/null 2>&1 \
    || nota "  non sono riuscito a potare la cache: controlla lo spazio a mano."

nota "cache locale dei livelli, se piu' vecchia di 30 giorni"
# La scrive `immagine.yml` sul runner di casa (`type=local`), al posto della
# cache di rete di GitHub: là l'esportazione costava dodici minuti a giro sulla
# linea di casa. Vive fuori da Docker, quindi `docker builder prune` non la
# vede e va potata qui.
#
# Trenta giorni e non subito: è un acceleratore, e buttarla via mentre serve
# rimette il build a diciassette minuti. Se nessuno costruisce da un mese, non
# serve a nessuno.
for D in "$HOME"/.cache/pantedu-buildx "$HOME"/.cache/pantedu-buildx-nuovo; do
    [ -d "$D" ] || continue
    if [ -z "$(find "$D" -maxdepth 0 -mtime -30 2>/dev/null)" ]; then
        nota "  $D: $(du -sh "$D" 2>/dev/null | cut -f1), non toccata da 30 giorni, la tolgo"
        rm -rf "$D"
    else
        nota "  $D: $(du -sh "$D" 2>/dev/null | cut -f1), ancora in uso"
    fi
done

dopo_disco=$(df -BG --output=avail / | tail -1 | tr -dc '0-9')
nota "dopo: ${dopo_disco} GB liberi (recuperati $((dopo_disco - prima_disco)))"

# Quello che resta, per chi legge il registro fra un mese.
docker system df 2>/dev/null | sed 's/^/[pulizia]   /' || true

if [ "${dopo_disco:-999}" -lt 30 ]; then
    nota "ATTENZIONE: sotto i 30 GB liberi. Guarda cosa occupa: docker system df"
fi
