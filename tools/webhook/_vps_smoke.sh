#!/bin/bash
# Smoke test pantedu live app.
echo "=== Anonymous endpoints ==="
for path in / /login /api/health /robots.txt /.well-known/security.txt; do
    CODE=$(curl -sk -o /dev/null -w '%{http_code}' --resolve pantedu.eu:443:127.0.0.1 "https://pantedu.eu$path" --max-time 5)
    echo "  $path -> HTTP $CODE"
done

echo "=== .env.local keys ==="
for key in KMS_MASTER_KEY STORAGE_SIGNING_SECRET WAF_HMAC_SECRET TEX_COMPILE_SECRET RESEND_API_KEY DB_NAME DB_USER STORAGE_PATH SESSION_COOKIE_NAME APP_URL APP_ENV MAIL_FROM MAIL_TRANSPORT; do
    if grep -q "^$key=" /var/www/pantedu/.env.local 2>/dev/null; then
        VAL=$(grep "^$key=" /var/www/pantedu/.env.local | head -1 | cut -d= -f2-)
        if [ -z "$VAL" ]; then
            echo "  $key = (empty)"
        else
            HEAD8=$(echo "$VAL" | head -c 8)
            LEN=${#VAL}
            echo "  $key = ${HEAD8}... ($LEN chars)"
        fi
    else
        echo "  $key = MISSING"
    fi
done

echo "=== DB pantedu tables count ==="
mysql -e "SELECT COUNT(*) AS tables FROM information_schema.tables WHERE table_schema='pantedu'" 2>/dev/null | tail -2

echo "=== users in pantedu DB ==="
mysql pantedu -e "SELECT COUNT(*) AS users FROM users" 2>/dev/null | tail -2

echo "=== storage data dir ==="
ls -ld /var/lib/pantedu-data/storage/
du -sh /var/lib/pantedu-data 2>/dev/null

echo "=== fismapant still alive (parallel run check) ==="
curl -sk -o /dev/null -w 'beta.fismapant.com -> HTTP %{http_code}\n' --resolve beta.fismapant.com:443:127.0.0.1 https://beta.fismapant.com/ --max-time 5
