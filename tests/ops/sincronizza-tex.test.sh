#!/bin/bash
#
# Prove del passo 7-bis di `tools/webhook/deploy-container.sh`, nei due versi
# (19/9/2026, riviste il 20/9): il rilascio porta in /opt il codice del servizio
# TeX **solo** se quello che c'è è diverso, lo riavvia, lo sonda, e se qualcosa
# va storto avvisa, lascia una riga fra le anomalie e un segno per riprovare al
# rilascio dopo — senza fermare il rilascio.
#
# Perché. Il rilascio vecchio (deploy.sh, passo 5) lo faceva; passando ai
# container (8/9/2026) il passo non è stato portato, e una correzione al
# servizio TeX sarebbe rimasta nel repository senza che niente lo dicesse.
#
# Perché **sui file e non sui commit** (20/9/2026). Al primo giro il passo
# decideva con `git diff PRIMA COMMIT`, dove PRIMA era la HEAD del sorgente. Ma
# il passo 1 fa `git reset --hard` prima di ogni uscita anticipata — il rinvio
# al differito del passo 2, il build fallito, il container malato del passo 6 —
# quindi un rilascio che porta codice del TeX e poi esce lascia /opt indietro, e
# il rilascio dopo confronta due commit fra cui non c'è più niente da vedere.
# Nessun avviso, nessuna anomalia: solo /opt che serve codice vecchio. È lo
# stesso difetto che `deploy.sh` si è tolto il 24/5 e il 31/8/2026 passando da
# «diff-gated» a «sempre idempotente» per le unità systemd.
#
# Niente root e niente macchina vera: un repository git in una cartella
# temporanea, un finto /opt, e al posto di rsync, chown, install, sudo,
# systemctl, docker e curl dei comandi finti che annotano come sono stati
# chiamati. `rsync` e `install` finti **copiano davvero** i file, perché la
# prova dopo possa chiedersi se il rilascio successivo trova ancora qualcosa da
# fare. Le funzioni sono quelle vere, prese dallo script fra i segni `>>> tex` e
# `<<< tex`.
#
# Uso: bash tests/ops/sincronizza-tex.test.sh
#      SCRIPT=<altro script> bash tests/ops/sincronizza-tex.test.sh   (per provarne un'altra versione)
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
SCRIPT="${SCRIPT:-$QUI/../../tools/webhook/deploy-container.sh}"
T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/sincronizza-tex.XXXXXX")
trap 'rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
SALTATE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }
# Una verifica che non si può fare va detta, non contata fra quelle passate.
saltata() { SALTATE=$((SALTATE + 1)); echo "  SALTATA: $*"; }

# ── Il repository: un commit per caso ─────────────────────────────────────
R="$T/repo"
git init -q "$R"
mkdir -p "$R/tools/tex-compile-vps/app" "$R/app"
printf 'x = 1\n' > "$R/tools/tex-compile-vps/app/main.py"
printf 'fastapi==0.115.6\n' > "$R/tools/tex-compile-vps/app/requirements.txt"
printf 'il servizio\n' > "$R/tools/tex-compile-vps/DEPLOY.md"
printf '<?php\n' > "$R/app/altro.php"
fai_commit() { git -C "$R" add -A && git -C "$R" -c user.name=prova -c user.email=prova@example.invalid commit -qm "$1" && git -C "$R" rev-parse HEAD; }
C0=$(fai_commit iniziale)
# Cambia l'applicazione e la documentazione del servizio, non il suo codice.
printf '<?php // 2\n' > "$R/app/altro.php"
printf 'il servizio, spiegato meglio\n' > "$R/tools/tex-compile-vps/DEPLOY.md"
fai_commit "solo l'applicazione" > /dev/null
printf 'x = 2\n' > "$R/tools/tex-compile-vps/app/main.py"
C2=$(fai_commit "il codice del servizio")
printf 'fastapi==0.115.7\n' > "$R/tools/tex-compile-vps/app/requirements.txt"
C3=$(fai_commit "le dipendenze del servizio")
# Un modulo in una sottocartella: oggi il servizio è piatto, ma il primo che
# arriva non deve restare nel repository.
mkdir -p "$R/tools/tex-compile-vps/app/sub"
printf 'y = 1\n' > "$R/tools/tex-compile-vps/app/sub/router.py"
C4=$(fai_commit "un modulo in una sottocartella")

# L'albero di lavoro del sorgente, come lo lascia il `reset --hard` del passo 1.
al_commit() { git -C "$R" checkout -q --detach "$1"; }

# Il finto /opt/tex-compile/app, con il codice di un commit — o inesistente.
prepara_opt() {
    rm -rf "$T/opt"
    [ "$1" = "assente" ] && return 0
    mkdir -p "$T/opt/app"
    git -C "$R" archive "$1" tools/tex-compile-vps/app \
        | tar -x -C "$T/opt/app" --strip-components=3
}

# ── I comandi finti ───────────────────────────────────────────────────────
mkdir -p "$T/bin"
for comando in chown sudo systemctl docker curl; do
    cat > "$T/bin/$comando" <<'FINTO'
#!/bin/bash
nome=$(basename "$0")
echo "$nome $*" >> "$REGISTRO"
case "$nome" in
    docker) echo "gateway-finto" ;;
    curl)
        [ -f "$STATO/tex-muto" ] && exit 7
        echo '{"status":"ok","service":"tex-compile-vps","version":"1.4.1"}' ;;
esac
exit 0
FINTO
    chmod +x "$T/bin/$comando"
done

# rsync e install copiano davvero: `rsync -a --include='*.py' --exclude='*'
# SORGENTE/ DESTINAZIONE/` porta i .py e lascia stare il resto, ed è quello che
# serve perché il giro dopo trovi /opt allineato.
cat > "$T/bin/rsync" <<'FINTO'
#!/bin/bash
echo "rsync $*" >> "$REGISTRO"
sorgente="${@: -2:1}"; destinazione="${@: -1}"
# Come rsync vero: una sorgente che non c'e' e' un errore, non un no-op.
[ -d "$sorgente" ] || exit 3
[ -d "$destinazione" ] || exit 0
# Ricorsivo, come `--include='*/' --include='*.py' --exclude='*'`. Se lo
# script vero perdesse `--include='*/'` tornerebbe a non scendere nelle
# sottocartelle, e la prova del modulo in `sub/` se ne accorge.
case "$*" in *"--include=*/"*) ricorsivo=1 ;; *) ricorsivo="" ;; esac
while IFS= read -r f; do
    rel="${f#"$sorgente"}"
    case "$rel" in */*) [ -n "$ricorsivo" ] || continue ;; esac
    mkdir -p "$destinazione$(dirname "$rel")"
    cp -p "$f" "$destinazione$rel"
done < <(find "$sorgente" -type f -name '*.py' 2>/dev/null)
exit 0
FINTO
chmod +x "$T/bin/rsync"

cat > "$T/bin/install" <<'FINTO'
#!/bin/bash
echo "install $*" >> "$REGISTRO"
sorgente="${@: -2:1}"; destinazione="${@: -1}"
[ -f "$sorgente" ] && cp -p "$sorgente" "$destinazione" 2>/dev/null
exit 0
FINTO
chmod +x "$T/bin/install"

export REGISTRO="$T/comandi.log" STATO="$T/stato"
mkdir -p "$STATO"

# ── Le funzioni vere ──────────────────────────────────────────────────────
sed -n '/^# >>> tex:/,/^# <<< tex/p' "$SCRIPT" > "$T/funzioni.sh"
if ! grep -q '^passo_tex()' "$T/funzioni.sh"; then
    echo "non trovo passo_tex fra i segni >>> tex e <<< tex in $SCRIPT"
    exit 1
fi

# Il passo come lo esegue il rilascio, con `set -euo pipefail` come lo script
# vero. Stampa quello che il passo dice; i comandi chiamati finiscono in
# $REGISTRO. Le variabili che imposta le leggono le funzioni caricate con `.`,
# dove shellcheck non guarda.
# shellcheck disable=SC2034
lancia() {
    : > "$REGISTRO"
    rm -rf "$T/dati"
    mkdir -p "$T/dati/storage/logs"
    (
        set -euo pipefail
        PATH="$T/bin:$PATH"
        REPO_DIR="$R"
        DATI="$T/dati"
        TEX_DIR="$T/opt"
        COMMIT=$(git -C "$R" rev-parse HEAD)
        TEX_SONDA_PAUSA=0
        TEX_SONDA_TENTATIVI=3
        nota() { echo "NOTA $*"; }
        avviso() { echo "AVVISO $*"; }
        git_come_proprietario() { git -C "$REPO_DIR" "$@"; }
        # shellcheck source=/dev/null
        . "$T/funzioni.sh"
        passo_tex
        echo "USCITA $?"
    ) 2>&1
}
chiamato() { grep -q -- "$1" "$REGISTRO"; }
anomalie() { cat "$T/dati/storage/logs/anomalie.jsonl" 2>/dev/null || true; }
allineato() { cmp -s "$R/tools/tex-compile-vps/app/main.py" "$T/opt/app/main.py"; }

echo "── /opt ha già il codice del repository: niente si tocca"
al_commit "$C2"; prepara_opt "$C2"
USCITA=$(lancia)
if ! chiamato '^rsync' && ! chiamato '^systemctl'; then ok; else ko "con /opt già allineato è partita la sincronizzazione: $(cat "$REGISTRO")"; fi
if grep -q 'già quello del repository' <<<"$USCITA"; then ok; else ko "il passo non dice che non c'è niente da fare: $USCITA"; fi
if grep -q '^USCITA 0' <<<"$USCITA"; then ok; else ko "uscita: $USCITA"; fi

echo "── Il codice è diverso: copia, riavvio, sonda sul gateway del bridge"
al_commit "$C2"; prepara_opt "$C0"
USCITA=$(lancia)
if chiamato "^rsync -a -m --include=\*/ --include=\*.py --exclude=\* $R/tools/tex-compile-vps/app/ $T/opt/app/"; then ok; else ko "rsync non chiamato come atteso: $(cat "$REGISTRO")"; fi
if chiamato '^chown -R texcompile:texcompile'; then ok; else ko "chown non chiamato"; fi
if chiamato '^systemctl restart tex-compile'; then ok; else ko "il servizio non è stato riavviato"; fi
if chiamato '^curl -sf -m 3 http://gateway-finto:8001/health'; then ok; else ko "la sonda non ha chiesto al gateway del bridge: $(cat "$REGISTRO")"; fi
if ! chiamato '^sudo'; then ok; else ko "senza requirements.txt diverso ha reinstallato le dipendenze"; fi
if [ -z "$(anomalie)" ]; then ok; else ko "anomalia scritta senza guasto: $(anomalie)"; fi
if grep -q 'aggiornato, e risponde' <<<"$USCITA"; then ok; else ko "$USCITA"; fi
if allineato; then ok; else ko "dopo la sincronizzazione /opt non ha il codice del repository"; fi

echo "── Il rilascio dopo non rifà il lavoro"
USCITA=$(lancia)
if ! chiamato '^rsync' && ! chiamato '^systemctl'; then ok; else ko "il secondo rilascio ha risincronizzato: $(cat "$REGISTRO")"; fi

echo "── /opt è rimasto indietro: si sincronizza lo stesso"
# Il caso che il confronto fra commit non vedeva. L'unione che tocca il codice
# del servizio esce **dopo** il reset del passo 1 (rinvio al differito, build
# fallito, container malato): /opt resta a C0. L'unione dopo tocca solo
# l'applicazione, quindi fra i due commit di quel rilascio del servizio non c'è
# niente — ma /opt è indietro lo stesso.
al_commit "$C2"; prepara_opt "$C0"
USCITA=$(lancia)
if chiamato '^rsync'; then ok; else ko "/opt indietro e nessuna sincronizzazione: $USCITA"; fi
if chiamato '^systemctl restart tex-compile'; then ok; else ko "il servizio non è stato riavviato con /opt indietro"; fi
if grep -q 'main.py' <<<"$USCITA"; then ok; else ko "il passo non dice quale file è diverso: $USCITA"; fi
if allineato; then ok; else ko "/opt è rimasto indietro"; fi

echo "── Le dipendenze sono diverse: si reinstallano"
al_commit "$C3"; prepara_opt "$C2"
USCITA=$(lancia)
if chiamato '^install -m 644 -o texcompile -g texcompile'; then ok; else ko "requirements.txt non copiato"; fi
if chiamato "^sudo -u texcompile $T/opt/venv/bin/pip install --quiet --no-cache-dir -r $T/opt/app/requirements.txt"; then ok; else ko "pip non chiamato: $(cat "$REGISTRO")"; fi
# Controprova: al giro dopo sono uguali e non si reinstalla niente.
USCITA=$(lancia)
if ! chiamato '^sudo'; then ok; else ko "ha reinstallato le dipendenze già allineate: $(cat "$REGISTRO")"; fi

echo "── Il servizio non risponde dopo il riavvio: avviso, anomalia, segno per riprovare"
al_commit "$C2"; prepara_opt "$C0"
touch "$STATO/tex-muto"
USCITA=$(lancia)
rm -f "$STATO/tex-muto"
if [ "$(grep -c '^curl' "$REGISTRO")" -eq 3 ]; then ok; else ko "la sonda doveva riprovare tre volte: $(grep -c '^curl' "$REGISTRO")"; fi
if grep -q '^AVVISO il servizio TeX non è stato aggiornato bene' <<<"$USCITA"; then ok; else ko "nessun avviso: $USCITA"; fi
if grep -q '^USCITA 0' <<<"$USCITA"; then ok; else ko "il passo ha fermato il rilascio: $USCITA"; fi
if grep -q '"codice":"tex_sincronizzazione"' <<<"$(anomalie)"; then ok; else ko "nessuna riga fra le anomalie: $(anomalie)"; fi
# La riga dev'essere JSON valido: una riga rotta la diagnostica la conta come
# messaggio perso (AnomaliaRigheIllegibiliTest).
if python3 -c 'import json,sys; [json.loads(r) for r in open(sys.argv[1]) if r.strip()]' "$T/dati/storage/logs/anomalie.jsonl" 2>/dev/null; then ok; else ko "la riga non è JSON valido: $(anomalie)"; fi
if [ -f "$T/opt/.risincronizza" ]; then ok; else ko "nessun segno da cui ripartire al rilascio dopo"; fi

echo "── Una sincronizzazione fallita si riprova al rilascio dopo"
# I file adesso sono allineati (rsync li ha copiati prima che il riavvio
# fallisse): senza il segno, il rilascio dopo direbbe «niente da fare» e il
# servizio resterebbe fermo col codice vecchio in memoria.
if allineato; then ok; else ko "prova mal posta: /opt non è allineato"; fi
USCITA=$(lancia)
if chiamato '^systemctl restart tex-compile'; then ok; else ko "la sincronizzazione fallita non è stata ripresa: $USCITA"; fi
if grep -q 'non era riuscita' <<<"$USCITA"; then ok; else ko "il passo non dice perché ci riprova: $USCITA"; fi
if [ ! -f "$T/opt/.risincronizza" ]; then ok; else ko "il segno resta anche dopo un giro riuscito"; fi
# Controprova: adesso che il segno non c'è più e i file sono allineati, il
# rilascio dopo non tocca niente.
USCITA=$(lancia)
if ! chiamato '^systemctl'; then ok; else ko "ha riavviato senza motivo: $(cat "$REGISTRO")"; fi

echo "── Il servizio non è installato dove ci si aspetta: avviso, niente riavvio"
al_commit "$C2"; prepara_opt assente
USCITA=$(lancia)
if ! chiamato '^systemctl'; then ok; else ko "ha riavviato un servizio che non ha trovato"; fi
if grep -q 'non esiste' <<<"$USCITA" && grep -q '^USCITA 0' <<<"$USCITA"; then ok; else ko "$USCITA"; fi
if grep -q '"codice":"tex_sincronizzazione"' <<<"$(anomalie)"; then ok; else ko "nessuna riga fra le anomalie"; fi

echo "── Un modulo in una sottocartella arriva anche lui"
# `rsync -a --include='*.py' --exclude='*'` non scende nelle sottocartelle:
# `--exclude='*'` esclude anche le cartelle. Misurato il 20/9/2026 — con
# main.py, sub/router.py e requirements.txt in /opt arrivava solo main.py, e il
# passo diceva comunque «aggiornato».
al_commit "$C4"; prepara_opt "$C3"
USCITA=$(lancia)
if chiamato -- '--include=\*/'; then ok; else ko "rsync chiamato senza --include='*/': non scende nelle sottocartelle — $(cat "$REGISTRO")"; fi
if grep -q 'sub/router.py' <<<"$USCITA"; then ok; else ko "il passo non si è accorto del modulo nella sottocartella: $USCITA"; fi
if [ -f "$T/opt/app/sub/router.py" ]; then ok; else ko "il modulo nella sottocartella non è arrivato in /opt"; fi
# Controprova, e prova che anche il confronto scende: il giro dopo non fa più
# niente. Se rsync non avesse portato `sub/router.py`, il confronto lo
# troverebbe diverso per sempre.
USCITA=$(lancia)
if ! chiamato '^rsync'; then ok; else ko "il giro dopo risincronizza: il modulo non è davvero arrivato"; fi

echo "── Un confronto che non si può fare non passa per «è uguale»"
# «Non ho guardato» e «sono uguali» si assomigliano solo nei registri scritti
# male. Primo caso: la cartella del servizio non c'è nel sorgente.
al_commit "$C2"; prepara_opt "$C2"
mv "$R/tools/tex-compile-vps/app" "$T/app-da-parte"
USCITA=$(lancia)
mv "$T/app-da-parte" "$R/tools/tex-compile-vps/app"
if grep -q 'non sono riuscito a confrontare' <<<"$USCITA"; then ok; else ko "il confronto impossibile è passato in silenzio: $USCITA"; fi
if ! grep -q 'è già quello del repository' <<<"$USCITA"; then ok; else ko "ha detto «già a posto» senza aver guardato: $USCITA"; fi
if grep -q '"codice":"tex_sincronizzazione"' <<<"$(anomalie)"; then ok; else ko "nessuna anomalia dopo un confronto impossibile: $(anomalie)"; fi
if grep -q '^USCITA 0' <<<"$USCITA"; then ok; else ko "il passo ha fermato il rilascio: $USCITA"; fi

# Secondo caso: il file c'è ma non si legge. Da root sarebbe leggibile
# comunque, e la verifica non direbbe niente.
al_commit "$C2"; prepara_opt "$C2"
if [ "$(id -u)" -eq 0 ]; then
    saltata "da root ogni file è leggibile: il ramo «cmp non ci riesce» non si può provare"
else
    chmod 000 "$R/tools/tex-compile-vps/app/main.py"
    USCITA=$(lancia)
    chmod 644 "$R/tools/tex-compile-vps/app/main.py"
    if grep -q 'non riesco a confrontare' <<<"$USCITA"; then ok; else ko "un file illeggibile è passato per uguale: $USCITA"; fi
fi
# Controprova: con tutto leggibile e allineato, non si dice niente di guasto.
USCITA=$(lancia)
if ! grep -q 'non sono riuscito a confrontare' <<<"$USCITA"; then ok; else ko "avviso di confronto guasto senza guasto: $USCITA"; fi

echo "── Dove sta il passo dentro lo script"
# Non è un dettaglio di stile: finché la sincronizzazione stava prima della
# verifica del passo 8, uno scambio annullato (rimetti_upstream /
# ferma_il_nuovo) rimetteva in servizio il container vecchio con in /opt il
# codice TeX nuovo, già riavviato. Due versioni che non si erano mai viste
# insieme, e nessuno lo diceva. Si misura sulle righe: la chiamata deve venire
# dopo l'ultimo punto da cui si torna indietro.
riga_di() { grep -n -- "$1" "$SCRIPT" | tail -1 | cut -d: -f1; }
CHIAMATA=$(grep -n '^passo_tex' "$SCRIPT" | tail -1 | cut -d: -f1)
ULTIMO_RITORNO=$(riga_di '^ *rimetti_upstream$')
DOMANDA_TEX=$(riga_di 'https://127.0.0.1/health/tex')
BUTTA_CACHE=$(riga_di 'rm -f "$DATI/storage/cache/health-tex.json"')
if [ -n "$CHIAMATA" ] && [ -n "$ULTIMO_RITORNO" ] && [ "$CHIAMATA" -gt "$ULTIMO_RITORNO" ]; then ok
else ko "passo_tex (riga ${CHIAMATA:-?}) sta prima dell'ultimo rimetti_upstream (riga ${ULTIMO_RITORNO:-?}): uno scambio annullato lascerebbe /opt avanti"; fi
if [ -n "$DOMANDA_TEX" ] && [ "$CHIAMATA" -lt "$DOMANDA_TEX" ]; then ok
else ko "passo_tex (riga ${CHIAMATA:-?}) sta dopo la domanda a /health/tex (riga ${DOMANDA_TEX:-?}): la verifica guarderebbe il servizio non ancora riavviato"; fi
# /health/tex tiene la risposta in cache per mezzo minuto, e la cache sta nei
# dati d'istanza che i due container condividono: senza buttarla, il passo 8
# leggerebbe la risposta di prima dello scambio.
if [ -n "$BUTTA_CACHE" ] && [ "$BUTTA_CACHE" -lt "$DOMANDA_TEX" ]; then ok
else ko "il rilascio non butta la cache di /health/tex prima di chiedere (riga ${BUTTA_CACHE:-assente})"; fi

echo
echo "$PASSATE passate, $FALLITE fallite, $SALTATE saltate"
[ "$FALLITE" -eq 0 ]
