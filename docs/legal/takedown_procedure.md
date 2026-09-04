---
title: "Notice & Takedown — Procedura Operativa"
subtitle: "Safe harbor giuridico D.Lgs. 70/2003 art. 16 (Direttiva 2000/31/CE)"
version: "1.1"
date: "1 settembre 2026"
mainfont: "Calibri"
fontsize: 10pt
geometry: "margin=2cm"
---

# Notice & Takedown Procedure

**Versione**: 1.1 · **Data**: 1 settembre 2026
**Applicativo**: pantedu.eu · **Operatore tecnico**: {{OPERATORE_NOME}}

> **Stato operativo (2026-09-01)**: procedura attiva.
> Componenti in produzione:
>
> - Form pubblico segnalazione: [`/segnalazione-contenuti`](https://pantedu.eu/segnalazione-contenuti) (rate-limited 3/h/IP)
> - Coda admin: [`/admin/takedown`](https://pantedu.eu/admin/takedown) (super-admin only)
> - Email contatto: `{{OPERATORE_EMAIL}}` (instradata alla casella dell'operatore)
> - Tabella DB: `takedown_requests` (migration 057 applicata)
> - Service: `App\Services\Gdpr\TakedownRequestService`
> - Notifica uploader (Fase 4): **automatica** all'azione admin —
>   `AdminTakedownController::notifyUploader()` invia il template §5.2 via
>   Resend e marca `notified_uploader` **solo a invio riuscito**.
>
> La notifica automatica non parte in tre casi, per scelta: azione
> `forwarded_authority` (avvisare l'uploader può compromettere l'indagine),
> `uploader_user_id` non valorizzato o utente cancellato, e `APP_MAIL_FROM`
> non configurata. In tutti e tre `notified_uploader` resta a 0 e la pagina
> di dettaglio avvisa che la Fase 4 va completata a mano da `abuse@`.

---

## Scope

Procedura operativa per la **gestione di segnalazioni** di contenuti
illeciti caricati sull'Applicativo da utenti terzi (docenti o studenti).

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

### 1.1 Email dedicato

**Indirizzo**: `{{OPERATORE_EMAIL}}` (RFC 2142 — obbligo di casella `abuse@`
per chi gestisce un dominio che accetta segnalazioni).

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

Vedi: `app/Controllers/Public/PublicTakedownController.php` (✅ implementato).

### 1.3 PEC / Posta tradizionale

Per atti formali di autorità giudiziaria o Garante Privacy:

- **Email PEC**: TBD (da configurare in caso di Scenario B/C avviato)
- **Posta cartacea**: tramite indirizzo Istituto Liceo Esempio

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
- **Trigger**: notifica push (email + Grafana alert se attivo)
- **Action**: creazione record in `takedown_requests` (status=`new`)
- **Log**: ingresso in audit log con metadata segnalante + contenuto

### Fase 2 — Valutazione fondatezza (T0 +24h)

- Verifica identità del segnalante (se richiesta dalla categoria)
- Verifica prova legittimazione (es. titolarità copyright)
- Identificazione del contenuto contestato sul server (via metadata —
  l'encryption per-teacher impedisce di vedere il contenuto, ma i
  metadata sono accessibili)
- Identificazione dell'**uploader** dal contenuto (FK `uploader_user_id`)
- **Decisione**:
  - **Fondata** → procedi a Fase 3
  - **Manifestamente infondata** → status=`rejected`, comunicazione al
    segnalante con motivazione
  - **Da approfondire** → status=`under_review`, richiesta info al
    segnalante o all'uploader

### Fase 3 — Azione (entro SLA)

Possibili azioni (`action_taken`):

a. **`removed`**: rimozione fisica del contenuto dal server (no
   restore senza ordine autorità contraria). DB record di
   `takedown_requests` mantiene riferimento al contenuto rimosso
   per audit.

b. **`suspended_user`**: sospensione temporanea (7 giorni) o
   permanente dell'account uploader. Audit log mantiene cronologia.

c. **`forwarded_authority`**: in caso di reato, inoltro alle
   autorità competenti (Polizia Postale, Garante Privacy). Contenuto
   conservato cifrato per cooperazione con indagini, NON rimosso.

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
§5.2, con `Reply-To: {{OPERATORE_EMAIL}}`.

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
- Mantenuto in DB per 5 anni (norma generale) per scopi di evidenza
  e audit
- Audit log MariaDB conserva storia delle modifiche
- Backup B2 conserva versioni storiche (max 24 mesi)

---

## 4. Cooperazione con autorità

In caso di richiesta formale da:
- **Autorità giudiziaria** (Procura, Tribunale): cooperazione piena
  ex art. 132 D.Lgs. 196/2003, fornitura di metadata + (se richiesto)
  contenuti cifrati (l'autorità potrà chiedere allo studente/docente la
  chiave per decifrare)
- **Garante Privacy**: cooperazione ex art. 58 GDPR + 154 D.Lgs. 196/2003
- **Polizia Postale**: cooperazione ex art. 7-bis L. 269/1998

**Documenti consegnabili**:
- Audit log filtrato per user_id / timestamp range
- Metadata contenuti (hash, dimensione, MIME, timestamp upload)
- ToS firmati dall'utente (data + IP + User-Agent)
- Eventuale corrispondenza email con l'utente

**Non consegnabile senza ordine**:
- Contenuti decifrati. L'operatore tecnico è tecnicamente in grado di
  decifrarli — custodisce la master key da cui derivano le KEK dei
  docenti — ma non lo fa in assenza di un provvedimento motivato: la
  procedura di accesso amministrativo è ammessa nei soli casi elencati
  al §11-bis dell'Informativa e ogni attivazione lascia una riga
  immutabile in `crypto_custody_events`
- Backup B2 (servirebbero credenziali AWS)

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

Pubblicabile (anonimizzato) come trasparenza ex art. 13 GDPR.

### 6.3 Forwarding al DPO scuola

In casi di violazione GDPR (art. 9, breach minori), forward
**immediato** al DPO [Consulente DPO esterno] (dpo@example.it) +
notifica al Dirigente Scolastico.

---

## 7. Limitazioni nota all'operatore tecnico

L'operatore tecnico, in virtù dell'architettura di envelope encryption
per-teacher KEK:

- **NON accede** ai contenuti decifrati di docenti diversi da se stesso
  nell'esercizio ordinario del servizio: nessuna funzione
  dell'applicativo glieli mostra in chiaro.

  Va precisato che **non si tratta di una impossibilità tecnica**: le
  KEK dei docenti sono derivate dalla master key che l'operatore
  custodisce, quindi la decifratura è possibile attivando la procedura
  di accesso amministrativo. Questa è ammessa nei soli casi tassativi
  del §11-bis dell'Informativa (provvedimento di autorità, successione,
  recupero accesso richiesto dal docente stesso), produce una riga
  immutabile in `crypto_custody_events` ed è il presupposto stesso
  della sezione §4: senza di essa non avrebbe senso distinguere fra ciò
  che è consegnabile e ciò che lo è "solo con ordine".

  La cifratura protegge quindi integralmente da terzi — altri docenti,
  chi ottenga una copia del database, il fornitore di hosting — e non
  dall'operatore. Il frazionamento Shamir *k*-su-*n*, disponibile su
  richiesta, subordina l'accesso al concorso di più custodi.

- **PUÒ** accedere senza alcuna procedura a:
  - Tutti i metadata (hash, dimensione, timestamp, user_id uploader,
    MIME)
  - Audit log MariaDB (chi-cosa-quando)
  - Statistiche aggregate di uso

In caso di rimozione, l'operatore tecnico procede sulla base dei
**metadata** del contenuto segnalato (identificazione per hash o ID
DB), **senza decifrare** il contenuto. La rimozione è quindi
"a-blind" — l'operatore tecnico applica la richiesta di rimozione
basandosi sulla descrizione fornita dal segnalante e sui metadata
verificabili.

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

*Versione documento: 1.1 — 1 settembre 2026.*

*Per segnalazioni: {{OPERATORE_EMAIL}}*
