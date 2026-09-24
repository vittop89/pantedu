#!/bin/bash
# La finestra oraria in cui NON si rilascia.
#
# Non è un eseguibile a sé: lo caricano con `source` sia lo script del trigger
# sia quello del rilascio differito, così la regola sta scritta in un posto
# solo.
#
# Spenta finché non esiste /etc/pantedu/deploy-window.conf. Senza quel file il
# comportamento è identico a prima: si rilascia sempre, subito.
#
# Il file di configurazione, quando serve, contiene righe come:
#
#     # giorni in cui la finestra vale: 1=lunedì … 7=domenica
#     FINESTRA_GIORNI="1 2 3 4 5"
#     # ora di inizio e di fine, in formato 24h, ora locale della macchina
#     FINESTRA_DA="08:00"
#     FINESTRA_A="14:00"
#
# Condizione di attivazione (documento pipeline-ci-cd-2026-09-08, §4): serve
# dallo scenario in cui ci sono classi che usano il sito in orario di lezione.
# Prima di allora un rilascio a metà mattina non disturba nessuno, e una
# finestra accesa senza motivo aggiunge solo un modo di aspettare.

FINESTRA_CONF=/etc/pantedu/deploy-window.conf

# Vero (0) se in questo momento NON si deve rilasciare.
finestra_chiusa() {
    [ -r "$FINESTRA_CONF" ] || return 1

    FINESTRA_GIORNI="" FINESTRA_DA="" FINESTRA_A=""
    # shellcheck source=/dev/null  # il percorso e' noto solo a runtime
    . "$FINESTRA_CONF"

    [ -n "$FINESTRA_DA" ] && [ -n "$FINESTRA_A" ] || return 1

    local oggi adesso
    oggi=$(date +%u)          # 1..7
    adesso=$(date +%H:%M)

    if [ -n "$FINESTRA_GIORNI" ]; then
        case " $FINESTRA_GIORNI " in
            *" $oggi "*) ;;
            *) return 1 ;;    # oggi la finestra non vale
        esac
    fi

    # Confronto lessicografico su HH:MM: funziona perché il formato è fisso.
    # Se DA <= A la finestra sta dentro la giornata; se DA > A scavalca la
    # mezzanotte, e allora si è dentro quando è dopo DA *oppure* prima di A.
    if [[ "$FINESTRA_DA" < "$FINESTRA_A" || "$FINESTRA_DA" == "$FINESTRA_A" ]]; then
        [[ "$adesso" > "$FINESTRA_DA" || "$adesso" == "$FINESTRA_DA" ]] \
            && [[ "$adesso" < "$FINESTRA_A" ]]
    else
        [[ "$adesso" > "$FINESTRA_DA" || "$adesso" == "$FINESTRA_DA" ]] \
            || [[ "$adesso" < "$FINESTRA_A" ]]
    fi
}
