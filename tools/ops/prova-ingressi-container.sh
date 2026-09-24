#!/bin/bash
#
# La prova, sul VPS, che gli ingressi nei container si registrano: nei due versi.
#
# Si lancia da root, **da una sessione ssh** (serve una sessione di login),
# dopo aver installato `tools/ops/audit-pantedu.rules` e riavviato: le regole
# sono immutabili (`-e 2`) e si caricano solo all'avvio.
#
#   bash /var/www/pantedu/tools/ops/prova-ingressi-container.sh
#
# Tre controlli, con un segno unico per ritrovare i propri eventi:
#   1. le quattro regole sono caricate;
#   2. deve scattare: un `docker exec` e un `docker ps` da questa sessione
#      compaiono, il primo come ingresso e il secondo come altro comando;
#   3. non deve scattare: lo stesso `docker ps` lanciato da systemd (senza
#      login, come il rilascio) non compare.
#
# Non cambia niente: `docker exec … true` e `docker ps` leggono e basta, e
# l'unità di systemd è transitoria.
set -uo pipefail

export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
QUI=$(cd "$(dirname "$0")" && pwd)
CLASSIFICA="$QUI/ingressi-container.sh"
SEGNO="prova-ingressi-$(date +%s)-$$"
FALLITE=0
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

if [ "$(cat /proc/self/loginuid)" = "4294967295" ]; then
    echo "Questa shell non ha una sessione di login (loginuid non impostato): la prova non vale. Lanciala da ssh."
    exit 2
fi

echo "── 1. le regole"
N=$(auditctl -l | grep -c 'pantedu_container_ingressi')
if [ "$N" = "4" ]; then echo "  caricate: 4"; else ko "regole caricate: $N invece di 4 (installate e riavviato?)"; fi

CONTAINER=$(docker ps --format '{{.Names}}' | grep -m1 '^pantedu-app-')
if [ -z "$CONTAINER" ]; then
    ko "nessun container pantedu-app- in esecuzione"
    exit 1
fi
DA=$(date '+%m/%d/%Y %H:%M:%S')

echo "── 2. deve scattare, da questa sessione"
docker exec -e "PROVA=$SEGNO" "$CONTAINER" true
docker ps --filter "label=$SEGNO" >/dev/null

echo "── 3. non deve scattare, da systemd"
systemd-run --wait --collect --quiet /usr/bin/docker ps --filter "label=$SEGNO-systemd" >/dev/null

sleep 2
# shellcheck disable=SC2086 # DA sono due parole, data e ora, come vuole ausearch
EVENTI=$(ausearch --input-logs -i -k pantedu_container_ingressi -ts $DA </dev/null 2>/dev/null)
RESOCONTO=$(printf '%s\n' "$EVENTI" | "$CLASSIFICA")

if printf '%s\n' "$RESOCONTO" | grep -q "docker exec -e PROVA=$SEGNO $CONTAINER true"; then
    echo "  docker exec: registrato come ingresso"
else
    ko "docker exec da ssh non registrato come ingresso"
fi
if printf '%s\n' "$EVENTI" | grep -q "label=$SEGNO " || printf '%s\n' "$EVENTI" | grep -q "label=$SEGNO$"; then
    echo "  docker ps: registrato"
else
    ko "docker ps da ssh non registrato"
fi
if printf '%s\n' "$EVENTI" | grep -q "label=$SEGNO-systemd"; then
    ko "docker ps da systemd registrato: il filtro sul login non tiene fuori il rilascio"
else
    echo "  docker ps da systemd: non registrato, come deve"
fi

echo
if [ "$FALLITE" -eq 0 ]; then
    echo "PROVA RIUSCITA: gli ingressi nei container da una sessione si registrano, quelli di systemd no."
else
    echo "PROVA NON RIUSCITA: $FALLITE controlli falliti."
fi
[ "$FALLITE" -eq 0 ]
