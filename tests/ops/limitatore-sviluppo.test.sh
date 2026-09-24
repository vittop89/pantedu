#!/bin/bash
#
# Prove di `tools/dev/wsl/limitatore-sviluppo.sh`, nei due versi (23/9/2026).
#
# Il difetto che l'ha fatta nascere: il `.env` versionato spegneva il
# limitatore delle richieste, e il rilascio lo monta anche in produzione
# (revisione architetturale del 23/9/2026, A-4). Ora `.env` dice 0, e in
# sviluppo il bypass lo mette `.env.local`, dove lo aggiunge questo script,
# chiamato da `server.sh` a ogni avvio.
#
# Il file che tocca è di chi sviluppa, e ci stanno le chiavi e le password di
# prova che leggono l'applicazione e la suite. Quindi si prova che scriva
# quando deve e SOLO quando deve:
#   - dove la chiave manca, la aggiunge (anche a un file senza a capo finale,
#     senza incollarla all'ultima riga), una volta sola;
#   - dove c'è, con qualunque valore, non tocca un byte;
#   - una riga commentata non conta come chiave;
#   - un collegamento resta un collegamento, e cambia il file a cui punta;
#   - `server.sh` lo chiama davvero, prima del controllo «già acceso», e con
#     la chiave già presente non tocca niente nemmeno lui.
#
# Si prova su cartelle usa e getta, MAI sul repository vero: il `.env.local`
# di una copia di lavoro è spesso un collegamento a quello di ~/pantedu.
#
# Uso: bash tests/ops/limitatore-sviluppo.test.sh
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
RADICE_REPO=$(cd "$QUI/../.." && pwd)
SCRIPT="${SCRIPT:-$RADICE_REPO/tools/dev/wsl/limitatore-sviluppo.sh}"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

# Quante righe dicono RATE_LIMIT_DISABLED=1, senza commento davanti.
attive() { grep -cE '^RATE_LIMIT_DISABLED=1$' "$1" || true; }

# 1. Senza .env.local: lo crea, con la chiave, leggibile solo dal proprietario.
mkdir -p "$T/vuota"
bash "$SCRIPT" "$T/vuota" > /dev/null
if [ -f "$T/vuota/.env.local" ] && [ "$(attive "$T/vuota/.env.local")" = 1 ]; then
    ok
else
    ko "senza .env.local la chiave non è stata scritta"
fi
permessi=$(stat -c '%a' "$T/vuota/.env.local" 2>/dev/null || echo "?")
if [ "$permessi" = 600 ]; then
    ok
else
    ko "il .env.local creato ha permessi $permessi, non 600"
fi

# 2. Chiave assente e file senza a capo finale: l'ultima riga resta com'era,
# e la chiave va su una riga sua. Qui si guarda il testo, non Dotenv: Dotenv
# tollera il commento incollato dietro un valore (misurato il 23/9/2026), il
# caricatore di playwright.config.js no, e la password del docente di prova
# arriverebbe alla suite con il commento attaccato.
mkdir -p "$T/senza-a-capo"
printf 'APP_URL=http://127.0.0.1:8765' > "$T/senza-a-capo/.env.local"
bash "$SCRIPT" "$T/senza-a-capo" > /dev/null
if grep -qx 'APP_URL=http://127.0.0.1:8765' "$T/senza-a-capo/.env.local" \
    && [ "$(attive "$T/senza-a-capo/.env.local")" = 1 ]; then
    ok
else
    ko "senza a capo finale la chiave si è incollata all'ultima riga o non c'è"
fi

# 3. Una seconda volta non la ripete.
cp "$T/senza-a-capo/.env.local" "$T/prima-del-secondo-giro"
bash "$SCRIPT" "$T/senza-a-capo" > /dev/null
if cmp -s "$T/prima-del-secondo-giro" "$T/senza-a-capo/.env.local"; then
    ok
else
    ko "al secondo giro il file è cambiato: la chiave si ripete"
fi

# 4. La chiave c'è, in una delle forme che Dotenv accetta: non tocca un byte.
# È il verso «non scatta quando non deve»: senza, uno script che aggiunge
# sempre passerebbe le prove qui sopra.
n=0
for riga in 'RATE_LIMIT_DISABLED=0' 'RATE_LIMIT_DISABLED=1' 'export RATE_LIMIT_DISABLED=0' \
    '  RATE_LIMIT_DISABLED = 0'; do
    n=$((n + 1))
    mkdir -p "$T/presente-$n"
    printf 'DB_PORT=3307\n%s\nAPP_URL=http://127.0.0.1:8765\n' "$riga" > "$T/presente-$n/.env.local"
    cp "$T/presente-$n/.env.local" "$T/presente-$n.prima"
    bash "$SCRIPT" "$T/presente-$n" > /dev/null
    if cmp -s "$T/presente-$n.prima" "$T/presente-$n/.env.local"; then
        ok
    else
        ko "con «$riga» il file è stato toccato"
    fi
done

# 5. Una riga commentata non è la chiave: l'applicazione non la vede.
mkdir -p "$T/commentata"
printf '# RATE_LIMIT_DISABLED=0\n' > "$T/commentata/.env.local"
bash "$SCRIPT" "$T/commentata" > /dev/null
if [ "$(attive "$T/commentata/.env.local")" = 1 ]; then
    ok
else
    ko "con la sola riga commentata la chiave non è stata aggiunta"
fi

# 6. Un collegamento resta tale, e cambia il file a cui punta.
mkdir -p "$T/principale" "$T/copia"
printf 'DB_PORT=3307\n' > "$T/principale/.env.local"
ln -s "$T/principale/.env.local" "$T/copia/.env.local"
bash "$SCRIPT" "$T/copia" > /dev/null
if [ -L "$T/copia/.env.local" ] && [ "$(attive "$T/principale/.env.local")" = 1 ]; then
    ok
else
    ko "con un collegamento: il collegamento è stato sostituito, o il file vero non ha la chiave"
fi

# 7. Una cartella che non esiste è un errore, non un successo silenzioso.
if bash "$SCRIPT" "$T/non-esiste" > /dev/null 2>&1; then
    ko "una cartella inesistente è stata accettata"
else
    ok
fi

# 8. Il file che esce lo legge Dotenv, come l'applicazione, e il valore è 1.
# Solo dove ci sono php e le dipendenze: nel lavoro della CI che fa girare
# questa prova vendor/ non c'è, e lì si prova il testo (punti 1-6).
if command -v php > /dev/null && [ -f "$RADICE_REPO/vendor/autoload.php" ]; then
    for caso in vuota senza-a-capo commentata; do
        valore=$(php -r 'require $argv[1]; $v = Dotenv\Dotenv::parse((string)file_get_contents($argv[2])); echo $v["RATE_LIMIT_DISABLED"] ?? "(assente)";' \
            "$RADICE_REPO/vendor/autoload.php" "$T/$caso/.env.local" 2>&1)
        if [ "$valore" = 1 ]; then
            ok
        else
            ko "Dotenv legge il .env.local del caso «$caso» come «$valore», non 1"
        fi
    done
else
    echo "  (php o vendor/ assenti: la lettura con Dotenv non è stata provata, solo il testo)"
fi

# 9. server.sh chiama davvero lo script, e prima del controllo «già acceso».
# Uno script giusto che nessuno chiama è il guasto che questo progetto
# insegue: senza la chiamata la suite end-to-end in locale riceverebbe 429, e
# le prove qui sopra resterebbero verdi.
#
# server.sh ricava la radice dalla propria posizione: lo si copia, con lo
# script accanto, in una radice usa e getta. E si finge un server già acceso,
# con un file PID che punta a questa stessa shell, viva: server.sh esce su
# «già in ascolto» senza avviare niente, e l'unico effetto che può lasciare è
# quello dello script. Se la chiamata manca, o sta dopo il controllo, il
# .env.local non nasce.
SERVER="${SERVER:-$RADICE_REPO/tools/dev/wsl/server.sh}"
PORTA_FINTA=65001
finta_radice() {
    mkdir -p "$T/$1/tools/dev/wsl" "$T/$1/run"
    cp "$SERVER" "$T/$1/tools/dev/wsl/server.sh"
    cp "$SCRIPT" "$T/$1/tools/dev/wsl/limitatore-sviluppo.sh"
    echo "$$" > "$T/$1/run/pantedu-server-$PORTA_FINTA.pid"
}
lancia_server() {
    XDG_RUNTIME_DIR="$T/$1/run" PORTA="$PORTA_FINTA" bash "$T/$1/tools/dev/wsl/server.sh" > "$T/$1.uscita" 2>&1
}

finta_radice server-senza-chiave
lancia_server server-senza-chiave
esito=$?
if [ "$esito" -eq 0 ] && grep -q 'già in ascolto' "$T/server-senza-chiave.uscita" \
    && [ -f "$T/server-senza-chiave/.env.local" ] \
    && [ "$(attive "$T/server-senza-chiave/.env.local")" = 1 ]; then
    ok
else
    ko "server.sh (esito $esito) non ha aggiunto la chiave prima del controllo «già acceso»: $(tr '\n' ' ' < "$T/server-senza-chiave.uscita")"
fi

# L'altro verso: con la chiave già scelta da chi sviluppa, server.sh non
# tocca un byte. Senza, un server.sh che scrivesse la riga da sé, sempre,
# passerebbe il caso qui sopra.
finta_radice server-con-chiave
printf 'DB_PORT=3307\nRATE_LIMIT_DISABLED=0\n' > "$T/server-con-chiave/.env.local"
cp "$T/server-con-chiave/.env.local" "$T/server-con-chiave.prima"
lancia_server server-con-chiave
esito=$?
if [ "$esito" -eq 0 ] && cmp -s "$T/server-con-chiave.prima" "$T/server-con-chiave/.env.local"; then
    ok
else
    ko "server.sh (esito $esito) ha toccato un .env.local che aveva già la chiave"
fi

echo "limitatore-sviluppo: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
