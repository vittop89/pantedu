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

Tutte le route admin **mutating** richiedono header
`X-Audit-Reason: <free-text 8-255 char>`.

L'header viene catturato da `RequiresAuditReasonMiddleware` e:
1. Validato (lunghezza min/max + non-empty)
2. Loggato come `audit_reason` nel `privileged_access_log`
3. Disponibile via `Auth::context('audit_reason')` durante il request
   handling (per arricchire log applicativi downstream)

### 3 modalità (env `AUDIT_REASON_MODE`)

| Modo | Comportamento | Uso |
|------|---------------|-----|
| `disabled` | Skip middleware (no validation, no log) | feature flag off (rollback) |
| `warn` | Log assenza/invalidità ma procedi | rollout iniziale (default) |
| `enforce` | 403 se mancante o invalida | produzione finale |

### Strategia rollout

1. **Phase 25.B4** (✅ done): middleware applicato in modo `warn`. Admin
   esistenti continuano a operare; assenza header genera log
   `[audit_reason] missing` ma 200 OK.
2. **Comunicazione**: super_admin notificato via email con esempio cURL +
   esempi UI (browser dev tools fetch override).
3. **Phase 25 final** (pending): grace period min 30g warn → switch a
   `enforce`. Frontend admin UI già passa l'header da Phase 25.B4.
4. **Telemetria**: contatore Prometheus `pantedu_audit_reason_total{mode,outcome}`
   espone `present|missing|invalid` × `200|403`. Switch a `enforce` solo
   quando `missing+invalid < 1%` per 7g consecutivi.

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
  - Frontend: textarea modal con default "manutenzione ordinaria"
    cancellabile (force re-think evitando auto-fill triviale).
  - Free-text: nessuna lista chiusa di motivi (libertà admin).
- Rischio "garbage reason" (es. "x"): mitigato da min 8 char + post-hoc
  audit sample review (non automatizzabile, accettato).

### Trade-off non scelti

- **Ticket-id obbligatorio**: rifiutato. Vincolerebbe a un sistema esterno
  (Linear/Jira) che oggi non esiste. Free-text è MVP.
- **Closed enum**: rifiutato. La diversità delle operazioni admin rende
  impossibile prevedere tutti i motivi a priori; closed enum genererebbe
  "altro" come catch-all svuotando il valore audit.

## Verifica

- E2E: `tests/e2e/b4_audit_reason.spec.js` 4/4 pass (warn no-block, enforce
  block, valid pass-through, log emission).
- Metric: `pantedu_audit_reason_total` esposta da `/metrics` (Phase 25.E4.2).

## Riferimenti

- Implementazione: `app/Middleware/RequiresAuditReasonMiddleware.php`
- Kernel registration: `app/Core/Kernel.php` middleware map
- Audit log: `privileged_access_log.audit_reason` (TEXT)
- Test: `tests/e2e/b4_audit_reason.spec.js`
- Compliance: GDPR Art. 5 §2, Art. 32 (sicurezza dei dati)
