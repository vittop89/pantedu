#!/bin/bash
#
# Separa le differenze attese da quelle che vanno guardate.
#
# ── Perché serve ──────────────────────────────────────────────────────────
#
# Un rapporto AIDE con centinaia di righe vere e nessuna indicazione di quali
# contino non si legge, e dopo un mese non si apre nemmeno la mail. È lo stesso
# meccanismo che ha reso invisibili centoundici notti di controlli che non
# guardavano niente.
#
# Le righe vere e innocue arrivano da più parti: `unattended-upgrades` aggiorna
# pacchetti sotto `/usr` circa una volta a settimana, il rilascio aggiorna i
# suoi file, un file riscritto uguale cambia data e inode. La risposta giusta
# non è togliere quei percorsi dal perimetro: sarebbe rinunciare proprio alla
# cosa che AIDE serve a vedere.
#
# ── Come decide ───────────────────────────────────────────────────────────
#
# Non per correlazione temporale («è cambiato lo stesso giorno di un
# aggiornamento, quindi va bene»): quella si può ingannare aspettando il
# martedì. Si **verifica**. Una differenza è attesa solo se passa una di queste
# prove; tutto il resto è da guardare.
#
#   1. **Stesso contenuto.** AIDE scrive quali attributi sono cambiati. Se sono
#      cambiate solo le date — e, per un file, l'inode — mentre impronta,
#      dimensione, permessi e proprietario sono quelli di prima, il file è stato
#      riscritto uguale. Per una cartella la data cambia quando cambia quello
#      che contiene, e quello AIDE lo elenca voce per voce.
#
#   2. **File installati dal repository** (l'elenco è in `sorgente_di`):
#      attesi solo se identici, byte per byte, al file del commit in servizio —
#      quello dell'immagine del container sano, che deve essere anche HEAD della
#      copia sull'host — e se sono di root e non scrivibili da altri.
#
#   3. **Due file che si riscrivono da sé.** L'upstream di nginx, atteso solo se
#      punta alla porta di un container sano dell'applicazione; `audit.rules`,
#      atteso solo se `augenrules --check` conferma che è quello che si ricava
#      da `rules.d`.
#
#   4. **File dei pacchetti.** Si chiede a dpkg di quale pacchetto sia il file,
#      e a `debsums` se **combacia con l'impronta del pacchetto**. Conta solo un
#      «OK» esplicito.
#
# I percorsi che nessun pacchetto possiede — `/etc`, `/usr/local`, `/opt`, i
# crontab, i `.env` — non passano mai dalla prova 4: stanno nel perimetro
# proprio perché non li sorveglia nessun altro.
#
# ── 13 settembre 2026 ─────────────────────────────────────────────────────
#
# Fino a quel giorno c'era solo la prova 4, fatta al contrario: era atteso
# tutto quello che `debsums -c` **non** elencava fra i file che non
# combaciano. Tre modi di dire «atteso» senza aver guardato:
#
#   - `debsums` assente: nessun file elencato, quindi tutti attesi;
#   - un pacchetto senza impronte: `debsums -c` non ha niente da dire, e i suoi
#     file risultavano attesi. Sul server, quel giorno, `debsums -l` non ne
#     trovava nessuno: il difetto c'era, l'occasione non ancora;
#   - `dpkg-query` assente: i file da verificare non finivano né fra gli attesi
#     né fra quelli da guardare. Sparivano dal conto.
#
# E un quarto: `printf "$ELENCO" | grep -Fxq` con `pipefail` riporta
# fallimento **proprio quando trova**, se l'elenco è grande — `grep` esce alla
# prima riga, `printf` prende SIGPIPE, la pipe vale 141. Misurato: con 1,3 MB
# di elenco la prima riga risulta «non trovata». Qui voleva dire far passare per
# atteso un file che non combacia. Adesso nessuna decisione passa da una pipe.
#
# Quella notte il controllo aveva 171 differenze, nessuna spiegata e nessuna
# da guardare: cartelle con la data nuova, file riscritti uguali, lo script del
# rilascio aggiornato, le regole di auditd installate a mano. Le prove 1, 2 e 3
# vengono da lì.
#
# Un limite che resta scritto, perché è vero: `debsums` usa gli MD5 che il
# pacchetto porta con sé, e il commit in servizio sta in un repository sulla
# stessa macchina. Chi può riscrivere un file di sistema può in linea di
# principio riscrivere anche quelli. È il motivo per cui questo **non
# sostituisce** AIDE, che tiene le sue impronte altrove: lo affianca per dire
# quali delle sue righe meritano i primi cinque minuti.
#
# Uso:
#   tools/ops/aide-spiega.sh <registro-di-aide>
#
# Stampa un riassunto leggibile e, in coda, due righe che il chiamante legge:
#   ATTESI=<n>
#   INATTESI=<n>     (-1 quando il registro non si è potuto leggere)
#
# Le prove, nei due versi: `tests/ops/aide-spiega.test.sh`.
set -uo pipefail

REGISTRO="${1:-}"
if [ -z "$REGISTRO" ] || [ ! -r "$REGISTRO" ]; then
    echo "uso: $0 <registro-di-aide>" >&2
    # -1 e non 0: chi chiama legge «0 da guardare» come «tutto spiegato», e un
    # registro che non si è potuto leggere non ha spiegato niente.
    echo "ATTESI=0"
    echo "INATTESI=-1"
    exit 2
fi

# In produzione valgono i valori predefiniti; le prove li spostano in una
# cartella temporanea.
REPO="${AIDE_SPIEGA_REPO:-/var/www/pantedu}"
RADICE="${AIDE_SPIEGA_RADICE:-}"
PROPRIETARIO="${AIDE_SPIEGA_PROPRIETARIO:-0}"
# Il repository è di `pantedu`, e lì git come root non si usa: si rifiuterebbe
# per `safe.directory`, e un comando che scrive lascerebbe in `.git` file di
# root che poi il rilascio non riesce a toccare.
if [ -n "${AIDE_SPIEGA_COME+x}" ]; then
    read -r -a COME <<< "$AIDE_SPIEGA_COME"
else
    COME=(runuser -u pantedu --)
fi

# ── Le righe del rapporto ─────────────────────────────────────────────────
#
# Attributi cambiati e percorso, separati da un tab, dalle sole sezioni degli
# elenchi. Si legge solo fino a «Detailed information about changes»: da lì in
# poi ci sono le righe delle impronte, che contengono due punti e verrebbero
# scambiate per percorsi.
voci() {
    awk '
        /^Detailed information about changes/ { exit }
        /^(Added|Removed|Changed) entries:/   { dentro = 1; next }
        dentro && index($0, ": /") > 0 {
            i = index($0, ": /")
            print substr($0, 1, i - 1) "\t" substr($0, i + 2)
        }
    ' "$REGISTRO" | sort -u
}

# Il riassunto di AIDE: quante delle sue tre righe ci sono, e quante differenze
# dichiarano. Se le righe non sono tre, o le differenze non sono quante se ne
# leggono negli elenchi, il rapporto non è scritto come ci si aspetta — e un
# rapporto letto male non deve poter dire «niente da guardare». Un registro
# vuoto, per esempio, altrimenti dava zero e zero.
riassunto() {
    awk '
        /^[[:space:]]*(Added|Removed|Changed) entries:[[:space:]]*[0-9]+[[:space:]]*$/ { righe++; n += $NF }
        END { print righe + 0, n + 0 }
    ' "$REGISTRO"
}

# ── 1. Stesso contenuto ───────────────────────────────────────────────────
#
# Le colonne sono quelle di `report_summarize_changes` di AIDE 0.19, misurate
# il 13 settembre 2026 cambiando un attributo alla volta su un albero di prova:
#
#    0 tipo  2 dimensione  3 blocchi  4 permessi  5 proprietario  6 gruppo
#    8 mtime  9 ctime  10 inode  11 collegamenti  12 impronta  13 ACL
#   14 attributi estesi  16 attributi ext
#
# «.» vuol dire uguale, « » non controllato, «=» dimensione uguale. Qualunque
# altra lettera in qualunque altra colonna è un cambiamento che conta, e una
# riga più corta è una riga che non si sa leggere: in tutti e due i casi non è
# attesa per questa prova. Righe vere dal controllo del 13 settembre:
#
#   d =.... mc.. .. .     /usr/local/bin                    → attesa
#   f =.... mci.... .     /var/lib/cloud/data/result.json   → attesa
#   f >.... mci.H.. .     lo script del rilascio            → non per questa prova
solo_date() {
    local b=$1 k c cambiate=0
    [ "${#b}" -ge 18 ] || return 1
    case "${b:0:1}" in f|d) ;; *) return 1 ;; esac
    for ((k = 1; k < ${#b}; k++)); do
        c=${b:k:1}
        case "$k$c" in
            8m|9c) cambiate=1 ;;
            10i) [ "${b:0:1}" = f ] || return 1; cambiate=1 ;;
            2=|*.|*' ') ;;
            *) return 1 ;;
        esac
    done
    [ "$cambiate" -eq 1 ]
}

# Per le prove 2, 3 e 4: la riga non segna un cambio di permessi, proprietario
# o gruppo. Per un file aggiunto o rimosso non c'è un «prima» con cui
# confrontare, e decidono le prove stesse.
permessi_invariati() {
    case "${1:1:1}" in '+'|'-') return 0 ;; esac
    [[ "${1:4:3}" =~ ^[.\ ]{3}$ ]]
}

# Per le prove 2 e 3: di root e non scrivibile dal gruppo né dagli altri. Un
# file identico al commit ma con i permessi aperti non è «atteso».
permessi_da_sistema() {
    local uid modo
    read -r uid modo < <(stat -c '%u %a' -- "$RADICE$1" 2>/dev/null) || return 1
    [ "$uid" = "$PROPRIETARIO" ] && (( (8#$modo & 8#022) == 0 ))
}

# ── 2. File installati dal repository ─────────────────────────────────────
#
# Dove sta, nel repository, la sorgente di un file installato a mano o dal
# rilascio. L'elenco viene da una misura, non da un ricordo: il 13 settembre
# 2026 si è confrontata l'impronta di ogni file sotto `/usr/local`,
# `/etc/systemd/system`, `/etc/cron.d`, `/etc/audit/rules.d`,
# `/etc/aide/aide.conf.d` e `/opt/tex-compile` con quelle del commit in
# servizio, e questi sono quelli che combaciavano.
#
# Un file nuovo da installare va aggiunto qui. Se manca, quando cambia risulta
# da guardare: si sbaglia dalla parte del rumore.
sorgente_di() {
    case "$1" in
        /usr/local/bin/pantedu-deploy-container.sh) echo tools/webhook/deploy-container.sh ;;
        /usr/local/bin/pantedu-backup-encrypted.sh) echo tools/backup/encrypted_backup.sh ;;
        /usr/local/bin/pantedu-deploy-trigger.sh|\
        /usr/local/bin/pantedu-deploy-differito.sh|\
        /usr/local/bin/pantedu-finestra-rilascio.sh) echo "tools/systemd/${1##*/}" ;;
        /usr/local/sbin/aide-check-pantedu.sh) echo tools/ops/aide-check.sh ;;
        /usr/local/sbin/aide-spiega.sh|\
        /usr/local/sbin/ingressi-container.sh|\
        /usr/local/sbin/versioni-indietro.sh|\
        /usr/local/sbin/imposta-chiave-b2.py) echo "tools/ops/${1##*/}" ;;
        /usr/local/sbin/hetzner_snapshot.sh) echo tools/webhook/hetzner_snapshot.sh ;;
        /etc/systemd/system/pantedu-*|\
        /etc/systemd/system/*.d/pantedu-*.conf) echo "tools/systemd/${1#/etc/systemd/system/}" ;;
        /etc/cron.d/aide-pantedu) echo tools/ops/cron.d-aide-pantedu ;;
        /etc/audit/rules.d/pantedu.rules) echo tools/ops/audit-pantedu.rules ;;
        /etc/aide/aide.conf.d/99_pantedu) echo tools/ops/aide-99_pantedu.conf ;;
        /opt/tex-compile/app/*.py) echo "tools/tex-compile-vps/app/${1##*/}" ;;
        /opt/tex-compile/requirements.txt) echo tools/tex-compile-vps/app/requirements.txt ;;
        *) return 1 ;;
    esac
}

# Il commit in servizio: l'etichetta dell'immagine dei container sani
# dell'applicazione, purché sia una sola e sia anche HEAD della copia sull'host.
# Durante uno scambio i container sani sono due, con due immagini: in quel
# caso non si confronta niente, e i file restano da guardare.
COMMIT=""
SENZA_COMMIT=""
COMMIT_CERCATO=0
cerca_commit() {
    [ "$COMMIT_CERCATO" -eq 1 ] && return 0
    COMMIT_CERCATO=1
    local immagini etichetta n head
    immagini=$(docker ps --filter 'name=^pantedu-app-' --filter 'health=healthy' \
        --format '{{.Image}}' 2>/dev/null | sort -u)
    n=$(printf '%s' "$immagini" | grep -c .)
    if [ "$n" -ne 1 ]; then
        SENZA_COMMIT="le immagini dei container sani dell'applicazione sono $n, e ne serve esattamente una"
        return 0
    fi
    etichetta=${immagini##*:}
    if ! [[ "$etichetta" =~ ^[0-9a-f]{40}$ ]]; then
        SENZA_COMMIT="l'immagine in servizio non ha un commit come etichetta"
        return 0
    fi
    head=$("${COME[@]}" git -C "$REPO" rev-parse --verify --quiet HEAD 2>/dev/null)
    if [ "$head" != "$etichetta" ]; then
        SENZA_COMMIT="HEAD della copia sull'host (${head:0:8}) non è il commit in servizio (${etichetta:0:8})"
        return 0
    fi
    COMMIT=$head
}

identico_al_commit() {  # $1 percorso installato, $2 sorgente nel repository
    local atteso reale
    atteso=$("${COME[@]}" git -C "$REPO" rev-parse --verify --quiet "$COMMIT:$2" 2>/dev/null) || return 1
    reale=$(cd / && git hash-object --no-filters -- "$RADICE$1" 2>/dev/null) || return 1
    [ -n "$atteso" ] && [ "$atteso" = "$reale" ]
}

# ── 3. I due file che si riscrivono da sé ─────────────────────────────────
#
# L'upstream lo riscrive `deploy-container.sh` a ogni scambio. È atteso se dice
# esattamente quello che lo script scrive, e se la porta è quella di un
# container sano dell'applicazione. I commenti non contano: nginx non li legge.
upstream_verificato() {
    local righe porte porta re='^upstream pantedu_app \{ server 127\.0\.0\.1:(809[01]); \}$'
    righe=$(grep -vE '^[[:space:]]*(#|$)' -- "$RADICE$1" 2>/dev/null) || return 1
    [[ "$righe" =~ $re ]] || return 1
    porta=${BASH_REMATCH[1]}
    porte=$(docker ps --filter 'name=^pantedu-app-' --filter 'health=healthy' \
        --format '{{.Ports}}' 2>/dev/null)
    [[ "$porte" == *"127.0.0.1:$porta->8080/tcp"* ]]
}

# `audit.rules` lo rigenera augenrules all'avvio, da `rules.d`. È atteso se
# `augenrules --check` risponde che non c'è niente da cambiare: allora è
# esattamente la somma dei file di `rules.d`, e quelli AIDE li guarda uno per
# uno.
regole_audit_verificate() {
    local uscita
    uscita=$(augenrules --check 2>&1) || return 1
    [[ "$uscita" == *"No change"* ]]
}

# ── 4. File dei pacchetti ─────────────────────────────────────────────────
#
# Il pacchetto che possiede il percorso. `dpkg-query -S` esce 1 quando nessun
# pacchetto lo possiede: è una risposta, non un guasto. Le righe «diversion
# by …» dicono chi l'ha deviato, non chi lo possiede.
pacchetto_di() {
    local riga pkg
    riga=$(dpkg-query -S "$1" 2>/dev/null | grep -v '^diversion by' | head -1)
    pkg=${riga%%: /*}
    { [ -n "$riga" ] && [ "$pkg" != "$riga" ]; } || return 1
    pkg=${pkg%%,*}
    printf '%s' "${pkg%%:*}"
}

# ── Il giro ───────────────────────────────────────────────────────────────

SEMPRE_DA_GUARDARE='^(/etc/|/usr/local/|/opt/|/var/spool/cron|/root/|/var/www/pantedu/\.env)'

LETTE=0
N_STESSO=0
N_PACCHETTI=0
ATTESI_NOSTRI=()
DA_GUARDARE=()
declare -A PACCHETTO_DI=() PACCHETTI=()
HA_DPKG=0
command -v dpkg-query >/dev/null 2>&1 && HA_DPKG=1

while IFS=$'\t' read -r bandierine p; do
    [ -n "$p" ] || continue
    LETTE=$((LETTE + 1))

    if solo_date "$bandierine"; then
        N_STESSO=$((N_STESSO + 1))
        continue
    fi

    # Un file rimosso non può essere «identico» a niente: le prove 2 e 3 valgono
    # solo per un file che c'è.
    if [ "${bandierine:0:1}" = f ] && [ "${bandierine:1:1}" != - ] && permessi_invariati "$bandierine"; then
        case "$p" in
            /etc/nginx/conf.d/pantedu-upstream.conf)
                if permessi_da_sistema "$p" && upstream_verificato "$p"; then
                    ATTESI_NOSTRI+=("$p: punta a un container sano dell'applicazione")
                    continue
                fi ;;
            /etc/audit/audit.rules)
                if permessi_da_sistema "$p" && regole_audit_verificate; then
                    ATTESI_NOSTRI+=("$p: augenrules --check non ha niente da cambiare")
                    continue
                fi ;;
            *)
                if src=$(sorgente_di "$p"); then
                    cerca_commit
                    if [ -n "$COMMIT" ] && permessi_da_sistema "$p" && identico_al_commit "$p" "$src"; then
                        ATTESI_NOSTRI+=("$p = $src")
                        continue
                    fi
                fi ;;
        esac
    fi

    if [[ "$p" =~ $SEMPRE_DA_GUARDARE ]]; then
        DA_GUARDARE+=("$p")
        continue
    fi

    if [ "${bandierine:0:1}" = f ] && [ "$HA_DPKG" -eq 1 ] && permessi_invariati "$bandierine" \
        && pkg=$(pacchetto_di "$p"); then
        PACCHETTO_DI["$p"]=$pkg
        PACCHETTI["$pkg"]=1
        continue
    fi

    DA_GUARDARE+=("$p")
done < <(voci)

if [ "${#PACCHETTO_DI[@]}" -gt 0 ]; then
    declare -A COMBACIANO=()
    if command -v debsums >/dev/null 2>&1; then
        # Una sola invocazione per tutti i pacchetti coinvolti. `debsums` scrive
        # ogni file con il suo esito ed esce 2 quando qualcosa non combacia: è
        # una risposta, non un guasto. Conta solo «OK»; quello che non compare —
        # un pacchetto senza impronte — resta da guardare.
        while read -r f esito; do
            if [ "$esito" = OK ]; then COMBACIANO["$f"]=1; fi
        done < <(debsums "${!PACCHETTI[@]}" 2>/dev/null)
    fi
    for p in "${!PACCHETTO_DI[@]}"; do
        if [ -n "${COMBACIANO[$p]+x}" ]; then
            N_PACCHETTI=$((N_PACCHETTI + 1))
        else
            DA_GUARDARE+=("$p")
        fi
    done
fi

ATTESI=$((N_STESSO + N_PACCHETTI + ${#ATTESI_NOSTRI[@]}))
INATTESI=${#DA_GUARDARE[@]}

# ── Il resoconto ──────────────────────────────────────────────────────────

echo
read -r RIGHE_RIASSUNTO DICHIARATE < <(riassunto)
if [ "$RIGHE_RIASSUNTO" -ne 3 ] || [ "$LETTE" -ne "$DICHIARATE" ]; then
    echo "=== il riassunto ha $RIGHE_RIASSUNTO righe su 3 e dichiara $DICHIARATE differenze; negli elenchi se ne leggono $LETTE ==="
    echo "    Il rapporto non è scritto come questo script si aspetta: nessuna differenza si può dire attesa."
    echo "ATTESI=0"
    echo "INATTESI=-1"
    exit 0
fi

echo "=== differenze: $INATTESI da guardare, $ATTESI attese ==="
if [ "$ATTESI" -gt 0 ]; then
    echo "    Attese perché verificate:"
    if [ "$N_STESSO" -gt 0 ]; then
        echo "      $N_STESSO con lo stesso contenuto: sono cambiate solo le date (per un file anche l'inode)"
    fi
    if [ "$N_PACCHETTI" -gt 0 ]; then
        echo "      $N_PACCHETTI file di pacchetti che combaciano con l'impronta del pacchetto"
    fi
    if [ "${#ATTESI_NOSTRI[@]}" -gt 0 ]; then
        echo "      ${#ATTESI_NOSTRI[@]} file nostri${COMMIT:+, confrontati con il commit in servizio ${COMMIT:0:8}}:"
        printf '        %s\n' "${ATTESI_NOSTRI[@]}"
    fi
fi
if [ -n "$SENZA_COMMIT" ]; then
    echo "    I file installati dal repository non si sono potuti confrontare: $SENZA_COMMIT."
fi
if [ "$INATTESI" -gt 0 ]; then
    echo
    echo "    Da guardare:"
    printf '      %s\n' "${DA_GUARDARE[@]}" | sort | head -60
    if [ "$INATTESI" -gt 60 ]; then
        echo "      ... e altre $((INATTESI - 60)). Tutte nel registro."
    fi
fi

echo "ATTESI=$ATTESI"
echo "INATTESI=$INATTESI"
exit 0
