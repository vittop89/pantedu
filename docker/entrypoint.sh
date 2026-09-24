#!/bin/bash
#
# Quello che va controllato prima di dire «sono in piedi».
#
# Un container che parte con la configurazione sbagliata non fallisce: serve
# pagine. È esattamente quello che è successo l'8 settembre 2026 con il vecchio
# assetto — dieci minuti di sito in servizio senza configurazione, sintomo
# unico un `/health/backup` a 503, trovato da un controllo esterno. Qui si
# controlla prima, e se qualcosa manca non si parte affatto: un container che
# non parte non prende traffico, un container che parte male sì.
#
# I controlli veri stanno in `verifica-avvio.php`, non qui. Il primo tentativo
# li aveva scritti come `php -r "..."` dentro questo script, e l'escape se li è
# mangiati: `App\Core\Config` arrivava a PHP come `App\\Core\\Config`, errore
# di sintassi, e il container diceva «non riesco a caricare la configurazione»
# — vero, ma per il motivo sbagliato. Un file PHP non ha livelli di virgolette
# e si può provare da solo.

set -euo pipefail

errore() { printf '[avvio] ERRORE: %s\n' "$*" >&2; exit 1; }
nota()   { printf '[avvio] %s\n' "$*"; }

APP=/var/www/pantedu

# ── 1. I dati devono essere montati ───────────────────────────────────────
# `PANTEDU_DATA_PATH` punta di proposito a un percorso che nell'immagine NON
# esiste: se il montaggio manca, si vede qui invece di scoprirlo fra un mese
# quando qualcuno cerca un file scritto in un livello buttato via.
: "${PANTEDU_DATA_PATH:?PANTEDU_DATA_PATH non è impostata}"

[ -d "$PANTEDU_DATA_PATH" ] \
    || errore "$PANTEDU_DATA_PATH non esiste: manca il montaggio dei dati d'istanza."

# ── 1b. La cartella delle sessioni, sul volume dei dati ───────────────────
# Fino al 14 settembre 2026 le sessioni stavano nella /tmp del container, e
# ogni rilascio, che cambia container, buttava fuori tutti (ADR-039). Ora
# stanno in `storage/sessions` dei dati, che il container nuovo ritrova.
#
# La crea l'avvio, come root, e la dà a www-data con 0700: i nomi dei file
# sono gli id di sessione, e se la creasse per prima un lavoro notturno
# dell'host, con il suo utente, chi serve le pagine non potrebbe scriverci.
# Chi sceglie un'altra cartella (`SESSION_SAVE_PATH`) la prepara da sé: il
# controllo qui sotto la verifica comunque, come www-data.
SESSIONI="$PANTEDU_DATA_PATH/storage/sessions"
mkdir -p "$SESSIONI" \
    && chown www-data:www-data "$SESSIONI" \
    && chmod 0700 "$SESSIONI" \
    || errore "non riesco a preparare $SESSIONI per le sessioni."

# ── 2. Configurazione, dati scrivibili, database ──────────────────────────
# Come www-data, cioè come chi serve le pagine — non come root, che può tutto.
# Il database si aspetta: all'avvio della macchina MariaDB può essere ancora in
# piedi a metà. Ma non all'infinito.
nota "controllo configurazione e dati…"
su -s /bin/sh www-data -c "cd $APP && php docker/verifica-avvio.php --senza-db" \
    || errore "i controlli di configurazione non passano (sopra c'è il motivo)."

nota "aspetto il database…"
for tentativo in $(seq 1 30); do
    if su -s /bin/sh www-data -c "cd $APP && php docker/verifica-avvio.php" >/dev/null 2>&1; then
        nota "database raggiungibile (tentativo $tentativo)"
        break
    fi
    if [ "$tentativo" -eq 30 ]; then
        # L'ultimo tentativo si fa parlare, così nel registro c'è il motivo e
        # non solo «non risponde».
        su -s /bin/sh www-data -c "cd $APP && php docker/verifica-avvio.php" || true
        errore "il database non risponde dopo trenta tentativi: non parto."
    fi
    sleep 2
done

# ── 3. Le migrazioni in sospeso si dicono, non si applicano ───────────────
# Applicarle qui sarebbe comodo e sbagliato: durante uno scambio due container
# partono a distanza di secondi, e due processi che migrano lo stesso database
# insieme sono un modo eccellente di romperlo. Le migrazioni le fa il rilascio,
# una volta, prima di far partire il container nuovo. Qui si guarda soltanto.
STATO=$(su -s /bin/sh www-data -c "cd $APP && php tools/migrate.php --status" 2>/dev/null || true)
QUANTE=$(printf '%s\n' "$STATO" | sed -n 's/^Pending (\([0-9]\+\)).*/\1/p' | head -1)

if [ -n "${QUANTE:-}" ] && [ "$QUANTE" -gt 0 ]; then
    nota "ATTENZIONE: $QUANTE migrazioni in sospeso —$(printf '%s\n' "$STATO" | sed -n 's/^  ⧖ /  /p' | tr '\n' ' ')"
    nota "il codice è più nuovo dello schema: le applica il rilascio, non io."
fi

nota "controlli passati, avvio nginx e php-fpm"
exec "$@"
