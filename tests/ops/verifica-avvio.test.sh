#!/bin/bash
#
# Prove delle guardie della produzione in `docker/verifica-avvio.php`, nei due
# versi (23/9/2026).
#
# Il difetto che le ha fatte nascere (revisione architetturale del 23/9,
# scheda R-4): il container monta il `.env` versionato e sopra `.env.local`
# del server, e ogni difesa dipendeva da una riga di uno dei due senza che
# nessuno la guardasse. Il `.env` versionato spegneva il limitatore (A-4); il
# predefinito della motivazione era `warn` (A-37); il provider S3 è uno stub
# (A-58); la chiave del WAF ha un ripiego scritto sul posto (A-39); scenario e
# modo scritti a mano possono non combaciare (A-8). Ora, con APP_ENV=production,
# il container si rifiuta di partire e dice quale guardia e dove guardare.
#
# Ogni guardia si prova nei due versi: scatta con il valore pericoloso, e NON
# scatta con quello di produzione. Il caso di partenza è la produzione del
# 23/9/2026, com'è stata misurata (solo forme e nomi, nessun valore): il `.env`
# versionato di questo repository, che il rilascio monta, e sopra un
# `.env.local` con le dieci chiavi che quello di produzione ridefinisce, con
# valori finti, più la chiave del WAF. Deve passare tutte le guardie: se
# fallisce, una guardia fermerebbe il prossimo rilascio.
#
# E `--solo-configurazione`, il controllo che il runbook fa lanciare dall'host
# dopo aver cambiato `.env.local` e prima di un riavvio: le stesse guardie,
# nessuna scrittura nei dati, e si ferma se la cartella dei dati non c'è o se
# `.env.local` non si legge.
#
# Nella copia pubblica, senza `.env` versionato, si parte da `.env.example` e
# il caso che prova il contenuto del `.env` versionato si salta, dicendolo.
#
# E la CI: l'immagine gira in e2e.yml e immagine.yml con il limitatore spento.
# Si rifà il suo `.env` con i `sed` letti da quei due workflow, e deve passare:
# lì APP_ENV=ci, e le guardie non si applicano.
#
# Come si isola. `app/bootstrap.php` carica `.env` e `.env.local` dalla
# cartella sopra `app/`, e PHP risolve i collegamenti simbolici prima di
# calcolarla: con `app/` collegato al repository caricherebbe il `.env.local`
# vero di chi sviluppa. Quindi `app/`, la verifica e le informative si
# COPIANO in una radice usa e getta; di `vendor/` si copia solo il
# caricatore (così le classi `App\` arrivano dalla copia) e i pacchetti si
# collegano. Si lancia con `env -i`, con l'ambiente del processo scritto qui,
# e `variables_order=EGPCS` come nell'immagine, che non ha un php.ini e usa il
# predefinito di PHP: così PANTEDU_DATA_PATH passato come `docker run -e`
# arriva a `$_ENV`. Nessun database: `--senza-db`, come il primo giro
# dell'entrypoint.
#
# I segreti della prova sono generati qui e non devono comparire in nessuna
# uscita: si controlla alla fine.
#
# Uso: bash tests/ops/verifica-avvio.test.sh
#      VERIFICA=<altro verifica-avvio.php> bash tests/ops/verifica-avvio.test.sh
#      (per la controprova su una versione vecchia)
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
RADICE_REPO=$(cd "$QUI/../.." && pwd)
VERIFICA="${VERIFICA:-$RADICE_REPO/docker/verifica-avvio.php}"

# Senza php o senza vendor/ questa prova non misurerebbe niente: è un errore,
# non un salto. In CI gira nel lavoro PHP, dopo `composer install`.
if ! command -v php > /dev/null || [ ! -f "$RADICE_REPO/vendor/autoload.php" ]; then
    echo "verifica-avvio: servono php e vendor/ (composer install): non ho provato niente" >&2
    exit 1
fi

T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/verifica-avvio.XXXXXX")
# Prima di cancellare si ridà la scrittura: i casi in sola lettura la tolgono.
trap 'chmod -R u+rwX "$T" 2>/dev/null; rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

# ── La radice usa e getta ─────────────────────────────────────────────────
R="$T/radice"
mkdir -p "$R/vendor" "$R/docker" "$R/docs/privacy"
cp -a "$RADICE_REPO/app" "$R/app"
cp "$VERIFICA" "$R/docker/verifica-avvio.php"
cp "$RADICE_REPO"/docs/privacy/informativa*.md "$R/docs/privacy/"
for voce in "$RADICE_REPO"/vendor/* "$RADICE_REPO"/vendor/.[!.]*; do
    [ -e "$voce" ] || continue
    nome=$(basename "$voce")
    case "$nome" in
        composer) cp -a "$voce" "$R/vendor/composer" ;;
        autoload.php) cp "$voce" "$R/vendor/autoload.php" ;;
        *) ln -s "$voce" "$R/vendor/$nome" ;;
    esac
done

segreto() { php -r 'echo bin2hex(random_bytes((int)$argv[1]));' "$1"; }
SEGRETO_DB=$(segreto 12)
SEGRETO_KMS=$(segreto 32)
SEGRETO_FIRMA=$(segreto 32)
SEGRETO_WAF=$(segreto 32)
SEGRETO_CORTO=$(segreto 16 | cut -c1-31)   # 31 byte: uno meno del minimo

# Il .env versionato, quello che il rilascio monta. Nella copia pubblica non
# c'è (lo toglie il sanitizer): lì si parte da .env.example, e lo si dice.
ENV_VERSIONATO="${ENV_VERSIONATO:-$RADICE_REPO/.env}"
if [ ! -f "$ENV_VERSIONATO" ]; then
    echo "  (nessun .env versionato: il caso della produzione parte da .env.example)"
    ENV_VERSIONATO="$RADICE_REPO/.env.example"
fi
# Partire da .env.example cambia una cosa sola: è il modello delle
# installazioni nuove e ha APP_URL piena, mentre il .env versionato la tiene
# vuota. I casi che provano il CONTENUTO del .env versionato, e non una
# guardia, lì non hanno niente da provare (23/9/2026: nella copia pubblica
# «indirizzo-senza-riga-locale» dava un rosso falso).
BASE_E_IL_MODELLO=0
if [ "$ENV_VERSIONATO" -ef "$RADICE_REPO/.env.example" ]; then
    BASE_E_IL_MODELLO=1
fi

# Le dieci chiavi che .env.local di produzione ridefinisce (misurate il
# 23/9/2026: i nomi, non i valori), con valori finti, e la chiave del WAF.
env_local_di_produzione() {
    cat <<RIGHE
APP_URL=https://pantedu.invalid
DB_HOST=localhost
DB_NAME=pantedu_prova
DB_PASS=$SEGRETO_DB
DB_USER=pantedu_prova
DEPLOYMENT_MODE=single
KMS_MASTER_KEY=$SEGRETO_KMS
RATE_LIMIT_DISABLED=0
STORAGE_SIGNING_SECRET=$SEGRETO_FIRMA
TEX_COMPILE_ENDPOINT=http://tex.invalid:8001
WAF_HMAC_SECRET=$SEGRETO_WAF
RIGHE
}

# Toglie una chiave da un file .env (anche se manca: nessun errore).
togli_chiave() {
    { grep -v -E "^[[:space:]]*(export[[:space:]]+)?$2[[:space:]]*=" "$1" || true; } > "$1.nuovo"
    mv "$1.nuovo" "$1"
}

# prepara NOME [MODIFICA...] — scrive .env, .env.local e i dati del caso.
#   CHIAVE=valore    in .env.local, al posto di quella che c'era
#   -CHIAVE          tolta da .env.local
#   env:-CHIAVE      tolta dal .env versionato
#   proc:CHIAVE=v    nell'ambiente del processo, come `docker run -e`
#   json:nome=testo  il file storage/config/nome.json dei dati
#   arg:--opzione    lancia la verifica con questa opzione al posto di
#                    --senza-db (quella dell'entrypoint)
DATI=""
PROCESSO=()
ARGOMENTI=()
prepara() {
    local nome=$1
    shift
    DATI="$T/dati-$nome"
    PROCESSO=()
    ARGOMENTI=()
    rm -rf "$DATI"
    mkdir -p "$DATI/storage/sessions" "$DATI/storage/config"
    chmod 700 "$DATI/storage/sessions"
    cp "$ENV_VERSIONATO" "$R/.env"
    rm -f "$R/.env.local"
    env_local_di_produzione > "$R/.env.local"
    local m
    for m in "$@"; do
        case "$m" in
            env:-*) togli_chiave "$R/.env" "${m#env:-}" ;;
            proc:*) PROCESSO+=("${m#proc:}") ;;
            arg:*) ARGOMENTI+=("${m#arg:}") ;;
            json:*) m=${m#json:}; printf '%s\n' "${m#*=}" > "$DATI/storage/config/${m%%=*}.json" ;;
            -*) togli_chiave "$R/.env.local" "${m#-}" ;;
            *=*) togli_chiave "$R/.env.local" "${m%%=*}"; printf '%s\n' "$m" >> "$R/.env.local" ;;
        esac
    done
}

lancia() {
    [ "${#ARGOMENTI[@]}" -gt 0 ] || ARGOMENTI=(--senza-db)
    env -i PATH="$PATH" HOME="$T" PANTEDU_DATA_PATH="$DATI" "${PROCESSO[@]}" \
        php -d variables_order=EGPCS "$R/docker/verifica-avvio.php" "${ARGOMENTI[@]}" > "$T/uscita" 2>&1
}

USCITE="$T/tutte-le-uscite"
: > "$USCITE"

# caso NOME ATTESO ETICHETTA [MODIFICA...]
#   ATTESO 0: deve partire. ATTESO 1: deve fermarsi, con «[ETICHETTA]» nella
#   riga d'errore e una riga «guarda:».
caso() {
    local nome=$1 atteso=$2 etichetta=$3
    shift 3
    prepara "$nome" "$@"
    lancia
    local esito=$?
    cat "$T/uscita" >> "$USCITE"
    if [ "$atteso" -eq 0 ]; then
        if [ "$esito" -eq 0 ]; then
            ok
        else
            ko "«$nome»: doveva partire, esito $esito: $(tr '\n' ' ' < "$T/uscita")"
        fi
        return
    fi
    if [ "$esito" -eq 1 ] && grep -qF "ERRORE: [$etichetta]" "$T/uscita" && grep -q 'guarda:' "$T/uscita"; then
        ok
    else
        ko "«$nome»: doveva fermarsi con [$etichetta], esito $esito: $(tr '\n' ' ' < "$T/uscita")"
    fi
}

# ── La produzione del 23/9/2026 passa ─────────────────────────────────────
# Prima si controlla che la radice finta dica davvero quello che è stato
# misurato in produzione: altrimenti il caso qui sotto proverebbe un'altra
# configurazione.
prepara misura
forma=$(env -i PATH="$PATH" HOME="$T" PANTEDU_DATA_PATH="$DATI" php -d variables_order=EGPCS -r '
    require $argv[1];
    $waf = (string)($_ENV["WAF_HMAC_SECRET"] ?? (getenv("WAF_HMAC_SECRET") ?: ""));
    printf("env=%s debug=%s url=%s mail=%s limitatore_spento=%s motivazione=%s scenario_env=%s scenario=%s modo=%s provider=%s waf_ambiente=%s\n",
        App\Core\Config::get("app.env"), var_export(App\Core\Config::get("app.debug"), true),
        trim((string)App\Core\Config::get("app.url")) === "" ? "vuota" : "piena",
        trim((string)App\Core\Config::get("mail.from")) === "" ? "vuota" : "piena",
        var_export(App\Core\Config::get("security.rate_limit_disabled"), true),
        App\Core\Config::get("audit.reason_mode"), App\Core\Config::get("app.deployment_scenario") === "" ? "vuoto" : "pieno",
        App\Support\DeploymentScenario::current(), App\Support\DeploymentMode::current(),
        isset($_ENV["STORAGE_PROVIDER"]) ? "impostato" : "non-impostato",
        strlen($waf) >= 32 ? "almeno-32" : "corta");
' "$R/app/bootstrap.php" 2>&1)
misurata="env=production debug=false url=piena mail=piena limitatore_spento=false motivazione=enforce scenario_env=vuoto scenario=personal modo=single provider=non-impostato waf_ambiente=almeno-32"
if [ "$forma" = "$misurata" ]; then
    ok
else
    ko "la radice finta non ha la forma misurata in produzione: «$forma»"
fi

caso produzione-oggi 0 -
if grep -q 'produzione: limitatore, motivazione' "$T/uscita"; then
    ok
else
    ko "la produzione è partita senza passare dalle guardie: $(tr '\n' ' ' < "$T/uscita")"
fi

# ── Il limitatore (A-4) ───────────────────────────────────────────────────
caso limitatore-spento 1 limitatore RATE_LIMIT_DISABLED=1
caso limitatore-senza-riga-locale 0 - -RATE_LIMIT_DISABLED
# 23/9/2026 (A-35) — gli interruttori si leggono in un modo solo: `true` vale
# come `1`. Prima questa riga lasciava il limitatore acceso; adesso lo
# spegnerebbe, e la guardia deve vederla. `false` resta acceso.
caso limitatore-spento-con-true 1 limitatore RATE_LIMIT_DISABLED=true
caso limitatore-acceso-con-false 0 - RATE_LIMIT_DISABLED=false

# ── Il gettone di cancellazione nella risposta (A-35) ─────────────────────
caso gettone-esposto 1 gettone EXPOSE_DELETION_DEBUG_TOKEN=1
caso gettone-esposto-con-yes 1 gettone EXPOSE_DELETION_DEBUG_TOKEN=yes
caso gettone-spento 0 - EXPOSE_DELETION_DEBUG_TOKEN=0

# ── La motivazione (A-37) ─────────────────────────────────────────────────
caso motivazione-warn 1 motivazione AUDIT_REASON_MODE=warn
caso motivazione-disabled 1 motivazione AUDIT_REASON_MODE=disabled
caso motivazione-enforce-locale 0 - AUDIT_REASON_MODE=enforce
# Senza la chiave né nel .env né in .env.local vale il predefinito: enforce.
caso motivazione-senza-chiave 0 - env:-AUDIT_REASON_MODE

# ── Il debug ──────────────────────────────────────────────────────────────
caso debug-acceso 1 debug APP_DEBUG=true
caso debug-spento-locale 0 - APP_DEBUG=false

# ── Indirizzo e posta ─────────────────────────────────────────────────────
caso indirizzo-vuoto 1 indirizzo APP_URL=
# Senza la riga in .env.local vale quella del .env versionato, che dal
# 23/9/2026 è vuota: un server che la perde non parte. Prima diceva
# http://pantedu.local, e la produzione sarebbe partita con i collegamenti
# della posta verso un indirizzo che non esiste. Prova il .env versionato, non
# la guardia (quella la provano il caso sopra e quello sotto): partendo da
# .env.example non c'è niente da provare.
if [ "$BASE_E_IL_MODELLO" -eq 1 ]; then
    echo "  (si parte da .env.example, che ha APP_URL piena: «indirizzo-senza-riga-locale» prova il .env versionato, e si salta)"
else
    caso indirizzo-senza-riga-locale 1 indirizzo -APP_URL
fi
caso indirizzo-di-spazi 1 indirizzo 'APP_URL="   "'
caso posta-vuota 1 posta APP_MAIL_FROM=
caso posta-locale 0 - APP_MAIL_FROM=avvisi@pantedu.invalid

# ── L'archivio (A-58) ─────────────────────────────────────────────────────
caso archivio-s3 1 archivio STORAGE_PROVIDER=s3
if grep -q 'non è implementato' "$T/uscita"; then ok; else ko "con s3 non dice che il provider non è implementato"; fi
caso archivio-sconosciuto 1 archivio STORAGE_PROVIDER=ftp
caso archivio-local 0 - STORAGE_PROVIDER=local

# ── La chiave del WAF (A-39) ──────────────────────────────────────────────
caso waf-senza-chiave 1 waf -WAF_HMAC_SECRET
# Il ripiego di waf.php ha scritto una chiave nei dati: esiste, ed è la
# ragione per cui senza la guardia non se ne accorgerebbe nessuno. Non basta.
if [ -s "$DATI/storage/keys/waf_hmac.key" ]; then
    ok
else
    ko "senza chiave nell'ambiente il ripiego di waf.php non ha scritto la sua: il caso non prova il ripiego"
fi
caso waf-chiave-corta 1 waf "WAF_HMAC_SECRET=$SEGRETO_CORTO"
caso waf-chiave-di-32 0 - "WAF_HMAC_SECRET=$(printf '%s' "$SEGRETO_WAF" | cut -c1-32)"
caso waf-chiave-dal-processo 0 - -WAF_HMAC_SECRET "proc:WAF_HMAC_SECRET=$SEGRETO_WAF"

# ── Scenario e modo (A-8) ─────────────────────────────────────────────────
caso scenario-2-modo-istituto 1 scenario DEPLOYMENT_SCENARIO=colleagues DEPLOYMENT_MODE=institute
caso scenario-3-modo-singolo 1 scenario DEPLOYMENT_SCENARIO=institute DEPLOYMENT_MODE=single INSTANCE_ACN_QUALIFIED=true
caso scenario-3-senza-acn 1 scenario DEPLOYMENT_SCENARIO=institute DEPLOYMENT_MODE=institute
if grep -q 'qualificata ACN' "$T/uscita"; then ok; else ko "lo scenario 3 senza ACN non dice perché"; fi
caso modo-istituto-senza-acn 1 scenario DEPLOYMENT_MODE=institute
caso scenario-3-con-acn 0 - DEPLOYMENT_SCENARIO=institute DEPLOYMENT_MODE=institute INSTANCE_ACN_QUALIFIED=true
caso scenario-2-modo-singolo 0 - DEPLOYMENT_SCENARIO=colleagues DEPLOYMENT_MODE=single
caso pannello-incoerente 1 scenario \
    'json:deployment_scenario={"scenario":"colleagues"}' 'json:deployment={"mode":"institute"}'
if grep -q 'storage/config' "$T/uscita"; then ok; else ko "la coppia dal pannello non dice che viene da storage/config"; fi
caso pannello-coerente 0 - INSTANCE_ACN_QUALIFIED=true \
    'json:deployment_scenario={"scenario":"institute"}' 'json:deployment={"mode":"institute"}'
# 23/9/2026 (A-38) — un file del pannello corrotto non ferma l'avvio e non
# decide: vale l'ambiente (qui lo scenario dedotto dal modo, `single`), e
# l'anomalia `sostituzione_illeggibile` finisce nel registro dei dati.
caso pannello-corrotto 0 - 'json:deployment_scenario={"scenario":' 'json:deployment={"mode":"saas"}'
if grep -q 'sostituzione_illeggibile' "$DATI/storage/logs/anomalie.jsonl" 2>/dev/null; then
    ok
else
    ko "un file del pannello corrotto non ha lasciato l'anomalia sostituzione_illeggibile nel registro dei dati"
fi

# ── L'informativa di ogni scenario dichiara la sua versione (DOC-19) ─────
# I consensi registrano il `versione:` del testo dello scenario attivo: un
# file senza ferma l'avvio, per qualunque scenario, non solo quello attivo.
cp "$R/docs/privacy/informativa-istituto.md" "$T/informativa-istituto.md.orig"
sed -i '/^versione:/d' "$R/docs/privacy/informativa-istituto.md"
prepara informativa-senza-versione
lancia
esito=$?
cat "$T/uscita" >> "$USCITE"
cp "$T/informativa-istituto.md.orig" "$R/docs/privacy/informativa-istituto.md"
if [ "$esito" -eq 1 ] && grep -q 'non dichiara `versione:`' "$T/uscita" && grep -q 'guarda:' "$T/uscita"; then
    ok
else
    ko "un'informativa senza versione non ferma l'avvio (esito $esito): $(tr '\n' ' ' < "$T/uscita")"
fi

# ── L'ambiente ────────────────────────────────────────────────────────────
caso ambiente-sconosciuto 1 ambiente APP_ENV=prod
# Fuori dalla produzione le guardie non si applicano: tutto quello che
# fermerebbe la produzione, insieme, e parte.
caso fuori-produzione 0 - APP_ENV=ci RATE_LIMIT_DISABLED=1 AUDIT_REASON_MODE=warn APP_DEBUG=true \
    APP_URL= STORAGE_PROVIDER=s3 -WAF_HMAC_SECRET DEPLOYMENT_SCENARIO=colleagues DEPLOYMENT_MODE=institute
if grep -q 'non si applicano' "$T/uscita"; then ok; else ko "fuori dalla produzione non dice che le guardie non si applicano"; fi

# ── Solo la configurazione, dall'host prima di un riavvio ─────────────────
# `--solo-configurazione` è il controllo del runbook (docs/ops/
# runbook-sito-irraggiungibile.md, § 4.7): dopo aver cambiato .env.local, e
# prima di un riavvio, dall'host e come un altro utente. Le guardie sono le
# stesse; i controlli che scrivono no, e non devono girare.
caso solo-configurazione-produzione 0 - arg:--solo-configurazione
if grep -q 'produzione: limitatore, motivazione' "$T/uscita" && grep -q 'solo la configurazione' "$T/uscita"; then
    ok
else
    ko "con --solo-configurazione la produzione non passa dalle guardie, o non dice che cosa non ha guardato: $(tr '\n' ' ' < "$T/uscita")"
fi
caso solo-configurazione-motivazione 1 motivazione arg:--solo-configurazione AUDIT_REASON_MODE=warn
caso solo-configurazione-limitatore 1 limitatore arg:--solo-configurazione RATE_LIMIT_DISABLED=1
caso solo-configurazione-waf 1 waf arg:--solo-configurazione -WAF_HMAC_SECRET
caso solo-configurazione-ambiente 1 ambiente arg:--solo-configurazione APP_ENV=prod

# Non scrive niente nella cartella dei dati. La controprova nello stesso
# posto: la verifica intera, sugli stessi dati, ci crea le radici di
# scrittura; se non cambiasse niente nemmeno lei, il confronto non vedrebbe.
# Il file di sessione resta fuori dal confronto: lo scrive app/bootstrap.php,
# in tutti e due i modi, quando da riga di comando la cartella delle sessioni
# è scrivibile. Sull'host non lo è (www-data, 0700), e Session::start da riga
# di comando allora non ci prova.
elenco_dati() { (cd "$DATI" && find . ! -path './storage/sessions/*' | sort); }
prepara solo-configurazione-non-scrive arg:--solo-configurazione
prima=$(elenco_dati)
lancia
esito=$?
cat "$T/uscita" >> "$USCITE"
dopo=$(elenco_dati)
ARGOMENTI=(--senza-db)
lancia
esito_intera=$?
cat "$T/uscita" >> "$USCITE"
dopo_intera=$(elenco_dati)
if [ "$esito" -eq 0 ] && [ "$prima" = "$dopo" ] && [ "$esito_intera" -eq 0 ] && [ "$dopo" != "$dopo_intera" ]; then
    ok
else
    ko "--solo-configurazione ha scritto nei dati, o la controprova non vede le scritture (esiti $esito e $esito_intera)"
fi

# La cartella dei dati deve esistere anche qui: altrimenti scenario e modo
# si leggerebbero da un'altra storage/config, e il controllo direbbe «a
# posto» su file che il container non legge.
prepara solo-configurazione-senza-dati arg:--solo-configurazione
rm -rf "$DATI"
lancia
esito=$?
cat "$T/uscita" >> "$USCITE"
if [ "$esito" -eq 1 ] && grep -q 'non esiste' "$T/uscita"; then
    ok
else
    ko "--solo-configurazione con la cartella dei dati che non c'è non si ferma (esito $esito): $(tr '\n' ' ' < "$T/uscita")"
fi

# Dall'host si gira come un altro utente: dove non si scrive la verifica
# intera si ferma, questa no. E un .env.local che non si legge non si salta in
# silenzio. Come root i permessi non fermano niente, e questi casi non
# proverebbero niente: si saltano, dicendolo.
if [ "$(id -u)" -eq 0 ]; then
    echo "  (giro come root: i casi dei permessi si saltano)"
else
    prepara solo-configurazione-sola-lettura
    chmod -R a-w "$DATI/storage"
    lancia
    esito_intera=$?
    cat "$T/uscita" >> "$USCITE"
    ARGOMENTI=(--solo-configurazione)
    lancia
    esito=$?
    cat "$T/uscita" >> "$USCITE"
    if [ "$esito_intera" -eq 1 ] && [ "$esito" -eq 0 ]; then
        ok
    else
        ko "dati in sola lettura: la verifica intera doveva fermarsi (esito $esito_intera) e --solo-configurazione no (esito $esito)"
    fi

    prepara solo-configurazione-env-local-illeggibile arg:--solo-configurazione
    chmod 000 "$R/.env.local"
    lancia
    esito=$?
    cat "$T/uscita" >> "$USCITE"
    chmod 600 "$R/.env.local"
    if [ "$esito" -eq 1 ] && grep -qF 'ERRORE: [configurazione]' "$T/uscita"; then
        ok
    else
        ko "un .env.local illeggibile non ferma --solo-configurazione con [configurazione] (esito $esito): $(tr '\n' ' ' < "$T/uscita")"
    fi
fi

# ── L'immagine in CI, con il .env che le scrivono i workflow ──────────────
# I `sed` si leggono da e2e.yml e immagine.yml, non si copiano qui: se un
# workflow passasse a APP_ENV=production con il limitatore spento, questo
# caso lo direbbe prima del rilascio. I valori calcolati dal job ($…)
# diventano segnaposti; PANTEDU_DATA_PATH i dati del caso.
for wf in e2e.yml immagine.yml; do
    FILE_WF="$RADICE_REPO/.github/workflows/$wf"
    if [ ! -f "$FILE_WF" ]; then
        echo "  ($wf non c'è, come nella copia pubblica: il caso della CI si salta)"
        continue
    fi
    prepara "ci-$wf"
    rm -f "$R/.env.local"
    cp "$RADICE_REPO/.env.example" "$R/.env"
    sostituzioni=0
    while IFS=$'\t' read -r chiave valore; do
        [ -n "$chiave" ] || continue
        # Su più righe: il case annidato su una riga sola semgrep lo legge
        # solo in parte, e salterebbe il resto del file.
        case "$chiave" in
            PANTEDU_DATA_PATH)
                valore="$DATI"
                ;;
            *)
                case "$valore" in
                    *'$'*) valore="segnaposto-della-prova" ;;
                esac
                ;;
        esac
        sed -i "s|^$chiave=.*|$chiave=$valore|" "$R/.env"
        sostituzioni=$((sostituzioni + 1))
    done < <(sed -n -E 's/.*sed -i "s\|\^([A-Z0-9_]+)=\.\*\|[A-Z0-9_]+=(.*)\|" +\.env.*/\1\t\2/p' "$FILE_WF")
    if grep -qx 'APP_ENV=ci' "$R/.env" && grep -qx 'RATE_LIMIT_DISABLED=1' "$R/.env" && [ "$sostituzioni" -ge 5 ]; then
        ok
    else
        ko "da $wf non ho ricavato il .env della CI ($sostituzioni sostituzioni): il caso non misurerebbe niente"
    fi
    lancia
    esito=$?
    cat "$T/uscita" >> "$USCITE"
    if [ "$esito" -eq 0 ] && grep -q 'non si applicano' "$T/uscita"; then
        ok
    else
        ko "l'immagine con il .env di $wf non parte (esito $esito): $(tr '\n' ' ' < "$T/uscita")"
    fi
done

# ── Il VPS in locale, che gira come la produzione ─────────────────────────
# `tools/dev/wsl/vps-locale.sh` monta il .env versionato e un .env.local che
# scrive lui: le chiavi si leggono dal suo testo, i valori calcolati ($…)
# diventano segreti finti. Deve partire, con le guardie applicate.
VPS_LOCALE="$RADICE_REPO/tools/dev/wsl/vps-locale.sh"
if [ -f "$VPS_LOCALE" ]; then
    prepara vps-locale
    : > "$R/.env.local"
    righe=0
    while IFS= read -r riga; do
        chiave=${riga%%=*}
        valore=${riga#*=}
        # Su più righe: il case annidato su una riga sola semgrep lo legge
        # solo in parte, e salterebbe il resto del file.
        case "$chiave" in
            PANTEDU_DATA_PATH)
                valore="$DATI"
                ;;
            *)
                case "$valore" in
                    *'$'*) valore="$SEGRETO_WAF" ;;
                esac
                ;;
        esac
        printf '%s=%s\n' "$chiave" "$valore" >> "$R/.env.local"
        righe=$((righe + 1))
    done < <(sed -n '/^    cat > "\$STATO\/env.local" <<RIGHE$/,/^RIGHE$/p' "$VPS_LOCALE" | grep -E '^[A-Z][A-Z0-9_]*=')
    lancia
    esito=$?
    cat "$T/uscita" >> "$USCITE"
    if [ "$righe" -ge 5 ] && [ "$esito" -eq 0 ] && grep -q 'produzione: limitatore, motivazione' "$T/uscita"; then
        ok
    else
        ko "il VPS in locale ($righe chiavi lette da vps-locale.sh) non parte con le guardie (esito $esito): $(tr '\n' ' ' < "$T/uscita")"
    fi
else
    echo "  (tools/dev/wsl/vps-locale.sh non c'è: il caso del VPS in locale si salta)"
fi

# ── Nessun segreto nelle uscite ───────────────────────────────────────────
trapelati=0
for s in "$SEGRETO_DB" "$SEGRETO_KMS" "$SEGRETO_FIRMA" "$SEGRETO_WAF" "$SEGRETO_CORTO"; do
    if grep -qF -- "$s" "$USCITE"; then
        trapelati=$((trapelati + 1))
    fi
done
if [ "$trapelati" -eq 0 ] && [ -s "$USCITE" ]; then
    ok
else
    ko "$trapelati segreti della prova compaiono nelle uscite della verifica"
fi

echo "verifica-avvio: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
