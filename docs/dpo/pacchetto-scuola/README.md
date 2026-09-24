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
| `Pacchetto-DPO-pantedu.{md,pdf}` | **Allegato A, versione 1.1 (22/9/2026)** — accountability completo: misure Art. 32, mappatura AgID, minimizzazione+DPIA, titolarità, sintesi audit, roadmap |
| `Lettera-alla-Dirigente.md` | **Bozza del 22/9/2026, non inviata** — comunicazione sull'attività extraistituzionale (art. 53 D.Lgs. 165/2001, DPR 62/2013 artt. 6-7). Separata da quella al DPO di proposito: è un'altra materia, e tenerle insieme faceva della lettera al DPO un atto rivolto all'Istituto |
| `Lettera-accompagnamento-DPO.{md,pdf}` | Primo invio (1° settembre 2026), conservata come trasmessa |
| `Bozza-DPA-Art28.{md,pdf}` | **Allegato B — ritirato il 3 settembre 2026**: presupponeva l'autore come Responsabile su infrastruttura propria |
| `Email-al-DPO.md` | Corpo dei messaggi email: primo e secondo invio (trasmessi), la bozza del terzo (assorbita), il sollecito con termine (**scartato il 22/9/2026**) e la **comunicazione di aggiornamento** che lo sostituisce, non ancora inviata. Non finisce nel clone pubblico |

## Allegati NON versionati qui (consegna su richiesta / sotto riservatezza)
| File | Dove | Nota |
|------|------|------|
| `Allegato-C-Report-Audit-firmato.pdf` | `C:\security_tools\audits\pantedu-2026-06-14\dpo-allegati\` | Report di pentest completo: contiene dettaglio tecnico/architetturale → consegnare solo su richiesta del DPO, preferibilmente sotto NDA |
| DPIA | `docs/privacy/dpia.{md,pdf}` | Allegato D |
| Informativa privacy | `docs/privacy/informativa.{md,pdf}` (fonte unica, anche su `/privacy/informativa`) | Allegato E |
| Registro art. 30 | `docs/privacy/registro-trattamenti.{md,pdf}` | Allegato F |

## Come usare
1. PDF del pacchetto: `_gen_pdf.py` (Edge headless) su
   `docs/dpo/pacchetto-scuola/Pacchetto-DPO-pantedu.md` (Allegato A), e su ogni
   altro `.md` di questa cartella con un `.pdf` accanto il cui sorgente è
   cambiato: li nomina `tools/ci/check-pdf-aggiornati.mjs`.
2. PDF di DPIA, Informativa e Registro: `tools/legal/build_pdf.sh` (pandoc +
   xelatex) su `docs/privacy/dpia.md`, `docs/privacy/informativa.md` e
   `docs/privacy/registro-trattamenti.md`.
3. Invia: Nota + A + D + E + F. Su richiesta del DPO: Allegato C.

I due script usano strumenti installati su Windows (Python, Edge, pandoc,
MiKTeX): col bash e il python di WSL si fermano. Come si lanciano e come si
controlla il PDF uscito: `docs/dev/sviluppo-in-wsl.md`, «Che cosa si fa ancora
da Windows».

> Nota di trasparenza: la documentazione attesta *due diligence* (Art. 24/32); un pentest manuale certificato di terza parte resta opzione a richiesta, a spese di chi lo richiede.
