#!/bin/bash
#
# Controllo giornaliero dell'integrità dei file (AIDE).
#
# Si installa in `/usr/local/sbin/aide-check-pantedu.sh` e lo lancia
# `/etc/cron.d/aide-pantedu` alle 04:00. Sta nel repository — e non solo sulla
# macchina — perché la storia qui sotto è nata proprio da uno script che
# viveva solo sul server e che nessuno ha più riletto per centoundici giorni.
#
# ── Perché esiste, e cosa c'era prima ──────────────────────────────────────
#
# Dal 20 maggio al 9 settembre 2026, ogni notte, `/etc/cron.daily/aide-check`
# ha scritto nel suo registro:
#
#     [2026-05-20T06:25:01+00:00] AIDE check completato
#       ERROR: missing configuration (use '--config' '--before' or '--after')
#
# Centoundici righe «completato». Centoundici volte zero file esaminati.
# Il monitoraggio d'integrità del server non ha mai guardato niente, e non
# l'ha mai detto a nessuno, per tre motivi che si sommavano:
#
#   1. `aide --check` **senza `-c`** non parte: questa build non ha un
#      percorso di configurazione compilato dentro, e esce 17.
#   2. Il vecchio script faceva `DIFF=$(aide --check 2>&1 | tail -200)`: la
#      pipe butta via l'uscita di aide e lascia quella di `tail`, che è
#      sempre zero.
#   3. Poi cercava la riga «Total number of differences». Con exit 17 quella
#      riga non c'è, quindi il ramo dell'allarme non scattava e lo script
#      terminava annunciando successo.
#
# Ognuno dei tre da solo sarebbe stato visibile. Insieme facevano un verde
# perfetto.
#
# ── Le regole che ne discendono, e che questo script rispetta ──────────────
#
# **Il codice di uscita si legge, e si legge senza pipe.** `$?` dopo una pipe
# è l'uscita dell'ultimo comando della pipe, non del primo.
#
# **«Nessuna differenza» e «non sono partito» non sono la stessa cosa.** AIDE
# usa 1, 2 e 4 (sommabili) per dire «ho trovato differenze» e i codici dal 14
# in su per dire «sono in avaria». Confonderli significa mandare un allarme
# che racconta la cosa sbagliata: la versione precedente di questo script
# avrebbe segnalato «differenze rilevate» mentre il vero problema era che
# mancava `-c`.
#
# **Un controllo che non lascia traccia del suo giro non è controllabile.**
# Ogni esecuzione scrive `aide-ultimo-giro.json` nell'albero dei dati, e la
# diagnostica (`tools/ops/diagnostica.php`, invariante `lavori`) si lamenta
# se quel file invecchia. È l'unico modo per accorgersi del caso peggiore —
# quello in cui il controllo smette di girare del tutto — che è esattamente
# quello che è successo qui.
#
# **Un allarme senza recapito funzionante non è un allarme.** Su questa
# macchina **non c'è nessun MTA**: `mail` non consegna e `MAILTO=` nel
# crontab non porta da nessuna parte. Le due strade che funzionano davvero
# sono `logger` (finisce in journald, e da lì in Loki e negli allarmi di
# Grafana) e il registro delle anomalie, che la diagnostica trasforma in una
# mail vera passando da Resend, che è un'API HTTP e quindi non ha bisogno di
# un MTA.
set -uo pipefail

# ── Il PATH si fissa qui ───────────────────────────────────────────────────
#
# Un lavoro in `/etc/cron.d/` **non** prende il PATH di `/etc/crontab`: parte
# con `/usr/bin:/bin`, e `ausearch`, `augenrules` e `runuser` stanno in
# `/usr/sbin`. Dal 9 al 13 settembre 2026 il giro delle 04:00 ha saltato in
# silenzio il controllo sulle letture di `.env.local` — `command -v ausearch`
# non trovava niente e il blocco non partiva — mentre i giri lanciati a mano,
# con il PATH di una shell di root, lo facevano. Misurato il 13 settembre: i
# rapporti di cron del 10, dell'11 e del 13 non hanno la sezione di auditd, e
# delle cinque segnalazioni di letture nel registro delle anomalie nessuna
# viene da cron. Con lo stesso PATH sarebbero fallite, sempre in silenzio,
# anche le prove di `aide-spiega.sh` che usano `runuser` e `augenrules`.
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

CONFIG=/etc/aide/aide.conf
CARTELLA_LOG=/var/log/aide
LOG="$CARTELLA_LOG/aide-daily.log"

# L'albero dei dati è fuori dal repository in produzione. La diagnostica
# legge di qui, quindi di qui deve scrivere anche il battito.
DATI="${PANTEDU_DATA_PATH:-/var/lib/pantedu-data}"
CARTELLA_DATI_LOG="$DATI/storage/logs"
ANOMALIE="$CARTELLA_DATI_LOG/anomalie.jsonl"
BATTITO="$CARTELLA_DATI_LOG/aide-ultimo-giro.json"

mkdir -p "$CARTELLA_LOG"

# ── Il giro ────────────────────────────────────────────────────────────────

INIZIO=$(date +%s)
{
    echo "=== AIDE check $(date '+%Y-%m-%d %H:%M:%S') ==="
    echo "=== configurazione: $CONFIG ==="
} > "$LOG"

# Redirezioni nell'ordine giusto: prima la stdout nel registro, poi la stderr
# insieme a lei. Scritte al contrario (`2>&1 >> "$LOG"`) gli errori andrebbero
# all'uscita di cron — cioè a nessuno — e nel registro resterebbe solo
# l'intestazione. Era l'altro difetto della versione precedente.
/usr/bin/aide --check -c "$CONFIG" >> "$LOG" 2>&1
RC=$?

DURATA=$(( $(date +%s) - INIZIO ))
echo "=== esito: rc=$RC, durata ${DURATA}s ===" >> "$LOG"

# ── Come si legge quel numero ──────────────────────────────────────────────
#
# 0            nessuna differenza
# 1|2|4        aggiunti | rimossi | cambiati, sommabili: quindi da 1 a 7
# 14 e oltre   avaria (configurazione, I/O, versione del database)
#
# Qualunque altra cosa la trattiamo come avaria: meglio un allarme di troppo
# che un codice sconosciuto interpretato come «tutto bene».
if [ "$RC" -eq 0 ]; then
    ESITO=nessuna_differenza
elif [ "$RC" -ge 1 ] && [ "$RC" -le 7 ]; then
    ESITO=differenze
else
    ESITO=guasto
fi

# Le tre cifre dal riassunto finale di AIDE. Le righe di intestazione delle
# sezioni hanno la stessa etichetta ma non hanno un numero in fondo: per
# questo si accetta solo un ultimo campo interamente numerico.
#
# Se il riassunto non c'è (avaria, o nessuna differenza) restano a -1, che
# nel battito si legge come «non pervenuto» ed è comunque un numero valido:
# una riga JSON rotta nel registro delle anomalie farebbe più danno del
# problema che sta segnalando.
conta() {
    awk -v etichetta="$1" '
        $0 ~ "^[[:space:]]*" etichetta "[[:space:]]*:" {
            n = $NF
            if (n ~ /^[0-9]+$/) { print n; trovato = 1; exit }
        }
        END { if (!trovato) print "-1" }
    ' "$LOG"
}

AGGIUNTI=$(conta "Added entries")
RIMOSSI=$(conta "Removed entries")
CAMBIATI=$(conta "Changed entries")

# ── Quali differenze meritano i primi cinque minuti ───────────────────────
#
# `unattended-upgrades` è attivo, e il perimetro contiene ~230.000 voci sotto
# `/usr`: ogni aggiornamento di sicurezza produce decine o centinaia di
# differenze **vere e attese**. Un rapporto che le elenca tutte allo stesso
# modo non si legge, e dopo un mese non si apre nemmeno la mail.
#
# `aide-spiega.sh` le separa verificando, non correlando: file riscritti con lo
# stesso contenuto, file identici a quelli del commit in servizio, file che
# combaciano con l'impronta del loro pacchetto. Le prove, il loro perché e il
# limite che resta stanno lì dentro.
ATTESI=0
INATTESI=-1
SPIEGA="$(dirname "$0")/aide-spiega.sh"
if [ "$ESITO" = "differenze" ] && [ -x "$SPIEGA" ]; then
    RESOCONTO=$("$SPIEGA" "$LOG" 2>/dev/null)
    ATTESI=$(printf '%s' "$RESOCONTO" | sed -n 's/^ATTESI=//p' | tail -1)
    INATTESI=$(printf '%s' "$RESOCONTO" | sed -n 's/^INATTESI=//p' | tail -1)
    ATTESI=${ATTESI:-0}
    INATTESI=${INATTESI:--1}
    # Il resoconto va nel registro **senza** le due righe che servono solo a
    # questo script.
    printf '%s\n' "$RESOCONTO" | grep -vE '^(ATTESI|INATTESI)=' >> "$LOG"
fi

# ── Chi è stato: le letture registrate da auditd ───────────────────────────
#
# AIDE e auditd rispondono a due domande diverse e nessuno dei due copre
# l'altra. AIDE confronta due fotografie a ventiquattr'ore di distanza: dice
# **che** un file è cambiato, non chi. auditd registra i fatti mentre
# accadono, quindi dice chi, quando e con quale comando — ma solo per le
# poche cose che gli abbiamo chiesto di guardare (`tools/ops/audit-pantedu.rules`).
#
# C'è però una cosa che solo auditd può vedere: una **lettura**. Chi copia
# `.env.local` e non lo modifica non lascia nessuna traccia in AIDE, perché
# per AIDE non è cambiato niente.
#
# Questo blocco esiste perché altrimenti nessuno aprirebbe mai
# `/var/log/audit/audit.log`. Un registro che non viene letto e un registro
# che non esiste si distinguono solo dopo, quando è tardi.
#
# `--input-logs` non è un dettaglio: senza, e senza un terminale, `ausearch`
# resta bloccato in attesa su stdin per sempre. Provato: esito 124 dopo
# quindici secondi di timeout, zero righe. Dentro un cron sarebbe un processo
# appeso tutti i giorni.
# Due programmi vanno tolti dal conteggio, e il primo è **questo**.
#
# AIDE calcola l'impronta di `.env.local`, quindi lo legge; gira da un cron,
# e un cron ha una sessione di login, quindi ha un `auid`. Risultato: il
# controllo d'integrità faceva scattare l'allarme «qualcuno ha letto i
# segreti» **su se stesso**, tutte le notti. Diciassette delle ventiquattro
# letture del primo giro erano sue.
#
# `auditctl` ci mette le altre quattro: quando installa la regola tocca il
# percorso, e quel tocco corrisponde alla regola appena installata.
#
# Il filtro sta qui e **non** nella regola del kernel, di proposito. Il
# registro di auditd deve restare completo — è il verbale, e un verbale
# potato non serve a niente il giorno in cui serve davvero. Quello che si
# filtra è l'**allarme**, che è un'altra cosa: nel registro giornaliero le
# righe escluse restano scritte, contate a parte.
IGNORATI='comm="(aide|auditctl)"'
LETTURE_SEGRETI=0

# Da quando contare: dall'ultimo giro, non dalle ultime ventiquattr'ore.
#
# Con la finestra fissa, una lettura segnalata da un giro fatto a mano tornava
# anche nel giro della notte: la stessa lettura, due allarmi, il secondo per
# una cosa già guardata. Dall'ultimo giro — lo dice il battito — ogni lettura
# finisce in un allarme solo. Un minuto di margine, perché il battito si scrive
# poco dopo la ricerca: meglio contare due volte una lettura di confine che
# perderla. Senza un battito leggibile si torna alle ventiquattr'ore.
DA=$(date -d '24 hours ago' +%s)
ADESSO=$(date +%s)
if [ -r "$BATTITO" ]; then
    ULTIMO=$(sed -n 's/.*"quando":"\([^"]*\)".*/\1/p' "$BATTITO")
    ULTIMO_S=$(date -d "$ULTIMO" +%s 2>/dev/null || echo 0)
    if [ "$ULTIMO_S" -gt 0 ] && [ "$ULTIMO_S" -le "$ADESSO" ]; then
        DA=$((ULTIMO_S - 60))
    fi
fi
DA_GIORNO=$(date -d "@$DA" '+%m/%d/%Y')
DA_ORA=$(date -d "@$DA" '+%H:%M:%S')
DA_LEGGIBILE=$(date -d "@$DA" '+%F %T')

if command -v ausearch >/dev/null 2>&1; then
    GREZZE=$(ausearch --input-logs -k pantedu_segreti_letti \
        -ts "$DA_GIORNO" "$DA_ORA" 2>/dev/null </dev/null \
        | grep '^type=SYSCALL')
    LETTURE_SEGRETI=$(printf '%s' "$GREZZE" | grep -vE "$IGNORATI" | grep -c 'type=SYSCALL')
    NOSTRE=$(printf '%s' "$GREZZE" | grep -cE "$IGNORATI")
    {
        echo
        echo "=== auditd: letture di .env.local da sessione interattiva, dal $DA_LEGGIBILE ==="
        echo "    da fuori:            $LETTURE_SEGRETI"
        echo "    da aide/auditctl:    $NOSTRE  (attese: il controllo legge il file per impronarlo)"
        if [ "$LETTURE_SEGRETI" -gt 0 ]; then
            ausearch --input-logs -k pantedu_segreti_letti -ts "$DA_GIORNO" "$DA_ORA" -i 2>/dev/null </dev/null
        fi
    } >> "$LOG"
else
    # Non deve più poter succedere in silenzio: vedi il PATH qui in cima.
    LETTURE_SEGRETI=-1
    echo "=== auditd: ausearch non trovato, le letture di .env.local NON sono state controllate ===" >> "$LOG"
    logger -t aide-check -p user.err "ausearch non trovato: le letture di .env.local non sono state controllate"
    if [ -d "$CARTELLA_DATI_LOG" ]; then
        printf '{"quando":"%s","codice":"audit_letture_non_controllate","cosa":"%s","dettagli":{"registro":"%s"}}\n' \
            "$(date -Iseconds)" "ausearch non trovato: le letture di .env.local non sono state controllate" "$LOG" \
            >> "$ANOMALIE"
        chown pantedu:www-data "$ANOMALIE" 2>/dev/null || true
        chmod 0660 "$ANOMALIE" 2>/dev/null || true
    fi
fi

# ── Chi è entrato in un container ──────────────────────────────────────────
#
# 15/9/2026 — il punto cieco della regola qui sopra: un `docker exec` non ha
# una sessione di login, e le sue letture di `.env.local` non si registrano. Si
# registra l'ingresso (`pantedu_container_ingressi` in audit-pantedu.rules), e
# `ingressi-container.sh` separa i comandi che entrano in un container (exec,
# cp, run…, nsenter, runc, ctr) dagli altri comandi docker di una persona (ps,
# logs…). Gli ingressi si segnalano come le letture, anche quando è stato
# l'amministratore: sono le volte in cui avrebbe potuto leggere i segreti
# senza lasciare la lettura nel registro.
#
# Se manca `ausearch` o il classificatore il controllo non è avvenuto, e lo si
# dice: -1 nel battito e un'anomalia, non uno zero.
INGRESSI=0
ALTRI_DOCKER=0
CLASSIFICA="$(dirname "$0")/ingressi-container.sh"
if command -v ausearch >/dev/null 2>&1 && [ -x "$CLASSIFICA" ]; then
    RESOCONTO_INGRESSI=$(ausearch --input-logs -i -k pantedu_container_ingressi \
        -ts "$DA_GIORNO" "$DA_ORA" 2>/dev/null </dev/null | "$CLASSIFICA")
    INGRESSI=$(printf '%s\n' "$RESOCONTO_INGRESSI" | sed -n 's/^INGRESSI=//p' | tail -1)
    ALTRI_DOCKER=$(printf '%s\n' "$RESOCONTO_INGRESSI" | sed -n 's/^ALTRI=//p' | tail -1)
    INGRESSI=${INGRESSI:-0}
    ALTRI_DOCKER=${ALTRI_DOCKER:-0}
    {
        echo
        echo "=== auditd: ingressi nei container da sessione interattiva, dal $DA_LEGGIBILE ==="
        echo "    ingressi (exec, cp, run, nsenter…): $INGRESSI"
        echo "    altri comandi docker:               $ALTRI_DOCKER"
        printf '%s\n' "$RESOCONTO_INGRESSI" | grep -vE '^(INGRESSI|ALTRI)=' | sed 's/^/    /'
    } >> "$LOG"
else
    INGRESSI=-1
    MESSAGGIO_INGRESSI="auditd: gli ingressi nei container NON sono stati controllati (manca ausearch o $CLASSIFICA)"
    echo "=== $MESSAGGIO_INGRESSI ===" >> "$LOG"
    logger -t aide-check -p user.err "$MESSAGGIO_INGRESSI"
    if [ -d "$CARTELLA_DATI_LOG" ]; then
        printf '{"quando":"%s","codice":"audit_ingressi_non_controllati","cosa":"%s","dettagli":{"registro":"%s"}}\n' \
            "$(date -Iseconds)" "$MESSAGGIO_INGRESSI" "$LOG" >> "$ANOMALIE"
        chown pantedu:www-data "$ANOMALIE" 2>/dev/null || true
        chmod 0660 "$ANOMALIE" 2>/dev/null || true
    fi
fi

# ── Il battito ─────────────────────────────────────────────────────────────
#
# Scritto **sempre**, anche quando va tutto bene: serve a dimostrare che il
# giro è avvenuto. Scritto prima su un temporaneo e poi spostato, così la
# diagnostica non incontra mai mezzo file.
if [ -d "$CARTELLA_DATI_LOG" ]; then
    TEMPORANEO="$BATTITO.parziale"
    printf '{"quando":"%s","rc":%d,"esito":"%s","aggiunti":%d,"rimossi":%d,"cambiati":%d,"attesi":%d,"inattesi":%d,"letture_segreti":%d,"ingressi_container":%d,"durata_s":%d,"registro":"%s"}\n' \
        "$(date -Iseconds)" "$RC" "$ESITO" "$AGGIUNTI" "$RIMOSSI" "$CAMBIATI" "$ATTESI" "$INATTESI" "$LETTURE_SEGRETI" "$INGRESSI" "$DURATA" "$LOG" \
        > "$TEMPORANEO" && mv -f "$TEMPORANEO" "$BATTITO"
    chown pantedu:www-data "$BATTITO" 2>/dev/null || true
    chmod 640 "$BATTITO" 2>/dev/null || true
fi

# ── Quando c'è qualcosa da dire ────────────────────────────────────────────

# Differenze tutte spiegate: si scrive, non si sveglia nessuno.
#
# `INATTESI=0` vuol dire che ogni differenza è passata da una delle prove di
# `aide-spiega.sh`: riscritta uguale, identica al commit in servizio, o
# combaciante con l'impronta del suo pacchetto. Verificato, non dedotto
# dall'orario. Resta tutto nel registro del giorno e nel battito; quello che
# non parte è l'allarme.
#
# La riga sotto è la differenza fra un allarme che si legge e uno che dopo un
# mese si archivia senza aprirlo.
if [ "$ESITO" = "differenze" ] && [ "$INATTESI" = "0" ]; then
    logger -t aide-check -p user.info \
        "AIDE: $ATTESI differenze, tutte verificate come attese (il perché in $LOG). Nessun allarme."
    echo "=== tutte le differenze sono spiegate: nessuna anomalia registrata ===" >> "$LOG"
    ESITO=differenze_attese
fi

if [ "$ESITO" != "nessuna_differenza" ] && [ "$ESITO" != "differenze_attese" ]; then
    if [ "$ESITO" = "differenze" ]; then
        if [ "$INATTESI" -ge 0 ] 2>/dev/null; then
            MESSAGGIO=$(printf 'AIDE: %s differenze da guardare (%s attese e verificate). Dettagli in %s' \
                "$INATTESI" "$ATTESI" "$LOG")
        else
            MESSAGGIO=$(printf 'AIDE: differenze nel filesystem (rc=%d): %s aggiunti, %s rimossi, %s cambiati. Dettagli in %s' \
                "$RC" "$AGGIUNTI" "$RIMOSSI" "$CAMBIATI" "$LOG")
        fi
        CODICE=aide_differenze
        # Un rilascio cambia file per definizione: non è per forza un
        # attacco. Ma è una cosa che qualcuno deve guardare, e finora non la
        # guardava nessuno.
        PRIORITA=user.warning
    else
        MESSAGGIO=$(printf 'AIDE non ha potuto controllare niente (rc=%d). Non e una segnalazione di differenze: e il controllo stesso che non ha funzionato. Dettagli in %s' \
            "$RC" "$LOG")
        CODICE=aide_guasto
        PRIORITA=user.err
    fi

    logger -t aide-check -p "$PRIORITA" "$MESSAGGIO"

    # Nel registro delle anomalie, che la diagnostica legge e trasforma in
    # una mail vera. Scritto a mano perché qui siamo in shell e come root: la
    # classe PHP non è raggiungibile e non deve esserlo.
    #
    # Gruppo www-data e 0660 (19/9/2026): nel registro scrive anche il
    # container, come www-data, che sta solo nel gruppo. Creato da qui con la
    # umask di cron usciva 0644, e il container non ci scriveva più.
    if [ -d "$CARTELLA_DATI_LOG" ]; then
        printf '{"quando":"%s","codice":"%s","cosa":"%s","dettagli":{"rc":%d,"aggiunti":%d,"rimossi":%d,"cambiati":%d,"attesi":%d,"inattesi":%d,"registro":"%s"}}\n' \
            "$(date -Iseconds)" "$CODICE" "$MESSAGGIO" "$RC" "$AGGIUNTI" "$RIMOSSI" "$CAMBIATI" "$ATTESI" "$INATTESI" "$LOG" \
            >> "$ANOMALIE"
        chown pantedu:www-data "$ANOMALIE" 2>/dev/null || true
        chmod 0660 "$ANOMALIE" 2>/dev/null || true
    fi
fi

# Le letture dei segreti si segnalano a parte: non sono un guasto e non sono
# una differenza. Sono un fatto che vale la pena sapere, anche quando è stato
# l'amministratore stesso — soprattutto se non se lo ricorda.
if [ "$LETTURE_SEGRETI" -gt 0 ]; then
    MESSAGGIO_LETTURE=$(printf 'auditd: .env.local letto %d volte da una sessione interattiva, dal %s. Chi e come in %s' \
        "$LETTURE_SEGRETI" "$DA_LEGGIBILE" "$LOG")
    logger -t aide-check -p user.warning "$MESSAGGIO_LETTURE"
    if [ -d "$CARTELLA_DATI_LOG" ]; then
        printf '{"quando":"%s","codice":"audit_segreti_letti","cosa":"%s","dettagli":{"letture":%d,"registro":"%s"}}\n' \
            "$(date -Iseconds)" "$MESSAGGIO_LETTURE" "$LETTURE_SEGRETI" "$LOG" \
            >> "$ANOMALIE"
        chown pantedu:www-data "$ANOMALIE" 2>/dev/null || true
        chmod 0660 "$ANOMALIE" 2>/dev/null || true
    fi
fi

if [ "$INGRESSI" -gt 0 ]; then
    MESSAGGIO_INGRESSI=$(printf 'auditd: %d ingressi in un container da una sessione interattiva, dal %s. Chi e con quale comando in %s' \
        "$INGRESSI" "$DA_LEGGIBILE" "$LOG")
    logger -t aide-check -p user.warning "$MESSAGGIO_INGRESSI"
    if [ -d "$CARTELLA_DATI_LOG" ]; then
        printf '{"quando":"%s","codice":"audit_container_ingressi","cosa":"%s","dettagli":{"ingressi":%d,"altri_comandi_docker":%d,"registro":"%s"}}\n' \
            "$(date -Iseconds)" "$MESSAGGIO_INGRESSI" "$INGRESSI" "$ALTRI_DOCKER" "$LOG" \
            >> "$ANOMALIE"
        chown pantedu:www-data "$ANOMALIE" 2>/dev/null || true
        chmod 0660 "$ANOMALIE" 2>/dev/null || true
    fi
fi

# ── Istantanea del giro, per poter guardare indietro ───────────────────────

cp "$LOG" "$CARTELLA_LOG/aide-$(date +%Y%m%d).log"
# `-name 'aide-2*.log'` e non `'aide-*.log'`: il secondo prenderebbe anche
# `aide-daily.log`, che è il registro corrente.
find "$CARTELLA_LOG" -maxdepth 1 -name 'aide-2*.log' -mtime +30 -delete

# Si esce sempre zero. Il codice di uscita di un cron su questa macchina non
# lo legge nessuno — non c'è MTA — quindi farlo diverso da zero non
# aggiungerebbe un allarme, aggiungerebbe solo un modo di sembrare rotti a
# chi guarda `systemctl`. Le segnalazioni vere sono le tre qui sopra:
# `logger`, il registro delle anomalie e il battito.
exit 0
