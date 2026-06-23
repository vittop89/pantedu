---
title: "DPA — Data Processing Agreement Template"
subtitle: "Accordo Titolare ↔ Responsabile esterno ex art. 28 GDPR"
version: "1.0"
date: "20 maggio 2026"
mainfont: "Calibri"
fontsize: 10pt
geometry: "margin=2cm"
---

# Data Processing Agreement (DPA) — Template

**Versione**: 1.0 · **Data**: 20 maggio 2026
**Tipo documento**: Template contrattuale per Scenario B/C multi-tenant

> **Stato (2026-05-20)**: documento **template non sottoscritto**.
> L'app pantedu è oggi in Scenario A (singolo docente operatore =
> Vittorio Pantaleo, anche utente unico). La sottoscrizione formale del
> DPA avviene quando si estende a Scenario B/C (più docenti / adozione
> istituzionale). Le misure di sicurezza descritte nell'Allegato 2 sono
> già implementate in produzione (cifratura per-teacher, audit log,
> WAF + geo-block, Lynis hardening, Loki centralized logging) — vedi
> [docs/dpo/executive_summary.md](../dpo/executive_summary.md) per il
> dettaglio tecnico aggiornato.

---

## Premessa

Questo è un **template** di contratto Data Processing Agreement (DPA)
ex **art. 28 GDPR** da personalizzare e firmare tra:

- **Titolare del trattamento**: {{INSTITUTE_LEGAL_NAME}}
- **Responsabile esterno del trattamento**: Vittorio Pantaleo (in qualità
  di operatore tecnico dell'applicativo pantedu) — contatto privacy:
  {{DPO_CONTACT}}

L'accordo va sottoscritto **prima** dell'attivazione di Scenario B
(estensione ad altri docenti) o Scenario C (adozione istituzionale).

---

## Articolo 1 — Definizioni e ambito

### 1.1 Definizioni

Ai sensi del Regolamento UE 2016/679 (GDPR) e del D.Lgs. 196/2003 mod.
101/2018:

- **Titolare**: {{INSTITUTE_LEGAL_NAME}}, rappresentato dal Dirigente
  Scolastico pro tempore.
- **Responsabile**: Vittorio Pantaleo (CF __________), in qualità di
  docente in servizio presso l'Istituto e operatore tecnico
  dell'applicativo pantedu.
- **Interessati**: studenti dell'Istituto + docenti Autorizzati ex art.
  29 GDPR utilizzatori dell'Applicativo.
- **Applicativo**: pantedu.eu, infrastruttura tecnica gestita dal
  Responsabile per finalità didattiche istituzionali.
- **Sub-Responsabili**: Hetzner Online GmbH (hosting infrastruttura) e
  Backblaze B2 (storage backup offsite).

### 1.2 Ambito

Il presente DPA regola il trattamento di dati personali effettuato dal
Responsabile per conto del Titolare attraverso l'Applicativo, nelle
finalità istituzionali didattiche previste.

---

## Articolo 2 — Natura, oggetto e finalità del trattamento

### 2.1 Natura

Il Responsabile eroga servizio tecnico di gestione dell'Applicativo
pantedu, comprendente:
- Hosting e gestione VPS infrastruttura
- Manutenzione applicativa
- Backup periodici cifrati
- Monitoring e incident response
- Cooperazione con DPO su procedure Notice & Takedown

### 2.2 Finalità

I dati sono trattati esclusivamente per le seguenti finalità
istituzionali coerenti con la **missione educativa dell'Istituto**:

a. Erogazione di esercizi e attività didattiche;
b. Creazione e consultazione di mappe concettuali (mappe degli studenti
   salvate su Drive personale, non sul server);
c. Produzione di verifiche in formato PDF da parte del docente;
d. Produzione di risorse documentali del docente (Risdoc) — esportate
   come PDF, non memorizzate sul server;
e. (Funzionalità futura) Upload da parte degli studenti di foto/PDF
   svolgimenti di esercizi.

### 2.3 Categorie di dati

Vedi Allegato 1 — Categorie di dati trattati e destinatari (data
mapping).

### 2.4 Durata

Il trattamento ha durata pari alla durata dell'utilizzo dell'Applicativo
da parte dell'Istituto, salvo recesso anticipato (vedi Articolo 12).

---

## Articolo 3 — Obblighi del Responsabile

Il Responsabile si impegna a:

a. **Trattare i dati personali esclusivamente su istruzione documentata
   del Titolare**, salvo che il diritto dell'Unione o dello Stato
   membro richieda diversamente (art. 28.3 GDPR);

b. **Garantire la riservatezza** dei dati trattati, anche dopo la
   cessazione del rapporto contrattuale, e imporre tale obbligo al
   personale (autorizzati);

c. **Adottare misure tecniche e organizzative adeguate** ex art. 32
   GDPR, descritte nell'Allegato 2 (Misure di sicurezza);

d. **Cooperare con il Titolare** per consentire l'esercizio dei diritti
   degli interessati ex artt. 15-22 GDPR;

e. **Assistere il Titolare** nell'adempimento degli obblighi di cui agli
   artt. 32-36 GDPR (sicurezza, data breach notification, DPIA);

f. **Notificare al Titolare** ogni violazione di dati personali (data
   breach) di cui venga a conoscenza **entro 24 ore** dall'evidenza,
   per consentire al Titolare di notificare il Garante entro 72 ore
   ex art. 33 GDPR;

g. **Mettere a disposizione del Titolare** tutte le informazioni
   necessarie per dimostrare la conformità agli obblighi GDPR, e
   consentire e contribuire ad attività di audit;

h. **Rispettare la disciplina sui sub-Responsabili** (Articolo 7);

i. **Restituire o cancellare** tutti i dati personali al termine della
   prestazione (vedi Articolo 12).

---

## Articolo 4 — Audit e cooperazione

### 4.1 Audit

Il Titolare può richiedere, con preavviso minimo di **30 giorni** e
non più di **una volta all'anno** (salvo violazioni accertate), audit
da parte di proprio incaricato sulla conformità del trattamento da
parte del Responsabile.

L'audit può essere svolto:
- Per consultazione di documentazione (audit log, configurazioni,
  procedure)
- Per verifica delle misure tecniche di sicurezza (test
  configurazioni, scan vulnerabilità con preavviso)
- Per intervista con il Responsabile

Costi dell'audit a carico del Titolare salvo riscontro di
non-conformità grave (in tal caso a carico del Responsabile).

### 4.2 Fornitura log

Il Responsabile fornisce al Titolare, **su richiesta motivata**:

- Audit log filtrato per user_id o timestamp range (entro 7 giorni
  lavorativi)
- Statistiche di utilizzo aggregate (entro 14 giorni)
- Documentazione delle misure di sicurezza adottate (entro 14 giorni)
- Cronologia takedown e azioni intraprese (entro 7 giorni)

---

## Articolo 5 — Responsabilità per contenuti dei docenti Autorizzati

### 5.1 Chain of responsibility

Le Parti convengono espressamente che:

a. **Titolare (Istituto)**: nomina i docenti come Autorizzati ex art.
   29 GDPR, è responsabile della vigilanza sui propri Autorizzati,
   eroga formazione GDPR ai docenti, attua sanzioni disciplinari per
   violazioni.

b. **Responsabile (Vittorio Pantaleo)**: responsabile delle misure
   tecniche/organizzative infrastrutturali ex art. 32 GDPR; **NON è
   responsabile dei contenuti caricati dai docenti Autorizzati**, in
   forza dell'architettura tecnica di envelope encryption per-teacher
   KEK che gli impedisce di accedere ai contenuti decifrati di docenti
   diversi da se stesso.

c. **Docenti Autorizzati**: responsabili diretti dei contenuti
   caricati, civilmente, penalmente e disciplinariamente, come
   sottoscritto nei Terms of Service e Acceptable Use Policy.

### 5.2 Safe harbor

Il Responsabile gode dell'esonero di responsabilità ex art. 16 D.Lgs.
70/2003 (Direttiva 2000/31/CE) per i contenuti immessi dai docenti
Autorizzati, purché:

1. Non abbia conoscenza effettiva dell'illiceità (garantita
   architetturalmente dall'envelope encryption);
2. Cooperi su procedure Notice & Takedown entro gli SLA stabiliti
   (vedi `docs/legal/takedown_procedure.md`);
3. Non agisca attivamente sui contenuti (no editing/curating).

### 5.3 Vigilanza del Titolare

Il Titolare si impegna a:

a. Nominare formalmente i docenti come Autorizzati ex art. 29 GDPR
   per l'utilizzo specifico dell'Applicativo;
b. Erogare formazione GDPR specifica (compresa AUP dell'Applicativo);
c. Far sottoscrivere ai docenti i Terms of Service e l'Acceptable Use
   Policy;
d. Attivare procedure disciplinari interne in caso di violazione
   accertata;
e. Collaborare con il Responsabile su procedure Notice & Takedown.

---

## Articolo 6 — Misure tecniche e organizzative (art. 32 GDPR)

Vedi Allegato 2 — Misure di sicurezza.

Le misure includono in sintesi:

- **Cifratura at-rest envelope** (KMS + per-teacher KEK + AES256-GCM)
  per i contenuti memorizzati sul server;
- **TLS 1.3** in transit;
- **Backup encrypted offsite** in UE (Backblaze B2 Amsterdam);
- **WAF self-hosted** con geo-block IT;
- **Sistema HIDS/NIDS** (AIDE, Suricata, ModSecurity);
- **Audit log** MariaDB server_audit + Loki centralized logs;
- **Identificazione studenti pseudonima** di default;
- **Hardening VPS** conforme CIS Benchmark (Lynis score 79+).

---

## Articolo 7 — Sub-Responsabili

### 7.1 Autorizzazione generale

Il Titolare autorizza il Responsabile a ricorrere ai seguenti
sub-Responsabili:

| Sub-Responsabile | Servizio | Sede | DPA |
|-------------------|----------|------|-----|
| Hetzner Online GmbH | Hosting VPS infrastruttura | Norimberga (DE — UE) | DPA standard Hetzner |
| Backblaze, Inc. — bucket EU | Storage backup offsite | Amsterdam (NL — UE) | DPA standard B2 |

### 7.2 Cambio sub-Responsabili

Il Responsabile notifica al Titolare con preavviso minimo di **30 giorni**
qualsiasi modifica o aggiunta di sub-Responsabili. Il Titolare può opporsi
motivatamente entro tale termine.

### 7.3 Trasferimenti extra-UE

I sub-Responsabili attuali operano **esclusivamente in territorio UE**
(no trasferimenti extra-UE, no clausole standard SCC art. 46 GDPR
necessarie).

---

## Articolo 8 — Data breach

### 8.1 Notifica del Responsabile al Titolare

Il Responsabile notifica al Titolare ogni data breach entro **24 ore**
dall'evidenza, fornendo:

- Natura della violazione e dati coinvolti
- Numero stimato di interessati
- Conseguenze probabili
- Misure adottate o proposte

### 8.2 Cooperazione

Il Responsabile coopera con il Titolare per:
- Valutazione impatto sul rischio degli interessati
- Notifica al Garante entro 72 ore ex art. 33 GDPR
- Eventuale comunicazione agli interessati ex art. 34 GDPR
- Documentazione interna del breach (ex art. 33.5 GDPR)

---

## Articolo 9 — Esercizio dei diritti degli interessati

Il Responsabile coopera con il Titolare per consentire l'esercizio dei
diritti degli interessati (artt. 15-22 GDPR):

- **Accesso** (art. 15): fornitura dati del singolo interessato entro
  10 giorni lavorativi dalla richiesta del Titolare;
- **Rettifica** (art. 16): esecuzione modifiche entro 5 giorni
  lavorativi;
- **Cancellazione** (art. 17): cancellazione completa + propagazione
  backup entro 30 giorni;
- **Portabilità** (art. 20): export dati JSON/CSV entro 14 giorni;
- **Opposizione** (art. 21): gestione caso per caso via DPO.

---

## Articolo 10 — Trasferimenti dati

Nessun dato è trasferito al di fuori dello Spazio Economico Europeo
(SEE). Tutti i sub-Responsabili operano in territorio UE.

---

## Articolo 11 — Compensi

Il presente DPA è a **titolo gratuito**: il Responsabile è docente
dipendente dell'Istituto Titolare e fornisce il servizio come strumento
didattico personale (Scenario A) o come liberalità (Scenari B/C).

Eventuali costi di hosting (Hetzner) e backup (Backblaze B2) sono
sostenuti integralmente dal Responsabile a proprie spese, salvo accordo
diverso scritto.

---

## Articolo 12 — Durata e cessazione

### 12.1 Durata

Il DPA decorre dalla data di sottoscrizione fino:
- Al recesso di una delle parti con preavviso di 90 giorni;
- Alla cessazione del rapporto di lavoro del Responsabile presso
  l'Istituto Titolare (in tal caso si applica Articolo 12.3 — transizione
  governance);
- All'eventuale passaggio a entità giuridica terza (in tal caso si
  rinegozia DPA con la nuova entità).

### 12.2 Restituzione/cancellazione dati

Alla cessazione, il Responsabile, su scelta documentata del Titolare:

a. **Restituisce** tutti i dati personali al Titolare in formato
   leggibile (export JSON/CSV) entro 60 giorni; oppure
b. **Cancella** tutti i dati personali entro 60 giorni con conferma
   scritta al Titolare.

Eccezione: dati che il Responsabile è tenuto a conservare per legge
(es. log per cooperazione autorità).

### 12.3 Transizione governance (caso cessazione rapporto)

In caso di cessazione del rapporto di lavoro del Responsabile presso
l'Istituto:

a. Le Parti valutano se proseguire come Responsabile esterno (con DPA
   eventualmente modificato) o se migrare il servizio ad altra
   soluzione;
b. Il Responsabile garantisce continuità del servizio per almeno 6 mesi
   dalla cessazione del rapporto, per consentire transizione ordinata;
c. Documentazione tecnica completa (procedure, credenziali, codici)
   viene fornita al Titolare.

---

## Articolo 13 — Disposizioni finali

### 13.1 Clausole prevalenti

In caso di contrasto, il presente DPA prevale su altri accordi tra
le Parti riguardanti il trattamento dei dati personali.

### 13.2 Modifiche

Le modifiche sono valide solo se redatte per iscritto e sottoscritte da
entrambe le Parti.

### 13.3 Foro competente

Foro di Verbania per qualsiasi controversia.

### 13.4 Legge applicabile

Legge italiana.

---

## Firma

| Per il Titolare | Per il Responsabile |
|------------------|---------------------|
| {{INSTITUTE_LEGAL_NAME}} | Vittorio Pantaleo |
| Dirigente Scolastico pro tempore: _______________ | CF: ______________ |
| Firma: _______________ | Firma: _______________ |
| Data: _______________ | Data: _______________ |

---

## ALLEGATO 1 — Data Mapping (Categorie di dati trattati)

| Categoria dato | Memorizzazione server | Base giuridica | Retention |
|----------------|----------------------|----------------|-----------|
| Username studente (pseudonimo) + hash password | Sì (DB) | Art. 6.1.e | Durata percorso + 30g |
| Banca esercizi docente | Sì (cifrato envelope) | Art. 6.1.e | Persistente |
| Template Risdoc | Sì (cifrato envelope) | Art. 6.1.e | Persistente |
| Template verifiche | Sì (cifrato envelope) | Art. 6.1.e | Persistente |
| File svolgimenti studente (futuro) | Sì (cifrato envelope) | Art. 6.1.e | Durata percorso + 30g |
| Mappe concettuali studente | NO (Drive personale) | — | — |
| Verifiche svolte studente | NO (fuori dal sito) | — | — |
| Documenti Risdoc finali | NO (esportati locali) | — | — |
| Log accessi | Sì | Art. 6.1.f | 30g |
| Audit log MariaDB | Sì | Art. 6.1.f | 365g |
| Backup B2 | Sì (cifrato GPG) | Art. 6.1.f | 24m |
| Categorie particolari art. 9 | **NON TRATTATE** | — | — |

---

## ALLEGATO 2 — Misure di sicurezza (sintesi ex art. 32 GDPR)

| Categoria | Misura |
|-----------|--------|
| Riservatezza | TLS 1.3 + HSTS preload; envelope encryption KMS+KEK+AES256-GCM; backup GPG AES256; systemd-creds per credentials at-rest |
| Integrità | WAF self-hosted geo-block IT; ModSecurity v3 + OWASP CRS; AIDE file integrity; Suricata NIDS ~40k regole; MariaDB server_audit |
| Disponibilità | Backup encrypted offsite UE giornaliero + tiered retention; restore test mensile (TTR ~3min); unattended-upgrades; health check post-deploy; Grafana alerting 4 rules |
| Accountability | Loki + Grafana centralized logs; audit trail completo; decision log su wiki/changelog; Lynis hardening score 79+ |
| Identificazione | Studenti pseudonimi default (codici random); 2FA TOTP disponibile per docenti |
| Hardening | CIS Benchmark Debian Linux aligned; SSH porta custom 2222; Phase 25.K-O hardening completo |

Dettaglio tecnico completo: `docs/todo/waf_security_prompt.md` (1700+ righe).

---

*Versione documento: 1.0 — 20 maggio 2026.*

*Per chiarimenti: privacy@pantedu.eu*
