#!/usr/bin/env bash
#
# Rilascio a container: costruisci a fianco, controlla, scambia.
#
# Sostituisce il `git reset --hard` sulla directory servita. La differenza che
# conta non è il container in sé: è che **il codice nuovo entra in servizio in
# un istante solo**, quando è già stato controllato. Nel vecchio assetto fra il
# `reset` e la fine delle migrazioni passavano decine di secondi in cui il
# codice nuovo rispondeva a richieste vere su uno schema vecchio, e un errore a
# metà lasciava l'albero mezzo aggiornato.
#
# Le tappe, e cosa succede se una fallisce:
#
#   1. si stabilisce QUALE commit                 → niente da rilasciare, si esce
#   2. si ottiene l'immagine (registro o build)   → niente cambia, il vecchio serve
#      (se intanto `main` è andato avanti,       → biglietto per il rilascio
#       non si aspetta un'immagine annullata)      differito, si esce
#   2-bis. se composer.json o composer.lock sono  → avviso e riga fra le anomalie,
#      cambiati, si aggiorna il vendor/ dell'host   si prosegue, e ci riprova il
#      (lo caricano le migrazioni)                   rilascio dopo
#   3. istantanea del database                    → non si prosegue
#   4. migrazioni                                 → non si prosegue
#   5. si avvia il container NUOVO su una porta   → si ferma, il vecchio serve
#      libera, senza traffico
#   6. si aspetta che si dichiari sano            → si ferma, il vecchio serve
#   7. si scambia l'upstream di nginx             → si rimette com'era
#   7-bis. se il codice del servizio TeX è        → avviso e riga fra le anomalie,
#      cambiato, lo si porta in /opt e lo si         lo scambio resta
#      riavvia (il TeX gira sull'host)
#   8. si verifica ATTRAVERSO nginx               → si rimette com'era
#      (e se il container nuovo raggiunge il TeX:  → solo avviso)
#   8-ter. si allineano nel database le versioni  → avviso e riga fra le anomalie,
#      legali di docs/legal/versions.json            lo scambio resta
#   8-quater. si portano nella biblioteca TikZ    → avviso e riga fra le anomalie,
#      i modelli di storage/templates/tikz           lo scambio resta
#   9. si ferma il vecchio                        → si segnala, ma è già fatto
#  10. si guarda se `main` è andato avanti nel     → si lascia il biglietto per
#      frattempo                                     il rilascio differito
#
# Fino al passo 7 la produzione non si accorge di niente. È tutto il punto.
#
# Installazione:
#   sudo cp tools/webhook/deploy-container.sh /usr/local/bin/pantedu-deploy-container.sh
#   sudo chmod 755 /usr/local/bin/pantedu-deploy-container.sh

set -euo pipefail

# Dichiarazione letta da `migra-a-release.sh`: da qui in poi il rilascio sa
# costruire e scambiare da sé. Senza questa riga quello script si rifiuta di
# toccare il collegamento (voce 86 del debito).
# shellcheck disable=SC2034  # la legge un altro script, con grep.
RILASCIO_A_RELEASE=1

# ── Impostazioni ──────────────────────────────────────────────────────────
REPO_DIR="/var/www/pantedu"
RAMO="main"
IMMAGINE="ghcr.io/vittop89/pantedu"
NOME_A="pantedu-app-a"
NOME_B="pantedu-app-b"
PORTA_A=8090
PORTA_B=8091
DATI="/var/lib/pantedu-data"
UPSTREAM="/etc/nginx/conf.d/pantedu-upstream.conf"
SEGRETI="/etc/pantedu-deploy.env"
UTENTE_GIT="pantedu"

ts()   { date '+%Y-%m-%d %H:%M:%S'; }
nota() { echo "[$(ts)] [rilascio] $*"; }
avviso() { echo "[$(ts)] [rilascio] [ATTENZIONE] $*" >&2; }
errore() { echo "[$(ts)] [rilascio] ERRORE: $*" >&2; exit 1; }

git_come_proprietario() { sudo -u "$UTENTE_GIT" git -C "$REPO_DIR" "$@"; }

# >>> tex: il servizio TeX, che resta sull'host ───────────────────────────
#
# Il servizio TeX non sta nel container: è l'unità systemd `tex-compile`, con
# il suo utente, i suoi limiti e il suo codice in /opt/tex-compile. Il rilascio
# vecchio (deploy.sh, passo 5) ci copiava i `.py` cambiati e lo riavviava;
# passando ai container (8/9/2026) quel passo non è stato portato, e una
# correzione al servizio sarebbe rimasta nel repository (misurato il 19/9: fino
# ad allora nessun commit l'aveva toccato, quindi nessuno scostamento).
#
# Il 5b di deploy.sh (i modelli verso /var/lib/pantedu-data/storage/templates)
# invece non si porta: la cartella non esiste più, il TeX compila i pacchetti
# che riceve e il PHP legge i modelli dall'immagine.
#
# La sonda dopo il riavvio chiede /health al **gateway del bridge Docker**, cioè
# dove il servizio ascolta perché lo raggiungano i container
# (docs/ops/tex-dal-container.md). L'indirizzo non si scrive: lo si chiede a
# Docker, così segue la sua configurazione.
#
# Queste funzioni stanno fra i due segni `>>> tex` e `<<< tex` perché la prova
# (tests/ops/sincronizza-tex.test.sh) le prende da qui e le esegue con comandi
# finti: si prova il codice che gira, non una copia.
TEX_DIR="${TEX_DIR:-/opt/tex-compile}"
TEX_UTENTE="${TEX_UTENTE:-texcompile}"
TEX_SERVIZIO="${TEX_SERVIZIO:-tex-compile}"
TEX_PORTA="${TEX_PORTA:-8001}"
TEX_SORGENTE_REL="tools/tex-compile-vps/app"
# Il segno che l'ultima sincronizzazione non è andata a buon fine. Sta fuori da
# `app/`, dove rsync non arriva, e vale finché un giro non riesce.
TEX_RIFARE="${TEX_RIFARE:-$TEX_DIR/.risincronizza}"
ANOMALIE="${ANOMALIE:-$DATI/storage/logs/anomalie.jsonl}"

# Una riga nel registro delle anomalie, scritta a mano come fa aide-check.sh:
# qui siamo in shell e come root, la classe PHP non si raggiunge. Il messaggio
# lo scrive questo script: niente virgolette né barre rovesciate dentro.
anomalia_rilascio() {
    local codice="$1" cosa="$2"
    [ -d "$(dirname "$ANOMALIE")" ] || return 0
    printf '{"quando":"%s","codice":"%s","cosa":"%s","dettagli":{"commit":"%s"}}\n' \
        "$(date -Iseconds)" "$codice" "$cosa" "${COMMIT:-?}" >> "$ANOMALIE" || return 0
    chown pantedu:www-data "$ANOMALIE" 2>/dev/null || true
    chmod 0660 "$ANOMALIE" 2>/dev/null || true
}

# Il servizio risponde sul gateway del bridge? Qualche secondo di pazienza:
# uvicorn con due processi ci mette un paio di secondi a ripartire.
tex_sonda() {
    local url="${TEX_SONDA_URL:-}" gateway corpo i
    if [ -z "$url" ]; then
        gateway=$(docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}' 2>/dev/null || true)
        if [ -z "$gateway" ]; then
            avviso "  non riesco a chiedere a Docker il gateway del bridge: non so dove sondare il TeX."
            return 1
        fi
        url="http://$gateway:$TEX_PORTA/health"
    fi
    for i in $(seq 1 "${TEX_SONDA_TENTATIVI:-10}"); do
        # Il corpo in una variabile e non `curl | grep -q`: con pipefail, grep
        # che chiude presto fa fallire curl, e la sonda direbbe «no» a un sì.
        corpo=$(curl -sf -m 3 "$url" 2>/dev/null || true)
        case "$corpo" in *'"status":"ok"'*) return 0 ;; esac
        [ "$i" -lt "${TEX_SONDA_TENTATIVI:-10}" ] && sleep "${TEX_SONDA_PAUSA:-2}"
    done
    return 1
}

# Porta in /opt i `.py` del repository, reinstalla le dipendenze se sono
# cambiate, riavvia e sonda. Esce diverso da zero al primo passo che fallisce,
# dicendo quale.
sincronizza_tex() {
    local cambiati="$1"
    if [ ! -d "$TEX_DIR/app" ]; then
        avviso "  $TEX_DIR/app non esiste: il servizio TeX non è installato dove me lo aspetto."
        return 1
    fi
    # Solo i `.py`, come faceva deploy.sh: il resto di /opt/tex-compile/app
    # (requirements.txt, copie .bak) non è codice che gira.
    #
    # `--include='*/'` (20/9/2026): senza, `--exclude='*'` esclude anche le
    # cartelle e rsync non ci scende. Misurato: con `app/main.py`,
    # `app/sub/router.py` e `app/requirements.txt`, in /opt arrivava solo
    # `main.py` — e il passo diceva «aggiornato». Oggi il servizio è piatto, ma
    # il primo modulo in una sottocartella sarebbe rimasto nel repository senza
    # che niente lo dicesse. `-m` non lascia in giro le cartelle rimaste vuote.
    rsync -a -m --include='*/' --include='*.py' --exclude='*' \
        "$REPO_DIR/$TEX_SORGENTE_REL/" "$TEX_DIR/app/" \
        || { avviso "  la copia dei .py in $TEX_DIR/app è fallita."; return 1; }
    chown -R "$TEX_UTENTE:$TEX_UTENTE" "$TEX_DIR/app" \
        || { avviso "  non riesco a dare $TEX_DIR/app a $TEX_UTENTE."; return 1; }
    find "$TEX_DIR/app" -type d -name __pycache__ -exec rm -rf {} + 2>/dev/null || true
    if grep -qx "$TEX_SORGENTE_REL/requirements.txt" <<<"$cambiati"; then
        nota "  requirements.txt è cambiato: reinstallo le dipendenze del servizio"
        install -m 644 -o "$TEX_UTENTE" -g "$TEX_UTENTE" \
            "$REPO_DIR/$TEX_SORGENTE_REL/requirements.txt" "$TEX_DIR/app/requirements.txt" \
            || { avviso "  non riesco a copiare requirements.txt."; return 1; }
        sudo -u "$TEX_UTENTE" "$TEX_DIR/venv/bin/pip" install --quiet --no-cache-dir \
            -r "$TEX_DIR/app/requirements.txt" \
            || { avviso "  pip install è fallito: il servizio gira con le dipendenze di prima."; return 1; }
    fi
    systemctl restart "$TEX_SERVIZIO" \
        || { avviso "  il riavvio di $TEX_SERVIZIO è fallito."; return 1; }
    tex_sonda \
        || { avviso "  dopo il riavvio il servizio TeX non risponde sul gateway del bridge."; return 1; }
}

# Che cosa c'è di diverso fra il repository e /opt — i file, non i commit
# (20/9/2026).
#
# Al primo giro qui c'era `git diff PRIMA COMMIT`, dove PRIMA è la HEAD del
# sorgente prima del `reset --hard`. Ma il reset sta al passo 1, cioè **prima
# di ogni uscita anticipata**: il rinvio al differito del passo 2, il build
# fallito, il container che non si dichiara mai sano al passo 6. Un rilascio
# che porta codice del servizio TeX e poi esce da una di quelle porte lascia
# /opt indietro; il rilascio dopo confronta due commit fra cui del servizio non
# c'è più niente, dice «non è cambiato» e /opt resta col codice vecchio. Senza
# avviso e senza anomalia — il verde che non ha guardato.
#
# È lo stesso difetto che `deploy.sh` si è tolto due volte (24/5 e 31/8/2026)
# per le unità systemd, con la stessa cura: confrontare i file installati con
# quelli del repository, non la storia di git. Il confronto è `cmp`, cioè byte
# per byte, e guarda esattamente i file che `sincronizza_tex` porta — i `.py` e
# `requirements.txt`, **anche nelle sottocartelle**. I file che stanno in /opt e
# non nel repository non contano: rsync non li toglie, e dirli «diversi»
# vorrebbe dire sincronizzare a ogni rilascio per sempre.
#
# Una riga per file diverso. Se il confronto **non si è potuto fare** la riga
# comincia con `!`: «non ho guardato» non è «sono uguali», ed è precisamente il
# modo in cui un controllo diventa un verde che non ha misurato niente. Chi
# chiama le separa, le dice, e sincronizza lo stesso.
tex_differenze() {
    local sorgente="$REPO_DIR/$TEX_SORGENTE_REL" f rel esito
    if [ ! -d "$sorgente" ]; then
        echo "!$TEX_SORGENTE_REL non esiste nel repository: non ho potuto confrontare niente"
        return 0
    fi
    while IFS= read -r f; do
        rel="${f#"$sorgente"/}"
        if [ ! -f "$TEX_DIR/app/$rel" ]; then
            echo "$TEX_SORGENTE_REL/$rel"   # non installato: è una differenza, non un guasto
            continue
        fi
        # `cmp` esce 0 se uguali, 1 se diversi, 2 o più se non ha potuto
        # leggere: i tre casi vanno tenuti distinti.
        cmp -s "$f" "$TEX_DIR/app/$rel"
        esito=$?
        case "$esito" in
            0) ;;
            1) echo "$TEX_SORGENTE_REL/$rel" ;;
            *) echo "!non riesco a confrontare $TEX_SORGENTE_REL/$rel (cmp è uscito $esito)" ;;
        esac
    done < <(find "$sorgente" -type f \( -name '*.py' -o -name 'requirements.txt' \) | sort)
    return 0
}

# Il passo del rilascio: niente se in /opt c'è già il codice del repository.
# Non ferma mai il rilascio: il container nuovo è già in servizio, e un TeX che
# non riparte si segnala e si guarda.
passo_tex() {
    local tutto cambiati guasti rifare=""
    if [ -f "$TEX_RIFARE" ]; then rifare=1; fi
    tutto=$(tex_differenze)
    guasti=$(grep '^!' <<<"$tutto" || true)
    cambiati=$(grep -v '^!' <<<"$tutto" || true)

    # Un confronto che non si è potuto fare non è un «tutto a posto»: si dice, e
    # si sincronizza lo stesso. Se il guasto è vero, rsync fallisce subito dopo
    # e il passo lascia avviso, anomalia e segno da cui ripartire.
    if [ -n "$guasti" ]; then
        avviso "non sono riuscito a confrontare tutto il codice del servizio TeX:"
        while IFS= read -r f; do avviso "  ${f#!}"; done <<<"$guasti"
        avviso "  sincronizzo lo stesso: «non ho guardato» non è «è già a posto»."
    fi

    if [ -z "$cambiati" ] && [ -z "$guasti" ] && [ -z "$rifare" ]; then
        nota "il codice del servizio TeX in $TEX_DIR è già quello del repository: niente da portare"
        return 0
    fi

    if [ -n "$cambiati" ]; then
        nota "il codice del servizio TeX in $TEX_DIR è diverso da quello del repository: lo porto e riavvio"
        while IFS= read -r f; do nota "  $f"; done <<<"$cambiati"
    elif [ -n "$guasti" ]; then
        nota "porto in $TEX_DIR il codice del servizio TeX e riavvio"
    else
        # I file combaciano ma l'ultimo giro non è arrivato in fondo: può
        # essere fallito il riavvio, e allora il servizio gira ancora con il
        # codice vecchio in memoria, che nessun confronto di file può vedere.
        nota "i file del servizio TeX sono allineati, ma l'ultima sincronizzazione non era riuscita: rifaccio e riavvio"
    fi

    if sincronizza_tex "$cambiati"; then
        rm -f "$TEX_RIFARE" 2>/dev/null || true
        nota "  servizio TeX aggiornato, e risponde"
    else
        # Il segno: senza, una sincronizzazione fallita non si riprovava mai
        # più, perché i file intanto erano stati copiati.
        if [ -d "$TEX_DIR" ]; then : > "$TEX_RIFARE" 2>/dev/null || true; fi
        avviso "il servizio TeX non è stato aggiornato bene: le compilazioni possono fallire."
        avviso "  il rilascio prosegue; cosa guardare in docs/ops/tex-dal-container.md."
        anomalia_rilascio tex_sincronizzazione "Il rilascio di ${COMMIT:0:8} non ha portato il codice del servizio TeX in $TEX_DIR, o dopo il riavvio il servizio non risponde. Il rilascio dopo ci riprova da solo. Dettagli nel giornale di pantedu-deploy."
    fi
    return 0
}
# <<< tex ─────────────────────────────────────────────────────────────────

# >>> host: il vendor/ dell'host, le versioni legali e i modelli TikZ ───────
#
# 23/9/2026 — due passi del rilascio vecchio (deploy.sh) che il passaggio ai
# container (8/9/2026) ha perso senza che niente lo dicesse (A-14 della
# revisione architetturale del 23/9):
#
#   - `tools/legal/sync_versions.php --apply`, che porta nel database le
#     versioni di Termini e AUP scritte in docs/legal/versions.json. Il cancello
#     dei Termini legge dal database quale versione è vincolante: il 23/9 in
#     produzione il registro era fermo a Termini e AUP 1.3, mentre
#     versions.json porta dal 22/9 i Termini alla 1.5 e l'AUP alla 1.4;
#   - `composer install --no-dev` quando cambiano composer.json o composer.lock.
#     Il container ha il suo vendor/, costruito nell'immagine; quello dell'host
#     lo caricano le migrazioni del passo 4, i lavori a orario e l'avviso di
#     guasto. Il primo aggiornamento di una dipendenza lo avrebbe lasciato
#     indietro in silenzio.
#
# Nessuno dei due ferma il rilascio: avvisano, scrivono una riga fra le
# anomalie (la diagnostica la trasforma in una mail) e si va avanti.
#
# **Dove stanno, e perché non tutti e due subito dopo le migrazioni.**
#
# Il vendor si aggiorna PRIMA delle migrazioni, come faceva deploy.sh:
# `tools/migrate.php` carica `vendor/autoload.php` dell'host. Una dipendenza
# che il codice nuovo richiede farebbe fallire le migrazioni con il vendor
# vecchio, e siccome le migrazioni fallite fermano il rilascio, un passo
# composer messo dopo non girerebbe mai — né in questo rilascio né nei
# successivi.
#
# Le versioni legali si allineano DOPO la verifica del passo 8, quando lo
# scambio non si annulla più. Prima, uno scambio annullato rimetterebbe in
# servizio il container vecchio, con i testi vecchi, accanto a un database che
# conosce già la versione nuova: il cancello chiederebbe di accettare un testo
# che la pagina non mostra. Il passo è idempotente e gira a ogni rilascio.
#
# **Il vendor si decide con `git diff`, ma con un segno che sopravvive.** Il
# `reset --hard` del passo 1 viene prima di ogni uscita anticipata — il rinvio
# al differito del passo 2, il build fallito — ed è il difetto che il passo del
# TeX si è tolto il 20/9: un rilascio che porta un composer.lock nuovo e poi
# esce, al giro dopo confronterebbe due commit fra cui composer non c'è più.
# Qui il confronto si fa **prima** del reset e lascia un segno, che toglie solo
# un `composer install` riuscito. Il segno sta in una cartella di root, 0700:
# in /var/lib/pantedu-deploy scrive anche il webhook, che gira come www-data, e
# potrebbe far ripartire composer a ogni rilascio. La cartella è fuori da AIDE
# (tools/ops/aide-99_pantedu.conf), come /var/lib/pantedu-deploy: il segno
# compare e sparisce per mestiere, e quando resta lo dice già l'anomalia
# `vendor_host`.
#
# **Un tetto al tempo di composer.** Il rilascio ha quindici minuti in tutto
# (TimeoutStartSec di pantedu-deploy.service), e un rilascio ucciso da systemd
# si ferma a metà, magari durante le migrazioni. Un composer appeso — un mirror
# che non risponde — ci arriverebbe da solo. Dopo $COMPOSER_TETTO secondi
# `timeout` ferma composer e tutto il suo gruppo di processi, e il passo fa
# come per un fallimento: avviso, anomalia, segno, e ci riprova il rilascio
# dopo. Il tetto sta dentro `sudo`, così `timeout` è il padre di composer e il
# segnale arriva anche ai processi che composer lancia. Misurato il 23/9 in
# WSL: un install da zero con la cache piena ci mette 2,6 secondi, con la
# cache vuota 7,1. Il tetto è di un minuto: otto volte il caso lento misurato.
# Il percorso peggiore — sette minuti di attesa dell'immagine, quattro di
# build di ripiego, uno di composer più i quindici secondi di --kill-after,
# fino a due minuti di attesa della salute — fa quattordici minuti e un
# quarto, e lascia circa quarantacinque secondi a istantanea e migrazioni
# dentro i quindici dell'unità. Il margine è stretto: il tetto non va alzato
# senza rifare il conto (con cinque minuti, com'era nella prima stesura, il
# percorso peggiore sforava).
#
# Il ritocco dei permessi di vendor/ che deploy.sh faceva dopo composer (il
# 25/5/2026 un vendor/ illeggibile da www-data aveva dato 500 a tutto il sito)
# qui non si porta: le pagine le serve il container, con il suo vendor/, e sul
# vendor/ dell'host non legge nessuno come www-data. Il webhook è autonomo
# (tools/webhook/github.php non carica l'autoload), e le unità di tools/systemd
# che lo usano girano come $UTENTE_GIT, che è il proprietario dei file. Il
# gruppo e i modi li riallinea comunque il passo 1.bis a ogni rilascio.
#
# Le funzioni stanno fra `>>> host` e `<<< host` perché la prova
# (tests/ops/vendor-e-versioni-legali.test.sh) le prende da qui e le esegue con
# comandi finti, come quella del TeX. `anomalia_rilascio` sta nel blocco `tex`.
VENDOR_DA_AGGIORNARE="${VENDOR_DA_AGGIORNARE:-/var/lib/pantedu-rilascio/vendor-da-aggiornare}"
COMPOSER_TETTO="${COMPOSER_TETTO:-60}"
VENDOR_DA_FARE=""

lascia_segno_vendor() {
    local cartella
    VENDOR_DA_FARE=1
    cartella=$(dirname "$VENDOR_DA_AGGIORNARE")
    { mkdir -p "$cartella" && chmod 0700 "$cartella"; } 2>/dev/null || true
    # Le graffe servono: in `: > segno 2>/dev/null` le redirezioni si applicano
    # da sinistra, e l'errore della prima usciva prima che la seconda
    # zittisse stderr. Nel registro restava la riga grezza di bash
    # («No such file or directory») sopra l'avviso.
    { : > "$VENDOR_DA_AGGIORNARE"; } 2>/dev/null \
        || avviso "non riesco a scrivere $VENDOR_DA_AGGIORNARE: se il rilascio esce prima del passo 2-bis, il vendor dell'host resta indietro."
    return 0
}

# Fra il `fetch` e il `reset --hard` del passo 1: composer.json o composer.lock
# cambiano fra il commit di prima e quello nuovo?
segna_vendor() {
    local prima="$1" dopo="$2" cambiati
    if [ "$prima" = "$dopo" ]; then return 0; fi
    if ! cambiati=$(git_come_proprietario diff --name-only "$prima" "$dopo" 2>/dev/null); then
        # «Non ho potuto guardare» non è «non è cambiato»: un install in più
        # costa un minuto, un vendor indietro non lo dice nessuno.
        avviso "non riesco a confrontare ${prima:0:8} e ${dopo:0:8}: aggiorno comunque il vendor dell'host."
    elif ! grep -qxE 'composer\.(json|lock)' <<<"$cambiati"; then
        return 0
    fi
    lascia_segno_vendor
}

# Il passo 2-bis: dopo l'immagine, prima dell'istantanea e delle migrazioni.
# Come le migrazioni: da $UTENTE_GIT, nella cartella del sorgente.
passo_vendor() {
    local esito=0 perche inizio
    if [ -z "$VENDOR_DA_FARE" ] && [ ! -f "$VENDOR_DA_AGGIORNARE" ]; then
        nota "composer.json e composer.lock non sono cambiati: il vendor dell'host resta com'è"
        return 0
    fi
    if [ -n "$VENDOR_DA_FARE" ]; then
        nota "composer.json o composer.lock sono cambiati: aggiorno il vendor dell'host"
    else
        nota "un rilascio precedente non ha aggiornato il vendor dell'host: lo aggiorno adesso"
    fi
    # `timeout` esce 124 quando ferma il comando con SIGTERM, 137 quando dopo
    # altri quindici secondi serve SIGKILL. Ma 137 esce anche quando composer
    # lo uccide qualcun altro con SIGKILL (l'OOM killer), molto prima del
    # tetto: il tetto si nomina solo se il tempo passato lo ha raggiunto.
    inizio=$SECONDS
    sudo -u "$UTENTE_GIT" bash -c "cd '$REPO_DIR' && exec timeout --kill-after=15 '$COMPOSER_TETTO' composer install --no-dev --no-interaction --prefer-dist" 2>&1 \
        | sed 's/^/[composer] /' || esito=$?
    if [ "$esito" -ne 0 ]; then
        case "$esito" in
            124) perche="non ha finito in $COMPOSER_TETTO secondi ed è stato fermato (uscita $esito)" ;;
            137)
                if [ $((SECONDS - inizio)) -ge "$COMPOSER_TETTO" ]; then
                    perche="non ha finito in $COMPOSER_TETTO secondi ed è stato fermato (uscita $esito)"
                else
                    perche="è stato ucciso con SIGKILL dopo $((SECONDS - inizio)) secondi, prima del tetto: memoria finita? (uscita $esito)"
                fi
                ;;
            *) perche="è fallito (uscita $esito)" ;;
        esac
        avviso "composer install $perche: il vendor dell'host è quello di prima, o a metà."
        avviso "  il rilascio prosegue, e il rilascio dopo ci riprova. Il motivo è nelle righe [composer] qui sopra."
        anomalia_rilascio vendor_host "Il rilascio di ${COMMIT:0:8} non ha aggiornato il vendor dell'host: composer install $perche. Migrazioni, lavori a orario e avviso di guasto usano quello di prima, o uno a metà. Il rilascio dopo ci riprova da solo. Dettagli nel giornale di pantedu-deploy."
        lascia_segno_vendor
        return 0
    fi
    rm -f "$VENDOR_DA_AGGIORNARE" 2>/dev/null || true
    VENDOR_DA_FARE=""
    nota "  vendor dell'host aggiornato"
    return 0
}

# Il passo 8-ter: dopo la verifica attraverso nginx, a ogni rilascio.
# sync_versions.php confronta e scrive solo quello che diverge, e non cancella
# niente. Da $UTENTE_GIT, come le migrazioni e come faceva deploy.sh.
passo_versioni_legali() {
    local esito=0
    if [ ! -f "$REPO_DIR/tools/legal/sync_versions.php" ]; then
        avviso "tools/legal/sync_versions.php non c'è nel sorgente: le versioni legali del database non si allineano."
        anomalia_rilascio legal_versioni "Il rilascio di ${COMMIT:0:8} non trova tools/legal/sync_versions.php: le versioni legali del database non si allineano a docs/legal/versions.json."
        return 0
    fi
    nota "versioni legali: allineo il database a docs/legal/versions.json"
    sudo -u "$UTENTE_GIT" bash -c "cd '$REPO_DIR' && php tools/legal/sync_versions.php --apply" 2>&1 \
        | sed 's/^/[legal] /' || esito=$?
    if [ "$esito" -eq 0 ]; then
        nota "  versioni legali allineate"
        return 0
    fi
    avviso "l'allineamento delle versioni legali è fallito (uscita $esito): il motivo è nelle righe [legal] qui sopra."
    avviso "  il sito serve, ma il cancello dei Termini può non conoscere le versioni nuove. Ci riprova il rilascio dopo; a mano: php tools/legal/sync_versions.php --apply, come $UTENTE_GIT."
    anomalia_rilascio legal_versioni "Il rilascio di ${COMMIT:0:8} non ha allineato legal_document_versions a docs/legal/versions.json: sync_versions.php è uscito $esito, e il cancello dei Termini può non conoscere le versioni nuove. Ci riprova il rilascio dopo. Dettagli nel giornale di pantedu-deploy."
    return 0
}

# Il passo 8-quater: i modelli TikZ versionati in storage/templates/tikz nella
# biblioteca dell'istanza (ADR-050, 24/9/2026), a ogni rilascio. Dentro il
# container NUOVO e come www-data, come la diagnostica: è l'identità del
# pannello admin, che scrive lo stesso file, e il codice è quello appena
# messo in servizio. Crea quel che manca, sostituisce solo le versioni che il
# manifesto dichiara superate, non tocca quel che l'amministratore ha cambiato
# dal pannello. Dopo lo scambio, come le versioni legali: uno scambio annullato
# non deve lasciare nell'istanza modelli che il codice in servizio non conosce.
passo_modelli_tikz() {
    local esito=0
    nota "modelli TikZ: porto nella biblioteca quelli di storage/templates/tikz"
    docker exec -u www-data "$NUOVO" \
        php /var/www/pantedu/tools/tikz/sincronizza_modelli.php --apply 2>&1 \
        | sed 's/^/[tikz] /' || esito=$?
    if [ "$esito" -eq 0 ]; then
        nota "  modelli TikZ allineati"
        return 0
    fi
    avviso "l'allineamento dei modelli TikZ è fallito (uscita $esito): il motivo è nelle righe [tikz] qui sopra."
    avviso "  il sito serve con la biblioteca di prima. Ci riprova il rilascio dopo; a mano: docker exec -u www-data <container> php /var/www/pantedu/tools/tikz/sincronizza_modelli.php --apply"
    anomalia_rilascio modelli_tikz "Il rilascio di ${COMMIT:0:8} non ha portato i modelli TikZ versionati nella biblioteca: sincronizza_modelli.php è uscito $esito. Ci riprova il rilascio dopo. Dettagli nel giornale di pantedu-deploy."
    return 0
}
# <<< host ────────────────────────────────────────────────────────────────

# shellcheck disable=SC2034  # usata dentro il `trap` qui sotto, che sta fra
# apici singoli: shellcheck non ci guarda dentro.
INIZIO=$(date +%s)
# `U=$?` va PRESA PER PRIMA: `$?` è l'esito dell'ultimo comando, e
# `DURATA=$(( $(date ...) ))` è un comando. Scritta nell'altro ordine — com'era
# al primo giro — la trappola leggeva l'esito di `date`, cioè sempre zero, e
# stampava «FINE, tutto bene» mentre l'unità systemd risultava `failed`. Due
# righe che si contraddicono in un registro sono peggio di una riga sola: chi
# legge crede a quella che lo rassicura.
#
# `FINE_NOTA` c'è per l'uscita buona che però non rilascia niente (il passo 2
# che rimanda al differito): «tutto bene» lì sarebbe la riga che rassicura a
# torto.
trap 'U=$?; DURATA=$(( $(date +%s) - INIZIO ));
      if [ "$U" -eq 0 ]; then echo "[$(ts)] [rilascio] === FINE, ${FINE_NOTA:-tutto bene} (${DURATA}s) ===";
      else echo "[$(ts)] [rilascio] === FINE, FALLITO con $U (${DURATA}s) ==="; fi' EXIT

# ── Un rilascio alla volta ────────────────────────────────────────────────
#
# systemd serializza le sue esecuzioni (`Type=oneshot`, e l'unità `.path` non
# ritrigghera finché il servizio non è inattivo), ma non protegge da un
# lancio a mano che arrivi nello stesso momento. È successo l'8 settembre 2026:
# un `systemctl reset-failed` ha sbloccato l'unità proprio mentre lanciavo lo
# script a mano, e due `git reset --hard` più due build sullo stesso albero
# hanno prodotto un container che leggeva una configurazione a metà — con un
# messaggio, «Database disabled via config», che mandava a cercare tutt'altro.
#
# `flock -n`: chi arriva secondo non aspetta. Aspettare vorrebbe dire
# rilasciare due volte di fila lo stesso commit.
#
# Ma non basta uscire: chi arriva secondo **lascia un biglietto**. Senza,
# la sua unione sparisce senza che nessuno lo sappia — è successo il 9
# settembre 2026 (vedi il passo 10 in fondo). Il timer
# `pantedu-deploy-differito` guarda quel file ogni dieci minuti.
SEGNAPOSTO=/var/lib/pantedu-deploy/in-attesa

LUCCHETTO=/var/lock/pantedu-rilascio.lock
exec 9>"$LUCCHETTO"
if ! flock -n 9; then
    mkdir -p "$(dirname "$SEGNAPOSTO")" 2>/dev/null || true
    : > "$SEGNAPOSTO" 2>/dev/null || true
    errore "c'è già un rilascio in corso: lascio il biglietto, ci pensa il differito entro dieci minuti."
fi

nota "=== INIZIO ==="

# ── 0. L'installazione dev'essere già passata ai container ────────────────
#
# C'è una finestra, durante il passaggio, in cui l'unità systemd punta già qui
# ma nginx serve ancora via php-fpm e il file dell'upstream non esiste. Un
# rilascio che partisse lì costruirebbe l'immagine, avvierebbe un container e
# poi scriverebbe un `upstream` che nessun vhost include: `nginx -t` fallirebbe
# e si tornerebbe indietro. Funziona, ma è lavoro sprecato e un allarme inutile.
#
# Meglio riconoscerlo e dirlo: il passaggio si fa con `passa-ai-container.sh`.
if ! grep -q 'proxy_pass http://pantedu_app' /etc/nginx/sites-available/pantedu.eu.conf 2>/dev/null; then
    errore "nginx non è ancora passato ai container: esegui prima $REPO_DIR/tools/webhook/passa-ai-container.sh (con --prova per guardare)."
fi

# ── 1. Quale commit ───────────────────────────────────────────────────────
nota "aggiorno il sorgente"
git_come_proprietario fetch --quiet origin "$RAMO"
COMMIT=$(git_come_proprietario rev-parse "origin/$RAMO")
PRIMA=$(git_come_proprietario rev-parse HEAD)

if [ "$COMMIT" = "$PRIMA" ] && [ -n "${FORZA:-}" ]; then
    nota "già su ${COMMIT:0:8}, ma FORZA è impostata: proseguo"
elif [ "$COMMIT" = "$PRIMA" ]; then
    nota "già su ${COMMIT:0:8}: niente da fare"
    exit 0
fi

# Le dipendenze PHP sono cambiate? Si guarda qui, PRIMA del reset e di ogni
# uscita anticipata, e se sì si lascia un segno che il passo 2-bis toglie solo
# a lavoro fatto (23/9/2026; il perché fra `>>> host` e `<<< host`).
segna_vendor "$PRIMA" "$COMMIT"

# Il sorgente serve comunque, anche nell'assetto a container: gli strumenti a
# riga di comando, l'avviso di guasto e le unità systemd stanno lì, e devono
# funzionare **anche quando il container è morto**. È il motivo per cui questa
# directory non sparisce.
git_come_proprietario reset --hard --quiet "origin/$RAMO"
nota "sorgente su ${COMMIT:0:8} (prima: ${PRIMA:0:8})"

# ── 1.bis. I permessi che `git reset --hard` porta via ────────────────────
#
# `git` gira come `pantedu` e ricrea i file come `pantedu:pantedu`, 0660. Sul
# tree servito questo non conta più — le pagine le serve il container, che ha
# la sua copia con i proprietari giusti. Ma due cose sull'host restano di
# `www-data`, e senza questa riparazione smettono di funzionare:
#
#   - `.env`, che è **versionato** (i segreti stanno in `.env.local`) e viene
#     quindi riscritto a ogni reset. È montato nel container: se `www-data` non
#     lo legge, il container parte, supera i primi controlli — che leggono
#     `.env.local` e le variabili d'ambiente — e poi dichiara «Database
#     disabled via config», che manda a cercare tutt'altro. È successo l'8
#     settembre 2026, due rilasci di fila;
#   - `tools/webhook/github.php`, che php-fpm dell'host esegue come `www-data`
#     per ricevere il webhook. (23/9/2026: qui c'era scritto «e tutto ciò che
#     serve a eseguirlo, `app/`, `vendor/`, `config/`», ma il file è autonomo e
#     non carica niente del repository.) Senza permessi, il webhook risponde
#     **403** e l'auto-rilascio muore in silenzio: se ne accorge solo chi
#     spinge la volta dopo e non vede succedere niente.
#
# Il blocco è preso da `deploy.sh`, dove esiste da maggio per la stessa
# ragione. È idempotente: gira sempre, anche quando non serve.
#
# 2026-09-09 — qui si riscrive anche `storage/version.txt` sull'host.
#
# Nell'assetto a container il file dentro l'immagine è quello che conta, e lo
# scrive il build. Ma sull'host ne resta uno vecchio, scritto dall'ultimo
# rilascio del vecchio assetto: il 9 settembre diceva `dbcfe63c` mentre in
# servizio c'era `f29b64fc`. Nessuno lo leggeva, ed è proprio il problema — un
# file che dichiara una versione sbagliata e non fa danni **finché** qualcuno
# non lo crede. Costa una riga tenerlo onesto.
nota "rimetto i permessi che il reset porta via"
mkdir -p "$REPO_DIR/storage"
printf '%s\n' "$COMMIT" > "$REPO_DIR/storage/version.txt"
chgrp www-data "$REPO_DIR/storage/version.txt" 2>/dev/null || true
chmod 0644 "$REPO_DIR/storage/version.txt" 2>/dev/null || true
chgrp www-data "$REPO_DIR/.env" 2>/dev/null || true
chmod 0640 "$REPO_DIR/.env" 2>/dev/null || true
for d in app routes database public config tools vendor views schemas docs; do
    [ -d "$REPO_DIR/$d" ] || continue
    chgrp -R www-data "$REPO_DIR/$d" 2>/dev/null || true
    # Gli `.sh` restano eseguibili. Scritto senza questa distinzione — cioè
    # `chmod 0644` su tutto — il rilascio toglieva il bit di esecuzione a ogni
    # script del repository. Il cron delle 03:00 chiama
    # `tools/crypto/backup_teacher_keys.sh` per percorso, quindi da quel
    # momento rispondeva «Permission denied» e finiva in un file di registro
    # che non legge nessuno: **il salvataggio delle chiavi dei docenti è
    # rimasto fermo dal 18 agosto al 9 settembre 2026**, tre settimane, senza
    # che niente lo segnalasse.
    #
    # `deploy.sh` non aveva il problema perché elenca le estensioni da
    # toccare e `*.sh` non c'è. Qui l'elenco non c'era.
    find "$REPO_DIR/$d" -type f ! -name '*.sh' -exec chmod 0644 {} + 2>/dev/null || true
    find "$REPO_DIR/$d" -type f -name '*.sh' -exec chmod 0755 {} + 2>/dev/null || true
    find "$REPO_DIR/$d" -type d -exec chmod 0755 {} + 2>/dev/null || true
done
find "$REPO_DIR" -maxdepth 1 -type f \( -name '*.php' -o -name '*.json' \) \
    -exec chgrp www-data {} + 2>/dev/null || true

# La verifica, non l'assunzione: è lo stesso errore di stamattina — controllare
# come l'utente che legge tutto invece che come quello che serve le pagine.
sudo -u www-data test -r "$REPO_DIR/.env" \
    || errore "www-data non legge $REPO_DIR/.env dopo la riparazione: non rilascio."

# ── 1.ter. Questo script si aggiorna da sé, e lo fa SUBITO ────────────────
#
# Al primo giro l'aggiornamento stava in fondo, dopo lo scambio. Sembrava
# prudente e invece era la trappola: se il rilascio falliva — cioè proprio
# quando la correzione serviva — lo script nuovo non veniva mai installato, e il
# rilascio dopo ripeteva lo stesso errore con la stessa versione vecchia. È
# successo due volte di fila l'8 settembre 2026.
#
# Perché si può fare adesso senza rompersi a metà: bash legge lo script mentre
# lo esegue, quindi riscriverlo in luogo lo corromperebbe. Qui si scrive un file
# nuovo e lo si **rinomina** sopra: il rename è atomico e cambia l'inode, mentre
# il processo in corso continua a leggere quello che aveva aperto. La versione
# nuova vale dal rilascio successivo, come prima — ma è installata anche se
# questo fallisce.
SE_STESSO="/usr/local/bin/pantedu-deploy-container.sh"
SORGENTE_DI_SE="$REPO_DIR/tools/webhook/deploy-container.sh"
if [ -f "$SORGENTE_DI_SE" ] && ! cmp -s "$SORGENTE_DI_SE" "$SE_STESSO"; then
    TEMPORANEO="${SE_STESSO}.nuovo.$$"
    install -m 755 -o root -g root "$SORGENTE_DI_SE" "$TEMPORANEO"
    mv -f "$TEMPORANEO" "$SE_STESSO"
    nota "questo script è stato aggiornato: la versione nuova vale dal prossimo rilascio."
fi

# ── 2. L'immagine ─────────────────────────────────────────────────────────
# Due modi, e si prova prima quello giusto.
#
# Dal registro: l'immagine è quella che la CI ha costruito **e provato**, byte
# per byte. È il modo che rende vero «costruisci una volta»: se una dipendenza
# cambia sotto, se ne accorge la CI e non la produzione.
#
# Costruita qui: ripiego. Funziona, dà comunque lo scambio atomico e il ritorno
# indietro immediato, ma il build torna a dipendere da questa macchina e da
# questa rete. Serve finché non c'è un gettone di lettura del registro in
# `/etc/pantedu-deploy.env` (`GHCR_TOKEN`, con `GHCR_USER`).
ETICHETTA="$IMMAGINE:$COMMIT"
ORIGINE="?"
# Perché si arriva al ripiego. Prima l'avviso diceva sempre «servono GHCR_USER
# e GHCR_TOKEN», anche quando le credenziali c'erano e mancava solo l'immagine:
# il 12 settembre 2026 ha mandato a cercare un guasto che non c'era.
MOTIVO_RIPIEGO="mancano GHCR_USER e GHCR_TOKEN in $SEGRETI"

# shellcheck source=/dev/null
[ -r "$SEGRETI" ] && . "$SEGRETI"

if [ -n "${GHCR_TOKEN:-}" ] && [ -n "${GHCR_USER:-}" ]; then
    nota "provo a scaricare $ETICHETTA dal registro"
    MOTIVO_RIPIEGO="l'immagine non è arrivata nel registro in sette minuti"
    GITHUB_MUTO=""
    if printf '%s' "$GHCR_TOKEN" | docker login ghcr.io -u "$GHCR_USER" --password-stdin >/dev/null 2>&1; then
        # La CI costruisce mentre questo gira, e ci mette circa quattro minuti:
        # il webhook arriva pochi secondi dopo l'unione, quindi qui si aspetta
        # quasi sempre. Sette minuti di margine, non dieci: il timeout
        # dell'unità systemd è di quindici, e dopo l'attesa può ancora servire
        # un build locale di ripiego (fino a quattro). Dieci più quattro
        # sfioravano il tetto, e un rilascio ucciso dal timeout è il modo
        # peggiore di fallire — a metà, senza che nessuno rimetta a posto.
        #
        # Si parla ogni minuto: un registro muto per sette minuti sembra un
        # blocco, e chi legge non sa se aspettare o intervenire.
        for tentativo in $(seq 1 28); do
            if docker pull --quiet "$ETICHETTA" >/dev/null 2>&1; then
                ORIGINE="registro"
                break
            fi
            [ "$tentativo" -eq 1 ] && nota "l'immagine non c'è ancora: la CI la sta costruendo, aspetto (max 7 min)"

            # 2026-09-12 — `main` è andato avanti mentre si aspetta?
            #
            # `immagine.yml` ha `cancel-in-progress`: una spinta nuova su `main`
            # ANNULLA il build dell'immagine precedente. Quella che si sta
            # aspettando qui, allora, non arriverà mai. La notte del 12
            # settembre, due unioni a sei secondi di distanza: il rilascio della
            # prima ha aspettato tutti e sette i minuti un'immagine annullata al
            # sedicesimo secondo, poi l'ha costruita sul VPS — la macchina da
            # 3,7 GB che intanto serve il sito — e ci ha messo 487 secondi
            # invece di 130. La seconda unione è arrivata comunque, col
            # biglietto del passo 10, ma dopo undici minuti.
            #
            # Adesso, se l'immagine non c'è e `main` è andato avanti, non si
            # aspetta: si lascia il biglietto e si esce, e il differito rilascia
            # la punta di `main` con l'immagine che la CI sta davvero
            # costruendo. Si esce **prima** dell'istantanea e delle migrazioni:
            # il database non è stato toccato, e il container in servizio è
            # ancora quello di prima.
            #
            # Prima si prova a scaricare, poi si chiede a GitHub: se l'immagine
            # c'è, si rilascia questo commit anche se `main` è andato avanti
            # (lo prenderà il passo 10). Se GitHub non risponde si continua ad
            # aspettare come prima, e lo si dice una volta sola.
            PUNTA=$(git_come_proprietario ls-remote origin "refs/heads/$RAMO" 2>/dev/null | cut -f1 || true)
            if [ -z "$PUNTA" ]; then
                [ -z "$GITHUB_MUTO" ] && nota "  (non riesco a chiedere a GitHub dov'è $RAMO: continuo ad aspettare)"
                GITHUB_MUTO=1
            elif [ "$PUNTA" != "$COMMIT" ]; then
                mkdir -p "$(dirname "$SEGNAPOSTO")" 2>/dev/null || true
                : > "$SEGNAPOSTO" 2>/dev/null || true
                avviso "$RAMO è già a ${PUNTA:0:8}: l'immagine di ${COMMIT:0:8} non arriverà (la CI annulla il build quando arriva una spinta nuova)."
                avviso "  non la aspetto e non la costruisco: biglietto lasciato, il differito rilascia ${PUNTA:0:8} entro dieci minuti."
                FINE_NOTA="rimandato al rilascio di ${PUNTA:0:8}, niente è cambiato"
                exit 0
            fi

            [ $((tentativo % 4)) -eq 0 ] && nota "  ancora niente dopo $((tentativo / 4)) minuti…"
            sleep 15
        done
    else
        MOTIVO_RIPIEGO="le credenziali del registro in $SEGRETI sono state rifiutate"
        avviso "credenziali del registro rifiutate"
    fi
fi

if [ "$ORIGINE" = "?" ]; then
    avviso "costruisco l'immagine QUI: è il ripiego, non il modo giusto."
    avviso "perché: $MOTIVO_RIPIEGO."
    docker build -f "$REPO_DIR/docker/Dockerfile" -t "$ETICHETTA" \
        --build-arg "COMMIT=$COMMIT" "$REPO_DIR" \
        || errore "il build dell'immagine è fallito: non tocco niente."
    ORIGINE="costruita qui"
fi
nota "immagine pronta ($ORIGINE)"

# ── 2-bis. Il vendor/ dell'host, se le dipendenze PHP sono cambiate ────────
#
# 23/9/2026. Qui e non dopo le migrazioni: `tools/migrate.php` carica il
# vendor/ dell'host, e con quello vecchio una dipendenza nuova le farebbe
# fallire — fermando il rilascio prima che questo passo possa girare. Prima
# dell'istantanea, perché fra l'istantanea e le migrazioni passi meno tempo
# possibile. Le funzioni e il resto del perché fra `>>> host` e `<<< host`.
passo_vendor

# ── 3. Istantanea del database ────────────────────────────────────────────
# Prima delle migrazioni, sempre. Una migrazione che va male senza istantanea
# non è un disservizio: è una perdita.
#
# Si usa la strada che `deploy.sh` percorre da maggio: `mysqldump` con
# `--defaults-file` esplicito. Il primo giro di questo script ne aveva inventata
# una nuova — leggere le credenziali dall'applicazione e passarle a
# `mariadb-dump` — e non ha funzionato al primo rilascio vero. Non c'era motivo
# di inventarla: qui non è cambiato niente rispetto a prima.
#
# `--defaults-file` esplicito e non l'automatismo di `$HOME/.my.cnf`: sotto
# systemd l'ambiente è ripulito e `mysqldump` ripiegava su un accesso anonimo
# («using password: NO»), che il database rifiuta.
#
# **Qui ci si ferma**, mentre `deploy.sh` proseguiva marcandosi fallito. La
# differenza è l'assetto: là il codice nuovo era già in servizio e fermarsi
# avrebbe lasciato il sito peggio di com'era; qui non è ancora cambiato niente,
# e il container vecchio continua a servire. Fermarsi è gratis.
PRIMA_DEL_RILASCIO="/var/backups/pantedu/pre-deploy"
DEFAULTS_MYSQL="/root/.my.cnf"
mkdir -p "$PRIMA_DEL_RILASCIO"
chmod 700 "$PRIMA_DEL_RILASCIO"
SNAP="${PRIMA_DEL_RILASCIO}/db-pre-deploy-$(date +%Y%m%d_%H%M%S).sql.gz"

nota "istantanea del database → $SNAP"
[ -r "$DEFAULTS_MYSQL" ] \
    || errore "$DEFAULTS_MYSQL non leggibile: senza istantanea non rilascio."

if mysqldump --defaults-file="$DEFAULTS_MYSQL" --single-transaction --quick \
        --routines --triggers --events pantedu 2>/dev/null | gzip > "$SNAP"; then
    nota "  istantanea fatta ($(du -h "$SNAP" | awk '{print $1}'))"
    # Se ne tengono cinque: ~3 MB in tutto, e più indietro di così non si torna.
    # shellcheck disable=SC2012  # i nomi sono marche temporali: niente spazi.
    ls -t "$PRIMA_DEL_RILASCIO"/db-pre-deploy-*.sql.gz 2>/dev/null | tail -n +6 | xargs -r rm
else
    rm -f "$SNAP"
    errore "istantanea fallita: non rilascio senza rete sul database."
fi

# ── 4. Migrazioni ─────────────────────────────────────────────────────────
# Le fa il rilascio, UNA volta, prima che il container nuovo parta. Non
# l'entrypoint: durante uno scambio due container partono a distanza di secondi
# e due processi che migrano insieme lo stesso database sono un modo eccellente
# di romperlo.
nota "migrazioni"
sudo -u "$UTENTE_GIT" bash -c "cd '$REPO_DIR' && php tools/migrate.php" \
    || errore "migrazioni fallite: il container nuovo non parte."

# ── 5. Il container nuovo, su una porta libera ────────────────────────────
if docker ps --format '{{.Names}}' | grep -qx "$NOME_A"; then
    VECCHIO="$NOME_A"; NUOVO="$NOME_B"; PORTA_NUOVA="$PORTA_B"; PORTA_VECCHIA="$PORTA_A"
else
    VECCHIO="$NOME_B"; NUOVO="$NOME_A"; PORTA_NUOVA="$PORTA_A"; PORTA_VECCHIA="$PORTA_B"
fi
nota "il nuovo sarà $NUOVO sulla porta $PORTA_NUOVA (il vecchio è $VECCHIO)"

docker rm -f "$NUOVO" >/dev/null 2>&1 || true

# `-p 127.0.0.1:` e non `-p` nudo: Docker scrive le proprie regole iptables
# scavalcando ufw, e una porta pubblicata senza indirizzo sarebbe raggiungibile
# da Internet anche se ufw la nega. Qui la vede solo nginx.
#
# ── Che cosa può fare chi entra ───────────────────────────────────────────
#
# Il container non è la difesa contro l'intrusione — quella sta davanti (WAF,
# nginx, fail2ban). È la difesa contro **cosa succede dopo**: dato per
# scontato che prima o poi qualcuno esegua codice dentro il PHP, quanto
# lontano arriva?
#
# **Via il montaggio di `/var/lib/pantedu-deploy`.** Era scrivibile, e systemd
# sorveglia `/var/lib/pantedu-deploy/trigger` per far partire il rilascio —
# che gira **come root sull'host**. Un'esecuzione di codice nel PHP poteva
# quindi far ripartire il rilascio a piacere.
#
# Il danno sarebbe stato limitato, e questo va detto per onestà: il ramo è
# fisso nel codice (`RAMO="main"`, in cima), non letto dal trigger, quindi si
# sarebbe ridistribuito il main legittimo — un disturbo e uno spreco di
# risorse, non l'esecuzione di codice dell'attaccante. Ma era una leva verso
# root che non serviva a niente: il webhook che scrive quel file gira
# sull'host (`fastcgi_pass unix:/run/php/php8.4-fpm.sock` su `/_hooks/github`),
# non qui dentro, e **nessuna riga dell'applicazione legge quella cartella**.
# Verificato prima di toglierla.
#
# **`no-new-privileges`**: dentro, php-fpm e nginx partono come root e
# scaricano i figli su `www-data`. Questo non lo impedisce — è una `setuid()`
# fatta da un processo già root — mentre impedisce l'opposto: che da
# `www-data` si torni su per mezzo di un binario setuid dell'immagine.
#
# **`--pids-limit 256`**: in condizioni normali dentro ci sono sedici
# processi (dieci figli php-fpm, due worker nginx, tre master, supervisord).
# Sedici volte di margine, e una fork bomb non porta giù la macchina.
#
# **Le capacità tolte** sono quelle che nessuno qui usa. `NET_RAW` è la più
# importante: dà i socket grezzi, cioè la possibilità di annusare e falsificare
# traffico sulla rete del container. Le altre — creare nodi di dispositivo,
# `chroot`, scrivere nel registro di audit, manipolare le capacità dei file —
# sono strumenti da attaccante e da nessun altro.
#
# Restano `SETUID`, `SETGID`, `CHOWN`, `DAC_OVERRIDE` e `FOWNER`, che servono
# a php-fpm e nginx per fare esattamente il lavoro descritto sopra.
#
# **Il socket di MariaDB con `--mount`, non con `-v`** (15/9/2026). Con `-v`,
# se il socket manca Docker crea al suo posto una **cartella**; con `--mount` si
# ferma con un errore e non crea niente (misurato sul VPS, Docker Engine
# 29.8.0). Al riavvio del 15 settembre Docker è partito insieme a MariaDB, ha
# riavviato il container (`--restart unless-stopped`) prima che il socket
# esistesse, e la cartella ha impedito a MariaDB di partire: sito e database
# giù. L'ordine all'avvio lo mette `tools/systemd/docker.service.d/pantedu-dopo-mariadb.conf`;
# questa riga fa sì che, se l'ordine non bastasse, si fermi solo il container e
# non anche il database. La guardia `tools/ci/check-deploy-units.mjs` impedisce
# che `-v` torni.
docker run -d \
    --name "$NUOVO" \
    --restart unless-stopped \
    -p "127.0.0.1:$PORTA_NUOVA:8080" \
    --add-host host.docker.internal:host-gateway \
    -v "$DATI:/var/lib/pantedu-data" \
    -v "$REPO_DIR/.env:/var/www/pantedu/.env:ro" \
    -v "$REPO_DIR/.env.local:/var/www/pantedu/.env.local:ro" \
    --mount type=bind,src=/run/mysqld/mysqld.sock,dst=/run/mysqld/mysqld.sock \
    -e PANTEDU_DATA_PATH=/var/lib/pantedu-data \
    -e DB_SOCKET=/run/mysqld/mysqld.sock \
    --memory 1g \
    --pids-limit 256 \
    --security-opt no-new-privileges \
    --cap-drop NET_RAW \
    --cap-drop MKNOD \
    --cap-drop SYS_CHROOT \
    --cap-drop AUDIT_WRITE \
    --cap-drop SETFCAP \
    --cap-drop SETPCAP \
    --health-start-period 90s \
    "$ETICHETTA" >/dev/null \
    || errore "il container nuovo non è partito: non ho toccato niente."

ferma_il_nuovo() {
    avviso "fermo $NUOVO; $VECCHIO continua a servire"
    docker logs --tail 40 "$NUOVO" 2>&1 | sed 's/^/    /' >&2 || true
    docker rm -f "$NUOVO" >/dev/null 2>&1 || true
}

# ── 6. Si dichiara sano? ──────────────────────────────────────────────────
nota "aspetto che $NUOVO si dichiari sano"
SANO=0
for tentativo in $(seq 1 40); do
    STATO=$(docker inspect -f '{{.State.Health.Status}}' "$NUOVO" 2>/dev/null || echo "assente")
    case "$STATO" in
        healthy) SANO=1; nota "sano dopo $tentativo tentativi"; break ;;
        unhealthy) avviso "si è dichiarato malato"; break ;;
        assente) avviso "il container non c'è più"; break ;;
    esac
    sleep 3
done

if [ "$SANO" -ne 1 ]; then
    ferma_il_nuovo
    errore "il container nuovo non è mai stato sano: niente scambio."
fi

# ── 7. Lo scambio ─────────────────────────────────────────────────────────
# Una riga riscritta e un `reload`. Il `reload` non chiude le connessioni in
# corso: i vecchi processi finiscono quello che stanno facendo e poi escono.
cp -a "$UPSTREAM" "${UPSTREAM}.prima" 2>/dev/null || true
cat > "$UPSTREAM" <<EOF
# Scritto da pantedu-deploy-container.sh il $(ts).
# Commit in servizio: $COMMIT
upstream pantedu_app { server 127.0.0.1:$PORTA_NUOVA; }
EOF

rimetti_upstream() {
    avviso "rimetto l'upstream com'era e ricarico"
    if [ -f "${UPSTREAM}.prima" ]; then
        mv "${UPSTREAM}.prima" "$UPSTREAM"
    else
        cat > "$UPSTREAM" <<EOF2
upstream pantedu_app { server 127.0.0.1:$PORTA_VECCHIA; }
EOF2
    fi
    nginx -t >/dev/null 2>&1 && systemctl reload nginx || true
}

if ! nginx -t >/dev/null 2>&1; then
    rimetti_upstream
    ferma_il_nuovo
    errore "la configurazione di nginx non regge lo scambio."
fi

systemctl reload nginx || { rimetti_upstream; ferma_il_nuovo; errore "reload di nginx fallito."; }
nota "scambiato: nginx parla con la porta $PORTA_NUOVA"

# ── 8. La verifica passa da nginx, non dal container ──────────────────────
# È la lezione dello scambio dell'8 settembre: il container rispondeva, ma
# nginx no. Un controllo che non passa da chi serve davvero le pagine non se ne
# sarebbe accorto. Intestazioni come le manda Cloudflare, per non farsi
# respingere dal blocco geografico del WAF.
sleep 2
GUASTE=""
for P in /login /health /accessibility; do
    C=$(curl -sk -o /dev/null -w '%{http_code}' \
        -H 'Host: pantedu.eu' -H 'X-Forwarded-For: 127.0.0.1' \
        --max-time 10 "https://127.0.0.1$P" 2>/dev/null || echo "000")
    nota "  $C  $P"
    case "$C" in 2*|3*) ;; *) GUASTE="$GUASTE $P($C)" ;; esac
done

if [ -n "$GUASTE" ]; then
    avviso "attraverso nginx non rispondono:$GUASTE"
    rimetti_upstream
    ferma_il_nuovo
    errore "scambio annullato, $VECCHIO è tornato a servire."
fi

# `/health` deve dire che il database c'è: `ok:true` da solo risponderebbe
# anche a un'applicazione senza configurazione. È il controllo che stamattina
# è mancato per dieci minuti.
SALUTE=$(curl -sk -H 'Host: pantedu.eu' -H 'X-Forwarded-For: 127.0.0.1' \
    --max-time 10 https://127.0.0.1/health 2>/dev/null || echo "")
if ! echo "$SALUTE" | grep -q '"db":true'; then
    avviso "/health non dichiara il database collegato: $SALUTE"
    rimetti_upstream
    ferma_il_nuovo
    errore "scambio annullato, $VECCHIO è tornato a servire."
fi
nota "verifica attraverso nginx: passata"

# ── 8-ante. Il servizio TeX, se in /opt non c'è il codice del repository ──
#
# Da qui in giù lo scambio non si annulla più: il container nuovo serve, e
# resta. È il motivo per cui questo passo sta **qui** e non prima della
# verifica, dov'era fino al 20/9/2026.
#
# Con la sincronizzazione prima del passo 8, uno scambio annullato
# (`rimetti_upstream` / `ferma_il_nuovo`) rimetteva in servizio il container
# vecchio mentre in /opt restava il codice TeX nuovo, riavviato: due versioni
# che non si erano mai viste insieme, e nessuno lo diceva. Adesso se lo scambio
# non regge il TeX non si tocca.
#
# Resta prima della domanda a /health/tex, qui sotto, che così guarda il
# servizio già riavviato: era la ragione della posizione di prima, e non si
# perde.
#
# Le funzioni e il resto del perché stanno in cima, fra `>>> tex` e `<<< tex`.
passo_tex

# Il container nuovo raggiunge il servizio TeX? (19/9/2026)
#
# Dall'8 settembre il container cercava il TeX sul proprio 127.0.0.1 e ogni
# compilazione avviata dal sito falliva: undici giorni, e questo passo
# rispondeva «passata» perché guardava solo pagine che il TeX non lo usano.
# /health/tex fa la domanda dal container, cioè da dove partono le
# compilazioni. Solo un avviso, come l'8-bis: un TeX irraggiungibile non deve
# impedire di rilasciare una correzione, e se dura lo ritrova la diagnostica
# dell'host, che manda la mail.
#
# 20/9/2026 — prima si butta l'esito in cache. Dal 20 settembre /health/tex
# tiene la risposta della sonda per trenta secondi (HealthController), perché
# è pubblico e una chiamata sincrona a ogni richiesta è una leva per tenere
# occupati tutti i processi di php-fpm. Quella cache sta nei dati d'istanza,
# che il container vecchio e quello nuovo condividono: senza questa riga, qui
# si leggerebbe la risposta di prima dello scambio.
rm -f "$DATI/storage/cache/health-tex.json" 2>/dev/null || true
TEX_SALUTE=$(curl -sk -H 'Host: pantedu.eu' -H 'X-Forwarded-For: 127.0.0.1' \
    --max-time 15 https://127.0.0.1/health/tex 2>/dev/null || echo "")
case "$TEX_SALUTE" in
    *'"tex":true'*)
        nota "  il container nuovo raggiunge il servizio TeX" ;;
    *'"tex":"non_configurato"'*)
        avviso "il container nuovo non ha la configurazione del TeX (TEX_COMPILE_ENDPOINT o TEX_COMPILE_SECRET vuoti)."
        avviso "  PDF, anteprime e TikZ non in cache non funzioneranno." ;;
    *)
        avviso "il container nuovo NON raggiunge il servizio TeX: ${TEX_SALUTE:-nessuna risposta da /health/tex}"
        avviso "  PDF, anteprime e TikZ non in cache falliranno. Lo scambio resta: cosa guardare in docs/ops/tex-dal-container.md." ;;
esac

# ── 8-bis. Gli invarianti, dentro il container ────────────────────────────
#
# La verifica qui sopra dice che il sito **risponde**. Non dice se risponde
# nello stato che dichiara: se il commit servito è quello giusto, se i file
# GeoIP configurati esistono davvero, se www-data legge la configurazione, se
# le migrazioni sul disco sono tutte applicate. Sono le cose che l'8 settembre
# erano rotte mentre il sito rispondeva 200.
#
# Gira **dentro il container** e **come www-data**: è l'unico posto e l'unico
# utente da cui la risposta significhi qualcosa. Un controllo dei permessi
# fatto da root, o fatto sull'host mentre l'applicazione vive altrove, non
# controlla niente — è la lezione dei due disservizi di quella mattina.
#
# Non annulla lo scambio: il container è sano e risponde, e tornare indietro
# per un database GeoIP scaduto sarebbe sproporzionato. Lascia un avviso qui e
# una riga `diagnostica_*` nel registro delle anomalie. La mail la manda il
# timer della diagnostica sull'host, che rifà gli stessi controlli (le righe
# `diagnostica_*` il controllo `anomalie` non le conta: le rivalutano i
# controlli stessi).
#
# 19/9/2026 — quella riga, dal container, non arrivava: il registro era 0640
# e il container scrive come www-data, che sta solo nel gruppo. Adesso il
# controllo `registro` lo dice, e Anomalia lo scrive in `docker logs`.
if docker exec "$NUOVO" test -f /var/www/pantedu/tools/ops/diagnostica.php 2>/dev/null; then
    nota "diagnostica degli invarianti (dentro $NUOVO, come www-data)"
    if docker exec -u www-data "$NUOVO" \
            php /var/www/pantedu/tools/ops/diagnostica.php 2>&1 | sed 's/^/[diag] /'; then
        nota "  gli invarianti reggono"
    else
        avviso "un invariante non regge: guarda le righe [diag] qui sopra."
        avviso "  il sito è su e risponde, ma qualcosa non è nello stato che dichiara."
    fi
fi

# ── 8-ter. Le versioni legali, adesso che lo scambio non si annulla più ────
#
# 23/9/2026. Dal passaggio ai container nessuno portava più nel database le
# versioni di docs/legal/versions.json. Qui e non subito dopo le migrazioni:
# uno scambio annullato rimetterebbe in servizio i testi vecchi accanto a un
# database che conosce già la versione nuova. Le righe dello script nel
# registro cominciano con [legal]; funzione e perché fra `>>> host` e
# `<<< host`.
passo_versioni_legali

# ── 8-quater. I modelli TikZ versionati, nella biblioteca dell'istanza ────
#
# 24/9/2026 (ADR-050). Le righe dello script cominciano con [tikz]; funzione
# e perché fra `>>> host` e `<<< host`.
passo_modelli_tikz

# ── 9. Via il vecchio ─────────────────────────────────────────────────────
rm -f "${UPSTREAM}.prima"
if docker ps -a --format '{{.Names}}' | grep -qx "$VECCHIO"; then
    nota "fermo $VECCHIO"
    docker rm -f "$VECCHIO" >/dev/null 2>&1 || avviso "non sono riuscito a fermare $VECCHIO"
fi

# ── Pulizia, con numeri misurati ──────────────────────────────────────────
#
# Un disco pieno su questa macchina non è un fastidio: è il sito giù. E i
# numeri veri, misurati l'8 settembre 2026 dopo un pomeriggio di rilasci, sono
# più grossi di quanto sembri: **12 GB in poche ore**, di cui 10 di sola cache
# di build. Le immagini contano meno di quanto dica la loro dimensione nominale
# (1,2 GB l'una) perché condividono gli strati: sei ne occupavano 3,4.
#
# Quindi si potano due cose diverse, con criteri diversi:
#
#   - le **immagini di pantedu**: si tengono le ultime cinque, che è la
#     finestra entro cui ha senso tornare indietro. Non si usa
#     `docker image prune -a`, che porterebbe via anche le immagini di base
#     (php, node, composer): sono vecchie per definizione e riscaricarle a
#     ogni rilascio è tempo buttato;
#   - la **cache di build**: tetto a 3 GB. È solo un acceleratore — perderla
#     costa un build più lento, non un rilascio fallito.
QUANTE_TENERE=5
# `docker images` elenca già dalla più recente. Il `sort -k2 -r` che c'era qui
# ordinava per il campo della data — identico per tutte le immagini dello stesso
# giorno — e scombinava quell'ordine: dopo la potatura ne restavano sette invece
# di cinque. Meno passaggi, meno modi di sbagliare.
docker images "$IMMAGINE" --format '{{.ID}}' 2>/dev/null \
    | tail -n "+$((QUANTE_TENERE + 1))" \
    | xargs -r docker rmi >/dev/null 2>&1 || true
docker image prune -f >/dev/null 2>&1 || true

# `--keep-storage` è stato rinominato `--max-used-space` in Docker 29. Si prova
# il nome nuovo e si ripiega sul vecchio: uno script di rilascio non deve
# dipendere dalla versione di Docker installata sulla macchina. E se falliscono
# entrambi lo dice, invece di lasciare che un `|| true` muto nasconda che la
# potatura non stava avvenendo affatto.
docker builder prune -f --max-used-space 3GB >/dev/null 2>&1 \
    || docker builder prune -f --keep-storage 3GB >/dev/null 2>&1 \
    || avviso "non sono riuscito a potare la cache di build: controlla lo spazio."

LIBERI=$(df -BG --output=avail / | tail -1 | tr -dc '0-9')
nota "disco: ${LIBERI} GB liberi"
if [ "${LIBERI:-99}" -lt 10 ]; then
    avviso "meno di 10 GB liberi: il prossimo rilascio potrebbe non avere spazio per costruire."
fi

nota "in servizio: ${COMMIT:0:8} ($ORIGINE) su $NUOVO"
nota "per tornare indietro a mano:"
nota "    docker run -d --name pantedu-app-manuale -p 127.0.0.1:$PORTA_VECCHIA:8080 ... $IMMAGINE:<commit-di-prima>"
nota "    poi riscrivi $UPSTREAM sulla porta $PORTA_VECCHIA e 'systemctl reload nginx'"

# ── 10. Main si è mosso mentre rilasciavamo? ──────────────────────────────
#
# Il caso che ha fatto scrivere questo blocco, il 9 settembre 2026: due
# unioni a pochi secondi di distanza. La prima ha fatto partire il rilascio;
# la seconda ha scritto il suo segnale, ma l'unità `.path` **non accoda
# mentre il servizio gira**, quindi non è successo niente. Nessun errore,
# nessuna riga: `main` a `4f6b144a` e in produzione `ae1f6fbd`, divergenti in
# silenzio finché qualcuno non spingeva di nuovo.
#
# È la forma peggiore della famiglia: non un controllo che non misura, ma un
# rilascio che non avviene e non lo dice.
#
# Qui si guarda se il ramo è andato avanti mentre lavoravamo. Se sì, si lascia
# il biglietto che il timer `pantedu-deploy-differito` raccoglie entro dieci
# minuti. Non si rilascia subito da dentro: si è ancora dentro il lucchetto, e
# una ricorsione qui è un modo elegante di riempire il disco.
#
# 2026-09-12 — **questo blocco, fino a oggi, non poteva scattare.** Chiedeva
# `rev-parse origin/main`, che legge la copia LOCALE del riferimento remoto:
# quella che il `fetch` del passo 1 aveva appena scritto. Fra il passo 1 e
# questo nessuno rifà `fetch`, quindi `DOPO` era sempre uguale a `COMMIT` e
# la risposta era sempre «main è fermo». Il 12 settembre quattro unioni in
# pochi secondi: in produzione `4c4a06b9` (la prima), `main` a `02f85ebb` (tre
# commit avanti), nessun avviso e nessun biglietto — lo stesso incidente del 9
# settembre, dentro il blocco scritto per impedirlo.
#
# Adesso si chiede a GitHub, con `ls-remote`, che legge la punta vera senza
# toccare i riferimenti della copia di lavoro. Provato con un repository nudo
# al posto di GitHub e un clone al posto del VPS, in tre scenari: con
# un'unione arrivata durante il rilascio il controllo vecchio diceva «fermo» e
# il nuovo la vede; senza unioni nessuno dei due scatta; con il remoto
# irraggiungibile il nuovo lo dice invece di tacere.
#
# `|| true` non è pigrizia: con `set -euo pipefail` un `ls-remote` che fallisce
# farebbe morire lo script QUI, dopo un rilascio riuscito, con l'unità in
# `failed` e un allarme per niente.
DOPO=$(git_come_proprietario ls-remote origin "refs/heads/$RAMO" 2>/dev/null | cut -f1 || true)
if [ -z "$DOPO" ]; then
    avviso "non riesco a chiedere a GitHub dov'è $RAMO: non so se è andato avanti mentre rilasciavamo."
    avviso "  se hai unito altro in questi minuti, confronta /version con la punta di $RAMO."
elif [ "$DOPO" != "$COMMIT" ]; then
    mkdir -p "$(dirname "$SEGNAPOSTO")" 2>/dev/null || true
    : > "$SEGNAPOSTO" 2>/dev/null || true
    avviso "$RAMO è andato avanti mentre rilasciavamo: ${DOPO:0:8} invece di ${COMMIT:0:8}."
    avviso "  biglietto lasciato: il differito lo prende entro dieci minuti."
fi
