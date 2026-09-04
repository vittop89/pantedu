# Changelog

Tutte le modifiche notevoli a questo progetto saranno documentate in
questo file.

Formato basato su [Keep a Changelog](https://keepachangelog.com/it/1.1.0/),
versioning [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Per la cronologia dettagliata mese-per-mese con riferimenti a commit,
ADR e ticket, vedi [`wiki/changelog/`](wiki/changelog/).

## [Unreleased]

## [0.4.0] - 2026-09-04

Riconfigurazione dopo l'osservazione del DPO sul Regolamento cloud ACN
(Decreto direttoriale n. 21007/24): un'infrastruttura non qualificata non
può ospitare dati di cui un Istituto è Titolare. Nessuno studente reale si
era mai registrato; la piattaforma dichiara ora tre scenari di esercizio e
non conserva dati di studenti nei primi due.

### Added
- **Scenari di esercizio** (ADR-032): 1 uso personale, 2 colleghi, 3
  Istituto. `App\Support\DeploymentScenario` è la sorgente di verità e
  allinea i flag legacy `DeploymentMode`/`StudentRegistration`; pannello
  `/admin/system/deployment` con confronto, documenti attivi, motivazione
  obbligatoria e conferma ACN (`INSTANCE_ACN_QUALIFIED` in `.env`). Login,
  registrazione e informativa seguono lo scenario; `/accesso-classe` è la
  porta degli studenti senza account (scenari 1 e 2).
- **Credenziale di classe applicata lato server** (`App\Support\ClassAccessGrant`):
  l'ospite con credenziale vede i contenuti e le mappe pubblicati dal
  docente della credenziale per la sua classe; banner con «Esci».
- **Hash di IP e User-Agent** in tutti i registri di audit
  (`App\Services\Audit\RequestFingerprint`, migration 100 con conversione
  delle righe esistenti).
- **Risdoc**: compilazioni cifrate con la chiave del docente (migration 101,
  `CompilationRepository`, `tools/gdpr/encrypt_risdoc_compilations.php`);
  `CompilationScrubber` svuota prima del salvataggio i campi riferiti a
  studenti o genitori quando non esistono account studente, e il client
  avvisa il docente.
- Informativa per Istituto (`docs/privacy/informativa-istituto.md`) per lo
  scenario 3, con token da compilare dall'adottante.
- Registro delle operazioni esteso agli studenti e al consenso genitoriale;
  motivazione obbligatoria per le mutazioni amministrative.
- Curriculum e sezioni: importatori dal dataset MIUR (indirizzi, materie,
  adozioni), registro degli alias per le descrizioni, vocabolario fissato
  dalla scuola, pannello sezioni con incarichi dei docenti e classe degli
  studenti, assegnazione incarichi da riga di comando; una scuola nuova
  nasce solo con un codice MIUR valido.
- Docente: pagina «da categorizzare» per i contenuti che non compaiono da
  nessuna parte, etichette dedotte dai contenuti gemelli, archiviazione dei
  contenuti per sezione invece che per anno.
- `tools/dev/render_page.php`: una pagina attraverso il router vero, senza
  browser.
- Check CI sulle versioni legali: `summary` obbligatorio e non oltre i 500
  caratteri della colonna a DB.

### Changed
- ToS 1.3 e AUP 1.2: tolte le formule che presentavano l'Applicativo come
  strumento dell'Istituto; divieto di inserire dati personali di studenti in
  qualunque campo; la piattaforma non conserva gli atti formali
  dell'Istituto; Shamir protegge la copia di sicurezza della chiave, non
  elimina l'accesso operativo.
- DPIA 1.5, informativa 2.3, registro dei trattamenti 1.5: nessun dato di
  studenti, hash dichiarati, copie di sicurezza fino a due anni, archivi in
  chiaro elencati con la loro durata.
- Pacchetto DPO riscritto sui tre scenari; bozza di DPA art. 28 ed
  executive summary ritirati con premessa.
- Purga dei log privilegiati e crypto a cinque anni, come dichiarato;
  access log di nginx disattivato.
- Blocco copyright sulla condivisione anche per i grant verso singoli
  colleghi; i membri di un gruppo devono essere colleghi dello stesso
  istituto.
- Selettore «studente» tolto dalla scheda di recupero e dallo schema dei
  modelli risdoc.
- Sidebar: selettori che seguono l'istituto, classi che seguono
  l'indirizzo, badge dello studente leggibile.

### Fixed
- Il grant della credenziale di classe non era letto da nessun endpoint di
  studio: la modalità Anonima non mostrava nulla.
- Un ospite senza credenziale poteva leggere per id qualunque contenuto
  pubblicato di qualunque docente; ora solo ciò che è davvero pubblico.
- Il consenso genitoriale concesso non finiva in nessun registro.
- Il migrator leggeva i file `.sql` spezzandoli a caso.
- Il deploy clonava il curriculum di una scuola su tutte le altre.
- PDF dei documenti: Edge headless tornava prima di scrivere il file;
  `build_pdf.sh` non trovava pandoc lanciato da PowerShell.
- Registro delle versioni legali: un `summary` oltre i 500 caratteri faceva
  morire il sync a metà.

### Removed
- Modulo di autorizzazione (modello risdoc con dati anagrafici dello
  studente) e le sue compilazioni (migration 102).
- `tools/migrate-compilations.php`, che leggeva le compilazioni in chiaro:
  disattivato.
- Rotte `/api/teacher/subjects`, non funzionanti da tempo.

### Security
- IP e User-Agent non più in chiaro in `content_action_log`,
  `privileged_access_log`, `teacher_recovery_audit` e `audit_activity_log`.
- Compilazioni risdoc cifrate at rest come il resto dei contenuti.
- Sanitizer di pubblicazione: esclusi anche i PDF compilati con dati reali;
  i test sui codici MIUR non partono più da codici reali.

## [0.3.0] - 2026-09-02

### Added
- Secondo fattore finalmente richiesto al login, con codice via email come
  alternativa dichiarata all'app e una via di rientro.
- Recupero password self-service; l'IP del recupero si conserva come hash.
- Tabelle di audit append-only con trigger a livello di database, creati
  dall'utenza delle migration; la retention gira davvero.
- Tre utenti di database (app, migrazioni, manutenzione) e un deploy che non
  ingoia i fallimenti; `.env.local` immutabile, rispettato dagli script.

### Changed
- ToS 1.2: corretta l'affermazione secondo cui l'operatore non poteva
  accedere ai contenuti; in vigore subito con rinuncia motivata al preavviso.
- Pacchetto DPO riletto integralmente.

### Fixed
- Il QR del secondo fattore usciva verso un servizio esterno con il segreto
  nell'URL; ora è generato in locale.
- Il job di anonimizzazione GDPR non aveva mai portato a termine
  un'esecuzione.
- Il bundler non inlinava il CSS, che restava vecchio.

## [0.2.0] - 2026-09-01

Prima versione dopo la migrazione da fismapant: riassume il lavoro da
maggio ad agosto 2026.

### Added
- **Accessibility WCAG 2.1 AA — Fase C** (Legge Stanca + dir EU 2016/2102):
  - **C.1** `css/a11y.css` shared layer caricato da head.php e shell.php
    con skip link "Salta al contenuto", `.fm-sr-only` utility, global
    `:focus-visible`, `prefers-reduced-motion`, `forced-colors` mode.
    Modali `role="dialog"` + `aria-modal` + `aria-labelledby`. Sync-panel
    `role="status"` + `aria-live="polite"` + `role="progressbar"` con
    `aria-valuenow` updates. data_breach_new form refactor: label[for] +
    input[id] + `aria-required` + `aria-describedby` per help.
  - **C.2** Typography px → rem migration su tutto il CSS (326 dichiarazioni
    in 4 file CSS + 175 inline style in 13 view PHP = 501 totale).
    Token scale `--fm-fs-xs..3xl` in tokens.css. WCAG 1.4.4 resize-200%
    ora supportato senza overflow.
  - **C.3** Color tokens completi (22 brand + 5 semantic + 4 surface +
    5 text + ecc.) con varianti dark per ognuno. Theme resolution:
    `html[data-theme="dark"]` override esplicito > `body.fm-dark` legacy >
    `@media (prefers-color-scheme: dark)` > default light. Dark toggle
    aria-pressed + aria-label.
  - **C.4** Dichiarazione di accessibilità AgID Form-A pubblicata su
    `/accessibility` (route + controller + `docs/legal/accessibility.md`).
    axe-core/playwright integration: `tests/e2e/a11y_wcag_aa.spec.js`
    asserisce zero violazioni critical/serious su 7 pagine pubbliche +
    skip link keyboard test + dark toggle ARIA test.
- Migration script `tools/crypto/migrate_hkdf_prefix.php` per ruotare il
  prefisso HKDF dei wrap KEK senza re-encrypt downstream blob.
- `/admin/monitoring` cheatsheet collapsible con 10 snippet copy-paste SSH
  per operazioni admin frequenti (Grafana password, fail2ban unban,
  restart service, tail log, file delete, disk usage, journalctl,
  certbot, deploy, backup DB).
- nginx `/grafana/` proxy con `auth_request` gate verso
  `GrafanaGateController` (super_admin only, SSO via header X-WEBAUTH-USER).
- Webhook auto-deploy via systemd Path unit (sostituisce SSH-based GHA):
  `tools/webhook/github.php` + `pantedu-deploy.{path,service}` con
  privilege separation a 3 stadi (PHP -> flag file -> systemd root).
- Conformità Developers Italia: `publiccode.yml`, `CONTRIBUTING.md`,
  `CODE_OF_CONDUCT.md`, `CHANGELOG.md`.

### Changed
- HKDF/HMAC prefix delle chiavi crypto rinominati `fismapant-*` ->
  `pantedu-*` (TeacherCryptoService, ClasseKeyService, TeacherRecoveryService,
  AdminCryptoStatusController + tools CLI). Migration database eseguita
  per re-wrappare i 2 `teacher_keys.wrapped_kek` con il nuovo salt.
  Side effect accettato: manifest recovery e export ZIP firmati prima di
  questa migration falliscono HMAC verify (dev environment, no impact).
- MySQL view definer rebound `fismapant_app@localhost` ->
  `pantedu_app@localhost` su 8 view dopo il DROP USER del vecchio account
  app (verifica_documents, risdoc_compilations, classe_keys, print_info,
  teacher_access_credentials, published_content, teacher_content, exercises).
- Grafana datasource + dashboards + provisioning yaml rinominati
  fismapant-* -> pantedu-*. Folder Fismapant orfana cancellata via API.
- Promtail config: `/var/log/fismapant-{deploy,waf-blocked}.log` ->
  `/var/log/pantedu-*.log`.
- fail2ban jail+filter `fismapant-waf` rinominato `pantedu-waf` con nuovo
  logpath.
- composer.json license `proprietary` -> `EUPL-1.2` (allineato a LICENSE).
- package.json: aggiunto `"license": "EUPL-1.2"`.

### Fixed
- Composer install permission: vendor/ generato dall'user `pantedu` aveva
  gruppo non-www-data -> 403 su /. Fix: chgrp -R www-data + chmod g+rX
  applicato in deploy.sh post-install.
- /etc/pantedu/webhook.env perm 750 dir bloccava read di www-data per il
  webhook HMAC verify. Fix: chmod 755 dir.
- nginx vhost legacy `beta.fismapant.com` + cert Let's Encrypt rimosso
  post-kill fismapant. Cleanup .bak files in sites-enabled.

### Removed
- VPS-only kill di fismapant: stopped+disabled 11 systemd units fismapant,
  rimosso `/var/www/fismapant` (con chattr -i per .env*) e
  `/var/lib/fismapant-data`, droppato DATABASE fismapant + USER
  fismapant_app, certbot delete beta.fismapant.com + tex.fismapant.com,
  cleanup `/etc/sudoers.d/fismapant-deploy`, `/etc/fismapant/`,
  `/usr/local/{bin,sbin}/fismapant-*`, userdel -r fismapant.
  Domini `fismapant.com` (hosting legacy) e repo GitHub `vittop89/fismapant`
  NON toccati (archivio storico).

### Security
- Snapshot Hetzner pre-migration `pre-pantedu-migration-20260523-0150`
  conservato per rollback.
- DB dump finale fismapant in `/var/backups/pantedu/final-fismapant/`
  (668K compresso).
- Grafana admin password reset richiesto post-migrazione (era inaccessibile
  dopo cleanup user).

## [0.1.0] - 2026-05-22

### Added
- Initial import del codebase ribrandizzato da fismapant.
- Licenza EUPL-1.2 ufficiale (`LICENSE` integralmente incluso).
- Integrazione Resend API per email transazionali (DPO contact, password
  reset, parent consent flow per studenti minorenni).
- Documentazione GDPR completa: TOS docente, AUP, DPA template,
  takedown procedure (PDF + Markdown) in `docs/legal/`.
- Pentest documentation 2026-04-29 in `docs/security/pentest/`.
- 66 tabelle DB clonate da fismapant (mantiene compat KMS via
  KMS_MASTER_KEY identica, STORAGE_SIGNING_SECRET identica,
  WAF_HMAC_SECRET identica).

### Architecture (ereditata da fismapant Phase 1-26)
- Stack PHP 8.4-fpm + MariaDB 11.x + nginx + ModSecurity.
- Frontend Vanilla JS modules (no jQuery), Vite build, Codemirror 6 +
  Tiptap 3 + Sortable.
- Microservice Python TeX compile su `/opt/tex-compile/`.
- Envelope encryption: KMS_MASTER -> HKDF -> TKEK -> AES-256-GCM wrap KEK
  per docente -> KEK encrypt body / blob.
- Crypto-shredding O(1) GDPR Art. 17 (DELETE teacher_keys row -> tutti
  i ciphertext di quel docente diventano inaccessibili).
- WAF a livello applicativo: GeoIP filtering (db-ip), threat-intel sync
  (spamhaus, tor, x4b, asn, opzionale crowdsec), scoring, IP/CIDR rules,
  fingerprinting, audit log append-only.
- Authority export GDPR Art. 6(1)(c) firmato HMAC SHA-256.
- Audit log append-only con conservazione 7-10 anni configurabile.
- Multi-tenant istituto: ogni scuola override materie, classi, indirizzi.

---

[Unreleased]: https://github.com/vittop89/pantedu/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/vittop89/pantedu/releases/tag/v0.4.0
[0.3.0]: https://github.com/vittop89/pantedu
[0.2.0]: https://github.com/vittop89/pantedu
[0.1.0]: https://github.com/vittop89/pantedu
