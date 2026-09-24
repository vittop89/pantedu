#!/bin/bash
# Ripristino di prova dalla copia offsite su Backblaze B2.
#
# Perche' esiste: una copia che si elenca non e' una copia che si ripristina.
# Finora del salvataggio cifrato sapevamo che il file sale e che la sua impronta
# combacia. Non sapevamo se si riapre, se il dump dentro e' un database vero, e
# se le chiavi di runtime ci sono davvero.
#
# Cosa NON fa: non tocca la produzione (il database vero non viene mai scritto),
# non stampa la passphrase, non stampa il contenuto di .env.local — di quel file
# verifica solo che ci sia e che non sia vuoto.
set -uo pipefail

REMOTE=b2-pantedu
BUCKET=pantedu-backup-vps
SCRATCH=pantedu_prova_ripristino
LAVORO=$(mktemp -d /root/prova-ripristino-XXXXXX)

V() { printf '\033[32m  OK  \033[0m %s\n' "$1"; }
X() { printf '\033[31m  NO  \033[0m %s\n' "$1"; ESITO=1; }
T() { printf '\n\033[36m== %s ==\033[0m\n' "$1"; }
ESITO=0

pulisci() {
    T "pulizia"
    mysql -e "DROP DATABASE IF EXISTS \`$SCRATCH\`" 2>/dev/null \
        && echo "  database di prova rimosso" || echo "  (nessun database di prova da rimuovere)"
    rm -rf "$LAVORO"
    echo "  cartella di lavoro cancellata"
}
trap pulisci EXIT

T "1. l'ultima copia nel secchio"
ULTIMO=$(rclone lsf "${REMOTE}:${BUCKET}" --include 'pantedu-backup-*.tar.gpg' 2>/dev/null | sort | tail -1)
if [[ -z "$ULTIMO" ]]; then X "nessuna copia trovata in ${REMOTE}:${BUCKET}"; exit 1; fi
V "trovata: $ULTIMO"

T "2. scarico copia e impronta"
rclone copy "${REMOTE}:${BUCKET}/${ULTIMO}"        "$LAVORO/" --no-traverse 2>&1 | tail -2
rclone copy "${REMOTE}:${BUCKET}/${ULTIMO}.sha256" "$LAVORO/" --no-traverse 2>&1 | tail -2
if [[ -s "$LAVORO/$ULTIMO" ]]; then
    V "scaricata: $(du -h "$LAVORO/$ULTIMO" | cut -f1)"
else
    X "la copia non e' arrivata"; exit 1
fi

T "3. l'impronta combacia?"
# Il file .sha256 porta il percorso di quando fu scritto: si confronta il valore.
ATTESA=$(awk '{print $1}' "$LAVORO/${ULTIMO}.sha256" 2>/dev/null)
CALCOLATA=$(sha256sum "$LAVORO/$ULTIMO" | awk '{print $1}')
if [[ -n "$ATTESA" && "$ATTESA" == "$CALCOLATA" ]]; then
    V "sha256 identica (${CALCOLATA:0:16}...)"
else
    X "sha256 diversa: attesa ${ATTESA:0:16}... calcolata ${CALCOLATA:0:16}..."
fi

T "4. si decifra?"
if [[ -f /etc/pantedu/backup.env ]]; then
    # shellcheck disable=SC1091
    source /etc/pantedu/backup.env
else
    X "manca /etc/pantedu/backup.env"; exit 1
fi
if [[ -z "${BACKUP_GPG_PASSPHRASE:-}" ]]; then X "BACKUP_GPG_PASSPHRASE non impostata"; exit 1; fi

ESITO_GPG=0
printf '%s' "$BACKUP_GPG_PASSPHRASE" | gpg --batch --yes --quiet --passphrase-fd 0 \
    --decrypt --output "$LAVORO/bundle.tar" "$LAVORO/$ULTIMO" 2>"$LAVORO/gpg.err" || ESITO_GPG=$?
if [[ $ESITO_GPG -eq 0 && -s "$LAVORO/bundle.tar" ]]; then
    V "decifrata: $(du -h "$LAVORO/bundle.tar" | cut -f1) in chiaro"
else
    X "gpg non ha decifrato (esito $ESITO_GPG): $(tail -1 "$LAVORO/gpg.err" 2>/dev/null)"; exit 1
fi

T "5. cosa c'e' dentro"
tar -tf "$LAVORO/bundle.tar" | sed 's/^/  /'
tar -xf "$LAVORO/bundle.tar" -C "$LAVORO"
DUMP=$(ls "$LAVORO"/db_*.sql.gz 2>/dev/null | head -1)
STOR=$(ls "$LAVORO"/storage_*.tar.zst 2>/dev/null | head -1)
CONF=$(ls "$LAVORO"/config_*.tar.gz 2>/dev/null | head -1)
[[ -s "$DUMP" ]] && V "dump del database: $(du -h "$DUMP" | cut -f1)" || X "dump del database assente o vuoto"
[[ -s "$STOR" ]] && V "archivio storage: $(du -h "$STOR" | cut -f1)" || X "archivio storage assente o vuoto"
[[ -s "$CONF" ]] && V "archivio configurazione: $(du -h "$CONF" | cut -f1)" || X "archivio configurazione assente o vuoto"

T "5b. ci sono gli utenti del database e i loro permessi?"
# Aggiunto il 10/9/2026: senza, su un server nuovo le viste sono illeggibili
# (vedi docs/ops/ripristino.md). Le copie anteriori a quella data non lo hanno,
# ed e' giusto che la prova lo dica invece di tacere.
GRANTS=$(ls "$LAVORO"/grants_*.sql 2>/dev/null | head -1)
if [[ -s "$GRANTS" ]]; then
    # `grep -c` stampa `0` E esce 1 quando non trova niente: un `|| echo 0`
    # stamperebbe DUE zeri e romperebbe il confronto qui sotto.
    N_GRANT=$(grep -c '^GRANT' "$GRANTS" 2>/dev/null) || N_GRANT=0
    if [[ "$N_GRANT" -gt 0 ]]; then
        V "permessi salvati: $N_GRANT righe GRANT"
    else
        X "il file dei permessi c'e' ma non contiene nessun GRANT"
    fi
else
    X "nessun file dei permessi: su un server nuovo le viste non si leggeranno"
fi

T "6. le chiavi di runtime ci sono? (solo i nomi, mai il contenuto)"
if [[ -s "$CONF" ]]; then
    tar -tzf "$CONF" | sed 's/^/  /' | head -12
    if tar -tzf "$CONF" | grep -qx '.env.local'; then
        BYTE=$(tar -xzOf "$CONF" .env.local | wc -c)
        [[ "$BYTE" -gt 100 ]] && V ".env.local presente, $BYTE byte" || X ".env.local presente ma sospetto ($BYTE byte)"
    else
        X ".env.local NON e' nell'archivio: senza, il database ripristinato resta illeggibile"
    fi
fi

T "7. l'archivio dei dati contiene i dati veri?"
# 2026-09-10 — questa prova diceva «si apre: 1447 voci» ed era verde. Ma
# l'archivio era quello della cartella del REPOSITORY: 35 MB di cache e
# modelli, senza nessuno dei dati veri, che da maggio stanno in
# PANTEDU_DATA_PATH. Aprirsi non basta: si confronta, cartella per cartella,
# con quello che c'e' nella cartella dei dati.
DATI=""
for f in /var/www/pantedu/.env.local /var/www/pantedu/.env; do
    v=$(grep -E '^PANTEDU_DATA_PATH=' "$f" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d "\"'")
    if [[ -n "$v" ]]; then DATI="$v"; break; fi
done
DATI="${DATI:-/var/www/pantedu}/storage"
echo "  cartella dei dati: $DATI"
if [[ -s "$STOR" ]]; then
    tar --use-compress-program=zstd -tf "$STOR" > "$LAVORO/elenco-dati.txt" 2>/dev/null
    N=$(wc -l < "$LAVORO/elenco-dati.txt")
    if [[ "$N" -gt 0 ]]; then V "si apre: $N voci"; else X "non si apre o e' vuoto"; fi
    for d in objects data audit-chain gdpr; do
        [[ -d "$DATI/$d" ]] || continue
        LOC=$(find "$DATI/$d" -type f | wc -l)
        [[ "$LOC" -gt 0 ]] || continue
        ARC=$(grep -cE "^\./$d/.*[^/]$" "$LAVORO/elenco-dati.txt") || ARC=0
        # Nove decimi e non tutto: fra la copia e la prova la cartella puo'
        # essere cresciuta. Quello che non deve succedere e' averne pochi.
        if [[ "$ARC" -gt 0 && $(( ARC * 10 )) -ge $(( LOC * 9 )) ]]; then
            V "$d: $ARC file nell'archivio, $LOC nella cartella dei dati"
        else
            X "$d: $ARC file nell'archivio contro $LOC nella cartella dei dati"
        fi
    done
fi

T "7b. i blob cifrati sono su B2?"
# Mappe e verifiche cifrate non stanno nel fascicolo (vedi il passo 5b del
# salvataggio): stanno in blob/<cartella>/attuale. Si contano.
for NS in maps_enc verifiche_enc; do
    [[ -d "$DATI/$NS" ]] || continue
    LOC=$(find "$DATI/$NS" -type f | wc -l)
    REM=$(rclone lsf -R --files-only "${REMOTE}:${BUCKET}/blob/$NS/attuale" 2>/dev/null | wc -l)
    if [[ "$REM" -gt 0 && "$REM" -eq "$LOC" ]]; then
        V "$NS: $REM su B2, $LOC in locale"
    else
        X "$NS: $REM su B2 contro $LOC in locale"
    fi
done

T "8. il dump ricostruisce un database vero?"
mysql -e "DROP DATABASE IF EXISTS \`$SCRATCH\`; CREATE DATABASE \`$SCRATCH\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>&1 | tail -2
ESITO_LOAD=0
zcat "$DUMP" | mysql "$SCRATCH" 2>"$LAVORO/mysql.err" || ESITO_LOAD=$?
if [[ $ESITO_LOAD -ne 0 ]]; then
    X "il caricamento e' fallito: $(tail -1 "$LAVORO/mysql.err")"
else
    V "caricato senza errori"
fi

T "9. confronto con la produzione"
# 2026-09-12 — riscritto. Prima questo confronto aveva due difetti, e il
# secondo e' proprio la forma di guasto che il progetto insegue.
#
#   1. cercava `migrations`, che non esiste: si chiama `schema_migrations`.
#      Falliva da tutte e due le parti, i due `-` erano uguali, e il confronto
#      era contento. Una riga che non ha mai misurato niente;
#   2. un conteggio FALLITO (`-`) veniva contato come una «differenza di
#      riga», e poi spiegato via con «la copia e' anteriore al lavoro di
#      oggi». `teacher_content` e' una VISTA: nel database di prova non si
#      legge, e la prova lo archiviava come normale scostamento. Un conteggio
#      che non riesce e una riga in piu' o in meno non sono la stessa cosa e
#      non si possono sommare.
printf '  %-28s %10s %10s\n' "tabella" "produzione" "ripristino"
DIFF=0
FALLITI=0
for TAB in users risdoc_templates audit_activity_log schema_migrations; do
    P=$(mysql -N -e "SELECT COUNT(*) FROM \`$TAB\`" pantedu 2>/dev/null) || P="-"
    R=$(mysql -N -e "SELECT COUNT(*) FROM \`$TAB\`" "$SCRATCH" 2>/dev/null) || R="-"
    printf '  %-28s %10s %10s\n' "$TAB" "$P" "$R"
    if [[ "$P" == "-" || "$R" == "-" ]]; then
        FALLITI=$((FALLITI + 1))
    elif [[ "$P" != "$R" ]]; then
        DIFF=$((DIFF + 1))
    fi
done
[[ "$FALLITI" -eq 0 ]] && V "tutti i conteggi sono riusciti" \
    || X "$FALLITI conteggi non sono riusciti: una tabella manca o non si legge"

TP=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='pantedu'" 2>/dev/null)
TR=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$SCRATCH'" 2>/dev/null)
printf '  %-28s %10s %10s\n' "(tabelle in tutto)" "$TP" "$TR"
[[ "$TP" == "$TR" ]] && V "stesso numero di tabelle" || X "tabelle diverse: $TP contro $TR"

# Le righe possono differire di poco: la copia e' di ieri sera e da allora si e'
# lavorato. Quello che NON deve succedere e' che il ripristino sia vuoto.
VUOTE=$(mysql -N -e "SELECT COUNT(*) FROM \`users\`" "$SCRATCH" 2>/dev/null || echo 0)
[[ "$VUOTE" -gt 0 ]] && V "il database ripristinato contiene dati ($VUOTE utenti)" || X "il database ripristinato e' vuoto"
[[ "$DIFF" -gt 0 ]] && echo "  (differenze di riga attese: la copia e' anteriore al lavoro di oggi)"

T "9b. le viste: ci sono, e il loro DEFINER e' nei permessi salvati?"
# Il guasto del 9/9/2026: il dump non porta gli utenti, e otto viste girano con
# `SQL SECURITY DEFINER`. Su un server nuovo il ripristino «riesce» e ogni
# pagina che passa da una vista risponde ERROR 1356 — sito morto, ripristino
# verde.
#
# Qui NON si prova a leggerle: nel database di prova il DEFINER non ha permessi
# (li ha su `pantedu`, non su `$SCRATCH`), quindi fallirebbero comunque e la
# prova direbbe una cosa falsa. Si verifica invece quello che conta davvero per
# un ripristino vero: che le viste siano nel dump, e che ogni utente che le
# definisce sia fra quelli salvati in `grants_*.sql`. Applicare quei GRANT qui
# vorrebbe dire toccare gli utenti del server: questa prova non lo fa.
VP=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.views WHERE table_schema='pantedu'" 2>/dev/null)
VR=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.views WHERE table_schema='$SCRATCH'" 2>/dev/null)
printf '  %-28s %10s %10s\n' "(viste)" "${VP:--}" "${VR:--}"
if [[ -n "$VR" && "$VR" -gt 0 && "$VP" == "$VR" ]]; then
    V "le $VR viste sono nel ripristino"
else
    X "viste: $VP in produzione, $VR nel ripristino"
fi

if [[ -s "$GRANTS" ]]; then
    # `information_schema` da' il DEFINER come `utente@host`, senza virgolette;
    # `SHOW GRANTS` su MariaDB lo scrive con i BACKTICK (`utente`@`host`), non
    # con gli apici. Cercare "'utente'@" non trova niente e fa dire alla prova
    # che i permessi mancano quando invece ci sono: la prima stesura di questo
    # controllo faceva esattamente questo. Si toglie ogni virgoletta e si
    # confronta `utente@host` per intero — l'utente da solo non basta, due
    # host diversi sono due utenti diversi.
    VIRGOLETTE='`'\''"'
    MANCANTI=0
    while IFS= read -r DEF; do
        [[ -n "$DEF" ]] || continue
        if grep -qF "$DEF" <(tr -d "$VIRGOLETTE" < "$GRANTS"); then
            echo "  ok   $DEF"
        else
            echo "  NO   $DEF — non e' nei permessi salvati"
            MANCANTI=$((MANCANTI + 1))
        fi
    done < <(mysql -N -e "SELECT DISTINCT definer FROM information_schema.views WHERE table_schema='$SCRATCH'" 2>/dev/null)
    [[ "$MANCANTI" -eq 0 ]] && V "ogni DEFINER delle viste e' nei permessi salvati" \
        || X "$MANCANTI DEFINER non sono nei permessi: su un server nuovo quelle viste non si leggeranno"
fi

T "esito"
if [[ $ESITO -eq 0 ]]; then
    printf '\033[32mRIPRISTINO RIUSCITO\033[0m — la copia su B2 si scarica, si verifica, si decifra, si apre e ricostruisce il database.\n'
else
    printf '\033[31mRIPRISTINO CON PROBLEMI\033[0m — vedi le righe NO qui sopra.\n'
fi
exit $ESITO
