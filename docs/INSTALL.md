# Installazione self-host — pantedu

Guida per installare pantedu su un proprio server (scuola o ente), **a mano,
senza container**: nginx, PHP-FPM e MariaDB installati direttamente sulla
macchina, single-VPS, nessuna dipendenza SaaS proprietaria obbligatoria.

> Non è la stessa cosa del rilascio di produzione di questo repository, che
> dall'8/9/2026 gira a container
> ([ADR-048](../wiki/decisions/ADR-048-rilascio-a-container.md),
> [docs/dev/ci-cd.md](dev/ci-cd.md)): quel rilascio serve chi contribuisce a
> *questo* progetto, non chi installa una copia propria. Questa guida resta
> bare-metal perché è il percorso più semplice per un istituto che non ha già
> Docker.

> Stato verifica: procedura derivata dal deployment di produzione. La
> validazione end-to-end su VM pulita è l'ultimo passo prima della
> pubblicazione (checklist in fondo).

---

## 1. Requisiti

**Hardware minimo** (≤ qualche centinaio di utenti):
- 2 vCPU, 4 GB RAM (8 GB consigliati), 40 GB SSD

**Sistema operativo**: Ubuntu 22.04/24.04 LTS o Debian 12.

**Pacchetti** (su questa macchina: nginx, PHP, database — non texlive, vedi
la nota sotto e il §8):
```bash
sudo apt update
sudo apt install -y nginx mariadb-server certbot python3-certbot-nginx git \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-zip \
  composer nodejs npm
```
> PHP 8.3 o 8.4. **Il rendering TeX/TikZ non gira su questa macchina**: pantedu
> chiama sempre, via HTTP firmato, un microservizio separato
> (`tools/tex-compile-vps/`, Python/FastAPI); è lì che serve texlive, non qui
> (vedi §8). Puoi installarlo sulla stessa macchina o su un'altra: pantedu non
> lo sa, gli basta l'indirizzo.

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

## 4. Configurazione (.env e .env.local)

```bash
cp .env.example .env
```

`.env` è la base comune e **non si modifica**: tutto quello che è della tua
installazione — l'indirizzo, il database, i segreti, e ogni valore che vuoi
diverso dalla base — va in `.env.local` (ignorato da git), che vince su
`.env`. Nel repository di sviluppo `.env` è versionato e il rilascio lo
riporta alla versione del repository: una modifica fatta lì sparisce. La
regola, e chi vince su chi, sta in
[wiki/environment-variables.md](../wiki/environment-variables.md), «Quale
file vale dove».

```bash
cat > .env.local <<EOF
# --- Indirizzo pubblico (link nelle email e reindirizzamenti) ---
APP_URL=https://tuodominio

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

Valori da controllare per la **produzione** (`.env.example` li ha tutti,
commentati; quelli che cambi vanno in `.env.local`). Nel container del
progetto alcuni li controlla l'avvio, che si rifiuta di partire
(`docker/verifica-avvio.php`, le guardie in
[wiki/environment-variables.md](../wiki/environment-variables.md));
un'installazione senza container come questa non ha quel controllo, e li
verifica a mano:

| Variabile | Produzione |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://tuodominio` — obbligatoria: è la radice di ogni collegamento nelle email e nei QR, e senza non c'è ripiego, né sul dominio di produzione né sull'intestazione `Host`. Senza, i messaggi che esistono per il loro collegamento non partono (reset della password, cambio email, consenso dei genitori, avvisi delle bozze e degli incarichi tolti, QR delle credenziali, preavviso delle policy); gli altri partono senza collegamento (codice di accesso, avviso di custodia, ricevuta del DPO, esito dell'iscrizione, segnalazioni di contenuti). In tutti e due i casi resta l'anomalia `indirizzo_pubblico_mancante` |
| `CONTACT_EMAIL`, `DPO_EMAIL`, `ABUSE_EMAIL`, `SECURITY_EMAIL` | le caselle **della tua istanza** (contatto generale, diritti GDPR, segnalazioni di contenuti, vulnerabilità): il `.env` versionato porta quelle dell'istanza di produzione, e vanno sostituite in `.env.local`. Vuote ripiegano su `CONTACT_EMAIL` |
| `PANTEDU_DATA_PATH` | path dati fuori dal web root (es. `/var/lib/pantedu-data`) |
| `SESSION_COOKIE_SECURE` | `true` (solo HTTPS) |
| `CSP_MODE` | `strict` (dopo verifica) |
| `AUDIT_REASON_MODE` | `enforce` |

**`public/.well-known/security.txt` va riscritto a mano.** È un file statico,
servito così com'è, e porta il recapito dell'istanza di produzione di
Pantedu: `Contact`, `Canonical`, `Policy` e il commento. Un'altra istanza lo
deve cambiare con i suoi (e tenere `Expires` nel futuro, RFC 9116),
altrimenti le segnalazioni di vulnerabilità del suo sito arrivano al titolare
di un altro. La pagina `/security` usa `SECURITY_EMAIL` (o `CONTACT_EMAIL`), e
senza nessuna delle due rimanda proprio al `Contact` di questo file.

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
   CDN);
3. abilita in nginx `real_ip` + `limit_req` + il marker `WAF_EDGE_TRUSTED`
   (già nel vhost, da scommentare dopo il lock origin);
4. imposta `WAF_HMAC_SECRET` in `.env.local` (vedi §4).

Toggle WAF (geo, Proof-of-Work, soglie) da `/admin/waf` o via `waf_config`
senza redeploy.

### 7.1 Accesso SSH amministrativo (Zero Trust — consigliato)

**Non esporre SSH su Internet.** Il modello robusto: sshd in ascolto solo su
`localhost` + accesso via **Cloudflare Tunnel** con **Cloudflare Access**
(identità email + MFA). Elimina la superficie brute-force SSH e la dipendenza
da whitelist di IP statici (utile con IP domestici dinamici).

Procedura completa (VPS + client + Access):
[`docs/ops/ssh-cloudflare-tunnel.md`](ops/ssh-cloudflare-tunnel.md).

> In sintesi: `cloudflared tunnel create` + ingress `ssh://localhost:2222` +
> `ListenAddress 127.0.0.1` in `sshd_config` + Access policy sull'email
> dell'operatore. Fallback di emergenza: **console web del provider VPS**
> (bypassa la rete). Stato/config firewall reale documentati in
> [`docs/ops/vps-info.md`](ops/vps-info.md).

### 7.2 Resilienza config (auto-heal al boot)

Uno spegnimento sporco può far perdere file di config non tracciati (es. `.env`
untracked, `storage/logs`, `storage/sessions`) → l'app va in errore
(es. `DB_ENABLED` torna `false` → HTTP 500 sul login). Il servizio
`pantedu-ensure-config.service` (in [`tools/systemd/`](../tools/systemd/))
ricrea questi elementi **a ogni boot, prima di php-fpm**. Installazione:

```bash
cp tools/systemd/pantedu-ensure-config.sh /usr/local/sbin/
cp tools/systemd/pantedu-ensure-config.service /etc/systemd/system/
chmod 755 /usr/local/sbin/pantedu-ensure-config.sh
systemctl daemon-reload && systemctl enable pantedu-ensure-config.service
```

> ⚠️ **Ordering cycle systemd**: evita di mettere in `multi-user.target.wants/`
> unit con `Before=sysinit.target` (es. `auditd`/`audit-rules` mal linkati):
> creano un ciclo che al boot fa cancellare a systemd servizi essenziali
> (`systemd-user-sessions`, `sshd`, `nginx`…) → boot non completo, login
> bloccato, HTTP 521. Verifica con `systemd-analyze verify multi-user.target`
> (nessun output = OK).

---

## 8. Rendering TeX / TikZ (esercizi e verifiche)

Il rendering di formule, esercizi e diagrammi TikZ avviene **lato server con
LaTeX**, ma **non dentro pantedu**: `App\Services\TexCompile\TexCompileClient`
non ha un percorso "in-process" — il costruttore rifiuta endpoint o secret
vuoti — e chiama sempre, via HTTP con firma HMAC, un **microservizio
separato**, in `tools/tex-compile-vps/` (Python/FastAPI, texlive suo). Va
installato e fatto girare a parte, sulla stessa macchina o su un'altra:
la sua guida è `tools/tex-compile-vps/DEPLOY.md` (`provision.sh` per una VPS
Debian). Configura poi in `.env.local`:

```
TEX_COMPILE_ENDPOINT=https://tex.tuosito.it   # o http://127.0.0.1:8001 in locale
TEX_COMPILE_SECRET=...                        # lo stesso segreto del microservizio
```

Senza endpoint e secret configurati, ogni compilazione risponde 503: non è
un guasto, è lo stato "spento" di default. `TEX_COMPILE_ENGINE`,
`TEX_COMPILE_PASSES` e `TEX_COMPILE_TIMEOUT` configurano il microservizio,
non un motore locale a pantedu.

> **Nota**: il rendering client-side legacy via `tikzjax` (WASM nel browser) è
> stato **rimosso** a favore di LaTeX server-side (il motore in `wasm/` e la rotta
> `/tikzjax.js` il 23/9/2026). Le installazioni devono basarsi su texlive sul
> server.

---

## 9. Cron job

Aggiungi alla crontab di `www-data` (`sudo crontab -u www-data -e`):

```cron
# GDPR — anonimizzazione account/dati scaduti (retention). Senza
# GDPR_RETENTION_ENABLED=1 nell'ambiente il job resta in dry-run per
# sempre: stampa che cosa anonimizzerebbe e non tocca nulla (comportamento
# predefinito, voluto finché non lo confermi su un giro reale).
0 2 * * *   GDPR_RETENTION_ENABLED=1 php /var/www/pantedu/tools/gdpr/anonymize_expired.php
# Crypto — report audit giornaliero
30 1 * * *  php /var/www/pantedu/tools/crypto/audit_report.php --json
# GDPR — la prova del piano violazioni NON sta più qui: dal 22/9/2026 è
# l'unità `pantedu-breach-drill.timer`, che a differenza di crontab raccoglie
# l'esito (OnFailure → avviso). Vedi tools/systemd/.
# WAF — aggiornamento GeoIP (se usi il DB locale DB-IP)
0 4 1 * *   /var/www/pantedu/tools/waf/update_dbip_geoip.sh
# Legale — preavviso aggiornamento ToS/AUP (30/7/1 giorni prima)
15 7 * * *  php /var/www/pantedu/tools/legal/notify_policy_update.php --apply
```

> Il job del preavviso è quello che raggiunge chi **non** entra nell'app: il
> banner in-app da solo non soddisfa l'impegno di ToS §8 / AUP §6. Senza
> `APP_MAIL_FROM` o senza `APP_URL` configurate esce con errore invece di
> fallire in silenzio.

---

## 10. Primo accesso — creazione del super-admin

Il **primo** amministratore (super-admin tecnico) si crea con il seed
parametrico, così ogni istituto definisce il **proprio** account e la propria
password (nessuna credenziale cablata):

```bash
export SEED_ADMIN_USERNAME="mario.rossi"          # username login
export SEED_ADMIN_FIRSTNAME="Mario"
export SEED_ADMIN_LASTNAME="Rossi"
export SEED_ADMIN_EMAIL="mario.rossi@tuoistituto.edu.it"
export SEED_INSTITUTE_CODE="ABIS01234X"           # cod. meccanografico reale
export SEED_INSTITUTE_NAME='I.I.S. "Nome Istituto"'
export SEED_INSTITUTE_CITY="Tua Città"
export SEED_INSTITUTE_REGION="Tua Regione"
read -rs SEED_ADMIN_PASSWORD; export SEED_ADMIN_PASSWORD   # password forte, mai in chiaro
php tools/seeds/seed_super_admin.php
```

Lo script crea utente (`is_super_admin=1`), istituto e collegamento
docente↔istituto. È idempotente. **Dettagli, regole di sicurezza e rotazione:
[docs/SUPERADMIN.md](SUPERADMIN.md).**

Dopodiché gli **admin di istituto** successivi si creano dal pannello
**`/admin/institutes`** (caricamento anagrafica + creazione account con
password one-time mostrata una sola volta).

Accedi a `https://tuodominio/login`: al primo login viene forzato il cambio
password.

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
