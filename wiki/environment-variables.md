---
tags:
  - documentazione/environment
date: 2026-09-23
tipo: environment
status: finale
aliases: ["env", "environment", "variabili"]
cssclasses: []
---

# Environment Variables

File: `.env` (versionato), `.env.local` (mai versionato) e `.env.example`
(il catalogo). Che cosa va in quale, e chi vince: «Quale file vale dove», qui
sotto.

Tabella scritta a mano da `grep` su `app/` (`$_ENV[...]` e `getenv(...)`);
questa pagina non le riconta a ogni modifica, il numero vero lo dà
`npm run env:check` quando gira («N chiavi lette dal codice»). «Esempio»
dice se la variabile compare in `.env.example`: dalla sera del 4 settembre
(revisione architetturale, intervento P7) **tutte le variabili lette dal
codice ci sono**, e `npm run env:check`
(`tools/ci/check-env-example.mjs`, dentro `npm run ci`) blocca ogni chiave
letta e non documentata. Dal 23/9/2026 blocca anche una chiave del `.env`
versionato che il codice non legge (prova
`tests/js-unit/env-versionato-senza-chiavi-morte.test.js`). Le letture
sparse di `$_ENV` fuori da `app/Config` sono state raccolte in
`app/Config/mail.php` (posta, DPO, Resend),
`app/Config/audit.php` (motivazione degli interventi, log degli accessi),
`app/Config/crypto.php` (dual write, lettura dai cifrati, rigenerazione KEK)
e nelle chiavi nuove di `app/Config/security.php` (sanitizer, CSRF, bearer
delle metriche, token di cancellazione, rate limit). Gli override runtime
scritti dai pannelli (`storage/config/*.json`) hanno la precedenza sulle
variabili corrispondenti.

## Quale file vale dove

Questa è la regola (23/9/2026, rilievo A-9 della revisione del 23/9); gli
altri posti che ne parlano rimandano qui.

| dove | versionato | che cosa ci sta |
|---|---|---|
| `.env` | sì | solo valori che valgono in produzione, o neutri: vuoti, o uguali al predefinito del codice. Niente segreti, niente indirizzi di una macchina, niente eccezioni dello sviluppo. È il file che il rilascio monta nel container di produzione, e che `git reset --hard` riporta alla versione del repository a ogni rilascio: modificarlo sul server non serve, la modifica sparisce |
| `.env.local` | mai | tutto quello che è di una macchina: segreti, indirizzi (`APP_URL`, `TEX_COMPILE_ENDPOINT`), credenziali del database, e ogni valore che su quella macchina deve essere diverso dal `.env`, comprese le eccezioni dello sviluppo (`RATE_LIMIT_DISABLED=1`). In produzione è immutabile e il container lo monta in sola lettura |
| `.env.example` | sì | il catalogo: ogni chiave letta dal codice, commentata (`npm run env:check`). Nessuno lo carica mentre l'applicazione gira; lo copiano in `.env` un'installazione nuova (`docs/INSTALL.md`) e i lavori della CI |
| `phpunit.xml` | sì | quello che serve a PHPUnit, con `force`: `APP_ENV=testing`, `RATE_LIMIT_DISABLED=1` |
| i `sed` della CI | sì, nei workflow | riscrivono il `.env` copiato da `.env.example` per un giro solo: `APP_ENV=ci`, il database del job, segreti generati lì; `e2e.yml` e `immagine.yml` anche il limitatore spento |
| l'ambiente del processo | — | quello che il rilascio passa al container (`docker run -e`: la cartella dei dati, il socket del database) |

Chi vince, misurato il 23/9/2026 con Dotenv come lo usa `app/bootstrap.php`:
`.env.local` vince su tutto; l'ambiente del processo vince su `.env`; `.env`
vale per quello che nessun altro dice. I file dei pannelli
(`storage/config/*.json`) vincono sulle variabili corrispondenti; un file
corrotto, o con un valore fuori elenco, vale come assente e scrive
l'anomalia `sostituzione_illeggibile` (`App\Support\SostituzioneSuFile`, dal
23/9/2026: prima due dei cinque file lo ignoravano in silenzio). Una
trappola, fuori dal container: con `variables_order` senza `E` (il `php.ini`
di Ubuntu per la riga di comando dice `GPCS`) una variabile che il processo
ha e che è anche nel `.env` non arriva affatto all'applicazione, che usa il
predefinito del codice. Nell'immagine, che non ha un `php.ini` e usa il
predefinito di PHP (`EGPCS`), arriva.

In produzione (misurato il 23/9/2026, i nomi e non i valori) `.env.local`
ridefinisce dieci chiavi del `.env`: `APP_URL`, `DB_HOST`, `DB_NAME`,
`DB_PASS`, `DB_USER`, `DEPLOYMENT_MODE`, `KMS_MASTER_KEY`,
`RATE_LIMIT_DISABLED`, `STORAGE_SIGNING_SECRET`, `TEX_COMPILE_ENDPOINT`.
Tutte le altre chiavi del `.env` la produzione le prende solo dal `.env`:
per questo contiene valori di produzione. Quelle che ogni installazione deve
dare in `.env.local` nel `.env` sono vuote (`APP_URL`, `KMS_MASTER_KEY`,
`STORAGE_SIGNING_SECRET`, `TEX_COMPILE_ENDPOINT`) o uguali al predefinito del
codice (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`): nessuna porta il valore
di un'altra macchina. Se un server ne perde una, senza `APP_URL` il container
si ferma (guardia `[indirizzo]`, qui sotto) e senza le credenziali del
database si ferma sul controllo del database; per le altre l'avvio non
controlla niente.

Una chiave nuova va sempre in `.env.example`. Nel `.env` solo se il valore
vale in produzione e non è un segreto né un indirizzo; se la produzione deve
cambiarlo, si cambia nel `.env` con una pull request, non sul server.
Altrimenti sta in `.env.local`. Le guardie: `npm run env:check` (niente
chiavi morte nel `.env`), `tests/Unit/EnvTracciatoTest.php` (il `.env` letto
da solo non spegne il limitatore, non accende il debug, non indica il
servizio TeX), le guardie all'avvio qui sotto.

## Come si leggono interruttori e testi

Questa è la regola (23/9/2026, rilievo A-35 della revisione del 23/9); gli
altri posti che ne parlano rimandano qui. Ogni interruttore dell'ambiente si
legge in `app/Config/` con `Config::booleanoDallAmbiente(NOME, predefinito)`
(`app/Core/Config.php`), che prima era sei idiomi diversi:

- vero: `1`, `true`, `yes`, `on`; falso: `0`, `false`, `no`, `off`; senza
  distinzione di maiuscole e senza gli spazi ai lati;
- variabile assente, o riga vuota (`CHIAVE=`): il predefinito del codice;
- qualunque altro valore: il predefinito, e una riga in `error_log` che nomina
  la variabile (non il valore), una volta per processo.

Il predefinito di un interruttore di sicurezza è il lato prudente: acceso per
i controlli (`SECURITY_HIBP_ENABLED`, `XSS_SANITIZE_ENABLED`), spento per i
bypass (`RATE_LIMIT_DISABLED`, `EXPOSE_DELETION_DEBUG_TOKEN`,
`ALLOW_CRYPTO_REGENERATE`, `APP_DEBUG`). Un refuso non spegne una difesa.
Prima del 23/9/2026 `(bool)` faceva valere vera la stringa `'false'` e falsa la
riga vuota; `=== 'true'` ignorava `1` (`APP_VITE_DEV=1` non accendeva Vite);
`=== '1'` ignorava `true`. I bypass che adesso si accendono anche con `true`,
`yes` e `on` sono sorvegliati in produzione dalle guardie `[limitatore]` e
`[gettone]` (qui sotto).

La sorgente non si allarga: si legge `$_ENV` (i `.env`, e l'ambiente del
processo solo dove PHP lo copia lì, vedi la trappola di `variables_order`
sopra). L'ambiente del processo con `getenv()` conta solo per le quattro
chiavi che lo leggevano già: `XSS_SANITIZE_ENABLED`,
`RISDOC_INSTITUTIONAL_TEMPLATES`, `FM_CRITICAL_CSS`, `PDF_IMPORT_PURGE_ONLY`.

Un testo con un predefinito si legge con `Config::testoDallAmbiente(NOME,
predefinito)`: la riga vuota vale il predefinito, come `${VAR:-predefinito}`
negli script bash. Con `?? '…'` una riga vuota vinceva: `BACKUP_DIR=` faceva
cercare i salvataggi alla radice del disco (voce 71 del registro del debito).

Le prove: `tests/Unit/Config/BooleaniDallAmbienteTest.php` (la regola, e le
letture vere dei file di configurazione), `tests/ops/verifica-avvio.test.sh`
(`true` ferma la produzione come `1`). `npm run env:check` riconosce le due
letture come letture della chiave.

## Le guardie all'avvio del container

Con `APP_ENV=production` il container si rifiuta di partire se la
configurazione, letta come la legge l'applicazione, spegne una difesa
(`docker/verifica-avvio.php`, dal 23/9/2026: scheda R-4 della revisione del
23/9). Una riga dice quale guardia e dove guardare; nessun segreto, né la sua
lunghezza, entra nel messaggio.

| guardia | si ferma se | rilievo |
|---|---|---|
| `[ambiente]` | `APP_ENV` non è `production`, `ci`, `testing`, `development` o `local`: un errore di battitura spegnerebbe le guardie | — |
| `[limitatore]` | `security.rate_limit_disabled` vero (`RATE_LIMIT_DISABLED=1`, o `true`, `yes`, `on`) | A-4 |
| `[gettone]` | `security.expose_deletion_debug_token` vero: la risposta alla richiesta di cancellazione dell'account conterrebbe il gettone che la conferma | A-35 |
| `[motivazione]` | `audit.reason_mode` diverso da `enforce` | A-37 |
| `[debug]` | `app.debug` vero | — |
| `[indirizzo]` | `app.url` vuota | A-9 |
| `[posta]` | `mail.from` vuota | A-9 |
| `[archivio]` | `STORAGE_PROVIDER` diverso da `local`: il provider S3 è uno stub | A-58 |
| `[waf]` | `WAF_HMAC_SECRET` manca dall'ambiente o ha meno di 32 byte, o la configurazione non usa quella | A-39 |
| `[scenario]` | scenario e modo non combaciano (3 con `institute`, 1 e 2 con `single`), o scenario 3 senza `INSTANCE_ACN_QUALIFIED` | A-8 |

Stanno all'avvio e non nel bootstrap: un errore nel bootstrap è un 500 su
ogni pagina del container che serve, qui ferma il container nuovo e nel
rilascio a scambio il vecchio continua a servire. Il rovescio: una riga
cambiata in `.env.local` mentre il container gira vale subito (i `.env` si
rileggono a ogni richiesta) e la guardia la vede solo al rilascio
successivo, che si ferma, o al primo riavvio del container, per esempio al
riavvio della macchina: lì non c'è un container vecchio che serve, e il sito
resta giù finché la riga non si corregge. Il motivo sta nel registro del
container: `[avvio] ERRORE: [...]`, con la riga «guarda:». Che cosa si fa, e
come si guardano le guardie dall'host prima di un riavvio
(`docker/verifica-avvio.php --solo-configurazione`): [runbook del sito
irraggiungibile](../docs/ops/runbook-sito-irraggiungibile.md), § 4.7 e § 4.8.

Fuori dalla produzione non si applicano, e lo dicono in una riga. La CI avvia
l'immagine con `APP_ENV=ci` e il limitatore spento (i `sed` di `e2e.yml` e
`immagine.yml`); il VPS in locale (`tools/dev/wsl/vps-locale.sh`) gira come
la produzione e le passa. Non esiste un'altra variabile che le spenga.

Prova nei due versi: `tests/ops/verifica-avvio.test.sh`, nel lavoro «PHP:
analisi statica e test» della CI. Parte dalla produzione misurata il
23/9/2026 (il `.env` versionato e un `.env.local` con le stesse chiavi di
quello del server, valori finti), che deve passare; ogni guardia scatta con
il valore pericoloso e non con quello di produzione; il `.env` della CI,
rifatto con i `sed` letti dai due workflow, deve partire;
`--solo-configurazione` applica le stesse guardie e non scrive niente nei
dati.

Il servizio TeX (`tools/tex-compile-vps`) ha il suo `.env.example`, che
`provision.sh` copia in `/opt/tex-compile/.env` alla prima installazione. Dal
23/9/2026 `npm run env:tex` (`tools/ci/check-env-servizio-tex.mjs`, dentro
`npm run ci` e nella CI) lo confronta con le letture del Python di `app/` e
delle unità systemd: prima non elencava `TIKZ_RENDER_TIMEOUT`,
`SVG_TO_PDF_TIMEOUT`, `SVG_TO_PDF_DPI`, `TEX_COMPILE_LATEXINDENT_OFF` e
`TEX_COMPILE_LATEXINDENT_TIMEOUT` (A-43).

## Applicazione e istanza

| Variabile | Esempio | Letta in | Effetto |
|---|:-:|---|---|
| `APP_ENV` | sì | `Config/app.php`, `Config/database.php`, `SelfServiceController`, `docker/verifica-avvio.php` | `production` accende le guardie all'avvio del container; `testing` (impostato da `phpunit.xml`) sceglie il DB `DB_NAME_TEST`; `ci` nei container della CI. Un valore fuori elenco ferma il container |
| `APP_DEBUG` | sì | `Config/app.php` | `true` → `display_errors` e stack trace nelle pagine di errore |
| `APP_URL` | sì | `Config/app.php`, letta da `Support/IndirizzoPubblico.php` | la radice di ogni collegamento assoluto: email, codici QR delle credenziali di classe, ritorno di Drive, `User-Agent` delle chiamate esterne. Dal 23/9/2026 senza ripieghi (né il dominio di produzione né l'intestazione `Host`): vuota o non `http(s)://host`, i messaggi il cui collegamento è la ragione d'essere non partono, gli altri partono senza, e resta l'anomalia `indirizzo_pubblico_mancante` (il perché nel commento della classe) |
| `APP_TIMEZONE` | sì | `Config/app.php` | `date_default_timezone_set()` |
| `PANTEDU_DATA_PATH` | sì | `Config::cartellaDati()`, usata da `Config/app.php`, `auth.php`, `monitoring.php`, `storage.php`; `waf.php` a parte | radice dei dati fuori dal repo (storage, log, chiavi, sessioni); vuota o assente = dentro il repo. Fino al 14/9/2026 «vuota» dava `/storage`: vedi il registro delle modifiche |
| `DEPLOYMENT_MODE` | sì | `Config/app.php` | asse legacy `single` / `institute` (ADR-017), allineato dallo scenario quando lo cambia il pannello; in produzione, se non combacia con lo scenario, il container non parte |
| `DEPLOYMENT_SCENARIO` | sì | `Config/app.php` | `personal` / `colleagues` / `institute` (ADR-032); override `storage/config/deployment_scenario.json` |
| `INSTANCE_ACN_QUALIFIED` | sì | `Config/app.php` | dichiarazione di infrastruttura qualificata ACN: senza, lo scenario 3 non si attiva |
| `INSTANCE_OPERATOR_NAME`, `INSTITUTE_LEGAL_NAME`, `INSTITUTE_OWNER_EMAIL` | sì | `Config/app.php` | nomi e contatti mostrati nelle trust page e nei documenti |
| `RISDOC_INSTITUTIONAL_TEMPLATES` | sì | `Config/app.php` | `false` nasconde i modelli istituzionali risdoc a tutti gli account |
| `CONTACT_EMAIL` | sì | `Config/mail.php` (`mail.contact_email`) | casella generale dell'istanza: Reply-To delle email di iscrizione, contatti del modale autore; ripiego delle tre qui sotto. Vuota anche lei, quella posta non la riceve nessuno e l'applicazione lo dice (23/9/2026: prima le caselle di produzione erano scritte nel codice) |
| `DPO_EMAIL` | sì | `Config/mail.php` (`mail.dpo_email`) | destinatario del form DPO e delle notifiche di custodia; vuota = `CONTACT_EMAIL` (fino al 23/9/2026 la casella di produzione) |
| `ABUSE_EMAIL` | sì | `Config/mail.php` (`mail.abuse_email`) | riceve le segnalazioni di `/segnalazione-contenuti` ed è il Reply-To delle comunicazioni all'autore del contenuto; vuota = `CONTACT_EMAIL`; senza nessuna delle due la segnalazione resta nella coda di `/admin/takedown` con l'anomalia `segnalazione_senza_casella` |
| `SECURITY_EMAIL` | sì | `Config/mail.php` (`mail.security_email`) | recapito delle vulnerabilità sulla pagina `/security`; vuota = `CONTACT_EMAIL`. `public/.well-known/security.txt` è statico: il suo `Contact` si allinea a mano |
| `ALERT_EMAIL` | sì | `tools/ops/avvisa_guasto.php` (non da `app/Config`: è uno script agganciato a `OnFailure=` delle unità systemd, fuori dal processo dell'applicazione) | destinatario degli avvisi di unità systemd fallite; vuota = `APP_MAIL_FROM`, che è send-only e nessuno legge |
| `APP_MAIL_FROM`, `APP_MAIL_FROM_NAME`, `APP_MAIL_REPLY_TO` | sì | `Config/mail.php`; chi manda posta parte da `Mailer::fromConfig()` | mittente della posta transazionale |
| `RESEND_API_KEY` | sì | `Config/mail.php` (`mail.resend_api_key`) | chiave del servizio di posta; senza, `Mailer` non invia |
| `APP_VITE_DEV` | sì | `Config/app.php` (`app.vite_dev`, letto da `Support/ViteManifest.php`) | usa il dev server Vite invece del manifest; `1` come `true` dal 23/9/2026 |
| `FM_CRITICAL_CSS` | sì | `Config/app.php` (`app.critical_css`, letto da `views/partials/head.php`) | CSS critico in linea e il resto asincrono; anche dall'ambiente del processo |

## Database

| Variabile | Esempio | Letta in | Effetto |
|---|:-:|---|---|
| `DB_ENABLED` | sì | `Config/database.php` | `false` → `Database::connection()` lancia; residuo dell'hosting condiviso (debito 4) |
| `DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET` | sì | `Config/database.php` | DSN dell'utente applicativo |
| `DB_SOCKET` | sì | `Config/database.php`, `Core/Database.php` | valorizzata, si passa dal socket Unix: host e porta non contano |
| `DB_NAME_TEST` | sì | `Config/database.php` | DB della suite (`pantedu_test`) quando `APP_ENV=testing` |
| `DB_MIGRATOR_USER`, `DB_MIGRATOR_PASS` | sì | `Core/Database.php` | utente DDL usato solo da `tools/migrate.php`; crea i trigger append-only |
| `DB_MAINT_USER`, `DB_MAINT_PASS` | sì | `Core/Database.php` | utente dei job di purga (unico con DELETE sui registri); dal 2026-09-24 l'anonimizzazione degli account inattivi usa la connessione dell'applicazione |
| `DB_QUOTA_MB` | sì | `InfrastructureMonitorService` | soglia di quota per il pannello infrastruttura |

## Sessione, CSRF, login

| Variabile | Esempio | Letta in | Effetto |
|---|:-:|---|---|
| `SESSION_COOKIE_NAME`, `SESSION_LIFETIME`, `SESSION_COOKIE_SAMESITE` | sì | `Config/session.php` | cookie e inattività (1800 s) |
| `SESSION_ABSOLUTE_LIFETIME` | sì | `Config/session.php` (`session.absolute_lifetime`) | durata massima dal login (43200 s); dal 14/9/2026 al posto di `SESSION_REGENERATE_INTERVAL`, perché l'id non ruota più |
| `SESSION_DRIVER` | sì | `Config/session.php` (`session.driver`) | `file` (predefinito) o `database` (ADR-039); un valore sconosciuto ferma il container all'avvio |
| `SESSION_SAVE_PATH` | sì | `Config/session.php` (`session.save_path`) | cartella delle sessioni a file; vuota: `storage/sessions` dei dati d'istanza, che l'avvio del container crea per `www-data` con 0700 |
| `SESSION_COOKIE_SECURE` | sì | `Config/session.php` | se assente, vuota o sconosciuta, auto-detect HTTPS (fino al 23/9/2026 vuota valeva «no») |
| `CSRF_TOKEN_LIFETIME` | sì | `Config/security.php` (`security.csrf_token_lifetime`) | TTL del token (7200 s) |
| `LOGIN_MAX_ATTEMPTS`, `LOGIN_LOCKOUT_SECONDS` | sì | `Config/auth.php` | rate limit del login (5 / 300 s) |
| `LOG_MAX_ENTRIES` | sì | `Config/audit.php` (`audit.access_log_max_entries`) | righe massime di `access_log.json` |
| `RATE_LIMIT_DISABLED` | sì | `Config/security.php` (`security.rate_limit_disabled`) | `1` disattiva il limitatore delle richieste per rotta (solo prove e sviluppo); dove vale `1`, qui sotto. Dal 23/9/2026 anche `true`, `yes`, `on` (in produzione la guardia `[limitatore]` li ferma) |
| `RATE_LIMIT_BACKEND` | sì | `Config/security.php` (`security.rate_limit_backend`) | `db` / `session`; `auto` sceglie `db` se disponibile |

### Dove `RATE_LIMIT_DISABLED` vale 1

Questa è la regola; gli altri posti che ne parlano rimandano qui. Il
limitatore delle richieste per rotta (`RateLimitMiddleware`) si spegne solo
con il valore `1`, e solo in tre posti:

| dove | chi mette `1` | perché |
|---|---|---|
| PHPUnit, le due suite, in locale e in CI | `phpunit.xml`, con `force="true"` | i contatori si accumulano da una prova all'altra |
| la suite end-to-end in CI e la prova dell'immagine | i `sed` di `e2e.yml` e `immagine.yml`, sul `.env` che copiano da `.env.example` | 429 a catena fra le spec |
| lo sviluppo in WSL | `.env.local`, dove `server.sh` aggiunge la chiave se manca ([sviluppo in WSL](../docs/dev/sviluppo-in-wsl.md)) | la suite end-to-end in locale |

Ovunque altro vale `0`: il `.env` versionato, che il rilascio monta anche nel
container di produzione; `.env.example`, da cui partono un'installazione nuova
e i lavori della CI `a11y.yml` e `lighthouse.yml`; il `.env.local` del VPS in
locale (`tools/dev/wsl/vps-locale.sh`), che imita la produzione. E la
produzione (misurato il 23/9/2026 in produzione, senza stampare valori:
.env.local ridefinisce la chiave e il limitatore è acceso), dove dal
23/9/2026 il container non parte con il limitatore spento: guardia
`[limitatore]`, qui sopra.

Fino al 23/9/2026 l'`1` stava nel `.env` versionato, e in produzione il
limitatore restava acceso solo grazie a `.env.local` (rilievo A-4 della
revisione architetturale; [[changelog/2026-09]]). Le guardie:
`tests/Unit/EnvTracciatoTest.php` fallisce se `.env` o `.env.example`, letti
da soli come li legge l'applicazione, spengono il limitatore o accendono
`APP_DEBUG`; `tests/Unit/LimitatoreAccesoTest.php` prova che, con il
limitatore acceso nella configurazione, il limite si applica anche a una
richiesta senza intestazione;
`tests/ops/limitatore-sviluppo.test.sh` prova lo script di sviluppo e che
`server.sh` lo chiami.

Nelle prove lo stato non è garantito per tutta la suite: le prove che
ricaricano `.env` come mutabile (per esempio `CurriculumServiceTest`) lo
riportano a `0` per quelle che seguono, dove `.env.local` non ha la chiave,
come in CI.
Nessuna prova deve contarci: chi vuole il limitatore acceso lo chiede, con
l'intestazione `X-Pantedu-Rate-Limit: enforce` (che opera solo in senso
restrittivo) o con la configurazione.

## Sicurezza applicativa

| Variabile | Esempio | Letta in | Effetto |
|---|:-:|---|---|
| `SECURITY_TOTP_ENABLED` | sì | `Config/security.php` | master switch del secondo fattore. Con dei ruoli elencati `false` non spegne l'obbligo: fino al 23/9/2026 `'false'` si leggeva «acceso», e la contraddizione resta sul lato prudente con l'anomalia `secondo_fattore_interruttore_contraddetto` (`TwoFactorEnforcement::mode()`); per spegnere si svuotano i ruoli, o si decide dal pannello |
| `SECURITY_TOTP_REQUIRED_ROLES` | sì | `Config/security.php` | ruoli obbligati (`super_admin,administrator,teacher`); override `storage/config` via `TwoFactorEnforcement` |
| `SECURITY_HIBP_ENABLED` | sì | `Config/security.php` | controllo password compromesse (fail-open); vuota vale acceso (fino al 23/9/2026 spento) |
| `CSP_MODE` | sì | `Config/security.php` | `relaxed` / `report-only` / `strict`; override in `waf_config` |
| `CSP_REPORT_URI` | sì | `Config/security.php` | endpoint dei report CSP; `/api/csp-report` è quello interno (NDJSON in `<logs>/csp-reports/`, dal 2026-09-05) |
| `AUDIT_REASON_MODE` | sì | `Config/audit.php` (`audit.reason_mode`, letto dal middleware) | `enforce` / `warn` / `disabled`; senza valore, o con uno sconosciuto, `enforce` (dal 23/9/2026; prima `warn`) |
| `XSS_SANITIZE_ENABLED` | sì | `Config/security.php` (`security.xss_sanitize_enabled`, letto dai tre sanitizer) | kill switch della sanitizzazione (mai in produzione) |
| `TOS_ENFORCE` | sì | `Config/multitenancy.php`, `Support/TosEnforcement.php` | gate ToS/AUP; override `storage/config/tos_enforcement.json` dal pannello |
| `LEGAL_NOTICE_DAYS` | sì | `Config/multitenancy.php` | preavviso minimo (30) |
| ~~`GDPR_TEXT_VERSION`~~ | no | — | non si legge più dal 23/9/2026 (DOC-19): la versione dei consensi è il `versione:` del testo dell'informativa dello scenario attivo (`DeploymentScenario::versioneInformativa()`); `app/Config/gdpr.php` è tolto. Se un `.env.local` la ha ancora, la riga si toglie |
| `GDPR_RETENTION_ENABLED` | sì | `Config/retention.php`, anche dall'ambiente del processo | `false` → i job di retention stampano soltanto. Basta l'`Environment=` dell'unità systemd (dal 23/9/2026, voce 194: prima serviva la chiave in un `.env`, perché la PHP da riga di comando dell'host non copia l'ambiente in `$_ENV`) |
| `PDF_IMPORT_PURGE_ONLY` | sì | `Config/pdf_import.php` (`pdf_import.purge_only`, letto da `tools/cron/process_pdf_import_jobs.php`) | solo pulizia, niente estrazione; anche dall'ambiente del processo |
| `EXPOSE_DELETION_DEBUG_TOKEN` | sì | `Config/security.php` (`security.expose_deletion_debug_token`) | espone il token di cancellazione (solo test); in produzione il container non parte (guardia `[gettone]`) |
| `ALLOW_CRYPTO_REGENERATE` | sì | `Config/crypto.php` (`crypto.allow_regenerate`) | consente la rigenerazione delle chiavi (pericoloso) |
| `TELEMETRY_ENABLED` | sì | `Config/app.php` (`app.telemetry_enabled`, letto da `Core/Telemetry.php`) | emissione degli span |
| `METRICS_BEARER_TOKEN` | sì | `Config/security.php` (`security.metrics_bearer_token`) | token per `/metrics` |

## Cifratura e storage

| Variabile | Esempio | Letta in | Effetto |
|---|:-:|---|---|
| `KMS_MASTER_KEY` | sì (vuota) | `Services/Crypto/ChiaveMadre` (dal 23/9/2026 l'unica lettura in `app/`; la usano `TeacherCryptoService`, `TeacherRecoveryService`, `AdminCryptoStatusController`, `PdfImport/ProviderKeyStore`, `PdfImport/Session/SessionStorage`; anche `tools/crypto/rewrap_master.php`, che da lì legge pure `KMS_MASTER_KEY_NEW`) | chiave master da cui derivano le KEK per docente; solo in `.env.local`; 64 caratteri esadecimali, ogni altra forma è «malformata» e non vale come assente |
| `KMS_MASTER_KEY_NEW` | sì (commentata) | `tools/crypto/rewrap_master.php` | solo durante un cambio della chiave master: la chiave nuova, che l'applicazione ignora; si toglie a cambio finito (`docs/security/operations/kms-recovery.md`) |
| `CRYPTO_DUAL_WRITE`, `CRYPTO_READ_FROM` | sì | `Config/crypto.php` (`crypto.dual_write`, `crypto.read_from`) | transizione plaintext → ciphertext dei body |
| `STORAGE_SIGNING_SECRET` | sì | `Config/storage.php`, `StorageFactory` | HMAC degli URL firmati; vuoto = URL firmati non funzionanti (debito 15) |
| `STORAGE_PROVIDER`, `STORAGE_S3_*` | sì | `Config/storage.php` | `local` (default). S3 è predisposto ma non implementato: in produzione il container non parte con un valore diverso da `local` |

## WAF

| Variabile | Esempio | Letta in | Effetto |
|---|:-:|---|---|
| `WAF_HMAC_SECRET` | sì | `Config/waf.php` | firma cookie e challenge; se < 32 byte, key-file auto-generato in `storage/keys`. In produzione, sotto i 32 byte il container non parte |
| `WAF_GEOIP_DB`, `WAF_GEOIP_ASN_DB` | sì | `Config/waf.php`, `WafAdminController` | file `.mmdb` per paese e ASN |
| `WAF_POW_BITS` | sì | `Config/waf.php` | difficoltà del proof-of-work (16) |
| `TRUSTED_PROXIES` | sì | `Config/waf.php`, `EdgeContext` | CIDR di proxy fidati oltre ai range del CDN |
| `CROWDSEC_LAPI_URL`, `CROWDSEC_LAPI_KEY` | sì | `Config/waf.php`, `WafCrowdSecBouncerService` | bouncer CrowdSec (fail-open): acceso solo con tutte e due. L'URL non ha predefinito dal 2026-09-23 (era `127.0.0.1:8080`, che nel container è il nginx dell'applicazione) e deve valere nel container e sull'host: il gateway del bridge Docker, come per il TeX, non `host.docker.internal`, che l'host non risolve ([diagnostica](../docs/ops/diagnostica.md#crowdsec-il-bouncer-parla-con-la-lapi)); lo verifica la diagnostica, `--solo=crowdsec` |

I toggle operativi del WAF (enabled, mode, soglie, geo) stanno nella tabella
`waf_config`, non in `.env`.

## Backup

| Variabile | Esempio | Letta in | Effetto |
|---|:-:|---|---|
| `BACKUP_DIR` | sì | `Config/backup.php` (`backup.dir`, letto da `tools/ops/diagnostica.php` e `tools/gdpr/breach_drill.php`) | cartella dei salvataggi cifrati di `tools/backup/encrypted_backup.sh`; vuota = `/var/backups/pantedu`, come nello script (fino al 23/9/2026 la diagnostica cercava alla radice del disco) |

## Integrazioni esterne

| Variabile | Esempio | Letta in | Effetto |
|---|:-:|---|---|
| `TEX_COMPILE_ENDPOINT`, `TEX_COMPILE_SECRET`, `TEX_COMPILE_TIMEOUT`, `TEX_COMPILE_ENGINE`, `TEX_COMPILE_PASSES` | sì | `Config/tex_compile.php`, `TexCompileClient`, `TikzRenderClient` | microservizio TeX; endpoint vuoto = compilazione disattivata |
| `TIKZ_RENDER_TIMEOUT`, `TIKZ_RENDER_SVG_MAX` | sì | `Config/tex_compile.php` | render TikZ |
| `CA_BUNDLE` | sì | `Config/app.php` (`app.ca_bundle`), usata da `App\Support\BundleCa`, che chiamano tutti quelli che fanno chiamate HTTPS in uscita (servizio TeX, Drive, import PDF, HIBP, threat intel, GitHub) | bundle delle CA; vuoto = quello di sistema di Linux, poi quello di curl. Dal 23/9/2026 sostituisce `TEX_COMPILE_CA_BUNDLE`, `DRIVE_CA_BUNDLE` e `PDF_IMPORT_CA_BUNDLE` e le chiavi `ca_bundle` di `Config/tex_compile.php`, `drive.php` e `pdf_import.php`, che avevano come default un percorso XAMPP di Windows (A-42) |
| `GOOGLE_DRIVE_CLIENT_ID`, `GOOGLE_DRIVE_CLIENT_SECRET`, `GOOGLE_DRIVE_REDIRECT_URI` | sì | `Config/drive.php` | OAuth Drive del docente |
| `DRIVE_ENABLED` | sì | `Config/drive.php` (`drive.enabled`) | Drive si offre ai docenti (ADR-038). Falso se manca: niente comandi Drive, giro notturno fermo con zero; vero senza credenziali è un guasto segnalato |
| `SPID_ENABLED`, `CIE_ENABLED` | sì | `Config/spid.php` (`spid.enabled`), `Config/cie.php` (`cie.enabled`) — dal 23/9/2026: prima i controller leggevano `$_ENV` direttamente, contro la regola del primer, senza che nessuno se ne accorgesse (`Config::get()` non trovava niente) | scaffolding: `SpidController`/`CieController` rispondono 503 finché non c'è registrazione AgID |
| `PDF_IMPORT_ENABLED`, `PDF_IMPORT_DEFAULT_PROVIDER`, `PDF_IMPORT_MAX_PAGES`, `PDF_IMPORT_MAX_PDF_BYTES`, `PDF_IMPORT_DPI`, `PDF_IMPORT_PROVIDER_TIMEOUT`, `PDF_IMPORT_RETENTION_DAYS`, `PDF_IMPORT_DAILY_TOKENS` | sì | `Config/pdf_import.php` | tool di estrazione da PDF (off di default). Serve un rasterizzatore sul server (Imagick+Ghostscript o `pdftoppm` di poppler-utils, `PdfRasterizer.php`): l'immagine del container non ne installa nessuno, e ad oggi si accende solo fuori dal container (D-19) |
| `PDF_IMPORT_ANTHROPIC_KEY`, `PDF_IMPORT_ANTHROPIC_MODEL`, `PDF_IMPORT_OPENAI_KEY`, `PDF_IMPORT_OPENAI_MODEL`, `PDF_IMPORT_OLLAMA_*` | sì | `Config/pdf_import.php` | provider LLM; Ollama locale con allowlist host (SSRF) |
| `PDF_IMPORT_ASYNC`, `PDF_IMPORT_PHP_CLI`, `PDF_IMPORT_NUMBER_SCAN`, `PDF_IMPORT_AUTO_DIFFICULTY`, `PDF_IMPORT_AUTO_TOPICS`, `PDF_IMPORT_AUTO_TRANSLATION`, `PDF_IMPORT_SOLUTIONS_PER_REQUEST` | sì | `Config/pdf_import.php` | worker in background, passate automatiche |
| `PDF_IMPORT_RATE_GENERIC`, `PDF_IMPORT_RATE_LLM` | sì | `Config/pdf_import.php` (`pdf_import.rate.*`), letto dal limitatore delle rotte dell'importazione da PDF (`rate:pdf_import,config:pdf_import.rate.pdf_import`) | richieste al minuto per utente, generiche (30) e verso l'LLM (12). `/translate` ha 30 scritto nella rotta, di proposito: il client la chiama a ripetizione. Prima del 23/9/2026 le rotte avevano il numero scritto e la variabile non faceva niente (voce 195) |

## Variabili riservate (documentate prima del codice che le leggerà)

`CIE_REQUESTED_AUTH_LEVEL`, `CIE_SP_CERT_PATH`, `CIE_SP_ENTITY_ID`,
`CIE_SP_KEY_PATH`, `SPID_REQUESTED_ATTRIBUTES`, `SPID_REQUESTED_AUTH_LEVEL`,
`SPID_SP_CERT_PATH`, `SPID_SP_ENTITY_ID`, `SPID_SP_KEY_PATH`,
`SPID_SP_ORGANIZATION`, `SPID_SP_TECH_CONTACT_EMAIL`: scaffolding SPID/CIE,
elencate come «riservate» nel check (`RESERVED`), che quindi non le segnala.
`FM_HTTP3_ENABLED`, `FM_VITALS_ENABLED`, `LOG_LEVEL` e `LOG_RETENTION_DAYS`
sono state tolte da `.env.example` il 2026-09-04: nessun codice le leggeva.
Le ultime tre erano rimaste nel `.env` versionato, che il rilascio monta in
produzione, fino al 2026-09-23.

## Variabili di test

`FM_E2E_BASE_URL`, `FM_E2E_ADMIN_USERNAME`, `FM_E2E_ADMIN_PASSWORD`,
`E2E_TEACHER_USER`, `E2E_TEACHER_PASS` sono lette da `playwright.config.js`
e da alcuni tool in `tools/`, da `.env.local`; non dall'applicazione.
