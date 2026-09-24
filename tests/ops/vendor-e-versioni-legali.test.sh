#!/bin/bash
#
# Prove dei passi 2-bis e 8-ter di `tools/webhook/deploy-container.sh`, nei due
# versi (23/9/2026): il rilascio aggiorna il vendor/ dell'host **solo** quando
# cambiano composer.json o composer.lock, e allinea a ogni giro le versioni
# legali del database a docs/legal/versions.json. Se uno dei due fallisce
# avvisa e lascia una riga fra le anomalie, **senza fermare il rilascio**.
#
# Perché. Il rilascio vecchio (deploy.sh) faceva tutte e due le cose; passando
# ai container (8/9/2026) sono andate perse, e nessuno se n'è accorto: in
# produzione `legal_document_versions` era fermo a Termini e AUP 1.3, mentre
# versions.json porta dal 22/9 i Termini alla 1.5 e l'AUP alla 1.4 (A-14 della
# revisione del 23/9/2026).
#
# Perché il vendor ha un segno che sopravvive al giro. Il `reset --hard` del
# passo 1 viene prima di ogni uscita anticipata: un rilascio che porta un
# composer.lock nuovo e poi esce (il rinvio al differito del passo 2, il build
# fallito) lascerebbe il vendor indietro, e il rilascio dopo, confrontando due
# commit fra cui composer non c'è più, direbbe «non è cambiato». È il difetto
# che il passo del TeX si è tolto il 20/9 (tests/ops/sincronizza-tex.test.sh).
#
# Aggiunte dopo la verifica del 23/9: un composer che si appende si ferma dopo
# un tetto di tempo (`timeout` vero, composer finto che dorme) e il giro dopo
# ci riprova; il segno vero sta in una cartella di root, nasce 0700 ed è
# escluso da AIDE; un segno che non si scrive dà solo l'avviso, senza la riga
# grezza di bash.
#
# Niente root e niente macchina vera: un repository git in una cartella
# temporanea e, al posto di sudo, composer e php, dei comandi finti che
# annotano come sono stati chiamati. `sudo` finto esegue davvero il comando
# (senza cambiare utente), così la riga che arriva a composer e a php è quella
# che scrive lo script. Le funzioni sono quelle vere, prese dallo script fra i
# segni `>>> host` e `<<< host` (e `>>> tex` / `<<< tex`, dove sta
# `anomalia_rilascio`).
#
# Uso: bash tests/ops/vendor-e-versioni-legali.test.sh
#      SCRIPT=<altro script> bash tests/ops/vendor-e-versioni-legali.test.sh   (per provarne un'altra versione)
#      AIDE_CONF=<altre regole> bash tests/ops/vendor-e-versioni-legali.test.sh  (idem per le regole di AIDE)
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
SCRIPT="${SCRIPT:-$QUI/../../tools/webhook/deploy-container.sh}"
T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/vendor-legali.XXXXXX")
trap 'rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

# ── Il repository: un commit per caso ─────────────────────────────────────
R="$T/repo"
git init -q "$R"
mkdir -p "$R/app" "$R/tools/legal" "$R/esempi"
printf '{"require":{"php":"^8.3"}}\n' > "$R/composer.json"
printf '{"content-hash":"1"}\n' > "$R/composer.lock"
printf '<?php\n' > "$R/app/altro.php"
printf '<?php // lo script vero non gira: al suo posto c e php finto\n' > "$R/tools/legal/sync_versions.php"
# Un composer.json che non è quello del progetto: il confronto guarda il nome
# intero dalla radice, non la fine del percorso.
printf '{}\n' > "$R/esempi/composer.json"
# vendor/ è ignorato da git, come nel repository vero: il reset non lo tocca.
printf 'vendor/\n' > "$R/.gitignore"
fai_commit() { git -C "$R" add -A && git -C "$R" -c user.name=prova -c user.email=prova@example.invalid commit -qm "$1" && git -C "$R" rev-parse HEAD; }
C0=$(fai_commit iniziale)
printf '<?php // 2\n' > "$R/app/altro.php"
C1=$(fai_commit "solo l'applicazione")
printf '{"content-hash":"2"}\n' > "$R/composer.lock"
C2=$(fai_commit "composer.lock")
printf '<?php // 3\n' > "$R/app/altro.php"
C3=$(fai_commit "di nuovo solo l'applicazione")
printf '{"require":{"php":"^8.4"}}\n' > "$R/composer.json"
C4=$(fai_commit "composer.json")
printf '{"name":"esempio"}\n' > "$R/esempi/composer.json"
C5=$(fai_commit "un composer.json che non è quello del progetto")
git -C "$R" rm -q tools/legal/sync_versions.php
C6=$(fai_commit "senza sync_versions.php")
FINTO_PRIMA=0123456789abcdef0123456789abcdef01234567   # un commit che non esiste

# ── I comandi finti ───────────────────────────────────────────────────────
mkdir -p "$T/bin"
cat > "$T/bin/sudo" <<'FINTO'
#!/bin/bash
echo "sudo $*" >> "$REGISTRO"
[ "${1:-}" = "-u" ] || exit 1
shift 2
exec "$@"
FINTO

cat > "$T/bin/composer" <<'FINTO'
#!/bin/bash
echo "composer $* (in $PWD)" >> "$REGISTRO"
# Un composer appeso: dorme quanto dice il file, poi finisce bene.
if [ -f "$STATO/composer-lento" ]; then
    sleep "$(cat "$STATO/composer-lento")"
fi
# Un composer ucciso da fuori con SIGKILL (l'OOM killer), subito.
if [ -f "$STATO/composer-ucciso" ]; then
    kill -9 $$
fi
if [ -f "$STATO/composer-rotto" ]; then
    echo "Your requirements could not be resolved to an installable set of packages."
    exit 2
fi
mkdir -p vendor/composer
printf '<?php\n' > vendor/autoload.php
echo "Installing dependencies from lock file"
echo "composer finito" >> "$REGISTRO"
exit 0
FINTO

cat > "$T/bin/php" <<'FINTO'
#!/bin/bash
echo "php $* (in $PWD)" >> "$REGISTRO"
if [ -f "$STATO/php-rotto" ]; then
    echo "DB non disponibile." >&2
    exit 1
fi
echo "[ADD] tos 1.4 — in vigore dal 2026-09-22"
echo "APPLY — aggiunte: 1, aggiornate: 0, invariate: 7"
exit 0
FINTO
chmod +x "$T/bin/"*

export REGISTRO="$T/comandi.log" STATO="$T/stato"
mkdir -p "$STATO"
SEGNO="$T/rilascio/vendor-da-aggiornare"

# ── Le funzioni vere ──────────────────────────────────────────────────────
sed -n '/^# >>> tex:/,/^# <<< tex/p; /^# >>> host:/,/^# <<< host/p' "$SCRIPT" > "$T/funzioni.sh"
for f in anomalia_rilascio segna_vendor passo_vendor passo_versioni_legali; do
    # Non si esce: le verifiche qui sotto diranno una per una che cosa manca.
    grep -q "^$f()" "$T/funzioni.sh" || echo "  (non trovo $f fra i segni >>> host / <<< host, o >>> tex / <<< tex, in $SCRIPT)"
done

# Un rilascio da PRIMA a DOPO, come lo esegue lo script vero con `set -euo
# pipefail`: il segno del passo 1, il reset, il passo 2-bis, il passo 8-ter.
# Con `esce-al-passo-2` si ferma dopo il reset, come il rinvio al differito.
# Stampa quello che i passi dicono; i comandi chiamati finiscono in $REGISTRO.
# Le variabili che imposta le leggono le funzioni caricate con `.`, che
# l'analisi di shellcheck non segue.
# shellcheck disable=SC2034
lancia() {
    local prima="$1" dopo="$2" fin_dove="${3:-tutto}"
    : > "$REGISTRO"
    rm -rf "$T/dati"
    mkdir -p "$T/dati/storage/logs"
    git -C "$R" checkout -q --detach "$prima" 2>/dev/null || true
    (
        set -euo pipefail
        PATH="$T/bin:$PATH"
        REPO_DIR="$R"
        DATI="$T/dati"
        UTENTE_GIT="pantedu"
        VENDOR_DA_AGGIORNARE="${SEGNO_PROVA:-$SEGNO}"
        COMMIT="$dopo"
        nota() { echo "NOTA $*"; }
        avviso() { echo "AVVISO $*"; }
        git_come_proprietario() { git -C "$REPO_DIR" "$@"; }
        # shellcheck source=/dev/null
        . "$T/funzioni.sh"
        segna_vendor "$prima" "$dopo"
        git -C "$REPO_DIR" checkout -q --detach "$dopo"     # il reset --hard
        if [ "$fin_dove" = "esce-al-passo-2" ]; then echo "USCITA 0"; exit 0; fi
        passo_vendor
        passo_versioni_legali
        echo "USCITA $?"
    ) 2>&1
}
chiamato() { grep -qF -- "$1" "$REGISTRO"; }
anomalie() { cat "$T/dati/storage/logs/anomalie.jsonl" 2>/dev/null || true; }
# Una riga rotta la diagnostica la conta come messaggio perso.
json_valido() { python3 -c 'import json,sys; [json.loads(r) for r in open(sys.argv[1]) if r.strip()]' "$T/dati/storage/logs/anomalie.jsonl" 2>/dev/null; }
COMPOSER_ATTESO="composer install --no-dev --no-interaction --prefer-dist (in $R)"
SYNC_ATTESO="php tools/legal/sync_versions.php --apply (in $R)"

echo "── Cambia solo l'applicazione: il vendor non si tocca, le versioni legali sì"
USCITA=$(lancia "$C0" "$C1")
if ! chiamato 'composer '; then ok; else ko "composer chiamato senza che composer.* fosse cambiato: $(cat "$REGISTRO")"; fi
if grep -q 'non sono cambiati' <<<"$USCITA"; then ok; else ko "il passo non dice che il vendor resta com'è: $USCITA"; fi
if chiamato "sudo -u pantedu bash -c cd '$R' && php tools/legal/sync_versions.php --apply"; then ok; else ko "sync_versions non chiamato da pantedu nel sorgente: $(cat "$REGISTRO")"; fi
if chiamato "$SYNC_ATTESO"; then ok; else ko "sync_versions non chiamato con --apply: $(cat "$REGISTRO")"; fi
if grep -q '^\[legal\] \[ADD\] tos 1.4' <<<"$USCITA"; then ok; else ko "le righe di sync_versions non arrivano nel registro come [legal]: $USCITA"; fi
if grep -q 'versioni legali allineate' <<<"$USCITA"; then ok; else ko "$USCITA"; fi
if grep -q '^USCITA 0' <<<"$USCITA"; then ok; else ko "uscita: $USCITA"; fi
if [ -z "$(anomalie)" ]; then ok; else ko "anomalia scritta senza guasto: $(anomalie)"; fi
if [ ! -f "$SEGNO" ]; then ok; else ko "segno lasciato senza motivo"; fi

echo "── Cambia composer.lock: composer install da pantedu, nel sorgente"
USCITA=$(lancia "$C1" "$C2")
if chiamato "sudo -u pantedu bash -c cd '$R' && exec timeout --kill-after=15 '60' composer install --no-dev --no-interaction --prefer-dist"; then ok; else ko "composer non chiamato da pantedu nel sorgente, con il tetto di tempo: $(cat "$REGISTRO")"; fi
if chiamato "$COMPOSER_ATTESO"; then ok; else ko "composer non chiamato come atteso: $(cat "$REGISTRO")"; fi
if grep -q '^\[composer\] Installing dependencies' <<<"$USCITA"; then ok; else ko "le righe di composer non arrivano nel registro: $USCITA"; fi
if grep -q "vendor dell'host aggiornato" <<<"$USCITA"; then ok; else ko "$USCITA"; fi
if chiamato "$SYNC_ATTESO"; then ok; else ko "con composer cambiato le versioni legali non si allineano più"; fi
if [ -z "$(anomalie)" ]; then ok; else ko "anomalia scritta senza guasto: $(anomalie)"; fi
if [ ! -f "$SEGNO" ]; then ok; else ko "il segno resta dopo un install riuscito"; fi
if grep -q '^USCITA 0' <<<"$USCITA"; then ok; else ko "uscita: $USCITA"; fi
# Controprova: lo stesso commit rilasciato di nuovo (FORZA) non reinstalla.
USCITA=$(lancia "$C2" "$C2")
if ! chiamato 'composer '; then ok; else ko "rilasciando di nuovo lo stesso commit ha reinstallato: $(cat "$REGISTRO")"; fi

echo "── Cambia solo composer.json: si installa lo stesso"
USCITA=$(lancia "$C3" "$C4")
if chiamato "$COMPOSER_ATTESO"; then ok; else ko "composer.json cambiato e composer non chiamato: $(cat "$REGISTRO")"; fi

echo "── Cambia un composer.json che non è quello del progetto: niente"
USCITA=$(lancia "$C4" "$C5")
if ! chiamato 'composer '; then ok; else ko "esempi/composer.json ha fatto partire composer: $(cat "$REGISTRO")"; fi

echo "── Il rilascio esce prima del passo 2-bis: il rilascio dopo aggiorna lo stesso"
# Il caso che un confronto fatto solo fra i due commit del giro non vede: il
# giro che porta composer.lock esce dopo il reset (rinvio al differito, build
# fallito); il giro dopo va da C2 a C3, fra cui composer non c'è.
USCITA=$(lancia "$C1" "$C2" esce-al-passo-2)
if ! chiamato 'composer '; then ok; else ko "prova mal posta: il giro uscito al passo 2 ha chiamato composer"; fi
if [ -f "$SEGNO" ]; then ok; else ko "il giro uscito dopo il reset non ha lasciato il segno: il vendor resterebbe indietro"; fi
USCITA=$(lancia "$C2" "$C3")
if chiamato "$COMPOSER_ATTESO"; then ok; else ko "il vendor rimasto indietro non è stato aggiornato: $USCITA"; fi
if grep -q 'un rilascio precedente non ha aggiornato' <<<"$USCITA"; then ok; else ko "il passo non dice perché installa: $USCITA"; fi
if [ ! -f "$SEGNO" ]; then ok; else ko "il segno resta dopo l'install"; fi
# Controprova: senza segno, lo stesso giro non installa.
USCITA=$(lancia "$C2" "$C3")
if ! chiamato 'composer '; then ok; else ko "senza segno e senza composer.* cambiati ha installato: $(cat "$REGISTRO")"; fi

echo "── composer fallisce: avviso, anomalia, il rilascio prosegue e il giro dopo ci riprova"
touch "$STATO/composer-rotto"
USCITA=$(lancia "$C1" "$C2")
rm -f "$STATO/composer-rotto"
if grep -q '^AVVISO composer install è fallito (uscita 2)' <<<"$USCITA"; then ok; else ko "nessun avviso: $USCITA"; fi
if grep -q '^\[composer\] Your requirements' <<<"$USCITA"; then ok; else ko "il motivo del fallimento non arriva nel registro: $USCITA"; fi
if grep -q '^USCITA 0' <<<"$USCITA"; then ok; else ko "il fallimento di composer ha fermato il rilascio: $USCITA"; fi
if grep -q '"codice":"vendor_host"' <<<"$(anomalie)"; then ok; else ko "nessuna riga fra le anomalie: $(anomalie)"; fi
if json_valido; then ok; else ko "la riga non è JSON valido: $(anomalie)"; fi
if chiamato "$SYNC_ATTESO"; then ok; else ko "dopo composer fallito le versioni legali non si allineano"; fi
if [ -f "$SEGNO" ]; then ok; else ko "nessun segno da cui ripartire al rilascio dopo"; fi
USCITA=$(lancia "$C2" "$C3")
if chiamato "$COMPOSER_ATTESO"; then ok; else ko "l'install fallito non è stato ripreso: $USCITA"; fi
if [ ! -f "$SEGNO" ]; then ok; else ko "il segno resta dopo un giro riuscito"; fi

echo "── Il confronto fra i due commit non si può fare: si aggiorna lo stesso"
USCITA=$(lancia "$FINTO_PRIMA" "$C3")
if grep -q '^AVVISO non riesco a confrontare' <<<"$USCITA"; then ok; else ko "il confronto impossibile è passato in silenzio: $USCITA"; fi
if chiamato "$COMPOSER_ATTESO"; then ok; else ko "«non ho potuto guardare» trattato come «non è cambiato»: $(cat "$REGISTRO")"; fi
if grep -q '^USCITA 0' <<<"$USCITA"; then ok; else ko "uscita: $USCITA"; fi

echo "── composer si appende: il tetto lo ferma, il rilascio prosegue e il giro dopo ci riprova"
# Il rilascio ha quindici minuti in tutto, e un composer che non torna lo
# farebbe uccidere da systemd a metà. Qui composer dorme 30 secondi con un
# tetto di 2: senza tetto la prova dura più di 30 secondi e composer finisce.
rm -f "$SEGNO"
echo 30 > "$STATO/composer-lento"
INIZIO_PROVA=$SECONDS
USCITA=$(COMPOSER_TETTO=2 lancia "$C1" "$C2")
DURATA=$((SECONDS - INIZIO_PROVA))
rm -f "$STATO/composer-lento"
if [ "$DURATA" -lt 20 ]; then ok; else ko "il composer appeso ha tenuto fermo il rilascio ${DURATA} s: il tetto di 2 s non l'ha fermato"; fi
if ! chiamato 'composer finito'; then ok; else ko "composer è arrivato in fondo: il tetto non l'ha fermato"; fi
if grep -q '^AVVISO composer install non ha finito in 2 secondi ed è stato fermato (uscita 124)' <<<"$USCITA"; then ok; else ko "nessun avviso sul tetto: $USCITA"; fi
if grep -q '^USCITA 0' <<<"$USCITA"; then ok; else ko "il tetto ha fermato il rilascio: $USCITA"; fi
if grep -q '"codice":"vendor_host"' <<<"$(anomalie)"; then ok; else ko "nessuna riga fra le anomalie: $(anomalie)"; fi
if json_valido; then ok; else ko "la riga non è JSON valido: $(anomalie)"; fi
if [ -f "$SEGNO" ]; then ok; else ko "nessun segno da cui ripartire dopo il tetto"; fi
if chiamato "$SYNC_ATTESO"; then ok; else ko "dopo il tetto le versioni legali non si allineano"; fi
USCITA=$(lancia "$C2" "$C3")
if chiamato 'composer finito'; then ok; else ko "il giro dopo il tetto non ha ripreso composer: $USCITA"; fi
if [ ! -f "$SEGNO" ]; then ok; else ko "il segno resta dopo il giro riuscito"; fi
# Controprova: lento, ma sotto il tetto. Non si ferma e non avvisa.
echo 1 > "$STATO/composer-lento"
USCITA=$(COMPOSER_TETTO=10 lancia "$C1" "$C2")
rm -f "$STATO/composer-lento"
if chiamato 'composer finito'; then ok; else ko "composer sotto il tetto fermato lo stesso: $USCITA"; fi
if ! grep -q 'non ha finito' <<<"$USCITA"; then ok; else ko "avviso del tetto senza tetto superato: $USCITA"; fi
if [ -z "$(anomalie)" ]; then ok; else ko "anomalia scritta senza guasto: $(anomalie)"; fi
if [ ! -f "$SEGNO" ]; then ok; else ko "segno lasciato dopo un install riuscito"; fi

echo "── composer ucciso con SIGKILL prima del tetto: 137, ma l'avviso non parla del tetto"
# `timeout` restituisce 137 anche quando il figlio muore di SIGKILL per mano
# d'altri: dire «non ha finito in N secondi» manderebbe a cercare nel posto
# sbagliato.
rm -f "$SEGNO"
touch "$STATO/composer-ucciso"
USCITA=$(COMPOSER_TETTO=60 lancia "$C1" "$C2")
rm -f "$STATO/composer-ucciso"
if grep -q '^AVVISO composer install è stato ucciso con SIGKILL dopo [0-9]* secondi, prima del tetto' <<<"$USCITA"; then ok; else ko "il SIGKILL da fuori non ha il suo avviso: $USCITA"; fi
if ! grep -q 'non ha finito in 60 secondi' <<<"$USCITA"; then ok; else ko "il SIGKILL da fuori è scambiato per il tetto: $USCITA"; fi
if grep -q '^USCITA 0' <<<"$USCITA"; then ok; else ko "il SIGKILL ha fermato il rilascio: $USCITA"; fi
if [ -f "$SEGNO" ]; then ok; else ko "nessun segno da cui ripartire dopo il SIGKILL"; fi

echo "── Il segno non si può scrivere: l'avviso, senza la riga grezza di bash, e composer nello stesso giro"
# Il segno sotto un file normale: né mkdir né la scrittura possono riuscire,
# anche da root.
printf 'non una cartella\n' > "$T/un-file"
USCITA=$(SEGNO_PROVA="$T/un-file/vendor-da-aggiornare" lancia "$C1" "$C2")
if grep -q '^AVVISO non riesco a scrivere' <<<"$USCITA"; then ok; else ko "nessun avviso sul segno che non si scrive: $USCITA"; fi
if ! grep -qE 'Not a directory|No such file|line [0-9]+:' <<<"$USCITA"; then ok; else ko "nel registro c'è l'errore grezzo della redirezione: $(grep -E 'Not a directory|No such file|line [0-9]+:' <<<"$USCITA")"; fi
if chiamato "$COMPOSER_ATTESO"; then ok; else ko "senza segno composer non è partito nello stesso giro: $(cat "$REGISTRO")"; fi
if grep -q '^USCITA 0' <<<"$USCITA"; then ok; else ko "uscita: $USCITA"; fi

echo "── Il segno vero: dove sta, con quali permessi, e fuori da AIDE"
# La prova in `lancia` sposta il segno in una cartella temporanea: qui si
# guarda il valore che lo script usa in produzione. In una cartella dove
# scrive il webhook (www-data, /var/lib/pantedu-deploy) chiunque arrivi al
# webhook potrebbe far ripartire composer a ogni rilascio.
PREDEFINITI=$(env -u VENDOR_DA_AGGIORNARE -u COMPOSER_TETTO bash -c '. "$1" && printf "%s|%s" "$VENDOR_DA_AGGIORNARE" "$COMPOSER_TETTO"' _ "$T/funzioni.sh" 2>&1)
SEGNO_VERO=${PREDEFINITI%%|*}
TETTO_VERO=${PREDEFINITI#*|}
if [ "$SEGNO_VERO" = /var/lib/pantedu-rilascio/vendor-da-aggiornare ]; then ok; else ko "il segno non sta più nella cartella di root /var/lib/pantedu-rilascio: «$SEGNO_VERO»"; fi
# Sotto il minuto si fermerebbe un install buono su una macchina lenta
# (misurato in WSL: 7 s con la cache vuota); sopra il minuto e mezzo il
# percorso peggiore del rilascio sfora i quindici dell'unità (il conto è nello
# script).
if [[ "$TETTO_VERO" =~ ^[0-9]+$ ]] && [ "$TETTO_VERO" -ge 60 ] && [ "$TETTO_VERO" -le 90 ]; then ok; else ko "il tetto di composer predefinito non è fra 60 e 90 secondi: «$TETTO_VERO»"; fi
# Il segno vuoto la prima volta, poi i permessi: nasce 0700, e torna 0700 se
# qualcuno ha allargato la cartella. Le variabili le leggono le funzioni
# caricate con `.`, che shellcheck non segue.
# shellcheck disable=SC2034
metti_segno() (
    set -euo pipefail
    DATI="$T/dati"
    VENDOR_DA_AGGIORNARE="$1"
    avviso() { echo "AVVISO $*"; }
    # shellcheck source=/dev/null
    . "$T/funzioni.sh"
    umask 022
    lascia_segno_vendor
)
metti_segno "$T/segni-nuovi/vendor-da-aggiornare" >/dev/null 2>&1
MODO=$(stat -c %a "$T/segni-nuovi" 2>/dev/null || echo assente)
if [ "$MODO" = 700 ]; then ok; else ko "la cartella del segno è nata con $MODO invece di 700"; fi
if [ -f "$T/segni-nuovi/vendor-da-aggiornare" ]; then ok; else ko "il segno non è stato scritto nella cartella nuova"; fi
mkdir -p "$T/segni-larghi" && chmod 0777 "$T/segni-larghi"
metti_segno "$T/segni-larghi/vendor-da-aggiornare" >/dev/null 2>&1
MODO=$(stat -c %a "$T/segni-larghi" 2>/dev/null || echo assente)
if [ "$MODO" = 700 ]; then ok; else ko "la cartella del segno allargata a 777 è rimasta $MODO"; fi
# AIDE: la cartella cambia per mestiere e il segno rimasto lo dice già
# l'anomalia vendor_host. Senza l'esclusione, la prima volta che il rilascio la
# crea il controllo della notte avrebbe una riga «aggiunta» da guardare, tutte
# le notti fino a quando qualcuno rifà la base.
AIDE_CONF="${AIDE_CONF:-$QUI/../../tools/ops/aide-99_pantedu.conf}"
if grep -qxF "!$(dirname "$SEGNO_VERO")(/|\$)" "$AIDE_CONF"; then ok; else ko "$(dirname "$SEGNO_VERO") non è esclusa in $AIDE_CONF (serve la riga «!$(dirname "$SEGNO_VERO")(/|\$)»)"; fi

echo "── sync_versions fallisce: avviso, anomalia, il rilascio prosegue"
touch "$STATO/php-rotto"
USCITA=$(lancia "$C0" "$C1")
rm -f "$STATO/php-rotto"
if grep -q "^AVVISO l'allineamento delle versioni legali è fallito (uscita 1)" <<<"$USCITA"; then ok; else ko "nessun avviso: $USCITA"; fi
if grep -q '^\[legal\] DB non disponibile' <<<"$USCITA"; then ok; else ko "il motivo del fallimento non arriva nel registro come [legal]: $USCITA"; fi
if grep -q '^USCITA 0' <<<"$USCITA"; then ok; else ko "il fallimento di sync_versions ha fermato il rilascio: $USCITA"; fi
if grep -q '"codice":"legal_versioni"' <<<"$(anomalie)"; then ok; else ko "nessuna riga fra le anomalie: $(anomalie)"; fi
if json_valido; then ok; else ko "la riga non è JSON valido: $(anomalie)"; fi
if ! grep -q 'versioni legali allineate' <<<"$USCITA"; then ok; else ko "dice «allineate» dopo un fallimento: $USCITA"; fi

echo "── sync_versions.php non c'è nel sorgente: lo si dice"
USCITA=$(lancia "$C5" "$C6")
if grep -q '^AVVISO tools/legal/sync_versions.php non c.è' <<<"$USCITA"; then ok; else ko "lo script mancante è passato in silenzio: $USCITA"; fi
if ! chiamato 'php '; then ok; else ko "ha chiamato php su uno script che non c'è: $(cat "$REGISTRO")"; fi
if grep -q '"codice":"legal_versioni"' <<<"$(anomalie)"; then ok; else ko "nessuna riga fra le anomalie: $(anomalie)"; fi
if grep -q '^USCITA 0' <<<"$USCITA"; then ok; else ko "uscita: $USCITA"; fi

echo "── Dove stanno i passi dentro lo script"
# Non è stile. Il segno va lasciato prima del reset, o un rilascio che esce
# dopo il reset lo perde. Il vendor va aggiornato prima delle migrazioni, che
# lo caricano: dopo, una migrazione fallita per il vendor vecchio fermerebbe il
# rilascio prima di arrivarci, a ogni giro. Le versioni legali vanno allineate
# dopo l'ultimo punto da cui lo scambio si annulla, o il container vecchio
# tornerebbe in servizio con i testi vecchi e il database già alla versione
# nuova. Si misura sulle righe, e solo sulle chiamate fuori dalle funzioni.
riga_di() { grep -n -- "$1" "$SCRIPT" | tail -1 | cut -d: -f1; }
PRIMA_LETTA=$(riga_di '^PRIMA=\$(git_come_proprietario rev-parse HEAD)')
SEGNA=$(riga_di '^segna_vendor "\$PRIMA" "\$COMMIT"$')
RESET=$(riga_di '^git_come_proprietario reset --hard')
VENDOR=$(riga_di '^passo_vendor$')
ISTANTANEA=$(riga_di '^if mysqldump ')
MIGRAZIONI=$(riga_di "&& php tools/migrate.php\"")
ULTIMO_RITORNO=$(riga_di '^ *rimetti_upstream$')
LEGALI=$(riga_di '^passo_versioni_legali$')
if [ -n "$SEGNA" ] && [ -n "$PRIMA_LETTA" ] && [ -n "$RESET" ] && [ "$PRIMA_LETTA" -lt "$SEGNA" ] && [ "$SEGNA" -lt "$RESET" ]; then ok
else ko "segna_vendor (riga ${SEGNA:-assente}) deve stare fra la lettura di PRIMA (riga ${PRIMA_LETTA:-?}) e il reset --hard (riga ${RESET:-?})"; fi
if [ -n "$VENDOR" ] && [ -n "$ISTANTANEA" ] && [ -n "$MIGRAZIONI" ] && [ "$VENDOR" -lt "$ISTANTANEA" ] && [ "$VENDOR" -lt "$MIGRAZIONI" ]; then ok
else ko "passo_vendor (riga ${VENDOR:-assente}) deve stare prima dell'istantanea (riga ${ISTANTANEA:-?}) e delle migrazioni (riga ${MIGRAZIONI:-?})"; fi
if [ -n "$LEGALI" ] && [ -n "$ULTIMO_RITORNO" ] && [ -n "$MIGRAZIONI" ] && [ "$LEGALI" -gt "$ULTIMO_RITORNO" ] && [ "$LEGALI" -gt "$MIGRAZIONI" ]; then ok
else ko "passo_versioni_legali (riga ${LEGALI:-assente}) deve stare dopo le migrazioni (riga ${MIGRAZIONI:-?}) e dopo l'ultimo rimetti_upstream (riga ${ULTIMO_RITORNO:-?})"; fi

echo
echo "$PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
