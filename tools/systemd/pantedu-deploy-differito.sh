#!/bin/bash
# Rilascia quello che la finestra oraria aveva messo in attesa.
#
# Lanciato ogni dieci minuti da pantedu-deploy-differito.timer. Non fa niente
# nel caso normale: senza marcatore in attesa esce subito.

set -uo pipefail

ATTESA=/var/lib/pantedu-deploy/in-attesa
# shellcheck source=/dev/null
. /usr/local/bin/pantedu-finestra-rilascio.sh

[ -f "$ATTESA" ] || exit 0

if finestra_chiusa; then
    exit 0    # ancora dentro la finestra: si riprova fra dieci minuti
fi

echo "[$(date -Iseconds)] [deploy-differito] la finestra si e' aperta, rilascio quello che era in attesa"
rm -f "$ATTESA"

# `--no-block` per non tenere occupato il timer per tutta la durata del
# rilascio: il risultato si legge nel log e, se fallisce, arriva l'avviso.
systemctl start --no-block pantedu-deploy.service
exit 0
