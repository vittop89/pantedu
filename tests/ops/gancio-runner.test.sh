#!/bin/bash
#
# Prove di `tools/ci/gancio-inizio-lavoro.sh`, nei due versi (14/9/2026).
#
# Il difetto che l'ha fatto nascere: il gancio di un runner si riprendeva i file
# del lavoro in corso su un altro runner della stessa macchina, e il container di
# prova di quel lavoro perdeva la sua cartella delle sessioni. Qui si fanno due
# runner finti, con un `Runner.Worker` che è una copia di bash (così
# /proc/PID/exe punta nella loro cartella), e si guarda quale cartella il gancio
# prova a riprendersi. `chown` è finto: registra gli argomenti e non cambia
# niente.
#
# Uso: bash tests/ops/gancio-runner.test.sh
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
GANCIO="${GANCIO:-$QUI/../../tools/ci/gancio-inizio-lavoro.sh}"
T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/gancio.XXXXXX")
trap 'rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

IO=$(id -un)
BASH_VERO=$(command -v bash)
for n in 1 2; do
    mkdir -p "$T/actions-runner-$n/bin" "$T/actions-runner-$n/_work/pantedu"
    cp "$BASH_VERO" "$T/actions-runner-$n/bin/Runner.Worker"
    echo x > "$T/actions-runner-$n/_work/pantedu/file"
done
# Un eseguibile qualsiasi dentro la cartella di un runner non è il runner.
cp "$BASH_VERO" "$T/actions-runner-1/bin/altro"

cat > "$T/chown-finto" <<'FINTO'
#!/bin/bash
printf '%s\n' "$*" >> "$REGISTRO_CHOWN"
FINTO
chmod +x "$T/chown-finto"

# lancia LANCIATORE UTENTE_NOSTRO: il gancio parte da un figlio del lanciatore,
# con una shell in mezzo (il `; true` impedisce a bash di sostituirsi al
# comando). Stampa l'output del gancio; le chiamate a chown vanno nel registro.
#
# La risalita si ferma a questa prova (GANCIO_FERMA_A): in CI la prova gira
# dentro un runner vero, e senza il limite i casi «fuori da un runner»
# trovavano quello (successo al primo giro, 14/9/2026).
lancia() {
    : > "$T/chown.log"
    GANCIO_FERMA_A="$$" GANCIO_UTENTE="$2" GANCIO_CHOWN="$T/chown-finto" REGISTRO_CHOWN="$T/chown.log" \
        "$1" -c '"$0" -c "bash \"\$0\"; true" "$1"; true' "$BASH_VERO" "$GANCIO"
}

# ── Verso buono: ognuno riprende solo il suo ──────────────────────────────
# `nobody` come utente «nostro»: i file della prova, che sono miei, contano
# come non suoi, in tutti e due i runner.
OUT=$(lancia "$T/actions-runner-1/bin/Runner.Worker" nobody)
[ "$(cat "$T/chown.log")" = "-R $IO:$IO $T/actions-runner-1/_work" ] \
    && ok || ko "sotto il runner 1 ha chiamato chown così: «$(cat "$T/chown.log")»"
grep -q "^\[gancio\] runner actions-runner-1$" <<<"$OUT" && ok || ko "non dice di quale runner è: $OUT"

lancia "$T/actions-runner-2/bin/Runner.Worker" nobody >/dev/null
[ "$(cat "$T/chown.log")" = "-R $IO:$IO $T/actions-runner-2/_work" ] \
    && ok || ko "sotto il runner 2 ha chiamato chown così: «$(cat "$T/chown.log")»"

# ── Verso cattivo: niente da riprendere, o nessun runner ──────────────────
lancia "$T/actions-runner-1/bin/Runner.Worker" "$IO" >/dev/null
[ ! -s "$T/chown.log" ] && ok || ko "con tutti i file suoi ha chiamato chown: $(cat "$T/chown.log")"

OUT=$(lancia "$BASH_VERO" nobody)
[ ! -s "$T/chown.log" ] && ok || ko "fuori da un runner ha chiamato chown: $(cat "$T/chown.log")"
grep -q "non trovo il runner" <<<"$OUT" && ok || ko "fuori da un runner non lo dice: $OUT"

OUT=$(lancia "$T/actions-runner-1/bin/altro" nobody)
[ ! -s "$T/chown.log" ] && ok || ko "un eseguibile che non è il runner è passato per runner: $(cat "$T/chown.log")"

echo "gancio-inizio-lavoro.sh: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
