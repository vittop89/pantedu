#!/bin/bash
#
# Prove di `tools/ci/immagine-e2e.sh`, nei due versi (14/9/2026), con un
# `docker` finto che risponde a `pull` come gli si dice.
#
# Il punto: dopo un'unione la suite deve provare l'immagine del RILASCIO. Se
# non è pubblicata aspetta, e se non arriva si ferma; non ne costruisce una al
# suo posto. Solo a mano da un ramo si costruisce nel job.
#
# Uso: bash tests/ops/immagine-e2e.test.sh
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
SCRIPT="${SCRIPT:-$QUI/../../tools/ci/immagine-e2e.sh}"
T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/immagine-e2e.XXXXXX")
trap 'rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

# `pull` legge da RISPOSTE una riga per chiamata: «c-e», «manca» o «negato».
mkdir -p "$T/bin"
cat > "$T/bin/docker" <<'FINTO'
#!/bin/bash
# Ogni chiamata registra anche la cartella delle credenziali che vede.
if [ "$1" = "login" ]; then
    echo "login ${DOCKER_CONFIG:-predefinita} $(cat)" >> "$REGISTRO"
    [ -n "${DOCKER_CONFIG:-}" ] && touch "$DOCKER_CONFIG/config.json"
    exit 0
fi
[ "$1" = "pull" ] || exit 0
echo "$* ${DOCKER_CONFIG:-predefinita}" >> "$REGISTRO"
riga=$(head -1 "$RISPOSTE" 2>/dev/null); sed -i 1d "$RISPOSTE" 2>/dev/null
case "${riga:-manca}" in
    c-e) exit 0 ;;
    negato) echo "Error response from daemon: denied: permission_denied" >&2; exit 1 ;;
    *) echo "Error response from daemon: manifest unknown" >&2; exit 1 ;;
esac
FINTO
chmod +x "$T/bin/docker"

# esegui EVENTO RAMO RISPOSTE...: stampa l'uscita dello script in $T/out, l'esito in $T/esito.
#
# 20/9/2026 — l'attesa predefinita è ZERO e la pausa pure: così il numero di
# tentativi dipende solo dalle risposte del docker finto, non da quanto è
# carica la macchina. Prima erano due secondi con pause di uno: su un runner
# occupato il terzo tentativo non ci stava dentro e quattro verifiche
# diventavano rosse senza che niente fosse cambiato (misurato due volte il
# 20/9 sulla stessa PR, verde in locale). I due casi in cui l'immagine arriva
# dopo qualche tentativo passano `ATTESA_PROVA` e dicono quanto aspettare.
esegui() {
    local evento=$1 ramo=$2; shift 2
    printf '%s\n' "$@" > "$T/risposte"; : > "$T/registro"; : > "$T/env"
    PATH="$T/bin:$PATH" REGISTRO="$T/registro" RISPOSTE="$T/risposte" GITHUB_ENV="$T/env" \
        RUNNER_TEMP="$T/lavoro" \
        EVENTO="$evento" RAMO="$ramo" SHA=abc123 REGISTRO_IMMAGINE=ghcr.io/prova/pantedu \
        GITHUB_RUN_ID=77 GITHUB_RUN_ATTEMPT=1 \
        ATTESA_SECONDI="${ATTESA_PROVA:-0}" PAUSA=0 \
        bash "$SCRIPT" >"$T/out" 2>&1
    echo $? > "$T/esito"
}
mkdir -p "$T/lavoro"
esito() { cat "$T/esito"; }
tentativi() { grep -c '^pull' "$T/registro"; }
env_ha() { grep -qx "$1" "$T/env"; }

# ── Dopo un'unione: si aspetta l'immagine del rilascio ────────────────────
ATTESA_PROVA=60 esegui push main manca manca c-e
[ "$(esito)" = 0 ] && ok || ko "push, immagine arrivata al terzo tentativo: esito $(esito) — $(cat "$T/out")"
env_ha "IMMAGINE_E2E=ghcr.io/prova/pantedu:abc123" && ok || ko "push: IMMAGINE_E2E sbagliata: $(cat "$T/env")"
env_ha "DA_COSTRUIRE=0" && ok || ko "push: DA_COSTRUIRE non è 0"
[ "$(tentativi)" = 3 ] && ok || ko "push: $(tentativi) tentativi invece di 3"

esegui push main manca manca manca manca manca manca
[ "$(esito)" != 0 ] && ok || ko "push senza immagine è finito bene"
env_ha "DA_COSTRUIRE=1" && ko "push senza immagine ha scelto di costruirla" || ok
grep -q "non è arrivata nel registro" "$T/out" && ok || ko "push senza immagine non dice perché: $(cat "$T/out")"

esegui schedule main manca manca manca manca manca manca
[ "$(esito)" != 0 ] && ok || ko "la domenica senza immagine è finito bene"

# Credenziali rifiutate: aspettare non serve.
esegui push main negato c-e
[ "$(esito)" != 0 ] && ok || ko "con l'accesso negato è finito bene"
[ "$(tentativi)" = 1 ] && ok || ko "con l'accesso negato ha riprovato ($(tentativi) tentativi)"

# A mano da main: come dopo un'unione.
esegui workflow_dispatch main manca manca manca manca manca
[ "$(esito)" != 0 ] && ok || ko "a mano da main senza immagine è finito bene"
env_ha "DA_COSTRUIRE=1" && ko "a mano da main ha scelto di costruirla" || ok

# ── A mano da un ramo: se non c'è si costruisce, senza aspettare ──────────
esegui workflow_dispatch ci/prova manca c-e
[ "$(esito)" = 0 ] && ok || ko "a mano da un ramo è fallito: $(cat "$T/out")"
env_ha "DA_COSTRUIRE=1" && ok || ko "a mano da un ramo non la costruisce: $(cat "$T/env")"
env_ha "IMMAGINE_E2E=pantedu:e2e-77-1" && ok || ko "a mano da un ramo: etichetta sbagliata: $(cat "$T/env")"
env_ha "ORIGINE_IMMAGINE=costruita" && ok || ko "a mano da un ramo: origine sbagliata"
[ "$(tentativi)" = 1 ] && ok || ko "a mano da un ramo ha aspettato ($(tentativi) tentativi)"

esegui workflow_dispatch ci/prova c-e
env_ha "DA_COSTRUIRE=0" && env_ha "ORIGINE_IMMAGINE=registro" && ok || ko "a mano da un ramo con l'immagine pubblicata non la usa: $(cat "$T/env")"

# ── Le credenziali: in una cartella del job, non in quella di tutti ────────
export TOKEN_REGISTRO=gettone-di-prova UTENTE_REGISTRO=chi-lancia
ATTESA_PROVA=60 esegui push main manca c-e
[ "$(esito)" = 0 ] && ok || ko "con le credenziali è fallito: $(cat "$T/out")"
LOGIN=$(grep '^login ' "$T/registro")
CARTELLA=$(echo "$LOGIN" | cut -d' ' -f2)
case "$CARTELLA" in "$T/lavoro/docker-registro."*) ok ;; *) ko "il login non usa una cartella del job: «$LOGIN»" ;; esac
[ "$(echo "$LOGIN" | cut -d' ' -f3)" = "gettone-di-prova" ] && ok || ko "il gettone non arriva da stdin: «$LOGIN»"
[ "$(head -1 "$T/registro" | cut -d' ' -f1)" = "login" ] && ok || ko "il login non viene prima dello scaricamento"
[ "$(grep -c "^pull .* $CARTELLA\$" "$T/registro")" = "$(tentativi)" ] && ok || ko "gli scaricamenti non usano la cartella del login: $(cat "$T/registro")"
[ ! -e "$CARTELLA" ] && ok || ko "la cartella delle credenziali resta dopo l'uscita"
unset TOKEN_REGISTRO UTENTE_REGISTRO

esegui push main c-e
grep -q '^login ' "$T/registro" && ko "senza credenziali ha fatto login" || ok

echo "immagine-e2e.sh: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
