# Dichiarazione di Accessibilità — Pantedu

> Dichiarazione redatta secondo il modello **AgID Form-A** (Allegato A
> alla Determinazione AgID n. 224/2020), in conformità con la Legge
> 9 gennaio 2004 n. 4 ("Legge Stanca"), Direttiva UE 2016/2102 e norma
> **EN 301 549 v3.2.1** (che incorpora WCAG 2.1 livello AA).
>
> **Allineamento volontario WCAG 2.2 AA / European Accessibility Act.**
> Pur non essendovi soggetto per obbligo (l'EAA — Dir. UE 2019/882 — non
> copre questa categoria di servizio e prevede l'esenzione per le
> microimprese), Pantedu adotta **volontariamente** come target i criteri
> **WCAG 2.2 livello AA** e i requisiti EAA applicabili (dichiarazione
> pubblicata e aggiornata, meccanismo di feedback accessibile, audit
> documentato, monitoraggio continuo). Audit tecnico per criterio:
> [`accessibility-audit.md`](accessibility-audit.md).

## Soggetto erogatore

| Campo | Valore |
|---|---|
| Nome del soggetto erogatore | Vittorio Pantaleo (Pantedu — progetto individuale) |
| Tipologia | Privato erogatore di servizio educativo open source |
| Sito web | <https://pantedu.eu> |
| Codice fiscale / P.IVA | _Non applicabile (progetto personale pre-PA-onboarding)_ |

## Stato di conformità

**Pantedu è conforme** ai requisiti previsti dalle linee guida AgID
sull'accessibilità degli strumenti informatici (Legge 4/2004, Direttiva
UE 2016/2102, EN 301 549 v3.2.1 / WCAG 2.1 livello AA), con le sole
esenzioni per **contenuti di terze parti** non sviluppati, finanziati né
controllati dal soggetto erogatore, documentate alla lettera c) della
sezione "Contenuti non accessibili".

> **Aggiornamento 2026-06-21** — Risolte le tre barriere di accessibilità
> in precedenza dichiarate:
>
> - **Mappe concettuali** (WCAG 1.1.1 Contenuti non testuali): ogni mappa
>   offre ora un'**alternativa testuale accessibile** — l'elenco dei
>   concetti e delle relazioni (sorgente → destinazione) estratto dal
>   contenuto del diagramma, presentato in un pannello collassabile
>   (`<details>`) nativamente navigabile da tastiera e screen reader. La
>   *consultazione* delle mappe da parte di studenti e pubblico è quindi
>   pienamente accessibile, indipendentemente dall'editor di disegno.
> - **Editor formule TeX** (WCAG 2.1.1 Keyboard, 4.1.2 Nome/Ruolo/Valore):
>   i comandi della palette sono pulsanti operabili da tastiera e
>   l'inserimento delle formule avviene in un campo di testo standard
>   (digitazione LaTeX); il menu "TeX" adotta il pattern ARIA *disclosure*
>   (`aria-expanded`/`aria-controls`, chiusura con Esc con ritorno del
>   focus al pulsante, spostamento del focus nel menu all'apertura).
> - **Tabelle dati** (WCAG 1.3.1 Info e correlazioni): le intestazioni di
>   colonna di tutte le tabelle dati (pannelli amministrativi e WAF, area
>   docente, tabelle nei documenti) usano ora `scope="col"`.
>
> Un audit automatico con axe-core (regole WCAG 2.1 A/AA) sulle pagine
> pubbliche (home, ToS, AUP, informativa privacy, dichiarazione di
> accessibilità) e su quelle autenticate principali (area docente, pannelli
> amministrativi, form profilo), in tema **chiaro e scuro**, non rileva
> **alcuna violazione di gravità serious/critical**. L'unica funzionalità
> non pienamente operabile da tastiera è l'**editor di disegno** delle
> mappe (componente di terze parti drawio/jgraph), trattato come contenuto
> di terze parti fuori ambito (lettera c); la *consultazione* delle mappe
> dispone comunque dell'alternativa testuale accessibile sopra descritta.

### Contenuti non accessibili

#### a) Inosservanza della Legge 4/2004

Non si rilevano contenuti di **prima parte** (sviluppati dal soggetto
erogatore) non conformi ai requisiti applicabili. Le barriere
precedentemente elencate — alternativa testuale delle mappe (1.1.1),
accessibilità da tastiera e semantica ARIA dell'editor di formule TeX
(2.1.1, 4.1.2) e intestazioni delle tabelle dati (1.3.1) — sono state
risolte (vedi "Aggiornamento 2026-06-21").

> **Nota su `style="..."` inline.** La presenza di attributi `style` inline
> nelle view **non costituisce** una non-conformità WCAG: il criterio
> 4.1.1 (Parsing) riguarda la correttezza del markup (annidamento, ID
> univoci) ed è stato **rimosso in WCAG 2.2**; gli stili inline restano una
> questione di manutenibilità, oggetto di refactor incrementale, non di
> accessibilità.

#### b) Onere sproporzionato

Nessuna esenzione per onere sproporzionato dichiarata.

#### c) Contenuti non rientranti nell'ambito di applicazione

- **Editor di disegno delle mappe concettuali (drawio)** — l'editing
  visuale dei diagrammi è fornito dal componente di terze parti
  **drawio/jgraph** (licenza Apache-2.0), incorporato come `iframe` e
  **non sviluppato, finanziato né controllato** dal soggetto erogatore.
  Le sue lacune di accessibilità da tastiera ricadono nell'esenzione per
  i **contenuti di terze parti** prevista dalla Direttiva UE 2016/2102
  (art. 1 §4 lett. d) e dalla Determinazione AgID n. 224/2020. La
  *consultazione* delle mappe è comunque resa accessibile a tutti gli
  utenti tramite l'alternativa testuale (concetti + relazioni) descritta
  nello "Stato di conformità".
- Documenti PDF generati automaticamente (verifiche, esercizi) sono
  forniti come output diagnostico per docenti; il rendering accessibile
  è demandato a tool di terze parti (es. Adobe Acrobat Pro).
- Mappe concettuali drawio sono contenuti generati dall'utente docente
  (UGC); l'accessibilità del singolo file dipende dalle scelte autoriali
  del docente.

## Redazione della dichiarazione

| Campo | Valore |
|---|---|
| Data di redazione | 2026-05-23 (rev. 2026-06-21: risoluzione barriere) |
| Metodologia | Autovalutazione effettuata dal soggetto erogatore |
| Strumenti utilizzati | Audit manuale + axe-core 4.x + WAVE + Lighthouse |
| Standard di riferimento | WCAG 2.1 AA (W3C) ed EN 301 549 v3.2.1 |
| Prossima revisione prevista | 2026-12-23 (cadenza semestrale o ad ogni release major) |

## Feedback e contatti

Gli utenti possono notificare al soggetto erogatore eventuali casi di
mancata conformità e richiedere informazioni o contenuti esclusi
dall'ambito della Direttiva via:

- **Email accessibilità**: <accessibility@pantedu.eu>
- **Email DPO** (per esercizio diritti GDPR collegati): <dpo@pantedu.eu>
- **Modulo segnalazione**: <https://pantedu.eu/segnalazione-contenuti>

Il soggetto erogatore risponde entro **30 giorni** dalla richiesta,
fornendo le informazioni richieste o motivando l'eventuale rifiuto.

## Procedura di attuazione

In caso di risposta insoddisfacente o di mancata risposta entro 30
giorni alla notifica o alla richiesta, l'utente può inoltrare una
segnalazione all'**AgID — Agenzia per l'Italia Digitale** secondo
le modalità indicate sul sito:

<https://form.agid.gov.it/view/eb1f4528-bcb4-4f0b-a40a-4c629051ac21>

## Informazioni sul sito

| Campo | Valore |
|---|---|
| Data pubblicazione | 2026-05-23 (versione v0.1.0) |
| Conformità ai sensi della Legge 4/2004 | Conforme, con esenzioni per contenuti di terze parti (vedi "Stato di conformità" e lettera c) |
| Modalità di realizzazione | Sviluppo interno, codice open source EUPL-1.2 |
| Conformità a Linee Guida AgID | Conforme (audit completato 2026-06; barriere first-party risolte) |
| Conformità a Linee Guida di Design servizi web PA | Non applicabile (progetto privato; valutazione in caso di adozione PA) |

## Tecnologie e compatibilità

Il sito è progettato per essere compatibile con i seguenti combinazioni
di tecnologie:

### Browser supportati

- **Desktop**: Chrome/Edge 120+, Firefox 120+, Safari 17+
- **Mobile**: Chrome Android 120+, Safari iOS 17+
- Browser legacy (IE11, browser pre-2024) non supportati per ragioni
  di sicurezza (TLS 1.3, CSP livello 3) e di accessibilità (manca
  supporto adeguato a `:focus-visible`, `prefers-color-scheme`).

### Lettura con screen reader (utenti non vedenti / ipovedenti)

- **Formule matematiche**: ogni formula renderizzata espone il **MathML
  assistivo** (`a11y/assistive-mml` di MathJax + `assistiveMml`), un MathML
  visivamente nascosto che gli screen reader leggono come matematica.
  Verificato in produzione su una pagina di esercizi reale: tutte le formule
  (1000+) dispongono del MathML assistivo. Essenziale per una piattaforma di
  matematica e fisica.
- **Contenuti dinamici** (notifiche, stato di salvataggio, sincronizzazione)
  annunciati via `role="status"` / `aria-live`.
- **Finestre di dialogo**: `role="dialog"` + `aria-modal` + `aria-labelledby`,
  con gestione del focus all'apertura.
- **Gerarchia dei titoli** senza salti di livello; **nessun ID duplicato**;
  ordine di lettura coerente (nessun `tabindex` positivo); ogni pagina ha un
  titolo `<h1>` programmatico.

### Tecnologie assistive testate

| AT | Versione | Browser host | Stato |
|---|---|---|---|
| NVDA | 2024.4+ | Firefox 120+ | ✅ Navigazione principale verificata |
| VoiceOver | macOS 14+ | Safari 17+ | ✅ Consultazione mappe via alternativa testuale; editor drawio (terze parti) non navigabile (fuori ambito) |
| TalkBack | Android 14+ | Chrome 120+ | ✅ Form mobile OK; editor TeX con palette tastiera + ARIA disclosure |
| JAWS | 2024+ | Edge 120+ | ❌ Non testato (licenza non disponibile) |

### Tecnologie web utilizzate

- **HTML5** con landmark semantici (`<main>`, `<nav>`, `<header>`, `<footer>`)
- **CSS3** con Custom Properties (token semantici), supporto
  `prefers-color-scheme` e `prefers-reduced-motion`
- **JavaScript ES2022+** con ES Modules, no jQuery dipendenze runtime
- **WAI-ARIA 1.2** per pattern complessi (dialog, tabs, live regions,
  combobox)
- **PHP 8.4** server-side rendering (no SPA-only — ogni route
  renderizzabile senza JS per content statico)

## Stato SPID/CIE {#stato-spid-cie}

Pulsanti "Entra con SPID" e "Entra con CIE" sono visualizzati sulla
pagina di login ma **attualmente disabilitati**: pantedu non è ancora
registrato come Service Provider presso AgID.

Stato:
- ✅ **Scaffolding tecnico pronto** (controller, route, schema DB,
  config env) — riferimento [`docs/plans/d2-spid-cie-integration.md`](https://github.com/vittop89/pantedu/blob/main/docs/plans/d2-spid-cie-integration.md)
- ⏸️ **Registrazione AgID Service Provider**: BLOCKED in attesa di
  decisione product (affiliazione PA pilota oppure costituzione
  aggregatore SPID privato).
- 🎯 **Target attivazione**: Q4 2026 — Q1 2027 (dipende dalla scelta
  del percorso).

Per docenti di scuole pubbliche interessati a contribuire come
"PA tenant pilota", contattare <vittorio.pantaleo@pantedu.eu>.

## Stato delle azioni di miglioramento (roadmap)

| Fase | Descrizione | Stato | Data target |
|---|---|---|---|
| C.1 | Critical WCAG fixes (skip link, focus visible, modal ARIA, label form, status messages) | ✅ Completata | 2026-05-23 |
| C.2 | Typography px → rem migration (501 declarations) per WCAG 1.4.4 resize | ✅ Completata | 2026-05-23 |
| C.3 | Color tokens completi + `prefers-color-scheme` + `data-theme` override esplicito | ✅ Completata | 2026-05-23 |
| C.4 | Modularizzazione `layout.css`, AgID statement, axe-core CI | ✅ Completata (audit axe pubbliche+autenticate light/dark senza serious/critical 2026-06-01) | 2026-06-01 |
| C.5 | Risoluzione barriere residue: alternativa testuale mappe (1.1.1), ARIA editor TeX (2.1.1/4.1.2), `scope` tabelle dati (1.3.1), operabilità da tastiera dei controlli del builder verifiche (2.1.1: `role=button`+`tabindex`+attivazione Invio/Spazio+focus visibile) | ✅ Completata | 2026-06-21 |
| D.1 | SPID / CIE integration come metodo di login alternativo per docenti PA | 🔜 Pianificata | 2026-Q4 |
| D.2 | WCAG 2.2 AA upgrade (target size 2.5.8, dragging 2.5.7, focus 2.4.11, auth 3.3.8, help 3.2.6, redundant entry 3.3.7) | ✅ Completata (criteri implementati in `a11y.css`; audit per criterio in `accessibility-audit.md`; suite CI axe-core 10/10 verde 2026-06-21) | 2026-06-21 |
| D.3 | EN 301 549 v3.3 readiness assessment + audit esterno indipendente | 🔜 Pianificata | 2027-Q2 |

---

_Ultimo aggiornamento: 2026-06-21 (conforme WCAG 2.2 AA; pagine pubbliche 14/14 + autenticate axe-clean; screen reader: MathML assistivo sulle formule verificato in prod, gerarchia heading senza salti, 0 ID duplicati, h1 su ogni pagina; allineamento volontario EAA)_

_Pubblicato in conformità con l'art. 3-quater della Legge 4/2004
e con la Determinazione AgID n. 224 del 16 luglio 2020._
