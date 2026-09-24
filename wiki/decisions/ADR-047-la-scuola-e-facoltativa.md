---
tags:
  - documentazione/adr
date: 2026-09-22
tipo: adr
status: accettato
aliases: ["ADR-047", "scuola facoltativa", "senza istituto", "minimizzazione iscrizione"]
cssclasses: []
---

# ADR-047 — La scuola è facoltativa, tranne dove è la scuola a gestire la piattaforma

## Stato

**ACCETTATO** dall'utente il 22 settembre 2026. La proposta è sua:

> «stavo pensando che magari la scuola da inserire all'iscrizione potrebbe
> essere facoltativa, indicando in tal caso con qualche messaggio i servizi che
> si perdono se si rimane senza scuola: che ne pensi? anche perché la scuola è
> un dato "particolare" rispetto a un indirizzo o classe»

E la decisione sullo scenario 3, alla domanda se lasciarla obbligatoria lì:
«nello scenario 3 la scuola è d'obbligo».

## Il problema

Scuola, indirizzo e classe venivano chiesti insieme, come se fossero lo stesso
genere di dato. Non lo sono. Indirizzo e classe dicono **che cosa insegni**; la
scuola dice **dove lavori**. Il secondo identifica il posto di lavoro di una
persona, e messo insieme per più docenti descrive l'organizzazione di
un'istituzione.

L'art. 5(1)(c) chiede di raccogliere quello che serve a erogare il servizio. A
un docente che si iscrive per scrivere le proprie verifiche, la scuola non
serve: serve a delimitare a chi sono visibili i contenuti che pubblica, che è
una funzione fra le tante.

## La decisione

**La scuola è facoltativa all'iscrizione negli scenari 1 e 2.** Nello
**scenario 3** — la piattaforma adottata da un Istituto — resta obbligatoria.

Non per una ragione di principio: lì un docente senza scuola non può avere
incarichi di sezione né pubblicare, e un account che non può fare niente è
peggio di un campo in più.

**Chi non la indica lo sa.** Il modulo dice che cosa resta spento, e dice anche
che cosa continua a funzionare — un elenco di sole perdite fa credere che senza
scuola non si possa fare niente, e non è vero: si scrive, si compila, si
esporta.

**Si aggiunge e si toglie quando si vuole**, dal proprio profilo. Era la
domanda dell'utente prima di accettare, e la risposta era già sì: la pagina
esiste dal principio ed è coperta da una spec end-to-end.

## La sorpresa: lo stato esisteva già, e nessuno l'aveva guardato

Questo è il punto che ha spostato il peso del lavoro.

**«Docente senza scuola» era già raggiungibile**, in due clic dal profilo,
perché lo scollegamento non ha mai controllato che ne restasse almeno una. Non
si stava inventando un caso nuovo: si stava **dichiarando un caso che esisteva**.

E non era guardato bene.

- **Sedici punti del codice rispondevano `institute_not_found` con un 404**, e
  nessun pezzo di interfaccia traduceva quel codice. Al docente arrivava un
  errore di rete grezzo su una pagina vuota. Sette di quei punti stanno nella
  sola pagina delle fonti.
- **Il ripiego che ci si aspetterebbe non esiste.** Il «catalogo globale» del
  codice ripiegava su `curriculum_entries.institute_id IS NULL`. Misurato:
  **zero voci di ogni tipo**. La colonna è `NOT NULL` nello schema e le righe
  globali le aveva cancellate la migrazione 043. Un ramo che sembra proteggere
  e non protegge è peggio di un ramo che non c'è.
- **Scollegare l'ultima scuola rispondeva `{ok:true}` e basta**: catalogo,
  fonti e adozioni si spegnevano senza che niente l'avesse detto.

## Che cosa si è fatto, in ordine di peso

1. **Tre righe** nella validazione dell'iscrizione: la scuola è obbligatoria
   solo dove `SenzaScuola::obbligatoria()`.
2. **Un posto solo** per l'elenco di ciò che si perde e di ciò che resta
   (`App\Services\SenzaScuola`). Serve al modulo d'iscrizione, alle tredici
   rotte e al profilo: scritto tre volte, dopo un mese ce ne sarebbero tre
   versioni e una sola vera.
3. **I tredici 404 del docente ora spiegano**. Il codice di stato resta quello
   di prima — cambiarlo su tredici rotte insieme romperebbe i client in
   silenzio, e su quelle che *scrivono* un 200 sarebbe una bugia — e resta
   anche `error`, per chi lo leggeva. Cambia quello che mancava: la
   spiegazione e dove si rimedia.
4. **Un punto solo lato client** (`assertJson`), come già per la sfida del
   filtro di sicurezza: ogni rotta che dichiara `senza_scuola` ottiene l'avviso
   senza che nessuna pagina debba ricordarsene. Con una briglia, perché la
   pagina delle fonti fa sette chiamate e sette avvisi identici non li legge
   nessuno.
5. **Scollegare l'ultima scuola lo dice.** Resta possibile — è coerente con la
   scelta — ma non avviene più in silenzio. Nello scenario 3 non è possibile, e
   il messaggio dice perché.
6. **Il ripiego morto è stato cancellato**, in tutti e due i punti.

## Che cosa si è scelto di non fare

**Non si è toccata `users.institute_id`.** Misurato: per i docenti quella
colonna non è la scuola — i due docenti reali del database di sviluppo l'hanno
`NULL` e la scuola solo in `teacher_institutes`. Renderla facoltativa avrebbe
dato l'illusione di aver fatto il lavoro.

**Non si è aggiunta una via amministrativa** per collegare un docente a una
scuola. Oggi l'unico che può farlo è il docente stesso, dal proprio profilo, ed
è una cosa da sapere: se quel percorso si rompesse, un docente senza scuola
resterebbe chiuso fuori senza che nessuno possa rimediare dal pannello. Non è
un problema oggi — la pagina è coperta da una spec — ma è un punto unico di
guasto, ed è scritto qui perché si veda.

**Non si è messa una guardia che vieti di togliere l'ultima scuola** fuori
dallo scenario 3. Se la scuola è facoltativa, toglierla dev'essere possibile.

## Conseguenze

- `App\Services\SenzaScuola` — la regola e i due elenchi.
- Informativa §3.1 e registro art. 30 B.1 lo dichiarano.
- Prove: cinque di unità sull'elenco e sull'avviso; quattro d'integrazione che
  provano i due versi della regola (si iscrive senza scuola nello scenario 2,
  non si iscrive nello scenario 3); una end-to-end che scollega l'ultima
  scuola, verifica che l'applicazione lo dica e che le rotte dichiarino lo
  stato, e **la ricollega** — che è anche la controprova di quello che l'utente
  aveva chiesto.

## Che cosa questa decisione NON risolve

- **Nello scenario 3 nulla lega il docente iscritto all'Istituto adottante**:
  il modulo lascia scegliere qualunque scuola del MIUR. È un'assenza misurata,
  non una svista dedotta, ed è fuori dal perimetro di questa decisione.
- **Chi si iscrive senza scuola e poi ne vuole una** deve aggiungerla da sé.
  Non c'è nessun invito a farlo oltre agli avvisi che compaiono quando una
  funzione resta spenta.
