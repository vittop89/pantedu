# Changelog

Tutte le modifiche notevoli a questo progetto saranno documentate in
questo file.

Formato basato su [Keep a Changelog](https://keepachangelog.com/it/1.1.0/),
versioning [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Per la cronologia dettagliata mese-per-mese con riferimenti a commit,
ADR e ticket, vedi [`wiki/changelog/`](wiki/changelog/).

## [Unreleased]

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
  Domini `fismapant.com` (Aruba) e repo GitHub `vittop89/fismapant`
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

[Unreleased]: https://github.com/vittop89/pantedu/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/vittop89/pantedu/releases/tag/v0.1.0
