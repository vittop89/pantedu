#!/usr/bin/env bash
# Phase 25.K.6 — Install ModSecurity v3 + OWASP CRS su nginx.
#
# Strategia rollout sicuro:
#   1. Install libmodsecurity3 + connector nginx
#   2. OWASP Core Rule Set v4 in /etc/modsecurity/crs
#   3. ModSecurity in DETECTION-ONLY (SecRuleEngine DetectionOnly)
#      → logga in /var/log/modsec_audit.log MA non blocca
#   4. Test per 24-48h, analizza falsi positivi
#   5. Switch a Enforce (SecRuleEngine On) quando rule set tunato

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }
W() { printf "\033[33m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

# ──────────────────────────────────────────────────────────────
# 1. Install ModSecurity v3 + nginx connector
# ──────────────────────────────────────────────────────────────
C "=== [1/5] Install ModSecurity v3 + nginx connector ==="

# Debian 12 (bookworm) ha pacchetti ufficiali per ModSecurity v3 + nginx-mod.
# Verifica disponibilità:
if apt-cache show libnginx-mod-http-modsecurity 2>/dev/null | grep -q "^Package:"; then
    DEBIAN_FRONTEND=noninteractive apt-get install -qy \
        libmodsecurity3 libnginx-mod-http-modsecurity modsecurity-crs
    G "  ✓ pacchetti apt installati"
else
    W "  ⚠️ libnginx-mod-http-modsecurity non in apt → build from source"
    W "  Skip per ora (richiede compilazione manuale)"
    R "  TODO: documentare procedura compile from source su Debian 11"
    exit 0
fi

# ──────────────────────────────────────────────────────────────
# 2. ModSecurity base config
# ──────────────────────────────────────────────────────────────
C "=== [2/5] Configure ModSecurity (detection-only mode) ==="

MODSEC_DIR="/etc/nginx/modsec"
mkdir -p "$MODSEC_DIR"

# main.conf — include OWASP CRS + custom whitelists
cat > "$MODSEC_DIR/main.conf" <<'EOF'
# Phase 25.K.6 v3 — ModSecurity v3 + OWASP CRS Debian Trixie.
# Use explicit Include path (NO owasp-crs.load: usa IncludeOptional non
# supportato da ModSec v3, è scritto per libapache2-mod-security2).
# Glob wildcard funziona in ModSec v3.

Include /etc/modsecurity/modsecurity.conf
Include /etc/modsecurity/crs/crs-setup.conf
Include /etc/modsecurity/crs/REQUEST-900-EXCLUSION-RULES-BEFORE-CRS.conf
Include /usr/share/modsecurity-crs/rules/*.conf
Include /etc/modsecurity/crs/RESPONSE-999-EXCLUSION-RULES-AFTER-CRS.conf

# Whitelist endpoint admin (già protetti da auth + CSRF + WAF):
SecRule REQUEST_URI "@beginsWith /api/admin/" \
    "id:1000,phase:1,pass,nolog,ctl:ruleRemoveById=920100"

# Whitelist WAF admin (path bypass anche per ModSec)
SecRule REQUEST_URI "@beginsWith /admin/waf" \
    "id:1001,phase:1,allow,nolog"

# Whitelist /js/waf/fingerprint.js (fingerprint script PHP-generated)
SecRule REQUEST_URI "@beginsWith /js/waf/" \
    "id:1002,phase:1,allow,nolog"

# Increase max body size per upload mappe (default 128KB troppo basso)
SecRequestBodyLimit 10485760              # 10MB
SecRequestBodyNoFilesLimit 1048576        # 1MB (no-file body)
EOF

# Crea modsecurity.conf base se mancante (Debian Trixie non lo crea di default)
if [[ ! -f /etc/modsecurity/modsecurity.conf ]]; then
    cat > /etc/modsecurity/modsecurity.conf <<'EOF'
# Phase 25.K.6 — ModSecurity v3 base config (Debian Trixie compatible).
# Detection-only mode for safe rollout. Switch to On dopo tuning.
SecRuleEngine DetectionOnly
SecRequestBodyAccess On
SecRequestBodyLimit 13107200
SecRequestBodyNoFilesLimit 131072
SecRequestBodyLimitAction Reject
SecRule REQUEST_HEADERS:Content-Type "(?:application(?:/soap\+|/)|text/)xml" "id:200000,phase:1,t:none,t:lowercase,pass,nolog,ctl:requestBodyProcessor=XML"
SecRule REQUEST_HEADERS:Content-Type "application/json" "id:200001,phase:1,t:none,t:lowercase,pass,nolog,ctl:requestBodyProcessor=JSON"
SecResponseBodyAccess Off
SecTmpDir /tmp/
SecDataDir /tmp/
SecAuditEngine RelevantOnly
SecAuditLogRelevantStatus "^(?:5|4(?!04))"
SecAuditLogParts ABIJDEFHZ
SecAuditLogType Serial
SecAuditLog /var/log/modsec_audit.log
SecArgumentSeparator &
SecCookieFormat 0
SecStatusEngine Off
EOF
    # NB: omesse direttive Apache-only: SecRequestBodyInMemoryLimit, SecUnicodeMapFile
fi

# Switch a DetectionOnly se non già
sed -i 's/^SecRuleEngine .*/SecRuleEngine DetectionOnly/' /etc/modsecurity/modsecurity.conf
G "  ✓ SecRuleEngine = DetectionOnly (no block, solo log per tuning)"

# Audit log permission corretto (nginx worker = www-data)
touch /var/log/modsec_audit.log
chown www-data:adm /var/log/modsec_audit.log
chmod 660 /var/log/modsec_audit.log

# Custom whitelists / tuning
cat > "$MODSEC_DIR/pantedu-tuning.conf" <<'EOF'
# Phase 25.K.6 — Tuning per Pantedu.
# Aggiungere qui false-positive whitelist man mano che si analizzano log.

# Esempio (commentato): se rule 941100 trigger su /admin/templates editor JS
# SecRule REQUEST_URI "@beginsWith /admin/templates" \
#     "id:2000,phase:2,pass,nolog,ctl:ruleRemoveById=941100"
EOF

# ──────────────────────────────────────────────────────────────
# 3. Nginx integration
# ──────────────────────────────────────────────────────────────
C "=== [3/5] Nginx integration ==="

# Load module (Debian ships in /etc/nginx/modules-enabled/ via package)
if ! nginx -V 2>&1 | grep -qE "modsecurity_module|ModSecurity"; then
    # Modulo dinamico già linkato dal pkg libnginx-mod-http-modsecurity
    if [[ -f /usr/share/nginx/modules-available/mod-http-modsecurity.conf ]]; then
        ln -sf /usr/share/nginx/modules-available/mod-http-modsecurity.conf \
            /etc/nginx/modules-enabled/50-mod-http-modsecurity.conf
        G "  ✓ modulo nginx ModSecurity attivato"
    fi
fi

# Include direttiva ModSecurity in nginx server block
# Verifica se è già in nginx.conf o sites-enabled
NGINX_SITE="/etc/nginx/sites-enabled/beta.pantedu.eu"
[[ ! -f "$NGINX_SITE" ]] && NGINX_SITE="/etc/nginx/sites-enabled/pantedu.eu"

if [[ -f "$NGINX_SITE" ]]; then
    # Idempotente: inserisce SOLO se non già presente (check pre-sed)
    if grep -qE "^[[:space:]]*modsecurity[[:space:]]+on[[:space:]]*;" "$NGINX_SITE"; then
        G "  ✓ ModSecurity già configurato in nginx (skip insert)"
    else
        cp -a "$NGINX_SITE" "${NGINX_SITE}.bak.pantedu"
        # Insert dopo prima riga "server {" (solo il primo match)
        sed -i '0,/^[[:space:]]*server[[:space:]]*{[[:space:]]*$/{s||server {\n    modsecurity on;\n    modsecurity_rules_file /etc/nginx/modsec/main.conf;|}' "$NGINX_SITE"
        G "  ✓ direttive ModSecurity aggiunte a $NGINX_SITE"
    fi
fi

# ──────────────────────────────────────────────────────────────
# 4. Validate + reload nginx
# ──────────────────────────────────────────────────────────────
C "=== [4/5] Validate nginx config + reload ==="

if nginx -t 2>&1 | tail -5; then
    systemctl reload nginx
    G "  ✓ nginx reloaded con ModSecurity"
else
    R "  ✗ nginx -t FAILED → rollback"
    [[ -f "${NGINX_SITE}.bak.pantedu" ]] && cp -a "${NGINX_SITE}.bak.pantedu" "$NGINX_SITE"
    nginx -t && systemctl reload nginx
    exit 2
fi

# ──────────────────────────────────────────────────────────────
# 5. Smoke test HTTPS
# ──────────────────────────────────────────────────────────────
C "=== [5/5] Smoke test ==="
HTTP_CODE=$(curl -sS -o /dev/null -w '%{http_code}' -m 10 https://beta.pantedu.eu/ 2>&1 || echo "000")
G "  ✓ HTTPS app HTTP $HTTP_CODE (atteso 200/302)"

# Test ModSecurity attivo: SQL injection nell URL → check log
TEST_URL="https://beta.pantedu.eu/?id=1%27%20OR%20%271%27=%271"
curl -sS -o /dev/null "$TEST_URL" 2>&1 || true
sleep 2
if grep -q "id.*94" /var/log/modsec_audit.log 2>/dev/null; then
    G "  ✓ ModSecurity rule trigger registrato in /var/log/modsec_audit.log"
else
    W "  ⚠ no log entry (atteso SQLi detection 94xxx rule)"
fi

echo
G "════════════════════════════════════════"
G "Phase 25.K.6 — ModSecurity OWASP CRS"
G "════════════════════════════════════════"
echo
echo "MODE: DetectionOnly (no block, solo log)"
echo
echo "ROLLOUT PROCEDURE:"
echo "  1. Lascia in DetectionOnly per 24-48h"
echo "  2. Analizza /var/log/modsec_audit.log:"
echo "     grep 'Matched Rule' /var/log/modsec_audit.log | sort | uniq -c | sort -rn"
echo "  3. Whitelist false positive in /etc/nginx/modsec/pantedu-tuning.conf"
echo "  4. Switch enforce:"
echo "     sudo sed -i 's/SecRuleEngine DetectionOnly/SecRuleEngine On/' /etc/modsecurity/modsecurity.conf"
echo "     sudo nginx -t && sudo systemctl reload nginx"
echo
echo "Rollback completo:"
echo "  sudo apt remove --purge libnginx-mod-http-modsecurity"
echo "  sudo systemctl reload nginx"
