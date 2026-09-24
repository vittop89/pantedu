---
tags:
  - documentazione/adr
date: 2026-09-14
tipo: adr
status: accettato
aliases: ["ADR-039", "sessioni", "SESSION_DRIVER", "SESSION_SAVE_PATH", "sessioni a file", "logout a ogni rilascio"]
cssclasses: []
---

# ADR-039 — Dove stanno le sessioni si sceglie in configurazione, e sono uguali ovunque

## Stato

**ACCETTATO** dall'utente il 14 settembre 2026 («5. va bene»), sulla proposta:
- scegliere in configurazione dove salvare le sessioni, non in base
  all'esistenza di una tabella;
- dare a CI e sviluppo le stesse impostazioni di sessione della produzione;
- infine, far girare la suite end-to-end contro l'immagine che si rilascia.

**Eseguito.** I primi due punti sono in questo ADR. Il terzo è il passo
successivo, in una pull request a parte.

## Contesto

Misurato il 14 settembre 2026.

- **Chi sceglieva era una tabella.** `Session::start` usava `DbSessionHandler`
  se esisteva la tabella `sessions`, altrimenti i file nella cartella
  predefinita di PHP. La tabella c'è solo dove il database nasce da
  `schema.sql`: in CI sì, in sviluppo e in produzione no. La CI lavorava con un
  gestore, la produzione con un altro, e un difetto di sessione si vedeva da
  una parte sola: il 14/9 il gettone CSRF che spariva solo in CI (#80).
- **`docker/php.ini` diceva il falso.** Scriveva che l'applicazione teneva le
  sessioni sul database, «quindi qui non serve un percorso». In produzione la
  tabella non c'era: `session.save_path` vuoto, sessioni nella `/tmp` del
  container (214 file in quella del container partito alle 11:47).
- **Ogni rilascio buttava fuori tutti.** Il rilascio a container (blu/verde)
  ferma il container vecchio e con lui la sua `/tmp`: le sessioni restavano
  lì. Il 14 settembre, fra mezzanotte e le 12:31, i rilasci riusciti erano
  stati sedici (registro del rilascio): sedici volte, chi era collegato è
  stato buttato fuori.
- **La modalità stretta c'era solo in produzione** (`session.use_strict_mode
  = 1` in `docker/php.ini`). In sviluppo e in CI un id sconosciuto si
  accettava: la sonda sulla rotazione dell'id (voce 101) ha dovuto
  aggiungerla a mano per vedere quello che vedeva la produzione.
- **La pulizia delle sessioni** aveva il valore di PHP, 1440 secondi, meno dei
  30 minuti di inattività concessi.

## Decisione

### Il modo si sceglie in configurazione

`SESSION_DRIVER`: `file` (predefinito) o `database`. Nessuna deduzione.

- **Servendo pagine, un posto che non funziona è un errore** (500), non un
  ripiego in silenzio: la cartella che non si crea o non si scrive, il
  database scelto senza la tabella, un modo sconosciuto.
- **Da riga di comando le sessioni non servono**, e `app/bootstrap.php` le
  avvia comunque. Lì, se il posto non va, si prosegue senza sessione, come già
  si faceva per l'avviso di guasto con `ProtectSystem=strict`.
- **Il container non parte** se il modo scelto non può funzionare: lo
  controlla `docker/verifica-avvio.php`, come `www-data`.

### A file, sul volume dei dati

La cartella è `SESSION_SAVE_PATH`, o `storage/sessions` dei dati d'istanza.

- **Sopravvive allo scambio dei container**: il container nuovo monta lo
  stesso volume, e ritrova le sessioni.
- **La crea l'avvio del container**, come root, per `www-data` con 0700: i
  nomi dei file sono gli id di sessione. Se la creasse per primo un lavoro
  notturno dell'host, con il suo utente, chi serve le pagine non potrebbe
  scriverci. Da riga di comando la cartella non si crea mai; servendo pagine
  sì, per sviluppo e CI.
- **Resta fuori dai salvataggi**: `encrypted_backup.sh` esclude già
  `./sessions`.
- **Resta fuori da AIDE**: il perimetro esclude i dati d'istanza.

### Le impostazioni le mette l'applicazione

`Session::impostazioni`, uguali in sviluppo, in CI e nel container:
- modalità stretta (solo cookie e niente id nell'indirizzo sono già i valori
  di PHP, e da PHP 8.4 cambiarli è deprecato);
- pulizia che aspetta tutta l'inattività (`SESSION_LIFETIME`) e gira davvero
  (1 richiesta su 100).

Il blocco sessioni di `docker/php.ini` esce, perché una regola sta in un posto
solo.

### Perché file e non database

- **Il database serve con più macchine.** Oggi c'è una macchina sola, e i file
  sul volume risolvono il difetto senza dati nuovi da nessuna parte: le stesse
  sessioni, sulla stessa macchina, con la stessa scadenza.
- **`DbSessionHandler` non è pronto per la produzione:**
  - salva l'id di sessione in chiaro, come chiave;
  - salva IP e browser in chiaro, mentre l'audit li salva come impronta;
  - la tabella finirebbe nei salvataggi notturni, con id ancora validi.
- **Prima di scegliere `database` su un'installazione vera** vanno fatte tre
  cose (voce 104 del registro del debito):
  - l'id come impronta;
  - niente IP e browser in chiaro;
  - la tabella esclusa dai salvataggi.

  Il blocco per sessione (#80) c'è già.

## Conseguenze

- **In produzione**, al primo rilascio con questo codice le sessioni passano
  dalla `/tmp` del container a `storage/sessions`. Chi è collegato in quel
  momento esce un'ultima volta; dal rilascio dopo, non più.
- **In CI** le sessioni non stanno più nel database: sono file, come in
  produzione. La tabella `sessions` di `schema.sql` resta, per chi sceglie
  `database`.
- **Dove si prova**, nei due versi:
  - `SessioniConfigurateTest`, con processi separati e il server incorporato
    di PHP;
  - `SessionImpostazioniTest`;
  - `SessioneSenzaRotazioneTest`, adattata alla modalità stretta.
  - Il controllo d'avvio è stato misurato su una cartella mancante, leggibile
    da altri, non scrivibile, buona, e con un modo sconosciuto.
- **Privacy.** Nessun dato nuovo, nessuna conservazione nuova: le sessioni
  cambiano cartella sulla stessa macchina, fuori dai salvataggi come prima.

## Riferimenti

- `app/Core/Session.php`, `app/Config/session.php`
- `docker/entrypoint.sh`, `docker/verifica-avvio.php`, `docker/php.ini`
- [[ADR-038-drive-stati]] (stesso giorno, stessa forma: uno stato esplicito al
  posto di una deduzione)
- Voce 101 del registro del debito (la rotazione dell'id), voce 104 (il
  gestore su database)
