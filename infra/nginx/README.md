# infra/nginx — Configurazioni nginx versionate

Phase 25.Q.4 — single source of truth per configurazione nginx VPS.

## File

| File | Target install | Note |
|------|----------------|------|
| `beta.pantedu.eu.conf` | `/etc/nginx/sites-enabled/beta.pantedu.eu` | Vhost produzione + SSL + ModSecurity + WAF |

## Apply automatico

`tools/webhook/deploy.sh` esegue (se diff con installato):

```bash
sudo cp infra/nginx/beta.pantedu.eu.conf /etc/nginx/sites-enabled/beta.pantedu.eu.new
sudo nginx -t -c /etc/nginx/nginx.conf  # test syntax
# se OK:
sudo cp /etc/nginx/sites-enabled/beta.pantedu.eu \
        /etc/nginx/sites-enabled/beta.pantedu.eu.bak-$(date +%Y%m%d-%H%M%S)
sudo mv /etc/nginx/sites-enabled/beta.pantedu.eu.new \
        /etc/nginx/sites-enabled/beta.pantedu.eu
sudo systemctl reload nginx
```

Backup automatico con timestamp prima di sostituire. In caso di test
fallito, il `.new` viene rimosso e l'installato resta intatto.

## Apply manuale (dev / debug)

```bash
# Su VPS, da repo clone in /var/www/pantedu
sudo cp infra/nginx/beta.pantedu.eu.conf /etc/nginx/sites-enabled/beta.pantedu.eu
sudo nginx -t && sudo systemctl reload nginx
```

## Storia modifiche

| Data | Modifica | Phase |
|------|----------|-------|
| 2026-05-20 | Rimosso `vendor` da regex blocklist (composer vendor è fuori webroot, blocco ridondante che impediva `/vendor/quill/*` self-host) | 25.Q.4 |
| 2026-05-20 | Cleanup symlink duplicato `beta.pantedu.eu.bak.pantedu` da sites-enabled | 25.Q.4 |

## Non versionato

- `/etc/letsencrypt/live/beta.pantedu.eu/*` — certificati Let's Encrypt (gestiti da certbot, rotazione automatica)
- `/etc/nginx/snippets/pantedu-webhook.conf` — config webhook GitHub deploy (contiene token/secret)
- `/etc/nginx/modsec/main.conf` — config ModSecurity (rules locali)

Quei file restano solo su VPS, gestiti separatamente.
