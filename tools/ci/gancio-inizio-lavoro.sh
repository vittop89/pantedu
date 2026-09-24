#!/bin/bash
#
# Gancio d'inizio lavoro dei runner di casa (ACTIONS_RUNNER_HOOK_JOB_STARTED).
# Non gira da qui: si installa copiandolo, vedi docs/ops/runner-self-hosted.md,
# «Il gancio prima di ogni lavoro».
#
# Che cosa fa. I container di prova scrivono nella cartella del lavoro come root
# o come www-data, e al lavoro dopo `actions/checkout` non riesce a cancellare
# quei file: muore con un errore che non spiega niente. Qui, prima di ogni
# lavoro, se nella cartella di lavoro ci sono file non del runner, il runner se
# li riprende.
#
# **Solo quelli del suo runner** (14/9/2026). La versione di prima girava su
# tutte le `actions-runner*/_work` della macchina: un lavoro che cominciava sul
# runner 2 si riprendeva i file del container di prova ancora acceso sul
# runner 3. La cartella delle sessioni di quel container (www-data, 0700)
# passava all'utente del runner, PHP-FPM non ci scriveva più, e il job
# dell'immagine della #95 è morto «unhealthy» dopo che l'avvio aveva verificato
# tutto. È lo stesso difetto delle porte e della /tmp: un job che tocca le cose
# di un altro.
#
# Quale runner. Lo dice il processo che lancia il gancio: fra i suoi antenati
# c'è `…/actions-runner*/bin/Runner.Worker` (o `Runner.Listener`). Se non lo
# trova, il gancio non tocca niente e lo dice.
#
# Per le prove (tests/ops/gancio-runner.test.sh): GANCIO_UTENTE è l'utente i cui
# file contano come «nostri», GANCIO_CHOWN il comando al posto di sudo chown,
# GANCIO_FERMA_A il processo sopra cui non si risale (la prova, che in CI gira
# a sua volta dentro un runner vero). Un job non li può impostare per il
# gancio: il gancio gira prima dei passi del job, con l'ambiente del runner.
set -euo pipefail

UTENTE=$(id -un)
MIO="${GANCIO_UTENTE:-$UTENTE}"
FERMA_A="${GANCIO_FERMA_A:-1}"

# Il runner a cui appartiene il processo $1: risale gli antenati fino a un
# eseguibile del runner, e ne stampa la cartella.
runner_di() {
    local pid=$1 exe cartella stato ppid
    while [ "$pid" -gt 1 ] && [ "$pid" != "$FERMA_A" ]; do
        exe=$(readlink "/proc/$pid/exe" 2>/dev/null) || exe=""
        case "$exe" in
            */bin/Runner.Worker | */bin/Runner.Listener)
                cartella=${exe%/bin/*}
                case "${cartella##*/}" in
                    actions-runner | actions-runner-*)
                        printf '%s\n' "$cartella"
                        return 0
                        ;;
                esac
                ;;
        esac
        # Il quarto campo di /proc/PID/stat è il padre; il nome del processo,
        # fra parentesi, può contenere spazi, quindi si taglia dopo «) ».
        stato=$(cat "/proc/$pid/stat" 2>/dev/null) || return 1
        read -r _ ppid _ <<<"${stato##*) }"
        pid=$ppid
    done
    return 1
}

if ! RUNNER=$(runner_di "$PPID"); then
    echo "[gancio] non trovo il runner che mi ha lanciato: non tocco niente"
    exit 0
fi
NOME=${RUNNER##*/}
echo "[gancio] runner $NOME"

LAVORO="$RUNNER/_work"
[ -d "$LAVORO" ] || exit 0

if find "$LAVORO" ! -user "$MIO" -print -quit 2>/dev/null | grep -q .; then
    echo "[gancio] riprendo la proprieta' di $NOME/_work"
    if [ -n "${GANCIO_CHOWN:-}" ]; then
        "$GANCIO_CHOWN" -R "$UTENTE:$UTENTE" "$LAVORO" || echo "[gancio] non ci sono riuscito"
    else
        sudo -n /usr/bin/chown -R "$UTENTE:$UTENTE" "$LAVORO" || echo "[gancio] non ci sono riuscito"
    fi
fi
