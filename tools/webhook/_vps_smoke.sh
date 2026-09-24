#!/bin/bash
# Smoke test pantedu live app.
echo "=== Anonymous endpoints ==="
for path in / /login /api/health /robots.txt /.well-known/security.txt; do
    CODE=$(curl -sk -o /dev/null -w '%{http_code}' --resolve pantedu.eu:443:127.0.0.1 "https://pantedu.eu$path" --max-time 5)
    echo "  $path -> HTTP $CODE"
done

# 2026-09-23 (A-87) — dei valori di .env.local si dice solo se ci sono, se
# sono vuoti o un segnaposto, e per la chiave madre se ha la forma giusta.
# Mai un carattere, mai la lunghezza. Prima si stampavano i primi otto
# caratteri e la lunghezza di tredici chiavi, cinque segreti compresi: otto
# cifre della chiave madre sono un quarto della chiave, e l'uscita finisce nel
# terminale e nelle trascrizioni delle sessioni. Prova:
# tests/ops/smoke-vps-segreti.test.sh.
#
# ENV_LOCAL serve alle prove; sul server vale il percorso di sempre.
ENV_LOCAL="${ENV_LOCAL:-/var/www/pantedu/.env.local}"
echo "=== .env.local: chiavi (solo presenza e forma, mai i valori) ==="
for key in KMS_MASTER_KEY STORAGE_SIGNING_SECRET WAF_HMAC_SECRET TEX_COMPILE_SECRET RESEND_API_KEY DB_NAME DB_USER STORAGE_PATH SESSION_COOKIE_NAME APP_URL APP_ENV MAIL_FROM MAIL_TRANSPORT; do
    riga=$(grep -m 1 "^$key=" "$ENV_LOCAL" 2>/dev/null) || riga=""
    if [ -z "$riga" ]; then
        echo "  $key: ASSENTE"
        continue
    fi
    val=${riga#*=}
    val=${val%$'\r'}
    case "$val" in
        \"*\") val=${val#\"}; val=${val%\"} ;;
        \'*\') val=${val#\'}; val=${val%\'} ;;
    esac
    if [ -z "$val" ]; then
        stato="presente ma VUOTA"
    else
        case "$val" in
            *'<'*'>'* | *[Cc][Hh][Aa][Nn][Gg][Ee]*[Mm][Ee]*)
                stato="presente ma è un SEGNAPOSTO" ;;
            *)
                stato="presente" ;;
        esac
    fi
    # La chiave madre: 64 cifre esadecimali, come la vuole TeacherCryptoService.
    if [ "$key" = KMS_MASTER_KEY ] && [ "$stato" = presente ]; then
        if [[ $val =~ ^[0-9a-fA-F]{64}$ ]]; then
            stato="presente, forma valida"
        else
            stato="presente, forma NON valida (servono cifre esadecimali: vedi TeacherCryptoService)"
        fi
    fi
    echo "  $key: $stato"
done
unset riga val stato

echo "=== DB pantedu tables count ==="
mysql -e "SELECT COUNT(*) AS tables FROM information_schema.tables WHERE table_schema='pantedu'" 2>/dev/null | tail -2

echo "=== users in pantedu DB ==="
mysql pantedu -e "SELECT COUNT(*) AS users FROM users" 2>/dev/null | tail -2

echo "=== storage data dir ==="
ls -ld /var/lib/pantedu-data/storage/
du -sh /var/lib/pantedu-data 2>/dev/null

# 2026-09-09 — tolto il controllo «il vecchio sito e' ancora vivo».
#
# Serviva nel periodo in cui i due assetti giravano in parallelo, e quel
# periodo e' finito da mesi: quel dominio non e' servito da nessun vhost e non
# ha nemmeno un certificato. La riga stampava quindi un codice di errore fisso
# dentro uno smoke test — rumore nel posto peggiore, quello dove si guarda per
# decidere se un rilascio e' andato bene.
