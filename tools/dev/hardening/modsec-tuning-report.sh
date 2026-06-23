#!/usr/bin/env bash
# Phase 25.K — ModSecurity tuning report.
#
# Analizza /var/log/modsec_audit.log per identificare candidati falsi positivi
# PRIMA di passare da DetectionOnly a Enforce.
#
# Logica:
# 1. Splitta i log per transaction ID (delimitatore ---<id>---A--)
# 2. Per ogni transazione: estrae Host, URI, IP, rule matchate
# 3. Classifica:
#    - SCANNER  (Host = IP nudo, NOT beta.pantedu.eu)        → vero positivo
#    - LEGIT    (Host = beta.pantedu.eu, URI app)            → CANDIDATO falso positivo
#    - UNKNOWN  (altro)
# 4. Per ogni LEGIT, riporta: URI, rule ID, msg, paranoia level, count
#
# Usage:
#   sudo bash tools/dev/hardening/modsec-tuning-report.sh
#   sudo bash tools/dev/hardening/modsec-tuning-report.sh --since "2 days ago"

set -euo pipefail

LOG_FILE="${MODSEC_LOG:-/var/log/modsec_audit.log}"
APP_HOST="${APP_HOST:-beta.pantedu.eu}"
SINCE="${1:-}"

if [[ "${1:-}" == "--since" ]]; then
    # Filtra log dato un timestamp
    SINCE_TS=$(date -d "$2" +%s)
    [[ -z "$SINCE_TS" ]] && { echo "Invalid --since"; exit 1; }
fi

[[ ! -r "$LOG_FILE" ]] && { echo "Cannot read $LOG_FILE"; exit 1; }

# Gather audit files (current + rotated .1 + .2.gz ecc.)
FILES=("$LOG_FILE")
for r in "${LOG_FILE}".1 "${LOG_FILE}".[2-9].gz "${LOG_FILE}".[1-9][0-9].gz; do
    [[ -f "$r" ]] && FILES+=("$r")
done

TMPDIR=$(mktemp -d)
trap "rm -rf $TMPDIR" EXIT

# Concatena tutti i log (gz → gunzip transparent)
for f in "${FILES[@]}"; do
    if [[ "$f" == *.gz ]]; then
        zcat "$f"
    else
        cat "$f"
    fi
done > "$TMPDIR/all.log"

# Parse: ogni transazione delimitata da ---<id>---A-- (start) e ---<id>---Z-- (end)
# Usiamo awk per estrarre transazioni
awk -v APP="$APP_HOST" '
BEGIN { txn_id=""; host=""; uri=""; client_ip=""; in_txn=0 }

# Inizio transazione: ---<id>---A--
/^---[A-Za-z0-9]+---A--/ {
    in_txn=1
    txn_id=$0
    gsub(/^---/, "", txn_id); gsub(/---A--$/, "", txn_id)
    host=""; uri=""; client_ip=""; rules=""
    next
}

# Header section
in_txn && /^Host:/ { host=$2; next }

# Section B: request headers — first line ha metodo + URI + protocol
in_txn && /^[A-Z]+\s+\S+\s+HTTP\// {
    uri=$2
    next
}

# Client IP nel section A (line ha format: timestamp uniq_id client_ip client_port server_ip server_port)
in_txn && /^\[/ && client_ip=="" {
    # Format: [19/May/2026:21:21:13] uniq_id 1.2.3.4 12345 5.6.7.8 443
    split($0, parts, " ")
    if (parts[3] ~ /^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/) client_ip=parts[3]
    next
}

# Rule match line: ModSecurity: Warning. ... [id "12345"] [msg "..."] [paranoia-level/N]
in_txn && /ModSecurity:.*\[id "/ {
    rule_id=""; rule_msg=""; rule_pl=""
    match($0, /\[id "[^"]+"\]/); rule_id=substr($0, RSTART+5, RLENGTH-7)
    match($0, /\[msg "[^"]+"\]/); rule_msg=substr($0, RSTART+6, RLENGTH-8)
    match($0, /\[tag "paranoia-level\/[0-9]+"\]/); if (RLENGTH>0) rule_pl=substr($0, RSTART+20, RLENGTH-22)
    # Append to rules buffer
    if (rules=="") rules=rule_id "|" rule_pl "|" rule_msg
    else rules=rules ";" rule_id "|" rule_pl "|" rule_msg
    next
}

# End transaction
/^---[A-Za-z0-9]+---Z--/ {
    if (in_txn && rules != "") {
        # Classify
        if (host == "" || host ~ /^[0-9.:]+$/) class="SCANNER"
        else if (host == APP) class="LEGIT"
        else class="UNKNOWN"
        # Output 1 line per rule
        n=split(rules, rr, ";")
        for (i=1; i<=n; i++) {
            print class "\t" host "\t" uri "\t" client_ip "\t" rr[i]
        }
    }
    in_txn=0
    next
}
' "$TMPDIR/all.log" > "$TMPDIR/parsed.tsv"

TOTAL=$(wc -l < "$TMPDIR/parsed.tsv")
SCANNER=$(grep -c '^SCANNER' "$TMPDIR/parsed.tsv" || true)
LEGIT=$(grep -c '^LEGIT' "$TMPDIR/parsed.tsv" || true)
UNKNOWN=$(grep -c '^UNKNOWN' "$TMPDIR/parsed.tsv" || true)

echo "════════════════════════════════════════════════════════"
echo "ModSecurity tuning report — $(date -Iseconds)"
echo "════════════════════════════════════════════════════════"
echo
echo "Source files: ${#FILES[@]} ($LOG_FILE + rotated)"
echo "Total rule matches: $TOTAL"
echo "  • SCANNER (Host=IP nudo, no app)        $SCANNER  → veri positivi (lascia)"
echo "  • LEGIT   (Host=$APP_HOST, app)         $LEGIT  → ⚠️ CANDIDATI falsi positivi"
echo "  • UNKNOWN (altro host/refer)            $UNKNOWN  → analizza manualmente"
echo

if [[ "$LEGIT" -eq 0 ]]; then
    echo "✓ NESSUN candidato falso positivo. Safe per switch a Enforce."
else
    echo "⚠️  CANDIDATI FALSI POSITIVI (Host = $APP_HOST):"
    echo
    echo "Top rule_id (sorted by count):"
    echo "  count  rule_id  paranoia  msg"
    grep '^LEGIT' "$TMPDIR/parsed.tsv" \
        | awk -F'\t' '{ split($5, r, "|"); print r[1] "|" r[2] "|" r[3] }' \
        | sort | uniq -c | sort -rn | head -20 \
        | awk -F'|' '{ printf "  %s\n", $0 }'
    echo
    echo "URI colpiti (top 10):"
    grep '^LEGIT' "$TMPDIR/parsed.tsv" \
        | awk -F'\t' '{ print $3 }' | sort | uniq -c | sort -rn | head -10
    echo
    echo "Per disabilitare una regola specifica (es. id 942100) per un URI:"
    echo "  Aggiungi a /etc/nginx/modsec/main.conf:"
    echo '  SecRule REQUEST_URI "@beginsWith /api/teacher/recovery-key" \'
    echo '    "id:9999,phase:1,nolog,pass,ctl:ruleRemoveById=942100"'
    echo
    echo "Poi: sudo nginx -t && sudo systemctl reload nginx"
fi

echo
echo "Per produrre un dump dettagliato dei LEGIT (analisi manuale):"
echo "  grep '^LEGIT' $TMPDIR/parsed.tsv | column -t -s$'\\t'"
echo
echo "Procedura tuning consigliata:"
echo "  1. Lascia girare in DetectionOnly 5-7 giorni"
echo "  2. Re-run questo report ogni 2-3 giorni"
echo "  3. Per ogni LEGIT con count >= 3 → review msg e decidi:"
echo "     a. Vero falso positivo → SecRuleRemoveById o ctl:ruleRemoveById per URI"
echo "     b. Comportamento app sospetto → fix app code"
echo "     c. Bot/scanner travestito → mantieni rule"
echo "  4. Quando LEGIT count = 0 stabile per 24h → switch SecRuleEngine On"
