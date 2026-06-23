#!/bin/bash
#
# update_dbip_geoip.sh — Download mensile dei DB GeoIP DB-IP Lite.
#
# Scarica due database EUPL-compatible (CC-BY-4.0):
#   - dbip-country-lite.mmdb  → country lookup (WAF geo-blocking)
#   - dbip-asn-lite.mmdb      → ASN lookup (WAF threat intel)
#
# Sostituisce il precedente MaxMind GeoLite2 (EULA proprietary).
# Lo SDK geoip2/geoip2 (Apache-2.0) usato da App\Services\Waf\GeoIpService
# legge il formato MMDB indipendentemente dal vendor → drop-in replacement.
#
# Uso:
#   Manuale:  sudo bash /var/www/pantedu/tools/waf/update_dbip_geoip.sh
#   Cron 1° del mese (con jitter random 0-30min anti thundering-herd):
#     0 4 1 * * www-data sleep $((RANDOM\%1800)) && /var/www/pantedu/tools/waf/update_dbip_geoip.sh
#
# Riferimenti:
#   - https://db-ip.com/db/download/ip-to-country-lite
#   - https://db-ip.com/db/download/ip-to-asn-lite
#   - docs/legal/third-party-licenses-audit.md §3
#   - NOTICE.md (attribuzione CC-BY-4.0 obbligatoria)

set -euo pipefail

# ---------------------------------------------------------------------------
# Configurazione (override via env)
# ---------------------------------------------------------------------------
GEOIP_DIR="${WAF_GEOIP_DIR:-/var/www/pantedu/storage/geoip}"
OWNER="${WAF_GEOIP_OWNER:-pantedu:www-data}"
DEST_COUNTRY="${GEOIP_DIR}/dbip-country-lite.mmdb"
DEST_ASN="${GEOIP_DIR}/dbip-asn-lite.mmdb"
LOG_TAG="dbip-geoip-update"

CURRENT_MONTH=$(date +%Y-%m)
PREVIOUS_MONTH=$(date -d "$(date +%Y-%m-01) -1 day" +%Y-%m)

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
log() {
    local lvl="$1"
    shift
    local msg="$*"
    if command -v logger >/dev/null 2>&1; then
        logger -t "${LOG_TAG}" -p "user.${lvl}" "${msg}"
    fi
    echo "[$(date -Iseconds)] [${lvl}] ${msg}" >&2
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || { log err "Manca comando: $1"; exit 1; }
}

# Download di un singolo DB con fallback al mese precedente.
# $1 = nome prodotto ("country" o "asn")
# $2 = path destinazione finale
update_db() {
    local product="$1"
    local dest="$2"
    local url_current="https://download.db-ip.com/free/dbip-${product}-lite-${CURRENT_MONTH}.mmdb.gz"
    local url_previous="https://download.db-ip.com/free/dbip-${product}-lite-${PREVIOUS_MONTH}.mmdb.gz"

    local tmp
    tmp=$(mktemp --suffix=.mmdb.gz)
    # shellcheck disable=SC2064
    trap "rm -f '${tmp}' '${tmp%.gz}'" RETURN

    local fetched=0
    for url in "${url_current}" "${url_previous}"; do
        log info "Tentativo download [${product}]: ${url}"
        if curl --fail --silent --show-error --location \
                --max-time 120 \
                --user-agent "pantedu-geoip-updater/1.0 (https://beta.pantedu.eu)" \
                --output "${tmp}" \
                "${url}"; then
            fetched=1
            break
        fi
        log warn "[${product}] mirror ${url} non disponibile, provo successivo"
    done

    if [ "${fetched}" -eq 0 ]; then
        log err "[${product}] download fallito da tutti i mirror"
        return 2
    fi

    local sz_gz
    sz_gz=$(stat -c%s "${tmp}")
    if [ "${sz_gz}" -lt 100000 ]; then
        log err "[${product}] file troppo piccolo (${sz_gz} bytes) — probabile HTML errore"
        return 3
    fi

    log info "[${product}] decompressione (${sz_gz} bytes)"
    gunzip -f "${tmp}"
    local decompressed="${tmp%.gz}"

    local sz
    sz=$(stat -c%s "${decompressed}")
    if [ "${sz}" -lt 1000000 ]; then
        log err "[${product}] DB decompresso troppo piccolo (${sz} bytes)"
        return 4
    fi

    if ! file "${decompressed}" | grep -qiE "data|binary"; then
        log err "[${product}] formato non binario riconosciuto"
        return 5
    fi

    # Atomic swap via tmp dello stesso filesystem
    local staging="${dest}.new"
    cp "${decompressed}" "${staging}"
    chown "${OWNER}" "${staging}" 2>/dev/null || log warn "chown ${OWNER} fallito (forse non-root)"
    chmod 664 "${staging}"
    mv -f "${staging}" "${dest}"

    if [ -f "${dest}.bak" ]; then rm -f "${dest}.bak"; fi
    log info "[${product}] ${dest} aggiornato (${sz} bytes)"
}

smoke_test() {
    local db="$1"
    local lookup="$2"
    if [ ! -f "/var/www/pantedu/vendor/autoload.php" ]; then
        log warn "Vendor autoload non disponibile — skip smoke test PHP"
        return 0
    fi
    local out
    out=$(php -r '
        require "/var/www/pantedu/vendor/autoload.php";
        try {
            $r = new GeoIp2\Database\Reader($argv[1]);
            if ($argv[2] === "country") {
                $rec = $r->country("8.8.8.8");
                echo "OK ", $rec->country->isoCode;
            } else {
                $rec = $r->asn("8.8.8.8");
                echo "OK AS", $rec->autonomousSystemNumber;
            }
        } catch (Throwable $e) {
            echo "FAIL ", $e->getMessage();
            exit(1);
        }
    ' "${db}" "${lookup}" 2>&1) || {
        log err "Smoke test [${lookup}] fallito: ${out}"
        return 1
    }
    log info "Smoke test [${lookup}] OK: ${out}"
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
require_cmd curl
require_cmd gunzip
require_cmd file

[ -d "${GEOIP_DIR}" ] || { log info "Creazione ${GEOIP_DIR}"; mkdir -p "${GEOIP_DIR}"; }

EXIT_CODE=0
update_db "country" "${DEST_COUNTRY}" || EXIT_CODE=$?
update_db "asn"     "${DEST_ASN}"     || EXIT_CODE=$?

if [ "${EXIT_CODE}" -ne 0 ]; then
    log err "Uno o più aggiornamenti falliti (code ${EXIT_CODE})"
    exit "${EXIT_CODE}"
fi

# Smoke test (best effort, non blocca exit)
smoke_test "${DEST_COUNTRY}" "country" || true
smoke_test "${DEST_ASN}"     "asn"     || true

log info "Aggiornamento DB-IP completato"
exit 0
