# Installazione self-host — pantedu

Guida per installare pantedu su un proprio server (scuola o ente). Stack:
**nginx + PHP 8.4-FPM + MariaDB 11**, single-VPS, nessuna dipendenza SaaS
proprietaria obbligatoria.

> Stato verifica: procedura derivata dal deployment di produzione. La
> validazione end-to-end su VM pulita è l'ultimo passo prima della
> pubblicazione (checklist in fondo).

---

## 1. Requisiti

**Hardware minimo** (≤ qualche centinaio di utenti):
- 2 vCPU, 4 GB RAM (8 GB consigliati), 40 GB SSD

**Sistema operativo**: Ubuntu 22.04/24.04 LTS o Debian 12.

**Pacchetti**:
```bash
sudo apt update
sudo apt install -y nginx mariadb-server certbot python3-certbot-nginx git \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-gd php8.4-zip php8.4-intl php8.4-bcmath \
  composer nodejs npm \
  texlive-latex-recommended texlive-pictures texlive-latex-extra \
  texlive-fonts-recommended texlive-lang-italian
```
> PHP 8.3 o 8.4. **LaTeX (texlive) sul server è il motore di rendering
> TeX/TikZ** per esercizi e verifiche (vedi §8): è un requisito, non opzionale.
> `texlive-pictures` fornisce TikZ/PGF.

---

## 2. Codice e dipendenze

```bash
sudo mkdir -p /var/www/pantedu && sudo chown "$USER" /var/www/pantedu
git clone https://github.com/vittop89/pantedu.git /var/www/pantedu
cd /var/www/pantedu

composer install --no-dev --optimize-autoloader
npm ci && npm run build                 # build asset Vite
php tools/build-css-bundle.php          # bundle CSS
```

---

## 3. Database

```bash
sudo mysql -e "CREATE DATABASE pantedu CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'pantedu'@'localhost' IDENTIFIED BY 'CAMBIAMI';"
sudo mysql -e "GRANT ALL PRIVILEGES ON pantedu.* TO 'pantedu'@'localhost'; FLUSH PRIVILEGES;"

# Schema iniziale + migrazioni incrementali
sudo mysql pantedu < database/schema.sql
php tools/migrate.php
```

---

## 4. Configurazione (.env)

```bash
cp .env.example .env
```

Edita `.env` con i valori della tua installazione. **I segreti vanno in
`.env.local`** (gitignored), NON in `.env`:

```bash
cat > .env.local <<EOF
# --- Database ---
DB_HOST=127.0.0.1
DB_NAME=pantedu
DB_USER=pantedu
DB_PASS=<password DB scelta sopra>

# --- Crittografia (CRITICI: backup off-line obbligatorio, vedi §6) ---
# Per KMS_MASTER_KEY usa il generatore dedicato (stampa chiave + istruzioni
# di backup): php tools/crypto/generate_kms_key.php
KMS_MASTER_KEY=$(openssl rand -hex 32)
STORAGE_SIGNING_SECRET=$(openssl rand -hex 32)

# --- WAF ---
WAF_HMAC_SECRET=$(openssl rand -hex 32)

# --- Metrics (se usi Prometheus/Grafana) ---
METRICS_BEARER_TOKEN=$(openssl rand -hex 32)
EOF
chmod 600 .env.local
```

Valori chiave in `.env` per la **produzione** (vedi `.env.example` per l'elenco
completo commentato):

| Variabile | Produzione |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://tuodominio` |
| `PANTEDU_DATA_PATH` | path dati fuori dal web root (es. `/var/lib/pantedu-data`) |
| `SESSION_COOKIE_SECURE` | `true` (solo HTTPS) |
| `CSP_MODE` | `strict` (dopo verifica) |
| `AUDIT_REASON_MODE` | `enforce` |

Permessi:
```bash
sudo chown -R www-data:www-data /var/www/pantedu
sudo find /var/www/pantedu -type d -exec chmod 755 {} \;
sudo find /var/www/pantedu -type f -exec chmod 644 {} \;
sudo chmod 600 /var/www/pantedu/.env.local
```

---

## 5. nginx + HTTPS

Adatta il vhost di esempio in [`infra/nginx/pantedu.eu.conf`](../infra/nginx/pantedu.eu.conf)
(sostituisci `server_name`, i path certbot, e `php8.4-fpm.sock`):

```bash
sudo cp infra/nginx/pantedu.eu.conf /etc/nginx/sites-available/pantedu.conf
sudo ln -s /etc/nginx/sites-available/pantedu.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# Certificato Let's Encrypt
sudo certbot --nginx -d tuodominio -d www.tuodominio
```

Il vhost imposta `root` su `public/`, FastCGI a PHP-FPM, header di sicurezza
(CSP/HSTS) e — se attivi la difesa di bordo (§7) — `real_ip` + `limit_req`.

---

## 6. Backup chiavi crittografiche (OBBLIGATORIO)

`KMS_MASTER_KEY` cifra tutte le KEK dei docenti: **se la perdi, perdi tutti i
contenuti cifrati** (by design, confidentiality > availability). Conserva un
backup off-line **prima** di inserire dati reali:

- copia in un password manager + backup cartaceo (BIP-39) in cassaforte, e/o
- Shamir Secret Sharing 3-of-5 (vedi
  [`docs/security/operations/shamir-recovery-runbook.md`](security/operations/shamir-recovery-runbook.md)).

---

## 7. Difesa di bordo + WAF (consigliato)

Il **WAF applicativo** è già attivo (tabella `waf_config`, pannello
`/admin/waf`). Per la postura completa "solo-IT" e anti-spoofing segui il
runbook [`docs/ops/waf-hardening-2026-06.md`](ops/waf-hardening-2026-06.md):

1. metti il sito dietro un CDN/proxy (es. Cloudflare, proxied);
2. **locka l'origin** ai soli IP del CDN (firewall cloud + UFW: 80/443 solo da
   CDN, SSH aperto);
3. abilita in nginx `real_ip` + `limit_req` + il marker `WAF_EDGE_TRUSTED`
   (già nel vhost, da scommentare dopo il lock origin);
4. imposta `WAF_HMAC_SECRET` in `.env` (vedi §4).

Toggle WAF (geo, Proof-of-Work, soglie) da `/admin/waf` o via `waf_config`
senza redeploy.

---

## 8. Rendering TeX / TikZ (esercizi e verifiche)

Il rendering di formule, esercizi e diagrammi TikZ avviene **lato server con
LaTeX** (texlive installato al §1). È il path **primario e raccomandato**:
deterministico, niente dipendenza dal browser, output PDF/SVG coerente.

Modalità:
- **In-process** (default): `pdflatex`/`lualatex` dalla toolchain texlive locale.
  Configurabile via `TEX_COMPILE_ENGINE`, `TEX_COMPILE_PASSES`, `TEX_COMPILE_TIMEOUT`.
- **Microservizio** `tex-compile` separato (per isolare la compilazione): imposta
  `TEX_COMPILE_ENDPOINT` + `TEX_COMPILE_SECRET` in `.env.local`.

> **Nota**: il rendering client-side legacy via `tikzjax` (WASM nel browser) è
> **deprecato** a favore di LaTeX server-side ed è in via di rimozione. Le nuove
> installazioni devono basarsi su texlive sul server.

---

## 9. Cron job

Aggiungi alla crontab di `www-data` (`sudo crontab -u www-data -e`):

```cron
# GDPR — anonimizzazione account/dati scaduti (retention)
0 2 * * *   php /var/www/pantedu/tools/gdpr/anonymize_expired.php
# Crypto — report audit giornaliero
30 1 * * *  php /var/www/pantedu/tools/crypto/audit_report.php --json
# GDPR — drill di breach notification (semestrale)
0 3 1 1,7 * php /var/www/pantedu/tools/gdpr/breach_drill.php
# WAF — aggiornamento GeoIP (se usi il DB locale DB-IP)
0 4 1 * *   /var/www/pantedu/tools/waf/update_dbip_geoip.sh
```

---

## 10. Primo accesso

Il primo istituto e il suo amministratore si creano dal seed iniziale +
pannello **`/admin/institutes`** (caricamento anagrafica istituto e creazione
account amministratore, a cui viene assegnata una password one-time).

Poi accedi a `https://tuodominio/login`: al primo login con password one-time
viene forzato il cambio password.

---

## Checklist di verifica installazione

- [ ] `https://tuodominio/` risponde 200 e mostra la home
- [ ] login amministratore OK; cambio password forzato funziona
- [ ] `php tools/migrate.php` non lascia migrazioni pendenti
- [ ] creazione di una mappa/esercizio di prova + render PDF OK
- [ ] `KMS_MASTER_KEY` salvato off-line (test di recovery eseguito)
- [ ] header di sicurezza presenti (`curl -I` mostra CSP/HSTS)
- [ ] (se edge) hit diretto all'origin bloccato, via CDN OK
- [ ] cron GDPR/crypto schedulati
- [ ] `APP_DEBUG=false`, `.env.local` con permessi `600`

## Riferimenti

- [README.md](../README.md) — panoramica e architettura
- [SECURITY.md](../SECURITY.md) — vulnerability disclosure
- [docs/ops/waf-hardening-2026-06.md](ops/waf-hardening-2026-06.md) — WAF + firewall
- [.env.example](../.env.example) — tutte le variabili commentate
