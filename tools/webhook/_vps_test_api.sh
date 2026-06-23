#!/bin/bash
# Test study/content.json endpoint + dump latest PHP errors.
URL='https://pantedu.eu/api/study/content.json?type=mappa&ind=SCI&cls=2&subject=MAT&limit=500'
echo "=== REQUEST: $URL ==="
curl -sk --resolve pantedu.eu:443:127.0.0.1 "$URL" -w '\nHTTP %{http_code}\n' -o /tmp/api_out.txt
echo "--- response body (first 800 bytes) ---"
head -c 800 /tmp/api_out.txt
echo ""
echo "=== nginx error tail ==="
tail -10 /var/log/nginx/error.log
echo "=== app error log ==="
for log in /var/lib/pantedu-data/storage/logs/error_log.json \
           /var/lib/pantedu-data/storage/logs/exception_log.json \
           /var/www/pantedu/log/admin/*.log; do
    if [ -f "$log" ]; then
        echo "--- $log ---"
        tail -10 "$log"
    fi
done
