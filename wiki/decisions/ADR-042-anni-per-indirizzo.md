---
tags:
  - documentazione/adr
date: 2026-09-15
tipo: adr
status: accettato
aliases: ["ADR-042", "anni per indirizzo", "classi annuali", "corso_anno"]
cssclasses: []
---

# ADR-042 — Gli anni di corso appartengono a un indirizzo

## Stato

**ACCETTATO** dall'utente il 15 settembre 2026: «le classi annuali dovrebbero
confinarsi all'interno dell'indirizzo, dovrebbero essere annuali per l'indirizzo
e non per tutti gli indirizzi della scuola», poi «Si a tutto, e le 6
pubblicazioni vanno a scientifico. va bene adr».

## Contesto

**Che cosa ha visto l'utente.** Come docente, sotto «Artistico — architettura e
ambiente» comparivano gli anni «1»…«5», anche se l'amministratore non gli aveva
dato niente in quel corso, e anche se architettura non ha né prima né seconda.

**Perché.** Nel catalogo di una scuola le classi sono di due specie
(migrazione 100):

- le **sezioni** («2A», «3AR»), che vengono dal MIUR e hanno un corso;
- gli **anni** («1»…«5»), che non avevano corso: una voce sola per tutta la
  scuola.

Un anno spuntato valeva quindi sotto ogni indirizzo spuntato. La barra lo
filtrava con «senza corso vale per tutti» (`sidebar-cascade.js`), il profilo lo
metteva in un gruppo «Anni interi», «Sezioni e incarichi» lo mostrava una volta
per tutti i corsi.

**Il resto era già per indirizzo.** Misurato in produzione il 15 settembre
2026, istituto 106:

| | |
|---|---|
| anni nel catalogo | 5, senza corso (id 164–168) |
| sezioni per corso | SCI 1–5, ART 1–2, AAA 3–5, AFI 3–5, SCS 1–2 |
| incarichi sugli anni | già per corso: docente 77, SCI 1–5 |
| pubblicazioni sugli anni | 411: tutte con il corso tranne 6 (bozze di verifica del docente 77 sulla «2») |
| esercizi sugli anni | 57, tutti con il corso (SCI 1–3, ART 3–5) |
| impostazioni di stampa e compilazioni sugli anni | 3 e 1; senza corso 2 e 1 |
| credenziali di classe e importazioni PDF sugli anni | 0 |
| spunte sugli anni | docenti 77 e 140, anni 1–5 |
| studenti con account | 0 |

Il problema stava in due posti: le voci del catalogo e le spunte.

## Decisione

1. **Un anno esiste una volta per corso.** «3» dello scientifico e «3»
   dell'artistico sono due voci. Nel catalogo nessun anno senza corso: lo
   impone il database (`chk_anno_ha_corso`).
2. **Le sigle delle sezioni restano uniche nella scuola**, come nel dataset
   MIUR. L'indice unico diventa `(kind, code, institute_id, corso_anno)`, dove
   `corso_anno` è il corso per un anno e vuoto per tutto il resto: si ripetono
   solo gli anni, una volta per corso.
3. **Quali anni ha un corso**, nella conversione: quelli in cui ha sezioni, più
   quelli su cui ci sono già dati di quel corso. Un corso senza nessuna sezione
   li prende tutti. In produzione: SCI 1–5, ART 1–5 (le sezioni sono solo 1–2,
   ma ci sono pubblicazioni ed esercizi su 3–5), AAA 3–5, AFI 3–5, SCS 1–2.
4. **I dati seguono il loro corso.** Pubblicazioni, esercizi, impostazioni di
   stampa, compilazioni, credenziali e importazioni passano all'anno del loro
   indirizzo. Chi vede che cosa non cambia.
5. **Le righe senza corso vanno al corso con più dati su quell'anno** (poi più
   sezioni, poi la sigla). In produzione è lo scientifico, come ha deciso
   l'utente per le 6 pubblicazioni; vale anche per le 2 impostazioni di stampa
   e la compilazione, le cui chiavi dicono già scientifico (`sc_3_MAT…`,
   `combo_SCI-2-…`). La riga prende anche l'indirizzo: da qui in poi un anno
   porta con sé il suo corso.
6. **Le spunte si dividono.** Un anno spuntato diventa l'anno di ogni corso in
   cui il docente lavora: i corsi che ha spuntato (accesi), quelli dove ha dati
   su quell'anno, quelli dove ha l'incarico. Si copiano accensione,
   sospensione, nome personale e condivisione. In produzione, docente 77: SCI
   1–5, AAA 3–5, ART 1–5; docente 140: SCI 1–5, ART 1–5.
7. **Un codice d'anno si risolve con il corso.** `CurriculumLookup` chiede
   l'indirizzo per gli anni (salvare un contenuto, un'importazione, una
   verifica, una credenziale, un incarico). Senza corso, un anno si risolve
   solo se la scuola lo ha in un corso solo. Una sezione non ammessa ripiega
   sull'anno **del suo corso**.
8. **Le pagine mostrano gli anni sotto il loro corso**: profilo del docente,
   barra del docente e barra pubblica (lo facevano già i dati), «Sezioni e
   incarichi» (gli anni dell'indirizzo scelto), catalogo dell'amministratore
   (un anno nuovo chiede un corso della scuola).

## Conseguenze

- **La prova a secco prima di unire**, su una copia ridotta delle tabelle di
  produzione (solo le colonne che la migrazione usa), fatta il 15 settembre
  2026: 411 pubblicazioni, 57 esercizi, 3 impostazioni di stampa, 1
  compilazione ancora sullo stesso anno; le 9 righe senza corso sullo
  scientifico; anni e spunte come nei punti 3 e 6; un anno senza corso
  rifiutato dal vincolo; il ritorno indietro rimette tutto com'era. Le chiavi esterne
  verso `curriculum_entries` sono `ON DELETE SET NULL`, e il trigger
  `trg_curriculum_no_orphan` non guarda `print_info_data` né
  `teacher_access_credentials_data`: cancellare un anno ancora puntato da lì
  azzererebbe la classe in silenzio. Per questo la migrazione conta i
  riferimenti rimasti e si ferma se non sono zero.
- **Nel minuto fra migrazione e scambio dei container** il codice precedente
  risolve un anno con la prima voce che trova, di un corso qualunque. Si unisce
  quando nessun docente sta salvando; il codice nuovo non parte prima della
  migrazione (tappe 4 e 7 del rilascio, `wiki/dev-workflow.md`).
- **Tornare indietro**: `tools/curriculum/anni_di_tutta_la_scuola.sql` rifonde
  gli anni per corso in un anno per scuola, con riferimenti e spunte, e rimette
  indice e vincolo di prima; poi il codice precedente. La divisione delle
  spunte si annulla per unione (un anno acceso in almeno un corso torna
  acceso).
- **Non cambia**: incarichi (`teacher_sections` aveva già il corso), sezioni,
  lettura delle pubblicazioni (si confrontano le sigle, già con il corso),
  iscrizione degli studenti (classe e indirizzo come sigle).
- **Non fa**: l'importatore MIUR crea sezioni, non anni; un corso nuovo
  importato dopo la conversione non ha anni finché l'amministratore non li
  aggiunge dal catalogo. Gli strumenti di una volta sola già usati
  (`tools/institutes/prune_cloned_curriculum.php`, `tools/cleanup_curriculum.php`)
  ragionano per `(kind, code)` e non si aggiornano: sono storici.

## Riferimenti

- [[decisions/ADR-035-catalogo-istituto-centralizzato]] — il catalogo è della scuola, il docente lo spunta.
- [[decisions/ADR-041-sezioni-dei-docenti]] — sezioni e incarichi; gli anni sono sempre ammessi.
- Migrazione `database/migrations/132_anni_per_indirizzo.sql`.
