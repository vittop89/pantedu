---
title: "Notice & Takedown — Procedura Operativa"
subtitle: "Safe harbor giuridico D.Lgs. 70/2003 art. 16 (Direttiva 2000/31/CE)"
version: "1.3"
date: "25 settembre 2026"
mainfont: "Calibri"
fontsize: 10pt
geometry: "margin=2cm"
---

# Notice & Takedown Procedure

**Versione**: 1.3 · **Data**: 25 settembre 2026
**Applicativo**: pantedu.eu · **Operatore tecnico**: {{OPERATORE_NOME}}

> **Stato operativo (2026-09-25)**: procedura attiva.
> Componenti in produzione:
>
> - Form pubblico segnalazione: [`/segnalazione-contenuti`](https://pantedu.eu/segnalazione-contenuti) (rate-limited 3/h/IP)
> - Coda admin: [`/admin/takedown`](https://pantedu.eu/admin/takedown) (super-admin only)
> - Email contatto: `{{OPERATORE_EMAIL}}` (instradata alla casella dell'operatore)
> - Tabella DB: `takedown_requests` (migration 057 applicata)
> - Service: `App\Services\Gdpr\TakedownRequestService`
> - Notifica all'autore del contenuto (Fase 4): parte da sola quando si
>   registra l'azione nella coda, **se** la segnalazione è collegata
>   all'autore (`uploader_user_id`). `AdminTakedownController::notifyUploader()`
>   invia il template §5.2 via Resend e marca `notified_uploader` **solo a
>   invio riuscito**.
>
> Il modulo pubblico non collega la segnalazione all'autore, e il pannello
> nemmeno: `uploader_user_id` va valorizzato a mano nel database, dopo aver
> identificato l'autore. Finché è vuoto la notifica non parte. Non parte
> nemmeno, per scelta, con l'azione `forwarded_authority` (avvisare l'autore
> può compromettere l'indagine), con l'utente cancellato, o senza mittente
> (`APP_MAIL_FROM`) o casella delle segnalazioni configurati. In tutti questi
> casi `notified_uploader` resta a 0 e la pagina di dettaglio avvisa che la
> Fase 4 va completata a mano da `abuse@`.
>
> Versione 1.3 (25 settembre 2026): la Fase 3 dice che registrare
> un'azione non la esegue, e come si rimuove un contenuto e si sospende un
> account; le copie di sicurezza durano al più un anno, non 24 mesi; detto
> quali dati di chi segnala si conservano e per quanto (dell'IP solo
> un'impronta, dal 24 settembre; cinque anni, con la pulizia automatica); la cifratura è
> descritta come nei Termini, e il frazionamento Shamir protegge solo la
> copia di sicurezza della chiave; tolto l'avviso alla scuola del docente
> quando un contenuto contiene il dato di uno studente (§6.3).

---

## Scope

Procedura operativa per la **gestione di segnalazioni** di contenuti
illeciti caricati sull'Applicativo da utenti terzi (i docenti: gli studenti
non caricano nulla).

L'aderenza a questa procedura attiva il **safe harbor giuridico ex
D.Lgs. 70/2003 art. 16** (recepimento Direttiva 2000/31/CE), che esonera
l'operatore tecnico dalla responsabilità per i contenuti immessi da terzi
purché:

1. Non abbia conoscenza effettiva dell'illiceità;
2. Su richiesta motivata di autorità competenti o aventi diritto rimuova
   tempestivamente il contenuto contestato;
3. Non agisca attivamente sui contenuti (no editing/curating).

---

## 1. Canali di ricezione segnalazioni

### 1.1 Email dedicata

**Indirizzo**: `{{OPERATORE_EMAIL}}` (RFC 2142, che indica `abuse@` come
casella convenzionale per le segnalazioni di abusi).

**Ricezione**: Cloudflare Email Routing, con regola di instradamento su
`{{OPERATORE_EMAIL}}` verso la casella personale dell'operatore. La casella
di destinazione non è pubblicata: chi segnala scrive sempre ad `abuse@`.

```
pantedu.eu.   MX   23  route2.mx.cloudflare.net.
pantedu.eu.   MX   44  route1.mx.cloudflare.net.
pantedu.eu.   MX   91  route3.mx.cloudflare.net.
```

**Invio**: Resend, autenticato sul dominio — SPF su `send.pantedu.eu`
(`include:amazonses.com`) e DKIM su `resend._domainkey.pantedu.eu`. Le mail
generate dall'Applicativo partono da `APP_MAIL_FROM` (casella send-only) con
`Reply-To: {{OPERATORE_EMAIL}}`, così la risposta di chi contesta arriva a una
casella letta. DMARC è a `p=none` con `rua=mailto:{{OPERATORE_EMAIL}}`.

> L'invio **non** passa da `mail()` del VPS: quel percorso non è coperto da
> SPF/DKIM del dominio e verrebbe valutato come non allineato in DMARC.

### 1.2 Form web pubblico

**URL**: `https://pantedu.eu/segnalazione-contenuti`

**Implementazione**: form PHP standalone (no autenticazione richiesta) →
INSERT in tabella `takedown_requests` (vedi migration 057) → invio
notifica email a `{{OPERATORE_EMAIL}}`.

Vedi: `app/Controllers/Public/PublicTakedownController.php`.

### 1.3 PEC / Posta tradizionale

Per atti formali di autorità giudiziaria o Garante Privacy:

- **Domicilio digitale (PEC)**: `<pec operatore>` — è il recapito
  dichiarato nell'[informativa](/privacy/informativa) §1, e vale per ogni
  comunicazione formale.

*Fino al 22 settembre 2026 questa sezione indicava, come recapito postale per
gli atti dell'autorità giudiziaria e del Garante, l'indirizzo dell'Istituto
scolastico in cui l'operatore insegna. Era sbagliato in due modi. Primo:
l'Istituto non è titolare, non è responsabile e non ha alcun ruolo in questo
servizio — indicarlo come recapito per gli atti formali è un fatto che dice il
contrario, e lo dice a chi quel recapito userebbe davvero. Secondo: era
l'indirizzo di un terzo, pubblicato su una pagina aperta a chiunque, senza che
quel terzo ne sapesse nulla.*

---

## 2. Categorie di segnalazione e tempistiche SLA

| Categoria | Esempio segnalante | SLA rimozione |
|-----------|---------------------|----------------|
| Ordine autorità giudiziaria | Procura, magistratura | **Immediato** (max 24h) |
| Provvedimento Garante Privacy | Autorità di controllo | **24 ore** |
| Notice editore per copyright (con prova legittimazione) | Editore con prova proprietà | **48-72 ore** |
| Segnalazione DPO di terza scuola/ente | DPO esterno | **48 ore** |
| Segnalazione privato cittadino — interessato | Persona fisica con prova identità | **72 ore** dopo valutazione |
| Segnalazione genitore studente minorenne | Genitore | **72 ore** dopo verifica legittimazione |
| Segnalazione anonima | Anonimo | **Valutazione caso per caso** (non vincolante senza identità) |

---

## 3. Flusso di gestione segnalazione

### Fase 1 — Ricezione (T0)

- Ricezione email su `{{OPERATORE_EMAIL}}` o submission form
- **Trigger**: dal modulo, notifica email alla casella delle segnalazioni
- **Action**: creazione record in `takedown_requests` (status=`new`)
- **Log**: la richiesta entra nel registro delle operazioni (metodo,
  percorso, esito); i dati della segnalazione stanno in `takedown_requests`

### Fase 2 — Valutazione fondatezza (T0 +24h)

- Verifica identità del segnalante (se richiesta dalla categoria)
- Verifica prova legittimazione (es. titolarità copyright)
- Identificazione del contenuto contestato sul server, dai metadati e, se
  il contenuto è pubblicato, dalla pagina come la vedono gli studenti o il
  pubblico. Nessuna funzione dell'applicativo mostra all'operatore il
  contenuto di un altro docente, e la decifratura dei contenuti cifrati è
  ammessa solo nei casi dei Termini §3(c): la valutazione di una
  segnalazione non è fra questi (§7)
- Identificazione dell'**autore** del contenuto, da annotare a mano in
  `uploader_user_id` (vedi il riquadro di stato)
- **Decisione**:
  - **Fondata** → procedi a Fase 3
  - **Manifestamente infondata** → status=`rejected`, comunicazione al
    segnalante con motivazione
  - **Da approfondire** → status=`under_review`, richiesta info al
    segnalante o all'uploader

### Fase 3 — Azione (entro SLA)

Possibili azioni (`action_taken`). Registrare l'azione nella coda **non la
esegue**: aggiorna lo stato della segnalazione e, se l'autore è collegato,
gli manda subito la comunicazione della Fase 4, che dice «abbiamo rimosso» o
«abbiamo sospeso». Perciò **prima si esegue, poi si registra**.

a. **`removed`**: rimozione del contenuto. Nessuna funzione del pannello
   toglie il contenuto di un altro docente: si fa a mano, da riga di
   comando — `tools/contenuti/elimina.php` per esercizi, mappe e
   laboratori, che prima ne salva una copia; `tools/crypto/delete_verifica.php`
   per le verifiche. Gli strumenti cancellano la riga del contenuto: i file
   sullo storage che lasciano, le versioni precedenti (`content_versions`)
   e la copia salvata vanno cancellati a parte. Il contenuto resta nelle
   copie di sicurezza cifrate fino alla loro rotazione (al più un anno,
   Fase 6). Nessun ripristino senza un ordine contrario dell'autorità. Il
   record in `takedown_requests` mantiene il riferimento al contenuto
   rimosso.

b. **`suspended_user`**: sospensione dell'account dell'autore. Si disattiva
   l'account dal pannello degli utenti; non esiste una sospensione a tempo:
   una sospensione temporanea (7 giorni) si chiude riattivando l'account a
   mano, alla scadenza. Il registro delle operazioni amministrative ne
   conserva la cronologia.

c. **`forwarded_authority`**: in caso di reato, inoltro alle
   autorità competenti (Polizia Postale, Garante Privacy). Contenuto
   conservato per la cooperazione con le indagini, NON rimosso.

d. **`dismissed`**: rifiuto della segnalazione con motivazione (es.
   uso lecito ex art. 70 L. 633/1941 o no violazione).

### Fase 4 — Notifica uploader (contestuale all'azione)

L'utente uploader viene notificato via email dell'azione intrapresa:
- Riferimento al contenuto contestato
- Motivazione della rimozione/sospensione
- Diritto di contestazione entro 14 giorni (rispondendo ad `abuse@`)
- Cooperazione richiesta in caso di indagini

L'invio è **automatico** e parte dalla stessa POST che registra l'azione
(`/admin/takedown/{id}/action`), non a T_action + 7gg: il ritardo era una
conseguenza del passo manuale, non un requisito. Il corpo è il template
§5.2, con `Reply-To: {{OPERATORE_EMAIL}}`. Parte solo se la segnalazione è
collegata all'autore (riquadro di stato), e dice che l'azione è fatta: per
questo si registra l'azione dopo averla eseguita (Fase 3).

Aggiornamento DB a invio riuscito: `notified_uploader=1`, `notified_at=NOW()`.
Se l'invio fallisce o non è dovuto (vedi riquadro di stato in testa al
documento) i flag restano a 0 — sono la prova documentale che la Fase 4
è ancora da fare, quindi non vanno marcati "a fiducia".

### Fase 5 — Comunicazione segnalante (T_action + 7gg)

Comunicazione al segnalante dell'esito:
- Conferma rimozione (con timestamp) o motivazione del rifiuto
- Riferimento procedurale per eventuali ulteriori azioni
- Disclaimer: l'operatore tecnico ha agito in cooperazione ex art. 16
  D.Lgs. 70/2003

### Fase 6 — Archiviazione (T_action + 30gg)

- Record `takedown_requests` aggiornato a `status=closed`
- **Dati di chi segnala**: nome ed email (facoltativi), ruolo e
  descrizione indicati nel modulo, e un'impronta con chiave dell'indirizzo
  IP da cui arriva la segnalazione (non l'indirizzo, e non nella notifica
  email). Servono a gestirla e a ricostruire, dopo, le decisioni prese
  (registro dei trattamenti, B.10). Fino al 24 settembre 2026 l'indirizzo
  si conservava in chiaro e arrivava anche nella notifica: le righe già
  scritte sono state ridotte a impronta
- La segnalazione resta in `takedown_requests` per **cinque anni** dalla
  ricezione, come i registri che servono a ricostruire gli abusi; la
  cancellazione è automatica, con la pulizia di ogni notte
- Il registro delle operazioni amministrative conserva la storia delle
  azioni
- Le copie di sicurezza cifrate ruotano a livelli: una copia sopravvive al
  più **un anno** (Informativa privacy, §5)

---

## 4. Cooperazione con autorità

In caso di richiesta formale da:
- **Autorità giudiziaria** (Procura, Tribunale): cooperazione piena
  ex art. 132 D.Lgs. 196/2003, fornitura dei metadati e, su provvedimento,
  dei contenuti (vedi sotto)
- **Garante Privacy**: cooperazione ex art. 58 GDPR + 154 D.Lgs. 196/2003
- **Polizia Postale**: cooperazione ex art. 7-bis L. 269/1998

**Documenti consegnabili**:
- Registri filtrati per user_id / intervallo di date
- Metadati dei contenuti (autore, tipo, titolo, date di creazione e
  modifica, visibilità)
- Verbale di accettazione dei Termini (data, versioni e, dove registrati,
  IP e User-Agent)
- Eventuale corrispondenza email con l'utente

**Non consegnabile senza ordine**:
- Contenuti decifrati. L'operatore tecnico è tecnicamente in grado di
  decifrarli — custodisce la master key con cui si aprono le KEK dei
  docenti — ma non lo fa in assenza di un provvedimento motivato: la
  procedura di accesso amministrativo è ammessa nei soli casi dei Termini
  §3(c) e ogni attivazione lascia una riga nel registro append-only
  `crypto_custody_events`
- Contenuti non cifrati (il testo di esercizi e laboratori, la
  composizione delle verifiche: Termini §3(c)): anche questi si consegnano
  solo su provvedimento
- Copie di sicurezza (cifrate lato client)

---

## 5. Templates email

### 5.1 Conferma ricezione segnalazione (al segnalante)

```
Oggetto: [pantedu abuse-001234] Segnalazione ricevuta

Gentile [Nome],

confermiamo la ricezione della Sua segnalazione del [data].

La Sua segnalazione è stata registrata con ID #1234 e classificata
come [tipologia]. La valuteremo entro le tempistiche SLA stabilite
(vedi <https://pantedu.eu/legal/takedown-procedure>).

Procederemo a ricontattarLa entro [SLA] giorni con l'esito.

Cordiali saluti,
{{OPERATORE_NOME}} — Operatore tecnico pantedu
{{OPERATORE_EMAIL}}
```

### 5.2 Notifica rimozione (all'uploader)

```
Oggetto: [pantedu] Contenuto rimosso a seguito di segnalazione

Gentile [Nome utente],

a seguito di segnalazione ricevuta in data [data] (ID #1234, tipologia
[tipo]), valutata fondata, abbiamo proceduto alla rimozione del
contenuto identificato come [riferimento].

Motivazione della rimozione: [motivazione sintetica].

Riferimenti normativi: [art. legge/regolamento].

Ti ricordiamo che hai diritto di contestare l'azione entro 14 giorni
rispondendo a questa email con motivazione e prove a sostegno.

Continueremo ad osservare il rispetto dei Termini di Servizio e
dell'AUP nei tuoi futuri utilizzi dell'Applicativo.

Cordiali saluti,
{{OPERATORE_NOME}} — Operatore tecnico pantedu
{{OPERATORE_EMAIL}}
```

### 5.3 Rifiuto segnalazione (al segnalante)

```
Oggetto: [pantedu abuse-001234] Segnalazione non accolta

Gentile [Nome],

abbiamo esaminato la Sua segnalazione del [data] (ID #1234) e, sulla
base delle informazioni disponibili, abbiamo deciso di non procedere
alla rimozione del contenuto contestato.

Motivazione: [motivazione]

In caso di disaccordo, Lei può:
- Inviare ulteriore documentazione a sostegno;
- Rivolgersi all'Autorità Garante Privacy (www.garanteprivacy.it);
- Adire l'autorità giudiziaria competente.

Cordiali saluti,
{{OPERATORE_NOME}} — Operatore tecnico pantedu
```

---

## 6. Audit & Reporting

### 6.1 Audit log per ogni segnalazione

Tabella `takedown_requests` mantiene cronologia completa:
- Ricezione (`submitted_at`)
- Valutazione (`status` transitions)
- Azione (`action_taken` + `actioned_at` + `actioned_by`)
- Notifiche (`notified_uploader`, `notified_at`)

### 6.2 Report annuale aggregato

Generazione report annuale (privacy-friendly, no contenuti) con:
- Numero totale segnalazioni
- Distribuzione per `violation_type`
- Tempi medi di risposta (SLA compliance)
- Numero rimozioni vs rifiuti
- Numero utenti sospesi/espulsi

Pubblicabile in forma aggregata, per trasparenza.

### 6.3 Quando la segnalazione è una possibile violazione di dati

**Chi riceve.** L'operatore, che è il Titolare del trattamento. Non c'è un DPO
designato. Il recapito privacy del titolare è `{{OPERATORE_EMAIL}}`, e il
[modulo](/dpo-contact) ha un oggetto apposta, «Segnalazione data breach».

**Che cosa fa.** Una segnalazione della categoria `gdpr_art9`, o che riguardi
una persona minorenne, **apre l'istruttoria dell'art. 33** e non la coda
ordinaria di questa procedura. Da quel momento decorrono **72 ore**, non i
termini di riscontro all'interessato: il piano è
`docs/privacy/data_breach_runbook.md`. Dalla scheda della segnalazione, nella
coda, il pulsante «Apri incidente (art. 33)» apre l'incidente nel registro
delle violazioni.

**Quando un contenuto contiene il dato di uno studente.** Un docente può aver
scritto in un campo a testo libero un dato che riguarda uno studente, contro
i Termini (§2.4). Della conservazione di quel dato sui propri sistemi risponde
l'operatore, come per ogni contenuto della piattaforma: lo rimuove con le
regole di questa procedura e, nei casi detti qui sopra, apre l'istruttoria
dell'art. 33. Il docente che l'ha inserito riceve la comunicazione della
Fase 4. L'operatore non avvisa la scuola del docente: la piattaforma non ha
rapporti con gli Istituti (Termini §1(b)).

---

## 7. Limiti dell'operatore tecnico

L'operatore tecnico:

- **NON accede** ai contenuti di docenti diversi da se stesso
  nell'esercizio ordinario del servizio: nessuna funzione
  dell'applicativo glieli mostra.

  Va precisato che **non si tratta di una impossibilità tecnica**, per due
  ragioni. Le KEK dei docenti sono conservate avvolte con una chiave
  derivata dalla master key che l'operatore custodisce, quindi la
  decifratura dei contenuti cifrati è possibile attivando la procedura di
  accesso amministrativo. Questa è
  ammessa nei soli casi tassativi dei Termini §3(c) (provvedimento di
  autorità, successione, recupero dell'accesso richiesto dal docente
  stesso), produce una riga nel registro append-only
  `crypto_custody_events` e un avviso email al docente, ed è il presupposto
  stesso della sezione §4: senza di essa non avrebbe senso distinguere fra
  ciò che è consegnabile e ciò che lo è "solo con ordine". E non tutti i
  contenuti sono cifrati: il testo di esercizi e laboratori e la
  composizione delle verifiche stanno sul server senza cifratura per
  docente (Termini §3(c)), e chi amministra il server può leggerli senza
  quella procedura.

  La cifratura protegge quindi i contenuti cifrati da terzi — altri
  docenti, chi ottenga una copia del database, il fornitore di hosting — e
  non dall'operatore. Il frazionamento Shamir *k*-su-*n* protegge la copia
  di sicurezza della master key, non l'accesso operativo dell'operatore.

- **PUÒ** accedere senza alcuna procedura a:
  - I metadati dei contenuti (autore, tipo, titolo, date, visibilità)
  - I registri (chi-cosa-quando)
  - Statistiche aggregate di uso

In caso di rimozione, l'operatore tecnico procede sulla base dei
**metadati** del contenuto segnalato (identificazione per ID), **senza
decifrare** il contenuto e senza leggerlo dai file del server. La rimozione
è quindi "alla cieca": l'operatore tecnico applica la richiesta basandosi
sulla descrizione fornita dal segnalante, sulla pagina pubblicata se c'è, e
sui metadati verificabili.

---

## 8. Riferimenti normativi

- **D.Lgs. 70/2003** art. 16 (Servizio dell'informazione — responsabilità
  prestatori)
- **Direttiva 2000/31/CE** sul commercio elettronico
- **Direttiva (UE) 2019/790** sul diritto d'autore nel mercato unico digitale
- **L. 633/1941** (Diritto d'autore italiano)
- **GDPR** art. 24, 28, 32, 33
- **D.Lgs. 196/2003** mod. **D.Lgs. 101/2018** (Codice Privacy)
- **DPR 62/2013** Codice di Comportamento dipendenti PA

---

*Versione documento: 1.3 — 25 settembre 2026.*

*Per segnalazioni: {{OPERATORE_EMAIL}}*
