---
tags:
    - documentazione/gdpr
    - scenario/1
date: 2026-09-25
tipo: informativa-utente
status: vigente
versione: 1.3
classification: PUBLIC — esibibile a utenti finali
aliases: ["informativa-personale", "privacy-policy-scenario-1"]
---

# Informativa Privacy — Pantedu, uso personale (Scenario 1)

**Versione:** 1.3

**Ultima revisione:** 2026-09-25

**Prima pubblicazione:** 2026-09-06

> Questa informativa vale quando l'istanza è nello **Scenario 1**: la
> piattaforma è usata dal solo docente che la conduce, per le proprie classi.
> **Nessuna iscrizione è aperta** e non esiste alcun account studente. Le
> altre configurazioni hanno la propria informativa: lo Scenario 2 (colleghi
> docenti che si iscrivono) e lo Scenario 3 (adozione da parte di un
> Istituto, che ne è Titolare). Quale sia lo scenario attivo è dichiarato nel
> pannello di amministrazione e in fondo a ogni pagina legale, questa compresa.

## 1. Titolare del trattamento

| Campo | Valore |
|-------|--------|
| Nome | {{OPERATORE_NOME}}, docente, che conduce l'istanza per la propria attività didattica |
| Email contatto | `{{OPERATORE_EMAIL}}` |
| Domicilio digitale (PEC) | `<pec operatore>` — recapito per comunicazioni formali e per l'esercizio dei diritti |
| Responsabile della protezione dei dati | Non designato: la designazione non è obbligatoria (Art. 37: persona fisica, nessun monitoraggio su larga scala, nessun dato Art. 9). `{{OPERATORE_EMAIL}}` è il recapito privacy del Titolare, per l'esercizio dei diritti: non è l'indirizzo di un DPO |

L'Istituto in cui il docente insegna **non è Titolare né contitolare**: la
piattaforma non è uno strumento d'Istituto, non è adottata con un suo atto e
non conserva atti formali della scuola (Termini di Servizio, §2.5).

## 2. A chi è rivolta questa informativa

- **Studenti che entrano con la credenziale di classe.** Non hanno un account:
  usano una credenziale creata dal docente, non nominativa, eventualmente
  delimitata a una classe. La sessione non è associata alla persona.
- **Visitatori** delle pagine pubbliche (home, informative, pagine legali).
- **Chi scrive o segnala**: chi scrive ai recapiti indicati in questa
  informativa, o usa il modulo per l'esercizio dei diritti (`/dpo-contact`) o
  quello per segnalare un contenuto (`/segnalazione-contenuti`).

Il docente che conduce l'istanza è al tempo stesso Titolare e unica persona
registrata: gli altri account (amministrazione, prova) sono suoi, e i dati dei
suoi account non sono oggetto di questa informativa.
Non esistono altri docenti iscritti: se l'iscrizione venisse aperta, l'istanza
passerebbe allo Scenario 2 con la relativa informativa.

## 3. Quali dati sono trattati

### 3.1 Studenti con credenziale di classe

**Nessun dato identificativo.** La credenziale contiene l'identità del
docente, un'etichetta e l'eventuale classe; chi la usa non fornisce nome,
email, data di nascita né altro. Sono trattati soltanto:

- l'indirizzo IP e lo User-Agent della connessione, **come impronta**, nei
  registri di audit dell'istanza, insieme a data, ora e operazione; l'indirizzo
  IP resta **in chiaro**, per la sola sicurezza, nel log del filtro di
  sicurezza (30 giorni) e nei contatori del limitatore di frequenza, per al
  massimo circa un giorno (sezione 3.3);
- il numero di ingressi e la data dell'ultimo ingresso **per credenziale**,
  mostrati al docente: un contatore, mai chi;
- il cookie di sessione e, quando il filtro di sicurezza la chiede, il cookie
  della verifica anti-bot (sezione 6);
- se lo studente sceglie «Ricorda su questo dispositivo», un cookie con i
  riferimenti firmati alle credenziali usate (sezione 6): nessun dato
  personale, nessuna password.

Nelle pagine con le mappe, il fornitore del visualizzatore riceve l'indirizzo
IP (sezione 6).

Nessun consenso del minore né consenso genitoriale è necessario, perché non
è trattato alcun dato che identifichi lo studente (Art. 8 GDPR e art.
2-quinquies del Codice non si applicano).

### 3.2 Visitatori e contatti

- Pagine pubbliche: gli stessi registri di sicurezza della sezione 3.3.
- Email ai recapiti: indirizzo del mittente e contenuto del messaggio,
  conservati per il tempo necessario a rispondere e comunque non oltre un
  anno.
- Modulo per l'esercizio dei diritti (`/dpo-contact`): nome, email, oggetto,
  messaggio e se la richiesta riguarda un minore; l'indirizzo IP e lo
  User-Agent solo come impronta. La ricevuta e la notifica restano anche in un
  registro della posta inviata sul server, con destinatario, oggetto e testo:
  il file ruota quando supera 5 MB, se ne tengono cinque copie, e non ha un
  termine in giorni.
- Modulo per segnalare un contenuto (`/segnalazione-contenuti`): nome, email,
  ruolo di chi segnala, il contenuto segnalato, la descrizione e l'indirizzo
  IP della connessione come **impronta con chiave** (sezione 3.3). Fino al 24
  settembre 2026 l'indirizzo si conservava in chiaro e compariva nell'email di
  notifica; le segnalazioni scritte prima l'hanno perso.

Le richieste e le segnalazioni si cancellano da sole alla fine del loro
termine (sezione 5).

### 3.3 Dati di accesso (IP, User-Agent)

Nei registri di audit l'indirizzo IP è conservato come **impronta con
chiave**: un HMAC calcolato con una chiave derivata da un segreto del server.
L'indirizzo non compare, ma chi ha la chiave può verificare se un indirizzo
noto è nel registro: è un dato pseudonimo, non anonimo, e resta dato
personale. Le righe scritte prima del 24 settembre 2026 hanno un'impronta
senza chiave (SHA-256), che per un indirizzo IPv4 si ricostruisce provando
tutti i valori possibili: si cancellano con il termine ordinario del registro.
Anche lo User-Agent è un'impronta, e resta dato personale: i browser in uso
sono pochi, e l'impronta si ritrova confrontandola con i più comuni. Con
loro si registrano data e ora e operazione.

Restano in chiaro, per la sola sicurezza e a conservazione breve: nel log del
filtro di sicurezza (WAF, 30 giorni), nei contatori del limitatore di
frequenza (ogni notte si cancellano quelli più vecchi di un'ora) e nel
contatore dei tentativi di accesso falliti (ogni notte si cancellano quelli
più vecchi di un giorno). Dopo troppi tentativi il filtro
blocca per qualche tempo un indirizzo o un nome utente: gli elenchi dei
blocchi oggi non si ripuliscono di quelli scaduti. Le sessioni attive sono
file sul server e non contengono l'indirizzo IP.

**Verifica anti-bot.** Quando il filtro di sicurezza la chiede, la pagina
legge alcune capacità del browser — se lo schermo, la grafica e l'audio
rispondono, quanti processori dichiara, se il mouse si è mosso — per
distinguere una persona da un programma automatico. Dal 22 settembre 2026 non
legge più i valori che identificano una macchina (l'impronta del canvas, il
modello della scheda video, l'impronta audio, l'elenco dei plugin, il fuso
orario), e nessuna impronta del dispositivo viene conservata.

**Correzioni del 24 settembre 2026.** La pulizia giornaliera dei contatori del
limitatore, dichiarata in questa informativa dalla prima versione, non girava:
la tabella conservava indirizzi IP dal 19 aprile. È partita la notte del 24
settembre, e il primo giro ha cancellato 6.536 righe. L'impronta dell'indirizzo
IP nei registri di audit era calcolata senza chiave, e questa informativa la
diceva «non ricostruibile»: dal 24 settembre è un'impronta con chiave.

**Base giuridica**: Art. 6(1)(f) GDPR — interesse legittimo alla sicurezza
dell'istanza (considerando 49).

### 3.4 Contenuti didattici

I contenuti pubblicati (esercizi, verifiche, mappe, documenti) sono del
docente Titolare: riferimenti bibliografici e svolgimenti propri, mai tracce
o soluzioni dei libri di testo. Non contengono dati personali di studenti;
i marcatori per le versioni adattate (DSA/DIS) descrivono l'esercizio, non
una persona, e non sono dati sanitari ex art. 9.

## 4. Finalità e basi giuridiche

| Finalità | Base giuridica |
|----------|----------------|
| Consultazione dei materiali del docente da parte delle sue classi | Art. 6(1)(f) GDPR — interesse legittimo del docente alla propria attività didattica; nessun dato identificativo dello studente |
| Sicurezza dell'istanza: registri, prevenzione degli abusi, rate-limiting | Art. 6(1)(f) GDPR — interesse legittimo (considerando 49) |
| Risposta a chi scrive ai recapiti o dai moduli | Art. 6(1)(c) GDPR per le richieste degli interessati (artt. 12-22) e le segnalazioni di contenuti illeciti (art. 16 D.Lgs. 70/2003); per gli altri messaggi Art. 6(1)(b) — misure precontrattuali su richiesta dell'interessato — o 6(1)(f) |

Trattamenti esclusi: profilazione, decisioni automatizzate ex art. 22,
pubblicità, cessione a terzi, geolocalizzazione, valutazione degli studenti.

## 5. Tempi di conservazione

| Dato | Conservazione |
|------|---------------|
| Registro delle operazioni (sessioni con credenziale: anonime) | 2 anni |
| Log del filtro di sicurezza (WAF, IP in chiaro) | 30 giorni, con la pulizia di ogni notte |
| Contatori del limitatore di frequenza (IP in chiaro) | un'ora; cancellati con la pulizia di ogni notte, restano al massimo circa un giorno |
| Tentativi di accesso falliti (IP e nome utente in chiaro) | un giorno; cancellati con la pulizia di ogni notte, restano al massimo circa due giorni |
| Contatori per credenziale (ingressi, ultimo ingresso) | vita della credenziale; per default scade il 31 agosto |
| Email ai recapiti | fino a un anno |
| Richieste dal modulo per l'esercizio dei diritti | un anno, poi cancellate dalla pulizia di ogni notte |
| Segnalazioni di contenuti (con l'impronta dell'IP, non l'indirizzo) | cinque anni, come dice la procedura di rimozione, poi cancellate dalla pulizia di ogni notte |
| Bozze di compilazione dei modelli salvate sul server | **15 giorni** dall'ultima fra lo scaricamento del documento e l'ultima modifica; e comunque il **31 agosto**, per quelle ferme dal 1° giugno precedente. Avviso di regola sette giorni prima; se l'avviso non è partito in tempo, la bozza si cancella non prima di tre giorni dopo l'avviso. Ogni modifica fa ripartire il conto; si cancella la compilazione, non il modello |
| Copie di sicurezza cifrate | rotazione a livelli, al massimo **un anno** |
| Copie del database fatte prima di ogni aggiornamento del software, sul server, non cifrate | le ultime cinque |

## 6. Cookie

Solo cookie tecnici, necessari al servizio richiesto: nessun banner di consenso.

- **Sessione** (sempre): cookie di sessione, token CSRF. Base: Art. 6(1)(f),
  funzionamento del servizio richiesto.
- **Portachiavi di classe** (facoltativo, solo se lo studente spunta «Ricorda
  su questo dispositivo»): cookie `fm_keychain` con i riferimenti firmati
  alle credenziali di classe scelte; nessun dato personale, nessuna
  password; dura fino alla scadenza della credenziale (per default il 31
  agosto) e si cancella con «Esci da tutte». Cookie tecnico attivato da una
  scelta esplicita.

- **Verifica di sicurezza** (quando il filtro la richiede): cookie
  `waf_session`, che porta la prova di aver superato il controllo anti-bot.
  Non è leggibile da JavaScript (`HttpOnly`), viaggia solo su HTTPS, dura
  un'ora e non serve a riconoscerti fra una visita e l'altra. Contiene
  l'indirizzo da cui il controllo è stato superato, perché quel permesso non
  possa essere riusato da un altro. È tecnico: senza, la pagina che hai
  chiesto non si può servire. Base: Art. 6(1)(f) — sicurezza.

Nessun cookie di analytics o di marketing.

**diagrams.net nelle pagine con le mappe.** Le mappe si vedono con il visualizzatore di diagrams.net (JGraph Ltd, Regno Unito), che il browser scarica dai loro server: ricevono l'indirizzo IP, come per ogni risorsa web. Il contenuto della mappa non viaggia con la richiesta: sta nel frammento dell'indirizzo, che il browser non invia, o passa al riquadro dalla pagina. Misurato il 15 settembre 2026: non impostano cookie leggibili, non caricano risorse da altri domini e salvano nel browser solo la propria configurazione (`.drawio-config`). L'editor con cui il docente crea e modifica le mappe è servito dalla piattaforma stessa, non da JGraph; il collegamento «Modifica copia» apre app.diagrams.net in un'altra finestra, solo se lo scegli.

## 7. Diritti dell'interessato (Art. 15-22 GDPR)

Le richieste si inviano a `{{OPERATORE_EMAIL}}`, alla PEC in sezione 1 o dal
modulo `/dpo-contact`: non serve un nome utente, e per un minore può scrivere
un genitore. Degli studenti non è conservato alcun dato identificativo: se
chi scrive indica da quale indirizzo IP e in quale momento si è collegato, il
Titolare cerca le righe nei registri che lo conservano (Art. 11 §2); in ogni
caso può fornire informazioni sul funzionamento e cancellare, su richiesta, i
contatti ricevuti. Il docente che ha dato la credenziale può disattivarla in
qualunque momento. Risposta entro 30 giorni. Reclamo: Garante per la
protezione dei dati personali ([garanteprivacy.it](https://www.garanteprivacy.it)).

## 8. Sicurezza, fornitori, intelligenza artificiale

Misure tecniche e organizzative (Art. 32): cifratura in transito (HTTPS,
HSTS) e, per una parte dei contenuti del docente, a riposo; registri di audit
append-only con impronta giornaliera, verifica in due passaggi per
l'amministrazione, backup cifrati. Dettagli su `/security`.

Fornitori di cui il Titolare si avvale (hosting in Germania, protezione di
bordo, backup cifrati prima dell'invio, posta di servizio): elencati con le
basi dei trasferimenti nel Registro delle attività di trattamento ex art. 30
(Sezione D), che prevale in caso di divergenza. Su richiesta
dell'amministratore, la reputazione di un singolo indirizzo IP si può chiedere
a CrowdSec (Francia); le risposte si conservano sul server con l'indirizzo,
oggi senza un termine applicato.

La funzione di intelligenza artificiale (PDF-Import) è riservata al docente,
disattivata per impostazione predefinita e non riceve dati di studenti:
inquadramento in [assessment AI Act](/legal/ai-act). Il codice
dell'applicazione è pubblico (EUPL-1.2) su
[github.com/vittop89/pantedu](https://github.com/vittop89/pantedu): dalla copia
pubblica restano esclusi alcuni documenti operativi, e alcuni nomi reali vi
sono sostituiti da segnaposto.

## 9. Documenti di riferimento e modifiche

- Termini di Servizio: `/legal/tos` · Acceptable Use Policy: `/legal/aup`
- Procedura Notice & Takedown: `/legal/takedown-procedure`
- Misure di sicurezza: `/security`

Questa informativa ha versione **1.3** (25 settembre 2026: dichiarati il
cookie della verifica di sicurezza `waf_session`, la verifica anti-bot, i
moduli per l'esercizio dei diritti e per le segnalazioni, con i loro termini
(un anno e cinque anni) e l'IP delle segnalazioni ridotto a impronta, la
consultazione di CrowdSec e le copie del database prima degli aggiornamenti;
corrette
l'impronta dell'IP, che era senza chiave e dal 24 settembre ne ha una, la
pulizia dei contatori del limitatore, dichiarata e partita solo il 24
settembre, le sessioni, che non contengono l'IP, e il preavviso delle bozze;
detto che l'editor delle mappe non viene da JGraph; §3, §5, §6, §7, §8). La
versione **1.2** (22 settembre 2026) ha tolto la conservazione a tempo
indefinito delle bozze di compilazione dei modelli: 15 giorni dopo lo
scaricamento del documento, e comunque a fine anno scolastico se restano
ferme (§5, ADR-046). La versione **1.1** (15 settembre 2026) ha tolto il
banner dei cookie e le categorie facoltative, che non bloccavano niente o non
esistevano, e ha dichiarato diagrams.net nelle pagine con le mappe. La
versione **1.0** (6 settembre 2026) è la prima pubblicazione, separata
dall'informativa dello Scenario 2 che fino ad allora copriva entrambi i casi.
La versione vigente è sempre quella pubblicata su
`/privacy/informativa` nell'istanza in Scenario 1.
