---
tags:
  - documentazione/adr
date: 2026-09-22
tipo: adr
status: accettato
aliases: ["ADR-046", "scadenza delle bozze", "compilazioni risdoc", "minimizzazione", "art. 5(1)(c)"]
cssclasses: []
---

# ADR-046 — Le bozze di compilazione hanno una scadenza

## Stato

**ACCETTATO** dall'utente il 22 settembre 2026, con un «sì» dopo tre giri di
domande sue che hanno cambiato il disegno due volte. Vale la pena scrivere
quali, perché sono la ragione per cui la cosa funziona.

1. *«Di che bozze stiamo parlando? Dei risdoc? E solo se il salvataggio è
   attivo?»* — Sì e sì. Senza questa domanda avremmo probabilmente messo una
   scadenza anche su cose che non ne avevano bisogno.
2. *«Quando dovrebbe avvenire l'esportazione? Attenzione che ci sono modal
   anche per le verifiche.»* — Il sospetto era fondato: il modal è **uno solo
   per cinque cose diverse**, e agganciare la cancellazione al suo pulsante
   avrebbe toccato anche verifiche, esercizi e mappe.
3. *«Per esportazione cosa intendi? Quando apro il modal parte la compilazione
   e mostra il PDF subito; l'esportazione è quando scarico il PDF.»* — Anche
   questa correggeva un errore vero. La compilazione del PDF parte
   all'apertura dell'anteprima e ogni due secondi con la ricompilazione
   automatica, cioè **mentre il docente sta ancora scrivendo**. Agganciarci una
   cancellazione, anche con quindici giorni di grazia, avrebbe messo in coda di
   distruzione un documento che qualcuno aveva soltanto aperto per guardarlo.

## Il problema

Una compilazione di un modello risdoc è ciò che il docente scrive dentro un
atto della scuola: relazione finale, scheda di recupero, piano annuale. Per
natura può nominare uno studente, e per i modelli istituzionali lo fa quasi
sempre.

Restava sul server **per sempre**. La sola condizione di uscita era che il
docente la cancellasse a mano, o che cancellasse il proprio account.

Non è un difetto di sicurezza: le compilazioni sono cifrate con la chiave del
singolo docente (migrazione 101), i campi che nominano una persona vengono
svuotati al salvataggio dove non esistono account studente
(`CompilationScrubber`), e un Istituto può chiedere che non si salvino affatto
(`institutes.compilation_storage`). È un problema di **quantità di tempo**:
ogni giorno in cui quei dati restano è un giorno in cui possono uscire da una
violazione che non è ancora successa. L'art. 5(1)(c) non chiede di non
tenerli, chiede di non tenerli più del necessario — e il necessario finisce
quando il docente ha il documento in mano.

È anche l'unica mitigazione di **R20** che non chieda a una persona di non
fare qualcosa. Tutte le altre (il divieto nei Termini, l'avviso in pagina, la
responsabilità del docente) dipendono da chi scrive; questa no.

## La decisione

Due regole, e la prima è quella che conta.

### 1. Quindici giorni dallo scaricamento

Il conto parte da quando il docente **scarica** il documento, e si guarda
**l'ultima fra scaricamento e modifica**: ogni modifica lo fa ripartire da
capo.

Perché lo scaricamento e non la fine della compilazione: non esiste una «fine
della compilazione». Esiste invece un momento preciso in cui una copia del
documento comincia a esistere fuori di qui, ed è quello. Da lì in poi tenerne
una qui non serve più a niente — è la stessa idea del §5.1 dell'informativa,
portata fino in fondo.

Perché non dal primo scaricamento e basta: si esporta per vedere
l'impaginazione, per stampare una bozza, per mostrarla a un collega. Senza il
riazzeramento, il documento sparirebbe mentre lo si sta ancora scrivendo.

### 2. La rete di fine anno scolastico

Una bozza mai scaricata non cadrebbe mai sotto la prima regola: resterebbe per
sempre **proprio perché nessuno l'ha portata via**. La rete la prende il **31
agosto**, se è ferma dal **1° giugno** precedente.

Non è una ricorrenza — un lavoro che salta una notte non deve saltare un anno
— ma una soglia: dal 31 agosto in poi muore ciò che non si tocca dal 1° giugno.
Una bozza ancora lavorata a giugno serve per l'anno che comincia e sopravvive;
una ferma da maggio apparteneva a un anno che è finito.

**Il minimo garantito dalla rete è tre mesi** (modificata il 31 maggio,
cancellata il 31 agosto). Non esiste il caso di una bozza che nasce e muore in
pochi giorni per colpa della rete: quello lo fa solo lo scaricamento, che è un
gesto del docente.

### 3. Prima l'avviso, sempre

Sette giorni prima della scadenza parte un'email al docente — **una per
docente**, non una per bozza — che dice quali stanno per andarsene, quando, e
le tre cose che può fare: modificarla (il conto riparte), scaricarla, o non
fare niente.

E una riga **non si cancella** se l'avviso non è partito almeno **tre giorni**
prima. Nel giro normale questa condizione è già soddisfatta quando arriva la
scadenza e non ritarda nulla; serve per il caso storto — il lavoro fermo una
settimana, il server spento — in cui al primo giro utile ci si troverebbe
davanti righe già scadute e mai annunciate.

> **Nota del 23/9/2026.** Fino a questa data il caso storto non era coperto:
> il giro avvisava solo le righe nel preavviso, e una riga già scaduta e mai
> annunciata non veniva né avvisata né cancellata (revisione Risdoc, A2;
> misurato con tre notti simulate). Ora `SpazzataDelleBozze::daAvvisare()` la
> avvisa la prima notte utile e la cancellazione arriva tre giorni dopo: per
> queste righe il preavviso effettivo è di tre giorni, non sette. In
> produzione, al momento della correzione, non ce n'era nessuna (61 bozze
> guardate, zero scadute).

## Come si è dovuto costruire, e perché non c'era altra strada

Qui la mappa del codice ha deciso il disegno, non il contrario.

**Il server non vede lo scaricamento.** Il gestore del pulsante `⤓ PDF` prende
i byte del PDF già in memoria nel browser e li dà al browser come file:
nessuna richiesta parte. Non c'era niente da intercettare — quindi una rotta
nuova, `POST /api/risdoc/compilations/{id}/scaricata`, che è il client a
chiamare.

**Il client non aveva l'identificativo della compilazione.** Passava di lì due
volte — il server lo restituisce al salvataggio, e `_loadInstanceBodyPt` lo
vede come `m.id` — e tutte e due le volte finiva in una variabile che moriva
subito. Ora l'adattatore lo tiene.

**La chiave testuale non andava bene al suo posto.** `combo_<slug>` si
ricalcola dallo stato a ogni uso, e lo stato cambia sotto i piedi. Segnare per
chiave avrebbe voluto dire rischiare di segnare la riga sbagliata — o nessuna,
in silenzio.

**Il modo del modal non basta come guardia.** `risdoc-template` è vero anche
quando il super-admin modifica il modello originale, dove non c'è nessuna
compilazione di nessun docente. La guardia vera è l'**esistenza
dell'identificativo**: esiste solo se una riga salvata c'è davvero.

**Lo scaricamento non è uno solo.** Il PDF dal modal, il pacchetto ZIP e il
pacchetto per VSCode portano via lo stesso contenuto da tre pulsanti diversi.
Sono tre scaricamenti veri, e li segnano tutti e tre.

## Due trappole misurate, non dedotte

**`updated_at` salta da solo.** La colonna ha `ON UPDATE CURRENT_TIMESTAMP`:
un UPDATE qualunque la sposta ad adesso. Misurato nei due versi il 22/9 —
senza `updated_at = updated_at` salta, con l'assegnazione resta ferma. Se
saltasse, segnare uno scaricamento verrebbe contato come una modifica del
docente: la lista si riordinerebbe sotto le sue mani e la grazia ripartirebbe
da capo a ogni scaricamento.

**`risdoc_compilations` è una vista**, definita come `SELECT rc.*`, che MariaDB
espande alla creazione e si tiene. Senza ricrearla, le colonne nuove esistono
nella tabella e non si vedono da nessuna query che passi dalla vista — cioè da
quasi tutte. È la stessa nota che la migrazione 101 aveva già lasciato scritta
dopo averci sbattuto.

## Che cosa si è scelto di non fare

**Non si cancella senza aver avvisato.** Sarebbe stato più semplice e più
puntuale. Ma è lavoro di una persona, e toglierlo senza dirlo prima è il modo
migliore perché la prima lamentela faccia disattivare tutto.

**Non si conta dalla compilazione del PDF**, che il server vede benissimo. È
il punto 3 delle domande dell'utente: quella parte all'apertura e ogni due
secondi con la ricompilazione automatica. Sarebbe stato il verde più comodo e
il più sbagliato.

**Non si tocca il modello.** Si cancella la compilazione; il modello resta e si
ricompila quando si vuole. È detto in pagina, nell'email e nell'informativa,
perché è la differenza fra «ho perso il lavoro» e «ho perso una bozza».

**Non si rinuncia a cancellare quando l'avviso non si può recapitare.** Se il
docente non ha un indirizzo valido, o l'istanza non manda posta, la riga viene
segnata lo stesso e il giro prosegue: l'alternativa sarebbe una conservazione
che non finisce mai per un indirizzo mancante, cioè una minimizzazione che si
autoannulla in silenzio. Il conto degli avvisi non recapitati esce nel
resoconto: deve restare una cosa che si vede.

## Conseguenze

- Migrazione **138**: due colonne (`exported_at`, `expiry_warned_at`), un
  indice, e la vista ricreata.
- `App\Services\Risdoc\ScadenzaDelleBozze` — la regola, senza database: si
  prova su qualunque data senza far finta che sia un altro giorno.
- `App\Services\Risdoc\SpazzataDelleBozze` — il giro: censimento, avvisi,
  cancellazioni.
- `tools/gdpr/spazza_bozze_scadute.php` + `pantedu-risdoc-scadenze.{service,timer}`,
  ogni notte alle 03:50. **Le unità non le installa il rilascio**: vanno messe
  a mano sul VPS.
- Invariante **`bozze`** della diagnostica: guarda il **risultato** (restano
  righe scadute?), non se il lavoro notturno è partito. Un lavoro che gira e
  non cancella niente risulterebbe a posto.
- Informativa **2.14**, registro art. 30 **1.17**, DPIA **1.19**.
- Corretto per strada un difetto che non c'entra: il file scaricato dal modal
  si chiamava `verifica_<id>.pdf` anche per un piano annuale. Il titolo era lì,
  a due righe di distanza; mancava la lettura, non il dato.

## Che cosa questa decisione NON risolve

- **Il testo libero resta libero.** R20 resta MEDIO e resta accettato: questa
  misura riduce la finestra, non impedisce la scrittura.
- **La segnalazione dello scaricamento non è un fatto certo.** Rete che cade,
  scheda chiusa, blocco JS che fallisce: il docente ha il PDF e la data non si
  scrive. La riga cade allora nella rete di fine anno. È il verso giusto — un
  guasto lascia il lavoro dov'è — ma vuol dire che la prima regola è una
  indicazione, non una misura.
- **Le bozze tenute nel browser** (Istituti con `compilation_storage = 0`) non
  sono toccate: lì non decidiamo noi, e non c'è niente sul server da
  cancellare.
- **Le copie che il docente ha portato via** — il PDF sul suo computer, il
  pacchetto TeX, la copia su Drive — non le tocca nessuno, ed è il punto: è
  proprio perché esistono che questa qui può sparire.
