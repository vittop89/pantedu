---
title: "Notice & Takedown — Procedura Operativa"
subtitle: "Safe harbor giuridico D.Lgs. 70/2003 art. 16 (Direttiva 2000/31/CE)"
version: "1.0"
date: "20 maggio 2026"
mainfont: "Calibri"
fontsize: 10pt
geometry: "margin=2cm"
---

# Notice & Takedown Procedure

**Versione**: 1.0 · **Data**: 20 maggio 2026
**Applicativo**: pantedu.eu · **Operatore tecnico**: Vittorio Pantaleo

> **Stato operativo (2026-05-20)**: procedura attiva.
> Componenti in produzione:
>
> - Form pubblico segnalazione: [`/segnalazione-contenuti`](https://beta.pantedu.eu/segnalazione-contenuti) (rate-limited 3/h/IP)
> - Coda admin: [`/admin/takedown`](https://beta.pantedu.eu/admin/takedown) (super-admin only)
> - Email contatto: `abuse@pantedu.eu` (alias Aruba → privacy@pantedu.eu)
> - Tabella DB: `takedown_requests` (migration 057 applicata)
> - Service: `App\Services\Gdpr\TakedownRequestService`
>
> ⚠️ **TODO residuo (ancora aperto, verificato 2026-06-18)**: notifica
> email *automatica* all'uploader quando una segnalazione viene presa in
> carico. Stato reale nel codice: `TakedownRequestService` espone solo
> `markUploaderNotified()` che imposta i flag DB `notified_uploader=1` /
> `notified_at=NOW()` — **non invia alcuna email**. La notifica resta
> quindi un passo manuale via `abuse@`. L'automazione email è da implementare.

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

**Indirizzo**: `abuse@pantedu.eu`

**Configurazione**: alias che forwarda a:
- privacy@pantedu.eu (primario)
- info@pantedu.eu (backup)

Setup DNS:
```
abuse.pantedu.eu.   MX   10  mx.pantedu.eu.
abuse@pantedu.eu    →    forward → privacy@pantedu.eu
```

### 1.2 Form web pubblico

**URL**: `https://beta.pantedu.eu/segnalazione-contenuti`

**Implementazione**: form PHP standalone (no autenticazione richiesta) →
INSERT in tabella `takedown_requests` (vedi migration 057) → invio
notifica email a `abuse@pantedu.eu`.

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

- Ricezione email su `abuse@pantedu.eu` o submission form
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

### Fase 4 — Notifica uploader (T_action + 7gg)

L'utente uploader viene notificato via email dell'azione intrapresa:
- Riferimento al contenuto contestato
- Motivazione della rimozione/sospensione
- Diritto di contestazione entro 14 giorni
- Cooperazione richiesta in caso di indagini

Aggiornamento DB: `notified_uploader=1`, `notified_at=NOW()`.

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
- Contenuti decifrati (l'operatore tecnico non può decifrare per
  envelope encryption)
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
(vedi <https://beta.pantedu.eu/legal/takedown_procedure>).

Procederemo a ricontattarLa entro [SLA] giorni con l'esito.

Cordiali saluti,
Vittorio Pantaleo — Operatore tecnico pantedu
privacy@pantedu.eu
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
Vittorio Pantaleo — Operatore tecnico pantedu
privacy@pantedu.eu
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
Vittorio Pantaleo — Operatore tecnico pantedu
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

- **NON può** accedere ai contenuti decifrati di docenti diversi da
  se stesso
- **PUÒ** accedere a:
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

*Versione documento: 1.0 — 20 maggio 2026.*

*Per segnalazioni: abuse@pantedu.eu*
