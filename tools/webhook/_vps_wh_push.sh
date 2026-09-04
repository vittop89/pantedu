#!/bin/bash
# Local smoke test for /_hooks/github (HMAC push triggers deploy).
set -e
SECRET=$(grep '^GITHUB_WEBHOOK_SECRET=' /etc/pantedu/webhook.env | cut -d= -f2)
HEAD=$(sudo -u pantedu git -C /var/www/pantedu rev-parse HEAD)
BODY='{"ref":"refs/heads/main","after":"'"$HEAD"'","pusher":{"name":"manual-test"}}'
SIG="sha256=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -r | awk '{print $1}')"
echo "Sig: $SIG"
echo "Body: $BODY"
curl -sk --resolve pantedu.eu:443:127.0.0.1 \
    -X POST https://pantedu.eu/_hooks/github \
    -H "X-GitHub-Event: push" \
    -H "X-GitHub-Delivery: push-test-$(date +%s)" \
    -H "X-Hub-Signature-256: $SIG" \
    -H "Content-Type: application/json" \
    --data-binary "$BODY"
echo ""
echo "---trigger file---"
ls -la /var/lib/pantedu-deploy/trigger 2>&1
cat /var/lib/pantedu-deploy/trigger 2>&1
echo ""
echo "---systemd service status (wait 3s for path unit to fire)---"
sleep 3
systemctl status pantedu-deploy.service --no-pager -l | head -30
