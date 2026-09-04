# Data Breach Runbook — Pantedu

Procedura operativa per gestione incidenti sicurezza (art. 33-34 GDPR).

## Trigger
Qualsiasi evento che può aver compromesso:
- Autenticità, integrità o disponibilità di dati personali.
- Confidenzialità di credenziali utente o del signing secret storage.
- Log di audit (tentativi di alterazione `privileged_access_log`).

## Fase 1 — Rilevamento (T+0)
1. Isolare il vettore: sospendere account compromessi (`UPDATE users SET active=0 WHERE id=?`).
2. Raccogliere evidenze: `privileged_access_log`, access log, error log.
3. **Non cancellare nulla**: snapshot immediato del DB e del filesystem.

## Fase 2 — Classificazione (T+1h)
| Livello | Dati | Notifica |
|---|---|---|
| Basso | log tecnici aggregati | solo log interno |
| Medio | email/username | Garante entro 72h + comunicazione interna |
| Alto | password hash o dati studenti | Garante 72h + interessati senza ritardo + rotazione credenziali |

## Fase 3 — Contenimento (T+4h)
- Rotazione `STORAGE_SIGNING_SECRET`, `APP_KEY`, credenziali DB.
- Revoca sessioni attive (`Session::destroy()` globale o flush store).
- Reset password forzato per account coinvolti.
- Blocco IP sospetti via `BlockList`.

## Fase 4 — Notifica (T+72h max)
- Garante Privacy: modulo online su `garanteprivacy.it`.
- Interessati se rischio elevato: email + avviso in dashboard.
- Documento sintetico: cosa è successo, dati coinvolti, misure adottate, contatti.

## Fase 5 — Post mortem (T+7gg)
- Root cause analysis scritta in `docs/privacy/breach_reports/YYYY-MM-DD.md`.
- Correzioni tecniche (patch, aggiornamenti dipendenze).
- Revisione DPIA.
- Formazione/comunicazione al team.

## Comando rapido in caso di sospetto breach
```bash
# Snapshot DB
mysqldump --single-transaction $DB_NAME > storage/backups/db/incident_$(date +%F_%H%M).sql

# Snapshot storage oggetti
tar czf storage/backups/files/incident_$(date +%F_%H%M).tgz storage/objects

# Estrai log ultimi 24h
mysql -e "SELECT * FROM privileged_access_log WHERE created_at > NOW() - INTERVAL 1 DAY" > incident_audit.tsv
```
