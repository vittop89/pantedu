# Shamir Secret Sharing — Runbook Custodia + Recovery

**Phase 25.R.24 — Custodia distribuita KMS_MASTER_KEY**

## Scopo

Eliminare il single-point-of-failure rappresentato dalla custodia esclusiva di `KMS_MASTER_KEY` da parte del data controller (Vittorio Pantaleo). Distribuire il segreto fra 5 custodi indipendenti tramite Shamir Secret Sharing 3-su-5: nessun custode singolo può ricomporre la chiave da solo; servono almeno 3 in concordia.

Riferimento crittografico: Shamir 1979, "How to Share a Secret". Algoritmo GF(2^8) implementato in `app/Services/Crypto/ShamirSecretSharing.php`.

## Schema 3-su-5 — Custodi consigliati

Configurazione bilanciata fra **operatività** (recovery facile in vita), **legittimità istituzionale** (segreteria scuola come stakeholder primario del dato) e **chain-of-custody legale** (notaio pubblico ufficiale).

| # | Custode | Forma fisica | Ruolo nel recovery |
|---|---|---|---|
| 1 | **Vittorio Pantaleo** (data controller) | Password Safe locale (`.psafe3` cifrato) + USB Cryptomator | Operatività quotidiana, sempre disponibile |
| 2 | **Segreteria scuola** | Cassaforte istituto + busta sigillata + verbale consegna controfirmato dal DS (Dirigente Scolastico) | Recovery operativo + visibilità istituzionale dell'istituto sui propri dati |
| 3 | **Avvocato/fiduciario di fiducia** | USB cifrato custodito in cassaforte dello studio + lettera istruzioni recovery | Recovery legale + post-mortem |
| 4 | **Notaio** | Busta sigillata fisica con atto deposito (vedi §3) | Decreto magistrato + ultima istanza, chain-of-custody legalmente irrefutabile |
| 5 | **Cassetta sicurezza banca** | Carta plastificata stampata in 2 copie (1 cassetta + 1 backup home) | Failover indipendente da tutti gli altri |

### Combinazioni recovery 3-su-5 possibili

| Scenario | Custodi necessari | Quando applicare |
|---|---|---|
| Operatività normale | 1 + 2 + 5 | Vittorio + segreteria + banca — disponibili senza pratiche legali |
| Decreto autorità | 1 + 3 + 4 | Vittorio + avvocato + notaio — chain-of-custody forte per tribunale |
| Post-mortem Vittorio | 2 + 3 + 4 | Segreteria + avvocato + notaio — eredi non coinvolti se segreteria custodi tecnica |
| Scuola cessa attività | 1 + 3 + 4 | Senza segreteria, ancora 4 custodi → 3 disponibili |
| Vittorio + Scuola compromessi | 3 + 4 + 5 | Tre custodi esterni indipendenti |
| Single custode perso (qualsiasi) | restano 4 su 5 | Operatività preservata |

### Perché segreteria scuola sì + perché bilanciare

**Pro segreteria scuola come custode**:
- Istituzione formale, contratto di stabilità ≥10 anni
- Stakeholder naturale dei dati educativi (la scuola è "interessato secondario")
- Recovery più rapido di un notaio per casi non-litigation (es. Vittorio in malattia, eredi)
- Documentazione: verbale consegna formale firmato DS + protocollo istituto

**Considerazioni di rischio**:
- Personale segreteria può cambiare → richiede passaggio consegne documentato
- Scuola potrebbe cambiare gestore (es. fusione istituti, dirigenza nuova) → notificare cambio custodia in `crypto_custody_events`
- Conflitto di interesse: scuola NON dovrebbe essere unico custode istituzionale (per questo manteniamo anche notaio + avvocato)

**Perché manteniamo anche notaio (custode #4)**:
- Pubblico ufficiale neutro (Art. 2700 cc, atto pubblico fa fede assoluta)
- Apre solo su decreto autorità o procura — irrebuttable chain-of-custody
- Sopravvive a fallimenti scuola/banca/studi legali
- Costo basso (€200-500 una-tantum + €50-150/anno)

## Setup iniziale (one-shot)

### Step 1 — Generare le share

```bash
# Su laptop dev OFFLINE (no rete, no swap su disco):
cd ~/progetti_vscode/pantedu

# Input interactivo (consigliato):
php tools/crypto/shamir_split.php
# Inserisci KMS_MASTER_KEY quando chiesto

# OPPURE con flag (rischio: shell history salva il segreto):
php tools/crypto/shamir_split.php --secret="<KMS_HEX>" --threshold=3 --n=5
```

Output: 5 stringhe formato `FSS1:1:abc...`, `FSS1:2:def...`, etc.

### Step 2 — Stampare + sigillare

Per ogni share, stampa **DUE copie cartacee**:
1. Su carta archival (no carta termica — dura 50+ anni)
2. Layout consigliato (1 share per pagina):
   ```
   ┌─────────────────────────────────────────────────┐
   │ PANTEDU — SHAMIR SHARE #1 di 5                │
   │ Threshold recovery: 3 su 5                       │
   │                                                  │
   │ Data generazione: 2026-MM-DD                     │
   │ Fingerprint segreto (SHA-256 first 16):          │
   │   <hex>                                          │
   │                                                  │
   │ Share value:                                     │
   │ FSS1:1:abc1234...                                │
   │ (continua...)                                    │
   │                                                  │
   │ Custode: <nome custode>                          │
   │ Recovery: contattare <operatore/gestore istanza> │
   │   PEC: <pec operatore>                           │
   │   Tel: +39 ...                                   │
   │ Documento custodia: <riferimento atto notarile>  │
   └─────────────────────────────────────────────────┘
   ```

### Step 3 — Deposito notarile (share #2)

Visita un notaio specializzato in **diritto digitale** (Milano/Roma/Torino hanno studi). Costo orientativo: **€200-500 una-tantum + €50-150/anno custodia**.

Atto da firmare include:
1. **Dichiarazione data controller**: chi è Vittorio, ruolo, contatti.
2. **Descrizione segreto**: SHA-256 fingerprint del KMS_MASTER_KEY (NON il segreto stesso!).
3. **Share custodita**: foglio cartaceo con `FSS1:2:...` (busta sigillata).
4. **Trigger di apertura**:
   - Procura notarile da Vittorio (recovery operativo)
   - Decreto magistratura (cooperazione autorità)
   - Certificato decesso + procura eredi (post-mortem)
   - Dichiarazione impossibilità medica (curatore)
5. **Procedura apertura**: presenza intestatario o suo rappresentante; verbale apertura; consegna share al richiedente con identificazione.

Memorizza **riferimento atto** (es. "Repertorio 12345/2026 Notaio Mario Rossi") — va sui certificati delle altre share.

### Step 4 — Distribuzione altre share

| Share # | Consegna | Procedura |
|---|---|---|
| 1 | **Vittorio** | Import in Password Safe (`.psafe3` cifrato) + copia USB Cryptomator. NO email plain, NO sync online. |
| 2 | **Segreteria scuola** | Stampa cartacea in busta sigillata. Consegna a mano al DS (Dirigente Scolastico) con verbale firmato controfirmato. Custodia in cassaforte istituto. Lettera accompagnamento con: fingerprint segreto, istruzioni recovery (chi contattare, quando aprire), riferimento atto notarile, contatti Vittorio. |
| 3 | **Avvocato** | USB cifrato + busta sigillata. Lettera istruzioni recovery + fingerprint. Consegna a mano in studio, verbale ricevuta. |
| 4 | **Notaio** | Già completato in Step 3 (busta sigillata depositata con atto). |
| 5 | **Cassetta sicurezza banca** | Carta plastificata (no carta termica). Stampa 2 copie: 1 nella cassetta + 1 a casa in cassetta ignifuga. Vittorio intestatario + co-intestatario fiduciario opzionale. |

### Step 5 — Registrare evento in crypto_custody_events

```sql
INSERT INTO crypto_custody_events (
  event_type, custodian_name, custody_location, description, legal_basis, occurred_at
) VALUES
  ('kms_backup_created', 'Vittorio Pantaleo (data controller)', 'Password Safe + USB Cryptomator', 'Shamir share #1', 'Art. 32(1)(c) GDPR', NOW()),
  ('kms_backup_created', 'Segreteria <Nome Istituto>', 'Cassaforte istituto (busta sigillata, verbale DS)', 'Shamir share #2 — stakeholder istituzionale', 'Affidamento DPA scuola', NOW()),
  ('kms_backup_created', 'Avv. <Nome Cognome>', 'Studio legale <indirizzo>', 'Shamir share #3 — USB cifrato', 'Affidamento fiduciario', NOW()),
  ('kms_backup_created', 'Notaio <Nome Cognome>', 'Studio notarile (atto Rep. <N>/<anno>)', 'Shamir share #4 — busta sigillata atto notarile', 'Atto notarile Rep. N/anno', NOW()),
  ('kms_backup_created', 'Banca <Nome>', 'Cassetta sicurezza N. <X>', 'Shamir share #5 — carta plastificata', 'Contratto cassetta sicurezza', NOW());
```

Oppure usa UI `/admin/crypto-status` per registrare ogni evento manualmente.

### Step 6 — Verifica round-trip (DA FARE PRIMA del go-live!)

**OBBLIGATORIO**: prima di considerare lo schema attivo, simulare recovery con 3 share casuali.

```bash
php tools/crypto/shamir_combine.php
# Incolla share #1, #3, #5 (esempio)
# Output deve essere identico a KMS_MASTER_KEY originale
# Fingerprint deve matchare quello documentato in atto notarile
```

Se OK: schema validato.

## Recovery procedure

### Scenario A — Vittorio dimentica password Cryptomator

1. Apre cassetta sicurezza banca → ottiene **share #5**
2. Chiede a segreteria scuola → ottiene **share #2** (DS apre cassaforte istituto + verbale)
3. Servono 3 share, ha share #1 (sua) + #2 + #5 → 3 disponibili
4. Esegue `php tools/crypto/shamir_combine.php` con queste 3 → ricompone KMS

**Tempo stimato**: 1-2 ore (apertura cassetta + viaggio scuola).

### Scenario B — Decreto magistrato (Art. 254 cpp sequestro)

1. Magistrato emette decreto motivato
2. Vittorio comunica il decreto a:
   - **Avvocato** → ricevuta consegna **share #3** (su verbale)
   - **Notaio** → apertura busta **share #4** in presenza magistrato/PG
3. Vittorio aggiunge la propria **share #1**
4. Recovery con 3 share (#1 + #3 + #4) sotto controllo magistrato → KMS ricomposto → decifratura dati
5. Logging immutabile:
   ```
   crypto_custody_events:
     authority_request   (con decreto)
     authority_granted   (valutazione legittimità)
     data_recovered      (KMS unwrap + KEK decrypt)
     data_provided       (bundle authority-export firmato)
   ```

**Tempo stimato**: 24-72 ore (notifica notaio, presa appuntamento avvocato).

### Scenario C — Vittorio deceduto

1. Eredi presentano certificato decesso + procura di successione al **notaio**
2. **Notaio** apre busta secondo Step 3.5 dell'atto deposito → consegna **share #4**
3. **Avvocato** (share #3) verifica eredi su atto notorio → consegna
4. **Segreteria scuola** (share #2): consegna su richiesta scritta DS + atto eredità
5. 3 share (#2 + #3 + #4) → KMS ricomposto → custodia trasferita a eredi (con responsabilità data controller).
6. Eredi possono scegliere: continuare servizio Pantedu, fare cessione dati a DPO scolastico, oppure crypto-shredding totale (cancellare KMS = irreversibile).

**Tempo stimato**: 30-90 giorni (formalizzazione successione).

### Scenario D — Scuola cessa attività / cambia gestione

1. Vittorio segue la procedura di "passaggio consegne" istituzionale
2. Recupera **share #2** dalla segreteria uscente (su verbale formalizzato)
3. Re-deposita nuova share #2 presso nuova segreteria/gestore (oppure ridistribuisce a custode alternativo)
4. Registra in `crypto_custody_events`: evento `kms_backup_created` (nuova custodia) + descrizione "passaggio segreteria"
5. **Non necessario** ri-splittare: la share è la stessa, cambia solo il custode fisico

### Scenario E — Custode singolo compromesso/non disponibile

Se UNO dei 5 custodi è perso/compromesso/non risponde:
- **4 share residue** → ancora sopra threshold 3 → recovery possibile
- Riassegnare share del custode perso a uno nuovo (consigliato dopo audit annuale)
- Se 2 o più persi simultaneamente → emergenza, registrare incidente in `data_breach_register`

## Manutenzione periodica

### Verifica annuale (ogni 12 mesi)

1. **Round-trip test** (Step 6 sopra): conferma 3 share casuali ancora ricompongono il segreto.
2. **Stato custodi**:
   - **Vittorio**: Password Safe + USB Cryptomator funzionanti, password ricordata
   - **Segreteria scuola**: stesso DS / cambio personale? Cassaforte istituto integra? Verbale aggiornato per eventuali nuovi incarichi?
   - **Avvocato**: studio attivo? Recapiti aggiornati? USB cifrato leggibile?
   - **Notaio**: studio attivo (non sciolto)? Atto vigente? Pagamento custodia annuale OK?
   - **Cassetta sicurezza banca**: pagamento annuale OK? Co-intestatari aggiornati?
3. **Audit log review**:
   - `SELECT * FROM crypto_custody_events WHERE occurred_at > NOW() - INTERVAL 1 YEAR`
   - Eventi inattesi? Accessi non autorizzati?
4. **Registra evento verifica**:
   ```
   INSERT crypto_custody_events ('kms_backup_verified', ...);
   ```

### Rotazione KMS_MASTER_KEY (ogni N anni o post-incident)

Quando si decide di ruotare KMS:
1. Genera nuovo `KMS_MASTER_KEY` (32 byte random hex)
2. Re-wrap tutte le `teacher_keys.wrapped_key` con la nuova KMS
3. Re-wrap tutte le `classe_keys.wrapped_key`
4. Nuovo Shamir split del nuovo KMS
5. **Distribuisci nuove share PRIMA di distruggere le vecchie**
6. Eventi: `kms_rotated` + `kms_backup_created` (x5 per nuove share)
7. Solo dopo verifica round-trip: **distruggi vecchie share** (custodi devono firmare verbale distruzione)
8. Vecchio `KMS_MASTER_KEY`: cancellato fisicamente da tutti i sistemi attivi

## Punti critici di attenzione

### Cosa NON fare

- ❌ Mai inviare share via email plain
- ❌ Mai loggare share (anche in cron error log)
- ❌ Mai salvare share nel codice/git
- ❌ Mai usare stesso password manager per tutte le share
- ❌ Mai distribuire share via WhatsApp / Telegram / SMS
- ❌ Mai stampare share su carta termica (sbiadisce)

### Cosa fare

- ✅ Distribuire share **in 5 sedi geograficamente diverse**
- ✅ Documentare ogni custodia in `crypto_custody_events`
- ✅ Verifica round-trip annuale obbligatoria
- ✅ Aggiornare contatti custodi alla scadenza
- ✅ Cifrare share online con password mai usate altrove
- ✅ Atto notarile in italiano (foro competente fissato)

## Riferimenti

- Shamir, "How to Share a Secret", Communications of the ACM, 1979
- Implementazione: [app/Services/Crypto/ShamirSecretSharing.php](../../../app/Services/Crypto/ShamirSecretSharing.php)
- Test: [tests/Unit/Services/Crypto/ShamirSecretSharingTest.php](../../../tests/Unit/Services/Crypto/ShamirSecretSharingTest.php)
- CLI tools: [tools/crypto/shamir_split.php](../../../tools/crypto/shamir_split.php), [tools/crypto/shamir_combine.php](../../../tools/crypto/shamir_combine.php)
- Authority cooperation: [docs/security/operations/authority-cooperation.md](authority-cooperation.md) §2.5
