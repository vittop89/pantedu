# Pacchetto DPO scuola — indice

Documentazione consegnata al DPO/RPD dell'Istituto in cui l'autore insegna.
Posizione dal 3 settembre 2026: **nessun dato personale di studenti**; titolare dei dati dei
docenti che si iscrivono è **{{OPERATORE_NOME}}**; un'adozione formale da parte di un Istituto
(con dati di studenti) è possibile solo su infrastruttura qualificata ACN condotta dall'Istituto
o da un fornitore qualificato, mai su infrastruttura dell'autore.

## Contenuto (versionato in questo repo)
| File | Cosa |
|------|------|
| `Nota-di-aggiornamento-DPO.{md,pdf}` | **Secondo invio (3 settembre 2026)** — nota formale e protocollabile: riscontro sull'osservazione ACN, rimozione dei dati degli studenti, questioni aperte (titolarità docenti, gestione documentale). Sostituisce Lettera e Sintesi per quanto in contrasto |
| `Pacchetto-DPO-pantedu.{md,pdf}` | **Allegato A** — accountability completo: misure Art. 32, mappatura AgID, minimizzazione+DPIA, titolarità, sintesi audit, roadmap |
| `Lettera-accompagnamento-DPO.{md,pdf}` | Primo invio (1° settembre 2026), conservata come trasmessa |
| `Bozza-DPA-Art28.{md,pdf}` | **Allegato B — ritirato il 3 settembre 2026**: presupponeva l'autore come Responsabile su infrastruttura propria |
| `Email-al-DPO.md` | Corpo dei messaggi email (primo e secondo invio). Non finisce nel clone pubblico |

## Allegati NON versionati qui (consegna su richiesta / sotto riservatezza)
| File | Dove | Nota |
|------|------|------|
| `Allegato-C-Report-Audit-firmato.pdf` | `C:\security_tools\audits\pantedu-2026-06-14\dpo-allegati\` | Report di pentest completo: contiene dettaglio tecnico/architetturale → consegnare solo su richiesta del DPO, preferibilmente sotto NDA |
| DPIA | `docs/privacy/dpia.{md,pdf}` | Allegato D |
| Informativa privacy | `docs/privacy/informativa.{md,pdf}` (fonte unica, anche su `/privacy/informativa`) | Allegato E |
| Registro art. 30 | `docs/privacy/registro-trattamenti.{md,pdf}` | Allegato F |

## Come usare
1. PDF del pacchetto: `python docs/dpo/pacchetto-scuola/_gen_pdf.py <file.md>` (Edge headless).
2. PDF di DPIA, Informativa e Registro: `tools/legal/build_pdf.sh docs/privacy/dpia.md docs/privacy/informativa.md docs/privacy/registro-trattamenti.md` (pandoc + xelatex).
3. Invia: Nota + A + D + E + F. Su richiesta del DPO: Allegato C.

> Nota di trasparenza: la documentazione attesta *due diligence* (Art. 24/32); un pentest manuale certificato di terza parte resta opzione a richiesta, a spese di chi lo richiede.
