#!/usr/bin/env bash
# Phase 25.K.4 — Backup encrypted con GPG + offsite (Hetzner Storage Box o S3).
#
# 1. mysqldump DB pantedu compresso
# 2. tar storage/ (objects, maps_enc, verifiche_enc — già cifrati app-level)
# 3. tar app/Config + .env.local (chiavi runtime — critical!)
# 4. GPG encrypt symmetric (passphrase) — output .gpg
# 5. rsync/scp a remoto (Hetzner Storage Box, AWS S3, ecc.)
# 6. Retention rotation locale (7gg) + remote (30gg)
# 7. Verify restore-ability con test estrazione
# 8. Marker di successo + heartbeat opzionale (solo se il backup e riuscito)
#
# Setup pre-run (1 volta):
#   export BACKUP_GPG_PASSPHRASE='<strong passphrase>'  # in /etc/pantedu/backup.env
#   export BACKUP_REMOTE='u123456@u123456.your-storagebox.de:/'
#   export BACKUP_STATE_FILE=/var/lib/pantedu-data/storage/backup-last-ok  # letto da /health/backup
#   export BACKUP_HEARTBEAT_URL="https://..."   # opzionale, dead-man-switch esterno
#   ssh-keygen -t ed25519 -f /root/.ssh/storagebox -N ''
#   ssh-copy-id -i /root/.ssh/storagebox.pub -p 23 u123456@u123456.your-storagebox.de
#
# Cron: 1 volta al giorno alle 02:30 (low traffic)

set -euo pipefail

# ──────────────────────────────────────────────────────────────
# Config (override via env o /etc/pantedu/backup.env)
# Phase 25.M: prefer systemd-creds encrypted (BACKUP_CREDS_FILE injected
# by systemd LoadCredentialEncrypted), fallback /etc/pantedu/backup.env
# per esecuzione manuale (non da systemd).
# ──────────────────────────────────────────────────────────────
if [[ -n "${BACKUP_CREDS_FILE:-}" && -r "$BACKUP_CREDS_FILE" ]]; then
    # Il percorso lo decide systemd a runtime (LoadCredentialEncrypted): non
    # c'è niente che shellcheck possa seguire, ed è giusto così.
    # shellcheck source=/dev/null
    source "$BACKUP_CREDS_FILE"
elif [[ -f /etc/pantedu/backup.env ]]; then
    source /etc/pantedu/backup.env
fi

BACKUP_DIR="${BACKUP_DIR:-/var/backups/pantedu}"
APP_DIR="${APP_DIR:-/var/www/pantedu}"
DB_NAME="${DB_NAME:-pantedu}"
DB_USER="${DB_USER:-}"
DB_PASS="${DB_PASS:-}"
GPG_PASS="${BACKUP_GPG_PASSPHRASE:-}"
REMOTE="${BACKUP_REMOTE:-}"           # esempio: u123456@u123456.your-storagebox.de:/pantedu
REMOTE_PORT="${BACKUP_REMOTE_PORT:-22}"
REMOTE_KEY="${BACKUP_REMOTE_KEY:-/root/.ssh/storagebox}"
RETENTION_LOCAL_DAYS="${RETENTION_LOCAL_DAYS:-7}"
RETENTION_REMOTE_DAYS="${RETENTION_REMOTE_DAYS:-90}"

# Dove stanno davvero i dati d'istanza.
#
# 2026-09-10 — fino a oggi il salvataggio archiviava `$APP_DIR/storage`, cioè la
# cartella del REPOSITORY. Da maggio 2026 i dati stanno sotto PANTEDU_DATA_PATH,
# fuori dal repository (in produzione /var/lib/pantedu-data): la copia prendeva
# 35 MB di cache e modelli e lasciava fuori i 3 GB veri — mappe e verifiche
# cifrate, `objects`, `data`, la catena di audit, il GDPR. Il commento sulla
# ritenzione più in basso lo dice ancora: «ogni backup è ~2.6GB». Lo era, prima
# dello spostamento; poi i fascicoli sono scesi a 21 MB e nessuno se n'è accorto.
#
# Il valore si legge come lo legge l'applicazione: `.env.local` prima, `.env`
# poi. Se non c'è, i dati stanno nel repository (installazione di sviluppo).
DATA_DIR="${PANTEDU_DATA_PATH:-}"
if [[ -z "$DATA_DIR" ]]; then
    for f in "$APP_DIR/.env.local" "$APP_DIR/.env"; do
        v=$(grep -E '^PANTEDU_DATA_PATH=' "$f" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d "\"'")
        if [[ -n "$v" ]]; then DATA_DIR="$v"; break; fi
    done
fi
DATA_DIR="${DATA_DIR:-$APP_DIR}"
STORAGE_DIR="$DATA_DIR/storage"

DATE=$(date +%Y%m%d_%H%M)
# /tmp è tmpfs (~2GB su VPS) — backup bundle può superarlo. Usa /var/backups/pantedu/.tmp (su disco).
TMPDIR_PARENT="${TMPDIR_PARENT:-${BACKUP_DIR}/.tmp}"
mkdir -p "$TMPDIR_PARENT"
chmod 700 "$TMPDIR_PARENT"
TMPDIR=$(mktemp -d -p "$TMPDIR_PARENT" backup.XXXXXX)
# Le virgolette doppie sono volute, e shellcheck qui ha torto (SC2064).
#
# `TMPDIR` è la variabile standard che *tutti* gli strumenti leggono per i loro
# file temporanei, e qui viene riassegnata apposta — vedi il commento sopra: su
# questa macchina `/tmp` è tmpfs e il pacchetto del backup non ci sta. Con le
# virgolette singole l'espansione avverrebbe **all'uscita**, e cancellerebbe
# qualunque cosa `TMPDIR` valga in quel momento; così invece il percorso è
# fissato adesso, ed è quello che `mktemp -d` ha appena creato.
#
# Per un `rm -rf` che gira come root, «deterministico» batte «idiomatico».
# shellcheck disable=SC2064
trap "rm -rf $TMPDIR" EXIT

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }
W() { printf "\033[33m%s\033[0m\n" "$*"; }

mkdir -p "$BACKUP_DIR"

# ──────────────────────────────────────────────────────────────
# Pre-flight checks
# ──────────────────────────────────────────────────────────────
[[ -z "$GPG_PASS" ]] && { R "BACKUP_GPG_PASSPHRASE not set"; exit 1; }
command -v gpg >/dev/null || { R "gpg not installed: apt install gnupg"; exit 1; }

# DB credentials: prima prova .env.local (per app user con password), poi env esplicito
if [[ -z "$DB_USER" || -z "$DB_PASS" ]]; then
    if [[ -f "$APP_DIR/.env.local" ]]; then
        DB_USER=$(grep -E '^DB_USER=' "$APP_DIR/.env.local" | cut -d= -f2- | tr -d '"' | tr -d "'")
        DB_PASS=$(grep -E '^DB_PASS=' "$APP_DIR/.env.local" | cut -d= -f2- | tr -d '"' | tr -d "'")
    fi
fi

# ──────────────────────────────────────────────────────────────
# 1. mysqldump
# ──────────────────────────────────────────────────────────────
# Auth strategy (Debian Trixie MariaDB su questo VPS — auth = password):
#   1. DB_ROOT_USER + DB_ROOT_PASSWORD (da /etc/pantedu/backup.env) — preferred (permessi pieni)
#   2. /root/.my.cnf default (se mysql -e 'SELECT 1' funziona senza arg)
#   3. App user (DB_USER/DB_PASS da .env.local) — fallback (richiede PROCESS+LOCK TABLES+EVENT su DB)
C "=== [1/5] mysqldump ${DB_NAME} ==="
DUMP_FILE="$TMPDIR/db_${DATE}.sql"

if [[ -n "${DB_ROOT_USER:-}" && -n "${DB_ROOT_PASSWORD:-}" ]]; then
    G "  ✓ auth via DB_ROOT_USER (${DB_ROOT_USER})"
    MYSQL_PWD="$DB_ROOT_PASSWORD" mysqldump \
        --user="$DB_ROOT_USER" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        "$DB_NAME" > "$DUMP_FILE"
elif mysql -e 'SELECT 1' >/dev/null 2>&1; then
    G "  ✓ auth via /root/.my.cnf default"
    mysqldump \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        "$DB_NAME" > "$DUMP_FILE"
elif [[ -n "$DB_USER" && -n "$DB_PASS" ]]; then
    G "  ✓ auth via app user (${DB_USER}) — limited dump"
    MYSQL_PWD="$DB_PASS" mysqldump \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        --user="$DB_USER" \
        "$DB_NAME" > "$DUMP_FILE"
else
    R "  ✗ no working DB auth (DB_ROOT_PASSWORD missing, /root/.my.cnf invalid, no DB_USER/DB_PASS)"
    exit 1
fi
DUMP_SIZE=$(du -h "$DUMP_FILE" | awk '{print $1}')
G "  ✓ dump $DUMP_SIZE"

# Compress
gzip -9 "$DUMP_FILE"
DUMP_FILE="${DUMP_FILE}.gz"
G "  ✓ gzip -9 → $(du -h "$DUMP_FILE" | awk '{print $1}')"

# ──────────────────────────────────────────────────────────────
# 2. I dati d'istanza (quelli in chiaro e piccoli)
# ──────────────────────────────────────────────────────────────
C "=== [2/5] dati d'istanza da ${STORAGE_DIR} ==="
STORAGE_TAR="$TMPDIR/storage_${DATE}.tar.zst"

[[ -d "$STORAGE_DIR" ]] || { R "  ✗ $STORAGE_DIR non esiste: senza i dati la copia non serve"; exit 1; }

# Dentro il fascicolo cifrato va tutto, TRANNE:
#   - maps_enc e verifiche_enc: già cifrati dall'applicazione, 2,5 GB che non
#     comprimono (misurato: 66 MB → 66 MB) e non cambiano quasi mai. Vanno su
#     B2 a parte, in modo incrementale (passo 5b): rimetterli ogni notte nel
#     fascicolo vorrebbe dire 2,5 GB di caricamento per zero file cambiati;
#   - quello che si rigenera o che non è un dato: cache, sessioni, temporanei,
#     GeoIP, il CA bundle, e le copie stesse (`backups/`).
#
# Un elenco di esclusioni e non di inclusioni, apposta: una cartella nuova con
# dati importanti finisce nella copia senza che nessuno se ne ricordi. Il
# contrario — un elenco da aggiornare a mano — è il modo in cui questa copia ha
# perso i dati veri per mesi.
#
# Le opzioni `--exclude` stanno PRIMA del percorso: GNU tar ignora quelle che
# vengono dopo, ed esce 2. Era l'avviso «tar storage parziale» di ogni notte, e
# non parlava di file illeggibili: parlava di opzioni al posto sbagliato.
#
# 2026-09-20 — `verifiche/temp`, `verifiche/tex_pdf` e `tex_pdf` sono nuovi qui
# dentro: stavano nella radice del repository (cioè, nel container, dentro
# l'immagine) e da oggi stanno nei dati. Sono derivati — il sorgente TeX di una
# stampa e il PDF che ne esce — e si rifanno premendo di nuovo il pulsante:
# fuori dalla copia, con questa riga a dirlo.
#
# I PDF compilati stanno in `verifiche/tex_pdf` (FileController), non nella
# radice `tex_pdf`, dove non scrive nessuno: escludere la seconda e non la
# prima — com'era scritto fino a stamattina — proteggeva la cartella vuota e
# archiviava quella piena, con il commento che diceva il contrario. Si
# escludono tutte e due: `tex_pdf` è comunque una radice di scrittura
# dichiarata in `app/Config/filesystem.php`.
#
# `security/`, invece, ENTRA: le soglie degli allarmi del WAF hanno il file
# come unica copia. `tests/ops/salvataggio-esclusioni.test.sh` misura queste
# righe nei due versi, leggendo le esclusioni da qui.
ESITO_TAR=0
tar --use-compress-program="zstd -3 -T0" \
    --exclude='./maps_enc' --exclude='./verifiche_enc' \
    --exclude='./cache' --exclude='./sessions' \
    --exclude='./temp' --exclude='./_tmp' --exclude='./risdoc-tmp' \
    --exclude='./verifiche/temp' --exclude='./verifiche/tex_pdf' \
    --exclude='./tex_pdf' \
    --exclude='./backups' --exclude='./geoip' --exclude='./ca-bundle' \
    --exclude='./logs/*.gz' \
    -cf "$STORAGE_TAR" -C "$STORAGE_DIR" . || ESITO_TAR=$?

# GNU tar: 1 = qualche file è cambiato mentre lo leggeva (normale per i
# registri di un sistema vivo); 2 o più = errore vero. Il secondo caso ferma la
# copia: l'unità va in `failed` e parte l'avviso, invece di un WARN che nessuno
# legge.
if [[ $ESITO_TAR -ge 2 ]]; then
    R "  ✗ tar dei dati fallito (esito $ESITO_TAR)"
    exit 1
elif [[ $ESITO_TAR -eq 1 ]]; then
    W "  (qualche file è cambiato durante la lettura: normale per i registri)"
fi

# Controllo di contenuto, non di esistenza: senza `objects/` e `data/` questa
# non è la copia dei dati, qualunque cosa dica tar. L'elenco si scrive su file:
# con `pipefail` un `tar -t | grep -q` può fallire per SIGPIPE quando grep
# trova subito quel che cerca e chiude.
#
# `security/` è nell'elenco dal 20/9/2026: le soglie degli allarmi del WAF
# stanno solo lì, e un'esclusione messa per sbaglio le porterebbe via in
# silenzio.
ELENCO_DATI="$TMPDIR/elenco-dati.txt"
tar --use-compress-program=zstd -tf "$STORAGE_TAR" > "$ELENCO_DATI"
VOCI=$(wc -l < "$ELENCO_DATI")
for d in objects data security; do
    if [[ -d "$STORAGE_DIR/$d" ]] && ! grep -q "^\./$d/" "$ELENCO_DATI"; then
        R "  ✗ $d/ esiste in $STORAGE_DIR ma non è nel fascicolo"
        exit 1
    fi
done
G "  ✓ dati d'istanza: $VOCI voci, $(du -h "$STORAGE_TAR" | awk '{print $1}')"

# ──────────────────────────────────────────────────────────────
# 3. Tar config + secrets (CRITICAL: chiavi runtime per decifrare DB!)
# ──────────────────────────────────────────────────────────────
C "=== [3/5] tar config + secrets ==="
CONFIG_TAR="$TMPDIR/config_${DATE}.tar.gz"

# Costruisci lista file/dir esistenti (tar exit 2 se file mancante → tolerante)
CFG_ITEMS=()
for f in .env .env.local app/Config; do
    [[ -e "$APP_DIR/$f" ]] && CFG_ITEMS+=("$f")
done
if [[ ${#CFG_ITEMS[@]} -eq 0 ]]; then
    W "  WARN: nessun config file trovato in $APP_DIR — skip"
    echo "no config files" > "$CONFIG_TAR"
else
    tar -czf "$CONFIG_TAR" -C "$APP_DIR" "${CFG_ITEMS[@]}" 2>&1 \
        || W "  WARN: tar config parziale (file rimossi durante archiviazione)"
fi
G "  ✓ config tar.gz $(du -h "$CONFIG_TAR" | awk '{print $1}')"

# ──────────────────────────────────────────────────────────────
# 3b. Utenti del database e loro permessi
# ──────────────────────────────────────────────────────────────
# 2026-09-10 — aggiunto dopo una prova di ripristino vera.
#
# Il dump contiene lo schema, i dati e le viste. Le viste pero' sono create
# con `DEFINER=`pantedu_app`@`localhost`` e `SQL SECURITY DEFINER`: otto viste,
# sei di `pantedu_app` e due di `pantedu_migrator`. Se su un server nuovo quegli
# utenti non esistono, il dump si carica senza un errore e poi **ogni lettura
# di una vista fallisce**:
#
#   ERROR 1356 (HY000): View '...' references invalid table(s) or column(s)
#   or function(s) or definer/invoker of view lack rights to use them
#
# E `teacher_content` — da cui l'applicazione legge tutti i contenuti dei
# docenti — e' una di quelle viste. Cioe' il ripristino sarebbe andato «a buon
# fine» e il sito non avrebbe funzionato, con un errore che parla d'altro.
#
# Il dump non porta gli utenti (verificato: zero righe CREATE USER o GRANT).
# Qui si aggiunge un file con `CREATE USER` + `GRANT` per gli utenti `pantedu%`,
# in forma eseguibile. Contiene gli hash delle password: finisce **solo** dentro
# il fascicolo cifrato, mai in chiaro accanto ad esso.
C "=== [3b/5] utenti e permessi del database ==="
GRANTS_FILE="$TMPDIR/grants_${DATE}.sql"
{
    echo "-- Utenti del database e permessi, $(date -Is)."
    echo "-- Servono PRIMA di caricare il dump: le viste hanno SQL SECURITY DEFINER."
    echo "-- Contiene hash di password: trattare come segreto."
    echo
} > "$GRANTS_FILE"

# Stessa strategia di autenticazione del dump, nello stesso ordine.
elenca_utenti() {
    mysql "$@" -N -B -e \
        "SELECT CONCAT('SHOW CREATE USER ''', user, '''@''', host, ''';'),
                CONCAT('SHOW GRANTS FOR ''',    user, '''@''', host, ''';')
         FROM mysql.user WHERE user LIKE 'pantedu%'" 2>/dev/null | tr '\t' '\n'
}
esegui_righe() { mysql "$@" -N -B 2>/dev/null | sed 's/$/;/'; }

if [[ -n "${DB_ROOT_USER:-}" && -n "${DB_ROOT_PASSWORD:-}" ]]; then
    export MYSQL_PWD="$DB_ROOT_PASSWORD"
    elenca_utenti --user="$DB_ROOT_USER" | esegui_righe --user="$DB_ROOT_USER" >> "$GRANTS_FILE"
    unset MYSQL_PWD
elif mysql -e 'SELECT 1' >/dev/null 2>&1; then
    elenca_utenti | esegui_righe >> "$GRANTS_FILE"
fi

# Il conteggio, non l'esito del comando: una pipe che non trova niente esce
# comunque zero, e un file di soli commenti non serve a nessuno.
RIGHE_GRANTS=$(grep -c '^GRANT' "$GRANTS_FILE" 2>/dev/null || echo 0)
if [[ "$RIGHE_GRANTS" -gt 0 ]]; then
    chmod 600 "$GRANTS_FILE"
    G "  ✓ $RIGHE_GRANTS permessi salvati (utenti pantedu%)"
else
    W "  WARN: nessun permesso salvato (autenticazione insufficiente): un ripristino su server nuovo avra' le viste rotte"
fi

# ──────────────────────────────────────────────────────────────
# 4. GPG encrypt symmetric (single bundle)
# ──────────────────────────────────────────────────────────────
C "=== [4/5] GPG encrypt ==="
BUNDLE="$TMPDIR/pantedu-backup-${DATE}.tar"
tar -cf "$BUNDLE" -C "$TMPDIR" \
    "$(basename "$DUMP_FILE")" "$(basename "$STORAGE_TAR")" \
    "$(basename "$CONFIG_TAR")" "$(basename "$GRANTS_FILE")"

ENCRYPTED="$BACKUP_DIR/pantedu-backup-${DATE}.tar.gpg"
echo "$GPG_PASS" | gpg --batch --yes --passphrase-fd 0 \
    --symmetric \
    --cipher-algo AES256 \
    --compress-algo none \
    --output "$ENCRYPTED" \
    "$BUNDLE"
chmod 600 "$ENCRYPTED"
ENC_SIZE=$(du -h "$ENCRYPTED" | awk '{print $1}')
G "  ✓ encrypted $(basename $ENCRYPTED) $ENC_SIZE"

# Compute checksum
sha256sum "$ENCRYPTED" > "${ENCRYPTED}.sha256"

# ──────────────────────────────────────────────────────────────
# 5. Offsite copy (B2 via rclone OR scp Hetzner SB)
# ──────────────────────────────────────────────────────────────
BACKUP_TYPE="${BACKUP_TYPE:-scp}"

# Esito della copia offsite, letto in fondo: se non è zero l'unità esce con
# errore e parte l'avviso (vedi prima del registro «backup OK»).
ESITO_SALITA=0

case "$BACKUP_TYPE" in
    b2)
        # Backblaze B2 via rclone (S3-compatible)
        REMOTE_NAME="${B2_REMOTE_NAME:-b2-pantedu}"
        BUCKET="${B2_BUCKET:-pantedu-backup-vps}"
        C "=== [5/5] Offsite to B2 ${REMOTE_NAME}:${BUCKET} ==="
        # 2026-09-09 — qui c'era:
        #
        #     if command -v rclone >/dev/null \
        #         && rclone copy ... 2>&1 | tail -5; then
        #
        # `$?` di una pipe e' l'uscita dell'**ultimo** comando, cioe' di `tail`,
        # che riesce sempre. Quindi «✓ uploaded to B2» si stampava comunque e il
        # ramo del fallimento era irraggiungibile: se B2 rifiutava la chiave, se
        # il secchio non esisteva o se la rete era giu', il registro diceva lo
        # stesso che il backup era finito offsite.
        #
        # Per un backup e' la bugia peggiore possibile: la copia remota esiste
        # per il giorno in cui quella locale non c'e' piu'.
        #
        # Adesso l'uscita si legge da sola, prima di guardare l'output.
        if ! command -v rclone >/dev/null; then
            R "  ✗ rclone non installato — copia offsite NON fatta"
            ESITO_SALITA=1
        else
            # `|| ESITO_SALITA=$?` non è ornamento: in cima c'è `set -e`, e
            # un'assegnazione da un comando che fallisce ucciderebbe lo script
            # **prima** di arrivare al ramo che segnala il guasto.
            #
            # Il codice di prima non moriva solo perché il comando stava dentro
            # la condizione di un `if`, dove `set -e` non si applica — cioè per
            # lo stesso motivo per cui non si accorgeva mai di niente.
            #
            # Trovato provandolo con un secchio inesistente: la prima versione
            # di questa correzione usciva 1 senza stampare una riga.
            ESITO_SALITA=0
            SALITA=$(rclone copy "$ENCRYPTED" "${REMOTE_NAME}:${BUCKET}/" \
                --transfers 2 --checkers 2 --no-traverse 2>&1) || ESITO_SALITA=$?
            [ -n "$SALITA" ] && printf '%s\n' "$SALITA" | tail -5

            if [ "$ESITO_SALITA" -eq 0 ]; then
                SALITA2=$(rclone copy "${ENCRYPTED}.sha256" "${REMOTE_NAME}:${BUCKET}/" --no-traverse 2>&1) || ESITO_SALITA=$?
                [ -n "$SALITA2" ] && printf '%s\n' "$SALITA2" | tail -2
            fi

            if [ "$ESITO_SALITA" -eq 0 ]; then
                # `REMOTE` alimenta il riepilogo finale. Senza, quello stampava
                # «Remote: (disabled)» anche dopo un caricamento riuscito —
                # perche' `REMOTE` lo valorizza solo il ramo `scp`. Chi leggeva
                # quella riga per sapere se la copia offsite era avvenuta,
                # leggeva il contrario della verita'.
                REMOTE="${REMOTE_NAME}:${BUCKET}"
                G "  ✓ uploaded to B2"
            else
                R "  ✗ B2 upload FAILED (rc=$ESITO_SALITA) — local backup OK, remote NO"
            fi

            # ── 5b. I blob già cifrati: copia incrementale ─────────────────
            # 2026-09-10 — maps_enc e verifiche_enc (2,5 GB, circa 4000 file)
            # non stanno più nel fascicolo: sono già cifrati dall'applicazione,
            # hanno nomi ULID che non si riscrivono, e non cambiano quasi mai
            # (il 10/9 zero file modificati nelle 24 ore).
            #
            # `sync` su `blob/<cartella>/attuale`, con `--backup-dir` su
            # `blob/<cartella>/rimossi/<data>`: un blob cancellato in locale non
            # sparisce da B2, finisce fra i rimossi; la ritenzione più in basso
            # toglie i rimossi dopo un anno, che è la promessa scritta nella DPIA
            # e nell'informativa. Un `copy` e basta li avrebbe tenuti per sempre.
            #
            # Rete: se in locale ci sono meno della metà dei file che ci sono su
            # B2, non si sincronizza. Un montaggio mancante sembrerebbe «tutto
            # cancellato», e la sincronizzazione lo eseguirebbe alla lettera — è
            # la stessa trappola della pulizia degli orfani, dall'altra parte.
            for NS in maps_enc verifiche_enc; do
                SRC="$STORAGE_DIR/$NS"
                DST="${REMOTE_NAME}:${BUCKET}/blob/$NS"
                if [[ ! -d "$SRC" ]]; then
                    W "  $NS: non c'è in $STORAGE_DIR, niente da copiare"
                    continue
                fi
                LOCALI=$(find "$SRC" -type f | wc -l)
                REMOTI=$(rclone lsf -R --files-only "$DST/attuale" 2>/dev/null | wc -l)
                if [[ "$REMOTI" -gt 0 && $(( LOCALI * 2 )) -lt "$REMOTI" ]]; then
                    R "  ✗ $NS: $LOCALI file in locale contro $REMOTI su B2 — non sincronizzo: sembra un montaggio mancante, non una cancellazione"
                    ESITO_SALITA=1
                    continue
                fi
                ESITO_BLOB=0
                USCITA_BLOB=$(rclone sync "$SRC" "$DST/attuale" \
                    --backup-dir "$DST/rimossi/$DATE" \
                    --transfers 4 --checkers 8 2>&1) || ESITO_BLOB=$?
                [ -n "$USCITA_BLOB" ] && printf '%s\n' "$USCITA_BLOB" | tail -3
                DOPO=$(rclone lsf -R --files-only "$DST/attuale" 2>/dev/null | wc -l)
                # Il conteggio dopo, non l'esito del comando: è il numero che
                # dice se su B2 c'è quello che c'è qui.
                if [[ $ESITO_BLOB -eq 0 && "$DOPO" -eq "$LOCALI" ]]; then
                    G "  ✓ $NS: $DOPO blob su B2, allineati a quelli locali"
                else
                    R "  ✗ $NS: sincronizzazione non riuscita (esito $ESITO_BLOB; $LOCALI in locale, $DOPO su B2)"
                    ESITO_SALITA=1
                fi
            done
        fi
        ;;
    scp)
        # Hetzner Storage Box / generic SSH target
        if [[ -n "$REMOTE" && -f "$REMOTE_KEY" ]]; then
            C "=== [5/5] Offsite scp to $REMOTE ==="
            # Stessa correzione del ramo `b2` qui sopra: `scp ... | tail -3`
            # dentro un `if` fa leggere l'uscita di `tail`, che riesce sempre.
            # Questo ramo oggi non gira (BACKUP_TYPE=b2), ma un difetto che
            # aspetta il giorno in cui si cambia assetto è peggio di uno che si
            # vede subito.
            ESITO_SALITA=0
            SALITA=$(scp -P "$REMOTE_PORT" -i "$REMOTE_KEY" -o StrictHostKeyChecking=accept-new \
                "$ENCRYPTED" "${ENCRYPTED}.sha256" \
                "$REMOTE" 2>&1) || ESITO_SALITA=$?
            [ -n "$SALITA" ] && printf '%s\n' "$SALITA" | tail -3

            if [ "$ESITO_SALITA" -eq 0 ]; then
                G "  ✓ uploaded"
            else
                R "  ✗ remote upload FAILED (rc=$ESITO_SALITA) — local backup OK, remote NO"
            fi
        else
            W "=== [5/5] Offsite skipped (BACKUP_REMOTE or key not set) ==="
        fi
        ;;
    *)
        W "=== [5/5] Unknown BACKUP_TYPE='$BACKUP_TYPE' — offsite skipped ==="
        ;;
esac

# Retention locale (daily 7 days)
find "$BACKUP_DIR" -name 'pantedu-backup-*.tar.gpg*' -mtime "+${RETENTION_LOCAL_DAYS}" -delete 2>/dev/null || true
LOCAL_COUNT=$(ls "$BACKUP_DIR"/pantedu-backup-*.tar.gpg 2>/dev/null | wc -l)
G "  ✓ retention locale: $LOCAL_COUNT backup (${RETENTION_LOCAL_DAYS}gg)"

# Retention REMOTE B2: "keep last N" + tiered long-term.
#
# Rationale: ogni backup è ~2.6GB (bulk = maps_enc blob già encrypted app-level,
# zstd non comprime entropia alta). Con free tier B2 = 10GB, "daily keep ALL"
# saturava in 4 giorni. Strategia nuova:
#
# - Keep last B2_KEEP_DAILY backups più recenti (default 3 = ~7.8GB)
# - PLUS 1 per settimana ISO (entro 30gg) come weekly snapshot
# - PLUS 1 per mese (entro 365gg) come monthly archive
# - PLUS 1 per anno (>365gg) come yearly archive
#
# Worst case: 3 daily + 4 weekly + 12 monthly + N yearly = ~50GB (cresce nel tempo).
# Mitigation: env B2_KEEP_DAILY/WEEKLY/MONTHLY/YEARLY override.
B2_KEEP_DAILY="${B2_KEEP_DAILY:-3}"
B2_KEEP_WEEKLY="${B2_KEEP_WEEKLY:-4}"
B2_KEEP_MONTHLY="${B2_KEEP_MONTHLY:-6}"
# 2026-09-04: una sola copia annuale, non due. Le anagrafiche di un account
# cancellato sopravvivono nei backup fino alla rotazione, e DPIA e informativa
# promettono "al massimo un anno". Dopo un ripristino le cancellazioni
# successive alla copia vanno rieseguite: docs/security/operations/restore-reerasure.md
B2_KEEP_YEARLY="${B2_KEEP_YEARLY:-1}"

if [[ "$BACKUP_TYPE" == "b2" ]] && command -v rclone >/dev/null; then
    C "=== Retention B2 (keep last N + tiered) ==="
    REMOTE_NAME="${B2_REMOTE_NAME:-b2-pantedu}"
    BUCKET="${B2_BUCKET:-pantedu-backup-vps}"
    NOW_EPOCH=$(date +%s)

    rclone lsf "${REMOTE_NAME}:${BUCKET}" --include 'pantedu-backup-*.tar.gpg' 2>/dev/null \
        | sort -r \
        | awk -v now="$NOW_EPOCH" \
              -v keep_daily="$B2_KEEP_DAILY" \
              -v keep_weekly="$B2_KEEP_WEEKLY" \
              -v keep_monthly="$B2_KEEP_MONTHLY" \
              -v keep_yearly="$B2_KEEP_YEARLY" '
            BEGIN { daily=0 }
            {
                if (match($0, /[0-9]{8}_[0-9]{4}/)) {
                    ts = substr($0, RSTART, RLENGTH)
                    year = substr(ts, 1, 4); mon = substr(ts, 5, 2); day = substr(ts, 7, 2)
                    hour = substr(ts, 10, 2); min = substr(ts, 12, 2)
                    spec = sprintf("%s %s %s %s %s 00", year, mon, day, hour, min)
                    epoch = mktime(spec)
                    if (epoch < 0) next
                    yyyymm = sprintf("%s-%s", year, mon)
                    iso_year_week = strftime("%G-%V", epoch)

                    # Priority order (i piu recenti vincono):
                    # 1. Daily slot (keep_daily piu recenti)
                    # 2. Weekly slot (1 per ISO week, max keep_weekly)
                    # 3. Monthly slot (1 per YYYY-MM, max keep_monthly)
                    # 4. Yearly slot (1 per YYYY, max keep_yearly)
                    keep = 0
                    if (daily < keep_daily) {
                        daily++; keep = 1
                    } else if (!(iso_year_week in seen_week) && length(seen_week) < keep_weekly) {
                        seen_week[iso_year_week] = 1; keep = 1
                    } else if (!(yyyymm in seen_month) && length(seen_month) < keep_monthly) {
                        seen_month[yyyymm] = 1; keep = 1
                    } else if (!(year in seen_year) && length(seen_year) < keep_yearly) {
                        seen_year[year] = 1; keep = 1
                    }
                    print (keep ? "KEEP " : "DEL ") $0
                }
            }
        ' > /tmp/b2-retention.$$
    KEPT=$(awk '/^KEEP/{c++} END{print c+0}' /tmp/b2-retention.$$)
    DELETE=$(awk '/^DEL /{c++} END{print c+0}' /tmp/b2-retention.$$)
    if [[ "$DELETE" -gt 0 ]]; then
        grep '^DEL' /tmp/b2-retention.$$ | awk '{print $2}' | while read -r f; do
            rclone delete "${REMOTE_NAME}:${BUCKET}/$f" 2>/dev/null && \
                rclone delete "${REMOTE_NAME}:${BUCKET}/${f}.sha256" 2>/dev/null
        done
    fi
    rm -f /tmp/b2-retention.$$
    G "  ✓ retention B2: $KEPT kept, $DELETE deleted (D=$B2_KEEP_DAILY W=$B2_KEEP_WEEKLY M=$B2_KEEP_MONTHLY Y=$B2_KEEP_YEARLY)"

    # I blob rimossi (passo 5b) si tengono un anno, poi via: la promessa della
    # DPIA vale anche per loro. (Il commento qui sopra sui «~2.6GB per
    # backup» descrive l'assetto di prima del 10/9/2026, quando i blob stavano
    # dentro il fascicolo; adesso il fascicolo è piccolo e i blob vivono in
    # `blob/<cartella>/attuale`, caricati una volta sola.)
    PURGATI=0
    for NS in maps_enc verifiche_enc; do
        while read -r cartella; do
            giorno="${cartella%%_*}"
            [[ "$giorno" =~ ^[0-9]{8}$ ]] || continue
            eta=$(( ( NOW_EPOCH - $(date -d "$giorno" +%s) ) / 86400 ))
            if [[ "$eta" -gt 365 ]]; then
                rclone purge "${REMOTE_NAME}:${BUCKET}/blob/$NS/rimossi/${cartella%/}" 2>/dev/null \
                    && PURGATI=$((PURGATI + 1))
            fi
        done < <(rclone lsf --dirs-only "${REMOTE_NAME}:${BUCKET}/blob/$NS/rimossi" 2>/dev/null)
    done
    G "  ✓ blob rimossi da più di un anno tolti da B2: $PURGATI cartelle"
fi

# 2026-09-10 — una copia offsite fallita non è un backup riuscito.
#
# Fino a oggi, se il caricamento su B2 falliva, lo script stampava la riga
# rossa e poi CONTINUAVA: scriveva «backup OK» nel registro qui sotto,
# aggiornava il marcatore che `/health/backup` legge per rispondere 200, e
# usciva zero — quindi l'unità risultava riuscita e l'avviso non partiva. La
# copia offsite esiste per il giorno in cui quella locale non c'è più: se manca,
# deve suonare. Adesso l'unità esce con errore e il marcatore resta vecchio.
if [[ "$ESITO_SALITA" -ne 0 ]]; then
    R "  ✗ la copia offsite non è completa: esco con errore perché parta l'avviso"
    echo "[$(date -Iseconds)] backup LOCALE ok, OFFSITE NO: $(basename "$ENCRYPTED")" >> /var/log/pantedu-backup.log
    exit 1
fi

# Log
echo "[$(date -Iseconds)] backup OK: $(basename $ENCRYPTED) $ENC_SIZE" >> /var/log/pantedu-backup.log

echo
G "════════════════════════════════════════"
G "Backup completato"
G "════════════════════════════════════════"
echo "  File:     $ENCRYPTED"
echo "  Size:     $ENC_SIZE"
echo "  SHA256:   $(cat ${ENCRYPTED}.sha256 | awk '{print $1}')"
echo "  Remote:   ${REMOTE:-(disabled)}"
echo
echo "Restore (test) — usa /var/backups/pantedu/.restore (NON /tmp tmpfs):"
echo "  mkdir -p /var/backups/pantedu/.restore && chmod 700 /var/backups/pantedu/.restore"
echo "  GPG_PASS=\$(grep ^BACKUP_GPG_PASSPHRASE /etc/pantedu/backup.env | cut -d= -f2- | tr -d \\\"\\')"
echo "  echo \"\$GPG_PASS\" | gpg --decrypt --batch --passphrase-fd 0 $ENCRYPTED > /var/backups/pantedu/.restore/restore.tar"
echo "  tar -tf /var/backups/pantedu/.restore/restore.tar  # deve elencare 4 file (db, storage, config, grants)"
echo "  # I blob cifrati non sono nel fascicolo: rclone copy ${B2_REMOTE_NAME:-b2-pantedu}:${B2_BUCKET:-pantedu-backup-vps}/blob/<cartella>/attuale"
echo "  # Procedura completa, nell'ordine giusto: docs/ops/ripristino.md"
echo "  # Estrai DB:"
echo "  tar -xOf /var/backups/pantedu/.restore/restore.tar db_${DATE}.sql.gz | gunzip | mysql ${DB_NAME}"

# ─────────────────────────────────────────────────────────────────────────────
# Segnale di "backup riuscito" per il monitoraggio esterno.
#
# Perche' in fondo: con `set -euo pipefail` qualunque fallimento nei passi
# precedenti interrompe lo script prima di arrivare qui. Quindi un segnale
# vecchio significa "il backup NON e' riuscito", senza dover capire quale
# passo sia saltato. Il 28-30 agosto 2026 il backup e' fallito tre notti di
# fila (MariaDB giu' all'ora del dump) e nessuno se n'e' accorto.
#
# Due canali, indipendenti:
#
#   1. MARKER LOCALE (sempre) — un file con l'epoch del successo, che
#      GET /health/backup legge per rispondere 200 o 503. Basta un monitor
#      HTTP qualunque, anche del piano gratuito: nessun servizio di
#      heartbeat a pagamento.
#      Path: BACKUP_STATE_FILE, default sotto la storage dell'app. Deve
#      essere leggibile da www-data, che e' l'utente che serve /health.
#
#   2. PING HTTP (opzionale) — per chi usa un dead-man's-switch esterno
#      (healthchecks.io, o gli heartbeat a pagamento di UptimeRobot).
#      Se BACKUP_HEARTBEAT_URL e' vuota, il passo si salta in silenzio.
#
# Nessuno dei due puo' far fallire un backup riuscito: gli errori sono
# soltanto loggati.
# ─────────────────────────────────────────────────────────────────────────────

STATE_FILE="${BACKUP_STATE_FILE:-/var/lib/pantedu-data/storage/backup-last-ok}"
if printf '%s' "$(date +%s)" > "$STATE_FILE" 2>/dev/null; then
    chmod 644 "$STATE_FILE" 2>/dev/null || true
    G "  ✓ marker aggiornato: $STATE_FILE"
else
    R "  ✗ marker NON scritto: $STATE_FILE"
    echo "    /health/backup continuera' a rispondere 503 e il monitor" >&2
    echo "    segnalera' un guasto che non c'e'. Controlla i permessi." >&2
fi

if [ -n "${BACKUP_HEARTBEAT_URL:-}" ]; then
    if curl -fsS --max-time 15 --retry 2 --retry-delay 3 -o /dev/null "$BACKUP_HEARTBEAT_URL"; then
        G "  ✓ heartbeat inviato al monitor"
    else
        echo "  ⚠ heartbeat NON inviato — il backup e' comunque riuscito." >&2
    fi
fi
