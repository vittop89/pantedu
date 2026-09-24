# Regole di lavoro su Pantedu

Questo file lo legge l'assistente a ogni sessione. Serve perché le regole che
si ripetono a voce si perdono al cambio di conversazione, e perché una regola
scritta si può correggere: se una di queste righe è sbagliata, si cambia qui.

## Da dove si parte

**Prima di lavorare su un'area, si legge la sua guida.** Non la memoria delle
sessioni precedenti, non un riassunto, non un prompt: quelli invecchiano senza
avvisare. Il 12 settembre 2026 una nota di memoria diceva «sette controlli
obbligatori su `main`», e la piattaforma ne applicava due.

| per | si legge |
|---|---|
| capire com'è fatto il progetto | `wiki/_llm-primer.md`, poi `wiki/map.md` |
| montare o usare l'ambiente, lanciare comandi in WSL | `docs/dev/sviluppo-in-wsl.md` |
| come si lavora: rami, controlli prima del push, migrazioni, cancello su `main` | `wiki/dev-workflow.md` |
| che cosa gira in CI, il rilascio, i falsi rossi | `docs/dev/ci-cd.md` |
| la suite end-to-end | `tests/e2e/README.md`, `wiki/testing.md` |
| il VPS: ripristino, diagnostica, accesso, runner | `docs/ops/` |
| sicurezza e WAF | `wiki/_security.md`, `wiki/waf.md` |
| privacy e documenti legali | `docs/privacy/`, `docs/legal/` |
| una decisione architetturale | `wiki/decisions/` (ADR) |
| tutto il resto | `docs/README.md` |

**Come si usano le guide.**

1. **Guida e realtà devono dire la stessa cosa.** Se il codice, la
   configurazione o la piattaforma contraddicono una guida, si misura chi ha
   ragione e si corregge l'altro **nella stessa pull request**. Non si segue in
   silenzio né la guida sbagliata né il codice che la smentisce.
2. **Una regola imparata va nella sua guida**, non solo in un messaggio di
   commit, in una nota di memoria o nel prompt di una sessione: lì non la
   ritrova nessuno. Vale per tutto il progetto → questo file; come si lavora →
   `wiki/dev-workflow.md`; l'ambiente → `docs/dev/sviluppo-in-wsl.md`; CI e
   rilascio → `docs/dev/ci-cd.md`; il VPS → `docs/ops/`; una scelta di
   architettura → un ADR.
3. **Una regola sta in un posto solo.** Altrove si mette un collegamento: due
   copie divergono, e prima o poi se ne crede quella sbagliata.
4. **I documenti storici non sono istruzioni.** `docs/plans/`, `docs/analysis/`,
   `docs/audits/`, `docs/security/pentest/` e `wiki/changelog/` raccontano che
   cosa si è deciso o trovato in un giorno preciso. Non si aggiornano al
   presente e non si seguono come procedura corrente.
5. **Le note di memoria sono fotografie.** Prima di citarne un numero, un
   percorso o un nome, si verifica che sia ancora vero.

**All'inizio di una sessione di lavoro:** in quale copia si è, su quale ramo, e
se ci sono modifiche in sospeso, **con il git di WSL**. Se la cartella di
lavoro non è `/home/<utente>/pantedu` né una sua copia di lavoro (compare in
`git -C ~/pantedu worktree list`), ci si ferma; se è la vecchia copia su
Windows, la sessione è sulla copia sbagliata, con `CLAUDE.md` e memoria
vecchi: si chiede all'utente. Se ci sono modifiche che non vengono da questa
sessione, ci si ferma e si chiede. Lo stato che l'app mostra da sola è
affidabile solo dentro WSL (`docs/dev/sviluppo-in-wsl.md`, «Che cosa si fa
ancora da Windows»).

Un ramo nato prima di un aggiornamento di questo file ne ha la versione vecchia,
o non ce l'ha affatto: in quel caso si legge quella di `main`
(`git show origin/main:CLAUDE.md`).

## Le prove seguono la modifica, senza chiederlo

**Non serve chiedere «e aggiorna anche il test».** Una modifica al
comportamento arriva con la sua prova: nuova se il comportamento è nuovo,
aggiornata se è cambiato. Vale per le correzioni quanto per le funzionalità —
una correzione senza una prova che fallisce prima e passa dopo non è una
correzione, è una speranza.

Le eccezioni sono tre, e vanno **dette**, non sottintese:

- modifiche che non toccano il comportamento (documentazione, commenti,
  rinomine interne);
- casi in cui la prova costerebbe più del codice che protegge, o richiederebbe
  un'infrastruttura che non c'è;
- casi in cui la prova non è scrivibile con gli strumenti attuali.

In tutti e tre: si dice quale prova manca e perché.

Dove vivono: `tests/Unit/` e `tests/Integration/` (PHPUnit), `tests/e2e/`
(Playwright, 138 file di spec al 16 settembre 2026). Il cancello su `main` fa girare la suite
`unit` senza database; tutte e due le suite PHPUnit girano su MariaDB nel
lavoro «PHP: prove d'integrazione (MariaDB)», che dal 14 settembre 2026 non è
ancora obbligatorio (`wiki/dev-workflow.md`). La suite end-to-end gira dopo
l'unione e la domenica.

## Misurare, non dedurre

La forma di guasto che questo progetto insegue è **il verde che non ha
guardato**: un controllo che riporta successo senza aver misurato niente. È
successo con AIDE («completato» per centoundici notti senza esaminare un file),
con un confronto di tabelle che cercava un nome che non esiste, con una prova
di semgrep che dava zero riscontri a tutte le varianti.

Quindi:

- ogni affermazione su come si comporta il sistema si accompagna alla misura
  che l'ha prodotta;
- ogni controllo nuovo si prova **nelle due direzioni**: scatta quando deve, e
  **non** scatta quando non deve. Un controllo provato in un verso solo può
  essere una funzione che risponde sempre «va bene»;
- «il comando non ha stampato niente» non è un esito: si guarda `$?`.
  Attenzione alla pipe, che restituisce l'esito dell'ultimo comando
  (`cmd | tail; echo $?` legge `tail`).

## Sicurezza e dati

- **Mai mockare `/auth/csrf`** nelle prove: si prende il gettone vero.
- **Non stampare né copiare i valori di `.env.local`**, e nessuna credenziale
  nelle spec.
- **Niente segreti, indirizzi IP o percorsi del server nei file del
  repository** — documentazione compresa.
- Le prove **non lasciano dati nel database**: nome unico con marca temporale e
  cancellazione a fine prova, anche quando la prova fallisce.
- **Non creare dati dalla UI dove esiste un'API.**

## Gli errori si correggono, non si silenziano

Gli allarmi che arrivano per posta (unità systemd fallite, integrità,
diagnostica) vanno chiusi correggendo la causa. Alzare una soglia, escludere un
percorso o marcare un'anomalia come vista è legittimo **solo dopo** aver
guardato ogni differenza una per una, e va scritto perché.

Un rapporto che non è mai pulito è un rapporto che dopo un mese non si apre
più.

## Ambiente

- **Si sviluppa in WSL** (`docs/dev/sviluppo-in-wsl.md`), non su Windows +
  XAMPP: repository in `~/pantedu`, database in un contenitore, server di
  sviluppo su `127.0.0.1:8765`. Le sessioni di Claude Code girano dentro WSL,
  sulla cartella `~/pantedu`, e git si usa solo da lì. La vecchia copia su
  Windows è in dismissione dal 22/9/2026: eliminazione decisa, la fa l'utente.
  Che cosa passa ancora da Windows — i PDF, `gh.exe`, l'SSH al VPS — e come ci
  si arriva dalla sessione sta nella stessa guida.
- La catena di integrazione e rilascio: `docs/dev/ci-cd.md`. Come si lavora:
  `wiki/dev-workflow.md`.
- **I comandi da dare all'utente:** quelli sul repository, sul database e sul
  server di sviluppo in **bash, da lanciare in WSL**; quelli per Windows
  (programmi, servizi, file fuori da WSL) in **PowerShell 5.1**, senza `&&` e
  un comando per blocco. Deciso dall'utente il 22/9/2026.
- Le spec end-to-end restano **JavaScript**; solo `tests/e2e/support/**` è
  TypeScript in modalità strict. `workers: 1` è una scelta, non una
  dimenticanza: un database e tre utenti condivisi.

## Lingua

**Italiano**: documenti, commenti nel codice, messaggi di commit, corpo delle
pull request. Anche i rapporti e le analisi.

## Firma

I commit finiscono con:

```
Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```

e il corpo delle pull request con:

```
🤖 Generated with [Claude Code](https://claude.com/claude-code)
```
