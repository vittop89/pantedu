#!/usr/bin/env bash
# Inserisce snippet location /_hooks/github nel vhost beta.pantedu.eu.
# Idempotente.
set -euo pipefail

VHOST=/etc/nginx/sites-available/beta.pantedu.eu
SNIPPET=/etc/nginx/snippets/pantedu-webhook.conf

mkdir -p /etc/nginx/snippets
cat > "$SNIPPET" <<'NGINX'
# GitHub webhook auto-deploy → tools/webhook/github.php (HMAC sha256 verify).
location = /_hooks/github {
    if ($request_method !~ ^POST$) { return 405; }
    client_max_body_size 1m;
    access_log off;
    error_log /var/log/nginx/pantedu-webhook.error.log warn;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /var/www/pantedu/tools/webhook/github.php;
    fastcgi_param REQUEST_METHOD $request_method;
    fastcgi_param CONTENT_TYPE $content_type;
    fastcgi_param CONTENT_LENGTH $content_length;
    fastcgi_param REMOTE_ADDR $remote_addr;
    include fastcgi_params;
}
NGINX

if grep -q "pantedu-webhook.conf" "$VHOST"; then
    echo "include già presente — skip"
else
    cp "$VHOST" "${VHOST}.bak.$(date +%s)"
    # Inserisce 'include $SNIPPET;' subito prima dell'ULTIMA `}` (chiusura server :443).
    # Approccio: sed con range, sostituisce la PENULTIMA occorrenza? No.
    # Più semplice: detect numero di righe, trova ultima `^}$`, inserisce sopra.
    LAST=$(grep -n '^}$' "$VHOST" | tail -1 | cut -d: -f1)
    if [[ -z "$LAST" ]]; then
        echo "ERROR: chiusura server block non trovata"
        exit 1
    fi
    sed -i "${LAST}i\\    include $SNIPPET;\\n" "$VHOST"
fi

nginx -t
systemctl reload nginx
echo "OK"
