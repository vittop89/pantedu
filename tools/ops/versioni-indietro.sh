#!/bin/bash
#
# Che cosa è rimasto indietro sul server, e soprattutto: gli aggiornamenti
# automatici stanno ancora funzionando?
#
# ── La domanda giusta ─────────────────────────────────────────────────────
#
# «Ci sono pacchetti da aggiornare?» è la domanda sbagliata. Su Debian stable
# la risposta è **sempre sì**: al 9 settembre 2026 ce n'erano ottanta, e non
# perché qualcuno avesse trascurato qualcosa — è come funziona una
# distribuzione stabile, dove le correzioni arrivano come backport e il resto
# aspetta il punto di rilascio.
#
# Un controllo che risponde a quella domanda diventa rosso ogni mese per
# ragioni che non richiedono nessuna azione, e in tre mesi non si apre più. È
# la forma di guasto che questo progetto ha passato una giornata intera a
# togliere di mezzo: allarmi che suonano sempre e quindi non dicono niente.
#
# La domanda giusta è un'altra: **quello che dovrebbe succedere da solo,
# succede ancora?** Su questa macchina `unattended-upgrades` installa le
# correzioni di sicurezza di Debian. Se quel meccanismo si ferma — e i
# meccanismi si fermano in silenzio, l'abbiamo visto oggi tre volte — nessuno
# se ne accorge finché non serve.
#
# Perciò si suona solo per tre cose, tutte e tre «l'automatismo è rotto»:
#
#   1. ci sono aggiornamenti di **sicurezza Debian** in attesa: vuol dire che
#      `unattended-upgrades` non li sta più applicando;
#   2. `unattended-upgrades` non gira da più di una settimana;
#   3. gli elenchi dei pacchetti sono vecchi di più di una settimana: senza,
#      il punto 1 non può nemmeno accorgersi di niente — un controllo che
#      guarda dati fermi è un controllo che non guarda.
#
# Tutto il resto — Debian ordinario, repository di terze parti — finisce nel
# rapporto e basta. Sono decisioni da prendere guardando, non allarmi.
#
# ── Le terze parti ────────────────────────────────────────────────────────
#
# docker, grafana, crowdsec e nodesource **non sono** fra le origini che
# `unattended-upgrades` aggiorna: le loro correzioni di sicurezza non arrivano
# da sole. Si contano e si riportano, senza suonare, per due motivi: quei
# repository non hanno una suite «security» da cui distinguere una correzione
# da una funzione nuova, e un `apt upgrade` non presidiato che riavvia il
# demone Docker butta giù i container di produzione. È una decisione umana.
set -uo pipefail

DATI="${PANTEDU_DATA_PATH:-/var/lib/pantedu-data}"
CARTELLA_LOG="$DATI/storage/logs"
ANOMALIE="$CARTELLA_LOG/anomalie.jsonl"
BATTITO="$CARTELLA_LOG/versioni-ultimo-giro.json"
RAPPORTO=/var/log/pantedu-versioni.log

GIORNI_MASSIMI=7

echo "=== versioni indietro — $(date '+%Y-%m-%d %H:%M:%S') ===" > "$RAPPORTO"

# ── 1. Gli elenchi dei pacchetti sono freschi? ────────────────────────────
#
# `apt-daily.timer` li aggiorna ogni giorno. Se sono vecchi, tutto quello che
# viene dopo guarda una fotografia scaduta e non può accorgersi di niente.
ELENCHI=/var/lib/apt/periodic/update-success-stamp
if [ ! -f "$ELENCHI" ]; then
    ELENCHI=/var/lib/apt/lists
fi
ETA_ELENCHI=$(( ( $(date +%s) - $(stat -c %Y "$ELENCHI" 2>/dev/null || echo 0) ) / 86400 ))
echo "elenchi dei pacchetti: aggiornati $ETA_ELENCHI giorni fa" >> "$RAPPORTO"

# ── 2. `unattended-upgrades` gira ancora? ─────────────────────────────────
#
# Si guarda il **prodotto**, non lo stato dell'unità: un servizio può uscire
# zero senza aver fatto niente, e su questa macchina è già successo.
REGISTRO_UU=/var/log/unattended-upgrades/unattended-upgrades.log
ETA_UU=-1
if [ -f "$REGISTRO_UU" ]; then
    ETA_UU=$(( ( $(date +%s) - $(stat -c %Y "$REGISTRO_UU") ) / 86400 ))
fi
echo "unattended-upgrades: ultima attività $ETA_UU giorni fa" >> "$RAPPORTO"

# ── 3. Cosa è in attesa, e da dove ────────────────────────────────────────
#
# `apt-get -s upgrade` simula soltanto: non installa e non tocca niente. Le
# righe `Inst` portano l'origine fra parentesi, ed è quella che ci interessa.
SIMULAZIONE=$(apt-get -s upgrade 2>/dev/null | grep "^Inst" || true)
TOTALE=$(printf '%s' "$SIMULAZIONE" | grep -c "^Inst" || true)

# L'origine si cerca **dentro le parentesi**, non su tutta la riga.
#
# Il primo giro di questo script ha segnalato «2 aggiornamenti di sicurezza in
# attesa» — cioè «unattended-upgrades è rotto» — perché cercava «security»
# ovunque nella riga e aveva trovato `libmodsecurity-dev` e
# `libmodsecurity3t64`: due pacchetti che hanno quella parola **nel nome** e
# vengono da `Debian:13.6/stable`. Quelli veri erano zero.
#
# Un falso positivo al primo allarme della vita di un controllo è il modo
# migliore per insegnare a ignorarlo. Qui si guarda solo il campo giusto:
# nelle righe `Inst` l'origine sta fra parentesi, e per la sicurezza contiene
# `stable-security` o `Debian-Security`.
origini() {
    printf '%s\n' "$SIMULAZIONE" | grep -oE '\([^)]*\)' || true
}

SICUREZZA=$(origini | grep -cE "stable-security|Debian-Security" || true)
DEBIAN=$(origini | grep -c "Debian:" || true)

# Le terze parti si contano per esclusione, e la sottrazione tiene conto di
# **tutte e due** le famiglie Debian.
#
# Alla prima stesura era `TOTALE - DEBIAN`, e i conti non tornavano: l'origine
# di un pacchetto di sicurezza è `Debian-Security:`, che non contiene
# `Debian:`, quindi ogni aggiornamento di sicurezza veniva attribuito ai
# repository di terze parti. Trovato con tre righe finte, perché in quel
# momento di aggiornamenti di sicurezza in attesa non ce n'era nessuno e il
# difetto non si sarebbe visto guardando i numeri veri.
TERZI=$(( TOTALE - DEBIAN - SICUREZZA ))
[ "$TERZI" -lt 0 ] && TERZI=0

{
    echo
    echo "in attesa: $TOTALE in tutto"
    echo "  di cui sicurezza Debian:   $SICUREZZA   <- se >0, unattended-upgrades non sta lavorando"
    echo "  Debian ordinari:           $DEBIAN"
    echo "  repository di terze parti: $TERZI   <- non aggiornati da soli, decisione umana"
    echo
    echo "--- per origine ---"
    origini \
        | grep -oE "[A-Za-z0-9.:*-]+/[A-Za-z0-9.*-]+" \
        | sort | uniq -c | sort -rn | head -10
    echo
    echo "--- l'elenco, per chi deve decidere ---"
    printf '%s\n' "$SIMULAZIONE" | awk '{print "  " $2 " " $3}' | head -40
    if [ "$TOTALE" -gt 40 ]; then
        echo "  ... e altri $((TOTALE - 40)). Tutti con: apt list --upgradable"
    fi
} >> "$RAPPORTO"

# ── 4. Il battito ─────────────────────────────────────────────────────────
#
# Scritto sempre: dimostra che il giro è avvenuto. La diagnostica lo guarda
# come guarda gli altri lavori periodici, e si lamenta se invecchia — perché
# un controllo silenzioso e un controllo morto si assomigliano troppo.
if [ -d "$CARTELLA_LOG" ]; then
    TEMPORANEO="$BATTITO.parziale"
    printf '{"quando":"%s","totale":%d,"sicurezza_debian":%d,"debian":%d,"terze_parti":%d,"eta_elenchi_giorni":%d,"eta_unattended_giorni":%d,"rapporto":"%s"}\n' \
        "$(date -Iseconds)" "$TOTALE" "$SICUREZZA" "$DEBIAN" "$TERZI" \
        "$ETA_ELENCHI" "$ETA_UU" "$RAPPORTO" \
        > "$TEMPORANEO" && mv -f "$TEMPORANEO" "$BATTITO"
    # Gruppo e permessi come il registro accanto: chi legge è `www-data`
    # dentro il container, chi scrive è root da qui.
    if [ -f "$ANOMALIE" ]; then
        chown --reference="$ANOMALIE" "$BATTITO" 2>/dev/null || true
        chmod --reference="$ANOMALIE" "$BATTITO" 2>/dev/null || true
    fi
fi

# ── 5. Le tre cose che suonano ────────────────────────────────────────────

segnala() {
    local codice="$1" messaggio="$2"
    logger -t versioni-indietro -p user.warning "$messaggio"
    if [ -d "$CARTELLA_LOG" ]; then
        printf '{"quando":"%s","codice":"%s","cosa":"%s","dettagli":{"rapporto":"%s"}}\n' \
            "$(date -Iseconds)" "$codice" "$messaggio" "$RAPPORTO" >> "$ANOMALIE"
        # Gruppo **e** permessi, come fanno gli altri strumenti di root e come
        # docs/ops/diagnostica.md dichiara. Qui il `chmod` mancava: se questo
        # script fosse il primo a creare il file, con la umask di root nascerebbe
        # 0644 e l'applicazione nel container (`www-data`, che sta solo nel
        # gruppo) non potrebbe scriverci — cioè il guasto del 19/9/2026, rifatto
        # da un'altra porta (corretto il 20/9/2026).
        chown pantedu:www-data "$ANOMALIE" 2>/dev/null || true
        chmod 0660 "$ANOMALIE" 2>/dev/null || true
    fi
    echo "SEGNALATO: $messaggio" >> "$RAPPORTO"
}

if [ "$SICUREZZA" -gt 0 ]; then
    segnala "aggiornamenti_sicurezza_fermi" \
        "$SICUREZZA aggiornamenti di sicurezza Debian sono in attesa: unattended-upgrades non li sta applicando. Dettagli in $RAPPORTO"
fi

if [ "$ETA_UU" -gt "$GIORNI_MASSIMI" ] || [ "$ETA_UU" -lt 0 ]; then
    segnala "aggiornamenti_automatici_fermi" \
        "unattended-upgrades non risulta attivo da $ETA_UU giorni (limite $GIORNI_MASSIMI). Le correzioni di sicurezza potrebbero non arrivare piu."
fi

if [ "$ETA_ELENCHI" -gt "$GIORNI_MASSIMI" ]; then
    segnala "elenchi_pacchetti_vecchi" \
        "gli elenchi dei pacchetti sono vecchi di $ETA_ELENCHI giorni: finche restano cosi, nessun controllo sugli aggiornamenti puo accorgersi di niente."
fi

cat "$RAPPORTO"
exit 0
