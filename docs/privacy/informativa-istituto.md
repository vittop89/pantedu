---
tags:
    - documentazione/gdpr
    - scenario/3
date: 2026-09-03
tipo: informativa-utente
status: modello
versione: 1.0
classification: PUBLIC — modello per un'istanza condotta da un Istituto (Scenario 3)
aliases: ["informativa-istituto", "privacy-policy-istituto"]
---

# Informativa Privacy — {{INSTITUTE_LEGAL_NAME}}

**Versione:** 1.0 — modello per lo Scenario 3 (adozione da parte di un Istituto)
**Istanza:** {{APP_URL}}

> Questa informativa è servita quando l'istanza è nello **Scenario 3**: la
> piattaforma è adottata da un Istituto scolastico, che ne è **Titolare del
> trattamento**, e chi conduce l'istanza tratta i dati **per conto
> dell'Istituto** come Responsabile ex art. 28 GDPR. I campi tra parentesi
> graffe sono compilati dalla configurazione dell'istanza. Negli Scenari 1 e 2
> vale invece l'informativa del gestore.
>
> Lo Scenario 3 è ammesso soltanto su un'istanza condotta dall'Istituto, o da
> un fornitore qualificato, su **infrastruttura qualificata ACN** (Regolamento
> cloud ACN, Decreto direttoriale n. 21007/24): le pubbliche amministrazioni
> possono avvalersi solo di infrastrutture e servizi cloud qualificati.

## 1. Titolare, DPO e Responsabile

| Ruolo | Soggetto |
|-------|----------|
| Titolare del trattamento | **{{INSTITUTE_LEGAL_NAME}}** |
| Responsabile della protezione dei dati (DPO) | `{{DPO_CONTACT}}` |
| Responsabile del trattamento (art. 28) | {{INSTANCE_OPERATOR_NAME}}, che conduce l'istanza per conto dell'Istituto in forza dell'Accordo sul trattamento dei dati (DPA), consultabile su `/legal/dpa` |

## 2. A chi è rivolta questa informativa

- **Docenti** dell'Istituto che utilizzano la piattaforma per la propria attività didattica.
- **Studenti** dell'Istituto, anche minorenni, secondo la modalità di raccolta scelta dal Titolare (sezione 3.2).
- **Genitori** o tutori di studenti minori di 14 anni, per il consenso di cui all'art. 8 GDPR e all'art. 2-quinquies del Codice, quando la modalità Completa è attiva.
- **Amministratori tecnici** dell'istanza.

## 3. Quali dati sono trattati

### 3.1 Docenti

- Username, nome, cognome, email; password conservata solo come hash (bcrypt).
- Istituto, indirizzo e classe: delimitano a chi sono visibili i contenuti pubblicati.
- Contenuti didattici prodotti dal docente (esercizi, verifiche, mappe, laboratori, documenti), cifrati a riposo con chiave individuale per docente.
- Marcatori BES/DSA sugli esercizi: descrivono l'esercizio (versione adattata), **non** uno studente. Non sono dati sanitari ex art. 9.

### 3.2 Studenti — modalità scelta dal Titolare

Il Titolare sceglie dal pannello di amministrazione quanti dati raccogliere:

| Modalità | Dati raccolti | Note |
|----------|---------------|------|
| **Completa** | username, nome, cognome, email, data di nascita, istituto, indirizzo, classe | per i minori di 14 anni: email e nome di un genitore, con conferma via link (doppio opt-in) |
| **Ridotta** | username, nome, cognome, email, istituto, indirizzo, classe | nessuna data di nascita, nessun dato del genitore |
| **Anonima** | nessun account studente | accesso con la credenziale del docente, non nominativa: nessun dato personale dello studente |

In ogni modalità gli studenti consultano soltanto i contenuti pubblicati dai docenti delle proprie classi: riferimenti bibliografici e svolgimenti del docente, mai tracce o soluzioni dei libri di testo. Non producono né caricano contenuti.

### 3.3 Dati particolari (art. 9)

**Non sono trattati.** PEI, PDP, certificazioni e diagnosi restano nei sistemi ufficiali dell'Istituto; i Termini di Servizio ne vietano il caricamento. I docenti si impegnano a non inserire dati personali di studenti in alcun campo libero.

### 3.4 Dati di accesso

Per ogni accesso autenticato vengono registrati indirizzo IP e User-Agent **in forma di hash SHA-256**, timestamp e operazione, nei registri di audit dell'istanza. Restano in chiaro, per la sola sicurezza e a conservazione breve, nel log del filtro di sicurezza (90 giorni), nei contatori anti-abuso e nelle sessioni attive, e nel verbale di accettazione dei Termini di Servizio quale prova dell'accettazione.

## 4. Finalità e basi giuridiche

| Finalità | Base giuridica |
|----------|----------------|
| Attività didattica dell'Istituto: distribuzione di materiali alle classi, gestione degli account | Art. 6(1)(e) GDPR — compito di interesse pubblico, con l'art. 2-ter del Codice; funzione istituzionale dell'Istituto |
| Consenso di un genitore per gli studenti minori di 14 anni (solo modalità Completa) | Art. 8 GDPR; art. 2-quinquies del Codice |
| Sicurezza dell'istanza: registri di audit, prevenzione degli abusi, rate-limiting | Art. 6(1)(f) GDPR — interesse legittimo (considerando 49) |
| Conservazione e accountability | Art. 6(1)(c) GDPR — obblighi di legge; art. 5(2) |

Trattamenti esclusi: profilazione, decisioni automatizzate ex art. 22, pubblicità, cessione a terzi, geolocalizzazione.

## 5. Tempi di conservazione

| Dato | Conservazione |
|------|---------------|
| Account docente | durata del rapporto con l'Istituto; anonimizzazione dopo 730 giorni di inattività |
| Account studente | durata del percorso di studi; anonimizzazione dopo 730 giorni di inattività, o cancellazione su richiesta del Titolare |
| Consenso genitoriale | fino a revoca o cessazione dell'account dello studente |
| Registro delle operazioni | 2 anni |
| Registro degli accessi privilegiati | 5 anni |
| Copie di sicurezza cifrate | rotazione a livelli, al massimo due anni |

## 6. Minori

Il consenso del minore è valido in Italia dai 14 anni. Sotto i 14 anni, nella modalità Completa, la registrazione richiede l'email di un genitore o tutore, che riceve un link di conferma; l'account non è attivo finché il genitore non conferma, e il genitore può revocare il consenso in qualsiasi momento. Nelle modalità Ridotta e Anonima non si raccolgono dati del genitore né la data di nascita.

## 7. Diritti degli interessati

Accesso, rettifica, cancellazione, limitazione, portabilità e opposizione (artt. 15-22 GDPR) si esercitano rivolgendosi al DPO dell'Istituto (`{{DPO_CONTACT}}`); per i docenti sono disponibili anche gli strumenti self-service dell'istanza (`/me/*`). Resta il diritto di reclamo al Garante per la protezione dei dati personali.

## 8. Sicurezza

Le misure tecniche e organizzative (art. 32) sono descritte su `/security`: cifratura in transito e a riposo con chiave per docente, controllo degli accessi, registri di audit append-only, verifica in due passaggi, backup cifrati. L'istanza è condotta su infrastruttura qualificata ACN.

## 9. Responsabili e destinatari

Il Responsabile del trattamento è chi conduce l'istanza (sezione 1). I fornitori di infrastruttura e servizi di cui il Responsabile si avvale (hosting, protezione di bordo, backup, posta) sono elencati nell'Allegato 2 del DPA e nel Registro delle attività di trattamento dell'Istituto. Nessun dato è comunicato ad altri destinatari.

## 10. Intelligenza artificiale

L'istanza include una funzione riservata ai docenti (PDF-Import) che invia pagine di libri di testo a un modello di intelligenza artificiale per ricavarne esercizi; è disattivata per impostazione predefinita e non riguarda gli studenti. Inquadramento su `/legal/ai-act`.

## 11. Documenti di riferimento

- Termini di Servizio per i docenti: `/legal/tos`
- Acceptable Use Policy: `/legal/aup`
- Accordo sul trattamento dei dati (DPA, art. 28): `/legal/dpa`
- Procedura Notice & Takedown: `/legal/takedown-procedure`
- Misure di sicurezza: `/security`

## 12. Modifiche

Le modifiche a questa informativa sono comunicate agli utenti registrati con avviso nell'applicativo; la versione vigente è sempre quella pubblicata su `/privacy/informativa`.
