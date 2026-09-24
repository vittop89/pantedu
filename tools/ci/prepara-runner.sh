#!/usr/bin/env bash
#
# Prepara una macchina a ospitare i runner di GitHub Actions per pantedu.
#
# Perché esiste. L'8 settembre 2026 questa configurazione è stata montata a
# mano, comando per comando, in una conversazione. Su una macchina nuova
# quella conoscenza non ci sarebbe: resterebbe da ricostruire a memoria,
# sbagliando gli stessi passi. Qui c'è tutto, e le ragioni con lui.
#
# Cosa fa (e cosa NON fa). Installa la catena di strumenti, scarica il runner
# verificandone l'impronta, e prepara N cartelle. **Non registra niente**: la
# registrazione vuole un gettone che si genera dal proprio account, e un
# gettone non si mette in uno script.
#
# Uso, in tre mosse:
#
#   1. sudo bash tools/ci/prepara-runner.sh            (installa e scarica)
#   2. i comandi che stampa, uno per runner            (li lanci tu, col gettone)
#   3. sudo bash tools/ci/prepara-runner.sh --servizi  (li avvia come servizio)
#   4. sudo bash tools/ci/prepara-runner.sh --gancio   (il gancio prima di ogni
#      lavoro e la sua regola di sudoers)
#
# Poi, una volta sola, nelle impostazioni del repository:
#   Settings → Secrets and variables → Actions → Variables → RUNNER = pantedu
# Da quel momento i workflow girano qui. Cancellando la variabile tornano su
# GitHub, senza toccare una riga di codice.
#
# Su Windows si esegue dentro WSL2 (Ubuntu): i workflow sono scritti per
# Linux — `bash`, `apt`, servizi Docker — e le immagini del confronto visivo
# sono generate su Linux. Un runner Windows non li eseguirebbe.

set -euo pipefail

VERSIONE_RUNNER="2.337.0"
QUANTI="${QUANTI:-4}"
REPO_URL="https://github.com/vittop89/pantedu-dev"
ETICHETTA="pantedu"

nota()   { printf '[prepara] %s\n' "$*"; }
errore() { printf '[prepara] ERRORE: %s\n' "$*" >&2; exit 1; }

[ "$EUID" -eq 0 ] || errore "va eseguito come root (sudo)."

UTENTE="${SUDO_USER:-$(logname 2>/dev/null || true)}"
[ -n "$UTENTE" ] || errore "non riesco a capire per quale utente installare; usa sudo da una sessione normale."
CASA=$(getent passwd "$UTENTE" | cut -d: -f6)
[ -d "$CASA" ] || errore "la cartella di $UTENTE non esiste."

# ── Modalità servizi ──────────────────────────────────────────────────────
if [ "${1:-}" = "--servizi" ]; then
    nota "installo e avvio i servizi"
    trovati=0
    for D in "$CASA"/actions-runner*; do
        [ -d "$D" ] || continue
        NOME=$(basename "$D")
        if [ ! -f "$D/.runner" ]; then
            nota "  $NOME: non registrato, salto"
            continue
        fi
        trovati=$((trovati + 1))
        if [ -f "$D/.service" ] && systemctl is-active --quiet "$(cat "$D/.service")"; then
            nota "  $NOME: già attivo"
            continue
        fi
        if [ -f "$D/.service" ]; then
            (cd "$D" && ./svc.sh start >/dev/null)
            nota "  $NOME: riavviato"
        else
            (cd "$D" && ./svc.sh install "$UTENTE" >/dev/null && ./svc.sh start >/dev/null)
            nota "  $NOME: installato e avviato"
        fi
    done
    [ "$trovati" -gt 0 ] || errore "nessun runner registrato: fai prima il passo 2."
    echo
    nota "stato:"
    for D in "$CASA"/actions-runner*; do
        [ -f "$D/.service" ] || continue
        printf '  %-24s %s\n' "$(basename "$D")" "$(systemctl is-active "$(cat "$D/.service")")"
    done
    exit 0
fi

# ── Modalità gancio ───────────────────────────────────────────────────────
# Il gancio che ogni runner lancia prima di un lavoro
# (tools/ci/gancio-inizio-lavoro.sh) e la regola di sudoers che gli serve per
# riprendersi i file lasciati dai container. Si rilancia quando cambia il
# gancio o il numero dei runner: rifà tutto da capo.
#
# La regola ha un percorso esatto per runner, senza asterischi. Quella montata a
# mano il 9/9/2026 era `chown -R utente\:utente <casa>/actions-runner*/_work`, e
# in sudoers l'asterisco negli argomenti prende anche spazi e barre. Misurato il
# 14/9/2026 eseguendo davvero, con `-n`: ammetteva senza password
# `chown -R utente:utente <casa>/actions-runner <qualsiasi> <qualsiasi>/_work`,
# cioè il possesso di qualunque file per qualunque codice giri come utente del
# runner, anche quello di una pull request. (`sudo -l` non serve a misurarlo:
# risponde di sì anche per la regola generale con password.)
if [ "${1:-}" = "--gancio" ]; then
    QUI=$(dirname "$(readlink -f "$0")")
    GANCIO="$CASA/actions-runner-hook.sh"
    install -m 0755 -o "$UTENTE" -g "$(id -gn "$UTENTE")" "$QUI/gancio-inizio-lavoro.sh" "$GANCIO"
    nota "gancio copiato in $GANCIO"

    REGOLE=$(mktemp)
    PROVA=$(mktemp -d)
    trap 'rm -rf "$REGOLE" "$PROVA"' EXIT
    echo "# Generato da tools/ci/prepara-runner.sh --gancio: un percorso esatto per runner, niente asterischi." > "$REGOLE"
    RIGA="ACTIONS_RUNNER_HOOK_JOB_STARTED=$GANCIO"
    PRIMO=""
    da_riavviare=""
    for D in "$CASA"/actions-runner*; do
        [ -f "$D/.runner" ] || continue
        # Spazi, virgole, due punti, uguali e asterischi hanno un significato in sudoers.
        case "$D" in *[!A-Za-z0-9/_.-]*) errore "percorso con caratteri che sudoers interpreta: $D" ;; esac
        PRIMO=${PRIMO:-$D}
        echo "$UTENTE ALL=(root) NOPASSWD: /usr/bin/chown -R $UTENTE\\:$UTENTE $D/_work" >> "$REGOLE"
        if ! grep -qxF "$RIGA" "$D/.env" 2>/dev/null; then
            [ -f "$D/.env" ] && sed -i '/^ACTIONS_RUNNER_HOOK_JOB_STARTED=/d' "$D/.env"
            echo "$RIGA" >> "$D/.env"
            chown "$UTENTE" "$D/.env"
            da_riavviare="$da_riavviare $(basename "$D")"
        fi
    done
    [ -n "$PRIMO" ] || errore "nessun runner registrato: fai prima il passo 2."

    visudo -cqf "$REGOLE" || errore "la regola generata non è valida: non la installo."
    DEST=/etc/sudoers.d/pantedu-runner
    PRIMA=$(mktemp)
    [ -f "$DEST" ] && cp -p "$DEST" "$PRIMA"
    install -m 0440 -o root -g root "$REGOLE" "$DEST"
    if ! visudo -cq; then
        if [ -s "$PRIMA" ]; then cp -p "$PRIMA" "$DEST"; else rm -f "$DEST"; fi
        rm -f "$PRIMA"
        errore "con la regola nuova sudoers non era valido: rimessa quella di prima."
    fi
    rm -f "$PRIMA"
    nota "regola installata in $DEST:"
    sed 's/^/    /' "$DEST"

    # Nei due versi, eseguendo come l'utente del runner. `-k` ignora una
    # password data da poco, che farebbe passare tutto; i percorsi della prova
    # sono suoi, quindi il chown non cambia niente.
    chown -R "$UTENTE" "$PROVA"
    mkdir -p "$PROVA/_work"; chown "$UTENTE" "$PROVA/_work"
    runuser -u "$UTENTE" -- sudo -k -n /usr/bin/chown -R "$UTENTE:$UTENTE" "$PRIMO/_work" \
        || errore "la regola nuova non ammette il gancio su $PRIMO/_work."
    if runuser -u "$UTENTE" -- sudo -k -n /usr/bin/chown -R "$UTENTE:$UTENTE" "$PRIMO/_work" "$PROVA/_work" 2>/dev/null; then
        errore "sudo ammette ancora argomenti in più senza password: c'è un'altra regola larga in /etc/sudoers.d."
    fi
    nota "verificato: il gancio passa, gli argomenti in più chiedono la password"

    if [ -n "$da_riavviare" ]; then
        nota "il gancio è nuovo per:$da_riavviare: il runner legge .env all'avvio,"
        nota "riavviali quando sono fermi (docs/ops/runner-self-hosted.md, «Riavviare i runner»)."
    fi
    exit 0
fi

# ── 1. La catena di strumenti ─────────────────────────────────────────────
export DEBIAN_FRONTEND=noninteractive
nota "indici dei pacchetti"
apt-get update -qq

nota "PHP 8.3 e le estensioni che la CI dichiara"
# `pdo_sqlite` e `sqlite3` non sono un dettaglio: senza, i test che usano un
# database in memoria si SALTANO in silenzio, e una suite che salta è verde.
# In locale erano settantasei test, cioè il sette per cento.
# `pdo_mysql` serve alle migrazioni del workflow dell'immagine; `xdebug` alla
# copertura.
apt-get install -y -qq \
    php8.3-cli php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
    php8.3-sqlite3 php8.3-mysql php8.3-intl php8.3-gd php8.3-bcmath \
    php8.3-xdebug unzip jq shellcheck mariadb-client curl ca-certificates

# 2026-09-23 — la versione la dice .nvmrc: era Node 20, fuori supporto dal
# 30/4/2026, mentre l'immagine costruisce con Node 22 (D-5). Questo è il Node
# del sistema, per lo sviluppo nella stessa WSL: i workflow no, prendono il
# loro con setup-node dalla cache degli strumenti del runner, anche in casa
# (docs/ops/runner-self-hosted.md). Si legge il .nvmrc accanto allo script:
# per allinearsi a una versione nuova si lancia dalla copia che ce l'ha.
NODE_MAGGIORE="$(tr -d '[:space:]v' < "$(dirname "${BASH_SOURCE[0]}")/../../.nvmrc")"
[[ "$NODE_MAGGIORE" =~ ^[0-9]+$ ]] || errore ".nvmrc non dice una versione maggiore di Node: «$NODE_MAGGIORE»."
nota "Node $NODE_MAGGIORE (da .nvmrc)"
if ! command -v node >/dev/null || [ "$(node -p 'process.versions.node.split(".")[0]')" != "$NODE_MAGGIORE" ]; then
    curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAGGIORE}.x" | bash - >/dev/null 2>&1
    apt-get install -y -qq nodejs
fi

nota "Composer"
if ! command -v composer >/dev/null; then
    curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f /tmp/composer-setup.php
fi

nota "librerie di sistema per il browser di Playwright"
# Le installerebbe `playwright install --with-deps`, che però invoca `sudo`:
# su un runner che gira senza sudo automatico fallirebbe a ogni giro. Messe
# qui una volta, nel workflow basta `playwright install`.
apt-get install -y -qq \
    libnss3 libnspr4 libatk1.0-0t64 libatk-bridge2.0-0t64 libcups2t64 \
    libdrm2 libxkbcommon0 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 \
    libgbm1 libpango-1.0-0 libcairo2 libasound2t64 libatspi2.0-0t64 \
    python3 fontconfig

# I font che vede il browser della suite. L'elenco sta in un posto solo, nel
# generatore della configurazione, perché è lo stesso insieme con cui sono state
# generate le immagini attese del confronto a pixel: installarne altri non
# cambia niente (il browser non li vede), ma se ne manca uno il giro si ferma.
# Prima qui ce n'erano due su sei: gli altri quattro c'erano per caso, perché
# stanno nell'immagine di base di Ubuntu.
# shellcheck disable=SC2046  # l'elenco va spezzato in parole, uno per pacchetto
apt-get install -y -qq $(python3 "$(dirname "$(readlink -f "$0")")/fontconfig/genera-fonts-conf.py" --pacchetti)

# ── 2. Docker ─────────────────────────────────────────────────────────────
# Serve ai `services:` dei workflow (MariaDB) e al build dell'immagine.
if sudo -u "$UTENTE" docker info >/dev/null 2>&1; then
    nota "Docker: risponde"
else
    nota "ATTENZIONE: Docker non risponde per l'utente $UTENTE."
    nota "  Su Windows: Docker Desktop → Settings → Resources → WSL Integration"
    nota "  → spunta questa distribuzione → Apply."
    nota "  Su Linux: installa docker-ce e aggiungi $UTENTE al gruppo docker."
    nota "  Senza, i workflow con un database di servizio non partiranno."
fi

# Con --solo-pacchetti ci si ferma qui: la catena di strumenti, senza i runner.
# La usa tools/dev/wsl/pacchetti.sh per l'ambiente di sviluppo, che ha bisogno
# degli stessi strumenti della CI ma non di ospitare lavori di GitHub. Così
# l'elenco dei pacchetti sta in un posto solo.
if [ "${1:-}" = "--solo-pacchetti" ]; then
    nota "pacchetti installati (--solo-pacchetti: nessun runner)"
    exit 0
fi

# ── 3. Il runner, con l'impronta verificata ───────────────────────────────
ARCHIVIO="actions-runner-linux-x64-${VERSIONE_RUNNER}.tar.gz"
BASE="$CASA/actions-runner"
SORGENTE="$BASE/$ARCHIVIO"

mkdir -p "$BASE"
if [ ! -f "$SORGENTE" ]; then
    nota "scarico il runner $VERSIONE_RUNNER"
    curl -sSfL -o "$SORGENTE" \
        "https://github.com/actions/runner/releases/download/v${VERSIONE_RUNNER}/${ARCHIVIO}"
fi

# L'impronta sta nelle note della release, fra due marcatori. Si scarica un
# binario noto, non «l'ultimo» — stessa regola del binario di gitleaks.
nota "verifico l'impronta"
ATTESA=$(curl -sSfL "https://api.github.com/repos/actions/runner/releases/tags/v${VERSIONE_RUNNER}" \
    | jq -r '.body' \
    | grep -o "BEGIN SHA linux-x64 -->[0-9a-f]\{64\}" \
    | grep -o "[0-9a-f]\{64\}" || true)
OTTENUTA=$(sha256sum "$SORGENTE" | cut -d' ' -f1)
if [ -z "$ATTESA" ]; then
    nota "  non sono riuscito a leggere l'impronta pubblicata: verificala a mano."
elif [ "$ATTESA" != "$OTTENUTA" ]; then
    rm -f "$SORGENTE"
    errore "l'impronta NON combacia: archivio scartato. Non proseguire."
else
    nota "  combacia"
fi

nota "preparo $QUANTI cartelle"
# Perché più di uno: un runner esegue **un lavoro alla volta**. Con uno solo, i
# cinque controlli di una pull request girano in fila e l'attesa passa da un
# paio di minuti a dieci.
for i in $(seq 1 "$QUANTI"); do
    D=$([ "$i" -eq 1 ] && echo "$BASE" || echo "${BASE}-${i}")
    mkdir -p "$D"
    if [ ! -f "$D/config.sh" ]; then
        tar xzf "$SORGENTE" -C "$D"
    fi
    chown -R "$UTENTE:$UTENTE" "$D"
done

# ── Cosa fare adesso ──────────────────────────────────────────────────────
echo
nota "=== fatto. Ora i due passi che restano ==="
echo
nota "1) Genera un gettone di registrazione:"
nota "     $REPO_URL/settings/actions/runners/new  (scegli Linux / x64)"
nota "   e lancia questi comandi, uno per runner, sostituendo GETTONE."
nota "   Il gettone scade in circa un'ora e serve solo a registrare."
echo
for i in $(seq 1 "$QUANTI"); do
    D=$([ "$i" -eq 1 ] && echo "$BASE" || echo "${BASE}-${i}")
    N=$([ "$i" -eq 1 ] && echo "$(hostname -s)" || echo "$(hostname -s)-${i}")
    echo "     cd $D && ./config.sh --url $REPO_URL --token GETTONE --name $N --labels $ETICHETTA --work _work --unattended --replace"
done
echo
nota "2) Avviali come servizio, così ripartono da soli:"
nota "     sudo bash $0 --servizi"
echo
nota "Infine, una volta sola, nelle impostazioni del repository:"
nota "  Settings → Secrets and variables → Actions → Variables → RUNNER = $ETICHETTA"
nota "Cancellando quella variabile i giri tornano sui computer di GitHub."
