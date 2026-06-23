#!/usr/bin/env bash
set -e
SECRET=$(grep GITHUB_WEBHOOK_SECRET /etc/pantedu/webhook.env | cut -d= -f2)
BODY='{"ref":"refs/heads/master_vps","after":"a6b5a3fd","pusher":{"name":"manual-test"}}'
SIG=$(echo -n "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')

echo "=== test push event triggers deploy ==="
curl -sS -o /tmp/resp.txt -w 'HTTP %{http_code}\n' \
  -H "Content-Type: application/json" \
  -H "X-GitHub-Event: push" \
  -H "X-Hub-Signature-256: sha256=$SIG" \
  -d "$BODY" \
  https://beta.pantedu.eu/_hooks/github
cat /tmp/resp.txt; echo
echo

# Aspetta che il background exec parta
sleep 4

echo "=== deploy log (last 20) ==="
tail -20 /var/log/pantedu-deploy.log
