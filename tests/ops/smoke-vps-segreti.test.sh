#!/bin/bash
#
# Lo smoke test del VPS non stampa i valori di .env.local, nei due versi
# (23/9/2026, A-87).
#
# tools/webhook/_vps_smoke.sh stampava, per tredici chiavi di .env.local fra
# cui cinque segreti (la chiave madre della cifratura, le firme dello storage e
# del WAF, il segreto del servizio TeX, la chiave della posta), i primi otto
# caratteri e la lunghezza. Si lancia a mano, ma l'uscita finisce nel terminale
# e nelle trascrizioni delle sessioni degli agenti: otto caratteri di una
# chiave di 64 cifre esadecimali sono un quarto della chiave.
#
# Qui si esegue lo script vero su un .env.local finto con valori sentinella, e
# con curl e mysql finti. Nell'uscita non deve comparire nessuna sottostringa
# di FINESTRA (tre) caratteri di un valore, né la sua lunghezza: così la
# prova prende anche uno script che ne stampasse i primi tre. L'altro verso: lo
# script deve ancora dire qualcosa di utile — presente, assente, vuota,
# segnaposto, e per la chiave madre se la forma è valida — altrimenti la prova
# la supererebbe uno script che non stampa niente.
#
# Uso: bash tests/ops/smoke-vps-segreti.test.sh
# Variabile: SCRIPT_SMOKE (lo script da provare).
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
SCRIPT_SMOKE="${SCRIPT_SMOKE:-$QUI/../../tools/webhook/_vps_smoke.sh}"
T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/smoke-segreti.XXXXXX")
trap 'rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

[ -f "$SCRIPT_SMOKE" ] || { echo "manca $SCRIPT_SMOKE"; exit 1; }

# curl e mysql finti: niente rete, niente database.
mkdir -p "$T/bin"
printf '#!/bin/bash\nprintf 000\n' > "$T/bin/curl"
printf '#!/bin/bash\nexit 0\n' > "$T/bin/mysql"
chmod +x "$T/bin/curl" "$T/bin/mysql"

# Le sentinelle: lettere e cifre mescolate, scelte perché nessuna delle loro
# terne compaia nell'uscita di uno script corretto (lo misura il primo giro:
# una terna che ci fosse per caso lo farebbe fallire, non passare), e
# lunghezze che si possono cercare come numeri.
FINESTRA=3
KMS_VALIDA='c7e9f5a8b6d9e7c5a9f8b7e6d5c9a7f6e8b5d7c9f6a8e5b9d6c8a7f9e5b6d8c7'
KMS_ROTTA='Kq7Wz9Vx8Jm6Hn5Gb4Ft3Rd9Qs8Pw7Lk6Yj5Ux4'
declare -A VALORI=(
    [STORAGE_SIGNING_SECRET]='Zq8vXw3kLp9yTn6mRb4cHs7jDf5gKa3eUi9oWv7xQ'
    [TEX_COMPILE_SECRET]='Hy5rNb8qWz3xKv7mJt4pLc9sFg6dQa8eRu5iYo7wXk3nM'
    [RESEND_API_KEY]='re_Wx7Kq4Zv9Lp3Jm8Tn6Rb5Hs4Df9Gk7Ua3Ye6Io8Pw'
    [DB_NAME]='Qz4Xv8Kw9Jp3Lm7Tn6'
    [DB_USER]='Vb7Nk3Wq8Zx5Jh9Lt4'
    [STORAGE_PATH]='/Pv9Zk4Wx7Qm3Jt8Ln5Hb6/Rs9'
    [APP_URL]='Tq7Zw4Xk9Vp3Jm8Ln5Wd2Hx6Gz'
    [APP_ENV]='Yh6Wq9Zk3Xv7Jp4'
    [MAIL_FROM]='Kv8Wz3Xq7@Jm9Tn4Lp6Rz5Bx'
    [MAIL_TRANSPORT]='Gw5Zq8Xk3Vn7Jb9'
)

scrivi_env() {  # valore di KMS_MASTER_KEY
    {
        echo "KMS_MASTER_KEY=$1"
        for k in "${!VALORI[@]}"; do
            echo "$k=${VALORI[$k]}"
        done
        # Vuota, e un segnaposto copiato alla lettera (il 15/9/2026 un
        # `<valore>` così ha fermato il sito).
        echo "SESSION_COOKIE_NAME="
        echo 'WAF_HMAC_SECRET="<valore>"'
    } > "$T/env.local"
}

esegui() {
    env -i PATH="$T/bin:/usr/bin:/bin" ENV_LOCAL="$T/env.local" \
        bash "$SCRIPT_SMOKE" > "$T/uscita" 2>&1
}

# La prima sottostringa di FINESTRA caratteri di un valore che compare
# nell'uscita.
trapelate() {  # valore
    local v=$1 i s
    for ((i = 0; i + FINESTRA <= ${#v}; i++)); do
        s=${v:i:FINESTRA}
        grep -qF -- "$s" "$T/uscita" && { echo "$s"; return 0; }
    done
    return 1
}

controlla_che_non_trapeli() {  # nome valore
    local s
    if s=$(trapelate "$2"); then
        ko "$1: nell'uscita c'è «$s», un pezzo del valore"
    else
        ok
    fi
    if grep -qwF -- "${#2}" "$T/uscita"; then
        ko "$1: nell'uscita c'è ${#2}, la lunghezza del valore"
    else
        ok
    fi
}

# ── Primo giro: chiave madre valida ───────────────────────────────────────
scrivi_env "$KMS_VALIDA"
esegui

controlla_che_non_trapeli KMS_MASTER_KEY "$KMS_VALIDA"
for k in "${!VALORI[@]}"; do
    controlla_che_non_trapeli "$k" "${VALORI[$k]}"
done
grep -qi 'chars' "$T/uscita" && ko "l'uscita parla ancora di caratteri contati" || ok

# L'altro verso: lo script dice ancora qualcosa di utile.
riga() { grep -E "^[[:space:]]*$1[: =]" "$T/uscita" | head -n 1; }
riga KMS_MASTER_KEY | grep -q 'forma valida' && ok \
    || ko "KMS_MASTER_KEY valida: attesa «forma valida» (riga: «$(riga KMS_MASTER_KEY)»)"
riga STORAGE_SIGNING_SECRET | grep -q 'presente' && ok \
    || ko "STORAGE_SIGNING_SECRET: attesa «presente» (riga: «$(riga STORAGE_SIGNING_SECRET)»)"
riga SESSION_COOKIE_NAME | grep -qi 'vuota' && ok \
    || ko "SESSION_COOKIE_NAME vuota: atteso «vuota» (riga: «$(riga SESSION_COOKIE_NAME)»)"
riga WAF_HMAC_SECRET | grep -qi 'segnaposto' && ok \
    || ko "WAF_HMAC_SECRET col segnaposto: atteso «segnaposto» (riga: «$(riga WAF_HMAC_SECRET)»)"
grep -q '<valore>' "$T/uscita" && ko "il segnaposto si nomina, non si stampa" || ok

# Una chiave controllata senza riga: assente.
sed -i '/^DB_USER=/d' "$T/env.local"
esegui
riga DB_USER | grep -qi 'assente' && ok \
    || ko "DB_USER senza riga: atteso «assente» (riga: «$(riga DB_USER)»)"

# ── Secondo giro: chiave madre di forma sbagliata ─────────────────────────
scrivi_env "$KMS_ROTTA"
esegui
controlla_che_non_trapeli KMS_MASTER_KEY "$KMS_ROTTA"
riga KMS_MASTER_KEY | grep -q 'forma NON valida' && ok \
    || ko "KMS_MASTER_KEY rotta: attesa «forma NON valida» (riga: «$(riga KMS_MASTER_KEY)»)"

echo "smoke del VPS senza valori di .env.local: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
