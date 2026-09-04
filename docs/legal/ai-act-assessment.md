---
tags:
    - documentazione/ai-act
    - conformita
    - sicurezza
date: 2026-08-26
tipo: assessment
status: bozza-completa
versione: 1.0
classification: 📗 PUBLIC
aliases: ["ai-act", "assessment-ia", "regolamento-ia"]
---

# Assessment AI Act — Regolamento (UE) 2024/1689

> **Oggetto**: classificazione dei sistemi di IA presenti in Pantedu e misure
> di conformità al Regolamento (UE) 2024/1689 ("AI Act").
>
> **Esito**: Pantedu è **fornitore** di sistemi di IA a **rischio limitato**.
> Si applicano l'**art. 4** (alfabetizzazione) e l'**art. 50** (trasparenza).
> **Non** si applica il Capo III (alto rischio): nessuno dei casi dell'Allegato
> III, punto 3 (istruzione) ricorre. Nessuna pratica vietata dall'art. 5.
>
> **Documento vivo**: va riesaminato a ogni nuova funzione che tocchi l'IA.
> Vedi § 8 "Trigger di riesame".

---

## 1. Perché questo documento esiste

L'AI Act non chiede a un fornitore a rischio limitato una valutazione di
conformità né una documentazione tecnica ex art. 11. Chiede però di sapere
cosa si sta facendo: la classificazione va motivata e deve reggere di fronte a
un'autorità di vigilanza. Questo documento è quella motivazione.

Serve anche a una seconda cosa, più pratica: il confine fra rischio limitato e
alto rischio, in una piattaforma didattica, è sottile. Una singola funzione in
più — la correzione automatica di una verifica — sposta Pantedu nel Capo III
con tutto ciò che ne consegue. Il § 8 elenca quei confini in modo esplicito
perché nessuno li attraversi per distrazione.

## 2. Ruolo di Pantedu nella catena del valore

| Qualificazione | Riferimento | Motivazione |
|---|---|---|
| **Fornitore** (provider) | art. 3(3) | Sviluppa sistemi di IA e li mette in servizio con il proprio nome |
| **Fornitore a valle** (downstream provider) | art. 3(68) | Integra modelli GPAI di terzi (Anthropic, OpenAI, oppure Ollama in locale) senza svilupparli |
| **Deployer** | art. 3(4) | Sull'istanza gestita direttamente dall'operatore tecnico |

**Non** è fornitore di modelli GPAI: non ne addestra né ne immette sul mercato.
Gli obblighi degli artt. 53-55 non lo riguardano.

### 2.1 Ambito di applicazione

Si applica ex art. 2(1)(a) e (b): stabilimento in Italia, sistemi messi in
servizio nell'Unione.

**L'esenzione open source dell'art. 2(12) non scherma.** Pantedu è rilasciato
sotto EUPL-1.2, ma l'esenzione per i sistemi rilasciati con licenza libera e
open source cede espressamente quando il sistema ricade nell'art. 5 o
nell'art. 50. Le funzioni di IA di Pantedu ricadono nell'art. 50. L'esenzione
è quindi irrilevante per la parte che conta.

Non si applicano neppure:

- art. 2(6) — l'esclusione per ricerca e sviluppo: l'istanza è in esercizio;
- art. 2(8) — l'esclusione per l'attività precedente all'immissione sul
  mercato: il codice è distribuito pubblicamente e l'istanza è operativa;
- art. 2(10) — l'esclusione per uso personale non professionale: l'uso è
  professionale (attività didattica).

## 3. Inventario dei sistemi di IA

### 3.1 PDF-Import (`app/Services/PdfImport/`)

Pipeline che estrae esercizi da pagine di libri scolastici e li porta nella
base dati del docente. Sei stadi, con natura giuridica diversa:

| Stadio | Componente | Cosa fa | Classe art. 50(2) |
|---|---|---|---|
| Estrazione | `ExtractionPipeline` | Legge il testo degli esercizi dalla pagina rasterizzata | **Assistiva** — trascrive ciò che è stampato |
| Scansione numeri | `ExtractionPipeline` (SCAN) | Legge i numeri dei badge | **Assistiva** |
| Difficoltà | `DifficultyRefiner` | Conta i pallini pieni del badge | **Assistiva** |
| Argomento | `TopicGenerator` | Inferisce l'argomento dell'esercizio | **Generativa** |
| Traduzione | `TranslationGenerator` | Traduce in italiano esercizi in lingua straniera | **Generativa** |
| Soluzione | `SolutionGenerator` | Genera la soluzione passo-passo quando non è stampata | **Generativa** |

**Finalità prevista**: produrre materiale didattico per il docente, che lo
rivede prima di inserirlo. Non valuta studenti, non li profila, non produce
decisioni che li riguardino.

**Destinatari**: solo ruolo `teacher` e superiori. Gli studenti non hanno
accesso agli endpoint.

**Stato**: disattivato per impostazione predefinita (`PDF_IMPORT_ENABLED=false`).

### 3.2 Copilot — **rimosso il 26 agosto 2026**

Esisteva un proxy verso OpenAI/Anthropic per un assistente di scrittura negli
editor (`/api/copilot/chat` più gli alias legacy `/copilot.php` e
`/copilot_proxy.php`). **È stato eliminato**, non riparato.

Tre motivi, in ordine di gravità:

1. **Credenziali dal browser** — il proxy accettava la chiave API del fornitore
   nel corpo della richiesta POST inviata dal client e ne registrava il prefisso
   nel log. Superficie di gestione credenziali senza contropartita.
2. **Informazione errata sull'identità del sistema** — l'interfaccia dichiarava
   "GitHub Copilot" mentre il traffico andava a OpenAI o Anthropic. Sotto
   l'art. 50(1) un'informazione sbagliata è peggio di nessuna informazione.
3. **Irraggiungibile** — il pannello si caricava solo se `api/copilot-ai.js`
   esisteva sotto la document root, che è `public/`: condizione mai vera. La
   funzione era di fatto morta da tempo.

Rimosso: controller, asset front-end (`copilot-ai.{js,css}`,
`copilot-ai-init.js`), tre rotte, i punti di aggancio nella toolbar dell'editor
e nel template riservato, i test e2e relativi.

**Se in futuro serve un assistente di scrittura**, il pattern corretto è quello
già usato da PDF-Import: chiavi lato server in `ProviderKeyStore`, cifrate con
la KEK del docente, mai in transito dal browser — più l'obbligo di disclosure
dell'art. 50(1) con il nome del fornitore reale.

Ne consegue che **l'unico sistema di IA presente in Pantedu è PDF-Import**.

### 3.3 Cosa NON è un sistema di IA

Il **WAF applicativo** (`app/Services/Waf/WafScoringService.php`) assegna un
punteggio di rischio bot al fingerprint del browser. Non rientra nella
definizione dell'art. 3(1): è un sistema basato su regole deterministiche
definite integralmente da persone fisiche, esplicitamente escluso dal
considerando 12. Non inferisce nulla, applica soglie scritte a mano.

Va detto, perché è il punto in cui un lettore frettoloso potrebbe leggere
"scoring" e pensare a profilazione: non lo è, e non tratta dati biometrici.

## 4. Pratiche vietate — art. 5

Nessuna. Verificato voce per voce:

| Lettera | Pratica | Presente |
|---|---|---|
| (a) | Tecniche subliminali o manipolative | No |
| (b) | Sfruttamento di vulnerabilità (età, disabilità, situazione socio-economica) | No |
| (c) | Punteggio sociale | No |
| (d) | Previsione del rischio di reato | No |
| (e) | Scraping non mirato di immagini facciali | No |
| (f) | **Inferenza di emozioni in ambito scolastico** | **No** |
| (g) | Categorizzazione biometrica su attributi sensibili | No |
| (h) | Identificazione biometrica remota in tempo reale | No |

La lettera (f) è quella che riguarda direttamente una piattaforma educativa e
merita di essere affermata esplicitamente: **Pantedu non inferisce emozioni né
stati d'animo di nessuno.** Non elabora immagini di persone, audio, webcam o
espressioni facciali. L'unico input visivo sono pagine di libri di testo.

## 5. Classificazione del rischio

### 5.1 Perché NON è alto rischio

L'Allegato III, punto 3 elenca quattro casi per istruzione e formazione
professionale. Nessuno ricorre:

| Caso Allegato III p.3 | Presente | Verifica |
|---|---|---|
| (a) Determinare accesso, ammissione o assegnazione a istituti | **No** | Nessuna funzione di ammissione o smistamento |
| (b) **Valutare i risultati dell'apprendimento**, anche per orientare il percorso | **No** | Nessuna correzione automatica; nessun adattamento del percorso in base al rendimento |
| (c) Valutare il livello di istruzione appropriato | **No** | Nessuna funzione del genere |
| (d) Monitorare comportamenti vietati durante le prove | **No** | Nessun proctoring, nessuna sorveglianza |

Il punto decisivo è il (b). L'IA di Pantedu classifica **esercizi**, non
studenti: assegna una difficoltà a un esercizio stampato su un libro, ne
inferisce l'argomento, ne genera la soluzione per il docente. In nessun punto
prende in input il lavoro di uno studente né produce un output che lo riguardi.

Non c'è **profilazione di persone fisiche** ex art. 4, punto 4 GDPR. Questo è
rilevante di per sé: l'art. 6(3), ultimo comma stabilisce che un sistema
dell'Allegato III è **sempre** ad alto rischio quando profila persone fisiche,
senza possibilità di deroga. L'assenza di profilazione è quindi una
precondizione, non un dettaglio.

### 5.2 Conclusione

**Rischio limitato.** Si applicano:

- **art. 4** — alfabetizzazione in materia di IA (dal 2 febbraio 2025);
- **art. 50** — obblighi di trasparenza (dal 2 agosto 2026).

Non si applicano: valutazione di conformità (art. 43), marcatura CE (art. 48),
dichiarazione di conformità (art. 47), registrazione nella banca dati UE
(art. 49), sistema di gestione della qualità (art. 17), documentazione tecnica
(art. 11), valutazione d'impatto sui diritti fondamentali (art. 27).

## 6. Misure adottate

### 6.1 Art. 4 — Alfabetizzazione

Vedi [`ai-literacy.md`](/legal/ai-literacy). Destinatari: operatore tecnico e
docenti che usano PDF-Import.

### 6.2 Art. 50(1) — Informare che si interagisce con un'IA

- **PDF-Import**: si applica l'eccezione ("a meno che ciò non risulti evidente"
  per una persona ragionevolmente informata e avveduta). Il docente sceglie
  esplicitamente il fornitore e il modello, inserisce la propria chiave API e
  vede il registro delle chiamate. Che sia IA è il presupposto della funzione.
- **Copilot**: era l'unico punto in cui l'obbligo si poneva davvero, ed è stato
  risolto rimuovendo la funzione (§ 3.2). Nessun sistema di IA di Pantedu
  interagisce oggi direttamente con una persona in modo non evidente.

### 6.3 Art. 50(2) — Marcatura degli output sintetici

**Implementata.** Il contenuto prodotto dagli stadi *generativi* viene marcato;
quello *assistivo* no, in forza dell'eccezione dell'art. 50(2) per i sistemi
che svolgono una funzione assistiva per l'editing standard o che non alterano
in modo sostanziale i dati di input o la loro semantica.

La provenienza è tracciata **per campo** (`AiProvenance`), non per esercizio:
la stessa riga può avere un testo trascritto dal libro e una soluzione generata
dal modello. `SolutionGenerator` scrive infatti la soluzione solo quando quella
stampata sul libro manca, così le due restano distinguibili.

Marcatura emessa da `ContractRenderer`:

| Livello | Forma |
|---|---|
| Item | `data-ai-generated="true"` + `data-ai-fields="…"` sull'elemento |
| Pagina | `<meta name="fm-ai-generated" content="partial">` + `<meta name="fm-ai-generated-fields">` |
| Pagina | Blocco JSON-LD `schema.org/CreativeWork` con l'elenco degli item e dei campi |
| Visibile | Marcatore "IA" accanto al badge, con `title` e `aria-label` discorsivi |

Nel contract l'item porta un blocco `ai` con `fields`, `ops` (operazione +
modello + data) e `human_reviewed`.

**Sullo stato dell'arte.** Non esiste, ad agosto 2026, uno standard armonizzato
per il watermarking del testo: l'art. 50(7) affida all'AI Office la
facilitazione dei codici di condotta e nessuno è oggi vincolante. L'art. 50(2)
chiede soluzioni "efficaci, interoperabili, solide e affidabili **nella misura
in cui ciò sia tecnicamente possibile**", tenuto conto dei costi di attuazione
e dello stato dell'arte. La combinazione qui descritta è quella scelta e va
letta come tale: una convenzione documentata e interoperabile, non una
conformità a uno standard che non esiste ancora. Quando un codice di condotta
verrà approvato ex art. 50(7), questa sezione va riaperta.

### 6.4 Art. 50(4) — Controllo editoriale

L'inserimento in `teacher_content` avviene **solo dopo** lo step di revisione
del docente in PDF-Import. Il fatto è registrato come `human_reviewed: true`
nel blocco `ai` dell'item. È l'elemento che sostiene l'eccezione dell'art.
50(4), secondo comma, per il testo sottoposto a revisione umana con
responsabilità editoriale.

Il contenuto resta comunque marcato: l'obbligo dell'art. 50(2) grava sul
fornitore e non ha una deroga per revisione umana.

### 6.5 Misure non dovute ma presenti

Queste non sono obbligatorie a rischio limitato. Sono elencate perché
costituiscono la sostanza di ciò che un codice di condotta volontario ex
art. 95 richiederebbe, e perché in caso di escalation ad alto rischio sono il
punto di partenza:

- `PromptGuard` — difesa da prompt injection sul testo derivato dal PDF, che è
  dato non fidato (cfr. art. 15(5), attacchi di evasione);
- `PiiMasker` — redazione di codice fiscale ed email prima dell'invio al
  fornitore. **Limite dichiarato**: copre pattern deterministici via regex, non
  i nomi liberi;
- `SsrfGuard` — allowlist degli host per il provider locale;
- budget token per docente, chiavi API cifrate con la KEK del docente;
- `LlmAuditLog` — registro delle operazioni verso il fornitore, visibile al
  docente.

## 7. Limiti noti

Dichiarati, non dimenticati.

| Limite | Conseguenza | Nota |
|---|---|---|
| **Nessuna marcatura nei PDF/LaTeX** | Un esercizio esportato in PDF perde la marcatura leggibile da macchina | `TexBuilder` non ha un preambolo `hyperref` gestito e i template verifica sono personalizzabili per istituto e docente. Mitigazione parziale: il marcatore visibile "IA" ha una regola `@media print` che ne esplicita il significato in stampa |
| `LlmAuditLog` limitato a 200 voci, cancellato con la sessione | Irrilevante a rischio limitato | Diventerebbe bloccante ad alto rischio: gli artt. 19 e 26(6) impongono almeno 6 mesi |
| `PiiMasker` non copre i nomi liberi | Rischio residuo GDPR sul trasferimento al fornitore | Vedi `docs/privacy/dpia.md` |

## 8. Trigger di riesame — cosa fa diventare Pantedu alto rischio

Se una di queste funzioni viene implementata, Pantedu diventa **fornitore di un
sistema di IA ad alto rischio** ex Allegato III, punto 3, e scatta l'intero
Capo III:

1. **Correzione o valutazione automatica** delle risposte degli studenti → p. 3(b);
2. **Difficoltà o argomento usati per decidere cosa mostrare a uno specifico
   studente** (percorso adattivo) → p. 3(b), "orientare il processo di
   apprendimento";
3. **Proctoring o rilevamento di comportamenti vietati** durante le verifiche →
   p. 3(d);
4. **Assegnazione a classi, indirizzi o livelli** tramite IA → p. 3(a) e 3(c);
5. **Qualsiasi profilazione di persone fisiche** → art. 6(3), ultimo comma: la
   deroga non è più disponibile, in nessun caso.

Cosa comporterebbe: artt. 8-15 (gestione dei rischi, governance dei dati,
documentazione tecnica ex Allegato IV, registrazione, trasparenza verso i
deployer, sorveglianza umana, accuratezza e cibersicurezza), artt. 16-22
(obblighi del fornitore, sistema di gestione della qualità, conservazione dei
log per almeno 6 mesi), art. 43 e Allegato VI (valutazione di conformità con
controllo interno), art. 47 (dichiarazione UE di conformità), art. 48
(marcatura CE), art. 49 (registrazione nella banca dati UE). E, in capo alle
scuole che lo utilizzano in quanto organismi di diritto pubblico, l'art. 27
(valutazione d'impatto sui diritti fondamentali).

### 8.1 Se una scuola cambia la finalità prevista

Art. 25(1)(c): chi modifica la finalità prevista di un sistema di IA non
classificato ad alto rischio in modo tale da renderlo ad alto rischio **diventa
fornitore** e assume gli obblighi dell'art. 16. La clausola è richiamata nei
[Termini di Servizio](/legal/tos) perché l'allocazione sia chiara prima,
non dopo.

## 9. Sanzioni

Per riferimento, ex art. 99:

| Violazione | Massimale |
|---|---|
| Art. 5 (pratiche vietate) | 35 M€ o 7% del fatturato mondiale annuo |
| Art. 50 (trasparenza) e obblighi degli operatori | 15 M€ o 3% |
| Informazioni inesatte alle autorità | 7,5 M€ o 1% |

Per le PMI e le start-up si applica il **minore** fra la percentuale e
l'importo (art. 99(6)).

## 10. Scadenze

| Data | Cosa |
|---|---|
| 2 feb 2025 | Capi I e II — art. 4 (alfabetizzazione) e art. 5 (pratiche vietate) |
| 2 ago 2025 | Capo XII (sanzioni), Capo V (GPAI), art. 78 |
| **2 ago 2026** | **Applicazione generale, incluso l'art. 50** |
| 2 ago 2027 | Art. 6(1) — alto rischio da normativa di armonizzazione |

## 11. Rapporto con il GDPR

L'art. 2(7) AI Act lascia impregiudicato il Regolamento (UE) 2016/679. Il
trattamento connesso a PDF-Import è descritto in:

- `docs/privacy/registro-trattamenti.md` — art. 30
- `docs/privacy/dpia.md` — art. 35
- [`dpa_template.md`](/legal/dpa) — sub-responsabili e trasferimenti art. 44

## 12. Riferimenti nel codice

| Cosa | Dove |
|---|---|
| Tracciamento della provenienza | `app/Services/PdfImport/AiProvenance.php` |
| Propagazione al contract | `app/Services/PdfImport/ExerciseInserter.php` (`baseItem`) |
| Marcatura in rendering | `app/Services/ContractRenderer.php` (`renderAiMarker`, `renderAiPageMarking`) |
| Forma del blocco `ai` | `schemas/pantedu.content.v1.json` (`$defs.aiProvenance`) |
| Test | `tests/Unit/PdfImport/AiProvenanceTest.php`, `tests/Unit/PdfImport/ExerciseInserterAiMarkingTest.php`, `tests/Unit/Services/ContractRendererAiMarkingTest.php` |

## Decision log

| Data | Decisione |
|---|---|
| 2026-08-26 | Assessment iniziale. Classificazione a rischio limitato motivata; marcatura art. 50(2) implementata; marcatura nei PDF esclusa dallo scope e dichiarata come limite noto |
| 2026-08-26 | **Copilot rimosso** anziché riparato (§ 3.2) → l'unico sistema di IA resta PDF-Import e l'art. 50(1) non ha più un caso applicativo aperto |
| 2026-08-26 | **Qwen (Alibaba) e OpenRouter rimossi** dai fornitori supportati. Ogni fornitore è un DPA da verificare e una base di trasferimento da reggere; Qwen è extra-SEE con SCC mai verificate, OpenRouter instrada verso terzi scelti da uno slug — sub-responsabili non enumerabili in anticipo, incompatibile con un DPA scolastico. Restano Anthropic, OpenAI e Ollama |
| 2026-08-26 | **Default provider = `ollama`**: il percorso senza trasferimenti diventa la scelta implicita. `PDF_IMPORT_ENABLED` resta `false` |
| 2026-08-26 | Assessment e scheda di alfabetizzazione **pubblicati** su `/legal/ai-act` e `/legal/ai-literacy`: l'art. 4 chiede misure che raggiungano le persone, non file in repository |
