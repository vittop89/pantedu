# WAF hardening — runbook deploy (audit sicurezza 2026-06-01)

Rende solido il WAF applicativo. Riferimento: report sicurezza (bypass WAF,
edge, ban credenziali). Le modifiche di **codice** vanno in deploy normale; le
azioni **ops** (secret, nginx, firewall) sono elencate qui.

## Cosa cambia nel codice (già in deploy)

- **EdgeContext** (`app/Services/Waf/EdgeContext.php`): IP client reale + edge
  fidato + country, a prova di spoofing `X-Forwarded-For`/`Cf-IPCountry`.
  Gli header di forwarding sono fidati SOLO da range Cloudflare o col marker
  `WAF_EDGE_TRUSTED` (fastcgi_param, non spoofabile). → "solo IP IT" robusto.
- **Proof-of-Work** (`WafProofOfWork` + `fingerprint.js`): la challenge ora è
  lavoro computazionale (hashcash, ~1s su device modesti), non più JS-eval.
- **Scoring server-side** (`WafScoringService::serverSignals`): UA reale vs UA
  dichiarato, UA di automazione/CLI, Accept-Language → non auto-dichiarabili.
- **Cookie WAF**: binding a IP **e** UA (anti-replay cross-client) + nonce.
- **Rate-limit** su `/waf/fingerprint`; `RateLimitMiddleware` usa l'IP reale.
- **Fail-CLOSED-verso-challenge**: errore interno del WAF non lascia passare.
- **Anti-ReDoS** sulle regole regex (runtime + validazione al salvataggio).
- **Ban brute-force** (`WafBruteforceGuard`): lockout temporaneo per-username +
  ban IP su credential-stuffing (username distinti) → **NAT-safe** (uno
  studente che sbaglia password sullo stesso account NON banna l'IP scuola).
- **Secret mai vuoto**: `config/waf.php` ricava `hmac_secret` da env o, in
  fallback, da un key-file persistente auto-generato.

## Azioni OPS (in ordine)

### 1. Secret HMAC (CRITICO — prod ha `WAF_HMAC_SECRET` vuoto)
```bash
ssh pantedu-vps
SECRET=$(openssl rand -hex 32)
grep -q '^WAF_HMAC_SECRET=' /var/www/pantedu/.env \
  && sed -i "s|^WAF_HMAC_SECRET=.*|WAF_HMAC_SECRET=$SECRET|" /var/www/pantedu/.env \
  || echo "WAF_HMAC_SECRET=$SECRET" >> /var/www/pantedu/.env
systemctl reload php8.4-fpm
```
(Senza, il fallback key-file copre comunque, ma l'env è la fonte autorevole.)

### 2. Migrazione DB (auto via webhook deploy: `tools/migrate.php`)
`086_waf_login_failures_and_pow.sql` crea `waf_login_failures` + seed config
(pow_enabled=1, pow_required=0, pow_bits=14, soglie brute-force). Verifica:
```bash
mysql pantedu -e "SHOW COLUMNS FROM waf_login_failures; SELECT config_key,config_value FROM waf_config WHERE config_key LIKE 'pow_%' OR config_key LIKE 'bf_%';"
```

### 3. Lock origin ai soli IP Cloudflare (PREREQUISITO sicurezza edge)
Senza, un attaccante colpisce l'origin diretto scavalcando CF e gli header
CF diventano falsificabili.
- **Pannello Hetzner** (azione utente): Cloud Firewall → consenti 80/443 SOLO
  dai range https://www.cloudflare.com/ips/ ; nega il resto.
- **UFW sul VPS** (via SSH): in alternativa/aggiunta, script che apre 80/443
  solo ai CIDR Cloudflare.
- **Cloudflare** (azione utente): assicurarsi che `pantedu.eu` sia **proxied
  (nuvola arancione)** così esistono `CF-Connecting-IP` / `Cf-IPCountry`.

### 4. nginx: real_ip + rate-limit (dopo il lock origin)
```bash
cp infra/nginx/ratelimit-zones.conf /etc/nginx/conf.d/pantedu-ratelimit.conf
cp infra/nginx/pantedu.eu.conf /etc/nginx/sites-available/pantedu.eu.conf  # copia manuale
# Dopo aver verificato il lock origin, scommenta nel vhost:
#   fastcgi_param WAF_EDGE_TRUSTED 1;
nginx -t && systemctl reload nginx
```
`set_real_ip_from` (range CF) + `real_ip_header CF-Connecting-IP` sono già nel
vhost → `$remote_addr` diventa l'IP client reale (rate-limit/log per-utente).

### 5. (Opzionale) CrowdSec firewall-bouncer su nginx
Sposta l'enforcement IP al bordo (oggi è una curl LAPI per IP in PHP). Vedi
`app/Config/waf.php` per i passi `cscli`. Richiede `CROWDSEC_LAPI_KEY` in `.env`.

## Rollback / tuning rapido (senza redeploy, da `/admin/waf` o SQL)
```sql
-- disattiva il PoW se desse problemi su device molto lenti
UPDATE waf_config SET config_value='0' WHERE config_key='pow_enabled';
-- abbassa la difficoltà PoW
UPDATE waf_config SET config_value='12' WHERE config_key='pow_bits';
-- geo: 'enforce' = solo IT (default voluto), 'monitor' = solo log
UPDATE waf_config SET config_value='enforce' WHERE config_key='geo_mode';
```

## Verifica post-deploy
- Browser reale → supera la challenge (PoW) e naviga; `curl` semplice → bloccato.
- `mysql pantedu -e "SELECT outcome,COUNT(*) FROM waf_logs WHERE created_at>NOW()-INTERVAL 1 HOUR GROUP BY outcome"` → presenza di `pass`/`fingerprint_collected`, assenza di picchi `blocked_*` legittimi.
- Login fallito ripetuto → comparsa righe in `waf_login_failures`; stuffing → riga in `waf_blocked_ips` con `source='auth_bruteforce'`.
