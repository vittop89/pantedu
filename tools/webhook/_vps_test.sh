#!/usr/bin/env bash
set -e
SECRET=$(grep GITHUB_WEBHOOK_SECRET /etc/pantedu/webhook.env | cut -d= -f2)
BODY='{"zen":"test","ref":"refs/heads/master_vps","after":"deadbeef","pusher":{"name":"manual"}}'
SIG=$(echo -n "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')

echo "=== test 1: ping event valid signature ==="
curl -sS -o /tmp/resp.txt -w 'HTTP %{http_code}\n' \
  -H "Content-Type: application/json" \
  -H "X-GitHub-Event: ping" \
  -H "X-Hub-Signature-256: sha256=$SIG" \
  -d "$BODY" \
  https://beta.pantedu.eu/_hooks/github
cat /tmp/resp.txt; echo

echo "=== test 2: NO signature expect 401 ==="
curl -sS -o /tmp/resp.txt -w 'HTTP %{http_code}\n' \
  -H "Content-Type: application/json" \
  -H "X-GitHub-Event: ping" \
  -d "$BODY" \
  https://beta.pantedu.eu/_hooks/github
cat /tmp/resp.txt; echo

echo "=== test 3: GET expect 405 ==="
curl -sS -o /tmp/resp.txt -w 'HTTP %{http_code}\n' \
  https://beta.pantedu.eu/_hooks/github
cat /tmp/resp.txt; echo

echo "=== webhook log ==="
tail -10 /var/log/pantedu-deploy.log 2>/dev/null || echo "(log vuoto)"
