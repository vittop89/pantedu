# Refactoring della suite E2E — esito

Documento di chiusura del refactoring cominciato il 6 settembre 2026 e finito
il 7. Il piano è in [docs/plans/e2e-refactor-blueprint.md](../docs/plans/e2e-refactor-blueprint.md);
il racconto fetta per fetta è nel [changelog di settembre](changelog/2026-09.md);
le regole d'uso quotidiane sono in [testing.md](testing.md) e in
`tests/e2e/README.md`.

---

## 1. Che cos'era e che cos'è

### Prima

183 file `.spec.js` tutti nella stessa cartella, con nomi che dicevano la fase
di sviluppo in cui erano nati (`g19_18_print_info_load_modal`,
`g22_s15bis_fase5_templates_full`) e non che cosa verificavano. Ogni file
ricominciava da capo: apriva la sessione compilando il modulo di accesso,
leggeva la password da una variabile d'ambiente, si costruiva i selettori, si
scriveva le chiamate HTTP con il gettone di sicurezza preso a mano. Nessuna
fixture, nessuna pagina oggetto, nessun client delle API, nessun tipo.

Fra un'azione e la verifica c'erano 469 attese a tempo — undici minuti di sonno
letterale a ogni giro — perché nessuno sapeva quale segnale aspettare. 126
errori venivano ingoiati con `.catch(() => {})`, e 555 `console.log`
raccontavano al terminale quello che le asserzioni avrebbero dovuto verificare.
I dati arrivavano dal dump locale di chi aveva scritto la spec: la copia numero
58, l'esercizio 1293, l'istituto 106, il docente 140. Ventisei spec creavano
contenuti e non li cancellavano.

### Adesso

122 file divisi in undici aree che si chiamano come le parti
dell'applicazione — `studio/`, `verifiche/`, `risdoc/`, `condivisione/`,
`admin/`, `area-docente/`, `editor/`, `pubblico/`, `sicurezza/`,
`osservabilita/`, `qualita/` — con dentro i nomi delle cose che verificano,
scritti in italiano.

Sotto, un livello di supporto in TypeScript (40 file, ~4.000 righe) che le spec
importano ma non conoscono per intero:

- **fixture** per ruolo (docente, secondo docente, amministratore), con la
  sessione presa una volta e riusata, validata a ogni impiego;
- **client delle API** tipizzati, uno per area (contenuti, verifiche,
  condivisione, curriculum, mappe, pacchetti, informazioni di stampa, risorse
  docente, fonti, modelli TikZ, osservabilità, accesso, amministrazione), con
  il gettone di sicurezza gestito in un posto solo;
- **factory** che creano i dati con nomi unici e ne registrano la cancellazione
  nel momento stesso in cui li creano;
- **pagine e componenti** (barra degli strumenti, barra dei filtri, gruppo di
  quesiti, generazione della verifica, barra laterale, banco dell'editor);
- **segnali**: un registratore degli eventi `fm:*` dell'applicazione, con cui
  si aspetta quel che è successo invece di un tempo.

Le spec restano in JavaScript, come chiesto; quelle che ne traggono vantaggio
dichiarano `// @ts-check` e i tipi arrivano dal supporto.

---

## 2. Miglioramenti, per dimensione

### Affidabilità

- **Attese a tempo: 469 → 0.** Ogni `waitForTimeout` è stato sostituito con
  un'asserzione web-first, un `waitForURL`, un `waitForResponse` sull'endpoint
  vero, un evento `fm:*` o un attributo di pronto. Nessuna è sopravvissuta.
- **Errori ingoiati: 126 → 1**, quello commentato in
  `verifiche/figure-tikz-nelle-pagine` e registrato alla voce 50 del debito.
- **`networkidle`: 100 → 0.** Le pagine dell'applicazione fanno polling e
  «ferme» non lo sono mai.
- **Dipendenza dall'ordine: 7 file → 0** (resta `serial` dove il dominio lo
  impone, come la finestra di un minuto del limitatore di richieste).
- **Test senza asserzioni: 15 → 0.** Erano interi file che dichiaravano di
  «rapportare» invece di verificare.

### Prestazioni

- Il giro completo passa da **36,6 minuti per 617 test** a **15,6 minuti per
  585**, con un lavoro maggiore per test: le figure e i documenti si compilano
  davvero anche dove prima la risposta era finta.
- Gli undici minuti di sonno letterale non ci sono più; al loro posto ci sono
  attese che finiscono quando il segnale arriva.
- Il modulo di accesso non si compila più: le sessioni arrivano dalla cache, e
  vengono riaperte solo quando il server dice che sono scadute.

### Manutenibilità

- I nomi dicono che cosa si verifica, non in che fase è stato scritto.
- Delle spec dell'utente nessuna supera le 280 righe; l'unico file lungo è un
  test di modulo dell'editor (951 righe di casi sui formati in linea), che è un
  elenco di casi, non un flusso. Prima cinque file superavano le 400 righe e il
  più lungo, `verifiche_studio_smoke`, ne aveva 950 di flussi diversi mescolati.
- Il codice di supporto sta in un posto solo: cambiare un selettore o
  l'indirizzo di un'API si fa in un file, non in trenta.
- Ogni spec riscritta porta in testa un commento che dice **che cosa** verifica
  e **che cosa è cambiato** rispetto alla versione storica: chi la legge fra un
  anno sa perché è fatta così.

### Scalabilità

- `workers: 1` resta, ed è una scelta, non un limite tecnico da superare: c'è
  un solo database e tre utenti reali. Le fixture sono però già scritte per
  reggere l'isolamento (nomi unici, pulizia registrata), quindi il giorno in cui
  ci fossero utenti e database per worker il passaggio sarebbe una riga di
  configurazione.
- Le aree si possono lanciare separatamente (`playwright test tests/e2e/studio`),
  e i tag `@tex` e `@pdflatex` isolano quel che ha bisogno del servizio esterno.

### Sicurezza

- **Password nelle spec: 121 file le leggevano → 0.** Nessuna spec legge
  più una password: la sessione arriva dalla fixture, e le credenziali stanno
  solo in `.env.local`.
- **Segreti: 0.** Il segreto del servizio TeX non è più letto da una spec per
  firmare una richiesta a mano.
- **`/auth/csrf` intercettato: 7 → 0.** Nessuna spec sostituisce più il gettone
  di sicurezza con uno finto.
- Nessun indirizzo IP, percorso del server o identificativo di persona nei file
  del repo. Le immagini della regressione visiva nascondono gli elenchi dei
  contenuti e i nomi.

### Osservabilità

- `trace: "retain-on-failure"` al posto di `on-first-retry`, che con
  `retries: 0` non produceva **mai** una traccia.
- Una fixture di diagnostica allega a ogni fallimento gli errori JavaScript,
  gli errori di console e le risposte 4xx/5xx della pagina.
- **555 `console.log` → 0**: il resoconto si legge.
- **58 schermate salvate a mano → 0.** Le ultime tre stavano in due test di
  modulo che costruivano un elemento, verificavano che fosse visibile e lo
  fotografavano: sono state tolte a chiusura.

### Esperienza di chi ci lavora

- Scrivere una spec nuova vuol dire chiedere una fixture e usare un client:
  `contentFactory.exercise(...)`, `studioEsercizio.vaiA(...)`,
  `studioEsercizio.topbar.premi("info")`.
- `npm run e2e:typecheck` controlla il supporto e le spec che lo chiedono.
- I prerequisiti per far girare la suite su un'altra macchina sono scritti in
  [docs/ops/e2e-runner-prerequisiti.md](../docs/ops/e2e-runner-prerequisiti.md).

---

## 3. Scorecard

| Area | Prima | Dopo | Perché |
|------|:-----:|:----:|--------|
| Isolamento dei test | 1/5 | 4/5 | Ogni test crea i propri dati con nomi unici e ne registra la cancellazione; niente più dipendenze d'ordine. Non 5/5 perché il database resta uno e i tre utenti sono condivisi |
| Resilienza dei locator | 2/5 | 4/5 | Ruolo, etichetta, titolo e testo dove l'applicazione li offre; `data-*` dove servono. Restano 99 `.first()`, quasi sempre su elenchi dove il primo elemento è quello giusto per costruzione |
| Architettura delle fixture | 0/5 | 5/5 | Catena composta: diagnostica → sessione → pulizia → client → factory → pagine → strumenti |
| Pagine e componenti | 0/5 | 4/5 | Sei fra pagine e componenti, ognuno con la propria attesa dentro. Non 5/5: alcune aree (amministrazione, osservabilità) lavorano ancora per selettori diretti perché la loro interfaccia è tabellare e non ha stato |
| Dati di prova | 1/5 | 5/5 | Factory per esercizi, documenti, verifiche, mappe, coppie e condivisioni; nessun identificativo fisso del dump locale |
| Autenticazione | 2/5 | 5/5 | Sessione per ruolo dalla cache, validata a ogni uso; nessun accesso dal modulo nelle spec |
| Parallelismo | n/a | n/a | `workers: 1` per scelta: un database, tre utenti |
| Resistenza alla flakiness | 1/5 | 4/5 | Nessuna attesa a tempo, nessun `networkidle`, nessun errore ingoiato salvo uno documentato. Non 5/5 finché il servizio TeX esterno è nel percorso di alcuni test |
| Sicurezza dei tipi | 0/5 | 4/5 | Supporto in TypeScript stretto, senza `any`; le spec sono JavaScript per vincolo, e quelle che possono dichiarano `@ts-check` |
| CI/CD | 1/5 | 3/5 | I prerequisiti sono documentati e la suite si lancia per area; in CI gira ancora il solo sottoinsieme di accessibilità, perché il servizio TeX e il database non ci sono |
| Osservabilità | 2/5 | 5/5 | Tracce che si producono davvero, diagnostica allegata, nessun rumore |
| Sicurezza | 3/5 | 5/5 | Nessuna password, nessun segreto, nessun gettone finto, nessun dato di persona |
| Prestazioni | 2/5 | 5/5 | Da 36,6 a 15,6 minuti facendo più lavoro vero; il tetto rimasto è il servizio TeX |
| Manutenibilità | 1/5 | 5/5 | Aree, nomi parlanti, file corti, supporto unico |

---

## 4. Registro dei test instabili

Nessun test è risultato instabile alla chiusura: i fallimenti incontrati
durante il lavoro erano difetti veri — della suite o dell'applicazione — e sono
stati corretti o registrati. La tabella li elenca perché la prossima volta si
riconoscano.

| Test | Causa sospetta | Confidenza | Rimedio adottato |
|------|----------------|:----------:|------------------|
| `g19_18_print_info_load_modal` (storico) | `waitForFunction(fn, { timeout })`: le opzioni al secondo posto finivano come argomento, il tetto non veniva applicato e l'attesa durava quella predefinita. Fallito una volta su 600 per un caricamento più lento | alta | Corrette tutte e diciotto le chiamate (voce 52 del debito); poi le spec sono state riscritte |
| `qualita/regressione-visiva` — cruscotto del docente | L'immagine dell'intera pagina cambia altezza perché sotto ci sono gli elenchi dei contenuti che la suite stessa crea e cancella | alta | Delle pagine riservate si fotografa solo la parte sopra la piega (voce 60) |
| `qualita/regressione-visiva` — anomalie del WAF | La pagina mostra le anomalie provocate dalla suite mentre gira: contatori e grafico cambiano a ogni esecuzione | alta | Pagina esclusa dal confronto, con la ragione scritta nel file (voce 60) |
| `studio/matematica-in-pagina` — chiusura dell'editor | La barra fissa in fondo copre il bottone «Chiudi» su una pagina corta: il clic del mouse ci sbatte contro | alta | Difetto dell'applicazione (voce 54), **corretto**: il contenuto riserva lo spazio della barra e il test preme di nuovo col mouse |
| `verifiche/informazioni-di-stampa-dalla-pagina` | Il pannello delle informazioni copre «Salva» e «Carica» della barra: stesso genere di problema | alta | Difetto dell'applicazione (voce 57), **corretto**: il pannello si apre sotto la barra |
| `risdoc/documento-personalizzabile` — salvataggio | Il documento si salva da solo quando si lascia un campo; il «Salva» successivo trova che non c'è niente da riscrivere e risponde con un errore | alta | Difetto dell'applicazione (voce 61), **corretto**: un salvataggio senza modifiche risponde 200 |
| `risdoc/documento-personalizzabile` — apertura in modifica | Il componente entra in modifica da solo (edit-first): il test premeva «Modifica» e lo faceva uscire. Non si vedeva perché il pacchetto JavaScript in cartella era più vecchio dei sorgenti | alta | Il test guarda l'etichetta del comando prima di premerlo; il pacchetto fermo è la voce 62 |

---

## 5. Quel che resta aperto

Non è tutto risolto, e vale la pena dirlo per esteso.

1. **99 `.first()`**: quasi sempre legittimi (il primo quesito, il primo
   gruppo), ma un elenco che cambia ordine li farebbe puntare altrove.
2. **189 `page.evaluate`**: molti sono test di modulo, che girano nel browser
   per definizione; altri leggono lo stato calcolato (colore, posizione,
   altezza) che dal DOM non si vede. Nessuno è più usato per premere un
   bottone.
3. **CI**: gira il solo sottoinsieme di accessibilità. Per il resto servono un
   database, un servizio TeX e le credenziali: i prerequisiti sono scritti, il
   runner no.
4. **Difetti dell'applicazione trovati durante il refactoring**: registrati e
   non toccati mentre il lavoro era in corso, come da mandato, e **corretti
   subito dopo** — voci 53, 54, 55, 57 e 61 del
   [registro del debito](technical-debt.md), tutte chiuse il 7 settembre.
   Restano aperte le voci 40, 41 e 58, che chiedono lavoro sull'applicazione
   fuori dal perimetro di questo intervento.
5. **Coperture perse per impossibilità**, dichiarate: lo scostamento di una
   copia dal modello (voce 44), la pubblicazione di una sezione al pubblico
   (47), il consenso del genitore (48), la coerenza fra i tipi di cella
   JavaScript e PHP (58). Quella dei punteggi del vero/falso (55) è stata
   recuperata correggendo il difetto: sta in `studio/punteggi-vero-falso`.
6. **Il pacchetto del front-end non è versionato** e può restare indietro
   rispetto ai sorgenti: è successo, e per qualche ora la suite ha verificato
   JavaScript vecchio (voce 62). Da allora il setup globale confronta le date
   e ferma il giro prima di cominciare (sezione 9), quindi il rischio è
   segnalato invece che silenzioso; ricostruire resta a carico di chi lancia.

---

## 6. Le venti domande

| Domanda | Risposta |
|---------|----------|
| I test sono indipendenti? | **Sì.** Ognuno crea i propri dati; nessuno legge quel che ha lasciato un altro |
| Possono essere eseguiti in parallelo? | **No, per scelta.** Un database e tre utenti reali: `workers: 1` è una decisione presa nel blueprint, non un difetto. Le fixture sono già scritte per reggerlo il giorno in cui i dati fossero separati |
| Possono essere eseguiti singolarmente? | **Sì.** Ogni test costruisce il proprio stato; `--grep` su un titolo funziona |
| Sono deterministici? | **Sì**, per quanto lo consente un servizio TeX esterno: i test che lo usano portano il tag `@tex` |
| I locator sono resilienti? | **Sì.** Ruolo, etichetta, titolo, testo; classi `fm-*` solo dentro i componenti |
| I dati sono isolati? | **Sì.** Nomi unici con marca di tempo, cancellazione registrata alla creazione, eseguita anche in caso di fallimento |
| L'autenticazione è efficiente? | **Sì.** Una sessione per ruolo per giro, dalla cache, validata a ogni uso |
| Il setup usa API quando appropriato? | **Sì.** I dati nascono dalle API; l'interfaccia si usa per verificare, non per preparare |
| Il teardown è affidabile? | **Sì.** Il registro di pulizia gira nel teardown del ruolo, in ordine inverso, anche dopo un fallimento |
| La suite è type-safe? | **Sì per il supporto** (TypeScript stretto, nessun `any`); le spec restano JavaScript per vincolo dell'addendum, con `@ts-check` dove aiuta |
| La configurazione è centralizzata? | **Sì.** `playwright.config.js` e `support/env.ts`; nessuna variabile letta da una spec |
| La CI è shardable? | **Non applicabile**, documentato: un database e tre utenti. Le aree si lanciano separatamente |
| I failure producono diagnostica sufficiente? | **Sì.** Traccia, video, schermata e diagnostica allegata (errori JS, console, risposte 4xx/5xx) |
| I retry non nascondono flakiness? | **Sì**: `retries: 0`. Un test che fallisce, fallisce |
| I visual test sono deterministici? | **Sì, entro i limiti dichiarati**: animazioni ferme, contenuti mutevoli nascosti, soglia 2%, pagine impossibili da confrontare escluse con la ragione scritta |
| I mock hanno contratti tipizzati? | **Non ci sono mock.** Le uniche intercettazioni erano quelle di `/auth/csrf` e delle figure, e sono state tolte |
| Non esistono secret hardcoded? | **Sì.** Nessuna password, nessun segreto, nessun gettone: solo `.env.local` |
| Non esistono wait arbitrari? | **Sì.** Zero `waitForTimeout` in tutta la suite |
| Non esistono God Objects? | **Sì.** Il file di supporto più lungo è l'elenco dei tipi delle risposte (322 righe); il codice più lungo è la factory dei contenuti (308). Ogni client copre un'area sola |
| La suite è più semplice da capire rispetto a prima? | **Sì.** Nomi che dicono cosa verificano, aree che ricalcano l'applicazione, un commento in testa a ogni spec che spiega perché esiste |

---

## 7. Il giro di chiusura

**585 test su 585 passati in 15,6 minuti**, nessuno saltato, 7 settembre 2026.

Il giro precedente si era fermato a 587 su 588, e vale la pena dire perché: la
spec delle scelte della verifica scriveva nel campo del titolo mentre la
pagina, seicento millisecondi dopo essere pronta, lo riscriveva da sola con il
ripristino automatico. Passava sempre da sola e falliva sotto carico. È l'unico
test instabile che il refactoring ha prodotto, ed è stato corretto aspettando
quella richiesta invece di sperare: nove esecuzioni su nove verdi.

---

## 8. Numeri

| Misura | Prima | Dopo |
|--------|------:|-----:|
| File di spec | 183 | 122 |
| Test | 617 | 585 |
| Righe nelle spec | 26.856 | 14.506 |
| File di supporto | 0 | 40 (4.026 righe) |
| Attese a tempo | 469 | 0 |
| `networkidle` | 100 | 0 |
| Errori ingoiati | 126 | 1 (commentato) |
| Stampe di console | 555 | 0 |
| Schermate salvate a mano | 58 | 0 |
| Spec che aprivano la sessione dal modulo | 114 | 0 |
| Spec che leggevano una password o un segreto | 121 | 0 |
| Intercettazioni di `/auth/csrf` | 7 | 0 |
| Spec che si saltavano da sole | 18 | 0 |
| Test senza asserzioni | 15 | 0 |
| `test.setTimeout` nelle spec | 317 | 24 |
| Durata del giro completo | 36,6 min | 15,6 min |

Il giro con le quattro reti della sezione 9 attive, la sera dello stesso
giorno: **589 test su 589 in 12,7 minuti**. I quattro test in più sono la
copertura dei cinque difetti corretti nel frattempo.

---

## 9. Dopo la chiusura: quattro reti, e quel che hanno trovato

Il refactoring era finito. Rileggendo la suite intera sono venute fuori quattro
cose che valevano solo per i test che se le ricordavano, o solo finché chi
scriveva stava attento. Sono diventate regole della suite (7 settembre 2026).

| Rete | Prima | Adesso |
|------|-------|--------|
| Tempo massimo | 317 `test.setTimeout` sparsi nelle spec, quasi tutti copiati | 24 dove il tempo dipende dal caso; 90 s nel config, 4,5× il test più lento |
| Regole delle spec | scritte nel README e affidate alla disciplina | 5 regole ESLint su `tests/e2e/**/*.spec.js`, tutte verificate accese |
| Errori JavaScript | asseriti in 67 test su 589 | controllati per tutti nella fixture della diagnostica, senza via d'uscita per test |
| Pacchetto del front-end | poteva essere più vecchio dei sorgenti in silenzio | il setup globale confronta le date e ferma il giro |

Sulla terza vale la pena essere espliciti, perché la tentazione era l'opposto.
Il primo disegno prevedeva una via d'uscita dichiarata — `diagnostics.tollera(schema, perché)` — per i casi
scomodi. È stata scartata: una tolleranza per test è la stessa cosa di un
errore ingoiato, scritta meglio, ed è esattamente il vizio che il refactoring
ha passato la giornata a togliere. L'unico posto dove si dichiara «questo non è
un errore dell'applicazione» è il filtro condiviso `CONSOLE_NOISE`, che sta in
un file solo e si legge tutto insieme. Quando un errore salta fuori ci sono tre
esiti onesti, in quest'ordine: si corregge l'applicazione; oppure, se è di una
libreria di terzi, si allarga il filtro scrivendo lì la ragione; oppure si apre
una voce nel registro del debito.

### Quel che le reti hanno trovato

Il primo giro con il controllo acceso ha dato 584 su 589. Cinque fallimenti,
due errori distinti, e nessuno dei due era rumore:

1. **Un gruppo aperto subito dopo il caricamento si richiudeva da solo**
   (voce 63 del debito). `collapsible.js` riapplicava lo stato di default a
   300 e a 1200 millisecondi dal caricamento senza guardare se nel frattempo
   l'utente avesse aperto qualcosa. Non l'ha trovato il controllo degli errori:
   l'ha trovato l'inseguimento di un test instabile, che è finito nell'unico
   posto dove poteva finire.
2. **La verifica WCAG del testo ingrandito al 200% non ingrandiva niente**
   (voce 64). Lo script che alzava il carattere girava prima che il documento
   esistesse e moriva su `documentElement` null; le tre immagini di
   riferimento erano state registrate a carattere normale, e il criterio 1.4.4
   non era verificato da nessuno. L'errore stava da mesi nella console della
   pagina — cioè esattamente dove il nuovo controllo guarda. Rifatte le
   immagini davvero al doppio, è emerso che l'etichetta «CHIUDI» della barra
   laterale si taglia (voce 65, aperta).
3. **Un errore rosso per una richiesta annullata**: il precaricamento di
   `Elementi_Riservati.html` registrava un errore quando la richiesta veniva
   annullata perché l'utente aveva già cambiato pagina. È un precaricamento e
   il codice lo gestisce: adesso è un avviso.
4. **Un test che verificava un incidente** (voce 66). «L'intestazione del
   gruppo in modifica resta ancorata» falliva una volta su sei. Guardando bene:
   entrando in modifica il gruppo cede l'intestazione al pannello dell'editor e
   perde la classe `active`, che è l'unica cosa che `verifica-sticky.js`
   ancora. Il test passava quando il modulo faceva in tempo a fissarla prima,
   e quel `position: fixed` rimasto lì era tutto ciò che vedeva. Adesso guarda
   il gruppo aperto — il caso che il modulo serve davvero — aspetta che il
   contenuto si distenda e scorre finché l'intestazione si ancora.

Corretti tutti e quattro, il giro completo è tornato verde: **589 test su 589
in 12,7 minuti**, con il controllo degli errori attivo su ognuno.

La lezione delle prime due è la stessa: un errore che nessuno legge non è un
errore che non c'è. Il criterio 1.4.4 era «verde» da mesi con una verifica
vuota, e nessuno se n'era accorto perché il segnale stava nella console della
pagina invece che nel risultato del test.
