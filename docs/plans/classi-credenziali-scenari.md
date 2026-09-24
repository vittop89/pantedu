# Piano: classi, credenziali e aree di amministrazione per scenario

Stato: approvato il 5 settembre 2026, decisioni chiuse (§6). Eseguito lo stesso giorno, per intero (A, B, D, C), sul ramo `worktree-classi-scenari`, un commit per passo; lo scenario 1 resta attivo in produzione. Prima del merge su `main`: prove locali di cambio scenario (§6.5), poi in produzione `php tools/migrate.php` (104 e 105) e riallineamento dei consensi all'informativa 2.4 (`GDPR_TEXT_VERSION` se impostato in `.env.local`).
Riferimenti: ADR-032 (scenari di esercizio), ADR-028 (governance d'Istituto), `app/Support/DeploymentScenario.php`, `app/Support/ClassAccessGrant.php`, `app/Services/TeacherSectionService.php`, `app/Repositories/TeacherContentRepository.php`.

## 0. Stato di fatto

| Tema | Com'è oggi |
|------|------------|
| Scenari | 1 personale, 2 colleghi, 3 Istituto. Sorgente di verità `DeploymentScenario`, pannello `/admin/system/deployment`. Il 3 richiede istanza dichiarata qualificata ACN. |
| Classi | Il codice anno («3») è la granularità dei contenuti: in locale tutti i contenuti sono etichettati per anno o senza classe, nessuno per sezione. La sezione («3A») è lo strato dell'Istituto: sezione dello studente con account e incarichi docente→sezione (`/admin/sections`). |
| Regola incarichi | «3» copre 3A, 3B e le sezioni future; «3A» copre solo sé stessa; lo studente su «3» vede solo docenti su «3» (asimmetria voluta). Vive in `TeacherSectionService`. |
| Credenziale di classe (scenari 1-2) | Un grant per docente in sessione (`fm_teacher_access`), facoltativamente delimitato a indirizzo e classe. L'ospite vede solo i contenuti pubblicati di quel docente; se delimitato, solo quella classe. Nessun dato personale nel grant. |
| Studente con account (scenario 3) | Scope forzato alla propria coppia indirizzo-classe; gli incarichi filtrano i docenti; sezioni nascoste rispettate. |
| Confronto classe | Esatto (`classe = ?`) sia per l'ospite sia per l'account: «3A» non vede «3». |
| Selettore classi | Lo studente non sceglie: indirizzo e classe sono fissi in sidebar. Con credenziale non delimitata naviga tutto il pubblicato del docente, anni futuri inclusi. |
| Area admin | Menu e dashboard uguali in ogni scenario. `/admin/sections`, registrazione studenti e classi ammesse sono inerti negli scenari 1-2 ma visibili senza indicazione. |

## 1. Problemi

| ID | Problema | Effetto | Scenari |
|----|----------|---------|---------|
| P1 | Confronto esatto anno/sezione | Credenziale o studente «3A» non vede i contenuti «3», cioè tutti quelli scritti oggi: solo i «generali» restano visibili | 1, 2, 3 |
| P2 | Una credenziale per docente | Con più colleghi sulla stessa classe, più accessi; una credenziale attiva per volta | 2 |
| P3 | Anni già frequentati non consultabili | Lo studente di terza non rivede prima e seconda, salvo credenziale non delimitata che apre anche quarta e quinta | 1, 2, 3 |
| P4 | Area admin non adattata allo scenario | Pannelli inerti visibili; nessuna guida su cosa serve nello scenario attivo | 1, 2, 3 |
| P5 | Verifiche riusate | Un archivio degli anni passati esporrebbe verifiche in uso con classi più giovani | 1, 2, 3 |

## 2. Principi

1. L'anno è la granularità base; la sezione è un raffinamento che si accende solo con un Istituto.
2. Una regola sola per incarichi e contenuti: l'anno copre le sue sezioni; la sezione non eredita nulla «a caso».
3. Negli scenari 1 e 2 nessun dato personale nuovo, nessun elenco di studenti, nessuna struttura d'Istituto.
4. I filtri dello studente partono chiusi e si aprono per regola esplicita, mai per default.
5. Chi amministra vede nel pannello solo ciò che ha effetto nello scenario attivo; il resto è raggiungibile ma dichiarato inattivo.

## 3. Interventi

### A. Regola anno→sezioni nel filtro dei contenuti (P1)

Priorità alta. Stima: mezza giornata con i test. Nessuna migrazione, nessun dato da toccare.

- `TeacherContentRepository`, clausola `student_scope`: da `classe = ?` a `classe IN (esatta, anno)`, con `TeacherSectionService::anno()`; stessa cosa per i bersagli del fan-out (`content_target_classes`).
- Vale per l'ospite con credenziale e per lo studente con account. Le query del docente non passano di lì e restano intatte.
- Test: credenziale «3A» vede «3» e «3A»; credenziale «3» non vede «3A»; fan-out; codici legacy («2s» → «2»).
- Documentazione: nota in ADR-032, voce nel registro del debito della wiki.

### B. Aree di amministrazione per scenario (P4)

Priorità alta. Stima: una giornata.

Matrice pagina × scenario. «Attiva» = nel menu e nella dashboard; «inerte» = fuori dal menu, raggiungibile per URL con un avviso «inattivo nello scenario N, si attiva con…» e link al pannello Deployment.

| Area | 1 personale | 2 colleghi | 3 Istituto |
|------|-------------|------------|------------|
| Dashboard, Tools (Notifiche, Utenti, Log, Hash), Analytics, Templates, RisDoc, Curriculum, Sidebar | attiva | attiva | attiva |
| Istituti (intestazione, logo, compilazioni per Istituto) | attiva | attiva | attiva |
| Conformità (DSR, Breach, Sub-processor, Authority, Takedown, ToS log) | attiva | attiva | attiva |
| Sicurezza e infra (Crypto, WAF, Backup, Deployment, Monitor, Logs, Migrazioni, Infrastruttura) | attiva | attiva | attiva |
| Registrazioni docenti (scheda in Tools, `/admin/registrations`) | inerte: iscrizioni chiuse | attiva, in evidenza: approvazioni | attiva |
| Sezioni e incarichi (`/admin/sections`) | inerte | inerte | attiva |
| Registrazione studenti: modalità e classi ammesse (blocco in Deployment) | inerte | inerte | attiva |
| Adozioni per Istituto | attiva (vocabolario del curriculum) | attiva | attiva |
| Riquadro «Credenziali di classe» (attive, senza identità) | attiva | attiva | inerte |
| Riquadro «Studenti senza sezione» | inerte | inerte | attiva |

Realizzazione:

- Un solo punto che decide: `DeploymentScenario::adminAreas()` (o `AdminAreaPolicy`) restituisce per ogni voce lo stato nello scenario corrente.
- `views/admin/_partials/page_head.php` consuma la mappa per il menu; `dashboard.php` per i riquadri; le pagine inerti mostrano l'avviso in testa.
- Badge dello scenario accanto al ruolo nella barra in alto, così è visibile da ogni pagina.
- Test: rendering del menu per ciascuno dei tre scenari; pagina inerte raggiungibile con avviso.

### C. Portachiavi di credenziali (P2)

Priorità media. Stima: una o due giornate. Solo se lo scenario 2 si apre con colleghi sulle stesse classi.

- Il grant diventa una lista in sessione (`ClassAccessGrant::all()`); il filtro dei contenuti accetta più docenti, ognuno con il proprio perimetro; la sidebar raggruppa per docente; uscita singola o totale.
- Quando una credenziale non è più valida, avviso esplicito con l'etichetta, non sparizione silenziosa dei contenuti.
- QR per credenziale, generato dal docente; pacchetto QR firmato dal server per esportare un portachiavi completo (solo riferimenti alle credenziali).
- «Ricorda su questo dispositivo», facoltativo e non spuntato per default: cookie tecnico firmato che dura quanto la credenziale (per default fino al 31 agosto), banner sempre visibile con «Esci». Da elencare fra i cookie tecnici nell'informativa.
- Scadenza per default al 31 agosto; rotazione della sola password; contatore delle sessioni attive per credenziale nel pannello del docente (solo il numero).
- Modalità aggiuntiva: codice monouso proiettato in classe, valido dieci minuti.
- Niente estensioni del browser: bloccate sui PC della scuola, assenti su Chrome per Android, onerose da pubblicare per un software rivolto a minori.

### D. Classi frequentate (P3, P5)

Priorità media. Stima: una o due giornate. Dopo A.

- Insieme ammesso derivato dalla classe attuale: anno in corso con la sua sezione; anni precedenti a livello di anno; sezioni passate solo se registrate nel profilo (scenario 3); nessun anno futuro.
- Selettore per anno, dal più recente: «Terza · 3A (la tua classe)», «Seconda», «Prima». «3» non compare da solo: è compreso in «3A».
- `ContentVisibilityPolicy::studyListFilters` e `ExerciseAccessPolicy::scopeConstraints` da una coppia a un insieme; repository `classe IN (...)`; sidebar da valore fisso a lista. Le rotte `/studio/{indirizzo}/{classe}/{materia}` esistono già.
- Verifiche escluse dall'archivio degli anni passati per default; interruttore sul singolo contenuto «visibile anche dopo l'anno».
- Scenario 3: per gli anni passati valgono gli incarichi dell'anno guardato; al cambio di classe il sistema annota la sezione lasciata nello storico del profilo.
- Scenari 1 e 2: l'archivio resta dentro il docente della credenziale.

### E. Documentazione, a ogni passo

- ADR: nuovo ADR-033 «classi frequentate e portachiavi di credenziali», oppure estensione di ADR-032 per A e B.
- Wiki: registro del debito (P1 come voce), pagina del dominio auth per credenziali e grant, pagina admin per la matrice di B.
- Pacchetto DPO §4: una frase su credenziali combinabili e archivio degli anni passati. Solo dopo il riscontro del DPO, non prima.
- Informativa: nessun dato nuovo per A, B, D; per C il cookie «ricorda» va elencato fra i cookie tecnici.

## 4. Sequenza

| Passo | Intervento | Stima | Condizione |
|-------|------------|-------|------------|
| 1 | A | ½ giornata | subito |
| 2 | B | 1 giornata | subito |
| 3 | C | 1-2 giornate | scenario 2 aperto con colleghi sulle stesse classi |
| 4 | D | 1-2 giornate | dopo A; utile in ogni scenario |

Totale: da tre giornate e mezzo a cinque e mezzo. I passi 1 e 2 non toccano nulla di ciò che è stato dichiarato al DPO.

## 5. Cosa non cambia

Nessun account studente negli scenari 1 e 2; nessun elenco di nomi sul server; titolarità e documenti legali come dichiarati; il pannello incarichi resta per lo scenario 3; l'anno come granularità dei contenuti.

## 6. Decisioni chiuse (5 settembre 2026)

1. **Pannelli inerti: fuori dal menu, con avviso sulla pagina.** Un menu che mostra ciò che non ha effetto insegna cose false. Le pagine restano raggiungibili per URL, con l'avviso «inattivo nello scenario N: si attiva con…» e il link al pannello Deployment, che elenca le aree inattive dello scenario corrente con i link. Segnalibri e vecchi collegamenti continuano a funzionare.
2. **«Ricorda su questo dispositivo»: sì, facoltativo, fino alla scadenza della credenziale.** Casella non spuntata per default, banner sempre visibile con «Esci». Durata allineata alla credenziale (per default 31 agosto): una decisione sola, e a fine anno cade da sé. Il grant si riverifica a ogni richiesta, quindi la revoca del docente vale subito anche sui dispositivi ricordati.
3. **Archivio degli anni passati: verifiche escluse per default.** Il danno di una verifica che gira fra classi più giovani è certo; il beneficio di rivederla è piccolo. Il docente accende «visibile anche dopo l'anno» sul singolo contenuto.
4. **Scenario 3, anni passati: incarichi dell'anno guardato.** Il materiale di seconda come lo insegna oggi l'Istituto, sezioni nascoste escluse. Nessuno storico dei docenti da conservare, nessuna informazione nuova sullo studente. «Solo i docenti attuali» farebbe sparire le materie con insegnante cambiato; «tutti i docenti dell'Istituto» aprirebbe più del necessario.
5. **Avvio: tutto subito, scenario 1 invariato.** Il rinvio di C e D era di utilità, non di rischio: il codice è consapevole dello scenario e in scenario 1 resta dormiente (portachiavi con una chiave sola, anni frequentati dentro la credenziale dell'autore, pannello con le aree dello scenario 1). Nessun account studente, nessun elenco: il modello dichiarato al DPO non si muove. Ordine: A, B, D, C, un commit per passo, tutti deployabili con lo scenario 1 fermo. Con C l'informativa passa a 2.4 (cookie tecnico «ricorda», modifica non sostanziale). Le prove di cambio scenario si fanno in locale: lo scenario 3 richiede `INSTANCE_ACN_QUALIFIED=true` nel `.env` locale; il ritorno allo scenario 1 dal pannello è bloccato con utenti attivi, quindi si rimuove l'override e si imposta `DEPLOYMENT_SCENARIO=personal` nel `.env`.
