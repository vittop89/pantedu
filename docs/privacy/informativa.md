---
tags:
    - documentazione/gdpr
    - phase/25.C10
date: 2026-04-27
tipo: informativa-utente
status: vigente
versione: 2.0
classification: PUBLIC — esibibile a utenti finali
aliases: ["informativa", "privacy-policy"]
---

# Informativa Privacy — Pantedu

**Versione:** 2.0
**Ultima revisione:** 2026-04-27
**Decorrenza:** 2026-04-27

> ⚠️ Per gli utenti già registrati: a fronte di questa nuova versione, al
> prossimo accesso ti verrà chiesto di confermare i consensi precedentemente
> espressi (Art. 7 §1 GDPR).

## 1. Titolare del trattamento

| Campo | Valore |
|-------|--------|
| Nome | {{OPERATORE_NOME}} |
| Email contatto | `{{OPERATORE_EMAIL}}` |
| Email DPO | `{{OPERATORE_EMAIL}}` (auto-nomina; per dimensioni attuali NON obbligatoria ex Art. 37, ma assunta come buona pratica) |
| Indirizzo | Italia (via comunicata su richiesta motivata) |

## 2. A chi è rivolta questa informativa

- **Docenti** che si registrano come professionisti.
- **Studenti** che si registrano come utenti della piattaforma (anche minorenni — vedi sezione **Minori**).
- **Genitori** di studenti minorenni (per il consenso parentale Art. 8).
- **Super-Admin tecnici** (solo personale autorizzato per manutenzione/audit).

## 3. Quali dati raccogliamo

### 3.1 Dati di identificazione (sempre)

- Username, nome, cognome, email
- Password (memorizzata cifrata con bcrypt cost 12 — mai in chiaro)
- Ruolo (docente / studente / admin)
- Istituto / classe / indirizzo di studio (per docenti e studenti)

**Base giuridica**: Art. 6(1)(b) GDPR — esecuzione contratto registrazione utenza.

### 3.2 Varianti adattate degli esercizi (DSA/DIS — NO Art. 9)

Il docente può segnare che un esercizio ha una **variante adattata** per copie DSA/DIS (es. con formula esplicita, font dyslexia-friendly, semplificazioni linguistiche). Può anche specificare un numero di copie da stampare in ciascuna variante (es. "3 copie standard, 1 copia DSA").

Questo è un **metadata di contenuto** del docente — NON un identificativo dello studente. L'app NON registra "lo studente Mario è DSA" e NON riceve dati sanitari personali (PEI/PDP, certificazioni mediche). Questi dati restano nella scuola tramite registro elettronico esterno + cartaceo.

**Base giuridica**: Art. 6(1)(b) — esecuzione contratto (gestione contenuti didattici).

**Misure tecniche**: i contenuti del docente (incl. flag DSA come metadata) sono cifrati at-rest con AES-256-GCM (envelope encryption per-docente, vedi sezione **Sicurezza**).

### 3.3 Contenuti didattici autoredatti dai docenti

- Esercizi (body HTML + body Portable Text)
- Verifiche
- Mappe concettuali (link Google Drawio + metadati)
- Documenti riservati docente (risdoc)
- Strumenti compensativi BES/DSA

**Base giuridica**: Art. 6(1)(b) — esecuzione contratto + diritto d'autore docente.

**Misure tecniche**: cifratura at-rest envelope (Phase 25.D — AES-256-GCM con per-teacher KEK derivata da HKDF).

### 3.4 Dati di accesso (IP, User-Agent)

Per ogni accesso (login, navigazione autenticata, API calls):
- Indirizzo IP — memorizzato come **hash SHA-256 dei primi 2 octet** (geolocalizzazione coarse, non identificazione individuale)
- User-Agent — memorizzato come **hash SHA-256** (audit traceabile, no fingerprinting)
- Timestamp, action, resourceType

**Base giuridica**: Art. 6(1)(f) — interesse legittimo sicurezza (rilevamento brute-force, account takeover, abusi privilegi).

**Conservazione**:
- Access log applicativo: **365 giorni**
- Privileged access log (admin actions): **1825 giorni / 5 anni** (termine prescrizione abusi amministrativi)

### 3.5 Cookie

Vedi sezione dedicata "Cookie" più sotto. Cookie banner (granulare 3-switch: necessari / analytics / marketing) gestito client-side + sync backend (`/me/consents`).

## 4. Finalità del trattamento

1. **Didattica** (Art. 6(1)(b) — esecuzione contratto): erogazione piattaforma per gestione esercizi/verifiche/mappe, comprese le varianti adattate degli esercizi (DSA/DIS metadata).
2. **Sicurezza** (Art. 6(1)(f) — interesse legittimo): audit log, prevenzione abusi, rate-limiting.
3. **Conformità normativa** (Art. 6(1)(c) — obbligo legale): retention policy, accountability Art. 5 §2.

Trattamenti **esplicitamente esclusi**:
- ❌ Profilazione comportamentale automatica
- ❌ Pubblicità mirata
- ❌ Vendita / cessione dati a terzi (no monetizzazione)
- ❌ Decisioni automatizzate Art. 22 GDPR
- ❌ Geolocalizzazione precisa

## 5. Tempi di conservazione

Definiti in `app/Config/retention.php`. Job automatico `tools/gdpr/anonymize_expired.php` esegue dry-run + commit:

| Dato | Conservazione | Azione a scadenza |
|------|---------------|-------------------|
| Account attivi (docente) | Vita ciclo account | Soft-delete + crypto-shredding al Art. 17 self-service |
| Account inattivi > 730 giorni | 730 giorni | Anonimizzazione (email/nome svuotati, body crypto-shredded) |
| Registrazioni pending mai approvate | 30 giorni | Cancellazione completa |
| Access log applicativo | 365 giorni | Cancellazione |
| Privileged access log (admin actions) | 1825 giorni (5 anni) | Cancellazione |
| Backup DB | 90 giorni | Sovrascrittura ciclica |
| Backup file | 30 giorni | Sovrascrittura ciclica |
| classe_keys (pubblicazione studenti) | 1 anno scolastico | Archive `archived_at` (decrypt audit-only) |
| consent_audit | Permanente (immutable log) | Mai cancellato |

## 6. Minori (Art. 8 GDPR + D.Lgs. 101/2018)

In Italia (D.Lgs. 101/2018), il consenso al trattamento è valido autonomamente da 14 anni in su. Per minori sotto 14 anni:

- La registrazione richiede `parent_email` obbligatoria
- Email automatica al genitore con link di conferma (token TTL 30 giorni)
- L'account NON è attivo finché il genitore non conferma il consenso
- Il genitore può revocare il consenso in qualsiasi momento → cancellazione cascade dell'account studente

## 7. Diritti dell'interessato (Art. 15-22 GDPR)

Per ogni utente registrato sono disponibili endpoint **self-service** (vedi `/me/*`):

| Diritto | Articolo | Endpoint self-service |
|---------|----------|----------------------|
| Accesso ai propri dati | Art. 15 | `GET /me/export-data` (download JSON) |
| Rettifica dati | Art. 16 | `POST /me/profile` (aggiorna nome/cognome/email) |
| **Diritto all'oblio** | Art. 17 | `POST /me/request-deletion` → email conferma → 30g cooling-off → crypto-shredding O(1) |
| Limitazione | Art. 18 | Richiesta a `{{OPERATORE_EMAIL}}` |
| Portabilità | Art. 20 | `GET /me/export-data` (JSON strutturato user + consents + contenuti decifrati + override) |
| Opposizione | Art. 21 | Revoca consensi via `POST /me/consents/revoke` |

Per i diritti che non hanno endpoint self-service, inviare richiesta a `{{OPERATORE_EMAIL}}` dall'indirizzo registrato. Risposta entro 30 giorni (prorogabile a 60 con comunicazione motivata in caso di complessità).

In caso di insoddisfazione: reclamo al Garante per la protezione dei dati personali ([garanteprivacy.it](https://www.garanteprivacy.it)).

## 8. Sicurezza tecnica e organizzativa

Misure implementate (Art. 32 GDPR — vedi anche `wiki/decisions/ADR-006-envelope-encryption.md` e `docs/privacy/dpia.md`):

- **Cifratura at-rest**: envelope encryption AES-256-GCM con per-teacher KEK derivata via HKDF-SHA256 da `KMS_MASTER_KEY` off-line backed up.
- **Cifratura in transito**: HTTPS obbligatorio (HSTS 1 anno).
- **Hashing password**: bcrypt cost 12.
- **CSRF**: token rotation su ogni mutazione.
- **Rate-limiting**: 10/min/IP login (anti-brute-force), 60/min teacher content.
- **Audit log immutabile**: privileged_access_log (REVOKE UPDATE/DELETE su DB user applicativo).
- **Pseudonimizzazione**: hash SHA-256 IP/UA in audit log (no PII raw).
- **Crypto-shredding O(1)**: cancellazione 1 row teacher_keys → tutti i dati cifrati immediatamente illeggibili.
- **CSP rigorosa**: prevenzione XSS + frame injection.
- **Permission system per-teacher**: isolation testata con E2E concurrent (Phase 25.B7).

## 9. Sub-processor e trasferimenti

### 9.1 Sub-processor reali (Art. 28 GDPR)

Soggetti che processano dati personali **per conto di pantedu**, con DPA standard:

| Sub-processor | Servizio | Localizzazione | Trasferimento extra-UE |
|---------------|----------|----------------|------------------------|
| Hetzner Online GmbH | VPS hosting (web server + database + storage), datacenter Nuremberg | Germania (UE) | NO |
| Backblaze Inc. | Object storage per backup cifrati (region eu-central-003 Amsterdam) | USA — storage UE | Sì, con SCC 2021/914 + DPF + region UE |

DPA firmati e archiviati. Lista live aggiornata su `/admin/subprocessors` (super-admin) o snapshot CSV nel pacchetto compliance per DPO.

### 9.2 Servizi terzi user-initiated (NON sub-processor)

Integrazioni che il **docente attiva opzionalmente** e che instaurano una relazione diretta tra docente e fornitore terzo (pantedu è solo middleware OAuth, non destinatario dei dati):

| Servizio | Ruolo pantedu | Cosa attiva il docente | Privacy applicabile |
|----------|----------------|------------------------|---------------------|
| Google LLC — OAuth + Drive integration | Solo middleware OAuth: il docente collega il PROPRIO account Google, i materiali sincronizzati finiscono nel SUO Drive personale | clicca "Connetti Drive" + autorizza scope `drive.file` | ToS + Privacy Policy Google direttamente applicabili al rapporto docente-Google |

**Conseguenze giuridiche**:
- Google **non** è sub-processor di pantedu in senso Art. 28 (pantedu non sceglie come/dove Google processa, non ha account Google centralizzato).
- Nessun DPA pantedu-Google richiesto.
- Trasferimento extra-UE: gestito direttamente dal rapporto docente-Google (Google offre SCC + DPF per i propri utenti UE).
- Il docente può **disconnettere in qualsiasi momento** via `POST /teacher/drive/disconnect` — questo revoca solo l'autorizzazione OAuth lato pantedu; i dati già nel Drive del docente restano nel suo account.

## 10. Cookie

Cookie banner granulare (vedi popup al primo accesso) con 3 categorie:

- **Necessari** (sempre attivi, no consenso richiesto): session cookie, CSRF token, login state. Base: Art. 6(1)(b).
- **Funzionali** (opt-in): preferenze UI (modalità scura, sidebar state). Base: Art. 6(1)(a).
- **Analytics** (opt-in): metriche aggregate audience, no profiling individuale. Base: Art. 6(1)(a) — opt-in esplicito.
- **Marketing** (opt-in): NON ATTIVO oggi. Riservato a usi futuri post-consenso.

Tutti i cookie opt-in sono **revocabili in qualsiasi momento** via il banner persistente in basso a destra o via `POST /me/consents/revoke`.

## 11. Data breach

Vedi `docs/privacy/data_breach_runbook.md`. In caso di breach:
- Notifica al Garante entro 72h (Art. 33)
- Notifica utenti se rischio elevato (Art. 34)
- Crypto-shredding emergenziale + KMS rotation (vedi `docs/security/operations/kms-recovery.md`)

Il registro incident è gestito internamente via `/admin/data-breach` (super-admin only).

## 11-bis. Cooperazione con autorità per recupero dati

Vedi `docs/security/operations/authority-cooperation.md`. Casi coperti:

- **Richieste autorità giudiziaria / Garante / forze di polizia**: il data
  controller verifica entro 72h legittimità (base giuridica + decreto motivato),
  registra l'evento nel log `crypto_custody_events`, ed estrae i dati
  esclusivamente nel perimetro autorizzato. Logging completo della chain of
  custody (`/admin/crypto-status`).
- **Eredi** del docente (Art. 460 c.c. + considerando 27 GDPR): documentazione
  successoria + estrazione mirata.
- **Docente che ha perso accesso** (Art. 15 GDPR): self-service via
  `/dpo-contact` + verifica identità → re-issue Recovery Key o estrazione
  amministrativa.

Tutte le operazioni di accesso amministrativo alle KEK docenti producono
righe immutabili in `crypto_custody_events` (kind: `kek_emergency_access`,
`data_recovered`, `data_provided`).

## 11-ter. Sviluppo del software e trasparenza

L'Applicativo è **software libero/open source** rilasciato sotto licenza
**EUPL-1.2**: il codice sorgente — incluse le logiche automatiche (es. il filtro
di sicurezza WAF) — è **interamente ispezionabile** pubblicamente.

Il codice sorgente è stato **scritto dai modelli di intelligenza artificiale
Claude Opus 4.7 e 4.8 (Anthropic), sotto la guida e la direzione di {{OPERATORE_NOME}}**, che ne ha curato ideazione, requisiti, revisione e responsabilità
(co-autorialità uomo–AI). L'informazione è fornita a fini di **trasparenza**; la
titolarità dei diritti d'autore del software è della persona fisica che lo ha
diretto (un sistema di IA non può detenerli).

L'Applicativo **non effettua processi decisionali automatizzati che producano
effetti giuridici o significativi sull'interessato** ai sensi dell'**Art. 22
GDPR** (in particolare non assegna valutazioni né profila gli studenti): le
funzioni automatiche presenti riguardano la sola sicurezza informatica.

## 12. Modifiche all'informativa

Questa informativa ha versione **2.0**. Ad ogni revisione sostanziale:
1. Bump versione (es. 2.1 → 3.0)
2. Notifica utenti registrati via banner al login
3. **Re-consent prompt** per i consensi attivi (Art. 7 §1 — informazione comprensibile)
4. Versione precedente conservata in audit (`consent_audit.text_version`)

## 13. Domande, contatti, reclami

| Tipo richiesta | Contatto |
|----------------|----------|
| Generiche / supporto | `{{OPERATORE_EMAIL}}` |
| DPO / privacy / esercizio diritti | `{{OPERATORE_EMAIL}}` (oggetto: "GDPR — [tuo username]") |
| Reclamo al Garante | [garanteprivacy.it/home/footer/contatti](https://www.garanteprivacy.it/home/footer/contatti) |

## Riferimenti tecnici (per audit professionali)

- [docs/privacy/dpia.md](dpia.md) — Valutazione d'Impatto Privacy
- [docs/privacy/registro-trattamenti.md](registro-trattamenti.md) — Registro Art. 30
- [docs/privacy/data_breach_runbook.md](data_breach_runbook.md) — Procedura data breach
- [docs/security/operations/kms-recovery.md](../security/operations/kms-recovery.md) — KMS recovery
- [docs/security/operations/authority-cooperation.md](../security/operations/authority-cooperation.md) — Cooperazione autorità + custodia chiavi
- [wiki/decisions/ADR-006-envelope-encryption.md](../../wiki/decisions/ADR-006-envelope-encryption.md) — Design crypto
- [wiki/decisions/ADR-007-gdpr-compliance.md](../../wiki/decisions/ADR-007-gdpr-compliance.md) — Design GDPR
