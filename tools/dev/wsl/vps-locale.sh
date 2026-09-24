#!/usr/bin/env bash
#
# Il VPS in locale: l'applicazione con intorno i servizi che ha in produzione.
#
# A cosa serve. `server.sh` fa girare il codice con il server integrato di PHP:
# va benissimo per lavorare, e non somiglia alla produzione. Lì l'applicazione
# è un'immagine con nginx e php-fpm, dietro un altro nginx che fa TLS e limiti,
# parla con MariaDB 11.8 attraverso un socket con tre utenti dai permessi
# diversi, compila i PDF chiedendo a un servizio TeX, e ha dei lavori a orario
# che girano accanto. Un guasto che nasce da una di queste cose — un permesso
# del database, un'intestazione del proxy, un limite di memoria del container
# — con `server.sh` non si vede. Qui sì.
#
# Che cosa gira, e da dove viene (misurato sul VPS il 14 settembre 2026):
#
#   pantedu-vps-db       MariaDB 11.8.6, stesso sql_mode; socket condiviso con
#                        l'applicazione come `/run/mysqld/mysqld.sock` sul server;
#                        gli utenti pantedu_app, pantedu_migrator e pantedu_maint
#                        con i permessi di produzione, e i trigger append-only
#   pantedu-vps-tex      il servizio TeX (tools/tex-compile-vps/Dockerfile): stessa
#                        Debian, stessi pacchetti, stesso comando di avvio.
#                        ATTENZIONE, la topologia è un'altra (19/9/2026): qui il
#                        TeX è un container con nome sulla rete dell'app, e
#                        l'app lo chiama per nome; in produzione è un'unità
#                        systemd sull'host, raggiunta sul gateway del bridge
#                        Docker con una regola del firewall. Un verde qui NON
#                        prova l'indirizzo di produzione: dall'8/9 il container
#                        vero non raggiungeva il TeX, e qui tutto passava. Lo
#                        guardano /health/tex e la diagnostica `tex` sul VPS
#                        (docs/ops/tex-dal-container.md).
#   pantedu-vps-app-a/b  l'immagine costruita da questa copia (docker/Dockerfile),
#                        avviata con le opzioni di `deploy-container.sh`: memoria,
#                        processi, capacità tolte, porte 8090/8091
#   pantedu-vps-nginx    nginx 1.26.3 con i file di `infra/nginx`: TLS (con un
#                        certificato locale), limiti di frequenza, intestazioni
#   pantedu-vps-lavori   le compilazioni in coda ogni cinque minuti e la pulizia
#                        dei temporanei ogni ora, come i timer del server
#
# Che cosa NON c'è: Cloudflare davanti (e quindi il paese del visitatore), la
# posta (Resend), Grafana, il webhook e il rilascio automatico, i salvataggi,
# AIDE e auditd. Il resoconto finale lo ripete.
#
# Uso:
#   bash tools/dev/wsl/vps-locale.sh avvia         costruisce e avvia tutto (la
#                                                  prima volta anche il servizio TeX:
#                                                  parecchi minuti)
#   bash tools/dev/wsl/vps-locale.sh ricostruisci  dopo una modifica al codice: immagine
#                                                  nuova, migrazioni, scambio blu/verde
#   bash tools/dev/wsl/vps-locale.sh stato         cosa gira, da quale commit, gli indirizzi
#   bash tools/dev/wsl/vps-locale.sh ramo          solo il controllo del ramo, senza costruire
#   bash tools/dev/wsl/vps-locale.sh prova         i controlli, di nuovo
#   bash tools/dev/wsl/vps-locale.sh lavori        fa girare subito i lavori a orario
#   bash tools/dev/wsl/vps-locale.sh registro [app|nginx|tex|db|lavori]
#   bash tools/dev/wsl/vps-locale.sh ferma         spegne, tiene database e dati
#   bash tools/dev/wsl/vps-locale.sh pulisci [--tutto] [--si]
#
# Opzioni di `avvia` e `ricostruisci`:
#   --sorgente DIR   la copia da provare (predefinita: quella che contiene questo file)
#   --senza-tex      niente servizio TeX: si risparmia la costruzione più lunga
#
# Ogni comando si stampa prima di eseguirlo, con esito e durata; tutto finisce
# anche in un registro, in ~/.local/state/pantedu-vps-locale/registri/.
set -uo pipefail

QUI=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SORGENTE=$(cd "$QUI/../../.." && pwd)
CON_TEX=1
CONFERMATO=0
TUTTO=0

PREFISSO=pantedu-vps
RETE=$PREFISSO
DB=$PREFISSO-db
TEX=$PREFISSO-tex
NGINX=$PREFISSO-nginx
LAVORI=$PREFISSO-lavori
IMG_APP=$PREFISSO-app
IMG_TEX=$PREFISSO-tex
VOL_DB=$PREFISSO-db-dati
VOL_SOCK=$PREFISSO-mysqld

# Le versioni di produzione. nginx è la build di nginx.org e non quella di
# Debian: stesso numero, stessi moduli che il vhost usa.
IMMAGINE_DB=mariadb:11.8.6
IMMAGINE_NGINX=nginx:1.26.3
SQL_MODE=STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION

NOME_DB=pantedu
PORTA_HTTPS=8443
URL="https://127.0.0.1:$PORTA_HTTPS"

STATO=${XDG_STATE_HOME:-$HOME/.local/state}/pantedu-vps-locale
DATI=$STATO/dati

# ── Come si scrive ────────────────────────────────────────────────────────

passo()  { printf '\n== %s\n' "$*"; }
nota()   { printf '   %s\n' "$*"; }
avviso() { printf '   ATTENZIONE: %s\n' "$*"; }
errore() { printf '\n   ERRORE: %s\n' "$*"; exit 1; }

# Il comando come lo si leggerebbe in un terminale.
mostra() {
    local a riga=""
    for a in "$@"; do
        # I `case` stanno su più righe: su una riga sola l'analizzatore bash di
        # semgrep non legge il file, e il cancello della CI conta i file non letti.
        case "$a" in
            *[[:space:]]* | "") riga+=" '$a'" ;;
            *) riga+=" $a" ;;
        esac
    done
    printf '   $%s\n' "$riga"
}

# Per quello che non è un comando da copiare (un SQL passato su stdin, con le
# password dentro): si dice cosa si fa, senza fingere una riga di terminale.
mostra_testo() { printf '   $ %s\n' "$*"; }

# Mostra, esegue, dice esito e durata. L'esito è quello del comando, non
# della pipe che ne indenta l'uscita; `awk` e non `sed`, perché un'uscita senza
# a capo finale (curl) altrimenti si attacca alla riga dell'esito.
esegui() {
    mostra "$@"
    local t0=$SECONDS rc
    "$@" 2>&1 | awk '{ print "     " $0 }'
    rc=${PIPESTATUS[0]}
    printf '   → esito %d, %ds\n' "$rc" $((SECONDS - t0))
    return "$rc"
}

# Come `esegui`, per i comandi con un'uscita lunga: a schermo solo le righe
# che combaciano con lo schema, tutto il resto nel file. Se il comando fallisce,
# anche le ultime trenta righe.
esegui_lungo() {   # file schema comando...
    local file=$1 schema=$2; shift 2
    mostra "$@"
    nota "(uscita completa in $file)"
    local t0=$SECONDS rc
    "$@" > "$file" 2>&1
    rc=$?
    grep -E "$schema" "$file" | tail -40 | awk '{ print "     " $0 }'
    if [ "$rc" -ne 0 ]; then tail -30 "$file" | awk '{ print "     " $0 }'; fi
    printf '   → esito %d, %ds\n' "$rc" $((SECONDS - t0))
    return "$rc"
}
SCHEMA_BUILD='^#[0-9]+ \[[^]]+\] |^#[0-9]+ (DONE [1-9][0-9]*\.|ERROR)'

apri_registro() {
    mkdir -p "$STATO/registri"
    REGISTRO="$STATO/registri/$(date +%Y%m%d-%H%M%S)-$1.log"
    exec > >(tee -a "$REGISTRO") 2>&1
    printf 'vps-locale %s — %s (registro: %s)\n' "$1" "$(date '+%F %T')" "$REGISTRO"
}

esiste()   { docker ps -a --format '{{.Names}}' | grep -qx "$1"; }
in_corso() { docker ps --format '{{.Names}}' | grep -qx "$1"; }

aspetta_sano() {   # nome [controlli]
    local nome=$1 max=${2:-40} i s=assente
    for ((i = 1; i <= max; i++)); do
        s=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$nome" 2>/dev/null || echo assente)
        case "$s" in
            healthy) nota "$nome: sano (controllo $i)"; return 0 ;;
            unhealthy | exited | dead | assente) break ;;
        esac
        sleep 3
    done
    avviso "$nome non è sano: $s"
    docker logs --tail 40 "$nome" 2>&1 | sed 's/^/     /'
    return 1
}

# ── Prima di tutto ────────────────────────────────────────────────────────

controlla_docker() {
    command -v docker >/dev/null 2>&1 \
        || errore "docker non è nel percorso: in Docker Desktop, Settings → Resources → WSL Integration → Ubuntu."
    docker info >/dev/null 2>&1 \
        || errore "Docker non risponde: apri Docker Desktop e aspetta «Engine running»."
}

# Le porte: se le usa qualcun altro ci si ferma, invece di credere di essere
# già accesi. «Risponde qualcuno» non vuol dire «sono io» (docs/dev/sviluppo-in-wsl.md).
controlla_porte() {
    local porta chi
    for porta in "$PORTA_HTTPS" 8090 8091; do
        chi=$(docker ps --format '{{.Names}} {{.Ports}}' | grep -F "127.0.0.1:$porta->" | awk '{print $1}' || true)
        if [ -n "$chi" ]; then
            case "$chi" in
                "$PREFISSO"-*) continue ;;
            esac
            errore "la porta $porta è del container $chi, che non è di questa prova."
        fi
        if ss -ltn 2>/dev/null | grep -qE "[:.]$porta\b"; then
            errore "la porta $porta è occupata da un programma che non è un container di questa prova (ss -ltnp)."
        fi
    done
}

# ── Il ramo, e che cosa vuol dire provarlo ────────────────────────────────

verifica_ramo() {
    passo "Il ramo da provare"
    git -C "$SORGENTE" rev-parse --git-dir >/dev/null 2>&1 || errore "$SORGENTE non è un repository git"
    RAMO=$(git -C "$SORGENTE" rev-parse --abbrev-ref HEAD)
    COMMIT=$(git -C "$SORGENTE" rev-parse HEAD)
    nota "copia:    $SORGENTE"
    nota "ramo:     $RAMO"
    nota "commit:   ${COMMIT:0:8} — $(git -C "$SORGENTE" log -1 --format='%s (%cr)' | cut -c1-90)"

    if ! esegui git -C "$SORGENTE" fetch --quiet origin main; then
        avviso "GitHub non risponde: il confronto con main usa l'ultima copia locale di origin/main."
    fi

    local modificati non_tracciati avanti indietro migrazioni
    modificati=$(git -C "$SORGENTE" status --porcelain --untracked-files=no | grep -c . || true)
    non_tracciati=$(git -C "$SORGENTE" status --porcelain --untracked-files=normal | grep -c '^??' || true)
    avanti=$(git -C "$SORGENTE" rev-list --count origin/main..HEAD 2>/dev/null || echo "?")
    indietro=$(git -C "$SORGENTE" rev-list --count HEAD..origin/main 2>/dev/null || echo "?")
    SPORCO=$modificati
    nota "rispetto a origin/main: $avanti commit avanti, $indietro indietro"
    nota "modifiche non committate: $modificati file; non tracciati: $non_tracciati"
    if [ "$modificati" -gt 0 ]; then
        git -C "$SORGENTE" status --porcelain --untracked-files=no | head -8 | sed 's/^/     /'
    fi

    passo "Considerazioni"
    local detto=0
    if [ "$RAMO" = main ] && [ "$modificati" -eq 0 ] && [ "$indietro" = 0 ]; then
        nota "- È main, pulito e allineato: è quello che va in produzione al prossimo rilascio."
        detto=1
    fi
    if [ "$modificati" -gt 0 ]; then
        nota "- L'immagine conterrà anche i file modificati e non committati ($modificati): /version"
        nota "  dirà ${COMMIT:0:8}, ma il codice non è esattamente quel commit."
        detto=1
    fi
    if [ "$non_tracciati" -gt 0 ]; then
        nota "- File non tracciati: $non_tracciati. Entrano nell'immagine se .dockerignore non li esclude."
        detto=1
    fi
    if [ "$indietro" != "?" ] && [ "$indietro" -gt 0 ]; then
        nota "- Commit di main che qui non ci sono: $indietro. Quello che è stato unito dopo non lo vedrai."
        detto=1
    fi
    if [ "$RAMO" != main ] && [ "$avanti" != "?" ] && [ "$avanti" -gt 0 ]; then
        nota "- Commit che provi e che in produzione non ci sono ancora: $avanti."
        detto=1
    fi
    migrazioni=$(git -C "$SORGENTE" diff --name-only --diff-filter=A origin/main...HEAD -- database/migrations 2>/dev/null || true)
    if [ -n "$migrazioni" ]; then
        nota "- Il ramo porta migrazioni che main non ha: il database di prova le applica."
        printf '%s\n' "$migrazioni" | sed 's/^/     /'
        nota "  In produzione arrivano con l'unione: guarda la regola sulle migrazioni distruttive"
        nota "  in wiki/dev-workflow.md."
        detto=1
    fi
    if git -C "$SORGENTE" diff --quiet origin/main...HEAD -- docker .dockerignore 2>/dev/null; then :; else
        nota "- Il ramo cambia docker/ o .dockerignore: l'immagine è fatta diversamente da quella in servizio."
        detto=1
    fi
    if git -C "$SORGENTE" diff --quiet origin/main...HEAD -- infra/nginx 2>/dev/null; then :; else
        nota "- Il ramo cambia infra/nginx: il nginx davanti usa i file di questo ramo."
        nota "  In produzione il vhost non si aggiorna da solo con il rilascio."
        detto=1
    fi
    if git -C "$SORGENTE" diff --quiet origin/main...HEAD -- tools/tex-compile-vps 2>/dev/null; then :; else
        # Dal 19/9/2026 il codice (app/) lo porta il rilascio (passo 7-bis di
        # deploy-container.sh); l'unità systemd e i pacchetti no.
        nota "- Il ramo cambia il servizio TeX: qui si ricostruisce. In produzione il rilascio porta"
        nota "  il codice di tools/tex-compile-vps/app; unità systemd e pacchetti vanno reinstallati a mano."
        detto=1
    fi
    local in_servizio
    in_servizio=$(commit_in_servizio)
    if [ -n "$in_servizio" ] && [ "$in_servizio" != "$COMMIT" ]; then
        nota "- Adesso gira ${in_servizio:0:8}: verrà sostituito da ${COMMIT:0:8}."
        detto=1
    fi
    [ "$detto" -eq 1 ] || nota "- Niente di particolare."
}

# L'impronta di quello che finisce nell'immagine: il commit, le modifiche non
# committate e i file non tracciati. Il commit da solo non basta: dopo una
# ricostruzione con modifiche in sospeso, `stato` diceva di ricostruire ancora.
impronta_sorgente() {
    {
        git -C "$SORGENTE" rev-parse HEAD
        git -C "$SORGENTE" diff HEAD
        git -C "$SORGENTE" ls-files --others --exclude-standard -z | (cd "$SORGENTE" && xargs -0 -r sha1sum)
    } 2>/dev/null | sha1sum | cut -c1-16
}

commit_in_servizio() {
    local attivo
    attivo=$(colore_attivo)
    [ -n "$attivo" ] || return 0
    docker inspect -f '{{index .Config.Labels "pantedu.vps.commit"}}' "$PREFISSO-app-$attivo" 2>/dev/null || true
}

colore_attivo() {
    local c
    c=$(cat "$STATO/attivo" 2>/dev/null || true)
    if [ -n "$c" ] && esiste "$PREFISSO-app-$c"; then printf '%s' "$c"; fi
}

# ── Segreti, configurazione, certificato ──────────────────────────────────
#
# Tutto sta fuori dal repository, in ~/.local/state/pantedu-vps-locale. I
# segreti si generano la prima volta e valgono solo per questa prova: nessuno
# di loro è, o deve diventare, un valore di produzione.

prepara_stato() {
    passo "Segreti, configurazione e certificato (in $STATO)"
    mkdir -p "$STATO/tls" "$STATO/nginx" "$DATI/storage/logs" "$DATI/storage/templates" "$DATI/storage/risdoc-tmp"
    chmod 700 "$STATO"

    if [ ! -s "$STATO/segreti.env" ]; then
        (
            umask 077
            {
                for k in DB_ROOT_PASS DB_APP_PASS DB_MAINT_PASS DB_MIGRATOR_PASS \
                         PROVA_DOCENTE_PASS PROVA_DOCENTE2_PASS PROVA_ADMIN_PASS; do
                    printf '%s=%s\n' "$k" "$(openssl rand -hex 12)"
                done
                for k in KMS_MASTER_KEY STORAGE_SIGNING_SECRET WAF_HMAC_SECRET TEX_COMPILE_SECRET; do
                    printf '%s=%s\n' "$k" "$(openssl rand -hex 32)"
                done
            } > "$STATO/segreti.env"
        )
        nota "segreti generati (valgono solo qui): $STATO/segreti.env"
    else
        nota "segreti: già presenti"
    fi
    set -a
    # shellcheck disable=SC1091
    . "$STATO/segreti.env"
    set +a

    (
        umask 077
        printf 'MARIADB_ROOT_PASSWORD=%s\n' "$DB_ROOT_PASS" > "$STATO/db.env"
        printf '[client]\nuser=root\npassword=%s\n' "$DB_ROOT_PASS" > "$STATO/db-root.cnf"
        {
            grep -vE '^[[:space:]]*(#|$)|^TEX_COMPILE_SECRET=' "$SORGENTE/tools/tex-compile-vps/.env.example"
            printf 'TEX_COMPILE_SECRET=%s\n' "$TEX_COMPILE_SECRET"
        } > "$STATO/tex.env"
        {
            printf 'PANTEDU_SEED_CI=1\n'
            printf 'E2E_TEACHER_PASS=%s\nE2E_TEACHER2_PASS=%s\nFM_E2E_ADMIN_PASSWORD=%s\n' \
                "$PROVA_DOCENTE_PASS" "$PROVA_DOCENTE2_PASS" "$PROVA_ADMIN_PASS"
        } > "$STATO/semina.env"
    )

    # .env.local: le chiavi sono quelle del .env.local di produzione (i nomi,
    # misurati; i valori no). Si scrive sul posto, senza rinominare: il
    # container lo monta come file, e un file sostituito non lo vedrebbe.
    #
    # 2026-09-23 — anche RATE_LIMIT_DISABLED=0, come in produzione (misurato
    # il 23/9/2026 in produzione, senza stampare valori: .env.local ridefinisce
    # la chiave e il limitatore è acceso). Qui era spento, perché il .env
    # versionato che si copia sotto diceva 1 e questo file non ridefiniva la
    # chiave: il VPS in locale girava con un limitatore diverso da quello di
    # produzione. Ora il .env versionato dice 0, e la riga lo tiene acceso
    # anche se cambiasse.
    local tex_endpoint=""
    [ "$CON_TEX" -eq 1 ] && tex_endpoint="http://$TEX:8001"
    cat > "$STATO/env.local" <<RIGHE
# Generato da tools/dev/wsl/vps-locale.sh. Vale solo per il VPS in locale.
APP_URL=$URL
DB_HOST=localhost
DB_NAME=$NOME_DB
DB_USER=pantedu_app
DB_PASS=$DB_APP_PASS
DB_MAINT_USER=pantedu_maint
DB_MAINT_PASS=$DB_MAINT_PASS
DB_MIGRATOR_USER=pantedu_migrator
DB_MIGRATOR_PASS=$DB_MIGRATOR_PASS
PANTEDU_DATA_PATH=/var/lib/pantedu-data
KMS_MASTER_KEY=$KMS_MASTER_KEY
STORAGE_SIGNING_SECRET=$STORAGE_SIGNING_SECRET
WAF_HMAC_SECRET=$WAF_HMAC_SECRET
TEX_COMPILE_ENDPOINT=$tex_endpoint
TEX_COMPILE_SECRET=$TEX_COMPILE_SECRET
RATE_LIMIT_DISABLED=0
RIGHE
    # La semina rifiuta APP_ENV=production: crea utenti con password note.
    { cat "$STATO/env.local"; printf 'APP_ENV=ci\n'; } > "$STATO/env.local.semina"
    cp "$SORGENTE/.env" "$STATO/env"

    if [ ! -s "$STATO/tls/fullchain.pem" ]; then
        esegui openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 365 \
            -subj "/CN=pantedu-vps-locale" \
            -addext "subjectAltName=IP:127.0.0.1,DNS:localhost" \
            -keyout "$STATO/tls/privkey.pem" -out "$STATO/tls/fullchain.pem" \
            || errore "certificato locale non creato"
    fi

    # I file di nginx si copiano dal repository a ogni giro: così il container
    # vede sempre la versione di questa copia, anche dopo un git checkout (un
    # file montato da solo resterebbe quello di prima).
    mkdir -p "$STATO/nginx/conf.d"
    cp "$SORGENTE/infra/nginx/pantedu.eu.container.conf" "$STATO/nginx/conf.d/pantedu.eu.conf"
    cp "$SORGENTE/infra/nginx/ratelimit-zones.conf" "$STATO/nginx/conf.d/pantedu-ratelimit.conf"
    nota "vhost e limiti copiati da infra/nginx"
}

# I permessi come sul server: i file di configurazione e i dati sono del
# proprietario e del gruppo www-data (33), non leggibili dagli altri. Lo fa un
# container, perché da WSL non si può dare un file a un utente che qui non
# esiste.
sistema_permessi() {
    esegui docker run --rm -v "$STATO":/s --entrypoint sh "$IMMAGINE_DB" -c \
        "chown $(id -u):33 /s/env /s/env.local /s/env.local.semina && chmod 640 /s/env /s/env.local /s/env.local.semina \
         && chown -R $(id -u):33 /s/dati && chmod -R u+rwX,g+rwX,o-rwx /s/dati && find /s/dati -type d -exec chmod g+s {} +" \
        || errore "permessi non sistemati"
}

# ── Il database ───────────────────────────────────────────────────────────

sql_root() { docker exec -i "$DB" mariadb --defaults-extra-file=/root/.my.cnf "$@"; }

avvia_database() {
    passo "Database: MariaDB 11.8.6, come in produzione"
    docker network inspect "$RETE" >/dev/null 2>&1 || esegui docker network create "$RETE" >/dev/null
    if esiste "$DB"; then
        in_corso "$DB" || esegui docker start "$DB"
    else
        esegui docker run -d --name "$DB" --network "$RETE" --restart unless-stopped \
            -v "$VOL_DB":/var/lib/mysql -v "$VOL_SOCK":/run/mysqld \
            -v "$STATO/db-root.cnf":/root/.my.cnf:ro \
            --env-file "$STATO/db.env" \
            "$IMMAGINE_DB" --sql-mode="$SQL_MODE" --max-allowed-packet=16M \
            --local-infile=0 --max-connections=100 \
            || errore "il database non parte"
    fi
    local i
    for i in $(seq 1 40); do
        docker exec "$DB" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && break
        sleep 2
    done
    esegui docker exec "$DB" mariadb --defaults-extra-file=/root/.my.cnf -N -e \
        "SELECT @@version, @@collation_server, @@sql_mode" || errore "il database non risponde"
}

# Le concessioni di pantedu_migrator e pantedu_maint si leggono dagli script
# che le creano in produzione (tools/security/create_*_db_user.sh), non si
# ricopiano qui: se lì cambiano, cambiano anche qui. Quelle di pantedu_app non
# hanno uno script nel repository: sono quelle misurate sul server il 14/9/2026.
sql_da_script() {   # file variabile-utente utente password
    local f=$SORGENTE/tools/security/$1 blocco
    blocco=$(sed -n '/^mysql <<SQL$/,/^SQL$/p' "$f" | sed '1d;$d')
    [[ "$blocco" == *GRANT* ]] || errore "non trovo le concessioni in $1: lo script è cambiato, e la simulazione va rivista"
    local v_utente="\${$2}" v_pw="\${PW}" v_db="\${DB_NAME}" apice='\`'
    blocco=${blocco//"$v_utente"/$3}
    blocco=${blocco//"$v_pw"/$4}
    blocco=${blocco//"$v_db"/$NOME_DB}
    blocco=${blocco//"$apice"/\`}
    printf '%s\n' "$blocco"
}

prepara_database() {
    passo "Database: schema, utenti con i permessi di produzione, migrazioni"
    local tabelle
    esegui sql_root -e "CREATE DATABASE IF NOT EXISTS \`$NOME_DB\` CHARACTER SET utf8mb4" || errore "database non creato"
    mostra_testo "utenti pantedu_app e pantedu_migrator, SQL su stdin (password non mostrate)"
    {
        printf "CREATE USER IF NOT EXISTS 'pantedu_app'@'localhost' IDENTIFIED BY '%s';\n" "$DB_APP_PASS"
        printf "ALTER USER 'pantedu_app'@'localhost' IDENTIFIED BY '%s';\n" "$DB_APP_PASS"
        printf "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, SHOW VIEW, DELETE HISTORY, SHOW CREATE ROUTINE ON \`%s\`.* TO 'pantedu_app'@'localhost';\n" "$NOME_DB"
        sql_da_script create_migrator_db_user.sh MIG_USER pantedu_migrator "$DB_MIGRATOR_PASS"
    } | sql_root || errore "utenti non creati"

    tabelle=$(sql_root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$NOME_DB'")
    if [ "$tabelle" -eq 0 ]; then
        mostra_testo "database/schema.sql su stdin a mariadb, come root"
        sql_root "$NOME_DB" < "$SORGENTE/database/schema.sql" || errore "schema non caricato"
        nota "tabelle dopo lo schema: $(sql_root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$NOME_DB'")"
    else
        nota "il database c'è già ($tabelle tabelle): si applicano solo le migrazioni nuove"
    fi
}

# Le migrazioni come sul server: le fa il rilascio, prima del container nuovo,
# con l'utente pantedu_migrator. Qui le fa l'immagine nuova, con la stessa
# configurazione montata dell'applicazione.
monta_app() {
    MONTA=(--network "$RETE"
        -v "$DATI":/var/lib/pantedu-data
        -v "$STATO/env":/var/www/pantedu/.env:ro
        -v "$STATO/env.local":/var/www/pantedu/.env.local:ro
        -v "$VOL_SOCK":/run/mysqld
        -e PANTEDU_DATA_PATH=/var/lib/pantedu-data
        -e DB_SOCKET=/run/mysqld/mysqld.sock)
}

migra() {   # immagine
    monta_app
    esegui_lungo "$STATO/registri/migrazioni.log" '^(Eseguite|Nessuna|Statement saltati|Errore|ERRORE|Migration fallita)' \
        docker run --rm "${MONTA[@]}" --user www-data --entrypoint php "$1" tools/migrate.php \
        || errore "migrazioni fallite: il container nuovo non parte"

    mostra_testo "utente pantedu_maint dalle concessioni di create_maintenance_db_user.sh, SQL su stdin"
    sql_da_script create_maintenance_db_user.sh MAINT_USER pantedu_maint "$DB_MAINT_PASS" | sql_root \
        || errore "utente di manutenzione non creato"
    esegui docker run --rm "${MONTA[@]}" --user www-data --entrypoint php "$1" \
        tools/security/apply_audit_append_only.php --apply \
        || errore "trigger append-only non applicati"
}

semina_se_serve() {   # immagine
    local utenti
    utenti=$(sql_root -N -e "SELECT COUNT(*) FROM \`$NOME_DB\`.users" 2>/dev/null || echo 0)
    if [ "$utenti" -gt 0 ]; then
        nota "utenti già presenti: $utenti"
        return 0
    fi
    passo "Utenti di prova"
    monta_app
    # Gli stessi montaggi, con il .env.local della semina al posto di quello vero.
    local SEMINA=() m
    for m in "${MONTA[@]}"; do
        if [[ "$m" == "$STATO/env.local:"* ]]; then m="$STATO/env.local.semina:/var/www/pantedu/.env.local:ro"; fi
        SEMINA+=("$m")
    done
    esegui docker run --rm "${SEMINA[@]}" --user www-data --env-file "$STATO/semina.env" \
        -v "$SORGENTE/tools/ci":/var/www/pantedu/tools/ci:ro \
        --entrypoint php "$1" tools/ci/seed_e2e_database.php || errore "semina fallita"
    esegui docker run --rm "${SEMINA[@]}" --user www-data \
        -v "$SORGENTE/tools/dev":/var/www/pantedu/tools/dev:ro \
        --entrypoint php "$1" tools/dev/e2e_prepare_users.php || errore "termini di servizio non accettati"
}

# ── Le immagini ───────────────────────────────────────────────────────────

costruisci_app() {
    passo "Immagine dell'applicazione, da questa copia"
    mkdir -p "$SORGENTE"/log/{admin,auth,errors,logout,security}
    ETICHETTA="$IMG_APP:${COMMIT:0:8}-$(date +%Y%m%d%H%M%S)"
    esegui_lungo "$STATO/registri/costruzione-app.log" "$SCHEMA_BUILD" \
        docker build --progress=plain -f "$SORGENTE/docker/Dockerfile" \
        --build-arg COMMIT="$COMMIT" \
        --label pantedu.vps.commit="$COMMIT" --label pantedu.vps.sporco="$SPORCO" \
        --label pantedu.vps.impronta="$(impronta_sorgente)" \
        -t "$ETICHETTA" "$SORGENTE" \
        || errore "l'immagine non si costruisce"
    nota "$ETICHETTA, $(( $(docker image inspect "$ETICHETTA" --format '{{.Size}}') / 1048576 )) MB"
}

# Si ricostruisce sempre: se tools/tex-compile-vps non è cambiato la cache
# risponde in pochi secondi, e se è cambiato — anche senza commit — lo si vede.
# `avvia_tex` rimette in piedi il container solo se l'immagine è un'altra.
#
# `--provenance=false`: con le attestazioni, a ogni costruzione cambia
# l'identificativo dell'immagine anche se tutti i passi vengono dalla cache (ci
# finisce dentro l'ora), e il servizio TeX veniva ricreato a ogni giro senza motivo.
costruisci_tex() {
    [ "$CON_TEX" -eq 1 ] || return 0
    passo "Immagine del servizio TeX (la prima volta: parecchi minuti)"
    esegui_lungo "$STATO/registri/costruzione-tex.log" "$SCHEMA_BUILD" \
        docker build --progress=plain --provenance=false -t "$IMG_TEX:locale" "$SORGENTE/tools/tex-compile-vps" \
        || errore "l'immagine del servizio TeX non si costruisce"
}

# ── I container ───────────────────────────────────────────────────────────

avvia_tex() {
    [ "$CON_TEX" -eq 1 ] || return 0
    passo "Servizio TeX"
    local attuale nuova
    nuova=$(docker image inspect -f '{{.Id}}' "$IMG_TEX:locale")
    attuale=$(docker inspect -f '{{.Image}}' "$TEX" 2>/dev/null || true)
    if [ "$attuale" != "$nuova" ]; then
        docker rm -f "$TEX" >/dev/null 2>&1 || true
        # I limiti dell'unità systemd del server: MemoryMax=2G, CPUQuota=350%.
        esegui docker run -d --name "$TEX" --network "$RETE" --restart unless-stopped \
            --env-file "$STATO/tex.env" --memory 2g --cpus 3.5 \
            --security-opt no-new-privileges "$IMG_TEX:locale" \
            || errore "il servizio TeX non parte"
    elif ! in_corso "$TEX"; then
        esegui docker start "$TEX"
    fi
    aspetta_sano "$TEX" || errore "il servizio TeX non è sano"
}

# Le opzioni di `docker run` di tools/webhook/deploy-container.sh, con i
# percorsi di questa prova.
avvia_app() {   # colore immagine
    local nome=$PREFISSO-app-$1 porta=8090
    [ "$1" = b ] && porta=8091
    monta_app
    docker rm -f "$nome" >/dev/null 2>&1 || true
    esegui docker run -d --name "$nome" --restart unless-stopped \
        -p "127.0.0.1:$porta:8080" \
        --add-host host.docker.internal:host-gateway \
        "${MONTA[@]}" \
        --memory 1g --pids-limit 256 --security-opt no-new-privileges \
        --cap-drop NET_RAW --cap-drop MKNOD --cap-drop SYS_CHROOT \
        --cap-drop AUDIT_WRITE --cap-drop SETFCAP --cap-drop SETPCAP \
        --health-start-period 90s \
        "$2" || errore "il container $nome non è partito"
    aspetta_sano "$nome" 50
}

scrivi_upstream() {   # colore
    printf '# Scritto da vps-locale.sh, come deploy-container.sh sul server.\nupstream pantedu_app { server %s-app-%s:8080; }\n' \
        "$PREFISSO" "$1" > "$STATO/nginx/conf.d/pantedu-upstream.conf"
    printf '%s' "$1" > "$STATO/attivo"
}

avvia_nginx() {
    passo "nginx davanti, con i file di infra/nginx"
    if esiste "$NGINX"; then
        in_corso "$NGINX" || esegui docker start "$NGINX"
        esegui docker exec "$NGINX" nginx -t || errore "la configurazione di nginx non passa"
        esegui docker exec "$NGINX" nginx -s reload
    else
        esegui docker run -d --name "$NGINX" --network "$RETE" --restart unless-stopped \
            -p "127.0.0.1:$PORTA_HTTPS:443" \
            -v "$STATO/nginx/conf.d":/etc/nginx/conf.d:ro \
            -v "$STATO/tls":/etc/letsencrypt/live/pantedu.eu:ro \
            "$IMMAGINE_NGINX" || errore "nginx non parte"
        sleep 2
        in_corso "$NGINX" || { docker logs --tail 30 "$NGINX" 2>&1 | sed 's/^/     /'; errore "nginx si è fermato"; }
        esegui docker exec "$NGINX" nginx -t || errore "la configurazione di nginx non passa"
    fi
}

avvia_lavori() {   # immagine
    passo "Lavori a orario"
    monta_app
    docker rm -f "$LAVORI" >/dev/null 2>&1 || true
    # Il timer del server: pantedu-compile-jobs ogni cinque minuti. Gli altri
    # (GDPR, catena di audit, intelligence del WAF, salvataggi) sono notturni:
    # `vps-locale.sh lavori` li elenca, e si lanciano a mano.
    # `--no-healthcheck`: l'immagine chiede /health a nginx, che qui dentro non
    # gira. Senza, il container risultava «unhealthy» mentre lavorava.
    # Il ciclo è fra apici singoli di proposito: le variabili le espande la
    # shell del container, non questa.
    # shellcheck disable=SC2016
    esegui docker run -d --name "$LAVORI" --restart unless-stopped "${MONTA[@]}" \
        --user www-data --memory 512m --no-healthcheck --entrypoint bash "$1" -c \
        'while :; do
            echo "[$(date +%T)] compilazioni in coda"; php tools/cron/process_compile_jobs.php
            sleep 300
         done' || errore "i lavori a orario non partono"
}

# ── I controlli ───────────────────────────────────────────────────────────

prova() {
    passo "Controlli, attraverso nginx come un visitatore"
    local esito=0 h v codice p attivo commit
    attivo=$(colore_attivo)
    [ -n "$attivo" ] || errore "non c'è un'applicazione in servizio: prima avvia"
    commit=$(commit_in_servizio)

    mostra curl -sk "$URL/health"
    h=$(curl -sk --max-time 10 "$URL/health" || true)
    nota "$h"
    [[ "$h" == *'"db":true'* ]] || { avviso "/health non dice db:true"; esito=1; }
    [[ "$h" == *'"pending":0'* ]] || { avviso "ci sono migrazioni in sospeso"; esito=1; }

    mostra curl -sk "$URL/version"
    v=$(curl -sk --max-time 10 "$URL/version" || true)
    nota "$v"
    [[ "$v" == *"\"sha\":\"$commit\""* ]] || { avviso "/version non dice ${commit:0:8}"; esito=1; }

    mostra curl -skI "$URL/health"
    if curl -skI --max-time 10 "$URL/health" | grep -qi '^strict-transport-security:'; then
        nota "intestazioni del nginx davanti: presenti (Strict-Transport-Security)"
    else
        avviso "manca Strict-Transport-Security: la richiesta non passa dal nginx davanti?"; esito=1
    fi

    for p in /.env /app/bootstrap.php /composer.json /tools/migrate.php /storage/version.txt /views/admin/Elementi_Riservati.html; do
        codice=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 "$URL$p" || true)
        nota "$codice  $p (deve essere negato)"
        case "$codice" in
            403 | 404) ;;
            *) esito=1 ;;
        esac
    done

    passo "Controlli, dentro"
    esegui docker exec "$DB" mariadb --defaults-extra-file=/root/.my.cnf -N -e \
        "SELECT CONCAT('trigger append-only: ', COUNT(*)) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$NOME_DB' AND TRIGGER_NAME LIKE 'trg_append_only_%';
         SELECT CONCAT('TRIGGER a pantedu_app (atteso 0): ', COUNT(*)) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=\"'pantedu_app'@'localhost'\" AND PRIVILEGE_TYPE='TRIGGER'" \
        || esito=1

    if [ "$CON_TEX" -eq 1 ] && esiste "$TEX"; then
        esegui docker exec -u www-data "$PREFISSO-app-$attivo" curl -s --max-time 5 "http://$TEX:8001/health" || esito=1
        mostra docker exec -u www-data "$PREFISSO-app-$attivo" php "(TexCompileClient::default()->compile di un documento di prova)"
        if docker exec -u www-data "$PREFISSO-app-$attivo" php -r '
            require "app/bootstrap.php";
            $r = App\Services\TexCompile\TexCompileClient::default()->compile(
                "\\documentclass{article}\\begin{document}Prova dal VPS in locale.\\end{document}", "vps-locale");
            $pdf = (string)($r["pdf"] ?? "");
            printf("     ok=%s, %d byte, inizia con %%PDF: %s, %s ms\n",
                !empty($r["ok"]) ? "sì" : "no", strlen($pdf), str_starts_with($pdf, "%PDF") ? "sì" : "no", $r["duration_ms"] ?? "?");
            exit(!empty($r["ok"]) && str_starts_with($pdf, "%PDF") ? 0 : 1);'; then
            nota "l'applicazione compila un PDF attraverso il servizio TeX"
        else
            avviso "la compilazione attraverso il servizio TeX non riesce"; esito=1
        fi
    fi
    if [ "$esito" -eq 0 ]; then
        nota "tutti i controlli passano"
    else
        avviso "qualche controllo non passa: vedi sopra"
    fi
    return "$esito"
}

resoconto() {
    local attivo commit
    attivo=$(colore_attivo)
    commit=$(commit_in_servizio)
    set -a
    # shellcheck disable=SC1091
    . "$STATO/segreti.env"
    set +a
    cat <<RIGHE

================================================================================
 Il VPS in locale è in piedi
================================================================================

  Il sito, come in produzione (attraverso nginx, TLS):
      $URL
  Il certificato è locale: il browser avvisa, e si va avanti.

  Dritto al container, senza il nginx davanti (per confronto):
      http://127.0.0.1:$([ "$attivo" = b ] && echo 8091 || echo 8090)
  Qui i cookie di sessione non passano: sono «Secure», e questa è http.

  In servizio: ${commit:0:8}, container $PREFISSO-app-$attivo

  Utenti di prova (validi solo in questo database):
      docente.uno   $PROVA_DOCENTE_PASS   (docente)
      docente.due         $PROVA_DOCENTE2_PASS   (docente)
      admin               $PROVA_ADMIN_PASS   (amministratore)

  Dopo una modifica al codice:  bash tools/dev/wsl/vps-locale.sh ricostruisci
  Stato e indirizzi:            bash tools/dev/wsl/vps-locale.sh stato
  Spegnere (tiene i dati):      bash tools/dev/wsl/vps-locale.sh ferma
  Togliere tutto:               bash tools/dev/wsl/vps-locale.sh pulisci

  Non simulati: Cloudflare (paese del visitatore), posta, Grafana, webhook e
  rilascio automatico, salvataggi, AIDE e auditd.
  Registro di questo giro: $REGISTRO
RIGHE
}

# ── I comandi ─────────────────────────────────────────────────────────────

cmd_avvia() {
    apri_registro avvia
    controlla_docker
    controlla_porte
    verifica_ramo
    prepara_stato
    costruisci_tex
    costruisci_app
    avvia_database
    prepara_database
    sistema_permessi
    migra "$ETICHETTA"
    semina_se_serve "$ETICHETTA"
    avvia_tex

    passo "Applicazione"
    local colore
    colore=$(colore_attivo)
    [ -n "$colore" ] || colore=a
    avvia_app "$colore" "$ETICHETTA" || errore "l'applicazione non è sana"
    scrivi_upstream "$colore"
    avvia_nginx
    avvia_lavori "$ETICHETTA"
    togli_immagini_vecchie
    prova || true
    resoconto
}

# Lo scambio di tools/webhook/deploy-container.sh: il nuovo parte accanto al
# vecchio, si dichiara sano, nginx passa a lui con un reload (che non tronca le
# richieste in corso), e solo allora il vecchio si ferma.
cmd_ricostruisci() {
    apri_registro ricostruisci
    controlla_docker
    local vecchio nuovo
    vecchio=$(colore_attivo)
    [ -n "$vecchio" ] || errore "non c'è niente da ricostruire: prima avvia"
    in_corso "$DB" || errore "il database è spento: prima avvia"
    nuovo=b; [ "$vecchio" = b ] && nuovo=a

    verifica_ramo
    prepara_stato
    sistema_permessi
    costruisci_tex
    avvia_tex
    costruisci_app

    passo "Migrazioni, con l'immagine nuova"
    migra "$ETICHETTA"

    passo "Scambio: $PREFISSO-app-$vecchio → $PREFISSO-app-$nuovo"
    if ! avvia_app "$nuovo" "$ETICHETTA"; then
        docker rm -f "$PREFISSO-app-$nuovo" >/dev/null 2>&1 || true
        errore "il nuovo non è sano: resta in servizio $PREFISSO-app-$vecchio"
    fi
    scrivi_upstream "$nuovo"
    if ! esegui docker exec "$NGINX" nginx -t; then
        scrivi_upstream "$vecchio"
        docker rm -f "$PREFISSO-app-$nuovo" >/dev/null 2>&1 || true
        errore "nginx rifiuta la configurazione nuova: resta in servizio $PREFISSO-app-$vecchio"
    fi
    esegui docker exec "$NGINX" nginx -s reload
    esegui docker rm -f "$PREFISSO-app-$vecchio"
    avvia_lavori "$ETICHETTA"
    togli_immagini_vecchie
    prova || true
    resoconto
}

# Si tengono l'immagine in servizio e quella di prima: un ritorno indietro a
# portata di mano, come sul server, senza riempire il disco a ogni giro.
togli_immagini_vecchie() {
    local in_uso tenute=0 img
    in_uso=$(docker inspect -f '{{.Config.Image}}' "$PREFISSO-app-$(colore_attivo)" 2>/dev/null || true)
    while read -r img; do
        [ -n "$img" ] || continue
        if [ "$img" = "$in_uso" ]; then continue; fi
        tenute=$((tenute + 1))
        [ "$tenute" -le 1 ] && continue
        esegui docker rmi "$img" || true
    done < <(docker image ls "$IMG_APP" --format '{{.CreatedAt}}|{{.Repository}}:{{.Tag}}' | sort -r | cut -d'|' -f2)
}

cmd_stato() {
    controlla_docker
    passo "Container"
    docker ps -a --filter "name=^$PREFISSO-" --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}' | sed 's/^/   /'
    passo "Immagini"
    docker image ls --filter "reference=$PREFISSO-*" --format 'table {{.Repository}}:{{.Tag}}\t{{.Size}}\t{{.CreatedSince}}' | sed 's/^/   /'
    local attivo commit ora sporco impronta_servizio
    attivo=$(colore_attivo)
    if [ -z "$attivo" ]; then
        nota "nessuna applicazione in servizio: bash tools/dev/wsl/vps-locale.sh avvia"
        return 0
    fi
    commit=$(commit_in_servizio)
    ora=$(git -C "$SORGENTE" rev-parse HEAD 2>/dev/null || true)
    sporco=$(git -C "$SORGENTE" status --porcelain --untracked-files=no 2>/dev/null | grep -c . || true)
    impronta_servizio=$(docker inspect -f '{{index .Config.Labels "pantedu.vps.impronta"}}' "$PREFISSO-app-$attivo" 2>/dev/null || true)
    passo "Che cosa gira"
    nota "in servizio: ${commit:0:8} ($PREFISSO-app-$attivo)"
    nota "la copia $SORGENTE è su $(git -C "$SORGENTE" rev-parse --abbrev-ref HEAD) a ${ora:0:8}, con $sporco file modificati non committati"
    if [ "$impronta_servizio" = "$(impronta_sorgente)" ]; then
        nota "il codice in servizio è quello della copia, modifiche non committate comprese"
    else
        nota "il codice della copia è cambiato da quando è stata costruita l'immagine: bash tools/dev/wsl/vps-locale.sh ricostruisci"
    fi
    passo "Indirizzi"
    nota "$URL (attraverso nginx)"
    nota "http://127.0.0.1:$([ "$attivo" = b ] && echo 8091 || echo 8090) (dritto al container)"
    nota "utenti e password: $STATO/segreti.env (PROVA_*)"
}

cmd_lavori() {
    apri_registro lavori
    controlla_docker
    local attivo
    attivo=$(colore_attivo)
    [ -n "$attivo" ] || errore "non c'è un'applicazione in servizio"
    passo "Lavori a orario, subito"
    esegui docker exec "$LAVORI" php tools/cron/process_compile_jobs.php
    esegui docker exec -u www-data "$PREFISSO-app-$attivo" php tools/ops/diagnostica.php
    nota "Notturni, da lanciare a mano se servono (sul server li fa systemd):"
    nota "  docker exec $LAVORI php tools/gdpr/anonymize_expired.php"
    nota "  docker exec $LAVORI php tools/audit/export_audit_chain.php"
    nota "  docker exec $LAVORI php tools/tikz_prewarm_cache.php --delay=2"
}

cmd_registro() {
    controlla_docker
    local chi=${1:-} c
    case "$chi" in
        app) c=$PREFISSO-app-$(colore_attivo) ;;
        nginx | tex | db | lavori) c=$PREFISSO-$chi ;;
        "")
            for c in "$DB" "$TEX" "$PREFISSO-app-$(colore_attivo)" "$NGINX" "$LAVORI"; do
                esiste "$c" || continue
                passo "$c (ultime 25 righe)"
                docker logs --tail 25 "$c" 2>&1 | sed 's/^/   /'
            done
            nota "registri dei giri: $STATO/registri/"
            return 0 ;;
        *) errore "registro di cosa? app, nginx, tex, db o lavori" ;;
    esac
    esiste "$c" || errore "$c non c'è"
    docker logs --tail 100 "$c" 2>&1
}

cmd_ferma() {
    apri_registro ferma
    controlla_docker
    passo "Spengo (database, dati e immagini restano)"
    local c
    for c in "$NGINX" "$LAVORI" "$PREFISSO-app-a" "$PREFISSO-app-b" "$TEX" "$DB"; do
        in_corso "$c" && esegui docker stop "$c"
    done
    nota "Si riaccende con: bash tools/dev/wsl/vps-locale.sh avvia"
}

cmd_pulisci() {
    apri_registro pulisci
    controlla_docker
    passo "Tolgo il VPS in locale"
    nota "container, rete, database, dati d'istanza, segreti e immagini dell'applicazione."
    if [ "$TUTTO" -eq 1 ]; then
        nota "Con --tutto anche l'immagine del servizio TeX (ricostruirla dura parecchi minuti) e i registri."
    else
        nota "L'immagine del servizio TeX e i registri restano (con --tutto vanno via anche loro)."
    fi
    if [ "$CONFERMATO" -ne 1 ]; then
        local r
        read -r -p "   procedo? (s/N) " r
        [ "$r" = s ] || [ "$r" = S ] || { nota "lasciato stare."; return 0; }
    fi
    local c img
    for c in "$NGINX" "$LAVORI" "$PREFISSO-app-a" "$PREFISSO-app-b" "$TEX" "$DB"; do
        esiste "$c" && esegui docker rm -f "$c"
    done
    docker network inspect "$RETE" >/dev/null 2>&1 && esegui docker network rm "$RETE"
    for c in "$VOL_DB" "$VOL_SOCK"; do
        docker volume inspect "$c" >/dev/null 2>&1 && esegui docker volume rm "$c"
    done
    # I file dei dati li ha scritti www-data: da WSL non si cancellano.
    if [ -d "$STATO" ]; then
        esegui docker run --rm -v "$STATO":/s --entrypoint sh "$IMMAGINE_DB" -c \
            'rm -rf /s/dati /s/env /s/env.local /s/env.local.semina /s/segreti.env /s/db.env /s/db-root.cnf /s/tex.env /s/semina.env /s/tls /s/nginx /s/attivo'
    fi
    while read -r img; do
        [ -n "$img" ] && esegui docker rmi "$img"
    done < <(docker image ls "$IMG_APP" --format '{{.Repository}}:{{.Tag}}')
    if [ "$TUTTO" -eq 1 ]; then
        docker image inspect "$IMG_TEX:locale" >/dev/null 2>&1 && esegui docker rmi "$IMG_TEX:locale"
        esegui rm -rf "$STATO"
    fi
    nota "Fatto. La cache di costruzione di Docker resta: la usano anche i runner della CI."
}

# ── Argomenti ─────────────────────────────────────────────────────────────

COMANDO=${1:-}
[ $# -gt 0 ] && shift
ARG_REGISTRO=""
while [ $# -gt 0 ]; do
    case "$1" in
        --sorgente) SORGENTE=$(cd "${2:?manca la cartella}" && pwd); shift ;;
        --senza-tex) CON_TEX=0 ;;
        --tutto) TUTTO=1 ;;
        --si) CONFERMATO=1 ;;
        app | nginx | tex | db | lavori) ARG_REGISTRO=$1 ;;
        *) errore "opzione sconosciuta: $1" ;;
    esac
    shift
done

case "$COMANDO" in
    avvia) cmd_avvia ;;
    ricostruisci) cmd_ricostruisci ;;
    stato) cmd_stato ;;
    ramo) verifica_ramo ;;
    prova) apri_registro prova; controlla_docker; prova ;;
    lavori) cmd_lavori ;;
    registro) cmd_registro "$ARG_REGISTRO" ;;
    ferma) cmd_ferma ;;
    pulisci) cmd_pulisci ;;
    *) sed -n '2,/^set -uo pipefail$/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 2 ;;
esac
