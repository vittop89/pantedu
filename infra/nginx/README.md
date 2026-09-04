# infra/nginx — Configurazioni nginx versionate

Phase 25.Q.4 — single source of truth per la configurazione nginx del VPS.

## File

| File | Target install | Note |
|------|----------------|------|
| `pantedu.eu.conf` | `/etc/nginx/sites-available/pantedu.eu.conf` | **Vhost di produzione** — `server_name pantedu.eu www.pantedu.eu`, SSL, ModSecurity, WAF |
| `ratelimit-zones.conf` | `/etc/nginx/conf.d/` | Zone `limit_req` condivise |

> **Attenzione al nome del file.** `deploy.sh` sincronizza **solo**
> `pantedu.eu.conf`: è quello il file da modificare. Fino al 2026-08-31 questo
> README documentava `beta.pantedu.eu.conf` come sorgente autorevole, ma quel
> vhost serviva l'host `beta.pantedu.eu`, ormai in NXDOMAIN, e il deploy non lo
> guardava più. Chi seguiva il README editava il file sbagliato e non vedeva
> alcun effetto. Il file è stato rimosso; resta recuperabile da git.

## Apply automatico

`tools/webhook/deploy.sh` esegue, se rileva un diff con l'installato:

1. backup dell'installato con timestamp;
2. `nginx -t` sul candidato;
3. se il test passa, applica e `systemctl reload nginx`;
4. se fallisce, **rollback** e log del test in `/tmp/nginx-test.log`.

## Apply manuale (dev / debug)

```bash
# Su VPS, dal clone in /var/www/pantedu
sudo cp infra/nginx/pantedu.eu.conf /etc/nginx/sites-available/pantedu.eu.conf
sudo nginx -t && sudo systemctl reload nginx
```

## Storia modifiche

| Data | Modifica | Phase |
|------|----------|-------|
| 2026-05-20 | Rimosso `vendor` da regex blocklist (composer vendor è fuori webroot, blocco ridondante che impediva `/vendor/quill/*` self-host) | 25.Q.4 |
| 2026-05-20 | Cleanup symlink duplicato `beta.pantedu.eu.bak.pantedu` da sites-enabled | 25.Q.4 |
| 2026-08-31 | Rimosso `beta.pantedu.eu.conf` (host in NXDOMAIN, superato da `pantedu.eu.conf`) e corretto questo README, che indicava il file sbagliato | — |

## Non versionato

- `/etc/letsencrypt/live/pantedu.eu/*` — certificati Let's Encrypt (gestiti da certbot, rotazione automatica)
- `/etc/nginx/snippets/pantedu-webhook.conf` — config webhook GitHub deploy (contiene token/secret)
- `/etc/nginx/modsec/main.conf` — config ModSecurity (rules locali)

Quei file restano solo sul VPS, gestiti separatamente.
