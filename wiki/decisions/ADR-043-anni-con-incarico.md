---
tags:
  - documentazione/adr
date: 2026-09-15
tipo: adr
status: accettato
aliases: ["ADR-043", "anni con incarico", "anni solo con incarico"]
cssclasses: []
---

# ADR-043 — Anche gli anni di corso seguono gli incarichi

## Stato

**ACCETTATO** dall'utente il 15 settembre 2026, scegliendo «Solo con incarico»
alla domanda: «Con "solo incaricati", oggi solo le sezioni seguono gli
incarichi: gli anni si offrono sempre. Come vuoi gli anni?». Nella stessa
risposta: togliendo un incarico, l'email al docente parte «solo se c'è qualcosa
da fare».

Modifica una regola di [[decisions/ADR-041-sezioni-dei-docenti]]: «Gli anni
sono sempre ammessi».

## Contesto

Dopo [[decisions/ADR-042-anni-per-indirizzo]] ogni anno appartiene a un corso.
L'utente, come amministratore, aveva dato a un docente le sezioni 3AR, 4AR e
5AR di architettura, e nessun anno di quel corso. Nel profilo il docente vedeva
comunque la 3, la 4 e la 5 di architettura da spuntare: con «solo incaricati»
solo le sezioni seguivano gli incarichi, gli anni si offrivano a tutti.

Nella stessa pagina, togliendo a un docente l'anno 5 dello scientifico, la
finestra diceva che avrebbe perso «questa sezione» dai suoi menù, cosa falsa per
un anno, e annunciava un'email anche se lì non aveva niente.

Misurato in produzione prima di decidere, istituto 106 in «solo incaricati»:

| docente | anni spuntati senza incarico | pubblicazioni su quegli anni |
|---|---|---|
| docente.uno | ART 1–5 (corso già spento da lui); AAA 3–5 (spente da lui) | 64 |
| docente.due (account di prova) | SCI 1–5, ART 1–5 | 168 |

## Decisione

1. **Con «solo incaricati» un docente usa una classe solo con l'incarico**,
   anno o sezione. L'incarico su un anno è quello nel suo corso («3» di
   architettura).
2. **Con «tutti»** ogni docente usa anni e sezioni; **con «nessuno»** nessuno usa
   le sezioni e tutti usano gli anni, come prima.
3. **Le spunte non ammesse si sospendono**, non si cancellano, e si riprendono
   quando l'amministratore dà l'incarico (migrazione 133,
   `SezioniDeiDocenti::riallinea`). I materiali restano dove sono: compaiono
   fra i «materiali su classi senza incarico», nel cruscotto del docente e in
   «Sposta di classe», da dove li porta in una classe che può usare.
4. **Un anno non ammesso resta la classe dei contenuti che ci sono già**:
   salvando non si perde il posto (`CurriculumLookup` risolve la classe ma non
   accende la spunta). Nuovi posti lì non se ne creano (serve la spunta).
5. **«Porta sull'anno» vuole l'incarico sull'anno**: portare i materiali di una
   sezione su un anno che il docente non può usare li lascerebbe di nuovo fuori
   dai suoi menù. L'amministratore dà prima l'incarico; niente si crea da solo.
6. **Togliere un incarico**: la finestra e l'email parlano di «classe»; l'email
   parte solo se su quelle classi il docente ha materiali o credenziali attive
   (`AvvisoIncarichiTolti::NIENTE_DA_FARE`).
7. **«Classi e sezioni»** nella pagina degli incarichi: una riga per anno, con
   prima l'anno intero e poi le sue sezioni, senza ripetere il nome del corso
   già scelto nel selettore (segnalato dall'utente).

## Conseguenze

- **Privacy.** L'incarico su un anno è meno dettagliato di quello su una sezione
  e non è un dato nuovo: incarichi sugli anni esistevano già. Cambia chi può
  usare un anno. DPIA 1.8, registro 1.10 e informativa 2.10 lo dicono; l'email
  al DPO (non ancora inviata) cita le versioni nuove.
- **Chi si iscrive** non vede classi finché l'amministratore non gli dà
  incarichi (con «solo incaricati»). Era già così per le sezioni.
- **La semina della suite end-to-end** dà ai due docenti di prova l'incarico su
  ogni anno che spuntano.
- **Non cambia**: la lettura delle pubblicazioni (chi vede che cosa), le
  credenziali di classe esistenti, gli studenti con account.

## Riferimenti

- [[decisions/ADR-041-sezioni-dei-docenti]], [[decisions/ADR-042-anni-per-indirizzo]].
- Migrazione `database/migrations/133_anni_con_incarico.sql`.
