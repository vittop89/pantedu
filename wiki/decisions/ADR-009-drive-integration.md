---
tags:
    - decisione
    - architettura
    - integrazione
date: 2026-04-29
status: accettato
phase: G1
aliases: ["adr-009", "drive-integration", "google-drive-oauth"]
---

# ADR-009 — Google Drive integration server→Drive (deprecazione scriptGoogle_sync)

## Contesto

`scriptGoogle_sync/` (Google Apps Script + webhook PHP su Aruba) implementa
oggi il flusso **Drive → server**: il docente carica `.drawio`/PDF su una
cartella Drive predefinita, GAS fa polling ogni 5 minuti, scarica via FTP
sull'hosting + genera `MAT_mappe-links_*.json` consumati dal sito.

Limiti del flusso attuale:

1. **Direzione unica Drive→server**: il sito non può creare mappe nuove o
   modificare quelle esistenti (solo viewer.diagrams.net read-only). Il
   docente deve aprire app.diagrams.net su Drive per modificare.
2. **Mappe "vivono" su Drive personale**: in caso di account chiuso o file
   eliminato dal docente per errore il sito perde il riferimento.
   `MAT_mappe-links_*.json` referenzia `drawio_id` che diventa 404.
3. **Diritto d'autore docente non protetto**: i `.drawio` sono accessibili
   in chiaro a chiunque abbia il link Drive (`viewer.diagrams.net?url=...&export=download`).
4. **No condivisione granulare**: tutto pubblico via link Drive o niente.
   Manca il modello "condividi questa mappa con docenti X dello stesso
   istituto, ma non con studenti di altre classi".
5. **Cron esterno fragile**: GAS ha 6 min/exec limit, FTP credentials in
   chiaro nel webhook (mitigato con SECRET_TOKEN ma comunque single point
   of failure).

Phase 18 ha già migrato i `.json` in `teacher_content` (`content_type='mappa'`,
metadata `{href, href_hide, drawio_id, display}`), ma il file `.drawio`
reale resta solo su Drive.

## Decisione

Implementare flusso **server→Drive** invertito, con questi pilastri:

1. **Storage locale cifrato come single source of truth**: `.drawio`
   serializzato XML salvato in `storage/maps_enc/{teacher_id}/{ulid}.bin`
   cifrato envelope con TKEK del docente (riuso ADR-006). DB row in
   `teacher_content` con colonne `map_blob_path`, `map_drive_id`,
   `map_origin`, `map_is_public`.
2. **Drive come copia secondaria**: ogni mappa esistente su DB è
   replicabile sul Drive personale del docente, organizzata in albero
   `Pantedu/{istituto}/{indirizzo}/{classe}/{materia}/{tipo}/`. La
   replica è push da server (manuale via pulsante o cron notturno),
   never pull. Modifiche bypass-app fatte direttamente su Drive vengono
   sovrascritte dal sync (trade-off accettabile per evitare conflitti
   merge XML).
3. **OAuth per-teacher con scope minimale**: `drive.file` (read+write SOLO
   file creati dall'app). Privacy-friendly: l'app NON vede il resto del
   Drive del docente. Lo scope `drive.readonly` è richiesto UNA TANTUM in
   fase G6 per migrare i `.drawio` legacy già esistenti su Drive del
   docente; post-migrazione si declassa via re-consent.
4. **Modifica via embed.diagrams.net**: il browser carica il blob locale
   (signed URL TTL 600s) dentro l'iframe `embed.diagrams.net?embed=1&proto=json`,
   il save event postMessage ritorna XML modificato → server re-cifra +
   sovrascrive blob + aggiorna versione. `lightbox=1&dark=0` forzato per
   tema chiaro come da requisito.
5. **Sharing granulare**: tabella `map_shares` con scope_type ∈
   {institute, class, student, teacher} + permission ∈ {view, copy}.
   `view` = signed URL solo lettura, `copy` = la modifica genera nuova
   row con `parent_map_id` (originale intoccato).

## Architettura OAuth (G1.a)

```
                    ┌─────────────────────────┐
   1. consent       │ Google Cloud Console     │
      ┌────────────►│ OAuth 2.0 client (Web)   │
      │             │ scope=drive.file         │
   ┌──┴────┐        │ redirect=APP_URL/.../    │
   │ Browser│       │   teacher/drive/callback │
   └──┬─────┘        └─────────────────────────┘
      │
      │ /teacher/drive/connect
      ▼
   ┌──────────────────────────┐
   │ DriveController.connect   │  state nonce → Session
   │ DriveClient.buildAuthUrl  │
   └──────────┬───────────────┘
              │ 302 redirect a accounts.google.com
              ▼
       (utente approva consent)
              │
              ▼
   /teacher/drive/callback?code=...&state=...
   ┌──────────────────────────┐
   │ DriveController.callback  │  verifica state (CSRF)
   │ DriveClient.exchangeCode  │  code → access+refresh
   │ DriveClient.fetchEmail    │  userinfo (best-effort)
   │ DriveOAuthRepository      │  envelope-encrypt refresh
   │   .upsert()               │  + INSERT teacher_drive_oauth
   └──────────┬───────────────┘
              │ 302 /teacher/dashboard?drive=connected
              ▼
        UI status pill aggiornata
```

Sessioni successive (G2+):

```
DriveClient.getDriveFor(teacherId)
  → DriveOAuthRepository.getRefreshToken(teacherId)  // decrypt envelope
  → GoogleClient.refreshToken(refreshToken)          // ottieni access_token
  → new GoogleDriveService(googleClient)             // usabile per API call
```

Il `refresh_token` non viaggia mai in chiaro su disco/log/backup non
cifrati: vive solo (a) in DB cifrato, (b) in memoria durante la chiamata
`refreshToken()` di apiclient.

## Razionale

### Perché account personale docente (non Workspace + DWD)

Domain-Wide Delegation richiederebbe:

- Account Google Workspace istituto disponibile (non sempre vero per
  docenti con account scolastico personale gmail.com).
- Approval del super-admin Workspace per ciascun service account → blocco
  burocratico.

Per la fase iniziale → account personale per-teacher con consent OAuth
standard. In futuro, per istituti con Workspace, si può aggiungere un
flow alternativo "Drive istituzionale" con DWD.

### Perché embed.diagrams.net (no self-host)

- Già usato in produzione come viewer (legacy `viewer.diagrams.net`).
- Self-host (Docker `jgraph/drawio`) richiede infra extra, manutenzione
  CSP/CORS, certificati TLS dedicati. Trade-off non giustificato per
  app didattica con <1000 docenti.
- Privacy: il browser carica XML dal nostro server (no upload a JGraph
  Ltd). JGraph serve solo l'editor JS — i dati restano lato pantedu.

### Perché scope drive.file (non drive)

- `drive.file`: read+write SOLO file creati dall'app (via picker o nostro
  upload). NON vede il resto del Drive del docente. Conforme principio
  GDPR di minimizzazione (Art. 5 §1c).
- `drive` (full): read+write tutto Drive. Rifiutato — overreach + maggior
  rischio in caso di breach.
- `drive.readonly` solo per migrazione G6 (download `.drawio` legacy
  pre-esistenti che NON sono stati creati dall'app). Una tantum, poi
  declassato.

### Perché push-only (no pull continuous)

Pull continuous (Drive change notification API) implica:

- Webhook publicly reachable + signature verification.
- Conflict resolution XML merge (drawio non ha CRDT/3-way merge native).
- Frequenza imprevedibile (utente può modificare offline poi sync).

Push on-demand (button) + cron notturno è più semplice, prevedibile,
e copre il 99% dei casi d'uso reali. Le mappe prodotte fuori dall'app
restano accessibili via Drive ma non sono "ufficiali" finché il docente
non importa esplicitamente.

## Conseguenze

### Positive

- **Diritto d'autore protetto**: blob `.drawio` cifrato envelope, nemmeno
  super_admin DBA legge il contenuto senza audit reason (riuso ADR-006/008).
- **No vendor lock-in Drive**: se Google chiude API o cambia pricing, le
  mappe vivono già in chiaro server-side, Drive è solo backup.
- **Modifica fluida**: docente apre mappa → embed → save → DB aggiornato
  → opzionale push Drive. No piu' "apri Drive in altra tab".
- **Sharing granulare**: `map_shares` permette il caso d'uso richiesto
  ("docente A condivide mappa X con docente B per altra classe-materia").
- **Privacy by design**: scope drive.file minimale; `drive.readonly`
  solo per migrazione una tantum, sempre revocabile via Google account
  settings.

### Negative

- **Cifratura blob aumenta storage**: ~5% overhead (IV+tag) trascurabile.
  Ma le ricerche full-text dentro `.drawio` XML diventano impossibili.
  Nessun caso d'uso attuale richiede ricerca contenuto mappa.
- **Quota Drive API**: 1000 req/100s/user. Per 200 mappe sync di un
  docente: ~2-3 minuti con backoff. Cron timeout 5min/teacher copre.
- **Modifica direct-on-Drive viene sovrascritta**: trade-off documentato.
  Mitigazione: UI pill mostra "ultima sync" e modal warning se differenza
  grossa.
- **Apiclient è una dep grossa**: ~12MB autoload. Mitigato con
  `optimize-autoloader` + opcache. Limitato a `apiclient-services` →
  Drive submodule (no Calendar/Gmail/Sheets shipped inutili).
- **OAuth refresh_token long-lived**: Google li valida finché l'utente
  non revoca o cambia password. Mitigazione crittografica: envelope con
  TKEK; se docente fa shred GDPR, refresh_token diventa unreadable.

### Neutrali

- **Dipendenza esterna embed.diagrams.net**: già esistente pre-G1, no
  nuovo rischio. Documentato in privacy policy update Phase G7.
- **Migration G6 non perde dati**: download legacy `.drawio` da Drive,
  cifra locale; Drive resta come copia. Failure mode (drawio_id
  inaccessibile) → flag `map_origin='drive_orphan'` con link viewer
  legacy degraded ma funzionante.

## Implementazione (rollout per fasi)

| Fase | Contenuto | Stato |
|------|-----------|-------|
| G1.a | OAuth foundation: tabelle, repo, client stub, controller, UI pill | done (PR #24) |
| G1.b | E2E test OAuth flow (Playwright + mock Drive) | next |
| G2   | `map_blob_path` + `MapBlobStore` + `map_shares` + permission helper | done (PR #25) |
| G3.a | Drive sync buttons UI (global + per-item) — no backend | done (PR #26) |
| G3.b | Modal create estensione: upload file + nuova drawio embed + POST /api/maps | done (PR #27) |
| G4   | Signed URL + MapsController::update + drawio editor hook su `.fm-item-edit` | done (PR #28) |
| G5   | FolderTreeBuilder + MapSyncService + endpoints sync + drive-sync-buttons live + cron notturno | done (PR #29) |
| G6   | Migration script `migrate_drive_mappe_to_local.php` + connect-migration scope readonly | done (PR #30) |
| G7   | Wiki domain `wiki/domains/mappe/` riscritto + deprecate scriptGoogle_sync (mv archive) | this commit (PIANO COMPLETATO) |

Rollback per ogni fase: feature non utilizzabile finché OAuth non è
configurato in `.env.local`. Senza credenziali → `DriveClient` lancia
`drive_oauth_credentials_missing`, controller risponde 500 con error code
stabile, UI pill resta in stato "non collegato". Zero impact su flussi
esistenti (mappe legacy continuano via viewer.diagrams.net `href`).

## Riferimenti

- [ADR-006](ADR-006-envelope-encryption.md) — envelope encryption riusata per refresh_token
- [ADR-007](ADR-007-gdpr-compliance.md) — minimizzazione + scope OAuth
- [ADR-008](ADR-008-audit-reason.md) — audit log per cross-teacher access
- Google Drive API v3: https://developers.google.com/drive/api/reference/rest/v3
- `scriptGoogle_sync/README.md` — sistema legacy in deprecazione
- `wiki/changelog/2026-04.md` Phase G1.a entry
