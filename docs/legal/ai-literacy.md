---
tags:
    - documentazione/ai-act
    - conformita
    - formazione
date: 2026-08-26
tipo: formazione
status: attivo
versione: 1.0
classification: 📗 PUBLIC
aliases: ["alfabetizzazione-ia", "ai-literacy"]
---

# Alfabetizzazione in materia di IA — art. 4 AI Act

> **Art. 4 del Regolamento (UE) 2024/1689**: fornitori e deployer di sistemi di
> IA adottano misure per assicurare, nella misura del possibile, un livello
> sufficiente di alfabetizzazione in materia di IA del personale e delle altre
> persone che si occupano del funzionamento e dell'uso dei sistemi per loro
> conto.
>
> In vigore dal **2 febbraio 2025**. Si applica **a prescindere** dal fatto che
> le funzioni di IA siano attive: riguarda le persone, non la configurazione.
>
> **Destinatari**: operatore tecnico e docenti che usano PDF-Import.
> Tempo di lettura: cinque minuti. Non serve altro.

---

## 1. Cosa fa l'IA dentro Pantedu

Una sola funzione: **PDF-Import**. Prende le pagine di un libro di testo, le
manda a un modello linguistico e ne ricava esercizi pronti da inserire.

Sei operazioni, di due nature diverse. La distinzione conta, perché cambia
quanto ci si può fidare:

**Trascrive** ciò che è già stampato sulla pagina:

- il testo degli esercizi;
- i numeri dei badge;
- la difficoltà (conta i pallini pieni).

**Inventa** contenuto che sulla pagina non c'è:

- la soluzione, quando il libro non la riporta;
- l'argomento dell'esercizio;
- la traduzione, per i libri in lingua straniera.

Il contenuto della seconda categoria viene marcato come generato da IA — nel
codice sorgente della pagina e con una sigla "IA" accanto all'esercizio.

## 2. Dove sbaglia — e sbaglia davvero

Non è un elenco teorico. Sono gli errori che questi modelli commettono su
questo compito specifico.

| Errore | Come si manifesta | Cosa fare |
|---|---|---|
| **Soluzioni matematiche sbagliate ma plausibili** | Il passaggio è formalmente ordinato, il risultato è errato. È il caso peggiore, perché *sembra* giusto | Rifare il conto. Sempre, su ogni soluzione generata |
| **Conteggio dei pallini** | Confonde i pallini pieni con quelli grigi o vuoti; tende a mettere 3 quando non è sicuro | Controllare la difficoltà a campione contro la pagina |
| **Numeri di esercizio** | Scambia il numero del badge col numero di pagina o con un numero dentro il testo | Verificare la numerazione in revisione |
| **Formule LaTeX** | Delimitatori sbagliati, parentesi non chiuse, indici persi | Compilare l'anteprima prima di inserire |
| **Esercizi inventati** | Raramente produce voci non presenti sulla pagina | Confrontare il conteggio con la pagina |
| **Traduzioni che toccano le formule** | Traduce dentro `\(...\)`, che va lasciato intatto | Rileggere gli esercizi tradotti |

Il denominatore comune: **il modello non sa di non sapere.** Non segnala
l'incertezza, produce sempre una risposta ben formata. Sicurezza di forma e
correttezza di contenuto sono cose diverse.

## 3. La regola operativa

**Nulla di generato viene pubblicato senza che una persona lo abbia riletto.**

Il sistema lo impone già: l'inserimento in archivio passa obbligatoriamente
dallo step di revisione. Ma il passaggio esiste per essere usato, non per
essere cliccato. Chi rivede è responsabile di ciò che pubblica: il modello non
lo è, e la marcatura "generato da IA" non trasferisce la responsabilità.

Questo, oltre che una buona pratica, è il fatto giuridico su cui poggia
l'eccezione dell'art. 50(4), secondo comma. Se la revisione diventa una
formalità, l'eccezione non regge più.

## 4. Cosa non dare in pasto ai modelli

Le pagine vanno a un fornitore esterno — quasi sempre fuori dall'Unione
europea (Stati Uniti), a meno che non si usi Ollama in locale. Quindi:

- **Niente compiti, verifiche svolte o elaborati di studenti.** Mai. PDF-Import
  serve per i libri di testo, non per il lavoro degli alunni.
- **Niente elenchi classe, registri, pagelle, certificazioni.**
- **Attenzione ai frontespizi**: un PDF scansionato può contenere il nome di
  uno studente in cima alla pagina.

Il sistema redige automaticamente codici fiscali e indirizzi email prima
dell'invio. **Non riconosce i nomi propri**: quella difesa è a carico di chi
carica il file.

Sul copyright dei libri di testo vale l'[AUP](/legal/aup): l'uso è quello privato
del docente ex art. 70-bis L. 633/1941, non la redistribuzione.

## 5. Chi ha accesso

| Ruolo | PDF-Import |
|---|---|
| Studente | **No** |
| Docente | Sì |
| Collaboratore, amministratore | Sì |

Gli studenti non hanno accesso ad alcuna funzione di IA. Vedono soltanto il
contenuto che un docente ha rivisto e pubblicato — marcato, quando è generato.

## 6. Se qualcosa va storto

| Situazione | Cosa fare |
|---|---|
| Soluzione sbagliata già pubblicata | Correggerla o ritirare il contenuto; se è arrivata agli studenti, dirlo |
| Dati personali finiti in un PDF inviato | Segnalare subito: è un potenziale data breach, vedi `docs/privacy/data_breach_runbook.md` |
| Il modello risponde in modo anomalo o fuori tema | Possibile prompt injection dal PDF. Interrompere la sessione e segnalare |
| Dubbi sulla marcatura o sull'inquadramento normativo | [`ai-act-assessment.md`](/legal/ai-act) |

Contatto: `{{OPERATORE_EMAIL}}`.

## 7. In tre righe

1. L'IA trascrive bene, **inventa male**: la soluzione generata va sempre
   ricontrollata.
2. **Solo libri di testo.** Nessun dato di studenti va inviato a un modello.
3. Chi rivede risponde di ciò che pubblica. La marcatura informa, non discolpa.

---

## Riesame

Da rileggere a ogni nuova funzione di IA e comunque una volta l'anno.

| Data | Versione | Nota |
|---|---|---|
| 2026-08-26 | 1.0 | Prima stesura — art. 4 AI Act |
