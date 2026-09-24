#!/usr/bin/env bash
# smoke_test.sh — verifica end-to-end del servizio tex-compile-vps.
#
# USO:
#   bash smoke_test.sh https://tex.tuosito.it <SECRET_HMAC>
#
# Esegue:
#   1. GET /health    (no auth)
#   2. POST /compile  con .tex minimo  (auth HMAC)
#   3. POST /compile  con HMAC errato → atteso 401
#   4. POST /compile  con .tex rotto  → atteso 422 + log
#
# Salva PDF di esito in /tmp/smoke_<timestamp>.pdf
#
set -euo pipefail

ENDPOINT="${1:-}"
SECRET="${2:-}"

if [[ -z "$ENDPOINT" || -z "$SECRET" ]]; then
    echo "Usage: $0 <https://tex.example.com> <SECRET_HMAC>"
    exit 1
fi

PASS=0
FAIL=0

assert_eq() {
    local label="$1" expected="$2" actual="$3"
    if [[ "$expected" == "$actual" ]]; then
        echo "  ✓ $label  (got $actual)"
        ((PASS++))
    else
        echo "  ✗ $label  (expected $expected, got $actual)"
        ((FAIL++))
    fi
}

sign() {
    # sign <timestamp> <body>
    printf '%s.%s' "$1" "$2" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}'
}

# ─── Test 1: health ────────────────────────────────────────────────────
echo "[1/4] GET /health (no auth)..."
HEALTH_STATUS=$(curl -s -o /tmp/health.txt -w "%{http_code}" "$ENDPOINT/health")
assert_eq "status 200" "200" "$HEALTH_STATUS"
grep -q "ok" /tmp/health.txt && echo "  ✓ body contains 'ok'" || echo "  ✗ body senza 'ok'"

# ─── Test 2: compile valido ────────────────────────────────────────────
echo ""
echo "[2/4] POST /compile (sorgente valido)..."
TEX='\documentclass{article}\usepackage[T1]{fontenc}\begin{document}Smoke test \(E=mc^2\)\end{document}'
TEX_B64=$(printf '%s' "$TEX" | base64 -w0)
PAYLOAD=$(printf '{"tex_b64":"%s","doc_id":"smoke","engine":"pdflatex","passes":1}' "$TEX_B64")
TS=$(date +%s)
SIG=$(sign "$TS" "$PAYLOAD")

PDF_OUT="/tmp/smoke_${TS}.pdf"
HTTP=$(curl -s -o "$PDF_OUT" -w "%{http_code}" \
    -X POST "$ENDPOINT/compile" \
    -H "Content-Type: application/json" \
    -H "X-Timestamp: $TS" \
    -H "X-Signature: $SIG" \
    -d "$PAYLOAD")
assert_eq "compile valido → 200" "200" "$HTTP"
if file "$PDF_OUT" | grep -q "PDF document"; then
    echo "  ✓ output è un PDF valido ($(stat -c %s "$PDF_OUT") bytes)"
    ((PASS++))
else
    echo "  ✗ output NON è un PDF: $(file "$PDF_OUT")"
    ((FAIL++))
fi

# ─── Test 3: HMAC errato ───────────────────────────────────────────────
echo ""
echo "[3/4] POST /compile (HMAC errato → atteso 401)..."
TS=$(date +%s)
HTTP=$(curl -s -o /dev/null -w "%{http_code}" \
    -X POST "$ENDPOINT/compile" \
    -H "Content-Type: application/json" \
    -H "X-Timestamp: $TS" \
    -H "X-Signature: deadbeef" \
    -d "$PAYLOAD")
assert_eq "HMAC errato → 401" "401" "$HTTP"

# ─── Test 4: tex rotto ─────────────────────────────────────────────────
echo ""
echo "[4/4] POST /compile (tex rotto → atteso 422 + log)..."
BAD_TEX='\documentclass{article}\begin{document}\unknown_command\end{document}'
BAD_B64=$(printf '%s' "$BAD_TEX" | base64 -w0)
BAD_PAYLOAD=$(printf '{"tex_b64":"%s","doc_id":"smoke-bad","engine":"pdflatex","passes":1}' "$BAD_B64")
TS=$(date +%s)
SIG=$(sign "$TS" "$BAD_PAYLOAD")
HTTP=$(curl -s -o /tmp/bad.json -w "%{http_code}" \
    -X POST "$ENDPOINT/compile" \
    -H "Content-Type: application/json" \
    -H "X-Timestamp: $TS" \
    -H "X-Signature: $SIG" \
    -d "$BAD_PAYLOAD")
assert_eq "tex rotto → 422" "422" "$HTTP"
grep -q '"ok"\s*:\s*false' /tmp/bad.json && echo "  ✓ body contiene ok:false" && ((PASS++)) || { echo "  ✗ body inatteso: $(cat /tmp/bad.json)"; ((FAIL++)); }

# ─── Recap ─────────────────────────────────────────────────────────────
echo ""
echo "============================================================"
echo "  PASS: $PASS    FAIL: $FAIL"
echo "============================================================"
[[ $FAIL -eq 0 ]] && exit 0 || exit 1
