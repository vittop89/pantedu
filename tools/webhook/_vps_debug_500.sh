#!/bin/bash
# Debug /api/study/content.json 500 error.
set +e
echo "=== PHP error_log destination ==="
grep -E '^error_log|^display_errors|^log_errors' /etc/php/8.4/fpm/php.ini
echo "=== php-fpm pool log directives ==="
grep -E '^(php_admin_value|catch_workers_output|access\.log)' /etc/php/8.4/fpm/pool.d/*.conf
echo "=== latest PHP-FPM errors (200 lines) ==="
tail -200 /var/log/php8.4-fpm.log | grep -iE 'error|warning|fatal|exception' | tail -30
echo "=== app subject_code rows ==="
mysql pantedu -e "SELECT subject_code, COUNT(*) AS n FROM teacher_content GROUP BY subject_code ORDER BY subject_code"
echo "=== app tipi disponibili per subject MAT ==="
mysql pantedu -e "SELECT type_code, subject_code, ind_code, cls_code, COUNT(*) AS n FROM teacher_content WHERE subject_code='MAT' GROUP BY type_code, subject_code, ind_code, cls_code LIMIT 20"
echo "=== nginx access log ultime 5 chiamate /api/study/content ==="
grep "api/study/content" /var/log/nginx/access.log 2>/dev/null | tail -5
