#!/bin/bash
#
# Prove di `tools/ci/container-app.sh`, nei due versi (14/9/2026), con un
# `docker` finto: nessun container parte davvero.
#
# Quello che conta di più è la scelta della rete. Sulla stessa macchina girano
# più job, ognuno col suo database su una sua rete: il container deve andare
# sulla rete del database di QUESTO job (quello che pubblica PORTA_DB), non
# sulla prima che capita. Il 9/9/2026 prendeva quella di un altro job.
#
# Uso: bash tests/ops/container-app.test.sh
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
SCRIPT="${SCRIPT:-$QUI/../../tools/ci/container-app.sh}"
T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/container-app.XXXXXX")
trap 'rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

# Il docker finto: due database di due job, su due reti; `run` e `rm` si
# registrano; la salute la legge da un file, un valore per chiamata.
mkdir -p "$T/bin"
cat > "$T/bin/docker" <<'FINTO'
#!/bin/bash
echo "$*" >> "$REGISTRO"
case "$1" in
    ps)
        if [ "$2" = "--format" ]; then
            printf 'aaa 0.0.0.0:5000->3306/tcp, [::]:5000->3306/tcp\n'
            printf 'bbb 0.0.0.0:6000->3306/tcp\n'
            printf 'ccc 0.0.0.0:16000->3306/tcp\n'
        else
            [ -f "$STATO_DIR/sparito" ] || echo "id-del-container"
        fi
        ;;
    inspect)
        if [ "$3" = "{{.State.Health.Status}}" ]; then
            riga=$(head -1 "$STATO_DIR/salute"); sed -i 1d "$STATO_DIR/salute"; echo "$riga"
        else
            case "$4" in
                aaa) printf 'github_network_AAA\nbridge\n' ;;
                bbb) printf 'bridge\ngithub_network_BBB\n' ;;
                ccc) printf 'github_network_CCC\n' ;;
            esac
        fi
        ;;
    port) echo "127.0.0.1:${PORTA_FINTA:-41234}" ;;
    logs) echo "registro del container" ;;
esac
exit 0
FINTO
chmod +x "$T/bin/docker"

esegui() {
    : > "$T/registro"; : > "$T/env"
    PATH="$T/bin:$PATH" REGISTRO="$T/registro" STATO_DIR="$T" GITHUB_ENV="$T/env" \
        CONTAINER=prova IMMAGINE=img:1 DATI="$T/dati" ENVFILE="$T/envfile" PAUSA=0 \
        "$@"
}

# ── La rete del nostro database ───────────────────────────────────────────
esegui env PORTA_DB=6000 bash "$SCRIPT" avvia >"$T/out" 2>&1 && ok || ko "avvia con PORTA_DB=6000 non riesce: $(cat "$T/out")"
grep -q -- "--network github_network_BBB" "$T/registro" && ok || ko "con PORTA_DB=6000 non usa la rete BBB: $(grep '^run' "$T/registro")"

esegui env PORTA_DB=5000 bash "$SCRIPT" avvia >/dev/null 2>&1
grep -q -- "--network github_network_AAA" "$T/registro" && ok || ko "con PORTA_DB=5000 non usa la rete AAA: $(grep '^run' "$T/registro")"

# Nessun database su quella porta (e :16000 non vale per :6000): si ferma, e non parte niente.
if esegui env PORTA_DB=7000 bash "$SCRIPT" avvia >"$T/out" 2>&1; then ko "con un database che non c'è è partito"; else ok; fi
grep -q '^run' "$T/registro" && ko "senza database ha lanciato docker run" || ok
grep -q "non trovo il container del database sulla porta 7000" "$T/out" && ok || ko "non dice perché: $(cat "$T/out")"

# ── La porta sull'host ────────────────────────────────────────────────────
esegui env PORTA_DB=6000 bash "$SCRIPT" avvia >/dev/null 2>&1
grep -q -- "-p 127.0.0.1::8080" "$T/registro" && ok || ko "senza PORTA non lascia scegliere a Docker"
grep -qx "URL_CONTAINER=http://127.0.0.1:41234" "$T/env" && ok || ko "URL_CONTAINER sbagliato: $(cat "$T/env")"

esegui env PORTA_DB=6000 PORTA=45678 PORTA_FINTA=45678 bash "$SCRIPT" avvia >/dev/null 2>&1
grep -q -- "-p 127.0.0.1:45678:8080" "$T/registro" && ok || ko "con PORTA non la pubblica"
grep -qx "URL_CONTAINER=http://127.0.0.1:45678" "$T/env" && ok || ko "URL_CONTAINER con PORTA sbagliato: $(cat "$T/env")"

# ── L'attesa della salute ─────────────────────────────────────────────────
rm -f "$T/sparito"
printf 'starting\nstarting\nhealthy\n' > "$T/salute"
esegui bash "$SCRIPT" aspetta >"$T/out" 2>&1 && ok || ko "healthy al terzo tentativo non basta: $(cat "$T/out")"

printf 'starting\nunhealthy\n' > "$T/salute"
if esegui bash "$SCRIPT" aspetta >"$T/out" 2>&1; then ko "unhealthy ha dato successo"; else ok; fi
grep -q "registro del container" "$T/out" && ok || ko "da malato non stampa il registro"

printf 'starting\nstarting\nstarting\n' > "$T/salute"; touch "$T/sparito"
if esegui bash "$SCRIPT" aspetta >/dev/null 2>&1; then ko "un container sparito ha dato successo"; else ok; fi
rm -f "$T/sparito"

printf 'starting\nstarting\nstarting\n' > "$T/salute"
if esegui env TENTATIVI=3 bash "$SCRIPT" aspetta >/dev/null 2>&1; then ko "senza mai diventare sano ha dato successo"; else ok; fi

# ── Rimozione ─────────────────────────────────────────────────────────────
esegui bash "$SCRIPT" togli >/dev/null 2>&1
grep -qx "rm -f prova" "$T/registro" && ok || ko "togli non rimuove il container"
grep -qx "rmi img:1" "$T/registro" && ok || ko "togli non rimuove l'immagine indicata"

echo "container-app.sh: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
