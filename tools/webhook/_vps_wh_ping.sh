#!/bin/bash
# Local smoke test for /_hooks/github (HMAC ping).
set -e
SECRET=$(grep '^GITHUB_WEBHOOK_SECRET=' /etc/pantedu/webhook.env | cut -d= -f2)
BODY='{"zen":"keep it simple"}'
SIG="sha256=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -r | awk '{print $1}')"
echo "Sig: $SIG"
curl -sk --resolve pantedu.eu:443:127.0.0.1 \
    -X POST https://pantedu.eu/_hooks/github \
    -H "X-GitHub-Event: ping" \
    -H "X-GitHub-Delivery: ping-test-$(date +%s)" \
    -H "X-Hub-Signature-256: $SIG" \
    -H "Content-Type: application/json" \
    --data-binary "$BODY"
echo ""
echo "---last webhook error log---"
tail -3 /var/log/nginx/pantedu-webhook.error.log
