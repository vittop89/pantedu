---
tags:
    - decisione
    - security
    - audit
date: 2026-04-27
status: accettato
phase: 25.B4
aliases: ["adr-008", "audit-reason"]
---

# ADR-008 — Audit reason obbligatoria su mutazioni admin cross-teacher

## Contesto

Phase 25.B (isolation hardening) ha introdotto `RequiresAuditReasonMiddleware`
sul gruppo route admin POST/DELETE per fornire una **traccia di
giustificazione** sulle mutazioni cross-teacher (es. modifica profilo altro
docente, override di un suo template, sblocco credential, ecc.).

Senza traccia di giustificazione:
- Audit log mostra "chi+cosa+quando" ma non "perché"
- Indagine post-incidente difficoltosa: nessun contesto sul motivo
  dell'intervento (manutenzione legittima vs abuso?)
- Compliance: GDPR Art. 5 §2 (accountability) richiede capacity to
  demonstrate compliance — la sola presenza dell'azione non basta

## Decisione

Tutte le rotte amministrative che modificano richiedono una motivazione:
l'intestazione `X-Audit-Reason` o il campo `_audit_reason` del corpo, da 10 a
255 caratteri (contati in caratteri dal 2026-09-23; il testo originale di
questo ADR diceva 8, il codice ha sempre chiesto 10).

La motivazione la legge `RequiresAuditReasonMiddleware`, che:
1. la valida (lunghezza minima e massima, non vuota);
2. la scrive in `privileged_access_log.reason`, con l'azione e il tipo di
   risorsa dichiarati sulla rotta (`audit_reason:<azione>,<risorsa>`) e il
   percorso come `resource_id`; una mutazione rifiutata lascia la riga
   `MISSING_OR_INVALID_AUDIT_REASON` con esito `denied`.

Il testo originale prometteva anche la motivazione «disponibile via
`Auth::context('audit_reason')`» per i log applicativi: quel metodo non è mai
esistito (verificato il 2026-09-23).

Il middleware agisce solo per il **super-admin**: per chiunque altro lascia
passare, perché il cancello del ruolo è di `RoleMiddleware` e
`SuperAdminRequiredMiddleware`.

### Dove si applica (2026-09-23)

Una **mutazione amministrativa** è una rotta POST, PUT, PATCH o DELETE che
sta sotto `/admin` o `/api/admin/`, oppure porta `super_admin_required`,
oppure ha soltanto la zona `admin` nei suoi middleware di ruolo (così
`/files/*`, `/tikz/*` e `/api/institutes`, che sono dell'amministratore anche
se il percorso non lo dice). Ognuna porta `audit_reason:<azione>,<risorsa>`,
salvo un elenco di esenzioni scritte: le rotte che non scrivono niente
(l'hash di una password, la verifica della password dell'amministratore, i
percorsi vecchi che rispondono 410) e quelle che toccano solo i temporanei (le
stampe in `storage/temp`, il `.tex` di lavoro, la pulizia dei temporanei). La
regola e le esenzioni stanno in `tests/Unit/Core/CoperturaMotivazioneTest.php`,
che legge la tabella delle rotte e fallisce se una mutazione nuova non ha la
motivazione, o se un'esenzione non serve più.

Fino al 2026-09-23 la regola era scritta qui ma non era vera: ricontate con il
Router, 50 mutazioni su 81 sotto `/admin` e `/api/admin` non la chiedevano
(rilievo A-69 della revisione di quel giorno), fra cui il cambio di ruolo, la
cancellazione di un utente, `/api/admin/security/*` e `/admin/migrate/run`.

**I chiamanti.** In `enforce` una rotta con `audit_reason` e un pulsante che
non manda la motivazione rispondono 400: il lavoro vero è nel client. Le fetch
la mandano con `auditReason(contesto, dettaglio)` di
`js/modules/core/audit-reason.js`, dove il contesto dice che cosa si fa e su
che cosa, e il dettaglio è la nota che il pannello ha già raccolto quando c'è
(il motivo per aprire l'elenco degli utenti, il motivo di un rifiuto). I
moduli HTML hanno un campo `_audit_reason`, nascosto con un testo che dice
l'azione, o visibile e obbligatorio dove la decisione lo merita (lo scenario,
i gate ToS e 2FA, il catalogo, le migrazioni). Nel motivo non vanno indirizzi
IP né nomi utente: nei registri di audit l'IP si tiene solo come hash. Che
ogni chiamante la mandi lo guarda
`tests/Unit/Core/ChiamantiMandanoLaMotivazioneTest.php`.

**L'amministratore di istituto** (ADR-040) oggi non ha mutazioni nella sua
zona. Quando ne avrà, sono mutazioni sui colleghi del suo istituto e ADR-008
dovrebbe valere anche per lui; ma il middleware lo salterebbe. La prova
`CoperturaMotivazioneTest::la_zona_istituto_non_ha_mutazioni_senza_una_decisione`
fallisce alla prima mutazione della zona, perché la scelta (estendere il
middleware, o scrivere perché no) si faccia allora.

### 3 modalità (env `AUDIT_REASON_MODE`)

| Modo | Comportamento | Uso |
|------|---------------|-----|
| `disabled` | Skip middleware (no validation, no log) | feature flag off (rollback) |
| `warn` | Log assenza/invalidità ma procedi | rollout iniziale |
| `enforce` | 400 se mancante o invalida | produzione finale; predefinito dal 2026-09-23 |

**Predefinito `enforce` (2026-09-23).** Il rollout è finito il 2026-09-02,
quando la produzione è passata a `enforce`, ma nel codice il predefinito era
rimasto `warn`: senza la chiave, o con un valore sconosciuto, le mutazioni
passavano senza motivazione (rilievo A-37 della revisione del 23/9). Ora
`app/Config/audit.php` e il ripiego del middleware dicono `enforce`; `warn` e
`disabled` vanno scritti per nome, e con `APP_ENV=production` il container non
parte con un modo diverso da `enforce` (guardie all'avvio:
[[environment-variables]]). Quindi `warn` in `.env.local` non è più una via
d'uscita in produzione: un percorso rimasto scoperto si corregge nel client.
Prove: `tests/Unit/Middleware/MotivazioneObbligatoriaPredefinitaTest.php`,
`tests/ops/verifica-avvio.test.sh`.

**Le lettere accentate (2026-09-23).** Il browser scrive un'intestazione in
ISO-8859-1: la «à» arriva come un byte che non è UTF-8, e il registro perdeva
la riga senza errori. Il middleware la legge come ISO-8859-1 quando non è
UTF-8 valido (`tests/Unit/Middleware/MotivazioneConLeAccentateTest.php`).

### Strategia rollout

1. **Phase 25.B4** (✅ done): middleware applicato in modo `warn`. Admin
   esistenti continuano a operare; assenza header genera log
   `[audit_reason] missing` ma 200 OK.
2. **Comunicazione**: super_admin notificato via email con esempio cURL +
   esempi UI (browser dev tools fetch override).
3. **Phase 25 final** (✅ 2026-09-02): `enforce` in produzione. Il testo
   originale diceva che il pannello mandava l'intestazione già dalla Phase
   25.B4: non era vero. Il 2026-09-02 178 righe su 216 del registro avevano
   `MISSING_OR_INVALID_AUDIT_REASON`, e il 2026-09-23 le 50 mutazioni senza
   `audit_reason` avevano chiamanti che non la mandavano; uno, «Sposta» del
   pannello Sezioni, la rotta la chiedeva già e rispondeva 400.
4. **Telemetria** (mai fatta): il testo originale prevedeva un contatore
   Prometheus `pantedu_audit_reason_total{mode,outcome}` e il passaggio a
   `enforce` sotto l'1% di mancanti per sette giorni. Il contatore non esiste
   (verificato il 2026-09-23); il passaggio si è fatto guardando
   `privileged_access_log`.

## Conseguenze

### Pro
- Audit log self-documenting: ogni mutazione cross-teacher ha contesto.
- GDPR Art. 5 §2 rafforzato: accountability dimostrabile.
- DPO/legal possono ricostruire intent post-incidente senza intervistare
  l'admin (che potrebbe non ricordare).
- Disciplina admin: il prompt "scrivi la motivazione" induce riflessione
  prima dell'azione (psychological speed bump).

### Contro
- Friction UX: 1 step extra ogni admin mutating action. Mitigato da:
  - un contesto scritto dal pannello (che cosa si fa, su quale oggetto) più la
    nota che il pannello chiede già, dove la chiede; un campo da riempire solo
    per le decisioni che lo meritano. Il testo originale prevedeva una
    finestra con «manutenzione ordinaria» precompilata: non è mai stata fatta;
  - Free-text: nessuna lista chiusa di motivi (libertà admin).
- Rischio "garbage reason" (es. "x"): mitigato dal minimo di 10 caratteri +
  post-hoc audit sample review (non automatizzabile, accettato). Un contesto
  scritto dal pannello dice il «cosa» meglio del «perché»: è il prezzo di non
  chiedere un campo a ogni clic.

### Trade-off non scelti

- **Ticket-id obbligatorio**: rifiutato. Vincolerebbe a un sistema esterno
  (Linear/Jira) che oggi non esiste. Free-text è MVP.
- **Closed enum**: rifiutato. La diversità delle operazioni admin rende
  impossibile prevedere tutti i motivi a priori; closed enum genererebbe
  "altro" come catch-all svuotando il valore audit.

## Verifica

- Rotte: `tests/Unit/Core/CoperturaMotivazioneTest.php` (ogni mutazione
  amministrativa porta `audit_reason`, esenzioni scritte che possono solo
  diminuire).
- Chiamanti: `tests/Unit/Core/ChiamantiMandanoLaMotivazioneTest.php` (fetch,
  aiutanti e moduli verso quelle rotte mandano la motivazione; ogni rotta ha
  un chiamante o un perché), e `tests/js-unit/motivazione-nelle-mutazioni-admin.test.js`.
- Middleware: `tests/Unit/Middleware/MotivazioneObbligatoriaPredefinitaTest.php`,
  `tests/Unit/Middleware/MotivazioneConLeAccentateTest.php`.
- E2E: `tests/e2e/admin/visibilita-e-motivazione.spec.js` (ex
  `b4_audit_reason.spec.js`) e `tests/e2e/admin/catalogo-dell-istituto.spec.js`:
  senza motivazione 400, con la motivazione nell'intestazione o nel corpo
  passa. Dal 2026-09-23 la suite non manda più la motivazione su ogni
  richiesta del browser: i pulsanti devono mandarla da sé.

## Riferimenti

- Implementazione: `app/Middleware/RequiresAuditReasonMiddleware.php`
- Kernel registration: `app/Core/Kernel.php` middleware map
- Audit log: `privileged_access_log.reason` (VARCHAR(255))
- Client: `js/modules/core/audit-reason.js`
- Compliance: GDPR Art. 5 §2, Art. 32 (sicurezza dei dati)
